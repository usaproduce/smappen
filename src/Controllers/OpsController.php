<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Catch-all controller for the operational-feature batch (OP4, OP5, OP9,
 * OP11, OP13, OP21). Each endpoint is small enough that extracting one
 * controller per resource would be 6 nearly-identical files; keeping
 * them here keeps the surface area browseable.
 *
 * Routes:
 *   GET    /api/saved-searches                — OP4 list saved analog configs
 *   POST   /api/saved-searches                — OP4 create
 *   DELETE /api/saved-searches/{id}           — OP4 delete
 *   GET    /api/saved-comparisons             — OP5 list
 *   POST   /api/saved-comparisons             — OP5 create
 *   DELETE /api/saved-comparisons/{id}        — OP5 delete
 *   GET    /api/activity                      — OP9 recent activity feed
 *   GET    /api/webhooks/deliveries           — OP11 webhook delivery history
 *   GET    /api/tags                          — OP21 list org tags
 *   POST   /api/tags                          — OP21 create tag
 *   POST   /api/areas/{id}/tags               — OP21 attach tag to area
 *   DELETE /api/areas/{id}/tags/{tagId}       — OP21 detach
 *   GET    /api/scheduled-reports             — OP13 list
 *   POST   /api/scheduled-reports             — OP13 create
 *   DELETE /api/scheduled-reports/{id}        — OP13 delete
 */
class OpsController
{
    // ─────────────────────────────────────────────────────────────────────
    // OP4 — saved analog searches
    // ─────────────────────────────────────────────────────────────────────

