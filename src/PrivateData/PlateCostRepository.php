<?php
declare(strict_types=1);

namespace App\PrivateData;

use App\Core\Database;

class PlateCostRepository
{
    public function upsert(string $organizationId, string $menuItemId, int $trueCostCents, int $coveragePct, array $missingIngredients = []): void
    {
        $existing = Database::getInstance()->fetch(
            'SELECT id FROM plate_costs WHERE menu_item_id = ?',
            [$menuItemId]
        );
        if ($existing) {
            Database::getInstance()->query(
                'UPDATE plate_costs
                    SET true_cost_cents = ?, coverage_pct = ?, missing_ingredients = ?, computed_at = NOW()
                  WHERE id = ?',
                [$trueCostCents, $coveragePct, json_encode($missingIngredients), $existing['id']]
            );
        } else {
            Database::getInstance()->query(
                'INSERT INTO plate_costs
                    (id, organization_id, menu_item_id, true_cost_cents, coverage_pct, missing_ingredients, computed_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [Database::uuid(), $organizationId, $menuItemId, $trueCostCents, $coveragePct, json_encode($missingIngredients)]
            );
        }
    }

    public function findByMenuItem(string $menuItemId): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT true_cost_cents, coverage_pct, missing_ingredients, computed_at
               FROM plate_costs WHERE menu_item_id = ?',
            [$menuItemId]
        );
    }
}
