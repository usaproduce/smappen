-- 021_carafe_activation_columns.sql — Carafe Chunk 8: extend the existing
-- activation_metrics table with Carafe-specific milestones.
--
-- Additive only. Idempotent via INFORMATION_SCHEMA guards (matches the
-- pattern from `008_bug_fixes.sql`).

SET @col_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'activation_metrics'
                     AND COLUMN_NAME = 'first_pos_connected_at');
SET @stmt := IF(@col_check = 0,
    'ALTER TABLE activation_metrics ADD COLUMN first_pos_connected_at DATETIME NULL AFTER first_report_at',
    'SELECT "first_pos_connected_at already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @col_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'activation_metrics'
                     AND COLUMN_NAME = 'first_menu_synced_at');
SET @stmt := IF(@col_check = 0,
    'ALTER TABLE activation_metrics ADD COLUMN first_menu_synced_at DATETIME NULL AFTER first_pos_connected_at',
    'SELECT "first_menu_synced_at already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @col_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'activation_metrics'
                     AND COLUMN_NAME = 'first_recommendation_accepted_at');
SET @stmt := IF(@col_check = 0,
    'ALTER TABLE activation_metrics ADD COLUMN first_recommendation_accepted_at DATETIME NULL AFTER first_menu_synced_at',
    'SELECT "first_recommendation_accepted_at already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @col_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'activation_metrics'
                     AND COLUMN_NAME = 'first_dollar_measured_at');
SET @stmt := IF(@col_check = 0,
    'ALTER TABLE activation_metrics ADD COLUMN first_dollar_measured_at DATETIME NULL AFTER first_recommendation_accepted_at',
    'SELECT "first_dollar_measured_at already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
