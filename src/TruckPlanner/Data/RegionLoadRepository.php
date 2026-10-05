<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;

/**
 * Everything the region loader writes (03_DATA.md 9.2 and section 10): the region row, the dataset ledger
 * with the cell pack, the source points, the places and their host vectors, and the switch that makes a
 * version the live one.
 *
 * Used by scripts/truck/load-region.php only. No request ever writes these tables.
 *
 * A load inserts rows of its own dataset version and touches no other. Which version is live is decided by
 * `tp_regions.active_version` alone, so a half-loaded version is never read.
 *
 * This is the one repository that uses the PDO handle itself: the pack and the host vectors are bound as
 * `PDO::PARAM_LOB` (binary must not travel through a text bind), and a batched delete needs the number of
 * rows it removed. Every other statement goes through query(), fetch() and fetchAll().
 *
 * Doubles are bound as text that reads back as the same double: a value that arrives as text (read from a
 * build file) is bound as it is, a float through Sql::f().
 */
class RegionLoadRepository
{
    /** Rows per INSERT statement. */
    public const ROWS_PER_STATEMENT = 500;

    public const STATE_LOADING = 'loading';
    public const STATE_READY = 'ready';
    public const STATE_FAILED = 'failed';

    /** The 16 base columns of tp_points, in segment order. */
    private const BASE_COLUMNS = [
        'b_res', 'b_w_office', 'b_w_health', 'b_w_edu', 'b_w_retail', 'b_w_industrial', 'b_w_hospitality', 'b_w_public',
        'b_v_nightlife', 'b_v_shopping', 'b_v_leisure', 'b_v_campus', 'b_v_hospital', 'b_v_transit', 'b_v_events', 'b_v_lodging',
    ];

    /** The tables that hold rows of a dataset version. */
    private const VERSION_TABLES = ['tp_points', 'tp_places', 'tp_region_packs'];

    private const HOST_VEC_BYTES = 400;

    private ?Database $db;
    private ?\PDOStatement $hostVecStatement = null;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * Creates the region row or brings it up to date with a build. `active_version` and `previous_version`
     * are not touched.
     *
     * @param array<string, mixed> $region the region definition (03_DATA.md section 1): `id`, `name`,
     *                                     `timezone`, `h3_res`, `map_center`, and `cbsa` when it has one
     * @param array<string, mixed> $bounds `lat_min`, `lng_min`, `lat_max`, `lng_max` of the county polygons
     * @param string|null $configJson the region definition as JSON text, stored as `config_json`; null
     *                                encodes `$region`
     */
    public function upsertRegion(array $region, array $bounds, ?string $configJson = null): void
    {
        $regionId = (string) $region['id'];
        $values = [
            (string) $region['name'],
            isset($region['cbsa']) ? (string) $region['cbsa'] : null,
            (string) $region['timezone'],
            (int) $region['h3_res'],
            self::double($bounds['lat_min']),
            self::double($bounds['lng_min']),
            self::double($bounds['lat_max']),
            self::double($bounds['lng_max']),
            self::double($region['map_center']['lat']),
            self::double($region['map_center']['lng']),
            $configJson ?? Sql::json($region, true),
        ];
        $exists = $this->db()->fetch('SELECT region_id FROM tp_regions WHERE region_id = ?', [$regionId]) !== null;
        if ($exists) {
            $values[] = $regionId;
            $this->db()->query(
                'UPDATE tp_regions
                    SET name = ?, cbsa = ?, timezone = ?, h3_res = ?,
                        bbox_lat_min = ?, bbox_lng_min = ?, bbox_lat_max = ?, bbox_lng_max = ?,
                        center_lat = ?, center_lng = ?, config_json = ?
                  WHERE region_id = ?',
                $values
            );
            return;
        }
        array_unshift($values, $regionId);
        $this->db()->query(
            'INSERT INTO tp_regions
                    (region_id, name, cbsa, timezone, h3_res,
                     bbox_lat_min, bbox_lng_min, bbox_lat_max, bbox_lng_max, center_lat, center_lng, config_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $values
        );
    }

    /** The `load_state` of a dataset version (`loading`, `ready` or `failed`), or null when it has no ledger row. */
    public function packRowState(string $regionId, string $version): ?string
    {
        $row = $this->db()->fetch(
            'SELECT load_state FROM tp_region_packs WHERE region_id = ? AND dataset_version = ?',
            [$regionId, $version]
        );
        return $row === null ? null : (string) $row['load_state'];
    }

    /**
     * Opens the ledger row of a load, in state `loading`.
     *
     * @param array<string, mixed> $meta `model_version`, `pipeline_version`, and `osm_snapshot`
     *                                   (YYYY-MM-DD or null)
     */
    public function insertPackRow(string $regionId, string $version, array $meta): void
    {
        $this->db()->query(
            'INSERT INTO tp_region_packs (region_id, dataset_version, load_state, model_version, pipeline_version, osm_snapshot)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $regionId,
                $version,
                self::STATE_LOADING,
                (string) $meta['model_version'],
                (string) $meta['pipeline_version'],
                isset($meta['osm_snapshot']) ? (string) $meta['osm_snapshot'] : null,
            ]
        );
    }

