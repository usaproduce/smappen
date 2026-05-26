-- 029_carafe_restaurant_google_place.sql — link restaurants to Google Places.
--
-- When operators create a restaurant via the Google autocomplete flow, we
-- capture the place_id + phone + website so:
--   - the restaurant can be unambiguously re-identified later (place_id)
--   - the war-room dashboard has contact info without manual entry
--   - the vendor-recommendation engine could one day enrich from Google
--     (hours, rating, photos) without a second autocomplete spend.
--
-- All columns NULL — manual-entry restaurants leave them blank.
-- Idempotent via INFORMATION_SCHEMA guards.

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'google_place_id');
SET @s := IF(@c = 0,
  'ALTER TABLE restaurants ADD COLUMN google_place_id VARCHAR(120) NULL AFTER region',
  'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'phone');
SET @s := IF(@c = 0,
  'ALTER TABLE restaurants ADD COLUMN phone VARCHAR(40) NULL AFTER google_place_id',
  'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'website');
SET @s := IF(@c = 0,
  'ALTER TABLE restaurants ADD COLUMN website VARCHAR(255) NULL AFTER phone',
  'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- Unique on google_place_id WITHIN an org so the same place can't be
-- accidentally added twice. NULL place_ids don't collide (MySQL UNIQUE
-- treats NULLs as distinct).
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND INDEX_NAME = 'uk_rst_org_place');
SET @s := IF(@c = 0,
  'ALTER TABLE restaurants ADD UNIQUE KEY uk_rst_org_place (organization_id, google_place_id)',
  'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
