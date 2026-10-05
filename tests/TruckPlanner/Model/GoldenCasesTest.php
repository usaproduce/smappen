<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Model;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\ModelError;
use App\TruckPlanner\Model\Seeds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every golden case of tests/fixtures/truck-planner/golden_cases.json through Estimator::dispatch
 * (02_MODEL.md section 8). The file is written by the Python reference and is never edited by hand; a port
 * is never "fixed" by changing it.
 *
 * Each case runs under three process time zones and two float-to-string precisions, set inside the test,
 * because the model must not depend on either.
 */
final class GoldenCasesTest extends TestCase
{
    private const FIXTURE = '/tests/fixtures/truck-planner/golden_cases.json';

    /** UTC, UTC-8/-7 with daylight saving, UTC+14: a civil date differs between them for most of a day. */
    private const ZONES = ['UTC', 'America/Los_Angeles', 'Pacific/Kiritimati'];

    private const PRECISIONS = ['14', '17'];

    private const MAX_REPORTED = 8;

    /**
     * Functions built from +, -, *, /, sqrt and floor only. IEEE-754 pins those operations down, so for
     * these the port must return the very bits of the reference on every machine: a different order of
     * additions or multiplications shows here, although it would pass the tolerance. The other functions
     * go through exp, ln, sin, cos or asin, which may differ in the last place between math libraries.
     */
    private const EXACT_FUNCTIONS = [
        'round_half_away', 'qkey', 'seed', 'est_fixed', 'est_levels', 'est_sum',
        'days_from_civil', 'civil_from_days', 'parse_date', 'day_of_week', 'federal_holidays', 'holiday_on',
        'day_context', 'typical_context', 'hour_weights', 'expand_curves',
        'host_exclusion', 'host_capture', 'weather_multiplier', 'calibration_factor', 'hourly_orders',
        'week_strip', 'best_windows', 'evidence_from',
        'stop_money_at', 'stop_money', 'unit_margins', 'break_even_orders', 'day_costs',
        'traffic_factor', 'leg_minutes', 'catering_money', 'accuracy_report',
        'strip_from_rows', 'scout_rank', 'map_weight_rows', 'cell_scores', 'score_byte',
    ];

    /** Fields the document types as `int` (02_MODEL.md section 3 and the records section 4 returns). */
    private const INT_FIELDS = [
        'hour', 'how', 'day_index', 'open_minute', 'close_minute', 'previous_close_minute', 'minutes', 'capped_hours',
        'points_used', 'traffic_lookup_minute', 'traffic_dow', 'traffic_hour', 'depart_minute', 'minute', 'stop_index',
        'arrive', 'setup_start', 'open', 'effective_open', 'close', 'leave', 'gap_before_minutes', 'late_minutes',
        'start_prep', 'leave_base', 'back_at_base', 'done', 'day_minutes', 'paid_minutes', 'unpaid_gap_minutes',
        'drive_minutes', 'service_minutes', 'generator_minutes', 'seeds_revision', 'truck_n', 'resid_n', 'n',
        'n_total', 'n_scored', 'n_sold_out', 'position', 'leaves_visited', 'dow', 'eff_dow', 'start', 'length', 'hours',
    ];

    /** Functions whose whole result is made of integers. */
    private const INT_FUNCTIONS = ['qkey', 'score_byte', 'days_from_civil', 'civil_from_days', 'parse_date', 'day_of_week'];

    /** @var array{tolerance: array{rel: float, abs: float}, header: array<string, mixed>, anchors: list<array<string, mixed>>, cases: array<string, array{function: string, args: array<string, mixed>, expected: mixed}>}|null */
    private static ?array $golden = null;

    private string $zoneBefore = 'UTC';
    private string $precisionBefore = '14';

    protected function setUp(): void
    {
        $this->zoneBefore = date_default_timezone_get();
        $this->precisionBefore = (string) ini_get('precision');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zoneBefore);
        ini_set('precision', $this->precisionBefore);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function cases(): iterable
    {
        foreach (self::ZONES as $zone) {
            foreach (self::PRECISIONS as $precision) {
                foreach (array_keys(self::golden()['cases']) as $id) {
                    yield $id . ' ' . $zone . ' precision=' . $precision => [(string) $id, $zone, $precision];
                }
            }
        }
    }

