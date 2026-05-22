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
            if (!empty($body['is_shared'])) {
                // Always generate a fresh token when sharing turns on, so old links
                // can't be silently reactivated by re-toggling.
                $update['share_token'] = Project::generateShareToken();
            } else {
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
