<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\ScoutLeadRepository;
use PHPUnit\Framework\TestCase;

final class ScoutLeadRepositoryTest extends TestCase
{
    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const TRUCK = '33333333-3333-4333-8333-333333333333';
    private const LEAD = '55555555-5555-4555-8555-555555555555';
    private const SPOT = '44444444-4444-4444-8444-444444444444';
    private const REGION = 'dc';
    private const KEY = 'w264230766';

    /** The columns that held Google Places content before only the place id was kept. They are gone. */
    private const REMOVED = ['g_name', 'g_address', 'g_phone', 'g_website', 'g_maps_uri'];

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
            'spot_id' => null, 'google_place_id' => null, 'g_lookup_state' => null, 'g_fetched_at' => null,
            'created_at' => '2026-10-04 23:50:12', 'updated_at' => '2026-10-05 08:01:00',
        ];
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
                'spot_id' => null, 'google_place_id' => null, 'lookup_state' => null, 'matched_at' => null,
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
        self::assertSame([self::ORG, self::TRUCK, self::REGION], $call['params']);
        self::assertStringNotContainsString('SELECT *', $call['sql']);
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
        self::assertSame([self::ORG, self::TRUCK, self::REGION, self::KEY], $call['params']);

        self::assertNull((new ScoutLeadRepository(new RecordingDatabase()))->find(self::ORG, self::TRUCK, self::REGION, 'w0'));
    }

    public function testTheReadNamesItsColumnsAndTheThreeALookupLeaves(): void
    {
        $db = new RecordingDatabase();
        (new ScoutLeadRepository($db))->find(self::ORG, self::TRUCK, self::REGION, self::KEY);
        self::assertSame(
            'SELECT id, organization_id, truck_id, region_id, place_key, place_name, place_type, lat, lng, lead_state, notes, spot_id, '
            . 'google_place_id, g_lookup_state, g_fetched_at, created_at, updated_at FROM tp_scout_leads '
            . 'WHERE organization_id = ? AND truck_id = ? AND region_id = ? AND place_key = ?',
            $db->only('FROM tp_scout_leads')['sql']
        );
    }

    public function testALookupLeavesThePlaceIdTheOutcomeAndTheTime(): void
    {
        $db = (new RecordingDatabase())->queue(
            self::databaseRow(['google_place_id' => 'ChIJexample', 'g_lookup_state' => 'found', 'g_fetched_at' => '2026-10-03 14:05:09']),
            self::databaseRow(['g_lookup_state' => 'not_found', 'g_fetched_at' => '2026-10-04 09:00:00'])
        );
        $repository = new ScoutLeadRepository($db);
        $found = $repository->find(self::ORG, self::TRUCK, self::REGION, self::KEY);
        self::assertSame('ChIJexample', $found['google_place_id']);
        self::assertSame('found', $found['lookup_state']);
        self::assertSame('2026-10-03 14:05:09', $found['matched_at']);

        $none = $repository->find(self::ORG, self::TRUCK, self::REGION, self::KEY);
        self::assertNull($none['google_place_id']);
        self::assertSame('not_found', $none['lookup_state']);
        self::assertSame('2026-10-04 09:00:00', $none['matched_at']);

        // A read row has no key that could carry what Google answered about the place.
        self::assertSame(
            ['id', 'organization_id', 'truck_id', 'region_id', 'place_key', 'place_name', 'place_type', 'lat', 'lng', 'status', 'notes',
                'spot_id', 'google_place_id', 'lookup_state', 'matched_at', 'created_at', 'updated_at'],
            array_keys($found)
        );
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
        // Neither what a lookup leaves, nor a text of Google's answer under any name, nor a column of the row.
        $refused = array_merge(
            self::REMOVED,
            ['google_place_id', 'g_lookup_state', 'g_fetched_at', 'organization_id', 'lead_state', 'name', 'address', 'phone', 'website', 'maps_uri']
        );
        foreach ($refused as $column) {
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

    // ------------------------------------------------------------------------------------ what a lookup leaves

    public function testAMatchWritesThePlaceIdTheOutcomeAndTheDatabaseTimeAndNothingElse(): void
    {
        $db = new RecordingDatabase();
        (new ScoutLeadRepository($db))->setMatch(self::LEAD, self::ORG, true, 'ChIJN1t_tDeuEmsRUsoyG83frY4');
        $call = $db->only('UPDATE tp_scout_leads');
        self::assertSame(
            'UPDATE tp_scout_leads SET google_place_id = ?, g_lookup_state = ?, g_fetched_at = NOW() WHERE id = ? AND organization_id = ?',
            $call['sql']
        );
        self::assertSame(['ChIJN1t_tDeuEmsRUsoyG83frY4', 'found', self::LEAD, self::ORG], $call['params']);
        self::assertCount(1, $db->calls);
    }

    public function testASearchThatFoundNothingTakesAnEarlierPlaceIdAway(): void
    {
        $db = new RecordingDatabase();
        (new ScoutLeadRepository($db))->setMatch(self::LEAD, self::ORG, false, null);
        $call = $db->only('UPDATE tp_scout_leads');
        self::assertSame(
            'UPDATE tp_scout_leads SET google_place_id = ?, g_lookup_state = ?, g_fetched_at = NOW() WHERE id = ? AND organization_id = ?',
            $call['sql']
        );
        self::assertSame([null, 'not_found', self::LEAD, self::ORG], $call['params']);
    }

    public function testAPlaceFoundWithoutAUsableIdIsFoundWithoutOne(): void
    {
        $db = new RecordingDatabase();
        (new ScoutLeadRepository($db))->setMatch(self::LEAD, self::ORG, true, null);
        self::assertSame([null, 'found', self::LEAD, self::ORG], $db->only('UPDATE tp_scout_leads')['params']);
    }

    public function testOnlyWhatLooksLikeAPlaceIdIsStoredAsOne(): void
    {
        // What a caller could pass by mistake: a phone number, a web address, a name, an address, a long text.
        $notIds = ['(703) 555-0100', 'https://example.com/', 'Example Brewing Co', '1 Example Rd, Sterling, VA 20166', '',
            'https://maps.google.com/?cid=1', str_repeat('a', 256), "ChIJ\nexample"];
        foreach ($notIds as $text) {
            $db = new RecordingDatabase();
            try {
                (new ScoutLeadRepository($db))->setMatch(self::LEAD, self::ORG, true, $text);
                self::fail('stored as a place id: ' . $text);
            } catch (\LogicException $e) {
                self::assertSame([], $db->calls);
            }
        }
        // and a search that found nothing has no id to keep
        $db = new RecordingDatabase();
        try {
            (new ScoutLeadRepository($db))->setMatch(self::LEAD, self::ORG, false, 'ChIJexample');
            self::fail('an id was stored for a place that was not found');
        } catch (\LogicException $e) {
            self::assertSame([], $db->calls);
        }
        self::assertSame(1, preg_match(ScoutLeadRepository::PLACE_ID_FORM, 'ChIJN1t_tDeuEmsRUsoyG83frY4'));
        self::assertSame(1, preg_match(ScoutLeadRepository::PLACE_ID_FORM, str_repeat('a', 255)));
    }

    public function testNoMethodTakesTheTextsOfAnAnswer(): void
    {
        // The public surface: nothing that stored or emptied Google content is left, and what a lookup
        // writes goes through setMatch(), whose only values are a flag and a place id.
        $methods = [];
        foreach ((new \ReflectionClass(ScoutLeadRepository::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $methods[] = $method->getName();
        }
        sort($methods);
        self::assertSame(['__construct', 'find', 'forTruck', 'setMatch', 'setSpot', 'upsert'], $methods);
        $parameters = [];
        foreach ((new \ReflectionMethod(ScoutLeadRepository::class, 'setMatch'))->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = (string) $parameter->getType();
        }
        self::assertSame(['id' => 'string', 'orgId' => 'string', 'found' => 'bool', 'placeId' => '?string'], $parameters);
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

    // ------------------------------------------------------------------------------------ every statement

    public function testEveryStatementCarriesTheOrganizationAndNoneNamesARemovedColumn(): void
    {
        $db = new RecordingDatabase();
        $db->when('SELECT id FROM tp_scout_leads', ['id' => self::LEAD]);
        $repository = new ScoutLeadRepository($db);
        $repository->forTruck(self::ORG, self::TRUCK, self::REGION);
        $repository->find(self::ORG, self::TRUCK, self::REGION, self::KEY);
        $repository->upsert(self::ORG, self::TRUCK, self::REGION, self::KEY, ['status' => 'booked']);
        $repository->setMatch(self::LEAD, self::ORG, true, 'ChIJexample');
        $repository->setMatch(self::LEAD, self::ORG, false, null);
        $repository->setSpot(self::LEAD, self::ORG, null);

        $statements = 0;
        foreach ($db->calls as $call) {
            if ($call['sql'] === '') {
                continue;
            }
            $statements++;
            self::assertStringContainsString('organization_id = ?', $call['sql']);
            self::assertContains(self::ORG, $call['params'], $call['sql']);
            self::assertStringNotContainsString('SELECT *', $call['sql']);
            foreach (self::REMOVED as $column) {
                self::assertStringNotContainsString($column, $call['sql']);
            }
        }
        self::assertSame(7, $statements);
        self::assertSame(['fetchAll', 'fetch', 'fetch', 'query', 'query', 'query', 'query'], $db->kinds(), 'no transaction: nothing is swept any more');
    }
}
