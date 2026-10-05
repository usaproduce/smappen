<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Support\JsonSafe;

/**
 * The cell pack of a region, format version 1 (03_DATA.md section 11): the 50 numbers of every kept map
 * cell in one binary file that the browser colours the map from.
 *
 *     offset 0   4 bytes   "TPCP"
 *            4   u16       format version, 1
 *            6   u16       flags, 0
 *            8   u32       H, the byte length of the JSON header
 *           12   H bytes   JSON header, UTF-8
 *       12 + H   P bytes   zeros, so that the sections start at a multiple of 8
 *            D   8N bytes  section h3: the N cell ids as u64, ascending
 *       D + 8N   2NK bytes section features: K = 50 columns of N u16 codes, column after column
 *
 * All integers are little-endian. A column is quantised on its own scale, the largest value of the column:
 *
 *     code   = min(65535, floor(65535 * sqrt(v / scale) + 0.5))        0 when scale is 0
 *     decode = scale * (code / 65535)^2
 *
 * so |decode - v| <= sqrt(v * scale) / 65535 + scale / (4 * 65535^2), zero is exact and the largest value
 * of a column is exact. The pack feeds the map colours only: every number shown to the owner comes from
 * exact vectors computed by the server.
 *
 * Cell centres are not stored (the browser derives the geometry from the id). Writing an id is formatting
 * hexadecimal text, not H3 arithmetic: PHP never needs H3.
 */
final class CellPackWriter
{
    public const MAGIC = 'TPCP';
    public const FORMAT = 'tp-cell-pack';
    public const FORMAT_VERSION = 1;
    public const LEVELS = 65535;

    /** Columns per cell: capture by day and by evening and nearby for 16 segments, then the two rival pulls. */
    public const COLUMNS = 50;

    private const PREFIX_BYTES = 12;
    private const CODES_PER_CHUNK = 8192;
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * The 16 segment keys in shared order.
     *
     * @return list<string>
     */
    public static function segments(): array
    {
        return array_values(Seeds::data()['vocabulary']['segments']);
    }

    /**
     * The 50 column names in pack order: `c_day_<segment>` (0 to 15), `c_eve_<segment>` (16 to 31),
     * `n_<segment>` (32 to 47), `r_day` (48), `r_eve` (49).
     *
     * @return list<string>
     */
    public static function columnNames(): array
    {
        $names = [];
        foreach (['c_day_', 'c_eve_', 'n_'] as $prefix) {
            foreach (self::segments() as $segment) {
                $names[] = $prefix . $segment;
            }
        }
        $names[] = 'r_day';
        $names[] = 'r_eve';
        return $names;
    }

