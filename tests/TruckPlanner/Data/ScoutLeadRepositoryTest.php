<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\ScoutLeadRepository;
use App\TruckPlanner\Services\Support\TpConfig;
use PHPUnit\Framework\TestCase;

final class ScoutLeadRepositoryTest extends TestCase
{
    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const TRUCK = '33333333-3333-4333-8333-333333333333';
    private const LEAD = '55555555-5555-4555-8555-555555555555';
    private const SPOT = '44444444-4444-4444-8444-444444444444';
    private const REGION = 'dc';
    private const KEY = 'w264230766';

    /** The Google content columns, which only a lookup writes and only the purge empties. */
    private const GOOGLE_TEXTS = ['g_name', 'g_address', 'g_phone', 'g_website', 'g_maps_uri'];

    protected function tearDown(): void
    {
        TpConfig::replace(null);
    }

    /**
     * A lead as MySQL returns it.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function databaseRow(array $over = []): array
    {
        return $over + [
            'id' => self::LEAD, 'organization_id' => self::ORG, 'truck_id' => self::TRUCK, 'region_id' => self::REGION,
            'place_key' => self::KEY, 'place_name' => 'Example Brewing', 'place_type' => 'taproom',
            'lat' => 39.010000000000005, 'lng' => -77.41, 'lead_state' => 'contacted', 'notes' => 'Call back Tuesday',
            'spot_id' => null, 'google_place_id' => null,
            'g_lookup_state' => null, 'g_name' => null, 'g_address' => null, 'g_phone' => null, 'g_website' => null,
            'g_maps_uri' => null, 'g_fetched_on' => null, 'g_age_hours' => null, 'g_fresh' => 0,
            'created_at' => '2026-10-04 23:50:12', 'updated_at' => '2026-10-05 08:01:00',
        ];
    }

    /**
     * The row above after a lookup that found the place two days ago.
     *
     * @return array<string, mixed>
     */
    private static function lookedUp(): array
    {
        return self::databaseRow([
            'google_place_id' => 'ChIJexample', 'g_lookup_state' => 'found', 'g_name' => 'Example Brewing Co',
            'g_address' => '1 Example Rd, Sterling, VA 20166', 'g_phone' => '(703) 555-0100',
            'g_website' => 'https://example.com/', 'g_maps_uri' => 'https://maps.google.com/?cid=1',
            'g_fetched_on' => '2026-10-03', 'g_age_hours' => '49', 'g_fresh' => '1',
        ]);
    }

    // ------------------------------------------------------------------------------------ reads

    public function testTheLeadsOfATruckAreReadByOrganizationTruckAndRegion(): void
    {
        $db = (new RecordingDatabase())->queue([
            self::databaseRow(),
            self::databaseRow(['id' => 'lead-2', 'place_key' => 'w9', 'lead_state' => 'hidden', 'notes' => null, 'lat' => null, 'lng' => null, 'place_name' => null, 'place_type' => null]),
        ]);
        $leads = (new ScoutLeadRepository($db))->forTruck(self::ORG, self::TRUCK, self::REGION);

        self::assertSame([self::KEY, 'w9'], array_keys($leads));
        self::assertSame(
            [
                'id' => self::LEAD, 'organization_id' => self::ORG, 'truck_id' => self::TRUCK, 'region_id' => self::REGION,
                'place_key' => self::KEY, 'place_name' => 'Example Brewing', 'place_type' => 'taproom',
                'lat' => 39.010000000000005, 'lng' => -77.41, 'status' => 'contacted', 'notes' => 'Call back Tuesday',
                'spot_id' => null, 'google_place_id' => null, 'google' => null,
                'created_at' => '2026-10-04 23:50:12', 'updated_at' => '2026-10-05 08:01:00',
            ],
            $leads[self::KEY]
        );
        self::assertSame('hidden', $leads['w9']['status']);
        self::assertNull($leads['w9']['lat']);
        self::assertNull($leads['w9']['place_name']);

        $call = $db->only('FROM tp_scout_leads');
        self::assertSame('fetchAll', $call['kind']);
        self::assertStringContainsString('WHERE organization_id = ? AND truck_id = ? AND region_id = ? ORDER BY place_key', $call['sql']);
        // the lifetime of Google content first, then the tenant, the truck and the region
        self::assertSame([30, self::ORG, self::TRUCK, self::REGION], $call['params']);
        self::assertStringNotContainsString('SELECT *', $call['sql']);
    }

