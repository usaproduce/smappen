<?php
declare(strict_types=1);

namespace App\MarketData;

use App\Core\Database;

/**
 * comparison_requests — the opt-in signal. A row here means the
 * restaurant explicitly clicked "compare suppliers". This is the
 * ONLY path data from a restaurant may enter the funnel.
 */
class ComparisonRequestRepository
{
    public function create(string $organizationId, ?string $restaurantId, string $category, ?string $region, array $basket, array $vendorIds): string
    {
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO comparison_requests
                (id, organization_id, restaurant_id, category, region, basket_json, vendor_ids_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $id, $organizationId, $restaurantId, $category, $region,
                json_encode($basket), json_encode($vendorIds),
            ]
        );
        return $id;
    }

    public function findById(string $id, string $organizationId): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT * FROM comparison_requests WHERE id = ? AND organization_id = ?',
            [$id, $organizationId]
        );
    }
}
