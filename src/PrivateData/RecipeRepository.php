<?php
declare(strict_types=1);

namespace App\PrivateData;

use App\Core\Database;

class RecipeRepository
{
    public function create(string $organizationId, string $restaurantId, string $name, ?string $notes = null): string
    {
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO recipes (id, organization_id, restaurant_id, name, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$id, $organizationId, $restaurantId, $name, $notes]
        );
        return $id;
    }

    public function addIngredient(string $recipeId, string $ingredientKey, float $qty, string $unit, ?string $notes = null): string
    {
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO recipe_ingredients (id, recipe_id, ingredient_key, qty, unit, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$id, $recipeId, $ingredientKey, $qty, $unit, $notes]
        );
        return $id;
    }

    public function ingredientsFor(string $recipeId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, ingredient_key, qty, unit, notes FROM recipe_ingredients WHERE recipe_id = ?',
            [$recipeId]
        );
        // PDO stringifies DECIMAL — cast qty back to float so the frontend's
        // `Number(ing.qty)` shows the right number in the recipe editor.
        foreach ($rows as &$r) $r['qty'] = (float) $r['qty'];
        return $rows;
    }

    public function listByRestaurant(string $restaurantId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT r.id, r.name, r.notes, r.created_at, r.updated_at,
                    (SELECT COUNT(*) FROM recipe_ingredients ri WHERE ri.recipe_id = r.id) AS ingredient_count,
                    (SELECT COUNT(*) FROM menu_items mi WHERE mi.recipe_id = r.id) AS linked_menu_items
               FROM recipes r
              WHERE r.restaurant_id = ?
              ORDER BY r.created_at DESC',
            [$restaurantId]
        );
    }

    public function findById(string $id, string $organizationId): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT * FROM recipes WHERE id = ? AND organization_id = ?',
            [$id, $organizationId]
        );
    }

    public function removeIngredient(string $ingredientId, string $organizationId): bool
    {
        // Org check via the recipe FK.
        $row = Database::getInstance()->fetch(
            'SELECT ri.id FROM recipe_ingredients ri
               JOIN recipes r ON r.id = ri.recipe_id
              WHERE ri.id = ? AND r.organization_id = ?',
            [$ingredientId, $organizationId]
        );
        if (!$row) return false;
        Database::getInstance()->query('DELETE FROM recipe_ingredients WHERE id = ?', [$ingredientId]);
        return true;
    }

    /**
     * Map LOWER(TRIM(name)) → id for every recipe in a restaurant. Used by
     * commitPaste to detect duplicate names so the operator can choose
     * skip / replace / create_new instead of silently double-creating.
     */
    public function nameMapByRestaurant(string $restaurantId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, LOWER(TRIM(name)) AS norm_name FROM recipes WHERE restaurant_id = ?',
            [$restaurantId]
        );
        $out = [];
        foreach ($rows as $r) $out[(string) $r['norm_name']] = (string) $r['id'];
        return $out;
    }

    /** Wipe every ingredient on a recipe — used by the "replace" duplicate path. */
    public function clearIngredients(string $recipeId): void
    {
        Database::getInstance()->query(
            'DELETE FROM recipe_ingredients WHERE recipe_id = ?',
            [$recipeId]
        );
    }

    /**
     * Delete a recipe and its ingredients. Returns false if the recipe
     * doesn't exist or doesn't belong to the caller's org. Any menu_items
     * pointing at it have their recipe_id nulled by FK ON DELETE SET NULL
     * (if configured) — otherwise we null them explicitly to avoid orphaned
     * pointers.
     */
    public function destroy(string $id, string $organizationId): bool
    {
        $db = Database::getInstance();
        $row = $db->fetch('SELECT id FROM recipes WHERE id = ? AND organization_id = ?', [$id, $organizationId]);
        if (!$row) return false;
        $db->beginTransaction();
        try {
            $db->query('UPDATE menu_items SET recipe_id = NULL, updated_at = NOW() WHERE recipe_id = ?', [$id]);
            $db->query('DELETE FROM recipe_ingredients WHERE recipe_id = ?', [$id]);
            $db->query('DELETE FROM plate_costs WHERE menu_item_id IN (SELECT id FROM menu_items WHERE recipe_id = ?)', [$id]);
            $db->query('DELETE FROM recipes WHERE id = ?', [$id]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        return true;
    }
}
