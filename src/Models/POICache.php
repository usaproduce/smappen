<?php
namespace App\Models;

use App\Core\Database;

class POICache
{
    public static function get(string $queryHash): ?array
    {
        $row = Database::getInstance()->fetch(
            'SELECT * FROM poi_cache WHERE query_hash = ? AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY cached_at DESC LIMIT 1',
            [$queryHash]
        );
        if (!$row) return null;
        $row['results'] = json_decode($row['results'], true);
        return $row;
    }

    public static function store(string $queryHash, ?string $areaId, array $results, int $ttlHours = 48): string
    {
        return Database::getInstance()->insert('poi_cache', [
            'query_hash' => $queryHash,
            'area_id' => $areaId,
            'results' => json_encode($results),
            'cached_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + ($ttlHours * 3600)),
        ]);
    }
}
