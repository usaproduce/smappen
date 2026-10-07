<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\TruckPlanner\Services\ServiceLogService;

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
 * A logged service keeps the prediction it is judged against, and every write answers with the
 * calibration state it leads to. An id of another organization answers like a missing one: 404
 * "Service not found".
 */
class TruckServiceLogController extends TruckBaseController
{
    /** The maps of an answer with one service and the calibration after it. */
    private const WRITE_MAPS = ['service.prediction.detail', 'calibration.spots'];

    private ?ServiceLogService $service;

    public function __construct(?ServiceLogService $service = null)
    {
        $this->service = $service;
    }

    public function index(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $services = $this->logs()->list($orgId, $truck, (array) $request->getQuery());
            $this->ok(['services' => $services], ['services.*.prediction.detail']);
        });
    }

    public function create(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $A = $this->assumptions($truck);
            $answer = $this->logs()->create($orgId, $truck, $A, $this->userId($request), $this->body($request));
            $this->ok($answer, self::WRITE_MAPS, 'Service logged', 201);
        });
    }

    public function show(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $this->truck($request);
            $this->ok(['service' => $this->logs()->get($orgId, self::id($request))], ['service.prediction.detail']);
        });
    }

    public function update(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $A = $this->assumptions($truck);
            $answer = $this->logs()->update($orgId, $truck, $A, self::id($request), $this->body($request));
            $this->ok($answer, self::WRITE_MAPS);
        });
    }

    public function destroy(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $A = $this->assumptions($truck);
            $this->ok($this->logs()->delete($orgId, $truck, $A, self::id($request)), ['calibration.spots']);
        });
    }

    private function logs(): ServiceLogService
    {
        return $this->service ??= new ServiceLogService();
    }

    /** The `{id}` of the path. It is only ever used as a bound parameter. */
    private static function id(Request $request): string
    {
        return (string) $request->getParam('id', '');
    }
}
