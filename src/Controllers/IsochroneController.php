<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Core\PlanLimits;
use App\Services\IsochroneService;

class IsochroneController
{
    public function calculate(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $lat = (float)($body['lat'] ?? 0);
        $lng = (float)($body['lng'] ?? 0);
        $time = (int)($body['time_minutes'] ?? 0);
        $mode = $body['travel_mode'] ?? 'driving-car';
        $type = $body['type'] ?? 'isochrone';

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            Response::error('Invalid coordinates');
        }
        if ($type === 'radius') {
            $km = (float)($body['radius_km'] ?? 0);
            if ($km <= 0 || $km > 100) Response::error('Radius must be 0-100km');
            self::checkLimit($request);
            $result = (new IsochroneService())->calculateRadius($lat, $lng, $km);
            self::logUsage($request, 'isochrone');
            Response::success($result);
            return;
        }
        if ($time < 1 || $time > 720) Response::error('Time must be 1-720 minutes');

        self::checkLimit($request);

        try {
            $result = (new IsochroneService())->calculate($lat, $lng, $time, $mode);
        } catch (\Throwable $e) {
            Response::error('Isochrone calculation failed: ' . $e->getMessage(), 502);
        }
        self::logUsage($request, 'isochrone');
        Response::success($result);
    }

    private static function checkLimit(Request $request): void
    {
        $plan = $request->user['plan'] ?? 'free';
        $limit = PlanLimits::getLimit($plan, 'max_isochrones_per_day');
        if ($limit < 0) return; // unlimited
        $row = Database::getInstance()->fetch(
            'SELECT COALESCE(SUM(request_count),0) AS total FROM api_usage_log
             WHERE user_id = ? AND api_name = ? AND created_at >= ?',
            [$request->user['id'], 'isochrone', date('Y-m-d 00:00:00')]
        );
        if ((int)$row['total'] >= $limit) {
            Response::error("Daily isochrone limit ({$limit}) reached on the {$plan} plan. Please upgrade.", 429);
        }
    }

    private static function logUsage(Request $request, string $api): void
    {
        // Raw INSERT — api_usage_log has BIGINT AUTO_INCREMENT id, not UUID.
        Database::getInstance()->query(
            'INSERT INTO api_usage_log (user_id, api_name, endpoint, request_count, created_at)
             VALUES (?, ?, ?, 1, ?)',
            [$request->user['id'], $api, $request->getPath(), date('Y-m-d H:i:s')]
        );
    }
}
