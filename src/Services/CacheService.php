<?php
namespace App\Services;

use App\Core\Database;

class CacheService
{
    public static function get(string $key): ?string
    {
        // Random cleanup
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
        Database::getInstance()->delete('cache', '`key` = :k', [':k' => $key]);
    }

    public static function flush(string $prefix = ''): void
    {
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
