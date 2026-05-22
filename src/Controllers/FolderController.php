<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Folder;
use App\Models\Project;

class FolderController
{
    public function index(Request $request): void
    {
        $project = $this->verifyProject($request);
        Response::success(Folder::getTreeByProject($project['id']));
    }

    public function store(Request $request): void
    {
        $project = $this->verifyProject($request);
        $body = $request->getBody() ?? [];
        $name = trim($body['name'] ?? '');
        if ($name === '') Response::error('Folder name is required');
        $id = Folder::create([
            'project_id' => $project['id'],
            'name' => $name,
            'color' => $body['color'] ?? '#6B4EFF',
            'sort_order' => (int)($body['sort_order'] ?? 0),
            'parent_folder_id' => $body['parent_folder_id'] ?? null,
        ]);
        Response::success(Folder::findById($id), 'Folder created', 201);
    }

    public function update(Request $request): void
    {
        $folder = Folder::findById($request->getParam('id'));
        if (!$folder) Response::error('Folder not found', 404);
        $project = Project::findById($folder['project_id']);
        if (!$project || $project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        $body = $request->getBody() ?? [];
        $update = [];
        foreach (['name', 'color', 'parent_folder_id', 'sort_order'] as $f) {
            if (array_key_exists($f, $body)) $update[$f] = $body[$f];
        }
        Folder::update($folder['id'], $update);
        Response::success(Folder::findById($folder['id']), 'Folder updated');
    }

    public function destroy(Request $request): void
    {
        $folder = Folder::findById($request->getParam('id'));
        if (!$folder) Response::error('Folder not found', 404);
        $project = Project::findById($folder['project_id']);
        if (!$project || $project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        Folder::delete($folder['id']);
        Response::success(['id' => $folder['id']], 'Folder deleted');
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
}
