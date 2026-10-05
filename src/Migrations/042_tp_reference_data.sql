-- 042_tp_reference_data.sql - Truck Planner shared reference tables.
--
-- tp_regions, tp_points, tp_places, tp_region_packs, tp_fuel_prices.
-- Not organization-scoped. Written by scripts/truck/load-region.php and the fuel price client.
-- Idempotent: CREATE TABLE IF NOT EXISTS only. No spatial columns, no foreign keys.
-- Splitter rule: statement-ending semicolons at the end of a line, none inside comments.

CREATE TABLE IF NOT EXISTS tp_regions (
  region_id         VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name              VARCHAR(120)  NOT NULL,
  cbsa              VARCHAR(5)    CHARACTER SET ascii COLLATE ascii_bin NULL,
  timezone          VARCHAR(60)   NOT NULL,
  h3_res            TINYINT UNSIGNED NOT NULL,
  bbox_lat_min      DOUBLE        NOT NULL,
  bbox_lng_min      DOUBLE        NOT NULL,
  bbox_lat_max      DOUBLE        NOT NULL,
  bbox_lng_max      DOUBLE        NOT NULL,
  center_lat        DOUBLE        NOT NULL,
  center_lng        DOUBLE        NOT NULL,
  active_version    VARCHAR(48)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  previous_version  VARCHAR(48)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  config_json       JSON          NOT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tp_points (
  region_id         VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  dataset_version   VARCHAR(48)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  point_id          VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  src_kind          VARCHAR(8)    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  src_ref           VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  in_region         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  job_adj           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lat               DOUBLE        NOT NULL,
  lng               DOUBLE        NOT NULL,
  b_res DOUBLE NOT NULL DEFAULT 0,           b_w_office DOUBLE NOT NULL DEFAULT 0,
  b_w_health DOUBLE NOT NULL DEFAULT 0,      b_w_edu DOUBLE NOT NULL DEFAULT 0,
  b_w_retail DOUBLE NOT NULL DEFAULT 0,      b_w_industrial DOUBLE NOT NULL DEFAULT 0,
  b_w_hospitality DOUBLE NOT NULL DEFAULT 0, b_w_public DOUBLE NOT NULL DEFAULT 0,
  b_v_nightlife DOUBLE NOT NULL DEFAULT 0,   b_v_shopping DOUBLE NOT NULL DEFAULT 0,
  b_v_leisure DOUBLE NOT NULL DEFAULT 0,     b_v_campus DOUBLE NOT NULL DEFAULT 0,
  b_v_hospital DOUBLE NOT NULL DEFAULT 0,    b_v_transit DOUBLE NOT NULL DEFAULT 0,
  b_v_events DOUBLE NOT NULL DEFAULT 0,      b_v_lodging DOUBLE NOT NULL DEFAULT 0,
  rivals_day DOUBLE NOT NULL DEFAULT 0,      rivals_eve DOUBLE NOT NULL DEFAULT 0,
  PRIMARY KEY (region_id, dataset_version, point_id),
  KEY idx_tpp_geo (region_id, dataset_version, lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every row of tp_places is derived from OpenStreetMap (ODbL). No other source may be merged into this table.
-- host_vec is the location vector of a possible host: 50 little-endian IEEE-754 doubles, written by the loader.
CREATE TABLE IF NOT EXISTS tp_places (
  region_id         VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  dataset_version   VARCHAR(48)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  place_key         VARCHAR(20)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  osm_type          VARCHAR(8)    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  osm_id            BIGINT UNSIGNED NOT NULL,
  snapshot_date     DATE          NOT NULL,
  place_type        VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  geom_kind         VARCHAR(8)    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  in_region         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  county_fips       VARCHAR(5)    CHARACTER SET ascii COLLATE ascii_bin NULL,
  name              VARCHAR(160)  NULL,
  brand             VARCHAR(120)  NULL,
  lat               DOUBLE        NOT NULL,
  lng               DOUBLE        NOT NULL,
  rival_kind        VARCHAR(12)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  visitor_segment   VARCHAR(16)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  size_default      DOUBLE        NOT NULL DEFAULT 0,
  host_fit          DOUBLE        NOT NULL DEFAULT 0,
  kitchen           VARCHAR(8)    CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unknown',
  phone             VARCHAR(16)   NULL,
  website           VARCHAR(255)  NULL,
  addr_line         VARCHAR(200)  NULL,
  city              VARCHAR(80)   NULL,
  state_code        VARCHAR(2)    NULL,
  postcode          VARCHAR(5)    NULL,
  cuisine           VARCHAR(120)  NULL,
  opening_hours_raw VARCHAR(255)  NULL,
  hours_mask        VARCHAR(42)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  tags_json         JSON          NULL,
  host_vec          VARBINARY(400) NULL,
  PRIMARY KEY (region_id, dataset_version, place_key),
  KEY idx_tpl_geo (region_id, dataset_version, lat, lng),
  KEY idx_tpl_type (region_id, dataset_version, place_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per loaded dataset version of a region. It is the dataset ledger and it holds the cell pack.
CREATE TABLE IF NOT EXISTS tp_region_packs (
  region_id         VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  dataset_version   VARCHAR(48)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  load_state        VARCHAR(12)   NOT NULL DEFAULT 'loading',
  model_version     VARCHAR(24)   NOT NULL,
  pipeline_version  VARCHAR(24)   NOT NULL,
  osm_snapshot      DATE          NULL,
  point_count       INT UNSIGNED  NOT NULL DEFAULT 0,
  place_count       INT UNSIGNED  NOT NULL DEFAULT 0,
  cell_count        INT UNSIGNED  NOT NULL DEFAULT 0,
  pack_format       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  pack_len          INT UNSIGNED  NOT NULL DEFAULT 0,
  pack_gz_len       INT UNSIGNED  NOT NULL DEFAULT 0,
  pack_sha256       VARCHAR(64)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  pack_gz           LONGBLOB      NULL,
  kernel_json       JSON          NULL,
  manifest_json     JSON          NULL,
  loaded_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at      DATETIME      NULL,
  PRIMARY KEY (region_id, dataset_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weekly EIA retail price per area and product. price_milli is dollars per gallon times 1000 (4.195 is stored as 4195).
CREATE TABLE IF NOT EXISTS tp_fuel_prices (
  duoarea           VARCHAR(8)    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  product           VARCHAR(12)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  period            DATE          NOT NULL,
  price_milli       INT UNSIGNED  NOT NULL,
  series_id         VARCHAR(40)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  src               VARCHAR(12)   NOT NULL DEFAULT 'eia',
  fetched_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (duoarea, product, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
