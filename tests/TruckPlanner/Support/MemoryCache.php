<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Support;

use App\TruckPlanner\Services\Support\TpCacheStore;

/**
 * An in-memory store for TpCache, so that code which caches can be tested without a database.
 *
 *     $clock = new FixedClock();
 *     $cache = new MemoryCache();
 *     TpCache::wire($clock, $cache);          // in setUp()
 *     TpCache::wire();                        // in tearDown(): the real clock and store again
 *
 * It keeps what it is given and never expires anything by itself: TpCache decides age from its own
 * envelope. `$ttls` shows what lifetime the store was asked for.
 */
final class MemoryCache implements TpCacheStore
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var array<string, int> the lifetime asked for at the last set() of each key */
    public array $ttls = [];

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->values[$key] = $value;
        $this->ttls[$key] = $ttlSeconds;
    }

    public function flush(string $prefix): void
    {
        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->values[$key], $this->ttls[$key]);
            }
        }
    }
}
