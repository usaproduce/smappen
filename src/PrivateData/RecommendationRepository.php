<?php
declare(strict_types=1);

namespace App\PrivateData;

use App\Core\Database;

class RecommendationRepository
{
    public function create(
        string $organizationId,
        string $restaurantId,
        ?string $menuItemId,
        string $kind,
        array $payload,
        ?string $narrative,
        int $dollarEstimateCents
    ): string {
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO recommendations
                (id, organization_id, restaurant_id, menu_item_id, kind, payload,
                 narrative, dollar_estimate_cents, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "suggested", NOW())',
            [
                $id, $organizationId, $restaurantId, $menuItemId, $kind,
                json_encode($payload), $narrative, $dollarEstimateCents,
            ]
        );
        return $id;
    }

    public function listByRestaurant(string $restaurantId, ?string $status = null, int $limit = 100): array
    {
        if ($status !== null) {
            $rows = Database::getInstance()->fetchAll(
                'SELECT id, menu_item_id, kind, payload, narrative, dollar_estimate_cents,
                        status, measured_impact_cents, created_at, decided_at, measured_at
                   FROM recommendations
                  WHERE restaurant_id = ? AND status = ?
                  ORDER BY created_at DESC LIMIT ?',
                [$restaurantId, $status, $limit]
            );
        } else {
            $rows = Database::getInstance()->fetchAll(
                'SELECT id, menu_item_id, kind, payload, narrative, dollar_estimate_cents,
                        status, measured_impact_cents, created_at, decided_at, measured_at
                   FROM recommendations
                  WHERE restaurant_id = ?
                  ORDER BY created_at DESC LIMIT ?',
                [$restaurantId, $limit]
            );
        }
        foreach ($rows as &$r) {
            $r['dollar_estimate_cents']  = (int) $r['dollar_estimate_cents'];
            $r['measured_impact_cents']  = $r['measured_impact_cents'] === null ? null : (int) $r['measured_impact_cents'];
        }
        return $rows;
    }

    public function findById(string $id, string $organizationId): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT * FROM recommendations WHERE id = ? AND organization_id = ?',
            [$id, $organizationId]
        );
    }

    public function decide(string $id, string $organizationId, string $status): void
    {
        Database::getInstance()->query(
            'UPDATE recommendations SET status = ?, decided_at = NOW()
              WHERE id = ? AND organization_id = ? AND status = "suggested"',
            [$status, $id, $organizationId]
        );
    }

    /** True if a recent (last 7d) suggestion already exists for this item+kind — guards against duplicate spam. */
    public function recentExistsFor(string $menuItemId, string $kind): bool
    {
        $row = Database::getInstance()->fetch(
            'SELECT 1 AS one FROM recommendations
              WHERE menu_item_id = ? AND kind = ?
                AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
              LIMIT 1',
            [$menuItemId, $kind]
        );
        return $row !== null;
    }
}
