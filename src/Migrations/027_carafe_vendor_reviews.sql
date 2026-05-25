-- 027_carafe_vendor_reviews.sql — Vendor Network spec §5 + §6.3 + §7.
--
-- MARKET reservoir (mostly). The wall in tests/DataWall/DataWallTest.php
-- already gates this — these tables live alongside vendors / vendor_*
-- and must not touch any private-reservoir table.
--
-- vendor_reviews carries `organization_id` because it's the operator
-- writing the review. The reviewer's ORG identity is used for the
-- one-per-org rule + spam defense. NEVER joins to their pos_sales /
-- menu / cost data — that would breach the wall.
--
-- Idempotent.

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_reviews — one per (org, vendor), versioned via updated_at.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vendor_reviews (
  id                      CHAR(36)        PRIMARY KEY,
  vendor_id               CHAR(36)        NOT NULL,
  organization_id         CHAR(36)        NOT NULL,
  reviewer_user_id        CHAR(36)        NOT NULL,
  restaurant_id           CHAR(36)        NULL,           -- which of the org's restaurants
  overall                 TINYINT UNSIGNED NOT NULL,       -- 1..5
  score_price             TINYINT UNSIGNED NULL,           -- 1..5
  score_reliability       TINYINT UNSIGNED NULL,
  score_quality           TINYINT UNSIGNED NULL,
  score_accuracy          TINYINT UNSIGNED NULL,
  score_service           TINYINT UNSIGNED NULL,
  body                    TEXT            NULL,
  photo_url               VARCHAR(500)    NULL,
  categories_bought       JSON            NULL,            -- ['produce','dairy', ...]
  volume_band             ENUM('light','moderate','heavy') NULL,
  delivery_or_pickup      ENUM('delivery','pickup','both') NULL,
  verification_strength   ENUM('restaurant_exists','pos_connected','manual_review') NOT NULL DEFAULT 'restaurant_exists',
  is_hidden               TINYINT(1)      NOT NULL DEFAULT 0,
  created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_vrev_org_vendor (organization_id, vendor_id),
  INDEX idx_vrev_vendor (vendor_id, is_hidden, created_at),
  INDEX idx_vrev_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_review_responses — vendor's labeled public reply. One per review.
-- Only the org that holds an approved vendor_claim may post a response.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vendor_review_responses (
  id              CHAR(36)        PRIMARY KEY,
  review_id       CHAR(36)        NOT NULL,
  vendor_id       CHAR(36)        NOT NULL,
  responder_org_id CHAR(36)       NOT NULL,
  responder_user_id CHAR(36)      NOT NULL,
  body            TEXT            NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_vresp_review (review_id),
  INDEX idx_vresp_vendor (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- saved_vendors — operator follow / shortlist.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS saved_vendors (
  id              CHAR(36)        PRIMARY KEY,
  organization_id CHAR(36)        NOT NULL,
  vendor_id       CHAR(36)        NOT NULL,
  user_id         CHAR(36)        NOT NULL,
  note            VARCHAR(255)    NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_sv_org_vendor (organization_id, vendor_id),
  INDEX idx_sv_org (organization_id),
  INDEX idx_sv_vendor (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- vendor_searches — saved searches + area-alert subscriptions.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vendor_searches (
  id              CHAR(36)        PRIMARY KEY,
  organization_id CHAR(36)        NOT NULL,
  user_id         CHAR(36)        NOT NULL,
  name            VARCHAR(160)    NULL,
  filters_json    JSON            NOT NULL,
  alert_on_new    TINYINT(1)      NOT NULL DEFAULT 0,
  last_alerted_at DATETIME        NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vs_org (organization_id),
  INDEX idx_vs_alert (alert_on_new, last_alerted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
