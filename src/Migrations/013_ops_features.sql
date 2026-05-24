-- 013_ops_features.sql — operational-feature schema additions:
--   • projects.archived_at  (OP15 soft-archive)
--   • saved_analog_searches (OP4 saved analog filter configs)
--   • saved_comparisons     (OP5 saved A/B snapshots)
--   • activity_log          (OP9 activity feed)
--   • area_tags             (OP21 org-wide tags — m:n with areas)
--   • scheduled_reports     (OP13 scheduled emailed reports)
--
-- All ops are guarded against re-running so a partial deploy can resume.

-- OP15 — soft-archive timestamp on projects (NULL = active).
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects'
              AND COLUMN_NAME = 'archived_at');
SET @sql := IF(@c = 0,
  'ALTER TABLE projects ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER updated_at',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- OP4 — per-user saved analog-finder configurations.
CREATE TABLE IF NOT EXISTS saved_analog_searches (
  id            CHAR(36)     PRIMARY KEY,
  user_id       CHAR(36)     NOT NULL,
  organization_id CHAR(36)   NOT NULL,
  name          VARCHAR(120) NOT NULL,
  source_area_id CHAR(36)    NULL,
  config_json   JSON         NOT NULL,
  created_at    DATETIME     NOT NULL,
  INDEX idx_sas_org (organization_id),
  INDEX idx_sas_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OP5 — saved comparison snapshots.
CREATE TABLE IF NOT EXISTS saved_comparisons (
  id            CHAR(36)     PRIMARY KEY,
  user_id       CHAR(36)     NOT NULL,
  organization_id CHAR(36)   NOT NULL,
  name          VARCHAR(120) NOT NULL,
  area_ids_json JSON         NOT NULL,
  created_at    DATETIME     NOT NULL,
  INDEX idx_sc_org (organization_id),
  INDEX idx_sc_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OP9 — append-only activity log for the bell-icon dropdown.
CREATE TABLE IF NOT EXISTS activity_log (
  id              BIGINT       AUTO_INCREMENT PRIMARY KEY,
  organization_id CHAR(36)     NOT NULL,
  actor_user_id   CHAR(36)     NULL,
  actor_name      VARCHAR(120) NULL,
  action          VARCHAR(60)  NOT NULL,
  subject_type    VARCHAR(60)  NULL,
  subject_id      VARCHAR(80)  NULL,
  subject_name    VARCHAR(255) NULL,
  meta_json       JSON         NULL,
  created_at      DATETIME     NOT NULL,
  INDEX idx_act_org_time (organization_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OP21 — org-wide tags + many-to-many to areas.
CREATE TABLE IF NOT EXISTS tags (
  id              CHAR(36)     PRIMARY KEY,
  organization_id CHAR(36)     NOT NULL,
  name            VARCHAR(60)  NOT NULL,
  color           VARCHAR(20)  NULL,
  created_at      DATETIME     NOT NULL,
  UNIQUE KEY uq_tag_org_name (organization_id, name),
  INDEX idx_tag_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS area_tags (
  area_id  CHAR(36) NOT NULL,
  tag_id   CHAR(36) NOT NULL,
  PRIMARY KEY (area_id, tag_id),
  INDEX idx_area_tag_tag (tag_id),
  CONSTRAINT fk_at_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE CASCADE,
  CONSTRAINT fk_at_tag  FOREIGN KEY (tag_id) REFERENCES tags(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OP13 — scheduled report emails. A cron worker reads `next_run_at`,
-- generates the report, emails it, then bumps next_run_at by frequency.
CREATE TABLE IF NOT EXISTS scheduled_reports (
  id              CHAR(36)     PRIMARY KEY,
  organization_id CHAR(36)     NOT NULL,
  user_id         CHAR(36)     NOT NULL,
  area_id         CHAR(36)     NULL,
  project_id      CHAR(36)     NULL,
  frequency       ENUM('daily','weekly','monthly') NOT NULL,
  recipient_email VARCHAR(255) NOT NULL,
  next_run_at     DATETIME     NOT NULL,
  last_run_at     DATETIME     NULL,
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL,
  INDEX idx_sr_next (next_run_at, active),
  INDEX idx_sr_org  (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
