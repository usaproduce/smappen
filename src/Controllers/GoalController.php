<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\PrivateData\GoalRepository;
use App\PrivateData\RestaurantRepository;
use App\Services\GoalService;

class GoalController
{
    private GoalRepository $repo;
    private RestaurantRepository $restaurants;
    private GoalService $svc;

    public function __construct(?GoalRepository $repo = null, ?RestaurantRepository $restaurants = null, ?GoalService $svc = null)
    {
        $this->repo = $repo ?? new GoalRepository();
        $this->restaurants = $restaurants ?? new RestaurantRepository();
        $this->svc = $svc ?? new GoalService($this->repo);
    }

    public function index(Request $request): void
    {
        $r = $this->verifyRestaurant($request);
        $goals = $this->repo->listByRestaurant($r['id']);
        foreach ($goals as &$g) {
            $g['recent_snapshots'] = $this->repo->recentSnapshots((string) $g['id'], 12);
        }
        Response::success(['goals' => $goals]);
    }

    public function create(Request $request): void
    {
        $r = $this->verifyRestaurant($request);
        $b = $request->getBody() ?? [];
        $metric = (string) ($b['metric'] ?? '');
        if (!in_array($metric, ['food_cost_pct', 'avg_check_cents', 'margin_pct', 'weekly_revenue_cents'], true)) {
            Response::error('metric must be one of: food_cost_pct, avg_check_cents, margin_pct, weekly_revenue_cents', 422);
        }
        $target = isset($b['target_value']) ? (float) $b['target_value'] : 0.0;
        if ($target <= 0) Response::error('target_value > 0 required', 422);
        $cadence = $b['cadence'] ?? 'monthly';
        if (!in_array($cadence, ['weekly', 'monthly', 'quarterly'], true)) {
            Response::error('cadence must be weekly|monthly|quarterly', 422);
        }
        $id = $this->repo->create(
            $request->user['organization_id'],
            $r['id'],
            $metric,
            $target,
            $cadence,
            isset($b['label']) ? (string) $b['label'] : null
        );
        Response::success(['id' => $id], 'Goal created', 201);
    }

    public function snapshot(Request $request): void
    {
        $id = (string) $request->getParam('id');
        $goal = $this->repo->findById($id, $request->user['organization_id']);
        if (!$goal) Response::error('Goal not found', 404);
        $result = $this->svc->snapshot($id, $request->user['organization_id']);
        Response::success($result ?? ['note' => 'no data']);
    }

    public function destroy(Request $request): void
    {
        $id = (string) $request->getParam('id');
        $goal = $this->repo->findById($id, $request->user['organization_id']);
        if (!$goal) Response::error('Goal not found', 404);
        $this->repo->archive($id, $request->user['organization_id']);
        Response::success([], 'Archived');
    }

    private function verifyRestaurant(Request $request): array
    {
        $id = (string) $request->getParam('id');
        $r = $this->restaurants->findById($id, $request->user['organization_id']);
        if (!$r) Response::error('Restaurant not found', 404);
        return $r;
    }
}
