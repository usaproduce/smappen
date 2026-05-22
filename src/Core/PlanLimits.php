<?php
namespace App\Core;

class PlanLimits
{
    public const LIMITS = [
        'free' => [
            'max_projects' => 1,
            'max_areas_per_project' => 3,
            'max_isochrones_per_day' => 5,
            'max_poi_searches_per_day' => 5,
            'max_import_rows' => 10,
            'reports' => false,
            'export' => false,
            'team_seats' => 1,
            'api_access' => false,
        ],
        'starter' => [
            'max_projects' => 5,
            'max_areas_per_project' => 25,
            'max_isochrones_per_day' => 50,
            'max_poi_searches_per_day' => 50,
            'max_import_rows' => 100,
            'reports' => true,
            'export' => true,
            'team_seats' => 1,
            'api_access' => false,
        ],
        'pro' => [
            'max_projects' => -1,
            'max_areas_per_project' => -1,
            'max_isochrones_per_day' => -1,
            'max_poi_searches_per_day' => -1,
            'max_import_rows' => 500,
            'reports' => true,
            'export' => true,
            'team_seats' => 3,
            'api_access' => false,
        ],
        'business' => [
            'max_projects' => -1,
            'max_areas_per_project' => -1,
            'max_isochrones_per_day' => -1,
            'max_poi_searches_per_day' => -1,
            'max_import_rows' => 2000,
            'reports' => true,
            'export' => true,
            'team_seats' => 10,
            'api_access' => true,
        ],
        'enterprise' => [
            'max_projects' => -1,
            'max_areas_per_project' => -1,
            'max_isochrones_per_day' => -1,
            'max_poi_searches_per_day' => -1,
            'max_import_rows' => -1,
            'reports' => true,
            'export' => true,
            'team_seats' => -1,
            'api_access' => true,
        ],
    ];

    public static function getLimits(string $plan): array
    {
        return self::LIMITS[$plan] ?? self::LIMITS['free'];
    }

    public static function getLimit(string $plan, string $name)
    {
        $limits = self::getLimits($plan);
        return $limits[$name] ?? 0;
    }

    public static function checkLimit(string $plan, string $name, int $currentUsage): bool
    {
        $limit = self::getLimit($plan, $name);
        if ($limit === -1) return true; // unlimited
        if (is_bool($limit)) return $limit;
        return $currentUsage < (int)$limit;
    }

    public static function getRemainingUsage(string $userId, string $name, string $plan): int
    {
        $limit = self::getLimit($plan, $name);
        if ($limit === -1 || is_bool($limit)) return -1;
        $apiName = match ($name) {
            'max_isochrones_per_day' => 'isochrone',
            'max_poi_searches_per_day' => 'places_nearby',
            default => null,
        };
        if (!$apiName) return (int)$limit;
        $since = date('Y-m-d 00:00:00');
        $row = \App\Core\Database::getInstance()->fetch(
            'SELECT COALESCE(SUM(request_count),0) AS total FROM api_usage_log
             WHERE user_id = ? AND api_name = ? AND created_at >= ?',
            [$userId, $apiName, $since]
        );
        $used = (int)($row['total'] ?? 0);
        return max(0, (int)$limit - $used);
    }
}
