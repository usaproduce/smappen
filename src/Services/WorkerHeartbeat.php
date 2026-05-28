<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Carafe worker liveness ledger. Workers call WorkerHeartbeat::beat($name)
 * at the top of each main-loop tick; the admin cron-health endpoint
 * reads worker_heartbeats and flags red/yellow/green against the
 * worker's expected cadence (see CADENCE_SECONDS below).
 *
 * One row per worker (UPSERT on worker_name) — the table never grows
 * past 8 rows. ticks_total is a monotonic counter so the dashboard can
 * tell "alive but processing nothing" from "queue draining as expected."
 *
 * Designed to never throw: a DB blip during heartbeat must not crash
 * the worker mid-tick. Failures log to error_log and the tick continues.
 */
final class WorkerHeartbeat
{
    /**
     * Expected cadence per worker, in seconds. The admin endpoint uses
     * this to bucket worker freshness:
     *   green  — last beat within 1× cadence
     *   yellow — within 2× cadence (a missed tick, may catch up)
     *   red    — past 2× cadence (operator should investigate)
     *
     * Weekly jobs use a generous 8d so a one-day delay during DST or
     * a holiday outage doesn't false-alarm the home-page banner.
     */
    public const CADENCE_SECONDS = [
        'seed-tile-worker'   =>     5 * 60,   //  cron */5
        'seed-dedupe'        =>    10 * 60,   //  cron */10
        'seed-classify'      =>    10 * 60,   //  cron */10
        'seed-coverage'      =>    15 * 60,   //  cron */15
        'seed-resweep'       =>     5 * 60,   //  cron */5
        'seed-enrich'        => 24 * 3600,    //  nightly
        'measure-roi'        => 24 * 3600,    //  nightly
        'send-weekly-digest' =>  8 * 24 * 3600, // weekly + slack
    ];

    public static function beat(string $workerName, ?string $note = null, ?string $args = null): void
    {
        try {
            $db = Database::getInstance();
            $db->query(
                'INSERT INTO worker_heartbeats (worker_name, beat_at, ticks_total, pid, host, last_args, last_note)
                 VALUES (?, NOW(), 1, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   beat_at     = NOW(),
                   ticks_total = ticks_total + 1,
                   pid         = VALUES(pid),
                   host        = VALUES(host),
                   last_args   = VALUES(last_args),
                   last_note   = VALUES(last_note)',
                [
                    $workerName,
                    getmypid() ?: null,
                    gethostname() ?: null,
                    $args !== null ? mb_substr($args, 0, 500) : null,
                    $note !== null ? mb_substr($note, 0, 255) : null,
                ]
            );
        } catch (\Throwable $e) {
            error_log("[WorkerHeartbeat] $workerName beat failed: " . $e->getMessage());
        }
    }
}
