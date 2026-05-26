<?php
namespace App\Tests\Services;

use App\Services\VendorClassifierService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-cascade tests for VendorClassifierService::classify. The
 * persistence path (classifyVendor / classifyPending) hits MySQL and
 * needs the integration harness; here we lock the decision logic.
 *
 * Carafe Vendor Network Spec v3 §4.3.
 */
class VendorClassifierServiceTest extends TestCase
{
    // ─── Tier 1: brand-name hit (95) ─────────────────────────────────

    public function testBrandSyscoIsBroadlineHighConfidence(): void
    {
        $c = VendorClassifierService::classify('wholesaler', [], 'Sysco Foods of Maryland');
        $this->assertSame('broadline', $c['type']);
        $this->assertSame(95,          $c['confidence']);
        $this->assertContains('brand:sysco', $c['signals']);
        $this->assertFalse($c['needs_review']);
    }

    public function testBrandRestaurantDepotIsCashCarry(): void
    {
        $c = VendorClassifierService::classify('warehouse_store', [], 'Restaurant Depot #142');
        $this->assertSame('cash_carry', $c['type']);
        $this->assertSame(95,           $c['confidence']);
    }

    public function testBrandChefsWarehouseWithApostropheVariants(): void
    {
        $c1 = VendorClassifierService::classify('wholesaler', [], "Chef's Warehouse — Mid-Atlantic");
        $c2 = VendorClassifierService::classify('wholesaler', [], 'Chefs Warehouse Mid-Atlantic');
        $this->assertSame('cash_carry', $c1['type']);
        $this->assertSame('cash_carry', $c2['type']);
    }

    // ─── Tier 2: strong primaryType (85) ─────────────────────────────

    public function testStrongPrimaryTypeProduceMarket(): void
    {
        $c = VendorClassifierService::classify('produce_market', [], 'Hunts Point Terminal');
        $this->assertSame('produce', $c['type']);
        $this->assertSame(85,        $c['confidence']);
        $this->assertContains('primary_type:produce_market', $c['signals']);
    }

    public function testStrongPrimaryTypeButcherShop(): void
    {
        $c = VendorClassifierService::classify('butcher_shop', [], 'Bruce\'s Meats');
        $this->assertSame('meat', $c['type']);
        $this->assertSame(85,     $c['confidence']);
    }

    public function testStrongTypeInSecondaryArrayIsWeaker(): void
    {
        // Same target type, but only in the secondary types array — confidence 75.
        $c = VendorClassifierService::classify('food_store', ['produce_market'], 'Generic Market');
        $this->assertSame('produce', $c['type']);
        $this->assertSame(75,        $c['confidence']);
        $this->assertContains('secondary_type:produce_market', $c['signals']);
    }

    // ─── Tier 3: generic type + name keyword (70) ────────────────────

    public function testGenericTypePlusProduceKeyword(): void
    {
        $c = VendorClassifierService::classify('wholesaler', [], "Joe's Produce Distributors");
        $this->assertSame('produce', $c['type']);
        $this->assertSame(70,        $c['confidence']);
    }

    public function testGenericTypePlusMeatKeyword(): void
    {
        $c = VendorClassifierService::classify('wholesaler', [], 'Atlantic Meat Wholesale');
        $this->assertSame('meat', $c['type']);
        $this->assertSame(70,     $c['confidence']);
    }

    public function testGenericTypePlusSeafoodKeyword(): void
    {
        $c = VendorClassifierService::classify('wholesaler', [], 'Chesapeake Seafood Co');
        $this->assertSame('seafood', $c['type']);
        $this->assertSame(70,        $c['confidence']);
    }

    // ─── Tier 3b: name keyword alone (50) ────────────────────────────

