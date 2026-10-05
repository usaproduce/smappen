<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\TruckPlanner\Services\ScoutingService;
use App\TruckPlanner\Services\Support\Input;

/**
 * Truck Planner: scouting and leads (4.15).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET  /api/truck/scout  ->  index
 *   PUT  /api/truck/scout/leads/{place_key}  ->  saveLead
 *   POST /api/truck/scout/leads/{place_key}/contact  ->  contact
 *   POST /api/truck/scout/leads/{place_key}/spot  ->  saveAsSpot
 *
 * A lead is addressed by the key of its place and belongs to the caller's truck: the same key in another
 * organization is another lead. A key that is not a possible host of the truck's region answers 404
 * "Place not found". The contact lookup is the only action here that can reach Google, and only when the
 * owner asks for it.
 */
class TruckScoutController extends TruckBaseController
{
    private ?ScoutingService $service;

    public function __construct(?ScoutingService $service = null)
    {
        $this->service = $service;
    }

    public function index(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $this->ok($this->scouting()->rank($orgId, $truck, $this->assumptions($truck), (array) $request->getQuery()));
        });
    }

    public function saveLead(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $this->ok($this->scouting()->saveLead($orgId, $truck, self::placeKey($request), $this->body($request)));
        });
    }

    public function contact(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $force = (new Input($this->body($request, true)))->bool('force') ?? false;
            $this->ok($this->scouting()->lookupContact($orgId, $truck, self::placeKey($request), $force));
        });
    }

    public function saveAsSpot(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $saved = $this->scouting()->saveAsSpot(
                $orgId,
                $truck,
                $this->userId($request),
                self::placeKey($request),
                $this->body($request, true)
            );
            $this->ok($saved, ['spot.vectors'], 'Spot saved', 201);
        });
    }

    private function scouting(): ScoutingService
    {
        return $this->service ??= new ScoutingService();
    }

    /** The `{place_key}` of the path. The service checks its form before it is used as a bound parameter. */
    private static function placeKey(Request $request): string
    {
        return (string) $request->getParam('place_key', '');
    }
}
