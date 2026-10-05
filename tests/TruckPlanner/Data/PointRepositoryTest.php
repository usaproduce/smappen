<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\PointRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Support\TpConfig;
use PHPUnit\Framework\TestCase;

/**
 * Queries Q1 and Q4 of 03_DATA.md 9.3: the statements, what they bind, and the positional rows.
 */
final class PointRepositoryTest extends TestCase
{
    private const VERSION = 'dc-20261003-d0514a63';

    private const BASES = [
        'b_res', 'b_w_office', 'b_w_health', 'b_w_edu', 'b_w_retail', 'b_w_industrial', 'b_w_hospitality', 'b_w_public',
        'b_v_nightlife', 'b_v_shopping', 'b_v_leisure', 'b_v_campus', 'b_v_hospital', 'b_v_transit', 'b_v_events', 'b_v_lodging',
    ];

    protected function tearDown(): void
    {
        TpConfig::replace(null);
    }

    /**
     * A row of tp_points as PDO hands it over: an associative array in the column order of Q1.
     *
     * @param array<string, mixed> $bases
     * @return array<string, mixed>
     */
    private static function pointRow(string $id, float $lat, float $lng, array $bases = [], float $day = 0.0, float $eve = 0.0): array
    {
        $row = [
            'point_id' => $id, 'src_kind' => $id[0] === 'b' ? 'block' : 'place', 'src_ref' => substr($id, 1),
            'lat' => $lat, 'lng' => $lng, 'rivals_day' => $day, 'rivals_eve' => $eve,
        ];
        foreach (self::BASES as $column) {
            $row[$column] = $bases[$column] ?? 0.0;
        }
        return $row;
    }

    public function testTheBoxIsTheOneOfTheSpecification(): void
    {
        // 03_DATA.md 9.3: for r = 1200 at latitude 38.9, dLat = 0.0108998 and dLng = 0.0140056 degrees
        $box = PointRepository::box(38.9, -77.0, 1200.0);
        self::assertSame(['lat_min', 'lat_max', 'lng_min', 'lng_max'], array_keys($box));
        self::assertEqualsWithDelta(0.0108998, $box['lat_max'] - 38.9, 5e-8);
        self::assertEqualsWithDelta(0.0108998, 38.9 - $box['lat_min'], 5e-8);
        self::assertEqualsWithDelta(0.0140056, $box['lng_max'] + 77.0, 5e-8);
        self::assertEqualsWithDelta(0.0140056, -77.0 - $box['lng_min'], 5e-8);

        // the formula, operation for operation
        $dLat = rad2deg(2400.0 / 6371008.8) * 1.01;
        $dLng = $dLat / max(0.01, cos(deg2rad(39.72)));
        self::assertSame(
            ['lat_min' => 39.72 - $dLat, 'lat_max' => 39.72 + $dLat, 'lng_min' => -78.39 - $dLng, 'lng_max' => -78.39 + $dLng],
            PointRepository::box(39.72, -78.39, 2400.0)
        );

        // near a pole the width stops growing at 100 times the height
        $polar = PointRepository::box(89.9999, 0.0, 1200.0);
        self::assertEqualsWithDelta(100.0 * ($polar['lat_max'] - 89.9999), $polar['lng_max'], 1e-9);
    }

    public function testTheBoxHoldsEveryPointWithinTheRadius(): void
    {
        // Points exactly r metres away in sixteen directions, at the latitudes of the region and beyond.
        foreach ([0.0, 25.0, 38.9, 39.75, 60.0, 80.0] as $lat) {
            foreach ([1200.0, 2400.0] as $r) {
                $box = PointRepository::box($lat, -77.0, $r);
                for ($k = 0; $k < 16; $k++) {
                    $bearing = $k * M_PI / 8.0;
                    $dLat = rad2deg($r * cos($bearing) / 6371008.8);
                    $dLng = rad2deg($r * sin($bearing) / (6371008.8 * cos(deg2rad($lat + $dLat))));
                    $d = Estimator::haversineM($lat, -77.0, $lat + $dLat, -77.0 + $dLng);
                    self::assertEqualsWithDelta($r, $d, 0.02 * $r, 'the test point is about r away');
                    if ($d <= $r) {
                        self::assertGreaterThanOrEqual($box['lat_min'], $lat + $dLat);
                        self::assertLessThanOrEqual($box['lat_max'], $lat + $dLat);
                        self::assertGreaterThanOrEqual($box['lng_min'], -77.0 + $dLng);
                        self::assertLessThanOrEqual($box['lng_max'], -77.0 + $dLng);
                    }
                }
            }
        }
    }

