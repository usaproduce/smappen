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
}
