<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\PrivateData\PosSalesRepository;
use App\PrivateData\RecommendationRepository;
use App\PrivateData\RestaurantRepository;
use App\Services\RoiService;

/**
 * ROI ledger — the "Carafe found you $X this month" surface.
 *
 * Routes (auth):
 *   GET  /api/restaurants/{id}/roi/monthly      — summary for current month (or ?month=2026-05)
 *   POST /api/restaurants/{id}/roi/measure      — manual trigger; cron will normally do this
 */
class RoiController
{
    private RestaurantRepository $restaurants;
    private RoiService $roi;

    public function __construct(?RestaurantRepository $restaurants = null, ?RoiService $roi = null)
    {
        $this->restaurants = $restaurants ?? new RestaurantRepository();
        $this->roi = $roi ?? new RoiService(new RecommendationRepository(), new PosSalesRepository());
    }

    public function monthly(Request $request): void
    {
        $r = $this->verifyOwnedRestaurant($request);
        $month = $request->getQuery('month');
        $summary = $this->roi->monthlySummary($r['id'], $month ? (string) $month : null);
        Response::success($summary);
    }

    public function measure(Request $request): void
    {
        $this->verifyOwnedRestaurant($request); // ownership check (doesn't scope measurePending)
        $count = $this->roi->measurePending();
        Response::success(['measured' => $count]);
    }

    private function verifyOwnedRestaurant(Request $request): array
    {
        $id = (string) $request->getParam('id');
        $r = $this->restaurants->findById($id, $request->user['organization_id']);
        if (!$r) Response::error('Restaurant not found', 404);
        return $r;
    }
}
