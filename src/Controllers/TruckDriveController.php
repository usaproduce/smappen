<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\TruckPlanner\Services\RoutingService;

/**
 * Truck Planner: drive legs and the owner's corrections (4.10).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   POST   /api/truck/drive-times  ->  compute
 *   GET    /api/truck/drive-times/overrides  ->  overrides
 *   PUT    /api/truck/drive-times/overrides  ->  saveOverride
 *   DELETE /api/truck/drive-times/overrides/{id}  ->  destroyOverride
 *
 * A leg Google could not supply is not an error: it comes back as a straight-line estimate that says so
 * and says why. A correction is the owner's own data and is addressed by its id; an id of another
 * organization answers like a missing one: 404 "Correction not found".
 */
class TruckDriveController extends TruckBaseController
{
    private ?RoutingService $service;

    public function __construct(?RoutingService $service = null)
    {
        $this->service = $service;
    }

    public function compute(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $this->ok($this->routing()->driveTimes($orgId, $truck, $this->body($request)));
        });
    }

    public function overrides(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $this->ok(['overrides' => $this->routing()->overrides($orgId, $truck)]);
        });
    }

    public function saveOverride(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $this->ok(['override' => $this->routing()->saveOverride($orgId, $truck, $this->body($request))]);
        });
    }

    public function destroyOverride(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $this->truck($request);
            // The `{id}` of the path is only ever used as a bound parameter.
            $this->ok($this->routing()->deleteOverride($orgId, (string) $request->getParam('id', '')));
        });
    }

    private function routing(): RoutingService
    {
        return $this->service ??= new RoutingService();
    }
}
