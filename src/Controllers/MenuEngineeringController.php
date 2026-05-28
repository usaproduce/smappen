<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\PrivateData\MenuItemRepository;
use App\PrivateData\PlateCostRepository;
use App\PrivateData\PosSalesRepository;
use App\PrivateData\RecommendationRepository;
use App\PrivateData\RestaurantRepository;
use App\Services\MenuEngineeringService;

/**
 * Menu engineering — runs the recommendation engine and exposes the
 * ledger. Money-quantified output only; charts come later.
 *
 * Routes (auth):
 *   POST /api/menu-items/{id}/recommend                — run engine for one item
 *   POST /api/restaurants/{id}/recommendations/run     — run engine for whole restaurant
 *   GET  /api/restaurants/{id}/recommendations         — list (optional ?status=)
 *   POST /api/recommendations/{id}/accept              — accept a suggestion
 *   POST /api/recommendations/{id}/dismiss             — dismiss a suggestion
 */
class MenuEngineeringController
{
    private MenuItemRepository $items;
    private RecommendationRepository $recs;
    private RestaurantRepository $restaurants;
    private MenuEngineeringService $engine;

    public function __construct(
        ?MenuItemRepository $items = null,
        ?RecommendationRepository $recs = null,
        ?RestaurantRepository $restaurants = null,
        ?MenuEngineeringService $engine = null,
    ) {
        $this->items = $items ?? new MenuItemRepository();
        $this->recs = $recs ?? new RecommendationRepository();
        $this->restaurants = $restaurants ?? new RestaurantRepository();
        $this->engine = $engine ?? new MenuEngineeringService(
            $this->items,
            new PlateCostRepository(),
            $this->recs,
            new PosSalesRepository(),
        );
    }

    public function classify(Request $request): void
    {
        $r = $this->verifyOwnedRestaurant($request);
        $payload = $this->engine->classify($r['id']);
        Response::success($payload);
    }

    public function recommendForItem(Request $request): void
    {
        $itemId = (string) $request->getParam('id');
        $item = $this->items->findById($itemId, $request->user['organization_id']);
        if (!$item) Response::error('Menu item not found', 404);
        $id = $this->engine->recommendForItem($itemId, $request->user['organization_id']);
        if ($id === null) {
            Response::success(['created' => false, 'reason' => 'No recommendation warranted at the current price + cost.']);
        }
        Response::success(['created' => true, 'recommendation_id' => $id], 'Recommendation created', 201);
    }

    public function recommendForRestaurant(Request $request): void
    {
        $r = $this->verifyOwnedRestaurant($request);
        $count = $this->engine->recommendForRestaurant($r['id'], $request->user['organization_id']);
        Response::success(['created_count' => $count]);
    }

    public function listForRestaurant(Request $request): void
    {
        $r = $this->verifyOwnedRestaurant($request);
        $status = $request->getQuery('status'); // null|'suggested'|'accepted'|...
        $rows = $this->recs->listByRestaurant($r['id'], $status ? (string) $status : null);

        // Build menu_item_id → {name, price_cents} lookup so summarize()
        // can render "raise Carbonara to $19.50". One query, not N.
        $items = $this->itemsByIdFor($r['id']);

        foreach ($rows as &$row) {
            $row['payload'] = $row['payload'] ? json_decode($row['payload'], true) : null;
            $mi = isset($row['menu_item_id']) ? ($items[(string) $row['menu_item_id']] ?? null) : null;
            $row['narrative_short'] = \App\Services\MenuEngineeringService::summarize($row, $mi);
        }
        Response::success(['recommendations' => $rows]);
    }

    /** Lightweight map for the summary join — only fields the summarizer needs. */
    private function itemsByIdFor(string $restaurantId): array
    {
        $rows = \App\Core\Database::getInstance()->fetchAll(
            'SELECT id, name, price_cents FROM menu_items WHERE restaurant_id = ?',
            [$restaurantId]
        );
        $map = [];
        foreach ($rows as $r) $map[(string) $r['id']] = $r;
        return $map;
    }

    public function accept(Request $request): void
    {
        $this->decide($request, 'accepted');
    }

    public function dismiss(Request $request): void
    {
        $this->decide($request, 'dismissed');
    }

    private function decide(Request $request, string $status): void
    {
        $id = (string) $request->getParam('id');
        $rec = $this->recs->findById($id, $request->user['organization_id']);
        if (!$rec) Response::error('Recommendation not found', 404);
        if ($rec['status'] !== 'suggested') {
            Response::error("Already $rec[status]", 409);
        }
        $this->recs->decide($id, $request->user['organization_id'], $status);
        if ($status === 'accepted') {
            OnboardingController::stampActivation(
                (string) $request->user['id'],
                $request->user['organization_id'],
                'first_recommendation_accepted_at'
            );
        }
        Response::success(['id' => $id, 'status' => $status]);
    }

    private function verifyOwnedRestaurant(Request $request): array
    {
        $id = (string) $request->getParam('id');
        $r = $this->restaurants->findById($id, $request->user['organization_id']);
        if (!$r) Response::error('Restaurant not found', 404);
        return $r;
    }
}
