<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * Helpers on the Estimate shape { value, low, high, confidence } (02_MODEL.md section 3).
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Estimates
{
    /** @return array{value: float, low: float, high: float, confidence: string} */
    public static function fixed(float $x): array
    {
        return ['value' => $x, 'low' => $x, 'high' => $x, 'confidence' => 'fixed'];
    }

    /**
     * An Estimate from three levels that may arrive in any order (a cost line falls when orders rise).
     *
     * @return array{value: float, low: float, high: float, confidence: string}
     */
    public static function levels(float $v, float $l, float $h, string $c): array
    {
        $low = $l < $v ? $l : $v;
        $low = $h < $low ? $h : $low;
        $high = $l > $v ? $l : $v;
        $high = $h > $high ? $h : $high;
        return ['value' => $v, 'low' => $low, 'high' => $high, 'confidence' => $c];
    }

    /**
     * The label earliest in [very_rough, rough, fair, good, fixed]; "fixed" for an empty list.
     *
     * @param array<int, string> $labels
     */
    public static function weakest(array $labels): string
    {
        $best = count(Vocab::CONFIDENCE_LABELS) - 1;
        foreach ($labels as $label) {
            if (!is_string($label) || !isset(Vocab::CONFIDENCE_INDEX[$label])) {
                throw new \OutOfBoundsException('unknown confidence label');
            }
            $i = Vocab::CONFIDENCE_INDEX[$label];
            if ($i < $best) {
                $best = $i;
            }
        }
        return Vocab::CONFIDENCE_LABELS[$best];
    }

    /**
     * Lows add to lows and highs to highs, in list order: stops are treated as moving together.
     *
     * @param array<int, array{value: float|int, low: float|int, high: float|int, confidence: string}> $estimates
     * @return array{value: float, low: float, high: float, confidence: string}
     */
    public static function sum(array $estimates): array
    {
        $value = 0.0;
        $low = 0.0;
        $high = 0.0;
        $labels = [];
        foreach ($estimates as $e) {
            $value += Num::f($e['value']);
            $low += Num::f($e['low']);
            $high += Num::f($e['high']);
            $labels[] = $e['confidence'];
        }
        return ['value' => $value, 'low' => $low, 'high' => $high, 'confidence' => self::weakest($labels)];
    }
}
