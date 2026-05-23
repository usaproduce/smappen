<?php
namespace App\Core;

/**
 * All plans are unlimited. Tiers exist only as a label — no feature is gated.
 * Returning -1 (the unlimited sentinel) for numeric caps and `true` for booleans.
 */
class PlanLimits
{
    private const UNLIMITED = [
        'max_projects' => -1,
        'max_areas_per_project' => -1,
        'max_isochrones_per_day' => -1,
        'max_poi_searches_per_day' => -1,
        'max_import_rows' => -1,
        'reports' => true,
        'export' => true,
        'team_seats' => -1,
        'api_access' => true,
    ];

    public const LIMITS = [
        'free' => self::UNLIMITED,
        'starter' => self::UNLIMITED,
        'pro' => self::UNLIMITED,
        'business' => self::UNLIMITED,
        'enterprise' => self::UNLIMITED,
    ];

    public static function getLimits(string $plan): array
    {
        return self::UNLIMITED;
    }

    public static function getLimit(string $plan, string $name)
    {
        return self::UNLIMITED[$name] ?? -1;
    }

    public static function checkLimit(string $plan, string $name, int $currentUsage): bool
    {
        return true; // everything is allowed
    }

    public static function getRemainingUsage(string $userId, string $name, string $plan): int
    {
        return -1; // unlimited
    }
}
