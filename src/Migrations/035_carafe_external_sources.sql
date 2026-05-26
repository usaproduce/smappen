-- 035_carafe_external_sources.sql — Carafe v3 §2 + §9 step 10.
--
-- Non-Google adapters (OSM, Foursquare, chain scrapers) need:
--   1. Their source values added to vendor_sources.source ENUM so the
--      provenance ledger can record them.
--   2. External-id columns on vendor_locations so cross-source rows
--      can be matched/deduped without a fuzzy round-trip.
--
-- OSM's id format is "{type}/{id}" where type ∈ {node,way,relation} —
-- preserve the prefix so the lookup is unambiguous when the same
-- numeric id exists across the three OSM tables.
--
-- Foursquare's fsq_id is opaque base16 (24 chars).
--
-- Idempotent ALTERs guarded by INFORMATION_SCHEMA.

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_sources.source — extend ENUM with 'osm' and 'foursquare'.
-- Check via COLUMN_TYPE string so a partial-state re-run is a no-op.
-- ───────────────────────────────────────────────────────────────────────────
SET @needs_extend := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'vendor_sources'
                        AND COLUMN_NAME = 'source'
                        AND COLUMN_TYPE NOT LIKE '%osm%');
SET @s := IF(@needs_extend = 1,
    "ALTER TABLE vendor_sources MODIFY COLUMN source ENUM('public_web','usda','operator_review','vendor_claimed','affiliated','chain_seed','places','manual','osm','foursquare') NOT NULL",
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_locations.source — same extension so the location's origin
-- source label can be 'osm' / 'foursquare' too.
-- ───────────────────────────────────────────────────────────────────────────
SET @needs_extend := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'vendor_locations'
                        AND COLUMN_NAME = 'source'
                        AND COLUMN_TYPE NOT LIKE '%osm%');
SET @s := IF(@needs_extend = 1,
    "ALTER TABLE vendor_locations MODIFY COLUMN source ENUM('manual','public_directory','places','chain_seed','vendor_claimed','osm','foursquare') NOT NULL",
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_locations external-id columns. UNIQUE where present so re-runs
-- upsert cleanly; NULL allowed for non-matched sources.
-- ───────────────────────────────────────────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND COLUMN_NAME = 'osm_id');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_locations ADD COLUMN osm_id VARCHAR(40) NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @uk := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND INDEX_NAME = 'uk_vloc_osm');
SET @s := IF(@uk = 0,
    'ALTER TABLE vendor_locations ADD UNIQUE KEY uk_vloc_osm (osm_id)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND COLUMN_NAME = 'foursquare_fsq_id');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_locations ADD COLUMN foursquare_fsq_id VARCHAR(40) NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @uk := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND INDEX_NAME = 'uk_vloc_fsq');
SET @s := IF(@uk = 0,
    'ALTER TABLE vendor_locations ADD UNIQUE KEY uk_vloc_fsq (foursquare_fsq_id)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
