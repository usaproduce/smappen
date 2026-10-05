<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: the truck profile (4.4).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET /api/truck/profile  ->  show
 *   PUT /api/truck/profile  ->  upsert
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P3, which replaces the bodies.
 */
class TruckProfileController extends TruckBaseController
{
    public function show(Request $request): void
    {
        $this->stub($request, false);
    }

    public function upsert(Request $request): void
    {
        $this->stub($request, false);
    }
}
