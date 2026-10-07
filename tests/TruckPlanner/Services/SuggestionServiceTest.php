<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\MemoryCache;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\CountsRepository;
use App\TruckPlanner\Data\LegKey;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\CalibrationProvider;
use App\TruckPlanner\Services\Contracts\DayContextProvider;
use App\TruckPlanner\Services\Contracts\FuelPriceProvider;
use App\TruckPlanner\Services\Contracts\LegProvider;
use App\TruckPlanner\Services\Fallback\PlainDayContexts;
use App\TruckPlanner\Services\Fallback\StraightLineLegs;
use App\TruckPlanner\Services\SpotService;
use App\TruckPlanner\Services\SuggestionService;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpInvalid;
use PHPUnit\Framework\TestCase;

// The in-memory spot table, the fixture region and its region service live with the spot service's test.
require_once __DIR__ . '/SpotServiceTest.php';

/**
 * SuggestionService on the worked example of 02_MODEL.md 4.15: the office park of anchor A1 and the taproom
 * of anchor A2 as two saved spots, the drive legs of the blueprint day sheet, the week of 2026-10-05, no
 * forecast. The spots are real rows behind the real SpotService (the fixture region answers the capture
 * contract), so what reaches the model is what a request would hand it. The expected answers are the
 * golden cases g21-002, g21-012 and g21-013, which the Python reference wrote.
 *
 * Then the leg strategy of 04_BACKEND.md 5.10, with a leg provider that records what it was asked.
 */
final class SuggestionServiceTest extends TestCase
{
    private const ORG = SpotServiceTest::ORG;
    private const OTHER_ORG = SpotServiceTest::OTHER_ORG;
    private const FIXTURE = '/tests/fixtures/truck-planner/golden_cases.json';
    private const OFFICE = 'office';
    private const TAPROOM = 'taproom';
    private const TAP_HOST = ['segment' => 'v_nightlife', 'size' => 120, 'only_food' => true];

    /** The six legs of the blueprint day sheet: [minutes, miles]. */
    private const BLUEPRINT_LEGS = [
        'base>office' => [11, 4.85], 'office>base' => [11, 4.85],
        'base>taproom' => [1, 0.2], 'taproom>base' => [1, 0.2],
        'office>taproom' => [10, 4.85], 'taproom>office' => [10, 4.85],
    ];

    /** @var array<string, array{function: string, args: array<string, mixed>, expected: mixed}> */
    private static array $golden = [];

    private SpotTable $table;
    private FixtureRegion $region;
    private ScriptedLegs $legs;
    private FixedClock $clock;
    private MemoryCache $cache;
    private SpotService $spotService;
    private CountingSuggestions $service;

    /** @var object{asOf: list<string>}&CalibrationProvider */
    private object $calibration;

    /** @var object{asked: list<array{0: string, 1: int, 2: array<string, ?string>}>}&DayContextProvider */
    private object $contexts;

    public static function setUpBeforeClass(): void
    {
        // One case a line: only the three that are wanted are decoded.
        $wanted = ['g21-002', 'g21-012', 'g21-013'];
        $file = new \SplFileObject(dirname(__DIR__, 3) . self::FIXTURE);
        foreach ($file as $line) {
            foreach ($wanted as $id) {
                if (is_string($line) && str_contains($line, '"id": "' . $id . '"')) {
                    self::$golden[$id] = json_decode(rtrim(rtrim($line), ','), true, 512, JSON_THROW_ON_ERROR);
                }
            }
        }
        self::assertCount(3, self::$golden, 'the golden cases of the suggestions example were not found');
    }

