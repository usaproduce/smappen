<?php
namespace App\Services;

/**
 * SeedEstimatorService — dual-pass cost projection for a seeding
 * campaign. Carafe Vendor Network Spec v3 §5.2 + §10 guardrail 2:
 * "No campaign without an approved estimate."
 *
 * Estimator is pure given (a) the pricing config, (b) the campaign
 * inputs, and (c) the current monthly billable volume baseline. It
 * makes NO API calls — that's the contract. The controller fills in
 * the volume baseline from api_cost_events and hands the result back
 * to the cost-preview UI before the admin clicks Run.
 *
 * Model:
 *
 *   sweep_calls   = tiles × types_count × pages_per_tile(density)
 *   enrich_calls  = expected_vendors × enrich_policy_factor
 *
 *   sweep_cost    = billable(sweep_calls)  × rate_per_call(search,  monthly_volume)
 *   enrich_cost   = billable(enrich_calls) × (rate_per_call(details, vol)
 *                                            + contact_addon_per_call
 *                                            + atmosphere_addon_per_call)
 *
 *   billable(N)   = max(0, N − free_tier_remaining_for_sku)
 *
 *   total         = sweep_cost + enrich_cost
 *
 * Low / Expected / High come from sweeping pages_per_tile and the
 * vendor-density factor through their plausible ranges (rural/dense
 * profile bounds). Estimator returns the per-SKU breakdown alongside
 * so the UI can show which SKUs the cost lives in.
 *
 * Tile-grid generation is intentionally rough — the goal is to give the
 * admin a number good to within ±30% before they spend money, not to
 * reproduce the tile worker's exact subdivision logic. Spec §4.1 says
 * the worker auto-subdivides tiles that saturate (full 60-result page);
 * the estimator approximates that by using density to pick a baseline
 * tile size + pages-per-tile multiplier.
 */
class SeedEstimatorService
{
    /** Pages per Places sweep call assumed by density profile (low / expected / high). */
    private const PAGES_PER_TILE = [
        'rural'    => [1.0, 1.0, 1.3],
        'suburban' => [1.0, 1.7, 2.4],
        'dense'    => [1.5, 2.6, 3.0],
        'mixed'    => [1.0, 1.8, 2.6],
    ];

    /** Expected vendor yield per km² by density profile (low / expected / high). */
    private const VENDOR_DENSITY_PER_KM2 = [
        'rural'    => [0.02, 0.05, 0.10],
        'suburban' => [0.20, 0.50, 1.00],
        'dense'    => [2.00, 4.00, 7.00],
        'mixed'    => [0.20, 0.80, 2.00],
    ];

    /** Tile edge length in km by density profile (used to size the grid). */
    private const TILE_SIZE_KM = [
        'rural'    => 12.0,
        'suburban' => 6.0,
        'dense'    => 2.5,
        'mixed'    => 6.0,
    ];

    /** Spec §4.4 — fraction of deduped vendors that get a full Place Details pull at seed time. */
    private const ENRICH_POLICY_FACTOR = [
        'all'            => 1.00,
        'priority_types' => 0.30,
        'on_demand'      => 0.00,
    ];

    private array $pricing;

    public function __construct(?array $pricing = null)
    {
        $this->pricing = $pricing ?? require dirname(__DIR__, 2) . '/config/google_places_pricing.php';
    }

