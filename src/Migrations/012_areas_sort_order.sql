-- 012_areas_sort_order.sql — adds a per-project sort_order column to areas
-- so user-driven drag-reorder in the left panel survives a page reload.
--
-- Default 0 + a covering index on (project_id, sort_order, created_at) so
-- the area-list query can serve a deterministic ORDER BY without a filesort.
--
-- Idempotent: guarded by INFORMATION_SCHEMA so re-running the migration on
-- a partially-applied DB doesn't error.

SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'areas'
     AND COLUMN_NAME  = 'sort_order'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE areas ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER notes',
  'SELECT "areas.sort_order already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'areas'
     AND INDEX_NAME   = 'idx_areas_proj_sort'
);
SET @sql := IF(@idx = 0,
  'CREATE INDEX idx_areas_proj_sort ON areas (project_id, sort_order, created_at)',
  'SELECT "idx_areas_proj_sort already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
