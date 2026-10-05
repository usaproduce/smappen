<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\ProfileMapper;
use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\TpInvalid;
use PHPUnit\Framework\TestCase;

final class ProfileMapperTest extends TestCase
{
    /** The keys of TruckProfile in the order of 02_MODEL.md section 3. */
    private const PROFILE_KEYS = [
        'name', 'region_id', 'base', 'avg_ticket', 'capacity_orders_per_hour', 'paid_crew', 'wage_per_hour',
        'payroll_burden_pct', 'food_cost_pct', 'packaging_per_order', 'card_fee_pct', 'card_fee_fixed', 'card_share',
        'tips_include', 'tips_pct_of_card_sales', 'mpg', 'fuel_type', 'fuel_price_override', 'generator_gal_per_hour',
        'prep_minutes', 'setup_minutes', 'teardown_minutes', 'closeout_minutes', 'fixed_cost_per_service_day',
        'daypart_fit', 'avoid_tolls', 'avoid_highways', 'truck_time_factor', 'licence_counties', 'scout_drive_minutes_limit',
    ];

    /**
     * A normalised row as TruckRepository::findByOrg() returns it.
     *
     * @return array<string, mixed>
     */
    private static function row(): array
    {
        return [
            'id' => '33333333-3333-4333-8333-333333333333',
            'organization_id' => '11111111-1111-4111-8111-111111111111',
            'created_by' => null,
            'name' => 'Smoke & Ember', 'region_id' => 'dc', 'timezone' => 'America/New_York',
            'base_lat' => 39.003, 'base_lng' => -77.405, 'base_address' => 'Sterling, VA',
            'base_state' => 'VA', 'base_county_fips' => '51107',
            'avg_ticket' => 15.0, 'capacity_orders_per_hour' => 45.0, 'paid_crew' => 2, 'wage' => 18.0,
            'payroll_burden_pct' => 0.1, 'food_cost_pct' => 0.3, 'packaging' => 0.5, 'card_fee_pct' => 0.026,
            'card_fee_fixed' => 0.15, 'card_share' => 0.85, 'tips_include' => false, 'tips_pct' => 0.1, 'mpg' => 9.0,
            'fuel_type' => 'gasoline', 'fuel_price_override' => null, 'generator_gal_per_hour' => 0.6,
            'prep_minutes' => 45, 'setup_minutes' => 30, 'teardown_minutes' => 20, 'closeout_minutes' => 30,
            'fixed_cost_day' => 0.0, 'fit_breakfast' => 0.3, 'fit_lunch' => 1.0, 'fit_dinner' => 1.0, 'fit_late' => 0.8,
            'avoid_tolls' => false, 'avoid_highways' => false, 'truck_time_factor' => 1.1,
            'licence_counties' => ['51107', '51059'], 'scout_drive_minutes_limit' => 45,
            'overrides' => ['host.captive_share' => 0.6], 'overrides_seeds_rev' => 1,
            'created_at' => '2026-10-04 23:50:12', 'updated_at' => '2026-10-04 23:58:40',
        ];
    }

    private static function input(string $json): Input
    {
        return new Input(json_decode($json, true, 64, JSON_THROW_ON_ERROR));
    }

    private static function assertInvalid(string $message, ?string $field, ?string $rule, string $json, bool $partial = false): void
    {
        try {
            (new ProfileMapper())->validate(self::input($json), $partial);
        } catch (TpInvalid $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame($field, $e->field());
            self::assertSame($rule, $e->rule());
            return;
        }
        self::fail('accepted: ' . $json);
    }

    // ------------------------------------------------------------------------------------ defaults

