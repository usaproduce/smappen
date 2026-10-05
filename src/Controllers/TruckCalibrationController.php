<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\TruckPlanner\Services\CalibrationService;
use App\TruckPlanner\Services\Support\Input;

/**
 * Truck Planner: calibration and accuracy (4.14).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET /api/truck/calibration  ->  show
 *   GET /api/truck/accuracy  ->  accuracy
 *
 * Both read the owner's logged services: what they say about the model (one factor for the truck, one
 * per spot) and how the estimates did against them.
 */
class TruckCalibrationController extends TruckBaseController
{
    private ?CalibrationService $service;

    public function __construct(?CalibrationService $service = null)
    {
        $this->service = $service;
    }

    public function show(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $A = $this->assumptions($truck);
            $this->ok($this->calibration()->summary($orgId, $truck, $A), ['calibration.spots']);
        });
    }

    public function accuracy(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $A = $this->assumptions($truck);
            $query = Input::query((array) $request->getQuery());
            $this->ok($this->calibration()->accuracy($orgId, $truck, $A, $query->date('from'), $query->date('to')));
        });
    }

    private function calibration(): CalibrationService
    {
        return $this->service ??= new CalibrationService();
    }
}
