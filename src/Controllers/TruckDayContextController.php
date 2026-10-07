<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\TruckPlanner\Services\DayContextService;

/**
 * Truck Planner: holidays, weather and fuel per civil date (4.9).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET /api/truck/day-context  ->  show
 *
 * Answers one ready day context per date of the range, built without a "treat this day as" value: the
 * browser derives the context of an override itself. The forecast is for the truck's base point; when
 * there is none the contexts still come, and `forecast.state` says so.
 */
class TruckDayContextController extends TruckBaseController
{
    private ?DayContextService $service;

    public function __construct(?DayContextService $service = null)
    {
        $this->service = $service;
    }

    public function show(Request $request): void
    {
        $this->run(function () use ($request): void {
            $this->orgId($request);
            $truck = (array) $this->truck($request);
            $A = $this->assumptions($truck);
            $service = $this->service ??= new DayContextService();
            $this->ok($service->range($truck, $A, (array) $request->getQuery()));
        });
    }
}
