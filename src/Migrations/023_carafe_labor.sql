-- 023_carafe_labor.sql — Carafe Chunk 11: labor shifts.
--
-- PRIVATE reservoir. Square Labor API → labor_shifts → LaborService matches
-- shifts vs hourly sales volume to surface over/under-staffed windows.
--
-- Manual entry path also supported (operator can punch in shifts when POS
-- doesn't have labor data). Each row carries `source` for provenance.
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS labor_shifts (
  id              CHAR(36)        PRIMARY KEY,
  organization_id CHAR(36)        NOT NULL,
  restaurant_id   CHAR(36)        NOT NULL,
  employee_label  VARCHAR(120)    NULL,           -- anonymized name / role
  role            VARCHAR(40)     NULL,           -- 'foh','boh','manager','prep'
  pos_provider    VARCHAR(40)     NULL,
  pos_shift_uid   VARCHAR(160)    NULL,           -- dedupe key when from POS
  source          ENUM('square','toast','clover','manual') NOT NULL DEFAULT 'manual',
  starts_at       DATETIME        NOT NULL,
  ends_at         DATETIME        NULL,
  hourly_wage_cents INT UNSIGNED  NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_lab_shift (restaurant_id, pos_provider, pos_shift_uid),
  INDEX idx_lab_rst (restaurant_id, starts_at),
  INDEX idx_lab_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
