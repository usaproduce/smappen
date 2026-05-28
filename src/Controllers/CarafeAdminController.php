<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\WorkerHeartbeat;

/**
 * CarafeAdminController — admin-side observability for the Carafe
 * worker pipeline. Routes under /api/admin/carafe/*.
 *
 * Distinct from SeedCampaignController (campaign CRUD / kick) — that
 * is about driving the pipeline, this is about watching it: is cron
 * actually scheduled? Are workers ticking on cadence? When did
 * send-weekly-digest last fire? The admin home reads these to drop
 * the "worker X hasn't beat in Yh" banner.
 *
 * All routes wrapped in Middleware::auth() + requireRole(['admin','owner']).
 */
class CarafeAdminController
{
    /** Where worker status-transition alerts are appended. Tail this to monitor. */
    public const ALERT_LOG_RELATIVE = 'storage/logs/cron-alerts.log';

    /**
     * GET /api/admin/carafe/cron-health
     *
     * Reads worker_heartbeats (v2, mig 039) and buckets each worker as
     * green/yellow/red against WorkerHeartbeat::CADENCE_SECONDS. Also:
     *
     *   - Compares each worker's current status to the snapshot we last
     *     reported (last_alerted_status column), and appends a one-line
     *     entry to storage/logs/cron-alerts.log on every transition.
     *     Tail-friendly format for operators or future Slack relay.
     *
     * Returns:
     *   {
     *     workers: [
     *       { name, status, cadence_seconds, last_beat_at, last_started_at,
     *         last_beat_age_seconds, run_status, last_error, last_duration_ms,
     *         ticks_total, ticks_failed, last_args, last_note, pid, host },
     *       ...
     *     ],
     *     summary: { green, yellow, red, with_errors, currently_running },
     *     server_time: ISO8601,
     *   }
     */
    public function cronHealth(Request $request): void
    {
        $db = Database::getInstance();

        try {
            $rows = $db->fetchAll(
                'SELECT worker_name, beat_at, last_started_at, status, last_error,
                        ticks_total, ticks_failed, last_duration_ms,
                        pid, host, last_args, last_note, last_alerted_status,
                        TIMESTAMPDIFF(SECOND, beat_at, NOW()) AS age_seconds
                   FROM worker_heartbeats'
            );
        } catch (\Throwable $e) {
            // Table missing (mig 038/039 not yet applied) — degrade to
            // "all red" so the admin home prompts the operator.
            error_log('[CarafeAdminController::cronHealth] heartbeat read failed: ' . $e->getMessage());
            $rows = [];
        }

        $byName = [];
        foreach ($rows as $r) {
            $byName[(string) $r['worker_name']] = $r;
        }

        $workers = [];
        $summary = ['green' => 0, 'yellow' => 0, 'red' => 0, 'with_errors' => 0, 'currently_running' => 0];

        foreach (WorkerHeartbeat::CADENCE_SECONDS as $name => $cadence) {
            $r = $byName[$name] ?? null;

            if ($r === null) {
                $workers[] = [
                    'name'                  => $name,
                    'status'                => 'red',
                    'cadence_seconds'       => $cadence,
                    'last_beat_at'          => null,
                    'last_started_at'       => null,
                    'last_beat_age_seconds' => null,
                    'run_status'            => null,
                    'last_error'            => null,
                    'last_duration_ms'      => null,
                    'ticks_total'           => 0,
                    'ticks_failed'          => 0,
                    'last_args'             => null,
                    'last_note'             => null,
                    'pid'                   => null,
                    'host'                  => null,
                ];
                $summary['red']++;
                continue;
            }

            $age = (int) $r['age_seconds'];
            if ($age <= $cadence)         { $bucket = 'green'; }
            elseif ($age <= 2 * $cadence) { $bucket = 'yellow'; }
            else                          { $bucket = 'red'; }

            $runStatus = (string) ($r['status'] ?? 'ok');

            $workers[] = [
                'name'                  => $name,
                'status'                => $bucket,
                'cadence_seconds'       => $cadence,
                'last_beat_at'          => $r['beat_at'],
                'last_started_at'       => $r['last_started_at'],
                'last_beat_age_seconds' => $age,
                'run_status'            => $runStatus,
                'last_error'            => $r['last_error'],
                'last_duration_ms'      => $r['last_duration_ms'] !== null ? (int) $r['last_duration_ms'] : null,
                'ticks_total'           => (int) $r['ticks_total'],
                'ticks_failed'          => (int) $r['ticks_failed'],
                'last_args'             => $r['last_args'],
                'last_note'             => $r['last_note'],
                'pid'                   => $r['pid'] !== null ? (int) $r['pid'] : null,
                'host'                  => $r['host'],
            ];
            $summary[$bucket]++;
            if ($runStatus === WorkerHeartbeat::STATUS_ERROR) $summary['with_errors']++;
            if ($runStatus === WorkerHeartbeat::STATUS_RUNNING) $summary['currently_running']++;

            $this->detectAndAlertTransition($db, $name, $bucket, $r);
        }

        Response::success([
            'workers'      => $workers,
            'summary'      => $summary,
            'server_time'  => date('c'),
        ]);
    }

    /**
     * If the bucket has changed since the last time cron-health was hit,
     * append one line to storage/logs/cron-alerts.log and persist the
     * new bucket back to the row's last_alerted_status. Best-effort —
     * exceptions are swallowed so a logging blip doesn't break the
     * dashboard.
     */
    private function detectAndAlertTransition(Database $db, string $name, string $newBucket, array $row): void
    {
        $prev = isset($row['last_alerted_status']) ? (string) $row['last_alerted_status'] : '';
        if ($prev === $newBucket) {
            return;
        }
        try {
            $db->query(
                'UPDATE worker_heartbeats SET last_alerted_status = ? WHERE worker_name = ?',
                [$newBucket, $name]
            );
            $line = sprintf(
                "[%s] %s %s → %s (age=%ss ticks_total=%d ticks_failed=%d last_error=%s)\n",
                date('c'),
                $name,
                $prev !== '' ? $prev : 'unknown',
                $newBucket,
                (string) ($row['age_seconds'] ?? '?'),
                (int) ($row['ticks_total'] ?? 0),
                (int) ($row['ticks_failed'] ?? 0),
                $row['last_error'] !== null ? '"' . str_replace(["\n", '"'], [' ', "'"], (string) $row['last_error']) . '"' : '-'
            );
            $logPath = self::alertLogPath();
            $dir = dirname($logPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            error_log('[CarafeAdminController::transitionAlert] ' . $e->getMessage());
        }
    }

    /** Absolute path to the alerts log, relative to the smappen install root. */
    private static function alertLogPath(): string
    {
        // src/Controllers/CarafeAdminController.php → up two = install root.
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::ALERT_LOG_RELATIVE;
    }
}
