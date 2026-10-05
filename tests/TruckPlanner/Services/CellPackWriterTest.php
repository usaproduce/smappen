<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Services\CellPackWriter;
use PHPUnit\Framework\TestCase;

/**
 * The cell pack, format version 1 (03_DATA.md section 11): the bytes, the header, the quantisation and the
 * PHP mirror of the browser's decoder.
 */
final class CellPackWriterTest extends TestCase
{
    private const IDS = ['892a8c9062fffff', '892aaab3043ffff', '892aaab3047ffff'];

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function header(array $changes = []): array
    {
        return $changes + [
            'region_id' => 'dc',
            'dataset_version' => 'dc-20261003-3fa9c2d1',
            'model_version' => 'tps-0.1.0',
            'pipeline_version' => 'tp-etl-1.0.0',
            'h3_res' => 9,
            'bounds' => ['lat_min' => 38.00484, 'lng_min' => -78.34937, 'lat_max' => 39.72058, 'lng_max' => -76.66133],
            'kernel' => ['earth_radius_m' => 6371008.8, 'walk_decay_m' => 400.0, 'walk_cutoff_m' => 1200.0, 'a0' => 1.6, 'visibility' => 1.0],
            'vintages' => ['census_reference_date' => '2020-04-01', 'lodes_year' => 2023, 'osm_snapshot_date' => '2026-10-03'],
            'attribution' => ['© OpenStreetMap contributors', 'U.S. Census Bureau, 2020 Census', 'U.S. Census Bureau, LEHD LODES 8.4 (2023)'],
        ];
    }

    /**
     * 50 columns for `$n` cells: column j of cell i is a different, reproducible number; column 7 is empty.
     *
     * @return list<list<float>>
     */
    private static function columns(int $n): array
    {
        $columns = [];
        for ($j = 0; $j < 50; $j++) {
            $column = [];
            for ($i = 0; $i < $n; $i++) {
                $column[] = $j === 7 ? 0.0 : (($i * 37 + $j * 11) % 101) * ($j + 1) * 0.37 + ($i === 0 && $j === 3 ? 2115.0 : 0.0);
            }
            $columns[] = $column;
        }
        return $columns;
    }

    // ------------------------------------------------------------------------------------ the layout

    public function testThePrefixTheHeaderThePaddingAndTheTwoSections(): void
    {
        $columns = self::columns(3);
        $bytes = CellPackWriter::build(self::header(), self::IDS, $columns);

        self::assertSame('TPCP', substr($bytes, 0, 4));
        self::assertSame("\x54\x50\x43\x50", substr($bytes, 0, 4));
        $prefix = unpack('vversion/vflags/Vh', $bytes, 4);
        self::assertSame(['version' => 1, 'flags' => 0, 'h' => $prefix['h']], $prefix);
        $h = $prefix['h'];
        $json = substr($bytes, 12, $h);
        self::assertSame('{', $json[0]);
        self::assertSame('}', $json[$h - 1]);

        $padding = (8 - (12 + $h) % 8) % 8;
        $d = 12 + $h + $padding;
        self::assertSame(0, $d % 8, 'the sections start at a multiple of 8');
        self::assertSame(str_repeat("\0", $padding), substr($bytes, 12 + $h, $padding));
        self::assertSame($d + 8 * 3 + 2 * 3 * 50, strlen($bytes), 'D + 8N + 100N');

        // section h3: the id read as a number, u64 little-endian, so the low word comes first
        self::assertSame(hex2bin('ffff4330abaa9208'), substr($bytes, $d + 8, 8), '892aaab3043ffff');
        self::assertSame(hex2bin('ffff2f06c9a89208'), substr($bytes, $d, 8), '892a8c9062fffff');

        // section features: column-major, so the three cells of column 0 come first
        $scale0 = max($columns[0]);
        $expected = '';
        foreach ($columns[0] as $v) {
            $expected .= pack('v', CellPackWriter::code($v, $scale0));
        }
        self::assertSame($expected, substr($bytes, $d + 24, 6));
        $scale49 = max($columns[49]);
        self::assertSame(pack('v', CellPackWriter::code($columns[49][2], $scale49)), substr($bytes, -2), 'the last code is cell 2 of column 49');
    }

