<?php
namespace App\Tests\Services;

use App\Services\BudgetCapExceededException;
use App\Services\PlacesClient;
use PHPUnit\Framework\TestCase;

/**
 * PlacesClient unit tests. No DB, no network — we subclass PlacesClient
 * and override the three protected seams (httpRequest, persistEvent,
 * monthlyVolume) so the assertions stay pure.
 *
 * What we lock down here is the contract of Phase 1 (Carafe Vendor
 * Network Spec v3 §9 step 1):
 *
 *   - field-mask enforcement: every public method emits the right mask
 *     header, no caller-supplied tokens, no leakage of expensive
 *     fields into the sweep pass
 *   - cost-ledger write: one row per SKU per call, no silent skips
 *   - budget cap: pre-call projection halts BEFORE the HTTP fires
 *   - grant fallback: storage_allowed=false degrades sweep to id-only
 *     and refuses Details / Photo
 *   - tier rate: monthly volume crossing 100k flips to the next tier
 */
class PlacesClientTest extends TestCase
{
    private array $pricing;
    private array $grantOn;
    private array $grantOff;

    protected function setUp(): void
    {
        $this->pricing  = require __DIR__ . '/../../config/google_places_pricing.php';
        $this->grantOn  = ['places_storage_allowed' => true];
        $this->grantOff = ['places_storage_allowed' => false];
    }

    // ─── Field-mask enforcement ──────────────────────────────────────

    public function testSweepNearbyMaskIsCheap(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->fakeResponse(200, '{"places":[]}');
        $c->searchNearby(['locationRestriction' => ['circle' => ['center' => ['latitude' => 0, 'longitude' => 0], 'radius' => 1000]]]);

        $mask = $c->lastFieldMask();
        $this->assertStringContainsString('places.id', $mask);
        $this->assertStringContainsString('places.displayName', $mask);
        // Cheap sweep must NOT leak contact / atmosphere fields.
        $this->assertStringNotContainsString('nationalPhoneNumber', $mask);
        $this->assertStringNotContainsString('websiteUri', $mask);
        $this->assertStringNotContainsString('rating', $mask);
        $this->assertStringNotContainsString('regularOpeningHours', $mask);
    }

    public function testEnrichFullMaskTriggersAllThreeSkus(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->fakeResponse(200, '{"id":"abc"}');
        $c->placeDetails('abc', 'enrich_full');

        $skusWritten = array_column($c->events(), 'sku');
        $this->assertContains('place_details_pro',        $skusWritten);
        $this->assertContains('place_details_contact',    $skusWritten);
        $this->assertContains('place_details_atmosphere', $skusWritten);
        $this->assertCount(3, $skusWritten);
    }

    public function testTierColdMaskBillsOnlyProSku(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->fakeResponse(200, '{"id":"abc"}');
        $c->placeDetails('abc', 'tier_cold');

        $skus = array_column($c->events(), 'sku');
        $this->assertSame(['place_details_pro'], $skus);
    }

    public function testTierWarmMaskBillsAllThreeBecauseHoursIsAtmosphere(): void
    {
        // tier_warm intentionally includes hours per spec §12.1 (90-day
        // volatility). Hours fields bill the Atmosphere SKU per Google's
        // Places SKU table, so a tier_warm refresh stacks Pro+Contact+
        // Atmosphere even though we're not refreshing rating. This is
        // the spec-literal tier; see config/google_places_pricing.php
        // 'masks.tier_warm' note for the cost tradeoff.
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->fakeResponse(200, '{"id":"abc"}');
        $c->placeDetails('abc', 'tier_warm');

        $skus = array_column($c->events(), 'sku');
        sort($skus);
        $this->assertSame(
            ['place_details_atmosphere', 'place_details_contact', 'place_details_pro'],
            $skus
        );
    }

    public function testTierHotMaskBillsProAndAtmosphere(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->fakeResponse(200, '{"id":"abc"}');
        $c->placeDetails('abc', 'tier_hot');

        $skus = array_column($c->events(), 'sku');
        sort($skus);
        $this->assertSame(['place_details_atmosphere', 'place_details_pro'], $skus);
    }

    public function testUnknownMaskPresetThrows(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $this->expectException(\InvalidArgumentException::class);
        $c->placeDetails('abc', 'totally-made-up-preset');
    }

    // ─── Cost-ledger writes ──────────────────────────────────────────

