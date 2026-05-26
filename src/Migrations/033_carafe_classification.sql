-- 033_carafe_classification.sql — Carafe v3 §4.3 (classify) + §8 (review queue).
--
-- Adds classification metadata to vendors so VendorClassifierService can
-- record its decision + the operator can override it via the review-queue
-- surface. The actual classification value lives in `vendors.type`
-- (mig 026); this migration adds:
--
--   - classification_confidence  — 0..100 (matches completeness_score)
--   - classification_signals_json — which heuristics fired (audit trail)
--   - classification_needs_review — flag the row for the review queue
--   - classified_at / reviewed_at / reviewed_by — audit columns
--
-- Idempotent ALTERs guarded by INFORMATION_SCHEMA.

-- ───────────────────────────────────────────────────────────────────────────
-- vendors classification columns
-- ───────────────────────────────────────────────────────────────────────────

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'classification_confidence');
SET @s := IF(@col = 0,
    'ALTER TABLE vendors ADD COLUMN classification_confidence TINYINT UNSIGNED NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'classification_signals_json');
SET @s := IF(@col = 0,
    'ALTER TABLE vendors ADD COLUMN classification_signals_json JSON NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'classification_needs_review');
SET @s := IF(@col = 0,
    'ALTER TABLE vendors ADD COLUMN classification_needs_review TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'classified_at');
SET @s := IF(@col = 0,
    'ALTER TABLE vendors ADD COLUMN classified_at DATETIME NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'classification_reviewed_at');
SET @s := IF(@col = 0,
    'ALTER TABLE vendors ADD COLUMN classification_reviewed_at DATETIME NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'classification_reviewed_by');
SET @s := IF(@col = 0,
    'ALTER TABLE vendors ADD COLUMN classification_reviewed_by CHAR(36) NULL',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- Index for the review queue's primary query: "all vendors flagged for
-- review, oldest first." Partial index over the flag column.
SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND INDEX_NAME = 'idx_vendor_needs_review');
SET @s := IF(@idx = 0,
    'ALTER TABLE vendors ADD INDEX idx_vendor_needs_review (classification_needs_review, classified_at)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
