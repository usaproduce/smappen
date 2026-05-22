<?php
namespace App\Models;

use App\Core\Database;

class Project
{
    public static function create(array $data): string
    {
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');
        return Database::getInstance()->insert('projects', $data);
    }

    public static function findById(string $id): ?array
    {
        $row = Database::getInstance()->fetch(
            'SELECT p.*, u.name AS created_by_name,
                    (SELECT COUNT(*) FROM areas WHERE project_id = p.id) AS area_count
             FROM projects p
             LEFT JOIN users u ON u.id = p.created_by
             WHERE p.id = ?',
            [$id]
        );
        return $row ?: null;
    }

    public static function update(string $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::getInstance()->update('projects', $data, 'id = :where_id', [':where_id' => $id]);
    }

    public static function delete(string $id): int
    {
        return Database::getInstance()->delete('projects', 'id = :id', [':id' => $id]);
    }

    public static function getByOrganization(string $orgId, ?string $search = null, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $where = 'p.organization_id = :org';
        $params = [':org' => $orgId];
        if ($search) {
            $where .= ' AND p.name LIKE :search';
            $params[':search'] = '%' . $search . '%';
        }
        $sql = "SELECT p.*, (SELECT COUNT(*) FROM areas WHERE project_id = p.id) AS area_count
                FROM projects p
                WHERE $where
                ORDER BY p.updated_at DESC
                LIMIT $perPage OFFSET $offset";
        $rows = Database::getInstance()->fetchAll($sql, $params);

        $totalRow = Database::getInstance()->fetch("SELECT COUNT(*) AS c FROM projects p WHERE $where", $params);
        return [
            'items' => $rows,
            'total' => (int)($totalRow['c'] ?? 0),
        ];
    }

    public static function getByShareToken(string $token): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT * FROM projects WHERE share_token = ? AND is_shared = 1',
            [$token]
        );
    }

    public static function generateShareToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
