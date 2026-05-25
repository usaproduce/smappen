# SharedRef namespace — shared reference data

Read-only reference tables that are not org-scoped and may be read by
either side of the data wall.

Currently:
- `CogsBenchmarkRepository` — reads `cogs_benchmark` (USDA + GreenDock-
  published market pricing for ingredients).

Rules:
- Tables here are read-only from application code. Writes happen only via
  operator/cron scripts (`scripts/seed-cogs-benchmark-stub.php`,
  future `scripts/refresh-cogs-benchmark.php`).
- Code here must not import `App\PrivateData\*` or `App\MarketData\*`.
  Shared reference is a leaf; it doesn't know who's reading it.

See: spec §7 (Shared reference section).
