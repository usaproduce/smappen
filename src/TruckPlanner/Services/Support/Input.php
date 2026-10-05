<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

use App\TruckPlanner\Model\Estimator;

/**
 * Request validation with the fixed messages V1 to V12 of 04_BACKEND.md 4.2.
 *
 * An Input wraps one JSON object (a body, or an object inside it) and knows the dotted path of that object,
 * so a message names its field in full: "stops[1].open_minute must be a whole number between 0 and 2880".
 * Every failure is a TpInvalid that carries the field path and the message id; the first failure wins.
 *
 * Reading a field: each getter returns the validated value, or null when the key is absent or holds JSON
 * null. With `$required` an absent key or a null is V1. For a field that may be set to null in an update,
 * ask has() first: has() and a null answer together mean "the caller sent null".
 *
 * A body is strict JSON: a numeric string is not a number and "true" is not a boolean. Query parameters
 * are strings, so Input::query() reads whole numbers from digits, flags from "1" and "0", and dates and
 * text as they are. Unknown keys are ignored.
 */
final class Input
{
    /** @var array<int|string, mixed> */
    private array $data;
    private string $prefix;
    private bool $list = false;
    private bool $strings = false;

    /**
     * @param array<int|string, mixed> $data a decoded JSON object
     * @param string $prefix the path of that object with its trailing dot ("terms."), empty for the body
     */
    public function __construct(array $data, string $prefix = '')
    {
        $this->data = $data;
        $this->prefix = $prefix;
    }

    /**
     * The query string of a request (Request::getQuery()).
     *
     * @param array<int|string, mixed> $query
     */
    public static function query(array $query): self
    {
        $input = new self($query);
        $input->strings = true;
        return $input;
    }

    public function has(int|string $k): bool
    {
        return array_key_exists($k, $this->data);
    }

    /** True when the key is present and holds JSON null. */
    public function isNull(int|string $k): bool
    {
        return array_key_exists($k, $this->data) && $this->data[$k] === null;
    }

    /**
     * The object as it was received.
     *
     * @return array<int|string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /** The dotted path of a field of this object: "terms.fee_pct", "stops[1]", "points[0].id". */
    public function path(int|string $k): string
    {
        return $this->list ? $this->prefix . '[' . $k . ']' : $this->prefix . $k;
    }

    /** Text, trimmed. V5 when it is not a string or is longer than `$max` characters after trimming. */
    public function str(int|string $k, int $max, bool $required = false): ?string
    {
        $v = $this->take($k, $required);
        if ($v === null) {
            return null;
        }
        if (is_string($v) && mb_check_encoding($v, 'UTF-8')) {
            $text = trim($v);
            if (mb_strlen($text, 'UTF-8') <= $max) {
                return $text;
            }
        }
        throw $this->error($k, 'must be text of at most ' . $max . ' characters', 'V5');
    }

