<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Area;
use App\Models\Project;
use App\Services\CensusService;

class DemographicsController
{
    public function show(Request $request): void
    {
        $area = Area::findById($request->getParam('id'));
        if (!$area) Response::error('Area not found', 404);
        $project = Project::findById($area['project_id']);
        if (!$project || $project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        try {
            $demo = (new CensusService())->getDemographicsForArea($area['id']);
            // Demographics change ~once a year — long client cache is safe; the
            // Vary header makes sure the cached entry doesn't leak across users.
            Response::cacheable(86400);
            Response::success($demo);
        } catch (\Throwable $e) {
            Response::error('Demographics fetch failed: ' . $e->getMessage(), 502);
        }
    }

    public function compare(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $ids = $body['area_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) Response::error('area_ids required');
        if (count($ids) > 10) Response::error('Max 10 areas per comparison');

        $svc = new CensusService();
        $out = [];
        foreach ($ids as $id) {
            $area = Area::findById($id);
            if (!$area) continue;
            $project = Project::findById($area['project_id']);
            if (!$project || $project['organization_id'] !== $request->user['organization_id']) continue;
            $out[] = [
                'area_id' => $id,
                'area_name' => $area['name'],
                'demographics' => $svc->getDemographicsForArea($id),
            ];
        }
        Response::success($out);
    }
}
