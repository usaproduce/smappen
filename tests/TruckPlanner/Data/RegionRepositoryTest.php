<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\RegionRepository;
use PHPUnit\Framework\TestCase;

final class RegionRepositoryTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function regionRow(string $id = 'dc'): array
    {
        return [
            'region_id' => $id, 'name' => 'Washington, DC region', 'cbsa' => '47900', 'timezone' => 'America/New_York',
            'h3_res' => '9', 'bbox_lat_min' => '37.9907', 'bbox_lng_min' => -78.3947, 'bbox_lat_max' => 39.7201,
            'bbox_lng_max' => -76.6625, 'center_lat' => 38.9072, 'center_lng' => -77.0369,
            'active_version' => 'dc-20261003-3fa9c2d1', 'previous_version' => null,
            'config_json' => '{"id": "dc", "traffic_matrix": "dc", "holidays": {"inauguration_day": true}, '
                . '"fuel_area_by_state": {"DC": "R1Y", "VA": "R1Z"}}',
            'created_at' => '2026-10-04 20:00:00', 'updated_at' => '2026-10-04 21:00:00',
        ];
    }

    public function testFindIsQueryZeroWithTheRestOfTheRow(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_regions WHERE region_id = ?', self::regionRow());
        $row = (new RegionRepository($db))->find('dc');

        $call = $db->only('FROM tp_regions');
        self::assertSame(['dc'], $call['params']);
        foreach (['active_version', 'timezone', 'h3_res', 'config_json', 'bbox_lat_min', 'center_lng'] as $column) {
            self::assertStringContainsString($column, $call['sql']);
        }
        self::assertStringNotContainsString('*', $call['sql']);

        self::assertIsArray($row);
        self::assertSame('dc', $row['region_id']);
        self::assertSame(9, $row['h3_res']);
        self::assertSame(37.9907, $row['bbox_lat_min']);
        self::assertSame(-77.0369, $row['center_lng']);
        self::assertSame('dc-20261003-3fa9c2d1', $row['active_version']);
        self::assertNull($row['previous_version']);
        self::assertSame('dc', $row['config']['traffic_matrix']);
        self::assertTrue($row['config']['holidays']['inauguration_day']);
        self::assertArrayNotHasKey('config_json', $row);
    }

    public function testFindUnknownRegion(): void
    {
        self::assertNull((new RegionRepository(new RecordingDatabase()))->find('zz'));
    }

    public function testAllIsOrderedById(): void
    {
        $db = (new RecordingDatabase())->queue([self::regionRow('atl'), self::regionRow('dc')]);
        $rows = (new RegionRepository($db))->all();
        self::assertSame(['atl', 'dc'], array_column($rows, 'region_id'));
        $call = $db->only('FROM tp_regions');
        self::assertSame('fetchAll', $call['kind']);
        self::assertStringEndsWith('ORDER BY region_id', $call['sql']);
        self::assertSame([], $call['params']);
    }

    public function testPackMetaNeverReadsTheBlobOrTheWholeManifest(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_region_packs', [
            'region_id' => 'dc', 'dataset_version' => 'dc-20261003-3fa9c2d1', 'load_state' => 'ready',
            'model_version' => 'tps-0.1.0', 'pipeline_version' => 'tp-etl-1.0.0', 'osm_snapshot' => '2026-10-03',
            'point_count' => '60678', 'place_count' => 25276, 'cell_count' => 61460, 'pack_format' => 1,
            'pack_len' => '6638000', 'pack_gz_len' => 3410000, 'pack_sha256' => str_repeat('9c', 32),
            'kernel_json' => '{"kernel": {"a0": 1.6, "walk_decay_m": 400}, "seeds_revision": 1}',
            'vintages_json' => '{"lodes_year": 2023, "census_reference_date": "2020-04-01", "osm_snapshot_date": "2026-10-03"}',
            'loaded_at' => '2026-10-04 20:00:00', 'activated_at' => null,
        ]);
        $meta = (new RegionRepository($db))->packMeta('dc', 'dc-20261003-3fa9c2d1');

        $call = $db->only('FROM tp_region_packs');
        self::assertSame(['dc', 'dc-20261003-3fa9c2d1'], $call['params']);
        self::assertStringContainsString('WHERE region_id = ? AND dataset_version = ?', $call['sql']);
        self::assertStringNotContainsString('pack_gz,', $call['sql']);
        self::assertStringNotContainsString('pack_gz ', $call['sql']);
        self::assertStringContainsString("JSON_EXTRACT(manifest_json, '$.vintages') AS vintages_json", $call['sql']);
        self::assertSame(1, substr_count($call['sql'], 'manifest_json'));
        self::assertStringNotContainsString('*', $call['sql']);

        self::assertIsArray($meta);
        self::assertSame('ready', $meta['load_state']);
        self::assertSame(60678, $meta['point_count']);
        self::assertSame(6638000, $meta['pack_len']);
        self::assertSame(1, $meta['pack_format']);
        // kernel_json is {seeds_revision, kernel}: the kernel object and the revision are handed out apart
        self::assertSame(['a0' => 1.6, 'walk_decay_m' => 400], $meta['kernel']);
        self::assertSame(1, $meta['kernel_seeds_revision']);
        self::assertSame(2023, $meta['vintages']['lodes_year']);
        self::assertNull($meta['activated_at']);
    }

    public function testPackMetaOfARowThatIsStillLoading(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_region_packs', [
            'region_id' => 'dc', 'dataset_version' => 'v2', 'load_state' => 'loading', 'model_version' => 'tps-0.1.0',
            'pipeline_version' => 'tp-etl-1.0.0', 'osm_snapshot' => null, 'point_count' => 0, 'place_count' => 0,
            'cell_count' => 0, 'pack_format' => 1, 'pack_len' => 0, 'pack_gz_len' => 0, 'pack_sha256' => null,
            'kernel_json' => null, 'vintages_json' => null, 'loaded_at' => '2026-10-04 20:00:00', 'activated_at' => null,
        ]);
        $meta = (new RegionRepository($db))->packMeta('dc', 'v2');
        self::assertIsArray($meta);
        self::assertNull($meta['kernel']);
        self::assertNull($meta['kernel_seeds_revision']);
        self::assertNull($meta['vintages']);
        self::assertNull($meta['pack_sha256']);
        self::assertNull((new RegionRepository(new RecordingDatabase()))->packMeta('dc', 'nope'));
    }

    public function testPackBlobIsItsOwnStatement(): void
    {
        $bytes = "\x1f\x8b\x08\x00binary\x00\xff";
        $db = (new RecordingDatabase())->when('SELECT pack_gz FROM tp_region_packs', ['pack_gz' => $bytes]);
        self::assertSame($bytes, (new RegionRepository($db))->packBlob('dc', 'v1'));
        $call = $db->only('SELECT pack_gz FROM tp_region_packs');
        self::assertSame('SELECT pack_gz FROM tp_region_packs WHERE region_id = ? AND dataset_version = ?', $call['sql']);
        self::assertSame(['dc', 'v1'], $call['params']);

        self::assertNull((new RegionRepository(new RecordingDatabase()))->packBlob('dc', 'v1'));
        $empty = (new RecordingDatabase())->when('SELECT pack_gz', ['pack_gz' => null]);
        self::assertNull((new RegionRepository($empty))->packBlob('dc', 'v1'));
    }

    public function testManifest(): void
    {
        $db = (new RecordingDatabase())->when(
            'SELECT manifest_json FROM tp_region_packs',
            ['manifest_json' => '{"parameters": {"walk_decay_m": 400}, "totals": {"residents": 6278542}}']
        );
        $manifest = (new RegionRepository($db))->manifest('dc', 'v1');
        self::assertSame(400, $manifest['parameters']['walk_decay_m']);
        self::assertSame(['dc', 'v1'], $db->only('manifest_json')['params']);
        self::assertNull((new RegionRepository(new RecordingDatabase()))->manifest('dc', 'v1'));
    }
}
