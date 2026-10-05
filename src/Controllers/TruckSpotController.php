<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: saved spots (4.8).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET    /api/truck/spots  ->  index
 *   POST   /api/truck/spots  ->  create
 *   POST   /api/truck/spots/refresh  ->  refreshStale
 *   GET    /api/truck/spots/{id}  ->  show
 *   PUT    /api/truck/spots/{id}  ->  update
 *   DELETE /api/truck/spots/{id}  ->  destroy
 *   POST   /api/truck/spots/{id}/refresh  ->  refresh
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P4, which replaces the bodies.
 */
class TruckSpotController extends TruckBaseController
{
    public function index(Request $request): void
    {
        $this->stub($request);
    }

    public function create(Request $request): void
    {
        $this->stub($request);
    }

    public function refreshStale(Request $request): void
    {
        $this->stub($request);
    }

    public function show(Request $request): void
    {
        $this->stub($request);
    }

    public function update(Request $request): void
    {
        $this->stub($request);
    }

    public function destroy(Request $request): void
    {
        $this->stub($request);
    }

    public function refresh(Request $request): void
    {
        $this->stub($request);
    }
}