    public function testDefaultsAreTheSeedGroupAndNothingElse(): void
    {
        $defaults = (new ProfileMapper())->defaults();
        self::assertSame(array_slice(self::PROFILE_KEYS, 3), array_keys($defaults));

        $A = Seeds::defaults();
        foreach ($defaults as $field => $value) {
            if ($field === 'fuel_price_override') {
                self::assertNull($value);
            } elseif ($field === 'licence_counties') {
                self::assertSame([], $value);
            } elseif ($field === 'daypart_fit') {
                self::assertSame(['breakfast', 'lunch', 'dinner', 'late'], array_keys($value));
                foreach ($value as $daypart => $fit) {
                    self::assertSame((float) Estimator::seed($A, 'profile_defaults.daypart_fit.' . $daypart), $fit);
                }
            } else {
                self::assertEquals(Estimator::seed($A, 'profile_defaults.' . $field), $value, $field);
            }
        }
    }

    public function testDefaultsOfRevisionOne(): void
    {
        $defaults = (new ProfileMapper())->defaults();
        self::assertSame(15.0, $defaults['avg_ticket']);
        self::assertSame(45.0, $defaults['capacity_orders_per_hour']);
        self::assertSame(2, $defaults['paid_crew']);
        self::assertSame(18.0, $defaults['wage_per_hour']);
        self::assertSame(0.3, $defaults['food_cost_pct']);
        self::assertFalse($defaults['tips_include']);
        self::assertSame('gasoline', $defaults['fuel_type']);
        self::assertSame(45, $defaults['prep_minutes']);
        self::assertSame(['breakfast' => 0.3, 'lunch' => 1.0, 'dinner' => 1.0, 'late' => 0.8], $defaults['daypart_fit']);
        self::assertSame(1.1, $defaults['truck_time_factor']);
        self::assertSame(45, $defaults['scout_drive_minutes_limit']);
    }

    // ------------------------------------------------------------------------------------ row -> record

    public function testToRecordIsTheTruckRecordOfTheApi(): void
    {
        $record = (new ProfileMapper())->toRecord(self::row());
        self::assertSame(['id', 'timezone', 'base_state', 'base_county_fips', 'profile', 'created_at', 'updated_at'], array_keys($record));
        self::assertSame('33333333-3333-4333-8333-333333333333', $record['id']);
        self::assertSame('America/New_York', $record['timezone']);
        self::assertSame('VA', $record['base_state']);
        self::assertSame('51107', $record['base_county_fips']);
        self::assertSame('2026-10-04 23:58:40', $record['updated_at']);

        $profile = $record['profile'];
        self::assertSame(self::PROFILE_KEYS, array_keys($profile));
        self::assertSame('Smoke & Ember', $profile['name']);
        self::assertSame('dc', $profile['region_id']);
        self::assertSame(['lat' => 39.003, 'lng' => -77.405, 'address' => 'Sterling, VA'], $profile['base']);
        self::assertSame(15.0, $profile['avg_ticket']);
        self::assertSame(18.0, $profile['wage_per_hour']);
        self::assertSame(0.5, $profile['packaging_per_order']);
        self::assertSame(0.15, $profile['card_fee_fixed']);
        self::assertSame(0.1, $profile['tips_pct_of_card_sales']);
        self::assertSame(0.0, $profile['fixed_cost_per_service_day']);
        self::assertNull($profile['fuel_price_override']);
        self::assertSame(['breakfast' => 0.3, 'lunch' => 1.0, 'dinner' => 1.0, 'late' => 0.8], $profile['daypart_fit']);
        self::assertSame(['51107', '51059'], $profile['licence_counties']);
        self::assertSame(2, $profile['paid_crew']);
        self::assertFalse($profile['tips_include']);
    }

    public function testTheRecordHidesWhatBelongsToTheServer(): void
    {
        $record = (new ProfileMapper())->toRecord(self::row());
        $flat = json_encode($record);
        self::assertIsString($flat);
        foreach (['organization_id', 'overrides', 'created_by', 'wage_cents', 'fit_lunch', 'base_lat'] as $internal) {
            self::assertStringNotContainsString($internal, $flat);
        }
    }

