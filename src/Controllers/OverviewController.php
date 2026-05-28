<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\PrivateData\PosIntegrationRepository;
use App\PrivateData\PosSalesRepository;
use App\PrivateData\RecommendationRepository;
use App\PrivateData\RestaurantRepository;
use App\Services\OverviewService;
use App\Services\RoiService;

/**
 * War-room overview — single fetch behind the post-login screen.
 *
 *   GET /api/restaurants/{id}/overview
 *
 * Returns the dense payload OverviewService::build() produces. Lighthouse
 * desktop ≥ 90 is the spec's bar (audit item 7) — one round trip + a
 * skeleton beats five Promise.all'd requests on a cold cache.
 */
class OverviewController
{
    private RestaurantRepository $restaurants;
    private OverviewService $svc;

    public function __construct(
        ?RestaurantRepository $restaurants = null,
        ?OverviewService $svc = null,
    ) {
        $this->restaurants = $restaurants ?? new RestaurantRepository();
        if ($svc !== null) {
            $this->svc = $svc;
        } else {
            $recs = new RecommendationRepository();
            $sales = new PosSalesRepository();
            $this->svc = new OverviewService(
                new RoiService($recs, $sales),
                $recs,
                new PosIntegrationRepository(),
                $sales,
            );
        }
    }

    public function show(Request $request): void
    {
        $r = $this->verifyRestaurant($request);
        $tz = !empty($r['timezone']) ? (string) $r['timezone'] : 'UTC';
        $payload = $this->svc->build($r['id'], $tz);
        Response::success($payload);
    }

    private function verifyRestaurant(Request $request): array
    {
        $id = (string) $request->getParam('id');
        $row = $this->restaurants->findById($id, $request->user['organization_id']);
        if (!$row) Response::error('Restaurant not found', 404);
        return $row;
    }
}
