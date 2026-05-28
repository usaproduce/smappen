-- 041_carafe_sample_data.sql — fully-populated sample restaurant.
--
-- Adds the minimum columns SampleDataService needs to write + tear down a
-- complete sample chain. Additive only. Idempotent via INFORMATION_SCHEMA
-- guards (same pattern as 021/037).
--
-- Splits target: migrate.php's naive `;\s*[\r\n]/` splitter — keep
-- statement-ending semicolons in column 0 of their own line. No `;` inside
-- `--` comments.

-- ─── pos_integrations.is_sample ─────────────────────────────────────────
-- A "connected" sample POS that never calls Square. PosService::sync
-- short-circuits when this is 1.
SET @col_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'pos_integrations'
                     AND COLUMN_NAME = 'is_sample');
SET @stmt := IF(@col_check = 0,
    'ALTER TABLE pos_integrations ADD COLUMN is_sample TINYINT(1) NOT NULL DEFAULT 0 AFTER provider',
    'SELECT "pos_integrations.is_sample already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── menu_items.external_id ─────────────────────────────────────────────
-- Stable synthetic id ("sample:carbonara") so SampleDataService can
-- ON DUPLICATE KEY UPDATE without re-creating items on re-seed.
SET @col_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'menu_items'
                     AND COLUMN_NAME = 'external_id');
SET @stmt := IF(@col_check = 0,
    'ALTER TABLE menu_items ADD COLUMN external_id VARCHAR(120) NULL AFTER pos_item_id',
    'SELECT "menu_items.external_id already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Unique key on (restaurant_id, external_id) — only enforced when external_id
-- is non-null, MySQL allows multiple NULLs per unique key. Idempotent guard.
SET @idx_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'menu_items'
                     AND INDEX_NAME = 'uk_mi_external');
SET @stmt := IF(@idx_check = 0,
    'CREATE UNIQUE INDEX uk_mi_external ON menu_items (restaurant_id, external_id)',
    'SELECT "uk_mi_external already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── recipes.external_id ────────────────────────────────────────────────
-- Same idempotency story for recipes.
SET @col_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'recipes'
                     AND COLUMN_NAME = 'external_id');
SET @stmt := IF(@col_check = 0,
    'ALTER TABLE recipes ADD COLUMN external_id VARCHAR(120) NULL AFTER name',
    'SELECT "recipes.external_id already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_check := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'recipes'
                     AND INDEX_NAME = 'uk_rcp_external');
SET @stmt := IF(@idx_check = 0,
    'CREATE UNIQUE INDEX uk_rcp_external ON recipes (restaurant_id, external_id)',
    'SELECT "uk_rcp_external already present"');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── cogs_benchmark stubs for sample-restaurant ingredients ─────────────
-- The SampleDataService recipes reference ingredients the original
-- seed-cogs-benchmark-stub.php list doesn't cover (guanciale, San Marzano,
-- '00' flour, etc.). Seeding them here keeps the SharedRef wall intact —
-- writes happen at migrate time, never at request time.
--
-- INSERT IGNORE so re-running this migration on top of an existing row is
-- a no-op. UNIQUE(ingredient_key, region, source, as_of) holds the
-- idempotency contract.
INSERT IGNORE INTO cogs_benchmark
    (id, ingredient_key, region, market_price_cents, unit, source, as_of, created_at)
VALUES
    (UUID(), 'guanciale',           'US', 1180, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'pecorino_romano',     'US', 1240, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'mozzarella_bufala',   'US',  980, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'flour_00',            'US',  160, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'tomato_san_marzano',  'US',  320, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'ribeye',              'US', 2480, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'branzino',            'US', 1680, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'chicken_thigh',       'US',  260, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'veal_ground',         'US',  980, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'pork_ground',         'US',  520, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'squid_tube',          'US',  980, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'mascarpone',          'US',  980, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'espresso_beans',      'US', 1200, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'ladyfingers',         'US',  640, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'gelatin_sheet',       'US',   65, 'oz',  'stub', CURDATE(), NOW()),
    (UUID(), 'wine_red_house',      'US',  180, 'cup', 'stub', CURDATE(), NOW()),
    (UUID(), 'arugula',             'US',  560, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'prosciutto',          'US', 1880, 'lb',  'stub', CURDATE(), NOW()),
    (UUID(), 'oregano_dry',         'US',   32, 'oz',  'stub', CURDATE(), NOW()),
    (UUID(), 'chili_flake',         'US',   38, 'oz',  'stub', CURDATE(), NOW())
;