    public function testFreshnessIsDecidedInSqlWithTheDatabaseClock(): void
    {
        $db = new RecordingDatabase();
        (new ScoutLeadRepository($db))->find(self::ORG, self::TRUCK, self::REGION, self::KEY);
        $sql = $db->only('FROM tp_scout_leads')['sql'];
        self::assertStringContainsString('(g_fetched_at IS NOT NULL AND g_fetched_at >= NOW() - INTERVAL ? DAY) AS g_fresh', $sql);
        self::assertStringContainsString('TIMESTAMPDIFF(HOUR, g_fetched_at, NOW()) AS g_age_hours', $sql);
        self::assertStringContainsString('DATE(g_fetched_at) AS g_fetched_on', $sql);
    }

    public function testOneLeadIsFoundByItsPlaceKey(): void
    {
        $db = (new RecordingDatabase())->queue(self::databaseRow());
        $lead = (new ScoutLeadRepository($db))->find(self::ORG, self::TRUCK, self::REGION, self::KEY);
        self::assertSame(self::LEAD, $lead['id']);
        self::assertSame('contacted', $lead['status']);

        $call = $db->only('FROM tp_scout_leads');
        self::assertSame('fetch', $call['kind']);
        self::assertStringContainsString('WHERE organization_id = ? AND truck_id = ? AND region_id = ? AND place_key = ?', $call['sql']);
        self::assertSame([30, self::ORG, self::TRUCK, self::REGION, self::KEY], $call['params']);

        self::assertNull((new ScoutLeadRepository(new RecordingDatabase()))->find(self::ORG, self::TRUCK, self::REGION, 'w0'));
    }

    public function testALookupYoungerThanItsLifetimeIsReadAsGoogleContent(): void
    {
        $db = (new RecordingDatabase())->queue(self::lookedUp());
        $lead = (new ScoutLeadRepository($db))->find(self::ORG, self::TRUCK, self::REGION, self::KEY);
        self::assertSame('ChIJexample', $lead['google_place_id']);
        self::assertSame(
            [
                'lookup_state' => 'found', 'name' => 'Example Brewing Co', 'address' => '1 Example Rd, Sterling, VA 20166',
                'phone' => '(703) 555-0100', 'website' => 'https://example.com/', 'maps_uri' => 'https://maps.google.com/?cid=1',
                'fetched_on' => '2026-10-03', 'age_hours' => 49,
            ],
            $lead['google']
        );
    }

    public function testAnOlderLookupReadsAsNoGoogleContentAndThePlaceIdStays(): void
    {
        // The row still holds its texts (the purge has not run yet); the database says it is not fresh.
        $db = (new RecordingDatabase())->queue(['g_fresh' => 0, 'g_age_hours' => 745, 'g_fetched_on' => '2026-09-04'] + self::lookedUp());
        $lead = (new ScoutLeadRepository($db))->find(self::ORG, self::TRUCK, self::REGION, self::KEY);
        self::assertNull($lead['google']);
        self::assertSame('ChIJexample', $lead['google_place_id']);
    }

    public function testTheLifetimeOfGoogleContentIsTheSetting(): void
    {
        $config = TpConfig::all();
        $config['places']['contact_ttl_days'] = 7;
        TpConfig::replace($config);
        $db = new RecordingDatabase();
        $repository = new ScoutLeadRepository($db);
        $repository->find(self::ORG, self::TRUCK, self::REGION, self::KEY);
        $repository->purgeExpiredGoogle(self::ORG);
        self::assertSame(7, $db->calls[0]['params'][0]);
        self::assertSame([self::ORG, 7], $db->only('SELECT COUNT(*)')['params']);
    }

    // ------------------------------------------------------------------------------------ upsert

