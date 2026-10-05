<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Model;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Model\Vocab;
use PHPUnit\Framework\TestCase;

/**
 * src/TruckPlanner/Model/SeedsData.php is a generated copy of docs/truck-planner/reference/tp_seeds.json
 * (the only place a seed is edited). This test fails when the two differ: run
 * `python docs/truck-planner/reference/generate_seed_copies.py` after every change to the seed file.
 *
 * Parsed values are compared, not file bytes (line endings differ between checkouts).
 */
final class SeedSyncTest extends TestCase
{
    private const SOURCE = '/docs/truck-planner/reference/tp_seeds.json';
    private const COPY = '/src/TruckPlanner/Model/SeedsData.php';

    public function testGeneratedCopyEqualsTheSeedFile(): void
    {
        $source = self::source();
        $copy = require dirname(__DIR__, 3) . self::COPY;

        self::assertIsArray($copy);
        $differences = [];
        self::compare($source, $copy, '', $differences);
        self::assertSame([], $differences, "SeedsData.php is not what tp_seeds.json generates:\n  " . implode("\n  ", $differences));
    }

    public function testSeedsServesThatCopy(): void
    {
        $copy = require dirname(__DIR__, 3) . self::COPY;

        self::assertSame($copy, Seeds::data());
        self::assertSame(Seeds::data(), Seeds::data(), 'loaded once, never modified');
        self::assertSame($copy['seeds_revision'], Seeds::revision());
        self::assertSame($copy['model_version'], Estimator::MODEL_VERSION);
    }

    /** A PHP array cannot tell {} from []; the override validator relies on there being no empty object. */
    public function testSeedFileHasNoEmptyObjectAndNoNumericKey(): void
    {
        $problems = [];
        self::scan(self::source(), '', $problems);
        self::assertSame([], $problems);
    }

    /** 02_MODEL.md 2.1: the lists under `vocabulary` are in index order and equal the constants in code. */
    public function testVocabularyEqualsTheConstantsInCode(): void
    {
        $vocabulary = Seeds::data()['vocabulary'];

        self::assertSame(Vocab::SEGMENTS, $vocabulary['segments']);
        self::assertSame(Vocab::REGIMES, $vocabulary['regimes']);
        self::assertSame(Vocab::RIVAL_KINDS, $vocabulary['rival_kinds']);
        self::assertSame(Vocab::DAY_TYPES, $vocabulary['day_types']);
        self::assertSame(Vocab::DAYPARTS, $vocabulary['dayparts']);
        self::assertSame(Vocab::VISIBILITY_LEVELS, $vocabulary['visibility_levels']);
        self::assertSame(Vocab::CONFIDENCE_LABELS, $vocabulary['confidence_labels']);
        self::assertSame(Vocab::DOW_KEYS, $vocabulary['dow']);

        self::assertSame(Vocab::NSEG, count(Vocab::SEGMENTS));
        self::assertSame(array_flip(Vocab::SEGMENTS), Vocab::SEGMENT_INDEX);
        self::assertSame(array_flip(Vocab::DOW_KEYS), Vocab::DOW_INDEX);
        self::assertSame(array_flip(Vocab::CONFIDENCE_LABELS), Vocab::CONFIDENCE_INDEX);
        foreach (Vocab::SEGMENTS as $index => $segment) {
            self::assertSame($index, Seeds::data()['segments'][$segment]['index'], $segment);
        }
        self::assertSame($vocabulary['place_types'], Seeds::data()['place_types']['order']);
    }

    /** 02_MODEL.md 1.2: the constants are literals in code; the seed file carries the same numbers. */
    public function testConstantsEqualTheLiteralsInCode(): void
    {
        $constants = Seeds::data()['constants'];

        self::assertSame(Vocab::EARTH_RADIUS_M, $constants['earth_radius_m']['value']);
        self::assertSame(Vocab::PI, $constants['pi']['value']);
        self::assertSame(Vocab::LN2, $constants['ln2']['value']);
        self::assertSame(Vocab::Z80, $constants['z80']['value']);
        self::assertSame(Vocab::METERS_PER_MILE, $constants['meters_per_mile']['value']);
        self::assertSame(Vocab::ROUND_HALF, $constants['round_half']['value']);
        self::assertSame(Vocab::QKEY_SCALE, $constants['qkey_scale']['value']);

        self::assertSame(M_PI, Vocab::PI, 'the literal is the double nearest pi');
        self::assertSame(log(2.0), Vocab::LN2, 'the literal is the double nearest ln 2');
    }

    public function testDefaultsAreAnAssumptionsRecordForRegionNone(): void
    {
        $A = Seeds::defaults();

        self::assertSame(['model_version', 'seeds_revision', 'seeds', 'overrides', 'region'], array_keys($A));
        self::assertSame('tps-0.1.0', $A['model_version']);
        self::assertSame(Seeds::revision(), $A['seeds_revision']);
        self::assertSame(Seeds::data(), $A['seeds']);
        self::assertSame([], $A['overrides']);
        self::assertSame(['id' => 'none', 'traffic_matrix' => 'us_mean', 'flags' => ['inauguration_day' => false]], $A['region']);
        self::assertSame(1.6, Estimator::seed($A, 'kernel.outside_option_a0'));
    }

    // ---------------------------------------------------------------------------------------------------

    private static function source(): mixed
    {
        $path = dirname(__DIR__, 3) . self::SOURCE;
        $text = file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException('cannot read ' . $path);
        }
        return json_decode($text, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Exact comparison: the same keys in the same order, lists of the same length, the same strings,
     * booleans and nulls, and numbers of the same kind (an integer stays an integer, a real a real) and
     * the same value.
     *
     * @param list<string> $out
     */
    private static function compare(mixed $source, mixed $copy, string $path, array &$out): void
    {
        if (count($out) >= 10) {
            return;
        }
        if ($source instanceof \stdClass) {
            $members = get_object_vars($source);
            if (!is_array($copy) || array_map('strval', array_keys($copy)) !== array_map('strval', array_keys($members))) {
                $out[] = $path . ': keys differ (or their order)';
                return;
            }
            foreach ($members as $key => $value) {
                self::compare($value, $copy[$key], $path === '' ? (string) $key : $path . '.' . $key, $out);
            }
            return;
        }
        if (is_array($source)) {
            if (!is_array($copy) || !array_is_list($copy) || count($copy) !== count($source)) {
                $out[] = $path . ': not a list of ' . count($source) . ' items';
                return;
            }
            foreach ($source as $i => $value) {
                self::compare($value, $copy[$i], $path . '.' . $i, $out);
            }
            return;
        }
        if ($source !== $copy) {                           // strict: 400 is not 400.0, "1" is not 1
            $out[] = sprintf('%s: %s in the seed file, %s in the copy', $path, var_export($source, true), var_export($copy, true));
        }
    }

    /**
     * @param list<string> $problems
     */
    private static function scan(mixed $node, string $path, array &$problems): void
    {
        if ($node instanceof \stdClass) {
            $members = get_object_vars($node);
            if ($members === []) {
                $problems[] = $path . ': empty object';
            }
            foreach ($members as $key => $value) {
                $key = (string) $key;
                if (preg_match('/^-?[0-9]+$/', $key) === 1) {
                    $problems[] = $path . '.' . $key . ': numeric key';
                }
                self::scan($value, $path === '' ? $key : $path . '.' . $key, $problems);
            }
        } elseif (is_array($node)) {
            foreach ($node as $i => $value) {
                self::scan($value, $path . '.' . $i, $problems);
            }
        }
    }
}
