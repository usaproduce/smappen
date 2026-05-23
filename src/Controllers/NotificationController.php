<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

class NotificationController
{
    public function index(Request $request): void
    {
        $userId = $request->user['id'];
        $unreadOnly = $request->getQuery('unread') === '1';
        $sql = 'SELECT * FROM notifications WHERE user_id = ?';
        $params = [$userId];
        if ($unreadOnly) $sql .= ' AND is_read = 0';
        $sql .= ' ORDER BY created_at DESC LIMIT 100';
        $rows = Database::getInstance()->fetchAll($sql, $params);
        foreach ($rows as &$r) {
            if (!empty($r['payload_json'])) $r['payload_json'] = json_decode($r['payload_json'], true);
        }
        $unread = Database::getInstance()->fetch(
            'SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
        Response::success([
            'notifications' => $rows,
            'unread_count' => (int)($unread['n'] ?? 0),
        ]);
    }

    public function markRead(Request $request): void
    {
        $id = $request->getParam('id');
        Database::getInstance()->query(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?',
            [$id, $request->user['id']]
        );
        Response::success(['id' => $id, 'read' => true]);
    }

    public function markAllRead(Request $request): void
    {
        Database::getInstance()->query(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0',
            [$request->user['id']]
        );
        Response::success(['ok' => true]);
    }
}