    #[DataProvider('cases')]
    public function testGoldenCase(string $id, string $zone, string $precision): void
    {
        date_default_timezone_set($zone);
        ini_set('precision', $precision);
        self::assertSame($zone, date_default_timezone_get());
        self::assertSame($precision, ini_get('precision'));

        $case = self::golden()['cases'][$id];
        $differences = self::runCase($case);

        self::assertSame(
            [],
            $differences,
            sprintf("%s %s under %s, precision %s:\n  %s", $id, $case['function'], $zone, $precision, implode("\n  ", $differences))
        );
    }

    /** The settings the cases run under really change how this process sees dates and prints floats. */
    public function testTheConfigurationsDifferWhereTheModelMustNot(): void
    {
        $seen = [];
        foreach (self::ZONES as $zone) {
            date_default_timezone_set($zone);
            $seen[] = date('Y-m-d H', 86400 * 20734 + 3600);      // 2026-10-08 01:00 UTC
        }
        self::assertCount(3, array_unique($seen), 'the three zones must give three different local hours');
        self::assertSame(['2026-10-08 01', '2026-10-07 18', '2026-10-08 15'], $seen);

        ini_set('precision', '14');
        $short = (string) (0.1 + 0.2);
        ini_set('precision', '17');
        $long = (string) (0.1 + 0.2);
        self::assertSame('0.3', $short);
        self::assertSame('0.30000000000000004', $long);
    }

    public function testFileBelongsToThisModelVersionAndSeeds(): void
    {
        $header = self::golden()['header'];
        self::assertSame(Estimator::MODEL_VERSION, $header['model_version']);
        self::assertSame(Seeds::revision(), $header['seeds_revision']);
        self::assertSame(1e-9, self::golden()['tolerance']['rel']);
        self::assertSame(1e-9, self::golden()['tolerance']['abs']);
        self::assertGreaterThan(0, count(self::golden()['cases']));
    }

    /** Every function of the document has a golden case, except the two that are covered through others. */
    public function testEveryFunctionHasACase(): void
    {
        $used = [];
        foreach (self::golden()['cases'] as $case) {
            $used[$case['function']] = true;
        }
        $catalogue = Estimator::functions();
        foreach (array_keys($used) as $function) {
            self::assertContains($function, $catalogue, 'the golden file calls a function the port does not have');
        }
        $missing = array_values(array_diff($catalogue, array_keys($used)));
        sort($missing);
        self::assertSame(['evaluate', 'make_context'], $missing);
    }

    /** The sanity anchors of 02_MODEL.md 8.3: ranges, asserted in addition to the exact golden values. */
    public function testAnchors(): void
    {
        $anchors = self::golden()['anchors'];
        self::assertNotSame([], $anchors);
        $ids = [];
        foreach ($anchors as $anchor) {
            $ids[] = $anchor['id'];
            $value = self::at(self::resultOf($anchor['case']), $anchor['path']);
            self::assertIsFloat($value, $anchor['id']);
            if (array_key_exists('greater_than_case', $anchor)) {
                $other = self::at(self::resultOf($anchor['greater_than_case']), $anchor['path']);
                self::assertGreaterThan($other, $value, $anchor['id']);
            } else {
                self::assertGreaterThanOrEqual($anchor['min'], $value, $anchor['id']);
                self::assertLessThanOrEqual($anchor['max'], $value, $anchor['id']);
            }
        }
        self::assertSame(['A1', 'A2', 'A2-friday'], $ids);
    }

    /** See EXACT_FUNCTIONS: no tolerance where every operation is exactly rounded. */
    public function testFunctionsOfExactlyRoundedOperationsAreBitIdentical(): void
    {
        $checked = [];
        $problems = [];
        foreach (self::golden()['cases'] as $id => $case) {
            if (!in_array($case['function'], self::EXACT_FUNCTIONS, true) || self::expectedError($case['expected']) !== null) {
                continue;
            }
            $checked[$case['function']] = true;
            $out = [];
            self::differences($case['expected'], Estimator::dispatch($case['function'], $case['args']), '', $out, true);
            foreach ($out as $difference) {
                $problems[] = $id . ' ' . $case['function'] . $difference;
            }
        }
        self::assertSame([], array_slice($problems, 0, self::MAX_REPORTED), count($problems) . ' cases are not bit-identical');
        self::assertCount(count(self::EXACT_FUNCTIONS), $checked, 'every listed function has a golden case');
    }

