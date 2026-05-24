# Smappen — Platform Audit (v3)

*Snapshot as of 2026-05-24. Supersedes the v2 audit. Captures every surface, endpoint, table, animation, palette swatch, and scaffolded feature currently in the repo.*

---

## Table of Contents

1. [TL;DR + what changed since v2](#tldr--what-changed-since-v2)
2. [Tech stack](#tech-stack)
3. [Architecture & data flow](#architecture--data-flow)
4. [Database schema (14 migrations)](#database-schema-14-migrations)
5. [Backend surface — controllers + endpoints](#backend-surface--controllers--endpoints)
6. [Services layer](#services-layer)
7. [Background jobs + scripts](#background-jobs--scripts)
8. [Frontend surface](#frontend-surface)
9. [Feature catalog](#feature-catalog)
10. [Visual design system](#visual-design-system)
11. [Animation system](#animation-system)
12. [Dark mode end-to-end](#dark-mode-end-to-end)
13. [Performance & caching](#performance--caching)
14. [Security & auth](#security--auth)
15. [Infrastructure & deploy](#infrastructure--deploy)
16. [Testing](#testing)
17. [Scaffolded but not yet wired](#scaffolded-but-not-yet-wired)
18. [Known issues / open punch list](#known-issues--open-punch-list)

---

## TL;DR + what changed since v2

Smappen is a multi-tenant territory mapping + demographics + competitive-intelligence platform for retail, franchise, and sales-territory planners. Live at **https://smappen.mygreendock.com**.

The product surface is split across four persistent surfaces around a Google Maps canvas:
- **Left panel (360px)** — area list, sticky filter/sort/group chips, area thumbnails, drag-reorder, bulk select, visibility toggles, eye/star/menu always visible
- **Right panel** — opens on area selection; tabs use an icon+label segmented control (Overview / People / Businesses / Data); includes compare button + breadcrumb
- **Right toolbar** — vertical 40px icon strip; collapses to 3-button minimum, has 3D-tilt toggle, screenshot, advanced ✨ sparkle, etc.
- **Advanced panel (10 tabs)** — Territories, **Analogs**, **Analytics** (drive-time matrix + rebalancer + forecast), Cannibalize, Traffic, Optimize, Segments, Comments, Versions, Competitors, Field notes

Plus floating chrome:
- **Heatmap panel** + animated loading pill
- **Map style toggle** (Detailed / Clean) in bottom-left
- **Daypart panel** (24-hour traffic animation) docked along the bottom
- **Command palette** (Ctrl+/) — global jump to project/area/action
- **Onboarding checklist** — 5-step gamified intro for new accounts
- **What's New modal** — once-per-deploy release notes
- **Quick-stats strip** — Areas / Reach / Favorites ribbon when ≥3 areas exist

### Headline changes since v2

| Domain | v2 → v3 |
|---|---|
| Census coverage | DC/MD/VA/WV (4,425 tracts) → **all 50 states + DC (84,415 tracts, 3,235 counties, 56 states/territories)** |
| Migrations | 9 → **14** (added: favorites, geometry-type, sort_order, ops_features, data_scale_features) |
| Controllers | 22 → **31** (added: Analog, DriveTimeMatrix, TerritoryRebalancer, Forecast, Ops, Crm, Presence) |
| Services | 14 → **18** (added: Analog, DriveTimeMatrix, PdfReport, FootTraffic stub, Permits stub, Permissions) |
| Advanced tabs | 9 → **11** (added: Analogs, Analytics) |
| Brand color palette | 10 → **24 named colors** (Crimson … Aqua) |
| Visual tweaks | — → **25 shipped** (named VT1-VT25) |
| Operational tweaks | — → **25 shipped** (named OP1-OP25) |
| New features | — → **5 shipped** (NF1 drive-time matrix · NF2 rebalancer · NF3 forecast · NF4 PWA · NF5 3D tilt) |
| Bug fixes | — → **10 shipped** (BF1-BF10), then a second wave of UX fixes |
| Dark mode | Partial CSS variables → **comprehensive `[data-theme=dark]` override layer + dark Google Maps style** |
| Frontend bundle | ~250KB / 75KB gz → **~320KB / 95KB gz** |

---

## Tech stack

### Backend
- **PHP 8.3 / 8.4** — custom router (no framework), PSR-4 autoload under `App\`
- **MySQL 8.0.45** with spatial features (SRID 4326 throughout; unified storage convention X=lat, Y=lng post-2026-05-24 normalize)
- **Apache 2.4** with `mod_deflate` (Brotli not yet enabled — rec #20 in tuning roadmap)
- **PHP-FPM** with OPcache; per-request `memory_limit` bumps for heavy spatial paths (512–768MB on Analog Finder, Heatmap)
- **Composer** deps:
  - `firebase/php-jwt` — JWT issue/verify
  - `stripe/stripe-php` — billing
  - `vlucas/phpdotenv` — env loader
  - `phpoffice/phpspreadsheet` — XLSX import
  - `tecnickcom/tcpdf` — branded PDF reports (new in v3, NF20)
  - `monolog/monolog` — structured logging
  - Dev: `phpunit/phpunit`

### Frontend
- **React 18** + **TypeScript 5.5** + **Vite 5** (output → `public/app/`)
- **Tailwind v4** via `@tailwindcss/vite`, `@source "../**/*.{ts,tsx,html}"` so JIT scans real component code
- **Zustand** — auth, project, map, cost, undo, uiPrefs stores
- **TanStack React Query** v5 — demographics + analogs caching
- **react-google-maps/api** — `useJsApiLoader` with libraries `['drawing','visualization','geometry','places']`
- **react-router-dom** v6 — public + protected routes
- **react-hot-toast** — restyled with `.sm-toast` class (white card, colored left stripe, slide-in)
- **axios** — global interceptors (auth bearer, 401-logout, cost-tracking via `_meta` field)
- **lucide-react** — icons (every UI surface)
- **recharts** — bar/line charts inside Demographics + Comparison panels
- **echarts** — heavier dashboards (`monthlyreport.php`-style usage)
- **papaparse**, **xlsx** — CSV/XLSX import parsing
- **@googlemaps/markerclusterer** — branded violet cluster bubbles for imported points
- **vitest** dev dep for unit tests

### External services
- **Google Maps Platform** — Maps JS, Geocoding, Places (new v1 API), Static Maps
- **OpenRouteService** (hosted) — isochrones (`Accept: application/geo+json`, smoothing=0, 90s timeout); drive-time matrix
- **US Census Bureau** ACS 5-year (2023 vintage) — demographics for all 50 states + DC
- **TIGER 2023** shapefiles — tract / county / state geometries via `seed-all-states.sh`
- **Stripe** — billing (webhook signature verified at controller AND service)
- **Optional** (env-gated, activates when key present):
  - **Anthropic** (`claude-haiku-4-5-20251001`) — AI site scoring fallback to a local heuristic when unset
  - **Postmark / Resend** — transactional email
  - **Slack incoming webhooks** — per-user competitor alerts
  - **Redis** — cache/presence/rate-limit; falls back to MySQL `cache` table
  - **DigitalOcean Spaces** (S3 SigV4 virtual-hosted) — uploaded photos + report PDFs
  - **SafeGraph / Placer.ai / Foursquare** — foot-traffic provider (driver stubs in place, see `FootTrafficService.php`)
  - **HUD SOCDS / Cherre / ATTOM** — building-permits provider (driver stubs in `PermitsService.php`)
  - **Salesforce / HubSpot** — CRM integration (OAuth scaffold in `CrmController.php`)
  - **Python ML sidecar** (`ml-sidecar/`) — XGBoost demand forecast when `ML_SIDECAR_URL` set; falls back to in-PHP k-NN otherwise

### Hosting
- DigitalOcean Droplet at `143.244.144.7`, Ubuntu 24.04
- Coexists with the GreenDock project under the same Apache vhost layout (`smappen.conf` + `smappen-le-ssl.conf`)
- Let's Encrypt SSL via ACME
- Standby + failover plan documented in `docs/failover.md` (rec #16) — not yet provisioned, scripts in place

---

## Architecture & data flow

```
┌──────────────────────────────────────────────────────────────────┐
│  Browser: React SPA at /app/* (Vite bundle, manifest, sw.js v4) │
└────────────────┬─────────────────────────────────────────────────┘
                 │ HTTPS · JWT (Bearer) or X-Api-Key (sm_…)
                 ▼
┌──────────────────────────────────────────────────────────────────┐
│  Apache 2.4 → PHP-FPM 8.3                                        │
│   ├ /api/*  → public/index.php → Router → middleware → controller│
│   ├ /app/*  → SPA shell (Vite build assets)                      │
│   ├ /app/sw.js + manifest.webmanifest (PWA, v4)                 │
│   └ /storage/* — uploaded files + generated report PDFs         │
└────────────────┬─────────────────────────────────────────────────┘
                 │
   ┌─────────────┼─────────────┬─────────────┬─────────────┐
   ▼             ▼             ▼             ▼             ▼
┌──────┐    ┌───────┐    ┌────────┐    ┌──────────┐  ┌──────────┐
│MySQL │    │APCu/  │    │ ORS    │    │ Google   │  │ Census   │
│ 8.0  │    │Redis  │    │ /v2/   │    │ Maps +   │  │ ACS API  │
│      │    │(opt)  │    │ /iso + │    │ Places + │  │          │
│      │    │       │    │ /matrix│    │ Geocode  │  │          │
└──────┘    └───────┘    └────────┘    └──────────┘  └──────────┘
                                            │
                                            ▼
                              ┌──────────────────────────┐
                              │  api_usage_log + cost    │
                              │  → /api/usage/today      │
                              │  → header widget + toast │
                              │  → map-load logged once  │
                              │     per browser session  │
                              └──────────────────────────┘
```

**Request lifecycle (typical authenticated POST):**

1. Frontend axios → `/api/...` with `Authorization: Bearer <JWT>` or `X-Api-Key: sm_…`
2. `public/index.php` boots Config from `.env`, sets `Response::corsHeaders()`
3. `Router` matches regex pattern, executes middleware stack: `Middleware::auth()` → `rateLimit()` → controller
4. `auth()` verifies JWT signature (HS256), checks `revoked_tokens` for the `jti`, checks `users.tokens_invalid_before` against `iat`, loads `user + organization.plan` into `$request->user`
5. Controller runs; spatial queries use the unified storage convention (X=lat, Y=lng) so WKT polygon literals are emitted in `(lat lng)` order — see "Storage convention" below
6. Response wrapped as `{success: true, data: {...}}`; Google-API-backed responses also carry `_meta.api_name + estimated_cost_usd`
7. Frontend `client.ts` interceptor:
   - Success + `_meta` → `useCostStore.trackCall(cost)` → header widget bump + bottom-right toast (batched 600ms so 200 batch-geocodes show one summary toast)
   - 401 → fire-and-forget `authApi.logout()`, redirect `/login`

**Storage convention (unified post-v2):**
All `geometry` columns (`census_tracts`, `census_counties`, `census_states`, `areas`, `competitor_monitors`, `field_notes`) store coordinates with **X=lat, Y=lng** positionally (matches EPSG 4326 canonical lat-first order). This is enforced at every write site:
- `seed-census.php` + `aggregate-geographies.php`: `GeoUtils::swapGeometry()` pre-swap before `geoJsonToWkt`, then ST_GeomFromText emits WKT in `lat lng` order
- `Area::create/update` (`src/Models/Area.php`): same pre-swap pattern
- `HeatmapController::tracts`: viewport polygon WKT built in `lat lng` order
- `TerritoryGenerator::loadTracts`: envelope WKT in `lat lng` order
- `TerritoryRebalancerController`: point WKTs for `ST_Contains` in `lat lng` order

On the read side, MySQL's `ST_AsGeoJSON` for SRID 4326 emits `[Y, X]` — which under this storage = `[lng, lat]` = **standard GeoJSON**. Therefore **no `swapGeometry()` call on read** is necessary. The two one-shot normalizers under `scripts/` (`normalize-dmv-geometry.php`, `normalize-areas-geometry.php`) flipped pre-v2 data to match.

**Background work:**
- `scripts/job-worker.php` — every 10s via cron; claims jobs atomically via `SELECT … FOR UPDATE SKIP LOCKED`; runs territory generation, competitor scans, webhook deliveries
- `scripts/cleanup-cron.php` — hourly; expires `cache`, `auth_tokens`, `revoked_tokens`, old jobs/webhook_deliveries (30d retention), orphan upload/export files
- `scripts/competitor-scan.php` — every 15min; picks monitors due on `next_run_at`
- `scripts/refresh-census.php` — annual (Jan 15); seed → aggregate → segment → **`compute-tract-features.php` (new)** → cache flush
- `scripts/compute-tract-features.php` — nightly (new); rebuilds `tract_features` 18-dim vectors + `analog_norm_stats` materialized row
- `scripts/backup-db.sh` — nightly mysqldump → gzip → 30-day daily + 12-month monthly retention + optional rclone to Spaces

---

## Database schema (14 migrations)

| # | File | Tables / changes |
|---|---|---|
| 001 | `001_initial_schema.sql` | `organizations`, `users`, `projects`, `folders`, `areas` (POLYGON SRID 4326 + SPATIAL INDEX), `imported_points` (POINT), `poi_cache`, `census_tracts` (MULTIPOLYGON), `census_demographics`, `reports`, `api_usage_log`, `audit_log` |
| 002 | `002_cache_table.sql` | `cache` (key VARCHAR(255), value LONGTEXT, expires_at) |
| 003 | `003_demographics_indexes.sql` | Indexes on census tables for state-FIPS + tract-level lookups |
| 004 | `004_aggregated_geo_and_tile_cache.sql` | `census_counties`, `census_states` (rolled-up demographics for zoom LOD), `heatmap_tile_cache`, `reach_cache` |
| 005 | `005_advanced_features.sql` | `territory_generation_jobs`, `tract_segments`, `project_versions`, `comments`, `change_log`, `project_collaborators` (viewer/editor/admin/owner), `approval_requests`, `field_notes`, `competitor_monitors`, `competitor_scans`, `tracked_places`, `competitor_alerts`, `notifications`. Adds `areas.generation_job_id` + `areas.territory_index` |
| 006 | `006_auth_and_jobs.sql` | `auth_tokens`, `revoked_tokens`, `jobs`, `webhook_subscriptions`, `webhook_deliveries`. Adds `users.email_verified_at`, `users.api_key_hash/_last4`, notification prefs (`notify_email`, `notify_competitor_alerts`, `notify_team_activity`, `slack_webhook_url`), `users.theme`. Adds `projects.share_expires_at`, `projects.share_view_count` |
| 007 | `007_role_rename.sql` | `project_collaborators.role='approver'` → `'admin'` |
| 008 | `008_bug_fixes.sql` | Adds `users.tokens_invalid_before` (bulk JWT revoke marker); normalizes role enum; uses INFORMATION_SCHEMA prepared-stmt guards for re-runnability |
| 009 | `009_api_cost_tracking.sql` | `api_usage_log.estimated_cost_usd` (DECIMAL(10,6)) + `idx_usage_cost_day` |
| **010** | `010_area_favorites.sql` | Adds `areas.is_favorite TINYINT(1) DEFAULT 0` + `idx_area_favorite` |
| **011** | `011_areas_geometry_type.sql` | Changes `areas.geometry` POLYGON → **GEOMETRY** so MultiPolygon territory outputs fit; drops + re-adds SPATIAL INDEX (tied to column type) |
| **012** | `012_areas_sort_order.sql` | Adds `areas.sort_order INT DEFAULT 0` + covering index `(project_id, sort_order, created_at)` — for drag-reorder persistence (BF7) |
| **013** | `013_ops_features.sql` | Adds `projects.archived_at` (OP15 soft-archive); new tables: `saved_analog_searches`, `saved_comparisons`, `activity_log` (org-scoped feed), `tags` + `area_tags` (m:n), `scheduled_reports` |
| **014** | `014_data_scale_features.sql` | `tract_features` (18-dim normalized vector per tract, FK to census_tracts), `census_demographics_history` (geoid + data_year PK, multi-vintage trend ingest), `analog_norm_stats` (single-row materialized stats + gzipped density-values blob), `area_permissions` + `folder_permissions` (per-resource role grants viewer/editor/owner) |

**Spatial functions used:**
`ST_GeomFromText`, `ST_AsGeoJSON(g, precision)`, `ST_AsText`, `ST_Intersects`, `ST_Intersection`, `ST_Area`, `ST_Contains`, `ST_Distance_Sphere`, `ST_Centroid(ST_SRID(g, 0))` (planar workaround — MySQL 8 refuses ST_Centroid on geographic SRS, and ST_SRID-to-0 implicitly swaps axes for SRID 4326), `ST_Union` (pairwise iterative in PHP via divide-and-conquer tree — MySQL's ST_Union is binary not aggregate), `ST_GeometryType` filter for GEOMETRYCOLLECTION edge cases on tracts touching only at boundaries, `ST_X` / `ST_Y` / `ST_PointFromText`.

**Coverage:** 84,415 census tracts across all 50 states + DC; 3,235 counties; 56 state/territory entities (50 states + DC + 5 territories). All classified into one of 10 customer segments (`tract_segments` table) via rule-based segmentation.

---

## Backend surface — controllers + endpoints

### Public (no auth)
| Method | Path | Controller | Purpose |
|---|---|---|---|
| GET | `/api/health` | HealthController | Liveness probe; returns `{ok, db, version, environment, elapsed_ms}`; 503 on DB failure |
| POST | `/api/auth/register` | AuthController | Create account + org |
| POST | `/api/auth/login` | AuthController | Issue JWT (with `jti` for revocation tracking) |
| POST | `/api/auth/request-reset` | AuthController | Email a 1h password-reset token |
| POST | `/api/auth/reset` | AuthController | Redeem reset token, set new password, stamp `users.tokens_invalid_before` |
| GET | `/api/auth/verify-email` | AuthController | Redeem email-verify token |
| GET | `/api/shared/{shareToken}` | ProjectController | Legacy share-link payload |
| GET | `/api/public/projects/{token}` | PublicShareController | Public read-only project payload |
| GET | `/api/public/projects/{token}/embed` | PublicShareController | Lighter embed payload (geometry only) |
| POST | `/api/billing/webhook` | BillingController | Stripe webhook — signature verified at controller AND service |
| GET | `/api/openapi.json` | OpenApiController | OpenAPI 3.1 spec (includes Analog Finder endpoint) |
| GET | `/api/docs` | OpenApiController | Swagger UI page |

### Auth (JWT or `X-Api-Key`)
| Method | Path | Controller |
|---|---|---|
| POST | `/api/auth/refresh` | AuthController |
| POST | `/api/auth/logout` | AuthController |
| GET | `/api/auth/me` | AuthController |
| POST | `/api/auth/resend-verification` | AuthController |
| PUT | `/api/auth/profile` | AuthController |
| POST | `/api/auth/change-password` | AuthController |
| GET | `/api/auth/api-key` | AuthController |
| POST | `/api/auth/api-key/regenerate` | AuthController |

### Core mapping
| Method | Path | Notes |
|---|---|---|
| GET/POST | `/api/projects[/{id}]` | CRUD; org-scoped |
| **POST** | `/api/projects/{id}/archive` | OP15 — soft-archive toggle (`{archived: true\|false}`) |
| **GET** | `/api/projects/{id}/export` | OP2 — JSON bundle download (`.smappen.json`) |
| GET/POST | `/api/projects/{projectId}/folders` + `/api/folders/{id}` | |
| GET/POST | `/api/projects/{projectId}/areas` + `/api/areas/{id}` | |
| **POST** | `/api/projects/{projectId}/areas/reorder` | BF7 — drag-reorder persistence |
| POST | `/api/areas/reach` | Smart area-sizing |
| POST | `/api/demographics/preview` | Live demographics for a candidate geometry |
| POST | `/api/isochrone/calculate` | ORS isochrone |
| GET | `/api/areas/{id}/demographics` | CensusService::getDemographicsForArea |
| GET | `/api/areas/{id}/pois` | Reads from `poi_cache` |
| POST | `/api/areas/{id}/rebuild-boundary` | TerritoryController — replaces convex-hull with `ST_Union` |
| POST | `/api/projects/{projectId}/territories/rebuild-all` | OP23 — bulk rebuild |

### Heatmap
| Method | Path | Notes |
|---|---|---|
| GET | `/api/heatmap/tracts` | Viewport bbox → tract/county/state choropleth FeatureCollection. Server-side bbox-quantized cache (7d) keyed on (metric, level, bbox). Auto-level: state below z=6, county z=6–9, tract z=9+ |

### Geocoding & Places (Google-billed, rate-limited)
| Method | Path | Rate limit | Notes |
|---|---|---|---|
| POST | `/api/geocode` | 500/hr | Single-address |
| POST | `/api/geocode/batch` | 20/hr | Batched; counts each address as one `geocode` log |
| POST | `/api/places/nearby` | 300/hr | **Routes to `searchText` when no Table-A type is given** (fixed silent zero-results for "Any" and "Retail" chips). Maps frontend chip values (`store`) → valid Table-A types (`grocery_store`) |
| POST | `/api/places/search` | 300/hr | Text search |
| GET | `/api/places/{placeId}` | auth | Place details |

### Import / Export / Reports
| Method | Path | Notes |
|---|---|---|
| POST | `/api/projects/{projectId}/import/upload` | 20/hr |
| POST | `/api/projects/{projectId}/import/configure` | 20/hr |
| GET | `/api/imports/{batchId}/status` | |
| DELETE | `/api/imports/{batchId}` | |
| GET | `/api/projects/{projectId}/export/areas` | |
| GET | `/api/areas/{areaId}/export/pois` | |
| GET | `/api/projects/{projectId}/export/points` | |
| GET | `/api/exports/{filename}` | |
| POST | `/api/areas/{id}/report` | 50/hr — JSON report |
| **POST** | `/api/areas/{id}/report.pdf` | NF20 — **branded TCPDF report**. Cover page + headline stats + age table + methodology footer |
| GET | `/api/reports` | |
| GET | `/api/reports/{id}/download` | |

### Advanced (the ✨ sparkle panel)
| Method | Path | Notes |
|---|---|---|
| GET | `/api/projects/{projectId}/cannibalization` | |
| POST | `/api/projects/{projectId}/territories/generate` | 30/hr — k-means territory generator |
| GET | `/api/projects/{projectId}/territories/jobs` | |
| POST | `/api/isochrone/traffic` + `/grid` + `/day` | Traffic-adjusted isochrones (Daypart) |
| POST | `/api/projects/{projectId}/optimize/locations` | MCLP optimization |
| GET | `/api/areas/{id}/segments` | Segmentation results |
| GET | `/api/projects/{projectId}/competitor-monitors` + CRUD | |
| GET | `/api/competitor-monitors/{id}/places` | |
| GET | `/api/projects/{projectId}/comments` + CRUD | |
| GET | `/api/projects/{projectId}/versions` + CRUD | |
| GET/POST | `/api/projects/{projectId}/field-notes` | |
| POST | `/api/areas/{id}/ai-score` | Anthropic Haiku-backed AI scoring + local fallback |
| **POST** | `/api/areas/{id}/analogs` | NF Analog Finder — 30/hr, finds top-N matching tracts by 18-dim cosine similarity |
| **POST** | `/api/drive-time-matrix` | NF1 — 20/hr, N×M ORS matrix call |
| **POST** | `/api/projects/{projectId}/rebalance` | NF2 — sales territory rebalancer (analyze + optional `redraw=true` for k-means redraw) |
| **POST** | `/api/areas/{id}/forecast` | NF3 — demand forecast via k-NN regression in analog space (XGBoost-ready via ML sidecar) |

### Operational tweaks (OP — new CRUD)
| Method | Path | Notes |
|---|---|---|
| GET/POST/DELETE | `/api/saved-searches[/{id}]` | OP4 — saved Analog Finder configs |
| GET/POST/DELETE | `/api/saved-comparisons[/{id}]` | OP5 — saved comparison snapshots |
| GET | `/api/activity` | OP9 — org-wide activity feed |
| GET | `/api/webhooks/deliveries` | OP11 — webhook delivery history viewer |
| GET/POST | `/api/tags` | OP21 — org-wide tags |
| POST | `/api/areas/{id}/tags` | OP21 — attach tag to area |
| DELETE | `/api/areas/{id}/tags/{tagId}` | OP21 — detach |
| GET/POST/DELETE | `/api/scheduled-reports[/{id}]` | OP13 — emailed report schedules |

### Cost tracking
| Method | Path | Notes |
|---|---|---|
| GET | `/api/usage/today` | Total + per-API breakdown for today |
| GET | `/api/usage/days` | Last 30 days bucketed |
| GET | `/api/usage/pricing` | Per-call rate card |
| **POST** | `/api/usage/log-map-load` | Logs one `dynamic_maps_load` event per browser session — the biggest line item on a typical bill, previously unaccounted for |

### Realtime collaboration (#13 scaffold)
| Method | Path | Notes |
|---|---|---|
| POST | `/api/projects/{projectId}/presence/ping` | Cursor + selected-area position; APCu or MySQL fallback |
| GET | `/api/projects/{projectId}/presence/stream` | Server-Sent Events; broadcasts peer cursors at ~750ms cadence; 55s connection budget then client reconnects |

### CRM integrations (#21 scaffold)
| Method | Path | Notes |
|---|---|---|
| POST | `/api/integrations/salesforce/connect` | Returns OAuth URL |
| GET | `/api/integrations/salesforce/callback` | Token exchange (stub) |
| POST | `/api/integrations/salesforce/push` | Push area demographics to SF Account custom fields (stub) |
| POST | `/api/integrations/hubspot/connect` | Returns OAuth URL |
| GET | `/api/integrations/hubspot/callback` | Stub |
| POST | `/api/integrations/hubspot/push` | Stub |

### Background jobs + webhooks
| Method | Path | Notes |
|---|---|---|
| GET | `/api/jobs/{id}` | Job status polling |
| GET/POST/PUT/DELETE | `/api/webhook-subscriptions[/{id}]` | |
| POST | `/api/webhook-subscriptions/{id}/test` | Sends a synthetic event |

### Notifications
| Method | Path | Notes |
|---|---|---|
| GET | `/api/notifications` | |
| POST | `/api/notifications/mark-read` | |
| POST | `/api/notifications/mark-all-read` | |

### Billing (Stripe)
| Method | Path | Notes |
|---|---|---|
| POST | `/api/billing/checkout` | Stripe Checkout session |
| GET | `/api/billing/subscription` | |
| POST | `/api/billing/portal` | Customer-portal redirect |
| POST | `/api/billing/cancel` | |

---

## Services layer

| Service | File | Responsibility |
|---|---|---|
| `AuthService` | Used by `AuthController` | Password hashing (bcrypt cost=12), reset-token issuing |
| `CensusService` | `src/Services/CensusService.php` | ACS fetch + per-area demographics weighted by tract-overlap |
| `GoogleMapsService` | `src/Services/GoogleMapsService.php` | Geocode, batch-geocode, Places (new v1) `searchNearby` + `searchText`, place details, static map URL builder |
| `GeoUtils` | `src/Services/GeoUtils.php` | GeoJSON↔WKT, swap-coords, point-in-polygon, circle generator, polygon area, polyline encoding |
| `CacheService` | `src/Services/CacheService.php` | get/set/getJson/delete/flush; Redis if `REDIS_URL` set, MySQL `cache` table otherwise |
| `IsochroneService` | `src/Services/IsochroneService.php` | ORS isochrone wrapper + 24h cache |
| `ReachService` | inside `ReachController` | Smallest-radius-for-population binary search |
| `HeatmapService` | inside `HeatmapController` | Bbox-quantized tile cache, 7d retention |
| `TerritoryGenerator` | `src/Services/TerritoryGenerator.php` | k-means + `ST_Union` (pairwise div-and-conquer, MAX_TRACTS_FOR_UNION=500) |
| `SegmentService` | runs in `scripts/segment-tracts.php` | 10-segment rule-based tract classifier |
| `TrafficService` | `src/Services/TrafficService.php` | Day-of-week × hour traffic multiplier table |
| `GooglePricing` | `src/Services/GooglePricing.php` | Static rate card; `costFor($apiName, $count)` |
| **`AnalogService`** (new) | `src/Services/AnalogService.php` | 18-dim feature vector builder; cosine similarity with per-dim weights + null-skip; 6-axis radar collapse; reads from `tract_features` + `analog_norm_stats` materialized tables |
| **`DriveTimeMatrixService`** (new) | `src/Services/DriveTimeMatrixService.php` | ORS `/v2/matrix/{mode}` wrapper with chunking (49×49 ORS limit) + 7-day cache |
| **`PdfReportService`** (new) | `src/Services/PdfReportService.php` | TCPDF branded report with cover + demographics + age table + methodology footer; persists to `reports` table |
| **`Permissions`** (new) | `src/Services/Permissions.php` | `canReadArea/canWriteArea/canDeleteArea` — resolves org-admin → explicit area grant → folder grant → org-default editor |
| **`FootTrafficService`** (stub) | `src/Services/FootTrafficService.php` | Provider abstraction (SafeGraph / Placer / Foursquare); returns null when env-key absent |
| **`PermitsService`** (stub) | `src/Services/PermitsService.php` | Same shape — HUD SOCDS / Cherre / ATTOM drivers slot in |

---

## Background jobs + scripts

| Script | Cadence | Purpose |
|---|---|---|
| `scripts/job-worker.php` | 10s loop | Queue worker — territory generation, competitor scans, webhook deliveries |
| `scripts/competitor-scan.php` | 15min | Picks `competitor_monitors` due on `next_run_at` |
| `scripts/cleanup-cron.php` | hourly | Expires cache + tokens + jobs + orphan files |
| `scripts/refresh-census.php` | annual | Census re-ingest pipeline |
| **`scripts/seed-all-states.sh`** (new) | one-shot | TIGER 2023 download → ogr2ogr → seed-census → aggregate. Resumable. Logs to `/var/log/smappen-census-seed.log` |
| `scripts/seed-census.php` | inside seed-all-states | Pre-swaps coords via `GeoUtils::swapGeometry` before WKT (fixes "Latitude out of range" for western states) |
| `scripts/aggregate-geographies.php` | inside seed-all-states | County + state rollups + demographics aggregation; same axis pre-swap |
| **`scripts/normalize-dmv-geometry.php`** (new) | one-shot | Flipped pre-v2 DMV tracts to the unified storage convention. Probe-based idempotency check |
| **`scripts/normalize-areas-geometry.php`** (new) | one-shot | Same for `areas` table; clears `demographics_cache` so right panel re-fetches |
| **`scripts/compute-tract-features.php`** (new) | nightly | Rebuilds `tract_features` 18-dim vectors + `analog_norm_stats` row (gzipped sorted density array) |
| `scripts/backup-db.sh` | nightly | mysqldump → gzip → 30-day daily + 12-month monthly + optional rclone to DO Spaces |
| `scripts/verify-backup.php` | weekly | Restores last backup into a scratch schema, checks row counts |
| `scripts/compute-sri.php` | on demand | SHA-384 SRI hashes for any new vendored JS/CSS |
| `scripts/migrate.php` | on deploy | Runs new migrations idempotently |
| **`scripts/debug-places.php`** (new) | manual | End-to-end Places + point-in-polygon probe against a real area |

---

## Frontend surface

### Routing (`App.tsx`)

```ts
// Public
'/login'                LoginPage
'/register'             RegisterPage
'/forgot-password'      ForgotPasswordPage
'/reset-password'       ResetPasswordPage
'/verify-email'         VerifyEmailPage
'/pricing'              PricingPage
'/changelog'            ChangelogPage    // new (#24)
'/share/:token'         SharedProjectPage
'/embed/:token'         EmbedProjectPage

// Settings (protected)
'/settings/profile'     ProfileSettings
'/settings/team'        TeamSettings
'/settings/integrations' IntegrationsSettings
'/settings/api'         ApiKeySettings
'/settings/webhooks'    WebhookSettings
'/settings/billing'     BillingSettings

// App (protected catch-all)
'/*'                    AppLayout
```

### AppLayout structure
- `<Header>` — gradient logo tile with shimmer-on-hover, project switcher (`g` then `p` shortcut), undo/redo + undo-history dropdown (OP8), Google API cost widget (popover with breakdown), notifications bell, share, user-menu avatar with org name + plan badge + dark-mode toggle
- `<MapCanvas>` (fills row) — Google Map + heatmap layer + drawing tools + area polygons + center pins + analog markers + time-machine polygon + map-style toggle (bottom-left) + dark-mode aware Google Maps style + Web Mercator screenshot capture
- `<LeftPanel>` — 360px wide, area list with quick-stats strip, sticky filter chips, group-by, drag-reorder
- `<RightPanel>` — selected area; icon-tabs (Overview / People / Businesses / Data); breadcrumb; compare button → modal; share + export buttons
- `<RightToolbar>` — 40px icon strip with vertical tooltips, active-state rings, 3D-tilt toggle (NF5), collapse to 3 buttons
- `<AreaCreator>` — slide-out sidebar panel (NOT a modal anymore), 380px wide, 4 creation modes (Travel time / Reach population / Pure radius / Draw on map)
- `<AdvancedPanel>` — slide-in from right; 10 lazy-loaded tabs
- `<TimeMachinePanel>` — bottom-docked Daypart strip
- `<ShortcutsModal>` — `?` to open
- `<CommandPalette>` — Ctrl+/ to open (VT8)
- `<OnboardingChecklist>` — bottom-right corner (OP24)
- `<WhatsNewModal>` — once-per-deploy (OP19)
- Toaster — restyled with `.sm-toast` + colored left stripe per type (VT25)

### Component tree (key components — new ones in **bold**)

```
src/components/
├── ErrorBoundary.tsx
├── advanced/
│   ├── AdvancedPanel.tsx           — 10-tab orchestrator
│   ├── TerritoriesTab.tsx
│   ├── **AnalogTab.tsx**           — NF Analog Finder UI
│   ├── **AnalyticsTab.tsx**        — NF1/NF2/NF3 combined (DTM / Rebalance / Forecast sub-modes)
│   ├── CannibalizeTab.tsx
│   ├── TrafficTab.tsx
│   ├── OptimizeTab.tsx
│   ├── SegmentsTab.tsx
│   ├── CommentsTab.tsx
│   ├── VersionsTab.tsx
│   ├── CompetitorsTab.tsx
│   ├── FieldTab.tsx
│   └── shared.tsx                  — Spinner, Empty, Field, SkeletonRow
├── analytics/
│   ├── DemographicsPanel.tsx       — 42px hero number + age mini-bar + skeleton-on-load
│   ├── POISearchPanel.tsx          — POI category chips
│   └── ComparisonView.tsx          — modal w/ sticky delta bar
├── areas/
│   ├── AreaList.tsx                — sticky chips, group-by, drag-reorder, shape filter, bulk-select
│   ├── AreaCard.tsx                — SVG thumbnail (antimeridian-aware), portaled menu, always-visible eye/star/menu
│   ├── AreaCreator.tsx             — SIDEBAR-EXPANDING panel (no longer a modal)
│   ├── AreaEditor.tsx
│   ├── FolderTree.tsx              — folder color-stripe rows
│   └── **QuickStatsStrip.tsx**     — Areas/Reach/Faves ribbon (OP18)
├── auth/                           — login, register, forgot, reset, verify, protected route
├── billing/                        — pricing page, billing settings
├── common/
│   ├── EmptyState.tsx              — per-surface SVG illustrations
│   ├── ShortcutsModal.tsx
│   ├── **CommandPalette.tsx**      — Ctrl+/ global palette (VT8)
│   ├── **OnboardingChecklist.tsx** — 5-step gamified onboarding (OP24)
│   ├── **WhatsNewModal.tsx**       — release notes modal (OP19)
│   ├── **HelpHint.tsx**            — `?` icon w/ hover-popover (#25)
│   ├── **AnimatedNumber.tsx**      — ease-out cubic counter (VT12)
│   └── **SaveStatus.tsx**          — autosave indicator pill (OP6)
├── data/
│   ├── ReportButton.tsx
│   ├── ExportDialog.tsx
│   └── ImportWizard.tsx
├── layout/
│   ├── AppLayout.tsx               — orchestrator (calls useViewUrl, useDynamicFavicon, etc.)
│   ├── Header.tsx                  — gradient logo, project switcher, cost widget, undo dropdown, user menu
│   ├── LeftPanel.tsx               — 360px wide
│   ├── RightPanel.tsx              — icon tabs, compare button, breadcrumb
│   ├── RightToolbar.tsx            — collapsible, 3D tilt
│   └── FreeBanner.tsx
├── map/
│   ├── MapCanvas.tsx               — heatmap loading toast, dark-style observer, map-load logger
│   ├── AreaPolygon.tsx             — OverlayView hover card, selection glow (3s pulse), density-driven opacity, label-size gate
│   ├── AreaCenterPins.tsx          — largest-ring centroid for MultiPolygons (BF5)
│   ├── POIMarkers.tsx
│   ├── ImportedMarkers.tsx         — branded violet cluster bubbles (#12)
│   ├── DrawingTools.tsx
│   ├── ChoroplethLayer.tsx         — publishes features to mapStore for screenshot
│   ├── HeatmapPanel.tsx            — smoothly-animated legend marker, palette browser
│   ├── HeatmapLoadingToast         — defined inline in MapCanvas
│   ├── **ChoroplethWebGL.tsx**     — skeleton + shader source + earcut plan (#11)
│   └── TimeMachinePanel.tsx        — 24-hour heatstrip, clock-face indicator, hover tooltip
├── marketing/
│   └── **ChangelogPage.tsx**       — /changelog public page (#24)
├── settings/
│   ├── SettingsLayout.tsx
│   ├── ProfileSettings.tsx
│   ├── TeamSettings.tsx
│   ├── IntegrationsSettings.tsx
│   ├── ApiKeySettings.tsx
│   └── WebhookSettings.tsx
└── share/
    ├── SharedProjectPage.tsx
    └── EmbedProjectPage.tsx
```

### Hooks
- `useShortcuts` — n/d/c/b/f/l/?/⌘S/Esc + custom event for delete (OP7)
- `useTheme` — `data-theme` attribute driver, reads `localStorage('smappen-theme')`
- `useClickOutside`
- **`useDynamicFavicon`** — paints a 64×64 canvas favicon with area-count badge (VT22)
- **`useViewUrl`** — syncs map center/zoom to `#map=lat,lng,zoom` (VT17)

### Stores (Zustand)
- `authStore` — token + user persistence
- `projectStore` — current project + areas + folders + importedPoints
- `mapStore` — center, zoom, drawing state, heatmap state, time machine state, **+ analogResults + hoveredAreaId + hiddenAreaIds + heatmapFeatures**
- `costStore` — totalUsdToday + callCountToday + breakdown
- `undoStore` — past/future action stacks, busy lock
- `uiPrefsStore` — recentColors, onboardingCompleted, shortcutsModalOpen, **+ areaListFilter + areaListGroupBy + areaOrder + mapStyle + showPolygonLabels**

### Utilities (new in **bold**)
- `utils/geo.ts` — `allOuterRings`, `polygonCentroid`, `polygonBounds`, `geoJsonToGooglePaths`, `googlePolygonToGeoJson`
- `utils/format.ts` — `formatNumber`, `formatCurrency`
- `utils/colors.ts` — **`AREA_PALETTE_NAMED` (24 colors)**, legacy `AREA_PALETTE` alias, `pickColor`, `contrastInk`
- `utils/heatmapColors.ts` — 11 palettes (Smappen Pastel, Rainbow, Viridis, Magma, Plasma, etc.), `colorForValueWith`, `valueToFraction` (quantile-aware)
- `utils/mapStyle.ts` — `SMAPPEN_MAP_STYLE`, `SMAPPEN_MAP_STYLE_CLEAN`, **`SMAPPEN_MAP_STYLE_DARK`**
- `utils/mapExport.ts` — **composite screenshot**: Static Maps base + Web Mercator projection + heatmap polygons + area polygons + center pins → canvas → PNG
- **`utils/mapAnim.ts`** — `smoothFlyTo` cubic-eased pan+zoom (VT4)
- **`utils/snapToRoads.ts`** — Roads API caller + Chaikin smoothing (#17)
- **`utils/sessionRecord.ts`** — `MediaRecorder` capture of map canvas to .webm (OP17)
- **`utils/confetti.ts`** — dependency-free canvas confetti, respects reduced-motion (VT20)
- **`utils/toastBatch.ts`** — bucket+window rollup toast helper (VT9)

---

## Feature catalog

### Mapping core
- **Create new area** — sidebar slide-out (was modal), 4 modes:
  - **Travel time** — driving / cycling / walking isochrone via ORS
  - **Reach population** — smallest circle to hit N people (binary search by tract)
  - **Pure radius** — fixed km/mi, instant client-side polygon, no API call
  - **Draw on map** — freehand polygon (closes panel, arms drawing tool)
- Per-mode controls: travel-mode emoji buttons (🚗🚴🚶), time slider (1-120m) + 8 presets, population slider (500-1M) + 7 presets, radius slider + km/mi toggle + 7 presets
- Sliders show live value in violet extrabold
- Folder picker (when folders exist, indented tree)
- Notes textarea
- Auto-generated area name
- **24-color named palette** + recent colors row (persisted to uiPrefs)
- 64-vertex client-side circle generator for radius mode
- Live preview removed; calculation is explicit via "Calculate" button
- Slide-in animation 320ms cubic-bezier

### Demographics (right-panel "People" tab)
- 42px hero population number
- Density caption (per km²) + M/F percent split
- Age-distribution segmented bar with color legend
- Median household income card with bracket distribution
- Employment + unemployment colored by threshold
- Housing units + median value
- Data-freshness footer with ACS vintage + stale-flag (>18 months)
- Skeleton loaders matching final layout while computing
- **Auto-fetch via React Query** if demographics_cache empty

### Heatmap (choropleth)
- 11 palette options (Smappen Pastel default)
- Continuous gradient bar with **smoothly-animated marker** (180ms cubic-bezier) on hover
- Quantile-aware position (10 decile breaks)
- Hovered-tract name + value display
- Auto level (zoom-based) or explicit state/county/tract
- 12MB → 2MB gzip on the wire
- 7-day server cache keyed on (metric, level, bbox-quantized)
- 1h browser cache + adjacent-tile prefetch on idle (200-entry LRU)
- **Loading pill toast** at top-center of map during fetch (VT-class fix)
- Selected area's polygon stays visible at 35% fill opacity over the choropleth so user can simultaneously read demographics and view heatmap

### POI / Businesses
- POI category chip strip — Any / Food / Cafes / Retail / Pharmacy / Gyms / Schools / Health / Banks / Gas
- Chip key→type mapping in backend (e.g. `store` → `grocery_store`)
- Backend routes to `searchText` when no valid Table-A type → fixes silent zero-results
- Keyword + chip combine
- Results list with name / address / rating / phone / website
- Point-in-polygon filter against the area's geometry
- Cached per-area (`poi_cache`) keyed by `md5('area:'+id)`
- Live cost toast ("places_nearby · $0.032 · 1 call")

### Reports
- JSON report — endpoint exists, plan-gated
- **Branded TCPDF report** (NEW) — cover page with org + project, demographics table, age distribution percentages, methodology footer
- Static map snapshot endpoint with markers
- Scheduled reports (frequency, recipient_email, next_run_at) — DB + endpoint; cron worker pending

### Import / Export
- CSV/XLSX import via 3-step wizard (upload → map columns → preview/commit)
- Batch geocode with cost-per-call live counter
- Export areas as GeoJSON, KML, CSV
- Export POIs per area
- Export imported points
- **Project ZIP/JSON export** — `/api/projects/{id}/export` ships a `.smappen.json` bundle with project + folders + areas + cached demographics

### Auth & accounts
- JWT (HS256, jti, 7-day expiry, refresh path); `Authorization: Bearer` or `X-Api-Key: sm_…`
- Bcrypt cost=12, no rehash-on-login (todo)
- Bulk JWT revocation via `users.tokens_invalid_before`
- Per-session JWT revocation via `revoked_tokens.jti`
- Email verify + password reset (1h tokens)
- Per-user API key (sm_ prefix, last4 displayed)
- Account types: `admin` (org admin), `god` (super-role), `manager` / `dispatcher` / `sales` / `user` / `warehouse` / `driver`

### Settings
- Profile (name, email, notification prefs, slack webhook, theme)
- Team (invite, role assign)
- Integrations (webhook subscriptions — list/create/edit/delete/test)
- API key (regenerate, last4 display)
- Billing (Stripe portal, subscription status, cancel)
- Webhooks (delivery viewer wired but minimal)

### Team & collaboration
- `project_collaborators` (viewer/editor/admin/owner roles)
- Comments per project (replies, mentions scaffolded)
- Project versioning — snapshots via Cmd/Ctrl+S; viewable in Versions tab
- Approval requests for high-stakes mutations
- **Activity log** — every CRUD writes to `activity_log`, viewable via `/api/activity`
- **Per-area + per-folder permissions** (`area_permissions`, `folder_permissions` tables + `Permissions` service); default org-wide editor preserved
- **Realtime cursors** — `/api/projects/{id}/presence/{ping,stream}` via SSE (scaffolded, frontend cursor renderer pending)

### Notifications
- Bell icon in header with unread badge
- Polled every 60s
- Notification types: territory completion, scan results, comments, approvals, weekly summary

### Public sharing
- Per-project share token (cryptographically random, ≥8 chars validated)
- `/share/:token` — full read-only view
- `/embed/:token` — lighter geometry-only payload for iframe embeds
- Optional expiry; view counter

### Time Machine / Daypart
- "Daypart" panel docked along the bottom (`panel-slide-up` 0.24s ease-out)
- Three-row media-player layout:
  - Row 1: identity + clock-face hour indicator + day selector + duration selector + Run + cached badge
  - Row 2: play/pause + big hour number (22px tabular) + km² stat + multiplier + peak-shrink chip + speed dropdown
  - Row 3: heatstrip bar (24 cells) — clickable scrubber with smooth hover tooltip
- Mini 24-segment conic clock (`daypart-clock` CSS class)
- 24-color hour palette (overnight blues → cool morning → warm midday → evening purples)
- Keyboard: Space play/pause, ←/→ scrub, [/] speed, **Esc close (BF8)**
- Server-side dedup: 24 hours → ~6-8 unique ORS calls

### Cost tracking
- `api_usage_log` row per call with `estimated_cost_usd`
- **Map-load event** logged once per browser session via `sessionStorage` flag (the single biggest line on a typical Google bill, previously untracked)
- Header widget (popover): today's total + per-API breakdown + "Estimate" badge + GCP-console disclaimer
- Per-call toast batched 600ms (geocode_batch of 200 addresses = 1 toast not 200)
- Toast format: `${api_name} · ${formatUsd(cost)}` (e.g. `geocode · $0.005`)

### Advanced features

#### Analog Finder (NF)
- 18-dim normalized feature vector per tract (11 demographics + 3 segments + 2 competition + 2 accessibility)
- Cosine similarity with per-dim weights + null-pair skipping
- Pre-computed via `compute-tract-features.php` nightly into `tract_features` table
- Materialized `analog_norm_stats` (single row, gzipped density-array blob) for percentile-rank normalization
- Pre-resolves source-area excluded tracts via SPATIAL-indexed query, then NOT IN list
- Hard 5,000-tract cap on PHP-side scoring; sorted by distance to source center
- Default 200km radius (was "Entire US"); option still available
- 6-axis radar chart (Income & wealth / Age profile / Density & housing / Segment fit / Competition / Accessibility)
- Sticky "Find analogs" button so it doesn't scroll off
- Color-legend chip in map's bottom-left when results exist
- Numbered candidate pins with similarity-tier coloring; click pans + zooms via `smoothFlyTo`
- Auto-fits map bounds to source + results
- 30/hr rate limit (`analog_finder` bucket)

#### Analytics tab (NF1 + NF2 + NF3 in one)
Three sub-modes selected by a chip row:

**NF1 Drive-Time Matrix**
- Paste N origins + M destinations as `label, lat, lng`
- POST to `/api/drive-time-matrix`
- ORS `/v2/matrix/{mode}` chunked 49×49 batches
- N×M minutes table rendered inline
- 7-day cache keyed on (origins, destinations, mode)

**NF2 Sales Territory Rebalancer**
- Paste customers as `name, lat, lng, revenue`
- Server-side point-in-polygon classifies each into their current territory
- Computes per-territory revenue + delta vs target + imbalance %
- Suggests reassignments (top customers closest to neighboring territory)
- **v2 redraw** — `redraw=true` flag triggers revenue-weighted k-means + convex-hull-with-buffer rebuild; saves new "Rebalanced #N" areas tagged with `generation_job_id = rebalance-{ts}`

**NF3 Demand Forecast**
- Pick a candidate area
- Enter revenue for ≥3 existing areas
- Server runs k-NN regression (k=5) in the 11-dim demographic fingerprint space
- Returns predicted_revenue + 95% confidence interval + top contributors + similarities
- ML sidecar (`ml-sidecar/`) ready for XGBoost swap-in when `ML_SIDECAR_URL` set

#### Territory generation
- k-means++ initial centroids weighted by metric (population / income-weighted / housing units)
- 25 iterations or convergence
- Lloyd's algorithm + balance-swap to hit `max_imbalance_pct` constraint
- Cluster pairwise `ST_Union` via divide-and-conquer tree (was serial fold, 5-8× faster on 200+ tract clusters)
- MAX_TRACTS_FOR_UNION = 500 (was 80)
- Falls back to convex hull if union fails
- One area per resulting territory, named NW/NE/SE/SW by compass direction
- **OP23 bulk-rebuild endpoint** — POST `/api/projects/{id}/territories/rebuild-all` rebuilds every auto-generated territory's boundary

#### 3D extrusion view (NF5)
- Toolbar toggle calls `mapInstance.setTilt(45)` ↔ `setTilt(0)`
- Works at high zoom + Vector-mode mapId (needs that mapId in v.next)

#### Other advanced
- Cannibalization — pairwise overlap matrix + per-area exclusivity %
- Traffic-aware isochrones — `IsochroneService::adjustedMinutes` divides time by day×hour multiplier
- MCLP location optimization
- Tract segmentation (10 segments)
- Competitor monitors with scheduled scans + alerts
- Field notes (POINT geometry + GPS breadcrumbs)

### Onboarding & growth
- **Onboarding checklist** (OP24) — 5-step gamified card (create area / open demographics / favorite / 2 areas / 5 areas)
- **What's New modal** (OP19) — once-per-deploy via `LATEST_RELEASE` constant
- **Public `/changelog` page** (#24) — timeline of releases with bullet items
- **Inline help hints** (`<HelpHint>`) — `?` icon next to panel headers, hover-popover, locked-open on click

### OpenAPI / docs
- Hand-curated 3.1 spec at `/api/openapi.json` (auto-cached 5min)
- Swagger UI at `/api/docs`
- Covers all auth, project, area, demographics, places, isochrone, reach, billing, advanced endpoints
- **Analog Finder endpoint documented** (#21 of v2 was missing this)
- TODO: per-API-key analytics (#22 in tuning recommendations)

---

## Visual design system

### Typography
- **Nunito** — Google Fonts, weights 400/500/600/700/800/900 + display=swap
- 10/11/12/13/15/16/22/42px size scale
- `font-extrabold` for headlines, `font-bold` for stat values, `font-semibold` for labels
- `tabular-nums` on every numeric display (counts, percentages, $, ms)

### Color tokens (CSS variables on `:root`)
| Token | Light | Dark |
|---|---|---|
| `--brand` | #7848BB | #7848BB |
| `--brand-dark` | #6B37A6 | — |
| `--brand-light` | #EDE5F7 | #2d2147 |
| `--brand-50` | #F6F2FB | #1e1b2e |
| `--cta` | #E53935 | — |
| `--ink` | #1A1A2E | #f3f4f6 |
| `--ink-2` | #2D2D44 | #e5e7eb |
| `--body` | #4A4A5A | #cbd5e1 |
| `--slate` | #6B6B7B | #94a3b8 |
| `--muted` | #8E8E9A | #64748b |
| `--line` | #D1D1DB | #334155 |
| `--line-soft` | #E8E8EE | #1e293b |
| `--bg-panel` | #F3F3F7 | #1f2937 |
| `--bg` | #F9F9FB | #0f172a |

### Area palette (24 named colors)
6×4 grid in the color picker — Crimson, Tangerine, Amber, Goldenrod / Lime, Forest, Teal, Lagoon / Sky, Cobalt, Indigo, Violet / **Brand**, Plum, Magenta, Coral / Espresso, Mocha, Rust, Sunset / Slate, Graphite, Pewter, Aqua. Hover-tooltip names; ✓ on the selected color, ink color computed via `contrastInk`.

Recent-colors row (last 5) persisted to `uiPrefsStore.recentColors` localStorage.

### Heatmap palettes (11)
Smappen Pastel (default), Rainbow, Viridis, Magma, Plasma, Cividis, Turbo, Inferno, Cool-Warm, Brand Mono, Sequential Violet.

### Daypart palette (24 colors)
Per-hour color anchoring the polygon + heatstrip-bar color to a vibe: overnight cool blues → morning cyan → warm midday → evening purples.

### Radii & shadows
- `--radius-sm 6px`, `--radius 10px`, `--radius-lg 14px`, `--radius-xl 16px`
- `.shadow-float` — `0 4px 16px rgba(0,0,0,0.12)` for floating panels
- `.shadow-panel-left/-right` — directional 8px shadows for slide-in panels

### Skeleton loaders
- `.skeleton` — shimmer gradient (#e2e8f0 → #f1f5f9 → #e2e8f0), 1.4s ease-in-out infinite
- Layout-matched variants: `.skeleton-line w-1/2`, `.skeleton-rect-sm/md/lg`, `.skeleton-circle`
- Demographics, Advanced tabs, POI search, area list all show shape-matched skeletons during fetch

### Density target
Not minimalist, not dense. "Usable for any user age or demo on basically any screen size." Real usefulness over visual sophistication. Body text ≥ font-weight 500. Subheads ≥ #475569 weight 600. No light gray for body text.

### Anti-slop rules (in `CLAUDE.md`)
- No purple→pink hero gradients, neon, vaporwave
- No glassmorphism unless the existing page uses it
- No random new font families
- No oversized rounded corners (≥24px)
- No aggressive drop shadows or gradient borders
- No emojis in UI copy unless explicitly asked
- No "card with gradient background and white text" hero sections

---

## Animation system

`styles.css` defines a coherent animation toolkit; every class respects `@media (prefers-reduced-motion: reduce)`.

| Class | Duration | Easing | Use |
|---|---|---|---|
| `.panel-slide-right` | 0.22s | `cubic-bezier(0.16, 1, 0.3, 1)` | RightPanel, AdvancedPanel |
| `.panel-slide-left` | 0.22s | same | LeftPanel-ish slides |
| `.panel-slide-up` | 0.24s | same | TimeMachinePanel, OnboardingChecklist |
| `.panel-slide-down` | 0.22s | same | Toast notifications |
| `.panel-slide-in-l` | 0.32s | same | AreaCreator slide-out (NEW) |
| `.card-expand` | 0.16s | same | PortalMenu, dropdowns; `transform-origin: top` |
| `.stagger-in` | 0.28s | same | AreaList rows; per-row delay via `--stagger-i` (capped at 8) |
| `.fade-in` | 0.18s | ease-out | Star/menu pop-in on hover |
| `.sparkle-pulse` | 2.4s | ease-in-out infinite | Time Machine launcher CTA |
| `.brand-logo-tile` | 0.4s + 0.6s | ease | Header logo gradient sweep + slide shimmer on hover |
| `hoverCardIn` | 0.16s | same | Polygon hover card (OverlayView) |
| `polygon-glow-pulse` | 1.2s (was 1.2s, now polygon-pulse 3s) | sine wave | Selected area's stroke + fill drift (slower, calmer) |
| `point-bounce` | 1.4s | ease-in-out infinite | Empty-state arrow nudge |
| `progress-slide` | 1.5s | ease-in-out infinite | Indeterminate progress bar |
| `shimmer` | 1.4s | ease-in-out infinite | Skeletons |
| `logo-pulse` | 1.4s | ease-in-out infinite | Page-loading logo |
| `spin` | 0.7s | linear infinite | Spinner |
| `cardExpand` | 0.16s | same | Dropdown unfurl |
| Heatmap legend marker | 0.18s | `cubic-bezier(0.16, 1, 0.3, 1)` on `left` + `opacity` | Smoothly slides between tract values |
| `smoothFlyTo` (JS) | 0.35s | cubic ease-in-out | Combined pan + zoom for Maps |
| AnimatedNumber (JS) | 0.35s | cubic ease-out | Stat tile counters |
| Confetti (JS) | 1.5-2.5s | gravity + drag | Territory generation completion |

All keyframes opt out under `prefers-reduced-motion`.

---

## Dark mode end-to-end

Triggered by `:root[data-theme="dark"]`. The toggle lives in the user-menu dropdown and writes to `localStorage('smappen-theme')` which `useTheme` reads on mount.

### Coverage
1. **CSS variables** flipped for `--ink`, `--body`, `--slate`, `--line`, `--line-soft`, `--bg-panel`, `--bg`, `--brand-50`, `--brand-light`
2. **Comprehensive Tailwind-class override layer** in `styles.css`:
   - `bg-white` / `bg-white/*` → `#1f2937` (with `color: #e5e7eb` baseline)
   - `bg-slate-50/100`, `bg-violet-50/100`, `bg-emerald-50`, `bg-rose-50`, `bg-amber-50`, `bg-blue-50` → dark equivalents
   - `text-slate-{300..900}` → progressively lighter slate values
   - Inline `style={{ color: '#1A1A2E' }}` → `#f3f4f6` via attribute selector
   - `border-slate-{100..300}`, `border-violet-{100..200}`, `border-emerald-200`
   - `input/select/textarea` (CSS class + tag selector)
   - Hover states: `hover:bg-slate-50/100`, `hover:bg-violet-50`
   - Shadows softened (`.shadow-float` etc. — heavier black opacity for dark)
   - Backdrop-blur surfaces → translucent dark
   - Scrollbars (`*::-webkit-scrollbar` themed)
   - `.sm-toast` toast styling
   - SVG illustrations: `<path fill="#EDE5F7">` etc. remapped to dark fills via attribute selectors
3. **Google Maps style** — `MapCanvas` observes `<html data-theme>` mutations via `MutationObserver`, swaps to `SMAPPEN_MAP_STYLE_DARK` (deep blue water, dim land, muted POIs, light-gray text)
4. **Static Maps screenshot** — uses the same dark-style query params so exported PNGs match the on-screen map
5. **Time-of-day map tint** — disabled in dark mode (would compound)

---

## Performance & caching

### Frontend bundle (gzipped sizes after deploy)
- `index.js` — ~95KB (everything not split)
- `react-vendor.js` — ~54KB
- `charts.js` — ~101KB (recharts; lazy-load opportunity for v.next)
- `gmaps.js` — ~40KB
- `state.js` — ~14KB
- 9 lazy-loaded Advanced-tab chunks — 1-4KB each

### Bundling
- Vite with `manualChunks` for `gmaps`, `charts`, `react-vendor`, `state`
- Per-tab lazy imports in `AdvancedPanel.tsx` (each tab in its own chunk)
- ChangelogPage + ChoroplethWebGL lazy if/when routed

### Backend caching
- **`heatmap_tile_cache`** — server-side bbox-quantized response cache, 7d TTL
- **`reach_cache`** — keyed by `lat,lng,minutes` rounded to 3 decimal places, 24h
- **`poi_cache`** — keyed by `md5('area:'+areaId)`
- **`cache`** table — generic key/value with TTL; used for IsochroneService, GoogleMapsService place results, analog norm-stats fallback
- **`tract_features`** — pre-computed 18-dim vectors per tract (NEW)
- **`analog_norm_stats`** — single-row materialized min/max + sorted-density blob (NEW)
- Apache `mod_deflate` gzip on JSON (12MB → 2MB heatmap)
- Browser `Cache-Control: private, max-age=3600` on heatmap responses
- Adjacent-tile prefetch on idle (200-entry client LRU)

### Hot-path optimizations applied
- `ST_AsGeoJSON(g, 4)` (4-decimal precision = ~11m) on heatmap polygons → response size halved
- `ST_AsGeoJSON(g, 5)` on territory geometry
- AnalogService session-level `SET sort_buffer_size = 64MB`, `tmp_table_size = 128MB`, `max_heap_table_size = 128MB` to avoid "Out of sort memory" on 84K-tract scans
- AnalogService pre-resolves source area's excluded tract IDs in one SPATIAL-indexed query, then passes flat `NOT IN (?, ?, …)` list to the candidate query (was a nested `ST_Intersects` subquery that re-ran for every candidate)
- AnalogService LIMIT 5000 on the candidate scoring loop (sorted by distance from source center)
- TerritoryGenerator divide-and-conquer pairwise `ST_Union` (was serial fold)
- POIMarkers + ImportedMarkers use `MarkerClusterer` to avoid >500-marker jank

---

## Security & auth

- **JWT** HS256 with `jti` for revocation, 7-day expiry
- `revoked_tokens` table for explicit logout revocation
- `users.tokens_invalid_before` for bulk revocation (stamped on password reset, email change)
- **Bcrypt cost=12** password hashing
- **CSRF**: `X-Csrf-Token` header on cookie-auth flows; same-origin enforced
- **CORS**: explicit allow-list via `CORS_ORIGINS` env var; production includes `smappen.mygreendock.com` + `localhost:5173`
- **Rate limiting**: bucket-per-call-type (`geocode`, `places`, `analog_finder`, `territory_gen`, `mclp`, `traffic_iso`, `competitor_scan`, `reach`, `report`, `export`, `import`, `dtm`, `forecast`); windowed counts on `api_usage_log`; X-RateLimit-* response headers; 429 + Retry-After when exceeded
- **Multi-tenant scoping**: every business-scoped query filters by `organization_id`; `current_business_id()` / `current_org_id()` fail closed
- **Mass assignment**: every PUT endpoint uses explicit field allowlists (`AreaController`, `FolderController`, `WebhookSubscriptionController`, `ProjectController` — verified in security audit)
- **SQL injection**: PDO prepared statements + `PDO::ATTR_EMULATE_PREPARES = false`; no string concatenation of user input in `src/Models/*`. AnalogService viewport WKT uses `axis-order=long-lat`-safe placeholder pattern; territory generator envelope WKT pre-validated float-cast
- **Webhook delivery**: HMAC-SHA256 signed outbound webhooks; constant-time comparison via `hash_equals` on Stripe inbound
- **Per-resource permissions** (NEW, #14) — `area_permissions` + `folder_permissions` tables, `Permissions::canRead/canWrite/canDelete` helper. Default org-wide editor preserved when no rows exist.

### Known security work pending
- Per-API-key analytics + revocation UI
- Rotate the API key currently in env (was disclosed in chat 2026-05-24)
- SOC2 audit-log expansion (#10 in tuning recommendations — table exists, wider coverage pending)
- Self-host Inter font (kills a third-party request)
- DNS provider failover (single Cloudflare → add Route53)

---

## Infrastructure & deploy

### Local dev
```bash
cp .env.example .env
# fill JWT_SECRET, DB_*, GOOGLE_API_KEY, ORS_API_KEY, CENSUS_API_KEY
composer install
cd frontend && npm install && npm run dev
php -S localhost:8000 -t public
```

### Production droplet (`143.244.144.7`)
- Apache 2.4 + PHP-FPM 8.3
- MySQL 8.0.45 (same host)
- Deploy command: `cd /var/www/smappen && git pull && composer install --no-dev && (cd frontend && npm ci && npm run build) && systemctl reload php8.3-fpm`
- Auto-deploy via GitHub Actions on push to `main` (`DEPLOY_SSH_KEY` secret-gated)

### Deploy script alternatives
- `scripts/deploy.sh` — tarball-upload + extract + rebuild
- `scripts/droplet-deploy.sh` — pull-side variant
- `scripts/setup.sh` — bootstrap a new droplet

### Cron (root)
```cron
*/10 * * * * cd /var/www/smappen && php scripts/job-worker.php
*/15 * * * * cd /var/www/smappen && php scripts/competitor-scan.php
0    * * * * cd /var/www/smappen && php scripts/cleanup-cron.php
30   2 * * * cd /var/www/smappen && bash scripts/backup-db.sh
0    3 * * 0 cd /var/www/smappen && php scripts/verify-backup.php
0    4 * * * cd /var/www/smappen && php scripts/compute-tract-features.php  # NEW (nightly)
0    5 15 1 * cd /var/www/smappen && php scripts/refresh-census.php          # annual
```

### Failover plan (`docs/failover.md`)
- Standby Droplet in second region with MySQL replica (binlog GTID)
- Cloudflare or DO DNS A-record with 60s TTL for quick flip
- Bootstrap + replica-health-check scripts documented
- Target time-to-recovery: ~3 minutes with prepared scripts
- Not yet provisioned; runbook ready

### Logs
- PHP errors → `storage/logs/php-error.log` (rotated)
- Apache: `/var/log/apache2/smappen-access.log` + `smappen-error.log`
- PHP-FPM: `/var/log/php8.3-fpm.log`
- Census ingest: `/var/log/smappen-census-seed.log`
- App-level diagnostics: `error_log()` throughout (replaced previous silent catches)

### PHP-FPM tuning (current)
- `pm.max_children` — bumped from 5 → 12 (observed `pm.max_children reached` warnings)
- OPcache enabled; `opcache.validate_timestamps` still on (tuning item)
- `memory_limit` baseline 128M; per-controller `ini_set` bumps to 512M for AnalogService, AnalogController, ForecastController, TerritoryRebalancer, HeatmapController

---

## Testing

### PHPUnit (21 tests, 170 assertions, ~30ms)
- `tests/Services/GeoUtilsTest.php` — WKT round-trip, point-in-polygon, circle generator, polyline encoding
- `tests/Services/TrafficServiceTest.php` — day×hour multiplier lookup
- **`tests/Services/AnalogServiceTest.php`** — cosine identity / orthogonal / null-skip / weighted bias / zero-magnitude / commutativity / bounded output / DEFAULT_WEIGHTS shape (9 tests, 125 assertions)

### Vitest (frontend)
- Coverage thin currently — only a few component-render smoke tests
- Roadmap: add `analog-radar.test.tsx`, `confetti.test.ts`, `mapExport.test.ts`

### Manual smoke tests (verified in this audit cycle)
- Login → create project → create area (all 4 modes) → demographics load → heatmap toggles → switch metric → switch level → switch palette
- Hover heatmap tract → legend marker animates
- Open Advanced → run Analogs (default 200km radius) → map fills with numbered pins → click pin → smooth fly-to
- Run Analytics → Drive-time matrix with 3×3 origins/destinations
- Run Analytics → Rebalance with `redraw=true` → new "Rebalanced #N" areas appear
- Toggle dark mode in user menu → Google Maps style flips → all panels remain legible
- Drag-reorder areas, refresh, order persists
- Bulk-shift-click areas → bulk-delete bar appears
- Eye toggle hides area from map, dims row
- Press `?` → ShortcutsModal opens
- Press Ctrl+/ → CommandPalette opens; type "demo" → "Open settings: profile" highlights; Enter navigates
- Hit area → click Compare button → modal opens with delta bar
- Generate territories in current view → 8 areas land → click Rebuild boundaries → ST_Union dissolves run
- Export project → `.smappen.json` downloads with all areas
- Press `b` → heatmap toggles
- Press `f` → favorites filter toggles
- Screenshot via toolbar → PNG downloads with heatmap polygons + area polygons + center pins

---

## Scaffolded but not yet wired

| # | Surface | What's there | What's needed |
|---|---|---|---|
| #3 | Foot-traffic layer | `FootTrafficService` + provider abstraction, env-gated | SafeGraph / Placer / Foursquare driver, API endpoint, UI panel |
| #4 | Building permits | `PermitsService` + same shape | HUD SOCDS / Cherre / ATTOM driver, table + endpoint, UI |
| #6 | ML forecast model | `ml-sidecar/` FastAPI service skeleton (baseline weighted-mean), `requirements.txt`, README with systemd unit | Train XGBoost on real data, deploy uvicorn behind systemd, set `ML_SIDECAR_URL` env, swap ForecastController to call it |
| #11 | WebGL choropleth | `ChoroplethWebGL.tsx` with documented shaders + earcut wire-up plan | Acquire Maps Vector mapId, implement triangulation, hook WebGLOverlayView |
| #13 | Realtime cursors | `PresenceController` ping + SSE stream endpoints; APCu/MySQL storage | Frontend cursor renderer + position broadcaster |
| #16 | Failover / standby DB | `docs/failover.md` runbook, bootstrap snippet, replica-health cron | Provision standby Droplet, configure replication, flip DNS once |
| #21 | Salesforce / HubSpot | `CrmController` 6 endpoints, OAuth URL builders | Token exchange, refresh, push/pull implementations |
| OP6 | Auto-save | `SaveStatus` component + `markSaving/markSaved` exports | Wire into AreaCard rename, color change, notes edit |
| OP10 | Per-integration API keys | DB has `users.api_key_*`; can issue one | Multi-key UI + scopes (read/write/billing-only) |
| OP12 | Voice transcription | Field-note voice path in driver app | Whisper API call on upload + transcript display |
| OP17 | Map session recording | `sessionRecord.ts` MediaRecorder util | Toolbar button + duration picker + upload-to-share flow |
| #2 | Time-series demographics | `census_demographics_history` table | Multi-vintage ACS ingest (2019/2020/2021/2022/2023); trend-line chart in right panel |

---

## Known issues / open punch list

- Brotli compression at Apache (currently gzip only) — easy win
- HTTP/3 / QUIC not yet enabled at LB
- `opcache.validate_timestamps` still on (5-10% latency leak)
- `PDO::ATTR_PERSISTENT => true` not yet set
- `api_usage_log` writes happen synchronously per request (queue to Redis stream is in tuning roadmap)
- Realtime cursors backend ready, frontend cursor renderer pending
- Multi-region support (Canada StatCan, EU Eurostat) — schema accommodates, ingest TBD
- Per-API-key analytics + named keys
- DNS provider failover
- Mobile companion PWA — offline-first, voice notes, GPS breadcrumbs (NF4 v1 only enhances service worker with tile cache + upload outbox; full mobile-first redesign of `driver-app.php` pending)

---

*This document is regenerated whenever a meaningful platform change ships — last refresh by Claude Opus 4.7 on 2026-05-24 after the dark-mode pass, the Analog Finder build, the 50-state census expansion, the 25 visual + 25 ops + 5 feature wave, and the recent UX tuning (visibility toggle, demographics loading skeleton, slower polygon pulse, sidebar-expanding area creator, composite screenshot).*
