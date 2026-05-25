<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Vendor search — composes filters into a single SQL query against the
 * MarketData vendor tables. Spatial bbox filter uses the SPATIAL INDEX
 * on vendor_locations.pt; everything else is just standard LIKE / IN.
 *
 * The "natural query" parsing in spec §6.2 is deliberately NOT in this
 * service yet — that's an AI-fronting concern that adds API costs. The
 * frontend's filter chips do the equivalent for v1.
 */
class VendorSearchService
{
    public function search(array $filters): array
    {
        $params = [];
        $where = ['1=1'];

        if (!empty($filters['q'])) {
            $where[] = 'v.name LIKE ?';
            $params[] = '%' . str_replace('%', '\\%', (string) $filters['q']) . '%';
        }
        if (!empty($filters['type'])) {
            $where[] = 'v.type = ?';
            $params[] = (string) $filters['type'];
        }
        if (!empty($filters['category'])) {
            $where[] = '(v.primary_category = ? OR EXISTS (SELECT 1 FROM vendor_categories vc WHERE vc.vendor_id = v.id AND vc.category = ?))';
            $params[] = (string) $filters['category'];
            $params[] = (string) $filters['category'];
        }
        if (!empty($filters['min_rating'])) {
            $where[] = '(v.aggregate_rating IS NOT NULL AND v.aggregate_rating >= ?)';
            $params[] = (float) $filters['min_rating'];
        }
        if (!empty($filters['claim_status'])) {
            $where[] = 'v.claim_status = ?';
            $params[] = (string) $filters['claim_status'];
        }

        // Spatial bbox: filter vendor LOCATIONS that fall in the viewport.
        $joinLoc = '';
        if (!empty($filters['bbox'])) {
            $b = $filters['bbox']; // [minLat, minLng, maxLat, maxLng]
            if (is_array($b) && count($b) === 4) {
                $bboxWkt = sprintf(
                    'POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))',
                    (float)$b[0], (float)$b[1], (float)$b[0], (float)$b[3],
                    (float)$b[2], (float)$b[3], (float)$b[2], (float)$b[1],
                    (float)$b[0], (float)$b[1]
                );
                $joinLoc = 'JOIN vendor_locations vl_b ON vl_b.vendor_id = v.id AND MBRContains(ST_GeomFromText(?, 4326), vl_b.pt)';
                array_unshift($params, $bboxWkt);
            }
        }

        $limit = (int) ($filters['limit'] ?? 200);
        if ($limit < 1 || $limit > 500) $limit = 200;

        $sql = "SELECT DISTINCT v.id, v.name, v.brand, v.type, v.primary_category,
                       v.is_affiliated, v.claim_status, v.aggregate_rating, v.rating_count,
                       v.last_verified_at, v.hq_address
                  FROM vendors v
                  $joinLoc
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY v.is_affiliated DESC, v.aggregate_rating DESC, v.name
                 LIMIT $limit";

        return \App\MarketData\VendorRepository::normalizeRows(
            Database::getInstance()->fetchAll($sql, $params)
        );
    }
}
