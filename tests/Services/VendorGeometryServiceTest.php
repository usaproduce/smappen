<?php
namespace App\Tests\Services;

use App\Services\VendorGeometryService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-helper tests for the Phase 9 additions to VendorGeometryService.
 * The DB-touching paths (ensureCoverageForVendor / setIsochroneCoverage
 * / simplifyCoverage) need the integration harness. Here we lock the
 * type → minutes / type → radius / type → coverage_type maps that
 * drive every coverage decision.
 *
 * Carafe Vendor Network Spec v3 §4.5.
 */
class VendorGeometryServiceTest extends TestCase
{
    // ─── isochroneMinutesForType ─────────────────────────────────────

    public function testBroadlineGetsLongIsochrone(): void
    {
        // Spec §4.5: "60/90-min drive-time isochrones for delivery vendors."
        // Broadliners are the wide-reach archetype.
        $this->assertSame(90, VendorGeometryService::isochroneMinutesForType('broadline'));
    }

    public function testCashCarryGets30MinIsochrone(): void
    {
        // Spec §4.5: "30-min for cash-and-carry."
        $this->assertSame(30, VendorGeometryService::isochroneMinutesForType('cash_carry'));
    }

    public function testLegacyWarehouseEquivalentToCashCarry(): void
    {
        // Migration 026 used 'warehouse' as the legacy enum for what
        // spec §2 now calls 'cash_carry'. Both must produce the same
        // operational behavior so existing rows aren't penalized.
        $this->assertSame(
            VendorGeometryService::isochroneMinutesForType('cash_carry'),
            VendorGeometryService::isochroneMinutesForType('warehouse')
        );
    }

    public function testProduceAndMeatAndSeafoodGet60MinIsochrone(): void
    {
        $this->assertSame(60, VendorGeometryService::isochroneMinutesForType('produce'));
        $this->assertSame(60, VendorGeometryService::isochroneMinutesForType('meat'));
        $this->assertSame(60, VendorGeometryService::isochroneMinutesForType('protein')); // legacy
        $this->assertSame(60, VendorGeometryService::isochroneMinutesForType('seafood'));
    }

    public function testLocalGroceryAndSmallwaresGetNoIsochrone(): void
    {
        // Spec §4.5 implies these long-tail types should use radius —
        // delivery zones for a corner grocery aren't worth an ORS call.
        $this->assertNull(VendorGeometryService::isochroneMinutesForType('local_grocery'));
        $this->assertNull(VendorGeometryService::isochroneMinutesForType('grocery')); // legacy
        $this->assertNull(VendorGeometryService::isochroneMinutesForType('smallwares_equip'));
    }

    public function testUnknownTypeFallsBackToNull(): void
    {
        // Defensive: an unknown type means we don't know how to drive-
        // time it; caller falls back to radius via the null signal.
        $this->assertNull(VendorGeometryService::isochroneMinutesForType('something_brand_new'));
        $this->assertNull(VendorGeometryService::isochroneMinutesForType(null));
        $this->assertNull(VendorGeometryService::isochroneMinutesForType(''));
    }

    // ─── coverageTypeForVendorType ───────────────────────────────────

    public function testCashCarryIsPickupDrivetime(): void
    {
        // Spec §4.5: operator drives TO the cash-and-carry warehouse,
        // so the coverage geometry models the drive radius around the
        // warehouse (pickup_drivetime), not a delivery zone.
        $this->assertSame('pickup_drivetime', VendorGeometryService::coverageTypeForVendorType('cash_carry'));
        $this->assertSame('pickup_drivetime', VendorGeometryService::coverageTypeForVendorType('warehouse'));
    }

    public function testDeliveryTypesGetDeliveryCoverage(): void
    {
        $this->assertSame('delivery', VendorGeometryService::coverageTypeForVendorType('broadline'));
        $this->assertSame('delivery', VendorGeometryService::coverageTypeForVendorType('produce'));
        $this->assertSame('delivery', VendorGeometryService::coverageTypeForVendorType('seafood'));
        $this->assertSame('delivery', VendorGeometryService::coverageTypeForVendorType('meat'));
        $this->assertSame('delivery', VendorGeometryService::coverageTypeForVendorType(null));
    }

