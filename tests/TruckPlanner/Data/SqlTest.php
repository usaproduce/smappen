<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\TruckPlanner\Data\Sql;
use PHPUnit\Framework\TestCase;

final class SqlTest extends TestCase
{
    public function testFloatsAreBoundAsShortestRoundTripText(): void
    {
        self::assertSame('38.96', Sql::f(38.96));
        self::assertSame('0.1', Sql::f(0.1));
        self::assertSame('0.30000000000000004', Sql::f(0.1 + 0.2));
        self::assertSame('1.0e-7', Sql::f(1.0e-7));
        self::assertSame('400', Sql::f(400.0));
        // PDO's own float binding would send 38.910068312346: two digits short of the stored double.
        self::assertSame('38.91006831234568', Sql::f(38.91006831234568));
        self::assertSame('-77.0368712345679', Sql::f(-77.036871234567891));
    }

    public function testEveryFloatTextReadsBackAsTheSameDouble(): void
    {
        foreach ([38.91006831234568, -77.03687123456789, 1 / 3, 2.675, 6371008.8, 4.9e-324, 1.7976931348623157e308, -0.0] as $x) {
            self::assertSame($x, (float) Sql::f($x), 'round trip of ' . var_export($x, true));
        }
    }

    public function testFloatTextDoesNotDependOnThePrecisionSetting(): void
    {
        $before = ini_get('serialize_precision');
        ini_set('serialize_precision', '5');
        try {
            self::assertSame('0.30000000000000004', Sql::f(0.1 + 0.2));
        } finally {
            ini_set('serialize_precision', (string) $before);
        }
    }

    public function testNonFiniteFloatsNeverReachSql(): void
    {
        foreach ([INF, -INF, NAN] as $x) {
            try {
                Sql::f($x);
                self::fail('a non-finite float was accepted');
            } catch (\DomainException $e) {
                self::assertNotInstanceOf(\InvalidArgumentException::class, $e);
            }
        }
    }

    public function testBooleansAreBoundAsOneOrZero(): void
    {
        self::assertSame(1, Sql::b(true));
        self::assertSame(0, Sql::b(false));
    }

    public function testJsonKeepsEmptyMapsAsObjects(): void
    {
        self::assertSame('{}', Sql::json([], true));
        self::assertSame('[]', Sql::json([]));
        self::assertSame('{"host.captive_share":0.6}', Sql::json(['host.captive_share' => 0.6], true));
        self::assertSame('{"0":"a"}', Sql::json(['a'], true));
        self::assertSame('["51107","51059"]', Sql::json(['51107', '51059']));
    }

    public function testJsonKeepsEveryDigitAndLeavesNestedListsAlone(): void
    {
        $json = Sql::json(['weather.floor' => 0.30000000000000004, 'curve' => [0.0, 0.25, 1.0]], true);
        self::assertSame('{"weather.floor":0.30000000000000004,"curve":[0,0.25,1]}', $json);
        self::assertSame(0.30000000000000004, json_decode($json, true)['weather.floor']);
        self::assertSame('{"a/b":"é"}', Sql::json(['a/b' => 'é'], true));
    }

    public function testJsonRefusesWhatCannotBeEncoded(): void
    {
        $this->expectException(\JsonException::class);
        Sql::json(['x' => INF]);
    }

    public function testMarks(): void
    {
        self::assertSame('?', Sql::marks(1));
        self::assertSame('?, ?, ?', Sql::marks(3));
    }

    public function testAnEmptyInListIsRefused(): void
    {
        $this->expectException(\LengthException::class);
        Sql::marks(0);
    }
}
