-- 014_data_scale_features.sql — schema for the "data-depth + scale" batch.
--
-- Covered:
--   #1  tract_features         pre-computed 18-dim fingerprints
--   #2  census_demographics_history  time-series ACS estimates per tract
--   #10 analog_norm_stats      materialized min/max/percentile stats
--   #14 area_permissions / folder_permissions  per-resource ACLs
--
-- All steps idempotent — guarded with INFORMATION_SCHEMA checks so a
-- partial deploy can resume.

-- ─────────────────────────────────────────────────────────────────────────
-- #1 — tract_features. Stored as 18 explicit columns so MySQL can index
-- them (cosine similarity is computed in PHP after a fast bbox pre-filter
-- against the table). Refreshed during Census ingest.
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tract_features (
  geoid                 VARCHAR(11) PRIMARY KEY,
  density_norm          DOUBLE NULL,
  income_norm           DOUBLE NULL,
  home_value_norm       DOUBLE NULL,
  unemployment_norm     DOUBLE NULL,
  pct_under_18          DOUBLE NULL,
  pct_18_34             DOUBLE NULL,
  pct_35_54             DOUBLE NULL,
  pct_55_64             DOUBLE NULL,
  pct_65_plus           DOUBLE NULL,
  pct_income_low        DOUBLE NULL,
  pct_income_high       DOUBLE NULL,
  segment_dominant_norm DOUBLE NULL,
  segment_concentration DOUBLE NULL,
  affluence_index       DOUBLE NULL,
  poi_density_norm      DOUBLE NULL,
  category_diversity    DOUBLE NULL,
  traffic_penalty_norm  DOUBLE NULL,
  reach_population_norm DOUBLE NULL,
  computed_at           DATETIME NOT NULL,
  CONSTRAINT fk_tf_tract FOREIGN KEY (geoid) REFERENCES census_tracts(geoid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
-- #2 — census_demographics_history. Same column shape as
-- census_demographics but with a `data_year` PK component, so we can store
-- ACS estimates per year and chart trend lines.
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS census_demographics_history (
  geoid                    VARCHAR(11) NOT NULL,
  data_year                SMALLINT    NOT NULL,
  total_population         INT         NULL,
  median_household_income  INT         NULL,
  median_home_value        INT         NULL,
  age_under_18             INT         NULL,
  age_18_to_34             INT         NULL,
  age_35_to_54             INT         NULL,
  age_55_to_64             INT         NULL,
  age_65_plus              INT         NULL,
  housing_units_total      INT         NULL,
  ingested_at              DATETIME    NOT NULL,
  PRIMARY KEY (geoid, data_year),
  INDEX idx_cdh_year (data_year),
  CONSTRAINT fk_cdh_tract FOREIGN KEY (geoid) REFERENCES census_tracts(geoid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
-- #10 — analog_norm_stats. Single-row table (latest=1) refreshed nightly.
-- Stores the min/max bounds + a JSON array of sorted-density values for
-- the AnalogService's percentile-rank normalization. Avoids the 84K-row
-- scan on every Analog Finder cold call.
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS analog_norm_stats (
  id              TINYINT      NOT NULL PRIMARY KEY DEFAULT 1,
  density_min     DOUBLE       NULL,
  density_max     DOUBLE       NULL,
  income_min      DOUBLE       NULL,
  income_max      DOUBLE       NULL,
  home_value_min  DOUBLE       NULL,
  home_value_max  DOUBLE       NULL,
  density_values  LONGBLOB     NULL,   -- gzip-encoded JSON array
  computed_at     DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
-- #14 — per-area + per-folder ACLs. Default behavior preserved when no
-- rows exist (org-wide read/write); rows here OVERRIDE the default with
-- explicit role grants.
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS area_permissions (
  area_id    CHAR(36) NOT NULL,
  user_id    CHAR(36) NOT NULL,
  role       ENUM('viewer','editor','owner') NOT NULL DEFAULT 'viewer',
  granted_at DATETIME NOT NULL,
  granted_by CHAR(36) NULL,
  PRIMARY KEY (area_id, user_id),
  INDEX idx_ap_user (user_id),
  CONSTRAINT fk_ap_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS folder_permissions (
  folder_id  CHAR(36) NOT NULL,
  user_id    CHAR(36) NOT NULL,
  role       ENUM('viewer','editor','owner') NOT NULL DEFAULT 'viewer',
  granted_at DATETIME NOT NULL,
  granted_by CHAR(36) NULL,
  PRIMARY KEY (folder_id, user_id),
  INDEX idx_fp_user (user_id),
  CONSTRAINT fk_fp_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
