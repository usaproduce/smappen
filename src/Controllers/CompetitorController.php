<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Competitor monitoring & alerts. The CRUD endpoints; the actual recurring
 * scan happens in scripts/competitor-scan.php (run from cron every 15 min).
 *
 *  GET  /api/projects/{projectId}/competitor-monitors      list monitors
 *  POST /api/projects/{projectId}/competitor-monitors      create monitor
 *  GET  /api/competitor-monitors/{id}                      one monitor + recent scans
 *  PUT  /api/competitor-monitors/{id}                      update (rename, types, freq, toggle)
 *  DEL  /api/competitor-monitors/{id}                      remove
 *  POST /api/competitor-monitors/{id}/scan                 force a scan now
 *  GET  /api/competitor-monitors/{id}/places               current tracked_places snapshot
 *  GET  /api/competitor-monitors/{id}/alerts               alerts (paged)
 *  POST /api/competitor-alerts/{id}/read                   mark alert read
 */
class CompetitorController
{
    public function index(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireProject($request, $projectId);
        $rows = Database::getInstance()->fetchAll(
            "SELECT cm.*,
                    (SELECT COUNT(*) FROM tracked_places WHERE monitor_id = cm.id AND is_gone = 0) AS active_places,
                    (SELECT COUNT(*) FROM competitor_alerts WHERE monitor_id = cm.id AND is_read = 0) AS unread_alerts
             FROM competitor_monitors cm
             WHERE cm.project_id = ?
             ORDER BY cm.created_at DESC",
            [$projectId]
        );
        foreach ($rows as &$r) {
            $r['place_types'] = json_decode($r['place_types'], true);
        }
        Response::success(['monitors' => $rows]);
    }

