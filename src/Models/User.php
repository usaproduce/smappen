<?php
namespace App\Models;

use App\Core\Database;

class User
{
    public static function findByEmail(string $email): ?array
    {
        return Database::getInstance()->fetch('SELECT * FROM users WHERE email = ?', [$email]);
    }

    public static function findById(string $id): ?array
    {
        return Database::getInstance()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function create(array $data): string
    {
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');
        return Database::getInstance()->insert('users', $data);
    }

    public static function update(string $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::getInstance()->update('users', $data, 'id = :where_id', [':where_id' => $id]);
    }

    public static function getWithOrganization(string $id): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT u.*, o.name AS organization_name, o.plan, o.stripe_customer_id, o.stripe_subscription_id, o.max_seats
             FROM users u
             LEFT JOIN organizations o ON o.id = u.organization_id
             WHERE u.id = ?',
            [$id]
        );
    }
}
