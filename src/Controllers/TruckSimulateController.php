<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\TruckPlanner\Services\SimulateService;

/**
 * Truck Planner: exact capture at one point (4.7).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   POST /api/truck/simulate  ->  simulate
 *
 * Answers the location vectors per requested visibility, the outlets and possible hosts nearby and the
 * server's own estimate at the point. Nothing is stored.
 */
class TruckSimulateController extends TruckBaseController
{
    private ?SimulateService $service;

    public function __construct(?SimulateService $service = null)
    {
        $this->service = $service;
    }

    public function simulate(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $A = $this->assumptions($truck);
            $body = $this->body($request);
            $this->ok(($this->service ??= new SimulateService())->run($orgId, $truck, $A, $body), ['vectors']);
        });
    }
}
