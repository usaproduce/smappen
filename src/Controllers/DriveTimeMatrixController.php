<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\DriveTimeMatrixService;

/**
 * NF1 — POST /api/drive-time-matrix
 *
 * Body: { origins: [{lat,lng,label?}], destinations: [...], mode? }
 * Each side capped at 200 locations.
 */
class DriveTimeMatrixController
{
    public function compute(Request $request): void
    {
        @ini_set('memory_limit', '256M');
        @set_time_limit(180);

        $b = $request->getBody() ?? [];
        $origins      = $b['origins']      ?? null;
        $destinations = $b['destinations'] ?? null;
        $mode         = $b['mode']         ?? 'driving-car';

        if (!is_array($origins) || !is_array($destinations)) {
            Response::error('origins and destinations arrays required', 422);
        }
        if (!in_array($mode, ['driving-car', 'cycling-regular', 'foot-walking'], true)) {
            Response::error('mode must be driving-car, cycling-regular, or foot-walking', 422);
        }

        $svc = new DriveTimeMatrixService();
        try {
            $result = $svc->compute($origins, $destinations, $mode);
            Response::success($result);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            error_log('DTM error: ' . $e->getMessage());
            Response::error('Drive-time matrix failed', 500);
        }
    }
}
