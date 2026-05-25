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
            'SELECT ingredient_key, qty, unit FROM recipe_ingredients WHERE recipe_id = ?',
            [$recipeId]
        );
    }
}