    /**
     * @param array $campaign {
     *     bbox: [lat_min, lng_min, lat_max, lng_max] required,
     *     vendor_types: array required (count drives sweep calls),
     *     enrich_policy: 'all'|'priority_types'|'on_demand' required,
     *     density_profile: 'rural'|'suburban'|'dense'|'mixed' optional, default 'mixed',
     * }
     * @param array $monthlyVolume {
     *     search:  int — billable units this month so far (places_nearby + places_text)
     *     details: int — billable units this month so far (place_details_pro family)
     * }
     */
    public function estimate(array $campaign, array $monthlyVolume = ['search' => 0, 'details' => 0]): array
    {
        $bbox    = $campaign['bbox'] ?? null;
        $types   = (array) ($campaign['vendor_types'] ?? []);
        $policy  = $campaign['enrich_policy'] ?? 'priority_types';
        $density = $campaign['density_profile'] ?? 'mixed';

        if (!$bbox || count($bbox) !== 4) {
            throw new \InvalidArgumentException('bbox required as [lat_min, lng_min, lat_max, lng_max]');
        }
        if (empty($types)) {
            throw new \InvalidArgumentException('vendor_types required (at least one)');
        }
        if (!isset(self::ENRICH_POLICY_FACTOR[$policy])) {
            throw new \InvalidArgumentException("unknown enrich_policy: $policy");
        }
        if (!isset(self::PAGES_PER_TILE[$density])) {
            throw new \InvalidArgumentException("unknown density_profile: $density");
        }

        [$latMin, $lngMin, $latMax, $lngMax] = array_map('floatval', $bbox);
        $areaKm2  = $this->bboxAreaKm2($latMin, $lngMin, $latMax, $lngMax);
        $tileKm   = self::TILE_SIZE_KM[$density];
        $tiles    = max(1, (int) ceil($areaKm2 / ($tileKm * $tileKm)));

        // Sweep calls per scenario. Each vendor type is its own sweep
        // (Nearby searches are includedTypes-scoped). Pagination
        // multiplier comes from the density profile.
        [$pLow, $pExp, $pHigh] = self::PAGES_PER_TILE[$density];
        $typesCount = count($types);
        $sweepLow   = (int) ceil($tiles * $typesCount * $pLow);
        $sweepExp   = (int) ceil($tiles * $typesCount * $pExp);
        $sweepHigh  = (int) ceil($tiles * $typesCount * $pHigh);

        // Expected vendors discovered → drives enrich-call count.
        [$dLow, $dExp, $dHigh] = self::VENDOR_DENSITY_PER_KM2[$density];
        $vendorsLow  = (int) ceil($areaKm2 * $dLow);
        $vendorsExp  = (int) ceil($areaKm2 * $dExp);
        $vendorsHigh = (int) ceil($areaKm2 * $dHigh);

        $policyFactor = self::ENRICH_POLICY_FACTOR[$policy];
        $enrichLow    = (int) ceil($vendorsLow  * $policyFactor);
        $enrichExp    = (int) ceil($vendorsExp  * $policyFactor);
        $enrichHigh   = (int) ceil($vendorsHigh * $policyFactor);

        $searchVol  = (int) ($monthlyVolume['search']  ?? 0);
        $detailsVol = (int) ($monthlyVolume['details'] ?? 0);

        $sweepCostLow  = $this->priceSweep($sweepLow,  $searchVol);
        $sweepCostExp  = $this->priceSweep($sweepExp,  $searchVol);
        $sweepCostHigh = $this->priceSweep($sweepHigh, $searchVol);

        $enrichCostLow  = $this->priceEnrich($enrichLow,  $detailsVol);
        $enrichCostExp  = $this->priceEnrich($enrichExp,  $detailsVol);
        $enrichCostHigh = $this->priceEnrich($enrichHigh, $detailsVol);

        $totalLow  = round($sweepCostLow['total']  + $enrichCostLow['total'],  2);
        $totalExp  = round($sweepCostExp['total']  + $enrichCostExp['total'],  2);
        $totalHigh = round($sweepCostHigh['total'] + $enrichCostHigh['total'], 2);

        return [
            'total' => [
                'low'      => $totalLow,
                'expected' => $totalExp,
                'high'     => $totalHigh,
            ],
            'sweep' => [
                'calls'    => ['low' => $sweepLow,  'expected' => $sweepExp,  'high' => $sweepHigh],
                'cost'     => ['low' => round($sweepCostLow['total'], 2), 'expected' => round($sweepCostExp['total'], 2), 'high' => round($sweepCostHigh['total'], 2)],
                'sku_breakdown_expected' => $sweepCostExp['skus'],
            ],
            'enrich' => [
                'policy'   => $policy,
                'vendors'  => ['low' => $vendorsLow, 'expected' => $vendorsExp, 'high' => $vendorsHigh],
                'calls'    => ['low' => $enrichLow,  'expected' => $enrichExp,  'high' => $enrichHigh],
                'cost'     => ['low' => round($enrichCostLow['total'], 2), 'expected' => round($enrichCostExp['total'], 2), 'high' => round($enrichCostHigh['total'], 2)],
                'sku_breakdown_expected' => $enrichCostExp['skus'],
            ],
            'free_tier_remaining' => [
                'places_nearby_pro'        => $this->freeRemaining('places_nearby_pro',        $searchVol),
                'places_text_pro'          => $this->freeRemaining('places_text_pro',          $searchVol),
                'place_details_pro'        => $this->freeRemaining('place_details_pro',        $detailsVol),
                'place_details_contact'    => $this->freeRemaining('place_details_contact',    $detailsVol),
                'place_details_atmosphere' => $this->freeRemaining('place_details_atmosphere', $detailsVol),
            ],
            'meta' => [
                'area_km2'           => round($areaKm2, 1),
                'tile_count'         => $tiles,
                'tile_size_km'       => $tileKm,
                'density_profile'    => $density,
                'enrich_policy'      => $policy,
                'pages_per_tile_exp' => $pExp,
            ],
        ];
    }