    /**
     * The bytes of a pack.
     *
     * @param array<string, mixed> $header what the caller knows: `region_id`, `dataset_version`,
     *        `model_version`, `pipeline_version`, `h3_res`, `bounds` ({lat_min, lng_min, lat_max, lng_max}
     *        of the cell centres), `kernel` (the build-scope constants the vectors were computed with),
     *        `vintages` and `attribution`. The format, the cell count, the segment and column names, the
     *        quantisation, the scales and the section table are added here, and the keys are written in
     *        the order of the specification
     * @param list<string> $h3Ids the cell ids, 15 hexadecimal characters each, strictly ascending
     * @param list<list<float>> $columns 50 columns in pack order, each with one value per cell (not negative)
     */
    public static function build(array $header, array $h3Ids, array $columns): string
    {
        $h3Ids = array_values($h3Ids);
        $columns = array_values($columns);
        $n = count($h3Ids);
        if (count($columns) !== self::COLUMNS) {
            throw new \LengthException('a cell pack has ' . self::COLUMNS . ' columns');
        }
        foreach (['region_id', 'dataset_version', 'model_version', 'pipeline_version', 'h3_res', 'bounds', 'kernel', 'vintages', 'attribution'] as $key) {
            if (!array_key_exists($key, $header)) {
                throw new \DomainException('the pack header needs ' . $key);
            }
        }

        $ids = '';
        $previous = '';
        foreach ($h3Ids as $id) {
            if (!is_string($id) || preg_match('/^[0-9a-f]{15}$/', $id) !== 1) {
                throw new \DomainException('a cell id is 15 lower-case hexadecimal characters');
            }
            if (strcmp($id, $previous) <= 0) {
                throw new \DomainException('cell ids must be strictly ascending');
            }
            $previous = $id;
            // The id read as a number, low word first.
            $ids .= pack('VV', hexdec(substr($id, -8)), hexdec(substr($id, 0, -8)));
        }

        $scales = [];
        $features = '';
        foreach ($columns as $j => $column) {
            if (!is_array($column) || count($column) !== $n) {
                throw new \LengthException('column ' . $j . ' does not hold one value per cell');
            }
            $scale = 0.0;
            foreach ($column as $v) {
                if (!is_int($v) && !is_float($v)) {
                    throw new \DomainException('column ' . $j . ' holds something that is not a number');
                }
                if (!is_finite((float) $v) || $v < 0) {
                    throw new \DomainException('column ' . $j . ' holds a value that is negative or not finite');
                }
                if ($v > $scale) {
                    $scale = (float) $v;
                }
            }
            $scales[] = $scale;
            if ($scale <= 0.0) {
                $features .= str_repeat("\0\0", $n);
                continue;
            }
            $codes = [];
            foreach ($column as $v) {
                // code() written out: this loop runs once per cell and column
                $code = (int) floor(self::LEVELS * sqrt($v / $scale) + 0.5);
                $codes[] = $code > self::LEVELS ? self::LEVELS : $code;
            }
            foreach (array_chunk($codes, self::CODES_PER_CHUNK) as $chunk) {
                $features .= pack('v*', ...$chunk);
            }
        }

        JsonSafe::shortestFloats();
        $json = json_encode([
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'region_id' => (string) $header['region_id'],
            'dataset_version' => (string) $header['dataset_version'],
            'model_version' => (string) $header['model_version'],
            'pipeline_version' => (string) $header['pipeline_version'],
            'h3_res' => (int) $header['h3_res'],
            'cell_count' => $n,
            'bounds' => $header['bounds'],
            'kernel' => $header['kernel'],
            'segments' => self::segments(),
            'columns' => self::columnNames(),
            'quant' => ['type' => 'u16-sqrt', 'levels' => self::LEVELS],
            'scale' => $scales,
            'sections' => [
                ['name' => 'h3', 'type' => 'u64le', 'offset' => 0, 'count' => $n],
                ['name' => 'features', 'type' => 'u16le', 'layout' => 'column-major', 'offset' => 8 * $n, 'count' => $n * self::COLUMNS],
            ],
            'vintages' => $header['vintages'],
            'attribution' => array_values((array) $header['attribution']),
        ], self::JSON_FLAGS);

        $h = strlen($json);
        $padding = (8 - (self::PREFIX_BYTES + $h) % 8) % 8;
        return self::MAGIC . pack('vvV', self::FORMAT_VERSION, 0, $h) . $json . str_repeat("\0", $padding) . $ids . $features;
    }

    /**
     * Reads a pack back: the PHP mirror of the browser's decoder (03_DATA.md 11.2).
     *
     * @return array{header: array<string, mixed>, n: int, k: int, ids: list<string>, columns: list<list<float>>}
     *         `columns[j][i]` is the decoded value of column j for cell i (what the browser keeps row by
     *         row as `features[i * k + j]`)
     * @throws \UnexpectedValueException when the bytes are not a cell pack of this version
     */
    public static function decode(string $bytes): array
    {
        $layout = self::layout($bytes);
        $columns = [];
        for ($j = 0; $j < $layout['k']; $j++) {
            $columns[] = self::column($bytes, $j, $layout);
        }
        return [
            'header' => $layout['header'],
            'n' => $layout['n'],
            'k' => $layout['k'],
            'ids' => self::ids($bytes, $layout),
            'columns' => $columns,
        ];
    }

