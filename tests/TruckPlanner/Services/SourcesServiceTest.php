<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\FuelPriceProvider;
use App\TruckPlanner\Services\ProfileMapper;
use App\TruckPlanner\Services\RegionService;
use App\TruckPlanner\Services\SourcesService;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConfig;
use PHPUnit\Framework\TestCase;

/**
 * SourcesService: the source lines are the strings of 03_DATA.md section 14 (read from that document
 * here), filled from the manifest of the region's active dataset, the fuel price in use and the server's
 * contact address, and never sent with a placeholder left in.
 */
final class SourcesServiceTest extends TestCase
{
    private const DATA_DOCUMENT = '/docs/truck-planner/03_DATA.md';
    private const VERSION = 'dc-20261003-d0514a63';
    private const OSM = 'https://www.openstreetmap.org/copyright';
    private const LEHD = 'https://lehd.ces.census.gov/data/';

    private const FILLED = [
        1 => "\u{00A9} OpenStreetMap contributors",
        2 => "Place data \u{00A9} OpenStreetMap contributors, available under the Open Database License (ODbL).",
        3 => 'Residents: U.S. Census Bureau, 2020 Census Redistricting Data (Public Law 94-171). Counts as of April 1, 2020, not adjusted for growth.',
        4 => 'Jobs: U.S. Census Bureau, LEHD Origin-Destination Employment Statistics (LODES), version 8.4, 2023, all jobs. '
            . 'Job counts are jobs of record with statistical noise added by the Census Bureau, not people present. '
            . '31 payroll-address blocks holding 196941 jobs were spread over their county (corrections 2026-10-05.1); '
            . 'construction jobs count at 30 %.',
        5 => "Places: OpenStreetMap snapshot of 2026-10-03 (Geofabrik extracts). \u{00A9} OpenStreetMap contributors, ODbL 1.0. "
            . 'The places table is a database derived from OpenStreetMap and is available under the ODbL on request: owner@example.com.',
        6 => 'Forecast: National Weather Service (weather.gov).',
        7 => 'Fuel price: U.S. Energy Information Administration, weekly retail prices, week of 2026-09-28.',
        8 => 'County boundaries: U.S. Census Bureau, TIGERweb.',
        9 => 'Drive times and distances: Google Maps Platform. Kept for at most 30 days.',
        10 => "People: US Census 2020, LEHD 2023 \u{00B7} Venues: \u{00A9} OpenStreetMap contributors",
        11 => 'Time-of-day traffic factors: derived from the TomTom Traffic Index 2025.',
        12 => 'Phone and website from Google Maps',
    ];

    private SourcesRegionRows $rows;
    private SourcesRegions $regions;

    /** @var array<string, ?string> the server's environment settings */
    private array $settings = ['TP_CONTACT_EMAIL' => 'owner@example.com', 'MAIL_FROM' => 'noreply@example.com'];

