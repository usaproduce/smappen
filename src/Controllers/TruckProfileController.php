<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\TruckPlanner\Services\ProfileMapper;
use App\TruckPlanner\Services\ProfileService;

/**
 * Truck Planner: the truck profile (4.4).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET /api/truck/profile  ->  show
 *   PUT /api/truck/profile  ->  upsert
 *
 * Both work without a truck: the GET then answers `truck: null`, and the PUT creates the truck.
 */
class TruckProfileController extends TruckBaseController
{
    public function show(Request $request): void
    {
        $this->run(function () use ($request): void {
            $truck = $this->truck($request, false);
            $mapper = new ProfileMapper();
            $this->ok([
                'truck' => $truck === null ? null : $mapper->toRecord($truck),
                'profile_defaults' => $mapper->defaults(),
            ]);
        });
    }

    /** The first save creates the truck (201), a later one changes the fields it carries (200). */
    public function upsert(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $saved = (new ProfileService())->upsert($orgId, $this->userId($request), $this->body($request));
            $created = $saved['created'];
            unset($saved['created']);
            $this->ok($saved, [], $created ? 'Truck created' : null, $created ? 201 : 200);
        });
    }
}
