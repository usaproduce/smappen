<?php
namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Cache facade. Picks the backend on first call:
 *   REDIS_URL set → redis (via phpredis if loaded, otherwise a TCP fallback)
 *   otherwise    → MySQL `cache` table
 *
 * Same interface for both. The Redis driver is a no-op fallback if the
 * connection fails so a redis outage doesn't take the app down — cache misses
 * just mean upstream APIs (Census, ORS, Google) get hit more often.
 */
class CacheService
{
    private static ?\Redis $redis = null;
    private static ?bool $redisAttempted = null;

    private static function tryRedis(): ?\Redis
    {
        if (self::$redisAttempted !== null) return self::$redis;
        self::$redisAttempted = true;
        $url = (string) Config::get('REDIS_URL', '');
        if ($url === '' || !class_exists(\Redis::class)) return null;
        try {
            $parts = parse_url($url) ?: [];
            $host = $parts['host'] ?? '127.0.0.1';
            $port = (int)($parts['port'] ?? 6379);
            $r = new \Redis();
            if (!$r->connect($host, $port, 1.5)) return null;
            if (!empty($parts['pass'])) $r->auth($parts['pass']);
            self::$redis = $r;
            return self::$redis;
        } catch (\Throwable $e) {
            error_log('CacheService redis init failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function get(string $key): ?string
    {
        if ($r = self::tryRedis()) {
            try { $v = $r->get($key); return $v === false ? null : (string)$v; }
            catch (\Throwable $e) {}
        }
        if (random_int(1, 100) === 1) self::cleanup();
        $row = Database::getInstance()->fetch(
            'SELECT `value` FROM cache WHERE `key` = ? AND (expires_at IS NULL OR expires_at > NOW())',
            [$key]
        );
        return $row['value'] ?? null;
    }

    public static function getJson(string $key): ?array
    {
        $raw = self::get($key);
        if ($raw === null) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function set(string $key, $value, ?int $ttlSeconds = null): void
    {
        $val = is_string($value) ? $value : json_encode($value);
        if ($r = self::tryRedis()) {
            try {
                $ttlSeconds === null ? $r->set($key, $val) : $r->setex($key, $ttlSeconds, $val);
                return;
            } catch (\Throwable $e) {}
        }
        $expires = $ttlSeconds === null ? null : date('Y-m-d H:i:s', time() + $ttlSeconds);
        $sql = 'INSERT INTO cache (`key`,`value`,expires_at) VALUES (:k,:v,:e)
                ON DUPLICATE KEY UPDATE `value`=:v2, expires_at=:e2';
        Database::getInstance()->query($sql, [
            ':k' => $key, ':v' => $val, ':e' => $expires,
            ':v2' => $val, ':e2' => $expires,
        ]);
    }

    public static function delete(string $key): void
    {
        if ($r = self::tryRedis()) {
            try { $r->del($key); } catch (\Throwable $e) {}
        }
        Database::getInstance()->delete('cache', '`key` = :k', [':k' => $key]);
    }

    public static function flush(string $prefix = ''): void
    {
        if ($r = self::tryRedis()) {
            try {
                if ($prefix === '') $r->flushDB();
                else {
                    $it = null;
                    while ($keys = $r->scan($it, $prefix . '*', 500)) {
                        foreach ($keys as $k) $r->del($k);
                    }
                }
            } catch (\Throwable $e) {}
        }
        if ($prefix === '') {
            Database::getInstance()->pdo()->exec('DELETE FROM cache');
        } else {
            Database::getInstance()->delete('cache', '`key` LIKE :p', [':p' => $prefix . '%']);
        }
    }

    public static function cleanup(): void
    {
        Database::getInstance()->pdo()->exec('DELETE FROM cache WHERE expires_at IS NOT NULL AND expires_at < NOW()');
    }
}