    public function create(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireProject($request, $projectId);
        $body = $request->getBody() ?? [];
        $name = trim((string)($body['name'] ?? ''));
        $types = (array)($body['place_types'] ?? []);
        $keywords = (string)($body['keywords'] ?? '');
        $freq = (string)($body['frequency'] ?? 'weekly');
        $areaId = $body['area_id'] ?? null;
        if (mb_strlen($name) < 1) Response::error('name required');
        if (empty($types)) Response::error('place_types required');
        if (!in_array($freq, ['daily', 'weekly', 'monthly'], true)) Response::error('frequency must be daily|weekly|monthly');

        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO competitor_monitors
               (id, project_id, area_id, name, place_types, keywords, frequency, is_active,
                next_run_at, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, NOW(), NOW())',
            [
                $id, $projectId, $areaId,
                $name, json_encode(array_values($types)), $keywords ?: null, $freq,
                $request->user['id'],
            ]
        );
        Response::success(['id' => $id]);
    }

    public function show(Request $request): void
    {
        $id = $request->getParam('id');
        $m = self::loadMonitor($request, $id);
        $scans = Database::getInstance()->fetchAll(
            'SELECT id, place_count, new_count, gone_count, moved_count, rating_change_count,
                    started_at, finished_at
             FROM competitor_scans WHERE monitor_id = ?
             ORDER BY started_at DESC LIMIT 50',
            [$id]
        );
        $m['place_types'] = json_decode($m['place_types'], true);
        $m['recent_scans'] = $scans;
        Response::success($m);
    }

    public function update(Request $request): void
    {
        $id = $request->getParam('id');
        $m = self::loadMonitor($request, $id);
        $body = $request->getBody() ?? [];
        $sets = [];
        $params = [];
        foreach (['name', 'keywords'] as $k) {
            if (array_key_exists($k, $body)) {
                $sets[] = "$k = ?";
                $params[] = $body[$k];
            }
        }
        if (array_key_exists('place_types', $body)) {
            $sets[] = 'place_types = ?';
            $params[] = json_encode(array_values((array)$body['place_types']));
        }
        if (array_key_exists('frequency', $body)) {
            if (!in_array($body['frequency'], ['daily', 'weekly', 'monthly'], true)) Response::error('bad frequency');
            $sets[] = 'frequency = ?';
            $params[] = $body['frequency'];
        }
        if (array_key_exists('is_active', $body)) {
            $sets[] = 'is_active = ?';
            $params[] = $body['is_active'] ? 1 : 0;
        }
        if (empty($sets)) Response::error('No fields to update');
        $sets[] = 'updated_at = NOW()';
        $params[] = $id;
        Database::getInstance()->query(
            'UPDATE competitor_monitors SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
        Response::success(['id' => $id, 'updated' => true]);
    }

    public function destroy(Request $request): void
    {
        $id = $request->getParam('id');
        self::loadMonitor($request, $id);
        Database::getInstance()->query('DELETE FROM competitor_monitors WHERE id = ?', [$id]);
        Response::success(['id' => $id, 'deleted' => true]);
    }

    public function scanNow(Request $request): void
    {
        $id = $request->getParam('id');
        $m = self::loadMonitor($request, $id);
        require_once dirname(__DIR__) . '/Services/CompetitorScanner.php';
        $scanner = new \App\Services\CompetitorScanner();
        try {
            $summary = $scanner->scan($m);
        } catch (\Throwable $e) {
            Response::error('Scan failed: ' . $e->getMessage(), 502);
        }
        Response::success($summary);
    }

    public function listPlaces(Request $request): void
    {
        $id = $request->getParam('id');
        self::loadMonitor($request, $id);
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, place_id, name, lat, lng, rating, user_ratings_total,
                    types, first_seen_at, last_seen_at, is_gone
             FROM tracked_places
             WHERE monitor_id = ?
             ORDER BY is_gone ASC, name ASC',
            [$id]
        );
        foreach ($rows as &$r) {
            if (!empty($r['types'])) $r['types'] = json_decode($r['types'], true);
        }
        Response::success(['places' => $rows]);
    }

    public function listAlerts(Request $request): void
    {
        $id = $request->getParam('id');
        self::loadMonitor($request, $id);
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM competitor_alerts
             WHERE monitor_id = ?
             ORDER BY created_at DESC LIMIT 200',
            [$id]
        );
        foreach ($rows as &$r) {
            if (!empty($r['detail'])) $r['detail'] = json_decode($r['detail'], true);
        }
        Response::success(['alerts' => $rows]);
    }

    public function markAlertRead(Request $request): void
    {
        $id = $request->getParam('id');
        $row = Database::getInstance()->fetch(
            'SELECT ca.monitor_id, cm.project_id
             FROM competitor_alerts ca
             JOIN competitor_monitors cm ON cm.id = ca.monitor_id
             WHERE ca.id = ?',
            [$id]
        );
        if (!$row) Response::error('Alert not found', 404);
        self::requireProject($request, $row['project_id']);
        Database::getInstance()->query('UPDATE competitor_alerts SET is_read = 1 WHERE id = ?', [$id]);
        Response::success(['id' => $id, 'read' => true]);
    }

    private static function requireProject(Request $request, ?string $projectId): array
    {
        if (!$projectId) Response::error('projectId required');
        $p = Database::getInstance()->fetch(
            'SELECT id FROM projects WHERE id = ? AND organization_id = ?',
            [$projectId, $request->user['organization_id']]
        );
        if (!$p) Response::error('Project not found', 404);
        return $p;
    }

    private static function loadMonitor(Request $request, ?string $id): array
    {
        if (!$id) Response::error('id required');
        $m = Database::getInstance()->fetch(
            "SELECT cm.*
             FROM competitor_monitors cm
             JOIN projects p ON p.id = cm.project_id
             WHERE cm.id = ? AND p.organization_id = ?",
            [$id, $request->user['organization_id']]
        );
        if (!$m) Response::error('Monitor not found', 404);
        return $m;
    }
}
