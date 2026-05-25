<?php
declare(strict_types=1);

namespace App\PrivateData;

use App\Core\Database;

class LaborShiftRepository
{
    public function upsertFromPos(string $organizationId, string $restaurantId, string $provider, array $shift): void
    {
        $existing = Database::getInstance()->fetch(
            'SELECT id FROM labor_shifts WHERE restaurant_id = ? AND pos_provider = ? AND pos_shift_uid = ?',
            [$restaurantId, $provider, $shift['pos_shift_uid']]
        );
        if ($existing) {
            Database::getInstance()->query(
                'UPDATE labor_shifts SET employee_label = ?, role = ?, starts_at = ?, ends_at = ?, hourly_wage_cents = ?
                  WHERE id = ?',
                [
                    $shift['employee_label'] ?? null, $shift['role'] ?? null,
                    $shift['starts_at'], $shift['ends_at'] ?? null,
                    isset($shift['hourly_wage_cents']) ? (int) $shift['hourly_wage_cents'] : null,
                    $existing['id'],
                ]
            );
            return;
        }
        Database::getInstance()->query(
            'INSERT INTO labor_shifts
                (id, organization_id, restaurant_id, employee_label, role,
                 pos_provider, pos_shift_uid, source, starts_at, ends_at, hourly_wage_cents, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                Database::uuid(), $organizationId, $restaurantId,
                $shift['employee_label'] ?? null, $shift['role'] ?? null,
                $provider, $shift['pos_shift_uid'], $provider,
                $shift['starts_at'], $shift['ends_at'] ?? null,
                isset($shift['hourly_wage_cents']) ? (int) $shift['hourly_wage_cents'] : null,
            ]
        );
    }

    public function createManual(string $organizationId, string $restaurantId, array $shift): string
    {
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO labor_shifts
                (id, organization_id, restaurant_id, employee_label, role,
                 source, starts_at, ends_at, hourly_wage_cents, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, "manual", ?, ?, ?, NOW(), NOW())',
            [
                $id, $organizationId, $restaurantId,
                $shift['employee_label'] ?? null, $shift['role'] ?? null,
                $shift['starts_at'], $shift['ends_at'] ?? null,
                isset($shift['hourly_wage_cents']) ? (int) $shift['hourly_wage_cents'] : null,
            ]
        );
        return $id;
    }

    public function listInWindow(string $restaurantId, string $start, string $end): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT id, employee_label, role, starts_at, ends_at, hourly_wage_cents
               FROM labor_shifts
              WHERE restaurant_id = ?
                AND starts_at < ?
                AND (ends_at IS NULL OR ends_at > ?)
              ORDER BY starts_at ASC',
            [$restaurantId, $end, $start]
        );
    }

    public function maxStartsAt(string $restaurantId, string $provider): ?string
    {
        $row = Database::getInstance()->fetch(
            'SELECT MAX(starts_at) AS last_start FROM labor_shifts WHERE restaurant_id = ? AND pos_provider = ?',
            [$restaurantId, $provider]
        );
        return $row['last_start'] ?? null;
    }
}
