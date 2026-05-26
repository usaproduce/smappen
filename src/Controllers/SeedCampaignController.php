<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\SeedEstimatorService;

/**
 * SeedCampaignController — admin surface for the vendor-network seeding
 * campaigns. Carafe Vendor Network Spec v3 §7 + §8.
 *
 * Phase 2 ships the estimator endpoint only. Future phases add:
 *   - POST   /api/admin/seed-campaigns          (create + persist a draft)
 *   - POST   /api/admin/seed-campaigns/{id}/run (approve + queue tiles)
 *   - POST   /api/admin/seed-campaigns/{id}/pause
 *   - GET    /api/admin/seed-campaigns         (list)
 *   - GET    /api/admin/seed-campaigns/{id}    (live status + spent)
 *   - POST   /api/admin/seed-campaigns/{id}/publish (move reviewed vendors to public)
 *
 * Admin gating: route is wrapped in Middleware::requireRole(['admin','owner']);
 * controller doesn't re-check, the middleware short-circuits with 403.
 */
class SeedCampaignController
{
    /**
     * POST /api/admin/seed-campaigns/estimate
     *
     * Body:
     *   {
     *     "bbox":            [lat_min, lng_min, lat_max, lng_max],   // required
     *     "vendor_types":    ["broadline","cash_carry","produce"],    // required
     *     "enrich_policy":   "all"|"priority_types"|"on_demand",      // optional, default priority_types
     *     "density_profile": "rural"|"suburban"|"dense"|"mixed"        // optional, default mixed
     *   }
     *
     * Returns the dual-pass estimate shape from SeedEstimatorService::estimate.
     * Makes ZERO Places API calls — spec §10 guardrail 2.
     */
    public function estimate(Request $request): void
    {
        $body = $request->getBody() ?? [];

        $bbox = $body['bbox'] ?? null;
        if (!is_array($bbox) || count($bbox) !== 4) {
            Response::error('bbox must be [lat_min, lng_min, lat_max, lng_max]', 422);
            return;
        }
        $bbox = array_map('floatval', $bbox);
        [$latMin, $lngMin, $latMax, $lngMax] = $bbox;
        if ($latMax <= $latMin || $lngMax <= $lngMin) {
            Response::error('bbox must satisfy lat_max > lat_min and lng_max > lng_min', 422);
            return;
        }
        if ($latMin < -90 || $latMax > 90 || $lngMin < -180 || $lngMax > 180) {
            Response::error('bbox lat/lng out of range', 422);
            return;
        }

        $vendorTypes = $body['vendor_types'] ?? null;
        if (!is_array($vendorTypes) || empty($vendorTypes)) {
            Response::error('vendor_types must be a non-empty array', 422);
            return;
        }

        $policy  = $body['enrich_policy']   ?? 'priority_types';
        $density = $body['density_profile'] ?? 'mixed';

        $monthlyVolume = self::currentMonthlyVolume();

        try {
            $estimator = new SeedEstimatorService();
            $result = $estimator->estimate(
                [
                    'bbox'            => $bbox,
                    'vendor_types'    => $vendorTypes,
                    'enrich_policy'   => $policy,
                    'density_profile' => $density,
                ],
                $monthlyVolume
            );
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
            return;
        }

        Response::success([
            'estimate'        => $result,
            'monthly_volume'  => $monthlyVolume,
        ]);
    }

    /**
     * Current calendar-month billable units per SKU family, from the
     * api_cost_events ledger. The estimator subtracts these from each
     * SKU's free-tier credit before pricing the campaign.
     *
     * Returns ['search' => N, 'details' => N] — add-on SKUs roll up
     * into the 'details' family for tiering purposes (they all bill
     * together at /v1/places/{id}).
     */
    public static function currentMonthlyVolume(): array
    {
        try {
            $db = Database::getInstance();
            $row = $db->fetch(
                "SELECT
                   COALESCE(SUM(CASE WHEN sku IN ('places_nearby_pro','places_text_pro') THEN billable_units ELSE 0 END), 0) AS search_units,
                   COALESCE(SUM(CASE WHEN sku IN ('place_details_pro','place_details_contact','place_details_atmosphere') THEN billable_units ELSE 0 END), 0) AS details_units
                 FROM api_cost_events
                 WHERE called_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
            );
            return [
                'search'  => (int) ($row['search_units']  ?? 0),
                'details' => (int) ($row['details_units'] ?? 0),
            ];
        } catch (\Throwable $e) {
            // If the table doesn't exist yet (mig 028 not run), return zeros.
            error_log('SeedCampaignController::currentMonthlyVolume: ' . $e->getMessage());
            return ['search' => 0, 'details' => 0];
        }
    }
}
