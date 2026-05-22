<?php
namespace App\Models;

use App\Core\Database;

class Organization
{
    public static function create(array $data): string
    {
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');
        return Database::getInstance()->insert('organizations', $data);
    }

    public static function findById(string $id): ?array
    {
        return Database::getInstance()->fetch('SELECT * FROM organizations WHERE id = ?', [$id]);
    }

    public static function update(string $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::getInstance()->update('organizations', $data, 'id = :where_id', [':where_id' => $id]);
    }

    public static function updatePlan(string $id, string $plan): int
    {
        return self::update($id, ['plan' => $plan]);
    }

    public static function getMemberCount(string $id): int
    {
        $row = Database::getInstance()->fetch('SELECT COUNT(*) AS c FROM users WHERE organization_id = ?', [$id]);
        return (int)($row['c'] ?? 0);
    }

    public static function findByStripeCustomerId(string $customerId): ?array
    {
        return Database::getInstance()->fetch('SELECT * FROM organizations WHERE stripe_customer_id = ?', [$customerId]);
    }
}
