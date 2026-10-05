<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: everything a /truck page needs before its first render (4.3).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET /api/truck/bootstrap  ->  show
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P3, which replaces the bodies.
 */
class TruckBootstrapController extends TruckBaseController
{
    public function show(Request $request): void
    {
        $this->stub($request, false);
    }
}
