<?php
declare(strict_types=1);

namespace App\PrivateData;

use App\Core\Database;

/**
 * Restaurants — Carafe's primary org-scoped entity. Lives in the private
 * reservoir because the restaurant's location + which POS it uses are
 * inputs to the money engine and not to be exposed to the funnel.
 */
class RestaurantRepository
{
    public function findById(string $id, string $organizationId): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT * FROM restaurants WHERE id = ? AND organization_id = ? AND archived_at IS NULL',
            [$id, $organizationId]
        );
    }

    public function listByOrg(string $organizationId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT id, organization_id, name, address, lat, lng, timezone, region, is_sample, created_at
               FROM restaurants
              WHERE organization_id = ? AND archived_at IS NULL
              ORDER BY created_at DESC',
            [$organizationId]
        );
    }

    public function findSample(): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT * FROM restaurants WHERE is_sample = 1 LIMIT 1'
        );
    }

    public function create(string $organizationId, array $data): string
    {
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO restaurants
                (id, organization_id, name, address, lat, lng, timezone, region, is_sample, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $id, $organizationId,
                $data['name'],
                $data['address'] ?? null,
                $data['lat'] ?? null,
                $data['lng'] ?? null,
                $data['timezone'] ?? null,
                $data['region'] ?? null,
                !empty($data['is_sample']) ? 1 : 0,
            ]
        );
        return $id;
    }

    public function archive(string $id, string $organizationId): void
    {
        Database::getInstance()->query(
            'UPDATE restaurants SET archived_at = NOW() WHERE id = ? AND organization_id = ?',
            [$id, $organizationId]
        );
    }
}
