<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\TruckPlanner\Services\Support\JsonSafe;

/**
 * SQL value helpers for the Truck Planner repositories.
 *
 * PDO binds a PHP float with 14 significant digits and a PHP false as an empty string, and it cannot bind
 * an array at all. So: a float is bound as f($x), a boolean as b($v), a JSON column as json($v), and an
 * IN list is written with marks($n).
 */
final class Sql
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * A float as the shortest text that reads back as the same double ("38.96", "400", "1.0e-7").
     * A non-finite value is refused here, so nothing of the kind reaches MySQL.
     */
    public static function f(float $x): string
    {
        return JsonSafe::float($x);
    }

    /** 1 or 0. */
    public static function b(bool $v): int
    {
        return $v ? 1 : 0;
    }

    /**
     * The text of a JSON column. With `$asObject` the value is a map and is written as a JSON object even
     * when it is empty ("{}"); without it an empty array is "[]". Floats keep every digit.
     *
     * @param array<int|string, mixed> $v
     */
    public static function json(array $v, bool $asObject = false): string
    {
        JsonSafe::shortestFloats();
        return json_encode($asObject ? (object) $v : $v, self::JSON_FLAGS);
    }

    /** "?, ?, ?" for an IN list of `$n` values. */
    public static function marks(int $n): string
    {
        if ($n < 1) {
            throw new \LengthException('an IN list needs at least one value');
        }
        return implode(', ', array_fill(0, $n, '?'));
    }
}
