<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

/**
 * Reads config/truck_planner.php (04_BACKEND.md 7.1) once per process.
 *
 * TpConfig::get('routing.timeout_s') walks the array by a dotted path. A path that does not exist is a
 * programming error and raises, so a misspelt setting never turns into a silent null.
 */
final class TpConfig
{
    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$config === null) {
            /** @var array<string, mixed> $loaded */
            $loaded = require dirname(__DIR__, 4) . '/config/truck_planner.php';
            self::$config = $loaded;
        }
        return self::$config;
    }

    public static function get(string $path): mixed
    {
        $node = self::all();
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                throw new \LogicException('unknown Truck Planner setting: ' . $path);
            }
            $node = $node[$key];
        }
        return $node;
    }

    /**
     * Use another settings array (tests). Null reads the file again on the next call.
     *
     * @param array<string, mixed>|null $config
     */
    public static function replace(?array $config): void
    {
        self::$config = $config;
    }
}
