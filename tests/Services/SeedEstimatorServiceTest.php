<?php
namespace App\Tests\Services;

use App\Services\SeedEstimatorService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-math tests for SeedEstimatorService. No DB, no network — the
 * estimator's whole contract is to be deterministic given inputs +
 * pricing + monthly volume baseline (Carafe v3 spec §5.2 + §10
 * guardrail 2).
 *
 * Two example bboxes used throughout:
 *   - DC-ish ~150 km²  (latMin 38.80, lngMin -77.10, latMax 38.92, lngMax -76.95)
 *   - Virginia-ish ~110,000 km²  (latMin 36.5, lngMin -83.5, latMax 39.5, lngMax -75.5)
 */
class SeedEstimatorServiceTest extends TestCase
{
    private SeedEstimatorService $svc;

    protected function setUp(): void
    {
        $this->svc = new SeedEstimatorService();
    }

    // ─── Input validation ────────────────────────────────────────────

    public function testBboxRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->estimate(['vendor_types' => ['produce'], 'enrich_policy' => 'priority_types']);
    }

    public function testVendorTypesRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->estimate([
            'bbox' => [38.80, -77.10, 38.92, -76.95],
            'vendor_types' => [],
            'enrich_policy' => 'priority_types',
        ]);
    }

    public function testUnknownEnrichPolicyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->estimate([
            'bbox' => [38.80, -77.10, 38.92, -76.95],
            'vendor_types' => ['produce'],
            'enrich_policy' => 'totally-made-up',
        ]);
    }

    public function testUnknownDensityProfileRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->estimate([
            'bbox' => [38.80, -77.10, 38.92, -76.95],
            'vendor_types' => ['produce'],
            'enrich_policy' => 'priority_types',
            'density_profile' => 'extra-spicy',
        ]);
    }

    // ─── Geodesic + tiling sanity ────────────────────────────────────

    public function testBboxAreaMatchesGeodesicApproximation(): void
    {
        // DC-ish bbox: 0.12° lat (13.36 km) × 0.15° lng × cos(38.86°)
        // (12.99 km) ≈ 174 km². Allow a 10% band on either side.
        $area = $this->svc->bboxAreaKm2(38.80, -77.10, 38.92, -76.95);
        $this->assertGreaterThan(160, $area);
        $this->assertLessThan(190, $area);
    }

    public function testInvertedBboxAreaIsZero(): void
    {
        // lat_max < lat_min — degenerate, area should be 0, not negative.
        $this->assertSame(0.0, $this->svc->bboxAreaKm2(40.0, -77.0, 39.0, -76.0));
    }

    public function testSmallRegionAlwaysGetsAtLeastOneTile(): void
    {
        // A 100m × 100m bbox should still cost something.
        $est = $this->svc->estimate([
            'bbox'            => [38.900, -77.000, 38.901, -76.999],
            'vendor_types'    => ['produce'],
            'enrich_policy'   => 'priority_types',
            'density_profile' => 'rural',
        ]);
        $this->assertSame(1, $est['meta']['tile_count']);
        $this->assertGreaterThan(0, $est['sweep']['calls']['expected']);
    }

    // ─── Enrich-policy cost ordering ─────────────────────────────────

    public function testEnrichPolicyOrdering(): void
    {
        // Same region, three policies. Cost order must be all > priority_types > on_demand.
        $base = [
            'bbox'            => [38.80, -77.10, 38.92, -76.95],
            'vendor_types'    => ['produce', 'meat', 'seafood'],
            'density_profile' => 'dense',
        ];
        // Push monthly volume past the free tier so we actually see add-on cost.
        $vol = ['search' => 10_000, 'details' => 10_000];

        $all  = $this->svc->estimate($base + ['enrich_policy' => 'all'],            $vol);
        $pri  = $this->svc->estimate($base + ['enrich_policy' => 'priority_types'], $vol);
        $od   = $this->svc->estimate($base + ['enrich_policy' => 'on_demand'],      $vol);

        $this->assertGreaterThan($pri['total']['expected'], $all['total']['expected']);
        $this->assertGreaterThan($od['total']['expected'],  $pri['total']['expected']);
        $this->assertSame(0.0,     (float) $od['enrich']['cost']['expected']);
        $this->assertSame(0,       $od['enrich']['calls']['expected']);
    }

    public function testOnDemandEnrichHasZeroEnrichCalls(): void
    {
        $est = $this->svc->estimate([
            'bbox'            => [38.80, -77.10, 38.92, -76.95],
            'vendor_types'    => ['produce'],
            'enrich_policy'   => 'on_demand',
            'density_profile' => 'dense',
        ]);
        $this->assertSame(0, $est['enrich']['calls']['expected']);
        $this->assertSame(0.0, (float) $est['enrich']['cost']['expected']);
        // Sweep still happens — that's the whole point of on_demand.
        $this->assertGreaterThan(0, $est['sweep']['calls']['expected']);
    }

    // ─── Free-tier accounting ────────────────────────────────────────

    public function testFreeTierRemainingDecrementsWithMonthlyVolume(): void
    {
        $rem0    = $this->svc->freeRemaining('places_nearby_pro', 0);
        $rem2500 = $this->svc->freeRemaining('places_nearby_pro', 2500);
        $rem6000 = $this->svc->freeRemaining('places_nearby_pro', 6000);
        $this->assertSame(5000, $rem0);
        $this->assertSame(2500, $rem2500);
        $this->assertSame(0,    $rem6000); // never negative
    }

    public function testSmallSweepInsideFreeTierCostsZero(): void
    {
        // Tiny rural region, monthly volume well under free tier — Google
        // credits the calls, our billable count is zero, cost is zero.
        $est = $this->svc->estimate(
            [
                'bbox'            => [38.900, -77.001, 38.901, -77.000],
                'vendor_types'    => ['produce'],
                'enrich_policy'   => 'on_demand',
                'density_profile' => 'rural',
            ],
            ['search' => 0, 'details' => 0]
        );
        $this->assertSame(0.0, (float) $est['total']['expected']);
        $this->assertSame(0.0, (float) $est['sweep']['cost']['expected']);
    }

    public function testSameSweepOutsideFreeTierCostsPositive(): void
    {
        // Same tiny region, but baseline already past free tier — now we pay.
        $est = $this->svc->estimate(
            [
                'bbox'            => [38.900, -77.001, 38.901, -77.000],
                'vendor_types'    => ['produce'],
                'enrich_policy'   => 'on_demand',
                'density_profile' => 'rural',
            ],
            ['search' => 100_000, 'details' => 100_000]
        );
        $this->assertGreaterThan(0, (float) $est['total']['expected']);
    }

    // ─── Pricing primitives (priceSweep / priceEnrich) ───────────────

    public function testPriceSweep10kCallsOutsideFreeTierFirstTier(): void
    {
        // monthly volume already past free tier, well inside first paid tier.
        $r = $this->svc->priceSweep(10_000, 10_000);
        // 10k calls × ($32/1000) = $320
        $this->assertEqualsWithDelta(320.0, $r['total'], 0.5);
    }

    public function testPriceSweepFreeTierEatsSmallCallCount(): void
    {
        // 1000 sweep calls when 5000 free are available — billable = 0.
        $r = $this->svc->priceSweep(1000, 0);
        $this->assertEqualsWithDelta(0.0, $r['total'], 1e-9);
    }

    public function testPriceEnrichStacksAllThreeSkus(): void
    {
        // 10k enrich calls outside all three free tiers. Per-call:
        //   pro     = $17/1k = $0.017
        //   contact = $3/1k  = $0.003
        //   atmo    = $5/1k  = $0.005
        // Total per call = $0.025  → 10k × $0.025 = $250
        $r = $this->svc->priceEnrich(10_000, 10_000);
        $this->assertEqualsWithDelta(250.0, $r['total'], 0.5);
        $this->assertArrayHasKey('place_details_pro',        $r['skus']);
        $this->assertArrayHasKey('place_details_contact',    $r['skus']);
        $this->assertArrayHasKey('place_details_atmosphere', $r['skus']);
        $this->assertGreaterThan($r['skus']['place_details_contact'], $r['skus']['place_details_pro']);
    }

    public function testPriceEnrichZeroCallsIsZeroCost(): void
    {
        $r = $this->svc->priceEnrich(0, 100_000);
        $this->assertSame(0.0, $r['total']);
    }

    // ─── Bands: low ≤ expected ≤ high ────────────────────────────────

    public function testBandsAreOrderedLowExpectedHigh(): void
    {
        $vol = ['search' => 10_000, 'details' => 10_000];
        $est = $this->svc->estimate([
            'bbox'            => [38.80, -77.10, 38.92, -76.95],
            'vendor_types'    => ['produce', 'meat'],
            'enrich_policy'   => 'priority_types',
            'density_profile' => 'mixed',
        ], $vol);
        $this->assertLessThanOrEqual($est['total']['expected'], $est['total']['low']);
        $this->assertLessThanOrEqual($est['total']['high'],     $est['total']['expected']);
        $this->assertLessThanOrEqual($est['sweep']['calls']['expected'], $est['sweep']['calls']['high']);
        $this->assertLessThanOrEqual($est['enrich']['calls']['expected'], $est['enrich']['calls']['high']);
    }

    public function testMoreVendorTypesMeansMoreSweepCalls(): void
    {
        // Two types should produce roughly 2× the sweep calls of one type
        // (one Nearby search per type per tile).
        $vol = ['search' => 10_000, 'details' => 10_000];
        $one = $this->svc->estimate([
            'bbox'            => [38.80, -77.10, 38.92, -76.95],
            'vendor_types'    => ['produce'],
            'enrich_policy'   => 'priority_types',
            'density_profile' => 'suburban',
        ], $vol);
        $three = $this->svc->estimate([
            'bbox'            => [38.80, -77.10, 38.92, -76.95],
            'vendor_types'    => ['produce', 'meat', 'seafood'],
            'enrich_policy'   => 'priority_types',
            'density_profile' => 'suburban',
        ], $vol);
        $ratio = $three['sweep']['calls']['expected'] / max(1, $one['sweep']['calls']['expected']);
        $this->assertGreaterThan(2.5, $ratio);
        $this->assertLessThan(3.5,    $ratio);
    }

    // ─── Density profile shapes the call count ───────────────────────

    public function testDenseProfileProducesMoreTilesThanRural(): void
    {
        $vol = ['search' => 10_000, 'details' => 10_000];
        $base = [
            'bbox'          => [38.80, -77.10, 38.92, -76.95],
            'vendor_types'  => ['produce'],
            'enrich_policy' => 'priority_types',
        ];
        $rural = $this->svc->estimate($base + ['density_profile' => 'rural'],    $vol);
        $dense = $this->svc->estimate($base + ['density_profile' => 'dense'],    $vol);
        $this->assertGreaterThan($rural['meta']['tile_count'], $dense['meta']['tile_count']);
        $this->assertGreaterThan($rural['enrich']['vendors']['expected'], $dense['enrich']['vendors']['expected']);
    }

    // ─── Estimator output shape ──────────────────────────────────────

    public function testEstimateOutputShapeIsStable(): void
    {
        $est = $this->svc->estimate([
            'bbox'            => [38.80, -77.10, 38.92, -76.95],
            'vendor_types'    => ['produce'],
            'enrich_policy'   => 'priority_types',
            'density_profile' => 'mixed',
        ]);
        foreach (['total', 'sweep', 'enrich', 'free_tier_remaining', 'meta'] as $key) {
            $this->assertArrayHasKey($key, $est);
        }
        foreach (['low', 'expected', 'high'] as $band) {
            $this->assertArrayHasKey($band, $est['total']);
            $this->assertArrayHasKey($band, $est['sweep']['calls']);
            $this->assertArrayHasKey($band, $est['sweep']['cost']);
            $this->assertArrayHasKey($band, $est['enrich']['calls']);
            $this->assertArrayHasKey($band, $est['enrich']['cost']);
        }
    }
}
