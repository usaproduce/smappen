<?php
declare(strict_types=1);

namespace App\MarketData;

use App\Core\Database;

class VendorCategoryRepository
{
    public function attach(string $vendorId, string $category, string $source = 'manual'): void
    {
        Database::getInstance()->query(
            'INSERT IGNORE INTO vendor_categories (id, vendor_id, category, source, created_at)
             VALUES (?, ?, ?, ?, NOW())',
            [Database::uuid(), $vendorId, $category, $source]
        );
    }

    public function listForVendor(string $vendorId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT category, source FROM vendor_categories WHERE vendor_id = ? ORDER BY category',
            [$vendorId]
        );
    }

    public function vendorsForCategory(string $category, int $limit = 200): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT DISTINCT v.id, v.name, v.type, v.is_affiliated, v.aggregate_rating, v.rating_count
               FROM vendors v
               JOIN vendor_categories vc ON vc.vendor_id = v.id
              WHERE vc.category = ?
              ORDER BY v.is_affiliated DESC, v.aggregate_rating DESC, v.name
              LIMIT ?',
            [$category, $limit]
        );
    }
}
