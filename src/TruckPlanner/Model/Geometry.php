<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * The one distance function of the model and the walking-distance kernel (02_MODEL.md 4.3).
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Geometry
{
    /** Metres on a sphere of radius 6371008.8. */
    public static function haversineM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = $lat1 * 3.141592653589793 / 180.0;
        $p2 = $lat2 * 3.141592653589793 / 180.0;
        $dp = ($lat2 - $lat1) * 3.141592653589793 / 180.0;
        $dl = ($lng2 - $lng1) * 3.141592653589793 / 180.0;
        $sp = sin($dp / 2.0);
        $sl = sin($dl / 2.0);
        $a = $sp * $sp + cos($p1) * cos($p2) * $sl * $sl;
        if ($a < 0.0) {
            $a = 0.0;
        } elseif ($a > 1.0) {
            $a = 1.0;
        }
        return 2.0 * 6371008.8 * asin(sqrt($a));
    }

    /**
     * f(d): how much a person d metres away counts. exp(-d / 400), zero beyond 1,200 m (inclusive cutoff).
     *
     * @param array<string, mixed> $A
     */
    public static function walkWeight(array $A, float $d): float
    {
        if ($d < 0 || $d > Seeds::read($A, 'kernel.walk_cutoff_m')) {
            return 0.0;
        }
        return exp(-$d / Seeds::read($A, 'kernel.walk_decay_m'));
    }
}
