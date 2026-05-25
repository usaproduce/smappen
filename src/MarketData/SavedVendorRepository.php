<?php
declare(strict_types=1);

namespace App\MarketData;

use App\Core\Database;

class SavedVendorRepository
{
    public function save(string $organizationId, string $vendorId, string $userId, ?string $note = null): void
    {
        Database::getInstance()->query(
            'INSERT INTO saved_vendors (id, organization_id, vendor_id, user_id, note, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE note = VALUES(note)',
            [Database::uuid(), $organizationId, $vendorId, $userId, $note]
        );
    }

    public function unsave(string $organizationId, string $vendorId): void
    {
        Database::getInstance()->query(
            'DELETE FROM saved_vendors WHERE organization_id = ? AND vendor_id = ?',
            [$organizationId, $vendorId]
        );
    }

    public function listForOrg(string $organizationId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT sv.vendor_id, sv.note, sv.created_at,
                    v.name, v.type, v.primary_category, v.is_affiliated,
                    v.aggregate_rating, v.rating_count
               FROM saved_vendors sv
               JOIN vendors v ON v.id = sv.vendor_id
              WHERE sv.organization_id = ?
              ORDER BY sv.created_at DESC',
            [$organizationId]
        );
    }

    public function isSaved(string $organizationId, string $vendorId): bool
    {
        $row = Database::getInstance()->fetch(
            'SELECT 1 AS one FROM saved_vendors WHERE organization_id = ? AND vendor_id = ? LIMIT 1',
            [$organizationId, $vendorId]
        );
        return $row !== null;
    }
}
