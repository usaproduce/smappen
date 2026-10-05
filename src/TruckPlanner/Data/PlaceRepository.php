<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * Reads of `tp_places`, the places of a region's dataset (03_DATA.md 9.1, 9.3, 6.2; 04_BACKEND.md 5.1).
 *
 * Shared reference data: there is no organization column. Every row is derived from OpenStreetMap and
 * nothing else is ever merged into the table. Every read names the dataset version.
 *
 * The box queries (Q2, Q3, Q6) return positional rows in the column order of the statement; the constants
 * below name the positions. `ORDER BY place_key` (a binary collation) is byte order. SQL only narrows: the
 * caller keeps the rows within its radius. Q5 and Q7 read places by key and return named fields.
 *
 * `host_vec` comes back as it is stored: 400 bytes (50 little-endian doubles, VectorCodec::fromBytes()
 * reads them), or null on a place that is not a possible host.
 */
class PlaceRepository
{
    /** Positions in a row of rivalsNear() (query Q2). */
    public const RIVAL_KEY = 0;
    public const RIVAL_TYPE = 1;
    public const RIVAL_KIND = 2;
    public const RIVAL_LAT = 3;
    public const RIVAL_LNG = 4;
    public const RIVAL_NAME = 5;
    public const RIVAL_KITCHEN = 6;
    public const RIVAL_HOURS_MASK = 7;

    /** Positions in a row of hostsNear() (query Q3). */
    public const HOST_KEY = 0;
    public const HOST_TYPE = 1;
    public const HOST_NAME = 2;
    public const HOST_BRAND = 3;
    public const HOST_LAT = 4;
    public const HOST_LNG = 5;
    public const HOST_COUNTY = 6;
    public const HOST_FIT = 7;
    public const HOST_KITCHEN = 8;
    public const HOST_SIZE = 9;
    public const HOST_SEGMENT = 10;
    public const HOST_PHONE = 11;
    public const HOST_WEBSITE = 12;
    public const HOST_ADDR = 13;
    public const HOST_CITY = 14;
    public const HOST_STATE = 15;
    public const HOST_POSTCODE = 16;
    public const HOST_HOURS_RAW = 17;
    public const HOST_HOURS_MASK = 18;
    public const HOST_VEC = 19;

    /** Positions in a row of hostVectorPage() (query Q6). */
    public const VEC_KEY = 0;
    public const VEC_TYPE = 1;
    public const VEC_LAT = 2;
    public const VEC_LNG = 3;
    public const VEC_COUNTY = 4;
    public const VEC_KITCHEN = 5;
    public const VEC_SEGMENT = 6;
    public const VEC_SIZE = 7;
    public const VEC_BYTES = 8;

    /** The display columns of a possible host: the column list of Q3 without `host_vec`. */
    private const DISPLAY_COLUMNS = 'place_key, place_type, name, brand, lat, lng, county_fips, host_fit, kitchen,
                    size_default, visitor_segment, phone, website, addr_line, city, state_code, postcode,
                    opening_hours_raw, hours_mask';

    private ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * Rows of one page of Q3 and Q6 (`scout.page_rows`). A page that holds fewer rows is the last one.
     */
    public static function pageRows(): int
    {
        return (int) TpConfig::get('scout.page_rows');
    }

    /**
     * Query Q2: the rival outlets in the box around a point, in place_key order. Halo outlets included.
     *
     * @return list<list<mixed>> rows [place_key, place_type, rival_kind, lat, lng, name, kitchen, hours_mask];
     *                           `name` and `hours_mask` may be null
     */
    public function rivalsNear(string $regionId, string $version, float $lat, float $lng, float $radiusM): array
    {
        $rows = $this->db()->fetchAll(
            'SELECT place_key, place_type, rival_kind, lat, lng, name, kitchen, hours_mask
               FROM tp_places FORCE INDEX (idx_tpl_geo)
              WHERE region_id = ? AND dataset_version = ?
                AND lat BETWEEN ? AND ?
                AND lng BETWEEN ? AND ?
                AND rival_kind IS NOT NULL
              ORDER BY place_key',
            PointRepository::boxParams($regionId, $version, PointRepository::box($lat, $lng, $radiusM))
        );
        $out = [];
        foreach ($rows as $row) {
            $v = array_values($row);
            $out[] = [
                (string) $v[self::RIVAL_KEY],
                (string) $v[self::RIVAL_TYPE],
                (string) $v[self::RIVAL_KIND],
                (float) $v[self::RIVAL_LAT],
                (float) $v[self::RIVAL_LNG],
                self::text($v[self::RIVAL_NAME]),
                (string) $v[self::RIVAL_KITCHEN],
                self::text($v[self::RIVAL_HOURS_MASK]),
            ];
        }
        return $out;
    }

