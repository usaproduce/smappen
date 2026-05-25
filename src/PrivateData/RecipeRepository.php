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
        return Database::getInstance()->fetchAll(
            'SELECT id, ingredient_key, qty, unit, notes FROM recipe_ingredients WHERE recipe_id = ?',
            [$recipeId]
        );
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
}