    /** A JSON number within [min, max]. V2 otherwise. */
    public function num(int|string $k, float $min, float $max, bool $required = false): ?float
    {
        $v = $this->take($k, $required);
        if ($v === null) {
            return null;
        }
        $x = null;
        if (is_int($v) || is_float($v)) {
            $x = (float) $v;
        } elseif ($this->strings && is_string($v) && preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $v) === 1) {
            $x = (float) $v;
        }
        if ($x !== null && is_finite($x) && $x >= $min && $x <= $max) {
            return $x;
        }
        throw $this->error(
            $k,
            'must be a number between ' . JsonSafe::float($min) . ' and ' . JsonSafe::float($max),
            'V2'
        );
    }

    /** An integer-valued JSON number within [min, max] (45 and 45.0 are both 45). V3 otherwise. */
    public function int(int|string $k, int $min, int $max, bool $required = false): ?int
    {
        $v = $this->take($k, $required);
        if ($v === null) {
            return null;
        }
        $n = null;
        if (is_int($v)) {
            $n = $v;
        } elseif (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) <= 9.0e15) {
            $n = (int) $v;
        } elseif ($this->strings && is_string($v) && strlen($v) <= 16 && preg_match('/^-?[0-9]+$/', $v) === 1) {
            $n = (int) $v;
        }
        if ($n !== null && $n >= $min && $n <= $max) {
            return $n;
        }
        throw $this->error($k, 'must be a whole number between ' . $min . ' and ' . $max, 'V3');
    }

    /** A JSON boolean (in a query string: "1" or "0"). V6 otherwise. */
    public function bool(int|string $k, bool $required = false): ?bool
    {
        $v = $this->take($k, $required);
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v;
        }
        if ($this->strings && ($v === '1' || $v === '0')) {
            return $v === '1';
        }
        throw $this->error($k, 'must be true or false', 'V6');
    }

    /**
     * One of the listed strings. V4 otherwise.
     *
     * @param list<string> $allowed
     */
    public function enum(int|string $k, array $allowed, bool $required = false): ?string
    {
        $v = $this->take($k, $required);
        if ($v === null) {
            return null;
        }
        if (is_string($v) && in_array($v, $allowed, true)) {
            return $v;
        }
        throw $this->error($k, 'must be one of: ' . implode(', ', $allowed), 'V4');
    }

    /** A civil date "YYYY-MM-DD" the model accepts (1970 to 2199). V7 otherwise. */
    public function date(int|string $k, bool $required = false): ?string
    {
        $v = $this->take($k, $required);
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            try {
                Estimator::parseDate($v);
                return $v;
            } catch (\InvalidArgumentException $e) {
                // the model's invalid_date: reported below
            }
        }
        throw $this->error($k, 'must be a date in the form YYYY-MM-DD', 'V7');
    }

    /**
     * A point object {lat, lng} with both numbers in range. V10 otherwise.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function point(int|string $k, bool $required = false): ?array
    {
        $v = $this->take($k, $required);
        if ($v === null) {
            return null;
        }
        if (is_array($v) && array_key_exists('lat', $v) && array_key_exists('lng', $v)) {
            $lat = $this->coordinate($v['lat']);
            $lng = $this->coordinate($v['lng']);
            if ($lat !== null && $lng !== null && $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0) {
                return ['lat' => $lat, 'lng' => $lng];
            }
        }
        throw $this->error($k, 'must have lat between -90 and 90 and lng between -180 and 180', 'V10');
    }

    /** A JSON object, as an Input that knows its path. V9 otherwise. */
    public function obj(int|string $k, bool $required = false): ?Input
    {
        $v = $this->take($k, $required);
        if ($v === null) {
            return null;
        }
        if (is_array($v) && ($v === [] || !array_is_list($v))) {
            $child = new self($v, $this->path($k) . '.');
            $child->strings = $this->strings;
            return $child;
        }
        throw $this->error($k, 'must be an object', 'V9');
    }

    /**
     * A JSON list of `$min` to `$max` items, as received. V8 otherwise. Validate the items with each().
     *
     * @return list<mixed>|null
     */
    public function items(int|string $k, int $min, int $max, bool $required = false): ?array
    {
        $v = $this->take($k, $required);
        if ($v === null) {
            return null;
        }
        if (is_array($v) && array_is_list($v) && count($v) >= $min && count($v) <= $max) {
            return $v;
        }
        throw $this->error($k, 'must be a list of ' . $min . ' to ' . $max . ' items', 'V8');
    }

    /**
     * The list at `$k` as an Input whose keys are the indexes, so that items are read with the same getters
     * and their paths print as "stops[1]": `$in->each('stops')->obj(1, true)->int('open_minute', 0, 2880, true)`,
     * `$in->each('licence_counties')->str(0, 5, true)`. Call items() first: it is the check that `$k` is a
     * list of the right length. Anything that is not a list reads as an empty one here.
     */
    public function each(int|string $k): Input
    {
        $v = $this->data[$k] ?? null;
        $child = new self(is_array($v) && array_is_list($v) ? $v : [], $this->path($k));
        $child->list = true;
        $child->strings = $this->strings;
        return $child;
    }

    /**
     * A 422 about one field of this object, in the house form "<path> <rest>": the way to raise the
     * messages of 4.2 that are not V1 to V10, such as "stops[1].close_minute must be after open_minute".
     */
    public function error(int|string $k, string $rest, ?string $code = null): TpInvalid
    {
        $path = $this->path($k);
        return new TpInvalid($path . ' ' . $rest, $path, $code);
    }

    /** V11: an id or key in the request that does not exist for this organization or region. */
    public function notFound(int|string $k): TpInvalid
    {
        return $this->error($k, 'was not found', 'V11');
    }

    /** V12: a PUT body that carries none of the keys it could change. */
    public static function nothingToUpdate(): TpInvalid
    {
        return new TpInvalid('Nothing to update', null, 'V12');
    }

    /**
     * Raises V12 unless at least one of the keys is present.
     *
     * @param list<string> $keys
     */
    public function requireAny(array $keys): void
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->data)) {
                return;
            }
        }
        throw self::nothingToUpdate();
    }

    /** The raw value, or null when the key is absent or null. V1 for those two cases when required. */
    private function take(int|string $k, bool $required): mixed
    {
        $v = $this->data[$k] ?? null;
        if ($v === null && $required) {
            throw $this->error($k, 'is required', 'V1');
        }
        return $v;
    }

    private function coordinate(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            $x = (float) $v;
            return is_finite($x) ? $x : null;
        }
        if ($this->strings && is_string($v) && preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $v) === 1) {
            return (float) $v;
        }
        return null;
    }
}
