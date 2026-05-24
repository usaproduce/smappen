# Smappen — Platform Audit (v4)

*Snapshot as of 2026-05-24, post-deploy `a21b00a`. Supersedes the v3 audit. Captures every surface, controller, table, animation, palette swatch, scaffolded feature, deployed bug fix, and operational tweak currently in the repo + on the droplet.*

---

## Table of Contents

1. [TL;DR + what changed since v3](#tldr--what-changed-since-v3)
2. [Tech stack](#tech-stack)
3. [Architecture & data flow](#architecture--data-flow)
4. [Database schema (16 migrations)](#database-schema-16-migrations)
5. [Backend surface — controllers + endpoints (172 routes, 42 controllers)](#backend-surface--controllers--endpoints)
6. [Services layer (22 services)](#services-layer)
7. [Background jobs + operator scripts](#background-jobs--operator-scripts)
8. [Frontend surface](#frontend-surface)
9. [Feature catalog](#feature-catalog)
10. [Plan enforcement + freemium scaffolding](#plan-enforcement--freemium-scaffolding)
11. [Onboarding + activation funnel](#onboarding--activation-funnel)
12. [Visual design system](#visual-design-system)
13. [Animation system](#animation-system)
14. [Dark mode end-to-end](#dark-mode-end-to-end)
15. [Performance & caching](#performance--caching)
16. [Security & auth](#security--auth)
17. [Reliability & deploy resilience](#reliability--deploy-resilience)
18. [Infrastructure & deploy](#infrastructure--deploy)
19. [Testing](#testing)
20. [Scaffolded but not yet wired](#scaffolded-but-not-yet-wired)
21. [Known issues / open punch list](#known-issues--open-punch-list)
22. [Bug-fix history (audit cycles)](#bug-fix-history-audit-cycles)

---

## TL;DR + what changed since v3

Smappen is a multi-tenant territory mapping + demographics + competitive-intelligence platform for retail, franchise, and sales-territory planners. Live at **https://smappen.mygreendock.com**.

The product surface is split across four persistent surfaces around a Google Maps canvas:
- **Left panel (360px)** — area list, sticky filter/sort/group chips, area thumbnails, drag-reorder, bulk select, visibility toggles
- **Right panel** — opens on area selection; tabs are an icon+label segmented control (Overview / People / Businesses / Data); includes Street View button + compare button + breadcrumb
- **Right toolbar** — vertical 40px icon strip; collapses to 3-button minimum, has 3D-tilt toggle, screenshot, advanced ✨ sparkle, etc.
- **Advanced panel (10 lazy tabs)** — Territories, Analogs, Analytics (drive-time matrix + rebalancer + forecast), Cannibalize, Traffic, Optimize, Segments, Comments, Versions, Competitors, Field notes

Plus floating chrome:
- **Heatmap panel** + animated loading pill + truncation hint
- **Map style picker** (Detailed / Clean / Mono / Dark / Satellite) bottom-left
- **Daypart panel** (24-hour traffic animation) docked along the bottom
- **Command palette** (Ctrl+/) — global jump to project/area/action
- **Onboarding checklist** — 5-step gamified intro for new accounts
- **First-run wizard** — 3-step modal on first /app visit (use-case → address → auto-isochrone preview)
- **What's New modal** — once-per-deploy release notes
- **Quick-stats strip** — Areas / Reach / Favorites ribbon when ≥3 areas exist
- **Presence cursors** — colored cursor pips for other users on the same project (SSE)

Outside `/app` there are now full **standalone pages**:
- `/` — marketing homepage (HomePage.tsx) — logged-in users redirect to `/dashboard`
- `/dashboard` — three-column landing after login (projects + activity + usage)
- `/projects` — full project gallery (grid + list views, search, sort, archive, rename)
- `/blog` — blog index (3 seed posts)
- `/pricing`, `/changelog` — pre-existing marketing
- `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email` — auth
- `/settings/*` — profile / team / integrations / api / webhooks / billing
- `/share/:token` and `/embed/:token` — public surfaces
- `/app/*` — the actual map application (was the catch-all in v3; now explicit)

### Headline changes since v3

**Schema** — 14 → **16 migrations**. New tables:
- `015_growth_features.sql` — `activation_metrics`, `alerts`, `alert_deliveries`, `custom_layers`, `embeds`, `integrations`, `da_boundaries_ca`, `demographics_cache_ca`, `demographics_history` + 6 new columns on `users`, `organizations`, `projects`
- `016_bugfix_round.sql` — `stripe_webhook_events` (idempotency)

**8 new controllers** (31 → 42): `OnboardingController`, `AlertsController`, `CustomLayerController`, `EmbedController`, `PresenceController`, `DriveTimeMatrixController`, `TerritoryRebalancerController`, `ForecastController`. `CrmController` was rewritten from a stub into a real OAuth implementation with AES-256-CBC token storage. `MclpController` rewritten with spatial-index pre-filter.

**2 new services** (20 → 22): `StatCanService` (Canadian Dissemination Areas, 2021 Census), `DemographicsHistoryService` (2019-2023 ACS vintages for the Trends sub-tab).

**Plan enforcement scaffolding** — `config/plans.php`, `App\Core\Middleware\PlanGate`, frontend `<UpgradeGate>` component. Flags currently all `true` per durable directive ("no restrictions on free tier"); cells flip per-feature later without code changes.

**Visual upgrades** — full marketing homepage with gradient hero, dashboard, project gallery, radar chart in ComparisonView, Street View modal, 5 map style presets, "Made with Smappen" badge.

**Reliability + ops fixes (multiple audit rounds today)**:
- 6 spatial WKT axis-order bugs (POINT was lng-lat instead of lat-lng) — silent failures across imports, AI scoring, MCLP, analog search, field notes, competitor scans
- 10 external curl calls now have `CURLOPT_CONNECTTIMEOUT => 3`
- Heatmap memory exhaustion (10K → 3K tract cap, ST_AsGeoJSON precision per zoom, row buffer freed early)
- PHP-FPM pool 5 → 20 workers
- SSE presence stream short-circuits when no peers (was tying up workers)
- Stripe webhook idempotency table
- Stuck-job sweeper in cleanup-cron
- Isochrone hard-capped at 60 min with friendly ORS error translation
- Places search tiles past the 20-result cap
- Territory generation returns 422 for "no census coverage" (was 500)
- Service worker fully killed (was causing recurring stale-cache bugs)
- Stale-chunk auto-recovery in `ErrorBoundary` + `main.tsx`
- Orphan modal-backdrop sweeper on every navigation
- Security headers (HSTS, X-Frame-Options, CSP frame-ancestors, etc.) on every API response

---

## Tech stack

### Backend
- **PHP 8.3 FastCGI** behind Apache 2.4 (mod_proxy_fcgi to `/var/run/php/php8.3-fpm.sock`)
- **MySQL 8.0.45** — strict SRID 4326 spatial mode, `axis-order=lat-long` enforced
- Custom router + Request/Response in `App\Core\*` (no framework)
- **PSR-4 autoload** under `App\*` → `src/`
- **Composer deps**: `firebase/php-jwt`, `stripe/stripe-php`, `tecnickcom/tcpdf`, `vlucas/phpdotenv`, `phpmailer/phpmailer`, `monolog/monolog`, `predis/predis`, `aws/aws-sdk-php` (Spaces S3 SigV4)
- **JWT** HS256 with `jti` claim, server-side revocation via `revoked_tokens` table, bulk revocation via `users.tokens_invalid_before`
- **Sessions** for the CRM OAuth flow only (state token); everything else stateless JWT

### Frontend
- **React 18 + TypeScript 5.5 + Vite 5**
- **Tailwind v4** via `@tailwindcss/vite` (no build step at the project root — the `frontend/` workspace builds into `public/app/`)
- **Zustand** for state (7 stores)
- **TanStack React Query v5** for server cache
- **react-google-maps/api** with libraries: drawing, visualization, geometry, places
- **Vite manualChunks**: `gmaps`, `charts` (recharts), `react-vendor`, `state` (zustand + RQ) split into stable cached vendor chunks; main bundle ~370KB gzipped 106KB
- **Lucide React** for icons (single icon set across the app)
- **react-hot-toast** for toasts
- **Nunito** webfont (single family, weights 400-900)
- **PWA** — manifest only (service worker disabled — see [Reliability section](#reliability--deploy-resilience))

### External services
- **Google Maps Platform** — Maps JS (map render), Geocoding API, Places API (New) — `searchNearby` + `searchText`, Static Maps API (PDF reports)
- **OpenRouteService** (ORS) — driving + walking + cycling isochrones, traffic-aware matrix; 60-min hard cap
- **US Census Bureau** — ACS 5-year (2023 vintage); migration script pulls all 50 states + DC (84,415 tracts)
- **Statistics Canada (StatCan)** — 2021 Census of Population WDS API (scaffolded; operator script not yet run)
- **Anthropic Claude** — `claude-haiku-4-5-20251001` for AI Site Scoring v2 (multi-dimensional with narrative). Falls back to deterministic local heuristic when `ANTHROPIC_API_KEY` is unset.
- **Stripe** — checkout, customer portal, webhook signature verification, idempotency table
- **Postmark / Resend** (whichever env is configured) — transactional email via `MailService`
- **DigitalOcean Spaces** (S3-compatible) — file uploads (field-note photos, exports); SigV4 signing in `StorageService`
- **Salesforce + HubSpot** — real OAuth in `CrmController`; tokens AES-256-CBC encrypted at rest in `integrations` table

### Hosting
- Single DigitalOcean droplet at **143.244.144.7** (`/var/www/smappen`)
- **Apache 2.4** terminates TLS (Let's Encrypt via certbot), proxies `*.php` to PHP-FPM 8.3 sock
- 4GB RAM, 2 vCPU
- Subdomain: `smappen.mygreendock.com`
- Same droplet also hosts `greendock` (separate vhost on PHP-FPM 8.2)

---

## Architecture & data flow

```
                        ┌────────────────────────────────────────────────┐
                        │   Browser  (React 18 SPA, /app/index.html)     │
                        │   ┌──────────────────────────────────────────┐ │
                        │   │   AppLayout                              │ │
                        │   │   ├─ Header (project switcher, save status,│ │
                        │   │   │           undo/redo, cost widget,     │ │
                        │   │   │           notifications, user menu)   │ │
                        │   │   ├─ LeftPanel (areas, folders)           │ │
                        │   │   ├─ MapCanvas (Google Maps)              │ │
                        │   │   │   ├─ AreaPolygon × N                  │ │
                        │   │   │   ├─ AreaCenterPins                   │ │
                        │   │   │   ├─ POIMarkers, ImportedMarkers      │ │
                        │   │   │   ├─ ChoroplethLayer (heatmap)        │ │
                        │   │   │   ├─ PresenceCursors (SSE)            │ │
                        │   │   │   └─ DrawingTools                     │ │
                        │   │   ├─ RightToolbar (40px icons)            │ │
                        │   │   ├─ RightPanel (area details, Street View)│ │
                        │   │   ├─ AdvancedPanel (10 lazy tabs)         │ │
                        │   │   ├─ HeatmapPanel                         │ │
                        │   │   ├─ TimeMachinePanel (Daypart)           │ │
                        │   │   ├─ FirstRunWizard (gated on flag)       │ │
                        │   │   └─ OnboardingChecklist                  │ │
                        │   └──────────────────────────────────────────┘ │
                        └────────────────────────────────────────────────┘
                                          │ HTTPS / JWT in Authorization
                                          │ SSE for /presence/stream
                                          ▼
                        ┌────────────────────────────────────────────────┐
                        │   Apache 2.4 vhost                             │
                        │   • SPA fallback → /app/index.html             │
                        │   • /api/* → /index.php → Router               │
                        │   • Security headers on every response         │
                        └────────────────────────────────────────────────┘
                                          │
                                          ▼
                        ┌────────────────────────────────────────────────┐
                        │   PHP-FPM 8.3 (pool: dynamic, max=20)          │
                        │   public/index.php                             │
                        │   ├─ Config::load → .env                       │
                        │   ├─ Security headers (HSTS, X-Frame, CSP, …)  │
                        │   ├─ CORS preflight short-circuit              │
                        │   └─ Router::dispatch(Request)                 │
                        │       ├─ Middleware::auth (JWT or X-Api-Key)   │
                        │       ├─ Middleware::rateLimit (api_usage_log) │
                        │       ├─ Middleware::requireRole               │
                        │       ├─ PlanGate::feature (scaffold; off)     │
                        │       └─ Controller::method(Request)           │
                        │           ├─ Models / Services                 │
                        │           ├─ CacheService (Redis or MySQL)     │
                        │           ├─ Database (PDO, prepared)          │
                        │           ├─ external HTTP (Google/ORS/Stripe/ │
                        │           │   Anthropic/Postmark/Spaces/SF/HS) │
                        │           └─ Response::success / ::error       │
                        └────────────────────────────────────────────────┘
                                          │                            ▲
                                          ▼                            │
                              ┌──────────────────┐         ┌────────────┴───┐
                              │  MySQL 8.0.45    │         │  Redis 7       │
                              │  (smappen DB)    │         │  (optional —   │
                              │  + SPATIAL idxs  │         │   CacheService │
                              │                  │         │   falls back to│
                              │                  │         │   `cache` row) │
                              └──────────────────┘         └────────────────┘
                                          ▲
                                          │
                              ┌──────────────────┐
                              │  cron (root)     │
                              │  scripts/cleanup-│
                              │  cron.php hourly │
                              │  scripts/run-    │
                              │  competitor-     │
                              │  scans.php daily │
                              └──────────────────┘
```

**Multi-tenancy**: every business-scoped row carries `organization_id`. Every controller method that takes a resource ID must verify the resource's org matches `$request->user['organization_id']`. The audit-cycle on 2026-05-24 caught one drift (AlertsController accepting cross-org `area_id`) — now fixed.

**Tile cache layer**: heatmap viewport queries are quantized + cached for 7 days in `heatmap_tile_cache`. Per-area demographics cached on the `areas` row for 30 days. Reach calculations cached for 30 days in `reach_cache`. Places nearby + text cached 48h.

---

## Database schema (16 migrations)

All migrations live in `src/Migrations/`. Each is idempotent (CREATE TABLE IF NOT EXISTS + INFORMATION_SCHEMA guards on ALTERs).

| # | File | What it adds |
|---|---|---|
| 001 | `initial_schema.sql` | Core: `organizations`, `users`, `projects`, `folders`, `areas` (POLYGON geometry, SRID 4326), `imported_points`, `share_tokens` |
| 002 | `cache_table.sql` | Generic `cache` table (key/value/expires_at) — fallback when Redis unavailable |
| 003 | `demographics_indexes.sql` | `census_tracts` + `census_demographics` + SPATIAL INDEX on `geometry` |
| 004 | `aggregated_geo_and_tile_cache.sql` | `census_counties` + `census_states` + `heatmap_tile_cache` (cache_key, response, hits) |
| 005 | `advanced_features.sql` | `territory_generation_jobs`, `cannibalization_overlaps`, `mclp_runs`, `tract_segments`, `competitor_monitors`, `tracked_places`, `competitor_alerts`, `competitor_scans`, `field_notes`, `versions`, `comments`, `approvals`, `collaborators` |
| 006 | `auth_and_jobs.sql` | `auth_tokens` (one-shot reset/verify), `revoked_tokens` (jti blacklist), `jobs` (queue), `webhook_deliveries`, `webhook_subscriptions`, `api_keys`, `api_usage_log` |
| 007 | `role_rename.sql` | Collaborator role enum drift fix (`approver` → `admin`) |
| 008 | `bug_fixes.sql` | Idempotent INFORMATION_SCHEMA-guarded ALTERs for pre-MySQL-8.0.29 droplets |
| 009 | `api_cost_tracking.sql` | `api_usage_log.estimated_cost_usd` + `api_usage_log.endpoint` |
| 010 | `area_favorites.sql` | `areas.is_favorite` |
| 011 | `areas_geometry_type.sql` | `areas.geometry` POLYGON → GEOMETRY (so MultiPolygon territories can land cleanly) |
| 012 | `areas_sort_order.sql` | `areas.sort_order` (persisted drag-reorder) |
| 013 | `ops_features.sql` | `saved_searches`, `saved_comparisons`, `activity_log`, `tags`, `area_tags`, `scheduled_reports` |
| 014 | `data_scale_features.sql` | `data_scale_features` (tract-level demographic features for AnalogService), `data_scale_segments`, `import_batches` |
| **015** | **`growth_features.sql`** | **`activation_metrics`, `alerts`, `alert_deliveries`, `custom_layers`, `embeds`, `integrations` (CRM tokens), `da_boundaries_ca`, `demographics_cache_ca`, `demographics_history`. Plus columns: `users.onboarding_flags JSON`, `users.use_case`, `users.signed_up_at`, `organizations.trial_ends_at`, `organizations.stripe_status`, `projects.is_sample`** |
| **016** | **`bugfix_round.sql`** | **`stripe_webhook_events` (idempotency dedupe table; PK on event_id)** |

Per the v4 audit: all 16 ran clean on the production droplet on 2026-05-24. Backup at `/var/www/smappen/backups/smappen-pre015-20260524T1838.sql.gz` (~600MB) taken before migration 015.

---

## Backend surface — controllers + endpoints

**42 controllers, 172 routes** in `config/routes.php`. Auth modes:
- **Public** (no middleware) — health, public-share, webhook receivers, OpenAPI spec
- **`Middleware::auth()`** — JWT in `Authorization: Bearer ...` OR `X-Api-Key` header OR `?token=` query param (the query-param fallback exists specifically for SSE EventSource, which can't set headers; the codebase only consults it when the header is absent)
- **`Middleware::rateLimit()`** — per-api-name windowed quota tracked in `api_usage_log`. Adds `X-RateLimit-Limit/Remaining/Reset` headers and `Retry-After` on 429
- **`Middleware::requireRole()`** — owner/admin/editor/viewer gates on team-admin routes

### Public (no auth)

- `GET /api/health` — DB ping, returns commit version + `connections` (current/max) for monitoring
- `GET /api/openapi.json` — OpenAPI 3.1 spec covering all auth'd endpoints
- `GET /api/docs` — Swagger UI
- `GET /api/public/projects/{token}` — read-only project payload for `/share/:token`
- `GET /api/public/projects/{token}/embed` — minimal embed-friendly payload for iframes
- `POST /api/billing/webhook` — Stripe webhook receiver (verified by HMAC signature + idempotency via `stripe_webhook_events`)
- `GET /robots.txt`, `/sitemap.xml` — SEO

### Auth (JWT or X-Api-Key)

- `POST /api/auth/register` / `/login` — public
- `POST /api/auth/refresh` / `/logout` — auth required
- `GET /api/auth/me` — current user + plan
- `POST /api/auth/request-reset` / `/reset` — password reset
- `GET /api/auth/verify-email` / `POST /api/auth/resend-verification`
- `PUT /api/auth/profile` / `POST /api/auth/change-password`
- `GET /api/auth/api-key` / `POST /api/auth/api-key/regenerate` — long-lived API keys

### Onboarding (new in v4)

- `POST /api/onboarding/use-case` — store `users.use_case`
- `POST /api/onboarding/seen` — stamp a flag into `users.onboarding_flags JSON`
- `GET /api/onboarding/state` — return flags + use_case + signed_up_at
- `POST /api/onboarding/clone-sample` — copy the system-wide `is_sample` project (folders + areas with geometries) into the caller's workspace
- `POST /api/onboarding/activate` — explicit activation-funnel stamps (`first_area`, `first_demographic`, etc.); most stamps fire automatically from controllers

### Core mapping

- `GET/POST/PUT/DELETE /api/projects/...` — CRUD + share + archive + bundle export
- `GET /api/projects/{id}/folders` + folder CRUD
- `GET/POST/PUT/DELETE /api/projects/{id}/areas` + reorder + `/areas/{id}/rebuild-boundary`
- `GET /api/areas/{id}/demographics` — Census-backed (tract-overlap weighted)
- **`GET /api/areas/{id}/demographics/trends`** — **new in v4**: per-vintage time series for any metric
- `GET /api/areas/{id}/pois` — cached POIs for an area
- `POST /api/demographics/compare` — multi-area comparison
- `POST /api/areas/reach` / `POST /api/demographics/preview` — live area-sizing
- `POST /api/isochrone/calculate` — drive/walk/cycle isochrones (capped at 60 min with friendly ORS error translation)
- `POST /api/geocode` + `/geocode/batch`
- `POST /api/places/nearby` (tiles past 20-result cap via 5 quadrant sub-calls) + `/places/search` (paginates up to 60 via nextPageToken) + `/places/{id}`

### Heatmap (choropleth)

- `GET /api/heatmap/tracts?bbox=lng1,lat1,lng2,lat2&metric=...&zoom=N&level=auto|state|county|tract`
- Server tile cache + zoom-based LOD (state ≤7, county 8-9, tract ≥10)
- Per-level row caps + ST_AsGeoJSON precision per zoom (state p=2, county p=3, tract p=4)

### Import / Export / Reports

- `POST /api/projects/{id}/import/upload` → `/import/configure` → status
- `GET /api/projects/{id}/export/areas|points` + `/api/areas/{id}/export/pois` (csv | xlsx | geojson | kml with per-color KML styles)
- `POST /api/areas/{id}/report` / `/report.pdf` — TCPDF
- `GET /api/report-templates` — **new in v4**: returns the template catalog (executive / site_selection / franchise_pitch / demographics_only)

### Advanced (the ✨ sparkle panel)

- `POST /api/projects/{id}/territories/generate` (k-means balanced; 422 on no-coverage)
- `POST /api/projects/{id}/optimize/locations` (MCLP greedy + local-search; spatial-indexed pre-filter; cap 500)
- `POST /api/projects/{id}/rebalance` — sales-territory rebalancer
- `POST /api/areas/{id}/analogs` — find demographically-similar tracts
- `POST /api/drive-time-matrix` — N×M ORS matrix
- `POST /api/areas/{id}/forecast` — demand forecasting from analogs
- `GET /api/projects/{id}/cannibalization` — overlap risk tiers
- `POST /api/isochrone/traffic` + `/grid` + `/day` — traffic-aware variants
- `GET /api/projects/{id}/competitor-monitors` + CRUD + `/scan` + `/places` + `/alerts`
- `GET /api/projects/{id}/field-notes` + CRUD + `/where-am-i`
- `GET /api/segmentation/segments` + `/areas/{id}/segments` + `/projects/{id}/segments` + `/segmentation/recompute`
- `POST /api/areas/{id}/ai-score` + `POST /api/projects/{id}/ai-rankings` (v2 with dimensions[reach, affluence, competition, segment_fit] + narrative)

### Collaboration

- `POST/GET /api/projects/{id}/versions` + `/api/versions/{id}` (snapshots)
- `GET/POST /api/projects/{id}/comments` + resolve + delete
- `GET /api/projects/{id}/changes` — change log
- `GET/POST/DELETE /api/projects/{id}/collaborators/{userId}` (admin/editor/viewer roles)
- `POST/GET /api/projects/{id}/approvals` + `/api/approvals/{id}/decide`

### Realtime presence (new — was scaffold in v3)

- `POST /api/projects/{id}/presence/ping` — broadcast mouse position
- `GET /api/projects/{id}/presence/stream` — Server-Sent Events, short-circuits to `retry: 30000` when no peers (so a solo session doesn't pin a worker)

### Operational tweaks

- Saved searches + comparisons + tags + scheduled reports + activity feed + webhook deliveries — small CRUD endpoints under `OpsController`

### Cost tracking

- `GET /api/usage/today` / `/days` / `/pricing` + `POST /api/usage/log-map-load`
- `_meta.estimated_cost_usd` on every Google-fronting response; tile-aware count for Places nearby (5 sub-calls = 5× cost)

### CRM (real OAuth in v4 — was stub in v3)

- `POST /api/integrations/salesforce/connect` → returns ORS auth URL; state token saved in session
- `GET /api/integrations/salesforce/callback` — exchanges code for tokens, AES-256-CBC encrypts at rest in `integrations` table, redirects to `/settings/integrations?connected=salesforce`
- `POST /api/integrations/salesforce/push` — upserts area demographics into SF Account custom fields
- Same triplet for HubSpot. Token decryption refuses to run without `APP_KEY` in `.env` (32 random bytes base64).

### Alerts (new in v4)

- `GET/POST/PUT/DELETE /api/alerts` — generic rules (competitor_new / demographics_changed / ai_score_drop / metric_threshold)
- `POST /api/alerts/{id}/test` — fire a synthetic delivery (sandbox)
- `GET /api/alerts/digest/recent` — last 7 days of deliveries for the weekly email digest cron

### Custom data layers (new in v4)

- `GET/POST /api/projects/{id}/custom-layers` + `PUT/DELETE /api/custom-layers/{id}`
- `GET /api/custom-layers/{id}/points` — resolves layer to its underlying `imported_points` rows

### Embed builder (new in v4)

- `GET/POST /api/projects/{id}/embeds` + `PUT/DELETE /api/embeds/{id}` — mints `embed_token`, returns iframe snippet HTML

### Background jobs + webhooks

- `GET /api/jobs/{id}` + `POST /api/jobs/{id}/cancel`
- `GET/POST/PUT/DELETE /api/webhooks/{id}` + `/test` + `/deliveries`
- `GET /api/webhooks/deliveries` — recent delivery log

### Notifications

- `GET /api/notifications` + `POST /api/notifications/{id}/read` + `/read-all`

### Billing (Stripe)

- `POST /api/billing/checkout` — creates a Checkout Session
- `POST /api/billing/webhook` — receives + verifies + dedupes via `stripe_webhook_events`
- `GET /api/billing/subscription` — current state
- `POST /api/billing/portal` — Customer Portal session
- `POST /api/billing/cancel` — cancel current sub

---

## Services layer

22 services in `src/Services/`. The non-trivial ones:

| Service | Purpose |
|---|---|
| `GoogleMapsService` | Geocoding + Places (new) — searchNearby tiles past 20-result cap, searchText paginates to 60. Per-call cost tracked via `$lastCallCount`. |
| `GooglePricing` | API-name → USD/call mapping (geocode 0.005, places_nearby 0.032, place_details 0.020, static_map 0.002, etc.) |
| `CensusService` | ACS 5-year v2023, 84,415 tracts. Tract-overlap-weighted aggregation with `ST_GeometryType IN ('Polygon','MultiPolygon')` guard against GEOMETRYCOLLECTION returns. |
| `DemographicsHistoryService` | Per-vintage history (2019-2023). Operator script `ingest-demographics-history.php` hits the ACS API one (state × year) at a time. |
| `StatCanService` | Canadian Dissemination Areas (2021 Census). Scaffolded; needs operator-run `import-statcan-da.php` to seed the 57K DAs. |
| `IsochroneService` | ORS proxy. 60-min hard cap. |
| `TrafficService` | Traffic-aware isochrone variants (`/grid`, `/day` for Daypart). |
| `DriveTimeMatrixService` | N×M time matrix via ORS. |
| `TerritoryGenerator` | k-means balanced + `ST_Union` over source tracts (no convex-hull shortcuts; 80-tract cap per cluster). Fallback to hull on union failure. |
| `AnalogService` | Demographically + competitively-similar tract search. Pre-filters via tract id lookup; main query is flat `NOT IN (?, ?, ...)`. |
| `SegmentationService` | Tract segment assignment + recompute. |
| `CompetitorScanner` | Per-monitor scan, MOVE > 150m, RATING Δ > 0.3, REVIEWS Δ > 25%. Sends email + Slack via `MailService` + webhook. |
| `PdfReportService` | TCPDF; accepts a template config (executive / site_selection / franchise_pitch / demographics_only). |
| `StripeService` | Subscription state mutations. Idempotency now enforced in `BillingController` via `stripe_webhook_events`. |
| `MailService` | Postmark/Resend wrapper (configurable). All curls now have `CURLOPT_CONNECTTIMEOUT => 3`. |
| `StorageService` | DigitalOcean Spaces (S3 SigV4 virtual-hosted addressing). |
| `WebhookDispatcher` | HMAC-signed outbound webhook delivery + retry. |
| `CacheService` | Redis primary, MySQL `cache` table fallback. |
| `Permissions` | Role-rank logic (owner > admin > editor > viewer). |
| `GeoUtils` | bbox, point-in-polygon, haversine. |
| `FootTrafficService` | Stub for future Placer.ai-style integration. |
| `PermitsService` | Stub for future permits-API integration. |

---

## Background jobs + operator scripts

`scripts/` directory:

| Script | Cadence | What it does |
|---|---|---|
| `cleanup-cron.php` | Hourly (cron) | Purges expired cache, auth_tokens, revoked_tokens, old jobs (>30d), webhook_deliveries (>30d), export files (>1h), upload temp (>24h). **New**: marks `jobs` and `territory_generation_jobs` `status='failed'` if running > 30 min (stuck-job sweeper). |
| `run-competitor-scans.php` | Daily | Iterates `competitor_monitors`, scans each, fans alerts to email + Slack + in-app. |
| `compute-tract-features.php` | Operator-run | Pre-computes the 18-dim feature vectors used by AnalogService. |
| `compute-sri.php` | Operator-run | Computes Subresource Integrity hashes for the CDN-loaded assets. |
| `verify-backup.php` | Operator-run | Smoke-tests the latest `mysqldump` backup. |
| `seed-census.php` | Operator-run | Seeds census tracts + demographics for one state. |
| `seed-all-states.sh` | Operator-run | Loops `seed-census.php` for all 50 states + DC. |
| `aggregate-geographies.php` | Operator-run | Builds `census_counties` + `census_states` (aggregated polygons + demographics) used by the heatmap state/county zoom levels. |
| `import-statcan-da.php` | **Not yet written** | Will seed `da_boundaries_ca`. |
| `ingest-demographics-history.php` | **Not yet written** | Will backfill `demographics_history` for 2019-2023. |
| `normalize-areas-geometry.php` | Operator-run | One-shot migration helper for areas stored with the old POLYGON-only column. |
| `normalize-dmv-geometry.php` | Operator-run | DC/MD/VA tract polygon-axis normalization (one-shot). |
| `debug-places.php` | Operator-run | Quick Places API smoke test. |

`job-worker.php` (PHP CLI long-runner) reads from the `jobs` table with `SELECT ... FOR UPDATE SKIP LOCKED`. Currently used by territory generation when the request exceeds the 60s sync budget. The 30-min stuck-job sweep is the safety net.

---

## Frontend surface

### Routing (`App.tsx`)

```
/                         → HomePage (anonymous) | <Navigate to="/dashboard"> (auth'd)
/blog                     → BlogPage
/dashboard                → DashboardPage  (auth)
/projects                 → ProjectGalleryPage  (auth)
/login | /register | /forgot-password | /reset-password | /verify-email
/pricing                  → PricingPage
/changelog                → ChangelogPage
/share/:token             → SharedProjectPage  (public)
/embed/:token             → EmbedProjectPage  (public)
/settings/profile|team|integrations|api|webhooks|billing   (auth)
/app/*                    → AppLayout  (auth) — the actual map app
/*                        → <Navigate to="/" replace />
```

In v3 the catch-all rendered `AppLayout`. In v4 the app is explicit at `/app/*` and unknown paths bounce to `/`.

### AppLayout structure

`AppLayout.tsx` mounts the map, all four chrome surfaces, the global modals (FirstRunWizard, CommandPalette, WhatsNewModal, ShortcutsModal, OnboardingChecklist), and reads onboarding state on mount to gate the wizard. The FirstRunWizard auto-opens on first visit; dismiss/skip stamps `wizard_complete` so it never re-shows.

### Component tree (key components — bolded in v4)

```
src/components/
├── auth/           LoginPage, RegisterPage, ForgotPasswordPage, ResetPasswordPage,
│                   VerifyEmailPage, ProtectedRoute
├── billing/        PricingPage, BillingSettings, **UpgradeGate** (plan-feature wrapper)
├── settings/       SettingsLayout, ProfileSettings, TeamSettings,
│                   IntegrationsSettings, ApiKeySettings, WebhookSettings
├── share/          SharedProjectPage, EmbedProjectPage, **SmappenBadge**
├── marketing/      **HomePage**, **BlogPage**, ChangelogPage
├── **dashboard/    DashboardPage**
├── **projects/     ProjectGalleryPage**
├── **onboarding/   FirstRunWizard**
├── layout/         AppLayout, Header, LeftPanel, RightPanel, RightToolbar
├── map/            MapCanvas, AreaPolygon, AreaCenterPins, POIMarkers,
│                   ImportedMarkers, DrawingTools, ChoroplethLayer, **ChoroplethWebGL**,
│                   HeatmapPanel, TimeMachinePanel (Daypart), **PresenceCursors**,
│                   **StreetViewModal**
├── areas/          AreaList, AreaCard, AreaCreator, AreaEditor, FolderTree,
│                   **QuickStatsStrip**
├── analytics/      DemographicsPanel, POISearchPanel, ComparisonView, **RadarChart**
├── advanced/       AdvancedPanel (lazy parent) + lazy tabs:
│                   AnalogTab, AnalyticsTab, CannibalizeTab, CommentsTab, CompetitorsTab,
│                   FieldTab, OptimizeTab, SegmentsTab, TerritoriesTab, TrafficTab, VersionsTab
├── common/         AnimatedNumber, CommandPalette, EmptyState, HelpHint,
│                   OnboardingChecklist, SaveStatus, ShortcutsModal, WhatsNewModal, Spinner
├── data/           ImportWizard, ExportDialog, ReportButton
└── ErrorBoundary   (now auto-recovers from stale Vite chunk errors)
```

### Hooks

- `useTheme` — sets `data-theme="dark|light"` on `<html>`, prefers user setting → localStorage → system preference
- `useShortcuts` — global keyboard map (Ctrl+/, Ctrl+S, Cmd+Z/Shift+Z, ? for shortcut help, etc.)
- `useDynamicFavicon` — favicon color reflects unread notifications
- `useViewUrl` — `#map=lat,lng,zoom` URL hash sync (one-shot read on map ready, debounced write on idle)
- `useClickOutside` — generic dropdown-dismissal helper

### Stores (Zustand)

| Store | Purpose |
|---|---|
| `authStore` | JWT + user. `partialize` persists only the token. |
| `projectStore` | Current project + areas + folders + importedPoints |
| `mapStore` | Map instance ref, viewport, drawing mode, heatmap state, time-machine state, presence peers, right-panel tab, time-machine request |
| `uiPrefsStore` | recentColors, areaListFilter/groupBy/order, **mapStyle (detailed | clean | mono | dark | satellite)**, showPolygonLabels, onboardingCompleted |
| `costStore` | totalUsdToday + callCountToday + per-session deltas |
| `undoStore` | reversible action stack (Cmd+Z / Shift+Cmd+Z) |
| **`saveStatusStore` (new in v4)** | pending count, lastSavedAt, lastError; `trackSave(promise)` wraps every project/area mutation so the header shows Saving / Saved / Couldn't save |

### Utilities (new in v4 bolded)

- `format.ts`, `colors.ts` — number/currency/area formatters; brand palette helpers
- `mapStyle.ts` — **5 presets** (was 2 in v3): detailed / clean / mono / dark / satellite. Roster-driven, picker auto-extends.
- `mapAnim.ts` — smooth fly-to (combined pan + zoom)
- `mapExport.ts` — Static-Maps PNG snapshot with style + auto-fit
- `confetti.ts` — dependency-free particle burst (VT20)
- `sessionRecord.ts` — MediaRecorder of the map's largest canvas (~30s webm)
- `snapToRoads.ts` — pre-fetched Google Roads helper
- `toastBatch.ts` — batches cost toasts so a 200-row geocode batch doesn't pop 200 toasts

---

## Feature catalog

### Marketing surface (new in v4)

- **HomePage** at `/` — gradient hero ("Territory mapping that actually answers questions"), value-props grid, pricing teaser. Logged-in users redirect to `/dashboard`.
- **BlogPage** at `/blog` — 3 seed posts (trade area analysis, drive-time vs radius, balancing franchise territories)
- `robots.txt`, `sitemap.xml` — explicit SEO surface

### Dashboard + project gallery (new in v4)

- **`/dashboard`** — three-column landing post-login: project cards (up to 8) + recent activity feed + usage summary (API spend today, project count). Empty-state CTA clones the system sample project.
- **`/projects`** — full gallery, grid/list views (persisted to localStorage), search, sort (recent / name / area-count), per-card rename / archive / delete

### First-run wizard (new in v4)

3-step modal:
1. Use-case picker (franchise / sales territory / site selection / delivery zone / other) — writes `users.use_case`
2. Address input via Google Places autocomplete
3. Auto-generated 15-min driving isochrone + AnimatedNumber population count-up

Mounted in `AppLayout`, gated on `onboarding_flags.wizard_complete`. Dismiss/skip stamps the flag (never re-shows).

### Mapping core

- Drawing: free-draw polygon, isochrone (drive / walk / cycle), radius circle, pin-drop
- Auto-fit / fly-to / fitBoundsToArea on selection
- Polygon hover infowindow with population + median income
- Centroid badges showing travel mode + minutes
- Polygon labels (toggleable via uiPrefsStore)
- MultiPolygon support throughout (territory rebuild path)
- Drag-reorder areas with persistence
- Color picker per area: 24-color named palette + Recent (last 5) + Brand row + custom hex

### Demographics (right-panel "People" tab)

- Population (with male/female + 5 age buckets)
- Income (median household, 5 brackets)
- Housing (units, median home value)
- Unemployment %
- Density per km²
- **Trends sub-tab (new in v4)** — per-vintage time series for any metric (2019-2023 ACS)
- DataFreshnessFooter: "Source: US Census ACS 2023" + stale-data badge if >18mo old

### Heatmap (choropleth)

- 6 metrics: population, population density, median income, median home value, unemployment rate, housing units
- 5 boundary levels (auto + state/county/tract overrides)
- 11 color palettes — Browse menu in the heatmap panel
- Tract cap: 3000 per request (was 10K — caused OOM at the new 84K-tract scale)
- Truncation hint when bbox returns the cap
- Server tile cache: 7 days, bbox quantized to coarse grid

### POI / Businesses

- Chip strip: Restaurant, Cafe, Pharmacy, Gym, School, Hospital, Bank, Gas Station, Store, custom keyword
- **Tiled search (new in v4)** — when the first 20-result response saturates, fires 4 quadrant sub-calls at radius/√2; merges by `place.id`, sorts by distance, caps at 200
- **Text search pagination (new in v4)** — `nextPageToken` flow for up to 60 results
- POI markers clustered via `@googlemaps/markerclusterer`

### Reports

- TCPDF, brand header (gradient), per-area static map with the area's fill color + centroid pin
- **4 templates (new in v4)**: executive (3 pages), site_selection (full), franchise_pitch (sales-tone, customer-facing), demographics_only (deep-dive)
- Per-area or per-project (AI ranking)

### Import / Export

- CSV / XLSX import: streamed row-by-row, server-side preview before commit
- Export: CSV / XLSX / GeoJSON / KML (with per-color KML PolyStyle + rich descriptions for Google Earth)

### Auth & accounts

- Email/password + JWT + revocation
- Password reset (one-shot tokens in `auth_tokens`)
- Email verification (same table)
- Long-lived API keys (`X-Api-Key` header)
- Bulk JWT revocation via `users.tokens_invalid_before`

### Settings

- Profile, Team (admin/editor/viewer roles), Integrations (Salesforce/HubSpot OAuth), API keys, Webhooks (subscription CRUD + delivery log), Billing (Stripe portal embed)

### Realtime collaboration

- **Presence cursors (new in v4)** — SSE-backed peer cursor pips. Solo session = no SSE worker held (server short-circuits with `retry: 30000`; client only pings while peers > 0)
- Versions (snapshot + restore)
- Comments (resolve, delete)
- Change log
- Approvals (request + decide)

### Notifications

- Bell icon (badge for unread), 60s poll, mark-read + mark-all-read
- Dynamic favicon: red dot when unread
- Per-CLAUDE.md memory: bell is for **decisions and abnormal events**, never routine activity logging

### Public sharing

- `/share/:token` — read-only project view, geometry + demographics, no edit affordances
- `/embed/:token` — minimal iframe-ready view, "Powered by Smappen" badge

### Daypart (24-hour traffic animation)

- Docked bottom strip, ORS traffic-aware `/api/isochrone/traffic/day`
- Play/pause + 4-speed scrubber, 24-bar heatstrip
- CSV download
- Polygon morphs on the live map via `mapStore.timeMachine`

### Cost tracking

- Header widget "$X.XX today" + per-API breakdown popover
- 60s poll + optimistic local bump on every billable response
- Toasts batched 600ms so a 200-row batch shows one summary toast

### Advanced features (the ✨ panel — 10 lazy tabs)

- **Territories**: k-means balanced, MultiPolygon-aware, compass-naming (NW / SE / etc.), rebuild-boundary action
- **Analogs**: 18-dim feature similarity, color-by-similarity markers, similarity legend
- **Analytics**: Drive-time matrix + sales-territory rebalancer + demand forecasting
- **Cannibalize**: pairwise overlap risk tiers
- **Traffic**: per-day traffic-aware isochrones + the "Watch drive-time over a full day" launch
- **Optimize**: MCLP greedy + local-search, spatial-indexed pre-filter, candidate cap 500
- **Segments**: tract segments per area / project
- **Comments**: project-level discussion threads
- **Versions**: snapshot + restore
- **Competitors**: monitor list + scan + alert log
- **Field notes** (mobile PWA)
- **AI Site Scoring v2** (new): 4 dimensions (reach, affluence, competition, segment_fit) + plain-English narrative, Claude Haiku 4.5 with local-heuristic fallback

### Alerts (new in v4)

- 4 kinds: `competitor_new` / `demographics_changed` / `ai_score_drop` / `metric_threshold`
- Test-fire button (logs a synthetic delivery)
- Weekly digest endpoint (`/api/alerts/digest/recent`) for the email-summary cron

### Custom data layers (new in v4)

- Upload customer CSV → render as marker layer or derived heatmap
- Palette + radius per layer
- Visibility toggle

### Embed builder (new in v4)

- Generate iframe snippets per project
- Configurable: width/height, show_legend, show_controls, show_branding
- View-count tracker

### CRM integrations (real OAuth in v4)

- Salesforce: full OAuth, AES-256-CBC token storage, push area demographics to Account custom fields
- HubSpot: same triplet, includes hub_id introspection
- Refuses to start without `APP_KEY` in `.env`

### OpenAPI / docs

- `/api/openapi.json` — OpenAPI 3.1
- `/api/docs` — Swagger UI

---

## Plan enforcement + freemium scaffolding

**Per durable user directive: no restrictions are active on the free tier.** All cells in the feature matrix evaluate to `true` for every plan. The scaffolding is in place so individual flags can flip later without code changes elsewhere.

- `config/plans.php` — plan metadata + feature matrix per plan + trial config + dunning grace
- `src/Core/Middleware/PlanGate.php` — `PlanGate::feature($featureName)` middleware factory + `PlanGate::quota($limit, $usageProvider)` + `cheapestPlanWith($feature)` helper
- `frontend/src/components/billing/UpgradeGate.tsx` — wrapper component (renders children when feature is enabled; otherwise a "Pro" pill or full upsell card)

Plan IDs: `free`, `starter`, `pro`, `team`, `enterprise`. Trial target: `pro`, 14 days.

---

## Onboarding + activation funnel

- **`activation_metrics`** table (one row per user): `signed_up_at`, `first_area_at`, `first_demographic_at`, `first_export_at`, `first_share_at`, `first_report_at`, `returned_in_week_2`, `health_score`
- **Auto-stamps from controllers** (set on first occurrence only via `INSERT … ON DUPLICATE KEY UPDATE col = COALESCE(col, VALUES(col))`):
  - `AreaController::store` → `first_area_at`
  - `DemographicsController::show` → `first_demographic_at`
  - `ExportController::exportAreas` → `first_export_at`
  - `ReportController::generate` → `first_report_at`
- **`POST /api/onboarding/activate`** for any frontend-driven step the backend can't observe (e.g., `first_share`)

---

## Visual design system

### Typography
- **Nunito** webfont, weights 400 / 500 / 600 / 700 / 800 / 900
- Loaded once in styles.css via Google Fonts
- No competing font families anywhere

### Color tokens (CSS variables on `:root`)
- **Brand**: `--brand: #7848BB`, `--brand-dark: #6B37A6`, `--brand-light: #EDE5F7`
- **CTA**: `--cta: #E53935`, `--cta-dark: #D42A2A`
- **Ink scale**: `--ink: #1A1A2E`, `--ink-2: #2D2D44`, `--body: #4A4A5A`, `--slate`, `--muted`
- **Borders + bgs**: `--line`, `--line-soft`, `--bg-panel`, `--bg`

### Area palette (24 named colors)
Includes the brand `Smappen Violet` plus a 23-color "preset" row in AreaCard's color picker.

### Heatmap palettes (11)
Browseable from the heatmap panel. Includes Viridis, Plasma, Magma, Inferno, Cividis, Smappen Pastel, Smappen Hot, Smappen Cool, RdBu, BrBG, Spectral.

### Daypart palette (24 colors)
One color per hour, matched to a sun/moon arc.

### Radii & shadows
- `--radius-sm 6px`, `--radius 10px`, `--radius-lg 14px`, `--radius-xl 16px`
- `--shadow-sm`, `--shadow-md`, `--shadow-lg`, `--shadow-float`

### Skeleton loaders
Global `.skeleton` class with shimmer; used in AreaList, POI panel, dashboard, project gallery.

### Density target
"Real usefulness over visual sophistication." Designed for any-age operator on any screen.

### Anti-slop rules (per CLAUDE.md)
No purple→pink gradients, no glassmorphism (unless context already has it), no Poppins/DM Sans/JetBrains Mono, no 24px+ rounded corners on cards, no emojis in copy.

---

## Animation system

Global classes in `styles.css`:
- `.panel-slide-right/left/up/down` — cubic-bezier ease-out for floating panels
- `.card-expand` — transform-origin top, auto-flips to bottom when portaled above trigger
- `.stagger-in` — per-row delay via inline `--stagger-i` CSS var (left-panel area list)
- `.fade-in` — hover-revealed buttons
- `.sparkle-pulse` — featured CTAs
- `.hover-lift` — toolbar buttons
- `.brand-logo-tile` — gradient sweep + shimmer on the smappen logo
- `.spinner`, `.progress-bar`, `.page-loading-logo`, `.shimmer-text`
- `.polygon-glow-pulse` — selected polygon halo

All honor `prefers-reduced-motion`.

---

## Dark mode end-to-end

Toggled via `data-theme="dark"` on `<html>`. Pre-paint script in the header inlines `localStorage.getItem('smappen-theme')` so there's no flash. Set via Profile settings (`user.theme`), or `data-theme` attribute, or system preference fallback.

**Coverage**:
- `:root[data-theme="dark"]` overrides for every `bg-white`, `bg-slate-50/100`, `bg-violet-50/100`, `bg-emerald-50`, `bg-rose-50`, `bg-amber-50`, `bg-blue-50`
- Text overrides for slate-300 through 900
- Brand-ink inline-style hook (`[style*="color:#1A1A2E"]` re-mapped to `#f3f4f6`)
- Input + textarea borders + bgs
- Map style auto-switches to dark Google Maps style when in dark mode
- Dashboard + project gallery now use `bg-white` (Tailwind) so they pick up the dark override; v3 used inline `style={{ background:'#F9F9FB' }}` which escaped the override

---

## Performance & caching

### Frontend bundle (gzipped sizes after deploy on `a21b00a`)

| Chunk | Size | Notes |
|---|---|---|
| `index-*.js` | ~107 KB | main app |
| `charts-*.js` | ~101 KB | recharts |
| `react-vendor-*.js` | ~54 KB | react + react-dom + router |
| `gmaps-*.js` | ~40 KB | @react-google-maps/api + markerclusterer |
| `state-*.js` | ~14 KB | zustand + RQ |
| 11 lazy tab chunks | 1-5 KB each | one per advanced tab |
| Total | ~370 KB main + lazy on-demand | |

### Bundling
- Vite 5 with `manualChunks` (gmaps / charts / react-vendor / state)
- All assets content-hashed; cache-busts on every build
- `base: '/app/'` in vite.config; Apache rewrites unknown paths to `/app/index.html`

### Backend caching
- **Demographics** cached on `areas.demographics_cache` (JSON) for 30 days
- **POI** cached on `poi_cache` (md5 of area + caller params) for 48h
- **Geocode** cached in `cache` (Redis primary, MySQL fallback) for 1 year
- **Heatmap tiles** cached in `heatmap_tile_cache` for 7 days, bbox quantized to coarse grid
- **Reach** cached in `reach_cache` for 30 days
- **Place details** cached for 72h
- **CRM tokens** never cached (encrypted at rest only)

### Hot-path optimizations applied (audit cycles)
- Heatmap state ST_AsGeoJSON precision: 4 → 2 (~25% payload reduction)
- Heatmap county precision: 4 → 3
- Heatmap tract cap: 10000 → 3000 (was OOM at 84K-tract scale)
- MCLP: ST_Distance_Sphere now preceded by `MBRIntersects(geometry, bbox_buffer)` — uses SPATIAL INDEX
- MCLP candidate cap: 1500 → 500
- Places nearby: tile into 5 sub-calls when saturated (was 20-result hard cap)
- Places text: paginate to 60 (was 20)
- SSE presence stream short-circuits on empty peer list (was holding worker 55s)
- PHP-FPM pool 5 → 20 workers
- Apache `mod_deflate` gzips heatmap responses (12MB → ~2MB on the wire)
- Vite manualChunks split (main bundle 971KB → 244KB unc)

---

## Security & auth

- **JWT HS256 + jti + revoked_tokens + tokens_invalid_before** for both per-token and bulk revocation
- **CSRF** — N/A (stateless JWT, no session cookies for the app — only for the OAuth state token, which has its own state parameter check)
- **Rate limits** per api_name in `api_usage_log` with `X-RateLimit-*` headers + `Retry-After` on 429
- **Prepared statements** everywhere (PDO with named or positional params; no string concatenation of user input)
- **Multi-tenant scoping** — every business-scoped query verified by org_id check
- **Stripe webhook**: HMAC signature verified at controller + service layers (defense in depth)
- **Webhook delivery (outbound)**: HMAC signed with per-subscription secret
- **CRM tokens**: AES-256-CBC at rest, IV per-row, key derived from `APP_KEY`
- **Security headers on every response** (set in `public/index.php`):
  - `Strict-Transport-Security: max-age=31536000; includeSubDomains`
  - `X-Frame-Options: SAMEORIGIN`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy: geolocation=(self), microphone=(), camera=()`
  - `Content-Security-Policy: frame-ancestors 'self'` (relaxed to `*` for `/api/public/*` embed surfaces)
- **CORS**: same-origin only by default; preflight short-circuits at 204
- **Stripe webhook idempotency**: `stripe_webhook_events` table prevents duplicate processing

### Bug-fix history (most recent first — 2026-05-24 batch in `a21b00a`)

**Spatial axis-order**: MySQL 8 SRID 4326 strictly enforces `(lat lng)` axis order. 6 files had `POINT(lng lat)` — all fixed:
- `ImportedPoint.php` (every CSV import row was silently dropping its `point` column)
- `AiScoringController.php` (competitor density always saw 0 — silently swallowed by try/catch)
- `MclpController.php` (worked only when MBRIntersects returned 0 rows)
- `FieldNoteController.php` (note save + where-am-i)
- `AnalogService.php` (center-point WKT)
- `CompetitorScanner.php` (tracked_places INSERT + UPDATE)

**ACL fixes**:
- `AlertsController::create` now joins areas → projects to verify caller's org owns the referenced `area_id`

**Reliability**:
- `CURLOPT_CONNECTTIMEOUT => 3` added to 10 external HTTP call sites (was relying on `CURLOPT_TIMEOUT` only — a stalled TLS handshake could hang for the full 60-90s)
- Stripe webhook idempotency via `stripe_webhook_events` (was duplicate-deliverable)
- Stuck-job sweep in cleanup-cron: `UPDATE jobs SET status='failed' WHERE running > 30min` (same for `territory_generation_jobs`)

**Frontend**:
- `DashboardPage` activity fetch now honors `cancelled` flag in both `.then()` and `.catch()`
- `SaveStatus` capture set-state ref into local var for clean listener cleanup
- `TimeMachinePanel` eslint-disable annotated for explicit one-shot useEffect intent

---

## Reliability & deploy resilience

### Service worker — KILLED in v4

The PWA service worker (`/app/sw.js`) is now a self-uninstalling kill-switch. It used to be cache-first on `/app/*` assets, which produced recurring stale-cache + stuck-SW bugs across deploys. Every existing client picks up the new bytes on next visit, the new SW installs + activates + immediately unregisters itself + purges caches + navigates each open client. After that, no SW intercepts anything.

`main.tsx` no longer registers a SW. On every load it actively unregisters any existing SW + purges caches so users don't have to wait for the kill-switch lifecycle to drain.

### Stale-chunk auto-recovery

Vite emits content-hashed chunk filenames. Across deploys, an open tab on the new shell may try to import a chunk that's been replaced. Three guards now:
1. **`ErrorBoundary`** detects `Failed to fetch dynamically imported module` (and variants), purges caches + unregisters SW + reloads once. Shared sessionStorage guard with main.tsx so it never loops.
2. **`main.tsx` window-level `unhandledrejection`** catches the same family for non-React.lazy dynamic imports.
3. **Recovery shows a calm "Updating to latest version…" spinner** instead of the red "crashed" card.

### Orphan-overlay sweeper

`App.tsx` runs `useOrphanOverlayCleanup()` on every navigation. After React has had a chance to mount/unmount, scans direct children of `<body>` for elements that match the modal-backdrop signature (`position: fixed`, `inset: 0`, class includes `fixed inset-0 bg-black/*` or `backdrop-blur-*`) AND have no React fiber pointer in any descendant — i.e., orphans React already discarded but whose portal DOM survived. Those get removed. Toaster, area card menus, and any live React portal are untouched.

### Isochrone failure UX

ORS hard-caps drive-time at 60 min. `IsochroneController` validates `time ≤ 60` up-front (returns 422 with a friendly hint) and translates the common ORS error codes (`3004 range out of range`, `2009/2010 location off road`, `6001 rate-limit`) into user-readable messages.

### Territory + MCLP — proper status codes

- "Not enough census coverage" → 422 (was 500)
- "Too many candidates" → 422 (was unspec'd)

### Activation funnel + plan scaffolding never block free tier

Per durable directive: no restrictions are active. The scaffolding is in place so cells flip later.

---

## Infrastructure & deploy

### Local dev
- `frontend/`: `npm run dev` → Vite on http://localhost:5173 with `/api/*` proxied to `http://localhost:8080`
- Backend dev: `php -S localhost:8080 -t public public/index.php` (or use the docker-compose stack)
- No service worker in dev (skipped by `import.meta.env.PROD` check)

### Production droplet (`143.244.144.7`)
- `/var/www/smappen` — code (`git pull` to deploy)
- `/var/www/smappen/.env` — secrets (gitignored)
- `/var/www/smappen/storage/exports`, `/storage/uploads`, `/storage/logs`
- `/var/www/smappen/backups/` — `mysqldump` snapshots (the pre-015 backup is at `smappen-pre015-20260524T1838.sql.gz`, 600MB gzipped)

### Apache vhost
- `DocumentRoot /var/www/smappen/public`
- TLS via certbot, auto-renewed
- `RewriteRule ^/api → /index.php`
- `RewriteRule !-f !-d → /app/index.html` (SPA fallback)
- `SetHandler proxy:unix:/var/run/php/php8.3-fpm.sock|fcgi://localhost`

### Deploy script alternatives
- `scripts/deploy.sh` — `git pull && cd frontend && npm run build`
- `scripts/droplet-deploy.sh` — variant for SSH-driven deploy
- Manual: `ssh root@droplet 'cd /var/www/smappen && git pull && cd frontend && npm run build'`

### Cron (root)
- Hourly: `php /var/www/smappen/scripts/cleanup-cron.php`
- Daily: `php /var/www/smappen/scripts/run-competitor-scans.php`
- Daily: `mysqldump … > /var/www/smappen/backups/smappen-$(date +%F).sql.gz`

### Failover plan (`docs/failover.md`)
Documents the manual fallback path: secondary droplet with identical Apache + PHP-FPM config, `mysqldump` restore, DNS cutover. Operational; not auto-failover.

### Logs
- PHP: `/var/www/smappen/storage/logs/php-error.log`
- Apache: `/var/log/apache2/smappen-{access,error}.log`
- FPM: `/var/log/php8.3-fpm.log`
- Monolog: optional `/var/www/smappen/storage/logs/app.log` (config-gated)

### PHP-FPM tuning (current — bumped in v4)
```
pm = dynamic
pm.max_children      = 20   (was 5)
pm.start_servers     = 4    (was 2)
pm.min_spare_servers = 2    (was 1)
pm.max_spare_servers = 8    (was 3)
```
Backup of prior config at `/etc/php/8.3/fpm/pool.d/www.conf.bak.20260524190946`.

### Apache `mod_deflate`
Enabled for `text/*`, `application/javascript`, `application/json`. Cuts heatmap JSON 12MB → 2MB.

---

## Testing

### PHPUnit (21 tests, 170 assertions, ~30ms)
- `tests/Services/GeoUtilsTest.php` — bbox, point-in-polygon, haversine
- `tests/Services/AnalogServiceTest.php` — similarity scoring

### Vitest (frontend)
- One smoke test on store-rehydration; expansion planned but not in-scope for this audit cycle

### Manual smoke tests (verified in this audit cycle)
- `/api/health` returns `version: a21b00a` post-deploy
- Migration 016 ran clean (stripe_webhook_events table exists)
- All new routes return 401 without auth (`/api/onboarding/state`, `/api/report-templates`, `/api/alerts`, `/api/projects/{id}/custom-layers`, `/api/projects/{id}/embeds`)
- Security headers present on `/api/*` (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, CSP)
- Frontend `tsc -b` clean
- Vite build clean (2615 modules, ~370KB main gzipped 106KB)
- Spatial axis-order: `ST_GeomFromText("POINT(-122 37)", 4326)` correctly throws — confirms the axis enforcement is active
- Spatial axis-order: `ST_GeomFromText("POINT(37 -122)", 4326)` returns x=37, y=-122 — confirms (lat lng) is correct
- PHP-FPM pool reports `pm.max_children = 20`
- Service worker `/app/sw.js` serves the kill-switch (8 lines, immediately unregisters)

---

## Scaffolded but not yet wired

| Feature | Status | What's missing |
|---|---|---|
| Canadian demographics (StatCan) | Service + schema in place | Need to run `scripts/import-statcan-da.php` (not yet written) to seed the 57K DAs |
| Time-series demographics (ACS history) | Service + schema in place | Need to run `scripts/ingest-demographics-history.php` (not yet written) for 2019-2022 backfill (2023 already in `census_demographics`) |
| Multi-location optimizer frontend wizard | Backend endpoint complete (`/optimize/locations`) | Frontend wizard UI not built; can be invoked via the existing `OptimizeTab` |
| Embed builder frontend UI | Backend CRUD complete | No frontend page yet for managing embeds (snippet generation works via the API) |
| Custom data layers frontend UI | Backend CRUD complete | No frontend page yet for managing layers (layer creation works via the API + existing import wizard) |
| Alerts frontend UI | Backend CRUD complete | No frontend page yet for managing alert rules (test-fire works via the API) |
| Weekly email digest | `/api/alerts/digest/recent` endpoint exists | No cron yet that pulls + emails the digest |
| Sample project seed | `projects.is_sample` column exists | Need to manually create a "Demo: Downtown Chicago" project on the droplet with `is_sample=1` so `cloneSample()` has source data |
| Activation metric `returned_in_week_2` + `health_score` | Columns exist | Computation logic not built; columns are NULL/0 currently |

---

## Known issues / open punch list

### Resolved this audit cycle (a21b00a)

- ✅ Spatial axis-order across 6 files (was silently failing)
- ✅ Stripe webhook idempotency (was duplicate-deliverable)
- ✅ Stuck-job sweeper (was leaving jobs in `running` forever)
- ✅ External curl `CURLOPT_CONNECTTIMEOUT` × 10
- ✅ `AlertsController::create` cross-org `area_id`
- ✅ `DashboardPage` activity unmount setState
- ✅ `SaveStatus` listener cleanup parity

### Resolved in earlier 2026-05-24 batches

- ✅ Heatmap memory exhaustion (caps + precision)
- ✅ PHP-FPM worker starvation from SSE long-poll
- ✅ Service-worker stale-cache loop
- ✅ Stale-chunk auto-recovery
- ✅ Orphan-overlay backdrop sweep
- ✅ Isochrone 60-min cap + friendly ORS error
- ✅ Places 20-result tile-out / pagination
- ✅ Territory 500 → 422 on no-coverage
- ✅ MCLP 504 → spatial-index pre-filter
- ✅ Dashboard "grayed out" (bg-white not literal gray)

### Open

- **AnalogController generic 500** — agent flagged at line 84 (catch-all `Response::error('Analog search failed', 500)`); not reproduced post-axis-order fix. Watch for it in the next round.
- **No connection timeout on `WebhookDispatcher`** (only `CURLOPT_TIMEOUT => 10`) — set but pre-existing; `WebhookDispatcher` already had it per the audit
- **OAuth state token in PHP session** — uses PHP's default session handler; if the droplet ever switches to multiple FPM hosts or load-balances, the state token won't be portable. Move to encrypted cookie or signed state-with-nonce.
- **CRM push doesn't refresh expired tokens** — `expires_at` is stored but `pushSalesforce/pushHubspot` don't check it before use. Long-lived integrations will start 401-ing when the access token expires.
- **No connection pool / persistent PDO** — every request opens a new MySQL connection. With 20 workers this is fine, but if traffic 10× it'll add up.
- **No background scheduler service** — operator must add cron entries manually; no `php scripts/schedule.php` self-tend.
- **Sample project for `cloneSample`** — `projects.is_sample = 1` column exists but no project marked yet; the demo button currently 404s on the droplet
- **Webhook delivery retries** — `WebhookDispatcher::send` is single-shot; no exponential-backoff retry on 5xx from subscriber endpoints
- **Embed view counter not incrementing** — `embeds.view_count` exists but the public render path doesn't bump it on each load

---

## Bug-fix history (audit cycles)

Each round below is a single deploy that bundled fixes from a focused review.

| Cycle / commit | Theme | Fixes shipped |
|---|---|---|
| `a21b00a` (this audit) | Bug-audit fix batch | 6 spatial axis-order, ACL on AlertsController, 10 curl connect-timeouts, Stripe webhook idempotency, stuck-job sweeper, DashboardPage cancel guard, SaveStatus listener cleanup |
| `9d02e56` | Stale chunk auto-recovery | ErrorBoundary catches React.lazy chunk failures + purges caches + reloads (was only caught by window.unhandledrejection which Suspense swallows) |
| `bf56ef2` | Isochrone UX | 60-min cap with friendly 422; ORS error code translation; AreaCreator slider max 120 → 60 |
| `200d194` | Territory + MCLP status codes | Coverage errors → 422 (was 500); MCLP spatial-index pre-filter; candidate cap 1500 → 500 |
| `f103355` | SW kill-switch + orphan overlay sweeper | sw.js now self-uninstalls; main.tsx unregisters on every load; App.tsx sweeps orphan modal portals on navigation |
| `b98b31d` | Stop chunk-recovery reload loop | sessionStorage guard now permanent for the tab; also unregisters SW alongside cache purge |
| `2413eb1` | PHP-FPM pool + SSE worker holds | pool 5 → 20; SSE short-circuits with `retry: 30000` on empty peer list; frontend skips ping when peers.length === 0 |
| `0c732d9` | Places > 20 results | Tile saturated nearby into 5 sub-calls; paginate text to 60; per-tile cost tracking |
| `5c51806` | Initial stale-chunk recovery | window.unhandledrejection handler purges caches + reloads (later supplemented by `9d02e56` for React.lazy) |
| `f12d91c` | Heatmap memory | Tract cap 10K → 3K; precision per zoom (state p=2, county p=3); row buffer freed before json_encode |
| `d47607e` | Growth + onboarding batch | 14 new files including OnboardingController, FirstRunWizard, HomePage, BlogPage, DashboardPage, ProjectGalleryPage, AlertsController, EmbedController, CustomLayerController, security headers, ai-scoring v2, report templates, presence cursors, auto-save wrapper |
| `c392e95` | Knife-cut territory boundaries | TerritoryGenerator does pairwise ST_Union directly during generation; MultiPolygon support in geoJsonToGooglePaths |
| `d05d487` | Bottom-left heatmap toggle removed + loading polish | Global .spinner, .page-loading, .progress-bar |
| `0066fb8` | GEOMCOLLECTION crash fix | `ST_GeometryType IN ('Polygon','MultiPolygon')` guard around ST_Area(ST_Intersection(…)) — applied in CensusService + earlier in Reach/Cannibalization |
| `3b61f19` | Cost tracking 2× inflation | Middleware logs once with cost; controllers no longer double-log |
| `c4ddbf4` | 50+ small fixes / UX polish | Undo system, favorites, EmptyState component, cost toast batching, ShortcutsModal, DataFreshnessFooter, KML per-color styles, Vite chunk split |
| `72c25ed` | 30+ smaller bug-fix audit | Auth bulk-revoke `tokens_invalid_before`, AiScoringController cache table, job-worker FOR UPDATE SKIP LOCKED, ProjectController share rotation only on transition, useShortcuts Cmd+S preventDefault, ImportController CSV off-by-one |
| `fbaaf2c` | 50-item action plan | ErrorBoundary per panel, CORS hardening, auth flows, JWT revocation, API keys, role gates, lazy advanced tabs, GitHub Actions CI, dark mode, Docker, OpenAPI |

---

*End of v4 audit. The next cycle should address the open items in the punch list, write `import-statcan-da.php` + `ingest-demographics-history.php`, build the alerts/embeds/custom-layers frontend pages, and seed the sample project on the droplet so `cloneSample()` has source data.*
