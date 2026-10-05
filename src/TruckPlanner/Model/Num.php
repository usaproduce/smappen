<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * Integer helpers, the one rounding helper and the ranking key (02_MODEL.md 1.2, 1.4).
 *
 * Everything here is a fixed sequence of exactly rounded binary64 operations, so every runtime returns the
 * same bits.
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Num
{
    /** @var list<float> */
    private const POW10 = [
        1.0, 10.0, 100.0, 1000.0, 10000.0, 100000.0, 1000000.0, 10000000.0, 100000000.0, 1000000000.0,
    ];

    /** 2^63: the first real above the integer range. */
    private const INT_LIMIT = 9223372036854775808.0;

    /**
     * A number read from an input shape. An int is widened; null, a string, a boolean or an array is a
     * TypeError (every file of the model declares strict types, and it is the calling file that decides),
     * so a missing or malformed field never turns into a silent zero.
     */
    public static function f(float $value): float
    {
        return $value;
    }

    /** A whole number read from an input shape (minutes, hours, counts). Anything else is a TypeError. */
    public static function i(int $value): int
    {
        return $value;
    }

    /**
     * A vector "per segment" read from an input shape: a list of sixteen numbers, returned as floats. A
     * missing list, a short list, a map or an element that is not a number is a TypeError.
     *
     * @return list<float>
     */
    public static function perSegment(mixed $list): array
    {
        if (!is_array($list) || !array_is_list($list) || count($list) < Vocab::NSEG) {
            throw new \TypeError('a list of 16 numbers is required');
        }
        $out = self::reals(...$list);         // the typed variadic checks and widens every element
        return count($out) === Vocab::NSEG ? $out : array_slice($out, 0, Vocab::NSEG);
    }

    /**
     * @return list<float>
     */
    private static function reals(float ...$values): array
    {
        return $values;
    }

    /** floor(a / b) for integers, b > 0, without reals: rounds toward minus infinity. */
    public static function floorDiv(int $a, int $b): int
    {
        $r = $a % $b;                         // takes the sign of the dividend
        $q = ($a - $r) / $b;                  // exact, so the result is an integer
        if ($r !== 0 && ($r < 0) !== ($b < 0)) {
            $q -= 1;
        }
        return (int) $q;
    }

    /** a - b * floorDiv(a, b): always in 0 .. b-1. */
    public static function modFloor(int $a, int $b): int
    {
        return $a - $b * self::floorDiv($a, $b);
    }

    /** lo if x < lo, hi if x > hi, else x. */
    public static function clamp(float $x, float $lo, float $hi): float
    {
        if ($x < $lo) {
            return $lo;
        }
        if ($x > $hi) {
            return $hi;
        }
        return $x;
    }

    /**
     * The only rounding helper: half away from zero, with a 1e-9 nudge so that decimal halves binary cannot
     * represent (2.675) round the way a person expects. Never returns negative zero.
     */
    public static function roundHalfAway(float $x, int $decimals): float
    {
        if ($decimals < 0 || $decimals > 9) {
            throw new \OutOfRangeException('decimals must be 0..9');
        }
        $p = self::POW10[$decimals];
        $a = abs($x) * $p;
        $n = floor($a + 0.500000001);
        $r = $n / $p;
        if ($x < 0 && $r != 0) {
            return -$r;
        }
        return $r;
    }

    /**
     * Ranking key in whole millionths, as a real. Orderings and ties are decided on this value; it is
     * integral, so comparing it is exact and it never leaves the range of a real.
     */
    public static function rank(float $x): float
    {
        return floor($x * 1000000.0 + 0.5);
    }

    /** Ranking key in whole millionths, as an integer. */
    public static function qkey(float $x): int
    {
        return self::toInt(self::rank($x));
    }

    /** An integral real as an integer; refuses what an integer cannot hold. */
    public static function toInt(float $whole): int
    {
        if (!($whole > -self::INT_LIMIT && $whole < self::INT_LIMIT)) {      // also false for NAN
            throw new ModelError(ModelError::NON_FINITE, ['reason' => 'outside the integer range']);
        }
        return (int) $whole;
    }
}
