-- 040_carafe_digest_rec_ids.sql — Carafe weekly digest provenance.
--
-- The war-room overview surfaces a "this is what we sent in your Monday
-- digest" callout for 48 hours after the weekly send (spec §1.6, §9 +
-- audit item 7). To link the callout back to the exact recs that went
-- out, the digest_sends row needs to record the rec ids it included.
--
-- digest_sends is currently created by scripts/send-weekly-digest.php
-- via CREATE TABLE IF NOT EXISTS — the script will continue to do that,
-- this migration is the migration-tracked path for existing installs.
--
-- Idempotent.

-- ── ensure digest_sends exists (matches the script's CREATE) ──────────
CREATE TABLE IF NOT EXISTS digest_sends (
  id              CHAR(36)     PRIMARY KEY,
  organization_id CHAR(36)     NOT NULL,
  restaurant_id   CHAR(36)     NOT NULL,
  week_start      DATE         NOT NULL,
  sent_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  recipient_email VARCHAR(255) NOT NULL,
  rec_count       INT UNSIGNED NOT NULL DEFAULT 0,
  total_cents     INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uk_digest_week (organization_id, restaurant_id, week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── rec_ids JSON column ───────────────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'digest_sends'
               AND COLUMN_NAME  = 'rec_ids');
SET @s := IF(@col = 0,
    'ALTER TABLE digest_sends ADD COLUMN rec_ids JSON NULL AFTER total_cents',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- ── index on sent_at so the war-room "last 48h" lookup is a range scan ─
SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'digest_sends'
               AND INDEX_NAME   = 'idx_digest_sends_restaurant_sent');
SET @s := IF(@idx = 0,
    'ALTER TABLE digest_sends ADD INDEX idx_digest_sends_restaurant_sent (restaurant_id, sent_at)',
    'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
