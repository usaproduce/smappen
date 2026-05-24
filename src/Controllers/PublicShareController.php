<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\GeoUtils;

/**
 * Public read-only project view (#45 / #46). No auth — validates the
 * share_token from the URL. The project must have is_shared=1 and a
 * non-expired share_expires_at (or null = never expires).
 *
 *   GET /api/public/projects/{token}        full project payload
 *   GET /api/public/projects/{token}/embed  minimal payload for iframe widget
 */
class PublicShareController
{
    public function show(Request $request): void
    {
        $project = self::loadByToken($request->getParam('token'));
        $folders = Database::getInstance()->fetchAll(
            'SELECT id, name, color, sort_order FROM folders WHERE project_id = ?',
            [$project['id']]
        );
        $areas = Database::getInstance()->fetchAll(
            "SELECT a.id, a.name, a.area_type, a.fill_color, a.stroke_color, a.fill_opacity,
                    a.travel_mode, a.travel_time_minutes,
                    a.center_lat, a.center_lng, a.center_address,
                    ST_AsGeoJSON(a.geometry, 6) AS geom_json,
                    a.demographics_cache
             FROM areas a WHERE a.project_id = ?",
            [$project['id']]
        );
        foreach ($areas as &$a) {
            $a['geometry'] = $a['geom_json'] ? GeoUtils::swapGeometry(json_decode($a['geom_json'], true)) : null;
            unset($a['geom_json']);
            if (!empty($a['demographics_cache'])) {
                $a['demographics'] = json_decode($a['demographics_cache'], true);
                unset($a['demographics_cache']);
            }
        }
        self::bumpViewCount($project['id']);
        Response::success([
            'project' => [
                'id' => $project['id'],
                'name' => $project['name'],
                'description' => $project['description'],
                'center_lat' => $project['center_lat'],
                'center_lng' => $project['center_lng'],
                'zoom_level' => $project['zoom_level'],
            ],
            'folders' => $folders,
            'areas' => $areas,
            'view_count' => (int)$project['share_view_count'] + 1,
        ]);
    }

    public function embed(Request $request): void
    {
        $project = self::loadByToken($request->getParam('token'));
        // Embed view is intentionally lighter: just polygons + colors, no demographics.
        $areas = Database::getInstance()->fetchAll(
            "SELECT a.id, a.name, a.fill_color, a.stroke_color, a.fill_opacity,
                    ST_AsGeoJSON(a.geometry, 4) AS geom_json
             FROM areas a WHERE a.project_id = ?",
            [$project['id']]
        );
        foreach ($areas as &$a) {
            $a['geometry'] = $a['geom_json'] ? GeoUtils::swapGeometry(json_decode($a['geom_json'], true)) : null;
            unset($a['geom_json']);
        }
        self::bumpViewCount($project['id']);
        Response::success([
            'project_id' => $project['id'],
            'name' => $project['name'],
            'center_lat' => $project['center_lat'],
            'center_lng' => $project['center_lng'],
            'zoom_level' => $project['zoom_level'],
            'areas' => $areas,
        ]);
    }

    private static function loadByToken(?string $token): array
    {
        // The PHP type-hint above says `?string` — the router may pass null
        // when the URL is malformed (e.g. /api/public/projects/ with trailing
        // slash). Bail with a stable 404 before mb_strlen() throws on null.
        if ($token === null || $token === '') {
            Response::error('Share token required', 404);
        }
        if (mb_strlen($token) < 8) Response::error('Invalid share token', 404);
        $row = Database::getInstance()->fetch(
            'SELECT * FROM projects WHERE share_token = ? AND is_shared = 1',
            [$token]
        );
        if (!$row) Response::error('Share link not found or revoked', 404);
        if (!empty($row['share_expires_at']) && strtotime($row['share_expires_at']) < time()) {
            Response::error('Share link has expired', 410);
        }
        return $row;
    }

    private static function bumpViewCount(string $projectId): void
    {
        try {
            Database::getInstance()->query(
                'UPDATE projects SET share_view_count = share_view_count + 1 WHERE id = ?',
                [$projectId]
            );
        } catch (\Throwable $e) {}
    }
}
