<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\IsochroneService;
use App\Services\TrafficService;

/**
 * Time-of-day / day-of-week aware isochrones.
 *
 * Strategy: ORS doesn't accept a `departure_time` for traffic, so we apply an
 * empirical multiplier (see TrafficService) to the requested minutes — at
 * Tue 8AM rush we ask ORS for `minutes / 1.5`, yielding the polygon you can
 * actually reach during traffic.
 *
 * Two endpoints:
 *   POST /api/isochrone/traffic       single window
 *   POST /api/isochrone/traffic/grid  one isochrone per predefined window (8 max)
 */
class TrafficIsochroneController
{
    public function calculate(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $lat = (float)($body['lat'] ?? 0);
        $lng = (float)($body['lng'] ?? 0);
        $time = (int)($body['time_minutes'] ?? 0);
        $mode = $body['travel_mode'] ?? 'driving-car';
        $day = (string)($body['day_of_week'] ?? 'monday');
        $hour = (int)($body['hour_24'] ?? 12);

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) Response::error('Invalid coordinates');
        if ($time < 1 || $time > 720) Response::error('Time must be 1-720 minutes');

        $adjusted = TrafficService::adjustedMinutes($time, $day, $hour);
        $multiplier = TrafficService::multiplier($day, $hour);

        try {
            $result = (new IsochroneService())->calculate($lat, $lng, $adjusted, $mode);
        } catch (\Throwable $e) {
            Response::error('Isochrone calculation failed: ' . $e->getMessage(), 502);
        }

        $result['traffic'] = [
            'day_of_week' => $day,
            'hour_24' => $hour,
            'requested_minutes' => $time,
            'adjusted_free_flow_minutes' => $adjusted,
            'multiplier' => $multiplier,
            'label' => self::label($day, $hour, $multiplier),
        ];
        // No inline logUsage — the rateLimit('traffic_iso') middleware already
        // wrote an api_usage_log row before invoking us. Double-logging would
        // inflate analytics by 2× for the same call.
        Response::success($result);
    }

    public function grid(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $lat = (float)($body['lat'] ?? 0);
        $lng = (float)($body['lng'] ?? 0);
        $time = (int)($body['time_minutes'] ?? 0);
        $mode = $body['travel_mode'] ?? 'driving-car';
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) Response::error('Invalid coordinates');
        if ($time < 1 || $time > 60) Response::error('Time must be 1-60 minutes for the grid view');

        $svc = new IsochroneService();
        $windows = TrafficService::windows();
        $out = [];
        foreach ($windows as $w) {
            $adjusted = TrafficService::adjustedMinutes($time, $w['day'], $w['hour']);
            $mult = TrafficService::multiplier($w['day'], $w['hour']);
            try {
                $iso = $svc->calculate($lat, $lng, $adjusted, $mode);
            } catch (\Throwable $e) {
                $out[] = [
                    'window' => $w,
                    'error' => $e->getMessage(),
                ];
                continue;
            }
            $out[] = [
                'window' => $w,
                'multiplier' => $mult,
                'adjusted_minutes' => $adjusted,
                'requested_minutes' => $time,
                'area_sq_km' => $iso['area_sq_km'],
                'geometry' => $iso['geojson'],
                'bbox' => $iso['bbox'],
            ];
        }
        // Same as calculate(): rateLimit middleware already logged this call.
        Response::success([
            'center' => ['lat' => $lat, 'lng' => $lng],
            'requested_minutes' => $time,
            'travel_mode' => $mode,
            'windows' => $out,
        ]);
    }

    private static function label(string $day, int $hour, float $mult): string
    {
        $pad = str_pad((string)$hour, 2, '0', STR_PAD_LEFT);
        $rush = $mult >= 1.5 ? ' (heavy traffic)' : ($mult >= 1.25 ? ' (busy)' : '');
        return ucfirst($day) . ' ' . $pad . ':00' . $rush;
    }

    private static function logUsage(Request $request, string $api): void
    {
        try {
            Database::getInstance()->query(
                'INSERT INTO api_usage_log (user_id, api_name, endpoint, request_count, created_at)
                 VALUES (?, ?, ?, 1, ?)',
                [$request->user['id'], $api, $request->getPath(), date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {}
    }
}