    /**
     * Public-and-pure: cost-after-free-tier for a count of Search calls
     * at the given monthly volume baseline. Exposed for testing.
     *
     * @return array{total: float, skus: array<string,float>}
     */
    public function priceSweep(int $calls, int $searchMonthlyVolume): array
    {
        // Sweep is single-SKU (Search Pro). For estimator purposes we
        // treat searchNearby and searchText interchangeably — they share
        // the same rate. PlacesClient writes them as distinct SKUs in
        // the ledger, so reconciliation can still separate them.
        $billable = $this->billableAfterFree('places_nearby_pro', $calls, $searchMonthlyVolume);
        $rate     = $this->tierRate('search', $searchMonthlyVolume + $billable / 2); // mid-tier rate
        $cost     = ($billable / 1000.0) * $rate;
        return [
            'total' => $cost,
            'skus'  => ['places_nearby_pro' => round($cost, 4)],
        ];
    }

    /**
     * Cost-after-free-tier for a count of Place Details (full enrich
     * mask) calls — Pro + Contact + Atmosphere stack. Each add-on has
     * its own free tier, so the three free-tier subtractions happen
     * independently.
     *
     * @return array{total: float, skus: array<string,float>}
     */
    public function priceEnrich(int $calls, int $detailsMonthlyVolume): array
    {
        if ($calls <= 0) {
            return ['total' => 0.0, 'skus' => ['place_details_pro' => 0.0, 'place_details_contact' => 0.0, 'place_details_atmosphere' => 0.0]];
        }
        $proBillable      = $this->billableAfterFree('place_details_pro',        $calls, $detailsMonthlyVolume);
        $contactBillable  = $this->billableAfterFree('place_details_contact',    $calls, $detailsMonthlyVolume);
        $atmoBillable     = $this->billableAfterFree('place_details_atmosphere', $calls, $detailsMonthlyVolume);

        $proRate          = $this->tierRate('details', $detailsMonthlyVolume + $proBillable / 2);
        $contactRate      = (float) ($this->pricing['addons']['place_details_contact']    ?? 0.0);
        $atmoRate         = (float) ($this->pricing['addons']['place_details_atmosphere'] ?? 0.0);

        $proCost      = ($proBillable     / 1000.0) * $proRate;
        $contactCost  = ($contactBillable / 1000.0) * $contactRate;
        $atmoCost     = ($atmoBillable    / 1000.0) * $atmoRate;

        return [
            'total' => $proCost + $contactCost + $atmoCost,
            'skus'  => [
                'place_details_pro'        => round($proCost,     4),
                'place_details_contact'    => round($contactCost, 4),
                'place_details_atmosphere' => round($atmoCost,    4),
            ],
        ];
    }

    /** Public for testing. Free-tier units remaining for a SKU this month. */
    public function freeRemaining(string $sku, int $monthlyVolumeForFamily): int
    {
        $monthly = $this->pricing['free_tier_monthly'][$sku] ?? 0;
        return max(0, $monthly - $monthlyVolumeForFamily);
    }

    /** Public for testing. Bbox geodesic area in km². */
    public function bboxAreaKm2(float $latMin, float $lngMin, float $latMax, float $lngMax): float
    {
        if ($latMax <= $latMin || $lngMax <= $lngMin) return 0.0;
        $latMid    = ($latMin + $latMax) / 2.0;
        $heightKm  = ($latMax - $latMin) * 111.32;
        $widthKm   = ($lngMax - $lngMin) * 111.32 * cos(deg2rad($latMid));
        return abs($widthKm * $heightKm);
    }

    // ─────────────────────────────────────────────────────────────────
    // Private — pricing internals
    // ─────────────────────────────────────────────────────────────────

    /**
     * How many of $calls fall outside the SKU's monthly free tier given
     * $monthlyVolume already burned. Each SKU's free tier is tracked in
     * config['free_tier_monthly'] and is per-SKU (not per-family) since
     * Google credits each line item separately.
     */
    private function billableAfterFree(string $sku, int $calls, int $monthlyVolume): int
    {
        $free = $this->pricing['free_tier_monthly'][$sku] ?? 0;
        $remaining = max(0, $free - $monthlyVolume);
        return max(0, $calls - $remaining);
    }

    /**
     * Tier rate per 1k for a SKU family at the given monthly volume.
     * Walks pricing['tiers'][$family] in order; first tier whose
     * up_to_units bound is not exceeded wins. The null up_to means
     * "everything past this point."
     */
    private function tierRate(string $family, float $monthlyVolume): float
    {
        $tiers = $this->pricing['tiers'][$family] ?? [];
        foreach ($tiers as $tier) {
            $cap = $tier['up_to_units'] ?? null;
            if ($cap === null || $monthlyVolume < $cap) {
                return (float) $tier['rate_per_1k_usd'];
            }
        }
        return 0.0;
    }
}
