<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\GooglePricing;

/**
 * Operator-facing spend visibility for Google Maps Platform calls.
 *
 *   GET /api/usage/today  — running total spent today (current user)
 *   GET /api/usage/days   — last 30 days, bucketed
 *   GET /api/usage/pricing — the per-call price card so the UI can compute
 *                           "this click will cost ~$X" before firing
 *
 * Cost numbers are estimates (see GooglePricing). Real billing is in GCP.
 */
class UsageController
{
    public function today(Request $request): void
    {
        $uid = $request->user['id'];
        // Daily total + per-api breakdown so the header can show "$1.34 today"
        // and a tooltip can break it down by which calls ate the budget.
        $row = Database::getInstance()->fetch(
            "SELECT COALESCE(SUM(estimated_cost_usd), 0) AS total,
                    COALESCE(SUM(request_count), 0) AS calls
             FROM api_usage_log
             WHERE user_id = ? AND created_at >= ?",
            [$uid, date('Y-m-d 00:00:00')]
        );
        $breakdown = Database::getInstance()->fetchAll(
            "SELECT api_name,
                    SUM(request_count) AS calls,
                    SUM(estimated_cost_usd) AS cost
             FROM api_usage_log
             WHERE user_id = ? AND created_at >= ? AND estimated_cost_usd > 0
             GROUP BY api_name
             ORDER BY cost DESC",
            [$uid, date('Y-m-d 00:00:00')]
        );
        Response::cacheable(0);
        Response::success([
            'date' => date('Y-m-d'),
            'total_usd' => (float)($row['total'] ?? 0),
            'call_count' => (int)($row['calls'] ?? 0),
            'breakdown' => array_map(fn($r) => [
                'api_name' => $r['api_name'],
                'calls' => (int)$r['calls'],
                'cost_usd' => (float)$r['cost'],
            ], $breakdown),
        ]);
    }

    public function days(Request $request): void
    {
        $uid = $request->user['id'];
        $rows = Database::getInstance()->fetchAll(
            "SELECT DATE(created_at) AS day,
                    SUM(estimated_cost_usd) AS cost,
                    SUM(request_count) AS calls
             FROM api_usage_log
             WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY DATE(created_at)
             ORDER BY day DESC",
            [$uid]
        );
        Response::success([
            'days' => array_map(fn($r) => [
                'day' => $r['day'],
                'cost_usd' => (float)$r['cost'],
                'calls' => (int)$r['calls'],
            ], $rows),
        ]);
    }

    /**
     * Public-ish price card. Useful for UIs that want to display "this will
     * cost ~$X" before the user clicks. No PII — just the rate table.
     */
    public function pricing(Request $request): void
    {
        Response::cacheable(3600, true);
        Response::success(['prices' => GooglePricing::COSTS]);
    }

    /**
     * Log a single Maps JS API "session" (page load). Frontend calls this
     * once per top-level mount of <GoogleMap>. Pricing: $7/1000 sessions,
     * which is the biggest line on most Smappen bills — without this the
     * cost widget badly under-counts.
     */
    public function logMapLoad(Request $request): void
    {
        $cost = GooglePricing::costFor('dynamic_maps_load');
        Database::getInstance()->query(
            'INSERT INTO api_usage_log
               (user_id, api_name, endpoint, request_count, estimated_cost_usd, created_at)
             VALUES (?, ?, ?, 1, ?, ?)',
            [$request->user['id'], 'dynamic_maps_load', '/api/usage/log-map-load',
             $cost, date('Y-m-d H:i:s')]
        );
        Response::success(['cost_usd' => $cost]);
    }
}
