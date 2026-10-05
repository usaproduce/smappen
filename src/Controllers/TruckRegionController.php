<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: regions and the cell pack (4.6).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET /api/truck/regions  ->  index
 *   GET /api/truck/regions/{region_id}/pack/{dataset_version}  ->  pack
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P2, which replaces the bodies.
 */
class TruckRegionController extends TruckBaseController
{
    public function index(Request $request): void
    {
        $this->stub($request, false);
    }

    public function pack(Request $request): void
    {
        $this->stub($request, false);
    }
}
