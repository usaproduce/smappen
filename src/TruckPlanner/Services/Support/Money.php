<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

use App\TruckPlanner\Model\Estimator;

/**
 * Dollars in the API and the model, whole cents in MySQL. Fuel prices have three decimals, so they are
 * stored in thousandths of a dollar ("milli"). Repositories convert at the boundary with these four
 * functions and nothing else: the one rounding rule is the model's.
 */
final class Money
{
    /** 15.0 -> 1500. Half a cent rounds away from zero. */
    public static function toCents(float $dollars): int
    {
        return (int) Estimator::roundHalfAway($dollars * 100.0, 0);
    }

    /** 1500 -> 15.0 */
    public static function fromCents(int $c): float
    {
        return $c / 100.0;
    }

    /** 4.195 -> 4195 */
    public static function toMilli(float $d): int
    {
        return (int) Estimator::roundHalfAway($d * 1000.0, 0);
    }

    /** 4195 -> 4.195 */
    public static function fromMilli(int $m): float
    {
        return $m / 1000.0;
    }
}
