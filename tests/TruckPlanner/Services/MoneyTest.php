<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Services\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testDollarsToCents(): void
    {
        self::assertSame(1500, Money::toCents(15.0));
        self::assertSame(15, Money::toCents(0.15));
        self::assertSame(0, Money::toCents(0.0));
        self::assertSame(80150, Money::toCents(801.5));
        self::assertSame(1999, Money::toCents(19.99));
        self::assertSame(29, Money::toCents(0.29));
        self::assertSame(10000000, Money::toCents(100000.0));
    }

    public function testHalfCentsRoundAwayFromZeroTheWayAPersonExpects(): void
    {
        // 2.675 and 1.005 are just below the half in binary: the model's rounding rule still rounds them up.
        self::assertSame(268, Money::toCents(2.675));
        self::assertSame(101, Money::toCents(1.005));
        self::assertSame(1235, Money::toCents(12.345));
        self::assertSame(1, Money::toCents(0.005));
        self::assertSame(0, Money::toCents(0.0049));
        self::assertSame(-268, Money::toCents(-2.675));
    }

    public function testCentsToDollars(): void
    {
        self::assertSame(15.0, Money::fromCents(1500));
        self::assertSame(0.15, Money::fromCents(15));
        self::assertSame(801.5, Money::fromCents(80150));
        self::assertSame(0.0, Money::fromCents(0));
    }

    public function testEveryCentAmountRoundTrips(): void
    {
        for ($cents = 0; $cents <= 250000; $cents += 7) {
            self::assertSame($cents, Money::toCents(Money::fromCents($cents)));
        }
        foreach ([99999999, 4294967295] as $cents) {
            self::assertSame($cents, Money::toCents(Money::fromCents($cents)));
        }
    }

    public function testFuelPricesInThousandths(): void
    {
        self::assertSame(4195, Money::toMilli(4.195));
        self::assertSame(4.195, Money::fromMilli(4195));
        self::assertSame(6531, Money::toMilli(6.531));
        self::assertSame(500, Money::toMilli(0.5));
        self::assertSame(20000, Money::toMilli(20.0));
        self::assertSame(4196, Money::toMilli(4.1955));
        for ($milli = 500; $milli <= 20000; $milli++) {
            self::assertSame($milli, Money::toMilli(Money::fromMilli($milli)));
        }
    }
}
