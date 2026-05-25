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
            // Post-normalize: ST_AsGeoJSON for SRID 4326 already emits standard
            // [lng, lat] under the unified (X=lat, Y=lng) storage. No swap.
            $a['geometry'] = $a['geom_json'] ? json_decode($a['geom_json'], true) : null;
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

    /**
     * Render the configured iframe embed by its embed_token. Separate from
     * embed() (which keys off projects.share_token) — embeds are first-class
     * rows in the `embeds` table with their own config + view_count.
     *
     * GET /api/public/embeds/{token}
     */
    public function embedByToken(Request $request): void
    {
        $token = $request->getParam('token');
        if ($token === null || $token === '' || mb_strlen($token) < 8) {
            Response::error('Embed token required', 404);
        }
        $embed = Database::getInstance()->fetch(
            'SELECT id, project_id, config_json, show_branding, view_count
               FROM embeds WHERE embed_token = ?',
            [$token]
        );
        if (!$embed) Response::error('Embed not found or revoked', 404);

        $project = Database::getInstance()->fetch(
            'SELECT id, name, center_lat, center_lng, zoom_level
               FROM projects WHERE id = ?',
            [$embed['project_id']]
        );
        if (!$project) Response::error('Embed target project not found', 404);

        $areas = Database::getInstance()->fetchAll(
            "SELECT a.id, a.name, a.fill_color, a.stroke_color, a.fill_opacity,
                    ST_AsGeoJSON(a.geometry, 4) AS geom_json
             FROM areas a WHERE a.project_id = ?",
            [$project['id']]
        );
        foreach ($areas as &$a) {
            $a['geometry'] = $a['geom_json'] ? json_decode($a['geom_json'], true) : null;
            unset($a['geom_json']);
        }

        try {
            Database::getInstance()->query(
                'UPDATE embeds SET view_count = view_count + 1 WHERE id = ?',
                [$embed['id']]
            );
        } catch (\Throwable $e) {
            // Non-critical — don't fail the render if the counter UPDATE hits
            // a deadlock or row-lock timeout under heavy iframe traffic.
            error_log('embed view_count bump failed: ' . $e->getMessage());
        }

        $config = json_decode($embed['config_json'] ?? '{}', true) ?: [];
        Response::success([
            'embed_id'      => $embed['id'],
            'config'        => $config,
            'show_branding' => (int) $embed['show_branding'] === 1,
            'project'       => [
                'id'         => $project['id'],
                'name'       => $project['name'],
                'center_lat' => $project['center_lat'],
                'center_lng' => $project['center_lng'],
                'zoom_level' => $project['zoom_level'],
            ],
            'areas' => $areas,
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
            // Post-normalize: ST_AsGeoJSON for SRID 4326 already emits standard
            // [lng, lat] under the unified (X=lat, Y=lng) storage. No swap.
            $a['geometry'] = $a['geom_json'] ? json_decode($a['geom_json'], true) : null;
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