    public function testEverySweepCallWritesOneLedgerRow(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->fakeResponse(200, '{"places":[]}');
        $c->searchNearby(['locationRestriction' => ['circle' => ['center' => ['latitude' => 0, 'longitude' => 0], 'radius' => 1000]]]);
        $c->searchText(['textQuery' => 'produce wholesale']);

        $this->assertCount(2, $c->events());
        $this->assertSame('places_nearby_pro', $c->events()[0]['sku']);
        $this->assertSame('places_text_pro',   $c->events()[1]['sku']);
        // total_cost_usd reflects per-1k tier rate / 1000
        $this->assertEqualsWithDelta(0.032, $c->events()[0]['total_cost_usd'], 1e-9);
    }

    public function testCampaignContextAttachesToEvents(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->setCampaignContext('camp-1', 'tile-7', null);
        $c->fakeResponse(200, '{"places":[]}');
        $c->searchNearby(['locationRestriction' => ['circle' => ['center' => ['latitude' => 0, 'longitude' => 0], 'radius' => 1000]]]);

        $evt = $c->events()[0];
        $this->assertSame('camp-1', $evt['campaign_id']);
        $this->assertSame('tile-7', $evt['tile_id']);
    }

    public function testFailedCallStillWritesEventWithErrorAndStatus(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->fakeResponse(429, '{"error":"rate limited"}');

        try {
            $c->searchNearby(['locationRestriction' => ['circle' => ['center' => ['latitude' => 0, 'longitude' => 0], 'radius' => 1000]]]);
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            // ok
        }
        $this->assertCount(1, $c->events());
        $this->assertSame(429,  $c->events()[0]['http_status']);
        $this->assertNotNull($c->events()[0]['error_message']);
    }

    public function testFieldMaskHashIsStableAndShared(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->fakeResponse(200, '{"id":"abc"}');
        $c->placeDetails('abc', 'enrich_full');
        $c->placeDetails('def', 'enrich_full');

        $hashes = array_unique(array_column($c->events(), 'field_mask_hash'));
        $this->assertCount(1, $hashes); // same mask preset → same hash
        $this->assertSame(16, strlen($hashes[0]));
    }

    // ─── Budget cap ──────────────────────────────────────────────────

    public function testBudgetCapHaltsBeforeHttpFires(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->setSpentForCampaign('camp-1', 99.99);
        $c->setCampaignContext('camp-1', null, 100.00); // cap = $100, spent = $99.99

        $c->fakeResponse(200, '{"id":"abc"}');
        try {
            // Place Details enrich_full ≈ $0.025 — would push spend over $100
            $c->placeDetails('abc', 'enrich_full');
            $this->fail('expected BudgetCapExceededException');
        } catch (BudgetCapExceededException $e) {
            $this->assertSame(0, $c->httpCallCount(), 'budget cap must halt BEFORE HTTP');
            $this->assertCount(0, $c->events(),       'budget cap must halt BEFORE ledger write');
        }
    }

    public function testBudgetCapAllowsCallSafelyUnderCap(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->setSpentForCampaign('camp-1', 10.00);
        $c->setCampaignContext('camp-1', null, 100.00);

        $c->fakeResponse(200, '{"places":[]}');
        $c->searchNearby(['locationRestriction' => ['circle' => ['center' => ['latitude' => 0, 'longitude' => 0], 'radius' => 1000]]]);
        $this->assertCount(1, $c->events());
    }

    public function testWithoutCampaignContextThereIsNoBudgetCheck(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        // no setCampaignContext → no cap to enforce
        $c->fakeResponse(200, '{"places":[]}');
        $c->searchNearby(['locationRestriction' => ['circle' => ['center' => ['latitude' => 0, 'longitude' => 0], 'radius' => 1000]]]);
        $this->assertCount(1, $c->events());
    }

    // ─── Grant flag fallback ─────────────────────────────────────────

    public function testGrantOffDegradesSweepMaskToIdOnly(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOff);
        $c->fakeResponse(200, '{"places":[]}');
        $c->searchNearby(['locationRestriction' => ['circle' => ['center' => ['latitude' => 0, 'longitude' => 0], 'radius' => 1000]]]);

