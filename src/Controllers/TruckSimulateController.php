<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: exact capture at one point (4.7).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   POST /api/truck/simulate  ->  simulate
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P4, which replaces the bodies.
 */
class TruckSimulateController extends TruckBaseController
{
    public function simulate(Request $request): void
    {
        $this->stub($request);
    }
}