    public function listSavedSearches(Request $request): void
    {
        $org = $request->user['organization_id'];
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, name, source_area_id, config_json, created_at
               FROM saved_analog_searches WHERE organization_id = ?
              ORDER BY created_at DESC LIMIT 100',
            [$org]
        );
        foreach ($rows as &$r) $r['config'] = json_decode($r['config_json'] ?? 'null', true);
        Response::success(['searches' => $rows]);
    }

    public function createSavedSearch(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $name = trim((string)($b['name'] ?? ''));
        if ($name === '') Response::error('name required', 422);
        $config = $b['config'] ?? [];
        $sourceAreaId = $b['source_area_id'] ?? null;
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO saved_analog_searches
               (id, user_id, organization_id, name, source_area_id, config_json, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $request->user['id'], $request->user['organization_id'], $name,
             $sourceAreaId, json_encode($config), date('Y-m-d H:i:s')]
        );
        Response::success(['id' => $id]);
    }

    public function deleteSavedSearch(Request $request): void
    {
        $id = $request->getParam('id');
        Database::getInstance()->query(
            'DELETE FROM saved_analog_searches WHERE id = ? AND organization_id = ?',
            [$id, $request->user['organization_id']]
        );
        Response::success([]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // OP5 — saved comparisons
    // ─────────────────────────────────────────────────────────────────────

    public function listSavedComparisons(Request $request): void
    {
        $org = $request->user['organization_id'];
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, name, area_ids_json, created_at FROM saved_comparisons
              WHERE organization_id = ? ORDER BY created_at DESC LIMIT 100',
            [$org]
        );
        foreach ($rows as &$r) $r['area_ids'] = json_decode($r['area_ids_json'] ?? '[]', true);
        Response::success(['comparisons' => $rows]);
    }

    public function createSavedComparison(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $name = trim((string)($b['name'] ?? ''));
        $ids = $b['area_ids'] ?? null;
        if ($name === '' || !is_array($ids) || count($ids) < 2) {
            Response::error('name and at least 2 area_ids required', 422);
        }
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO saved_comparisons
               (id, user_id, organization_id, name, area_ids_json, created_at)
              VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $request->user['id'], $request->user['organization_id'], $name,
             json_encode($ids), date('Y-m-d H:i:s')]
        );
        Response::success(['id' => $id]);
    }

    public function deleteSavedComparison(Request $request): void
    {
        Database::getInstance()->query(
            'DELETE FROM saved_comparisons WHERE id = ? AND organization_id = ?',
            [$request->getParam('id'), $request->user['organization_id']]
        );
        Response::success([]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // OP9 — activity feed
    // ─────────────────────────────────────────────────────────────────────

    public function activityFeed(Request $request): void
    {
        $org = $request->user['organization_id'];
        $rows = Database::getInstance()->fetchAll(
            'SELECT actor_name, action, subject_type, subject_id, subject_name, created_at, meta_json
               FROM activity_log WHERE organization_id = ?
              ORDER BY created_at DESC LIMIT 50',
            [$org]
        );
        Response::success(['activity' => $rows]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // OP11 — webhook delivery history
    // ─────────────────────────────────────────────────────────────────────

    public function webhookDeliveries(Request $request): void
    {
        $org = $request->user['organization_id'];
        // webhook_deliveries table is part of an older feature batch (#19);
        // this read-only viewer just shows the last 50 attempts including
        // status_code, attempt_count, response_body_excerpt.
        $rows = Database::getInstance()->fetchAll(
            'SELECT wd.id, wd.event_type, wd.status_code, wd.attempt_count,
                    wd.delivered_at, wd.last_attempt_at, wd.response_excerpt,
                    ws.target_url
               FROM webhook_deliveries wd
               JOIN webhook_subscriptions ws ON ws.id = wd.subscription_id
              WHERE ws.organization_id = ?
              ORDER BY wd.last_attempt_at DESC LIMIT 50',
            [$org]
        );
        Response::success(['deliveries' => $rows]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // OP21 — tags
    // ─────────────────────────────────────────────────────────────────────

    public function listTags(Request $request): void
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, name, color FROM tags WHERE organization_id = ? ORDER BY name',
            [$request->user['organization_id']]
        );
        Response::success(['tags' => $rows]);
    }

    public function createTag(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $name = trim((string)($b['name'] ?? ''));
        $color = $b['color'] ?? '#7848BB';
        if ($name === '' || mb_strlen($name) > 60) Response::error('name 1..60 chars required', 422);
        $id = Database::uuid();
        try {
            Database::getInstance()->query(
                'INSERT INTO tags (id, organization_id, name, color, created_at)
                  VALUES (?, ?, ?, ?, ?)',
                [$id, $request->user['organization_id'], $name, $color, date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            Response::error('Tag name already exists', 409);
        }
        Response::success(['id' => $id]);
    }

    public function attachTag(Request $request): void
    {
        $areaId = $request->getParam('id');
        $b = $request->getBody() ?? [];
        $tagId = $b['tag_id'] ?? null;
        if (!$areaId || !$tagId) Response::error('tag_id required', 422);
        // Verify ownership: the area must be in a project owned by this org.
        $owned = Database::getInstance()->fetch(
            'SELECT 1 FROM areas a JOIN projects p ON p.id = a.project_id
              WHERE a.id = ? AND p.organization_id = ?',
            [$areaId, $request->user['organization_id']]
        );
        if (!$owned) Response::error('Area not found', 404);
        Database::getInstance()->query(
            'INSERT IGNORE INTO area_tags (area_id, tag_id) VALUES (?, ?)',
            [$areaId, $tagId]
        );
        Response::success([]);
    }

    public function detachTag(Request $request): void
    {
        Database::getInstance()->query(
            'DELETE area_tags FROM area_tags
              JOIN areas a ON a.id = area_tags.area_id
              JOIN projects p ON p.id = a.project_id
             WHERE area_tags.area_id = ? AND area_tags.tag_id = ?
               AND p.organization_id = ?',
            [$request->getParam('id'), $request->getParam('tagId'),
             $request->user['organization_id']]
        );
        Response::success([]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // OP13 — scheduled reports
    // ─────────────────────────────────────────────────────────────────────

    public function listScheduledReports(Request $request): void
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, area_id, project_id, frequency, recipient_email,
                    next_run_at, last_run_at, active, created_at
               FROM scheduled_reports WHERE organization_id = ?
              ORDER BY created_at DESC',
            [$request->user['organization_id']]
        );
        Response::success(['scheduled_reports' => $rows]);
    }

    public function createScheduledReport(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $freq = $b['frequency'] ?? null;
        $email = $b['recipient_email'] ?? null;
        if (!in_array($freq, ['daily','weekly','monthly'], true)) Response::error('frequency must be daily|weekly|monthly', 422);
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('valid recipient_email required', 422);
        // First run scheduled for tomorrow at 9am org-local; cron worker
        // bumps thereafter.
        $next = strtotime('tomorrow 09:00:00');
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO scheduled_reports
               (id, organization_id, user_id, area_id, project_id, frequency,
                recipient_email, next_run_at, active, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
            [$id, $request->user['organization_id'], $request->user['id'],
             $b['area_id'] ?? null, $b['project_id'] ?? null,
             $freq, $email, date('Y-m-d H:i:s', $next), date('Y-m-d H:i:s')]
        );
        Response::success(['id' => $id]);
    }

    public function deleteScheduledReport(Request $request): void
    {
        Database::getInstance()->query(
            'DELETE FROM scheduled_reports WHERE id = ? AND organization_id = ?',
            [$request->getParam('id'), $request->user['organization_id']]
        );
        Response::success([]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Dashboard overview — one round-trip for the landing page.
    // Returns aggregates the dashboard needs so the page paints with 3
    // requests (projects, activity, this) instead of N+1 area fetches.
    // ─────────────────────────────────────────────────────────────────────

    public function dashboardStats(Request $request): void
    {
        $org = $request->user['organization_id'];
        $uid = $request->user['id'];
        $db = Database::getInstance();

        $proj = $db->fetch(
            'SELECT COUNT(*) AS c FROM projects WHERE organization_id = ?',
            [$org]
        );
        $totalProjects = (int)($proj['c'] ?? 0);

        $areas = $db->fetch(
            'SELECT COUNT(*) AS c
               FROM areas a JOIN projects p ON p.id = a.project_id
              WHERE p.organization_id = ?',
            [$org]
        );
        $totalAreas = (int)($areas['c'] ?? 0);

        $byType = $db->fetchAll(
            'SELECT a.area_type AS k, COUNT(*) AS c
               FROM areas a JOIN projects p ON p.id = a.project_id
              WHERE p.organization_id = ?
              GROUP BY a.area_type',
            [$org]
        );
        $areasByType = [];
        foreach ($byType as $r) $areasByType[$r['k']] = (int)$r['c'];

        $byMode = $db->fetchAll(
            'SELECT COALESCE(a.travel_mode, "—") AS k, COUNT(*) AS c
               FROM areas a JOIN projects p ON p.id = a.project_id
              WHERE p.organization_id = ? AND a.travel_mode IS NOT NULL
              GROUP BY a.travel_mode',
            [$org]
        );
        $travelMode = [];
        foreach ($byMode as $r) $travelMode[$r['k']] = (int)$r['c'];

        // Pull demographics_cache JSON for ALL areas in the org. The cache
        // is a small (~2KB) document per area; even at 1000 areas this is
        // ~2MB streamed to PHP and aggregated in memory once per page load.
        // Worth it to avoid teaching MySQL to do JSON math.
        $cached = $db->fetchAll(
            'SELECT a.id, a.name, a.demographics_cache, p.name AS project_name
               FROM areas a JOIN projects p ON p.id = a.project_id
              WHERE p.organization_id = ? AND a.demographics_cache IS NOT NULL',
            [$org]
        );
        $totalPop = 0;
        $totalSqKm = 0.0;
        $incomeSum = 0; $incomeN = 0;
        $densSum = 0.0; $densN = 0;
        $unempSum = 0.0; $unempN = 0;
        $topAreas = [];
        foreach ($cached as $row) {
            $d = json_decode($row['demographics_cache'] ?? 'null', true);
            if (!is_array($d)) continue;
            $pop = (int)($d['population']['total'] ?? 0);
            $totalPop += $pop;
            $sq = (float)($d['meta']['area_sq_km'] ?? 0);
            $totalSqKm += $sq;
            $inc = $d['income']['median_household'] ?? null;
            if (is_numeric($inc) && $inc > 0) { $incomeSum += (float)$inc; $incomeN++; }
            $den = $d['population']['density_per_sq_km'] ?? null;
            if (is_numeric($den) && $den > 0) { $densSum += (float)$den; $densN++; }
            $un = $d['employment']['unemployment_rate'] ?? null;
            if (is_numeric($un)) { $unempSum += (float)$un; $unempN++; }
            $topAreas[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'project_name' => $row['project_name'],
                'population' => $pop,
                'area_sq_km' => $sq,
            ];
        }
        usort($topAreas, fn($a, $b) => $b['population'] <=> $a['population']);
        $topAreas = array_slice($topAreas, 0, 5);

        $folders = $db->fetch(
            'SELECT COUNT(*) AS c FROM folders f
               JOIN projects p ON p.id = f.project_id
              WHERE p.organization_id = ?',
            [$org]
        );
        $reports = $db->fetch(
            'SELECT COUNT(*) AS c FROM reports r
               JOIN areas a ON a.id = r.area_id
               JOIN projects p ON p.id = a.project_id
              WHERE p.organization_id = ?',
            [$org]
        );
        $shared = $db->fetch(
            'SELECT COUNT(*) AS c FROM projects
              WHERE organization_id = ? AND is_shared = 1',
            [$org]
        );

        // Saved comparisons & analog searches — these are quick-link surfaces
        // on the dashboard; the count tells the user they exist without
        // making the request itself.
        $comparisons = $db->fetch(
            'SELECT COUNT(*) AS c FROM saved_comparisons WHERE organization_id = ?',
            [$org]
        );
        $searches = $db->fetch(
            'SELECT COUNT(*) AS c FROM saved_analog_searches WHERE organization_id = ?',
            [$org]
        );

        // Recently updated areas — for "where you were working" surface.
        $recentAreas = $db->fetchAll(
            'SELECT a.id, a.name, a.area_type, a.updated_at,
                    p.id AS project_id, p.name AS project_name
               FROM areas a JOIN projects p ON p.id = a.project_id
              WHERE p.organization_id = ?
              ORDER BY a.updated_at DESC LIMIT 5',
            [$org]
        );

        Response::success([
            'totals' => [
                'projects' => $totalProjects,
                'areas' => $totalAreas,
                'population' => $totalPop,
                'area_sq_km' => round($totalSqKm, 1),
                'folders' => (int)($folders['c'] ?? 0),
                'reports' => (int)($reports['c'] ?? 0),
                'shared_projects' => (int)($shared['c'] ?? 0),
                'saved_comparisons' => (int)($comparisons['c'] ?? 0),
                'saved_searches' => (int)($searches['c'] ?? 0),
            ],
            'averages' => [
                'median_income' => $incomeN ? (int)round($incomeSum / $incomeN) : null,
                'density_per_sq_km' => $densN ? (float)round($densSum / $densN, 1) : null,
                'unemployment_rate' => $unempN ? (float)round($unempSum / $unempN, 2) : null,
            ],
            'areas_by_type' => $areasByType,
            'travel_mode' => $travelMode,
            'top_areas' => $topAreas,
            'recent_areas' => $recentAreas,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Org-wide restaurant intelligence — "Palantir for restaurants" core.
    // Returns one round-trip with revenue/cost/menu/recs/forecast/etc.
    // ─────────────────────────────────────────────────────────────────────

    public function restaurantsOverview(Request $request): void
    {
        $org = $request->user['organization_id'];
        $db = Database::getInstance();

        $range = (string)($request->getQuery('range') ?? 'mtd');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $todayStart = $now->modify('today')->format('Y-m-d H:i:s');
        $monthStart = $now->modify('first day of this month')->format('Y-m-01 00:00:00');
        $sevenDayStart = $now->modify('-7 days')->format('Y-m-d H:i:s');
        [$curStart, $curEnd, $prevStart, $prevEnd] = $this->rangeWindow($range, $now);

        $rstCounts = $db->fetch(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN archived_at IS NULL THEN 1 ELSE 0 END) AS active
               FROM restaurants WHERE organization_id = ?',
            [$org]
        );
        $totalRestaurants = (int)($rstCounts['total'] ?? 0);
        $activeRestaurants = (int)($rstCounts['active'] ?? 0);

        $posConnected = $db->fetch(
            'SELECT COUNT(DISTINCT restaurant_id) AS c FROM pos_integrations WHERE organization_id = ?',
            [$org]
        );

        $today = $db->fetch(
            'SELECT COUNT(DISTINCT pos_order_id) AS covers,
                    COALESCE(SUM(gross_cents), 0) AS revenue_cents,
                    COUNT(*) AS sale_lines,
                    MAX(sold_at) AS last_sale_at
               FROM pos_sales WHERE organization_id = ? AND sold_at >= ?',
            [$org, $todayStart]
        );
        $mtd = $db->fetch(
            'SELECT COUNT(DISTINCT pos_order_id) AS covers,
                    COALESCE(SUM(gross_cents), 0) AS revenue_cents
               FROM pos_sales WHERE organization_id = ? AND sold_at >= ?',
            [$org, $monthStart]
        );
        $week = $db->fetch(
            'SELECT COALESCE(SUM(gross_cents), 0) AS revenue_cents,
                    COUNT(DISTINCT pos_order_id) AS covers
               FROM pos_sales WHERE organization_id = ? AND sold_at >= ?',
            [$org, $sevenDayStart]
        );

        $foodCostMtd = $db->fetch(
            'SELECT COALESCE(SUM(ps.qty * pc.true_cost_cents), 0) AS cost_cents,
                    COALESCE(SUM(ps.gross_cents), 0) AS rev_cents
               FROM pos_sales ps
               LEFT JOIN plate_costs pc ON pc.menu_item_id = ps.menu_item_id
              WHERE ps.organization_id = ? AND ps.sold_at >= ?
                AND pc.true_cost_cents IS NOT NULL',
            [$org, $monthStart]
        );
        $foodCostCents = (int)($foodCostMtd['cost_cents'] ?? 0);
        $foodCostRev = (int)($foodCostMtd['rev_cents'] ?? 0);
        $foodCostPct = $foodCostRev > 0 ? round($foodCostCents / $foodCostRev, 4) : null;

        $laborRow = $db->fetch(
            'SELECT COALESCE(SUM(
                GREATEST(0, TIMESTAMPDIFF(SECOND, starts_at, COALESCE(ends_at, NOW()))) / 3600
                * hourly_wage_cents
              ), 0) AS labor_cents
               FROM labor_shifts
              WHERE organization_id = ? AND starts_at >= ?
                AND hourly_wage_cents IS NOT NULL',
            [$org, $monthStart]
        );
        $laborCents = (int)($laborRow['labor_cents'] ?? 0);
        $mtdRev = (int)($mtd['revenue_cents'] ?? 0);
        $laborPct = $mtdRev > 0 ? round($laborCents / $mtdRev, 4) : null;
        $primePct = ($foodCostPct !== null && $laborPct !== null) ? round($foodCostPct + $laborPct, 4) : null;

        // Previous period for variance decomposition
        $prevRev = (int)($db->fetch(
            'SELECT COALESCE(SUM(gross_cents), 0) AS r FROM pos_sales
              WHERE organization_id = ? AND sold_at >= ? AND sold_at < ?',
            [$org, $prevStart, $prevEnd]
        )['r'] ?? 0);
        $prevFood = $db->fetch(
            'SELECT COALESCE(SUM(ps.qty * pc.true_cost_cents), 0) AS cost_cents,
                    COALESCE(SUM(ps.gross_cents), 0) AS rev_cents
               FROM pos_sales ps
               LEFT JOIN plate_costs pc ON pc.menu_item_id = ps.menu_item_id
              WHERE ps.organization_id = ? AND ps.sold_at >= ? AND ps.sold_at < ?
                AND pc.true_cost_cents IS NOT NULL',
            [$org, $prevStart, $prevEnd]
        );
        $prevFoodPct = ((int)$prevFood['rev_cents']) > 0
            ? round(((int)$prevFood['cost_cents']) / ((int)$prevFood['rev_cents']), 4) : null;
        $prevLabor = $db->fetch(
            'SELECT COALESCE(SUM(
                GREATEST(0, TIMESTAMPDIFF(SECOND, starts_at, COALESCE(ends_at, NOW()))) / 3600
                * hourly_wage_cents
              ), 0) AS labor_cents
               FROM labor_shifts
              WHERE organization_id = ? AND starts_at >= ? AND starts_at < ?
                AND hourly_wage_cents IS NOT NULL',
            [$org, $prevStart, $prevEnd]
        );
        $prevLaborPct = $prevRev > 0 ? round(((int)$prevLabor['labor_cents']) / $prevRev, 4) : null;
        $prevPrimePct = ($prevFoodPct !== null && $prevLaborPct !== null) ? round($prevFoodPct + $prevLaborPct, 4) : null;

        // Carafe ROI
        $roiMtd = $db->fetch(
            'SELECT
                COALESCE(SUM(CASE WHEN status = "measured" THEN measured_impact_cents ELSE 0 END), 0) AS measured_cents,
                COALESCE(SUM(CASE WHEN status = "accepted" THEN dollar_estimate_cents ELSE 0 END), 0) AS accepted_cents,
                COUNT(*) AS rec_count_mtd
               FROM recommendations WHERE organization_id = ? AND created_at >= ?',
            [$org, $monthStart]
        );
        $openRecs = $db->fetch(
            'SELECT COUNT(*) AS n, COALESCE(SUM(dollar_estimate_cents), 0) AS total_open_cents
               FROM recommendations WHERE organization_id = ? AND status = "suggested"',
            [$org]
        );
        $recFunnelRows = $db->fetchAll(
            'SELECT status, COUNT(*) AS c FROM recommendations
              WHERE organization_id = ? GROUP BY status',
            [$org]
        );
        $funnel = ['suggested' => 0, 'accepted' => 0, 'dismissed' => 0, 'measured' => 0];
        foreach ($recFunnelRows as $r) $funnel[$r['status']] = (int)$r['c'];

        $topRecs = $db->fetchAll(
            'SELECT r.id, r.restaurant_id, r.menu_item_id, r.kind,
                    r.narrative, r.dollar_estimate_cents, r.created_at,
                    mi.name AS menu_item_name, rst.name AS restaurant_name
               FROM recommendations r
               JOIN restaurants rst ON rst.id = r.restaurant_id
               LEFT JOIN menu_items mi ON mi.id = r.menu_item_id
              WHERE r.organization_id = ? AND r.status = "suggested"
              ORDER BY r.dollar_estimate_cents DESC LIMIT 5',
            [$org]
        );

        // Per-restaurant w/ food + labor (for heatmap)
        $perRst = $db->fetchAll(
            'SELECT rst.id, rst.name, rst.address, rst.archived_at,
                    MAX(ps.sold_at) AS last_sale_at,
                    COALESCE(SUM(CASE WHEN ps.sold_at >= ? THEN ps.gross_cents END), 0) AS revenue_mtd_cents,
                    COUNT(DISTINCT CASE WHEN ps.sold_at >= ? THEN ps.pos_order_id END) AS covers_mtd,
                    COALESCE(SUM(CASE WHEN ps.sold_at >= ? THEN ps.gross_cents END), 0) AS revenue_today_cents,
                    (SELECT COUNT(*) FROM pos_integrations pi WHERE pi.restaurant_id = rst.id) AS pos_count,
                    (SELECT COUNT(*) FROM recommendations rc
                       WHERE rc.restaurant_id = rst.id AND rc.status = "suggested") AS open_recs,
                    (SELECT COALESCE(SUM(ps2.qty * pc.true_cost_cents), 0)
                       FROM pos_sales ps2
                       JOIN plate_costs pc ON pc.menu_item_id = ps2.menu_item_id
                      WHERE ps2.restaurant_id = rst.id AND ps2.sold_at >= ?) AS food_cost_cents,
                    (SELECT COALESCE(SUM(
                        GREATEST(0, TIMESTAMPDIFF(SECOND, ls.starts_at, COALESCE(ls.ends_at, NOW()))) / 3600
                        * ls.hourly_wage_cents
                      ), 0)
                       FROM labor_shifts ls
                      WHERE ls.restaurant_id = rst.id AND ls.starts_at >= ?
                        AND ls.hourly_wage_cents IS NOT NULL) AS labor_cost_cents
               FROM restaurants rst
               LEFT JOIN pos_sales ps ON ps.restaurant_id = rst.id
              WHERE rst.organization_id = ? AND rst.archived_at IS NULL
              GROUP BY rst.id, rst.name, rst.address, rst.archived_at
              ORDER BY revenue_mtd_cents DESC LIMIT 20',
            [$monthStart, $monthStart, $todayStart, $monthStart, $monthStart, $org]
        );

        $topItems = $db->fetchAll(
            'SELECT mi.id, mi.name, mi.category, rst.name AS restaurant_name,
                    SUM(ps.gross_cents) AS revenue_cents,
                    SUM(ps.qty) AS units_sold
               FROM pos_sales ps
               JOIN menu_items mi ON mi.id = ps.menu_item_id
               JOIN restaurants rst ON rst.id = ps.restaurant_id
              WHERE ps.organization_id = ? AND ps.sold_at >= ?
              GROUP BY mi.id, mi.name, mi.category, rst.name
              ORDER BY revenue_cents DESC LIMIT 6',
            [$org, $monthStart]
        );

        $byCategory = $db->fetchAll(
            'SELECT COALESCE(NULLIF(mi.category, ""), "Uncategorized") AS category,
                    SUM(ps.gross_cents) AS revenue_cents
               FROM pos_sales ps
               JOIN menu_items mi ON mi.id = ps.menu_item_id
              WHERE ps.organization_id = ? AND ps.sold_at >= ?
              GROUP BY category
              ORDER BY revenue_cents DESC LIMIT 6',
            [$org, $monthStart]
        );

        $byDaypart = $db->fetchAll(
            'SELECT COALESCE(NULLIF(daypart_label, ""), "unknown") AS daypart,
                    SUM(gross_cents) AS revenue_cents,
                    COUNT(*) AS sale_lines
               FROM pos_sales
              WHERE organization_id = ? AND sold_at >= ?
              GROUP BY daypart_label
              ORDER BY revenue_cents DESC',
            [$org, $monthStart]
        );

        $dailyRev = $db->fetchAll(
            'SELECT DATE(sold_at) AS day,
                    SUM(gross_cents) AS revenue_cents,
                    COUNT(DISTINCT pos_order_id) AS covers
               FROM pos_sales
              WHERE organization_id = ? AND sold_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
              GROUP BY DATE(sold_at)
              ORDER BY day',
            [$org]
        );

        $roiTrend = $db->fetchAll(
            'SELECT DATE_FORMAT(measured_at, "%Y-%m-01") AS month_start,
                    SUM(measured_impact_cents) AS found_cents
               FROM recommendations
              WHERE organization_id = ? AND status = "measured"
                AND measured_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
              GROUP BY DATE_FORMAT(measured_at, "%Y-%m-01")
              ORDER BY month_start',
            [$org]
        );

        $coverage = $db->fetch(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN pc.true_cost_cents IS NOT NULL THEN 1 ELSE 0 END) AS covered
               FROM menu_items mi
               LEFT JOIN plate_costs pc ON pc.menu_item_id = mi.id
              WHERE mi.organization_id = ? AND mi.is_active = 1',
            [$org]
        );
        $covTotal = (int)($coverage['total'] ?? 0);
        $covCovered = (int)($coverage['covered'] ?? 0);
        $coveragePct = $covTotal > 0 ? round(($covCovered / $covTotal) * 100) : null;

        // Needs attention
        $needsAttention = [];
        foreach ($perRst as $r) {
            $reason = null;
            if ((int)$r['pos_count'] === 0) $reason = 'No POS connected';
            elseif ($r['last_sale_at']) {
                $stale = strtotime($r['last_sale_at']) < (time() - 48 * 3600);
                if ($stale) $reason = 'No sale in 48h+';
            } else $reason = 'POS connected but no sales yet';
            if ($reason) {
                $needsAttention[] = ['id' => $r['id'], 'name' => $r['name'], 'reason' => $reason, 'last_sale_at' => $r['last_sale_at']];
            }
            if (count($needsAttention) >= 5) break;
        }

        // 28-day baseline for anomaly detection
        $baseline = $db->fetchAll(
            'SELECT DATE(sold_at) AS day, SUM(gross_cents) AS rev_cents
               FROM pos_sales
              WHERE organization_id = ?
                AND sold_at >= DATE_SUB(CURDATE(), INTERVAL 28 DAY) AND sold_at < CURDATE()
              GROUP BY DATE(sold_at)',
            [$org]
        );
        $vals = array_map(fn($r) => (int)$r['rev_cents'], $baseline);
        $mean = $vals ? array_sum($vals) / count($vals) : 0;
        $variance = $vals ? array_sum(array_map(fn($v) => ($v - $mean) ** 2, $vals)) / count($vals) : 0;
        $std = $variance > 0 ? sqrt($variance) : 0;
        $todayRev = (int)($today['revenue_cents'] ?? 0);
        $todayZ = $std > 0 ? round(($todayRev - $mean) / $std, 2) : null;
        $todayPctDev = $mean > 0 ? round(($todayRev - $mean) / $mean, 4) : null;

        $anomalies = [];
        if ($mean > 0 && abs($todayPctDev ?? 0) > 0.25) {
            $anomalies[] = [
                'kind' => $todayPctDev > 0 ? 'revenue_spike' : 'revenue_drop',
                'label' => $todayPctDev > 0 ? 'Revenue running hot' : 'Revenue running cold',
                'detail' => 'Today is ' . ($todayPctDev > 0 ? '+' : '') . round(($todayPctDev ?? 0) * 100) . '% vs 28-day average',
                'magnitude' => abs((int)round(($todayPctDev ?? 0) * 100)),
            ];
        }
        if ($foodCostPct !== null && $prevFoodPct !== null && abs($foodCostPct - $prevFoodPct) > 0.02) {
            $delta = $foodCostPct - $prevFoodPct;
            $anomalies[] = [
                'kind' => $delta > 0 ? 'food_cost_up' : 'food_cost_down',
                'label' => $delta > 0 ? 'Food cost % climbing' : 'Food cost % falling',
                'detail' => ($delta > 0 ? '+' : '') . round($delta * 100, 1) . 'pp vs previous period',
                'magnitude' => (int)round(abs($delta) * 1000),
            ];
        }
        if ($laborPct !== null && $prevLaborPct !== null && abs($laborPct - $prevLaborPct) > 0.02) {
            $delta = $laborPct - $prevLaborPct;
            $anomalies[] = [
                'kind' => $delta > 0 ? 'labor_cost_up' : 'labor_cost_down',
                'label' => $delta > 0 ? 'Labor cost % climbing' : 'Labor cost % falling',
                'detail' => ($delta > 0 ? '+' : '') . round($delta * 100, 1) . 'pp vs previous period',
                'magnitude' => (int)round(abs($delta) * 1000),
            ];
        }

        // Item velocity WoW
        $wowStart = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $wowPrevStart = $now->modify('-14 days')->format('Y-m-d H:i:s');
        $velocity = $db->fetchAll(
            'SELECT mi.id, mi.name, rst.name AS restaurant_name,
                    SUM(CASE WHEN ps.sold_at >= ? THEN ps.gross_cents ELSE 0 END) AS cur_rev,
                    SUM(CASE WHEN ps.sold_at >= ? AND ps.sold_at < ? THEN ps.gross_cents ELSE 0 END) AS prev_rev,
                    SUM(CASE WHEN ps.sold_at >= ? THEN ps.qty ELSE 0 END) AS cur_units,
                    SUM(CASE WHEN ps.sold_at >= ? AND ps.sold_at < ? THEN ps.qty ELSE 0 END) AS prev_units
               FROM pos_sales ps
               JOIN menu_items mi ON mi.id = ps.menu_item_id
               JOIN restaurants rst ON rst.id = ps.restaurant_id
              WHERE ps.organization_id = ? AND ps.sold_at >= ?
              GROUP BY mi.id, mi.name, rst.name
              HAVING (cur_rev + prev_rev) > 0
              ORDER BY ABS(cur_rev - prev_rev) DESC LIMIT 5',
            [$wowStart, $wowPrevStart, $wowStart, $wowStart, $wowPrevStart, $wowStart, $org, $wowPrevStart]
        );

        // Top cost-driver ingredients MTD
        $costDrivers = $db->fetchAll(
            'SELECT ri.ingredient_key AS k,
                    ROUND(SUM(ps.qty * ri.qty)) AS total_units,
                    SUM(ps.qty * ri.qty *
                        (SELECT market_price_cents FROM cogs_benchmark
                          WHERE ingredient_key = ri.ingredient_key
                          ORDER BY as_of DESC LIMIT 1)) AS cost_cents
               FROM pos_sales ps
               JOIN menu_items mi ON mi.id = ps.menu_item_id
               JOIN recipes r ON r.id = mi.recipe_id
               JOIN recipe_ingredients ri ON ri.recipe_id = r.id
              WHERE ps.organization_id = ? AND ps.sold_at >= ?
              GROUP BY ri.ingredient_key
              HAVING cost_cents IS NOT NULL AND cost_cents > 0
              ORDER BY cost_cents DESC LIMIT 6',
            [$org, $monthStart]
        );

        $staleCosts = $db->fetchAll(
            'SELECT mi.id, mi.name, rst.name AS restaurant_name,
                    pc.true_cost_cents, pc.computed_at, pc.coverage_pct
               FROM plate_costs pc
               JOIN menu_items mi ON mi.id = pc.menu_item_id
               JOIN restaurants rst ON rst.id = mi.restaurant_id
              WHERE pc.organization_id = ?
                AND pc.computed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND mi.is_active = 1
              ORDER BY pc.computed_at ASC LIMIT 5',
            [$org]
        );

        // Leaderboard
        $leaderboard = [];
        foreach ($perRst as $r) {
            $rev = (int)$r['revenue_mtd_cents'];
            if ($rev < 10000) continue;
            $food = (int)$r['food_cost_cents'];
            $labor = (int)$r['labor_cost_cents'];
            $marginPct = $rev > 0 ? round(($rev - $food - $labor) / $rev, 4) : null;
            $leaderboard[] = [
                'id' => $r['id'], 'name' => $r['name'],
                'margin_pct' => $marginPct,
                'food_pct' => $rev > 0 ? round($food / $rev, 4) : null,
                'labor_pct' => $rev > 0 ? round($labor / $rev, 4) : null,
                'revenue_cents' => $rev,
            ];
        }
        usort($leaderboard, fn($a, $b) => ($b['margin_pct'] ?? -1) <=> ($a['margin_pct'] ?? -1));

        // Forecasts
        $secondsToday = max(1, time() - strtotime(date('Y-m-d 00:00:00')));
        $dayFraction = min(1, $secondsToday / 86400);
        $eodRevenueForecast = $dayFraction > 0.05 && $todayRev > 0 ? (int) round($todayRev / $dayFraction) : null;

        $daysInMonth = (int) date('t');
        $dayOfMonth = (int) date('j');
        $eomRev = $dayOfMonth > 0 && $mtdRev > 0 ? (int) round(($mtdRev / $dayOfMonth) * $daysInMonth) : null;
        $eomFoodCents = $dayOfMonth > 0 && $foodCostCents > 0 ? (int) round(($foodCostCents / $dayOfMonth) * $daysInMonth) : null;
        $eomLaborCents = $dayOfMonth > 0 && $laborCents > 0 ? (int) round(($laborCents / $dayOfMonth) * $daysInMonth) : null;

        // Day-of-week revenue averages for next-7d forecast
        $dowRows = $db->fetchAll(
            'SELECT DAYOFWEEK(d) AS dow, AVG(daily_rev) AS avg_rev
               FROM (
                 SELECT DATE(sold_at) AS d, SUM(gross_cents) AS daily_rev
                   FROM pos_sales
                  WHERE organization_id = ?
                    AND sold_at >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
                  GROUP BY DATE(sold_at)
               ) x
              GROUP BY DAYOFWEEK(d)',
            [$org]
        );
        $dowMap = [];
        foreach ($dowRows as $r) $dowMap[(int)$r['dow']] = (int) round((float)$r['avg_rev']);
        $nextWeek = [];
        for ($i = 1; $i <= 7; $i++) {
            $d = (new \DateTimeImmutable())->modify("+$i days");
            $dow = (int) $d->format('w') + 1;
            $nextWeek[] = [
                'day' => $d->format('Y-m-d'),
                'dow_label' => $d->format('D'),
                'projected_cents' => $dowMap[$dow] ?? 0,
            ];
        }
        $nextWeekTotal = array_sum(array_column($nextWeek, 'projected_cents'));

        $scheduledLabor = $db->fetch(
            'SELECT COALESCE(SUM(
                GREATEST(0, TIMESTAMPDIFF(SECOND, starts_at, COALESCE(ends_at, starts_at + INTERVAL 8 HOUR))) / 3600
                * hourly_wage_cents
              ), 0) AS labor_cents
               FROM labor_shifts
              WHERE organization_id = ?
                AND starts_at >= NOW() AND starts_at < DATE_ADD(NOW(), INTERVAL 7 DAY)
                AND hourly_wage_cents IS NOT NULL',
            [$org]
        );
        $scheduledLaborCents = (int)($scheduledLabor['labor_cents'] ?? 0);
        $scheduledLaborPctOfForecast = $nextWeekTotal > 0 ? round($scheduledLaborCents / $nextWeekTotal, 4) : null;

        // Org-wide goal rollup
        $goals = $db->fetchAll(
            'SELECT metric, target_value FROM goals
              WHERE organization_id = ? AND is_active = 1',
            [$org]
        );
        $byMetric = [];
        foreach ($goals as $g) $byMetric[$g['metric']][] = (float)$g['target_value'];
        $goalRollup = [];
        foreach ($byMetric as $metric => $targets) {
            $avgTarget = array_sum($targets) / count($targets);
            $actual = null;
            switch ($metric) {
                case 'food_cost_pct': $actual = $foodCostPct; break;
                case 'margin_pct': $actual = ($foodCostPct !== null && $laborPct !== null)
                    ? round(1 - $foodCostPct - $laborPct, 4) : null; break;
                case 'avg_check_cents': $actual = ((int)($mtd['covers'] ?? 0)) > 0
                    ? (int) round($mtdRev / (int)$mtd['covers']) : null; break;
                case 'weekly_revenue_cents': $actual = (int)($week['revenue_cents'] ?? 0); break;
            }
            $goalRollup[] = [
                'metric' => $metric,
                'target_value' => round($avgTarget, 4),
                'actual_value' => $actual,
                'restaurant_count' => count($targets),
            ];
        }

        $alertCount = $db->fetch(
            'SELECT COUNT(*) AS c FROM alerts
              WHERE organization_id = ? AND active = 1
                AND kind IN ("metric_threshold","dashboard_threshold")',
            [$org]
        );

        $preShift = [];
        if ($foodCostPct !== null && $foodCostPct > 0.34) {
            $preShift[] = ['kind' => 'cost', 'label' => 'Food cost ' . round($foodCostPct * 100, 1) . '% — review top 5 cost drivers', 'priority' => 'high'];
        }
        if ($laborPct !== null && $laborPct > 0.34) {
            $preShift[] = ['kind' => 'labor', 'label' => 'Labor ' . round($laborPct * 100, 1) . '% — trim a shift on the slow daypart', 'priority' => 'high'];
        }
        if ((int)($openRecs['n'] ?? 0) > 0) {
            $preShift[] = ['kind' => 'recs', 'label' => ((int)$openRecs['n']) . ' open recommendations ($' . round(((int)$openRecs['total_open_cents']) / 100) . ' total)', 'priority' => 'medium'];
        }
        if ($coveragePct !== null && $coveragePct < 80) {
            $preShift[] = ['kind' => 'menu', 'label' => 'Plate cost coverage ' . $coveragePct . '% — cost the missing recipes', 'priority' => 'medium'];
        }
        if (count($staleCosts) > 0) {
            $preShift[] = ['kind' => 'drift', 'label' => count($staleCosts) . ' recipes haven\'t been recosted in 30+ days', 'priority' => 'low'];
        }
        foreach ($needsAttention as $a) {
            $preShift[] = ['kind' => 'restaurant', 'label' => $a['name'] . ': ' . $a['reason'], 'priority' => 'high'];
        }

        Response::success([
            'range' => $range,
            'totals' => [
                'restaurants_total' => $totalRestaurants,
                'restaurants_active' => $activeRestaurants,
                'pos_connected_restaurants' => (int)($posConnected['c'] ?? 0),
                'menu_items_active' => $covTotal,
                'menu_items_with_plate_cost' => $covCovered,
                'menu_coverage_pct' => $coveragePct,
                'open_recommendations' => (int)($openRecs['n'] ?? 0),
                'open_recommendations_cents' => (int)($openRecs['total_open_cents'] ?? 0),
                'recommendations_mtd' => (int)($roiMtd['rec_count_mtd'] ?? 0),
            ],
            'today' => [
                'revenue_cents' => (int)($today['revenue_cents'] ?? 0),
                'covers' => (int)($today['covers'] ?? 0),
                'avg_ticket_cents' => (int)($today['covers'] ?? 0) > 0
                    ? (int) round(((int)$today['revenue_cents']) / ((int)$today['covers'])) : null,
                'sale_lines' => (int)($today['sale_lines'] ?? 0),
                'last_sale_at' => $today['last_sale_at'] ?? null,
            ],
            'mtd' => [
                'revenue_cents' => $mtdRev,
                'covers' => (int)($mtd['covers'] ?? 0),
                'food_cost_pct' => $foodCostPct,
                'food_cost_cents' => $foodCostCents,
                'labor_cost_cents' => $laborCents,
                'labor_cost_pct' => $laborPct,
                'prime_cost_pct' => $primePct,
                'carafe_found_cents' => (int)($roiMtd['measured_cents'] ?? 0) + (int)($roiMtd['accepted_cents'] ?? 0),
                'carafe_measured_cents' => (int)($roiMtd['measured_cents'] ?? 0),
                'carafe_accepted_cents' => (int)($roiMtd['accepted_cents'] ?? 0),
            ],
            'last_7d' => [
                'revenue_cents' => (int)($week['revenue_cents'] ?? 0),
                'covers' => (int)($week['covers'] ?? 0),
            ],
            'previous' => [
                'revenue_cents' => $prevRev,
                'food_cost_pct' => $prevFoodPct,
                'labor_cost_pct' => $prevLaborPct,
                'prime_cost_pct' => $prevPrimePct,
            ],
            'baseline_28d' => [
                'mean_revenue_cents' => (int) round($mean),
                'stddev_cents' => (int) round($std),
                'today_revenue_cents' => $todayRev,
                'today_z' => $todayZ,
                'today_pct_deviation' => $todayPctDev,
            ],
            'anomalies' => $anomalies,
            'recommendation_funnel' => $funnel,
            'top_recommendations' => array_map(fn($r) => [
                'id' => $r['id'], 'restaurant_id' => $r['restaurant_id'],
                'restaurant_name' => $r['restaurant_name'],
                'menu_item_id' => $r['menu_item_id'], 'menu_item_name' => $r['menu_item_name'],
                'kind' => $r['kind'], 'narrative' => $r['narrative'],
                'dollar_estimate_cents' => (int)$r['dollar_estimate_cents'],
                'created_at' => $r['created_at'],
            ], $topRecs),
            'restaurants' => array_map(function($r) {
                $rev = (int)$r['revenue_mtd_cents'];
                $food = (int)$r['food_cost_cents'];
                $labor = (int)$r['labor_cost_cents'];
                return [
                    'id' => $r['id'], 'name' => $r['name'], 'address' => $r['address'],
                    'pos_connected' => (int)$r['pos_count'] > 0,
                    'last_sale_at' => $r['last_sale_at'],
                    'revenue_today_cents' => (int)$r['revenue_today_cents'],
                    'revenue_mtd_cents' => $rev, 'covers_mtd' => (int)$r['covers_mtd'],
                    'open_recs' => (int)$r['open_recs'],
                    'food_cost_cents' => $food, 'labor_cost_cents' => $labor,
                    'food_cost_pct' => $rev > 0 ? round($food / $rev, 4) : null,
                    'labor_cost_pct' => $rev > 0 ? round($labor / $rev, 4) : null,
                    'prime_cost_pct' => $rev > 0 ? round(($food + $labor) / $rev, 4) : null,
                    'margin_pct' => $rev > 0 ? round(($rev - $food - $labor) / $rev, 4) : null,
                ];
            }, $perRst),
            'top_menu_items' => array_map(fn($r) => [
                'id' => $r['id'], 'name' => $r['name'], 'category' => $r['category'],
                'restaurant_name' => $r['restaurant_name'],
                'revenue_cents' => (int)$r['revenue_cents'], 'units_sold' => (int)$r['units_sold'],
            ], $topItems),
            'sales_by_category' => array_map(fn($r) => [
                'category' => $r['category'], 'revenue_cents' => (int)$r['revenue_cents'],
            ], $byCategory),
            'sales_by_daypart' => array_map(fn($r) => [
                'daypart' => $r['daypart'], 'revenue_cents' => (int)$r['revenue_cents'],
                'sale_lines' => (int)$r['sale_lines'],
            ], $byDaypart),
            'daily_revenue_14d' => array_map(fn($r) => [
                'day' => $r['day'], 'revenue_cents' => (int)$r['revenue_cents'],
                'covers' => (int)$r['covers'],
            ], $dailyRev),
            'carafe_roi_6mo' => array_map(fn($r) => [
                'month_start' => $r['month_start'], 'found_cents' => (int)$r['found_cents'],
            ], $roiTrend),
            'needs_attention' => $needsAttention,
            'item_velocity' => array_map(fn($r) => [
                'id' => $r['id'], 'name' => $r['name'], 'restaurant_name' => $r['restaurant_name'],
                'cur_rev_cents' => (int)$r['cur_rev'], 'prev_rev_cents' => (int)$r['prev_rev'],
                'cur_units' => (int)$r['cur_units'], 'prev_units' => (int)$r['prev_units'],
                'delta_pct' => ((int)$r['prev_rev']) > 0
                    ? round((((int)$r['cur_rev']) - ((int)$r['prev_rev'])) / ((int)$r['prev_rev']), 4) : null,
            ], $velocity),
            'cost_drivers' => array_map(fn($r) => [
                'ingredient_key' => $r['k'], 'total_units' => (int)$r['total_units'],
                'cost_cents' => (int)$r['cost_cents'],
            ], $costDrivers),
            'recipe_drift' => array_map(fn($r) => [
                'id' => $r['id'], 'name' => $r['name'], 'restaurant_name' => $r['restaurant_name'],
                'true_cost_cents' => (int)$r['true_cost_cents'], 'computed_at' => $r['computed_at'],
                'coverage_pct' => (int)$r['coverage_pct'],
            ], $staleCosts),
            'leaderboard' => $leaderboard,
            'variance_decomposition' => [
                'prime_delta_pct' => ($primePct !== null && $prevPrimePct !== null) ? round($primePct - $prevPrimePct, 4) : null,
                'food_delta_pct' => ($foodCostPct !== null && $prevFoodPct !== null) ? round($foodCostPct - $prevFoodPct, 4) : null,
                'labor_delta_pct' => ($laborPct !== null && $prevLaborPct !== null) ? round($laborPct - $prevLaborPct, 4) : null,
            ],
            'forecast' => [
                'eod_revenue_cents' => $eodRevenueForecast,
                'eom_revenue_cents' => $eomRev,
                'eom_food_cost_cents' => $eomFoodCents,
                'eom_labor_cost_cents' => $eomLaborCents,
                'next_week' => $nextWeek,
                'next_week_total_cents' => (int)$nextWeekTotal,
                'scheduled_labor_next7_cents' => $scheduledLaborCents,
                'scheduled_labor_pct_of_forecast' => $scheduledLaborPctOfForecast,
            ],
            'goals' => $goalRollup,
            'industry_benchmarks' => [
                'food_cost_pct' => 0.30, 'labor_cost_pct' => 0.30,
                'prime_cost_pct' => 0.60, 'margin_pct' => 0.18,
                'source' => 'NRA / casual dining peer median',
            ],
            'pre_shift' => $preShift,
            'active_alerts' => (int)($alertCount['c'] ?? 0),
        ]);
    }

    private function rangeWindow(string $range, \DateTimeImmutable $now): array
    {
        $fmt = 'Y-m-d H:i:s';
        $tz = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('today', $tz);
        switch ($range) {
            case 'today':
                $cur = $today; $end = $now;
                $prev = $today->modify('-1 day'); $prevEnd = $today; break;
            case 'wtd':
                $monday = $today->modify('monday this week');
                $cur = $monday; $end = $now;
                $prev = $monday->modify('-7 days'); $prevEnd = $monday; break;
            case '7d':
                $cur = $today->modify('-7 days'); $end = $now;
                $prev = $today->modify('-14 days'); $prevEnd = $cur; break;
            case '30d':
                $cur = $today->modify('-30 days'); $end = $now;
                $prev = $today->modify('-60 days'); $prevEnd = $cur; break;
            case 'ytd':
                $cur = $today->modify('first day of January this year'); $end = $now;
                $prev = $cur->modify('-1 year'); $prevEnd = $cur; break;
            case 'mtd':
            default:
                $cur = $today->modify('first day of this month'); $end = $now;
                $prev = $cur->modify('-1 month'); $prevEnd = $cur; break;
        }
        return [$cur->format($fmt), $end->format($fmt), $prev->format($fmt), $prevEnd->format($fmt)];
    }

    // ─────────────────────────────────────────────────────────────────────
    // AI daily briefing — Claude Haiku w/ template fallback. Cached 30min.
    // GET /api/dashboard/briefing
    // ─────────────────────────────────────────────────────────────────────

    public function dashboardBriefing(Request $request): void
    {
        $org = $request->user['organization_id'];
        $cacheKey = 'dash_briefing:' . $org;
        $now = time();

        try {
            $cached = Database::getInstance()->fetch(
                'SELECT `value`, expires_at FROM cache WHERE `key` = ? LIMIT 1',
                [$cacheKey]
            );
            if ($cached && $cached['expires_at'] && strtotime($cached['expires_at']) > $now) {
                Response::success(json_decode($cached['value'], true) + ['cached' => true]);
                return;
            }
        } catch (\Throwable) { /* table may not exist; ignore */ }

        $body = $this->briefingFacts($org);
        $bullets = null;
        $apiKey = (string) \App\Core\Config::get('ANTHROPIC_API_KEY', '');
        if ($apiKey !== '') {
            try { $bullets = $this->briefingViaClaude($apiKey, $body); }
            catch (\Throwable $e) { error_log('briefing/claude failed: ' . $e->getMessage()); }
        }
        if (!$bullets) $bullets = $this->briefingTemplate($body);

        $result = [
            'generated_at' => date('c'),
            'bullets' => $bullets,
            'source' => ($bullets && $apiKey !== '') ? 'claude' : 'template',
        ];

        try {
            Database::getInstance()->query(
                'INSERT INTO cache (`key`, `value`, expires_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), expires_at = VALUES(expires_at)',
                [$cacheKey, json_encode($result)]
            );
        } catch (\Throwable) { /* fine */ }

        Response::success($result);
    }

    private function briefingFacts(string $org): array
    {
        $db = Database::getInstance();
        $monthStart = date('Y-m-01 00:00:00');
        $row = $db->fetch(
            'SELECT COALESCE(SUM(gross_cents), 0) AS rev,
                    COUNT(DISTINCT pos_order_id) AS covers
               FROM pos_sales WHERE organization_id = ? AND sold_at >= ?',
            [$org, $monthStart]
        );
        $food = $db->fetch(
            'SELECT COALESCE(SUM(ps.qty * pc.true_cost_cents), 0) AS cost
               FROM pos_sales ps JOIN plate_costs pc ON pc.menu_item_id = ps.menu_item_id
              WHERE ps.organization_id = ? AND ps.sold_at >= ?',
            [$org, $monthStart]
        );
        $openRecs = $db->fetch(
            'SELECT COUNT(*) AS n, COALESCE(SUM(dollar_estimate_cents),0) AS t
               FROM recommendations WHERE organization_id = ? AND status = "suggested"',
            [$org]
        );
        $rest = $db->fetch(
            'SELECT COUNT(*) AS active FROM restaurants WHERE organization_id = ? AND archived_at IS NULL',
            [$org]
        );
        return [
            'mtd_revenue_cents' => (int)$row['rev'],
            'mtd_covers' => (int)$row['covers'],
            'mtd_food_cost_cents' => (int)$food['cost'],
            'open_recs' => (int)$openRecs['n'],
            'open_recs_cents' => (int)$openRecs['t'],
            'restaurants_active' => (int)$rest['active'],
        ];
    }

    private function briefingTemplate(array $f): array
    {
        $bullets = [];
        $rev = number_format($f['mtd_revenue_cents'] / 100, 0);
        $foodPct = $f['mtd_revenue_cents'] > 0
            ? round($f['mtd_food_cost_cents'] / $f['mtd_revenue_cents'] * 100, 1) : null;
        $bullets[] = 'MTD revenue $' . $rev . ' across ' . $f['restaurants_active'] . ' restaurants · '
            . number_format($f['mtd_covers']) . ' covers'
            . ($foodPct !== null ? ', food cost ' . $foodPct . '%' : '');
        if ($f['open_recs'] > 0) {
            $bullets[] = $f['open_recs'] . ' open recommendations totaling $'
                . number_format($f['open_recs_cents'] / 100, 0)
                . ' — clear the top 3 to capture the biggest near-term lift.';
        } else {
            $bullets[] = 'No open Carafe recommendations — keep POS sync current so the model can score this week\'s sales.';
        }
        if ($foodPct !== null && $foodPct > 32) {
            $bullets[] = 'Food cost is running at ' . $foodPct . '% (target ~30%). Review the top cost-driver ingredients and recipes with stale plate costs.';
        } else {
            $bullets[] = 'Cost discipline is on target. Focus on revenue moves: promote your top-selling items via daypart pushes.';
        }
        return $bullets;
    }

    private function briefingViaClaude(string $apiKey, array $f): ?array
    {
        $prompt = "You are a restaurant operations chief-of-staff. Write exactly 3 bullets, each one sentence, in plain English. Be specific with the numbers. No headers, no preamble, no markdown — just three short lines separated by newlines.\n\nFacts:\n"
            . json_encode($f, JSON_PRETTY_PRINT);
        $payload = json_encode([
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 400,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT => 12,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$res) return null;
        $parsed = json_decode($res, true);
        $text = $parsed['content'][0]['text'] ?? '';
        if (!is_string($text) || $text === '') return null;
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
        $lines = array_map(fn($l) => ltrim($l, "-•* \t"), $lines);
        return array_slice($lines, 0, 3);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Dashboard threshold alerts — per-user, persisted in `alerts` table.
    //   GET    /api/dashboard/alerts
    //   POST   /api/dashboard/alerts        { metric, op, value, label }
    //   DELETE /api/dashboard/alerts/{id}
    // ─────────────────────────────────────────────────────────────────────

    public function listDashboardAlerts(Request $request): void
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, kind, config_json, active, last_fired_at, fire_count, created_at
               FROM alerts
              WHERE organization_id = ? AND user_id = ?
                AND kind IN ("dashboard_threshold","metric_threshold")
              ORDER BY created_at DESC LIMIT 50',
            [$request->user['organization_id'], $request->user['id']]
        );
        foreach ($rows as &$r) $r['config'] = json_decode($r['config_json'] ?? 'null', true);
        Response::success(['alerts' => $rows]);
    }

    public function createDashboardAlert(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $metric = (string)($b['metric'] ?? '');
        $op = (string)($b['op'] ?? '>');
        $value = (float)($b['value'] ?? 0);
        $label = (string)($b['label'] ?? '');
        $allowed = ['food_cost_pct','labor_cost_pct','prime_cost_pct','revenue_today_cents','open_recs','margin_pct'];
        if (!in_array($metric, $allowed, true)) Response::error('metric invalid', 422);
        if (!in_array($op, ['>', '<', '>=', '<='], true)) Response::error('op invalid', 422);

        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO alerts (id, organization_id, user_id, area_id, kind, config_json, active, created_at)
             VALUES (?, ?, ?, NULL, "dashboard_threshold", ?, 1, NOW())',
            [$id, $request->user['organization_id'], $request->user['id'],
             json_encode(['metric' => $metric, 'op' => $op, 'value' => $value, 'label' => $label])]
        );
        Response::success(['id' => $id]);
    }

    public function deleteDashboardAlert(Request $request): void
    {
        Database::getInstance()->query(
            'DELETE FROM alerts WHERE id = ? AND organization_id = ? AND user_id = ?',
            [$request->getParam('id'), $request->user['organization_id'], $request->user['id']]
        );
        Response::success([]);
    }
}
