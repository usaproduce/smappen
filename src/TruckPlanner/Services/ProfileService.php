<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;

/**
 * Saves the truck profile (04_BACKEND.md 4.4). The first save creates the organization's truck: `name`,
 * `base` and `avg_ticket` are required and every other field starts at its default. A later save changes
 * the fields it carries and nothing else.
 *
 * ProfileMapper checks the shape and the ranges of the body. This class adds what needs other data:
 *
 *   - The region. A `region_id` in the body is taken when it is `none`, the truck's present region, or a
 *     region whose active data can be used. Without one the region follows the base: whenever a base point
 *     is saved, the truck stays in its region if that region's box holds the point, and else gets the first
 *     region (ascending id) whose box holds it, or `none`.
 *   - The time zone. It is the region's. A truck without a region has the zone the body names, else the
 *     zone it already has; a new truck that names none gets the default zone and the warning
 *     `timezone_assumed`.
 *   - The state and the county of the base, from the nearest census block of the region data (the capture
 *     contract). When the data cannot tell the state, the `base.state` of the body is used. A base that the
 *     data places outside the region raises the warning `base_outside_region`. The region's box never
 *     decides that: it only chooses the default region.
 *   - The licence counties, which must be counties of the truck's region.
 *
 * Money is stored in whole cents, so the answer is built from the row as it reads back.
 */
class ProfileService
{
    public const TIMEZONE_ASSUMED = 'timezone_assumed';
    public const BASE_OUTSIDE_REGION = 'base_outside_region';

    private TruckRepository $trucks;
    private RegionService $regions;
    private ProfileMapper $mapper;

    public function __construct(?TruckRepository $trucks = null, ?RegionService $regions = null, ?ProfileMapper $mapper = null)
    {
        $this->trucks = $trucks ?? new TruckRepository();
        $this->regions = $regions ?? new RegionService();
        $this->mapper = $mapper ?? new ProfileMapper();
    }

    /**
     * Creates the organization's truck, or changes the fields the body carries.
     *
     * @param array<int|string, mixed> $body the JSON object of the request
     * @return array{created: bool, truck: array<string, mixed>, region: array<string, mixed>|null,
     *               fuel: array<string, mixed>, warnings: list<string>}
     *         the answer of route 3 (`truck` is a TruckRecord, `region` the RegionInfo of its region or null
     *         for `none`, `fuel` the FuelInfo after the save, `warnings` the codes above) with one key added:
     *         `created` tells the controller whether to answer 201 or 200, and is not sent
     * @throws \App\TruckPlanner\Services\Support\TpInvalid 422, with the messages of 4.2 and 4.4
     */
    public function upsert(string $orgId, ?string $userId, array $body): array
    {
        $in = new Input($body);
        $created = false;
        $warnings = [];

        $existing = $this->trucks->findByOrg($orgId);
        if ($existing === null) {
            [$columns, $warnings] = $this->resolve($in, null);
            try {
                $this->trucks->create($orgId, $userId, $columns + ['overrides_seeds_rev' => Seeds::revision()]);
                $created = true;
            } catch (\PDOException $e) {
                // Two first saves at the same moment: the one-truck key let the other one in. If the truck
                // is there now, this save goes on as a change of it.
                $existing = $this->trucks->findByOrg($orgId);
                if ($existing === null) {
                    throw $e;
                }
            }
        }
        if ($existing !== null) {
            [$columns, $warnings] = $this->resolve($in, $existing);
            $this->trucks->update((string) $existing['id'], $orgId, $columns);
        }

        $row = $this->trucks->findByOrg($orgId);
        if ($row === null) {
            // The truck was deleted while this save ran: the caller starts over, as after any deletion.
            throw new TpConflict('Set up your truck first');
        }
        $record = $this->mapper->toRecord($row);
        return [
            'created' => $created,
            'truck' => $record,
            'region' => $this->regions->info((string) $record['profile']['region_id']),
            'fuel' => Registry::fuel()->resolve($row + ['profile' => $record['profile']]),
            'warnings' => $warnings,
        ];
    }

