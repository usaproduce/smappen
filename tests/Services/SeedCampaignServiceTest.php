<?php
namespace App\Tests\Services;

use App\Services\SeedCampaignService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-math tests for SeedCampaignService — specifically the tile-grid
 * generator. Lifecycle methods (create/run/pause/resume/cancel,
 * materializeTiles, summary, index) all hit MySQL and need an
 * integration harness Smappen doesn't have.
 *
 * Carafe Vendor Network Spec v3 §4.1.
 */
class SeedCampaignServiceTest extends TestCase
{
    public function testTileGridCoversTheWholeBbox(): void
    {
        // A DC-ish bbox (~174 km²) at suburban tile-size (6 km) should
        // produce a grid that spans the full bbox bounds — first tile
        // anchors at (lat_min, lng_min); last tile clamps to (lat_max,
        // lng_max).
        $tiles = SeedCampaignService::computeTileGrid(38.80, -77.10, 38.92, -76.95, 'suburban');
        $this->assertNotEmpty($tiles);

        $firstLatMin = min(array_column($tiles, 0));
        $firstLngMin = min(array_column($tiles, 1));
        $lastLatMax  = max(array_column($tiles, 2));
        $lastLngMax  = max(array_column($tiles, 3));

        $this->assertEqualsWithDelta(38.80,  $firstLatMin, 1e-9);
        $this->assertEqualsWithDelta(-77.10, $firstLngMin, 1e-9);
        $this->assertEqualsWithDelta(38.92,  $lastLatMax,  1e-9);
        $this->assertEqualsWithDelta(-76.95, $lastLngMax,  1e-9);
    }

    public function testDenseProfileProducesMoreTilesThanRural(): void
    {
        $rural = SeedCampaignService::computeTileGrid(38.80, -77.10, 38.92, -76.95, 'rural');
        $dense = SeedCampaignService::computeTileGrid(38.80, -77.10, 38.92, -76.95, 'dense');
        $this->assertGreaterThan(count($rural), count($dense));
    }

    public function testSmallBboxStillProducesOneTile(): void
    {
        // Tile size > region — must clamp to a single tile, not zero.
        $tiles = SeedCampaignService::computeTileGrid(38.900, -77.001, 38.901, -77.000, 'rural');
        $this->assertCount(1, $tiles);
    }

    public function testTilesDoNotOverlap(): void
    {
        // Adjacent tiles share an edge but interiors don't overlap.
        // Sort tiles by (lat_min, lng_min); for each, the next tile in
        // the same row has lng_min >= this tile's lng_max.
        $tiles = SeedCampaignService::computeTileGrid(38.80, -77.10, 38.92, -76.95, 'suburban');
        $byRow = [];
        foreach ($tiles as $t) {
            $byRow[(string) $t[0]][] = $t;
        }
        foreach ($byRow as $row) {
            usort($row, fn($a, $b) => $a[1] <=> $b[1]);
            for ($i = 1; $i < count($row); $i++) {
                $this->assertGreaterThanOrEqual($row[$i - 1][3], $row[$i][1]);
            }
        }
    }

    public function testTileSizeMapMatchesEstimator(): void
    {
        // SeedEstimatorService and SeedCampaignService must agree on
        // tile sizes — otherwise the estimate's tile_count diverges
        // from what materializeTiles actually creates. Use reflection
        // to compare both constants directly.
        $ref = new \ReflectionClass(\App\Services\SeedEstimatorService::class);
        $estMap = $ref->getConstant('TILE_SIZE_KM');
        $this->assertSame(SeedCampaignService::TILE_SIZE_KM, $estMap);
    }
}
