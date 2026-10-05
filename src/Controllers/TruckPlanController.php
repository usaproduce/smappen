<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

/**
 * Truck Planner: day plans (4.11).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET    /api/truck/plans  ->  index
 *   POST   /api/truck/plans  ->  create
 *   POST   /api/truck/plans/evaluate  ->  preview
 *   GET    /api/truck/plans/{id}  ->  show
 *   PUT    /api/truck/plans/{id}  ->  update
 *   DELETE /api/truck/plans/{id}  ->  destroy
 *   POST   /api/truck/plans/{id}/evaluate  ->  evaluate
 *
 * Stub of the foundation package: every action runs the base guards and answers 501
 * "Not implemented yet". The file belongs to package P6, which replaces the bodies.
 */
class TruckPlanController extends TruckBaseController
{
    public function index(Request $request): void
    {
        $this->stub($request);
    }

    public function create(Request $request): void
    {
        $this->stub($request);
    }

    public function preview(Request $request): void
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

    public function evaluate(Request $request): void
    {
        $this->stub($request);
    }
}
