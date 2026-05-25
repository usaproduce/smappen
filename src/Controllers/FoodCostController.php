<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\PrivateData\RestaurantRepository;
use App\Services\FoodCostService;

class FoodCostController
{
    private RestaurantRepository $restaurants;
    private FoodCostService $svc;

    public function __construct(?RestaurantRepository $restaurants = null, ?FoodCostService $svc = null)
    {
        $this->restaurants = $restaurants ?? new RestaurantRepository();
        $this->svc = $svc ?? new FoodCostService();
    }

    public function theoretical(Request $request): void
    {
        $r = $this->verifyRestaurant($request);
        $start = (string) ($request->getQuery('start') ?? date('Y-m-01'));
        $end   = (string) ($request->getQuery('end')   ?? date('Y-m-t'));
        $payload = $this->svc->theoretical($r['id'], $start, $end);
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
