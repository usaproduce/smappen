-- 022_carafe_goals.sql — Carafe Chunk 9: goal tracking.
--
-- PRIVATE reservoir. Operator sets a target (food cost %, avg check, margin
-- %); Carafe shows progress over rolling windows. Snapshots so trend lines
-- have history.
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS goals (
  id               CHAR(36)     PRIMARY KEY,
  organization_id  CHAR(36)     NOT NULL,
  restaurant_id    CHAR(36)     NOT NULL,
  metric           ENUM('food_cost_pct','avg_check_cents','margin_pct','weekly_revenue_cents') NOT NULL,
  target_value     DECIMAL(12,4) NOT NULL,        -- pct stored as 0.30 = 30%; cents stored as int-cast
  cadence          ENUM('weekly','monthly','quarterly') NOT NULL DEFAULT 'monthly',
  label            VARCHAR(120) NULL,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_goal_rst (restaurant_id, is_active),
  INDEX idx_goal_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goal_snapshots (
  id           CHAR(36)      PRIMARY KEY,
  goal_id      CHAR(36)      NOT NULL,
  period_start DATE          NOT NULL,
  period_end   DATE          NOT NULL,
  actual_value DECIMAL(12,4) NOT NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_gs_window (goal_id, period_start, period_end),
  INDEX idx_gs_goal (goal_id, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