    /**
     * The header of a pack and where its sections start, after checking the prefix and the length. For a
     * reader that takes one column at a time with ids() and column() instead of decoding everything.
     *
     * @return array{header: array<string, mixed>, n: int, k: int, ids_at: int, features_at: int}
     * @throws \UnexpectedValueException when the bytes are not a cell pack of this version
     */
    public static function layout(string $bytes): array
    {
        $length = strlen($bytes);
        if ($length < self::PREFIX_BYTES || substr($bytes, 0, 4) !== self::MAGIC) {
            throw new \UnexpectedValueException('not a cell pack');
        }
        /** @var array{version: int, flags: int, h: int} $prefix */
        $prefix = unpack('vversion/vflags/Vh', $bytes, 4);
        if ($prefix['version'] !== self::FORMAT_VERSION) {
            throw new \UnexpectedValueException('unsupported pack version');
        }
        $h = $prefix['h'];
        if (self::PREFIX_BYTES + $h > $length) {
            throw new \UnexpectedValueException('pack length mismatch');
        }
        $header = json_decode(substr($bytes, self::PREFIX_BYTES, $h), true);
        if (!is_array($header) || !is_int($header['cell_count'] ?? null) || !is_array($header['columns'] ?? null)
            || !is_array($header['scale'] ?? null)) {
            throw new \UnexpectedValueException('the pack header cannot be read');
        }
        $d = self::PREFIX_BYTES + $h + (8 - (self::PREFIX_BYTES + $h) % 8) % 8;
        $n = $header['cell_count'];
        $k = count($header['columns']);
        if ($n < 0 || count($header['scale']) !== $k || $length !== $d + 8 * $n + 2 * $n * $k) {
            throw new \UnexpectedValueException('pack length mismatch');
        }
        return ['header' => $header, 'n' => $n, 'k' => $k, 'ids_at' => $d, 'features_at' => $d + 8 * $n];
    }

    /**
     * The cell ids of a pack, as 15-character hexadecimal text, in file order.
     *
     * @param array{header: array<string, mixed>, n: int, k: int, ids_at: int, features_at: int}|null $layout
     *        what layout() returned for these bytes, when the caller has it
     * @return list<string>
     */
    public static function ids(string $bytes, ?array $layout = null): array
    {
        $layout ??= self::layout($bytes);
        $n = $layout['n'];
        $ids = [];
        if ($n === 0) {
            return $ids;
        }
        $words = unpack('V*', substr($bytes, $layout['ids_at'], 8 * $n));
        if ($words === false) {
            throw new \UnexpectedValueException('the cell ids cannot be read');
        }
        for ($i = 0; $i < $n; $i++) {
            $lo = $words[2 * $i + 1];
            $hi = $words[2 * $i + 2];
            $ids[] = dechex($hi) . str_pad(dechex($lo), 8, '0', STR_PAD_LEFT);
        }
        return $ids;
    }

    /**
     * One decoded column of a pack: a value per cell, in file order.
     *
     * @param array{header: array<string, mixed>, n: int, k: int, ids_at: int, features_at: int}|null $layout
     * @return list<float>
     */
    public static function column(string $bytes, int $j, ?array $layout = null): array
    {
        $layout ??= self::layout($bytes);
        $n = $layout['n'];
        if ($j < 0 || $j >= $layout['k']) {
            throw new \OutOfRangeException('the pack has no column ' . $j);
        }
        $column = [];
        if ($n === 0) {
            return $column;
        }
        $scale = (float) $layout['header']['scale'][$j];
        $codes = unpack('v*', substr($bytes, $layout['features_at'] + 2 * $j * $n, 2 * $n));
        if ($codes === false) {
            throw new \UnexpectedValueException('the features cannot be read');
        }
        foreach ($codes as $code) {
            $r = $code / self::LEVELS;
            $column[] = $scale * ($r * $r);
        }
        return $column;
    }

    /** The 16-bit code of a value on a column's scale. */
    public static function code(float $v, float $scale): int
    {
        if ($scale <= 0.0) {
            return 0;
        }
        $code = (int) floor(self::LEVELS * sqrt($v / $scale) + 0.5);
        return $code > self::LEVELS ? self::LEVELS : $code;
    }

    /**
     * The largest distance between a value and what its code decodes to, on a column's scale:
     * sqrt(v * scale) / 65535 + scale / (4 * 65535^2).
     */
    public static function errorBound(float $v, float $scale): float
    {
        return sqrt($v * $scale) / self::LEVELS + $scale / (4.0 * self::LEVELS * self::LEVELS);
    }
}
