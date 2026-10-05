<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\TruckPlanner\Services\BootstrapService;

/**
 * Truck Planner: everything a /truck page needs before its first render (4.3).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET /api/truck/bootstrap  ->  show
 *
 * Works without a truck: the answer then says `has_truck: false` and the browser shows the first-run step.
 */
class TruckBootstrapController extends TruckBaseController
{
    public function show(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = $this->truck($request, false);
            $this->ok(
                (new BootstrapService())->build($orgId, $truck),
                ['assumptions.overrides', 'calibration.spots']
            );
        });
    }
}
