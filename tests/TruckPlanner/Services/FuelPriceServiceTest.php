<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FakeHttp;
use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\MemoryCache;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Data\FuelPriceRepository;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Services\Contracts\FuelPriceProvider;
use App\TruckPlanner\Services\FuelPriceService;
use App\TruckPlanner\Services\RegionService;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Upstream\FuelClient;
use PHPUnit\Framework\TestCase;

/**
 * The fuel price of a truck: the owner's own price, else the stored weekly price, else the seed price.
 * And the weekly refresh: when it is due, what it asks for, and that it is tried at most every six hours.
 */
final class FuelPriceServiceTest extends TestCase
{
    private const KEY = 'test-eia-key-not-real';
    private const PAD = 93600;

    private FixedClock $clock;
    private MemoryCache $store;
    private RecordingDatabase $db;
    private FakeHttp $http;
    private RecordingDatabase $ledger;
    private string|false $keyBefore;
    private ?string $envBefore;

    protected function setUp(): void
    {
        // Tuesday 6 October 2026, 14:00 UTC: 10:00 in New York, the moment a week's prices come out.
        $this->clock = new FixedClock('2026-10-06 14:00:00');
        $this->store = new MemoryCache();
        TpCache::wire($this->clock, $this->store);
        $this->http = new FakeHttp();
        $this->ledger = new RecordingDatabase();
        $this->db = (new RecordingDatabase())
            ->when('FROM tp_regions WHERE region_id = ?', self::region('dc', ['DC' => 'R1Y', 'MD' => 'R1Y', 'VA' => 'R1Z', 'WV' => 'R1Z']))
            ->when('FROM tp_regions ORDER BY region_id', [
                self::region('dc', ['DC' => 'R1Y', 'MD' => 'R1Y', 'VA' => 'R1Z', 'WV' => 'R1Z']),
                self::region('mini', ['VA' => 'R1Z']),
                self::region('sea', ['WA' => 'R50', 'OR' => 'bad area', 'ID' => 7]),
            ]);
        $this->keyBefore = getenv('EIA_API_KEY');
        $this->envBefore = $_ENV['EIA_API_KEY'] ?? null;
        unset($_ENV['EIA_API_KEY']);
        putenv('EIA_API_KEY=' . self::KEY);
    }

    protected function tearDown(): void
    {
        TpCache::wire();
        putenv($this->keyBefore === false ? 'EIA_API_KEY' : 'EIA_API_KEY=' . $this->keyBefore);
        if ($this->envBefore !== null) {
            $_ENV['EIA_API_KEY'] = $this->envBefore;
        }
    }

    /**
     * @param array<string, mixed> $areas
     * @return array<string, mixed> a row of tp_regions
     */
    private static function region(string $id, array $areas): array
    {
        return [
            'region_id' => $id, 'name' => $id, 'cbsa' => null, 'timezone' => 'America/New_York', 'h3_res' => 9,
            'bbox_lat_min' => 37.9907, 'bbox_lng_min' => -78.3947, 'bbox_lat_max' => 39.7201, 'bbox_lng_max' => -76.6625,
            'center_lat' => 38.9072, 'center_lng' => -77.0369, 'active_version' => null, 'previous_version' => null,
            'config_json' => (string) json_encode(['fuel_area_by_state' => $areas]),
            'created_at' => '2026-10-04 20:00:00', 'updated_at' => '2026-10-04 20:00:00',
        ];
    }

