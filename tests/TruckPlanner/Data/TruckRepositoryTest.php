<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\TruckRepository;
use PHPUnit\Framework\TestCase;

final class TruckRepositoryTest extends TestCase
{
    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const USER = '22222222-2222-4222-8222-222222222222';
    private const TRUCK = '33333333-3333-4333-8333-333333333333';

    /**
     * A complete normalised row as a caller gives it to create().
     *
     * @return array<string, mixed>
     */
    private static function row(): array
    {
        return [
            'name' => 'Smoke & Ember',
            'region_id' => 'dc',
            'timezone' => 'America/New_York',
            'base_lat' => 39.00300000000001,
            'base_lng' => -77.405,
            'base_address' => 'Sterling, VA',
            'base_state' => 'VA',
            'base_county_fips' => '51107',
            'avg_ticket' => 15.0,
            'capacity_orders_per_hour' => 45.0,
            'paid_crew' => 2,
            'wage' => 18.5,
            'payroll_burden_pct' => 0.1,
            'food_cost_pct' => 0.3,
            'packaging' => 0.5,
            'card_fee_pct' => 0.026,
            'card_fee_fixed' => 0.15,
            'card_share' => 0.85,
            'tips_include' => false,
            'tips_pct' => 0.1,
            'mpg' => 9.0,
            'fuel_type' => 'gasoline',
            'fuel_price_override' => 4.195,
            'generator_gal_per_hour' => 0.6,
            'prep_minutes' => 45,
            'setup_minutes' => 30,
            'teardown_minutes' => 20,
            'closeout_minutes' => 30,
            'fixed_cost_day' => 0.0,
            'fit_breakfast' => 0.3,
            'fit_lunch' => 1.0,
            'fit_dinner' => 1.0,
            'fit_late' => 0.8,
            'avoid_tolls' => true,
            'avoid_highways' => false,
            'truck_time_factor' => 1.1,
            'licence_counties' => ['51107', '51059'],
            'scout_drive_minutes_limit' => 45,
        ];
    }

    /**
     * The same truck as MySQL returns it.
     *
     * @return array<string, mixed>
     */
    private static function databaseRow(): array
    {
        return [
            'id' => self::TRUCK, 'organization_id' => self::ORG, 'created_by' => self::USER,
            'name' => 'Smoke & Ember', 'region_id' => 'dc', 'timezone' => 'America/New_York',
            'base_lat' => 39.00300000000001, 'base_lng' => -77.405, 'base_address' => 'Sterling, VA',
            'base_state' => 'VA', 'base_county_fips' => '51107',
            'avg_ticket_cents' => 1500, 'capacity_orders_per_hour' => 45.0, 'paid_crew' => 2, 'wage_cents' => 1850,
            'payroll_burden_pct' => 0.1, 'food_cost_pct' => 0.3, 'packaging_cents' => 50, 'card_fee_pct' => 0.026,
            'card_fee_fixed_cents' => 15, 'card_share' => 0.85, 'tips_include' => 0, 'tips_pct' => 0.1, 'mpg' => 9.0,
            'fuel_type' => 'gasoline', 'fuel_price_override_milli' => 4195, 'generator_gal_per_hour' => 0.6,
            'prep_minutes' => 45, 'setup_minutes' => 30, 'teardown_minutes' => 20, 'closeout_minutes' => 30,
            'fixed_cost_day_cents' => 0, 'fit_breakfast' => 0.3, 'fit_lunch' => 1.0, 'fit_dinner' => 1.0,
            'fit_late' => 0.8, 'avoid_tolls' => 1, 'avoid_highways' => 0, 'truck_time_factor' => 1.1,
            'licence_counties_json' => '["51107", "51059"]', 'scout_drive_minutes_limit' => 45,
            'overrides_json' => '{"host.captive_share": 0.6}', 'overrides_seeds_rev' => 1,
            'created_at' => '2026-10-04 23:50:12', 'updated_at' => '2026-10-04 23:58:40',
        ];
    }

