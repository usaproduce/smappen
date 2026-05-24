<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\SegmentationService;

/**
 * Customer segmentation endpoints.
 *  GET  /api/segmentation/segments              list segment catalog
 *  GET  /api/areas/{id}/segments                breakdown for one area
 *  POST /api/projects/{projectId}/segments      breakdown for project (all areas combined)
 *  POST /api/segmentation/recompute             admin/cron: re-classify all tracts
 */
class SegmentationController
{
    public function catalog(Request $request): void
    {
        Response::cacheable(3600, true);
        Response::success([
            'segments' => SegmentationService::SEGMENTS,
            'description' => 'Tracts are auto-classified into one of these 10 segments using Census ACS features.',
        ]);
    }

    public function forArea(Request $request): void
    {
        $areaId = $request->getParam('id');
        if (!$areaId) Response::error('id required');
        $area = Database::getInstance()->fetch(
            "SELECT a.id, a.project_id, ST_AsText(a.geometry) AS wkt
             FROM areas a
             JOIN projects p ON p.id = a.project_id
             WHERE a.id = ? AND p.organization_id = ?",
            [$areaId, $request->user['organization_id']]
        );
        if (!$area) Response::error('Area not found', 404);

        $rows = self::segmentBreakdown($area['wkt']);
        Response::cacheable(86400);
        Response::success([
            'area_id' => $areaId,
            'segments' => $rows,
            'total_population' => array_sum(array_column($rows, 'population')),
        ]);
    }

    public function forProject(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        if (!$projectId) Response::error('projectId required');
        $org = $request->user['organization_id'] ?? null;
        $p = Database::getInstance()->fetch(
            'SELECT id FROM projects WHERE id = ? AND organization_id = ?',
            [$projectId, $org]
        );
        if (!$p) Response::error('Project not found', 404);

        $areas = Database::getInstance()->fetchAll(
            "SELECT id, name, ST_AsText(geometry) AS wkt FROM areas WHERE project_id = ?",
            [$projectId]
        );
        $perArea = [];
        $totals = [];
        foreach ($areas as $a) {
            $rows = self::segmentBreakdown($a['wkt']);
            $perArea[] = [
                'area_id' => $a['id'],
                'area_name' => $a['name'],
                'segments' => $rows,
            ];
            foreach ($rows as $r) {
                if (!isset($totals[$r['segment_id']])) {
                    $totals[$r['segment_id']] = [
                        'segment_id' => $r['segment_id'],
                        'segment_name' => $r['segment_name'],
                        'population' => 0,
                        'tract_count' => 0,
                    ];
                }
                $totals[$r['segment_id']]['population'] += $r['population'];
                $totals[$r['segment_id']]['tract_count'] += $r['tract_count'];
            }
        }
        usort($totals, fn($a, $b) => $b['population'] <=> $a['population']);
        Response::success([
            'project_id' => $projectId,
            'totals' => array_values($totals),
            'per_area' => $perArea,
        ]);
    }

    public function recompute(Request $request): void
    {
        // Only owners/admins of the org can rerun this — it's expensive.
        $role = $request->user['role'] ?? 'member';
        if (!in_array($role, ['owner', 'admin'], true)) {
            Response::error('Only org owners/admins may recompute segments', 403);
        }
        ini_set('memory_limit', '1024M');
        set_time_limit(300);
        $svc = new SegmentationService();
        $n = $svc->recomputeAll();
        Response::success(['classified' => $n]);
    }

    /**
     * Per-tract population × overlap_pct, grouped by segment.
     */
    private static function segmentBreakdown(string $wkt): array
    {
        $sql = "
            SELECT
              ts.segment_id,
              ts.segment_name,
              SUM(d.total_population * inter.overlap_pct) AS pop,
              COUNT(*) AS tract_count
            FROM (
              SELECT ct.geoid,
                     CASE WHEN ST_GeometryType(ST_Intersection(ct.geometry, ST_GeomFromText(?, 4326)))
                              IN ('Polygon','MultiPolygon')
                          THEN ST_Area(ST_Intersection(ct.geometry, ST_GeomFromText(?, 4326)))
                               / NULLIF(ST_Area(ct.geometry), 0)
                          ELSE 0 END AS overlap_pct
              FROM census_tracts ct
              WHERE ST_Intersects(ct.geometry, ST_GeomFromText(?, 4326))
            ) inter
            JOIN census_demographics d ON d.geoid = inter.geoid
            JOIN tract_segments ts ON ts.geoid = inter.geoid
            WHERE inter.overlap_pct > 0
            GROUP BY ts.segment_id, ts.segment_name
            ORDER BY pop DESC
        ";
        try {
            $rows = Database::getInstance()->fetchAll($sql, [$wkt, $wkt, $wkt]);
        } catch (\Throwable $e) {
            error_log('Segmentation breakdown: ' . $e->getMessage());
            return [];
        }
        $palette = [];
        foreach (SegmentationService::SEGMENTS as $s) $palette[$s['id']] = $s['color'];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'segment_id' => $r['segment_id'],
                'segment_name' => $r['segment_name'],
                'color' => $palette[$r['segment_id']] ?? '#7848BB',
                'population' => (int) round((float)$r['pop']),
                'tract_count' => (int)$r['tract_count'],
            ];
        }
        return $out;
    }
}
