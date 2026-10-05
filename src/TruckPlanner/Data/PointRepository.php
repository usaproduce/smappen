<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * Reads of `tp_points`, the source points of a region's dataset (03_DATA.md 9.1, 9.3).
 *
 * Shared reference data: there is no organization column. Every read is a box around a point and names the
 * dataset version, so a request never mixes two versions and never reads a region without a box.
 *
 * Rows are positional, in the column order of the statement, which is the contract of 03_DATA.md 9.3
 * between this class, the capture service and the region loader. `ORDER BY point_id` (a binary collation)
 * is byte order: it fixes the order in which the model adds the rows up.
 *
 * SQL only narrows: a box holds every point within the radius and some beyond it. The caller keeps the
 * rows within the radius (the model does it for capture).
 */
class PointRepository
{
    /** Positions in a row of near() (query Q1). The sixteen bases follow from BASE, in segment order. */
    public const ID = 0;
    public const KIND = 1;
    public const REF = 2;
    public const LAT = 3;
    public const LNG = 4;
    public const RIVALS_DAY = 5;
    public const RIVALS_EVE = 6;
    public const BASE = 7;
    public const SEGMENTS = 16;

    /** Positions in a row of nearestBlock() (query Q4). */
    public const BLOCK_ID = 0;
    public const BLOCK_REF = 1;
    public const BLOCK_IN_REGION = 2;
    public const BLOCK_LAT = 3;
    public const BLOCK_LNG = 4;

    /** The radius of the sphere the box is measured on: seed `constants.earth_radius_m`. */
    public const EARTH_RADIUS_M = 6371008.8;

    /** The box is 1 % wider than the radius asks for. */
    public const GUARD_BAND = 1.01;

    private ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * The box that holds every point within `$radiusM` metres of (lat, lng), with the guard band
     * (03_DATA.md 9.3):
     *
     *     dLat = rad2deg(r / 6371008.8) * 1.01
     *     dLng = dLat / max(0.01, cos(deg2rad(lat)))
     *
     * @return array{lat_min: float, lat_max: float, lng_min: float, lng_max: float}
     */
    public static function box(float $lat, float $lng, float $radiusM): array
    {
        $dLat = rad2deg($radiusM / self::EARTH_RADIUS_M) * self::GUARD_BAND;
        $dLng = $dLat / max(0.01, cos(deg2rad($lat)));
        return [
            'lat_min' => $lat - $dLat,
            'lat_max' => $lat + $dLat,
            'lng_min' => $lng - $dLng,
            'lng_max' => $lng + $dLng,
        ];
    }

    /**
     * Query Q1: the source points in the box around a point, in point_id order.
     *
     * @return list<list<mixed>> rows [point_id, src_kind, src_ref, lat, lng, rivals_day, rivals_eve,
     *                           b_res, b_w_office, ... b_v_lodging]: three strings, then twenty floats
     */
    public function near(string $regionId, string $version, float $lat, float $lng, float $radiusM): array
    {
        $rows = $this->db()->fetchAll(
            'SELECT point_id, src_kind, src_ref, lat, lng, rivals_day, rivals_eve,
                    b_res, b_w_office, b_w_health, b_w_edu, b_w_retail, b_w_industrial, b_w_hospitality, b_w_public,
                    b_v_nightlife, b_v_shopping, b_v_leisure, b_v_campus, b_v_hospital, b_v_transit, b_v_events, b_v_lodging
               FROM tp_points FORCE INDEX (idx_tpp_geo)
              WHERE region_id = ? AND dataset_version = ?
                AND lat BETWEEN ? AND ?
                AND lng BETWEEN ? AND ?
              ORDER BY point_id',
            self::boxParams($regionId, $version, self::box($lat, $lng, $radiusM))
        );
        $out = [];
        $last = self::BASE + self::SEGMENTS;
        foreach ($rows as $row) {
            $values = array_values($row);
            $values[self::ID] = (string) $values[self::ID];
            $values[self::KIND] = (string) $values[self::KIND];
            $values[self::REF] = (string) $values[self::REF];
            for ($i = self::LAT; $i < $last; $i++) {
                $values[$i] = (float) $values[$i];
            }
            $out[] = $values;
        }
        return $out;
    }

    /**
     * Query Q4: the census block rows in the box of 2,400 m (twice the walking cutoff, the depth of the
     * halo) around a point, in point_id order. The caller keeps the rows within that distance and takes
     * the nearest: its `in_region` and the first digits of its `src_ref` say where the point lies.
     *
     * @return list<list<mixed>> rows [point_id, src_ref, in_region (1 or 0), lat, lng]
     */
    public function nearestBlock(string $regionId, string $version, float $lat, float $lng): array
    {
        $rows = $this->db()->fetchAll(
            'SELECT point_id, src_ref, in_region, lat, lng
               FROM tp_points FORCE INDEX (idx_tpp_geo)
              WHERE region_id = ? AND dataset_version = ?
                AND lat BETWEEN ? AND ?
                AND lng BETWEEN ? AND ?
                AND src_kind = \'block\'
              ORDER BY point_id',
            self::boxParams($regionId, $version, self::box($lat, $lng, (float) TpConfig::get('regions.locate_radius_m')))
        );
        $out = [];
        foreach ($rows as $row) {
            $values = array_values($row);
            $out[] = [
                (string) $values[self::BLOCK_ID],
                (string) $values[self::BLOCK_REF],
                (int) $values[self::BLOCK_IN_REGION],
                (float) $values[self::BLOCK_LAT],
                (float) $values[self::BLOCK_LNG],
            ];
        }
        return $out;
    }

    /**
     * The six values the box statements bind: region, version, then the box as texts that read back as the
     * same doubles (a float bound directly would lose digits).
     *
     * @param array{lat_min: float, lat_max: float, lng_min: float, lng_max: float} $box
     * @return list<string>
     */
    public static function boxParams(string $regionId, string $version, array $box): array
    {
        return [
            $regionId,
            $version,
            Sql::f((float) $box['lat_min']),
            Sql::f((float) $box['lat_max']),
            Sql::f((float) $box['lng_min']),
            Sql::f((float) $box['lng_max']),
        ];
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }
}
