<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: export, deletion and sources (4.16).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET  /api/truck/export  ->  export
 *   POST /api/truck/data/delete  ->  destroy
 *   GET  /api/truck/sources  ->  sources
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P8, which replaces the bodies.
 */
class TruckDataController extends TruckBaseController
{
    public function export(Request $request): void
    {
        $this->stub($request);
    }

    public function destroy(Request $request): void
    {
        $this->stub($request, false);
    }

    public function sources(Request $request): void
    {
        $this->stub($request, false);
    }
}
