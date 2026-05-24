# Smappen — Platform Audit (v2)

*Snapshot as of 2026-05-24 — supersedes the prior audit. This document describes everything the platform currently does, every visual surface, every endpoint, every data flow.*

---

## Table of Contents

1. [TL;DR](#tldr)
2. [Tech stack](#tech-stack)
3. [Architecture & data flow](#architecture--data-flow)
4. [Database schema](#database-schema)
5. [Backend surface (controllers + endpoints)](#backend-surface)
6. [Services layer](#services-layer)
7. [Frontend surface](#frontend-surface)
8. [Feature catalog](#feature-catalog)
9. [Visual design system](#visual-design-system)
10. [Performance & caching](#performance--caching)
11. [Security & auth](#security--auth)
12. [Infrastructure & deploy](#infrastructure--deploy)
13. [Testing](#testing)
14. [Known gaps & roadmap](#known-gaps--roadmap)

---

## TL;DR

Smappen is a territory mapping + demographics + competitive intelligence platform for retail / franchise / sales-territory planners. Live at **https://smappen.mygreendock.com**.

The product surface is split across **three persistent panels** around a Google Maps canvas:
- **Left panel** — area list, search/filter/sort, create/import buttons
- **Right panel** — opens when an area is selected; tabs for Overview / Demographics / Businesses / Data
- **Right toolbar** — vertical icon strip for primary tools (overview, address, heatmap, demographics, reports, import, favorites, advanced sparkle, screenshot, zoom, help)

Plus four overlay surfaces:
- **Heatmap panel** — bottom-left when the choropleth is active
- **Mini-map toggle** — bottom-left, slides over when heatmap panel opens
- **Advanced panel** — slide-in from the right toolbar's ✨ sparkle, with 9 lazy-loaded tabs
- **Daypart strip** — full-width media-player along the bottom, for the 24-hour traffic animation

Top-of-page header carries: project switcher (Cmd/Ctrl+K), undo/redo placeholders, **Google-API spend widget** (clickable for per-API breakdown), notifications bell with unread badge, share button, user menu (profile / team / integrations / billing / sign-out).

Every Google-API-fronting endpoint emits a `_meta.estimated_cost_usd` field that the axios interceptor surfaces as a small bottom-right toast (e.g. *"geocode · $0.005"*) AND bumps the persistent header widget.

---

## Tech stack

### Backend
- **PHP 8.3 / 8.4** (custom router, no framework). PSR-4 autoload under `App\`
- **MySQL 8.0.45** with spatial features (SRID 4326 throughout)
- **Apache 2.4** with `mod_deflate` (gzip on JSON, brings 12MB heatmap responses to ~2MB on the wire)
- **PHP-FPM** with OPcache + per-request `memory_limit` bumps for heavy spatial queries (512–768MB)
- **Composer** deps: `firebase/php-jwt`, `stripe/stripe-php`, `vlucas/phpdotenv`, `phpoffice/phpspreadsheet`, `tecnickcom/tcpdf`, `monolog/monolog`, dev: `phpunit/phpunit`

### Frontend
- **React 18** + **TypeScript 5.5** + **Vite 5** (build output to `public/app/`)
- **Tailwind v4** (`@tailwindcss/vite`) with `@source` glob covering all `.ts(x)`/`.html`
- **Zustand** for state (auth, project, map, cost)
- **TanStack React Query** v5 (installed; partially used)
- **react-google-maps/api** with `useJsApiLoader` — libraries `drawing`, `visualization`, `geometry`, `places`
- **react-router-dom** v6
- **react-hot-toast** for toasts (incl. cost-per-call notifications)
- **axios** with global request/response interceptors (auth, 401 logout, cost-tracking)
- **lucide-react** for icons
- **recharts** for charts, **echarts** as alternative
- **papaparse**, **xlsx** for CSV/Excel parsing
- **vitest** dev dep for unit tests

### External services
- **Google Maps Platform**: Maps JS API, Geocoding, Places (new API), Static Maps
- **OpenRouteService** (hosted) for isochrones, `Accept: application/geo+json`, `smoothing=0`
- **US Census Bureau** ACS 5-year (2023 vintage) for demographics + TIGER 2023 for tract geometries
- **Stripe** for billing (webhook-verified at controller + service)
- **Optional plumbing** (activates when env-keys are set):
  - **Anthropic API** (`claude-haiku-4-5-20251001`) for AI site scoring
  - **Postmark** OR **Resend** for transactional email (password reset, verify, competitor alerts)
  - **Slack incoming webhooks** for per-user competitor alert pipes
  - **Redis** for cache backend (filesystem fallback)
  - **DigitalOcean Spaces** (S3 SigV4 virtual-hosted) for file storage

### Hosting
- DigitalOcean droplet (143.244.144.7), Ubuntu 24.04
- Coexists with GreenDock under the same Apache via separate vhost (smappen.conf + smappen-le-ssl.conf)
- Let's Encrypt SSL via ACME alias
- Deploy: `ssh root@droplet 'git pull && composer install --no-dev && (cd frontend && npm ci && npm run build) && systemctl reload php8.3-fpm'`
- Auto-deploy wired via GitHub Actions on push to main (secrets-gated, no-op if `DEPLOY_SSH_KEY` unset)

---

## Architecture & data flow

```
┌──────────────────────────────────────────────────────────────┐
│  Browser: React SPA at /app/* (Vite bundle, manifest, sw.js) │
└────────────────┬─────────────────────────────────────────────┘
                 │ HTTPS · JWT (Bearer) or X-Api-Key
                 ▼
┌──────────────────────────────────────────────────────────────┐
│  Apache 2.4 → PHP-FPM 8.3                                    │
│   ├ /api/* → public/index.php → Router → Controller          │
│   ├ /app/* → SPA shell (built assets)                        │
│   ├ /app/sw.js + /app/manifest.webmanifest (PWA)             │
│   └ static /storage/* via alias (for local-driver files)     │
└────────────────┬─────────────────────────────────────────────┘
                 │
   ┌─────────────┼─────────────┬─────────────┬─────────────┐
   ▼             ▼             ▼             ▼             ▼
┌──────┐     ┌──────┐    ┌────────┐    ┌─────────┐   ┌─────────┐
│MySQL │     │Redis │    │ ORS    │    │ Google  │   │ Census  │
│ 8.0  │     │(opt) │    │ /iso   │    │ Maps/   │   │ ACS API │
│      │     │      │    │        │    │ Places  │   │         │
└──────┘     └──────┘    └────────┘    └─────────┘   └─────────┘
                                            │
                                            ▼
                              ┌──────────────────────────┐
                              │  api_usage_log + cost    │
                              │  → /api/usage/today      │
                              │  → header widget + toast │
                              └──────────────────────────┘
```

**Request flow** (typical authenticated POST):
1. Frontend axios → `/api/...` with `Authorization: Bearer <JWT>` or `X-Api-Key`
2. `public/index.php` boots Config from `.env`, mounts Response::corsHeaders
3. Router matches regex pattern, runs middleware stack: `Middleware::auth()` → `rateLimit()` → controller
4. Auth middleware verifies JWT signature, checks `revoked_tokens` for the `jti`, checks `users.tokens_invalid_before` against `iat`, loads user + org plan
5. Controller executes; spatial queries against MySQL with `ST_GeomFromText(..., 4326)` + `ST_Intersects` / `ST_Intersection` / `ST_Distance_Sphere`
6. Response wrapped as `{success: true, data: {...}}`; Google-API-backed responses carry `_meta.estimated_cost_usd`
7. Frontend interceptor:
   - On success with cost meta → `useCostStore.trackCall(cost)` + bottom-right toast
   - On 401 → fire-and-forget `authApi.logout()`, redirect to /login (unless on auth page)

**Background work** (PWA outbox + cron + job worker):
- `scripts/job-worker.php` — runs every 10s from cron, claims jobs atomically via `SELECT … FOR UPDATE SKIP LOCKED`, executes territory generation / competitor scans / webhook deliveries
- `scripts/cleanup-cron.php` — hourly; expires cache, auth_tokens, revoked_tokens, old jobs (30d), old webhook_deliveries (30d), orphan upload/export files
- `scripts/competitor-scan.php` — runs every 15min; picks monitors due based on `next_run_at`
- `scripts/refresh-census.php` — annual (Jan 15); seed → aggregate → segment pipeline
- `scripts/backup-db.sh` — nightly mysqldump → gzipped → local 30-day daily + 12-month monthly retention + optional rclone to Spaces

---

## Database schema

**9 migrations** (numbered, all currently applied to production):

| # | Migration | Tables / changes |
|---|---|---|
| 001 | `001_initial_schema.sql` | `organizations`, `users`, `projects`, `folders`, `areas` (POLYGON SRID 4326 + SPATIAL INDEX), `imported_points` (POINT), `poi_cache`, `census_tracts` (MULTIPOLYGON), `census_demographics`, `reports`, `api_usage_log`, `audit_log` |
| 002 | `002_cache_table.sql` | `cache` (key VARCHAR(255), value LONGTEXT, expires_at) |
| 003 | `003_demographics_indexes.sql` | Indexes on census tables for state-FIPS + tract-level lookups |
| 004 | `004_aggregated_geo_and_tile_cache.sql` | `census_counties`, `census_states` (rolled-up demographics for zoom-out LOD), `heatmap_tile_cache`, `reach_cache` |
| 005 | `005_advanced_features.sql` | `territory_generation_jobs`, `tract_segments`, `project_versions`, `comments`, `change_log`, `project_collaborators` (viewer/editor/admin/owner), `approval_requests`, `field_notes` (POINT), `competitor_monitors`, `competitor_scans`, `tracked_places`, `competitor_alerts`, `notifications`. Adds `areas.generation_job_id` + `areas.territory_index` |
| 006 | `006_auth_and_jobs.sql` | `auth_tokens` (password reset + email verify), `revoked_tokens` (JWT revocation), `jobs` (background queue), `webhook_subscriptions`, `webhook_deliveries`. Adds `users.email_verified_at`, `users.api_key_hash`, `users.api_key_last4`, `users.notify_email`, `users.notify_competitor_alerts`, `users.notify_team_activity`, `users.slack_webhook_url`, `users.theme`. Adds `projects.share_expires_at`, `projects.share_view_count` |
| 007 | `007_role_rename.sql` | Renames `project_collaborators.role='approver'` → `'admin'` |
| 008 | `008_bug_fixes.sql` | Adds `users.tokens_invalid_before` (bulk JWT revocation marker); normalizes role enum. Uses INFORMATION_SCHEMA prepared-statement guards for idempotency on MySQL < 8.0.29 |
| 009 | `009_api_cost_tracking.sql` | Adds `api_usage_log.estimated_cost_usd` (DECIMAL(10,6)) + `idx_usage_cost_day` index |

**Spatial features used**: `ST_GeomFromText`, `ST_AsGeoJSON(g, 4)` (4-decimal precision = ~11m), `ST_AsText`, `ST_Intersects`, `ST_Intersection`, `ST_Area`, `ST_Distance_Sphere`, `ST_Centroid(ST_SRID(g, 0))` (planar workaround for geographic SRS — MySQL 8 refuses `ST_Centroid` on SRID 4326 directly), `ST_Union` (pairwise iterative in PHP for territory boundary rebuild — MySQL's ST_Union is binary not aggregate), `ST_GeometryType` filter for `GEOMETRYCOLLECTION` edge cases on touching tracts, `ST_X` / `ST_Y` / `ST_PointFromText`, `ST_Contains`.

**Coverage**: ~4,425 census tracts loaded for DC / MD / VA / WV (the launch corridor); each classified into one of 10 customer segments via the rule-based segmentation service.

---

## Backend surface

### Public (no auth)
| Method | Path | Controller | Purpose |
|---|---|---|---|
| GET | `/api/health` | HealthController | Liveness probe; returns `{ok, db, version, environment, elapsed_ms}`, 503 on DB failure |
| POST | `/api/auth/register` | AuthController | Create account + org |
| POST | `/api/auth/login` | AuthController | Issue JWT (with `jti`) |
| POST | `/api/auth/request-reset` | AuthController | Email a 1h password-reset token |
| POST | `/api/auth/reset` | AuthController | Redeem reset token, set new password, stamp `tokens_invalid_before` |
| GET | `/api/auth/verify-email` | AuthController | Redeem verification token |
| GET | `/api/shared/{shareToken}` | ProjectController | Legacy share link |
| GET | `/api/public/projects/{token}` | PublicShareController | Public read-only project payload |
| GET | `/api/public/projects/{token}/embed` | PublicShareController | Lighter embed payload (geometry only, no demographics) |
| POST | `/api/billing/webhook` | BillingController | Stripe webhook — signature verified at controller AND service |
| GET | `/api/openapi.json` | OpenApiController | OpenAPI 3.1 spec |
| GET | `/api/docs` | OpenApiController | Swagger UI page |

### Auth (JWT or `X-Api-Key`)
| Method | Path | Controller | Notes |
|---|---|---|---|
| POST | `/api/auth/refresh` | AuthController | Issue fresh JWT |
| POST | `/api/auth/logout` | AuthController | Insert current `jti` into `revoked_tokens` |
| GET | `/api/auth/me` | AuthController | Current user + org |
| POST | `/api/auth/resend-verification` | AuthController | |
| PUT | `/api/auth/profile` | AuthController | Update name/email/notification prefs/slack/theme |
| POST | `/api/auth/change-password` | AuthController | |
| GET | `/api/auth/api-key` | AuthController | Returns `{has_key, last4}` |
| POST | `/api/auth/api-key/regenerate` | AuthController | Mints new `sm_…` key (shown once) |

### Core mapping
| Method | Path | Notes |
|---|---|---|
| GET/POST/PUT/DELETE | `/api/projects[/{id}]` | CRUD; org-scoped |
| GET/POST/PUT/DELETE | `/api/folders` + `/api/projects/{projectId}/folders` | |
| GET/POST/PUT/DELETE | `/api/areas[/{id}]` + `/api/projects/{projectId}/areas` | |
| GET | `/api/areas/{id}/demographics` | Cache-Control: max-age=86400 |
| GET | `/api/areas/{id}/pois` | From POICache table |
| POST | `/api/demographics/compare` | Side-by-side comparison |
| GET | `/api/heatmap/tracts` | Viewport choropleth (see Heatmap section) |
| POST | `/api/areas/reach` | Smart-sizing: smallest circle covering N people (binary search), rate-limited 120/hr |
| POST | `/api/demographics/preview` | Live demographics for a drafted polygon (no persist) |
| POST | `/api/isochrone/calculate` | ORS isochrone or radius circle |

### Geocoding & Places (Google-billed, rate-limited)
| Method | Path | Cost/call | Rate limit |
|---|---|---|---|
| POST | `/api/geocode` | $0.005 | 500/hr |
| POST | `/api/geocode/batch` | $0.005 × N | 20/hr |
| POST | `/api/places/nearby` | $0.032 | 300/hr |
| POST | `/api/places/search` | $0.032 | 300/hr |
| GET | `/api/places/{placeId}` | $0.020 | — |

### Import / Export / Reports
| Method | Path | Notes |
|---|---|---|
| POST | `/api/projects/{projectId}/import/upload` | CSV or XLSX (10MB cap); 20/hr |
| POST | `/api/projects/{projectId}/import/configure` | Streams row-by-row (`fgetcsv` for CSV, `getRowIterator` for XLSX); per-row geocode |
| GET | `/api/imports/{batchId}/status` | |
| DELETE | `/api/imports/{batchId}` | |
| GET | `/api/projects/{projectId}/export/areas` | CSV/GeoJSON/KML |
| GET | `/api/areas/{areaId}/export/pois` | |
| GET | `/api/projects/{projectId}/export/points` | |
| GET | `/api/exports/{filename}` | Signed download |
| POST | `/api/areas/{id}/report` | TCPDF or wkhtmltopdf → PDF |
| GET | `/api/reports` | List |
| GET | `/api/reports/{id}/download` | Streams `application/pdf`; frontend wraps in blob for proper download |

### Advanced (the ✨ sparkle panel)
**Cannibalization** (with risk tiers):
- `GET /api/projects/{projectId}/cannibalization` — pairwise overlap demographics, severity = low/moderate/high/critical, includes recommendation strings

**Territory generation** (k-means + boundary swap):
- `POST /api/projects/{projectId}/territories/generate` — sync or `async:true` (queues a job)
- `GET /api/projects/{projectId}/territories/jobs`
- `POST /api/areas/{id}/rebuild-boundary` — pairwise `ST_Union` over source tracts (slow, pretty)

**Multi-location optimization (MCLP)**:
- `POST /api/projects/{projectId}/optimize/locations` — greedy + 4-pass local-search refinement (~63% greedy → near-optimal after swap)

**Customer segmentation**:
- `GET /api/segmentation/segments` — catalog of 10 personas (affluent-suburbs, urban-professionals, family-suburbs, working-class-urban, rural-stable, retirement, college-towns, low-income-urban, moderate-suburbs, emerging-growth)
- `GET /api/areas/{id}/segments` — Cache-Control: max-age=86400
- `POST /api/projects/{projectId}/segments` — project-wide aggregate
- `POST /api/segmentation/recompute` — admin/owner only; ~0.4s for 4,425 tracts

**Traffic-aware isochrones (incl. Daypart)**:
- `POST /api/isochrone/traffic` — single hour, applies 7×24 multiplier matrix to ORS budget
- `POST /api/isochrone/traffic/grid` — 8 predefined windows (Mon AM peak, Fri PM peak, etc.)
- `POST /api/isochrone/traffic/day` — **24 hours in one call**; dedupes by `adjusted_minutes` so 24 hours → ~6-8 unique ORS calls per day

**Collaboration**:
- Versions: `POST/GET /api/projects/{projectId}/versions`, `GET /api/versions/{id}`
- Comments: list/create/resolve/delete with optional `anchor_lat/lng` + parent threading
- Change log: `GET /api/projects/{projectId}/changes`
- Collaborators: list/add/remove with viewer/editor/admin/owner roles
- Approvals: create/list/decide with payload + decision note

**Competitor monitoring**:
- Monitors CRUD + `/scan` to force-run (rate-limited 60/hr)
- Tracked places snapshot via `/places`
- Alerts via `/alerts` + `/read`
- Cron-driven scanner (`scripts/competitor-scan.php`) detects new / gone / moved (>150m) / rating drift (>0.3 stars)
- Fans out to in-app notifications, email (if user opted in), and Slack (if user webhook configured)

**Field notes / mobile PWA**:
- `GET/POST /api/projects/{projectId}/field-notes` — geo-stamped
- `DELETE /api/field-notes/{id}`
- `GET /api/projects/{projectId}/where-am-i?lat=&lng=` — returns containing areas + tract + segment

**AI site scoring**:
- `POST /api/areas/{id}/ai-score` — Anthropic Claude Haiku 4.5 when key configured; deterministic local fallback otherwise. Cached 24h per area-WKT signature

### Cost tracking
| Method | Path | Notes |
|---|---|---|
| GET | `/api/usage/today` | Total + per-API breakdown for today |
| GET | `/api/usage/days` | Last 30 days, bucketed |
| GET | `/api/usage/pricing` | Public price card |

### Background jobs + webhooks
| Method | Path | Notes |
|---|---|---|
| GET | `/api/jobs/{id}` | Poll status (queued/running/done/failed/cancelled) |
| POST | `/api/jobs/{id}/cancel` | Soft cancel |
| GET/POST/PUT/DELETE | `/api/webhooks[/{id}]` | Up to 10 per org; HMAC-SHA256 signed deliveries |
| POST | `/api/webhooks/{id}/test` | Ping a configured URL |

### Notifications
| Method | Path | |
|---|---|---|
| GET | `/api/notifications?unread=1` | Poll every 60s from header |
| POST | `/api/notifications/{id}/read` | |
| POST | `/api/notifications/read-all` | |

### Billing (Stripe)
| Method | Path | |
|---|---|---|
| POST | `/api/billing/checkout` | Owners only |
| GET | `/api/billing/subscription` | |
| POST | `/api/billing/portal` | |
| POST | `/api/billing/cancel` | |

---

## Services layer

Located under `src/Services/`. All injected as plain instances; no DI container.

| Service | Purpose |
|---|---|
| `CacheService` | Redis-first (when `REDIS_URL` set), MySQL `cache` table fallback. Same interface for both. Soft-fail on Redis errors |
| `StorageService` | Local FS or DigitalOcean Spaces (S3 SigV4 virtual-hosted addressing). Pre-signed URLs for private assets |
| `MailService` | Postmark/Resend/log driver; chooses on first call based on env keys |
| `Logger` | Monolog with JSON rotating handler (14d for app.log, 30d for error.log), UID + WebProcessor, stderr in dev. Stub fallback if Monolog missing |
| `GoogleMapsService` | Geocode, reverse, batch, Places nearby/text/details, place details. Persists costs via `logApiUsage(api_name, count)` |
| `GooglePricing` | Per-call USD price card (geocode $0.005, places $0.032, place_details $0.020, static_map $0.002, routes $0.005, maps_load $0.007, etc.) |
| `IsochroneService` | ORS wrapper. `smoothing=0` for max detail, `Accept: application/geo+json` (else ORS 406s), 90s timeout, 24h cache keyed on `lat,lng,minutes,mode` |
| `TrafficService` | 7×24 multiplier matrix (calibrated against FHWA/INRIX). Methods: `multiplier(day, hour)`, `adjustedMinutes(minutes, day, hour)`, `windows()` for the 8-window grid view |
| `TerritoryGenerator` | k-means++ init, weighted Lloyd iterations (up to 25), boundary swap (up to 8 passes), convex hull dissolves. Max 5,000 tracts × 30 territories |
| `SegmentationService` | 10 rule-based personas from Census features; recomputeAll batched 500 at a time |
| `CompetitorScanner` | Per-monitor Google Places sweep, diff against `tracked_places`, emits new/gone/moved/rating alerts. Fans out to notifications + email + Slack |
| `WebhookDispatcher` | HMAC-SHA256 signatures (`X-Smappen-Signature: t=<unix>,v1=<hex>`), 10s timeout, retry on 5xx, deduplicated by org+event |
| `GeoUtils` | GeoJSON ↔ WKT, point-in-polygon, circle polygon generator, spherical area, coordinate swap (MySQL returns lat,lng for SRID 4326 GeoJSON), polyline encoding |
| `CensusService` | Tract-weighted demographics aggregation (population, income, home value, housing units, age + income buckets) for any polygon WKT |

---

## Frontend surface

### Routing (`App.tsx`)

| Path | Component | Auth |
|---|---|---|
| `/login` | LoginPage | redirect if already auth'd |
| `/register` | RegisterPage | redirect if already auth'd |
| `/forgot-password` | ForgotPasswordPage | |
| `/reset-password?token=…` | ResetPasswordPage | |
| `/verify-email?token=…` | VerifyEmailPage | StrictMode ref-lock against double-redemption |
| `/pricing` | PricingPage | |
| `/share/:token` | SharedProjectPage | Public, read-only, raw axios |
| `/settings` (layout) | SettingsLayout | Protected |
| `/settings/profile` | ProfileSettings | Name, email (with verify-resend), notif toggles, Slack URL, theme |
| `/settings/team` | TeamSettings | Invite by email + role badge (viewer/editor/admin/owner) |
| `/settings/integrations` | IntegrationsSettings | API key tile, webhooks tile, Slack tile, email status |
| `/settings/api` | ApiKeySettings | Show last4, regenerate w/ confirm, curl/JS/Python snippets |
| `/settings/webhooks` | WebhookSettings | URL + events checkboxes, test ping, secret shown once |
| `/settings/billing` | BillingSettings | Plan + Stripe portal |
| `/*` | AppLayout (the map) | Protected |

### AppLayout structure

```
┌──────────────────── Header (h-14, sticky top, z-30) ───────────────────┐
│  Logo S · "smappen" · │ ProjectName▼ ⋮ │  Undo Redo │ $0.01 today▼ 🔔 │
│                                                       Share  AvatarMenu │
└─────────────────────────────────────────────────────────────────────────┘
┌──────────────────────────────────────────────────────────────────┬──────┐
│                                                                  │ Tool │
│  ┌────────────┐                          ┌──────────────────┐  │  bar │
│  │ Left panel │      ← Map canvas →      │ Right panel      │  │ (v)  │
│  │ Areas list │      (Google Maps)       │ when area        │  │  📊  │
│  │ search +   │                          │ selected         │  │  📍  │
│  │ filters    │                          │ tabs:            │  │  🗺   │
│  │ AreaCard×N │                          │ Overview/        │  │  🏢   │
│  │ Import/Draw│                          │ Demographics/    │  │  📋   │
│  └────────────┘                          │ Businesses/Data  │  │  📦   │
│                                          └──────────────────┘  │  ⭐   │
│  ┌────────────────────┐                                        │  ✨   │
│  │ Heatmap panel      │                  ┌──────────────────┐  │  📷   │
│  │ (when active)      │                  │ Advanced panel   │  │  +    │
│  │ Metric/Level/      │                  │ when sparkle on  │  │  −    │
│  │ Palette/Legend     │                  │ tabs:Territories │  │  ?    │
│  └────────────────────┘                  │ Cannibalize/     │  │      │
│  [MiniMap toggle]                        │ Traffic/Optimize │  │      │
│                                          │ Segments/        │  │      │
│  ┌────────────────────────────────────────│ Comments/Versions│  │      │
│  │   Daypart strip (full-width when on)  │ Competitors/Field│  │      │
│  │  Mon ▾ 60m ▾ Run   ▶ 06:00 5,088km²   │                  │  │      │
│  │  ▆▇▇▇▇▆▅▃▂▃▄▅▆▆▅▄▃▃▄▅▆▆▇▇            │                  │  │      │
│  └────────────────────────────────────────└──────────────────┘  │      │
└──────────────────────────────────────────────────────────────────┴──────┘
```

### Component tree (key components)

- **`AppLayout.tsx`** — Mounts `useJsApiLoader`, all `ErrorBoundary` wrappers (per-panel inline fallbacks instead of full-screen), shortcuts hook, screenshot handler, and the TimeMachinePanel via `mapStore.timeMachineRequest`
- **`Header.tsx`** — Project switcher (Cmd+K opens with `autoFocus` search), notifications dropdown (with click-outside-to-close), Google API spend widget (Dollar icon, color goes rose when >$1/day, popover shows per-API breakdown), user menu (Profile/Team/Integrations/Billing/Sign-out)
- **`LeftPanel.tsx`** — `AreaList`, search-filter-sort chips, Create/Import buttons
- **`AreaList.tsx`** — Search by name, sort (recent/name/time/pop), filter by area type (isochrone/radius/manual), proper search-icon inset
- **`AreaCard.tsx`** — Color dot, name, meta, full action menu (rename inline, change color, duplicate, zoom-to, delete with confirm)
- **`RightPanel.tsx`** — Closes on Esc; tabs Overview/Demographics/Businesses/Data; Overview hosts Report button, Export button, and the dashed-violet **"Drive-time over a full day"** launcher (only for isochrone-type areas)
- **`DemographicsPanel.tsx`** — Population, age buckets, income buckets, median income, median home value, housing units. ECharts donuts
- **`POISearchPanel.tsx`** — Type + keyword + radius, Google Places nearby
- **`MapCanvas.tsx`** — Single GoogleMap. Children: ChoroplethLayer (when heatmap on), AreaPolygons, AreaCenterPins (memoized icon + guard against `typeof google === 'undefined'`), ImportedMarkers, POIMarkers, DrawingTools, TimeMachine polygon overlay (zIndex 999)
- **`HeatmapPanel.tsx`** — Metric select (population, density, income, home value, unemployment, housing), level (auto/state/county/tract), palette picker (11 named palettes), legend gradient bar with hover-tract position marker
- **`ChoroplethLayer.tsx`** — Google Data layer, debounced viewport fetch (250ms), prefetchAdjacent on idle, 200-entry client memCache LRU
- **`MiniMapToggle.tsx`** — Bottom-left; slides to `left-[364px]` when heatmap is open
- **`RightToolbar.tsx`** — Vertical icon strip: Overview, Address/pin, Heatmap toggle, Demographics, Reports, Add data, Favorites, **Sparkle (Advanced)**, **Camera (Screenshot)**, Zoom+/−, Help
- **`AreaCreator.tsx`** — Address autocomplete (Google Places), travel-mode (car/bike/walk), time-budget or radius, color picker, live demographics preview
- **`ImportWizard.tsx`** — Upload, column-mapping preview, configure-and-run

### Advanced Panel tabs (each lazy-loaded ~2KB gz)

| Tab | Component | Purpose |
|---|---|---|
| Territories | TerritoriesTab | k-means generator: target count, balance metric, bbox=current viewport, name prefix |
| Cannibalize | CannibalizeTab | Per-area uniqueness + overlap matrix with low/moderate/high/critical badges |
| Traffic | TrafficTab | Featured "▶ Watch drive-time over a full day" button + single-hour calculator |
| Optimize | OptimizeTab | MCLP grid: picks, radius, grid_step, demand_metric |
| Segments | SegmentsTab | Project-wide segment mix with per-segment % bars |
| Comments | CommentsTab | Threaded comments with resolve/delete, anchored to selected area |
| Versions | VersionsTab | Snapshot button + version list + recent activity (change_log) |
| Competitors | CompetitorsTab | Create monitor + list + per-monitor Scan/Remove |
| Field notes | FieldTab | Geolocate-or-map-center + body + tags; lists notes with author + timestamp |

### Daypart strip (the time machine — completely redesigned)

**Layout** — full-width media-player strip docked along the bottom (`left-4 right-20 bottom-4`), 3 rows tall:

- **Row 1 (28px)**: `⏰ Daypart` · `24-HOUR REACH` pill · Day select · Duration select · Run button · "X unique routes" status · Collapse chevron · Close
- **Row 2 (44px)**: ▶ play (36px violet circle) · `06:00` (22px extrabold tabular) · `5,088 km² · 1.0× traffic` · peak-shrink summary chip · speed select (0.5×/1×/2×/4×)
- **Row 3 (44px)**: 24 color-coded clickable bars (overnight blues → cool morning → warm midday → evening purples; height = relative reach vs day's best); time scale `00:00 06:00 12:00 18:00 23:00`

The heatstrip IS the scrubber — click any bar to jump. Playing wraps midnight → 1am automatically.

The polygon morphs on the map in real time via `mapStore.timeMachine`, drawn by `MapCanvas` as an overlay polygon at zIndex 999.

Backend dedupes by `adjusted_minutes` so a 24-hour request on a Monday only needs ~6-8 ORS calls per origin per day; remaining hours hit the IsochroneService cache.

Launched from two places:
1. Right panel's Overview tab on an isochrone area
2. Advanced panel's Traffic tab, big dashed-violet button

Both route through `mapStore.openTimeMachine(opts?)` → renders `TimeMachinePanel` at the AppLayout level.

---

## Feature catalog

### Mapping core
- 4 area types: `isochrone` (travel-time), `isodistance` (travel-distance), `radius` (circle), `manual` (drawn polygon)
- Travel modes: car, bike, walk, wheelchair
- Live demographics preview while drafting (population, median income, area km², density)
- "Smallest circle reaching N people" via binary-search radius (`POST /api/areas/reach`)
- Folders (color-coded) for grouping areas within a project
- Notes per area
- 4-pt drawing tools: polygon, circle, pin, freehand

### Demographics
- US Census ACS 5-year (2023 vintage) at tract level: 50-variable batches split to fit the 50-per-request limit
- Aggregations: population total, age buckets (under-18, 18-34, 35-54, 55-64, 65+), income buckets (under-25k, 25-50k, 50-75k, 75-100k, 100k+), median income, median home value, housing units, labor force / unemployment
- Coverage: DC + MD + VA + WV (~4,425 tracts)
- Cached in `areas.demographics_cache` JSON; refreshed annually via `scripts/refresh-census.php`

### Heatmap (choropleth)
- 6 metrics: population density, population, median income, median home value, unemployment rate, housing units
- 3 LOD levels (auto by zoom): state (z≤7), county (8-9), tract (10+)
- **11 named palettes** (Smappen Pastel, Vivid Rainbow, Viridis, Plasma, Magma, Inferno, Turbo, Heat, Cool-Warm, Sunset, Mono Purple) with weighted stop positions; legend bar + polygon coloring derive from the same active palette
- Quantile-aware decile breaks for accurate hue distribution
- Hover-marker on the legend gradient shows current tract's position
- "cached" badge when served from server tile cache
- Truncation badge with guidance when polygon cap is hit
- **Tiered caching**: server-side bbox-quantized 7-day cache (`heatmap_tile_cache`), 1h browser `Cache-Control`, 200-entry client memCache LRU, adjacent-tile prefetch on idle (warms 8 surrounding viewports)
- ST_AsGeoJSON precision=4 (~11m, ~4× smaller payload than default)
- mod_deflate gzip: 12MB → ~2MB on the wire

### POI / Businesses
- Google Places nearby + text search
- Type filter + keyword filter
- Per-area POI cache (`poi_cache` table, 48h)
- POI markers rendered with clustering on the map

### Reports (PDF)
- TCPDF default + wkhtmltopdf if installed (auto-detected)
- Includes area name, demographics, POIs, static map preview
- **Fixed in this audit cycle**: was using `window.open(downloadUrl, '_blank')` → silent 401 in new tab without auth header. Now fetches via authed axios as a blob, triggers a real `<a download>`. Verified: 159KB valid PDF with `%PDF` magic header.

### Import / Export
- Upload CSV (10MB cap) or XLSX
- Column mapping (address OR lat/lng + name + custom columns)
- Per-row geocoding via Google with the address-column path
- **Streaming**: row-by-row processing (`fgetcsv` + `getRowIterator`); no full-file load
- Imports as `imported_points` (POINT SRID 4326)
- Export: CSV, GeoJSON, KML for areas; CSV for POIs and imported points; signed download via storage layer

### Auth & accounts
- Email + password registration (bcrypt)
- JWT issuance with embedded `jti`, 24h expiry
- **JWT revocation**: per-token via `revoked_tokens` table OR bulk via `users.tokens_invalid_before` (set on password reset)
- API keys: `sm_<48 hex>` prefix, sha256 at rest, last4 visible, shown raw once at creation
- Two auth methods supported on every protected endpoint: `Authorization: Bearer <JWT>` or `X-Api-Key: sm_…`
- Password reset email (1h token) — via Postmark/Resend or log driver
- Email verification (7-day token); UI nudge on profile page when unverified
- StrictMode-safe verify page (useRef lock prevents double-redemption)
- Logout = local-state-first then fire-and-forget server revocation

### Settings
- Profile: name, email (with verification status), theme (light/dark/auto), notification toggles (email, competitor alerts, team activity), Slack webhook URL, change password
- Team: invite by email → role select (viewer/editor/admin/owner) → table with badges + remove
- Integrations: API key tile, webhooks tile, Slack tile, email status
- API key: regenerate with confirm (rotates), curl/JS/Python snippets
- Webhooks: up to 10 per org, URL + event checkboxes (`competitor.alert`, `territory.generated`, `import.completed`, `comment.created`, `approval.requested`, `approval.decided`, `project.shared`), HMAC secret shown once, test ping, delivery history
- Billing: plan badge + Stripe portal link

### Team & collaboration
- Project collaborators: viewer / editor / admin / owner roles
- Comments: per-project + per-area, threaded (`parent_comment_id`), anchored to lat/lng (for map pins), resolve/unresolve
- Change log: every mutation (area create/update/delete, comment create/resolve, approval decisions) logged with diff JSON
- Snapshots (project versions): manual snapshot button stores full JSON payload (project meta + folders + areas + geometries) keyed by monotonically increasing version_number per project
- Approval requests: title + description + payload, decided by admin+ with note
- All actions emit in-app notifications to non-actor collaborators

### Notifications
- In-app dropdown in header; unread badge with count (max display "99+"); 60s polling that skips when logged out
- Click-outside-to-close
- "Mark all read" action
- Types: `comment`, `approval_request`, `competitor_alert`, custom
- Email fanout for warn/high competitor alerts when user opted in (`users.notify_competitor_alerts` AND `users.notify_email`)
- Slack fanout if user has webhook URL configured

### Public sharing
- Per-project share token (32-hex) + `is_shared` toggle
- Token rotation only on off→on transition (re-PUTting `is_shared:true` does NOT rotate, so existing links don't break)
- Optional `share_expires_at`
- `share_view_count` bumped on each load
- Read-only React view at `/share/{token}` with map + polygons (no demographics)
- Lighter `/embed` payload for iframe embedding

### Time Machine / Daypart
See full Daypart strip section above.

Backend: `POST /api/isochrone/traffic/day` returns 24 hour-frames; dedupes by adjusted_minutes so 24 hours collapse to ~6-8 unique ORS calls per origin per day.

Live polygon morphs on the map. Heatstrip bars are the scrubber. Play/pause auto-wraps midnight. 4 playback speeds (0.5× to 4×). Collapse mode hides the player row, leaving just title + heatstrip.

### Cost tracking
- Every Google-API-fronting response carries `_meta: {api_name, estimated_cost_usd}`
- Axios interceptor fires a 💸 toast bottom-right per call ("geocode · $0.005")
- `useCostStore` (zustand) maintains running daily total + session total + per-API calls
- Header widget shows `$X.XX today` (color goes rose when >$1); click for popover with per-API breakdown + footer disclaimer "real billing lives in GCP"
- Backend persists costs to `api_usage_log.estimated_cost_usd` (DECIMAL(10,6))
- Endpoints: `/api/usage/today`, `/api/usage/days`, `/api/usage/pricing` (public price card)
- Pricing source of truth: `GooglePricing::COSTS` (geocode $0.005, places $0.032, place_details $0.020, static_map $0.002, routes $0.005, etc.)

### Advanced features

**Territory generation** — k-means++ initialization with weighted Lloyd iterations (up to 25), boundary swap (up to 8 passes) targeting balanced totals (configurable `max_imbalance_pct`, default 12%). Convex hull dissolves for fast deterministic territory polygons. Cluster naming via 8-point compass (N, NE, E, SE, S, SW, W, NW) relative to bbox centroid — "NW Territory", "NW Territory 2", etc., ordered by population descending within each direction. Optional async path queues a job and returns `202` with poll URL. Optional `POST /api/areas/{id}/rebuild-boundary` does pairwise `ST_Union` over the territory's source tracts for prettier (slower) borders.

**Cannibalization** — pairwise overlap demographics for every project area. Returns:
- Per-area: population, housing, % unique (not shared with any other area), % cannibalized
- Per pair: shared population, shared housing, shared area_sq_km, pct_of_a, pct_of_b
- **Severity tiers** (driven by worse-of-pct_of_a/pct_of_b): `low` (<10%) / `moderate` (10-25%) / `high` (25-50%) / `critical` (50%+) with colored badge + recommendation string ("Consider adjusting territory boundaries…")

**MCLP** — Maximum Coverage Location Problem with greedy + local-search refinement (4 swap-improvement passes). Inputs: explicit candidates OR auto-grid over bbox with `grid_step_km`; pick_count (1-20); radius_km (1-80); demand_metric (population / housing_units / income_weighted_pop). Returns ranked picks with unique demand added per pick, cumulative demand, and total coverage % of universe.

**Customer segmentation** — 10 personas derived from Census ACS features via percentile-cutoff rule cascade:
1. `retirement` — pct65+ ≥ 40%
2. `college-towns` — pct18-34 ≥ 40% AND not high income
3. `affluent-suburbs` — high income AND high home value AND not high density
4. `urban-professionals` — high density AND mid-high income
5. `low-income-urban` — high density AND low income
6. `family-suburbs` — pctU18 ≥ 25% AND not low density AND not low income
7. `rural-stable` — low density AND not low income
8. `working-class-urban` — high density (catch-all)
9. `emerging-growth` — housing/population > 0.55
10. `moderate-suburbs` — default

Each tract stored in `tract_segments` with segment_id + segment_name + confidence + features_json. Cutoffs computed lazily from the population on first run.

**Traffic isochrones** — 7×24 multiplier matrix calibrated against FHWA/INRIX. Mon 8AM rush ~1.5×, Fri 5PM ~1.8×, overnight 1.0×. Single-hour endpoint + 8-window grid + 24-hour Daypart timeline.

**Competitor monitoring** — Per-org monitors track Google Places nearby search results over time. Daily/weekly/monthly cron via `next_run_at`. Diff against `tracked_places`: new (place_id appeared), gone (didn't appear in latest scan), moved (centroid shift >150m via Haversine), rating drift (±0.3 stars). Alerts persisted to `competitor_alerts`; warn/high severity fans out to in-app + email + Slack notifications.

**Field notes (mobile PWA)** — manifest.webmanifest + service worker:
- Shell cache: app shell + assets, network-first navigation with offline fallback
- API GETs: network-first with cached fallback
- POST `/api/projects/*/field-notes`: when offline, queues to IndexedDB outbox (`sm-outbox`); registers a `sync` event tag; flushes when back online
- Note model: body, lat, lng, location POINT, accuracy_m, photo_url, tags JSON, captured_at, author
- `/api/projects/*/where-am-i?lat=&lng=` resolves a coordinate to containing areas + tract + segment

**AI site scoring** — Anthropic Claude Haiku 4.5 when `ANTHROPIC_API_KEY` is set, deterministic local heuristic otherwise. Gathers facts: population, median income, top-5 segments, competitor density inside the polygon. Prompts for `{score:1-100, verdict, reasons:[≤5]}`. 24h cache per (area, geometry signature). Always returns something usable.

### OpenAPI / docs
- OpenAPI 3.1 spec auto-served at `/api/openapi.json`
- Swagger UI at `/api/docs`
- Covers: health, all auth endpoints, projects, areas, demographics, segmentation, AI score, cannibalization, territory generation, MCLP, competitor monitors, traffic isochrones (single + day), field notes, where-am-i, notifications, webhooks, jobs, public share
- Security schemes: `BearerAuth` (HTTP bearer JWT) + `ApiKeyAuth` (`X-Api-Key`)
- Public endpoints flagged `security: []`

---

## Visual design system

### Typography
- **Nunito** body font (loaded from Google Fonts in `styles.css`, weights 400-900)
- Tabular nums on times, km², percentages
- Body: `#111827` / `#1f2937` near-black at weight 500+
- Subheads / labels: `#475569` (slate-600) at weight 600
- Decorative: never light gray for body text

### Color tokens (CSS variables in `:root`)
- `--brand: #7848BB` (Smappen violet)
- `--brand-dark: #6B37A6`, `--brand-light: #EDE5F7`
- `--ink: #1A1A2E`, `--ink-2: #2D2D44`
- `--body: #4A4A5A`, `--slate: #6B6B7B`, `--muted: #8E8E9A`
- `--line: #D1D1DB`, `--line-soft: #E8E8EE`
- `--bg-panel: #F3F3F7`, `--bg: #F9F9FB`

### Dark mode
- Toggle via `data-theme="dark"` on `<html>`
- Resolution priority: `user.theme` (Profile setting) → `localStorage('smappen-theme')` → OS `prefers-color-scheme`
- `useTheme()` hook in App.tsx; auto-mode listener properly cleaned up
- CSS overrides via `[data-theme="dark"]` selectors for cards, inputs, area rows, kbd, btn-secondary

### Radii & shadows
- `--radius-sm: 6px`, `--radius: 10px`, `--radius-lg: 14px`, `--radius-xl: 16px`
- Subtle shadows only — `--shadow-sm/md/lg`
- Float shadows on cards (`shadow-float`)

### Heatmap palettes (11)
Each has stops + optional weighted positions:
1. **Smappen Pastel** (default) — lavender → sky → cyan → mint → lime → sunny → peach → coral → pink
2. Vivid Rainbow — full hue rotation
3. Viridis — perceptually uniform purple → yellow
4. Plasma — purple → orange
5. Magma — black → cream
6. Inferno — black → yellow
7. Turbo — Google's perceptual rainbow
8. Heat — black → red → yellow → white
9. Cool-Warm — blue ↔ red diverging
10. Sunset — purple → orange
11. Mono Purple — single-hue tint

### Daypart palette (24 colors)
Hour-anchored circadian wheel — overnight blues (`#1e3a8a`) → cool morning (`#06b6d4`) → warm midday (`#22c55e`, `#eab308`) → red rush (`#dc2626`) → evening purples (`#7848BB`) → back to deep blue at 23:00.

### Skeleton loader
`.skeleton` class with shimmer animation (1.4s ease-in-out infinite, 200% background slide). Dark-mode variant.

### Density target
Not minimalist, not dense. "Real usefulness over visual sophistication" — every panel surfaces actionable info, no decorative whitespace.

---

## Performance & caching

### Frontend
- Code-splitting: each Advanced panel tab lazy-loaded as a separate ~2KB gz chunk (`TerritoriesTab-*.js`, `CannibalizeTab-*.js`, etc.)
- 200-entry client memCache LRU for heatmap responses (`memCache.ts`)
- Adjacent-tile prefetch on map idle (8 surrounding viewports, fire-and-forget)
- React Query installed but partial coverage (header notif + cost queries imperative; advanced tabs imperative; many components could benefit)
- Optimistic cost-store bumps so widget updates between 60s server polls

### Backend
- **Heatmap pipeline**:
  - bbox-quantized server cache (`heatmap_tile_cache`) with 7-day TTL
  - Cached responses streamed via `str_replace` patch — no JSON decode/encode round-trip
  - 1h browser `Cache-Control`
  - mod_deflate gzip (12MB → 2MB on wire)
  - ST_AsGeoJSON precision=4 (~4× smaller payload)
  - `ini_set('memory_limit', '512M')` per request
  - 10K feature cap (vs our 4,425-tract universe = no truncation in practice)
  - `SET SESSION sort_buffer_size = 67108864` + sort-then-JOIN-for-geometry pattern (avoids MySQL 1038)
- **Isochrone cache**: 24h, keyed `lat,lng,minutes,mode`. Daypart benefits massively (24 hours → ~6-8 unique calls)
- **Reach cache**: 30d, keyed by 3-decimal coords + target snapped to 500
- **POI cache**: 48h via Google
- **CacheService**: Redis when `REDIS_URL` set (with soft-fail to MySQL on Redis errors)
- **Streaming CSV import**: row-by-row, no full-file load
- **Job queue**: `SELECT … FOR UPDATE SKIP LOCKED` for atomic claim, retry-on-failure with attempt counter
- **Database backups**: nightly mysqldump → gzipped → 30 daily + 12 monthly retention, optional rclone to Spaces

---

## Security & auth

- **Sessions**: JWT 24h with `jti`. Logout inserts jti into `revoked_tokens`; auth middleware checks every request. Bulk revoke via `users.tokens_invalid_before` (timestamp; tokens with `iat <` rejected)
- **API keys**: sha256 at rest, shown raw once. Auth middleware accepts `X-Api-Key` as alternative to JWT
- **Password reset**: 1h token sha256-hashed at rest; redeem also stamps `tokens_invalid_before` to kill any stolen old JWTs
- **Email verification**: 7-day token, optional gate
- **Rate limits** (per user via `api_usage_log`):
  - geocode 500/hr, geocode_batch 20/hr
  - places 300/hr
  - imports 20/hr
  - territory_gen 30/hr, mclp 30/hr
  - traffic_iso 60/hr, competitor_scan 60/hr
  - reach 120/hr, report 50/hr, export 60/hr
- **Roles**: project_collaborators (viewer / editor / admin / owner). `requireAccess(minRole)` helper across collaboration controllers
- **CORS**: explicit allow-list from `config/cors.php` (env-overridable via `CORS_ORIGINS`). Never echoes `*` with credentials. OPTIONS preflight handled at router level → 204
- **Stripe webhooks**: signature verified at controller AND service (defense-in-depth)
- **Webhook outbound deliveries**: HMAC-SHA256 signed using subscription `secret_hash` as key (so receivers verify by hashing their stored raw secret first)
- **CSRF**: not needed for JWT-bearer API (cross-origin requires the user to opt-in via Authorization header)
- **SQL**: prepared statements throughout via PDO; no string interpolation of user input
- **Multi-tenant scoping**: every query filters by `organization_id` OR validates `project_collaborators` membership
- **Tokens cleanup**: cleanup-cron expires `auth_tokens`, `revoked_tokens`, old `jobs` (30d), old `webhook_deliveries` (30d)

---

## Infrastructure & deploy

### Local dev
- `php -S localhost:8080 -t public` for API
- `cd frontend && npm run dev` for SPA (port 5173, proxies /api → :8080)
- MySQL via Docker or local install; load migrations 001-009 in order

### Docker (alternative)
- `Dockerfile` (PHP 8.3-FPM + redis ext + composer prod deps + opcache)
- `docker-compose.yml`: app + nginx (`docker/nginx.conf`) + mysql + redis
- Designed so `docker compose up` brings a full dev stack to localhost:8080

### Production droplet
- DigitalOcean Ubuntu 24.04 at 143.244.144.7
- Apache 2.4 + PHP-FPM 8.3 + MySQL 8.0.45
- Coexists with GreenDock under the same Apache via separate vhost
- Let's Encrypt SSL (ACME alias in deploy script)
- SSH deploy key at `/c/Users/adams/.ssh/claude_deploy`
- Deploy: `git pull && composer install --no-dev && (cd frontend && npm ci && npm run build) && systemctl reload php8.3-fpm`

### GitHub Actions CI (`.github/workflows/ci.yml`)
- **lint-backend**: `php -l` every PHP file + PHPUnit if configured
- **lint-frontend**: `tsc -b --noEmit` + `npm run build`
- **deploy** (on push to main, secrets-gated): SSH + git pull + composer + npm + reload PHP-FPM

### Cron jobs (recommended on droplet)
- `*/10 * * * *` — `php scripts/job-worker.php` (background jobs)
- `*/15 * * * *` — `php scripts/competitor-scan.php` (due monitors)
- `0 * * * *` — `php scripts/cleanup-cron.php` (cache + tokens + old jobs)
- `0 3 * * *` — `bash scripts/backup-db.sh` (nightly DB backup)
- `0 4 15 1 *` — `php scripts/refresh-census.php` (annual Census refresh, Jan 15)

### Storage
- Local: `storage/uploads`, `storage/exports`, `storage/reports`, `storage/backups`, `storage/logs`, `storage/cache` (when no Redis)
- Optional Spaces: SigV4 virtual-hosted-style, presigned URLs for private downloads. `StorageService::put/get/delete/url` abstracts both

### Logging
- Monolog with JSON rotating file handler
- `storage/logs/app.log` — 14d retention, INFO+
- `storage/logs/error.log` — 30d retention, ERROR+
- Stderr in dev for tail-while-coding
- UidProcessor adds per-request UID for cross-file correlation
- Stub fallback if Monolog not installed (writes via `error_log`)

---

## Testing

### PHPUnit (12 tests, 45 assertions, ~9ms)
- `tests/Services/TrafficServiceTest.php` — multiplier never <1, rush hour heavier than midday, adjusted minutes scales correctly, invalid day falls back, hour clamped, windows cover full week
- `tests/Services/GeoUtilsTest.php` — WKT round-trip, point-in-polygon, circle polygon shape (65 points), area calculation within 10% of πr² for a 5km circle, coordinate swap, bounding box

### Vitest (frontend)
- `src/utils/__tests__/geo.test.ts` — polygonCentroid, polygonBounds
- `src/utils/__tests__/format.test.ts` — formatNumber, formatCurrency, formatPercent, formatCompact, formatArea
- More tests not yet written

### Manual smoke tests (verified in this audit cycle)
- Login + JWT issuance
- AI score endpoint (was 500 due to wrong cache column names — fixed in B2, now 200)
- Public share endpoint (null token handling fixed in B4)
- Time Machine 24h endpoint (returned 200 with 7 unique ORS calls for 24 hours)
- Report download (159KB PDF, valid `%PDF` magic header — fixed silent 401 from window.open)
- Geocode response includes `_meta.estimated_cost_usd: 0.005`
- `/api/usage/today` shows `$0.005` after a geocode call
- Token revocation: stamping `tokens_invalid_before` causes old JWTs to return 401 with "Token revoked — please sign in again"

---

## Known gaps & roadmap

### Things that work but could be tighter
- **React Query coverage** — installed but only ~4 of ~40 components use it. Big payoff on AreaList (reload-from-scratch on every panel open), POISearchPanel (re-fire on tab switch), advanced-panel tabs
- **Mobile layout** — three-panel desktop layout doesn't reflow well below 768px. driver-app.php / customerfacing/ are mobile-first but the planner UI is desktop-first. Needs left-panel-as-bottom-sheet treatment
- **Time-of-day Routes API integration** — current Daypart uses an empirical multiplier table. Google Routes API with `departureTime` would give real-time traffic numbers (~$0.005/call, so 24 calls = $0.12 per Daypart run)
- **ST_Union territory boundaries by default** — currently convex hull (fast, deterministic, ugly); manual rebuild endpoint exists for real boundaries
- **Field-photo upload** — `field_notes.photo_url` column exists but no upload UI yet
- **Onboarding tour** — new users land on a blank map with no guidance
- **Undo/redo for area operations** — header buttons exist but disabled

### Plumbing wired but inactive without env keys
- Anthropic AI scoring (without `ANTHROPIC_API_KEY` → falls back to local heuristic — works, just less smart)
- Postmark / Resend email (without keys → writes to `storage/logs/mail.log`)
- Slack alerts (per-user; activates when user fills in slack_webhook_url in Profile)
- Redis cache (without `REDIS_URL` → falls back to MySQL `cache` table)
- DigitalOcean Spaces (without `SPACES_*` → falls back to local filesystem)
- Auto-deploy GitHub Action (without `DEPLOY_SSH_KEY` secret → no-op)

### Not yet shipped
- Statistics Canada demographics (only US Census currently)
- Self-hosted OpenRouteService (uses public ORS; rate-limited at ~40 req/min on free tier — would need self-host for production scale)
- Map screenshot embedded in PDF reports (currently fetched via Static Maps inside the report controller; image only, no overlay)
- Customer-facing public dashboards beyond the basic read-only share page
- Multi-region / Canada FSA support
- iframe embed widget (route exists at `/api/public/projects/{token}/embed` but no `<iframe>`-friendly HTML page yet)

### Visible UI debt
- The static `Undo` / `Redo` buttons in the header are disabled placeholders
- "Favorites" toolbar button has no handler
- Some POI cluster icons render via the old `Hexagon` icon path on areas with no `travel_mode`
- Demographics panel uses ECharts donuts; could benefit from sparkline-style historical trends once we have time-series data

---

*Last updated: 2026-05-24 after the Daypart redesign (3-row media-player strip docked along the bottom width of the map, renamed from "Drive-time over a full day").*
