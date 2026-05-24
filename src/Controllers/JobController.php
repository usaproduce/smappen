<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Background job status. Workers (scripts/job-worker.php) consume the jobs
 * table; controllers create rows and the frontend polls /api/jobs/{id} for
 * progress.
 */
class JobController
{
    public function show(Request $request): void
    {
        $id = $request->getParam('id');
        $row = Database::getInstance()->fetch(
            'SELECT j.*, p.organization_id
             FROM jobs j LEFT JOIN projects p ON p.id = j.project_id
             WHERE j.id = ?',
            [$id]
        );
        if (!$row) Response::error('Job not found', 404);
        // ACL: same user, or same org if the job is project-scoped.
        $org = $request->user['organization_id'] ?? null;
        if ($row['user_id'] !== $request->user['id'] && $row['organization_id'] !== $org) {
            Response::error('Access denied', 403);
        }
        foreach (['payload', 'result'] as $k) {
            if (!empty($row[$k])) $row[$k] = json_decode($row[$k], true);
        }
        Response::success($row);
    }

    public function cancel(Request $request): void
    {
        $id = $request->getParam('id');
        $row = Database::getInstance()->fetch('SELECT * FROM jobs WHERE id = ?', [$id]);
        if (!$row) Response::error('Job not found', 404);
        if ($row['user_id'] !== $request->user['id']) Response::error('Only the job creator can cancel', 403);
        if (in_array($row['status'], ['done', 'failed', 'cancelled'], true)) {
            Response::success(['id' => $id, 'status' => $row['status'], 'note' => 'already terminal']);
        }
        // Soft cancel: workers honor the cancelled status on the next progress checkpoint.
        Database::getInstance()->query(
            'UPDATE jobs SET status = "cancelled", finished_at = NOW() WHERE id = ? AND status IN ("queued","running")',
            [$id]
        );
        Response::success(['id' => $id, 'status' => 'cancelled']);
    }
}
