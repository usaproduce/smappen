<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

/**
 * Response hygiene and the canonical text used for hashes.
 *
 * clean() makes a payload safe to encode: PHP's json_encode gives up on INF, NAN and invalid UTF-8, and
 * the house Response class would then send an empty 200. A value that cannot travel becomes null and is
 * logged with its path. PHP also prints an empty map as [], so the dotted paths of the payload's maps are
 * named by the caller and are sent as JSON objects.
 */
final class JsonSafe
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param array<int|string, mixed> $data the `data` of a response
     * @param list<string> $mapPaths dotted paths of maps, from the root of `$data`; `*` matches every key
     *                               of one level (the items of a list): "calibration.spots",
     *                               "plan.result.warnings.*.data"
     * @return array<int|string, mixed>
     */
    public static function clean(array $data, array $mapPaths = []): array
    {
        $patterns = [];
        foreach ($mapPaths as $path) {
            $patterns[] = explode('.', $path);
        }
        /** @var array<int|string, mixed> $cleaned */
        $cleaned = self::walk($data, [], $patterns, true);
        return $cleaned;
    }

    /**
     * One text for one value: keys sorted byte-wise at every level, lists in their order, numbers in their
     * shortest form that reads back the same, no spaces. Two arrays that differ only in key order, or only
     * in 66 against 66.0, give the same text. Used for cache keys and basis hashes.
     *
     * @param array<int|string, mixed> $data
     */
    public static function canonical(array $data): string
    {
        self::shortestFloats();
        return json_encode(self::sorted($data), self::FLAGS | JSON_THROW_ON_ERROR);
    }

    /**
     * The shortest decimal text that reads back as the same double: "0.1", "400", "1.0e-7". This is how a
     * float is bound into SQL (Sql::f) and printed in a validation message. A non-finite value has no such
     * text and is refused.
     */
    public static function float(float $x): string
    {
        if (!is_finite($x)) {
            throw new \DomainException('a non-finite number cannot be written');
        }
        self::shortestFloats();
        return (string) json_encode($x);
    }

    /**
     * json_encode prints floats with the `serialize_precision` setting. -1 is PHP's default and means the
     * shortest text that round-trips; any other value would lose or pad digits, so it is set for the process.
     */
    public static function shortestFloats(): void
    {
        if (ini_get('serialize_precision') !== '-1') {
            ini_set('serialize_precision', '-1');
        }
    }

    /**
     * @param list<string> $path
     * @param list<list<string>> $patterns
     */
    private static function walk(mixed $value, array $path, array $patterns, bool $root = false): mixed
    {
        if (is_float($value)) {
            if (!is_finite($value)) {
                error_log('[tp] non-finite at ' . Redactor::text(implode('.', $path)));
                return null;
            }
            return $value;
        }
        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                error_log('[tp] invalid text at ' . Redactor::text(implode('.', $path)));
                return null;
            }
            return $value;
        }
        if ($value instanceof \stdClass) {
            $out = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $out->{$key} = self::walk($item, array_merge($path, [(string) $key]), $patterns);
            }
            return $out;
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::walk($item, array_merge($path, [(string) $key]), $patterns);
        }
        if (!$root && self::isMap($path, $patterns)) {
            return (object) $out;
        }
        return $out;
    }

    /**
     * @param list<string> $path
     * @param list<list<string>> $patterns
     */
    private static function isMap(array $path, array $patterns): bool
    {
        $depth = count($path);
        foreach ($patterns as $pattern) {
            if (count($pattern) !== $depth) {
                continue;
            }
            $hit = true;
            foreach ($pattern as $i => $segment) {
                if ($segment !== '*' && $segment !== $path[$i]) {
                    $hit = false;
                    break;
                }
            }
            if ($hit) {
                return true;
            }
        }
        return false;
    }

    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
            if ($value === []) {
                return new \stdClass();
            }
        }
        if (!is_array($value)) {
            return $value;
        }
        $list = array_is_list($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::sorted($item);
        }
        if (!$list) {
            ksort($out, SORT_STRING);
        }
        return $out;
    }
}
