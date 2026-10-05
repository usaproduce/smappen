<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\TruckPlanner\Services\PlanningService;

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
 * A truck has one plan per service date. Saving a plan evaluates it and stores the result with the
 * context it was computed in; `preview` evaluates a body and stores nothing. An id of another
 * organization answers like a missing one: 404 "Plan not found".
 */
class TruckPlanController extends TruckBaseController
{
    /** The maps of a stored or previewed result: sent as {} also when they are empty. */
    private const PLAN_MAPS = ['plan.result.warnings.*.data'];
    private const PREVIEW_MAPS = ['result.warnings.*.data'];

    private ?PlanningService $service;

    public function __construct(?PlanningService $service = null)
    {
        $this->service = $service;
    }

    public function index(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $A = $this->assumptions($truck);
            $this->ok(['plans' => $this->plans()->list($orgId, $truck, $A, (array) $request->getQuery())]);
        });
    }

    public function create(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $A = $this->assumptions($truck);
            $plan = $this->plans()->create($orgId, $truck, $A, $this->userId($request), $this->body($request));
            $this->ok(['plan' => $plan], self::PLAN_MAPS, 'Plan saved', 201);
        });
    }

    public function preview(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $A = $this->assumptions($truck);
            $this->ok($this->plans()->preview($orgId, $truck, $A, $this->body($request)), self::PREVIEW_MAPS);
        });
    }

    public function show(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $A = $this->assumptions($truck);
            $this->ok(['plan' => $this->plans()->get($orgId, $truck, $A, self::id($request))], self::PLAN_MAPS);
        });
    }

    public function update(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $A = $this->assumptions($truck);
            $plan = $this->plans()->update($orgId, $truck, $A, self::id($request), $this->body($request));
            $this->ok(['plan' => $plan], self::PLAN_MAPS);
        });
    }

    public function destroy(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $this->truck($request);
            $this->ok($this->plans()->delete($orgId, self::id($request)));
        });
    }

    public function evaluate(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $A = $this->assumptions($truck);
            $this->body($request, true);
            $this->ok(['plan' => $this->plans()->evaluateStored($orgId, $truck, $A, self::id($request))], self::PLAN_MAPS);
        });
    }

    private function plans(): PlanningService
    {
        return $this->service ??= new PlanningService();
    }

    /** The `{id}` of the path. It is only ever used as a bound parameter. */
    private static function id(Request $request): string
    {
        return (string) $request->getParam('id', '');
    }
}
