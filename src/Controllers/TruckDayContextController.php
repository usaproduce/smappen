<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: holidays, weather and fuel per civil date (4.9).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET /api/truck/day-context  ->  show
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P5, which replaces the bodies.
 */
class TruckDayContextController extends TruckBaseController
{
    public function show(Request $request): void
    {
        $this->stub($request);
    }
}
