<?php
declare(strict_types=1);

namespace App\PrivateData;

use App\Core\Database;

class MenuItemRepository
{
    public function findById(string $id, string $organizationId): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT * FROM menu_items WHERE id = ? AND organization_id = ?',
            [$id, $organizationId]
        );
    }

    public function listByRestaurant(string $restaurantId): array
    {
        // Joined with plate_costs so the UI can render margin in one round-trip.
        return Database::getInstance()->fetchAll(
            'SELECT mi.id, mi.name, mi.category, mi.price_cents, mi.recipe_id, mi.is_active,
                    mi.pos_provider, mi.pos_item_id, mi.last_synced_at,
                    pc.true_cost_cents, pc.coverage_pct, pc.computed_at AS cost_computed_at
               FROM menu_items mi
               LEFT JOIN plate_costs pc ON pc.menu_item_id = mi.id
              WHERE mi.restaurant_id = ?
              ORDER BY mi.is_active DESC, mi.name ASC',
            [$restaurantId]
        );
    }

    /**
     * Upsert by (restaurant_id, pos_provider, pos_item_id) — POS sync calls
     * this once per pulled item.
     */
    public function upsertFromPos(string $organizationId, string $restaurantId, string $provider, array $item): string
    {
        $existing = Database::getInstance()->fetch(
            'SELECT id FROM menu_items
              WHERE restaurant_id = ? AND pos_provider = ? AND pos_item_id = ?',
            [$restaurantId, $provider, $item['pos_item_id']]
        );
        if ($existing) {
            Database::getInstance()->query(
                'UPDATE menu_items
                    SET name = ?, category = ?, price_cents = ?, is_active = 1, last_synced_at = NOW()
                  WHERE id = ?',
                [$item['name'], $item['category'] ?? null, (int) ($item['price_cents'] ?? 0), $existing['id']]
            );
            return $existing['id'];
        }
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO menu_items
                (id, organization_id, restaurant_id, pos_provider, pos_item_id,
                 name, category, price_cents, is_active, last_synced_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())',
            [
                $id, $organizationId, $restaurantId, $provider, $item['pos_item_id'],
                $item['name'], $item['category'] ?? null, (int) ($item['price_cents'] ?? 0),
            ]
        );
        return $id;
    }

    /** Manual insert for the seed sample restaurant (no POS connected). */
    public function createManual(string $organizationId, string $restaurantId, array $data): string
    {
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO menu_items
                (id, organization_id, restaurant_id, name, category, price_cents, recipe_id,
                 is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [
                $id, $organizationId, $restaurantId,
                $data['name'], $data['category'] ?? null,
                (int) $data['price_cents'], $data['recipe_id'] ?? null,
            ]
        );
        return $id;
    }

    public function setRecipe(string $id, string $organizationId, ?string $recipeId): void
    {
        Database::getInstance()->query(
            'UPDATE menu_items SET recipe_id = ?, updated_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [$recipeId, $id, $organizationId]
        );
    }

    public function setPrice(string $id, string $organizationId, int $priceCents): void
    {
        Database::getInstance()->query(
            'UPDATE menu_items SET price_cents = ?, updated_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [$priceCents, $id, $organizationId]
        );
    }

    /** Map pos_item_id → menu_items.id for one restaurant + provider. */
    public function posIdMap(string $restaurantId, string $provider): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, pos_item_id FROM menu_items
              WHERE restaurant_id = ? AND pos_provider = ? AND pos_item_id IS NOT NULL',
            [$restaurantId, $provider]
        );
        $out = [];
        foreach ($rows as $r) $out[(string) $r['pos_item_id']] = (string) $r['id'];
        return $out;
    }

    public function listWithRecipes(string $restaurantId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT id, recipe_id FROM menu_items
              WHERE restaurant_id = ? AND is_active = 1 AND recipe_id IS NOT NULL',
            [$restaurantId]
        );
    }
}
