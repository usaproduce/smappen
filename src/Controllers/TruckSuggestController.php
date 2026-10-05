<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: suggested days and weeks (4.12).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   POST /api/truck/suggest/day  ->  day
 *   POST /api/truck/suggest/week  ->  week
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P8, which replaces the bodies.
 */
class TruckSuggestController extends TruckBaseController
{
    public function day(Request $request): void
    {
        $this->stub($request);
    }

    public function week(Request $request): void
    {
        $this->stub($request);
    }
}