    public function testTheHeaderHasTheKeysOfTheSpecificationInTheirOrder(): void
    {
        $columns = self::columns(3);
        $bytes = CellPackWriter::build(self::header(), self::IDS, $columns);
        $h = unpack('V', $bytes, 8)[1];
        $json = substr($bytes, 12, $h);
        $header = json_decode($json, true);

        self::assertSame(
            ['format', 'format_version', 'region_id', 'dataset_version', 'model_version', 'pipeline_version', 'h3_res',
                'cell_count', 'bounds', 'kernel', 'segments', 'columns', 'quant', 'scale', 'sections', 'vintages', 'attribution'],
            array_keys($header)
        );
        self::assertSame('tp-cell-pack', $header['format']);
        self::assertSame(1, $header['format_version']);
        self::assertSame('dc', $header['region_id']);
        self::assertSame('dc-20261003-3fa9c2d1', $header['dataset_version']);
        self::assertSame('tps-0.1.0', $header['model_version']);
        self::assertSame('tp-etl-1.0.0', $header['pipeline_version']);
        self::assertSame(9, $header['h3_res']);
        self::assertSame(3, $header['cell_count']);
        self::assertSame(['type' => 'u16-sqrt', 'levels' => 65535], $header['quant']);
        self::assertSame(
            [
                ['name' => 'h3', 'type' => 'u64le', 'offset' => 0, 'count' => 3],
                ['name' => 'features', 'type' => 'u16le', 'layout' => 'column-major', 'offset' => 24, 'count' => 150],
            ],
            $header['sections']
        );
        self::assertSame(
            ['res', 'w_office', 'w_health', 'w_edu', 'w_retail', 'w_industrial', 'w_hospitality', 'w_public',
                'v_nightlife', 'v_shopping', 'v_leisure', 'v_campus', 'v_hospital', 'v_transit', 'v_events', 'v_lodging'],
            $header['segments']
        );
        self::assertCount(50, $header['columns']);
        self::assertSame('c_day_res', $header['columns'][0]);
        self::assertSame('c_day_v_lodging', $header['columns'][15]);
        self::assertSame('c_eve_res', $header['columns'][16]);
        self::assertSame('n_res', $header['columns'][32]);
        self::assertSame('n_v_lodging', $header['columns'][47]);
        self::assertSame('r_day', $header['columns'][48]);
        self::assertSame('r_eve', $header['columns'][49]);
        self::assertSame(CellPackWriter::columnNames(), $header['columns']);

        // scale = the largest value of each column, 0 for an empty one
        self::assertCount(50, $header['scale']);
        foreach ($columns as $j => $column) {
            self::assertEqualsWithDelta(max($column), $header['scale'][$j], 0.0, 'scale of column ' . $j);
        }
        self::assertSame(0, $header['scale'][7]);

        // numbers as shortest round-trip decimals, text as UTF-8
        self::assertStringContainsString('"walk_decay_m":400,', $json);
        self::assertStringContainsString('"earth_radius_m":6371008.8,', $json);
        self::assertStringContainsString('"lat_min":38.00484,', $json);
        self::assertStringContainsString('"attribution":["© OpenStreetMap contributors","U.S. Census Bureau, 2020 Census","U.S. Census Bureau, LEHD LODES 8.4 (2023)"]', $json);
        self::assertStringNotContainsString('\\' . 'u00', $json, 'no escaped characters');
        self::assertStringNotContainsString('\\' . '/', $json, 'no escaped slashes');
    }

    public function testTheHeaderIsWrittenTheSameWhateverThePrecisionSettingsAre(): void
    {
        $first = CellPackWriter::build(self::header(), self::IDS, self::columns(3));
        $before = [ini_get('serialize_precision'), ini_get('precision')];
        ini_set('serialize_precision', '17');
        ini_set('precision', '5');
        try {
            self::assertSame($first, CellPackWriter::build(self::header(), self::IDS, self::columns(3)));
        } finally {
            ini_set('serialize_precision', (string) $before[0]);
            ini_set('precision', (string) $before[1]);
        }
    }

