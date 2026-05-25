-- 019_carafe_pos_sales.sql — Carafe Chunk 2: PMIX (product mix) + sales history.
--
-- PRIVATE reservoir. PosService::syncSales() upserts; MenuEngineeringService
-- reads to replace the hardcoded MIN_EST_MONTHLY_SALES estimate from Chunk 1
-- with real volume.
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS pos_sales (
  id              CHAR(36)        PRIMARY KEY,
  organization_id CHAR(36)        NOT NULL,
  restaurant_id   CHAR(36)        NOT NULL,
  menu_item_id    CHAR(36)        NULL,            -- NULL if the POS item wasn't matched yet
  pos_provider    VARCHAR(40)     NOT NULL,
  pos_order_id    VARCHAR(120)    NOT NULL,        -- order/line composite for dedupe
  pos_line_uid    VARCHAR(160)    NOT NULL,        -- per-line unique id (pos_order_id + line idx)
  qty             INT UNSIGNED    NOT NULL DEFAULT 1,
  gross_cents     INT UNSIGNED    NOT NULL DEFAULT 0,
  net_cents       INT UNSIGNED    NULL,            -- after discounts, if POS supplies
  sold_at         DATETIME        NOT NULL,
  daypart_label   VARCHAR(20)     NULL,            -- 'breakfast','lunch','dinner','late' — derived
  raw_json        JSON            NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ps_line (restaurant_id, pos_provider, pos_line_uid),
  INDEX idx_ps_rst_sold (restaurant_id, sold_at),
  INDEX idx_ps_item (menu_item_id, sold_at),
  INDEX idx_ps_org (organization_id, sold_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
