<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

/**
 * What TpCache needs from a key-value store. In production the store is the house CacheService (the MySQL
 * `cache` table); the adapter lives inside TpCache.php. Tests pass an in-memory store to TpCache::wire().
 */
interface TpCacheStore
{
    /** The stored text, or null when the store holds nothing (or nothing it still considers alive). */
    public function get(string $key): ?string;

    /** Keep `$value` under `$key`; the store may forget it after `$ttlSeconds`. */
    public function set(string $key, string $value, int $ttlSeconds): void;

    /** Forget every key that starts with `$prefix`. */
    public function flush(string $prefix): void;
}
