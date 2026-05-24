<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Project;

class ProjectController
{
    public function index(Request $request): void
    {
        $search = $request->getQuery('search');
        $page = max(1, (int)$request->getQuery('page', 1));
        $perPage = min(100, max(1, (int)$request->getQuery('per_page', 20)));

        $result = Project::getByOrganization(
            $request->user['organization_id'],
            $search,
            $page,
            $perPage
        );
        Response::paginated($result['items'], $result['total'], $page, $perPage);
    }

    public function store(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $name = trim($body['name'] ?? '');
        if ($name === '') Response::error('Project name is required');

        $id = Project::create([
            'organization_id' => $request->user['organization_id'],
            'name' => $name,
            'description' => $body['description'] ?? null,
            'center_lat' => $body['center_lat'] ?? null,
            'center_lng' => $body['center_lng'] ?? null,
            'zoom_level' => $body['zoom_level'] ?? 10,
            'created_by' => $request->user['id'],
        ]);
        Response::success(Project::findById($id), 'Project created', 201);
    }

    public function show(Request $request): void
    {
        $project = $this->verifyOwnership($request);
        $project['folders'] = \App\Models\Folder::getTreeByProject($project['id']);
        Response::success($project);
    }

    public function update(Request $request): void
    {
        $project = $this->verifyOwnership($request);
        $body = $request->getBody() ?? [];
        $update = [];
        foreach (['name', 'description', 'center_lat', 'center_lng', 'zoom_level', 'is_shared'] as $f) {
            if (array_key_exists($f, $body)) $update[$f] = $body[$f];
        }
        if (array_key_exists('is_shared', $body)) {
            $turningOn = !empty($body['is_shared']);
            $wasOn = !empty($project['is_shared']) && !empty($project['share_token']);
            if ($turningOn && !$wasOn) {
                // Only mint a token on the off→on transition. Re-PUTting
                // is_shared:true on an already-shared project must NOT
                // rotate the URL — otherwise existing links break silently.
                $update['share_token'] = Project::generateShareToken();
            } elseif (!$turningOn) {
                // Turning off invalidates any outstanding share link.
                $update['share_token'] = null;
            }
        }
        Project::update($project['id'], $update);
        Response::success(Project::findById($project['id']), 'Project updated');
    }

    public function destroy(Request $request): void
    {
        $project = $this->verifyOwnership($request);
        Project::delete($project['id']);
        Response::success(['id' => $project['id']], 'Project deleted');
    }

    /**
     * OP15 — soft archive: stamp `archived_at` so the project drops out of
     * the main list but data is preserved (90-day retention before cron
     * cleanup actually deletes). Body: { archived: true|false } toggles.
     */
    public function archive(Request $request): void
    {
        $project = $this->verifyOwnership($request);
        $body = $request->getBody() ?? [];
        $archived = !empty($body['archived']);
        \App\Core\Database::getInstance()->query(
            'UPDATE projects SET archived_at = ? WHERE id = ?',
            [$archived ? date('Y-m-d H:i:s') : null, $project['id']]
        );
        Response::success(['id' => $project['id'], 'archived' => $archived]);
    }

    /**
     * OP2 — export a project as a JSON bundle (areas + folders + project
     * meta + demographics snapshot). v1 is JSON, not ZIP, because PHP's
     * ZipArchive isn't installed everywhere and the bundle is small (<5MB
     * even for big projects). Client can save it as `.smappen` and re-import.
     */
    public function exportBundle(Request $request): void
    {
        $project = $this->verifyOwnership($request);
        $db = \App\Core\Database::getInstance();
        $areas = $db->fetchAll(
            'SELECT id, name, area_type, ST_AsGeoJSON(geometry) AS geometry, fill_color, stroke_color,
                    fill_opacity, stroke_weight, center_lat, center_lng, center_address, travel_mode,
                    travel_time_minutes, travel_distance_km, notes, demographics_cache, created_at
               FROM areas WHERE project_id = ?',
            [$project['id']]
        );
        $folders = $db->fetchAll('SELECT * FROM folders WHERE project_id = ?', [$project['id']]);
        $bundle = [
            'smappen_export_version' => 1,
            'exported_at' => date('c'),
            'project'  => $project,
            'folders'  => $folders,
            'areas'    => $areas,
        ];
        $fname = preg_replace('/[^a-z0-9-]+/i', '-', $project['name'] ?? 'project') . '-' . date('Ymd') . '.smappen.json';
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function shared(Request $request): void
    {
        $token = $request->getParam('shareToken');
        $project = Project::getByShareToken($token);
        if (!$project) Response::error('Shared project not found', 404);
        $project['folders'] = \App\Models\Folder::getTreeByProject($project['id']);
        $project['areas'] = \App\Models\Area::getByProject($project['id']);
        Response::success($project);
    }

    private function verifyOwnership(Request $request): array
    {
        $project = Project::findById($request->getParam('id'));
        if (!$project) Response::error('Project not found', 404);
        if ($project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        return $project;
    }
}
