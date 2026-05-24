-- 010_area_favorites.sql — toggle for starring areas. Sort favorites-first in
-- the left panel. Idempotent via INFORMATION_SCHEMA guard.

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'areas'
    AND column_name = 'is_favorite'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE areas ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0 AFTER notes',
  'SELECT "areas.is_favorite already exists" AS noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'areas'
    AND index_name = 'idx_area_favorite'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE areas ADD INDEX idx_area_favorite (project_id, is_favorite)',
  'SELECT "idx_area_favorite already exists" AS noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