    public function testEveryPaddingLengthFromZeroToSeven(): void
    {
        $seen = [];
        for ($extra = 0; $extra < 8; $extra++) {
            $bytes = CellPackWriter::build(self::header(['region_id' => 'r' . str_repeat('x', $extra)]), self::IDS, self::columns(3));
            $h = unpack('V', $bytes, 8)[1];
            $padding = (8 - (12 + $h) % 8) % 8;
            $seen[$padding] = true;
            $decoded = CellPackWriter::decode($bytes);
            self::assertSame(self::IDS, $decoded['ids']);
            self::assertSame('r' . str_repeat('x', $extra), $decoded['header']['region_id']);
        }
        ksort($seen);
        self::assertSame([0, 1, 2, 3, 4, 5, 6, 7], array_keys($seen));
    }

    // ------------------------------------------------------------------------------------ quantisation

    public function testCodesFollowTheSquareRootRule(): void
    {
        self::assertSame(0, CellPackWriter::code(0.0, 100.0));
        self::assertSame(65535, CellPackWriter::code(100.0, 100.0));
        self::assertSame(0, CellPackWriter::code(5.0, 0.0), 'an empty column has scale 0 and codes 0');
        // 65535 * sqrt(0.25) = 32767.5, + 0.5 = 32768
        self::assertSame(32768, CellPackWriter::code(25.0, 100.0));
        // 65535 * sqrt(1e-4) = 655.35
        self::assertSame(655, CellPackWriter::code(0.01, 100.0));
        // below a quarter of a step the code is 0: 65535 * sqrt(v / s) < 0.5
        $threshold = 100.0 / (4.0 * 65535.0 * 65535.0);
        self::assertSame(0, CellPackWriter::code($threshold * 0.99, 100.0));
        self::assertSame(1, CellPackWriter::code($threshold * 1.01, 100.0));
    }

    public function testEveryValueDecodesWithinTheBoundAndZeroAndTheLargestValueAreExact(): void
    {
        $n = 400;
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = sprintf('892a%011x', 0x1000 + $i);
        }
        // Values over twelve orders of magnitude, so that every part of the scale is used.
        $columns = [];
        for ($j = 0; $j < 50; $j++) {
            $column = [];
            for ($i = 0; $i < $n; $i++) {
                $column[] = match (true) {
                    $j === 7 => 0.0,
                    $i === 0 => 0.0,
                    $i === 1 => 32731.0 + $j,
                    default => (32731.0 + $j) * exp(-0.07 * (($i * 7919 + $j * 31) % 400)),
                };
            }
            $columns[] = $column;
        }

        $decoded = CellPackWriter::decode(CellPackWriter::build(self::header(), $ids, $columns));
        self::assertSame($n, $decoded['n']);
        self::assertSame(50, $decoded['k']);
        self::assertSame($ids, $decoded['ids']);
        self::assertCount(50, $decoded['columns']);

