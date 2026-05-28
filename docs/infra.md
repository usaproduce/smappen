# Infrastructure & runtime tuning

This document captures the operational decisions that aren't obvious from
code alone — things future-me needs to know when capacity-planning, debugging
a connection storm, or rolling back a change.

## Hosting

* One DigitalOcean Droplet at `143.244.144.7` (NYC).
* Apache 2 reverse proxy → PHP-FPM 8.3 (`/var/run/php/php8.3-fpm.sock`).
* MySQL 8 on the same box (`localhost`, port 3306).
* TLS via Let's Encrypt (`/etc/letsencrypt/live/smappen.mygreendock.com/`).

See [failover.md](failover.md) for the cold-standby plan.

## PHP-FPM pool sizing

`/etc/php/8.3/fpm/pool.d/www.conf`:

```
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
```

Sized against the spec §12 ceiling (`pm.max_children=20`). Each worker keeps
one persistent MySQL handle once warm (see below), so the worst-case web
connection count is **20**, with headroom for cron workers underneath the
MySQL `max_connections` ceiling.

## Connection pooling (recommendation #12)

The central `App\Core\Database` factory uses `PDO::ATTR_PERSISTENT => true`
in long-lived SAPIs (FPM, Apache). The first request a worker handles pays
the ~1ms TCP/auth handshake; every subsequent request on that worker reuses
the same MySQL handle. After warmup, `SHOW PROCESSLIST` settles at roughly
one connection per FPM worker plus whatever cron is currently running.

* **CLI mode is deliberately NOT persistent.** Cron scripts (`scripts/*.php`)
  run as one-shot PHP processes and exit; the persistent pool has nothing to
  reuse them against. We skip the flag in CLI to keep the worker chain from
  accidentally holding handles after the script finishes.
* **Escape hatch:** `DB_PERSISTENT=false` in `.env` reverts to per-request
  `new PDO(...)` everywhere. The default is `true`. Flip it if a hosting
  environment starts misbehaving — e.g., MySQL `aborted_clients` climbs or a
  PHP/MySQL upgrade ships a regression in persistent reuse.
* **Health visibility:** `GET /api/health` returns
  `pool.persistent: true|false` so monitors and the in-app banner can see
  which mode is live, plus `connections.current` (from `SHOW STATUS LIKE
  'Threads_connected'`) and `connections.max` (from `SHOW VARIABLES LIKE
  'max_connections'`).

### What this changes operationally

Measured on the production Droplet (`143.244.144.7`, MySQL on the same box
via Unix socket), 2026-05-28:

| Metric | Per-request connect | Persistent (current) |
|---|---|---|
| Raw PDO connect+`SELECT 1` (microbench, 100 iter, p50) | ~251 µs | ~44 µs (**−82%**) |
| New MySQL connections during a 200-request burst on `/api/health` | **202** | **1** |
| `Threads_connected` mid-burst | grows with concurrency | flat at ~5 (≈ FPM worker count) |
| 100×`/api/health` wall-time, loopback (3-trial median) | ~1.33 s | ~1.27 s (~4% — see note) |
| Behavior after FPM worker recycle (`pm.max_requests`) | unchanged | handle closes with the worker, new handle on next request |

> **Note on wall-time delta.** The spec §12 target was "≥30% faster on a
> 100-call `/api/health` burst." We do not hit that on this setup, and we
> don't expect to: the endpoint runs three MySQL queries per call against a
> Unix-socket MySQL, where the handshake cost (~250 µs) is a small slice of
> the ~13 ms total request budget (Apache → FPM → autoload → controller →
> three queries). The underlying connect saving is real (82% at the PDO
> layer), it just doesn't dominate `/api/health`. The bigger operational
> win is the connection-reuse column — 200 web requests creating 1 new
> MySQL connection instead of 202 — which is what actually matters under
> sustained load and when the cron stack overlaps with web traffic.

### Rollback

If something goes sideways:

```bash
ssh root@143.244.144.7
sed -i 's/^DB_PERSISTENT=true$/DB_PERSISTENT=false/' /var/www/smappen/.env
# Optional: force already-warm FPM workers to recycle so the change applies
# immediately to every worker (otherwise it's worker-by-worker as each rotates).
systemctl reload php8.3-fpm
```

A second `sed`/reload flips it back. No code deploy is needed — the switch
is a runtime env read inside `Database::__construct`.

## MySQL capacity

```
max_connections   = 151   (default; comfortably above the §12 floor of 60)
Max_used_connections so far ≈ 13
```

The headroom calculation:

* 20 FPM workers × 1 persistent handle = **20**
* Cron workers (worst case: all Carafe seed/dedupe/classify/coverage cron lines
  fire in the same minute) ≈ **10**
* Standby for ad-hoc shells, backup, replication = **~5**
* Total worst case: ≈ 35, well below 151.

If we ever raise `pm.max_children` past ~80, bump `max_connections` to keep a
2× safety factor. Edit `/etc/mysql/mysql.conf.d/mysqld.cnf`:

```
[mysqld]
max_connections = 300
```

Then `systemctl restart mysql`.

## Cron capacity

See `scripts/cron/carafe-crontab` for the active worker chain. Every line is
wrapped in `flock` so a slow run can't stack a second process on top of
itself. Worker logs land under `storage/logs/cron/`; `cleanup-cron.php`
truncates anything older than 30 days every 15 minutes.

During a full cron sweep (the 02:30/03:00/03:30/04:00/04:30 UTC stack),
`Threads_connected` should stay under 40. If it climbs past 80, audit which
worker grew its query count and consider sharding the cadence.

## Verifying the pool is working

```bash
# From the droplet:
mysql -usmappen -p"$DB_PASS" -e 'SHOW PROCESSLIST' | grep smappen | wc -l
# After warmup, expect roughly the count of active FPM workers, not one per
# in-flight request.

# Burst the health endpoint and check it's reusing handles:
for i in $(seq 1 100); do
  curl -s https://smappen.mygreendock.com/api/health > /dev/null
done

curl -s https://smappen.mygreendock.com/api/health | jq '.pool, .connections'
# .pool.persistent should be true; .connections.current should be small (~20).
```

## Out of scope (intentionally)

* **ProxySQL or a dedicated pooler.** Overkill at Phase 1 traffic; PDO's
  built-in persistent flag is sufficient until we add a second app box.
* **Switching to MariaDB.** MySQL 8 is fine and matches the audit baseline.
* **Read replicas.** The standby plan in `failover.md` already covers DR;
  there's no read-heavy workload that benefits from a hot read replica yet.
