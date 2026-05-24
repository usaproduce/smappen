<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Area;
use App\Models\Project;

class AreaController
{
    public function index(Request $request): void
    {
        $project = $this->verifyProject($request);
        $folderId = $request->getQuery('folder_id');
        $areas = Area::getByProject($project['id'], $folderId);
        $features = array_map(fn($a) => [
            'type' => 'Feature',
            'id' => $a['id'],
            'geometry' => $a['geometry'] ?? null,
            'properties' => [
                'name' => $a['name'],
                'area_type' => $a['area_type'],
                'travel_mode' => $a['travel_mode'],
                'travel_time_minutes' => $a['travel_time_minutes'],
                'fill_color' => $a['fill_color'],
                'fill_opacity' => (float)$a['fill_opacity'],
                'stroke_color' => $a['stroke_color'],
                'stroke_weight' => (int)$a['stroke_weight'],
                'folder_id' => $a['folder_id'],
                'center_lat' => $a['center_lat'],
                'center_lng' => $a['center_lng'],
                'center_address' => $a['center_address'],
                'notes' => $a['notes'],
                'demographics_cache' => $a['demographics_cache'] ?? null,
            ],
        ], $areas);
        Response::success(['type' => 'FeatureCollection', 'features' => $features]);
    }

    public function store(Request $request): void
    {
        $project = $this->verifyProject($request);
        $body = $request->getBody() ?? [];
        $name = trim($body['name'] ?? '');
        $geometry = $body['geometry'] ?? null;
        if ($name === '') Response::error('Area name is required');
        if (!$geometry) Response::error('Geometry is required');

        $id = Area::create([
            'project_id' => $project['id'],
            'folder_id' => $body['folder_id'] ?? null,
            'name' => $name,
            'area_type' => $body['area_type'] ?? 'isochrone',
            'center_lat' => $body['center_lat'] ?? null,
            'center_lng' => $body['center_lng'] ?? null,
            'center_address' => $body['center_address'] ?? null,
            'travel_mode' => $body['travel_mode'] ?? null,
            'travel_time_minutes' => $body['travel_time_minutes'] ?? null,
            'travel_distance_km' => $body['travel_distance_km'] ?? null,
            'fill_color' => $body['fill_color'] ?? '#6B4EFF',
            'fill_opacity' => $body['fill_opacity'] ?? 0.3,
            'stroke_color' => $body['stroke_color'] ?? '#6B4EFF',
            'stroke_weight' => $body['stroke_weight'] ?? 2,
            'notes' => $body['notes'] ?? null,
            'created_by' => $request->user['id'],
            'geometry' => $geometry,
        ]);
        OnboardingController::stampActivation($request->user['id'], $request->user['organization_id'], 'first_area_at');
        Response::success(Area::findById($id), 'Area created', 201);
    }

    public function show(Request $request): void
    {
        $area = $this->verifyArea($request);
        Response::success($area);
    }

    public function update(Request $request): void
    {
        $area = $this->verifyArea($request);
        $body = $request->getBody() ?? [];
        $update = [];
        foreach (['name', 'folder_id', 'fill_color', 'fill_opacity', 'stroke_color', 'stroke_weight', 'notes',
                  'travel_mode', 'travel_time_minutes', 'travel_distance_km', 'center_address',
                  'is_favorite'] as $f) {
            if (array_key_exists($f, $body)) {
                // Coerce booleans on the favorite flag so the JSON `true/false`
                // → MySQL 1/0 round-trips cleanly.
                $update[$f] = $f === 'is_favorite' ? ($body[$f] ? 1 : 0) : $body[$f];
            }
        }
        // folder_id must belong to the same project as the area (no cross-project moves).
        if (!empty($update['folder_id'])) {
            $folder = \App\Models\Folder::findById($update['folder_id']);
            if (!$folder || $folder['project_id'] !== $area['project_id']) {
                Response::error('folder_id must be in the same project as the area', 422);
            }
        }
        if (isset($body['geometry'])) $update['geometry'] = $body['geometry'];
        Area::update($area['id'], $update);
        Response::success(Area::findById($area['id']), 'Area updated');
    }

    public function destroy(Request $request): void
    {
        $area = $this->verifyArea($request);
        Area::delete($area['id']);
        Response::success(['id' => $area['id']], 'Area deleted');
    }

    /**
     * BF7 — persist drag-reorder. Body: { area_ids: [id1, id2, ...] } in the
     * desired order. Server stamps sort_order = index. All ids must belong
     * to the same project (and the caller's org) — silently skips any that
     * don't, so a maliciously crafted list can't reorder another tenant's
     * areas.
     */
    public function reorder(Request $request): void
    {
        $project = $this->verifyProject($request);
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $ids = $body['area_ids'] ?? null;
        if (!is_array($ids) || empty($ids)) Response::error('area_ids required', 422);

        $db = \App\Core\Database::getInstance();
        // Single multi-row update: CASE WHEN id = ? THEN N ELSE sort_order END
        $cases = []; $params = [];
        $valid = [];
        foreach ($ids as $i => $id) {
            if (!is_string($id) || $id === '') continue;
            $cases[] = 'WHEN ? THEN ?';
            $params[] = $id;
            $params[] = $i;
            $valid[] = $id;
        }
        if (empty($valid)) Response::error('No valid ids', 422);
        $placeholders = implode(',', array_fill(0, count($valid), '?'));
        $sql = "UPDATE areas SET sort_order = CASE id " . implode(' ', $cases)
             . " END WHERE id IN ($placeholders) AND project_id = ?";
        $db->query($sql, array_merge($params, $valid, [$project['id']]));
        Response::success(['count' => count($valid)]);
    }

    private function verifyProject(Request $request): array
    {
        $project = Project::findById($request->getParam('projectId'));
        if (!$project) Response::error('Project not found', 404);
        if ($project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        return $project;
    }

    private function verifyArea(Request $request): array
    {
        $area = Area::findById($request->getParam('id'));
        if (!$area) Response::error('Area not found', 404);
        $project = Project::findById($area['project_id']);
        if (!$project || $project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        return $area;
    }
}