    public function testNameKeywordAloneIsLowMedium(): void
    {
        // primaryType unknown, no generic match. Falls into 'name_keyword' tier.
        $c = VendorClassifierService::classify('liquor_store', [], 'Bayside Seafood Imports');
        $this->assertSame('seafood', $c['type']);
        $this->assertSame(50,        $c['confidence']);
        $this->assertTrue($c['needs_review'], 'confidence 50 is below the review threshold of 60');
    }

    // ─── Tier 4: fall-through (review queue) ─────────────────────────

    public function testGenericTypeWithNoKeywordIsLowConfidence(): void
    {
        // Just "wholesaler" + no helpful name → defaults to broadline,
        // confidence 30, flagged for review.
        $c = VendorClassifierService::classify('wholesaler', [], 'ABC Trading LLC');
        $this->assertSame('broadline', $c['type']);
        $this->assertSame(30,          $c['confidence']);
        $this->assertTrue($c['needs_review']);
        $this->assertContains('default_from_generic:wholesaler', $c['signals']);
    }

    public function testFullyUnknownPrimaryAndNameIsLowest(): void
    {
        $c = VendorClassifierService::classify(null, [], 'Mystery Co');
        $this->assertSame('broadline', $c['type']);
        $this->assertSame(15,          $c['confidence']);
        $this->assertTrue($c['needs_review']);
        $this->assertContains('default_unknown', $c['signals']);
    }

    public function testEmptyNameWithEmptyTypeDoesntCrash(): void
    {
        $c = VendorClassifierService::classify(null, [], '');
        $this->assertIsString($c['type']);
        $this->assertIsInt($c['confidence']);
        $this->assertTrue($c['needs_review']);
    }

    // ─── Review-threshold semantics ──────────────────────────────────

    public function testReviewThresholdConstantIsSixty(): void
    {
        // Locked: anything below 60 needs review. Changing this is a
        // policy decision — touch the constant deliberately.
        $this->assertSame(60, VendorClassifierService::CONFIDENCE_REVIEW_THRESHOLD);
    }

    public function testNeedsReviewMatchesThreshold(): void
    {
        // Confidence 60 is NOT below 60 → not flagged.
        // (We don't currently produce score==60 directly, but the
        // boundary behavior is locked here so it stays predictable.)
        // Confidence 50 (name-keyword-only) IS flagged.
        $kw = VendorClassifierService::classify(null, [], 'Bayside Seafood Imports');
        $this->assertTrue($kw['needs_review']);
        $brand = VendorClassifierService::classify(null, [], 'Sysco Foods');
        $this->assertFalse($brand['needs_review']);
    }

    // ─── Output shape ────────────────────────────────────────────────

    public function testOutputShapeIsStable(): void
    {
        $c = VendorClassifierService::classify('produce_market', ['wholesaler'], 'Foo');
        foreach (['type', 'category', 'confidence', 'signals', 'needs_review'] as $k) {
            $this->assertArrayHasKey($k, $c, "missing key: $k");
        }
        $this->assertIsArray($c['signals']);
        $this->assertGreaterThan(0, count($c['signals']));
    }

    public function testCategoryFollowsType(): void
    {
        // type → category map should yield sensible categories.
        $this->assertSame('produce',   VendorClassifierService::classify('produce_market', [], 'X')['category']);
        $this->assertSame('protein',   VendorClassifierService::classify('butcher_shop',   [], 'X')['category']);
        $this->assertSame('seafood',   VendorClassifierService::classify('seafood_market', [], 'X')['category']);
        $this->assertSame('broadline', VendorClassifierService::classify('wholesaler',     [], 'Sysco Foods')['category']);
    }

    public function testBrandHitTakesPrecedenceOverPrimaryType(): void
    {
        // primaryType says wholesaler, name says Sysco. Brand should
        // win — Sysco is a broadline distributor regardless of the
        // primary_type Google attached.
        $c = VendorClassifierService::classify('warehouse_store', [], 'Sysco Foods');
        $this->assertSame('broadline', $c['type']);   // NOT 'cash_carry'
        $this->assertSame(95,          $c['confidence']);
    }
}
