-- 020_carafe_planning_sandbox.sql — Carafe Chunk 6: planning sandbox.
--
-- PRIVATE reservoir. Stores hypothetical scenarios — model a new location,
-- model a menu change — without touching live menu_items / restaurants.
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS plans_sandbox (
  id              CHAR(36)     PRIMARY KEY,
  organization_id CHAR(36)     NOT NULL,
  restaurant_id   CHAR(36)     NULL,             -- NULL = new-location scenario (no source restaurant)
  name            VARCHAR(160) NOT NULL,
  kind            ENUM('new_location','menu_change') NOT NULL,
  payload         JSON         NOT NULL,         -- inputs: candidate address, proposed menu deltas, etc.
  projected       JSON         NULL,             -- computed outputs: projected COGS/margin/break-even
  computed_at     DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ps_org (organization_id, created_at),
  INDEX idx_ps_rst (restaurant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