    /**
     * Where the reference returns a real (spelled 66.0 in the golden file) the port returns a float, and a
     * field the document types as int (a minute, a count, an index) comes back as an int. Runners compare
     * numbers as doubles, so 02_MODEL.md 8.1 does not require this; typed PHP callers and assertSame rely on
     * it. (An integer the reference merely hands through from an input, such as an override written as 1
     * where the seed is a real, is a real: the port may return 1.0 for it.)
     */
    public function testRealsComeBackAsFloatsAndIntFieldsAsInts(): void
    {
        $problems = [];
        $reals = 0;
        $ints = 0;
        foreach (self::golden()['cases'] as $id => $case) {
            if (self::expectedError($case['expected']) !== null) {
                continue;
            }
            $actual = Estimator::dispatch($case['function'], $case['args']);
            $allInts = in_array($case['function'], self::INT_FUNCTIONS, true);
            self::types($case['expected'], $actual, (string) $id, '', $allInts, $problems, $reals, $ints);
        }
        self::assertGreaterThan(0, $reals);
        self::assertGreaterThan(0, $ints);
        self::assertSame([], array_slice($problems, 0, self::MAX_REPORTED), count($problems) . ' numbers of the wrong type');
    }

    /** A second run of a case in the same process gives the same result: nothing is kept between calls. */
    public function testASecondRunIsIdentical(): void
    {
        foreach (self::golden()['cases'] as $id => $case) {
            if (self::expectedError($case['expected']) !== null) {
                continue;
            }
            $first = Estimator::dispatch($case['function'], $case['args']);
            $second = Estimator::dispatch($case['function'], $case['args']);
            self::assertSame($first, $second, (string) $id);
        }
    }

    // ---------------------------------------------------------------------------------------------------