    /**
     * Deletes up to `$limit` rows of one dataset version from one table and says how many went. The caller
     * repeats the call until it answers 0: `tp_points`, then `tp_places`, then the ledger row in
     * `tp_region_packs`.
     */
    public function deleteVersionBatch(string $table, string $regionId, string $version, int $limit = 5000): int
    {
        if (!in_array($table, self::VERSION_TABLES, true)) {
            throw new \LogicException('not a table of dataset versions: ' . $table);
        }
        $statement = $this->db()->pdo()->prepare(
            'DELETE FROM ' . $table . ' WHERE region_id = ? AND dataset_version = ? LIMIT ' . max(1, $limit)
        );
        $statement->execute([$regionId, $version]);
        return $statement->rowCount();
    }

    /**
     * Inserts places of a dataset version: statements of 500 rows, all of one call in one transaction.
     * `host_vec` stays NULL until setHostVec().
     *
     * @param list<array<string, mixed>> $rows each with the keys of a line of places.ndjson (03_DATA.md
     *        8.2: `place_key`, `osm_type`, `osm_id`, `place_type`, `geom_kind`, `in_region`, `county_fips`,
     *        `name`, `brand`, `lat`, `lng`, `rival_kind`, `visitor_segment`, `size_default`, `host_fit`,
     *        `kitchen`, `phone`, `website`, `addr_line`, `city`, `state_code`, `postcode`, `cuisine`,
     *        `opening_hours_raw`, `hours_mask`, `tags`) and `snapshot_date` (YYYY-MM-DD)
     */
    public function insertPlaces(string $regionId, string $version, array $rows): void
    {
        $this->insertRows(
            'INSERT INTO tp_places
                    (region_id, dataset_version, place_key, osm_type, osm_id, snapshot_date, place_type, geom_kind,
                     in_region, county_fips, name, brand, lat, lng, rival_kind, visitor_segment, size_default, host_fit,
                     kitchen, phone, website, addr_line, city, state_code, postcode, cuisine, opening_hours_raw,
                     hours_mask, tags_json)
             VALUES ',
            29,
            $rows,
            static function (array $row) use ($regionId, $version): array {
                $tags = $row['tags'] ?? null;
                return [
                    $regionId,
                    $version,
                    (string) $row['place_key'],
                    (string) $row['osm_type'],
                    (string) $row['osm_id'],
                    (string) $row['snapshot_date'],
                    (string) $row['place_type'],
                    (string) $row['geom_kind'],
                    self::flag($row['in_region']),
                    self::text($row['county_fips'] ?? null),
                    self::text($row['name'] ?? null),
                    self::text($row['brand'] ?? null),
                    self::double($row['lat']),
                    self::double($row['lng']),
                    self::text($row['rival_kind'] ?? null),
                    self::text($row['visitor_segment'] ?? null),
                    self::double($row['size_default']),
                    self::double($row['host_fit']),
                    (string) $row['kitchen'],
                    self::text($row['phone'] ?? null),
                    self::text($row['website'] ?? null),
                    self::text($row['addr_line'] ?? null),
                    self::text($row['city'] ?? null),
                    self::text($row['state_code'] ?? null),
                    self::text($row['postcode'] ?? null),
                    self::text($row['cuisine'] ?? null),
                    self::text($row['opening_hours_raw'] ?? null),
                    self::text($row['hours_mask'] ?? null),
                    is_array($tags) ? Sql::json($tags, true) : null,
                ];
            }
        );
    }