    private function service(): FuelPriceService
    {
        $regionRows = new RegionRepository($this->db);
        return new FuelPriceService(
            new FuelPriceRepository($this->db),
            new RegionService($regionRows),
            $regionRows,
            new FuelClient($this->http, new ApiLedger($this->ledger)),
            $this->clock
        );
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private static function truck(array $profile = [], ?string $state = 'VA'): array
    {
        return [
            'id' => 't1',
            'timezone' => 'America/New_York',
            'base_state' => $state,
            'profile' => $profile + ['region_id' => 'dc', 'fuel_type' => 'gasoline', 'fuel_price_override' => null],
        ];
    }

    /**
     * @return array<string, mixed> a row of tp_fuel_prices
     */
    private static function stored(string $area, string $product, string $period, int $milli): array
    {
        return ['duoarea' => $area, 'product' => $product, 'period' => $period, 'price_milli' => $milli, 'series_id' => 's', 'fetched_at' => '2026-10-06 10:05:00'];
    }

    // ------------------------------------------------------------------------------------ resolve

    public function testItIsAFuelPriceProviderThatNeedsNoArguments(): void
    {
        self::assertInstanceOf(FuelPriceProvider::class, new FuelPriceService());
    }

    public function testTheOwnersOwnPriceWinsAndNothingIsRead(): void
    {
        $answer = $this->service()->resolve(self::truck(['fuel_price_override' => 3.899]));
        self::assertSame(['price_per_gal' => 3.899, 'source' => 'owner', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => null], $answer);
        self::assertSame([], $this->db->find('tp_fuel_prices'));
    }

    public function testTheStoredWeeklyPriceOfTheAreaAndTheFuelComesNext(): void
    {
        $this->db->queue(self::stored('R1Z', 'EPMR', '2026-10-05', 4201));
        $answer = $this->service()->resolve(self::truck());
        self::assertSame(['price_per_gal' => 4.201, 'source' => 'eia', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-10-05'], $answer);
        $read = $this->db->only('FROM tp_fuel_prices');
        self::assertSame(['R1Z', 'EPMR'], $read['params']);

        // diesel in Maryland: another area, another product
        $this->db->queue(self::stored('R1Y', 'EPD2D', '2026-09-28', 6531));
        $diesel = $this->service()->resolve(self::truck(['fuel_type' => 'diesel'], 'MD'));
        self::assertSame(['price_per_gal' => 6.531, 'source' => 'eia', 'area' => 'R1Y', 'product' => 'EPD2D', 'period' => '2026-09-28'], $diesel);
        self::assertSame(['R1Y', 'EPD2D'], $this->db->find('FROM tp_fuel_prices')[1]['params']);
    }

    public function testWithoutAStoredPriceTheSeedPriceIsShownWithItsDate(): void
    {
        self::assertSame(
            ['price_per_gal' => 4.195, 'source' => 'seed', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28'],
            $this->service()->resolve(self::truck())
        );
        self::assertSame(
            ['price_per_gal' => 6.531, 'source' => 'seed', 'area' => 'R1Y', 'product' => 'EPD2D', 'period' => '2026-09-28'],
            $this->service()->resolve(self::truck(['fuel_type' => 'diesel'], 'DC'))
        );
    }

    public function testATruckWithoutAStateOrARegionIsPricedWithTheNationalAverage(): void
    {
        $this->db->queue(self::stored('NUS', 'EPMR', '2026-10-05', 4470), null, null);
        self::assertSame(
            ['price_per_gal' => 4.47, 'source' => 'eia', 'area' => 'NUS', 'product' => 'EPMR', 'period' => '2026-10-05'],
            $this->service()->resolve(self::truck([], null))
        );
        // a state the region has no area for, and region `none`: the national area, here from the seeds
        self::assertSame(
            ['price_per_gal' => 4.465, 'source' => 'seed', 'area' => 'NUS', 'product' => 'EPMR', 'period' => '2026-09-28'],
            $this->service()->resolve(self::truck([], 'PA'))
        );
        self::assertSame('NUS', $this->service()->resolve(self::truck(['region_id' => 'none']))['area']);
        foreach ($this->db->find('FROM tp_fuel_prices') as $read) {
            self::assertSame(['NUS', 'EPMR'], $read['params']);
        }
    }

    public function testTheOrderIsOwnerThenWeeklyThenSeed(): void
    {
        // all three are there: the owner's
        $this->db->when('FROM tp_fuel_prices', self::stored('R1Z', 'EPMR', '2026-10-05', 4201));
        self::assertSame('owner', $this->service()->resolve(self::truck(['fuel_price_override' => 5.0]))['source']);
        // weekly and seed: the weekly
        self::assertSame('eia', $this->service()->resolve(self::truck())['source']);
        // seed only
        $this->db = (new RecordingDatabase())->when('FROM tp_regions WHERE region_id = ?', self::region('dc', ['VA' => 'R1Z']));
        self::assertSame('seed', $this->service()->resolve(self::truck())['source']);
    }

    // ------------------------------------------------------------------------------------ the weekly refresh

    /**
     * @param list<array<string, string>> $rows
     */
    private function queueWeekly(array $rows): void
    {
        $this->http->json(200, ['response' => ['total' => (string) count($rows), 'data' => $rows]]);
    }

    /**
     * @return array<string, mixed> the query of the one request that was sent
     */
    private function sentQuery(): array
    {
        self::assertCount(1, $this->http->requests);
        parse_str((string) parse_url($this->http->requests[0]['url'], PHP_URL_QUERY), $query);
        return $query;
    }

    public function testADueRefreshAsksForEveryAreaOfEveryRegionAndStoresTheRows(): void
    {
        $this->db->when('SELECT MAX(period)', ['newest' => '2026-09-28']);
        $this->queueWeekly([
            ['period' => '2026-10-05', 'duoarea' => 'R1Z', 'product' => 'EPMR', 'series' => 'EMM_EPMR_PTE_R1Z_DPG', 'value' => '4.201'],
            ['period' => '2026-10-05', 'duoarea' => 'NUS', 'product' => 'EPD2D', 'series' => 'EMD_EPD2D_PTE_NUS_DPG', 'value' => '6.4'],
        ]);
        $this->service()->refreshIfDue();

        $query = $this->sentQuery();
        // the areas of all regions and the national one, each once, in order; a value that is no area is left out
        self::assertSame(['NUS', 'R1Y', 'R1Z', 'R50'], $query['facets']['duoarea']);
        self::assertSame(['EPMR', 'EPD2D'], $query['facets']['product']);
        // four weeks before the week that is out (Monday 5 October)
        self::assertSame('2026-09-07', $query['start']);

        $insert = $this->db->only('INSERT INTO tp_fuel_prices');
        self::assertSame(
            ['NUS', 'EPD2D', '2026-10-05', 6400, 'EMD_EPD2D_PTE_NUS_DPG', 'eia', 'R1Z', 'EPMR', '2026-10-05', 4201, 'EMM_EPMR_PTE_R1Z_DPG', 'eia'],
            $insert['params']
        );
        self::assertSame('tp_eia_weekly', $this->ledger->only('INSERT INTO api_cost_events')['params'][1]);
    }

    public function testTheAttemptIsRememberedForSixHoursBeforeTheCall(): void
    {
        $this->db->when('SELECT MAX(period)', ['newest' => '2026-09-28']);
        $this->http->fail('timeout');
        LogCapture::during(function (): void {
            $this->service()->refreshIfDue();
        });
        self::assertCount(1, $this->http->requests);
        self::assertSame(21600 + self::PAD, $this->store->ttls['tp:eia:attempt']);

        // however often the page is asked for in the next six hours, the failing service is not called again
        foreach ([1, 3600, 17998] as $seconds) {
            $this->clock->advance($seconds);
            $this->service()->refreshIfDue();
        }
        self::assertCount(1, $this->http->requests);

        $this->clock->advance(1);
        $this->queueWeekly([]);
        $this->service()->refreshIfDue();
        self::assertCount(2, $this->http->requests);
        self::assertSame([], $this->db->find('INSERT INTO tp_fuel_prices'), 'no row came back: nothing is written');
    }

    public function testNothingIsRequestedWithoutAKey(): void
    {
        putenv('EIA_API_KEY');
        $this->db->when('SELECT MAX(period)', ['newest' => null]);
        $this->service()->refreshIfDue();
        self::assertSame([], $this->http->requests);
        self::assertArrayNotHasKey('tp:eia:attempt', $this->store->values);
        self::assertSame([], $this->db->calls, 'without a key not even the table is read');
    }

    public function testAnEmptyTableIsDue(): void
    {
        $this->db->when('SELECT MAX(period)', ['newest' => null]);
        $this->queueWeekly([]);
        $this->service()->refreshIfDue();
        self::assertCount(1, $this->http->requests);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: ?string}> UTC instant, newest stored week, due?, start asked
     */
    public static function moments(): array
    {
        return [
            // The week of Monday 5 October 2026 comes out on Tuesday 6 October at 10:00 in New York (14:00 UTC).
            'Monday evening: last week is the newest there is' => ['2026-10-05 23:00:00', '2026-09-28', false, null],
            'Tuesday 09:59 in New York: not out yet' => ['2026-10-06 13:59:59', '2026-09-28', false, null],
            'Tuesday 10:00 in New York: out' => ['2026-10-06 14:00:00', '2026-09-28', true, '2026-09-07'],
            'Tuesday 10:00 and already stored' => ['2026-10-06 14:00:00', '2026-10-05', false, null],
            'before the release, and two weeks behind' => ['2026-10-06 13:00:00', '2026-09-21', true, '2026-08-31'],
            'Sunday of the same week' => ['2026-10-11 20:00:00', '2026-09-28', true, '2026-09-07'],
            // 02:00 UTC on Monday 5 October is still Sunday 4 October in New York: the week of 28 September
            'Monday in UTC, Sunday in New York' => ['2026-10-05 02:00:00', '2026-09-28', false, null],
            'Monday in UTC, Sunday in New York, one week behind' => ['2026-10-05 02:00:00', '2026-09-21', true, '2026-08-31'],
            // 03:30 UTC on Wednesday is Tuesday 23:30 in New York
            'late on Tuesday' => ['2026-10-07 03:30:00', '2026-09-28', true, '2026-09-07'],
            // winter time: New York is five hours behind, so 10:00 there is 15:00 UTC
            'winter, Tuesday 09:59 in New York' => ['2026-11-03 14:59:59', '2026-10-26', false, null],
            'winter, Tuesday 10:00 in New York' => ['2026-11-03 15:00:00', '2026-10-26', true, '2026-10-05'],
            // across a year end: Tuesday 29 December 2026, the week of Monday 28 December
            'the last week of a year' => ['2026-12-29 15:00:00', '2026-12-21', true, '2026-11-30'],
        ];
    }

    /**
     * @dataProvider moments
     */
    public function testARefreshIsDueWhenANewerWeekIsOut(string $utc, string $newest, bool $due, ?string $start): void
    {
        $this->clock->set($utc);
        $this->db->when('SELECT MAX(period)', ['newest' => $newest]);
        if ($due) {
            $this->queueWeekly([]);
        }
        $this->service()->refreshIfDue();
        if (!$due) {
            self::assertSame([], $this->http->requests);
            self::assertArrayNotHasKey('tp:eia:attempt', $this->store->values, 'a check that finds nothing due is not an attempt');
            return;
        }
        self::assertSame($start, $this->sentQuery()['start']);
    }

    public function testNothingARefreshMeetsIsRaised(): void
    {
        // the table cannot be read
        $this->db->failOn('SELECT MAX(period)');
        $lines = LogCapture::during(function (): void {
            $this->service()->refreshIfDue();
        });
        self::assertCount(1, $lines);
        self::assertStringStartsWith('[tp] fuel price refresh failed: RuntimeException', $lines[0]);
        self::assertSame([], $this->http->requests);

        // the rows cannot be written
        $this->db = (new RecordingDatabase())
            ->when('SELECT MAX(period)', ['newest' => null])
            ->when('FROM tp_regions ORDER BY region_id', [])
            ->failOn('INSERT INTO tp_fuel_prices', new \RuntimeException('disk full at https://api.eia.gov/?api_key=' . self::KEY));
        $this->queueWeekly([['period' => '2026-10-05', 'duoarea' => 'NUS', 'product' => 'EPMR', 'series' => 's', 'value' => '4.4']]);
        $lines = LogCapture::during(function (): void {
            $this->service()->refreshIfDue();
        });
        self::assertCount(1, $lines);
        self::assertStringNotContainsString(self::KEY, $lines[0]);
        self::assertSame(['NUS'], $this->sentQuery()['facets']['duoarea']);
    }
}