    public function testFindByOrgCarriesTheOrganizationAndNamesEveryColumn(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_trucks', self::databaseRow());
        $row = (new TruckRepository($db))->findByOrg(self::ORG);

        $call = $db->only('FROM tp_trucks');
        self::assertSame('fetch', $call['kind']);
        self::assertStringContainsString('WHERE organization_id = ?', $call['sql']);
        self::assertSame([self::ORG], $call['params']);
        self::assertStringNotContainsString('*', $call['sql']);
        foreach (['avg_ticket_cents', 'fuel_price_override_milli', 'licence_counties_json', 'overrides_json', 'overrides_seeds_rev', 'fit_late', 'updated_at'] as $column) {
            self::assertStringContainsString($column, $call['sql']);
        }
        self::assertIsArray($row);
    }

    public function testReadsAreNormalised(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_trucks', self::databaseRow());
        $row = (new TruckRepository($db))->findByOrg(self::ORG);
        self::assertIsArray($row);

        // identity and text
        self::assertSame(self::TRUCK, $row['id']);
        self::assertSame(self::ORG, $row['organization_id']);
        self::assertSame(self::USER, $row['created_by']);
        self::assertSame('Smoke & Ember', $row['name']);
        self::assertSame('VA', $row['base_state']);
        // cents and thousandths become dollars, under the name without the suffix
        self::assertSame(15.0, $row['avg_ticket']);
        self::assertSame(18.5, $row['wage']);
        self::assertSame(0.5, $row['packaging']);
        self::assertSame(0.15, $row['card_fee_fixed']);
        self::assertSame(0.0, $row['fixed_cost_day']);
        self::assertSame(4.195, $row['fuel_price_override']);
        foreach (['avg_ticket_cents', 'wage_cents', 'fuel_price_override_milli', 'licence_counties_json', 'overrides_json'] as $stored) {
            self::assertArrayNotHasKey($stored, $row);
        }
        // doubles are floats, whole numbers ints, flags booleans
        self::assertSame(39.00300000000001, $row['base_lat']);
        self::assertSame(45.0, $row['capacity_orders_per_hour']);
        self::assertSame(2, $row['paid_crew']);
        self::assertSame(45, $row['scout_drive_minutes_limit']);
        self::assertFalse($row['tips_include']);
        self::assertTrue($row['avoid_tolls']);
        self::assertFalse($row['avoid_highways']);
        // JSON is decoded
        self::assertSame(['51107', '51059'], $row['licence_counties']);
        self::assertSame(['host.captive_share' => 0.6], $row['overrides']);
        self::assertSame(1, $row['overrides_seeds_rev']);
        self::assertSame('2026-10-04 23:58:40', $row['updated_at']);
    }

