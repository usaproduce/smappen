<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\TruckPlanner\Services\SpotService;
use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\TpConfig;

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
 * A spot is archived, never deleted, and an archived spot is still addressed by its id (plans and logs
 * refer to it). An id of another organization answers like a missing one: 404 "Spot not found".
 */
class TruckSpotController extends TruckBaseController
{
    private const SPOT_VECTORS = ['spot.vectors'];

    private ?SpotService $service;

    public function __construct(?SpotService $service = null)
    {
        $this->service = $service;
    }

    public function index(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $archived = Input::query((array) $request->getQuery())->bool('archived') ?? false;
            $this->ok(['spots' => $this->spots()->list($orgId, $truck, $archived)], ['spots.*.vectors']);
        });
    }

    public function create(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $spot = $this->spots()->create($orgId, $truck, $this->userId($request), $this->body($request));
            $this->ok(['spot' => $spot], self::SPOT_VECTORS, 'Spot saved', 201);
        });
    }

    public function refreshStale(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $this->body($request, true);
            $limit = (int) TpConfig::get('requests.refresh_stale_spots');
            $this->ok($this->spots()->refreshStale($orgId, $truck, $limit));
        });
    }

    public function show(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $this->ok(['spot' => $this->spots()->get($orgId, $truck, self::id($request))], self::SPOT_VECTORS);
        });
    }

    public function update(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $spot = $this->spots()->update($orgId, $truck, self::id($request), $this->body($request));
            $this->ok(['spot' => $spot], self::SPOT_VECTORS);
        });
    }

    public function destroy(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $this->truck($request);
            $this->ok($this->spots()->archive($orgId, self::id($request)));
        });
    }

    public function refresh(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $this->body($request, true);
            $this->ok(['spot' => $this->spots()->refresh($orgId, $truck, self::id($request))], self::SPOT_VECTORS);
        });
    }

    private function spots(): SpotService
    {
        return $this->service ??= new SpotService();
    }

    /** The `{id}` of the path. It is only ever used as a bound parameter. */
    private static function id(Request $request): string
    {
        return (string) $request->getParam('id', '');
    }
}
