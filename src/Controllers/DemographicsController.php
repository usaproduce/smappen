<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Area;
use App\Models\Project;
use App\Services\CensusService;
use App\Services\DemographicsHistoryService;
use App\Core\Database;

class DemographicsController
{
    /**
     * Trends sub-tab data. Pulls per-vintage profiles for every tract that
     * overlaps the area's geometry, averages weighted by overlap fraction,
     * and returns a tidy per-year series for the requested metric.
     */
    public function trends(Request $request): void
    {
        $area = Area::findById($request->getParam('id'));
        if (!$area) Response::error('Area not found', 404);
        $project = Project::findById($area['project_id']);
        if (!$project || $project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        $metric = $request->get('metric', 'total_population');

        $tracts = Database::getInstance()->fetchAll(
            'SELECT ct.geoid FROM census_tracts ct JOIN areas a ON a.id = :aid
              WHERE ST_Intersects(ct.geometry, a.geometry)',
            [':aid' => $area['id']]
        );
        $geoids = array_column($tracts, 'geoid');
        $series = (new DemographicsHistoryService())->avgForArea($geoids, $metric);
        Response::cacheable(86400);
        Response::success(['metric' => $metric, 'series' => $series]);
    }

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
            OnboardingController::stampActivation($request->user['id'], $request->user['organization_id'], 'first_demographic_at');
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
