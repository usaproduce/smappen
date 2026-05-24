<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Custom data layers — user-uploaded points (typically a customer CSV) that
 * render as either a marker layer or a derived heatmap on top of the map.
 *
 * Routes:
 *   GET    /api/projects/{projectId}/custom-layers
 *   POST   /api/projects/{projectId}/custom-layers
 *   PUT    /api/custom-layers/{id}
 *   DELETE /api/custom-layers/{id}
 *   GET    /api/custom-layers/{id}/points    (joins imported_points by source_import_batch)
 *
 * The actual point data lives in `imported_points` (existing table) — this
 * controller just manages the layer metadata: name, kind (point|heatmap),
 * palette, radius, visibility, and which CSV column to use as the metric.
 */
class CustomLayerController
{
    public function index(Request $request): void
    {
        $project = $this->verifyProject($request);
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM custom_layers WHERE project_id = ? ORDER BY created_at DESC',
            [$project['id']]
        );
        Response::success(['layers' => $rows]);
    }

    public function create(Request $request): void
    {
        $project = $this->verifyProject($request);
        $b = $request->getBody() ?? [];
        $name = trim($b['name'] ?? '');
        if ($name === '') Response::error('name required', 422);
        $kind = $b['kind'] ?? 'point';
        if (!in_array($kind, ['point', 'heatmap'], true)) Response::error('kind must be point|heatmap', 422);

        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO custom_layers
                (id, organization_id, project_id, name, kind, source_import_batch,
                 metric_column, palette_id, radius_meters, visible, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())',
            [
                $id, $request->user['organization_id'], $project['id'], $name, $kind,
                $b['source_import_batch'] ?? null,
                $b['metric_column']       ?? null,
                $b['palette_id']          ?? 'viridis',
                isset($b['radius_meters']) ? (int) $b['radius_meters'] : 800,
            ]
        );
        Response::success(['id' => $id], 'Layer created', 201);
    }

    public function update(Request $request): void
    {
        $id = $request->getParam('id');
        $row = $this->loadOwned($request, $id);
        $b = $request->getBody() ?? [];
        $fields = []; $params = [];
        foreach (['name', 'kind', 'metric_column', 'palette_id'] as $k) {
            if (array_key_exists($k, $b)) { $fields[] = "$k = ?"; $params[] = $b[$k]; }
        }
        if (array_key_exists('radius_meters', $b)) { $fields[] = 'radius_meters = ?'; $params[] = (int) $b['radius_meters']; }
        if (array_key_exists('visible', $b))       { $fields[] = 'visible = ?';       $params[] = $b['visible'] ? 1 : 0; }
        if (!$fields) Response::error('Nothing to update', 422);
        $params[] = $id;
        Database::getInstance()->query('UPDATE custom_layers SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
        Response::success([]);
    }

    public function destroy(Request $request): void
    {
        $id = $request->getParam('id');
        $this->loadOwned($request, $id);
        Database::getInstance()->query('DELETE FROM custom_layers WHERE id = ?', [$id]);
        Response::success([]);
    }

    /**
     * Resolve a layer to its raw imported points. Joins by
     * `source_import_batch` since one CSV upload can back many layers.
     */
    public function points(Request $request): void
    {
        $id = $request->getParam('id');
        $layer = $this->loadOwned($request, $id);
        if (!$layer['source_import_batch']) Response::success(['points' => []]);
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, lat, lng, label, meta_json
               FROM imported_points
              WHERE import_batch_id = ? LIMIT 50000',
            [$layer['source_import_batch']]
        );
        foreach ($rows as &$r) {
            $r['meta'] = json_decode($r['meta_json'] ?? '{}', true);
            unset($r['meta_json']);
        }
        Response::cacheable(3600);
        Response::success(['points' => $rows, 'layer' => $layer]);
    }

    private function verifyProject(Request $r): array
    {
        $p = Database::getInstance()->fetch(
            'SELECT id, organization_id FROM projects WHERE id = ?',
            [$r->getParam('projectId')]
        );
        if (!$p) Response::error('Project not found', 404);
        if ($p['organization_id'] !== $r->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        return $p;
    }

    private function loadOwned(Request $r, string $id): array
    {
        $row = Database::getInstance()->fetch(
            'SELECT cl.* FROM custom_layers cl
              WHERE cl.id = ? AND cl.organization_id = ?',
            [$id, $r->user['organization_id']]
        );
        if (!$row) Response::error('Layer not found', 404);
        return $row;
    }
}
