<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\PrivateData\RestaurantRepository;

/**
 * Restaurants — Carafe's primary entity. Org-scoped CRUD.
 *
 * Routes:
 *   GET    /api/restaurants                — list active for caller's org
 *   POST   /api/restaurants                — create
 *   GET    /api/restaurants/{id}           — fetch one
 *   DELETE /api/restaurants/{id}           — archive (soft delete)
 *   POST   /api/onboarding/clone-sample-restaurant — clone the is_sample row
 */
class RestaurantController
{
    private RestaurantRepository $repo;

    public function __construct(?RestaurantRepository $repo = null)
    {
        $this->repo = $repo ?? new RestaurantRepository();
    }

    public function index(Request $request): void
    {
        $rows = $this->repo->listByOrg($request->user['organization_id']);
        Response::success(['restaurants' => $rows]);
    }

    public function show(Request $request): void
    {
        $row = $this->repo->findById((string) $request->getParam('id'), $request->user['organization_id']);
        if (!$row) Response::error('Restaurant not found', 404);
        Response::success(['restaurant' => $row]);
    }

    public function create(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            Response::error('name (1–160 chars) required', 422);
        }
        $placeId = isset($b['google_place_id']) ? trim((string) $b['google_place_id']) : '';
        // Dedupe: if this org already has a restaurant for this Google place,
        // return it instead of creating a duplicate.
        if ($placeId !== '') {
            $existing = $this->repo->findByGooglePlaceId($request->user['organization_id'], $placeId);
            if ($existing) {
                Response::success(['id' => $existing['id'], 'already_exists' => true], 'Restaurant already in your workspace', 200);
            }
        }
        $id = $this->repo->create($request->user['organization_id'], [
            'name'            => $name,
            'address'         => isset($b['address'])  ? (string) $b['address']  : null,
            'lat'             => isset($b['lat'])      ? (float)  $b['lat']      : null,
            'lng'             => isset($b['lng'])      ? (float)  $b['lng']      : null,
            'timezone'        => isset($b['timezone']) ? (string) $b['timezone'] : null,
            'region'          => isset($b['region'])   ? (string) $b['region']   : null,
            'google_place_id' => $placeId !== '' ? $placeId : null,
            'phone'           => isset($b['phone'])    ? (string) $b['phone']    : null,
            'website'         => isset($b['website'])  ? (string) $b['website']  : null,
        ]);
        Response::success(['id' => $id], 'Restaurant created', 201);
    }

    public function destroy(Request $request): void
    {
        $id = (string) $request->getParam('id');
        $row = $this->repo->findById($id, $request->user['organization_id']);
        if (!$row) Response::error('Restaurant not found', 404);
        $this->repo->archive($id, $request->user['organization_id']);
        Response::success([], 'Archived');
    }
}