    public function testTheFirstTouchInsertsTheLead(): void
    {
        $db = new RecordingDatabase();
        $id = (new ScoutLeadRepository($db))->upsert(self::ORG, self::TRUCK, self::REGION, self::KEY, [
            'notes' => 'Spoke to the manager',
            'place_name' => 'Example Brewing',
            'place_type' => 'taproom',
            'lat' => 39.010000000000005,
            'lng' => -77.41,
        ]);

        self::assertSame(['fetch', 'query'], $db->kinds());
        // the row is looked for first, inside the organization
        $read = $db->calls[0];
        self::assertSame('SELECT id FROM tp_scout_leads WHERE organization_id = ? AND truck_id = ? AND region_id = ? AND place_key = ?', $read['sql']);
        self::assertSame([self::ORG, self::TRUCK, self::REGION, self::KEY], $read['params']);

        $insert = $db->only('INSERT INTO tp_scout_leads');
        self::assertSame(
            'INSERT INTO tp_scout_leads (id, organization_id, truck_id, region_id, place_key, lead_state, notes, spot_id, '
            . 'place_name, place_type, lat, lng, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            $insert['sql']
        );
        self::assertSame(36, strlen($id));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        // a new lead is `new`; the point is bound as texts that read back as the same doubles
        self::assertSame(
            [$id, self::ORG, self::TRUCK, self::REGION, self::KEY, 'new', 'Spoke to the manager', null, 'Example Brewing', 'taproom', '39.010000000000005', '-77.41'],
            $insert['params']
        );
        foreach (self::GOOGLE_TEXTS as $column) {
            self::assertStringNotContainsString($column, $insert['sql']);
        }
    }

    public function testALaterTouchChangesOnlyTheColumnsItCarries(): void
    {
        $db = (new RecordingDatabase())->queue(['id' => self::LEAD]);
        $id = (new ScoutLeadRepository($db))->upsert(self::ORG, self::TRUCK, self::REGION, self::KEY, ['status' => 'declined', 'notes' => null]);

        self::assertSame(self::LEAD, $id);
        self::assertSame([], $db->find('INSERT INTO'));
        $update = $db->only('UPDATE tp_scout_leads');
        self::assertSame('UPDATE tp_scout_leads SET lead_state = ?, notes = ? WHERE id = ? AND organization_id = ?', $update['sql']);
        self::assertSame(['declined', null, self::LEAD, self::ORG], $update['params']);
    }

    public function testATouchThatCarriesNothingOnlyMakesSureTheLeadExists(): void
    {
        $db = (new RecordingDatabase())->queue(['id' => self::LEAD]);
        self::assertSame(self::LEAD, (new ScoutLeadRepository($db))->upsert(self::ORG, self::TRUCK, self::REGION, self::KEY, []));
        self::assertSame(['fetch'], $db->kinds());
    }

    public function testTheSpotLinkAndTheStatusAreWrittenInOneStatement(): void
    {
        $db = (new RecordingDatabase())->queue(['id' => self::LEAD]);
        (new ScoutLeadRepository($db))->upsert(self::ORG, self::TRUCK, self::REGION, self::KEY, ['spot_id' => self::SPOT, 'status' => 'shortlisted']);
        $update = $db->only('UPDATE tp_scout_leads');
        self::assertSame('UPDATE tp_scout_leads SET spot_id = ?, lead_state = ? WHERE id = ? AND organization_id = ?', $update['sql']);
        self::assertSame([self::SPOT, 'shortlisted', self::LEAD, self::ORG], $update['params']);
    }

    public function testTwoFirstTouchesAtOnceEndAsOneLead(): void
    {
        // The other request inserted between this one's read and its insert: the unique key refuses the
        // second row, and this touch goes on as a change of the row that is there.
        $db = (new RecordingDatabase())
            ->queue(null, ['id' => self::LEAD])
            ->failOn('INSERT INTO tp_scout_leads', new \PDOException('SQLSTATE[23000]: Duplicate entry'));
        $id = (new ScoutLeadRepository($db))->upsert(self::ORG, self::TRUCK, self::REGION, self::KEY, ['status' => 'hidden']);
        self::assertSame(self::LEAD, $id);
        self::assertSame(['fetch', 'query', 'fetch', 'query'], $db->kinds());
        self::assertSame(['hidden', self::LEAD, self::ORG], $db->only('UPDATE tp_scout_leads')['params']);
    }

    public function testAnInsertThatFailsForAnotherReasonIsNotSwallowed(): void
    {
        $db = (new RecordingDatabase())->failOn('INSERT INTO tp_scout_leads', new \PDOException('SQLSTATE[22001]: Data too long'));
        $this->expectException(\PDOException::class);
        (new ScoutLeadRepository($db))->upsert(self::ORG, self::TRUCK, self::REGION, self::KEY, ['status' => 'hidden']);
    }

