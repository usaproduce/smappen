-- 029_carafe_seed_campaigns.sql — Carafe Vendor Network Spec v3 §4 + §6.
--
-- The unit of work for the vendor-network seeding pipeline. A campaign
-- defines the geography, vendor types, source mix, and enrich policy —
-- runs as a parent job that spawns tile children on the existing `jobs`
-- queue (mig 006). The estimator (§5.2) writes its dual-pass projection
-- back to the campaign row before the admin approves a run, and the
-- live run dashboard (§5.3) reads spent_usd vs budget_cap_usd to drive
-- the budget-cap halt (§10 guardrail 5).
--
-- seed_tiles is the per-tile work ledger. result_id_hash drives §12.3
-- (delta seeding): on a re-sweep, an unchanged tile hash skips the
-- entire downstream dedupe/enrich pipeline for that tile.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS.

-- ───────────────────────────────────────────────────────────────────────────
-- seed_campaigns
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS seed_campaigns (
  id                      CHAR(36)        PRIMARY KEY,
  name                    VARCHAR(160)    NOT NULL,
  -- Region: stored both as raw GeoJSON (for round-trip with the frontend
  -- region picker) and as a derived bbox so tile generation doesn't have
  -- to parse the polygon on every read.
  region_geojson          JSON            NOT NULL,
  bbox_lat_min            DOUBLE          NOT NULL,
  bbox_lng_min            DOUBLE          NOT NULL,
  bbox_lat_max            DOUBLE          NOT NULL,
  bbox_lng_max            DOUBLE          NOT NULL,
  -- Spec §2 vendor types — array of enum values stored as JSON for
  -- forward compatibility (new types add without ALTER).
  vendor_types_json       JSON            NOT NULL,
  -- Spec §4.4 enrich policies.
  enrich_policy           ENUM('all','priority_types','on_demand') NOT NULL DEFAULT 'priority_types',
  -- Spec §5.2 density profile drives the estimator + tile sizing.
  density_profile         ENUM('rural','suburban','dense','mixed') NOT NULL DEFAULT 'mixed',
  -- Spec §2 source mix — which non-Google adapters to fold in alongside Places.
  source_mix_json         JSON            NULL,
  -- Budget cap §5.3 / §10 g5. NULL = no cap (admin override; PlacesClient still records cost).
  budget_cap_usd          DECIMAL(10,2)   NULL,
  status                  ENUM('draft','estimating','approved','running','paused','done','failed','cancelled')
                                          NOT NULL DEFAULT 'draft',
  pause_reason            VARCHAR(255)    NULL,
  -- Estimator output (§5.2). estimate_skus_json is the per-SKU breakdown
  -- the cost preview UI renders.
  estimate_low_usd        DECIMAL(10,2)   NULL,
  estimate_expected_usd   DECIMAL(10,2)   NULL,
  estimate_high_usd       DECIMAL(10,2)   NULL,
  estimate_skus_json      JSON            NULL,
  estimate_meta_json      JSON            NULL,     -- tile_count, calls, free_tier_remaining, etc
  estimated_at            DATETIME        NULL,
  -- Live counters (updated by tile workers + recordCall in PlacesClient).
  spent_usd               DECIMAL(10,4)   NOT NULL DEFAULT 0,
  tile_count              INT UNSIGNED    NOT NULL DEFAULT 0,
  tiles_done_count        INT UNSIGNED    NOT NULL DEFAULT 0,
  vendor_count            INT UNSIGNED    NOT NULL DEFAULT 0,
  created_by              CHAR(36)        NULL,     -- users.id of the admin who built it
  created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  approved_at             DATETIME        NULL,
  started_at              DATETIME        NULL,
  finished_at             DATETIME        NULL,
  INDEX idx_campaign_status  (status, created_at),
  INDEX idx_campaign_creator (created_by, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- seed_tiles
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS seed_tiles (
  id                      CHAR(36)        PRIMARY KEY,
  campaign_id             CHAR(36)        NOT NULL,
  -- Tile bbox. Lat/lng pairs kept as columns (not just JSON) so the
  -- spatial overlap query "which tile contains place P" stays index-able.
  lat_min                 DOUBLE          NOT NULL,
  lng_min                 DOUBLE          NOT NULL,
  lat_max                 DOUBLE          NOT NULL,
  lng_max                 DOUBLE          NOT NULL,
  status                  ENUM('queued','running','done','failed','skipped') NOT NULL DEFAULT 'queued',
  -- Spec §12.3 — fingerprint of the sweep result place-id set. On re-sweep,
  -- an identical hash means the tile is unchanged and downstream
  -- processing is skipped wholesale.
  result_id_hash          CHAR(64)        NULL,
  calls_made              INT UNSIGNED    NOT NULL DEFAULT 0,
  results_count           INT UNSIGNED    NOT NULL DEFAULT 0,
  cost_usd                DECIMAL(10,4)   NOT NULL DEFAULT 0,
  attempt_count           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error_message           VARCHAR(255)    NULL,
  started_at              DATETIME        NULL,
  finished_at             DATETIME        NULL,
  created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- (campaign_id, status) is what the worker uses to pick its next tile;
  -- result_id_hash by itself is the §12.3 dedupe lookup on re-sweep.
  INDEX idx_tiles_camp_status (campaign_id, status),
  INDEX idx_tiles_hash        (result_id_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
