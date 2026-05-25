-- 017_carafe_scaffold.sql — Carafe Phase 1 scaffolding.
--
-- Introduces the shared-reference COGS benchmark table. This is the ONLY
-- reservoir that is read-cross-tenant: it holds market/USDA pricing that
-- every restaurant queries against. Provenance is mandatory (`source`) so
-- the spec §6.1 legal gate can distinguish public/USDA prices from
-- confidential supplier prices when we get to the vendor marketplace.
--
-- Carafe ingests this table from the GreenDock side (see §1a Pipe A) via
-- `CogsBenchmarkService`. Until that live feed exists, the table is
-- populated by `scripts/seed-cogs-benchmark-stub.php` with `source='stub'`
-- so Phase 1 builds + runs end-to-end without GreenDock.
--
-- All ops idempotent (CREATE TABLE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS cogs_benchmark (
  id                   CHAR(36)        PRIMARY KEY,
  ingredient_key       VARCHAR(120)    NOT NULL,
  region               VARCHAR(40)     NULL,
  market_price_cents   INT UNSIGNED    NOT NULL,
  unit                 VARCHAR(20)     NOT NULL,
  source               ENUM('usda','greendock','usa_produce','foundation_foods','stub') NOT NULL,
  as_of                DATE            NOT NULL,
  created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_cogs_lookup (ingredient_key, region, source, as_of),
  INDEX idx_cogs_ingredient (ingredient_key, as_of),
  INDEX idx_cogs_source (source, as_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