    // ─── defaultRadiusMiles ──────────────────────────────────────────

    public function testRadiusFallbackBroadlineIsBroadest(): void
    {
        // Sanity ordering: broadline > seafood ≥ produce > local_grocery.
        $b  = VendorGeometryService::defaultRadiusMiles('broadline');
        $sf = VendorGeometryService::defaultRadiusMiles('seafood');
        $p  = VendorGeometryService::defaultRadiusMiles('produce');
        $g  = VendorGeometryService::defaultRadiusMiles('local_grocery');

        $this->assertGreaterThan($sf, $b);
        $this->assertGreaterThanOrEqual($p, $sf);
        $this->assertGreaterThan($g,  $p);
    }

    public function testRadiusFallbackLegacyAndSpecTypesAgree(): void
    {
        // Legacy + spec aliases must produce identical fallback radii;
        // otherwise the same physical vendor gets a different radius
        // depending on which enum value the classifier wrote.
        $this->assertSame(
            VendorGeometryService::defaultRadiusMiles('cash_carry'),
            VendorGeometryService::defaultRadiusMiles('warehouse')
        );
        $this->assertSame(
            VendorGeometryService::defaultRadiusMiles('meat'),
            VendorGeometryService::defaultRadiusMiles('protein')
        );
        $this->assertSame(
            VendorGeometryService::defaultRadiusMiles('local_grocery'),
            VendorGeometryService::defaultRadiusMiles('grocery')
        );
        $this->assertSame(
            VendorGeometryService::defaultRadiusMiles('dairy_bakery_bev'),
            VendorGeometryService::defaultRadiusMiles('bakery_dairy_beverage')
        );
    }

    public function testRadiusFallbackUnknownHasDefault(): void
    {
        $this->assertGreaterThan(0, VendorGeometryService::defaultRadiusMiles('completely_unknown'));
    }

    // ─── circleWkt (existing helper — re-verified) ───────────────────

    public function testCircleWktClosesTheRing(): void
    {
        $wkt = VendorGeometryService::circleWkt(38.9, -77.0, 5.0);
        $this->assertStringStartsWith('POLYGON((', $wkt);
        // The last point in the ring must equal the first so MySQL
        // accepts it as a valid POLYGON.
        $inner = trim($wkt, 'POLYGON()');
        $points = explode(', ', $inner);
        $this->assertGreaterThanOrEqual(4, count($points)); // 3 verts + closure min
        $this->assertSame($points[0], end($points));
    }

    public function testCircleWktRadiusScalesPolygon(): void
    {
        // 1-mile vs 100-mile polygon at the same center — same vertex
        // count, but the bounding-box of the big polygon must extend
        // farther in lat than the small one.
        $small = VendorGeometryService::circleWkt(38.9, -77.0, 1.0);
        $big   = VendorGeometryService::circleWkt(38.9, -77.0, 100.0);
        $this->assertNotSame($small, $big);
        $this->assertGreaterThan(
            self::maxLatDelta($small, 38.9),
            self::maxLatDelta($big,   38.9)
        );
    }

    /** Read a polygon's WKT and return the largest |lat - center_lat| in its ring. */
    private static function maxLatDelta(string $wkt, float $centerLat): float
    {
        preg_match('/POLYGON\(\((.*)\)\)/', $wkt, $m);
        if (empty($m[1])) return 0.0;
        $maxDelta = 0.0;
        foreach (explode(',', $m[1]) as $pair) {
            $parts = preg_split('/\s+/', trim($pair));
            if (count($parts) < 2) continue;
            $maxDelta = max($maxDelta, abs((float) $parts[0] - $centerLat));
        }
        return $maxDelta;
    }
}
