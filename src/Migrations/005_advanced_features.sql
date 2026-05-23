-- 005_advanced_features.sql
-- Tables for the 8 advanced features: territory generation, cannibalization,
-- traffic-aware isochrones, multi-location opt, segmentation, collaboration,
-- mobile field, competitor monitoring.
-- MySQL 8+ spatial; UUIDs stored as CHAR(36); JSON for flexible payloads.

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- Feature 1: Auto territory generation
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS territory_generation_jobs (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    user_id CHAR(36) NULL,
    status ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    method ENUM('k_means_balanced','equal_population','equal_geography') NOT NULL DEFAULT 'k_means_balanced',
    target_count INT NOT NULL DEFAULT 10,
    balance_metric ENUM('population','income_weighted_pop','housing_units') NOT NULL DEFAULT 'population',
    region_bbox JSON NULL,                -- {minLat,minLng,maxLat,maxLng}
    constraints_json JSON NULL,           -- maxImbalancePct, must-include, etc.
    progress_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
    result_summary JSON NULL,             -- per-territory totals
    error_message TEXT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_tgj_project (project_id, status),
    CONSTRAINT fk_tgj_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_tgj_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Areas grow a pointer back to the generation job that created them (nullable).
ALTER TABLE areas
    ADD COLUMN generation_job_id CHAR(36) NULL AFTER created_by,
    ADD COLUMN territory_index INT NULL AFTER generation_job_id,
    ADD INDEX idx_area_gen_job (generation_job_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- Feature 5: Customer segmentation (8–12 named segments derived from Census)
-- One row per tract; segment_id is short kebab-case (e.g. "affluent-suburbs").
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tract_segments (
    geoid VARCHAR(11) NOT NULL PRIMARY KEY,
    segment_id VARCHAR(64) NOT NULL,
    segment_name VARCHAR(120) NOT NULL,
    confidence DOUBLE NOT NULL DEFAULT 1.0,
    features_json JSON NULL,              -- raw scores used to assign
    computed_at DATETIME NOT NULL,
    INDEX idx_ts_segment (segment_id),
    CONSTRAINT fk_ts_tract FOREIGN KEY (geoid) REFERENCES census_tracts(geoid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Feature 6: Collaboration — versioning, comments, change log, ACL, approvals
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS project_versions (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    version_number INT NOT NULL,           -- 1-based, monotonic per project
    snapshot_json LONGTEXT NOT NULL,       -- full project payload (areas + meta)
    note VARCHAR(255) NULL,
    created_by CHAR(36) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_pv_proj_version (project_id, version_number),
    INDEX idx_pv_project (project_id, created_at),
    CONSTRAINT fk_pv_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_pv_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    area_id CHAR(36) NULL,
    parent_comment_id CHAR(36) NULL,
    user_id CHAR(36) NULL,
    body TEXT NOT NULL,
    anchor_lat DOUBLE NULL,
    anchor_lng DOUBLE NULL,
    resolved_at DATETIME NULL,
    resolved_by CHAR(36) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_comments_project (project_id, created_at),
    INDEX idx_comments_area (area_id),
    INDEX idx_comments_parent (parent_comment_id),
    CONSTRAINT fk_comments_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_parent FOREIGN KEY (parent_comment_id) REFERENCES comments(id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_comments_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS change_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    user_id CHAR(36) NULL,
    entity_type VARCHAR(40) NOT NULL,      -- 'area','project','comment',…
    entity_id CHAR(36) NULL,
    action VARCHAR(40) NOT NULL,           -- 'create','update','delete','approve',…
    diff_json JSON NULL,                   -- before/after for update
    created_at DATETIME NOT NULL,
    INDEX idx_cl_project (project_id, created_at),
    INDEX idx_cl_entity (entity_type, entity_id),
    CONSTRAINT fk_cl_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_cl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_collaborators (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    role ENUM('viewer','editor','approver','owner') NOT NULL DEFAULT 'viewer',
    invited_by CHAR(36) NULL,
    invited_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    UNIQUE KEY uniq_pc_project_user (project_id, user_id),
    INDEX idx_pc_user (user_id),
    CONSTRAINT fk_pc_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_inviter FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_requests (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    requested_by CHAR(36) NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    payload_json JSON NULL,                -- proposed change diff
    status ENUM('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
    decided_by CHAR(36) NULL,
    decided_at DATETIME NULL,
    decision_note TEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ar_project (project_id, status),
    CONSTRAINT fk_ar_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_ar_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ar_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Feature 7: Mobile field — geo-stamped notes captured from PWA.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS field_notes (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    area_id CHAR(36) NULL,
    user_id CHAR(36) NULL,
    body TEXT NOT NULL,
    lat DOUBLE NOT NULL,
    lng DOUBLE NOT NULL,
    location POINT NOT NULL SRID 4326,
    accuracy_m DOUBLE NULL,
    photo_url TEXT NULL,
    tags JSON NULL,
    captured_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_fn_project (project_id, captured_at),
    INDEX idx_fn_area (area_id),
    SPATIAL INDEX idx_fn_location (location),
    CONSTRAINT fk_fn_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_fn_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE SET NULL,
    CONSTRAINT fk_fn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Feature 8: Competitor monitoring & alerts
-- A monitor watches a (project, area, place_type) tuple; scans persist
-- snapshots; tracked_places diff across scans; alerts surface new/gone/moved.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS competitor_monitors (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    area_id CHAR(36) NULL,
    name VARCHAR(255) NOT NULL,
    place_types JSON NOT NULL,             -- e.g. ["restaurant","cafe"]
    keywords VARCHAR(255) NULL,
    frequency ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    next_run_at DATETIME NULL,
    created_by CHAR(36) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_cm_project (project_id),
    INDEX idx_cm_next_run (is_active, next_run_at),
    CONSTRAINT fk_cm_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_cm_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE SET NULL,
    CONSTRAINT fk_cm_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS competitor_scans (
    id CHAR(36) PRIMARY KEY,
    monitor_id CHAR(36) NOT NULL,
    place_count INT NOT NULL DEFAULT 0,
    new_count INT NOT NULL DEFAULT 0,
    gone_count INT NOT NULL DEFAULT 0,
    moved_count INT NOT NULL DEFAULT 0,
    rating_change_count INT NOT NULL DEFAULT 0,
    raw_response JSON NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    INDEX idx_cs_monitor (monitor_id, started_at),
    CONSTRAINT fk_cs_monitor FOREIGN KEY (monitor_id) REFERENCES competitor_monitors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tracked_places (
    id CHAR(36) PRIMARY KEY,
    monitor_id CHAR(36) NOT NULL,
    place_id VARCHAR(255) NOT NULL,         -- Google Places place_id
    name VARCHAR(255) NULL,
    lat DOUBLE NULL,
    lng DOUBLE NULL,
    location POINT NULL SRID 4326,
    rating DOUBLE NULL,
    user_ratings_total INT NULL,
    types JSON NULL,
    last_seen_scan_id CHAR(36) NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    is_gone TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_tp_monitor_place (monitor_id, place_id),
    INDEX idx_tp_monitor (monitor_id, is_gone),
    SPATIAL INDEX idx_tp_location (location),
    CONSTRAINT fk_tp_monitor FOREIGN KEY (monitor_id) REFERENCES competitor_monitors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS competitor_alerts (
    id CHAR(36) PRIMARY KEY,
    monitor_id CHAR(36) NOT NULL,
    scan_id CHAR(36) NULL,
    place_id VARCHAR(255) NULL,
    alert_type ENUM('new','gone','moved','rating_drop','rating_jump') NOT NULL,
    severity ENUM('info','warn','high') NOT NULL DEFAULT 'info',
    title VARCHAR(255) NOT NULL,
    detail JSON NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_ca_monitor (monitor_id, created_at),
    INDEX idx_ca_unread (monitor_id, is_read),
    CONSTRAINT fk_ca_monitor FOREIGN KEY (monitor_id) REFERENCES competitor_monitors(id) ON DELETE CASCADE,
    CONSTRAINT fk_ca_scan FOREIGN KEY (scan_id) REFERENCES competitor_scans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Cross-feature: in-app notifications (used by competitor alerts, approvals,
-- comments, completed generation jobs, etc.)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    project_id CHAR(36) NULL,
    notif_type VARCHAR(60) NOT NULL,       -- 'competitor_alert','approval_request',…
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    link_url VARCHAR(500) NULL,
    payload_json JSON NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_notif_user (user_id, is_read, created_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
