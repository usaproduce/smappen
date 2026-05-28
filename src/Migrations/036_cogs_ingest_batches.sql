-- 036_cogs_ingest_batches.sql — Carafe COGS benchmark provenance ledger.
--
-- Mirrors the vendor_sources pattern (mig 026): every row in cogs_benchmark
-- gets to point back at the batch that produced it, and every batch
-- records the adapter, endpoint, region, http status, and counts that
-- made it. That's how we answer "where did this $1.80 tomato price come
-- from?" from the audit trail rather than from inference.
--
-- Spec §13 Q2: provenance is the linchpin for the COGS benchmark — we
-- need to distinguish public USDA prices from private supplier prices
-- (legal gate §6.1), and we need to show operators a freshness footer
-- per region+source.
--
-- All ops idempotent.

-- ───────────────────────────────────────────────────────────────────────────
-- cogs_ingest_batches — one row per CogsIngestAdapter::fetchBatch() call.
-- Successful and failed runs both insert a row so the operator dashboard
-- can show last-attempt vs last-success for each adapter.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cogs_ingest_batches (
  id              CHAR(36)        PRIMARY KEY,
  adapter         VARCHAR(48)     NOT NULL,          -- 'usda_ams' | 'usda_nass' | 'greendock'
  source          ENUM('usda','greendock','usa_produce','foundation_foods','stub') NOT NULL,
  region          VARCHAR(40)     NULL,
  endpoint        VARCHAR(500)    NULL,              -- URL (or composite ref) called
  source_ref      VARCHAR(255)    NULL,              -- report slug / commodity / dataset id
  as_of           DATE            NOT NULL,
  fetched_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rows_fetched    INT UNSIGNED    NOT NULL DEFAULT 0,
  rows_inserted   INT UNSIGNED    NOT NULL DEFAULT 0,
  rows_skipped    INT UNSIGNED    NOT NULL DEFAULT 0,
  http_status     SMALLINT        NULL,
  latency_ms      INT UNSIGNED    NULL,
  ok              TINYINT(1)      NOT NULL DEFAULT 0,
  error_message   VARCHAR(500)    NULL,
  notes_json      JSON            NULL,              -- adapter-specific debug (e.g. parsed report metadata)
  INDEX idx_cib_source_region (source, region, fetched_at),
  INDEX idx_cib_adapter (adapter, fetched_at),
  INDEX idx_cib_fetched (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- cogs_benchmark.batch_id — link each price row to the batch that wrote it.
-- Nullable: pre-036 rows (stub seed + earlier ingest attempts) carry NULL.
-- Not declared FOREIGN KEY to avoid coupling drops to history.
-- ───────────────────────────────────────────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cogs_benchmark'
               AND COLUMN_NAME  = 'batch_id');
SET @s := IF(@col = 0,
    'ALTER TABLE cogs_benchmark ADD COLUMN batch_id CHAR(36) NULL AFTER as_of',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cogs_benchmark'
               AND INDEX_NAME   = 'idx_cogs_benchmark_batch');
SET @s := IF(@idx = 0,
    'ALTER TABLE cogs_benchmark ADD INDEX idx_cogs_benchmark_batch (batch_id)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
