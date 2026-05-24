<?php
namespace App\Models;

use App\Core\Database;
use App\Services\GeoUtils;

class Area
{
    public static function create(array $data): string
    {
        $geometry = $data['geometry'] ?? null;
        unset($data['geometry']);
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');
        $id = isset($data['id']) ? $data['id'] : Database::uuid();
        $data['id'] = $id;

        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO areas (' . implode(',', array_map(fn($c) => "`$c`", $cols))
             . ', geometry) VALUES (' . implode(',', $placeholders) . ', ST_GeomFromText(:wkt, 4326))';
        $stmt = Database::getInstance()->pdo()->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        // Pre-swap GeoJSON [lng,lat] → [lat,lng] before writing WKT so the
        // stored geometry uses the unified (X=lat, Y=lng) convention shared
        // with census_tracts/counties/states. Without this, ST_Intersects
        // between areas.geometry and census_tracts.geometry silently finds
        // nothing — that's why the right-panel demographics tiles came back
        // empty for newly-created radius/isochrone areas.
        $swapped = is_array($geometry) ? GeoUtils::swapGeometry($geometry) : null;
        $wkt = $swapped !== null ? GeoUtils::geoJsonToWkt($swapped) : $geometry;
        $stmt->bindValue(':wkt', $wkt);
        $stmt->execute();
        return $id;
    }

    public static function findById(string $id): ?array
    {
        $row = Database::getInstance()->fetch(
            'SELECT *, ST_AsGeoJSON(geometry) AS geometry_geojson FROM areas WHERE id = ?',
            [$id]
        );
        if (!$row) return null;
        if (!empty($row['geometry_geojson'])) {
            // Post-normalize: areas now store with X=lat,Y=lng matching
            // census_tracts. MySQL's ST_AsGeoJSON for SRID 4326 emits
            // [Y, X] which is [lng, lat] under this storage = STANDARD
            // GeoJSON already, no swap needed. The previous swap was only
            // necessary for the legacy (X=lng, Y=lat) storage.
            $row['geometry'] = json_decode($row['geometry_geojson'], true);
        }
        unset($row['geometry_geojson']);
        if (!empty($row['demographics_cache'])) {
            $row['demographics_cache'] = json_decode($row['demographics_cache'], true);
        }
        return $row;
    }

    public static function update(string $id, array $data): int
    {
        $geometry = $data['geometry'] ?? null;
        unset($data['geometry']);
        $data['updated_at'] = date('Y-m-d H:i:s');

        if ($geometry !== null) {
            $data['demographics_cache'] = null;
            $data['demographics_cached_at'] = null;
        }

        // Drop any caller-supplied 'id' to keep it out of the SET clause and
        // avoid a placeholder collision with the WHERE binding.
        unset($data['id']);
        $sets = [];
        $params = [':where_id' => $id];
        foreach ($data as $k => $v) {
            $sets[] = "`$k` = :set_$k";
            $params[':set_' . $k] = $v;
        }
        if ($geometry !== null) {
            // Same pre-swap as create() — keep storage convention unified
            // with census_tracts so ST_Intersects works in both directions.
            $swapped = is_array($geometry) ? GeoUtils::swapGeometry($geometry) : null;
            $sets[] = 'geometry = ST_GeomFromText(:wkt, 4326)';
            $params[':wkt'] = $swapped !== null ? GeoUtils::geoJsonToWkt($swapped) : $geometry;
        }
        $sql = 'UPDATE areas SET ' . implode(',', $sets) . ' WHERE id = :where_id';
        $stmt = Database::getInstance()->pdo()->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public static function delete(string $id): int
    {
        return Database::getInstance()->delete('areas', 'id = :id', [':id' => $id]);
    }

    public static function getByProject(string $projectId, ?string $folderId = null): array
    {
        $where = 'project_id = :pid';
        $params = [':pid' => $projectId];
        if ($folderId !== null) {
            if ($folderId === 'none' || $folderId === 'null') {
                $where .= ' AND folder_id IS NULL';
            } else {
                $where .= ' AND folder_id = :fid';
                $params[':fid'] = $folderId;
            }
        }
        // ORDER BY sort_order first so user-driven drag-reorder (BF7)
        // survives across reloads; tie-broken by created_at DESC. Covered
        // by idx_areas_proj_sort.
        $sql = "SELECT *, ST_AsGeoJSON(geometry) AS geometry_geojson FROM areas WHERE $where ORDER BY sort_order ASC, created_at DESC";
        $rows = Database::getInstance()->fetchAll($sql, $params);
        foreach ($rows as &$r) {
            if (!empty($r['geometry_geojson'])) {
                // ST_AsGeoJSON under the unified (X=lat, Y=lng) storage emits
                // standard [lng, lat] GeoJSON — no swap needed (see findById).
                $r['geometry'] = json_decode($r['geometry_geojson'], true);
            }
            if (!empty($r['demographics_cache'])) $r['demographics_cache'] = json_decode($r['demographics_cache'], true);
            unset($r['geometry_geojson']);
        }
        return $rows;
    }

    public static function findOverlapping(string $projectId, string $wkt): array
    {
        $sql = 'SELECT id, name, ST_AsGeoJSON(geometry) AS geometry_geojson
                FROM areas
                WHERE project_id = :pid AND ST_Intersects(geometry, ST_GeomFromText(:wkt, 4326))';
        return Database::getInstance()->fetchAll($sql, [':pid' => $projectId, ':wkt' => $wkt]);
    }
}
