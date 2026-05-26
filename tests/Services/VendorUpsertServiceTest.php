<?php
namespace App\Tests\Services;

use App\Services\VendorUpsertService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-helper tests for VendorUpsertService. The multi-table upsert
 * paths (upsertVendorFromPlace, upsertGoogleDetails, upsertReviews,
 * upsertPhotos) need a real MySQL connection — those are integration
 * tests. We assert here on what's testable without DB:
 *
 *   - categoryFromType heuristic (used by every sweep-pass write)
 *
 * Carafe Vendor Network Spec v3 §12.6.
 */
class VendorUpsertServiceTest extends TestCase
{
    public function testCategoryWholesalerMapsToBroadline(): void
    {
        $this->assertSame('broadline', VendorUpsertService::categoryFromType('wholesaler'));
        $this->assertSame('broadline', VendorUpsertService::categoryFromType('food_distributor'));
        $this->assertSame('broadline', VendorUpsertService::categoryFromType('warehouse_club'));
    }

    public function testCategoryProduce(): void
    {
        $this->assertSame('produce', VendorUpsertService::categoryFromType('produce_market'));
    }

    public function testCategoryMeatAndSeafood(): void
    {
        $this->assertSame('protein', VendorUpsertService::categoryFromType('butcher_shop'));
        $this->assertSame('protein', VendorUpsertService::categoryFromType('meat_market'));
        $this->assertSame('seafood', VendorUpsertService::categoryFromType('seafood_market'));
        $this->assertSame('seafood', VendorUpsertService::categoryFromType('fish_market'));
    }

    public function testCategoryUnknownFallsBackToSpecialty(): void
    {
        $this->assertSame('specialty', VendorUpsertService::categoryFromType('liquor_store'));
        $this->assertSame('specialty', VendorUpsertService::categoryFromType('something_brand_new'));
    }

    public function testCategoryNullSafe(): void
    {
        // Sweep mask sometimes returns no primaryType — must not blow up.
        $this->assertSame('specialty', VendorUpsertService::categoryFromType(null));
        $this->assertSame('specialty', VendorUpsertService::categoryFromType(''));
    }

    public function testCategoryIsCaseInsensitive(): void
    {
        $this->assertSame('broadline', VendorUpsertService::categoryFromType('WHOLESALER'));
        $this->assertSame('seafood',   VendorUpsertService::categoryFromType('Seafood_Market'));
    }
}
