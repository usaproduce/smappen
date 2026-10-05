<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: logged services (4.13).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET    /api/truck/services  ->  index
 *   POST   /api/truck/services  ->  create
 *   GET    /api/truck/services/{id}  ->  show
 *   PUT    /api/truck/services/{id}  ->  update
 *   DELETE /api/truck/services/{id}  ->  destroy
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P6, which replaces the bodies.
 */
class TruckServiceLogController extends TruckBaseController
{
    public function index(Request $request): void
    {
        $this->stub($request);
    }

    public function create(Request $request): void
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
}