        $this->assertSame('places.id', $c->lastFieldMask());
    }

    public function testGrantOffSweepTextKeepsPageToken(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOff);
        $c->fakeResponse(200, '{"places":[]}');
        $c->searchText(['textQuery' => 'x']);
        $mask = $c->lastFieldMask();
        $this->assertStringContainsString('places.id', $mask);
        $this->assertStringContainsString('nextPageToken', $mask);
        $this->assertStringNotContainsString('displayName', $mask);
    }

    public function testGrantOffRefusesPlaceDetails(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOff);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/places_storage_allowed=false/');
        $c->placeDetails('abc', 'enrich_full');
    }

    public function testGrantOffRefusesPlacePhoto(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOff);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/places_storage_allowed=false/');
        $c->placePhoto('places/X/photos/Y');
    }

    public function testIsStorageAllowedReflectsGrantConfig(): void
    {
        $on  = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $off = new TestablePlacesClient('K', $this->pricing, $this->grantOff);
        $this->assertTrue($on->isStorageAllowed());
        $this->assertFalse($off->isStorageAllowed());
    }

    // ─── Tiered pricing ──────────────────────────────────────────────

    public function testFirstTierRateAt50kVolume(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->setMonthlyVolume('search',  50_000);
        $c->setMonthlyVolume('details', 50_000);

        $this->assertEqualsWithDelta(0.032,  $c->projectCost(['places_nearby_pro']),     1e-9);
        $this->assertEqualsWithDelta(0.017,  $c->projectCost(['place_details_pro']),    1e-9);
    }

    public function testSecondTierRateAt200kVolume(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->setMonthlyVolume('search',  200_000);
        $c->setMonthlyVolume('details', 200_000);

        $this->assertEqualsWithDelta(0.0256, $c->projectCost(['places_nearby_pro']),  1e-9);
        $this->assertEqualsWithDelta(0.0136, $c->projectCost(['place_details_pro']),  1e-9);
    }

    public function testThirdTierRateAt600kVolume(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->setMonthlyVolume('search',  600_000);
        $c->setMonthlyVolume('details', 600_000);

        $this->assertEqualsWithDelta(0.0192, $c->projectCost(['places_nearby_pro']),  1e-9);
        $this->assertEqualsWithDelta(0.0102, $c->projectCost(['place_details_pro']),  1e-9);
    }

    public function testAddonRatesAreFlat(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->setMonthlyVolume('details', 800_000);
        $this->assertEqualsWithDelta(0.003, $c->projectCost(['place_details_contact']),    1e-9);
        $this->assertEqualsWithDelta(0.005, $c->projectCost(['place_details_atmosphere']), 1e-9);
        $this->assertEqualsWithDelta(0.007, $c->projectCost(['place_photo']),              1e-9);
    }

    public function testFullEnrichTotalIsSumOfThreeSkus(): void
    {
        $c = new TestablePlacesClient('K', $this->pricing, $this->grantOn);
        $c->setMonthlyVolume('details', 50_000);
        $cost = $c->projectCost(['place_details_pro', 'place_details_contact', 'place_details_atmosphere']);
        $this->assertEqualsWithDelta(0.017 + 0.003 + 0.005, $cost, 1e-9); // $0.025 / call
    }
}

/**
 * Test double — overrides the three protected seams to capture state
 * instead of touching DB / network. Owns nothing in the production
 * surface beyond what PlacesClient already exposes.
 */
class TestablePlacesClient extends PlacesClient
{
    private array $events    = [];
    private array $masks     = [];
    private array $spent     = [];
    private array $volumes   = [];
    private int   $httpCalls = 0;
    private int   $fakeStatus = 200;
    private string $fakeBody  = '{}';

    public function fakeResponse(int $status, string $body): void
    {
        $this->fakeStatus = $status;
        $this->fakeBody   = $body;
    }

    public function setSpentForCampaign(string $campaignId, float $spent): void
    {
        $this->spent[$campaignId] = $spent;
    }

    public function setMonthlyVolume(string $family, int $units): void
    {
        $this->volumes[$family] = $units;
    }

    public function events(): array       { return $this->events; }
    public function lastFieldMask(): string {
        return end($this->masks) ?: '';
    }
    public function httpCallCount(): int  { return $this->httpCalls; }

    protected function httpRequest(string $method, string $url, array $headers, ?string $body): array
    {
        $this->httpCalls++;
        // Capture the mask header for assertion.
        foreach ($headers as $h) {
            if (stripos($h, 'X-Goog-FieldMask:') === 0) {
                $this->masks[] = trim(substr($h, strlen('X-Goog-FieldMask:')));
            }
        }
        if ($this->fakeStatus >= 400) {
            return [$this->fakeStatus, $this->fakeBody, 1, "HTTP {$this->fakeStatus}"];
        }
        return [$this->fakeStatus, $this->fakeBody, 1, null];
    }

    protected function persistEvent(array $event): void
    {
        $this->events[] = $event;
    }

    protected function monthlyVolume(string $skuFamily): int
    {
        return $this->volumes[$skuFamily] ?? 0;
    }

    protected function spentForCampaign(string $campaignId): float
    {
        return $this->spent[$campaignId] ?? 0.0;
    }
}