    /**
     * Query Q3, its first page: the possible hosts (in the region, host_fit above 0) in the box around a
     * point, in place_key order, at most one page of rows.
     *
     * @return list<list<mixed>> rows [place_key, place_type, name, brand, lat, lng, county_fips, host_fit,
     *                           kitchen, size_default, visitor_segment, phone, website, addr_line, city,
     *                           state_code, postcode, opening_hours_raw, hours_mask, host_vec]
     */
    public function hostsNear(string $regionId, string $version, float $lat, float $lng, float $radiusM): array
    {
        $params = PointRepository::boxParams($regionId, $version, PointRepository::box($lat, $lng, $radiusM));
        $params[] = '';
        $rows = $this->db()->fetchAll(
            'SELECT place_key, place_type, name, brand, lat, lng, county_fips, host_fit, kitchen, size_default, visitor_segment,
                    phone, website, addr_line, city, state_code, postcode, opening_hours_raw, hours_mask, host_vec
               FROM tp_places
              WHERE region_id = ? AND dataset_version = ?
                AND lat BETWEEN ? AND ?
                AND lng BETWEEN ? AND ?
                AND in_region = 1 AND host_fit > 0
                AND place_key > ?
              ORDER BY place_key
              LIMIT ' . self::pageRows(),
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $v = array_values($row);
            $out[] = [
                (string) $v[self::HOST_KEY],
                (string) $v[self::HOST_TYPE],
                self::text($v[self::HOST_NAME]),
                self::text($v[self::HOST_BRAND]),
                (float) $v[self::HOST_LAT],
                (float) $v[self::HOST_LNG],
                self::text($v[self::HOST_COUNTY]),
                (float) $v[self::HOST_FIT],
                (string) $v[self::HOST_KITCHEN],
                (float) $v[self::HOST_SIZE],
                self::text($v[self::HOST_SEGMENT]),
                self::text($v[self::HOST_PHONE]),
                self::text($v[self::HOST_WEBSITE]),
                self::text($v[self::HOST_ADDR]),
                self::text($v[self::HOST_CITY]),
                self::text($v[self::HOST_STATE]),
                self::text($v[self::HOST_POSTCODE]),
                self::text($v[self::HOST_HOURS_RAW]),
                self::text($v[self::HOST_HOURS_MASK]),
                self::text($v[self::HOST_VEC]),
            ];
        }
        return $out;
    }

    /**
     * Query Q6: one page of possible hosts with their stored location vectors, in place_key order. The same
     * rows and paging as Q3, less the hosts whose vector is not written yet, and only the listed counties
     * when a list is given. The caller repeats the call with the last key of the page until a page holds
     * fewer than pageRows() rows.
     *
     * @param array{lat_min: float, lat_max: float, lng_min: float, lng_max: float} $box see PointRepository::box()
     * @param list<string> $countyFips five-digit county codes; empty = every county
     * @param string $afterKey the last place_key of the previous page, '' for the first page
     * @return list<list<mixed>> rows [place_key, place_type, lat, lng, county_fips, kitchen, visitor_segment,
     *                           size_default, host_vec]
     */
    public function hostVectorPage(string $regionId, string $version, array $box, array $countyFips, string $afterKey): array
    {
        $params = PointRepository::boxParams($regionId, $version, $box);
        $counties = '';
        if ($countyFips !== []) {
            $counties = ' AND county_fips IN (' . Sql::marks(count($countyFips)) . ')';
            foreach ($countyFips as $fips) {
                $params[] = (string) $fips;
            }
        }
        $params[] = $afterKey;
        $rows = $this->db()->fetchAll(
            'SELECT place_key, place_type, lat, lng, county_fips, kitchen, visitor_segment, size_default, host_vec
               FROM tp_places
              WHERE region_id = ? AND dataset_version = ?
                AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?
                AND in_region = 1 AND host_fit > 0 AND host_vec IS NOT NULL'
            . $counties . '
                AND place_key > ?
              ORDER BY place_key
              LIMIT ' . self::pageRows(),
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $v = array_values($row);
            $out[] = [
                (string) $v[self::VEC_KEY],
                (string) $v[self::VEC_TYPE],
                (float) $v[self::VEC_LAT],
                (float) $v[self::VEC_LNG],
                self::text($v[self::VEC_COUNTY]),
                (string) $v[self::VEC_KITCHEN],
                self::text($v[self::VEC_SEGMENT]),
                (float) $v[self::VEC_SIZE],
                (string) $v[self::VEC_BYTES],
            ];
        }
        return $out;
    }

    /**
     * Query Q7: the display columns of the places with these keys.
     *
     * @param list<string> $keys any number of place keys; they are read `scout.display_keys_per_query` at a time
     * @return array<string, array<string, mixed>> place_key => { place_key, place_type, name, brand, lat,
     *         lng, county_fips, host_fit, kitchen, size_default, visitor_segment, phone, website, addr_line,
     *         city, state_code, postcode, opening_hours_raw, hours_mask }, in place_key order. A key the
     *         dataset does not hold is absent
     */
    public function byKeys(string $regionId, string $version, array $keys): array
    {
        $wanted = [];
        foreach ($keys as $key) {
            $wanted[(string) $key] = true;
        }
        $out = [];
        $perQuery = max(1, (int) TpConfig::get('scout.display_keys_per_query'));
        foreach (array_chunk(array_map('strval', array_keys($wanted)), $perQuery) as $chunk) {
            $params = [$regionId, $version];
            foreach ($chunk as $key) {
                $params[] = $key;
            }
            $rows = $this->db()->fetchAll(
                'SELECT ' . self::DISPLAY_COLUMNS . '
                   FROM tp_places
                  WHERE region_id = ? AND dataset_version = ?
                    AND place_key IN (' . Sql::marks(count($chunk)) . ')
                  ORDER BY place_key',
                $params
            );
            foreach ($rows as $row) {
                $out[(string) $row['place_key']] = self::display($row);
            }
        }
        uksort($out, static fn ($a, $b): int => strcmp((string) $a, (string) $b));
        return $out;
    }

