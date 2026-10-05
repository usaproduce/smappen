<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: scouting and leads (4.15).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET  /api/truck/scout  ->  index
 *   PUT  /api/truck/scout/leads/{place_key}  ->  saveLead
 *   POST /api/truck/scout/leads/{place_key}/contact  ->  contact
 *   POST /api/truck/scout/leads/{place_key}/spot  ->  saveAsSpot
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P7, which replaces the bodies.
 */
class TruckScoutController extends TruckBaseController
{
    public function index(Request $request): void
    {
        $this->stub($request);
    }

    public function saveLead(Request $request): void
    {
        $this->stub($request);
    }

    public function contact(Request $request): void
    {
        $this->stub($request);
    }

    public function saveAsSpot(Request $request): void
    {
        $this->stub($request);
    }
}