    public function testOnlyTheOwnersColumnsCanBeSetThroughUpsert(): void
    {
        self::assertSame(['status', 'notes', 'spot_id', 'place_name', 'place_type', 'lat', 'lng'], array_keys(ScoutLeadRepository::COLUMNS));
        foreach (['google_place_id', 'g_phone', 'g_website', 'g_fetched_at', 'organization_id', 'lead_state'] as $column) {
            $db = new RecordingDatabase();
            try {
                (new ScoutLeadRepository($db))->upsert(self::ORG, self::TRUCK, self::REGION, self::KEY, [$column => 'x']);
                self::fail($column . ' was accepted');
            } catch (\LogicException $e) {
                self::assertSame([], $db->calls, 'nothing is read or written for a column that cannot be set');
            }
        }
    }

    public function testALeadAlwaysHasAStatus(): void
    {
        $db = (new RecordingDatabase())->queue(['id' => self::LEAD]);
        $this->expectException(\LogicException::class);
        (new ScoutLeadRepository($db))->upsert(self::ORG, self::TRUCK, self::REGION, self::KEY, ['status' => null]);
    }

    // ------------------------------------------------------------------------------------ Google content

    public function testAFoundLookupStoresThePlaceIdTheTextsAndTheDatabaseTime(): void
    {
        $db = new RecordingDatabase();
        (new ScoutLeadRepository($db))->setGoogle(self::LEAD, self::ORG, [
            'lookup_state' => 'found',
            'place_id' => 'ChIJexample',
            'name' => 'Example Brewing Co',
            'address' => '1 Example Rd, Sterling, VA 20166',
            'phone' => '(703) 555-0100',
            'website' => null,
            'maps_uri' => 'https://maps.google.com/?cid=1',
        ]);
        $call = $db->only('UPDATE tp_scout_leads');
        self::assertSame(
            'UPDATE tp_scout_leads SET google_place_id = ?, g_lookup_state = ?, g_name = ?, g_address = ?, g_phone = ?, '
            . 'g_website = ?, g_maps_uri = ?, g_fetched_at = NOW() WHERE id = ? AND organization_id = ?',
            $call['sql']
        );
        self::assertSame(
            ['ChIJexample', 'found', 'Example Brewing Co', '1 Example Rd, Sterling, VA 20166', '(703) 555-0100', null,
                'https://maps.google.com/?cid=1', self::LEAD, self::ORG],
            $call['params']
        );
    }

    public function testALookupThatFoundNothingLeavesAnEarlierPlaceIdAlone(): void
    {
        $db = new RecordingDatabase();
        (new ScoutLeadRepository($db))->setGoogle(self::LEAD, self::ORG, ['lookup_state' => 'not_found']);
        $call = $db->only('UPDATE tp_scout_leads');
        self::assertSame(
            'UPDATE tp_scout_leads SET g_lookup_state = ?, g_name = ?, g_address = ?, g_phone = ?, g_website = ?, g_maps_uri = ?, '
            . 'g_fetched_at = NOW() WHERE id = ? AND organization_id = ?',
            $call['sql']
        );
        self::assertSame(['not_found', null, null, null, null, null, self::LEAD, self::ORG], $call['params']);
    }

    public function testALookupIsFoundOrNotFound(): void
    {
        $db = new RecordingDatabase();
        try {
            (new ScoutLeadRepository($db))->setGoogle(self::LEAD, self::ORG, ['lookup_state' => 'maybe']);
            self::fail('an unknown lookup state was stored');
        } catch (\LogicException $e) {
            self::assertSame([], $db->calls);
        }
    }

    public function testTheSpotLinkIsSetAndTakenAway(): void
    {
        $db = new RecordingDatabase();
        $repository = new ScoutLeadRepository($db);
        $repository->setSpot(self::LEAD, self::ORG, self::SPOT);
        $repository->setSpot(self::LEAD, self::ORG, null);
        foreach ($db->calls as $call) {
            self::assertSame('UPDATE tp_scout_leads SET spot_id = ? WHERE id = ? AND organization_id = ?', $call['sql']);
        }
        self::assertSame([self::SPOT, self::LEAD, self::ORG], $db->calls[0]['params']);
        self::assertSame([null, self::LEAD, self::ORG], $db->calls[1]['params']);
    }