    /**
     * Query Q5: one place by its key, for the host link rule (03_DATA.md 6.2).
     *
     * @return array{place_key: string, place_type: string, visitor_segment: ?string, lat: float, lng: float}|null
     */
    public function findHost(string $regionId, string $version, string $placeKey): ?array
    {
        $row = $this->db()->fetch(
            'SELECT place_key, place_type, visitor_segment, lat, lng
               FROM tp_places
              WHERE region_id = ? AND dataset_version = ? AND place_key = ?',
            [$regionId, $version, $placeKey]
        );
        if ($row === null) {
            return null;
        }
        return [
            'place_key' => (string) $row['place_key'],
            'place_type' => (string) $row['place_type'],
            'visitor_segment' => self::text($row['visitor_segment']),
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lng'],
        ];
    }

    /**
     * Query Q8: one page of every place of a dataset version, in place_key order, without `host_vec`. For
     * the export script only: a web request never reads a region without a box.
     *
     * @param string $afterKey the last place_key of the previous page, '' for the first page
     * @return list<array<string, mixed>> rows with the keys of a line of places.ndjson (03_DATA.md 8.2)
     *                                   plus `snapshot_date`; `tags` is the decoded object or null
     */
    public function exportPage(string $regionId, string $version, string $afterKey, int $limit = 1000): array
    {
        $rows = $this->db()->fetchAll(
            'SELECT place_key, osm_type, osm_id, snapshot_date, place_type, geom_kind, in_region, county_fips, name, brand,
                    lat, lng, rival_kind, visitor_segment, size_default, host_fit, kitchen, phone, website, addr_line,
                    city, state_code, postcode, cuisine, opening_hours_raw, hours_mask, tags_json
               FROM tp_places
              WHERE region_id = ? AND dataset_version = ? AND place_key > ?
              ORDER BY place_key
              LIMIT ' . max(1, $limit),
            [$regionId, $version, $afterKey]
        );
        $out = [];
        foreach ($rows as $row) {
            $tags = is_string($row['tags_json']) && $row['tags_json'] !== '' ? json_decode($row['tags_json'], true) : null;
            $out[] = [
                'place_key' => (string) $row['place_key'],
                'osm_type' => (string) $row['osm_type'],
                'osm_id' => (string) $row['osm_id'],
                'snapshot_date' => (string) $row['snapshot_date'],
                'place_type' => (string) $row['place_type'],
                'geom_kind' => (string) $row['geom_kind'],
                'in_region' => (int) $row['in_region'],
                'county_fips' => self::text($row['county_fips']),
                'name' => self::text($row['name']),
                'brand' => self::text($row['brand']),
                'lat' => (float) $row['lat'],
                'lng' => (float) $row['lng'],
                'rival_kind' => self::text($row['rival_kind']),
                'visitor_segment' => self::text($row['visitor_segment']),
                'size_default' => (float) $row['size_default'],
                'host_fit' => (float) $row['host_fit'],
                'kitchen' => (string) $row['kitchen'],
                'phone' => self::text($row['phone']),
                'website' => self::text($row['website']),
                'addr_line' => self::text($row['addr_line']),
                'city' => self::text($row['city']),
                'state_code' => self::text($row['state_code']),
                'postcode' => self::text($row['postcode']),
                'cuisine' => self::text($row['cuisine']),
                'opening_hours_raw' => self::text($row['opening_hours_raw']),
                'hours_mask' => self::text($row['hours_mask']),
                'tags' => is_array($tags) ? $tags : null,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function display(array $row): array
    {
        return [
            'place_key' => (string) $row['place_key'],
            'place_type' => (string) $row['place_type'],
            'name' => self::text($row['name']),
            'brand' => self::text($row['brand']),
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lng'],
            'county_fips' => self::text($row['county_fips']),
            'host_fit' => (float) $row['host_fit'],
            'kitchen' => (string) $row['kitchen'],
            'size_default' => (float) $row['size_default'],
            'visitor_segment' => self::text($row['visitor_segment']),
            'phone' => self::text($row['phone']),
            'website' => self::text($row['website']),
            'addr_line' => self::text($row['addr_line']),
            'city' => self::text($row['city']),
            'state_code' => self::text($row['state_code']),
            'postcode' => self::text($row['postcode']),
            'opening_hours_raw' => self::text($row['opening_hours_raw']),
            'hours_mask' => self::text($row['hours_mask']),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }
}
