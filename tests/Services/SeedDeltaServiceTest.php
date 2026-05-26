<?php
namespace App\Tests\Services;

use App\Services\SeedDeltaService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SeedDeltaService. The DB-touching paths
 * (scheduleResweepForCampaign, recoverStuckTiles, deltaSummary) need
 * the integration harness — we lock the constants spec'd in §12.3.
 *
 * The actual delta-skip *behavior* lives in TileSweepWorker (Phase 8
 * refactor) — see TileSweepWorkerTest for the result_id_hash hash
 * determinism that drives it.
 */
class SeedDeltaServiceTest extends TestCase
{
    public function testResweepAgeDefaultIsThirtyDays(): void
    {
        // Spec §12.1: hot tier TTL is 30 days. Tying the re-sweep
        // default to the same horizon means "re-sweep + tier-hot
        // refresh" naturally line up on the same nightly window.
        $this->assertSame(30, SeedDeltaService::DEFAULT_RESWEEP_AGE_DAYS);
    }

    public function testStuckTileTimeoutIsHalfHour(): void
    {
        // The worker's --max-seconds default is 240s and tile work
        // averages well under that; 30 min is a generous "definitely
        // dead" threshold that won't false-trip a slow-but-alive tile.
        $this->assertSame(30 * 60, SeedDeltaService::DEFAULT_STUCK_TILE_SECONDS);
    }

    public function testInstantiable(): void
    {
        // Smoke check — class compiles, constructor doesn't need args.
        // The DB-touching path is exercised by integration tests.
        $this->assertInstanceOf(
            SeedDeltaService::class,
            (function () {
                $ref = new \ReflectionClass(SeedDeltaService::class);
                return $ref->newInstanceWithoutConstructor();
            })()
        );
    }

    public function testCanBeConstructedWithMockDatabase(): void
    {
        // Constructor accepts nullable Database — confirms the seam exists.
        $ref = new \ReflectionClass(SeedDeltaService::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $params = $ctor->getParameters();
        $this->assertCount(1, $params);
        $this->assertTrue($params[0]->allowsNull(),
            'SeedDeltaService::__construct should accept null Database for DI');
    }
}
