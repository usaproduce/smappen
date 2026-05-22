<?php
namespace App\Models;

use App\Core\Database;

class ImportedPoint
{
    public static function create(array $data): string
    {
        $lat = $data['lat'];
        $lng = $data['lng'];
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $id = $data['id'] ?? Database::uuid();
        $data['id'] = $id;
        if (isset($data['custom_data']) && is_array($data['custom_data'])) {
            $data['custom_data'] = json_encode($data['custom_data']);
        }

        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $wkt = sprintf('POINT(%.7f %.7f)', $lng, $lat);
        $sql = 'INSERT INTO imported_points (' . implode(',', array_map(fn($c) => "`$c`", $cols))
             . ', point) VALUES (' . implode(',', $placeholders) . ', ST_GeomFromText(:wkt, 4326))';
        $stmt = Database::getInstance()->pdo()->prepare($sql);
        foreach ($data as $k => $v) $stmt->bindValue(':' . $k, $v);
        $stmt->bindValue(':wkt', $wkt);
        $stmt->execute();
        return $id;
    }

    public static function getByBatch(string $batchId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT *, ST_X(point) AS lng_pt, ST_Y(point) AS lat_pt FROM imported_points WHERE import_batch_id = ?',
            [$batchId]
        );
    }

    public static function getByProject(string $projectId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT *, ST_X(point) AS lng_pt, ST_Y(point) AS lat_pt FROM imported_points WHERE project_id = ?',
            [$projectId]
        );
        foreach ($rows as &$r) {
            if (!empty($r['custom_data'])) $r['custom_data'] = json_decode($r['custom_data'], true);
        }
        return $rows;
    }

    public static function getInArea(string $projectId, string $areaId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT ip.* FROM imported_points ip
             JOIN areas a ON a.id = :aid
             WHERE ip.project_id = :pid AND ST_Contains(a.geometry, ip.point)',
            [':aid' => $areaId, ':pid' => $projectId]
        );
        foreach ($rows as &$r) {
            if (!empty($r['custom_data'])) $r['custom_data'] = json_decode($r['custom_data'], true);
        }
        return $rows;
    }

    public static function deleteBatch(string $batchId): int
    {
        return Database::getInstance()->delete('imported_points', 'import_batch_id = :b', [':b' => $batchId]);
    }

    public static function countByBatch(string $batchId): int
    {
        $r = Database::getInstance()->fetch('SELECT COUNT(*) AS c FROM imported_points WHERE import_batch_id = ?', [$batchId]);
        return (int)($r['c'] ?? 0);
    }

    public static function projectIdForBatch(string $batchId): ?string
    {
        $r = Database::getInstance()->fetch(
            'SELECT project_id FROM imported_points WHERE import_batch_id = ? LIMIT 1',
            [$batchId]
        );
        return $r['project_id'] ?? null;
    }
}
