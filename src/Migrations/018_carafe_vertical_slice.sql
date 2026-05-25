-- 018_carafe_vertical_slice.sql — Carafe Phase 1 vertical slice.
--
-- Adds the minimum tables needed to: connect a Square POS, pull menu items,
-- compute plate cost against `cogs_benchmark`, and surface one
-- dollar-quantified price recommendation per item. Spec §5.1, §5.2, §5.4,
-- §5.10 (recommendations table seeds the ROI ledger).
--
-- EVERY table here is in the PRIVATE reservoir (spec §1.5). Reads/writes
-- must go through `App\PrivateData\*` repositories — enforced by the
-- data-wall test in `tests/DataWall/DataWallTest.php`.
--
-- All ops idempotent (CREATE TABLE IF NOT EXISTS).

-- ───────────────────────────────────────────────────────────────────────────
-- restaurants — one row per physical restaurant. Org-scoped.
--
-- Independent of `projects` for now (Phase 1 vertical slice doesn't need
-- a map). Chunk 6 (planning sandbox) will create projects on demand from
-- a restaurant's location; the two stay loosely coupled.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS restaurants (
  id              CHAR(36)     PRIMARY KEY,
  organization_id CHAR(36)     NOT NULL,
  name            VARCHAR(160) NOT NULL,
  address         VARCHAR(255) NULL,
  lat             DOUBLE       NULL,
  lng             DOUBLE       NULL,
  timezone        VARCHAR(60)  NULL,
  region          VARCHAR(40)  NULL,             -- COGS lookup region (e.g. 'US-NE')
  is_sample       TINYINT(1)   NOT NULL DEFAULT 0,
  archived_at     DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rst_org (organization_id, archived_at),
  INDEX idx_rst_sample (is_sample)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- pos_integrations — OAuth tokens per restaurant per POS provider.
--
-- Schema mirrors the existing `integrations` table (CRM OAuth) so the
-- same AES-256-CBC-with-per-row-IV encryption pattern applies verbatim.
-- Separate table from `integrations` because:
--   (a) `integrations` is org-scoped; POS integration is restaurant-scoped,
--       so we keep one connection per (restaurant, provider).
--   (b) keeping POS data on its own table makes the data-wall enforcement
--       cleaner — the funnel never touches this table.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pos_integrations (
  id                CHAR(36)     PRIMARY KEY,
  organization_id   CHAR(36)     NOT NULL,
  restaurant_id     CHAR(36)     NOT NULL,
  provider          VARCHAR(40)  NOT NULL,        -- 'square', 'toast', 'clover'
  access_token_enc  TEXT         NOT NULL,
  refresh_token_enc TEXT         NULL,
  token_iv          VARCHAR(32)  NOT NULL,         -- hex-encoded 16-byte IV
  expires_at        DATETIME     NULL,
  meta_json         JSON         NULL,             -- merchant_id, scopes, etc.
  connected_at      DATETIME     NOT NULL,
  last_used_at      DATETIME     NULL,
  last_synced_at    DATETIME     NULL,
  UNIQUE KEY uk_pos_rst_prov (restaurant_id, provider),
  INDEX idx_pos_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- menu_items — normalized item catalog, synced from the POS.
-- pos_item_id is the foreign id (per provider) used for sync upserts.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS menu_items (
  id              CHAR(36)     PRIMARY KEY,
  organization_id CHAR(36)     NOT NULL,
  restaurant_id   CHAR(36)     NOT NULL,
  pos_provider    VARCHAR(40)  NULL,               -- which POS this came from
  pos_item_id     VARCHAR(120) NULL,
  name            VARCHAR(255) NOT NULL,
  category        VARCHAR(120) NULL,
  price_cents     INT UNSIGNED NOT NULL DEFAULT 0,
  recipe_id       CHAR(36)     NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_synced_at  DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_mi_pos (restaurant_id, pos_provider, pos_item_id),
  INDEX idx_mi_rst (restaurant_id, is_active),
  INDEX idx_mi_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- recipes + recipe_ingredients — operator-entered. Each menu_item may link
-- to one recipe; the plate cost engine multiplies quantities by
-- cogs_benchmark prices to compute true plate cost.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS recipes (
  id              CHAR(36)     PRIMARY KEY,
  organization_id CHAR(36)     NOT NULL,
  restaurant_id   CHAR(36)     NOT NULL,
  name            VARCHAR(255) NOT NULL,
  notes           TEXT         NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rcp_rst (restaurant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_ingredients (
  id             CHAR(36)       PRIMARY KEY,
  recipe_id      CHAR(36)       NOT NULL,
  ingredient_key VARCHAR(120)   NOT NULL,          -- matches cogs_benchmark.ingredient_key
  qty            DECIMAL(10,4)  NOT NULL,
  unit           VARCHAR(20)    NOT NULL,          -- 'oz','lb','each','cup','tbsp'
  notes          VARCHAR(255)   NULL,
  created_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ri_recipe (recipe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- plate_costs — one row per menu_item, recomputed by PlateCostService.
-- Tracks coverage_pct so the UI can tell the operator when the cost
-- estimate is based on a partial recipe walk (e.g., 2 of 5 ingredients
-- missing from the benchmark).
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plate_costs (
  id                  CHAR(36)        PRIMARY KEY,
  organization_id     CHAR(36)        NOT NULL,
  menu_item_id        CHAR(36)        NOT NULL,
  true_cost_cents     INT UNSIGNED    NOT NULL,
  coverage_pct        TINYINT UNSIGNED NOT NULL DEFAULT 100,
  missing_ingredients JSON            NULL,
  computed_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_pc_item (menu_item_id),
  INDEX idx_pc_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- recommendations — every rec the engine emits. Powers the ROI ledger
-- (Chunk 3). Status flips: suggested → accepted | dismissed; once
-- accepted, the ROI cron computes measured_impact_cents against later
-- pos_sales (Chunk 3).
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS recommendations (
  id                    CHAR(36)     PRIMARY KEY,
  organization_id       CHAR(36)     NOT NULL,
  restaurant_id         CHAR(36)     NOT NULL,
  menu_item_id          CHAR(36)     NULL,
  kind                  ENUM('price_raise','price_lower','reposition','reprice','cut') NOT NULL,
  payload               JSON         NOT NULL,
  narrative             TEXT         NULL,
  dollar_estimate_cents INT          NOT NULL DEFAULT 0,
  status                ENUM('suggested','accepted','dismissed','measured') NOT NULL DEFAULT 'suggested',
  measured_impact_cents INT          NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at            DATETIME     NULL,
  measured_at           DATETIME     NULL,
  INDEX idx_rec_rst (restaurant_id, status, created_at),
  INDEX idx_rec_org (organization_id, created_at),
  INDEX idx_rec_item (menu_item_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
