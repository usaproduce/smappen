-- 016_bugfix_round.sql — schema needed by the bug-audit fix batch.
--
-- All ops idempotent via INFORMATION_SCHEMA guards / CREATE TABLE IF NOT
-- EXISTS so it's safe to re-run.

-- stripe_webhook_events — idempotency log for Stripe deliveries. Stripe
-- retries webhooks on network hiccups; without this dedupe table a
-- checkout.session.completed delivered 3× would bump the plan 3×.
-- BillingController::webhook INSERT IGNOREs on event_id; a 0-row return
-- means we've already processed and we ack the duplicate without work.
CREATE TABLE IF NOT EXISTS stripe_webhook_events (
  event_id    VARCHAR(64)  PRIMARY KEY,
  received_at DATETIME     NOT NULL,
  INDEX idx_swe_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
