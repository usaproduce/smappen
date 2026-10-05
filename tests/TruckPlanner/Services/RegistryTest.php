<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Services\Contracts\CalibrationProvider;
use App\TruckPlanner\Services\Contracts\CaptureProvider;
use App\TruckPlanner\Services\Contracts\DayContextProvider;
use App\TruckPlanner\Services\Contracts\FuelPriceProvider;
use App\TruckPlanner\Services\Contracts\LegProvider;
use App\TruckPlanner\Services\Fallback\IdentityCalibration;
use App\TruckPlanner\Services\Fallback\NoCapture;
use App\TruckPlanner\Services\Fallback\PlainDayContexts;
use App\TruckPlanner\Services\Fallback\SeedFuelPrice;
use App\TruckPlanner\Services\Fallback\StraightLineLegs;
use App\TruckPlanner\Services\Support\Registry;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    /** Contract => [interface, real class of a later package, fallback]. */
    private const CONTRACTS = [
        'capture' => [CaptureProvider::class, 'App\\TruckPlanner\\Services\\CaptureService', NoCapture::class],
        'legs' => [LegProvider::class, 'App\\TruckPlanner\\Services\\RoutingService', StraightLineLegs::class],
        'dayContexts' => [DayContextProvider::class, 'App\\TruckPlanner\\Services\\DayContextService', PlainDayContexts::class],
        'fuel' => [FuelPriceProvider::class, 'App\\TruckPlanner\\Services\\FuelPriceService', SeedFuelPrice::class],
        'calibration' => [CalibrationProvider::class, 'App\\TruckPlanner\\Services\\CalibrationService', IdentityCalibration::class],
    ];

    protected function setUp(): void
    {
        Registry::reset();
    }

    protected function tearDown(): void
    {
        Registry::reset();
    }

    public function testEachContractResolvesToTheRealServiceWhenItIsInstalledElseToItsFallback(): void
    {
        foreach (self::CONTRACTS as $contract => [$interface, $real, $fallback]) {
            $provider = Registry::$contract();
            self::assertInstanceOf($interface, $provider);
            self::assertInstanceOf(class_exists($real) ? $real : $fallback, $provider, $contract);
        }
    }

    public function testEveryFallbackImplementsItsContractAndNeedsNoArguments(): void
    {
        foreach (self::CONTRACTS as [$interface, , $fallback]) {
            self::assertInstanceOf($interface, new $fallback());
        }
    }

    public function testTheSameObjectIsGivenEveryTime(): void
    {
        self::assertSame(Registry::capture(), Registry::capture());
        self::assertSame(Registry::legs(), Registry::legs());
        self::assertSame(Registry::fuel(), Registry::fuel());
    }

    public function testAnObjectThatWasSetWinsUntilReset(): void
    {
        $mine = new StraightLineLegs();
        $before = Registry::legs();
        Registry::set('legs', $mine);
        self::assertSame($mine, Registry::legs());
        self::assertNotSame($before, Registry::legs());

        Registry::reset();
        self::assertNotSame($mine, Registry::legs());
        self::assertInstanceOf(LegProvider::class, Registry::legs());
    }

    public function testSetOnOneContractLeavesTheOthersAlone(): void
    {
        $fuel = new SeedFuelPrice();
        Registry::set('fuel', $fuel);
        self::assertSame($fuel, Registry::fuel());
        self::assertInstanceOf(CalibrationProvider::class, Registry::calibration());
        self::assertNotSame($fuel, Registry::calibration());
    }

    public function testSetRefusesAnObjectOfTheWrongKind(): void
    {
        try {
            Registry::set('legs', new NoCapture());
            self::fail('a capture provider was accepted as the leg provider');
        } catch (\LogicException $e) {
            self::assertStringContainsString('does not implement', $e->getMessage());
        }
        self::assertNotInstanceOf(NoCapture::class, Registry::legs());
    }

    public function testSetRefusesAnUnknownContract(): void
    {
        $this->expectException(\LogicException::class);
        Registry::set('weather', new NoCapture());
    }
}
