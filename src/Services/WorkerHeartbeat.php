<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Carafe worker liveness ledger. v2 (mig 039) tracks status/error/duration
 * per worker, not just "did it beat".
 *
 * Two usage patterns:
 *
 *   1. Wrapped script (preferred for batch workers):
 *
 *        WorkerHeartbeat::start('seed-dedupe', "batch-size=$batchSize");
 *        // ... do work ...
 *        // shutdown handler auto-fires finish() at script exit, marking
 *        //   status='ok' on normal exit, status='error' on fatal/uncaught.
 *
 *      Override the auto-finish with an explicit one to attach a note:
 *
 *        WorkerHeartbeat::finish('seed-dedupe', "merged=$n stale=$m");
 *
 *   2. Per-iteration beat (for long-running loop workers like
 *      seed-tile-worker that hold multiple tiles per invocation):
 *
 *        WorkerHeartbeat::start('seed-tile-worker', $argSummary);
 *        while ($keepGoing) {
 *            WorkerHeartbeat::beat('seed-tile-worker', "tile=$id");
 *            // ... work ...
 *        }
 *
 *      beat() bumps ticks_total + refreshes beat_at, but does NOT change
 *      the run's status or end its timing window. finish() (auto or
 *      manual) is what closes the run.
 *
 * Designed to never throw: a DB blip during heartbeat must not crash
 * the worker mid-tick. Failures log to error_log and the call returns.
 */
