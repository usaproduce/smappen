-- 006_auth_and_jobs.sql — password reset, email verify, JWT revocation,
-- API keys, background jobs, webhook subscriptions, audit improvements.
SET FOREIGN_KEY_CHECKS = 0;

-- Password-reset / email-verify share the same shape; one table with `purpose`.
CREATE TABLE IF NOT EXISTS auth_tokens (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    purpose ENUM('password_reset','email_verify') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_at_user_purpose (user_id, purpose),
    UNIQUE KEY uniq_at_hash (token_hash),
    CONSTRAINT fk_at_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track verified emails on the user row itself.
-- MySQL 8.0.29+ supports IF NOT EXISTS on ADD COLUMN/INDEX so re-running this
-- migration is a no-op instead of erroring on duplicate-column 1060.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER is_active,
    ADD COLUMN IF NOT EXISTS api_key_hash CHAR(64) NULL AFTER email_verified_at,
    ADD COLUMN IF NOT EXISTS api_key_last4 CHAR(4) NULL AFTER api_key_hash,
    ADD INDEX IF NOT EXISTS idx_users_api_key (api_key_hash);

-- JWT revocation list. `jti` is a UUID we embed in the token payload at issue.
-- On logout we insert here; the auth middleware checks every request.
CREATE TABLE IF NOT EXISTS revoked_tokens (
    jti CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NULL,
    revoked_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    reason VARCHAR(60) NULL,
    INDEX idx_rt_expires (expires_at),
    CONSTRAINT fk_rt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Background job queue. One workhorse table that backs territory generation,
-- MCLP, competitor scans, imports, and exports. status transitions are linear:
-- queued → running → done / failed.
CREATE TABLE IF NOT EXISTS jobs (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NULL,
    organization_id CHAR(36) NULL,
    project_id CHAR(36) NULL,
    type VARCHAR(60) NOT NULL,
    payload JSON NULL,
    status ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
    progress_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
    progress_message VARCHAR(255) NULL,
    result JSON NULL,
    error_message TEXT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    available_at DATETIME NOT NULL,
    reserved_at DATETIME NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_jobs_status_avail (status, available_at),
    INDEX idx_jobs_user (user_id, created_at),
    INDEX idx_jobs_project (project_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbound webhooks for API customers (#50).
CREATE TABLE IF NOT EXISTS webhook_subscriptions (
    id CHAR(36) PRIMARY KEY,
    organization_id CHAR(36) NOT NULL,
    created_by CHAR(36) NULL,
    target_url VARCHAR(500) NOT NULL,
    events JSON NOT NULL,                 -- ["competitor.alert","territory.generated",…]
    secret_hash CHAR(64) NOT NULL,        -- HMAC key for signing payloads
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_delivery_at DATETIME NULL,
    last_status_code SMALLINT NULL,
    failure_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_ws_org (organization_id, is_active),
    CONSTRAINT fk_ws_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ws_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id CHAR(36) PRIMARY KEY,
    subscription_id CHAR(36) NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    payload_json JSON NOT NULL,
    status_code SMALLINT NULL,
    response_excerpt TEXT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    delivered_at DATETIME NULL,
    next_retry_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_wd_sub (subscription_id, created_at),
    INDEX idx_wd_retry (next_retry_at),
    CONSTRAINT fk_wd_sub FOREIGN KEY (subscription_id) REFERENCES webhook_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Project sharing — share_token already exists; we add expiry and view counter.
ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS share_expires_at DATETIME NULL AFTER share_token,
    ADD COLUMN IF NOT EXISTS share_view_count INT NOT NULL DEFAULT 0 AFTER share_expires_at;

-- User notification preferences (in-app default, opt-in for email/slack).
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS notify_email TINYINT(1) NOT NULL DEFAULT 1 AFTER api_key_last4,
    ADD COLUMN IF NOT EXISTS notify_competitor_alerts TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_email,
    ADD COLUMN IF NOT EXISTS notify_team_activity TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_competitor_alerts,
    ADD COLUMN IF NOT EXISTS slack_webhook_url VARCHAR(500) NULL AFTER notify_team_activity,
    ADD COLUMN IF NOT EXISTS theme ENUM('light','dark','auto') NOT NULL DEFAULT 'light' AFTER slack_webhook_url;

SET FOREIGN_KEY_CHECKS = 1;
