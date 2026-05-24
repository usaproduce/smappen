<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\GeoUtils;
use App\Services\TerritoryGenerator;

/**
 * Auto territory generation — POST /api/projects/{projectId}/territories/generate
 * Runs synchronously; persists a job row + one area per resulting territory.
 */
class TerritoryController
{
    public function generate(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        $body = $request->getBody() ?? [];
        $target = (int)($body['target_count'] ?? 10);
        $metric = (string)($body['balance_metric'] ?? 'population');
        $bbox = $body['bbox'] ?? null;
        $name = (string)($body['name'] ?? 'Territory');
        $constraints = (array)($body['constraints'] ?? []);
        $asyncQueue = !empty($body['async']);

        if (!$projectId) Response::error('projectId required');
        if (!$bbox || count($bbox) !== 4) Response::error('bbox required as [minLng, minLat, maxLng, maxLat]');
        $project = self::loadProject($request, $projectId);

        // Async path: enqueue a job, return 202 with the job id. The job worker
        // (scripts/job-worker.php) runs the same TerritoryGenerator pipeline.
        if ($asyncQueue) {
            $jobId = Database::uuid();
            Database::getInstance()->query(
                'INSERT INTO jobs
                   (id, user_id, organization_id, project_id, type, payload,
                    status, available_at, created_at)
                 VALUES (?, ?, ?, ?, "territory.generate", ?, "queued", NOW(), NOW())',
                [
                    $jobId,
                    $request->user['id'],
                    $request->user['organization_id'],
                    $projectId,
                    json_encode([
                        'bbox' => $bbox,
                        'target_count' => $target,
                        'balance_metric' => $metric,
                        'name' => $name,
                        'constraints' => $constraints,
                    ]),
                ]
            );
            Response::json(['success' => true, 'data' => [
                'job_id' => $jobId,
                'status' => 'queued',
                'poll_url' => '/api/jobs/' . $jobId,
            ]], 202);
        }

        $jobId = Database::uuid();
        $db = Database::getInstance();
        $db->query(
            'INSERT INTO territory_generation_jobs
               (id, project_id, user_id, status, method, target_count, balance_metric,
                region_bbox, constraints_json, started_at, created_at)
             VALUES (?, ?, ?, "running", "k_means_balanced", ?, ?, ?, ?, NOW(), NOW())',
            [
                $jobId,
                $projectId,
                $request->user['id'],
                $target,
                $metric,
                json_encode(['minLng' => $bbox[0], 'minLat' => $bbox[1], 'maxLng' => $bbox[2], 'maxLat' => $bbox[3]]),
                json_encode($constraints),
            ]
        );

        ini_set('memory_limit', '768M');
        set_time_limit(60);

        try {
            $gen = new TerritoryGenerator();
            $result = $gen->run($bbox, $target, $metric, $constraints);
        } catch (\Throwable $e) {
            $db->query(
                'UPDATE territory_generation_jobs
                 SET status = "failed", error_message = ?, finished_at = NOW()
                 WHERE id = ?',
                [substr($e->getMessage(), 0, 5000), $jobId]
            );
            Response::error('Territory generation failed: ' . $e->getMessage(), 500);
        }

        $palette = ['#7848BB', '#22c55e', '#3b82f6', '#f59e0b', '#ef4444', '#ec4899',
                    '#06b6d4', '#a855f7', '#14b8a6', '#f97316', '#6366f1', '#84cc16',
                    '#0ea5e9', '#d946ef', '#10b981', '#eab308', '#dc2626', '#8b5cf6',
                    '#f43f5e', '#0284c7', '#65a30d', '#c026d3', '#0d9488', '#b45309',
                    '#15803d', '#1d4ed8', '#a16207', '#9333ea', '#0369a1', '#b91c1c'];

        // Compute a compass-bearing name for each territory relative to the
        // overall bbox center — "NW Territory" instead of "Territory 3". Tie-
        // breaker is the centroid index so names are deterministic.
        $bboxCenter = [
            'lat' => ($bbox[1] + $bbox[3]) / 2,
            'lng' => ($bbox[0] + $bbox[2]) / 2,
        ];
        $directionalNames = self::assignDirectionalNames($result['territories'], $bboxCenter, $name);

        $areaIds = [];
        foreach ($result['territories'] as $i => $t) {
            try {
                $wkt = GeoUtils::geoJsonToWkt($t['geometry']);
                $areaId = Database::uuid();
                $color = $palette[$i % count($palette)];
                $db->query(
                    "INSERT INTO areas
                       (id, project_id, name, area_type, center_lat, center_lng,
                        geometry, fill_color, fill_opacity, stroke_color, stroke_weight,
                        demographics_cache, demographics_cached_at,
                        created_by, generation_job_id, territory_index,
                        created_at, updated_at)
                     VALUES (?, ?, ?, 'manual', ?, ?,
                             ST_GeomFromText(?, 4326), ?, 0.30, ?, 2,
                             ?, NOW(),
                             ?, ?, ?,
                             NOW(), NOW())",
                    [
                        $areaId, $projectId,
                        $directionalNames[$i],
                        $t['centroid']['lat'], $t['centroid']['lng'],
                        $wkt, $color, $color,
                        json_encode([
                            'population' => $t['population'],
                            'median_household_income' => $t['median_household_income'],
                            'tract_count' => $t['tract_count'],
                            'tract_geoids' => $t['tract_geoids'],
                            'pop_share_pct' => $t['pop_share_pct'],
                            'compass_label' => self::compassLabel($bboxCenter, $t['centroid']),
                        ]),
                        $request->user['id'],
                        $jobId,
                        $i,
                    ]
                );
                $areaIds[] = $areaId;
            } catch (\Throwable $e) {
                error_log('Territory area insert failed: ' . $e->getMessage());
            }
        }

        $db->query(
            'UPDATE territory_generation_jobs
             SET status = "done", progress_pct = 100, finished_at = NOW(),
                 result_summary = ?
             WHERE id = ?',
            [
                json_encode([
                    'territory_count' => count($result['territories']),
                    'tract_count' => $result['tract_count'],
                    'iterations' => $result['iterations'],
                    'area_ids' => $areaIds,
                ]),
                $jobId,
            ]
        );

        Response::success([
            'job_id' => $jobId,
            'status' => 'done',
            'territory_count' => count($result['territories']),
            'tract_count' => $result['tract_count'],
            'area_ids' => $areaIds,
            'territories' => array_map(fn($t) => [
                'index' => $t['index'],
                'population' => $t['population'],
                'median_household_income' => $t['median_household_income'],
                'tract_count' => $t['tract_count'],
                'pop_share_pct' => $t['pop_share_pct'],
            ], $result['territories']),
        ]);
    }

