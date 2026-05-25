-- 025_carafe_lead_funnel.sql — Carafe Chunk 16: comparison_requests + supplier_leads.
--
-- MARKET reservoir (spec §1.5 / §7). These two tables are the funnel.
--
-- comparison_requests = the opt-in signal. A restaurant explicitly ran a
--   comparison and (separately) submitted a quote request from the
--   results screen. This is the ONLY path data from a restaurant can
--   enter the funnel — never from passive POS/menu data.
--
-- supplier_leads = the outbox. Carafe emits these to GreenDock as
--   HMAC-signed webhooks via WebhookDispatcher (spec §1a Pipe B). GreenDock
--   subscribes like any third party; it does NOT read this table.
--
-- ENFORCEMENT (tests/DataWall/DataWallTest.php):
--   - The only file allowed to write supplier_leads is
--     App\MarketData\LeadFunnelService. Test fails if any other file
--     contains `INSERT INTO supplier_leads`.
--   - MarketData/* may not reference private-reservoir tables. Test
--     fails if comparison_requests or supplier_leads code mentions
--     pos_sales, menu_items, plate_costs, etc.

CREATE TABLE IF NOT EXISTS comparison_requests (
  id              CHAR(36)     PRIMARY KEY,
  organization_id CHAR(36)     NOT NULL,           -- the restaurant's org
  restaurant_id   CHAR(36)     NULL,                -- which restaurant (NULL = ad-hoc browse)
  category        VARCHAR(60)  NOT NULL,
  region          VARCHAR(40)  NULL,
  basket_json     JSON         NULL,                -- snapshot of the basket compared
  vendor_ids_json JSON         NULL,                -- which vendors were returned
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cr_org (organization_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_leads (
  id                CHAR(36)     PRIMARY KEY,
  organization_id   CHAR(36)     NOT NULL,
  restaurant_id     CHAR(36)     NULL,
  comparison_id     CHAR(36)     NULL,              -- references comparison_requests.id
  vendor_id         CHAR(36)     NOT NULL,          -- which supplier is the lead for
  is_affiliated     TINYINT(1)   NOT NULL DEFAULT 0,
  contact_name      VARCHAR(120) NULL,
  contact_email     VARCHAR(160) NOT NULL,
  contact_phone     VARCHAR(40)  NULL,
  message           TEXT         NULL,
  basket_json       JSON         NULL,              -- snapshot at lead time
  status            ENUM('queued','emitted','acknowledged','closed_won','closed_lost') NOT NULL DEFAULT 'queued',
  webhook_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  webhook_last_at   DATETIME     NULL,
  webhook_last_code SMALLINT     NULL,
  external_ref      VARCHAR(120) NULL,              -- GreenDock-side id once acked
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_lead_org (organization_id, created_at),
  INDEX idx_lead_vendor (vendor_id, status),
  INDEX idx_lead_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