    /**
     * @param array{function: string, args: array<string, mixed>, expected: mixed} $case
     * @return list<string> what differs; empty when the case passes
     */
    private static function runCase(array $case): array
    {
        $wantError = self::expectedError($case['expected']);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);      // a warning or notice is a failure
        });
        try {
            $actual = Estimator::dispatch($case['function'], $case['args']);
        } catch (ModelError $error) {
            if ($wantError === null) {
                return ['raised the model error ' . $error->errorCode() . ' ' . json_encode($error->details())];
            }
            return $error->errorCode() === $wantError ? [] : ['raised ' . $error->errorCode() . ', expected ' . $wantError];
        } catch (\Throwable $error) {
            return [sprintf('threw %s: %s (%s:%d)', $error::class, $error->getMessage(), basename($error->getFile()), $error->getLine())];
        } finally {
            restore_error_handler();
        }
        if ($wantError !== null) {
            return ['returned a result, expected the model error ' . $wantError];
        }
        $out = [];
        self::differences($case['expected'], $actual, '', $out);
        return $out;
    }

    /** The code of a case that must fail (expected is { "error": "<code>" }), or null. */
    private static function expectedError(mixed $expected): ?string
    {
        if ($expected instanceof \stdClass && array_keys(get_object_vars($expected)) === ['error']) {
            return (string) $expected->error;
        }
        return null;
    }

    /**
     * The comparison of 02_MODEL.md 1.4 and 8.1. $expected is the decoded JSON with objects kept as objects,
     * so an object and an array are never confused; $actual is what the port returned (PHP arrays).
     *
     * With $exact, two numbers match only when they are the same double.
     *
     * @param list<string> $out
     */
    private static function differences(mixed $expected, mixed $actual, string $path, array &$out, bool $exact = false): void
    {
        if (count($out) >= self::MAX_REPORTED) {
            return;
        }
        if ($expected instanceof \stdClass) {
            if (!is_array($actual)) {
                $out[] = sprintf('%s: expected an object, got %s', $path, get_debug_type($actual));
                return;
            }
            $members = get_object_vars($expected);
            $want = array_map('strval', array_keys($members));
            $have = array_map('strval', array_keys($actual));
            sort($want, SORT_STRING);
            sort($have, SORT_STRING);
            if ($want !== $have) {
                $out[] = sprintf('%s: keys %s != %s', $path, json_encode($want), json_encode($have));
                return;
            }
            foreach ($members as $key => $value) {
                self::differences($value, $actual[$key], $path . '.' . $key, $out, $exact);
            }
            return;
        }
        if (is_array($expected)) {
            if (!is_array($actual) || !array_is_list($actual)) {
                $out[] = sprintf('%s: expected a list, got %s', $path, is_array($actual) ? 'a map' : get_debug_type($actual));
                return;
            }
            if (count($expected) !== count($actual)) {
                $out[] = sprintf('%s: %d items != %d items', $path, count($expected), count($actual));
                return;
            }
            foreach ($expected as $i => $value) {
                self::differences($value, $actual[$i], $path . '.' . $i, $out, $exact);
            }
            return;
        }
        if (is_bool($expected) || $expected === null || is_string($expected)) {
            if ($expected !== $actual) {
                $out[] = sprintf('%s: %s != %s', $path, var_export($expected, true), var_export($actual, true));
            }
            return;
        }
        if (!is_int($actual) && !is_float($actual)) {
            $out[] = sprintf('%s: expected the number %s, got %s', $path, var_export($expected, true), get_debug_type($actual));
            return;
        }
        $same = $exact ? (float) $expected === (float) $actual : self::numbersMatch($expected, $actual);
        if (!$same) {
            $out[] = sprintf('%s: %s != %s', $path, var_export($expected, true), var_export($actual, true));
        }
    }

    /**
     * Compared as doubles whatever their JSON spelling (66 and 66.0 are the same number). Integral values
     * must be equal; otherwise abs(a - b) <= max(abs, rel * max(|a|, |b|)) with the tolerance of the file.
     */
    private static function numbersMatch(int|float $a, int|float $b): bool
    {
        $x = (float) $a;
        $y = (float) $b;
        if ($x === $y) {
            return true;
        }
        if (is_finite($x) && is_finite($y) && floor($x) === $x && floor($y) === $y) {
            return false;
        }
        $tolerance = self::golden()['tolerance'];
        return abs($x - $y) <= max($tolerance['abs'], $tolerance['rel'] * max(abs($x), abs($y)));
    }

    /**
     * @param string $key the object key the value sits under ('' inside a list or at the top)
     * @param list<string> $problems
     */
    private static function types(mixed $expected, mixed $actual, string $path, string $key, bool $allInts, array &$problems, int &$reals, int &$ints): void
    {
        if ($expected instanceof \stdClass) {
            foreach (get_object_vars($expected) as $member => $value) {
                self::types($value, $actual[$member], $path . '.' . $member, (string) $member, $allInts, $problems, $reals, $ints);
            }
        } elseif (is_array($expected)) {
            foreach ($expected as $i => $value) {
                self::types($value, $actual[$i], $path . '.' . $i, '', $allInts, $problems, $reals, $ints);
            }
        } elseif (is_float($expected)) {
            $reals += 1;
            if (!is_float($actual)) {
                $problems[] = sprintf('%s: a real in the golden file, %s from the port', $path, get_debug_type($actual));
            }
        } elseif (is_int($expected) && ($allInts || in_array($key, self::INT_FIELDS, true))) {
            $ints += 1;
            if (!is_int($actual)) {
                $problems[] = sprintf('%s: an int field, %s from the port', $path, get_debug_type($actual));
            }
        }
    }

    private static function resultOf(string $caseId): mixed
    {
        $case = self::golden()['cases'][$caseId];
        return Estimator::dispatch($case['function'], $case['args']);
    }

    /** Follow a dotted path ("orders.value"). */
    private static function at(mixed $value, string $path): mixed
    {
        if ($path === '') {
            return $value;
        }
        foreach (explode('.', $path) as $key) {
            $value = $value[$key];
        }
        return $value;
    }

    /**
     * The golden file, read once. `args` become PHP arrays (what a caller passes); `expected` keeps JSON
     * objects as objects so the comparison can tell { } from [ ].
     *
     * @return array{tolerance: array{rel: float, abs: float}, header: array<string, mixed>, anchors: list<array<string, mixed>>, cases: array<string, array{function: string, args: array<string, mixed>, expected: mixed}>}
     */
    private static function golden(): array
    {
        if (self::$golden !== null) {
            return self::$golden;
        }
        $path = dirname(__DIR__, 3) . self::FIXTURE;
        $text = file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException('cannot read ' . $path);
        }
        $document = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($document->cases as $case) {
            if (isset($cases[$case->id])) {
                throw new \RuntimeException('duplicate golden case id ' . $case->id);
            }
            $cases[$case->id] = [
                'function' => $case->function,
                'args' => self::toArray($case->args),
                'expected' => $case->expected,
            ];
        }
        self::$golden = [
            'tolerance' => ['rel' => (float) $document->tolerance->rel, 'abs' => (float) $document->tolerance->abs],
            'header' => ['model_version' => $document->model_version, 'seeds_revision' => $document->seeds_revision],
            'anchors' => self::toArray($document->anchors),
            'cases' => $cases,
        ];
        return self::$golden;
    }

    /** JSON objects to associative arrays, recursively ({} becomes []). */
    private static function toArray(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $out = [];
            foreach (get_object_vars($value) as $key => $member) {
                $out[$key] = self::toArray($member);
            }
            return $out;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $member) {
                $out[] = self::toArray($member);
            }
            return $out;
        }
        return $value;
    }
}
