<?php
namespace App\Tests\Services;

use App\Core\Database;
use App\Services\PlacesEnrichService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-helper tests for PlacesEnrichService. The DB-touching paths
 * (enrichVendor, enrichCampaign, refreshStaleTier) need the integration
 * harness because they wire together VendorCacheService.withCoalescedFetch
 * + PlacesClient.placeDetails + VendorUpsertService.upsertGoogleDetails.
 *
 * Locked here:
 *   - tier → mask-preset mapping (must match config['masks'] keys)
 *   - policy → SQL candidate filter (priority_types vs all)
 *   - PRIORITY_TYPES list matches spec §4.4
 *
 * Carafe Vendor Network Spec v3 §4.4 + §12.1.
 */
class PlacesEnrichServiceTest extends TestCase
{
    // ─── maskPresetForTier ───────────────────────────────────────────

    public function testTierMaskPresetCold(): void
    {
        $this->assertSame('tier_cold', PlacesEnrichService::maskPresetForTier('cold'));
    }

    public function testTierMaskPresetWarm(): void
    {
        $this->assertSame('tier_warm', PlacesEnrichService::maskPresetForTier('warm'));
    }

    public function testTierMaskPresetHot(): void
    {
        $this->assertSame('tier_hot', PlacesEnrichService::maskPresetForTier('hot'));
    }

    public function testTierMaskPresetFull(): void
    {
        $this->assertSame('enrich_full', PlacesEnrichService::maskPresetForTier('full'));
    }

    public function testTierMaskPresetUnknownThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PlacesEnrichService::maskPresetForTier('lukewarm');
    }

    public function testEveryTierPresetExistsInPriceBook(): void
    {
        // If a tier maps to a preset key that isn't in
        // config/google_places_pricing.php['masks'], PlacesClient throws
        // at runtime — this is the static-time check that locks it.
        $pricing = require dirname(__DIR__, 2) . '/config/google_places_pricing.php';
        foreach (['cold', 'warm', 'hot', 'full'] as $tier) {
            $preset = PlacesEnrichService::maskPresetForTier($tier);
            $this->assertArrayHasKey(
                $preset,
                $pricing['masks'],
                "config['masks'] is missing preset '$preset' that maskPresetForTier('$tier') returns"
            );
        }
    }

    // ─── PRIORITY_TYPES — spec §4.4 lockdown ─────────────────────────

    public function testPriorityTypesMatchesSpec(): void
    {
        // Spec §4.4 explicitly: "priority_types → enrich only broadline,
        // cash_carry, produce, seafood." Changing this is a policy
        // decision — touch the constant deliberately, with a re-cost run.
        $this->assertSame(
            ['broadline', 'cash_carry', 'produce', 'seafood'],
            PlacesEnrichService::PRIORITY_TYPES
        );
    }

    public function testPriorityTypesAreAllRealVendorTypesInConfig(): void
    {
        // Every priority type must exist in the vendor-type map, or the
        // sweep + classify pipeline will never produce vendors matching
        // them and priority_types will be a silent no-op.
        $types = require dirname(__DIR__, 2) . '/config/carafe_vendor_types.php';
        foreach (PlacesEnrichService::PRIORITY_TYPES as $vt) {
            $this->assertArrayHasKey(
                $vt,
                $types,
                "config/carafe_vendor_types.php is missing priority type '$vt'"
            );
            $this->assertTrue(
                (bool) ($types[$vt]['priority_enrich'] ?? false),
                "config/carafe_vendor_types.php[$vt].priority_enrich should be true to match PRIORITY_TYPES"
            );
        }
    }

    // ─── candidatesForCampaign — SQL filter ──────────────────────────

    public function testCandidatesAllPolicyEmitsNoTypeFilter(): void
    {
        $db = $this->makeRecordingDb();
        PlacesEnrichService::candidatesForCampaign(
            $db,
            ['bbox_lat_min' => 38, 'bbox_lat_max' => 39, 'bbox_lng_min' => -78, 'bbox_lng_max' => -77],
            'all',
            100
        );
        $sql = $db->lastSql();
        $this->assertStringContainsString('FROM vendors v', $sql);
        $this->assertStringNotContainsString('v.type IN', $sql);
        // The bbox bind values + the limit should be present, in that order.
        $this->assertSame([38.0, 39.0, -78.0, -77.0, 100], $db->lastParams());
    }

    public function testCandidatesPriorityTypesPolicyEmitsTypeFilter(): void
    {
        $db = $this->makeRecordingDb();
        PlacesEnrichService::candidatesForCampaign(
            $db,
            ['bbox_lat_min' => 38, 'bbox_lat_max' => 39, 'bbox_lng_min' => -78, 'bbox_lng_max' => -77],
            'priority_types',
            50
        );
        $sql = $db->lastSql();
        $this->assertStringContainsString('v.type IN', $sql);
        $params = $db->lastParams();
        // bbox(4) + 4 priority types + limit = 9 params
        $this->assertCount(9, $params);
        $this->assertSame(50, end($params));
        $expectedTypes = array_slice($params, 4, 4);
        $this->assertSame(PlacesEnrichService::PRIORITY_TYPES, $expectedTypes);
    }

    public function testCandidatesAlwaysFiltersUnEnriched(): void
    {
        $db = $this->makeRecordingDb();
        PlacesEnrichService::candidatesForCampaign(
            $db,
            ['bbox_lat_min' => 0, 'bbox_lat_max' => 1, 'bbox_lng_min' => 0, 'bbox_lng_max' => 1],
            'all',
            10
        );
        $sql = $db->lastSql();
        // The dedupe gating clause must always be present so a re-run
        // doesn't re-enrich a vendor that already has details.
        $this->assertStringContainsString('NOT EXISTS', $sql);
        $this->assertStringContainsString('vendor_google_details', $sql);
    }

    // ─────────────────────────────────────────────────────────────────
    // Recording DB — anonymous subclass of Database that captures the
    // last fetchAll() call without actually opening a connection.
    // ─────────────────────────────────────────────────────────────────
    private function makeRecordingDb(): Database
    {
        return new class extends Database {
            private string $sql = '';
            private array $params = [];
            // Skip the parent constructor — no PDO needed for this test.
            public function __construct() {}
            public function fetchAll(string $sql, array $params = []): array
            {
                $this->sql = $sql;
                $this->params = $params;
                return [];
            }
            public function lastSql(): string    { return $this->sql; }
            public function lastParams(): array  { return $this->params; }
        };
    }
}
