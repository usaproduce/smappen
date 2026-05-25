<?php
declare(strict_types=1);

namespace App\MarketData;

use App\Core\Database;

/**
 * Vendor coverage — the geometry of where each location reaches.
 * The "drop a pin / who serves me" query uses MBRContains + ST_Contains
 * against this table's spatial index.
 *
 * Wall rules: MarketData namespace.
 */
class VendorCoverageRepository
{
    public function create(string $vendorId, string $locationId, array $data): string
    {
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO vendor_coverage
                (id, vendor_id, location_id, coverage_type, geom, travel_mode, travel_minutes,
                 radius_miles, confidence, source, created_at, updated_at)
             VALUES (?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $id, $vendorId, $locationId,
                $data['coverage_type'],
                $data['wkt'],
                $data['travel_mode'] ?? null,
                $data['travel_minutes'] ?? null,
                $data['radius_miles'] ?? null,
                (int) ($data['confidence'] ?? 50),
                $data['source'] ?? 'radius_fallback',
            ]
        );
        return $id;
    }

    public function listForVendor(string $vendorId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT id, vendor_id, location_id, coverage_type, ST_AsGeoJSON(geom, 4) AS geom_json,
                    travel_mode, travel_minutes, radius_miles, confidence, source
               FROM vendor_coverage WHERE vendor_id = ?',
            [$vendorId]
        );
    }

    public function deleteForVendor(string $vendorId): void
    {
        Database::getInstance()->query('DELETE FROM vendor_coverage WHERE vendor_id = ?', [$vendorId]);
    }

    /**
     * The core "who serves this pin" query. Returns vendor ids whose
     * coverage geometry contains the point. Uses MBRContains for index
     * acceleration + ST_Contains for the precise pass.
     */
    public function vendorsServingPoint(float $lat, float $lng): array
    {
        $pt = sprintf('POINT(%f %f)', $lat, $lng);
        $rows = Database::getInstance()->fetchAll(
            'SELECT DISTINCT vc.vendor_id, vc.coverage_type, vc.confidence,
                    v.name AS vendor_name, v.type, v.primary_category, v.is_affiliated,
                    v.aggregate_rating, v.rating_count
               FROM vendor_coverage vc
               JOIN vendors v ON v.id = vc.vendor_id
              WHERE MBRContains(vc.geom, ST_GeomFromText(?, 4326))
                AND ST_Contains(vc.geom, ST_GeomFromText(?, 4326))
              ORDER BY v.is_affiliated DESC, v.aggregate_rating DESC, vc.confidence DESC',
            [$pt, $pt]
        );
        // Cast PDO-stringified numerics so the frontend ranking + .toFixed work.
        foreach ($rows as &$r) {
            $r['confidence'] = (int) $r['confidence'];
            $r['is_affiliated'] = (int) $r['is_affiliated'];
            $r['aggregate_rating'] = $r['aggregate_rating'] === null ? null : (float) $r['aggregate_rating'];
            $r['rating_count'] = (int) $r['rating_count'];
        }
        return $rows;
    }
}
