<?php
declare(strict_types=1);

namespace App\PrivateData;

use App\Core\Database;

class GoalRepository
{
    public function create(string $organizationId, string $restaurantId, string $metric, float $target, string $cadence, ?string $label): string
    {
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO goals (id, organization_id, restaurant_id, metric, target_value, cadence, label, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [$id, $organizationId, $restaurantId, $metric, $target, $cadence, $label]
        );
        return $id;
    }

    public function listByRestaurant(string $restaurantId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT id, metric, target_value, cadence, label, is_active, created_at
               FROM goals WHERE restaurant_id = ? AND is_active = 1
              ORDER BY created_at DESC',
            [$restaurantId]
        );
    }

    public function findById(string $id, string $organizationId): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT * FROM goals WHERE id = ? AND organization_id = ?',
            [$id, $organizationId]
        );
    }

    public function archive(string $id, string $organizationId): void
    {
        Database::getInstance()->query(
            'UPDATE goals SET is_active = 0, updated_at = NOW() WHERE id = ? AND organization_id = ?',
            [$id, $organizationId]
        );
    }

    public function recordSnapshot(string $goalId, string $periodStart, string $periodEnd, float $actual): void
    {
        $existing = Database::getInstance()->fetch(
            'SELECT id FROM goal_snapshots WHERE goal_id = ? AND period_start = ? AND period_end = ?',
            [$goalId, $periodStart, $periodEnd]
        );
        if ($existing) {
            Database::getInstance()->query(
                'UPDATE goal_snapshots SET actual_value = ? WHERE id = ?',
                [$actual, $existing['id']]
            );
        } else {
            Database::getInstance()->query(
                'INSERT INTO goal_snapshots (id, goal_id, period_start, period_end, actual_value, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [Database::uuid(), $goalId, $periodStart, $periodEnd, $actual]
            );
        }
    }

    public function recentSnapshots(string $goalId, int $limit = 12): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT period_start, period_end, actual_value
               FROM goal_snapshots WHERE goal_id = ?
              ORDER BY period_end DESC LIMIT ?',
            [$goalId, $limit]
        );
    }
}