final class WorkerHeartbeat
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_OK      = 'ok';
    public const STATUS_ERROR   = 'error';

    /**
     * Expected cadence per worker, in seconds. The admin endpoint uses
     * this to bucket worker freshness:
     *   green  — last beat within 1× cadence
     *   yellow — within 2× cadence
     *   red    — past 2× cadence, or no row at all
     *
     * Weekly jobs use a generous 8d so a one-day delay during DST or a
     * holiday outage doesn't false-alarm the home-page banner.
     */
    public const CADENCE_SECONDS = [
        'seed-tile-worker'   =>     5 * 60,
        'seed-dedupe'        =>    10 * 60,
        'seed-classify'      =>    10 * 60,
        'seed-coverage'      =>    15 * 60,
        'seed-resweep'       =>     5 * 60,
        'seed-enrich'        => 24 * 3600,
        'measure-roi'        => 24 * 3600,
        'send-weekly-digest' =>  8 * 24 * 3600,
    ];

    /** start-time (microtime float) per worker name, for duration calc on finish(). */
    private static array $startedAt = [];
    /** start args per worker name, so finish() can preserve them. */
    private static array $startArgs = [];
    /** finish-already-called guard so the shutdown handler doesn't double-fire. */
    private static array $finished = [];

    /**
     * Mark a worker run as started. Status flips to 'running' immediately
     * so the dashboard reflects in-flight state. Registers a shutdown
     * handler that auto-finishes with 'ok' (normal exit) or 'error'
     * (fatal / uncaught exception).
     */
    public static function start(string $name, ?string $args = null): void
    {
        self::$startedAt[$name] = microtime(true);
        self::$startArgs[$name] = $args;
        unset(self::$finished[$name]);

        self::write(
            $name,
            self::STATUS_RUNNING,
            null,
            $args,
            null,
            null,
            /* isStart */ true,
            /* isBeat  */ false,
        );

        register_shutdown_function([self::class, 'shutdownFinish'], $name);
    }

    /**
     * End a worker run. Bumps ticks_total + records duration_ms.
     * Pass a non-null $error to mark status='error' and bump ticks_failed.
     * Safe to call after auto-finish — the second call is a no-op.
     */
    public static function finish(string $name, ?string $note = null, ?string $error = null): void
    {
        if (!empty(self::$finished[$name])) {
            return;
        }
        self::$finished[$name] = true;

        $started = self::$startedAt[$name] ?? microtime(true);
        $duration = (int) round((microtime(true) - $started) * 1000);
        $status = $error ? self::STATUS_ERROR : self::STATUS_OK;

        self::write(
            $name,
            $status,
            $error,
            self::$startArgs[$name] ?? null,
            $note,
            $duration,
            /* isStart */ false,
            /* isBeat  */ false,
        );
    }

    /**
     * Per-iteration tick — refreshes beat_at + bumps ticks_total without
     * closing the run. Use inside long loops to show "still alive" between
     * start() and the final finish(). status stays 'running'.
     */
    public static function beat(string $name, ?string $note = null): void
    {
        self::write(
            $name,
            self::STATUS_RUNNING,
            null,
            null,
            $note,
            null,
            /* isStart */ false,
            /* isBeat  */ true,
        );
    }

    /**
     * register_shutdown_function trampoline — fires on script exit if
     * finish() hasn't been called yet. Reads error_get_last() to decide
     * whether to record 'ok' or 'error'.
     */
    public static function shutdownFinish(string $name): void
    {
        if (!empty(self::$finished[$name])) {
            return;
        }
        $last = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        $isFatal = $last !== null && in_array((int) ($last['type'] ?? 0), $fatalTypes, true);
        self::finish($name, null, $isFatal ? ($last['message'] ?? 'fatal error') : null);
    }

    /**
     * Single point of DB contact. Uses MySQL 8 row-alias syntax (AS new) —
     * avoids the deprecated VALUES() form. Never throws.
     *
     * On `start` we set status='running' and stamp last_started_at; we do
     * NOT bump ticks_total or refresh beat_at (the tick hasn't completed).
     * On `finish` we update status/error/duration and bump ticks_total
     * (+ ticks_failed on error) and stamp beat_at = NOW().
     * On `beat` (mid-loop) we refresh beat_at + bump ticks_total but leave
     * status as 'running' and don't touch duration.
     */
    private static function write(
        string $name,
        string $status,
        ?string $error,
        ?string $args,
        ?string $note,
        ?int $durationMs,
        bool $isStart,
        bool $isBeat,
    ): void {
        try {
            $db = Database::getInstance();
            $isErr = $status === self::STATUS_ERROR ? 1 : 0;
            $pid   = getmypid() ?: null;
            $host  = gethostname() ?: null;
            $argsT = $args !== null ? mb_substr($args, 0, 500) : null;
            $noteT = $note !== null ? mb_substr($note, 0, 255) : null;
            $errT  = $error !== null ? mb_substr($error, 0, 500) : null;

            // Three different UPDATE branches depending on which call this
            // is. Spelled out rather than building dynamic SQL — easier to
            // audit + the query planner caches each one separately.
            if ($isStart) {
                $sql = 'INSERT INTO worker_heartbeats
                          (worker_name, beat_at, status, last_error, ticks_total, ticks_failed,
                           last_duration_ms, last_started_at, pid, host, last_args, last_note)
                        VALUES (?, NOW(), ?, NULL, 0, 0, NULL, NOW(), ?, ?, ?, NULL)
                        AS new
                        ON DUPLICATE KEY UPDATE
                          status          = new.status,
                          last_started_at = new.last_started_at,
                          pid             = new.pid,
                          host            = new.host,
                          last_args       = new.last_args,
                          last_error      = NULL';
                $db->query($sql, [$name, $status, $pid, $host, $argsT]);
                return;
            }

            if ($isBeat) {
                // Bare column names in ON DUPLICATE KEY UPDATE are ambiguous
                // once a row alias is introduced — qualify existing-row
                // references with the table name (worker_heartbeats) so
                // MySQL knows we mean the prior row, not new.*.
                $sql = 'INSERT INTO worker_heartbeats
                          (worker_name, beat_at, status, ticks_total, pid, host, last_note)
                        VALUES (?, NOW(), ?, 1, ?, ?, ?)
                        AS new
                        ON DUPLICATE KEY UPDATE
                          beat_at     = NOW(),
                          ticks_total = worker_heartbeats.ticks_total + 1,
                          pid         = new.pid,
                          host        = new.host,
                          last_note   = COALESCE(new.last_note, worker_heartbeats.last_note)';
                $db->query($sql, [$name, $status, $pid, $host, $noteT]);
                return;
            }

            // finish() path
            $sql = 'INSERT INTO worker_heartbeats
                      (worker_name, beat_at, status, last_error, ticks_total, ticks_failed,
                       last_duration_ms, pid, host, last_args, last_note)
                    VALUES (?, NOW(), ?, ?, 1, ?, ?, ?, ?, ?, ?)
                    AS new
                    ON DUPLICATE KEY UPDATE
                      beat_at          = NOW(),
                      status           = new.status,
                      last_error       = new.last_error,
                      ticks_total      = worker_heartbeats.ticks_total + 1,
                      ticks_failed     = worker_heartbeats.ticks_failed + new.ticks_failed,
                      last_duration_ms = COALESCE(new.last_duration_ms, worker_heartbeats.last_duration_ms),
                      pid              = new.pid,
                      host             = new.host,
                      last_args        = COALESCE(new.last_args, worker_heartbeats.last_args),
                      last_note        = COALESCE(new.last_note, worker_heartbeats.last_note)';
            $db->query($sql, [$name, $status, $errT, $isErr, $durationMs, $pid, $host, $argsT, $noteT]);
        } catch (\Throwable $e) {
            error_log("[WorkerHeartbeat] $name $status write failed: " . $e->getMessage());
        }
    }
}
