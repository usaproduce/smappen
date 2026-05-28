-- 037_carafe_wizard_v2.sql — Carafe first-run wizard v2 schema.
--
-- Additive only. Idempotent via INFORMATION_SCHEMA guards.
-- Splits target: migrate.php's naive `;\s*[\r\n]/` splitter — keep
-- statement-ending semicolons in column 0 of their own line. No `;` inside
-- `--` comments. Match the pattern from 021_carafe_activation_columns.sql.

-- ─── users.onboarding_state — JSON blob for wizard resume + dismiss path ──
SET @col_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'users'
                     AND COLUMN_NAME = 'onboarding_state');
SET @stmt := IF(@col_check = 0,
    'ALTER TABLE users ADD COLUMN onboarding_state JSON NULL AFTER onboarding_flags',
    'SELECT "onboarding_state already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── activation_metrics.carafe_wizard_step_2_at ──────────────────────────
SET @col_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'activation_metrics'
                     AND COLUMN_NAME = 'carafe_wizard_step_2_at');
SET @stmt := IF(@col_check = 0,
    'ALTER TABLE activation_metrics ADD COLUMN carafe_wizard_step_2_at DATETIME NULL AFTER first_dollar_measured_at',
    'SELECT "carafe_wizard_step_2_at already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── activation_metrics.carafe_wizard_step_3_at ──────────────────────────
SET @col_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'activation_metrics'
                     AND COLUMN_NAME = 'carafe_wizard_step_3_at');
SET @stmt := IF(@col_check = 0,
    'ALTER TABLE activation_metrics ADD COLUMN carafe_wizard_step_3_at DATETIME NULL AFTER carafe_wizard_step_2_at',
    'SELECT "carafe_wizard_step_3_at already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── activation_metrics.carafe_wizard_completed_at ───────────────────────
SET @col_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'activation_metrics'
                     AND COLUMN_NAME = 'carafe_wizard_completed_at');
SET @stmt := IF(@col_check = 0,
    'ALTER TABLE activation_metrics ADD COLUMN carafe_wizard_completed_at DATETIME NULL AFTER carafe_wizard_step_3_at',
    'SELECT "carafe_wizard_completed_at already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── activation_metrics.carafe_wizard_dismissed_path — VARCHAR(40) ──────
-- One of: skipped_step_1, skipped_step_2, skipped_step_3, completed_sample,
-- completed_real_manual, completed_real_pos. Lets us split conversion by
-- exit path in the funnel report without an enum migration every time.
SET @col_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'activation_metrics'
                     AND COLUMN_NAME = 'carafe_wizard_dismissed_path');
SET @stmt := IF(@col_check = 0,
    'ALTER TABLE activation_metrics ADD COLUMN carafe_wizard_dismissed_path VARCHAR(40) NULL AFTER carafe_wizard_completed_at',
    'SELECT "carafe_wizard_dismissed_path already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── restaurants.cuisine — used to pick which sample to clone ────────────
-- Free-text VARCHAR so operators can later type "italian", "neopolitan_pizza",
-- "izakaya", etc. without an enum migration. Wizard only writes one of
-- {italian, mexican, asian, american} for samples.
SET @col_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'restaurants'
                     AND COLUMN_NAME = 'cuisine');
SET @stmt := IF(@col_check = 0,
    'ALTER TABLE restaurants ADD COLUMN cuisine VARCHAR(40) NULL AFTER region',
    'SELECT "cuisine already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