    /**
     * Inserts source points of a dataset version: statements of 500 rows, all of one call in one
     * transaction.
     *
     * @param list<array<string, mixed>> $rows each with `point_id`, `src_kind`, `src_ref`, `in_region`,
     *        `job_adj`, `lat`, `lng`, `base` (the 16 bases in segment order), `rivals_day`, `rivals_eve`
     */
    public function insertPoints(string $regionId, string $version, array $rows): void
    {
        $this->insertRows(
            'INSERT INTO tp_points
                    (region_id, dataset_version, point_id, src_kind, src_ref, in_region, job_adj, lat, lng, '
            . implode(', ', self::BASE_COLUMNS) . ', rivals_day, rivals_eve)
             VALUES ',
            27,
            $rows,
            static function (array $row) use ($regionId, $version): array {
                $base = array_values((array) $row['base']);
                if (count($base) !== count(self::BASE_COLUMNS)) {
                    throw new \LengthException('a source point has 16 bases');
                }
                $values = [
                    $regionId,
                    $version,
                    (string) $row['point_id'],
                    (string) $row['src_kind'],
                    (string) $row['src_ref'],
                    self::flag($row['in_region']),
                    self::flag($row['job_adj']),
                    self::double($row['lat']),
                    self::double($row['lng']),
                ];
                foreach ($base as $b) {
                    $values[] = self::double($b);
                }
                $values[] = self::double($row['rivals_day']);
                $values[] = self::double($row['rivals_eve']);
                return $values;
            }
        );
    }

    /**
     * Stores the location vector of a possible host.
     *
     * @param string $bytes `pack('e50', ...$v)`: 50 little-endian doubles in cell-pack column order
     */
    public function setHostVec(string $regionId, string $version, string $placeKey, string $bytes): void
    {
        if (strlen($bytes) !== self::HOST_VEC_BYTES) {
            throw new \LengthException('a host vector is 400 bytes');
        }
        $this->hostVecStatement ??= $this->db()->pdo()->prepare(
            'UPDATE tp_places SET host_vec = ? WHERE region_id = ? AND dataset_version = ? AND place_key = ?'
        );
        $statement = $this->hostVecStatement;
        $statement->bindValue(1, $bytes, \PDO::PARAM_LOB);
        $statement->bindValue(2, $regionId, \PDO::PARAM_STR);
        $statement->bindValue(3, $version, \PDO::PARAM_STR);
        $statement->bindValue(4, $placeKey, \PDO::PARAM_STR);
        $statement->execute();
    }

    /**
     * Runs the writes made inside `$work` in one transaction (the loader groups 500 setHostVec() calls).
     * insertPlaces(), insertPoints() and activate() open their own and must not be called inside.
     */
    public function transaction(callable $work): void
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $work();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * Closes a load: the counts, the pack and what it was built with, and state `ready`.
     *
     * @param array<string, mixed> $counts `points`, `places`, `cells`, `pack_format`, `pack_len` (bytes of
     *                                     the pack before compression) and `pack_sha256` (of those bytes)
     * @param string $packGz the pack, gzip-compressed, as it will be served
     * @param array<string, mixed> $kernel what `kernel_json` holds: { seeds_revision, kernel }
     * @param string $manifestJson the text of the build's manifest.json, stored as it is
     */
    public function finishPack(string $regionId, string $version, array $counts, string $packGz, array $kernel, string $manifestJson): void
    {
        $statement = $this->db()->pdo()->prepare(
            'UPDATE tp_region_packs
                SET point_count = ?, place_count = ?, cell_count = ?, pack_format = ?, pack_len = ?, pack_gz_len = ?,
                    pack_sha256 = ?, pack_gz = ?, kernel_json = ?, manifest_json = ?, load_state = ?, loaded_at = NOW()
              WHERE region_id = ? AND dataset_version = ?'
        );
        $statement->bindValue(1, (int) $counts['points'], \PDO::PARAM_INT);
        $statement->bindValue(2, (int) $counts['places'], \PDO::PARAM_INT);
        $statement->bindValue(3, (int) $counts['cells'], \PDO::PARAM_INT);
        $statement->bindValue(4, (int) $counts['pack_format'], \PDO::PARAM_INT);
        $statement->bindValue(5, (int) $counts['pack_len'], \PDO::PARAM_INT);
        $statement->bindValue(6, strlen($packGz), \PDO::PARAM_INT);
        $statement->bindValue(7, (string) $counts['pack_sha256'], \PDO::PARAM_STR);
        $statement->bindValue(8, $packGz, \PDO::PARAM_LOB);
        $statement->bindValue(9, Sql::json($kernel, true), \PDO::PARAM_STR);
        $statement->bindValue(10, $manifestJson, \PDO::PARAM_STR);
        $statement->bindValue(11, self::STATE_READY, \PDO::PARAM_STR);
        $statement->bindValue(12, $regionId, \PDO::PARAM_STR);
        $statement->bindValue(13, $version, \PDO::PARAM_STR);
        $statement->execute();
    }

    /** Sets the `load_state` of a dataset version: `loading`, `ready` or `failed`. */
    public function setState(string $regionId, string $version, string $state): void
    {
        if (!in_array($state, [self::STATE_LOADING, self::STATE_READY, self::STATE_FAILED], true)) {
            throw new \LogicException('not a load state: ' . $state);
        }
        $this->db()->query(
            'UPDATE tp_region_packs SET load_state = ? WHERE region_id = ? AND dataset_version = ?',
            [$state, $regionId, $version]
        );
    }

    /**
     * Makes a `ready` version the live one, in one transaction. The version that was live becomes the
     * previous one, so the same call with that version is the way back. Activating the version that is
     * already live changes nothing.
     *
     * @throws \DomainException when the region or the version does not exist, or the version is not `ready`
     */
    public function activate(string $regionId, string $version): void
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $region = $db->fetch('SELECT active_version FROM tp_regions WHERE region_id = ?', [$regionId]);
            if ($region === null) {
                throw new \DomainException('unknown region: ' . $regionId);
            }
            $state = $this->packRowState($regionId, $version);
            if ($state === null) {
                throw new \DomainException('unknown dataset version: ' . $version);
            }
            if ($state !== self::STATE_READY) {
                throw new \DomainException('dataset version ' . $version . ' is ' . $state . ', not ready');
            }
            if ((string) ($region['active_version'] ?? '') !== $version) {
                $db->query(
                    'UPDATE tp_regions SET previous_version = active_version, active_version = ?, updated_at = NOW() WHERE region_id = ?',
                    [$version, $regionId]
                );
                $db->query(
                    'UPDATE tp_region_packs SET activated_at = NOW() WHERE region_id = ? AND dataset_version = ?',
                    [$regionId, $version]
                );
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * The ledger of a region: one row per loaded dataset version, oldest load first. No pack, no manifest.
     *
     * @return list<array{dataset_version: string, load_state: string, model_version: string,
     *                    pipeline_version: string, osm_snapshot: ?string, point_count: int, place_count: int,
     *                    cell_count: int, pack_len: int, pack_gz_len: int, loaded_at: string, activated_at: ?string}>
     */
    public function versions(string $regionId): array
    {
        $rows = $this->db()->fetchAll(
            'SELECT dataset_version, load_state, model_version, pipeline_version, osm_snapshot,
                    point_count, place_count, cell_count, pack_len, pack_gz_len, loaded_at, activated_at
               FROM tp_region_packs
              WHERE region_id = ?
              ORDER BY loaded_at, dataset_version',
            [$regionId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'dataset_version' => (string) $row['dataset_version'],
                'load_state' => (string) $row['load_state'],
                'model_version' => (string) $row['model_version'],
                'pipeline_version' => (string) $row['pipeline_version'],
                'osm_snapshot' => $row['osm_snapshot'] === null ? null : (string) $row['osm_snapshot'],
                'point_count' => (int) $row['point_count'],
                'place_count' => (int) $row['place_count'],
                'cell_count' => (int) $row['cell_count'],
                'pack_len' => (int) $row['pack_len'],
                'pack_gz_len' => (int) $row['pack_gz_len'],
                'loaded_at' => (string) $row['loaded_at'],
                'activated_at' => $row['activated_at'] === null ? null : (string) $row['activated_at'],
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>): list<mixed> $values the bound values of one row, in column order
     */
    private function insertRows(string $insert, int $columns, array $rows, callable $values): void
    {
        if ($rows === []) {
            return;
        }
        $tuple = '(' . Sql::marks($columns) . ')';
        $db = $this->db();
        $db->beginTransaction();
        try {
            foreach (array_chunk($rows, self::ROWS_PER_STATEMENT) as $chunk) {
                $params = [];
                foreach ($chunk as $row) {
                    $bound = $values($row);
                    if (count($bound) !== $columns) {
                        throw new \LengthException('a row binds ' . $columns . ' values');
                    }
                    foreach ($bound as $value) {
                        $params[] = $value;
                    }
                }
                $db->query($insert . implode(', ', array_fill(0, count($chunk), $tuple)), $params);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * A double as the text to bind. Text is a number read from a build file and is bound as it is; a float
     * (or an int) is written with every digit it needs.
     */
    private static function double(mixed $value): string
    {
        if (is_string($value)) {
            if (!is_numeric($value)) {
                throw new \DomainException('not a number: ' . substr($value, 0, 40));
            }
            return $value;
        }
        if (!is_int($value) && !is_float($value)) {
            throw new \DomainException('a number is required');
        }
        return Sql::f((float) $value);
    }

    private static function flag(mixed $value): int
    {
        return Sql::b($value === true || $value === 1 || $value === '1');
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
