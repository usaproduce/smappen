<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

use App\Services\CacheService;

/**
 * The only Truck Planner class that talks to the house CacheService. Every `tp:` cache key goes through it.
 *
 * Why not CacheService directly: it stamps `expires_at` with PHP's clock and compares it with MySQL NOW(),
 * so a time-zone difference between the two moves every expiry by hours. TpCache therefore stores an
 * envelope {"exp": <UTC epoch>, "v": <value>} and decides the age itself with the epoch from Clock. The
 * store only has to keep the row at least that long, so it is asked for the TTL plus 26 hours: enough
 * under any pair of zone offsets.
 *
 * Values are arrays. A stored empty array is a hit ([]), a miss is null: test with `!== null`. Numbers
 * come back with the type they went in with.
 */
final class TpCache
{
    /** 26 hours: the widest difference between two time-zone offsets. */
    private const PAD_SECONDS = 93600;
    private const PREFIX = 'tp:';
    private const MAX_KEY_BYTES = 255;
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private static ?Clock $clock = null;
    private static ?TpCacheStore $store = null;

    /**
     * @param array<int|string, mixed> $value
     */
    public static function put(string $key, array $value, int $ttlSeconds): void
    {
        self::checkKey($key);
        JsonSafe::shortestFloats();
        $json = json_encode(['exp' => self::clock()->epoch() + $ttlSeconds, 'v' => $value], self::FLAGS);
        if ($json === false) {
            error_log('[tp] cache value not stored: ' . Redactor::text($key));
            return;
        }
        self::store()->set($key, $json, $ttlSeconds + self::PAD_SECONDS);
    }

    /**
     * @return array<int|string, mixed>|null the value while it is younger than its TTL, else null
     */
    public static function get(string $key): ?array
    {
        self::checkKey($key);
        $raw = self::store()->get($key);
        if ($raw === null) {
            return null;
        }
        $envelope = json_decode($raw, true);
        if (!is_array($envelope) || !is_int($envelope['exp'] ?? null) || !is_array($envelope['v'] ?? null)) {
            return null;
        }
        if ($envelope['exp'] <= self::clock()->epoch()) {
            return null;
        }
        return $envelope['v'];
    }

    /**
     * A counter: read, add, write, and return the new total. The entry lives `$ttlSeconds` from this call.
     * Not atomic: two requests at the same moment can lose one addition, so a budget counted this way is
     * approximate.
     */
    public static function add(string $key, int $n, int $ttlSeconds): int
    {
        $current = self::get($key);
        $total = (is_int($current['n'] ?? null) ? $current['n'] : 0) + $n;
        self::put($key, ['n' => $total], $ttlSeconds);
        return $total;
    }

    /** The present value of a counter kept with add(), 0 when there is none. */
    public static function count(string $key): int
    {
        $current = self::get($key);
        return is_int($current['n'] ?? null) ? $current['n'] : 0;
    }

    /**
     * Forget one entry, and only that one: forgetPrefix() would also reach every key that merely starts
     * with the same text. The entry is replaced by one that has expired already, so it reads as a miss at
     * once, whatever the store still holds.
     */
    public static function forget(string $key): void
    {
        self::put($key, [], 0);
    }

    /** Forget every entry whose key starts with `$prefix` (which must itself start with "tp:"). */
    public static function forgetPrefix(string $prefix): void
    {
        if (strlen($prefix) <= strlen(self::PREFIX) || !str_starts_with($prefix, self::PREFIX)) {
            throw new \LogicException('TpCache::forgetPrefix needs a prefix inside tp:');
        }
        self::store()->flush($prefix);
    }

    /**
     * Replace the clock and the store (tests). Null puts the real one back.
     */
    public static function wire(?Clock $clock = null, ?TpCacheStore $store = null): void
    {
        self::$clock = $clock;
        self::$store = $store;
    }

    private static function checkKey(string $key): void
    {
        if (!str_starts_with($key, self::PREFIX) || strlen($key) > self::MAX_KEY_BYTES) {
            throw new \LogicException('TpCache keys start with tp: and are at most 255 bytes long');
        }
    }

    private static function clock(): Clock
    {
        return self::$clock ??= new Clock();
    }

    private static function store(): TpCacheStore
    {
        return self::$store ??= new class implements TpCacheStore {
            public function get(string $key): ?string
            {
                return CacheService::get($key);
            }

            public function set(string $key, string $value, int $ttlSeconds): void
            {
                CacheService::set($key, $value, $ttlSeconds);
            }

            public function flush(string $prefix): void
            {
                CacheService::flush($prefix);
            }
        };
    }
}
