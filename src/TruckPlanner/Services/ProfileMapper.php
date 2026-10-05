<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\Input;

/**
 * The truck profile between its three forms (04_BACKEND.md 1.4, 4.4):
 *
 *   - the normalised row of TruckRepository (flat, column names without the storage suffix),
 *   - the API's TruckRecord { id, timezone, base_state, base_county_fips, profile: TruckProfileX,
 *     created_at, updated_at }, whose `profile` is the TruckProfile the model takes,
 *   - a request body.
 *
 * It also gives the profile defaults (the seed group `profile_defaults` is their only source) and validates
 * the shape and the ranges of a body. What needs other data is the profile service's: whether the region
 * exists and is usable, which region and time zone a new truck gets, whether a licence county belongs to
 * the region, the state and county of the base.
 */
class ProfileMapper
{
    private const NUM = 'num';
    private const INT = 'int';
    private const BOOL = 'bool';
    private const FUEL = 'fuel';
    private const PRICE = 'price';
    private const FIT = 'fit';
    private const COUNTIES = 'counties';

    private const FUEL_TYPES = ['gasoline', 'diesel'];
    private const DAYPARTS = ['breakfast', 'lunch', 'dinner', 'late'];
    private const FUEL_PRICE_MIN = 0.5;
    private const FUEL_PRICE_MAX = 20.0;
    private const MAX_COUNTIES = 60;

    /**
     * Profile field => [kind, normalised column], in the key order of TruckProfile after `base`.
     *
     * @var array<string, array{0: string, 1: ?string}>
     */
    private const FIELDS = [
        'avg_ticket' => [self::NUM, 'avg_ticket'],
        'capacity_orders_per_hour' => [self::NUM, 'capacity_orders_per_hour'],
        'paid_crew' => [self::INT, 'paid_crew'],
        'wage_per_hour' => [self::NUM, 'wage'],
        'payroll_burden_pct' => [self::NUM, 'payroll_burden_pct'],
        'food_cost_pct' => [self::NUM, 'food_cost_pct'],
        'packaging_per_order' => [self::NUM, 'packaging'],
        'card_fee_pct' => [self::NUM, 'card_fee_pct'],
        'card_fee_fixed' => [self::NUM, 'card_fee_fixed'],
        'card_share' => [self::NUM, 'card_share'],
        'tips_include' => [self::BOOL, 'tips_include'],
        'tips_pct_of_card_sales' => [self::NUM, 'tips_pct'],
        'mpg' => [self::NUM, 'mpg'],
        'fuel_type' => [self::FUEL, 'fuel_type'],
        'fuel_price_override' => [self::PRICE, 'fuel_price_override'],
        'generator_gal_per_hour' => [self::NUM, 'generator_gal_per_hour'],
        'prep_minutes' => [self::INT, 'prep_minutes'],
        'setup_minutes' => [self::INT, 'setup_minutes'],
        'teardown_minutes' => [self::INT, 'teardown_minutes'],
        'closeout_minutes' => [self::INT, 'closeout_minutes'],
        'fixed_cost_per_service_day' => [self::NUM, 'fixed_cost_day'],
        'daypart_fit' => [self::FIT, null],
        'avoid_tolls' => [self::BOOL, 'avoid_tolls'],
        'avoid_highways' => [self::BOOL, 'avoid_highways'],
        'truck_time_factor' => [self::NUM, 'truck_time_factor'],
        'licence_counties' => [self::COUNTIES, 'licence_counties'],
        'scout_drive_minutes_limit' => [self::INT, 'scout_drive_minutes_limit'],
    ];

