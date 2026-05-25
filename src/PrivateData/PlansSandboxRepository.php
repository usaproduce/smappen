<?php
declare(strict_types=1);

namespace App\PrivateData;

use App\Core\Database;

class PlansSandboxRepository
{
    public function create(string $organizationId, ?string $restaurantId, string $name, string $kind, array $payload): string
    {
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO plans_sandbox (id, organization_id, restaurant_id, name, kind, payload, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$id, $organizationId, $restaurantId, $name, $kind, json_encode($payload)]
        );
        return $id;
    }

    public function findById(string $id, string $organizationId): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT * FROM plans_sandbox WHERE id = ? AND organization_id = ?',
            [$id, $organizationId]
        );
    }

    public function listByOrg(string $organizationId, ?string $restaurantId = null): array
    {
        if ($restaurantId !== null) {
            return Database::getInstance()->fetchAll(
                'SELECT id, name, kind, restaurant_id, computed_at, created_at
                   FROM plans_sandbox
                  WHERE organization_id = ? AND restaurant_id = ?
                  ORDER BY created_at DESC',
                [$organizationId, $restaurantId]
            );
        }
        return Database::getInstance()->fetchAll(
            'SELECT id, name, kind, restaurant_id, computed_at, created_at
               FROM plans_sandbox
              WHERE organization_id = ?
              ORDER BY created_at DESC',
            [$organizationId]
        );
    }

    public function setProjected(string $id, array $projected): void
    {
        Database::getInstance()->query(
            'UPDATE plans_sandbox SET projected = ?, computed_at = NOW(), updated_at = NOW() WHERE id = ?',
            [json_encode($projected), $id]
        );
    }

    public function destroy(string $id, string $organizationId): void
    {
        Database::getInstance()->query(
            'DELETE FROM plans_sandbox WHERE id = ? AND organization_id = ?',
            [$id, $organizationId]
        );
    }
}
