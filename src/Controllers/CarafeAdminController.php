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
    /**
     * GET /api/admin/carafe/cron-health
     *
     * Reads worker_heartbeats, joins each row against
     * WorkerHeartbeat::CADENCE_SECONDS, and bucket-flags every worker:
     *
     *   green  — last beat within 1× cadence  (or never expected to have run yet)
     *   yellow — last beat within 2× cadence  (one missed tick)
     *   red    — last beat past 2× cadence, OR no row at all  (operator should investigate)
     *
     * Returns:
     *   {
     *     workers: [
     *       { name, status: 'green'|'yellow'|'red'|'unknown',
     *         cadence_seconds, last_beat_at, last_beat_age_seconds,
     *         ticks_total, last_args, last_note, pid, host },
     *       ...
     *     ],
     *     summary: { green: N, yellow: N, red: N, unknown: N },
     *     server_time: ISO8601,
     *   }
     */
    public function cronHealth(Request $request): void
    {
        $db = Database::getInstance();

        try {
            $rows = $db->fetchAll(
                'SELECT worker_name, beat_at, ticks_total, pid, host, last_args, last_note,
                        TIMESTAMPDIFF(SECOND, beat_at, NOW()) AS age_seconds
                   FROM worker_heartbeats'
            );
        } catch (\Throwable $e) {
            // Table missing on a droplet that hasn't run migration 038
            // yet — degrade to "all unknown" so the admin home can
            // still render and prompt the operator to run migrations.
            error_log('[CarafeAdminController::cronHealth] heartbeat read failed: ' . $e->getMessage());
            $rows = [];
        }

        $byName = [];
        foreach ($rows as $r) {
            $byName[(string) $r['worker_name']] = $r;
        }

        $workers = [];
        $summary = ['green' => 0, 'yellow' => 0, 'red' => 0, 'unknown' => 0];

        foreach (WorkerHeartbeat::CADENCE_SECONDS as $name => $cadence) {
            $r = $byName[$name] ?? null;
            if ($r === null) {
                $status = 'red';
                $workers[] = [
                    'name'                  => $name,
                    'status'                => $status,
                    'cadence_seconds'       => $cadence,
                    'last_beat_at'          => null,
                    'last_beat_age_seconds' => null,
                    'ticks_total'           => 0,
                    'last_args'             => null,
                    'last_note'             => null,
                    'pid'                   => null,
                    'host'                  => null,
                ];
                $summary[$status]++;
                continue;
            }

            $age = (int) $r['age_seconds'];
            if ($age <= $cadence)            { $status = 'green'; }
            elseif ($age <= 2 * $cadence)    { $status = 'yellow'; }
            else                             { $status = 'red'; }

            $workers[] = [
                'name'                  => $name,
                'status'                => $status,
                'cadence_seconds'       => $cadence,
                'last_beat_at'          => $r['beat_at'],
                'last_beat_age_seconds' => $age,
                'ticks_total'           => (int) $r['ticks_total'],
                'last_args'             => $r['last_args'],
                'last_note'             => $r['last_note'],
                'pid'                   => $r['pid'] !== null ? (int) $r['pid'] : null,
                'host'                  => $r['host'],
            ];
            $summary[$status]++;
        }

        Response::success([
            'workers'      => $workers,
            'summary'      => $summary,
            'server_time'  => date('c'),
        ]);
    }
}
