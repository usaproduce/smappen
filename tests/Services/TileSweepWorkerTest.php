<?php
namespace App\Tests\Services;

use App\Services\TileSweepWorker;
use PHPUnit\Framework\TestCase;

/**
 * Pure-helper tests for TileSweepWorker. The runOne() path hits MySQL
 * (claim via FOR UPDATE SKIP LOCKED, status writes, counter bumps) +
 * makes Places HTTP calls — needs an integration harness. Here we
 * cover the deterministic helpers used inside runOne():
 *
 *   - resultIdHash: the §12.3 fingerprint used for delta seeding
 *   - tileEdgeKm: subdivide-threshold geometry
 */
class TileSweepWorkerTest extends TestCase
{
    // ─── resultIdHash ────────────────────────────────────────────────

    public function testResultHashIsDeterministicAndOrderInsensitive(): void
    {
        // Spec §12.3: an unchanged place-id set on re-sweep must hash
        // identically regardless of the order Places returned them.
        $a = TileSweepWorker::resultIdHash(['places/A' => true, 'places/B' => true, 'places/C' => true]);
        $b = TileSweepWorker::resultIdHash(['places/C' => true, 'places/A' => true, 'places/B' => true]);
        $this->assertSame($a, $b);
    }

    public function testResultHashChangesWhenSetChanges(): void
    {
        $a = TileSweepWorker::resultIdHash(['places/A' => true, 'places/B' => true]);
        $b = TileSweepWorker::resultIdHash(['places/A' => true, 'places/C' => true]);
        $this->assertNotSame($a, $b);
    }

    public function testResultHashEmptySetIsStable(): void
    {
        $a = TileSweepWorker::resultIdHash([]);
        $b = TileSweepWorker::resultIdHash([]);
        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a)); // sha256 hex length
    }

    public function testResultHashIsSha256Width(): void
    {
        $h = TileSweepWorker::resultIdHash(['places/X' => true]);
        $this->assertSame(64, strlen($h));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h);
    }

    // ─── tileEdgeKm ──────────────────────────────────────────────────

    public function testTileEdgeKmForOneDegreeBoxAtEquator(): void
    {
        // 1° at the equator ≈ 111.32 km both directions. Min edge is
        // also ~111.32 km.
        $km = TileSweepWorker::tileEdgeKm([
            'lat_min' => 0.0, 'lng_min' => 0.0,
            'lat_max' => 1.0, 'lng_max' => 1.0,
        ]);
        $this->assertGreaterThan(110, $km);
        $this->assertLessThan(112,    $km);
    }

    public function testTileEdgeKmShrinksAtHighLatitude(): void
    {
        // 1° lng at 60°N is ~55.6 km wide (cos(60°) = 0.5). Min edge
        // is the lng dimension → ~55 km.
        $km = TileSweepWorker::tileEdgeKm([
            'lat_min' => 60.0, 'lng_min' => 0.0,
            'lat_max' => 61.0, 'lng_max' => 1.0,
        ]);
        $this->assertGreaterThan(54, $km);
        $this->assertLessThan(57,   $km);
    }

    public function testTileEdgeKmReturnsTheShorterEdge(): void
    {
        // Wide-but-short tile — width is the long edge, height is the
        // short edge. tileEdgeKm should report the short one (height).
        $km = TileSweepWorker::tileEdgeKm([
            'lat_min' => 0.0,  'lng_min' => 0.0,
            'lat_max' => 0.01, 'lng_max' => 1.00,
        ]);
        // 0.01° lat ≈ 1.1 km — that's the floor, not the 111 km width.
        $this->assertLessThan(2, $km);
    }
}
