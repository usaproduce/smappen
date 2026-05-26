-- 028_carafe_api_cost_events.sql — Carafe Vendor Network Spec v3 §5 + §6.
--
-- Dedicated ledger for every Google Places call made by the seeding
-- pipeline (PlacesClient). Distinct from `api_usage_log` (mig 009),
-- which is per-user UI-spend tracking; this table is campaign-aware so
-- the run dashboard (§5.3) can show running total, free-tier burn-down,
-- and the budget-cap guardrail (§10 guardrail 5) can halt before the
-- next tile/enrich batch overruns.
--
-- One row per Places HTTP call, written by PlacesClient::record().
-- field_mask_hash buckets calls by exact mask shape so the nightly
-- reconciliation (§5.4) can flag drift >5% vs the Google Cloud billing
-- export.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS api_cost_events (
  id                  CHAR(36)        PRIMARY KEY,
  campaign_id         CHAR(36)        NULL,            -- nullable for non-campaign calls
  tile_id             CHAR(36)        NULL,            -- nullable; set when call is inside a seed_tile worker
  sku                 VARCHAR(48)     NOT NULL,        -- e.g. 'places_nearby_pro', 'place_details_pro', 'place_details_contact'
  billable_units      INT UNSIGNED    NOT NULL DEFAULT 1,
  unit_cost_usd       DECIMAL(10,6)   NOT NULL DEFAULT 0,
  total_cost_usd      DECIMAL(10,6)   NOT NULL DEFAULT 0,
  field_mask_hash     CHAR(16)        NULL,            -- truncated sha256 of normalized mask
  http_status         SMALLINT        NULL,
  latency_ms          INT UNSIGNED    NULL,
  error_message       VARCHAR(255)    NULL,
  called_at           DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_cost_campaign       (campaign_id, called_at),
  INDEX idx_cost_tile           (tile_id),
  INDEX idx_cost_sku_day        (sku, called_at),
  INDEX idx_cost_called_at      (called_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
