<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Models\Area;
use App\Models\Project;
use App\Models\POICache;
use App\Services\GoogleMapsService;
use App\Services\GeoUtils;

class PlacesController
{
    public function nearby(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $lat = (float)($body['lat'] ?? 0);
        $lng = (float)($body['lng'] ?? 0);
        $radius = min(50000, max(1, (int)($body['radius_meters'] ?? 5000)));
        $type = $body['type'] ?? null;
        $keyword = $body['keyword'] ?? null;
        $areaId = $body['area_id'] ?? null;

        try {
            $svc = new GoogleMapsService();
            $places = $svc->searchPlacesNearby($lat, $lng, $radius, $type, $keyword);
            $svc->logApiUsage('places_nearby', $request->user['id']);
        } catch (\Throwable $e) {
            Response::error('Places search failed: ' . $e->getMessage(), 502);
        }

        if ($areaId) {
            $area = Area::findById($areaId);
            // Verify ownership so a caller can't poison another org's POI cache.
            if ($area) {
                $proj = Project::findById($area['project_id']);
                if (!$proj || $proj['organization_id'] !== $request->user['organization_id']) {
                    Response::error('Access denied', 403);
                }
            }
            if ($area && !empty($area['geometry'])) {
                $polygon = $area['geometry'];
                $places = array_values(array_filter($places, function ($p) use ($polygon) {
                    $loc = $p['location'] ?? null;
                    if (!$loc) return false;
                    return GeoUtils::pointInPolygon(
                        (float)($loc['latitude'] ?? 0),
                        (float)($loc['longitude'] ?? 0),
                        $polygon
                    );
                }));
                // Key matches what forArea/Reports/Exports read.
                POICache::store(md5('area:' . $areaId), $areaId, $places);
            }
        }
        Response::success(['places' => $places, 'count' => count($places)]);
    }

    public function search(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $query = trim($body['query'] ?? '');
        if ($query === '') Response::error('query is required');
        $lat = isset($body['lat']) ? (float)$body['lat'] : null;
        $lng = isset($body['lng']) ? (float)$body['lng'] : null;
        $radius = isset($body['radius_meters']) ? (int)$body['radius_meters'] : null;

        try {
            $svc = new GoogleMapsService();
            $places = $svc->searchPlacesText($query, $lat, $lng, $radius);
            $svc->logApiUsage('places_search', $request->user['id']);
            Response::success(['places' => $places, 'count' => count($places)]);
        } catch (\Throwable $e) {
            Response::error('Places search failed: ' . $e->getMessage(), 502);
        }
    }

    public function show(Request $request): void
    {
        $placeId = $request->getParam('placeId');
        try {
            $svc = new GoogleMapsService();
            $details = $svc->getPlaceDetails($placeId);
            Response::success($details);
        } catch (\Throwable $e) {
            Response::error('Place details failed: ' . $e->getMessage(), 502);
        }
    }

    public function forArea(Request $request): void
    {
        $areaId = $request->getParam('id');
        $area = Area::findById($areaId);
        if (!$area) Response::error('Area not found', 404);
        $project = Project::findById($area['project_id']);
        if (!$project || $project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        $cached = POICache::get(md5('area:' . $areaId));
        if ($cached) {
            Response::success(['places' => $cached['results'], 'cached_at' => $cached['cached_at']]);
        }
        Response::success(['places' => [], 'message' => 'No POIs cached. Run a nearby search first.']);
    }
}
