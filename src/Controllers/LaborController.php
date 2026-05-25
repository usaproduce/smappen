<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\PrivateData\LaborShiftRepository;
use App\PrivateData\RestaurantRepository;
use App\Services\LaborDemandService;

/**
 * Labor-vs-demand + daypart demand-filling. Reads pos_sales + labor_shifts
 * and surfaces both staffing-imbalance flags and slow-window suggestions.
 *
 * Routes (auth):
 *   GET   /api/restaurants/{id}/labor/analysis?start=&end=
 *   POST  /api/restaurants/{id}/labor/shifts         — manual entry
 *   GET   /api/restaurants/{id}/labor/shifts?start=&end=
 */
class LaborController
{
    private RestaurantRepository $restaurants;
    private LaborShiftRepository $shifts;
    private LaborDemandService $svc;

    public function __construct(?RestaurantRepository $restaurants = null, ?LaborShiftRepository $shifts = null, ?LaborDemandService $svc = null)
    {
        $this->restaurants = $restaurants ?? new RestaurantRepository();
        $this->shifts = $shifts ?? new LaborShiftRepository();
        $this->svc = $svc ?? new LaborDemandService($this->shifts);
    }

    public function analysis(Request $request): void
    {
        $r = $this->verifyRestaurant($request);
        $start = (string) ($request->getQuery('start') ?? date('Y-m-d', strtotime('-14 days')));
        $end   = (string) ($request->getQuery('end')   ?? date('Y-m-d'));
        Response::success($this->svc->analyze($r['id'], $start, $end));
    }

    public function listShifts(Request $request): void
    {
        $r = $this->verifyRestaurant($request);
        $start = (string) ($request->getQuery('start') ?? date('Y-m-d', strtotime('-14 days'))) . ' 00:00:00';
        $end   = (string) ($request->getQuery('end')   ?? date('Y-m-d')) . ' 23:59:59';
        Response::success(['shifts' => $this->shifts->listInWindow($r['id'], $start, $end)]);
    }

    public function createShift(Request $request): void
    {
        $r = $this->verifyRestaurant($request);
        $b = $request->getBody() ?? [];
        $starts = (string) ($b['starts_at'] ?? '');
        $ends   = isset($b['ends_at']) ? (string) $b['ends_at'] : null;
        if ($starts === '') Response::error('starts_at required', 422);
        $id = $this->shifts->createManual($request->user['organization_id'], $r['id'], [
            'employee_label'    => $b['employee_label'] ?? null,
            'role'              => $b['role'] ?? null,
            'starts_at'         => $starts,
            'ends_at'           => $ends,
            'hourly_wage_cents' => isset($b['hourly_wage_cents']) ? (int) $b['hourly_wage_cents'] : null,
        ]);
        Response::success(['id' => $id], 'Shift recorded', 201);
    }

    private function verifyRestaurant(Request $request): array
    {
        $id = (string) $request->getParam('id');
        $r = $this->restaurants->findById($id, $request->user['organization_id']);
        if (!$r) Response::error('Restaurant not found', 404);
        return $r;
    }
}