    /**
     * Validates the body and works out what to write.
     *
     * @param array<string, mixed>|null $existing the stored row (TruckRepository::findByOrg), null for a new truck
     * @return array{0: array<string, mixed>, 1: list<string>} the normalised columns to write (every column
     *         of a new truck; only the ones that change for an existing truck) and the warnings of this save
     */
    private function resolve(Input $in, ?array $existing): array
    {
        $fields = $this->mapper->validate($in, $existing !== null);
        $base = is_array($fields['base'] ?? null) ? $fields['base'] : [];
        $warnings = [];

        // The base point: the one sent, else the stored one.
        $pointSent = array_key_exists('lat', $base) && array_key_exists('lng', $base);
        if ($existing === null && !$pointSent) {
            throw new \LogicException('a new truck passed validation without a base point');
        }
        $lat = (float) ($pointSent ? $base['lat'] : $existing['base_lat']);
        $lng = (float) ($pointSent ? $base['lng'] : $existing['base_lng']);
        $moved = $existing === null || $lat !== (float) $existing['base_lat'] || $lng !== (float) $existing['base_lng'];
        $storedRegion = $existing === null ? null : (string) $existing['region_id'];

        // The region: the one asked for, else the one of the base point that is being saved, else unchanged.
        if (array_key_exists('region_id', $fields)) {
            $regionId = (string) $fields['region_id'];
            if ($regionId !== RegionService::NONE && $regionId !== $storedRegion && !$this->usable($regionId)) {
                throw $in->notFound('region_id');
            }
        } elseif ($pointSent) {
            $regionId = $this->regionOfBase($lat, $lng, $storedRegion);
        } else {
            $regionId = (string) $storedRegion;
        }
        $regionChanged = $regionId !== $storedRegion;

        $zone = $this->zone($regionId, $fields['timezone'] ?? null, $existing, $warnings);

        // Where the base is. Asked again whenever the answer may have changed, and when a state was sent.
        $relocate = $existing === null || $pointSent || $regionChanged || array_key_exists('state', $base);
        $state = null;
        $county = null;
        if ($relocate) {
            $located = Registry::capture()->locate($regionId, $lat, $lng);
            // A point that did not move keeps what was known about it when the data cannot tell today.
            $state = $located['state'] ?? $base['state'] ?? ($moved ? null : $existing['base_state']);
            $county = $located['county_fips'] ?? ($moved ? null : $existing['base_county_fips']);
            if ($regionId !== RegionService::NONE && ($located['in_region'] ?? false) !== true) {
                $warnings[] = self::BASE_OUTSIDE_REGION;
            }
        }

        $counties = $this->licenceCounties($in, $fields, $regionId, $regionChanged ? $existing : null);

        if ($existing === null) {
            $profile = array_replace($this->mapper->defaults(), $fields);
            $profile['daypart_fit'] = array_replace($this->mapper->defaults()['daypart_fit'], $fields['daypart_fit'] ?? []);
            $columns = $this->mapper->toColumns($profile);
        } else {
            $columns = $this->mapper->toColumns($fields);
        }
        if ($regionChanged) {
            $columns['region_id'] = $regionId;
        }
        if ($existing === null || $zone !== $existing['timezone']) {
            $columns['timezone'] = $zone;
        }
        if ($relocate) {
            $columns['base_state'] = $state;
            $columns['base_county_fips'] = $county;
        }
        if ($counties !== null) {
            $columns['licence_counties'] = $counties;
        }
        return [$columns, $warnings];
    }

    /**
     * The region of a base point that is saved without a region id. The truck stays in its present region
     * while that region's box holds the point, so a region that was chosen survives a move inside it.
     * Otherwise it is the default: the first region (ascending id) whose box holds the point, else `none`.
     */
    private function regionOfBase(float $lat, float $lng, ?string $present): string
    {
        $box = $present === null ? null : ($this->regions->info($present)['bbox'] ?? null);
        if (is_array($box)
            && $lat >= $box['lat_min'] && $lat <= $box['lat_max']
            && $lng >= $box['lng_min'] && $lng <= $box['lng_max']) {
            return (string) $present;
        }
        return $this->regions->regionForPoint($lat, $lng);
    }

    /**
     * May a truck choose this region? Only one whose active dataset version can be used with this model.
     *
     * A region id is a short ASCII name. Other text is no region and is not looked up: MySQL refuses to
     * compare text outside ASCII with the id column, and that refusal would surface as a server error.
     */
    private function usable(string $regionId): bool
    {
        if (preg_match('/\A[\x21-\x7E]{1,24}\z/', $regionId) !== 1) {
            return false;
        }
        $active = $this->regions->active($regionId);
        return $active !== null && $active['usable'] === true;
    }

    /**
     * The truck's time zone: the region's. A truck without a region has the zone that was sent, else the
     * zone it already has; a new one gets the default zone, and the answer says that it was assumed.
     *
     * @param array<string, mixed>|null $existing
     * @param list<string> $warnings
     */
    private function zone(string $regionId, ?string $sent, ?array $existing, array &$warnings): string
    {
        if ($regionId !== RegionService::NONE) {
            $zone = (string) ($this->regions->info($regionId)['timezone'] ?? '');
            if (Clock::isZone($zone)) {
                return $zone;
            }
        } elseif ($sent !== null) {
            return $sent;
        }
        if ($existing !== null) {
            return (string) $existing['timezone'];
        }
        $warnings[] = self::TIMEZONE_ASSUMED;
        return (string) TpConfig::get('regions.default_timezone');
    }

    /**
     * The licence counties to store, or null to leave the column as it is.
     *
     * A list that was sent must name counties of the truck's region (a truck without a region takes any
     * code). When the region changes and no list was sent, the stored codes that are not counties of the
     * new region are dropped: they would filter every place of the new region away.
     *
     * @param array<string, mixed> $fields the validated fields of the body
     * @param array<string, mixed>|null $changedFrom the stored row when this save changes the region
     * @return list<string>|null
     */
    private function licenceCounties(Input $in, array $fields, string $regionId, ?array $changedFrom): ?array
    {
        if ($regionId === RegionService::NONE) {
            return $fields['licence_counties'] ?? null;
        }
        if (array_key_exists('licence_counties', $fields)) {
            $known = $this->regions->countyFips($regionId);
            $sent = $in->each('licence_counties');
            foreach ($sent->all() as $i => $code) {
                if (!in_array(trim((string) $code), $known, true)) {
                    throw $sent->error($i, 'is not a county of this region');
                }
            }
            return $fields['licence_counties'];
        }
        if ($changedFrom === null) {
            return null;
        }
        $stored = array_values((array) $changedFrom['licence_counties']);
        $kept = array_values(array_intersect($stored, $this->regions->countyFips($regionId)));
        return $kept === $stored ? null : $kept;
    }
}
