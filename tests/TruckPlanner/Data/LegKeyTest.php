<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\TruckPlanner\Data\LegKey;
use PHPUnit\Framework\TestCase;

final class LegKeyTest extends TestCase
{
    public function testFourDecimalsAsIntegers(): void
    {
        self::assertSame(389600, LegKey::e4(38.96));
        self::assertSame(-773600, LegKey::e4(-77.36));
        self::assertSame(390030, LegKey::e4(39.003));
        self::assertSame(0, LegKey::e4(0.0));
        self::assertSame(0, LegKey::e4(-0.00004));
        self::assertSame(900000, LegKey::e4(90.0));
        self::assertSame(-1800000, LegKey::e4(-180.0));
    }

    public function testHalvesRoundAwayFromZero(): void
    {
        self::assertSame(389601, LegKey::e4(38.96005));
        self::assertSame(-773601, LegKey::e4(-77.36005));
        self::assertSame(389600, LegKey::e4(38.960049));
        self::assertSame(1, LegKey::e4(0.00005));
        self::assertSame(-1, LegKey::e4(-0.00005));
    }

    public function testPointKeyIsLatThenLng(): void
    {
        self::assertSame([389600, -773600], LegKey::of(38.96, -77.36));
        self::assertSame(LegKey::of(38.96, -77.36), LegKey::of(38.960004, -77.359996));
        self::assertNotSame(LegKey::of(38.96, -77.36), LegKey::of(38.9601, -77.36));
    }

    public function testDegreesOfAKey(): void
    {
        self::assertSame(38.96, LegKey::deg(389600));
        self::assertSame(-77.36, LegKey::deg(-773600));
        self::assertSame(389600, LegKey::e4(LegKey::deg(389600)));
        foreach ([-1800000, -773605, -1, 0, 1, 123457, 899999] as $e4) {
            self::assertSame($e4, LegKey::e4(LegKey::deg($e4)));
        }
    }

    public function testRouteKey(): void
    {
        self::assertSame('d', LegKey::routeKey(['avoid_tolls' => false, 'avoid_highways' => false]));
        self::assertSame('dt', LegKey::routeKey(['avoid_tolls' => true, 'avoid_highways' => false]));
        self::assertSame('dh', LegKey::routeKey(['avoid_tolls' => false, 'avoid_highways' => true]));
        self::assertSame('dth', LegKey::routeKey(['avoid_tolls' => true, 'avoid_highways' => true]));
        self::assertSame('d', LegKey::routeKey([]));
    }
}