        $worst = 0.0;
        foreach ($columns as $j => $column) {
            $scale = max($column);
            self::assertEqualsWithDelta($scale, $decoded['header']['scale'][$j], 0.0);
            self::assertCount($n, $decoded['columns'][$j]);
            foreach ($column as $i => $v) {
                $got = $decoded['columns'][$j][$i];
                $bound = CellPackWriter::errorBound($v, $scale);
                self::assertLessThanOrEqual($bound * (1.0 + 1e-9), abs($got - $v), "column $j cell $i");
                if ($bound > 0.0) {
                    $worst = max($worst, abs($got - $v) / $bound);
                }
                if ($v === 0.0) {
                    self::assertSame(0.0, $got, 'zero is exact');
                }
                if ($v === $scale) {
                    self::assertSame($scale, $got, 'the largest value of a column is exact');
                }
            }
        }
        self::assertGreaterThan(0.5, $worst, 'the bound is not slack: some value comes close to it');
        self::assertSame(array_fill(0, $n, 0.0), $decoded['columns'][7], 'an empty column');
    }

    public function testTheErrorBoundIsTheOneOfTheSpecification(): void
    {
        $scale = 32731.0;
        self::assertEqualsWithDelta(sqrt(5.0 * $scale) / 65535 + $scale / (4 * 65535 ** 2), CellPackWriter::errorBound(5.0, $scale), 1e-18);
        // relative error 1.5e-5 at v = scale, 1.5e-4 at 1 % of it, 1.5e-3 at 1e-4 of it, below 1 % from 2.35e-6 of it
        self::assertEqualsWithDelta(1.5e-5, CellPackWriter::errorBound($scale, $scale) / $scale, 1e-6);
        self::assertEqualsWithDelta(1.5e-4, CellPackWriter::errorBound(0.01 * $scale, $scale) / (0.01 * $scale), 1e-5);
        self::assertEqualsWithDelta(1.5e-3, CellPackWriter::errorBound(1e-4 * $scale, $scale) / (1e-4 * $scale), 1e-4);
        self::assertLessThan(0.01, CellPackWriter::errorBound(2.35e-6 * $scale, $scale) / (2.35e-6 * $scale));
        self::assertGreaterThan(0.01, CellPackWriter::errorBound(2.33e-6 * $scale, $scale) / (2.33e-6 * $scale));
        // the largest absolute error is 1.53e-5 of the scale
        self::assertEqualsWithDelta(1.53e-5, CellPackWriter::errorBound($scale, $scale) / $scale, 1e-7);
    }

    // ------------------------------------------------------------------------------------ the mirror of the browser

    public function testDecodeIsTheMirrorOfTheBrowsersDecoder(): void
    {
        $columns = self::columns(3);
        $bytes = CellPackWriter::build(self::header(), self::IDS, $columns);
        $decoded = CellPackWriter::decode($bytes);
        self::assertSame(['header', 'n', 'k', 'ids', 'columns'], array_keys($decoded));

        // 03_DATA.md 11.2, written out: prefix, header, D, the ids from two u32 words, features from u16 codes
        self::assertSame(0x54504350, unpack('N', $bytes)[1]);
        self::assertSame(1, unpack('v', $bytes, 4)[1]);
        $h = unpack('V', $bytes, 8)[1];
        $header = json_decode(substr($bytes, 12, $h), true);
        $d = 12 + $h + ((8 - ((12 + $h) % 8)) % 8);
        $n = $header['cell_count'];
        $k = count($header['columns']);
        self::assertSame(strlen($bytes), $d + 8 * $n + 2 * $n * $k);
        for ($i = 0; $i < $n; $i++) {
            $lo = unpack('V', $bytes, $d + 8 * $i)[1];
            $hi = unpack('V', $bytes, $d + 8 * $i + 4)[1];
            $id = dechex($hi) . str_pad(dechex($lo), 8, '0', STR_PAD_LEFT);
            self::assertSame(15, strlen($id));
            self::assertSame($decoded['ids'][$i], $id);
        }
        $f = $d + 8 * $n;
        for ($j = 0; $j < $k; $j++) {
            $s = $header['scale'][$j] / (65535 * 65535);
            for ($i = 0; $i < $n; $i++) {
                $q = unpack('v', $bytes, $f + 2 * ($j * $n + $i))[1];
                // features[i * K + j] of the browser
                self::assertEqualsWithDelta($s * $q * $q, $decoded['columns'][$j][$i], 1e-12 * max(1.0, $header['scale'][$j]));
            }
        }
        self::assertSame($header, $decoded['header']);
    }

    public function testOneColumnAtATime(): void
    {
        $bytes = CellPackWriter::build(self::header(), self::IDS, self::columns(3));
        $whole = CellPackWriter::decode($bytes);
        $layout = CellPackWriter::layout($bytes);
        self::assertSame(['header', 'n', 'k', 'ids_at', 'features_at'], array_keys($layout));
        self::assertSame(3, $layout['n']);
        self::assertSame(50, $layout['k']);
        self::assertSame($layout['ids_at'] + 24, $layout['features_at']);
        self::assertSame($whole['ids'], CellPackWriter::ids($bytes));
        self::assertSame($whole['ids'], CellPackWriter::ids($bytes, $layout));
        for ($j = 0; $j < 50; $j++) {
            self::assertSame($whole['columns'][$j], CellPackWriter::column($bytes, $j, $layout));
        }
        self::assertSame($whole['columns'][49], CellPackWriter::column($bytes, 49));
        $this->expectException(\OutOfRangeException::class);
        CellPackWriter::column($bytes, 50);
    }

    public function testAPackWithoutCells(): void
    {
        $bytes = CellPackWriter::build(self::header(), [], array_fill(0, 50, []));
        $decoded = CellPackWriter::decode($bytes);
        self::assertSame(0, $decoded['n']);
        self::assertSame([], $decoded['ids']);
        self::assertSame(array_fill(0, 50, []), $decoded['columns']);
        self::assertSame(array_fill(0, 50, 0), $decoded['header']['scale']);
        self::assertSame(0, strlen($bytes) % 8);
    }

    public function testManyCellsCrossTheChunksOfTheWriter(): void
    {
        $n = 20000;
        $ids = [];
        $column = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = sprintf('892a%011x', $i + 1);
            $column[] = (float) (($i * 7) % 1000);
        }
        $columns = array_fill(0, 50, $column);
        $bytes = CellPackWriter::build(self::header(), $ids, $columns);
        $layout = CellPackWriter::layout($bytes);
        self::assertSame($n, $layout['n']);
        self::assertSame($layout['features_at'] + 2 * $n * 50, strlen($bytes));
        $decoded = CellPackWriter::column($bytes, 49, $layout);
        self::assertCount($n, $decoded);
        foreach ([0, 1, 8191, 8192, 8193, 16384, 19999] as $i) {
            self::assertLessThanOrEqual(CellPackWriter::errorBound($column[$i], 999.0) * (1.0 + 1e-9), abs($decoded[$i] - $column[$i]), 'cell ' . $i);
        }
        self::assertSame($ids[19999], CellPackWriter::ids($bytes, $layout)[19999]);
    }

    // ------------------------------------------------------------------------------------ what is refused

    public function testBuildRefusesWhatIsNotAPack(): void
    {
        $columns = self::columns(3);
        $cases = [
            '49 columns' => [\LengthException::class, self::header(), self::IDS, array_slice($columns, 0, 49)],
            'a short column' => [\LengthException::class, self::header(), self::IDS, array_replace($columns, [5 => [1.0, 2.0]])],
            'ids not ascending' => [\DomainException::class, self::header(), [self::IDS[1], self::IDS[0], self::IDS[2]], $columns],
            'an id twice' => [\DomainException::class, self::header(), [self::IDS[0], self::IDS[0], self::IDS[2]], $columns],
            'an id in upper case' => [\DomainException::class, self::header(), ['892A8C9062FFFFF', self::IDS[1], self::IDS[2]], $columns],
            'a short id' => [\DomainException::class, self::header(), ['892a8c9062ffff', self::IDS[1], self::IDS[2]], $columns],
            'a negative value' => [\DomainException::class, self::header(), self::IDS, array_replace($columns, [5 => [1.0, -0.5, 2.0]])],
            'a value that is not finite' => [\DomainException::class, self::header(), self::IDS, array_replace($columns, [5 => [1.0, INF, 2.0]])],
            'text for a number' => [\DomainException::class, self::header(), self::IDS, array_replace($columns, [5 => [1.0, '2', 2.0]])],
            'a header without its kernel' => [\DomainException::class, array_diff_key(self::header(), ['kernel' => 1]), self::IDS, $columns],
        ];
        foreach ($cases as $name => [$exception, $header, $ids, $cols]) {
            try {
                CellPackWriter::build($header, $ids, $cols);
                self::fail($name . ' was accepted');
            } catch (\Throwable $e) {
                self::assertInstanceOf($exception, $e, $name);
            }
        }
    }

    public function testDecodeRefusesWhatIsNotAPackOfThisVersion(): void
    {
        $bytes = CellPackWriter::build(self::header(), self::IDS, self::columns(3));
        $h = unpack('V', $bytes, 8)[1];
        $cases = [
            'not a cell pack' => 'XPCP' . substr($bytes, 4),
            'not a cell pack ' => 'TPC',
            'unsupported pack version' => substr($bytes, 0, 4) . pack('v', 2) . substr($bytes, 6),
            'pack length mismatch' => substr($bytes, 0, -2),
            'pack length mismatch ' => $bytes . "\0\0",
            'pack length mismatch  ' => substr($bytes, 0, 8) . pack('V', strlen($bytes)) . substr($bytes, 12),
            'the pack header cannot be read' => substr($bytes, 0, 12) . str_repeat('x', $h) . substr($bytes, 12 + $h),
        ];
        foreach ($cases as $message => $broken) {
            try {
                CellPackWriter::decode($broken);
                self::fail(trim($message) . ' was accepted');
            } catch (\UnexpectedValueException $e) {
                self::assertSame(trim($message), $e->getMessage());
            }
        }
    }
}