    public function testStringsFromTheDriverAreCastToo(): void
    {
        $stored = self::databaseRow();
        foreach ($stored as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $stored[$key] = (string) $value;
            }
        }
        $stored['fuel_price_override_milli'] = null;
        $stored['base_state'] = null;
        $stored['overrides_json'] = '{}';
        $stored['licence_counties_json'] = '[]';
        $db = (new RecordingDatabase())->when('FROM tp_trucks', $stored);
        $row = (new TruckRepository($db))->findByOrg(self::ORG);
        self::assertIsArray($row);
        self::assertSame(15.0, $row['avg_ticket']);
        self::assertSame(2, $row['paid_crew']);
        self::assertTrue($row['avoid_tolls']);
        self::assertNull($row['fuel_price_override']);
        self::assertNull($row['base_state']);
        self::assertSame([], $row['overrides']);
        self::assertSame([], $row['licence_counties']);
    }

    public function testNoTruckIsNull(): void
    {
        $db = new RecordingDatabase();
        self::assertNull((new TruckRepository($db))->findByOrg(self::ORG));
    }

    public function testCreateWritesEveryColumnWithTheStorageEncodings(): void
    {
        $db = new RecordingDatabase();
        $id = (new TruckRepository($db))->create(self::ORG, self::USER, self::row());

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $call = $db->only('INSERT INTO tp_trucks');
        self::assertSame('query', $call['kind']);

        preg_match('/INSERT INTO tp_trucks \((.*?)\) VALUES \((.*)\)$/', $call['sql'], $m);
        $columns = array_map('trim', explode(',', $m[1]));
        $values = array_map('trim', explode(',', $m[2]));
        self::assertSame(count($columns), count($values));
        self::assertSame(['NOW()', 'NOW()'], array_slice($values, -2));
        self::assertSame(['created_at', 'updated_at'], array_slice($columns, -2));
        $bound = array_combine(array_slice($columns, 0, -2), $call['params']);

        // every column of the table is written: 43 bound plus the two timestamps
        self::assertCount(43, $bound);
        self::assertSame($id, $bound['id']);
        self::assertSame(self::ORG, $bound['organization_id']);
        self::assertSame(self::USER, $bound['created_by']);
        // dollars become whole cents, the fuel price thousandths
        self::assertSame(1500, $bound['avg_ticket_cents']);
        self::assertSame(1850, $bound['wage_cents']);
        self::assertSame(50, $bound['packaging_cents']);
        self::assertSame(15, $bound['card_fee_fixed_cents']);
        self::assertSame(0, $bound['fixed_cost_day_cents']);
        self::assertSame(4195, $bound['fuel_price_override_milli']);
        // floats travel as text that round-trips, never as PHP floats
        self::assertSame('39.00300000000001', $bound['base_lat']);
        self::assertSame('-77.405', $bound['base_lng']);
        self::assertSame('0.026', $bound['card_fee_pct']);
        self::assertSame('45', $bound['capacity_orders_per_hour']);
        foreach ($call['params'] as $value) {
            self::assertFalse(is_float($value), 'a PHP float was bound');
            self::assertFalse(is_bool($value), 'a PHP boolean was bound');
            self::assertFalse(is_array($value), 'an array was bound');
        }
        // booleans as 1 and 0
        self::assertSame(0, $bound['tips_include']);
        self::assertSame(1, $bound['avoid_tolls']);
        self::assertSame(0, $bound['avoid_highways']);
        // JSON by hand, an empty map as an object
        self::assertSame('["51107","51059"]', $bound['licence_counties_json']);
        self::assertSame('{}', $bound['overrides_json']);
        self::assertSame(1, $bound['overrides_seeds_rev']);
        self::assertSame(2, $bound['paid_crew']);
        self::assertSame('gasoline', $bound['fuel_type']);
    }

    public function testCreateRoundsHalfCentsAwayFromZeroAndAllowsTheNullableColumns(): void
    {
        $row = self::row();
        $row['avg_ticket'] = 12.345;
        $row['wage'] = 2.675;
        unset($row['base_state'], $row['base_county_fips'], $row['fuel_price_override']);
        $row['overrides'] = ['weather.floor' => 0.30000000000000004];
        $row['overrides_seeds_rev'] = 3;
        $db = new RecordingDatabase();
        (new TruckRepository($db))->create(self::ORG, null, $row);
        $call = $db->only('INSERT INTO tp_trucks');
        preg_match('/\((.*?)\) VALUES/', $call['sql'], $m);
        $bound = array_combine(array_slice(array_map('trim', explode(',', $m[1])), 0, -2), $call['params']);
        self::assertSame(1235, $bound['avg_ticket_cents']);
        self::assertSame(268, $bound['wage_cents']);
        self::assertNull($bound['created_by']);
        self::assertNull($bound['base_state']);
        self::assertNull($bound['base_county_fips']);
        self::assertNull($bound['fuel_price_override_milli']);
        self::assertSame('{"weather.floor":0.30000000000000004}', $bound['overrides_json']);
        self::assertSame(3, $bound['overrides_seeds_rev']);
    }

    public function testCreateRefusesAnIncompleteRow(): void
    {
        $row = self::row();
        unset($row['mpg']);
        $db = new RecordingDatabase();
        try {
            (new TruckRepository($db))->create(self::ORG, self::USER, $row);
            self::fail('an incomplete row was accepted');
        } catch (\LogicException $e) {
            self::assertStringContainsString('mpg', $e->getMessage());
        }
        self::assertSame([], $db->calls);
    }

    public function testUpdateChangesOnlyTheGivenColumnsOfThatOrganization(): void
    {
        $db = new RecordingDatabase();
        (new TruckRepository($db))->update(self::TRUCK, self::ORG, [
            'avg_ticket' => 16.25,
            'tips_include' => true,
            'base_lat' => 38.91006831234568,
            'fuel_price_override' => null,
            'licence_counties' => [],
        ]);
        $call = $db->only('UPDATE tp_trucks');
        self::assertSame(
            'UPDATE tp_trucks SET avg_ticket_cents = ?, tips_include = ?, base_lat = ?, fuel_price_override_milli = ?, '
            . 'licence_counties_json = ? WHERE id = ? AND organization_id = ?',
            $call['sql']
        );
        self::assertSame([1625, 1, '38.91006831234568', null, '[]', self::TRUCK, self::ORG], $call['params']);
    }

    public function testUpdateWithNothingDoesNothing(): void
    {
        $db = new RecordingDatabase();
        (new TruckRepository($db))->update(self::TRUCK, self::ORG, []);
        self::assertSame([], $db->calls);
    }

    public function testUpdateRefusesAKeyThatIsNotAColumn(): void
    {
        $db = new RecordingDatabase();
        foreach (['organization_id', 'id', 'avg_ticket_cents', 'overrides', 'created_at', 'name = name, id'] as $key) {
            try {
                (new TruckRepository($db))->update(self::TRUCK, self::ORG, [$key => 'x']);
                self::fail('"' . $key . '" was accepted as a column');
            } catch (\LogicException $e) {
                self::assertSame([], $db->calls);
            }
        }
    }

    public function testSetOverrides(): void
    {
        $db = new RecordingDatabase();
        $repo = new TruckRepository($db);
        $repo->setOverrides(self::TRUCK, self::ORG, ['host.captive_share' => 0.6, 'segments.res.dow_factor' => [1.0, 1.0, 1.0, 1.0, 0.9]], 1);
        $repo->setOverrides(self::TRUCK, self::ORG, [], 2);
        $calls = $db->find('UPDATE tp_trucks SET overrides_json = ?, overrides_seeds_rev = ? WHERE id = ? AND organization_id = ?');
        self::assertCount(2, $calls);
        self::assertSame(['{"host.captive_share":0.6,"segments.res.dow_factor":[1,1,1,1,0.9]}', 1, self::TRUCK, self::ORG], $calls[0]['params']);
        self::assertSame(['{}', 2, self::TRUCK, self::ORG], $calls[1]['params']);
    }

    public function testEveryStatementIsScopedToTheOrganization(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_trucks', self::databaseRow());
        $repo = new TruckRepository($db);
        $repo->findByOrg(self::ORG);
        $repo->create(self::ORG, self::USER, self::row());
        $repo->update(self::TRUCK, self::ORG, ['name' => 'New name']);
        $repo->setOverrides(self::TRUCK, self::ORG, [], 1);
        self::assertCount(4, $db->calls);
        foreach ($db->calls as $call) {
            self::assertContains(self::ORG, $call['params']);
            if (!str_starts_with($call['sql'], 'INSERT')) {
                self::assertStringContainsString('organization_id = ?', $call['sql']);
            }
            self::assertStringNotContainsString('SELECT *', $call['sql']);
        }
    }
}
