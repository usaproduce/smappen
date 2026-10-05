<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: the owner's overrides of seed assumptions (4.5).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET  /api/truck/assumptions  ->  show
 *   PUT  /api/truck/assumptions  ->  update
 *   POST /api/truck/assumptions/reset  ->  reset
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P3, which replaces the bodies.
 */
class TruckAssumptionsController extends TruckBaseController
{
    public function show(Request $request): void
    {
        $this->stub($request);
    }

    public function update(Request $request): void
    {
        $this->stub($request);
    }

    public function reset(Request $request): void
    {
        $this->stub($request);
    }
}
