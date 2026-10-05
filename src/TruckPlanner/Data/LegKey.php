<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\TruckPlanner\Model\Estimator;

/**
 * The key of a drive leg (04_BACKEND.md 1.4): coordinates rounded to four decimals (about 11 m) and stored
 * as integers of ten-thousandths of a degree, plus a route key for the owner's routing options.
 *
 * A point key is the pair [lat_e4, lng_e4]. Two points with equal keys are the same place for routing.
 * Cached legs and the owner's corrections are both keyed this way, and Google is asked about the rounded
 * coordinates (deg()), so a cached answer always belongs to its key.
 */
final class LegKey
{
    /** Degrees to ten-thousandths of a degree, rounded half away from zero: 38.96004 -> 389600. */
    public static function e4(float $deg): int
    {
        return (int) Estimator::roundHalfAway($deg * 10000.0, 0);
    }

    /**
     * The key of a point.
     *
     * @return array{0: int, 1: int} [lat_e4, lng_e4]
     */
    public static function of(float $lat, float $lng): array
    {
        return [self::e4($lat), self::e4($lng)];
    }

    /** Ten-thousandths of a degree back to degrees: 389600 -> 38.96. */
    public static function deg(int $e4): float
    {
        return $e4 / 10000.0;
    }

    /**
     * "d" plus "t" when the profile avoids tolls plus "h" when it avoids highways: d, dt, dh, dth.
     *
     * @param array<string, mixed> $profile a TruckProfile (`avoid_tolls`, `avoid_highways`)
     */
    public static function routeKey(array $profile): string
    {
        return 'd'
            . (($profile['avoid_tolls'] ?? false) === true ? 't' : '')
            . (($profile['avoid_highways'] ?? false) === true ? 'h' : '');
    }
}
