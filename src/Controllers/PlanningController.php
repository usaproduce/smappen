<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\PrivateData\MenuItemRepository;
use App\PrivateData\PlateCostRepository;
use App\PrivateData\PlansSandboxRepository;
use App\PrivateData\PosSalesRepository;
use App\PrivateData\RestaurantRepository;
use App\Services\PlanningService;

/**
 * Planning sandbox. Restaurants model menu changes / new locations before
 * committing.
 *
 * Routes (auth):
 *   GET    /api/sandbox                       — list all for caller's org
 *   POST   /api/sandbox                       — create scenario
 *   GET    /api/sandbox/{id}                  — fetch with projection
 *   POST   /api/sandbox/{id}/compute          — (re)run the projection
 *   DELETE /api/sandbox/{id}                  — delete
 */
class PlanningController
{
    private PlansSandboxRepository $sandbox;
    private RestaurantRepository $restaurants;
    private PlanningService $svc;

    public function __construct(?PlansSandboxRepository $sandbox = null, ?RestaurantRepository $restaurants = null, ?PlanningService $svc = null)
    {
        $this->sandbox = $sandbox ?? new PlansSandboxRepository();
        $this->restaurants = $restaurants ?? new RestaurantRepository();
        $this->svc = $svc ?? new PlanningService(
            new MenuItemRepository(),
            new PlateCostRepository(),
            new PosSalesRepository(),
            $this->sandbox,
        );
    }

    public function index(Request $request): void
    {
        $restaurantId = $request->getQuery('restaurant_id');
        $rows = $this->sandbox->listByOrg(
            $request->user['organization_id'],
            $restaurantId ? (string) $restaurantId : null
        );
        Response::success(['scenarios' => $rows]);
    }

    public function show(Request $request): void
    {
        $row = $this->sandbox->findById((string) $request->getParam('id'), $request->user['organization_id']);
        if (!$row) Response::error('Scenario not found', 404);
        $row['payload']   = $row['payload']   ? json_decode($row['payload'], true)   : null;
        $row['projected'] = $row['projected'] ? json_decode($row['projected'], true) : null;
        Response::success(['scenario' => $row]);
    }

    public function create(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $name = trim((string) ($b['name'] ?? ''));
        $kind = (string) ($b['kind'] ?? '');
        if ($name === '' || !in_array($kind, ['menu_change', 'new_location'], true)) {
            Response::error('name + kind (menu_change|new_location) required', 422);
        }
        $restaurantId = isset($b['restaurant_id']) ? (string) $b['restaurant_id'] : null;
        if ($restaurantId !== null) {
            $r = $this->restaurants->findById($restaurantId, $request->user['organization_id']);
            if (!$r) Response::error('Restaurant not found', 404);
        }
        $payload = is_array($b['payload'] ?? null) ? $b['payload'] : [];
        $id = $this->sandbox->create($request->user['organization_id'], $restaurantId, $name, $kind, $payload);
        // Best-effort initial compute so the UI gets numbers immediately.
        try { $this->svc->compute($id, $request->user['organization_id']); } catch (\Throwable $e) {
            error_log('[planning] initial compute failed: ' . $e->getMessage());
        }
        Response::success(['id' => $id], 'Scenario created', 201);
    }

    public function compute(Request $request): void
    {
        $id = (string) $request->getParam('id');
        $projected = $this->svc->compute($id, $request->user['organization_id']);
        if ($projected === null) Response::error('Scenario not found', 404);
        Response::success(['projected' => $projected]);
    }

    public function destroy(Request $request): void
    {
        $id = (string) $request->getParam('id');
        $row = $this->sandbox->findById($id, $request->user['organization_id']);
        if (!$row) Response::error('Scenario not found', 404);
        $this->sandbox->destroy($id, $request->user['organization_id']);
        Response::success([], 'Deleted');
    }
}
