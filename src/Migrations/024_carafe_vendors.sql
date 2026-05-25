-- 024_carafe_vendors.sql — Carafe Chunk 13: vendor directory.
--
-- MARKET reservoir (spec §1.5 / §7). The vendor directory is the public
-- side of the data wall — readable cross-tenant (the whole point is a
-- shared directory of distributors). Writes go through
-- App\MarketData\VendorRepository.
--
-- NO pricing data in this migration. vendor_listings carries category
-- coverage only. Pricing fields will land once §13 Q3 legal clarifies
-- which sources are public-safe to publish.
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS vendors (
  id                CHAR(36)        PRIMARY KEY,
  name              VARCHAR(200)    NOT NULL,
  legal_name        VARCHAR(200)    NULL,
  hq_address        VARCHAR(255)    NULL,
  hq_lat            DOUBLE          NULL,
  hq_lng            DOUBLE          NULL,
  phone             VARCHAR(40)     NULL,
  website           VARCHAR(255)    NULL,
  primary_category  VARCHAR(60)     NULL,         -- 'produce', 'protein', 'dairy', 'broadline', 'specialty'
  source            ENUM('manual','public_directory','usda','greendock_affiliate') NOT NULL,
  is_affiliated     TINYINT(1)      NOT NULL DEFAULT 0,
                                                  -- TRUE when this row is USA Produce (or any
                                                  -- GreenDock-affiliated supplier). UI uses this
                                                  -- flag for the mandatory affiliation disclosure
                                                  -- label (spec §1.4).
  claim_status      ENUM('unclaimed','pending','claimed','disputed') NOT NULL DEFAULT 'unclaimed',
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_vendor_name (name),
  INDEX idx_vendor_category (primary_category),
  INDEX idx_vendor_claim (claim_status),
  INDEX idx_vendor_affiliated (is_affiliated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- vendor_listings — category × region coverage. NO pricing.
CREATE TABLE IF NOT EXISTS vendor_listings (
  id              CHAR(36)     PRIMARY KEY,
  vendor_id       CHAR(36)     NOT NULL,
  category        VARCHAR(60)  NOT NULL,
  region          VARCHAR(40)  NULL,              -- coverage region; NULL = nationwide
  service_radius_mi INT UNSIGNED NULL,            -- delivery radius from HQ
  min_order_cents INT UNSIGNED NULL,
  notes           VARCHAR(255) NULL,
  source          ENUM('vendor_claimed','operator_added','public_directory') NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_listing (vendor_id, category, region),
  INDEX idx_listing_cat (category, region)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- vendor_claims — workflow for a vendor reps claiming + correcting their row.
CREATE TABLE IF NOT EXISTS vendor_claims (
  id              CHAR(36)     PRIMARY KEY,
  vendor_id       CHAR(36)     NOT NULL,
  organization_id CHAR(36)     NOT NULL,           -- the org claiming (vendor's own Carafe org)
  claimant_user_id CHAR(36)    NOT NULL,
  contact_email   VARCHAR(160) NOT NULL,
  contact_phone   VARCHAR(40)  NULL,
  message         TEXT         NULL,
  status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  decided_at      DATETIME     NULL,
  decided_by      CHAR(36)     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_claim_vendor (vendor_id, status),
  INDEX idx_claim_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
