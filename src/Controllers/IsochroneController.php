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
        // ORS hard-caps drive-time isochrones at 60 minutes (3600s) on the
        // free + most paid plans. Earlier the controller accepted up to 720
        // and let ORS reject the request, dumping the raw 400-JSON into the
        // toast — see the user-facing error "Parameter 'range=5400.0' is out
        // of range. Maximum possible value is 3600". Validate up-front so we
        // never make that call.
        if ($time < 1) Response::error('Time must be at least 1 minute', 422);
        if ($time > 60) {
            Response::error(
                "Drive-time isochrones top out at 60 minutes on the routing service we use. "
                . "For longer reach use the Radius option instead, or split it into multiple 60-min areas.",
                422
            );
        }

        self::checkLimit($request);

        try {
            $result = (new IsochroneService())->calculate($lat, $lng, $time, $mode);
        } catch (\Throwable $e) {
            self::handleOrsError($e);
        }
        self::logUsage($request, 'isochrone');
        Response::success($result);
    }

    /**
     * ORS errors come back as "HTTP 400 from https://api.openrouteservice.org/...: {...JSON...}".
     * Show the user a friendly translation instead of the raw payload.
     */
    private static function handleOrsError(\Throwable $e): void
    {
        $msg = $e->getMessage();
        // Pull the JSON tail if present.
        $jsonStart = strpos($msg, '{');
        $parsed = null;
        if ($jsonStart !== false) {
            $parsed = json_decode(substr($msg, $jsonStart), true);
        }
        $orsCode    = $parsed['error']['code']    ?? null;
        $orsMessage = $parsed['error']['message'] ?? null;

        switch ((int) $orsCode) {
            case 3004: // range out of range
                Response::error('That drive-time exceeds the routing service ceiling. Try 60 minutes or less.', 422);
                break;
            case 2010: // location too far from road
            case 2009:
                Response::error('No road network near that point. Pick a location closer to a public road.', 422);
                break;
            case 6001:
                Response::error('Routing service rate-limit hit. Please wait a moment and try again.', 429);
                break;
            default:
                if ($orsMessage) {
                    Response::error('Routing service error: ' . $orsMessage, 502);
                }
                error_log('[isochrone] upstream: ' . substr($msg, 0, 1000));
                Response::error('Routing service unavailable. Try again in a few seconds.', 502);
        }
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
