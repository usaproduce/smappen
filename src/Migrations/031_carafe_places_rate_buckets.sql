-- 031_carafe_places_rate_buckets.sql — Carafe v3 §12.4 + §10 guardrail 10:
--
-- "One shared rate limiter across all concurrent workers — never per-
-- worker limits, or aggregate QPS breaches the Places ceiling."
--
-- A single row per bucket (e.g. 'places_search', 'places_details') is
-- the shared token state. PlacesRateLimiter does the atomic refill +
-- decrement via UPDATE — concurrent workers serialize on the row lock
-- inside the UPDATE itself.
--
-- This is intentionally low-tech (no Redis dep). At expected Carafe
-- scale (a few tile workers, low double-digit QPS overall) MySQL row-
-- level locking on a 3-row table is more than fast enough.

CREATE TABLE IF NOT EXISTS places_rate_buckets (
  bucket               VARCHAR(40)     PRIMARY KEY,
  capacity             INT UNSIGNED    NOT NULL,             -- max tokens in the bucket
  fill_rate_per_sec    DECIMAL(8,3)    NOT NULL,             -- tokens added per second
  tokens_available     DECIMAL(12,4)   NOT NULL,             -- current token balance
  last_refill_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default buckets. Conservative defaults — admin can tune via
-- direct SQL UPDATE without code change. Capacity = burst headroom;
-- fill_rate = sustained QPS.
INSERT INTO places_rate_buckets (bucket, capacity, fill_rate_per_sec, tokens_available, last_refill_at)
VALUES
  ('places_search',  30, 10.0, 30, CURRENT_TIMESTAMP(3)),
  ('places_details', 20,  5.0, 20, CURRENT_TIMESTAMP(3)),
  ('places_photo',   10,  2.0, 10, CURRENT_TIMESTAMP(3))
ON DUPLICATE KEY UPDATE bucket = bucket;
