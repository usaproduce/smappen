-- 032_carafe_dedupe_and_geocode.sql — Carafe v3 §12.2 (dedupe pipeline)
-- + §4.3 (block → match → cluster) + §12.4 (batched geocode).
--
-- Two purposes:
--
--   1. Block-key columns on vendor_locations so the dedupe blocker
--      can find candidate pairs via index lookup rather than n²
--      cross-product. Spec §12.2: "blocking removes ~99.9% of pairs
--      before comparison." Three keys: zip5+name_prefix, state+soundex,
--      geohash-6.
--
--   2. vendor_dedupe_pairs — one row per candidate pair the matcher
--      scored, with the auto_merge | review | reject decision. The
--      apply-merge step (later) reads this; the review queue UI (later
--      phases) reads decision='review'.
--
-- All operations idempotent. The block-key columns are populated lazily
-- by VendorDedupeService::assignBlockKeys() — this migration only adds
-- the columns + indexes.

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_locations — block-key columns + their indexes.
-- ───────────────────────────────────────────────────────────────────────────

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND COLUMN_NAME = 'zip5');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_locations ADD COLUMN zip5 CHAR(5) NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND COLUMN_NAME = 'state_code');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_locations ADD COLUMN state_code CHAR(2) NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND COLUMN_NAME = 'name_soundex');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_locations ADD COLUMN name_soundex CHAR(4) NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- Three normalized name-prefix chars — the first half of block key 1.
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND COLUMN_NAME = 'name_prefix3');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_locations ADD COLUMN name_prefix3 CHAR(3) NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- 6-char base32 geohash — ~1.2km cell at the equator. Block key 3.
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND COLUMN_NAME = 'geohash6');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_locations ADD COLUMN geohash6 CHAR(6) NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- Placekey (cross-source canonical id) — spec §12.2 shortcut. Same key
-- value = same place at zero matching cost. Nullable since most sources
-- (Google Places, manual) don't emit it.
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND COLUMN_NAME = 'placekey');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_locations ADD COLUMN placekey VARCHAR(40) NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- Whether dedupe has run for this row yet. Lets the worker pick up only
-- new vendor_locations on incremental dedupe sweeps.
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND COLUMN_NAME = 'dedupe_scanned_at');
SET @s := IF(@col = 0,
    'ALTER TABLE vendor_locations ADD COLUMN dedupe_scanned_at DATETIME NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- Indexes. Each block key gets its own to drive O(log n) candidate
-- enumeration. Placekey too (for the §12.2 shortcut lookup).
SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND INDEX_NAME = 'idx_vloc_block_zip_name');
SET @s := IF(@idx = 0,
    'ALTER TABLE vendor_locations ADD INDEX idx_vloc_block_zip_name (zip5, name_prefix3)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND INDEX_NAME = 'idx_vloc_block_state_soundex');
SET @s := IF(@idx = 0,
    'ALTER TABLE vendor_locations ADD INDEX idx_vloc_block_state_soundex (state_code, name_soundex)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND INDEX_NAME = 'idx_vloc_block_geohash6');
SET @s := IF(@idx = 0,
    'ALTER TABLE vendor_locations ADD INDEX idx_vloc_block_geohash6 (geohash6)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND INDEX_NAME = 'idx_vloc_placekey');
SET @s := IF(@idx = 0,
    'ALTER TABLE vendor_locations ADD INDEX idx_vloc_placekey (placekey)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_locations' AND INDEX_NAME = 'idx_vloc_dedupe_scan');
SET @s := IF(@idx = 0,
    'ALTER TABLE vendor_locations ADD INDEX idx_vloc_dedupe_scan (dedupe_scanned_at)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_dedupe_pairs — one row per candidate pair the matcher scored.
-- The order is canonicalized (left_vendor_id < right_vendor_id) so the
-- UNIQUE key catches duplicate-pair attempts on re-runs.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vendor_dedupe_pairs (
  id                  CHAR(36)        PRIMARY KEY,
  left_vendor_id      CHAR(36)        NOT NULL,
  right_vendor_id     CHAR(36)        NOT NULL,
  left_location_id    CHAR(36)        NULL,
  right_location_id   CHAR(36)        NULL,
  score               DECIMAL(5,4)    NOT NULL,
  distance_m          DECIMAL(10,2)   NULL,
  shared_name_tokens  TINYINT UNSIGNED NULL,
  decision            ENUM('auto_merge','review','reject') NOT NULL,
  block_key_hit       VARCHAR(80)     NULL,             -- which blocking key produced this pair
  reviewed_at         DATETIME        NULL,
  reviewed_by         CHAR(36)        NULL,
  review_outcome      ENUM('merged','rejected','deferred') NULL,
  applied_merge_at    DATETIME        NULL,             -- when the auto/manual merge actually fired
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_pair      (left_vendor_id, right_vendor_id),
  INDEX idx_decision      (decision),
  INDEX idx_score         (score),
  INDEX idx_review_open   (decision, reviewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- vendors.merged_into — pointer to the surviving vendor when this row
-- got merged. NULL = active. Keeps full provenance — the merged row stays
-- on disk for audit + reference reversal.
-- ───────────────────────────────────────────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'merged_into');
SET @s := IF(@col = 0,
    'ALTER TABLE vendors ADD COLUMN merged_into CHAR(36) NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND INDEX_NAME = 'idx_vendor_merged_into');
SET @s := IF(@idx = 0,
    'ALTER TABLE vendors ADD INDEX idx_vendor_merged_into (merged_into)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
