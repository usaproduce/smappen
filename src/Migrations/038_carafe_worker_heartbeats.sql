-- 037_carafe_worker_heartbeats.sql — Carafe cron-pipeline observability.
--
-- Each worker (seed-tile, seed-dedupe, seed-classify, seed-enrich,
-- seed-resweep, seed-coverage, send-weekly-digest, measure-roi) writes
-- one row per successful tick. The admin cron-health endpoint groups by
-- worker_name and compares NOW() - MAX(beat_at) against the worker's
-- expected cadence to flag red/yellow/green.
--
-- One row per tick, not per process — we use UPSERT on worker_name so
-- the table stays small (one row per worker name forever). The
-- ticks_total counter still lets us see a process did run.
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS worker_heartbeats (
  worker_name      VARCHAR(64)     PRIMARY KEY,
  beat_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ticks_total      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  pid              INT UNSIGNED    NULL,
  host             VARCHAR(64)     NULL,
  last_args        VARCHAR(500)    NULL,
  last_note        VARCHAR(255)    NULL,
  INDEX idx_wh_beat (beat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
