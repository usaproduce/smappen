-- 039_cogs_rollups_rolling_unmatched.sql — COGS ingest v2 improvements.
--
-- Three additions, all reversible from the prior 036 baseline:
--
--   1. cogs_benchmark gains observation_count + price_stddev_cents +
--      is_anomaly so the row reflects a within-day MEDIAN over N raw
--      observations rather than first-write-wins. CogsBenchmarkService
--      now aggregates IngestRows across all batches before writing.
--   2. cogs_benchmark_rolling — keyed by (ingredient_key, region, source),
--      caches 7d and 30d rolling mean/stddev/min/max. Recomputed at the
--      end of each refresh run for tuples touched today. Used by:
--        - PlateCostService for "current vs 30d normal" comparison
--        - anomaly detection (today's median vs prior 30d ± 3σ)
--        - admin /api/admin/cogs/health for at-a-glance freshness
--   3. cogs_unmatched_commodities — every AMS row whose commodity+variety
--      doesn't match any ingredient_key in the config is logged here with
--      a running observation_count. Drives the operator-facing "200 rows
--      of Squash, Yellow last week — add it to config?" surface.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + INFORMATION_SCHEMA-guarded ALTERs.

-- ───────────────────────────────────────────────────────────────────────────
-- cogs_benchmark.observation_count — # of raw upstream observations rolled
-- into this row's median.
-- ───────────────────────────────────────────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cogs_benchmark'
               AND COLUMN_NAME  = 'observation_count');
SET @s := IF(@col = 0,
    'ALTER TABLE cogs_benchmark ADD COLUMN observation_count SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER batch_id',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- ───────────────────────────────────────────────────────────────────────────
-- cogs_benchmark.price_stddev_cents — within-day stddev over the N
-- observations. NULL when N <= 1.
-- ───────────────────────────────────────────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cogs_benchmark'
               AND COLUMN_NAME  = 'price_stddev_cents');
SET @s := IF(@col = 0,
    'ALTER TABLE cogs_benchmark ADD COLUMN price_stddev_cents INT UNSIGNED NULL AFTER observation_count',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- ───────────────────────────────────────────────────────────────────────────
-- cogs_benchmark.is_anomaly — set when today's median deviates from the
-- prior 30d mean by > 3σ. Surfaced by the UI as a "market spike" badge.
-- ───────────────────────────────────────────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cogs_benchmark'
               AND COLUMN_NAME  = 'is_anomaly');
SET @s := IF(@col = 0,
    'ALTER TABLE cogs_benchmark ADD COLUMN is_anomaly TINYINT(1) NOT NULL DEFAULT 0 AFTER price_stddev_cents',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cogs_benchmark'
               AND INDEX_NAME   = 'idx_cogs_anomaly');
SET @s := IF(@idx = 0,
    'ALTER TABLE cogs_benchmark ADD INDEX idx_cogs_anomaly (is_anomaly, as_of)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- ───────────────────────────────────────────────────────────────────────────
-- cogs_benchmark_rolling — rolling stats per (ingredient_key, region, source).
-- One row per tuple, updated on every refresh that touches that tuple.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cogs_benchmark_rolling (
  id                CHAR(36)        PRIMARY KEY,
  ingredient_key    VARCHAR(120)    NOT NULL,
  region            VARCHAR(40)     NULL,
  source            ENUM('usda','greendock','usa_produce','foundation_foods','stub') NOT NULL,
  as_of_max         DATE            NOT NULL,
  mean_7d_cents     INT UNSIGNED    NULL,
  mean_30d_cents    INT UNSIGNED    NULL,
  stddev_30d_cents  INT UNSIGNED    NULL,
  min_30d_cents     INT UNSIGNED    NULL,
  max_30d_cents     INT UNSIGNED    NULL,
  obs_count_30d     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- NULL region needs to be unique-distinct, so include a synthetic
  -- region_key = COALESCE(region,'__null__') if we ever hit collisions.
  -- For now MySQL treats two NULLs as distinct in a UNIQUE so
  -- (key, NULL, source) can appear once per write, which is fine because
  -- region is NULL only when the source itself is region-less.
  UNIQUE KEY uk_rolling (ingredient_key, region, source),
  INDEX idx_rolling_key (ingredient_key),
  INDEX idx_rolling_freshness (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- cogs_unmatched_commodities — running count of upstream rows we didn't
-- recognize. Operator surface to evolve the ingredient_key catalog.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cogs_unmatched_commodities (
  id                CHAR(36)        PRIMARY KEY,
  adapter           VARCHAR(48)     NOT NULL,
  source_ref        VARCHAR(255)    NULL,
  commodity         VARCHAR(160)    NOT NULL,
  variety           VARCHAR(160)    NOT NULL DEFAULT '',
  unit_hint         VARCHAR(60)     NULL,
  observation_count INT UNSIGNED    NOT NULL DEFAULT 1,
  first_seen_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_unmatched (adapter, commodity, variety),
  INDEX idx_unmatched_volume (observation_count DESC),
  INDEX idx_unmatched_recent (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