    // ------------------------------------------------------------------------------------ purge

    public function testThePurgeEmptiesTheGoogleColumnsOfOldLookupsAndKeepsThePlaceId(): void
    {
        $db = (new RecordingDatabase())->queue(['lead_count' => '3']);
        $purged = (new ScoutLeadRepository($db))->purgeExpiredGoogle(self::ORG);

        self::assertSame(3, $purged);
        self::assertSame(['begin', 'fetch', 'query', 'commit'], $db->kinds());
        $where = 'WHERE organization_id = ? AND g_fetched_at IS NOT NULL AND g_fetched_at < NOW() - INTERVAL ? DAY';
        $count = $db->only('SELECT COUNT(*)');
        self::assertSame('SELECT COUNT(*) AS lead_count FROM tp_scout_leads ' . $where, $count['sql']);
        self::assertSame([self::ORG, 30], $count['params']);

        $update = $db->only('UPDATE tp_scout_leads');
        self::assertSame(
            'UPDATE tp_scout_leads SET g_lookup_state = NULL, g_name = NULL, g_address = NULL, g_phone = NULL, g_website = NULL, '
            . 'g_maps_uri = NULL, g_fetched_at = NULL ' . $where,
            $update['sql']
        );
        self::assertSame([self::ORG, 30], $update['params']);
        // the id Google gave the place may be kept
        self::assertStringNotContainsString('google_place_id', $update['sql']);
    }

    public function testAPurgeWithNothingToDoWritesNothing(): void
    {
        $db = (new RecordingDatabase())->queue(['lead_count' => 0]);
        self::assertSame(0, (new ScoutLeadRepository($db))->purgeExpiredGoogle(self::ORG));
        self::assertSame(['begin', 'fetch', 'commit'], $db->kinds());
    }

    public function testTheDailySweepCoversEveryOrganization(): void
    {
        $db = (new RecordingDatabase())->queue(['lead_count' => 2]);
        self::assertSame(2, (new ScoutLeadRepository($db))->purgeExpiredGoogle());
        $where = 'WHERE g_fetched_at IS NOT NULL AND g_fetched_at < NOW() - INTERVAL ? DAY';
        self::assertStringEndsWith($where, $db->only('SELECT COUNT(*)')['sql']);
        self::assertStringEndsWith($where, $db->only('UPDATE tp_scout_leads')['sql']);
        self::assertSame([30], $db->only('UPDATE tp_scout_leads')['params']);
    }

    public function testAFailingPurgeIsRolledBack(): void
    {
        $db = (new RecordingDatabase())->queue(['lead_count' => 1])->failOn('UPDATE tp_scout_leads');
        try {
            (new ScoutLeadRepository($db))->purgeExpiredGoogle(self::ORG);
            self::fail('the failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame(['begin', 'fetch', 'query', 'rollback'], $db->kinds());
        }
    }

    // ------------------------------------------------------------------------------------ every statement

    public function testEveryStatementOfAnOwnerCallCarriesTheOrganization(): void
    {
        $db = new RecordingDatabase();
        $db->when('SELECT id FROM tp_scout_leads', ['id' => self::LEAD]);
        $db->when('SELECT COUNT(*)', ['lead_count' => 1]);
        $repository = new ScoutLeadRepository($db);
        $repository->forTruck(self::ORG, self::TRUCK, self::REGION);
        $repository->find(self::ORG, self::TRUCK, self::REGION, self::KEY);
        $repository->upsert(self::ORG, self::TRUCK, self::REGION, self::KEY, ['status' => 'booked']);
        $repository->setGoogle(self::LEAD, self::ORG, ['lookup_state' => 'not_found']);
        $repository->setSpot(self::LEAD, self::ORG, null);
        $repository->purgeExpiredGoogle(self::ORG);

        $statements = 0;
        foreach ($db->calls as $call) {
            if ($call['sql'] === '') {
                continue;
            }
            $statements++;
            self::assertStringContainsString('organization_id = ?', $call['sql']);
            self::assertContains(self::ORG, $call['params'], $call['sql']);
            self::assertStringNotContainsString('SELECT *', $call['sql']);
        }
        self::assertSame(8, $statements);
    }
}
