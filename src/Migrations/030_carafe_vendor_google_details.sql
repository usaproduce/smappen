-- 030_carafe_vendor_google_details.sql — Carafe Vendor Network Spec v3
-- §3.3 (full Place Details storage) + §12.1 (three-tier volatility cache)
-- + §12.6 (idempotent upserts) + §12.2 (Placekey shortcut).
--
-- Adds the storage layer authorized by the written Google grant
-- (config/google_places_grant.php). The grant_flag still gates write
-- behavior in PHP — this migration is schema-only.
--
-- Three pieces:
--   1. vendors.placekey + vendor_locations.google_place_id — the
--      natural keys that make §12.6 upserts safe (INSERT ... ON
--      DUPLICATE KEY UPDATE). UNIQUE on google_place_id is the join
--      key from a Place ID back to a vendor.
--   2. vendor_google_details — one row per place_id, full payload
--      mirror, per-tier fetched_at timestamps that drive §12.1's
--      tier-aware refresh.
--   3. vendor_google_reviews / vendor_google_photos — child tables.
--      Photos store the resource NAME only; binary fetch is a
--      separate billable call (Places Photo SKU).
--
-- Idempotent (INFORMATION_SCHEMA-guarded ALTERs + CREATE TABLE IF
-- NOT EXISTS).

-- ───────────────────────────────────────────────────────────────────────────
-- vendors.placekey — cross-source canonical id for §12.2 dedupe shortcut.
-- ───────────────────────────────────────────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'placekey');
SET @s := IF(@col = 0,
    'ALTER TABLE vendors ADD COLUMN placekey VARCHAR(40) NULL AFTER name',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND INDEX_NAME = 'idx_vendor_placekey');
SET @s := IF(@idx = 0,
    'ALTER TABLE vendors ADD INDEX idx_vendor_placekey (placekey)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_locations.google_place_id — the upsert natural key. NULL allowed
-- for manual / non-Google sources; UNIQUE means non-NULL place_ids
-- can never duplicate (InnoDB allows multiple NULL rows in a UNIQUE).
-- ───────────────────────────────────────────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND COLUMN_NAME = 'google_place_id');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_locations ADD COLUMN google_place_id VARCHAR(120) NULL AFTER address',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @uk := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND INDEX_NAME = 'uk_vloc_place');
SET @s := IF(@uk = 0,
    'ALTER TABLE vendor_locations ADD UNIQUE KEY uk_vloc_place (google_place_id)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_google_details — full Place Details payload per vendor.
-- UNIQUE(google_place_id) makes this the §12.6 upsert target.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vendor_google_details (
  id                          CHAR(36)        PRIMARY KEY,
  vendor_id                   CHAR(36)        NOT NULL,
  google_place_id             VARCHAR(120)    NOT NULL,

  -- Contact (Places SKU: Contact add-on)
  national_phone              VARCHAR(40)     NULL,
  international_phone         VARCHAR(40)     NULL,
  website_uri                 VARCHAR(500)    NULL,
  google_maps_uri             VARCHAR(500)    NULL,

  -- Atmosphere (Places SKU: Atmosphere add-on)
  rating                      DECIMAL(3,2)    NULL,
  user_rating_count           INT UNSIGNED    NULL,
  price_level                 ENUM('PRICE_LEVEL_FREE','PRICE_LEVEL_INEXPENSIVE','PRICE_LEVEL_MODERATE','PRICE_LEVEL_EXPENSIVE','PRICE_LEVEL_VERY_EXPENSIVE') NULL,
  price_range_json            JSON            NULL,

  -- Status / type
  business_status             ENUM('OPERATIONAL','CLOSED_TEMPORARILY','CLOSED_PERMANENTLY') NULL,
  primary_type                VARCHAR(80)     NULL,
  primary_type_display        VARCHAR(160)    NULL,
  types_json                  JSON            NULL,

  -- Hours
  regular_hours_json          JSON            NULL,
  current_opening_hours_json  JSON            NULL,
  secondary_hours_json        JSON            NULL,
  utc_offset_minutes          SMALLINT        NULL,

  -- Address detail
  short_formatted_address     VARCHAR(255)    NULL,
  address_components_json     JSON            NULL,
  postal_address_json         JSON            NULL,

  -- Attributes (booleans only — JSON for richer payment/parking sets)
  delivery                    TINYINT(1)      NULL,
  takeout                     TINYINT(1)      NULL,
  curbside_pickup             TINYINT(1)      NULL,
  dine_in                     TINYINT(1)      NULL,
  payment_options_json        JSON            NULL,
  parking_options_json        JSON            NULL,
  accessibility_json          JSON            NULL,

  -- Raw verbatim payload — future-proof against schema additions.
  -- §3.3: "persist the raw_payload_json verbatim. This means future
  -- fields require no re-pull — you query the JSON."
  raw_payload_json            JSON            NULL,

  -- Cost accounting on this row's most recent enrich
  field_mask_used             VARCHAR(2000)   NULL,
  sku_cost_usd                DECIMAL(10,6)   NOT NULL DEFAULT 0,

  -- Per-tier cache timestamps (§12.1). The nightly refresh inspects
  -- each tier's TTL and re-pulls only the expired tier's field set.
  cold_fetched_at             DATETIME        NULL,
  warm_fetched_at             DATETIME        NULL,
  hot_fetched_at              DATETIME        NULL,

  created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_vgd_place (google_place_id),
  INDEX idx_vgd_vendor (vendor_id),
  INDEX idx_vgd_business_status (business_status),
  -- Refresh worker scans by tier expiry. Composite (status, tier_at)
  -- lets it pick OPERATIONAL rows whose hot tier needs a re-pull
  -- in O(log n).
  INDEX idx_vgd_hot_refresh (business_status, hot_fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_google_reviews — up to 5 per place; ordered by publish_time DESC
-- as Google returns. UNIQUE on (place_id, author_uri, publish_time) keeps
-- re-enriches idempotent.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vendor_google_reviews (
  id                  CHAR(36)        PRIMARY KEY,
  vendor_id           CHAR(36)        NOT NULL,
  google_place_id     VARCHAR(120)    NOT NULL,
  author_name         VARCHAR(160)    NULL,
  author_uri          VARCHAR(500)    NULL,
  author_photo_uri    VARCHAR(500)    NULL,
  rating              TINYINT UNSIGNED NULL,
  text_body           TEXT            NULL,
  language_code       VARCHAR(8)      NULL,
  relative_time_desc  VARCHAR(80)     NULL,
  publish_time        DATETIME        NULL,
  fetched_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vgr_place (google_place_id),
  INDEX idx_vgr_vendor (vendor_id),
  UNIQUE KEY uk_vgr_natural (google_place_id, author_uri, publish_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_google_photos — photo references only (binary fetch is its own
-- billable call). UNIQUE on photo_name = idempotent re-enrich.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vendor_google_photos (
  id                  CHAR(36)        PRIMARY KEY,
  vendor_id           CHAR(36)        NOT NULL,
  google_place_id     VARCHAR(120)    NOT NULL,
  photo_name          VARCHAR(255)    NOT NULL,        -- "places/X/photos/Y" — opaque resource name
  width_px            INT UNSIGNED    NULL,
  height_px           INT UNSIGNED    NULL,
  author_attributions_json JSON       NULL,
  fetched_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vgph_place (google_place_id),
  INDEX idx_vgph_vendor (vendor_id),
  UNIQUE KEY uk_vgph_name (photo_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