    public function listJobs(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        if (!$projectId) Response::error('projectId required');
        self::loadProject($request, $projectId);
        $jobs = Database::getInstance()->fetchAll(
            'SELECT id, status, method, target_count, balance_metric,
                    progress_pct, started_at, finished_at, created_at,
                    result_summary, error_message
             FROM territory_generation_jobs
             WHERE project_id = ?
             ORDER BY created_at DESC
             LIMIT 50',
            [$projectId]
        );
        foreach ($jobs as &$j) {
            if (!empty($j['result_summary'])) {
                $j['result_summary'] = json_decode($j['result_summary'], true);
            }
        }
        Response::success(['jobs' => $jobs]);
    }

    /**
     * Rebuild a territory's polygon from its source tracts via pairwise
     * ST_Union — turns the convex hull (fast, deterministic, ugly) into a
     * real tract-following boundary (slow, pretty). Iterates in PHP because
     * MySQL's ST_Union is binary, not aggregate.
     *
     * POST /api/areas/{id}/rebuild-boundary
     */
    /**
     * OP23 — bulk-rebuild every auto-generated territory in a project.
     * Iterates each area that has a generation_job_id (= came out of
     * territory generation) and applies the same ST_Union-over-source-
     * tracts pass that rebuildBoundary does individually. Returns a
     * per-area success/failure roll-up.
     */
    public function bulkRebuild(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        if (!$projectId) Response::error('projectId required');
        $project = self::loadProject($request, $projectId);

        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        $areas = Database::getInstance()->fetchAll(
            'SELECT id, name FROM areas WHERE project_id = ? AND generation_job_id IS NOT NULL',
            [$project['id']]
        );

        $ok = 0; $failed = 0; $details = [];
        foreach ($areas as $a) {
            try {
                // Reuse the existing rebuild logic via a synthetic request hop.
                // Cheaper than duplicating it; the controller method runs the
                // ST_Union pass and updates the row.
                $r = new \App\Core\Request();
                $r->setParam('id', $a['id']);
                $r->user = $request->user;
                ob_start();
                $this->rebuildBoundary($r);
                ob_end_clean();
                $ok++;
                $details[] = ['id' => $a['id'], 'name' => $a['name'], 'status' => 'ok'];
            } catch (\Throwable $e) {
                $failed++;
                $details[] = ['id' => $a['id'], 'name' => $a['name'], 'status' => 'error', 'error' => $e->getMessage()];
                error_log('bulkRebuild ' . $a['id'] . ' failed: ' . $e->getMessage());
            }
        }
        Response::success(['ok' => $ok, 'failed' => $failed, 'total' => count($areas), 'details' => $details]);
    }