    /**
     * The API's TruckRecord of a normalised row. Keys the row has beyond the record (organization_id,
     * overrides, a ready `profile`) are ignored, so the truck value of the base controller is accepted too.
     *
     * @param array<string, mixed> $row a row of TruckRepository::findByOrg()
     * @return array{id: string, timezone: string, base_state: ?string, base_county_fips: ?string,
     *               profile: array<string, mixed>, created_at: string, updated_at: string}
     */
    public function toRecord(array $row): array
    {
        $profile = [
            'name' => (string) $row['name'],
            'region_id' => (string) $row['region_id'],
            'base' => [
                'lat' => (float) $row['base_lat'],
                'lng' => (float) $row['base_lng'],
                'address' => (string) $row['base_address'],
            ],
        ];
        foreach (self::FIELDS as $field => [$kind, $column]) {
            switch ($kind) {
                case self::NUM:
                    $profile[$field] = (float) $row[$column];
                    break;
                case self::INT:
                    $profile[$field] = (int) $row[$column];
                    break;
                case self::BOOL:
                    $profile[$field] = (bool) $row[$column];
                    break;
                case self::FUEL:
                    $profile[$field] = (string) $row[$column];
                    break;
                case self::PRICE:
                    $profile[$field] = $row[$column] === null ? null : (float) $row[$column];
                    break;
                case self::FIT:
                    $fit = [];
                    foreach (self::DAYPARTS as $daypart) {
                        $fit[$daypart] = (float) $row['fit_' . $daypart];
                    }
                    $profile[$field] = $fit;
                    break;
                case self::COUNTIES:
                    $profile[$field] = array_values(array_map('strval', (array) $row[$column]));
                    break;
            }
        }
        return [
            'id' => (string) $row['id'],
            'timezone' => (string) $row['timezone'],
            'base_state' => $row['base_state'] === null ? null : (string) $row['base_state'],
            'base_county_fips' => $row['base_county_fips'] === null ? null : (string) $row['base_county_fips'],
            'profile' => $profile,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * The normalised columns of a profile, or of the part of a profile that is given: only the keys that
     * are present are mapped, a nested object (`base`, `daypart_fit`) key by key. `timezone`, `base_state`
     * and `base_county_fips` are not profile fields: the profile service sets those columns itself.
     *
     * @param array<string, mixed> $profile TruckProfileX, whole or partial
     * @return array<string, mixed> keys of TruckRepository::COLUMNS
     */
    public function toColumns(array $profile): array
    {
        $columns = [];
        if (array_key_exists('name', $profile)) {
            $columns['name'] = (string) $profile['name'];
        }
        if (array_key_exists('region_id', $profile)) {
            $columns['region_id'] = (string) $profile['region_id'];
        }
        if (is_array($profile['base'] ?? null)) {
            foreach (['lat' => 'base_lat', 'lng' => 'base_lng'] as $key => $column) {
                if (array_key_exists($key, $profile['base'])) {
                    $columns[$column] = (float) $profile['base'][$key];
                }
            }
            if (array_key_exists('address', $profile['base'])) {
                $columns['base_address'] = (string) $profile['base']['address'];
            }
        }
        foreach (self::FIELDS as $field => [$kind, $column]) {
            if (!array_key_exists($field, $profile)) {
                continue;
            }
            $value = $profile[$field];
            switch ($kind) {
                case self::NUM:
                    $columns[$column] = (float) $value;
                    break;
                case self::INT:
                    $columns[$column] = (int) $value;
                    break;
                case self::BOOL:
                    $columns[$column] = (bool) $value;
                    break;
                case self::FUEL:
                    $columns[$column] = (string) $value;
                    break;
                case self::PRICE:
                    $columns[$column] = $value === null ? null : (float) $value;
                    break;
                case self::FIT:
                    foreach (self::DAYPARTS as $daypart) {
                        if (is_array($value) && array_key_exists($daypart, $value)) {
                            $columns['fit_' . $daypart] = (float) $value[$daypart];
                        }
                    }
                    break;
                case self::COUNTIES:
                    $columns[$column] = array_values(array_map('strval', (array) $value));
                    break;
            }
        }
        return $columns;
    }

    /**
     * Every TruckProfileX field except `name`, `region_id` and `base`, with its default value.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $A = Seeds::defaults();
        $defaults = [];
        foreach (self::FIELDS as $field => [$kind]) {
            switch ($kind) {
                case self::NUM:
                    $defaults[$field] = (float) Estimator::seed($A, 'profile_defaults.' . $field);
                    break;
                case self::INT:
                    $defaults[$field] = (int) Estimator::seed($A, 'profile_defaults.' . $field);
                    break;
                case self::BOOL:
                    $defaults[$field] = (bool) Estimator::seed($A, 'profile_defaults.' . $field);
                    break;
                case self::FUEL:
                    $defaults[$field] = (string) Estimator::seed($A, 'profile_defaults.' . $field);
                    break;
                case self::PRICE:
                    $defaults[$field] = null;
                    break;
                case self::FIT:
                    $fit = [];
                    foreach (self::DAYPARTS as $daypart) {
                        $fit[$daypart] = (float) Estimator::seed($A, 'profile_defaults.daypart_fit.' . $daypart);
                    }
                    $defaults[$field] = $fit;
                    break;
                case self::COUNTIES:
                    $defaults[$field] = [];
                    break;
            }
        }
        return $defaults;
    }

    /**
     * Validates the profile fields of a request body and returns the ones that were sent, in profile shape
     * (a nested object holds only the keys it carried). Ranges are the `min` and `max` of the seed group
     * `profile_defaults`.
     *
     * With `$partial` false (no truck yet) `name`, `base` with its point, and `avg_ticket` are required and
     * `base.address` defaults to "". With `$partial` true every key is optional and a body without any
     * known key is V12.
     *
     * Two keys of the answer are not profile fields: `timezone` (a valid IANA name, as sent) and
     * `base.state` (two letters, upper case). toColumns() leaves both alone.
     *
     * @return array<string, mixed>
     */
    public function validate(Input $in, bool $partial): array
    {
        if ($partial) {
            $in->requireAny(array_merge(['name', 'base', 'region_id', 'timezone'], array_keys(self::FIELDS)));
        }
        $A = Seeds::defaults();
        $out = [];

        if (!$partial || $in->has('name')) {
            $name = (string) $in->str('name', 120, true);
            if ($name === '') {
                throw $in->error('name', 'is required', 'V1');
            }
            $out['name'] = $name;
        }

        if (!$partial || $in->has('base')) {
            $base = $in->obj('base', true);
            $place = [];
            if ($base !== null && (!$partial || $base->has('lat') || $base->has('lng'))) {
                $point = $in->point('base', true);
                if ($point !== null) {
                    $place['lat'] = $point['lat'];
                    $place['lng'] = $point['lng'];
                }
            }
            if ($base !== null && $base->has('address')) {
                $place['address'] = (string) $base->str('address', 255, true);
            } elseif (!$partial) {
                $place['address'] = '';
            }
            if ($base !== null && $base->has('state') && !$base->isNull('state')) {
                $state = (string) $base->str('state', 2, true);
                if (preg_match('/^[A-Za-z]{2}$/', $state) !== 1) {
                    throw $base->error('state', 'must be a two-letter state code');
                }
                $place['state'] = strtoupper($state);
            }
            $out['base'] = $place;
        }

        if ($in->has('region_id') && !$in->isNull('region_id')) {
            $out['region_id'] = (string) $in->str('region_id', 24, true);
        }

        if ($in->has('timezone') && !$in->isNull('timezone')) {
            $zone = $in->all()['timezone'];
            if (!is_string($zone) || !Clock::isZone($zone)) {
                throw $in->error('timezone', 'must be an IANA time zone name');
            }
            $out['timezone'] = $zone;
        }

        foreach (self::FIELDS as $field => [$kind]) {
            $required = !$partial && $field === 'avg_ticket';
            if (!$required && !$in->has($field)) {
                continue;
            }
            switch ($kind) {
                case self::NUM:
                    $out[$field] = $in->num($field, self::bound($A, $field, 'min'), self::bound($A, $field, 'max'), true);
                    break;
                case self::INT:
                    $out[$field] = $in->int($field, (int) self::bound($A, $field, 'min'), (int) self::bound($A, $field, 'max'), true);
                    break;
                case self::BOOL:
                    $out[$field] = $in->bool($field, true);
                    break;
                case self::FUEL:
                    $out[$field] = $in->enum($field, self::FUEL_TYPES, true);
                    break;
                case self::PRICE:
                    $out[$field] = $in->isNull($field)
                        ? null
                        : $in->num($field, self::FUEL_PRICE_MIN, self::FUEL_PRICE_MAX, true);
                    break;
                case self::FIT:
                    $fit = $in->obj($field, true);
                    $values = [];
                    foreach (self::DAYPARTS as $daypart) {
                        if ($fit !== null && $fit->has($daypart)) {
                            $values[$daypart] = $fit->num(
                                $daypart,
                                self::bound($A, $field, 'min'),
                                self::bound($A, $field, 'max'),
                                true
                            );
                        }
                    }
                    $out[$field] = $values;
                    break;
                case self::COUNTIES:
                    $list = $in->items($field, 0, self::MAX_COUNTIES, true) ?? [];
                    $each = $in->each($field);
                    $codes = [];
                    foreach (array_keys($list) as $i) {
                        $code = (string) $each->str($i, 5, true);
                        if (preg_match('/^[0-9]{5}$/', $code) !== 1) {
                            throw $each->error($i, 'must be a 5-digit county code');
                        }
                        if (!in_array($code, $codes, true)) {
                            $codes[] = $code;
                        }
                    }
                    $out[$field] = $codes;
                    break;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $A
     */
    private static function bound(array $A, string $field, string $which): float
    {
        return (float) Estimator::seed($A, 'profile_defaults.' . $field . '.' . $which);
    }
}
