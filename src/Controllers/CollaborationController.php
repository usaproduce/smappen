<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Collaboration endpoints — versions, comments, change_log, collaborators,
 * approvals. Kept in one controller because they all share the same
 * project-scope ACL check and most ops are CRUD-shaped.
 *
 * ACL model:
 *   - User must belong to the project's organization, OR
 *   - User must have a project_collaborators row (any role) for the project.
 *   - Mutating actions require role >= 'editor' (or 'owner' for approval).
 */
class CollaborationController
{
    // ── Versions ──────────────────────────────────────────────────────────
    public function snapshotVersion(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireAccess($request, $projectId, 'editor');
        $note = (string)($request->input('note') ?? '');

        $project = Database::getInstance()->fetch('SELECT * FROM projects WHERE id = ?', [$projectId]);
        $areas = Database::getInstance()->fetchAll(
            "SELECT id, name, area_type, fill_color, stroke_color, fill_opacity,
                    stroke_weight, travel_mode, travel_time_minutes, travel_distance_km,
                    center_lat, center_lng, center_address,
                    ST_AsGeoJSON(geometry, 6) AS geom_json
             FROM areas WHERE project_id = ?",
            [$projectId]
        );
        $folders = Database::getInstance()->fetchAll(
            'SELECT * FROM folders WHERE project_id = ?',
            [$projectId]
        );

        $snapshot = [
            'project' => $project,
            'folders' => $folders,
            'areas' => $areas,
            'snapshot_at' => date('c'),
        ];

        $row = Database::getInstance()->fetch(
            'SELECT COALESCE(MAX(version_number),0)+1 AS next FROM project_versions WHERE project_id = ?',
            [$projectId]
        );
        $versionNumber = (int)$row['next'];
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO project_versions
               (id, project_id, version_number, snapshot_json, note, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$id, $projectId, $versionNumber, json_encode($snapshot), $note ?: null, $request->user['id']]
        );
        self::logChange($projectId, $request->user['id'], 'version', $id, 'create', ['version_number' => $versionNumber]);
        Response::success([
            'id' => $id,
            'version_number' => $versionNumber,
            'project_id' => $projectId,
            'created_at' => date('c'),
        ]);
    }

    public function listVersions(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireAccess($request, $projectId, 'viewer');
        $rows = Database::getInstance()->fetchAll(
            'SELECT v.id, v.version_number, v.note, v.created_at,
                    u.name AS created_by_name, u.email AS created_by_email
             FROM project_versions v
             LEFT JOIN users u ON u.id = v.created_by
             WHERE v.project_id = ?
             ORDER BY v.version_number DESC
             LIMIT 200',
            [$projectId]
        );
        Response::success(['versions' => $rows]);
    }

    public function showVersion(Request $request): void
    {
        $id = $request->getParam('id');
        $row = Database::getInstance()->fetch(
            'SELECT v.*, u.name AS created_by_name
             FROM project_versions v
             LEFT JOIN users u ON u.id = v.created_by
             WHERE v.id = ?',
            [$id]
        );
        if (!$row) Response::error('Version not found', 404);
        self::requireAccess($request, $row['project_id'], 'viewer');
        $row['snapshot'] = json_decode($row['snapshot_json'], true);
        unset($row['snapshot_json']);
        Response::success($row);
    }

    // ── Comments ──────────────────────────────────────────────────────────
    public function listComments(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireAccess($request, $projectId, 'viewer');
        $areaId = $request->getQuery('area_id');
        $sql = 'SELECT c.*, u.name AS author_name, u.email AS author_email
                FROM comments c LEFT JOIN users u ON u.id = c.user_id
                WHERE c.project_id = ?';
        $params = [$projectId];
        if ($areaId) {
            $sql .= ' AND c.area_id = ?';
            $params[] = $areaId;
        }
        $sql .= ' ORDER BY c.created_at ASC LIMIT 500';
        $rows = Database::getInstance()->fetchAll($sql, $params);
        Response::success(['comments' => $rows]);
    }

    public function createComment(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireAccess($request, $projectId, 'viewer');
        $body = trim((string)($request->input('body') ?? ''));
        if (mb_strlen($body) < 1 || mb_strlen($body) > 5000) {
            Response::error('Comment must be 1-5000 chars');
        }
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO comments
               (id, project_id, area_id, parent_comment_id, user_id, body,
                anchor_lat, anchor_lng, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $id, $projectId,
                $request->input('area_id') ?: null,
                $request->input('parent_comment_id') ?: null,
                $request->user['id'],
                $body,
                $request->input('anchor_lat') !== null ? (float)$request->input('anchor_lat') : null,
                $request->input('anchor_lng') !== null ? (float)$request->input('anchor_lng') : null,
            ]
        );
        self::logChange($projectId, $request->user['id'], 'comment', $id, 'create', null);
        self::notifyCollaborators($projectId, $request->user['id'], 'comment', 'New comment', mb_substr($body, 0, 200));
        Response::success(['id' => $id]);
    }

    public function resolveComment(Request $request): void
    {
        $id = $request->getParam('id');
        $c = Database::getInstance()->fetch('SELECT project_id FROM comments WHERE id = ?', [$id]);
        if (!$c) Response::error('Comment not found', 404);
        self::requireAccess($request, $c['project_id'], 'editor');
        Database::getInstance()->query(
            'UPDATE comments SET resolved_at = NOW(), resolved_by = ?, updated_at = NOW() WHERE id = ?',
            [$request->user['id'], $id]
        );
        self::logChange($c['project_id'], $request->user['id'], 'comment', $id, 'resolve', null);
        Response::success(['id' => $id, 'resolved' => true]);
    }

    public function deleteComment(Request $request): void
    {
        $id = $request->getParam('id');
        $c = Database::getInstance()->fetch('SELECT project_id, user_id FROM comments WHERE id = ?', [$id]);
        if (!$c) Response::error('Comment not found', 404);
        // Author can delete their own; otherwise need editor+.
        if ($c['user_id'] !== $request->user['id']) {
            self::requireAccess($request, $c['project_id'], 'editor');
        } else {
            self::requireAccess($request, $c['project_id'], 'viewer');
        }
        Database::getInstance()->query('DELETE FROM comments WHERE id = ?', [$id]);
        self::logChange($c['project_id'], $request->user['id'], 'comment', $id, 'delete', null);
        Response::success(['id' => $id, 'deleted' => true]);
    }

    // ── Change log ────────────────────────────────────────────────────────
    public function listChanges(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireAccess($request, $projectId, 'viewer');
        $rows = Database::getInstance()->fetchAll(
            'SELECT cl.*, u.name AS user_name
             FROM change_log cl
             LEFT JOIN users u ON u.id = cl.user_id
             WHERE cl.project_id = ?
             ORDER BY cl.id DESC
             LIMIT 500',
            [$projectId]
        );
        foreach ($rows as &$r) {
            if (!empty($r['diff_json'])) $r['diff_json'] = json_decode($r['diff_json'], true);
        }
        Response::success(['changes' => $rows]);
    }

    // ── Collaborators ─────────────────────────────────────────────────────
    public function listCollaborators(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireAccess($request, $projectId, 'viewer');
        $rows = Database::getInstance()->fetchAll(
            'SELECT pc.*, u.name, u.email
             FROM project_collaborators pc
             JOIN users u ON u.id = pc.user_id
             WHERE pc.project_id = ?',
            [$projectId]
        );
        Response::success(['collaborators' => $rows]);
    }

    public function addCollaborator(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireAccess($request, $projectId, 'owner');
        $email = trim((string)($request->input('email') ?? ''));
        $role = (string)($request->input('role') ?? 'viewer');
        if (!in_array($role, ['viewer', 'editor', 'admin', 'owner'], true)) {
            Response::error('Invalid role (allowed: viewer, editor, admin, owner)');
        }
        $user = Database::getInstance()->fetch('SELECT id FROM users WHERE email = ?', [$email]);
        if (!$user) Response::error('User with that email not found', 404);
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO project_collaborators (id, project_id, user_id, role, invited_by, invited_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE role = VALUES(role)',
            [$id, $projectId, $user['id'], $role, $request->user['id']]
        );
        self::logChange($projectId, $request->user['id'], 'collaborator', $user['id'], 'add', ['role' => $role]);
        Response::success(['user_id' => $user['id'], 'role' => $role]);
    }

    public function removeCollaborator(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        $userId = $request->getParam('userId');
        self::requireAccess($request, $projectId, 'owner');
        Database::getInstance()->query(
            'DELETE FROM project_collaborators WHERE project_id = ? AND user_id = ?',
            [$projectId, $userId]
        );
        self::logChange($projectId, $request->user['id'], 'collaborator', $userId, 'remove', null);
        Response::success(['removed' => true]);
    }

    // ── Approval requests ─────────────────────────────────────────────────
    public function createApproval(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireAccess($request, $projectId, 'editor');
        $title = trim((string)($request->input('title') ?? ''));
        if (mb_strlen($title) < 1) Response::error('Title required');
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO approval_requests
               (id, project_id, requested_by, title, description, payload_json, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, "pending", NOW())',
            [
                $id, $projectId, $request->user['id'],
                $title,
                $request->input('description'),
                $request->input('payload') !== null ? json_encode($request->input('payload')) : null,
            ]
        );
        self::logChange($projectId, $request->user['id'], 'approval', $id, 'create', ['title' => $title]);
        self::notifyCollaborators($projectId, $request->user['id'], 'approval_request', 'Approval needed', $title);
        Response::success(['id' => $id, 'status' => 'pending']);
    }

    public function listApprovals(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireAccess($request, $projectId, 'viewer');
        $rows = Database::getInstance()->fetchAll(
            'SELECT ar.*, u.name AS requester_name, ud.name AS decider_name
             FROM approval_requests ar
             LEFT JOIN users u ON u.id = ar.requested_by
             LEFT JOIN users ud ON ud.id = ar.decided_by
             WHERE ar.project_id = ?
             ORDER BY ar.created_at DESC
             LIMIT 200',
            [$projectId]
        );
        foreach ($rows as &$r) {
            if (!empty($r['payload_json'])) $r['payload_json'] = json_decode($r['payload_json'], true);
        }
        Response::success(['approvals' => $rows]);
    }

    public function decideApproval(Request $request): void
    {
        $id = $request->getParam('id');
        $row = Database::getInstance()->fetch('SELECT * FROM approval_requests WHERE id = ?', [$id]);
        if (!$row) Response::error('Approval not found', 404);
        self::requireAccess($request, $row['project_id'], 'admin');

        $decision = (string)($request->input('decision') ?? 'approved');
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            Response::error('decision must be approved or rejected');
        }
        Database::getInstance()->query(
            'UPDATE approval_requests
             SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ?
             WHERE id = ?',
            [$decision, $request->user['id'], $request->input('note'), $id]
        );
        self::logChange($row['project_id'], $request->user['id'], 'approval', $id, $decision, null);
        Response::success(['id' => $id, 'status' => $decision]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────
    private static function requireAccess(Request $request, ?string $projectId, string $minRole = 'viewer'): array
    {
        if (!$projectId) Response::error('projectId required');
        $user = $request->user;
        $org = $user['organization_id'] ?? null;
        // Same-org access counts as owner-equivalent for now.
        $p = Database::getInstance()->fetch('SELECT id, organization_id FROM projects WHERE id = ?', [$projectId]);
        if (!$p) Response::error('Project not found', 404);
        if ($org && $p['organization_id'] === $org) {
            // Org members get full access (the org role gates them above).
            return $p;
        }
        $pc = Database::getInstance()->fetch(
            'SELECT role FROM project_collaborators WHERE project_id = ? AND user_id = ?',
            [$projectId, $user['id']]
        );
        if (!$pc) Response::error('Access denied', 403);
        // Higher = more permissive. 'admin' covers both team management (invite/remove)
        // and approval authority — owner is the only role that can change ownership.
        $rank = ['viewer' => 1, 'editor' => 2, 'admin' => 3, 'owner' => 4];
        if (($rank[$pc['role']] ?? 0) < ($rank[$minRole] ?? 0)) {
            Response::error('Insufficient role for this action (need ' . $minRole . ')', 403);
        }
        return $p;
    }

    private static function logChange(string $projectId, ?string $userId, string $entityType, ?string $entityId, string $action, ?array $diff): void
    {
        try {
            Database::getInstance()->query(
                'INSERT INTO change_log (project_id, user_id, entity_type, entity_id, action, diff_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$projectId, $userId, $entityType, $entityId, $action, $diff !== null ? json_encode($diff) : null]
            );
        } catch (\Throwable $e) {
            error_log('change_log insert failed: ' . $e->getMessage());
        }
    }

    private static function notifyCollaborators(string $projectId, ?string $excludeUserId, string $type, string $title, string $body): void
    {
        try {
            $users = Database::getInstance()->fetchAll(
                'SELECT user_id FROM project_collaborators WHERE project_id = ?',
                [$projectId]
            );
            $owner = Database::getInstance()->fetch('SELECT created_by FROM projects WHERE id = ?', [$projectId]);
            $targets = array_column($users, 'user_id');
            if ($owner && !empty($owner['created_by'])) $targets[] = $owner['created_by'];
            $targets = array_values(array_unique($targets));
            foreach ($targets as $uid) {
                if ($uid === $excludeUserId) continue;
                Database::getInstance()->query(
                    'INSERT INTO notifications (id, user_id, project_id, notif_type, title, body, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())',
                    [Database::uuid(), $uid, $projectId, $type, $title, $body]
                );
            }
        } catch (\Throwable $e) {
            error_log('notify failed: ' . $e->getMessage());
        }
    }
}
