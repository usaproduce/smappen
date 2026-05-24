-- 015_growth_features.sql — schema additions for the growth & onboarding batch:
--   • organizations.trial_ends_at + .stripe_status  (billing dunning)
--   • users.onboarding_flags (JSON — which one-shot tooltips/wizards seen)
--   • users.use_case          (set during first-run wizard)
--   • activation_metrics      (signup → first_area → first_demographic_view funnel)
--   • alerts                  (expanded beyond competitor scans)
--   • custom_layers           (user-uploaded CSV → derived heatmap)
--   • embeds                  (saved embed configurations + view counters)

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations'
              AND COLUMN_NAME = 'trial_ends_at');
SET @sql := IF(@c = 0,
  'ALTER TABLE organizations ADD COLUMN trial_ends_at DATETIME NULL, ADD COLUMN stripe_status VARCHAR(40) NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
              AND COLUMN_NAME = 'onboarding_flags');
SET @sql := IF(@c = 0,
  'ALTER TABLE users ADD COLUMN onboarding_flags JSON NULL, ADD COLUMN use_case VARCHAR(60) NULL, ADD COLUMN signed_up_at DATETIME NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Activation funnel — one row per user that records the timestamp of
-- each milestone. Used by the dashboard + retention analytics.
CREATE TABLE IF NOT EXISTS activation_metrics (
  user_id              CHAR(36)  PRIMARY KEY,
  organization_id      CHAR(36)  NOT NULL,
  signed_up_at         DATETIME  NOT NULL,
  first_area_at        DATETIME  NULL,
  first_demographic_at DATETIME  NULL,
  first_export_at      DATETIME  NULL,
  first_share_at       DATETIME  NULL,
  first_report_at      DATETIME  NULL,
  returned_in_week_2   TINYINT(1) DEFAULT 0,
  health_score         INT       DEFAULT 0,
  INDEX idx_am_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic alert rules + delivery log. Expands the existing
-- competitor_monitors / competitor_alerts pair to cover more event types.
CREATE TABLE IF NOT EXISTS alerts (
  id              CHAR(36)     PRIMARY KEY,
  organization_id CHAR(36)     NOT NULL,
  user_id         CHAR(36)     NOT NULL,
  area_id         CHAR(36)     NULL,
  kind            VARCHAR(60)  NOT NULL,                 -- 'competitor_new', 'demographics_changed', 'ai_score_drop', 'metric_threshold'
  config_json     JSON         NOT NULL,
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  last_fired_at   DATETIME     NULL,
  fire_count      INT          NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL,
  INDEX idx_alerts_org_active (organization_id, active),
  INDEX idx_alerts_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_deliveries (
  id          BIGINT     AUTO_INCREMENT PRIMARY KEY,
  alert_id    CHAR(36)   NOT NULL,
  fired_at    DATETIME   NOT NULL,
  payload_json JSON      NOT NULL,
  email_sent  TINYINT(1) DEFAULT 0,
  slack_sent  TINYINT(1) DEFAULT 0,
  read_at     DATETIME   NULL,
  INDEX idx_ad_alert (alert_id),
  INDEX idx_ad_time (fired_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom data layers — uploaded customer points + derived heatmap config.
CREATE TABLE IF NOT EXISTS custom_layers (
  id              CHAR(36)     PRIMARY KEY,
  organization_id CHAR(36)     NOT NULL,
  project_id      CHAR(36)     NOT NULL,
  name            VARCHAR(120) NOT NULL,
  kind            VARCHAR(40)  NOT NULL DEFAULT 'point',  -- 'point' | 'heatmap'
  source_import_batch CHAR(36) NULL,                       -- joins to import_batches
  metric_column   VARCHAR(80)  NULL,                       -- which CSV column to use as the metric
  palette_id      VARCHAR(40)  NULL,
  radius_meters   INT          NULL,
  visible         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL,
  INDEX idx_cl_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Embed builder — saved iframe configurations + view counters.
CREATE TABLE IF NOT EXISTS embeds (
  id              CHAR(36)     PRIMARY KEY,
  organization_id CHAR(36)     NOT NULL,
  project_id      CHAR(36)     NOT NULL,
  embed_token     VARCHAR(64)  NOT NULL UNIQUE,
  config_json     JSON         NOT NULL,
  view_count      INT          NOT NULL DEFAULT 0,
  show_branding   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL,
  INDEX idx_embeds_project (project_id),
  INDEX idx_embeds_token (embed_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample project flag — when set, /api/projects/sample/clone copies the
-- whole project (folders + areas + cached demographics) into the user's
-- workspace as a starter.
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects'
              AND COLUMN_NAME = 'is_sample');
SET @sql := IF(@c = 0,
  'ALTER TABLE projects ADD COLUMN is_sample TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Canadian demographics — parallel to US `demographics_cache` + `tracts`.
-- Keyed on Dissemination Area UID (8 digits). Boundary polygons come from
-- StatCan 2021 Census shapefiles; profile data from the WDS API.
CREATE TABLE IF NOT EXISTS da_boundaries_ca (
  da_uid     CHAR(8)  PRIMARY KEY,
  prov_code  CHAR(2)  NOT NULL,
  prov_name  VARCHAR(40) NOT NULL,
  cma_uid    CHAR(3)  NULL,
  cma_name   VARCHAR(120) NULL,
  population INT      NULL,
  area_sqkm  DECIMAL(10,3) NULL,
  geometry   GEOMETRY NOT NULL SRID 4326,
  centroid   POINT    NOT NULL SRID 4326,
  SPATIAL INDEX idx_da_geom (geometry),
  INDEX idx_da_prov (prov_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demographics_cache_ca (
  da_uid       CHAR(8)  NOT NULL,
  year         SMALLINT NOT NULL,
  profile_json JSON     NOT NULL,
  cached_at    DATETIME NOT NULL,
  PRIMARY KEY (da_uid, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Time-series demographics — annual ACS 5-year snapshots, keyed on GEOID +
-- vintage year. v1 ingests 2018-2023 (the last 6 ACS vintages), enabling
-- a Trends sub-tab in the demographics panel.
CREATE TABLE IF NOT EXISTS demographics_history (
  geoid        VARCHAR(15) NOT NULL,
  vintage_year SMALLINT    NOT NULL,
  profile_json JSON        NOT NULL,
  cached_at    DATETIME    NOT NULL,
  PRIMARY KEY (geoid, vintage_year),
  INDEX idx_dh_year (vintage_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CRM integration tokens. Tokens are at-rest encrypted using openssl_encrypt
-- with the APP_KEY env (32-byte). One row per (organization_id, provider).
-- Provider in ('salesforce','hubspot'). `meta_json` stores per-provider
-- bits like SF `instance_url`, HubSpot `hub_id`, granted scopes, etc.
CREATE TABLE IF NOT EXISTS integrations (
  id                CHAR(36)     PRIMARY KEY,
  organization_id   CHAR(36)     NOT NULL,
  provider          VARCHAR(40)  NOT NULL,
  access_token_enc  TEXT         NOT NULL,
  refresh_token_enc TEXT         NULL,
  token_iv          VARCHAR(32)  NOT NULL,
  expires_at        DATETIME     NULL,
  meta_json         JSON         NULL,
  connected_at      DATETIME     NOT NULL,
  last_used_at      DATETIME     NULL,
  UNIQUE KEY uk_int_org_prov (organization_id, provider),
  INDEX idx_int_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