    public function rebuildBoundary(Request $request): void
    {
        $areaId = $request->getParam('id');
        if (!$areaId) Response::error('id required');
        $area = Database::getInstance()->fetch(
            'SELECT a.*, p.organization_id FROM areas a JOIN projects p ON p.id = a.project_id WHERE a.id = ?',
            [$areaId]
        );
        if (!$area) Response::error('Area not found', 404);
        if ($area['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        $cache = $area['demographics_cache'] ? json_decode($area['demographics_cache'], true) : [];
        $tractIds = $cache['tract_geoids'] ?? [];
        if (empty($tractIds)) Response::error('Area has no source tracts to dissolve', 400);

        ini_set('memory_limit', '768M');
        set_time_limit(60);

        // Pairwise ST_Union — accumulate into a single working WKT.
        // 500-tract territories take ~8s on the droplet; OK for an on-demand
        // op that runs once per territory.
        $db = Database::getInstance();
        $current = null;
        $batch = 50;
        for ($i = 0; $i < count($tractIds); $i += $batch) {
            $chunk = array_slice($tractIds, $i, $batch);
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            // Fold the chunk into one polygon first (pairwise loop inside SQL via repeated UNION).
            $rows = $db->fetchAll(
                "SELECT ST_AsText(geometry) AS wkt FROM census_tracts WHERE geoid IN ($placeholders)",
                $chunk
            );
            foreach ($rows as $r) {
                if ($current === null) {
                    $current = $r['wkt'];
                    continue;
                }
                $merged = $db->fetch(
                    "SELECT ST_AsText(ST_Union(ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326))) AS wkt",
                    [$current, $r['wkt']]
                );
                $current = $merged['wkt'] ?? $current;
            }
        }
        if (!$current) Response::error('Failed to build union', 500);

        // Persist back as the area's geometry.
        $db->query(
            'UPDATE areas SET geometry = ST_GeomFromText(?, 4326), updated_at = NOW() WHERE id = ?',
            [$current, $areaId]
        );
        Response::success(['id' => $areaId, 'rebuilt' => true]);
    }

    /** Map a centroid → 8-point compass label relative to a reference point. */
    private static function compassLabel(array $center, array $point): string
    {
        $dLat = $point['lat'] - $center['lat'];
        $dLng = $point['lng'] - $center['lng'];
        // Compute bearing in degrees (0 = N, 90 = E, 180 = S, 270 = W).
        $angle = rad2deg(atan2($dLng, $dLat));
        if ($angle < 0) $angle += 360;
        $directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        // 22.5° wedges centered on each cardinal/intercardinal direction.
        $idx = (int) floor((($angle + 22.5) % 360) / 45);
        return $directions[$idx % 8];
    }

    /**
     * Produce unique, human-readable names like "NW Territory", "NW Territory 2".
     * Within each compass direction, we order by population descending so the
     * biggest territory gets the unsuffixed name.
     */
    private static function assignDirectionalNames(array $territories, array $center, string $prefix): array
    {
        $byDir = [];
        foreach ($territories as $i => $t) {
            $dir = self::compassLabel($center, $t['centroid']);
            $byDir[$dir][] = ['idx' => $i, 'pop' => $t['population'] ?? 0];
        }
        $out = [];
        foreach ($byDir as $dir => $list) {
            usort($list, fn($a, $b) => $b['pop'] <=> $a['pop']);
            foreach ($list as $rank => $item) {
                $label = "$dir $prefix" . ($rank > 0 ? ' ' . ($rank + 1) : '');
                $out[$item['idx']] = $label;
            }
        }
        ksort($out);
        return $out;
    }

    private static function loadProject(Request $request, string $projectId): array
    {
        $org = $request->user['organization_id'] ?? null;
        if (!$org) Response::error('User has no organization', 403);
        $p = Database::getInstance()->fetch(
            'SELECT id FROM projects WHERE id = ? AND organization_id = ?',
            [$projectId, $org]
        );
        if (!$p) Response::error('Project not found', 404);
        return $p;
    }
}
