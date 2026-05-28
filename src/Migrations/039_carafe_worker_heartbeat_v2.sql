-- 039_carafe_worker_heartbeat_v2.sql — observability v2 for Carafe workers.
--
-- v1 (mig 038) only recorded "did the worker beat?". v2 extends to:
--   - status: 'running' | 'ok' | 'error' — distinguishes started from completed
--   - last_error: captured exception or fatal message
--   - ticks_failed: counts failed runs for error-rate
--   - last_duration_ms: how long the last tick took (perf trend)
--   - last_started_at: when the most recent run began (paired with beat_at = ended_at)
--   - last_alerted_status: bookkeeping for the alert-on-transition log
--
-- All ALTERs idempotent via INFORMATION_SCHEMA guards (mig 036 pattern).

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'worker_heartbeats' AND COLUMN_NAME = 'status');
SET @s := IF(@col = 0,
    'ALTER TABLE worker_heartbeats ADD COLUMN status ENUM("running","ok","error") NOT NULL DEFAULT "ok" AFTER beat_at',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'worker_heartbeats' AND COLUMN_NAME = 'last_error');
SET @s := IF(@col = 0,
    'ALTER TABLE worker_heartbeats ADD COLUMN last_error VARCHAR(500) NULL AFTER status',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'worker_heartbeats' AND COLUMN_NAME = 'ticks_failed');
SET @s := IF(@col = 0,
    'ALTER TABLE worker_heartbeats ADD COLUMN ticks_failed BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER ticks_total',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'worker_heartbeats' AND COLUMN_NAME = 'last_duration_ms');
SET @s := IF(@col = 0,
    'ALTER TABLE worker_heartbeats ADD COLUMN last_duration_ms INT UNSIGNED NULL AFTER ticks_failed',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'worker_heartbeats' AND COLUMN_NAME = 'last_started_at');
SET @s := IF(@col = 0,
    'ALTER TABLE worker_heartbeats ADD COLUMN last_started_at DATETIME NULL AFTER last_duration_ms',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'worker_heartbeats' AND COLUMN_NAME = 'last_alerted_status');
SET @s := IF(@col = 0,
    'ALTER TABLE worker_heartbeats ADD COLUMN last_alerted_status VARCHAR(16) NULL AFTER last_note',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
