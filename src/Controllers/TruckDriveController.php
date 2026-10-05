<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: drive legs and the owner's corrections (4.10).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   POST   /api/truck/drive-times  ->  compute
 *   GET    /api/truck/drive-times/overrides  ->  overrides
 *   PUT    /api/truck/drive-times/overrides  ->  saveOverride
 *   DELETE /api/truck/drive-times/overrides/{id}  ->  destroyOverride
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P5, which replaces the bodies.
 */
class TruckDriveController extends TruckBaseController
{
    public function compute(Request $request): void
    {
        $this->stub($request);
    }

    public function overrides(Request $request): void
    {
        $this->stub($request);
    }

    public function saveOverride(Request $request): void
    {
        $this->stub($request);
    }

    public function destroyOverride(Request $request): void
    {
        $this->stub($request);
    }
}