    /** @var array<string, mixed> the FuelInfo in use */
    private array $fuel = ['price_per_gal' => 4.195, 'source' => 'eia', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28'];

    protected function setUp(): void
    {
        $this->rows = new SourcesRegionRows();
        $this->regions = new SourcesRegions($this->rows);
        $test = $this;
        Registry::reset();
        Registry::set('fuel', new class($test) implements FuelPriceProvider {
            private SourcesServiceTest $test;

            public function __construct(SourcesServiceTest $test)
            {
                $this->test = $test;
            }

            public function resolve(array $truck): array
            {
                return $this->test->fuelInfo();
            }
        });
    }

    protected function tearDown(): void
    {
        Registry::reset();
        TpConfig::replace(null);
    }

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * @return array<string, mixed>
     */
    public function fuelInfo(): array
    {
        return $this->fuel;
    }

    private function service(): SourcesService
    {
        return new SourcesService($this->rows, $this->regions, null, fn (string $name): ?string => $this->settings[$name] ?? null);
    }

    /**
     * The truck value of TruckBaseController::truck(): a default truck of the region `dc`.
     *
     * @param array<string, mixed> $overrides the owner's seed overrides
     * @return array<string, mixed>
     */
    private static function truck(string $regionId = 'dc', array $overrides = []): array
    {
        return [
            'id' => '33333333-3333-4333-8333-333333333333',
            'organization_id' => '11111111-1111-4111-8111-111111111111',
            'timezone' => 'America/New_York',
            'base_state' => 'VA',
            'base_county_fips' => '51107',
            'overrides' => $overrides,
            'overrides_seeds_rev' => Seeds::revision(),
            'profile' => ['name' => 'Smoke & Ember', 'region_id' => $regionId, 'base' => ['lat' => 39.003, 'lng' => -77.405, 'address' => 'Sterling, VA']]
                + (new ProfileMapper())->defaults(),
            'created_at' => '2026-10-04 23:50:12',
            'updated_at' => '2026-10-04 23:50:12',
        ];
    }

    /**
     * Overrides that make the region's traffic table neutral: every hour of the week and the typical value 1.
     *
     * @return array<string, mixed>
     */
    private static function neutralTraffic(string $matrix = 'dc'): array
    {
        return ['traffic.' . $matrix => array_fill(0, 7, array_fill(0, 24, 1.0)), 'traffic.' . $matrix . '_typical' => 1.0];
    }

    /** Section 14 of 03_DATA.md, as it stands in the repository. */
    private static function sectionFourteen(): string
    {
        $document = (string) file_get_contents(dirname(__DIR__, 3) . self::DATA_DOCUMENT);
        $document = str_replace("\r\n", "\n", $document);
        $start = strpos($document, "\n## 14. ");
        $end = strpos($document, "\n## 15. ");
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        // The strings are written on one line each, but a long line may have been wrapped in the source.
        return (string) preg_replace('/\n\s+/', ' ', substr($document, (int) $start, (int) $end - (int) $start));
    }

    /**
     * @param list<array{id: int, text: string, url: ?string}> $attribution
     * @return array<int, string> id => text
     */
    private static function texts(array $attribution): array
    {
        $texts = [];
        foreach ($attribution as $line) {
            self::assertSame(['id', 'text', 'url'], array_keys($line));
            $texts[$line['id']] = $line['text'];
        }
        return $texts;
    }

    // ------------------------------------------------------------------------------------ the strings

    public function testTheTwelveTemplatesAreTheStringsOfTheDataDocument(): void
    {
        $section = self::sectionFourteen();

        self::assertSame(range(1, 12), array_keys(SourcesService::TEXTS));
        foreach (SourcesService::TEXTS as $id => $template) {
            self::assertStringContainsString('`' . $template . '`', $section, 'string ' . $id . ' is not a code span of 03_DATA.md section 14');
        }
        // The placeholders are exactly those of the table that follows the strings.
        preg_match_all('/\{[a-z0-9_]+\}/', implode(' ', SourcesService::TEXTS), $m);
        $used = array_values(array_unique($m[0]));
        sort($used);
        $documented = [
            '{blocks_adjusted}', '{census_year}', '{cns04_weight_percent}', '{contact}', '{corrections_version}',
            '{jobs_spread}', '{lodes_year}', '{osm_snapshot_date}', '{period}',
        ];
        self::assertSame($documented, $used);
        foreach ($documented as $placeholder) {
            self::assertStringContainsString('`' . $placeholder . '`', $section);
        }
        // String 9 is also what the drive-time answers carry.
        self::assertSame(TpConfig::get('routing.attribution'), SourcesService::TEXTS[9]);
        // The document spells out string 10 for the first region.
        self::assertStringContainsString('`' . self::FILLED[10] . '`', $section);
    }

    public function testSourcesReturnsTheTwelveStringsFilled(): void
    {
        $answer = $this->service()->build(self::truck());

        self::assertSame(
            ['model_version', 'seeds_revision', 'region', 'dataset', 'fuel', 'contact', 'attribution'],
            array_keys($answer)
        );
        self::assertSame(self::FILLED, self::texts($answer['attribution']));
        self::assertSame(range(1, 12), array_column($answer['attribution'], 'id'), 'in id order');
        foreach ($answer['attribution'] as $line) {
            self::assertStringNotContainsString('{', $line['text']);
            self::assertStringNotContainsString('}', $line['text']);
        }
        // The OpenStreetMap copyright page for 1, 2 and 10, the LEHD page for 4, nothing else.
        self::assertSame(
            [1 => self::OSM, 2 => self::OSM, 3 => null, 4 => self::LEHD, 5 => null, 6 => null, 7 => null, 8 => null, 9 => null, 10 => self::OSM, 11 => null, 12 => null],
            array_column($answer['attribution'], 'url', 'id')
        );
        self::assertSame('owner@example.com', $answer['contact']);
        self::assertSame($this->fuel, $answer['fuel']);
        self::assertSame(Estimator::MODEL_VERSION, $answer['model_version']);
        self::assertSame(Seeds::revision(), $answer['seeds_revision']);
        self::assertSame('dc', $answer['region']['region_id']);
        self::assertSame([['dc', self::VERSION]], $this->rows->manifestsRead, 'the manifest of the active version, once');
    }

    public function testTheTrafficLineIsLeftOutWhileTheTableIsNeutral(): void
    {
        // The seed table of revision 1 is not neutral: the line is shown, for either matrix.
        self::assertFalse(SourcesService::trafficIsNeutral(Seeds::assumptions([], ['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => []])));
        self::assertFalse(SourcesService::trafficIsNeutral(Seeds::defaults()));
        self::assertArrayHasKey(11, self::texts($this->service()->build(self::truck())['attribution']));

        // With every factor and the typical value at 1 it is: eleven strings, none of them the traffic line.
        $neutral = $this->service()->build(self::truck('dc', self::neutralTraffic()));
        $texts = self::texts($neutral['attribution']);
        self::assertCount(11, $texts);
        self::assertArrayNotHasKey(11, $texts);
        $expected = self::FILLED;
        unset($expected[11]);
        self::assertSame($expected, $texts);
        foreach ($texts as $text) {
            self::assertDoesNotMatchRegularExpression('/[{}]/', $text);
        }

        // One hour off 1, or a typical value off 1, and the table is not neutral.
        $almost = self::neutralTraffic();
        $almost['traffic.dc'][3][17] = 1.01;
        self::assertArrayHasKey(11, self::texts($this->service()->build(self::truck('dc', $almost))['attribution']));
        $almost = self::neutralTraffic();
        $almost['traffic.dc_typical'] = 1.265;
        self::assertArrayHasKey(11, self::texts($this->service()->build(self::truck('dc', $almost))['attribution']));

        // The table that counts is the region's own: a neutral `us_mean` says nothing about `dc`.
        self::assertArrayHasKey(11, self::texts($this->service()->build(self::truck('dc', self::neutralTraffic('us_mean')))['attribution']));
        $this->rows->config['traffic_matrix'] = 'us_mean';
        self::assertArrayNotHasKey(11, self::texts($this->service()->build(self::truck('dc', self::neutralTraffic('us_mean')))['attribution']));
    }

    public function testAStringWhosePlaceholderCannotBeFilledIsLeftOut(): void
    {
        // The owner's own fuel price has no week: no fuel line.
        $this->fuel = ['price_per_gal' => 3.999, 'source' => 'owner', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => null];
        $texts = self::texts($this->service()->build(self::truck())['attribution']);
        self::assertArrayNotHasKey(7, $texts);
        self::assertCount(11, $texts);

        // The seed price is an EIA price of a stated week: the line names that week.
        $this->fuel = ['price_per_gal' => 4.195, 'source' => 'seed', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28'];
        self::assertSame(self::FILLED[7], self::texts($this->service()->build(self::truck())['attribution'])[7]);

        // No contact address on the server: the offer of the places table cannot be made.
        $this->settings = [];
        $answer = $this->service()->build(self::truck());
        self::assertSame('', $answer['contact']);
        self::assertArrayNotHasKey(5, self::texts($answer['attribution']));

        // A manifest that lacks a value: the string that needs it goes, the others stay.
        $this->settings = ['MAIL_FROM' => 'noreply@example.com'];
        unset($this->rows->manifest['totals']['jobs_spread'], $this->rows->manifest['vintages']['lodes_year']);
        $texts = self::texts($this->service()->build(self::truck())['attribution']);
        self::assertSame([1, 2, 3, 5, 6, 7, 8, 9, 11, 12], array_keys($texts));
        self::assertStringEndsWith('on request: noreply@example.com.', $texts[5]);
    }

    public function testTheContactIsTheTruckPlannerAddressElseTheMailSender(): void
    {
        self::assertSame('owner@example.com', $this->service()->build(null)['contact']);
        $this->settings = ['TP_CONTACT_EMAIL' => null, 'MAIL_FROM' => 'noreply@example.com'];
        self::assertSame('noreply@example.com', $this->service()->build(null)['contact']);
        $this->settings = ['TP_CONTACT_EMAIL' => '', 'MAIL_FROM' => ''];
        self::assertSame('', $this->service()->build(null)['contact']);
    }

    public function testWithoutATruckTheLinesThatNeedNoDatasetAreStillGiven(): void
    {
        $answer = $this->service()->build(null);

        self::assertNull($answer['region']);
        self::assertNull($answer['dataset']);
        self::assertNull($answer['fuel']);
        self::assertSame('owner@example.com', $answer['contact']);
        $texts = self::texts($answer['attribution']);
        self::assertSame([1, 2, 3, 6, 8, 9, 11, 12], array_keys($texts));
        foreach ($texts as $id => $text) {
            self::assertSame(self::FILLED[$id], $text);
        }
        self::assertSame([], $this->rows->manifestsRead);
        self::assertSame(Estimator::MODEL_VERSION, $answer['model_version']);
    }

    public function testARegionWithoutLoadedDataHasNoDataset(): void
    {
        // Region `none`.
        $answer = $this->service()->build(self::truck('none'));
        self::assertNull($answer['region']);
        self::assertNull($answer['dataset']);
        self::assertSame($this->fuel, $answer['fuel']);
        self::assertSame([1, 2, 3, 6, 7, 8, 9, 11, 12], array_keys(self::texts($answer['attribution'])));

        // A region whose data is not loaded.
        $this->regions->activeVersion = null;
        $answer = $this->service()->build(self::truck());
        self::assertSame('dc', $answer['region']['region_id']);
        self::assertNull($answer['dataset']);
        self::assertSame([1, 2, 3, 6, 7, 8, 9, 11, 12], array_keys(self::texts($answer['attribution'])));
        self::assertSame([], $this->rows->manifestsRead);
    }

    // ------------------------------------------------------------------------------------ the dataset block

    public function testTheDatasetIsDescribedFromItsManifest(): void
    {
        $dataset = $this->service()->build(self::truck())['dataset'];

        self::assertSame([
            'dataset_version' => self::VERSION,
            'pipeline_version' => 'tp-etl-1.0.0',
            'corrections_version' => '2026-10-05.1',
            'places_source' => 'geofabrik',
            'vintages' => ['census_reference_date' => '2020-04-01', 'lodes_year' => 2023, 'osm_snapshot_date' => '2026-10-03'],
            'counts' => ['points' => 60678, 'places' => 25276, 'cells' => 61460],
            'totals' => ['residents' => 6278542, 'jobs' => 3140158],
            // Each gate of level `warn` that did not pass, once, in the order of the manifest.
            'warn_gates' => ['G15', 'G12'],
        ], $dataset);
    }

    public function testAManifestWithGapsGivesNullsNotErrors(): void
    {
        $this->rows->manifest = ['pipeline_version' => 'tp-etl-1.0.0', 'gates' => 'none', 'counts' => ['points' => 5]];

        $answer = $this->service()->build(self::truck());

        self::assertSame([
            'dataset_version' => self::VERSION,
            'pipeline_version' => 'tp-etl-1.0.0',
            'corrections_version' => null,
            'places_source' => null,
            'vintages' => ['census_reference_date' => null, 'lodes_year' => null, 'osm_snapshot_date' => null],
            'counts' => ['points' => null, 'places' => null, 'cells' => null],
            'totals' => ['residents' => null, 'jobs' => null],
            'warn_gates' => [],
        ], $answer['dataset']);
        self::assertSame([1, 2, 3, 6, 7, 8, 9, 11, 12], array_keys(self::texts($answer['attribution'])));

        // A version whose manifest cannot be read at all has no dataset block.
        $this->rows->manifest = null;
        self::assertNull($this->service()->build(self::truck())['dataset']);
    }

    public function testNumbersOfTheManifestArePrintedAsWholeNumbers(): void
    {
        $this->rows->manifest['totals']['jobs_spread'] = 1287.5;
        $this->rows->manifest['totals']['blocks_adjusted'] = 4.0;
        $this->rows->manifest['parameters']['cns04_weight'] = 0.285;
        $this->rows->manifest['vintages']['census_reference_date'] = '2030-04-01';
        $this->rows->manifest['vintages']['lodes_year'] = 2031;

        $texts = self::texts($this->service()->build(self::truck())['attribution']);

        // Half a job rounds away from zero, like every rounding of the model; 28.5 % is shown as 29 %.
        self::assertStringContainsString('4 payroll-address blocks holding 1288 jobs were spread', $texts[4]);
        self::assertStringEndsWith('construction jobs count at 29 %.', $texts[4]);
        self::assertSame("People: US Census 2030, LEHD 2031 \u{00B7} Venues: \u{00A9} OpenStreetMap contributors", $texts[10]);
    }

    // ------------------------------------------------------------------------------------ the two helpers

    public function testAValueThatBringsABraceIsNotSent(): void
    {
        $lines = SourcesService::attribution(['contact' => 'someone{at}example.com', 'osm_snapshot_date' => '2026-10-03', 'period' => '2026-09-28'], false);
        $texts = self::texts($lines);
        self::assertArrayNotHasKey(5, $texts);
        self::assertSame(self::FILLED[7], $texts[7]);
        foreach ($texts as $text) {
            self::assertDoesNotMatchRegularExpression('/[{}]/', $text);
        }
        // Nothing to fill with: the eight strings that need nothing.
        self::assertSame([1, 2, 3, 6, 8, 9, 11, 12], array_keys(self::texts(SourcesService::attribution([], false))));
        self::assertSame([1, 2, 3, 6, 8, 9, 12], array_keys(self::texts(SourcesService::attribution([], true))));
    }

    public function testNoStringSaysAnythingAboutWhereATruckMayStand(): void
    {
        foreach (SourcesService::TEXTS as $text) {
            foreach (['legal', 'permitted', 'allowed to park', 'approved', 'permit'] as $word) {
                self::assertStringNotContainsStringIgnoringCase($word, $text);
            }
        }
    }
}

/**
 * The region rows as the sources answer reads them: the region `dc` with a manifest shaped like the one of
 * its first real build.
 */
final class SourcesRegionRows extends RegionRepository
{
    /** @var array<string, mixed>|null the manifest of the active version */
    public ?array $manifest;

    /** @var array<string, mixed> the region definition */
    public array $config = ['traffic_matrix' => 'dc', 'holidays' => ['inauguration_day' => true]];

    /** @var list<array{0: string, 1: string}> */
    public array $manifestsRead = [];

    public function __construct()
    {
        parent::__construct(new RecordingDatabase());
        $this->manifest = [
            'dataset_version' => 'dc-20261003-d0514a63',
            'pipeline_version' => 'tp-etl-1.0.0',
            'model_version' => 'tps-0.1.0',
            'inputs' => ['places_source' => 'geofabrik', 'seeds_revision' => 1, 'corrections_version' => '2026-10-05.1'],
            'vintages' => [
                'lodes_year' => 2023, 'lodes_format' => '8.4', 'lodes_vintage' => '20251202_1657', 'osm_snapshot_date' => '2026-10-03',
                'census_reference_date' => '2020-04-01', 'osm_replication_timestamp' => '2026-10-03T20:20:50Z',
            ],
            'counts' => [
                'cells' => ['kept' => 61460, 'occupied' => 29404, 'candidates' => 150849],
                'places' => ['halo' => 390, 'named' => 24589, 'total' => 25276],
                'points' => ['block' => 51262, 'place' => 7952, 'total' => 60678],
            ],
            'totals' => ['jobs' => 3140158, 'residents' => 6278542, 'jobs_spread' => 196941, 'blocks_adjusted' => 31, 'housing_units' => 2458414],
            'parameters' => ['cns04_weight' => 0.3, 'walk_decay_m' => 400],
            'gates' => [
                ['id' => 'G1', 'part' => 'files', 'pass' => true, 'level' => 'fail'],
                ['id' => 'G10', 'part' => null, 'pass' => true, 'level' => 'warn'],
                ['id' => 'G15', 'part' => 'flagged', 'pass' => true, 'level' => 'warn'],
                ['id' => 'G15', 'part' => 'unconfirmed', 'pass' => false, 'level' => 'warn'],
                ['id' => 'G12', 'part' => 'hours', 'pass' => false, 'level' => 'warn'],
                ['id' => 'G15', 'part' => 'entries', 'pass' => false, 'level' => 'warn'],
                ['id' => 'G16', 'part' => 'share', 'pass' => false, 'level' => 'fail'],
            ],
        ];
    }

    public function find(string $regionId): ?array
    {
        if ($regionId !== 'dc') {
            return null;
        }
        return [
            'region_id' => 'dc', 'name' => 'Washington, DC region', 'cbsa' => '47900', 'timezone' => 'America/New_York', 'h3_res' => 9,
            'bbox_lat_min' => 37.99, 'bbox_lng_min' => -78.39, 'bbox_lat_max' => 39.72, 'bbox_lng_max' => -76.66,
            'center_lat' => 38.9072, 'center_lng' => -77.0369, 'active_version' => 'dc-20261003-d0514a63', 'previous_version' => null,
            'config' => $this->config, 'created_at' => '2026-10-04 00:00:00', 'updated_at' => '2026-10-04 00:00:00',
        ];
    }

    public function manifest(string $regionId, string $version): ?array
    {
        $this->manifestsRead[] = [$regionId, $version];
        return $this->manifest;
    }
}

/**
 * The region service as the fixture stands: `dc` with one active version, or none.
 */
final class SourcesRegions extends RegionService
{
    public ?string $activeVersion = 'dc-20261003-d0514a63';

    public function active(string $regionId): ?array
    {
        if ($regionId !== 'dc' || $this->activeVersion === null) {
            return null;
        }
        return [
            'region_id' => 'dc', 'dataset_version' => $this->activeVersion, 'timezone' => 'America/New_York', 'h3_res' => 9,
            'usable' => true, 'unusable_reason' => null, 'config' => [],
        ];
    }

    public function info(string $regionId): ?array
    {
        if ($regionId !== 'dc') {
            return null;
        }
        return ['region_id' => 'dc', 'name' => 'Washington, DC region', 'dataset_version' => $this->activeVersion, 'usable' => $this->activeVersion !== null];
    }
}