    public function testTheBoxIsMeasuredOnTheModelsSphere(): void
    {
        self::assertSame((float) Estimator::seed(Seeds::defaults(), 'constants.earth_radius_m'), PointRepository::EARTH_RADIUS_M);
        self::assertSame(1.01, PointRepository::GUARD_BAND);
    }

    public function testNearIsQueryOne(): void
    {
        $db = (new RecordingDatabase())->queue([
            self::pointRow('b110010001011000', 38.9100683, -77.0528631, ['b_res' => 607, 'b_w_office' => '58.64713383536734'], 2.5, 3.25),
            self::pointRow('pw264230766', 38.91, -77.05, ['b_v_nightlife' => 40.0]),
        ]);
        $rows = (new PointRepository($db))->near('dc', self::VERSION, 38.91, -77.05, 1200.0);

        $call = $db->only('FROM tp_points');
        self::assertSame('fetchAll', $call['kind']);
        self::assertSame(
            'SELECT point_id, src_kind, src_ref, lat, lng, rivals_day, rivals_eve, '
            . 'b_res, b_w_office, b_w_health, b_w_edu, b_w_retail, b_w_industrial, b_w_hospitality, b_w_public, '
            . 'b_v_nightlife, b_v_shopping, b_v_leisure, b_v_campus, b_v_hospital, b_v_transit, b_v_events, b_v_lodging '
            . 'FROM tp_points FORCE INDEX (idx_tpp_geo) '
            . 'WHERE region_id = ? AND dataset_version = ? AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? '
            . 'ORDER BY point_id',
            $call['sql']
        );

        // region, version, then the box as texts that read back as the same doubles
        $box = PointRepository::box(38.91, -77.05, 1200.0);
        self::assertSame(
            ['dc', self::VERSION, json_encode($box['lat_min']), json_encode($box['lat_max']), json_encode($box['lng_min']), json_encode($box['lng_max'])],
            $call['params']
        );
        foreach (array_slice($call['params'], 2) as $i => $text) {
            self::assertIsString($text);
            self::assertSame(array_values($box)[$i], (float) $text, 'the bound value reads back as the same double');
        }

        // positional rows in the column order of the statement: three strings, then twenty floats
        self::assertCount(2, $rows);
        self::assertCount(23, $rows[0]);
        self::assertSame(
            ['b110010001011000', 'block', '110010001011000', 38.9100683, -77.0528631, 2.5, 3.25, 607.0, 58.64713383536734],
            array_slice($rows[0], 0, 9)
        );
        self::assertSame(array_fill(0, 14, 0.0), array_slice($rows[0], 9));
        self::assertSame('pw264230766', $rows[1][PointRepository::ID]);
        self::assertSame('place', $rows[1][PointRepository::KIND]);
        self::assertSame('w264230766', $rows[1][PointRepository::REF]);
        self::assertSame(38.91, $rows[1][PointRepository::LAT]);
        self::assertSame(-77.05, $rows[1][PointRepository::LNG]);
        self::assertSame(0.0, $rows[1][PointRepository::RIVALS_DAY]);
        self::assertSame(0.0, $rows[1][PointRepository::RIVALS_EVE]);
        self::assertSame(40.0, $rows[1][PointRepository::BASE + 8], 'the base of v_nightlife');
        self::assertSame(16, PointRepository::SEGMENTS);
        self::assertSame(23, PointRepository::BASE + PointRepository::SEGMENTS);
    }

