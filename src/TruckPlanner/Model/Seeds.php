<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * The seed assumptions and the Assumptions record built around them (02_MODEL.md section 2).
 *
 * SeedsData.php is the generated copy of docs/truck-planner/reference/tp_seeds.json; it is loaded once per
 * process and never modified. An Assumptions record is
 *
 *     { model_version, seeds_revision, seeds: <that array>, overrides: { <seed path>: <value> },
 *       region: { id, traffic_matrix, flags: { inauguration_day } } }
 *
 * and is what every model function takes as `A`.
 */
final class Seeds
{
    /** The region of a point outside every loaded region. */
    public const REGION_NONE = ['id' => 'none', 'traffic_matrix' => 'us_mean', 'flags' => ['inauguration_day' => false]];

    private const INHERITED_KEYS = ['scope', 'min', 'max', 'allowed'];

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * The seed file as an array (the content of SeedsData.php), loaded once.
     *
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        if (self::$data === null) {
            /** @var array<string, mixed> $loaded */
            $loaded = require __DIR__ . '/SeedsData.php';
            self::$data = $loaded;
        }
        return self::$data;
    }

    public static function revision(): int
    {
        return Num::i(self::data()['seeds_revision']);
    }

    /**
     * The Assumptions record with no overrides, for region `none`. Code that reads only build- or
     * fixed-scope seeds (capture, the region loader, fallback legs) uses this as its `A`; set `region` on the
     * copy when a region applies.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return self::assumptions([], null);
    }

    /**
     * The Assumptions record with the owner's sparse overrides, after validating them (2.2). Invalid
     * overrides are never clamped or dropped: a ModelError `invalid_overrides` is raised and its details()
     * hold the list of { path, error } that validate() returns.
     *
     * @param array<string, mixed> $overrides seed path => value
     * @param array<string, mixed>|null $region { id, traffic_matrix, flags }; null means region `none`
     * @return array<string, mixed>
     */
    public static function withOverrides(array $overrides, ?array $region = null): array
    {
        $problems = self::validate(self::data(), $overrides);
        if ($problems !== []) {
            throw new ModelError(ModelError::INVALID_OVERRIDES, $problems);
        }
        return self::assumptions($overrides, $region);
    }

    /**
     * An Assumptions record as given, without validation: for overrides that are already known to be valid
     * and for the golden cases, whose `A` arrives as { overrides, region }.
     *
     * @param array<string, mixed> $overrides
     * @param array<string, mixed>|null $region
     * @return array<string, mixed>
     */
    public static function assumptions(array $overrides = [], ?array $region = null): array
    {
        $seeds = self::data();
        return [
            'model_version' => Vocab::MODEL_VERSION,
            'seeds_revision' => $seeds['seeds_revision'],
            'seeds' => $seeds,
            'overrides' => $overrides,
            'region' => $region ?? self::REGION_NONE,
        ];
    }

    /**
     * seed(A, path): read a seed by its dot-separated path. An override under exactly that path wins.
     *
     * @param array<string, mixed> $A
     */
    public static function read(array $A, string $path): mixed
    {
        $overrides = $A['overrides'];
        if ($overrides !== [] && array_key_exists($path, $overrides)) {
            return $overrides[$path];
        }
        $node = $A['seeds'];
        foreach (explode('.', $path) as $key) {
            // A path walks objects only: "3" is not a key of an array of 24 numbers, although PHP would
            // find index 3 there.
            if (!self::isObject($node) || !array_key_exists($key, $node)) {
                throw new \OutOfBoundsException('unknown seed path: ' . $path);      // a defect of the caller
            }
            $node = $node[$key];
        }
        if (is_array($node) && array_key_exists('value', $node)) {
            return $node['value'];
        }
        return $node;
    }

    /**
     * validate_overrides(seeds, overrides): the problems of a sparse override map, one per offending path
     * in ascending path order, each with the first error the steps of 2.2 find. Empty = valid.
     *
     * @param array<string, mixed> $seeds the seed file as an array (Seeds::data())
     * @param array<int|string, mixed> $overrides
     * @return list<array{path: string, error: string}>
     */
    public static function validate(array $seeds, array $overrides): array
    {
        $paths = [];
        foreach ($overrides as $path => $unused) {
            $paths[] = (string) $path;
        }
        usort($paths, static fn (string $a, string $b): int => strcmp($a, $b));
        $problems = [];
        foreach ($paths as $path) {
            $error = self::overrideError($seeds, $path, $overrides[$path]);
            if ($error !== null) {
                $problems[] = ['path' => $path, 'error' => $error];
            }
        }
        return $problems;
    }

    /**
     * @param array<string, mixed> $seeds
     */
    private static function overrideError(array $seeds, string $path, mixed $value): ?string
    {
        $keys = explode('.', $path);
        $node = $seeds;
        $inherited = [];                      // scope, min, max, allowed of the nearest enclosing object
        foreach ($keys as $key) {
            if (!self::isObject($node) || !array_key_exists($key, $node)) {
                return 'unknown_path';                                               // step 1
            }
            foreach (self::INHERITED_KEYS as $name) {
                if (array_key_exists($name, $node)) {
                    $inherited[$name] = $node[$name];
                }
            }
            $node = $node[$key];
        }
        if (self::isObject($node)) {
            foreach (self::INHERITED_KEYS as $name) {
                if (array_key_exists($name, $node)) {
                    $inherited[$name] = $node[$name];
                }
            }
        }
        if (in_array($keys[count($keys) - 1], $seeds['vocabulary']['structural_keys'], true)) {
            return 'not_a_seed';                                                     // step 2
        }
        if (($inherited['scope'] ?? null) !== 'owner') {
            return 'not_overridable';                                                // step 3
        }
        $target = self::isObject($node) && array_key_exists('value', $node) ? $node['value'] : $node;
        if (self::isObject($target)) {
            return 'not_a_leaf';                                                     // step 4
        }
        if (!self::sameShape($value, $target)) {
            return 'wrong_shape';                                                    // step 5
        }
        $items = is_array($value) ? $value : [$value];
        foreach ($items as $x) {                                                     // step 6
            if (is_int($x) || is_float($x)) {
                // As reals on both sides. PHP compares two integers exactly and an integer with a real as
                // reals, so beyond 2^53 the answer would otherwise hang on how the seed file spells a bound.
                $real = (float) $x;
                if (array_key_exists('min', $inherited) && $real < Num::f($inherited['min'])) {
                    return 'out_of_bounds';
                }
                if (array_key_exists('max', $inherited) && $real > Num::f($inherited['max'])) {
                    return 'out_of_bounds';
                }
            }
        }
        foreach ($items as $x) {                                                     // step 7
            if (is_string($x) && array_key_exists('allowed', $inherited) && !in_array($x, $inherited['allowed'], true)) {
                return 'not_allowed';
            }
        }
        return null;
    }

    /**
     * Is this node of the seed tree a JSON object? Objects of the seed file never have numeric keys (the
     * generator refuses them) and are never empty (the seed-sync test asserts it), so a list test decides.
     */
    private static function isObject(mixed $node): bool
    {
        return is_array($node) && !array_is_list($node);
    }

    /**
     * Step 5: number for number (finite; an integer is a number, a boolean is not), string for string,
     * array of the same length whose elements have the type of the seed's.
     */
    private static function sameShape(mixed $value, mixed $target): bool
    {
        if (is_int($target) || is_float($target)) {
            return is_int($value) || (is_float($value) && is_finite($value));
        }
        if (is_string($target)) {
            return is_string($value);
        }
        if (is_bool($target)) {
            return is_bool($value);
        }
        if (is_array($target)) {
            if (!is_array($value) || !array_is_list($value) || count($value) !== count($target)) {
                return false;
            }
            foreach ($target as $k => $element) {
                if (is_array($element) || !self::sameShape($value[$k], $element)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }
}
