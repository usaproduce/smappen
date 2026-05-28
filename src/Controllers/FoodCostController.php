<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\PrivateData\RestaurantRepository;
use App\Services\CogsBenchmarkService;
use App\Services\FoodCostService;

class FoodCostController
{
    private RestaurantRepository $restaurants;
    private FoodCostService $svc;
    private CogsBenchmarkService $benchmark;

    public function __construct(
        ?RestaurantRepository $restaurants = null,
        ?FoodCostService $svc = null,
        ?CogsBenchmarkService $benchmark = null
    ) {
        $this->restaurants = $restaurants ?? new RestaurantRepository();
        $this->svc = $svc ?? new FoodCostService();
        $this->benchmark = $benchmark ?? new CogsBenchmarkService();
    }

    public function theoretical(Request $request): void
    {
        $r = $this->verifyRestaurant($request);
        $start = (string) ($request->getQuery('start') ?? date('Y-m-01'));
        $end   = (string) ($request->getQuery('end')   ?? date('Y-m-t'));
        $payload = $this->svc->theoretical($r['id'], $start, $end);

        // Benchmark provenance for the DataFreshnessFooter on /restaurants/.../costs.
        // Scoped to the restaurant's region (with national fallback handled inside
        // CogsBenchmarkService::freshness()) so we don't bleed irrelevant regions
        // into the footer.
        $payload['benchmark_freshness'] = $this->benchmark->freshness($r['region'] ?? null);
        $payload['benchmark_is_live']   = $this->benchmark->isConfigured();
        Response::success($payload);
    }

    private function verifyRestaurant(Request $request): array
    {
        $id = (string) $request->getParam('id');
        $r = $this->restaurants->findById($id, $request->user['organization_id']);
        if (!$r) Response::error('Restaurant not found', 404);
        return $r;
    }
}