    public function testNearestBlockIsQueryFourWithItsOwnRadius(): void
    {
        $db = (new RecordingDatabase())->queue([
            ['point_id' => 'b510594825021000', 'src_ref' => '510594825021000', 'in_region' => '1', 'lat' => '38.9601', 'lng' => -77.3602],
            ['point_id' => 'b510610001001000', 'src_ref' => '510610001001000', 'in_region' => 0, 'lat' => 38.95, 'lng' => -77.37],
        ]);
        $rows = (new PointRepository($db))->nearestBlock('dc', self::VERSION, 38.96, -77.36);

        $call = $db->only('FROM tp_points');
        self::assertSame(
            'SELECT point_id, src_ref, in_region, lat, lng FROM tp_points FORCE INDEX (idx_tpp_geo) '
            . 'WHERE region_id = ? AND dataset_version = ? AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? '
            . "AND src_kind = 'block' ORDER BY point_id",
            $call['sql']
        );
        // r = 2,400 m: twice the walking cutoff, the depth of the halo
        self::assertSame(2400.0, TpConfig::get('regions.locate_radius_m'));
        self::assertSame(2.0 * Estimator::seed(Seeds::defaults(), 'kernel.walk_cutoff_m'), TpConfig::get('regions.locate_radius_m'));
        $box = PointRepository::box(38.96, -77.36, 2400.0);
        self::assertSame(
            ['dc', self::VERSION, json_encode($box['lat_min']), json_encode($box['lat_max']), json_encode($box['lng_min']), json_encode($box['lng_max'])],
            $call['params']
        );

        self::assertSame(
            [
                ['b510594825021000', '510594825021000', 1, 38.9601, -77.3602],
                ['b510610001001000', '510610001001000', 0, 38.95, -77.37],
            ],
            $rows
        );
        self::assertSame('510594825021000', $rows[0][PointRepository::BLOCK_REF]);
        self::assertSame(1, $rows[0][PointRepository::BLOCK_IN_REGION]);
        self::assertSame(38.9601, $rows[0][PointRepository::BLOCK_LAT]);
        self::assertSame(-77.3602, $rows[0][PointRepository::BLOCK_LNG]);
        self::assertSame('b510594825021000', $rows[0][PointRepository::BLOCK_ID]);
    }

    public function testTheRadiusOfQueryFourComesFromTheSettings(): void
    {
        $config = TpConfig::all();
        $config['regions']['locate_radius_m'] = 1000.0;
        TpConfig::replace($config);
        $db = new RecordingDatabase();
        (new PointRepository($db))->nearestBlock('dc', self::VERSION, 38.96, -77.36);
        $box = PointRepository::box(38.96, -77.36, 1000.0);
        self::assertSame(json_encode($box['lat_min']), $db->only('FROM tp_points')['params'][2]);
    }

    public function testNothingFoundIsAnEmptyList(): void
    {
        $repository = new PointRepository(new RecordingDatabase());
        self::assertSame([], $repository->near('dc', self::VERSION, 0.0, 0.0, 1200.0));
        self::assertSame([], $repository->nearestBlock('dc', self::VERSION, 0.0, 0.0));
    }

    public function testNoStatementSelectsEveryColumnAndEveryStatementNamesTheVersion(): void
    {
        $db = new RecordingDatabase();
        $repository = new PointRepository($db);
        $repository->near('dc', self::VERSION, 38.9, -77.0, 1200.0);
        $repository->nearestBlock('dc', self::VERSION, 38.9, -77.0);
        self::assertCount(2, $db->statements());
        foreach ($db->calls as $call) {
            self::assertStringNotContainsString('*', $call['sql']);
            self::assertStringContainsString('region_id = ? AND dataset_version = ?', $call['sql']);
            self::assertSame(['dc', self::VERSION], array_slice($call['params'], 0, 2));
            self::assertCount(6, $call['params']);
        }
    }
}