    public function testTheTruckValueOfTheBaseControllerMapsToTheSameRecord(): void
    {
        $mapper = new ProfileMapper();
        $row = self::row();
        $truck = $row + ['profile' => $mapper->toRecord($row)['profile']];
        self::assertSame($mapper->toRecord($row), $mapper->toRecord($truck));
    }

    public function testTheProfileIsWhatTheModelTakes(): void
    {
        $profile = (new ProfileMapper())->toRecord(self::row())['profile'];
        $terms = ['spot_id' => null, 'visibility' => 'normal', 'host' => null, 'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null];
        $margins = Estimator::unitMargins($profile, $terms);
        self::assertGreaterThan(0.0, $margins['at_minimum']);
        self::assertSame('d', \App\TruckPlanner\Data\LegKey::routeKey($profile));
    }

    // ------------------------------------------------------------------------------------ profile -> columns

    public function testToColumnsIsTheInverseOfToRecord(): void
    {
        $mapper = new ProfileMapper();
        $row = self::row();
        $columns = $mapper->toColumns($mapper->toRecord($row)['profile']);
        foreach ($columns as $key => $value) {
            self::assertArrayHasKey($key, TruckRepository::COLUMNS, $key . ' is not a column of tp_trucks');
            self::assertSame($row[$key], $value, $key);
        }
        // With the three columns the profile service derives, a profile fills every column create() needs.
        $given = array_keys($columns + ['timezone' => 1, 'base_state' => 1, 'base_county_fips' => 1]);
        sort($given);
        $needed = array_keys(TruckRepository::COLUMNS);
        sort($needed);
        self::assertSame($needed, $given);
    }

    public function testDefaultsPlusTheThreeRequiredFieldsMakeACompleteProfile(): void
    {
        $mapper = new ProfileMapper();
        $profile = ['name' => 'Smoke & Ember', 'region_id' => 'none', 'base' => ['lat' => 39.003, 'lng' => -77.405, 'address' => '']]
            + $mapper->defaults();
        $profile['avg_ticket'] = 12.5;
        self::assertSame(self::PROFILE_KEYS, array_keys($profile));
        $columns = $mapper->toColumns($profile);
        self::assertCount(count(TruckRepository::COLUMNS) - 3, $columns);
        self::assertSame(12.5, $columns['avg_ticket']);
        self::assertSame(18.0, $columns['wage']);
        self::assertSame(0.8, $columns['fit_late']);
        self::assertNull($columns['fuel_price_override']);
        self::assertSame([], $columns['licence_counties']);
    }

    public function testToColumnsMapsOnlyWhatIsGiven(): void
    {
        $mapper = new ProfileMapper();
        self::assertSame([], $mapper->toColumns([]));
        self::assertSame(['wage' => 21.0], $mapper->toColumns(['wage_per_hour' => 21]));
        self::assertSame(['base_address' => 'Lot 4'], $mapper->toColumns(['base' => ['address' => 'Lot 4']]));
        self::assertSame(['fit_late' => 0.5], $mapper->toColumns(['daypart_fit' => ['late' => 0.5]]));
        self::assertSame(['fuel_price_override' => null], $mapper->toColumns(['fuel_price_override' => null]));
        self::assertSame(
            ['name' => 'X', 'base_lat' => 38.9, 'base_lng' => -77.0, 'tips_include' => true, 'tips_pct' => 0.12],
            $mapper->toColumns(['name' => 'X', 'base' => ['lat' => 38.9, 'lng' => -77], 'tips_include' => true, 'tips_pct_of_card_sales' => 0.12, 'timezone' => 'UTC', 'unknown' => 1])
        );
    }

    // ------------------------------------------------------------------------------------ validation: create

    public function testANewTruckNeedsThreeFields(): void
    {
        $fields = (new ProfileMapper())->validate(
            self::input('{"name":"Smoke & Ember","base":{"lat":39.003,"lng":-77.405,"address":"Sterling, VA"},"avg_ticket":15}'),
            false
        );
        self::assertSame(
            ['name' => 'Smoke & Ember', 'base' => ['lat' => 39.003, 'lng' => -77.405, 'address' => 'Sterling, VA'], 'avg_ticket' => 15.0],
            $fields
        );
        $fields = (new ProfileMapper())->validate(self::input('{"name":" Truck ","base":{"lat":39,"lng":-77},"avg_ticket":9.5}'), false);
        self::assertSame(['name' => 'Truck', 'base' => ['lat' => 39.0, 'lng' => -77.0, 'address' => ''], 'avg_ticket' => 9.5], $fields);
    }

    public function testTheRequiredFieldsOfANewTruck(): void
    {
        self::assertInvalid('name is required', 'name', 'V1', '{"base":{"lat":39,"lng":-77},"avg_ticket":15}');
        self::assertInvalid('name is required', 'name', 'V1', '{"name":"  ","base":{"lat":39,"lng":-77},"avg_ticket":15}');
        self::assertInvalid('name is required', 'name', 'V1', '{"name":null,"base":{"lat":39,"lng":-77},"avg_ticket":15}');
        self::assertInvalid('base is required', 'base', 'V1', '{"name":"T","avg_ticket":15}');
        self::assertInvalid('base must be an object', 'base', 'V9', '{"name":"T","base":"Sterling","avg_ticket":15}');
        self::assertInvalid(
            'base must have lat between -90 and 90 and lng between -180 and 180',
            'base',
            'V10',
            '{"name":"T","base":{"address":"Sterling"},"avg_ticket":15}'
        );
        self::assertInvalid(
            'base must have lat between -90 and 90 and lng between -180 and 180',
            'base',
            'V10',
            '{"name":"T","base":{"lat":139,"lng":-77},"avg_ticket":15}'
        );
        self::assertInvalid('avg_ticket is required', 'avg_ticket', 'V1', '{"name":"T","base":{"lat":39,"lng":-77}}');
        self::assertInvalid('name must be text of at most 120 characters', 'name', 'V5', json_encode(['name' => str_repeat('n', 121), 'base' => ['lat' => 39, 'lng' => -77], 'avg_ticket' => 15]));
        self::assertInvalid('base.address must be text of at most 255 characters', 'base.address', 'V5', json_encode(['name' => 'T', 'base' => ['lat' => 39, 'lng' => -77, 'address' => str_repeat('a', 256)], 'avg_ticket' => 15]));
    }

    public function testEveryNumericFieldIsHeldToItsSeedRange(): void
    {
        $base = '"name":"T","base":{"lat":39,"lng":-77},"avg_ticket":15';
        $cases = [
            ['avg_ticket must be a number between 1 and 200', 'V2', '{"name":"T","base":{"lat":39,"lng":-77},"avg_ticket":0.5}'],
            ['avg_ticket must be a number between 1 and 200', 'V2', '{"name":"T","base":{"lat":39,"lng":-77},"avg_ticket":"15"}'],
            ['capacity_orders_per_hour must be a number between 0 and 400', 'V2', '{' . $base . ',"capacity_orders_per_hour":401}'],
            ['paid_crew must be a whole number between 0 and 12', 'V3', '{' . $base . ',"paid_crew":13}'],
            ['paid_crew must be a whole number between 0 and 12', 'V3', '{' . $base . ',"paid_crew":1.5}'],
            ['wage_per_hour must be a number between 0 and 200', 'V2', '{' . $base . ',"wage_per_hour":-1}'],
            ['payroll_burden_pct must be a number between 0 and 1', 'V2', '{' . $base . ',"payroll_burden_pct":1.2}'],
            ['food_cost_pct must be a number between 0 and 0.95', 'V2', '{' . $base . ',"food_cost_pct":0.96}'],
            ['packaging_per_order must be a number between 0 and 20', 'V2', '{' . $base . ',"packaging_per_order":21}'],
            ['card_fee_pct must be a number between 0 and 0.2', 'V2', '{' . $base . ',"card_fee_pct":0.3}'],
            ['card_fee_fixed must be a number between 0 and 5', 'V2', '{' . $base . ',"card_fee_fixed":6}'],
            ['card_share must be a number between 0 and 1', 'V2', '{' . $base . ',"card_share":1.01}'],
            ['tips_pct_of_card_sales must be a number between 0 and 0.5', 'V2', '{' . $base . ',"tips_pct_of_card_sales":0.6}'],
            ['mpg must be a number between 1 and 60', 'V2', '{' . $base . ',"mpg":0}'],
            ['generator_gal_per_hour must be a number between 0 and 5', 'V2', '{' . $base . ',"generator_gal_per_hour":5.5}'],
            ['prep_minutes must be a whole number between 0 and 600', 'V3', '{' . $base . ',"prep_minutes":601}'],
            ['setup_minutes must be a whole number between 0 and 240', 'V3', '{' . $base . ',"setup_minutes":-1}'],
            ['teardown_minutes must be a whole number between 0 and 240', 'V3', '{' . $base . ',"teardown_minutes":241}'],
            ['closeout_minutes must be a whole number between 0 and 600', 'V3', '{' . $base . ',"closeout_minutes":"30"}'],
            ['fixed_cost_per_service_day must be a number between 0 and 5000', 'V2', '{' . $base . ',"fixed_cost_per_service_day":5001}'],
            ['truck_time_factor must be a number between 0.5 and 3', 'V2', '{' . $base . ',"truck_time_factor":0.4}'],
            ['scout_drive_minutes_limit must be a whole number between 5 and 60', 'V3', '{' . $base . ',"scout_drive_minutes_limit":61}'],
            ['fuel_price_override must be a number between 0.5 and 20', 'V2', '{' . $base . ',"fuel_price_override":0.4}'],
        ];
        foreach ($cases as [$message, $rule, $json]) {
            self::assertInvalid($message, explode(' ', $message)[0], $rule, $json);
        }
    }

    public function testTheOtherFields(): void
    {
        $base = '"name":"T","base":{"lat":39,"lng":-77},"avg_ticket":15';
        self::assertInvalid('tips_include must be true or false', 'tips_include', 'V6', '{' . $base . ',"tips_include":1}');
        self::assertInvalid('avoid_tolls must be true or false', 'avoid_tolls', 'V6', '{' . $base . ',"avoid_tolls":"no"}');
        self::assertInvalid('fuel_type must be one of: gasoline, diesel', 'fuel_type', 'V4', '{' . $base . ',"fuel_type":"electric"}');
        self::assertInvalid('daypart_fit must be an object', 'daypart_fit', 'V9', '{' . $base . ',"daypart_fit":[1,1,1,1]}');
        self::assertInvalid('daypart_fit.lunch must be a number between 0 and 1', 'daypart_fit.lunch', 'V2', '{' . $base . ',"daypart_fit":{"lunch":1.5}}');
        self::assertInvalid('licence_counties must be a list of 0 to 60 items', 'licence_counties', 'V8', '{' . $base . ',"licence_counties":"51107"}');
        self::assertInvalid('licence_counties must be a list of 0 to 60 items', 'licence_counties', 'V8', json_encode(['name' => 'T', 'base' => ['lat' => 39, 'lng' => -77], 'avg_ticket' => 15, 'licence_counties' => array_fill(0, 61, '51107')]));
        self::assertInvalid('licence_counties[1] must be text of at most 5 characters', 'licence_counties[1]', 'V5', '{' . $base . ',"licence_counties":["51107",51059]}');
        self::assertInvalid('licence_counties[0] must be a 5-digit county code', 'licence_counties[0]', null, '{' . $base . ',"licence_counties":["5110"]}');
        self::assertInvalid('licence_counties[2] must be a 5-digit county code', 'licence_counties[2]', null, '{' . $base . ',"licence_counties":["51107","51059","5110x"]}');
        self::assertInvalid('timezone must be an IANA time zone name', 'timezone', null, '{' . $base . ',"timezone":"Eastern"}');
        self::assertInvalid('timezone must be an IANA time zone name', 'timezone', null, '{' . $base . ',"timezone":5}');
        self::assertInvalid('base.state must be a two-letter state code', 'base.state', null, '{"name":"T","base":{"lat":39,"lng":-77,"state":"V1"},"avg_ticket":15}');
        self::assertInvalid('base.state must be text of at most 2 characters', 'base.state', 'V5', '{"name":"T","base":{"lat":39,"lng":-77,"state":"Virginia"},"avg_ticket":15}');
        self::assertInvalid('region_id must be text of at most 24 characters', 'region_id', 'V5', '{' . $base . ',"region_id":7}');
    }

    public function testAFullBodyComesBackInProfileShape(): void
    {
        $json = '{"name":"Smoke & Ember","region_id":"dc","timezone":"America/Chicago",'
            . '"base":{"lat":39.003,"lng":-77.405,"address":" Sterling, VA ","state":"va"},'
            . '"avg_ticket":15,"capacity_orders_per_hour":45,"paid_crew":2,"wage_per_hour":18,"payroll_burden_pct":0.1,'
            . '"food_cost_pct":0.3,"packaging_per_order":0.5,"card_fee_pct":0.026,"card_fee_fixed":0.15,"card_share":0.85,'
            . '"tips_include":true,"tips_pct_of_card_sales":0.1,"mpg":9,"fuel_type":"diesel","fuel_price_override":4.195,'
            . '"generator_gal_per_hour":0.6,"prep_minutes":45,"setup_minutes":30.0,"teardown_minutes":20,"closeout_minutes":30,'
            . '"fixed_cost_per_service_day":25,"daypart_fit":{"breakfast":0,"lunch":1,"dinner":1,"late":0.8},'
            . '"avoid_tolls":false,"avoid_highways":true,"truck_time_factor":1.1,'
            . '"licence_counties":["51107","51059","51107"],"scout_drive_minutes_limit":45,"something_else":true}';
        $fields = (new ProfileMapper())->validate(self::input($json), false);

        self::assertSame('dc', $fields['region_id']);
        self::assertSame('America/Chicago', $fields['timezone']);
        self::assertSame(['lat' => 39.003, 'lng' => -77.405, 'address' => 'Sterling, VA', 'state' => 'VA'], $fields['base']);
        self::assertSame(15.0, $fields['avg_ticket']);
        self::assertSame(2, $fields['paid_crew']);
        self::assertSame(30, $fields['setup_minutes']);
        self::assertTrue($fields['tips_include']);
        self::assertTrue($fields['avoid_highways']);
        self::assertSame('diesel', $fields['fuel_type']);
        self::assertSame(4.195, $fields['fuel_price_override']);
        self::assertSame(['breakfast' => 0.0, 'lunch' => 1.0, 'dinner' => 1.0, 'late' => 0.8], $fields['daypart_fit']);
        self::assertSame(['51107', '51059'], $fields['licence_counties']);
        self::assertArrayNotHasKey('something_else', $fields);

        // every profile field is there, and the two request-only keys are left alone by toColumns()
        foreach (self::PROFILE_KEYS as $key) {
            self::assertArrayHasKey($key, $fields);
        }
        $columns = (new ProfileMapper())->toColumns($fields);
        self::assertArrayNotHasKey('timezone', $columns);
        self::assertArrayNotHasKey('base_state', $columns);
        self::assertSame('Sterling, VA', $columns['base_address']);
    }

    // ------------------------------------------------------------------------------------ validation: update

    public function testAnUpdateChangesOnlyTheKeysItCarries(): void
    {
        $mapper = new ProfileMapper();
        self::assertSame(['wage_per_hour' => 21.5], $mapper->validate(self::input('{"wage_per_hour":21.5}'), true));
        self::assertSame(['base' => ['address' => 'Lot 4']], $mapper->validate(self::input('{"base":{"address":"Lot 4"}}'), true));
        self::assertSame(['base' => ['lat' => 38.9, 'lng' => -77.0]], $mapper->validate(self::input('{"base":{"lat":38.9,"lng":-77}}'), true));
        self::assertSame(['daypart_fit' => ['late' => 0.5]], $mapper->validate(self::input('{"daypart_fit":{"late":0.5}}'), true));
        self::assertSame(['fuel_price_override' => null], $mapper->validate(self::input('{"fuel_price_override":null}'), true));
        self::assertSame(['licence_counties' => []], $mapper->validate(self::input('{"licence_counties":[]}'), true));
        self::assertSame(['timezone' => 'America/Denver'], $mapper->validate(self::input('{"timezone":"America/Denver"}'), true));
        self::assertSame(['name' => 'New name', 'tips_include' => false], $mapper->validate(self::input('{"name":"New name","tips_include":false}'), true));
    }

    public function testAnUpdateWithNoKnownKeyIsRefused(): void
    {
        self::assertInvalid('Nothing to update', null, 'V12', '{}', true);
        self::assertInvalid('Nothing to update', null, 'V12', '{"colour":"red"}', true);
    }

    public function testAnUpdateCannotClearWhatMustHaveAValue(): void
    {
        self::assertInvalid('name is required', 'name', 'V1', '{"name":null}', true);
        self::assertInvalid('name is required', 'name', 'V1', '{"name":""}', true);
        self::assertInvalid('avg_ticket is required', 'avg_ticket', 'V1', '{"avg_ticket":null}', true);
        self::assertInvalid('tips_include is required', 'tips_include', 'V1', '{"tips_include":null}', true);
        self::assertInvalid('daypart_fit.lunch is required', 'daypart_fit.lunch', 'V1', '{"daypart_fit":{"lunch":null}}', true);
        self::assertInvalid('base is required', 'base', 'V1', '{"base":null}', true);
        self::assertInvalid(
            'base must have lat between -90 and 90 and lng between -180 and 180',
            'base',
            'V10',
            '{"base":{"lat":38.9}}',
            true
        );
    }

    // ------------------------------------------------------------------------------------ the whole way round

    public function testFromABodyToTheRowAndBack(): void
    {
        $mapper = new ProfileMapper();
        $fields = $mapper->validate(self::input('{"name":"Smoke & Ember","base":{"lat":39.003,"lng":-77.405,"address":"Sterling, VA"},"avg_ticket":15.999}'), false);
        $profile = array_replace($mapper->defaults(), $fields) + ['region_id' => 'none'];
        $columns = $mapper->toColumns($profile) + ['timezone' => 'America/New_York', 'base_state' => null, 'base_county_fips' => null];

        // what the repository would give back after storing cents
        $row = $columns + [
            'id' => 't1', 'organization_id' => 'o1', 'created_by' => null, 'overrides' => [], 'overrides_seeds_rev' => 1,
            'created_at' => '2026-10-04 23:50:12', 'updated_at' => '2026-10-04 23:50:12',
        ];
        $row['avg_ticket'] = 16.0;

        $record = $mapper->toRecord($row);
        self::assertSame(16.0, $record['profile']['avg_ticket']);
        self::assertSame('Smoke & Ember', $record['profile']['name']);
        self::assertSame('none', $record['profile']['region_id']);
        self::assertSame($mapper->defaults()['daypart_fit'], $record['profile']['daypart_fit']);
        self::assertNull($record['base_state']);
    }
}
