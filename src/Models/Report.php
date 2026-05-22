<?php
namespace App\Models;

use App\Core\Database;

class Report
{
    public static function create(array $data): string
    {
        $data['generated_at'] = $data['generated_at'] ?? date('Y-m-d H:i:s');
        return Database::getInstance()->insert('reports', $data);
    }

    public static function findById(string $id): ?array
    {
        return Database::getInstance()->fetch('SELECT * FROM reports WHERE id = ?', [$id]);
    }

    public static function getByProject(string $projectId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT r.*, a.name AS area_name FROM reports r
             LEFT JOIN areas a ON a.id = r.area_id
             WHERE r.project_id = ? ORDER BY r.generated_at DESC',
            [$projectId]
        );
    }
}