    protected function setUp(): void
    {
        $this->table = new SpotTable();
        $this->region = FixtureRegion::standard();
        $regions = new FixtureRegions($this->region);
        $this->legs = new ScriptedLegs();
        // 13:00 UTC on Monday 2026-10-05 is 09:00 that Monday where the truck is.
        $this->clock = new FixedClock('2026-10-05 13:00:00');
        $this->cache = new MemoryCache();
        TpCache::wire($this->clock, $this->cache);

        $this->calibration = new class implements CalibrationProvider {
            /** @var list<string> */
            public array $asOf = [];

            public function state(string $orgId, array $truck, array $A, string $asOf): array
            {
                $this->asOf[] = $asOf;
                return Estimator::calibrate($A, [], $asOf);
            }
        };
        $this->contexts = new class implements DayContextProvider {
            /** @var list<array{0: string, 1: int, 2: array<string, ?string>}> */
            public array $asked = [];

            public function contexts(array $truck, array $A, string $from, int $days, array $treatAs = []): array
            {
                $this->asked[] = [$from, $days, $treatAs];
                return (new PlainDayContexts())->contexts($truck, $A, $from, $days, $treatAs);
            }
        };
        $fuel = new class implements FuelPriceProvider {
            public function resolve(array $truck): array
            {
                // The price of the example: regular gasoline at $4.195, the seed value.
                return ['price_per_gal' => 4.195, 'source' => 'seed', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28'];
            }
        };

        Registry::reset();
        Registry::set('capture', $this->region);
        Registry::set('legs', $this->legs);
        Registry::set('fuel', $fuel);
        Registry::set('dayContexts', $this->contexts);
        Registry::set('calibration', $this->calibration);

        $spots = new SpotRepository($this->table);
        $this->spotService = new SpotService($spots, new CountsRepository(new RecordingDatabase()), $regions);
        $this->service = new CountingSuggestions($spots, $this->spotService, $this->clock);
    }

    protected function tearDown(): void
    {
        Registry::reset();
        TpCache::wire();
        TpConfig::replace(null);
    }

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * The Assumptions of the example: no overrides, the `dc` region block.
     *
     * @return array<string, mixed>
     */
    private static function A(): array
    {
        $given = self::$golden['g21-002']['args']['A'];
        return Seeds::assumptions((array) $given['overrides'], $given['region']);
    }

    /**
     * A saved spot with an id of the test's choosing (the repository would draw a random one, and the
     * model breaks ties by id).
     *
     * @param array<string, mixed> $body
     */
    private function saveSpot(string $id, array $body, string $org = self::ORG): void
    {
        $truck = SpotServiceTest::truck();
        $spot = $this->spotService->create($org, $truck, SpotServiceTest::USER, $body);
        $row = $this->table->rows[$spot['id']];
        unset($this->table->rows[$spot['id']]);
        $row['id'] = $id;
        $this->table->rows[$id] = $row;
    }

    /** The two spots of the example: anchor A1 as `office`, anchor A2 as `taproom`. */
    private function saveExampleSpots(): void
    {
        // A census block of the region within sight of the taproom and out of walking reach: it makes the
        // taproom a point inside the region (as it is in the example) and adds nobody to its catchment.
        $this->region->addBlock('b511076110001001', FixtureRegion::north(FixtureRegion::TAPROOM['lat'], 1500.0), FixtureRegion::TAPROOM['lng'], 0, 0.0, true);
        $this->saveSpot(self::OFFICE, ['name' => 'Herndon office park', 'point' => FixtureRegion::OFFICE]);
        $this->saveSpot(self::TAPROOM, ['name' => 'Sterling taproom', 'point' => FixtureRegion::TAPROOM, 'terms' => ['host' => self::TAP_HOST]]);
    }

    /**
     * The legs of the blueprint day sheet as the leg provider would answer them: the owner's minutes over
     * a routed leg of the sheet's miles.
     *
     * @param list<string> $keys the legs the provider knows; all six when empty
     */
    private function knowBlueprintLegs(array $keys = []): void
    {
        foreach (self::BLUEPRINT_LEGS as $key => [$minutes, $miles]) {
            if ($keys !== [] && !in_array($key, $keys, true)) {
                continue;
            }
            $this->legs->cached[$key] = ['distance_m' => $miles * 1609.344, 'duration_s' => 0.0];
            $this->legs->corrections[$key] = ['minutes' => $minutes, 'toll' => null];
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function day(array $body = [], ?array $truck = null): array
    {
        return $this->service->day(self::ORG, $truck ?? SpotServiceTest::truck(), self::A(), $body + ['date' => '2026-10-08']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function week(array $body = []): array
    {
        return $this->service->week(self::ORG, SpotServiceTest::truck(), self::A(), $body + ['week_start' => '2026-10-05']);
    }

    /**
     * A suggestion as the service sends it: the hours of every window left out.
     *
     * @param array<string, mixed> $suggestion
     * @return array<string, mixed>
     */
    private static function withoutHours(array $suggestion): array
    {
        foreach ($suggestion['result']['stops'] as $i => $stop) {
            $suggestion['result']['stops'][$i]['window']['hours'] = [];
        }
        return $suggestion;
    }

    /**
     * Two values agree the way golden cases are compared (02_MODEL.md 1.4): numbers within 1e-9 relative,
     * everything else exactly, arrays key by key.
     */
    private static function assertAgrees(mixed $expected, mixed $actual, string $path = ''): void
    {
        $isNumber = static fn (mixed $v): bool => is_int($v) || is_float($v);
        if ($isNumber($expected) && $isNumber($actual)) {
            $tolerance = 1e-9 * max(1.0, abs((float) $expected), abs((float) $actual));
            self::assertEqualsWithDelta((float) $expected, (float) $actual, $tolerance, $path);
            return;
        }
        if (is_array($expected) && is_array($actual)) {
            self::assertSame(array_keys($expected), array_keys($actual), 'keys at ' . $path);
            foreach ($expected as $key => $value) {
                self::assertAgrees($value, $actual[$key], $path . '.' . $key);
            }
            return;
        }
        self::assertSame($expected, $actual, $path);
    }

    /**
     * What the golden case leaves aside when the spots come from rows instead of from the reference's own
     * records: nothing in the result. The hours are emptied on both sides.
     *
     * @param list<array<string, mixed>> $expected Suggestion list of the golden case
     * @param list<array<string, mixed>> $actual
     */
    private static function assertSuggestions(array $expected, array $actual): void
    {
        self::assertCount(count($expected), $actual);
        foreach ($expected as $i => $suggestion) {
            self::assertAgrees(self::sorted(self::withoutHours($suggestion)), self::sorted($actual[$i]), 'suggestions.' . $i);
        }
    }

    /** Keys in one order on both sides: the golden file sorts them, the model writes them as documented. */
    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sorted($item);
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    private static function assertInvalid(string $message, ?string $field, ?string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail('no validation error, expected: ' . $message);
        } catch (TpInvalid $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame($field, $e->field());
            self::assertSame($code, $e->rule());
        }
    }

    /**
     * @param array<string, mixed> $estimate
     * @return list<float> value, low and high in cents
     */
    private static function cents(array $estimate): array
    {
        return [
            Estimator::roundHalfAway((float) $estimate['value'], 2),
            Estimator::roundHalfAway((float) $estimate['low'], 2),
            Estimator::roundHalfAway((float) $estimate['high'], 2),
        ];
    }

    // ------------------------------------------------------------------------------------ the example

    public function testTheSavedSpotsAreTheAnchorsOfTheExample(): void
    {
        $this->saveExampleSpots();
        $given = [];
        foreach (self::$golden['g21-002']['args']['spots'] as $spot) {
            $given[$spot['spot_id']] = $spot;
        }
        $rows = (new SpotRepository($this->table))->findMany([self::OFFICE, self::TAPROOM], self::ORG);
        foreach ([self::OFFICE, self::TAPROOM] as $id) {
            $value = $this->spotService->ensureFresh(self::ORG, SpotServiceTest::truck(), $rows[$id]);
            $vectors = $value['vectors']['normal'];
            self::assertAgrees($given[$id]['vectors']['capture'], $vectors['capture'], $id . '.capture');
            self::assertAgrees($given[$id]['vectors']['nearby'], $vectors['nearby'], $id . '.nearby');
            self::assertAgrees($given[$id]['vectors']['rivals'], $vectors['rivals'], $id . '.rivals');
            self::assertSame($given[$id]['vectors']['in_region'], $vectors['in_region'], $id . '.in_region');
            self::assertSame('fresh', $value['vectors_state']);
        }
        // The taproom's own visitors are taken out of its catchment: they arrive through the host term.
        self::assertSame('p' . FixtureRegion::TAPROOM_KEY, $this->table->rows[self::TAPROOM]['host_point_id']);
        self::assertSame(120.0, (float) $this->table->rows[self::TAPROOM]['host_size']);
    }

    public function testSuggestDayReproducesTheExampleWithTheSameLegs(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();

        $answer = $this->day();

        // The table of 02_MODEL.md 4.15, Thursday 2026-10-08.
        self::assertSame(2, $answer['spots_considered']);
        self::assertSame(0, $answer['fallback_pairs']);
        self::assertCount(3, $answer['suggestions']);
        [$first, $second, $third] = $answer['suggestions'];
        self::assertSame(1, $first['position']);
        self::assertSame(
            [['spot_id' => self::OFFICE, 'open_minute' => 660, 'close_minute' => 840], ['spot_id' => self::TAPROOM, 'open_minute' => 1020, 'close_minute' => 1200]],
            $first['stops']
        );
        self::assertSame([482.2, 42.35, 1011.86], self::cents($first['take_home']));
        self::assertSame('rough', $first['take_home']['confidence']);
        self::assertSame(677, $first['day_minutes']);
        self::assertSame(2, $second['position']);
        self::assertSame([['spot_id' => self::OFFICE, 'open_minute' => 660, 'close_minute' => 840]], $second['stops']);
        self::assertSame([347.18, 85.0, 659.18], self::cents($second['take_home']));
        self::assertSame(327, $second['day_minutes']);
        self::assertSame([['spot_id' => self::TAPROOM, 'open_minute' => 1020, 'close_minute' => 1200]], $third['stops']);
        self::assertSame(163.31, self::cents($third['take_home'])[0]);

        // The same legs: the blueprint day sheet, 9:34 to 20:51.
        $timeline = $first['result']['timeline'];
        self::assertSame([574, 619, 1221, 1251], [$timeline['start_prep'], $timeline['leave_base'], $timeline['back_at_base'], $timeline['done']]);
        self::assertSame([11, 10, 1], array_column($timeline['legs'], 'minutes'));
        self::assertSame(['override', 'override', 'override'], array_column($timeline['legs'], 'source'));

        // And the whole answer is the reference's, number for number.
        self::assertSuggestions(self::$golden['g21-002']['expected'], $answer['suggestions']);
        self::assertAgrees(self::sorted(self::$golden['g21-002']['args']['ctx']), self::sorted($answer['context']), 'context');

        // One run of the model was enough: every leg was at hand.
        self::assertSame(1, $this->service->dayRuns);
    }

    public function testSuggestWeekReproducesTheExampleWithTheSameLegs(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();

        $answer = $this->week();
        $week = $answer['week'];

        // Five days, two visits to a spot: Tuesday and Wednesday at the office, Friday and Saturday at the taproom.
        self::assertSame('2026-10-05', $week['week_start']);
        self::assertSame(1550.49, self::cents($week['total_take_home'])[0]);
        self::assertSame(407, $week['leaves_visited']);
        self::assertSame([self::OFFICE => 2, self::TAPROOM => 2], $week['visits']);
        $chosen = [];
        foreach ($week['days'] as $d => $day) {
            self::assertSame(Estimator::addDays('2026-10-05', $d), $day['date']);
            $chosen[] = $day['suggestion'] === null ? null : [$day['suggestion']['position'], array_column($day['suggestion']['stops'], 'spot_id')];
        }
        self::assertSame(
            [null, [1, [self::OFFICE]], [2, [self::OFFICE]], null, [2, [self::TAPROOM]], [1, [self::TAPROOM]], null],
            $chosen
        );
        self::assertSame(2, $answer['spots_considered']);
        self::assertSame(0, $answer['fallback_pairs']);

        // The whole week is the reference's.
        $expected = self::$golden['g21-012']['expected'];
        self::assertAgrees(self::sorted($expected['total_take_home']), self::sorted($week['total_take_home']), 'total_take_home');
        foreach ($expected['days'] as $d => $day) {
            if ($day['suggestion'] === null) {
                self::assertNull($week['days'][$d]['suggestion']);
                continue;
            }
            self::assertAgrees(self::sorted(self::withoutHours($day['suggestion'])), self::sorted($week['days'][$d]['suggestion']), 'days.' . $d);
        }

        // The eight contexts of one question to the provider: the week and the Monday after.
        self::assertSame([['2026-10-05', 8, []]], $this->contexts->asked);
        self::assertSame(1, $this->service->weekRuns);
    }

    public function testThreeVisitsToASpotAddTheThursdayOfTheExample(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();

        $week = $this->week(['options' => ['max_visits_per_spot_per_week' => 3]])['week'];

        self::assertSame(2032.69, self::cents($week['total_take_home'])[0]);
        self::assertSame(1419, $week['leaves_visited']);
        self::assertSame([self::OFFICE => 3, self::TAPROOM => 3], $week['visits']);
        self::assertSame([self::OFFICE, self::TAPROOM], array_column($week['days'][3]['suggestion']['stops'], 'spot_id'));
        $expected = self::$golden['g21-013']['expected'];
        self::assertAgrees(self::sorted($expected['total_take_home']), self::sorted($week['total_take_home']), 'total_take_home');
        self::assertAgrees(self::sorted(self::withoutHours($expected['days'][3]['suggestion'])), self::sorted($week['days'][3]['suggestion']), 'thursday');
    }

    public function testTheHoursOfEveryWindowAreLeftOutAndTheRestOfTheResultStays(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();

        foreach ($this->day()['suggestions'] as $suggestion) {
            foreach ($suggestion['result']['stops'] as $stop) {
                self::assertSame([], $stop['window']['hours']);
                self::assertGreaterThan(0.0, $stop['window']['orders']['value']);
                self::assertLessThanOrEqual($stop['orders']['value'], $stop['orders']['low']);
                self::assertGreaterThanOrEqual($stop['orders']['value'], $stop['orders']['high']);
                self::assertContains($stop['orders']['confidence'], ['very_rough', 'rough', 'fair', 'good']);
                self::assertArrayHasKey('adds', $stop);
            }
            // Every estimate of a suggestion carries its range and its label.
            foreach ([$suggestion['take_home'], $suggestion['orders']] as $estimate) {
                self::assertSame(['value', 'low', 'high', 'confidence'], array_keys($estimate));
                self::assertLessThanOrEqual($estimate['value'], $estimate['low']);
                self::assertGreaterThanOrEqual($estimate['value'], $estimate['high']);
            }
        }
        $week = $this->week()['week'];
        foreach ($week['days'] as $day) {
            foreach ($day['suggestion']['result']['stops'] ?? [] as $stop) {
                self::assertSame([], $stop['window']['hours']);
            }
        }
    }

    // ------------------------------------------------------------------------------------ the leg strategy

    public function testBaseLegsAreFetchedAndSpotToSpotLegsAreReadFromTheCacheOnly(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();

        $this->day();

        self::assertCount(2, $this->legs->calls);
        [$base, $between] = $this->legs->calls;
        // (a) base to and from every spot: 2N pairs, fetched, with tolls.
        self::assertSame(
            [['base', self::OFFICE], [self::OFFICE, 'base'], ['base', self::TAPROOM], [self::TAPROOM, 'base']],
            $base['pairs']
        );
        self::assertSame(['tolls' => true], $base['options']);
        self::assertSame(['base', self::OFFICE, self::TAPROOM], $base['points']);
        // (b) every ordered pair of spots, from the cache only.
        self::assertSame([[self::OFFICE, self::TAPROOM], [self::TAPROOM, self::OFFICE]], $between['pairs']);
        self::assertSame(['fetch' => false], $between['options']);
        self::assertSame(self::ORG, $base['org']);
        self::assertSame(SpotServiceTest::TRUCK, $base['truck']);

        // The model was handed the six legs under the keys it looks up.
        $handed = $this->service->legsOfRuns[0];
        self::assertSame(array_keys(self::BLUEPRINT_LEGS), array_keys($handed));
        self::assertSame(
            ['source' => 'google', 'distance_m' => 4.85 * 1609.344, 'duration_s' => 0.0, 'override_minutes' => 10, 'toll' => 0.0],
            $handed['office>taproom']
        );
    }

    public function testALegThatIsNotCachedIsLeftOutAndTheModelFillsItWithItsOwnEstimate(): void
    {
        $this->saveExampleSpots();
        // Nothing is cached and nothing can be fetched: every leg is a labelled straight line.
        $this->legs->reason = 'no_key';

        $answer = $this->day();

        // No straight line travels in the leg map: the model computes the same one for a missing pair.
        self::assertSame([[]], $this->service->legsOfRuns);
        $spots = $this->service->spotsOfRuns[0];
        $context = (new PlainDayContexts())->contexts(SpotServiceTest::truck(), self::A(), '2026-10-08', 2)['days'];
        $direct = Estimator::suggestDay(
            self::A(),
            SpotServiceTest::truck()['profile'],
            $context[0]['context'],
            $context[1]['context'],
            $spots,
            [],
            Estimator::calibrate(self::A(), [], '2026-10-05'),
            null
        );
        self::assertSame(array_map(static fn (array $s): array => self::withoutHours($s), $direct), $answer['suggestions']);

        // And it is what the leg provider itself calls a straight line between the two points.
        $first = $answer['suggestions'][0];
        self::assertSame([self::OFFICE, self::TAPROOM], array_column($first['stops'], 'spot_id'));
        $legs = $first['result']['timeline']['legs'];
        self::assertSame(['fallback', 'fallback', 'fallback'], array_column($legs, 'source'));
        $line = StraightLineLegs::straightLine(self::OFFICE, self::TAPROOM, FixtureRegion::OFFICE, FixtureRegion::TAPROOM, 'no_key');
        self::assertSame($line['leg_input']['distance_m'], $legs[1]['distance_m']);
        self::assertSame($line['leg_input']['duration_s'] / 60.0, $legs[1]['base_minutes']);

        // Five different drives in the three suggestions rest on an estimate: out and back for each spot,
        // and the drive between them.
        self::assertSame(5, $answer['fallback_pairs']);
        self::assertContains('fallback_drive_time', array_column($first['result']['warnings'], 'code'));
    }

    public function testASuggestionOnAnEstimatedSpotToSpotLegIsRefinedOnce(): void
    {
        $this->saveExampleSpots();
        // The base legs are cached. The drive between the spots is not, but Google has it.
        $this->knowBlueprintLegs(['base>office', 'office>base', 'base>taproom', 'taproom>base']);
        $this->legs->upstream['office>taproom'] = ['distance_m' => 7805.0, 'duration_s' => 600.0];
        $this->legs->upstream['taproom>office'] = ['distance_m' => 7805.0, 'duration_s' => 600.0];

        $answer = $this->day();

        // First run: the pair is absent. The best plan drives it, so exactly that pair is fetched.
        self::assertCount(3, $this->legs->calls);
        self::assertSame([[self::OFFICE, self::TAPROOM]], $this->legs->calls[2]['pairs']);
        self::assertSame(['tolls' => true], $this->legs->calls[2]['options']);
        self::assertSame([self::OFFICE, self::TAPROOM], $this->legs->calls[2]['points']);
        self::assertSame(2, $this->service->dayRuns);
        self::assertArrayNotHasKey('office>taproom', $this->service->legsOfRuns[0]);
        self::assertSame(
            ['source' => 'google', 'distance_m' => 7805.0, 'duration_s' => 600.0, 'override_minutes' => null, 'toll' => 0.0],
            $this->service->legsOfRuns[1]['office>taproom']
        );
        // The pair nobody drives was not fetched.
        self::assertArrayNotHasKey('taproom>office', $this->service->legsOfRuns[1]);

        // The second run is the answer: the drive between the spots is Google's now.
        $legs = $answer['suggestions'][0]['result']['timeline']['legs'];
        self::assertSame(['override', 'google', 'override'], array_column($legs, 'source'));
        self::assertSame(0, $answer['fallback_pairs']);
        self::assertSame(
            array_map(static fn (array $s): array => self::withoutHours($s), $this->service->resultsOfRuns[1]),
            $answer['suggestions']
        );
    }

    public function testTheRefinementPassRunsAtMostOnce(): void
    {
        $this->saveExampleSpots();
        // A second lunch spot at the office park, a little dearer: second best at lunch.
        $this->saveSpot('office2', ['name' => 'Herndon office park, far lot', 'point' => FixtureRegion::OFFICE, 'terms' => ['fee_flat' => 25]]);
        $this->knowBlueprintLegs(['base>office', 'office>base', 'base>taproom', 'taproom>base']);
        $this->legs->cached['base>office2'] = $this->legs->cached['base>office'];
        $this->legs->cached['office2>base'] = $this->legs->cached['office>base'];
        $this->legs->corrections['base>office2'] = $this->legs->corrections['base>office'];
        $this->legs->corrections['office2>base'] = $this->legs->corrections['office>base'];
        // Google's answer for the best plan's drive turns out to be five hours: that plan falls away.
        $this->legs->upstream['office>taproom'] = ['distance_m' => 400000.0, 'duration_s' => 18000.0];

        $answer = $this->day(['options' => ['limit' => 1]]);

        // Run 1 suggested office then taproom on an estimate; that pair was fetched; run 2 answers.
        self::assertSame([self::OFFICE, self::TAPROOM], array_column($this->service->resultsOfRuns[0][0]['stops'], 'spot_id'));
        self::assertSame([[self::OFFICE, self::TAPROOM]], $this->legs->calls[2]['pairs']);
        self::assertSame(2, $this->service->dayRuns);

        // The answer drives from the other lot to the taproom, on an estimate that was never fetched ...
        self::assertSame(['office2', self::TAPROOM], array_column($answer['suggestions'][0]['stops'], 'spot_id'));
        self::assertArrayNotHasKey('office2>taproom', $this->service->legsOfRuns[1]);
        self::assertSame('fallback', $answer['suggestions'][0]['result']['timeline']['legs'][1]['source']);
        self::assertSame(1, $answer['fallback_pairs']);
        // ... and that is where it ends: no third question to the provider, no third run.
        self::assertCount(3, $this->legs->calls);
        self::assertSame(2, $this->service->dayRuns);
    }

    public function testNothingIsFetchedAgainWhenTheFetchChangesNoLeg(): void
    {
        $this->saveExampleSpots();
        // The provider would fetch, but Google is not reachable: the pair stays a straight line.
        $this->legs->reason = 'quota';

        $this->day();

        self::assertCount(3, $this->legs->calls);
        self::assertSame([[self::OFFICE, self::TAPROOM]], $this->legs->calls[2]['pairs']);
        self::assertSame(1, $this->service->dayRuns, 'the same legs give the same answer: the model is not run again');
    }

    public function testAProviderThatFetchesNothingIsNotAskedTwice(): void
    {
        $this->saveExampleSpots();
        // The fallback provider (no routing service installed): every leg is a straight line "no_key",
        // also when it is asked for the cache only.
        $recorder = new ScriptedLegs();
        $recorder->delegate = new StraightLineLegs();
        Registry::set('legs', $recorder);

        $answer = $this->day();

        self::assertCount(2, $recorder->calls, 'no pair was left out for want of a fetch, so there is nothing to refine');
        self::assertSame(1, $this->service->dayRuns);
        self::assertSame(5, $answer['fallback_pairs']);
    }

    public function testTheOwnersCorrectionOfAnUncachedLegReachesTheModel(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs(['base>office', 'office>base', 'base>taproom', 'taproom>base']);
        // No routed leg between the spots, but the owner knows the drive: 14 minutes and a $2.50 toll.
        $this->legs->corrections['office>taproom'] = ['minutes' => 14, 'toll' => 2.5];
        $this->legs->reason = 'no_key';

        $answer = $this->day();

        $handed = $this->service->legsOfRuns[0]['office>taproom'];
        self::assertSame('fallback', $handed['source']);
        self::assertSame(14, $handed['override_minutes']);
        self::assertSame(2.5, $handed['toll']);
        $leg = $answer['suggestions'][0]['result']['timeline']['legs'][1];
        self::assertSame(['override', 14, 2.5], [$leg['source'], $leg['minutes'], $leg['toll']]);
        // The owner's minutes are no estimate of ours: nothing counts as a fallback here.
        self::assertSame(0, $answer['fallback_pairs']);
        // Its distance still is one, so the pair is asked for once.
        self::assertSame([[self::OFFICE, self::TAPROOM]], $this->legs->calls[2]['pairs']);
        self::assertSame(1, $this->service->dayRuns);
    }

    public function testADayOfThreeStopsAlsoRefinesTheLegThatSkipsItsMiddleStop(): void
    {
        $this->saveExampleSpots();
        // A campus between lunch and the evening, open to the truck from 14:00 to 17:00.
        $this->saveSpot('campus', [
            'name' => 'Campus green',
            'point' => FixtureRegion::LONE,
            'terms' => ['host' => ['segment' => 'v_campus', 'size' => 2000], 'allowed' => ['days' => array_fill(0, 7, true), 'open_minute' => 840, 'close_minute' => 1020]],
        ]);
        // Google is asked and gives nothing: the legs stay estimates and the model runs once.
        $this->legs->reason = 'quota';

        $answer = $this->day(['options' => ['service_minutes' => 120, 'max_stops_per_day' => 3, 'limit' => 10]]);

        $three = null;
        $read = [];
        foreach ($answer['suggestions'] as $suggestion) {
            $ids = array_column($suggestion['stops'], 'spot_id');
            if (count($ids) === 3) {
                $three ??= $ids;
            }
            foreach (Estimator::requiredLegKeys(array_map(static fn (string $id): array => ['id' => $id], $ids)) as $key) {
                if (!str_contains($key, 'base')) {
                    $read[$key] = explode('>', $key);
                }
            }
        }
        self::assertSame([self::OFFICE, 'campus', self::TAPROOM], $three, 'one suggestion works all three spots');

        // Exactly the spot-to-spot legs the suggestions read are fetched: the drives in order, and for the
        // day of three stops the drive from the office to the taproom that its "adds" figures compare with.
        self::assertCount(3, $this->legs->calls);
        self::assertSame(array_values($read), $this->legs->calls[2]['pairs']);
        self::assertContains([self::OFFICE, self::TAPROOM], $this->legs->calls[2]['pairs']);
        self::assertContains([self::OFFICE, 'campus'], $this->legs->calls[2]['pairs']);
        self::assertContains(['campus', self::TAPROOM], $this->legs->calls[2]['pairs']);
        self::assertNotContains([self::TAPROOM, self::OFFICE], $this->legs->calls[2]['pairs'], 'a drive no suggestion reads is not fetched');
        self::assertSame(1, $this->service->dayRuns);
    }

    public function testTwoSpotsAtOnePlaceShareALegOfNoLength(): void
    {
        $this->saveSpot(self::OFFICE, ['name' => 'Herndon office park', 'point' => FixtureRegion::OFFICE]);
        $this->saveSpot('office2', ['name' => 'Herndon office park, far lot', 'point' => FixtureRegion::OFFICE, 'terms' => ['fee_flat' => 25]]);

        $this->day();

        $handed = $this->service->legsOfRuns[0];
        self::assertSame(
            ['source' => 'google', 'distance_m' => 0.0, 'duration_s' => 0.0, 'override_minutes' => null, 'toll' => 0.0],
            $handed['office>office2']
        );
        self::assertArrayHasKey('office2>office', $handed);
    }

    public function testTheWeekRefinesTheLegsOfTheChosenDaysOnly(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs(['base>office', 'office>base', 'base>taproom', 'taproom>base']);
        $this->legs->upstream['office>taproom'] = ['distance_m' => 7805.0, 'duration_s' => 600.0];

        // Two visits to a spot: the chosen days are single stops, although the best Wednesday is not.
        $week = $this->week()['week'];
        $options = Estimator::suggestDay(
            self::A(),
            SpotServiceTest::truck()['profile'],
            $this->service->contextsOfRuns[0][2],
            $this->service->contextsOfRuns[0][3],
            $this->service->spotsOfRuns[0],
            $this->service->legsOfRuns[0],
            Estimator::calibrate(self::A(), [], '2026-10-05'),
            null
        );
        self::assertCount(2, $options[0]['stops'], 'the best Wednesday drives between the spots on an estimate');
        foreach ($week['days'] as $day) {
            self::assertLessThanOrEqual(1, count($day['suggestion']['stops'] ?? []));
        }
        self::assertCount(2, $this->legs->calls, 'no chosen day drives between two spots: nothing is fetched');
        self::assertSame(1, $this->service->weekRuns);

        // Three visits: Thursday is office then taproom, and exactly that drive is fetched, once.
        $this->legs->calls = [];
        $answer = $this->week(['options' => ['max_visits_per_spot_per_week' => 3]]);
        self::assertSame([[self::OFFICE, self::TAPROOM]], $this->legs->calls[2]['pairs']);
        self::assertCount(3, $this->legs->calls);
        self::assertSame(3, $this->service->weekRuns);
        self::assertSame(0, $answer['fallback_pairs']);
        $thursday = $answer['week']['days'][3]['suggestion'];
        self::assertSame(['override', 'google', 'override'], array_column($thursday['result']['timeline']['legs'], 'source'));
    }

    public function testManySpotsAreAskedForInPages(): void
    {
        // 46 spots are 2,070 ordered pairs: more than one call of 2,000.
        for ($i = 0; $i < 46; $i++) {
            $this->saveSpot(sprintf('lot%02d', $i), [
                'name' => sprintf('Lot %02d', $i),
                'point' => ['lat' => FixtureRegion::north(FixtureRegion::EMPTY['lat'], 30.0 * $i), 'lng' => FixtureRegion::EMPTY['lng']],
            ]);
        }

        $answer = $this->day();

        self::assertSame(46, $answer['spots_considered']);
        self::assertSame([], $answer['suggestions'], 'nobody is near these lots');
        self::assertCount(3, $this->legs->calls);
        self::assertCount(92, $this->legs->calls[0]['pairs']);
        self::assertCount(2000, $this->legs->calls[1]['pairs']);
        self::assertCount(70, $this->legs->calls[2]['pairs']);
        self::assertSame(['fetch' => false], $this->legs->calls[2]['options']);
        $seen = [];
        foreach (array_merge($this->legs->calls[1]['pairs'], $this->legs->calls[2]['pairs']) as [$from, $to]) {
            self::assertNotSame($from, $to);
            $seen[$from . '>' . $to] = true;
        }
        self::assertCount(46 * 45, $seen, 'every ordered pair of different spots, once');
    }

    // ------------------------------------------------------------------------------------ the cache

    public function testAnAnswerIsKeptForTenMinutesUnderAKeyOfEveryInput(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();

        $first = $this->day();
        self::assertSame(1, $this->service->dayRuns);
        self::assertCount(1, $this->cache->values);
        $key = (string) array_key_first($this->cache->values);
        self::assertMatchesRegularExpression('/^tp:suggest:' . preg_quote(self::ORG, '/') . ':[0-9a-f]{40}$/', $key);
        self::assertSame(600 + 93600, $this->cache->ttls[$key]);

        // The same question again: the kept answer, to the last digit, and no run of the model.
        $this->clock->advance(599);
        self::assertSame($first, $this->day());
        self::assertSame(1, $this->service->dayRuns);

        // Ten minutes on it is computed anew.
        $this->clock->advance(2);
        self::assertSame($first, $this->day());
        self::assertSame(2, $this->service->dayRuns);

        // Any other input is another question: options, the date, a leg, a spot's terms.
        $this->day(['options' => ['limit' => 2]]);
        $this->day(['date' => '2026-10-09']);
        self::assertSame(4, $this->service->dayRuns);
        $this->legs->corrections['office>taproom'] = ['minutes' => 12, 'toll' => null];
        $this->day();
        self::assertSame(5, $this->service->dayRuns);
        $this->table->rows[self::OFFICE]['fee_flat_cents'] = 1000;
        $this->day();
        self::assertSame(6, $this->service->dayRuns);
        self::assertCount(5, $this->cache->values);

        // A week is kept the same way, under its own key.
        $week = $this->week();
        self::assertSame($week, $this->week());
        self::assertSame(1, $this->service->weekRuns);
    }

    public function testARefinedAnswerIsKeptForTheQuestionThatFindsItsLegsCached(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs(['base>office', 'office>base', 'base>taproom', 'taproom>base']);
        $this->legs->upstream['office>taproom'] = ['distance_m' => 7805.0, 'duration_s' => 600.0];

        $first = $this->day();
        self::assertSame(2, $this->service->dayRuns);
        self::assertCount(2, $this->cache->values, 'kept under the legs it started from and under the legs it ended with');

        // The provider now has the leg in its cache: the next question starts with it and is answered at once.
        $this->legs->calls = [];
        self::assertSame($first, $this->day());
        self::assertSame(2, $this->service->dayRuns);
        self::assertCount(2, $this->legs->calls);
    }

    public function testAnotherOrganizationNeverSharesAnAnswer(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();
        $this->day();

        $other = $this->service->day(self::OTHER_ORG, ['organization_id' => self::OTHER_ORG] + SpotServiceTest::truck(), self::A(), ['date' => '2026-10-08']);

        self::assertSame([], $other['suggestions'], 'the other organization has no spot of its own');
        self::assertSame(0, $other['spots_considered']);
        foreach (array_keys($this->cache->values) as $key) {
            self::assertTrue(str_starts_with($key, 'tp:suggest:' . self::ORG . ':') || str_starts_with($key, 'tp:suggest:' . self::OTHER_ORG . ':'));
        }
        self::assertCount(2, $this->cache->values);
    }

    // ------------------------------------------------------------------------------------ spots

    public function testWithoutASavedSpotTheAnswerIsEmptyAndNothingIsAsked(): void
    {
        $day = $this->day();
        self::assertSame([], $day['suggestions']);
        self::assertSame(0, $day['spots_considered']);
        self::assertSame(0, $day['fallback_pairs']);
        self::assertSame('2026-10-08', $day['context']['date']);
        self::assertSame(4.195, $day['context']['fuel_price_per_gal']);

        $week = $this->week();
        self::assertSame('2026-10-05', $week['week']['week_start']);
        self::assertCount(7, $week['week']['days']);
        foreach ($week['week']['days'] as $d => $entry) {
            self::assertSame(['date' => Estimator::addDays('2026-10-05', $d), 'suggestion' => null], $entry);
        }
        self::assertSame(['value' => 0.0, 'low' => 0.0, 'high' => 0.0, 'confidence' => 'fixed'], $week['week']['total_take_home']);
        self::assertSame([], $week['week']['visits']);
        self::assertSame(0, $week['spots_considered']);
        self::assertSame([], $this->legs->calls, 'no spot, no leg');
    }

    public function testASubsetOfSpotsCanBeAskedFor(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();

        $answer = $this->day(['spot_ids' => [self::TAPROOM, self::TAPROOM]]);

        self::assertSame(1, $answer['spots_considered'], 'a spot named twice counts once');
        foreach ($answer['suggestions'] as $suggestion) {
            self::assertSame([self::TAPROOM], array_column($suggestion['stops'], 'spot_id'));
        }
        self::assertSame([['base', self::TAPROOM], [self::TAPROOM, 'base']], $this->legs->calls[0]['pairs']);
        self::assertCount(1, $this->legs->calls, 'one spot has no drive between spots');
    }

    public function testArchivedSpotsAreLeftOutAndCannotBeAskedFor(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();
        $this->spotService->archive(self::ORG, self::OFFICE);

        $answer = $this->day();
        self::assertSame(1, $answer['spots_considered']);
        self::assertNotSame([], $answer['suggestions']);
        foreach ($answer['suggestions'] as $suggestion) {
            self::assertSame([self::TAPROOM], array_column($suggestion['stops'], 'spot_id'));
        }

        self::assertInvalid('spot_ids[1] was not found', 'spot_ids[1]', 'V11', fn () => $this->day(['spot_ids' => [self::TAPROOM, self::OFFICE]]));
    }

    public function testSpotsWhoseVectorsAreNotCurrentAreRecomputedFirst(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();
        $before = $this->day()['suggestions'][1]['orders']['value'];
        $captures = $this->region->count('capture');

        // A new dataset version with half the office jobs becomes the active one.
        $this->region->switchTo('mini-20261101-bbbb2222', 0.5);
        $answer = $this->day();

        self::assertSame($captures + 2, $this->region->count('capture'), 'both spots were stale');
        self::assertSame('mini-20261101-bbbb2222', $this->table->rows[self::OFFICE]['vec_dataset']);
        self::assertSame('mini-20261101-bbbb2222', $this->service->spotsOfRuns[1][0]['vectors']['dataset_version']);
        $officeOnly = null;
        foreach ($answer['suggestions'] as $suggestion) {
            if (array_column($suggestion['stops'], 'spot_id') === [self::OFFICE]) {
                $officeOnly = $suggestion['orders']['value'];
            }
        }
        self::assertNotNull($officeOnly);
        self::assertLessThan($before, $officeOnly);
        foreach ($answer['suggestions'] as $suggestion) {
            self::assertNotContains('stale_vectors', array_column($suggestion['result']['warnings'], 'code'));
        }
    }

    public function testRegionDataOfAnotherBuildAnswersAConflict(): void
    {
        $this->saveExampleSpots();
        $this->region->switchTo('mini-20261101-bbbb2222', 1.0);
        $this->region->usable = false;

        $this->expectException(TpConflict::class);
        $this->expectExceptionMessage(FixtureRegion::MISMATCH);
        $this->day();
    }

    public function testNoMoreSpotsAreConsideredThanTheLimit(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();
        $config = TpConfig::all();
        $config['limits']['max_suggest_spots'] = 1;
        TpConfig::replace($config);

        // The first spot of the spot list (by name): the office park.
        $answer = $this->day();
        self::assertSame(1, $answer['spots_considered']);
        self::assertSame(self::OFFICE, $this->service->spotsOfRuns[0][0]['spot_id']);

        self::assertInvalid('spot_ids must be a list of 1 to 1 items', 'spot_ids', 'V8', fn () => $this->day(['spot_ids' => [self::OFFICE, self::TAPROOM]]));
    }

    public function testTheSpotsReachTheModelAsSpotInputs(): void
    {
        $this->saveExampleSpots();
        $this->table->rows[self::TAPROOM]['visibility'] = 'prominent';
        $this->knowBlueprintLegs();

        $this->day();

        $inputs = [];
        foreach ($this->service->spotsOfRuns[0] as $input) {
            self::assertSame(['spot_id', 'point', 'terms', 'vectors'], array_keys($input));
            $inputs[$input['spot_id']] = $input;
        }
        self::assertSame(FixtureRegion::OFFICE, $inputs[self::OFFICE]['point']);
        self::assertSame(self::OFFICE, $inputs[self::OFFICE]['terms']['spot_id']);
        self::assertNull($inputs[self::OFFICE]['terms']['host']);
        // The vectors are those of the spot's own visibility.
        self::assertSame('normal', $inputs[self::OFFICE]['vectors']['visibility']);
        self::assertSame('prominent', $inputs[self::TAPROOM]['terms']['visibility']);
        self::assertSame('prominent', $inputs[self::TAPROOM]['vectors']['visibility']);
        self::assertTrue(Estimator::vectorsMatch(self::A(), $inputs[self::TAPROOM]['terms'], $inputs[self::TAPROOM]['vectors']));
        self::assertSame(120.0, $inputs[self::TAPROOM]['terms']['host']['size']);
    }

    // ------------------------------------------------------------------------------------ day contexts, calibration

    public function testTheDayIsBuiltWithItsTreatAsAndTheNextDayWithout(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();

        $answer = $this->day(['treat_as' => 'sat']);

        self::assertSame([['2026-10-08', 2, ['2026-10-08' => 'sat']]], $this->contexts->asked);
        self::assertSame('sat', $answer['context']['treat_as']);
        self::assertSame(5, $answer['context']['eff_dow']);
        self::assertSame('sat', $this->service->contextsOfRuns[0][0]['treat_as']);
        self::assertNull($this->service->contextsOfRuns[0][1]['treat_as']);
        self::assertSame('2026-10-09', $this->service->contextsOfRuns[0][1]['date']);
        // On a day that is treated as a Saturday the taproom alone is the best plan, as on a real one.
        self::assertSame([['spot_id' => self::TAPROOM, 'open_minute' => 1020, 'close_minute' => 1200]], $answer['suggestions'][0]['stops']);
    }

    public function testAWeekTakesItsTreatAsByDate(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();

        $this->week(['treat_as' => ['2026-10-07' => 'holiday', '2026-10-10' => null, '2026-10-12' => 'sat', '2026-09-30' => 'nonsense']]);

        // Only the seven dates of the week are read: the Monday after and any other key are not.
        self::assertSame([['2026-10-05', 8, ['2026-10-07' => 'holiday']]], $this->contexts->asked);
        $contexts = $this->service->contextsOfRuns[0];
        self::assertCount(8, $contexts);
        self::assertSame('holiday', $contexts[2]['treat_as']);
        self::assertNull($contexts[7]['treat_as']);
        self::assertSame('2026-10-12', $contexts[7]['date']);
    }

    public function testTheCalibrationIsAsOfTodayWhereTheTruckIs(): void
    {
        $this->saveExampleSpots();
        // 03:30 UTC on Tuesday is still Monday evening in New York.
        $this->clock->set('2026-10-06 03:30:00');

        $this->day();
        self::assertSame(['2026-10-05'], $this->calibration->asOf);

        // A zone the server does not know falls back to the default zone instead of failing.
        $this->day([], ['timezone' => 'Mars/Olympus_Mons'] + SpotServiceTest::truck());
        self::assertSame(['2026-10-05', '2026-10-05'], $this->calibration->asOf);
        self::assertSame('2026-10-05', $this->service->calibrationsOfRuns[0]['as_of']);
    }

    // ------------------------------------------------------------------------------------ validation

    public function testTheDayBodyIsValidatedInTheOrderOfTheFieldTable(): void
    {
        $this->saveExampleSpots();
        $one = fn (array $body) => fn () => $this->service->day(self::ORG, SpotServiceTest::truck(), self::A(), $body);

        self::assertInvalid('date is required', 'date', 'V1', $one([]));
        self::assertInvalid('date is required', 'date', 'V1', $one(['date' => null, 'treat_as' => 'nonsense']));
        self::assertInvalid('date must be a date in the form YYYY-MM-DD', 'date', 'V7', $one(['date' => '2026-02-30']));
        self::assertInvalid('date must be a date in the form YYYY-MM-DD', 'date', 'V7', $one(['date' => 20261008]));
        // The day after must be a date the model knows as well.
        self::assertInvalid('date must be a date in the form YYYY-MM-DD', 'date', 'V7', $one(['date' => '2199-12-31']));
        self::assertInvalid(
            'treat_as must be one of: normal, holiday, mon, tue, wed, thu, fri, sat, sun',
            'treat_as',
            'V4',
            $one(['date' => '2026-10-08', 'treat_as' => 'saturday', 'spot_ids' => []])
        );
        self::assertInvalid('spot_ids must be a list of 1 to 200 items', 'spot_ids', 'V8', $one(['date' => '2026-10-08', 'spot_ids' => [], 'options' => 3]));
        self::assertInvalid('spot_ids must be a list of 1 to 200 items', 'spot_ids', 'V8', $one(['date' => '2026-10-08', 'spot_ids' => self::OFFICE]));
        self::assertInvalid('spot_ids must be a list of 1 to 200 items', 'spot_ids', 'V8', $one(['date' => '2026-10-08', 'spot_ids' => array_fill(0, 201, self::OFFICE)]));
        self::assertInvalid('spot_ids[0] was not found', 'spot_ids[0]', 'V11', $one(['date' => '2026-10-08', 'spot_ids' => ['nowhere'], 'options' => 3]));
        self::assertInvalid('spot_ids[1] was not found', 'spot_ids[1]', 'V11', $one(['date' => '2026-10-08', 'spot_ids' => [self::OFFICE, 7]]));
        self::assertInvalid('spot_ids[0] was not found', 'spot_ids[0]', 'V11', $one(['date' => '2026-10-08', 'spot_ids' => [str_repeat('a', 37)]]));
        self::assertInvalid('options must be an object', 'options', 'V9', $one(['date' => '2026-10-08', 'options' => [1, 2]]));
    }

    public function testEachOptionHasItsRange(): void
    {
        $this->saveExampleSpots();
        $with = fn (array $options) => fn () => $this->day(['options' => $options]);

        self::assertInvalid('options.service_minutes must be a whole number between 60 and 480', 'options.service_minutes', 'V3', $with(['service_minutes' => 30]));
        self::assertInvalid('options.service_minutes must be a whole number between 60 and 480', 'options.service_minutes', 'V3', $with(['service_minutes' => 540]));
        self::assertInvalid('options.service_minutes must be a whole number between 60 and 480', 'options.service_minutes', 'V3', $with(['service_minutes' => '120']));
        self::assertInvalid('options.service_minutes must be a multiple of 60', 'options.service_minutes', null, $with(['service_minutes' => 150]));
        self::assertInvalid('options.max_stops_per_day must be a whole number between 1 and 3', 'options.max_stops_per_day', 'V3', $with(['max_stops_per_day' => 4]));
        self::assertInvalid('options.max_stops_per_day must be a whole number between 1 and 3', 'options.max_stops_per_day', 'V3', $with(['max_stops_per_day' => 0]));
        self::assertInvalid('options.max_days_per_week must be a whole number between 1 and 7', 'options.max_days_per_week', 'V3', $with(['max_days_per_week' => 8]));
        self::assertInvalid('options.max_visits_per_spot_per_week must be a whole number between 1 and 7', 'options.max_visits_per_spot_per_week', 'V3', $with(['max_visits_per_spot_per_week' => 0]));
        self::assertInvalid('options.limit must be a whole number between 1 and 10', 'options.limit', 'V3', $with(['limit' => 11]));
        self::assertInvalid('options.limit must be a whole number between 1 and 10', 'options.limit', 'V3', $with(['limit' => 1.5]));
    }

    public function testOptionsReachTheModelAndAnOptionThatIsNotSentTakesItsDefault(): void
    {
        $this->saveExampleSpots();
        $this->knowBlueprintLegs();

        $answer = $this->day(['options' => ['limit' => 1, 'max_stops_per_day' => 1, 'service_minutes' => 120, 'max_days_per_week' => null]]);
        self::assertSame(['service_minutes' => 120, 'max_stops_per_day' => 1, 'limit' => 1], $this->service->optionsOfRuns[0]);
        self::assertCount(1, $answer['suggestions']);
        self::assertCount(1, $answer['suggestions'][0]['stops']);
        self::assertSame(120, $answer['suggestions'][0]['stops'][0]['close_minute'] - $answer['suggestions'][0]['stops'][0]['open_minute']);

        // No option, an empty object and nulls only are the same question.
        $this->day();
        $this->day(['options' => []]);
        $this->day(['options' => ['limit' => null]]);
        self::assertSame([null], [$this->service->optionsOfRuns[1]]);
        self::assertSame(2, $this->service->dayRuns, 'the second and third were answered from the cache');
    }

    public function testTheWeekBodyIsValidated(): void
    {
        $this->saveExampleSpots();
        $one = fn (array $body) => fn () => $this->service->week(self::ORG, SpotServiceTest::truck(), self::A(), $body);

        self::assertInvalid('week_start is required', 'week_start', 'V1', $one(['date' => '2026-10-05']));
        self::assertInvalid('week_start must be a date in the form YYYY-MM-DD', 'week_start', 'V7', $one(['week_start' => '05.10.2026']));
        self::assertInvalid('week_start must be a Monday', 'week_start', null, $one(['week_start' => '2026-10-06']));
        self::assertInvalid('week_start must be a Monday', 'week_start', null, $one(['week_start' => '2026-10-11', 'treat_as' => 'sat']));
        // The week needs the context of the Monday after: a week that would reach into the year 2200 has none.
        self::assertInvalid('week_start must be a date in the form YYYY-MM-DD', 'week_start', 'V7', $one(['week_start' => '2199-12-30']));
        self::assertInvalid('treat_as must be an object', 'treat_as', 'V9', $one(['week_start' => '2026-10-05', 'treat_as' => 'sat']));
        self::assertInvalid('treat_as must be an object', 'treat_as', 'V9', $one(['week_start' => '2026-10-05', 'treat_as' => ['sat']]));
        self::assertInvalid(
            'treat_as.2026-10-07 must be one of: normal, holiday, mon, tue, wed, thu, fri, sat, sun',
            'treat_as.2026-10-07',
            'V4',
            $one(['week_start' => '2026-10-05', 'treat_as' => ['2026-10-07' => 'weekend']])
        );
        self::assertInvalid('spot_ids[0] was not found', 'spot_ids[0]', 'V11', $one(['week_start' => '2026-10-05', 'spot_ids' => ['nowhere']]));
        self::assertInvalid('options.limit must be a whole number between 1 and 10', 'options.limit', 'V3', $one(['week_start' => '2026-10-05', 'options' => ['limit' => 0]]));
    }

    public function testASpotOfAnotherOrganizationIsNotFound(): void
    {
        $this->saveExampleSpots();
        $this->saveSpot('theirs', ['name' => 'Their lot', 'point' => FixtureRegion::OFFICE], self::OTHER_ORG);

        self::assertInvalid('spot_ids[0] was not found', 'spot_ids[0]', 'V11', fn () => $this->day(['spot_ids' => ['theirs']]));
        // And it never joins the spots of this organization by itself.
        self::assertSame(2, $this->day()['spots_considered']);
    }
}

/**
 * The service under test with its two model calls counted and their arguments kept.
 */
final class CountingSuggestions extends SuggestionService
{
    public int $dayRuns = 0;
    public int $weekRuns = 0;

    /** @var list<array<string, array<string, mixed>>> the leg map of each run */
    public array $legsOfRuns = [];

    /** @var list<list<array<string, mixed>>> the SpotInput list of each run */
    public array $spotsOfRuns = [];

    /** @var list<list<array<string, mixed>>> the day contexts of each run */
    public array $contextsOfRuns = [];

    /** @var list<array<string, mixed>|null> */
    public array $calibrationsOfRuns = [];

    /** @var list<array<string, int>|null> */
    public array $optionsOfRuns = [];

    /** @var list<array<int|string, mixed>> what the model answered */
    public array $resultsOfRuns = [];

    protected function suggestDay(array $A, array $profile, array $ctx, ?array $ctxNext, array $spots, array $legs, ?array $cal, ?array $options): array
    {
        $this->dayRuns++;
        $this->keep($legs, $spots, [$ctx, $ctxNext], $cal, $options);
        return $this->resultsOfRuns[] = parent::suggestDay($A, $profile, $ctx, $ctxNext, $spots, $legs, $cal, $options);
    }

    protected function suggestWeek(array $A, array $profile, string $weekStart, array $contexts, array $spots, array $legs, ?array $cal, ?array $options): array
    {
        $this->weekRuns++;
        $this->keep($legs, $spots, $contexts, $cal, $options);
        return $this->resultsOfRuns[] = parent::suggestWeek($A, $profile, $weekStart, $contexts, $spots, $legs, $cal, $options);
    }

    /**
     * @param array<string, array<string, mixed>> $legs
     * @param list<array<string, mixed>> $spots
     * @param list<array<string, mixed>|null> $contexts
     * @param array<string, mixed>|null $cal
     * @param array<string, int>|null $options
     */
    private function keep(array $legs, array $spots, array $contexts, ?array $cal, ?array $options): void
    {
        $this->legsOfRuns[] = $legs;
        $this->spotsOfRuns[] = $spots;
        $this->contextsOfRuns[] = $contexts;
        $this->calibrationsOfRuns[] = $cal;
        $this->optionsOfRuns[] = $options;
    }
}

/**
 * A leg provider that answers from what the test told it, and records every question.
 *
 *   $cached       legs it has: served whatever the options say
 *   $upstream     legs Google would give: served only when fetching is asked for, and cached from then on
 *   $corrections  the owner's corrections, laid over whatever leg is served
 *   $reason       why a pair it cannot fetch is a straight line ("no_key", "quota", ...)
 *
 * A pair it does not have is a labelled straight line: reason "cache_only" when it was told not to fetch.
 * Two points with one leg key are one place. With `$delegate` set it only records and passes the question on.
 */
final class ScriptedLegs implements LegProvider
{
    /** @var array<string, array{distance_m: float, duration_s: float}> by "<from_id>><to_id>" */
    public array $cached = [];

    /** @var array<string, array{distance_m: float, duration_s: float}> */
    public array $upstream = [];

    /** @var array<string, array{minutes: ?int, toll: ?float}> */
    public array $corrections = [];

    public string $reason = 'no_key';
    public ?LegProvider $delegate = null;

    /** @var list<array{org: string, truck: string, points: list<string>, pairs: list<array{0: string, 1: string}>, options: array<string, bool>}> */
    public array $calls = [];

    public function legs(string $orgId, array $truck, array $points, array $pairs, array $options = []): array
    {
        $this->calls[] = [
            'org' => $orgId,
            'truck' => (string) $truck['id'],
            'points' => array_column($points, 'id'),
            'pairs' => $pairs,
            'options' => $options,
        ];
        if ($this->delegate !== null) {
            return $this->delegate->legs($orgId, $truck, $points, $pairs, $options);
        }
        $byId = [];
        foreach ($points as $point) {
            $byId[$point['id']] = $point;
        }
        $fetch = ($options['fetch'] ?? true) === true;
        $legs = [];
        foreach ($pairs as [$from, $to]) {
            if (!isset($byId[$from], $byId[$to])) {
                throw new \LogicException('a leg names a point that was not given');
            }
            $key = $from . '>' . $to;
            if ($fetch && isset($this->upstream[$key])) {
                $this->cached[$key] = $this->upstream[$key];
            }
            if (LegKey::of($byId[$from]['lat'], $byId[$from]['lng']) === LegKey::of($byId[$to]['lat'], $byId[$to]['lng'])) {
                $leg = StraightLineLegs::samePoint($from, $to);
            } elseif (isset($this->cached[$key])) {
                $leg = self::routed($from, $to, $this->cached[$key]);
            } else {
                $leg = StraightLineLegs::straightLine($from, $to, $byId[$from], $byId[$to], $fetch ? $this->reason : 'cache_only');
            }
            if (isset($this->corrections[$key])) {
                $correction = $this->corrections[$key];
                $leg['override'] = ['id' => 'c-' . $key, 'minutes' => $correction['minutes'], 'toll' => $correction['toll'], 'note' => ''];
                $leg['leg_input']['override_minutes'] = $correction['minutes'];
                if ($correction['toll'] !== null) {
                    $leg['toll_source'] = 'owner';
                    $leg['leg_input']['toll'] = $correction['toll'];
                }
            }
            $legs[] = $leg;
        }
        return $legs;
    }

    public function status(): array
    {
        return ['state' => 'ok'];
    }

    public function movePoint(string $orgId, array $truck, array $oldPoint, array $newPoint): void
    {
    }

    /**
     * @param array{distance_m: float, duration_s: float} $leg
     * @return array<string, mixed> DriveLeg
     */
    private static function routed(string $from, string $to, array $leg): array
    {
        return [
            'from_id' => $from,
            'to_id' => $to,
            'source' => 'google_routes',
            'fetched_on' => '2026-10-05',
            'age_days' => 0,
            'distance_m' => $leg['distance_m'],
            'duration_s' => $leg['duration_s'],
            'toll_state' => 'none',
            'google_toll' => null,
            'toll_source' => 'none',
            'override' => null,
            'fallback_reason' => null,
            'leg_input' => [
                'source' => 'google',
                'distance_m' => $leg['distance_m'],
                'duration_s' => $leg['duration_s'],
                'override_minutes' => null,
                'toll' => 0.0,
            ],
        ];
    }
}
