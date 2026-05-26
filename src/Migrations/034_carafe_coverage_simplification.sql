-- 034_carafe_coverage_simplification.sql — Carafe v3 §12.5.
--
-- Pre-rendered simplified polygons at three Douglas-Peucker tolerances.
-- The vector-tile pipeline (tippecanoe) reads the coarse column at low
-- zoom levels; the original `geom` column stays the source of truth
-- for the point-in-polygon "who serves me" query.
--
-- Tolerances are in degrees (SRID 4326). Approximate equivalences at
-- mid-latitudes:
--   0.001° ≈ 100 m  → low-zoom city level
--   0.01°  ≈ 1 km   → metro-level
--   0.1°   ≈ 10 km  → state-level
--
-- Columns are GEOMETRY (not POLYGON) because ST_Simplify can return
-- a LINESTRING when a polygon degenerates at high tolerance. No
-- SPATIAL INDEX needed — these columns aren't queried by point-in-
-- polygon; the original `geom` keeps that role.
--
-- Idempotent ALTERs guarded by INFORMATION_SCHEMA.

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_coverage' AND COLUMN_NAME = 'simplified_100m');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_coverage ADD COLUMN simplified_100m GEOMETRY NULL /*!80003 SRID 4326*/',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_coverage' AND COLUMN_NAME = 'simplified_1km');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_coverage ADD COLUMN simplified_1km GEOMETRY NULL /*!80003 SRID 4326*/',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_coverage' AND COLUMN_NAME = 'simplified_10km');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_coverage ADD COLUMN simplified_10km GEOMETRY NULL /*!80003 SRID 4326*/',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_coverage' AND COLUMN_NAME = 'simplified_at');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_coverage ADD COLUMN simplified_at DATETIME NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- Index so the batch simplifier can pick up "not yet simplified" rows fast.
SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_coverage' AND INDEX_NAME = 'idx_vcov_simplified_at');
SET @s := IF(@idx = 0,
    'ALTER TABLE vendor_coverage ADD INDEX idx_vcov_simplified_at (simplified_at)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
