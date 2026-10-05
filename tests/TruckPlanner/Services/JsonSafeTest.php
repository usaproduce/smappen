<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\LogCapture;
use App\TruckPlanner\Services\Support\JsonSafe;
use PHPUnit\Framework\TestCase;

final class JsonSafeTest extends TestCase
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function testFinitePayloadIsUntouched(): void
    {
        $data = [
            'model_version' => 'tps-0.1.0',
            'count' => 3,
            'value' => 482.2,
            'flag' => false,
            'nothing' => null,
            'list' => [1, 2.5, 'x'],
            'nested' => ['a' => ['b' => 0.30000000000000004]],
        ];
        self::assertSame($data, JsonSafe::clean($data));
    }

    public function testNonFiniteNumbersBecomeNullAndAreLoggedWithTheirPath(): void
    {
        $data = ['result' => ['totals' => ['take_home_per_hour' => ['value' => INF, 'low' => -INF, 'high' => NAN, 'confidence' => 'rough']]]];
        $clean = null;
        $lines = LogCapture::during(static function () use ($data, &$clean): void {
            $clean = JsonSafe::clean($data);
        });
        self::assertSame(
            ['value' => null, 'low' => null, 'high' => null, 'confidence' => 'rough'],
            $clean['result']['totals']['take_home_per_hour']
        );
        self::assertSame(
            [
                '[tp] non-finite at result.totals.take_home_per_hour.value',
                '[tp] non-finite at result.totals.take_home_per_hour.low',
                '[tp] non-finite at result.totals.take_home_per_hour.high',
            ],
            $lines
        );
        self::assertNotFalse(json_encode($clean, self::FLAGS));
    }

    public function testListIndexesAppearInThePath(): void
    {
        $lines = LogCapture::during(static function (): void {
            JsonSafe::clean(['stops' => [['orders' => 1.0], ['orders' => NAN]]]);
        });
        self::assertSame(['[tp] non-finite at stops.1.orders'], $lines);
    }

    public function testInvalidTextBecomesNull(): void
    {
        $clean = null;
        $lines = LogCapture::during(static function () use (&$clean): void {
            $clean = JsonSafe::clean(['place' => ['name' => "Caf\xE9", 'city' => 'Sterling', 'brand' => 'Café']]);
        });
        self::assertSame(['name' => null, 'city' => 'Sterling', 'brand' => 'Café'], $clean['place']);
        self::assertSame(['[tp] invalid text at place.name'], $lines);
        self::assertNotFalse(json_encode($clean, self::FLAGS));
    }

    public function testEmptyMapsAtTheNamedPathsAreSentAsObjects(): void
    {
        $data = [
            'assumptions' => ['overrides' => [], 'region' => ['flags' => []]],
            'calibration' => ['spots' => [], 'truck_n' => 0],
            'regions' => [],
        ];
        $clean = JsonSafe::clean($data, ['assumptions.overrides', 'calibration.spots']);
        self::assertSame(
            '{"assumptions":{"overrides":{},"region":{"flags":[]}},"calibration":{"spots":{},"truck_n":0},"regions":[]}',
            json_encode($clean, self::FLAGS)
        );
    }

    public function testAMapWithEntriesStaysAnObjectEvenWithNumericKeys(): void
    {
        $clean = JsonSafe::clean(['week' => ['visits' => ['0' => 2, '1' => 1]]], ['week.visits']);
        self::assertSame('{"week":{"visits":{"0":2,"1":1}}}', json_encode($clean, self::FLAGS));
        $clean = JsonSafe::clean(['calibration' => ['spots' => ['b2f0' => ['factor' => 0.97, 'n' => 1]]]], ['calibration.spots']);
        self::assertSame('{"calibration":{"spots":{"b2f0":{"factor":0.97,"n":1}}}}', json_encode($clean, self::FLAGS));
    }

    public function testAStarMatchesTheItemsOfAList(): void
    {
        $data = [
            'plan' => ['result' => ['warnings' => [
                ['code' => 'long_gap', 'data' => ['gap_before_minutes' => 120]],
                ['code' => 'stops_overlap', 'data' => []],
            ]]],
            'suggestions' => [
                ['result' => ['warnings' => [['code' => 'x', 'data' => []]]]],
                ['result' => ['warnings' => []]],
            ],
        ];
        $clean = JsonSafe::clean($data, ['plan.result.warnings.*.data', 'suggestions.*.result.warnings.*.data']);
        self::assertSame(
            '{"plan":{"result":{"warnings":[{"code":"long_gap","data":{"gap_before_minutes":120}},{"code":"stops_overlap","data":{}}]}},'
            . '"suggestions":[{"result":{"warnings":[{"code":"x","data":{}}]}},{"result":{"warnings":[]}}]}',
            json_encode($clean, self::FLAGS)
        );
    }

    public function testATopLevelMapPath(): void
    {
        $clean = JsonSafe::clean(['vectors' => [], 'outlets' => []], ['vectors']);
        self::assertSame('{"vectors":{},"outlets":[]}', json_encode($clean, self::FLAGS));
    }

    public function testMapPathsDoNotReachOtherDepths(): void
    {
        $clean = JsonSafe::clean(['a' => ['data' => []], 'data' => []], ['a.data']);
        self::assertSame('{"a":{"data":{}},"data":[]}', json_encode($clean, self::FLAGS));
    }

    public function testValuesInsideAMapAreStillCleaned(): void
    {
        $clean = null;
        LogCapture::during(static function () use (&$clean): void {
            $clean = JsonSafe::clean(['calibration' => ['spots' => ['s1' => ['factor' => INF]]]], ['calibration.spots']);
        });
        self::assertSame('{"calibration":{"spots":{"s1":{"factor":null}}}}', json_encode($clean, self::FLAGS));
    }

    public function testCanonicalSortsKeysAtEveryLevelAndKeepsListOrder(): void
    {
        $a = ['b' => 1, 'a' => ['z' => [3, 1, 2], 'k' => 'v'], 'B' => true];
        $b = ['B' => true, 'a' => ['k' => 'v', 'z' => [3, 1, 2]], 'b' => 1];
        self::assertSame('{"B":true,"a":{"k":"v","z":[3,1,2]},"b":1}', JsonSafe::canonical($a));
        self::assertSame(JsonSafe::canonical($a), JsonSafe::canonical($b));
        self::assertNotSame(JsonSafe::canonical(['z' => [1, 2]]), JsonSafe::canonical(['z' => [2, 1]]));
    }

    public function testCanonicalSortsByBytesNotByNumberOrLocale(): void
    {
        self::assertSame('{"10":"a","9":"b","Z":"c","a":"d","é":"e"}', JsonSafe::canonical(['é' => 'e', 'a' => 'd', 9 => 'b', 'Z' => 'c', 10 => 'a']));
    }

    public function testCanonicalNumbers(): void
    {
        self::assertSame(JsonSafe::canonical(['x' => 66]), JsonSafe::canonical(['x' => 66.0]));
        self::assertSame('{"x":0.30000000000000004,"y":1.0e-7,"z":45}', JsonSafe::canonical(['z' => 45.0, 'y' => 1.0e-7, 'x' => 0.1 + 0.2]));
        self::assertSame('[]', JsonSafe::canonical([]));
        self::assertSame('{"a":[],"b":null,"c":"/é"}', JsonSafe::canonical(['c' => '/é', 'b' => null, 'a' => []]));
    }

    public function testCanonicalDoesNotDependOnThePrecisionSetting(): void
    {
        $before = ini_get('serialize_precision');
        ini_set('serialize_precision', '4');
        try {
            self::assertSame('{"x":0.30000000000000004}', JsonSafe::canonical(['x' => 0.1 + 0.2]));
        } finally {
            ini_set('serialize_precision', (string) $before);
        }
    }

    public function testCanonicalRefusesWhatCannotBeHashed(): void
    {
        $this->expectException(\JsonException::class);
        JsonSafe::canonical(['x' => NAN]);
    }

    public function testFloatText(): void
    {
        self::assertSame('0.5', JsonSafe::float(0.5));
        self::assertSame('200', JsonSafe::float(200.0));
        self::assertSame('1', JsonSafe::float(1.0));
        self::assertSame('100000', JsonSafe::float(100000.0));
        self::assertSame('0.95', JsonSafe::float(0.95));
        $this->expectException(\DomainException::class);
        JsonSafe::float(INF);
    }
}
