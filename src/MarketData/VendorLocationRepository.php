<?php
declare(strict_types=1);

namespace App\MarketData;

use App\Core\Database;

/**
 * Vendor locations — one vendor, many branches. Each row carries a POINT
 * geometry (SRID 4326) for spatial queries.
 *
 * Wall rules: MarketData namespace, see src/MarketData/README.md.
 */
class VendorLocationRepository
{
    public function create(string $vendorId, array $data): string
    {
        $id = Database::uuid();
        $wkt = sprintf('POINT(%f %f)', (float) $data['lat'], (float) $data['lng']);
        Database::getInstance()->query(
            'INSERT INTO vendor_locations
                (id, vendor_id, label, address, lat, lng, pt, phone, is_primary, source, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?, ?, NOW(), NOW())',
            [
                $id, $vendorId,
                $data['label'] ?? null,
                $data['address'] ?? null,
                (float) $data['lat'], (float) $data['lng'],
                $wkt,
                $data['phone'] ?? null,
                !empty($data['is_primary']) ? 1 : 0,
                $data['source'] ?? 'manual',
            ]
        );
        return $id;
    }

    public function findById(string $id): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT id, vendor_id, label, address, lat, lng, phone, is_primary, source, created_at, updated_at
               FROM vendor_locations WHERE id = ?',
            [$id]
        );
    }

    public function listForVendor(string $vendorId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT id, label, address, lat, lng, phone, is_primary, source, created_at
               FROM vendor_locations WHERE vendor_id = ?
              ORDER BY is_primary DESC, label ASC',
            [$vendorId]
        );
    }

    public function primaryFor(string $vendorId): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT id, lat, lng, address, label FROM vendor_locations
              WHERE vendor_id = ? ORDER BY is_primary DESC, created_at ASC LIMIT 1',
            [$vendorId]
        );
    }

    /**
     * Batch variant of primaryFor — single query for N vendors. Returns
     * [vendor_id => location_row]. Use this in loops to avoid the N+1
     * pattern that the per-vendor primaryFor() falls into.
     */
    public function primaryForMany(array $vendorIds): array
    {
        if (empty($vendorIds)) return [];
        $ids = array_values(array_unique(array_map('strval', $vendorIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::getInstance()->fetchAll(
            "SELECT vl.id, vl.vendor_id, vl.lat, vl.lng, vl.address, vl.label,
                    vl.is_primary, vl.created_at
               FROM vendor_locations vl
              WHERE vl.vendor_id IN ($placeholders)
              ORDER BY vl.vendor_id, vl.is_primary DESC, vl.created_at ASC",
            $ids
        );
        $out = [];
        foreach ($rows as $r) {
            $vid = (string) $r['vendor_id'];
            if (isset($out[$vid])) continue; // first row per vendor wins (sorted)
            $out[$vid] = [
                'id'      => $r['id'],
                'lat'     => (float) $r['lat'],
                'lng'     => (float) $r['lng'],
                'address' => $r['address'],
                'label'   => $r['label'],
            ];
        }
        return $out;
    }

    /**
     * Spatial bounding-box query — used by the map cluster endpoint. Lat/lng
     * here are the min/max corners of the visible viewport.
     */
    public function inBbox(float $minLat, float $minLng, float $maxLat, float $maxLng, int $limit = 2000): array
    {
        // Build a polygon with our (lat lng) axis order convention.
        $bboxWkt = sprintf(
            'POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))',
            $minLat, $minLng,
            $minLat, $maxLng,
            $maxLat, $maxLng,
            $maxLat, $minLng,
            $minLat, $minLng
        );
        $rows = Database::getInstance()->fetchAll(
            'SELECT vl.id, vl.vendor_id, vl.lat, vl.lng, vl.label,
                    v.name AS vendor_name, v.type, v.primary_category, v.is_affiliated,
                    v.aggregate_rating, v.rating_count
               FROM vendor_locations vl
               JOIN vendors v ON v.id = vl.vendor_id
              WHERE MBRContains(ST_GeomFromText(?, 4326), vl.pt)
              ORDER BY v.is_affiliated DESC, v.aggregate_rating DESC
              LIMIT ?',
            [$bboxWkt, $limit]
        );
        // PDO with native prepares stringifies DECIMAL/BIGINT — cast back so
        // the frontend can call .toFixed() on aggregate_rating without crash.
        foreach ($rows as &$r) {
            $r['lat'] = (float) $r['lat'];
            $r['lng'] = (float) $r['lng'];
            $r['is_affiliated'] = (int) $r['is_affiliated'];
            $r['aggregate_rating'] = $r['aggregate_rating'] === null ? null : (float) $r['aggregate_rating'];
            $r['rating_count'] = (int) $r['rating_count'];
        }
        return $rows;
    }

    public function deleteForVendor(string $vendorId): void
    {
        Database::getInstance()->query('DELETE FROM vendor_locations WHERE vendor_id = ?', [$vendorId]);
    }
}
