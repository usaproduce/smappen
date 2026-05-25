<?php
declare(strict_types=1);

namespace App\MarketData;

use App\Core\Database;

class VendorClaimRepository
{
    public function create(string $vendorId, string $organizationId, string $claimantUserId, array $contact, ?string $message): string
    {
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO vendor_claims
                (id, vendor_id, organization_id, claimant_user_id, contact_email, contact_phone, message, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, "pending", NOW())',
            [
                $id, $vendorId, $organizationId, $claimantUserId,
                $contact['email'], $contact['phone'] ?? null, $message,
            ]
        );
        return $id;
    }

    public function findById(string $id): ?array
    {
        return Database::getInstance()->fetch('SELECT * FROM vendor_claims WHERE id = ?', [$id]);
    }

    public function listForVendor(string $vendorId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT id, organization_id, claimant_user_id, contact_email, status, decided_at, created_at
               FROM vendor_claims WHERE vendor_id = ? ORDER BY created_at DESC',
            [$vendorId]
        );
    }

    public function listPendingForOrg(string $organizationId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT c.id, c.vendor_id, c.contact_email, c.created_at, v.name AS vendor_name
               FROM vendor_claims c
               JOIN vendors v ON v.id = c.vendor_id
              WHERE c.organization_id = ? AND c.status = "pending"
              ORDER BY c.created_at DESC',
            [$organizationId]
        );
    }

    public function decide(string $id, string $status, string $decidedByUserId): void
    {
        Database::getInstance()->query(
            'UPDATE vendor_claims SET status = ?, decided_at = NOW(), decided_by = ? WHERE id = ?',
            [$status, $decidedByUserId, $id]
        );
    }

    public function pendingExistsFor(string $vendorId, string $organizationId): bool
    {
        $row = Database::getInstance()->fetch(
            'SELECT 1 AS one FROM vendor_claims
              WHERE vendor_id = ? AND organization_id = ? AND status = "pending"
              LIMIT 1',
            [$vendorId, $organizationId]
        );
        return $row !== null;
    }
}
