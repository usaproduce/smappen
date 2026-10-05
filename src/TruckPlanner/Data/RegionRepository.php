<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;

/**
 * The read side of `tp_regions` and `tp_region_packs` (03_DATA.md 9.1 to 9.3).
 *
 * Shared reference data: there is no organization column. Rows are written by the region loader only.
 * JSON columns are decoded here and returned under the column name without `_json`. The pack blob is
 * read by packBlob() alone: no other statement touches `pack_gz`.
 */
class RegionRepository
{
    private const REGION_COLUMNS = 'region_id, name, cbsa, timezone, h3_res,
                    bbox_lat_min, bbox_lng_min, bbox_lat_max, bbox_lng_max, center_lat, center_lng,
                    active_version, previous_version, config_json, created_at, updated_at';

    private ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * Every region, in ascending region_id.
     *
     * @return list<array<string, mixed>> region rows as find() returns them
     */
    public function all(): array
    {
        $rows = $this->db()->fetchAll('SELECT ' . self::REGION_COLUMNS . ' FROM tp_regions ORDER BY region_id');
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::region($row);
        }
        return $out;
    }

    /**
     * One region: query Q0 of 03_DATA.md 9.3 (`active_version`, `timezone`, `h3_res`) with the rest of the
     * row beside it.
     *
     * @return array{region_id: string, name: string, cbsa: ?string, timezone: string, h3_res: int,
     *               bbox_lat_min: float, bbox_lng_min: float, bbox_lat_max: float, bbox_lng_max: float,
     *               center_lat: float, center_lng: float, active_version: ?string, previous_version: ?string,
     *               config: array<string, mixed>, created_at: string, updated_at: string}|null
     *         `config` is the region definition file (03_DATA.md section 1)
     */
    public function find(string $regionId): ?array
    {
        $row = $this->db()->fetch(
            'SELECT ' . self::REGION_COLUMNS . ' FROM tp_regions WHERE region_id = ?',
            [$regionId]
        );
        return $row === null ? null : self::region($row);
    }

    /**
     * The ledger row of one dataset version, without the pack and without the manifest.
     *
     * @return array{region_id: string, dataset_version: string, load_state: string, model_version: string,
     *               pipeline_version: string, osm_snapshot: ?string, point_count: int, place_count: int,
     *               cell_count: int, pack_format: int, pack_len: int, pack_gz_len: int, pack_sha256: ?string,
     *               kernel: ?array<string, mixed>, kernel_seeds_revision: ?int,
     *               vintages: ?array<string, mixed>, loaded_at: string, activated_at: ?string}|null
     *         `kernel` is the `kernel` object of `kernel_json` (what the kernel check compares) and
     *         `kernel_seeds_revision` the revision recorded beside it; both are null while the row is still
     *         loading. `vintages` is the `vintages` object of the manifest
     */
    public function packMeta(string $regionId, string $version): ?array
    {
        $row = $this->db()->fetch(
            'SELECT region_id, dataset_version, load_state, model_version, pipeline_version, osm_snapshot,
                    point_count, place_count, cell_count, pack_format, pack_len, pack_gz_len, pack_sha256,
                    kernel_json, JSON_EXTRACT(manifest_json, \'$.vintages\') AS vintages_json,
                    loaded_at, activated_at
               FROM tp_region_packs
              WHERE region_id = ? AND dataset_version = ?',
            [$regionId, $version]
        );
        if ($row === null) {
            return null;
        }
        $recorded = self::decode($row['kernel_json']);
        $kernel = is_array($recorded['kernel'] ?? null) ? $recorded['kernel'] : null;
        $revision = $recorded['seeds_revision'] ?? null;
        return [
            'region_id' => (string) $row['region_id'],
            'dataset_version' => (string) $row['dataset_version'],
            'load_state' => (string) $row['load_state'],
            'model_version' => (string) $row['model_version'],
            'pipeline_version' => (string) $row['pipeline_version'],
            'osm_snapshot' => $row['osm_snapshot'] === null ? null : (string) $row['osm_snapshot'],
            'point_count' => (int) $row['point_count'],
            'place_count' => (int) $row['place_count'],
            'cell_count' => (int) $row['cell_count'],
            'pack_format' => (int) $row['pack_format'],
            'pack_len' => (int) $row['pack_len'],
            'pack_gz_len' => (int) $row['pack_gz_len'],
            'pack_sha256' => $row['pack_sha256'] === null ? null : (string) $row['pack_sha256'],
            'kernel' => $kernel,
            'kernel_seeds_revision' => (is_int($revision) || is_float($revision)) ? (int) $revision : null,
            'vintages' => self::decode($row['vintages_json']),
            'loaded_at' => (string) $row['loaded_at'],
            'activated_at' => $row['activated_at'] === null ? null : (string) $row['activated_at'],
        ];
    }

    /** The gzip-compressed cell pack of one dataset version, as stored, or null. */
    public function packBlob(string $regionId, string $version): ?string
    {
        $row = $this->db()->fetch(
            'SELECT pack_gz FROM tp_region_packs WHERE region_id = ? AND dataset_version = ?',
            [$regionId, $version]
        );
        if ($row === null || $row['pack_gz'] === null) {
            return null;
        }
        return (string) $row['pack_gz'];
    }

    /**
     * The manifest of one dataset version (03_DATA.md 8.5), or null.
     *
     * @return array<string, mixed>|null
     */
    public function manifest(string $regionId, string $version): ?array
    {
        $row = $this->db()->fetch(
            'SELECT manifest_json FROM tp_region_packs WHERE region_id = ? AND dataset_version = ?',
            [$regionId, $version]
        );
        return $row === null ? null : self::decode($row['manifest_json']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function region(array $row): array
    {
        return [
            'region_id' => (string) $row['region_id'],
            'name' => (string) $row['name'],
            'cbsa' => $row['cbsa'] === null ? null : (string) $row['cbsa'],
            'timezone' => (string) $row['timezone'],
            'h3_res' => (int) $row['h3_res'],
            'bbox_lat_min' => (float) $row['bbox_lat_min'],
            'bbox_lng_min' => (float) $row['bbox_lng_min'],
            'bbox_lat_max' => (float) $row['bbox_lat_max'],
            'bbox_lng_max' => (float) $row['bbox_lng_max'],
            'center_lat' => (float) $row['center_lat'],
            'center_lng' => (float) $row['center_lng'],
            'active_version' => $row['active_version'] === null ? null : (string) $row['active_version'],
            'previous_version' => $row['previous_version'] === null ? null : (string) $row['previous_version'],
            'config' => self::decode($row['config_json']) ?? [],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }
}
