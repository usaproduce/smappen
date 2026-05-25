<?php
declare(strict_types=1);

namespace App\MarketData;

use App\Core\Database;

/**
 * Vendor directory — the public/market side of the data wall.
 *
 * Wall rules: see src/MarketData/README.md and tests/DataWall/DataWallTest.php.
 * MarketData code is forbidden from importing PrivateData or naming any
 * private-reservoir table in SQL — the grep test in DataWallTest enforces
 * both, so this comment doesn't enumerate the list (enumerating it here
 * would itself trip the test).
 */
class VendorRepository
{
    public function create(array $data): string
    {
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO vendors
                (id, name, legal_name, hq_address, hq_lat, hq_lng, phone, website,
                 primary_category, source, is_affiliated, claim_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "unclaimed", NOW(), NOW())',
            [
                $id,
                $data['name'],
                $data['legal_name'] ?? null,
                $data['hq_address'] ?? null,
                isset($data['hq_lat']) ? (float) $data['hq_lat'] : null,
                isset($data['hq_lng']) ? (float) $data['hq_lng'] : null,
                $data['phone'] ?? null,
                $data['website'] ?? null,
                $data['primary_category'] ?? null,
                $data['source'] ?? 'manual',
                !empty($data['is_affiliated']) ? 1 : 0,
            ]
        );
        return $id;
    }

    public function findById(string $id): ?array
    {
        return Database::getInstance()->fetch('SELECT * FROM vendors WHERE id = ?', [$id]);
    }

    public function findByName(string $name): ?array
    {
        return Database::getInstance()->fetch('SELECT * FROM vendors WHERE name = ?', [$name]);
    }

    /**
     * Browse the directory. Filters by category + region. Returns up to 200.
     * Cross-tenant by design (directory is shared market reference).
     */
    public function search(array $filters): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['category'])) {
            $where[] = '(v.primary_category = ? OR EXISTS (SELECT 1 FROM vendor_listings vl WHERE vl.vendor_id = v.id AND vl.category = ?))';
            $params[] = (string) $filters['category'];
            $params[] = (string) $filters['category'];
        }
        if (!empty($filters['region'])) {
            $where[] = '(EXISTS (SELECT 1 FROM vendor_listings vl WHERE vl.vendor_id = v.id AND (vl.region = ? OR vl.region IS NULL)))';
            $params[] = (string) $filters['region'];
        }
        if (!empty($filters['q'])) {
            $where[] = 'v.name LIKE ?';
            $params[] = '%' . str_replace('%', '\%', (string) $filters['q']) . '%';
        }
        if (!empty($filters['claim_status'])) {
            $where[] = 'v.claim_status = ?';
            $params[] = (string) $filters['claim_status'];
        }
        $sql = 'SELECT v.id, v.name, v.legal_name, v.hq_address, v.hq_lat, v.hq_lng,
                       v.phone, v.website, v.primary_category, v.source, v.is_affiliated,
                       v.claim_status
                  FROM vendors v
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY v.is_affiliated DESC, v.name ASC
                 LIMIT 200';
        return Database::getInstance()->fetchAll($sql, $params);
    }

    public function listingsFor(string $vendorId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT category, region, service_radius_mi, min_order_cents, notes, source
               FROM vendor_listings WHERE vendor_id = ?
              ORDER BY category, region',
            [$vendorId]
        );
    }

    public function addListing(string $vendorId, array $data): string
    {
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO vendor_listings
                (id, vendor_id, category, region, service_radius_mi, min_order_cents, notes, source, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE service_radius_mi = VALUES(service_radius_mi),
                                     min_order_cents   = VALUES(min_order_cents),
                                     notes             = VALUES(notes)',
            [
                $id, $vendorId,
                $data['category'],
                $data['region'] ?? null,
                isset($data['service_radius_mi']) ? (int) $data['service_radius_mi'] : null,
                isset($data['min_order_cents']) ? (int) $data['min_order_cents'] : null,
                $data['notes'] ?? null,
                $data['source'] ?? 'operator_added',
            ]
        );
        return $id;
    }

    public function setClaimStatus(string $vendorId, string $status): void
    {
        Database::getInstance()->query(
            'UPDATE vendors SET claim_status = ?, updated_at = NOW() WHERE id = ?',
            [$status, $vendorId]
        );
    }

    /** Lookup by category serving a region — used by the comparison engine. */
    public function candidatesForCategory(string $category, ?string $region = null): array
    {
        $params = [$category, $category];
        $whereRegion = '';
        if ($region !== null) {
            $whereRegion = 'AND (vl.region = ? OR vl.region IS NULL)';
            $params[] = $region;
        }
        return Database::getInstance()->fetchAll(
            "SELECT DISTINCT v.id, v.name, v.is_affiliated, v.claim_status, v.primary_category,
                    v.hq_lat, v.hq_lng, v.aggregate_rating, v.rating_count
               FROM vendors v
               LEFT JOIN vendor_listings vl ON vl.vendor_id = v.id
              WHERE (v.primary_category = ? OR vl.category = ?)
                $whereRegion
              ORDER BY v.is_affiliated DESC, v.aggregate_rating DESC, v.name ASC
              LIMIT 50",
            $params
        );
    }
}
