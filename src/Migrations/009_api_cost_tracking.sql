-- 009_api_cost_tracking.sql — record estimated USD cost per Google API call
-- so users can see daily spend in the header and per-call toasts on the
-- frontend. INFORMATION_SCHEMA-guarded so re-running is a no-op.

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'api_usage_log'
    AND column_name = 'estimated_cost_usd'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE api_usage_log ADD COLUMN estimated_cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0 AFTER request_count',
  'SELECT "api_usage_log.estimated_cost_usd already exists" AS noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for the daily-spend query (sum over user_id within today).
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'api_usage_log'
    AND index_name = 'idx_usage_cost_day'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE api_usage_log ADD INDEX idx_usage_cost_day (user_id, created_at, estimated_cost_usd)',
  'SELECT "idx_usage_cost_day already exists" AS noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
