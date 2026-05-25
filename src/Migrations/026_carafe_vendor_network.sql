-- 026_carafe_vendor_network.sql — Vendor Network spec §7 data model.
--
-- Extends the vendors table from migration 024 with multi-location support,
-- coverage geometry, multi-category, and a provenance ledger. Spatial
-- indexes on geometry columns so the "drop a pin → who serves me"
-- point-in-polygon query scales nationally.
--
-- All ops idempotent (CREATE TABLE IF NOT EXISTS + INFORMATION_SCHEMA-
-- guarded ALTERs).
--
-- Spatial convention: SRID 4326 with the project's (lat lng) axis order
-- — same as `areas.geometry`. WKT "POINT(lat lng)" / "POLYGON((lat lng, ...))".

-- ───────────────────────────────────────────────────────────────────────────
-- vendors — additive columns for type/brand/score/rating aggregate
-- ───────────────────────────────────────────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'type');
SET @s := IF(@col = 0,
    "ALTER TABLE vendors ADD COLUMN type ENUM('broadline','warehouse','produce','protein','seafood','specialty','grocery','bakery_dairy_beverage') NULL AFTER primary_category",
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'brand');
SET @s := IF(@col = 0,
    'ALTER TABLE vendors ADD COLUMN brand VARCHAR(120) NULL AFTER name',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'completeness_score');
SET @s := IF(@col = 0,
    'ALTER TABLE vendors ADD COLUMN completeness_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER claim_status',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'aggregate_rating');
SET @s := IF(@col = 0,
    'ALTER TABLE vendors ADD COLUMN aggregate_rating DECIMAL(3,2) NULL AFTER completeness_score',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'rating_count');
SET @s := IF(@col = 0,
    'ALTER TABLE vendors ADD COLUMN rating_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER aggregate_rating',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'last_verified_at');
SET @s := IF(@col = 0,
    'ALTER TABLE vendors ADD COLUMN last_verified_at DATETIME NULL AFTER rating_count',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_locations — one vendor → many geocoded branches.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vendor_locations (
  id              CHAR(36)        PRIMARY KEY,
  vendor_id       CHAR(36)        NOT NULL,
  label           VARCHAR(160)    NULL,            -- "Newark NJ", "Bronx Hunts Point"
  address         VARCHAR(255)    NULL,
  lat             DOUBLE          NOT NULL,
  lng             DOUBLE          NOT NULL,
  pt              POINT           NOT NULL /*!80003 SRID 4326*/,
  phone           VARCHAR(40)     NULL,
  is_primary      TINYINT(1)      NOT NULL DEFAULT 0,
  source          ENUM('manual','public_directory','places','chain_seed','vendor_claimed') NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_vloc_vendor (vendor_id, is_primary DESC),
  SPATIAL INDEX idx_vloc_pt (pt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_coverage — geometry of where a location actually reaches.
-- coverage_type:
--   delivery          → polygon of delivery zone
--   pickup_drivetime  → isochrone around the location (will-call / warehouse)
--   declared_territory→ self-reported polygon (lower confidence)
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vendor_coverage (
  id              CHAR(36)        PRIMARY KEY,
  vendor_id       CHAR(36)        NOT NULL,
  location_id     CHAR(36)        NOT NULL,
  coverage_type   ENUM('delivery','pickup_drivetime','declared_territory','radius') NOT NULL,
  geom            POLYGON         NOT NULL /*!80003 SRID 4326*/,
  travel_mode     VARCHAR(40)     NULL,            -- 'driving-car' when pickup_drivetime
  travel_minutes  INT UNSIGNED    NULL,
  radius_miles    DECIMAL(6,2)    NULL,
  confidence      TINYINT UNSIGNED NOT NULL DEFAULT 50, -- 0..100
  source          ENUM('vendor_claimed','operator_review','ors_isochrone','radius_fallback','public_directory') NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_vcov_vendor (vendor_id),
  INDEX idx_vcov_location (location_id),
  SPATIAL INDEX idx_vcov_geom (geom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_categories — many-to-many vendor ↔ category.
-- (vendors.primary_category remains for the "headline" category; this
-- table captures the full set the vendor actually serves.)
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vendor_categories (
  id              CHAR(36)        PRIMARY KEY,
  vendor_id       CHAR(36)        NOT NULL,
  category        ENUM('produce','meat','poultry','seafood','dairy','dry_goods','frozen','bakery','beverage','paper_disposables','cleaning_chemical','specialty_imported') NOT NULL,
  source          ENUM('public_directory','vendor_claimed','operator_review','chain_seed','manual') NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_vcat (vendor_id, category),
  INDEX idx_vcat_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_sources — provenance ledger. Backs the DataFreshnessFooter and
-- the legal gate (parent spec §6.1). Per-field, so the UI can show "name
-- from public_directory, coverage from operator_review, last_verified 5d
-- ago".
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vendor_sources (
  id              CHAR(36)        PRIMARY KEY,
  vendor_id       CHAR(36)        NOT NULL,
  field_name      VARCHAR(60)     NOT NULL,        -- 'name','phone','website','coverage','category'
  source          ENUM('public_web','usda','operator_review','vendor_claimed','affiliated','chain_seed','places','manual') NOT NULL,
  source_ref      VARCHAR(255)    NULL,            -- URL / dataset id / review_id
  verified_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vsrc_vendor (vendor_id, field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- Backfill: existing vendors with hq_lat/hq_lng → seed vendor_locations.
-- Idempotent: only inserts when the vendor has no location yet.
-- ───────────────────────────────────────────────────────────────────────────
INSERT INTO vendor_locations (id, vendor_id, label, address, lat, lng, pt, is_primary, source, created_at, updated_at)
SELECT
  UUID() AS id,
  v.id   AS vendor_id,
  CONCAT(v.name, ' HQ') AS label,
  v.hq_address AS address,
  v.hq_lat AS lat,
  v.hq_lng AS lng,
  ST_GeomFromText(CONCAT('POINT(', v.hq_lat, ' ', v.hq_lng, ')'), 4326) AS pt,
  1 AS is_primary,
  CASE
    WHEN v.source = 'greendock_affiliate' THEN 'manual'
    WHEN v.source = 'public_directory'    THEN 'public_directory'
    ELSE 'manual'
  END AS source,
  NOW(), NOW()
FROM vendors v
WHERE v.hq_lat IS NOT NULL
  AND v.hq_lng IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM vendor_locations vl WHERE vl.vendor_id = v.id);
