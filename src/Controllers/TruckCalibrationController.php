<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: calibration and accuracy (4.14).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET /api/truck/calibration  ->  show
 *   GET /api/truck/accuracy  ->  accuracy
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P6, which replaces the bodies.
 */
class TruckCalibrationController extends TruckBaseController
{
    public function show(Request $request): void
    {
        $this->stub($request);
    }

    public function accuracy(Request $request): void
    {
        $this->stub($request);
    }
}
