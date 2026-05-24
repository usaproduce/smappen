-- 008_bug_fixes.sql — small schema fixes uncovered in the bug audit.
-- Earlier draft used `ADD COLUMN IF NOT EXISTS`, which only landed in
-- MySQL 8.0.29. The droplet runs 8.0.45 in some envs and 8.0 in others;
-- this version uses INFORMATION_SCHEMA-guarded prepared statements so the
-- migration is portable AND idempotent.

-- B1: tokens_invalid_before on users — bulk JWT revocation marker.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'users'
    AND column_name = 'tokens_invalid_before'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE users ADD COLUMN tokens_invalid_before DATETIME NULL AFTER api_key_last4',
  'SELECT "users.tokens_invalid_before already exists" AS noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- B20: ensure the collaborator enum matches what the code expects after the
-- "approver → admin" rename. The MODIFY is idempotent — running it when
-- the column already has the target type is a no-op.
ALTER TABLE project_collaborators
  MODIFY role ENUM('viewer','editor','admin','owner') NOT NULL DEFAULT 'viewer';
