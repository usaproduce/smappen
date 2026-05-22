<?php
namespace App\Core;

use Dotenv\Dotenv;

class Config
{
    private static bool $loaded = false;

    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }
        if (file_exists($basePath . '/.env')) {
            $dotenv = Dotenv::createImmutable($basePath);
            $dotenv->safeLoad();
        }
        self::$loaded = true;
    }

    public static function get(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    public static function isDevelopment(): bool
    {
        return self::get('APP_ENV', 'production') === 'development';
    }
}
