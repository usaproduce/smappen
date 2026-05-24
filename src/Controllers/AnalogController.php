<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\AnalogService;

/**
 * AnalogController — "find me places that look like this one".
 *
 * POST /api/areas/{id}/analogs
 *   body: { max_results?: 1-50, search_radius_km?: float|null, weights?: float[18]|null }
 *
 * Auth + multi-tenant: the area must belong to the caller's organization
 * (joined through projects.organization_id). 404 otherwise — same shape as
 * AreaController::show so an attacker can't probe area IDs.
 *
 * Rate limit applied at the route layer via the `analog_finder` bucket so it
 * doesn't share quota with territory generation (which is much heavier).
 */
class AnalogController
{
    public function find(Request $request): void
    {
        // Tract scoring needs ~512MB at 84K rows × 18 features × cached norm
        // stats. PHP's default 128MB OOMs partway through; bumping early so
        // the request doesn't die mid-loop.
        @ini_set('memory_limit', '512M');
        // ~120s for first-time normalization-stats cache miss on the full
        // national tract set; subsequent calls finish in <10s.
        @set_time_limit(120);

        $areaId = $request->getParam('id');
        if (!$areaId) Response::error('Area id required', 400);

        $user = $request->user;
        $org = $user['organization_id'] ?? null;
        if (!$org) Response::error('User has no organization', 403);

        $db = Database::getInstance();
        $area = $db->fetch(
            'SELECT a.id, a.name, a.project_id, a.demographics_cache,
                    a.center_lat, a.center_lng, a.area_type,
                    ST_AsText(a.geometry) AS geometry_wkt
             FROM areas a
             JOIN projects p ON a.project_id = p.id
             WHERE a.id = ? AND p.organization_id = ?',
            [$areaId, $org]
        );
        if (!$area) Response::error('Area not found', 404);

        // Read + validate body.
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $maxResults = (int)($body['max_results'] ?? 25);
        $maxResults = max(1, min(50, $maxResults));

        $radiusKm = $body['search_radius_km'] ?? null;
        if ($radiusKm !== null) {
            $radiusKm = (float)$radiusKm;
            if ($radiusKm <= 0 || $radiusKm > 5000) {
                Response::error('search_radius_km must be between 0 and 5000', 422);
            }
        }

        $weights = $body['weights'] ?? null;
        if ($weights !== null) {
            if (!is_array($weights) || count($weights) !== 18) {
                Response::error('weights must be an array of exactly 18 floats', 422);
            }
            $weights = array_map('floatval', $weights);
        }

        $service = new AnalogService();
        try {
            $payload = $service->findAnalogs($area, $maxResults, $radiusKm, $weights);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
            return;
        } catch (\Throwable $e) {
            error_log('AnalogController error: ' . $e->getMessage());
            Response::error('Analog search failed', 500);
            return;
        }

        Response::success($payload);
    }
}
