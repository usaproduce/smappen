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

        if (!$projectId) Response::error('projectId required');
        if (!$bbox || count($bbox) !== 4) Response::error('bbox required as [minLng, minLat, maxLng, maxLat]');
        $project = self::loadProject($request, $projectId);

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
                        $name . ' ' . ($i + 1),
                        $t['centroid']['lat'], $t['centroid']['lng'],
                        $wkt, $color, $color,
                        json_encode([
                            'population' => $t['population'],
                            'median_household_income' => $t['median_household_income'],
                            'tract_count' => $t['tract_count'],
                            'tract_geoids' => $t['tract_geoids'],
                            'pop_share_pct' => $t['pop_share_pct'],
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
