<?php
namespace App\Models;

use App\Core\Database;

class Folder
{
    public static function create(array $data): string
    {
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        return Database::getInstance()->insert('folders', $data);
    }

    public static function findById(string $id): ?array
    {
        return Database::getInstance()->fetch('SELECT * FROM folders WHERE id = ?', [$id]);
    }

    public static function update(string $id, array $data): int
    {
        return Database::getInstance()->update('folders', $data, 'id = :where_id', [':where_id' => $id]);
    }

    public static function delete(string $id): int
    {
        Database::getInstance()->update('areas', ['folder_id' => null], 'folder_id = :fid', [':fid' => $id]);
        Database::getInstance()->update('folders', ['parent_folder_id' => null], 'parent_folder_id = :fid', [':fid' => $id]);
        return Database::getInstance()->delete('folders', 'id = :id', [':id' => $id]);
    }

    public static function getTreeByProject(string $projectId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT f.*, (SELECT COUNT(*) FROM areas WHERE folder_id = f.id) AS area_count
             FROM folders f WHERE project_id = ? ORDER BY sort_order, name',
            [$projectId]
        );
        $byParent = [];
        foreach ($rows as $r) {
            $byParent[$r['parent_folder_id'] ?? 'root'][] = $r;
        }
        $build = function ($parent) use (&$build, &$byParent) {
            $out = [];
            foreach ($byParent[$parent] ?? [] as $f) {
                $f['children'] = $build($f['id']);
                $out[] = $f;
            }
            return $out;
        };
        return $build('root');
    }

    public static function reorder(string $projectId, array $orderedIds): void
    {
        $db = Database::getInstance();
        foreach ($orderedIds as $i => $id) {
            $db->update('folders', ['sort_order' => $i], 'id = :id AND project_id = :pid', [':id' => $id, ':pid' => $projectId]);
        }
    }
}
