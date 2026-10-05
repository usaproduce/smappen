<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

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

/**
 * Resolves the five contracts through which the feature packages call one another.
 *
 * For each contract the answer is, in this order: an object given with set(); else the real service when
 * its class exists (it is built with no arguments); else the fallback. A feature package therefore only
 * adds files, and a package runs against the fallbacks until its neighbour lands.
 *
 * One object per contract and process: asking twice gives the same instance.
 */
final class Registry
{
    private const SERVICES = 'App\\TruckPlanner\\Services\\';

    /**
     * Contract name => [interface, real class, fallback class].
     *
     * @var array<string, array{0: class-string, 1: string, 2: class-string}>
     */
    private const CONTRACTS = [
        'capture' => [CaptureProvider::class, self::SERVICES . 'CaptureService', NoCapture::class],
        'legs' => [LegProvider::class, self::SERVICES . 'RoutingService', StraightLineLegs::class],
        'dayContexts' => [DayContextProvider::class, self::SERVICES . 'DayContextService', PlainDayContexts::class],
        'fuel' => [FuelPriceProvider::class, self::SERVICES . 'FuelPriceService', SeedFuelPrice::class],
        'calibration' => [CalibrationProvider::class, self::SERVICES . 'CalibrationService', IdentityCalibration::class],
    ];

    /** @var array<string, object> */
    private static array $given = [];

    /** @var array<string, object> */
    private static array $resolved = [];

    public static function capture(): CaptureProvider
    {
        /** @var CaptureProvider $provider */
        $provider = self::resolve('capture');
        return $provider;
    }

    public static function legs(): LegProvider
    {
        /** @var LegProvider $provider */
        $provider = self::resolve('legs');
        return $provider;
    }

    public static function dayContexts(): DayContextProvider
    {
        /** @var DayContextProvider $provider */
        $provider = self::resolve('dayContexts');
        return $provider;
    }

    public static function fuel(): FuelPriceProvider
    {
        /** @var FuelPriceProvider $provider */
        $provider = self::resolve('fuel');
        return $provider;
    }

    public static function calibration(): CalibrationProvider
    {
        /** @var CalibrationProvider $provider */
        $provider = self::resolve('calibration');
        return $provider;
    }

    /**
     * Use this object for a contract (tests, and scripts that wire a service by hand).
     *
     * @param string $contract "capture", "legs", "dayContexts", "fuel" or "calibration"
     */
    public static function set(string $contract, object $impl): void
    {
        if (!isset(self::CONTRACTS[$contract])) {
            throw new \LogicException('unknown Truck Planner contract: ' . $contract);
        }
        $interface = self::CONTRACTS[$contract][0];
        if (!$impl instanceof $interface) {
            throw new \LogicException(get_class($impl) . ' does not implement ' . $interface);
        }
        self::$given[$contract] = $impl;
    }

    /** Forget every object given with set() and every object built so far. */
    public static function reset(): void
    {
        self::$given = [];
        self::$resolved = [];
    }

    private static function resolve(string $contract): object
    {
        if (isset(self::$given[$contract])) {
            return self::$given[$contract];
        }
        if (!isset(self::$resolved[$contract])) {
            [$interface, $real, $fallback] = self::CONTRACTS[$contract];
            $class = class_exists($real) ? $real : $fallback;
            $object = new $class();
            if (!$object instanceof $interface) {
                throw new \LogicException($class . ' does not implement ' . $interface);
            }
            self::$resolved[$contract] = $object;
        }
        return self::$resolved[$contract];
    }
}
