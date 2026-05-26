<?php
namespace App\Tests\Services;

use App\Services\VendorImportPipeline;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for VendorImportPipeline. The importOne/importBatch
 * DB-touching paths need the integration harness; here we lock the
 * external-id routing logic + syntheticId determinism.
 *
 * Carafe Vendor Network Spec v3 §7 + §9 step 10.
 */
class VendorImportPipelineTest extends TestCase
{
    // ─── externalIdKeyFor ────────────────────────────────────────────

    public function testExternalIdKeyForKnownSources(): void
    {
        // Spec §12.6 idempotent upserts rely on these column names —
        // they must match the columns added in migration 035.
        $this->assertSame('osm_id',            VendorImportPipeline::externalIdKeyFor('osm'));
        $this->assertSame('foursquare_fsq_id', VendorImportPipeline::externalIdKeyFor('foursquare'));
    }

    public function testExternalIdKeyForSourcesWithoutColumnsIsNull(): void
    {
        // chain_seed, usda, manual sources don't have a dedicated
        // external-id column — the pipeline falls back to name-based
        // matching via the standard upsert path.
        $this->assertNull(VendorImportPipeline::externalIdKeyFor('chain_seed'));
        $this->assertNull(VendorImportPipeline::externalIdKeyFor('usda'));
        $this->assertNull(VendorImportPipeline::externalIdKeyFor('manual'));
        $this->assertNull(VendorImportPipeline::externalIdKeyFor('totally-unknown'));
    }

    // ─── syntheticId ────────────────────────────────────────────────

    public function testSyntheticIdIsDeterministic(): void
    {
        // Same name + coords must produce identical synthetic ids so
        // re-runs of the same chain scrape don't create duplicate
        // vendor rows.
        $p = ['displayName' => ['text' => 'Acme'], 'location' => ['latitude' => 38.9, 'longitude' => -77.0]];
        $this->assertSame(
            VendorImportPipeline::syntheticId($p),
            VendorImportPipeline::syntheticId($p)
        );
    }

    public function testSyntheticIdDistinguishesDifferentInputs(): void
    {
        $a = ['displayName' => ['text' => 'Acme'], 'location' => ['latitude' => 38.9, 'longitude' => -77.0]];
        $b = ['displayName' => ['text' => 'Acme'], 'location' => ['latitude' => 38.9, 'longitude' => -77.1]];
        $c = ['displayName' => ['text' => 'Other'], 'location' => ['latitude' => 38.9, 'longitude' => -77.0]];
        $this->assertNotSame(VendorImportPipeline::syntheticId($a), VendorImportPipeline::syntheticId($b));
        $this->assertNotSame(VendorImportPipeline::syntheticId($a), VendorImportPipeline::syntheticId($c));
    }

    public function testSyntheticIdHasPrefixAndFixedLength(): void
    {
        $p = ['displayName' => ['text' => 'Acme'], 'location' => ['latitude' => 38.9, 'longitude' => -77.0]];
        $id = VendorImportPipeline::syntheticId($p);
        $this->assertStringStartsWith('synth/', $id);
        // 'synth/' (6) + first 24 hex chars = 30 total
        $this->assertSame(30, strlen($id));
    }

    public function testSyntheticIdHandlesMissingFields(): void
    {
        // Defensive: chain scrapers may submit places with missing
        // location or name. Synthetic id must still produce something
        // stable (the same shape → same id), not crash.
        $id = VendorImportPipeline::syntheticId([]);
        $this->assertStringStartsWith('synth/', $id);
        $this->assertSame(30, strlen($id));
    }
}
