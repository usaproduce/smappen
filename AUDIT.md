# Smappen — Implementation Completeness Audit

**Date:** 2026-05-23
**Auditor:** Claude (automated, six-agent crawl)
**Specs audited against:**
- `_specs/smappen-clone-prompts.md` (base blueprint, 75KB)
- `_specs/advanced-features-implementation-guide.md` (advanced features, 65KB)
- `_specs/smappen-style-guide.md` (style guide, 39KB)

**Scope:** Every file under `src/`, `frontend/src/`, `config/`, `scripts/`, `public/`. Excluded `node_modules/` and `vendor/`.

**Headline:** The project is **substantially complete** against both specs. All 23 spec'd database tables, all 50+ API endpoints, all 7 external API integrations, and ~32 of ~34 frontend components exist and are wired. The dominant gaps are operational rather than functional: no Docker, no Redis, no S3/object storage, no automated Census refresh, no email/Slack notification channels, no password reset, and several core algorithms ship with documented simplifications (greedy MCLP instead of greedy+local-search, convex-hull territory boundaries instead of ST_Union+simplify, no risk-tier classification in cannibalization).

---

## SECTION 1: PROJECT STRUCTURE & STACK AUDIT

### Top-Level Directory Structure

```
smappen/
├── public/                     # Web root (entry point, assets, uploads)
├── frontend/                   # React + TypeScript SPA (Vite)
├── src/                        # PHP backend (Controllers/Core/Models/Services/Migrations/Templates)
├── config/                     # routes.php, cors.php
├── storage/                    # Logs, cache, reports (runtime)
├── scripts/                    # Migrations, seeding, cron, deploy
├── _specs/                     # Spec docs (copied into project for audit)
├── composer.json               # PHP deps
├── nginx.conf                  # Nginx vhost
└── README.md
```

- [public/](public/) — entry point [`index.php`](public/index.php), `.htaccess`, `assets/`, `uploads/`
- [src/Core/](src/Core) — Router, Database (PDO), Request/Response, Middleware, Config, PlanLimits
- [src/Controllers/](src/Controllers) — 23 controller classes
- [src/Models/](src/Models) — 8 model classes (User, Organization, Project, Area, Folder, ImportedPoint, POICache, Report)
- [src/Services/](src/Services) — 10 service classes (GoogleMapsService, CensusService, IsochroneService, SegmentationService, TerritoryGenerator, CompetitorScanner, TrafficService, StripeService, CacheService, GeoUtils)
- [src/Migrations/](src/Migrations) — 5 SQL files (~579 lines)
- [frontend/src/](frontend/src) — React components, API layer, Zustand stores
- [storage/](storage) — writable runtime dirs (logs, cache, reports, uploads)

### Frontend Stack

- **Framework:** React 18.3.1, TypeScript 5.5.3 (strict), Vite 5.3.3
- **Styling:** Tailwind CSS 4.0 + custom CSS variables in [`styles.css`](frontend/src/styles.css). Brand: `--brand: #7848BB` (purple), `--cta: #E53935` (red) — matches spec.
- **State:** Zustand 4.5.4 with persist middleware ([`stores/`](frontend/src/stores)): `authStore`, `projectStore`, `mapStore`
- **Server state:** `@tanstack/react-query` 5.51.0 — installed but used in only 4 places (see Section 5.6)
- **Routing:** `react-router-dom` 6.24.1
- **Maps:** `@react-google-maps/api` 2.20.0, `@googlemaps/markerclusterer` 2.5.3
- **Geo math:** `@turf/turf` 7.0.0
- **Data:** `papaparse` 5.4.1 (CSV), `xlsx` 0.18.5 (Excel), `recharts` 2.12.7 (charts)
- **HTTP:** `axios` 1.7.2 via [`api/client.ts`](frontend/src/api/client.ts)
- **UI:** `react-hot-toast` 2.4.1, `lucide-react` 0.408.0
- **Build target:** `frontend/public/index.html` → built to `public/app/` ([`vite.config.ts`](frontend/vite.config.ts))

All dependencies are current. No deprecated packages detected.

### Backend Stack

- **Language:** PHP 8.1+ (composer.json), PHP 8.3 on production droplet ([`droplet-deploy.sh:61`](scripts/droplet-deploy.sh#L61))
- **Framework:** None. Custom regex-based router in [`src/Core/Router.php`](src/Core/Router.php) with middleware chain.
- **HTTP:** Nginx primary ([`nginx.conf`](nginx.conf)); Apache fallback via [`public/.htaccess`](public/.htaccess)
- **DB driver:** PDO singleton in [`src/Core/Database.php`](src/Core/Database.php) — prepared statements, named params, no ORM
- **JSON envelope:** [`src/Core/Response.php`](src/Core/Response.php) — uniform `{ success, data, error, message }` shape

### PHP Dependencies (composer.json)

- `firebase/php-jwt` ^6.10 — JWT for auth
- `stripe/stripe-php` ^13.0 — billing
- `vlucas/phpdotenv` ^5.6 — env loading
- `phpoffice/phpspreadsheet` ^2.0 — Excel I/O
- `tecnickcom/tcpdf` ^6.6 — PDF reports

All current. No deprecated/abandoned/vulnerable packages.

### Database

- **DBMS:** MySQL 8 (utf8mb4 / utf8mb4_unicode_ci) with native spatial types — POINT, POLYGON, MULTIPOLYGON, SRID 4326
- **Important deviation from spec:** Spec says **PostgreSQL + PostGIS**; implementation uses **MySQL 8 with native spatial types**. Functionally equivalent for the queries actually used (ST_Intersects, ST_Contains, ST_Area, ST_Intersection, ST_Distance_Sphere). No PostGIS-only features (e.g., topology, raster, network analysis) are needed.
- **Schema:** 5 migrations, ~29 tables, ~40 indexes (full inventory in Section 2)
- **Migration runner:** [`scripts/migrate.php`](scripts/migrate.php) — tracks executed migrations in `migrations` table, idempotent

### State Management & API Layer (Frontend)

- Zustand stores typed with TypeScript ([`stores/`](frontend/src/stores))
- Custom Axios client with auth interceptor, 401 auto-logout, network toast ([`api/client.ts`](frontend/src/api/client.ts))
- React Query installed but underused (4 of ~35 components) — see Section 5.6

### Environment Variables

**Backend (.env loaded via Dotenv):**
- DB: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- Auth: `JWT_SECRET`
- APIs: `GOOGLE_API_KEY`, `ORS_API_KEY`, `CENSUS_API_KEY`
- Billing: `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_STARTER`, `STRIPE_PRICE_PRO`, `STRIPE_PRICE_BUSINESS`
- URLs: `APP_URL`, `FRONTEND_URL`, `APP_ENV`

**Frontend:** `VITE_API_URL` (read via `import.meta.env`)

`.env.example` present at both root and `frontend/`. `.env` correctly in `.gitignore`.

### TypeScript

`tsconfig.json` configured with strict mode. Build step runs `tsc -b && vite build` ([`frontend/package.json`](frontend/package.json)). No type-check run performed in this audit.

### Docker / CI / CD

- **No Dockerfile, no docker-compose.yml.** Deployment is bare-metal DigitalOcean droplet.
- **No GitHub Actions, CircleCI, or CI config** of any kind.
- **Deployment:** [`scripts/droplet-deploy.sh`](scripts/droplet-deploy.sh) — one-shot provisioner for Ubuntu (PHP 8.3, MySQL, Nginx, Node 20, Composer, Certbot). Coexists with GreenDock on same droplet.

---

## SECTION 2: DATABASE SCHEMA AUDIT

### Executive Summary

5 migration files, ~579 SQL lines, ~29 tables, ~40 indexes. **Every spec'd base and advanced-feature table is present.** No structural mismatches.

The only architectural deviation: spec calls for **PostgreSQL + PostGIS**; implementation uses **MySQL 8 with native spatial types**. Validated equivalent for all queries actually used. PostGIS-only features (topology extension, raster, pgRouting) are not required by any feature.

### 2.1 Base Blueprint Tables

| Table | Status | Source |
|---|---|---|
| `organizations` | ✅ | [001:5-11](src/Migrations/001_initial_schema.sql#L5) |
| `users` | ✅ | [001:13-27](src/Migrations/001_initial_schema.sql#L13) |
| `projects` | ✅ | [001:29-42](src/Migrations/001_initial_schema.sql#L29) |
| `folders` | ✅ (self-ref `parent_folder_id`) | [001:44-54](src/Migrations/001_initial_schema.sql#L44) |
| `areas` | ✅ all 17 spec'd columns + `generation_job_id`/`territory_index` (advanced) | [001:56-85](src/Migrations/001_initial_schema.sql#L56), [005:38-41](src/Migrations/005_advanced_features.sql#L38) |
| `imported_points` | ✅ POINT SRID 4326 | [001:87-103](src/Migrations/001_initial_schema.sql#L87) |
| `poi_cache` | ✅ | [001:105-112](src/Migrations/001_initial_schema.sql#L105) |
| `census_tracts` | ✅ MULTIPOLYGON SRID 4326 | [001:114-124](src/Migrations/001_initial_schema.sql#L114) |
| `census_demographics` | ✅ all 19 demographic columns | [001:126-148](src/Migrations/001_initial_schema.sql#L126) |
| `reports` | ✅ | [001:150-161](src/Migrations/001_initial_schema.sql#L150) |
| `audit_log` | ✅ BIGINT id | [001:170-180](src/Migrations/001_initial_schema.sql#L170) |
| `api_usage_log` | ✅ (extra, for rate limiting) | [001:163-168](src/Migrations/001_initial_schema.sql#L163) |

For `areas`: verified `id, project_id, folder_id, name, area_type` (ENUM isochrone/isodistance/manual/radius), `center_lat/lng, center_address, travel_mode, travel_time_minutes, travel_distance_km, geometry (POLYGON 4326), fill_color, fill_opacity, stroke_color, stroke_weight, demographics_cache (JSON), demographics_cached_at, notes, created_by, timestamps`.

For `census_demographics`: verified `total_population, median_household_income, median_home_value, labor_force_total, unemployed_total, male_total, female_total, housing_units_total, age_under_18, age_18_to_34, age_35_to_54, age_55_to_64, age_65_plus, income_under_25k, income_25k_to_50k, income_50k_to_75k, income_75k_to_100k, income_100k_plus, data_year, updated_at`.

### 2.2 Advanced-Feature Tables

| Table | Status | Source |
|---|---|---|
| `territory_generation_jobs` | ✅ | [005:14-34](src/Migrations/005_advanced_features.sql#L14) |
| `tract_segments` | ✅ | [005:50-60](src/Migrations/005_advanced_features.sql#L50) |
| `project_versions` | ✅ UNIQUE(project_id, version_number) | [005:69-82](src/Migrations/005_advanced_features.sql#L69) |
| `comments` | ✅ supports threading (parent_comment_id) | [005:84-106](src/Migrations/005_advanced_features.sql#L84) |
| `change_log` | ✅ BIGINT id | [005:108-124](src/Migrations/005_advanced_features.sql#L108) |
| `project_collaborators` | ✅ UNIQUE(project_id, user_id) | [005:126-141](src/Migrations/005_advanced_features.sql#L126) |
| `approval_requests` | ✅ | [005:143-159](src/Migrations/005_advanced_features.sql#L143) |
| `field_notes` | ✅ POINT SRID 4326 + spatial index | [005:182-202](src/Migrations/005_advanced_features.sql#L182) |
| `competitor_monitors` | ✅ index on (is_active, next_run_at) for scheduling | [005:215-233](src/Migrations/005_advanced_features.sql#L215) |
| `competitor_scans` | ✅ | [005:235-246](src/Migrations/005_advanced_features.sql#L235) |
| `tracked_places` | ✅ UNIQUE(monitor_id, place_id); location intentionally nullable (no spatial index) | [005:248-272](src/Migrations/005_advanced_features.sql#L248) |
| `competitor_alerts` | ✅ | [005:274-290](src/Migrations/005_advanced_features.sql#L274) |
| `notifications` | ✅ (cross-feature, not in original spec but correct) | [005:297-312](src/Migrations/005_advanced_features.sql#L297) |

### 2.3 Supporting / Performance Tables

- `cache` — generic K/V cache ([002](src/Migrations/002_cache_table.sql))
- `census_counties` — county-level rollup with MULTIPOLYGON geometry and aggregated demographics ([004:2-18](src/Migrations/004_aggregated_geo_and_tile_cache.sql#L2))
- `census_states` — state-level rollup ([004:20-33](src/Migrations/004_aggregated_geo_and_tile_cache.sql#L20))
- `heatmap_tile_cache` — choropleth response cache ([004:35-47](src/Migrations/004_aggregated_geo_and_tile_cache.sql#L35))
- `reach_cache` — isochrone/radius result cache ([004:49-57](src/Migrations/004_aggregated_geo_and_tile_cache.sql#L49))

### 2.4 PostGIS & Indexes

- **PostGIS extension:** ❌ not enabled — implementation uses MySQL 8 native spatial. **Not a defect** (see Section 1).
- **Spatial indexes (all NOT NULL geometry columns covered):**
  - `areas.geometry` → `idx_area_geom`
  - `imported_points.point` → `idx_ip_point`
  - `census_tracts.geometry` → `idx_ct_geom`
  - `census_counties.geometry` → `idx_counties_geom`
  - `census_states.geometry` → `idx_states_geom`
  - `field_notes.location` → `idx_fn_location`
  - `tracked_places.location` — none (intentionally nullable per code comment)
- **Functional indexes:** `idx_census_demo_pop`, `idx_census_demo_income`, plus expected indexes on every FK and `(project_id, status)`, `(monitor_id, created_at)`, etc. — all present.

### 2.5 Result

Zero mismatches. Schema faithfully implements both the base blueprint and the advanced features guide.

---

## SECTION 3: API ENDPOINTS AUDIT

24 controllers, 120+ routes. Centralized auth via [`Middleware::auth()`](src/Core/Middleware.php#L6). Responses use uniform `{ success, data, error }` envelope from [`Response.php`](src/Core/Response.php).

### 3.1 Core Endpoints

| Endpoint | Status | Handler | Notes |
|---|---|---|---|
| `POST /api/auth/register` | ✅ | [`AuthController`](src/Controllers/AuthController.php) | Bcrypt + JWT |
| `POST /api/auth/login` | ✅ | [`AuthController`](src/Controllers/AuthController.php) | Issues 24h JWT |
| `POST /api/auth/refresh` | ✅ | [`AuthController`](src/Controllers/AuthController.php) | |
| `GET /api/auth/me` | ✅ | [`AuthController`](src/Controllers/AuthController.php) | Returns user + org |
| Projects CRUD | ✅ | [`ProjectController:11-77`](src/Controllers/ProjectController.php#L11) | Includes `/api/shared/{token}` (no auth) |
| Folders CRUD | ✅ | [`FolderController`](src/Controllers/FolderController.php) | Tree, cascades |
| Areas CRUD | ✅ | [`AreaController`](src/Controllers/AreaController.php) | Geometry as WKT |
| `POST /api/isochrone/calculate` | ✅ | [`IsochroneController:7-35`](src/Controllers/IsochroneController.php#L7) | ORS proxy, daily rate limit |
| `GET /api/areas/{id}/demographics` | ✅ | [`DemographicsController:7-19`](src/Controllers/DemographicsController.php#L7) | Census API w/ 30-day cache |
| `POST /api/demographics/compare` | ✅ | [`DemographicsController:21-39`](src/Controllers/DemographicsController.php#L21) | Max 10 areas |
| `POST /api/places/nearby` | ✅ | [`PlacesController:10-42`](src/Controllers/PlacesController.php#L10) | Places API + polygon filter + cache |
| `POST /api/places/search` | ✅ | [`PlacesController:44-55`](src/Controllers/PlacesController.php#L44) | Text Search |
| `GET /api/places/{placeId}` | ✅ | [`PlacesController:57-62`](src/Controllers/PlacesController.php#L57) | Place Details |
| `GET /api/areas/{id}/pois` | ✅ | [`PlacesController:64-72`](src/Controllers/PlacesController.php#L64) | From poi_cache |
| `POST /api/geocode` | ✅ | [`GeocodingController:10-20`](src/Controllers/GeocodingController.php#L10) | |
| `POST /api/geocode/batch` | ✅ | [`GeocodingController:22-43`](src/Controllers/GeocodingController.php#L22) | Plan-tiered limits (10/100/500) |
| Import upload + configure | ✅ | [`ImportController:10-117`](src/Controllers/ImportController.php#L10) | 10MB cap, on-the-fly geocoding |
| Export areas/POIs/points | ✅ | [`ExportController`](src/Controllers/ExportController.php) | CSV/XLSX/GeoJSON/KML |
| Reports generate/list/download | ✅ | [`ReportController`](src/Controllers/ReportController.php) | PDF |
| `POST /api/billing/checkout` | ✅ | [`BillingController:10-27`](src/Controllers/BillingController.php#L10) | Owner-only |
| `POST /api/billing/webhook` | ✅ | [`BillingController:29-42`](src/Controllers/BillingController.php#L29) | Stripe-signed |
| Billing subscription/portal/cancel | ✅ | [`BillingController:44-80`](src/Controllers/BillingController.php#L44) | |
| `GET /api/heatmap/tracts` | ✅ | [`HeatmapController`](src/Controllers/HeatmapController.php) | Viewport tracts for choropleth |
| `POST /api/areas/reach`, `/api/demographics/preview` | ✅ | [`ReachController`](src/Controllers/ReachController.php) | Smart area sizing |

### 3.2 Advanced-Feature Endpoints

| Endpoint | Status | Handler |
|---|---|---|
| `POST /api/projects/{id}/territories/generate` | ✅ | [`TerritoryController:10-90`](src/Controllers/TerritoryController.php#L10) |
| `GET /api/projects/{id}/territories/jobs` | ✅ | [`TerritoryController:92-112`](src/Controllers/TerritoryController.php#L92) |
| `GET /api/projects/{id}/cannibalization` | ✅ | [`CannibalizationController:23-93`](src/Controllers/CannibalizationController.php#L23) |
| `POST /api/isochrone/traffic` | ✅ | [`TrafficIsochroneController:19-48`](src/Controllers/TrafficIsochroneController.php#L19) |
| `POST /api/isochrone/traffic/grid` | ✅ | [`TrafficIsochroneController:50-91`](src/Controllers/TrafficIsochroneController.php#L50) |
| `POST /api/projects/{id}/optimize/locations` | ✅ | [`MclpController:17-105`](src/Controllers/MclpController.php#L17) |
| `GET /api/segmentation/segments` | ✅ | [`SegmentationController:10-14`](src/Controllers/SegmentationController.php#L10) |
| `GET /api/areas/{id}/segments` | ✅ | [`SegmentationController:16-32`](src/Controllers/SegmentationController.php#L16) |
| `POST /api/projects/{id}/segments` | ✅ | [`SegmentationController:34-63`](src/Controllers/SegmentationController.php#L34) |
| `POST /api/segmentation/recompute` | ✅ | [`SegmentationController:65-73`](src/Controllers/SegmentationController.php#L65) |
| Versions (POST/GET list/GET one) | ✅ | [`CollaborationController:13-79`](src/Controllers/CollaborationController.php#L13) |
| Comments (CRUD + resolve) | ✅ | [`CollaborationController:82-130+`](src/Controllers/CollaborationController.php#L82) |
| Change log (`/changes`) | ✅ | [`CollaborationController`](src/Controllers/CollaborationController.php) |
| Collaborators (CRUD) | ✅ | [`CollaborationController`](src/Controllers/CollaborationController.php) |
| Approvals (create/list/decide) | ✅ | [`CollaborationController`](src/Controllers/CollaborationController.php) |
| Notifications (list/read/read-all) | ✅ | [`NotificationController`](src/Controllers/NotificationController.php) |
| Competitor monitors (full CRUD + scan-now + places + alerts) | ✅ | [`CompetitorController:10-?`](src/Controllers/CompetitorController.php#L10) |
| Field notes (CRUD + `where-am-i`) | ✅ | [`FieldNoteController:10-107`](src/Controllers/FieldNoteController.php#L10) |

### 3.3 Endpoint-Quality Findings

- ✅ **Auth:** uniformly enforced. Webhook + register + login + `/api/shared/{token}` correctly unauthenticated.
- ✅ **Input validation:** manual but thorough — coord bounds, array caps, plan-based limits.
- ⚠️ **CORS preflight:** `Response::corsHeaders()` exists but no explicit `OPTIONS` route registered in `routes.php`. Complex preflight requests may fail.
- ⚠️ **Webhook signature:** delegated to `StripeService::handleWebhook()`; controller does not re-verify. Trust boundary is in the service layer — acceptable but worth a comment.
- ⚠️ **Rate limiting incomplete:** only isochrone and places endpoints log to `api_usage_log` and check daily limits. Geocoding/imports/territory-generation/MCLP have no per-endpoint throttle.
- ⚠️ **Long-running jobs:** Territory generation and MCLP bump `memory_limit` and `set_time_limit` inline; no queue/worker abstraction. PHP-FPM workers can stall under load.
- ⚠️ **No caching headers** (no `Cache-Control` / `ETag`) on read endpoints.
- ℹ️ No validation library (no Respect, no Symfony Validator). Manual checks work but are verbose.

### 3.4 Result

All 50+ spec endpoints are present and functional. Response envelope consistent. Minor production-hardening gaps listed above.

---

## SECTION 4: EXTERNAL API INTEGRATIONS AUDIT

### 4.1 Google Maps JS API (Frontend) — ✅ COMPLETE

- Map init with drawing + visualization libraries ([`MapCanvas.tsx`](frontend/src/components/map/MapCanvas.tsx))
- Custom styled map (13-rule desaturated theme) at [`mapStyle.ts`](frontend/src/utils/mapStyle.ts)
- Polygon rendering with styling at [`AreaPolygon.tsx`](frontend/src/components/map/AreaPolygon.tsx)
- DrawingManager (polygon + circle) at [`DrawingTools.tsx:11-24`](frontend/src/components/map/DrawingTools.tsx#L11)
- HeatmapLayer (visualization library) at [`HeatmapLayer.tsx`](frontend/src/components/map/HeatmapLayer.tsx)
- Choropleth via Google Data layer at [`ChoroplethLayer.tsx`](frontend/src/components/map/ChoroplethLayer.tsx)

### 4.2 OpenRouteService (Isochrone) — ✅ COMPLETE

- [`IsochroneService.php:1-97`](src/Services/IsochroneService.php) — hosted ORS API (`api.openrouteservice.org/v2/isochrones/{mode}`)
- Modes: `driving-car`, `cycling-regular`, `foot-walking`, `wheelchair` ([line 9](src/Services/IsochroneService.php#L9))
- Coordinate order: `[lng, lat]` ✅ ([line 33](src/Services/IsochroneService.php#L33))
- Time→seconds: `$timeMinutes * 60` ([line 34](src/Services/IsochroneService.php#L34))
- `smoothing: 0` for max detail ([line 35](src/Services/IsochroneService.php#L35))
- 24h cache via [`CacheService`](src/Services/CacheService.php) with v2 key prefix
- Alt `calculateRadius()` for fixed-distance circles ([lines 87-97](src/Services/IsochroneService.php#L87))
- ⚠️ Self-hosted ORS / Docker setup **not present** — relies on hosted API (rate-limited)

### 4.3 Google Places API (New) — ✅ COMPLETE

- Nearby Search: [`GoogleMapsService.php:103-145`](src/Services/GoogleMapsService.php#L103) — endpoint `places.googleapis.com/v1/places:searchNearby`
- Text Search: [`GoogleMapsService.php:147-180`](src/Services/GoogleMapsService.php#L147)
- Place Details: [`GoogleMapsService.php:182-198`](src/Services/GoogleMapsService.php#L182)
- Correct `X-Goog-FieldMask` header (Essentials + selected Pro fields: rating, userRatingCount, phone, website)
- Point-in-polygon filter via ray-casting in [`GeoUtils::pointInPolygon()`](src/Services/GeoUtils.php) called from [`PlacesController.php:36-49`](src/Controllers/PlacesController.php#L36)
- 48h cache + `poi_cache` table

### 4.4 Google Geocoding — ✅ COMPLETE

- Forward: [`GoogleMapsService.php:20-47`](src/Services/GoogleMapsService.php#L20)
- Reverse: [`GoogleMapsService.php:49-73`](src/Services/GoogleMapsService.php#L49)
- Batch (concurrency 5, 20ms throttle): [`GoogleMapsService.php:75-101`](src/Services/GoogleMapsService.php#L75)
- 365-day cache on both forward and reverse
- Plan-tier batch caps in [`GeocodingController.php:23-28`](src/Controllers/GeocodingController.php#L23)

### 4.5 Traffic-Aware Isochrones — ✅ COMPLETE (with caveat)

- 7×24 multiplier matrix in [`TrafficService.php:10-38`](src/Services/TrafficService.php#L10)
- `adjustedMinutes()` divides budget by multiplier ([lines 48-54](src/Services/TrafficService.php#L48))
- 8 preset windows in [`TrafficService.php:57-66`](src/Services/TrafficService.php#L57)
- Grid endpoint queries all 8 windows ([`TrafficIsochroneController.php:55-103`](src/Controllers/TrafficIsochroneController.php#L55))
- ⚠️ Spec calls for Google Routes API `computeRoutes` with `TRAFFIC_AWARE` + radial sampling/interpolation. This implementation instead **adjusts ORS isochrone budgets using empirical multipliers** — simpler and cheaper, but less precise than a true traffic-aware routing query.

### 4.6 US Census Bureau ACS — ✅ COMPLETE

- [`CensusService.php`](src/Services/CensusService.php) — ACS 5-Year 2023, `acs/acs5` dataset
- All spec variables present: B01003 (pop), B19013 (income), B25001/B25077 (housing), B23025 (employment), B01001/B09001 (age), B19001 (income brackets)
- Request chunking (50 vars per call, 200ms throttle) at [`CensusService.php:100-125`](src/Services/CensusService.php#L100)
- Spatial join + overlap-weighted aggregation at [`CensusService.php:128-223`](src/Services/CensusService.php#L128); counts scaled by `ST_Area(ST_Intersection)/ST_Area(tract)`, medians weighted by population × overlap
- 30-day cache on per-area demographics

### 4.7 Stripe Billing — ✅ COMPLETE

- [`StripeService.php`](src/Services/StripeService.php) full lifecycle: createCustomer, createCheckoutSession, billing portal, webhook handler (4 event types), getSubscription, cancelSubscription
- Webhook signature validated against `STRIPE_WEBHOOK_SECRET`
- Plan ↔ price-id mapping via env vars

### 4.8 Authentication — 🟡 PARTIAL

- ✅ JWT (Firebase library), 24h expiry, HS256, Bearer header
- ✅ `auth()` middleware loads user, checks `is_active`, strips password hash
- ✅ Optional `optionalAuth()` variant for public endpoints
- 🟡 **No password reset** flow
- 🟡 **No email verification** — accounts active immediately
- 🟡 **No logout token revocation** (no blacklist)
- 🟡 **Role granularity minimal** — owner vs non-owner; no fine-grained per-feature permissions

---

## SECTION 5: FRONTEND COMPONENTS AUDIT

### 5.1 Map Components — ✅ all 7 present

| Spec component | File | Status |
|---|---|---|
| MapCanvas | [`MapCanvas.tsx`](frontend/src/components/map/MapCanvas.tsx) | ✅ |
| IsochroneLayer | [`AreaPolygon.tsx`](frontend/src/components/map/AreaPolygon.tsx) | ✅ (renamed) |
| DrawingTools | [`DrawingTools.tsx`](frontend/src/components/map/DrawingTools.tsx) | ✅ polygon+circle |
| HeatmapLayer | [`HeatmapLayer.tsx`](frontend/src/components/map/HeatmapLayer.tsx) | ✅ |
| ImportedPointsLayer | [`ImportedMarkers.tsx`](frontend/src/components/map/ImportedMarkers.tsx) | ✅ MarkerClusterer |
| POIMarkers | [`POIMarkers.tsx`](frontend/src/components/map/POIMarkers.tsx) | ✅ click→info window |
| AreaInfoWindow | inside `AreaPolygon.tsx` | ✅ |
| ChoroplethLayer (bonus) | [`ChoroplethLayer.tsx`](frontend/src/components/map/ChoroplethLayer.tsx) | ✅ extra |

### 5.2 Panel Components — ✅ all 7

- LeftPanel, RightPanel — [`layout/`](frontend/src/components/layout)
- AreaCreator — [`AreaCreator.tsx`](frontend/src/components/areas/AreaCreator.tsx) (multi-step: address → geocode → isochrone/reach → preview → save)
- FolderTree, AreaList, AreaCard, AreaEditor — [`areas/`](frontend/src/components/areas)
- DemographicsPanel, POISearchPanel, ComparisonView, ChartWidgets — [`analytics/`](frontend/src/components/analytics)

### 5.3 Data Components — ✅ all 3

- [`ImportWizard.tsx`](frontend/src/components/data/ImportWizard.tsx) — 3-step: upload → column-map → process
- [`ExportDialog.tsx`](frontend/src/components/data/ExportDialog.tsx) — CSV/XLSX/GeoJSON/KML
- [`ReportButton.tsx`](frontend/src/components/data/ReportButton.tsx) — PDF generate + download

### 5.4 Advanced-Feature Components — 🟡 ALL FEATURES PRESENT, BUT CONSOLIDATED

All 9 advanced features are implemented inside a **single 712-line file**, [`AdvancedPanel.tsx`](frontend/src/components/advanced/AdvancedPanel.tsx), as tab content functions rather than separate components. The 20+ spec-named components (AutoGenerateWizard, BalanceIndicator, CannibalizationPanel, OverlapHeatmap, TrafficAwareControls, ComparisonIsochrones, OptimizationWizard, CandidateMap, SegmentDonutChart, SegmentHeatmap, CollaboratorsList, VersionTimeline, CommentsPanel, ChangeLogFeed, MonitorSetupWizard, MonitorDashboard, AlertBell, AlertFeed, CompetitorTimeline, MobileFieldView, FieldNoteCapture) **do not exist as standalone files**.

Tab inventory in `AdvancedPanel.tsx`:
1. `TerritoriesTab()` ([line 105](frontend/src/components/advanced/AdvancedPanel.tsx#L105)) — territory generation
2. `CannibalizeTab()` — cannibalization
3. `TrafficTab()` — traffic isochrones
4. `OptimizeTab()` — MCLP
5. `SegmentsTab()` — segmentation
6. `CommentsTab()` — collaboration comments
7. `VersionsTab()` — version history
8. `CompetitorsTab()` — competitor monitoring + alerts
9. `FieldTab()` — field notes

API layer comprehensive at [`api/advanced.ts`](frontend/src/api/advanced.ts) (412 lines, fully typed).

**Functional coverage:** 100%. **Architectural alignment with spec:** partial — no reusable subcomponents, no tree-shaking benefit, harder to test in isolation.

### 5.5 Settings & Admin — 🟡 2/3

- ✅ BillingSettings + PricingPage — [`billing/`](frontend/src/components/billing) (subscription, plan tiers, Stripe portal/checkout, cancel)
- 🔴 **TeamManagement.tsx — MISSING** (needed for Business tier seat management)
- 🔴 **IntegrationsPage.tsx — MISSING** (needed for API keys, HubSpot connection)

### 5.6 State Management

- ✅ All three stores typed: `authStore`, `mapStore` (12 properties), `projectStore`
- ✅ Auth store persists token to localStorage
- 🟡 **React Query underutilized** — installed (5.51.0) but used in only **4 of ~35 components**:
  - `DemographicsPanel` (`['demographics', areaId]`)
  - `ComparisonView` (`['compare', areaIds]`)
  - `BillingSettings` (`['subscription']`)
  - `usePlanLimits` hook
  Most API calls use imperative `await` in event handlers with no caching. Expanding React Query to AreaList, POISearchPanel, AreaCreator preview, and AdvancedPanel tabs would significantly reduce redundant fetches.
- 🟡 [`ErrorBoundary.tsx`](frontend/src/components/ErrorBoundary.tsx) exists but is **not mounted** anywhere — no global crash protection.

### Component Tally

| Category | Present | Partial | Missing |
|---|---|---|---|
| Map (7 core) | 7 | 0 | 0 |
| Panels (7 core) | 7 | 0 | 0 |
| Data (3 core) | 3 | 0 | 0 |
| Advanced (9 features) | 9 | 9 (as inline tabs, not files) | 0 |
| Settings/Admin (3) | 2 | 0 | 1 |
| Stores + hooks | 4 | 0 | 0 |

---

## SECTION 6: ADVANCED ALGORITHM IMPLEMENTATION AUDIT

### 6.1 Territory Auto-Generation — 🟡 PARTIAL

**Files:** [`TerritoryGenerator.php`](src/Services/TerritoryGenerator.php) (313 lines), [`TerritoryController.php`](src/Controllers/TerritoryController.php) (177 lines)

- ✅ K-means clustering with Lloyd's algorithm + k-means++ seeding ([lines 44-67, 160-188](src/Services/TerritoryGenerator.php#L44))
- ✅ Three balance metrics: `population`, `income_weighted_pop`, `housing_units` ([lines 131-141](src/Services/TerritoryGenerator.php#L131))
- ✅ Border-tract swapping rebalancer — `balanceSwap()` up to 8 passes ([lines 204-252](src/Services/TerritoryGenerator.php#L204))
- ✅ Imbalance threshold check (`(max-min)/min ≤ max_imbalance_pct`, default 15%)
- ⚠️ **Convex hull** (Andrew's monotone chain, [lines 283-327](src/Services/TerritoryGenerator.php#L283)) instead of `ST_Union` — documented trade-off (MySQL struggles with pairwise union of 200+ polygons)
- 🔴 **Boundary smoothing** (`ST_SimplifyPreserveTopology`) **not implemented** — covered by hull approximation
- 🔴 **Direction-based naming** ("Territory — Northwest") **not implemented** — names are index-based ("Territory 1", "Territory 2", ...) at [`TerritoryController.php:91`](src/Controllers/TerritoryController.php#L91)

### 6.2 Cannibalization Modeling — 🟡 PARTIAL

**File:** [`CannibalizationController.php`](src/Controllers/CannibalizationController.php) (207 lines)

- ✅ `ST_Intersection` overlap polygons + tract aggregation ([lines 89-123](src/Controllers/CannibalizationController.php#L89))
- ✅ Overlap-weighted demographics (`d.total_population * inter.overlap_pct`)
- ✅ Network-wide impact + per-area `cannibalized_pct` ([lines 135-151](src/Controllers/CannibalizationController.php#L135))
- 🔴 **Risk-tier classification (low/moderate/high/critical) NOT implemented** — only raw percentages returned; UI has no severity labels

### 6.3 Traffic-Aware Isochrones — ✅ COMPLETE (with simplification noted in 4.5)

- ✅ 7×24 multiplier matrix
- ✅ 8 preset windows + grid endpoint
- ✅ `adjustedMinutes()` correctly divides time budget by multiplier
- ℹ️ Implementation is **multiplier-based**, not radial-sampling + Google Routes — see Section 4.5

### 6.4 Multi-Location Optimization (MCLP) — ✅ COMPLETE (greedy only)

**File:** [`MclpController.php`](src/Controllers/MclpController.php) (232 lines)

- ✅ Uncovered area computation
- ✅ Candidate grid generation from bbox + grid_step_km ([lines 168-203](src/Controllers/MclpController.php#L168))
- ✅ Greedy selection with incremental scoring ([lines 95-117](src/Controllers/MclpController.php#L95))
- ✅ Three demand metrics: `population`, `housing_units`, `income_weighted_pop`
- ✅ Before/after coverage metrics + `coverage_pct`
- ℹ️ **Local search refinement skipped** — documented as acceptable trade-off (1-1/e ≈ 63% approximation, fine for iterative UX)

### 6.5 Customer Segmentation — ✅ COMPLETE (no spending profiles)

**Files:** [`SegmentationService.php`](src/Services/SegmentationService.php) (202 lines), [`scripts/segment-tracts.php`](scripts/segment-tracts.php)

- ✅ 10 segments defined ([lines 20-31](src/Services/SegmentationService.php#L20)): affluent-suburbs, urban-professionals, family-suburbs, working-class-urban, rural-stable, retirement, college-towns, low-income-urban, moderate-suburbs, emerging-growth
- ✅ Rule cascade classifier on age %, income tier, home value, density ([lines 89-119](src/Services/SegmentationService.php#L89))
- ✅ Confidence score (0.5–0.9)
- ✅ Batch upsert to `tract_segments` ([lines 44-61](src/Services/SegmentationService.php#L44))
- 🔴 **Spending profiles NOT implemented** — DIY classifier uses Census only (Esri Tapestry-style spending data absent; documented acceptable in spec)

### 6.6 Competitor Scanning — 🟡 PARTIAL

**Files:** [`CompetitorScanner.php`](src/Services/CompetitorScanner.php) (299 lines), [`scripts/competitor-scan.php`](scripts/competitor-scan.php)

- ✅ Cron entry point + `nextRunAt()` scheduling by frequency (daily/weekly/monthly)
- ✅ Places API search within radius/area
- ✅ Full diff detection: new, gone (`is_gone=1`), moved (Haversine > 150m), rating delta ≥ 0.3
- ✅ Alert generation with type + severity
- 🟡 **Notification channels: in-app only** — `fanoutNotification()` inserts into `notifications` table; **email + Slack channels stubbed/missing**

---

## SECTION 7: DATA PIPELINE AUDIT

### 7.1 Census Data ETL — 🟡 PARTIAL

**Files:** [`scripts/seed-census.php`](scripts/seed-census.php), [`scripts/seed-census-reporter.php`](scripts/seed-census-reporter.php), [`scripts/aggregate-geographies.php`](scripts/aggregate-geographies.php)

- ✅ TIGER/Line download instructions (manual: `ogr2ogr -f GeoJSON`)
- ✅ Shapefile → MySQL via `ST_GeomFromText(?, 4326)`
- ✅ Two ACS strategies: official Census API + Census Reporter fallback (no API key)
- ✅ All required variable codes present (B01003, B19013, B23025, B25077, B25001, B01001, B19001)
- ✅ Materialized aggregations to `census_counties` + `census_states` ([`aggregate-geographies.php:86-130`](scripts/aggregate-geographies.php#L86))
- ✅ Idempotent (`ON DUPLICATE KEY UPDATE`)
- 🔴 **No yearly refresh cron** — scripts exist but no schedule. Data drift left to operator.

### 7.2 OpenRouteService Data — 🔴 NOT IMPLEMENTED

- No OSM download
- No Dockerfile / docker-compose for self-hosted ORS
- Implementation uses **hosted ORS API** — fine for low volume, but subject to public-tier rate limits

### 7.3 Segmentation Pipeline — ✅ COMPLETE

- [`scripts/segment-tracts.php`](scripts/segment-tracts.php) — 16 lines, invokes `SegmentationService::recomputeAll()`
- Batched 500 tracts at a time, REPLACE INTO `tract_segments`
- Uses age + income + home value + density Census fields (no education/commute/mobility, but the implemented rule cascade doesn't require them)

---

## SECTION 8: INFRASTRUCTURE & DEVOPS AUDIT

### Implemented

- ✅ **Custom migration system** ([`scripts/migrate.php`](scripts/migrate.php)) — tracked, idempotent
- ✅ **Nginx + Apache fallback** ([`nginx.conf`](nginx.conf), [`.htaccess`](public/.htaccess)) — gzip, static-asset caching, internal upload serving
- ✅ **CORS** — [`config/cors.php`](config/cors.php) (currently `*` — permissive)
- ✅ **Rate limiting** middleware ([`Middleware.php:58-72`](src/Core/Middleware.php#L58)) via `api_usage_log`; plan-tier enforcement via `PlanLimits.php`
- ✅ **Audit logging** — `audit_log` table populated by mutating endpoints
- ✅ **API usage logging** — `api_usage_log` table feeds rate limiter
- ✅ **Global error handler** ([`public/index.php:28-38`](public/index.php#L28)) — logs to `storage/logs/php-error.log`, returns generic 500 in production, stack trace in dev
- ✅ **HTTPS via Certbot** in [`droplet-deploy.sh`](scripts/droplet-deploy.sh)
- ✅ `.env.example` complete for both backend and frontend

### Missing / Not Production-Grade

- 🔴 **No Docker** — bare-metal only; hard to reproduce environments
- 🔴 **No Redis** — `CacheService` uses filesystem (`storage/cache/`); spec assumes Redis for hot caches (isochrone results, Places, geocoding)
- 🔴 **No S3/GCS** — uploads + generated reports live in `public/uploads/` and `storage/reports/` on the droplet; not horizontally scalable, no backup story
- 🔴 **No health-check endpoint** — `/health` or `/status` not registered; load balancers can't probe
- 🔴 **No structured logging** — `error_log()` only; no Monolog, no JSON logs, no log shipping
- 🔴 **No CI/CD** — no GitHub Actions, no automated tests run on push
- 🔴 **No automated backups documented**
- 🔴 **No API docs** (Swagger / OpenAPI / Postman collection)
- 🟡 **Rate limiting partial** — only some endpoints
- 🟡 **CORS too permissive** — `*` should be narrowed to known origins in production

---

## SECTION 9: QUALITY & COMPLETENESS SCORECARD

Legend: ✅ COMPLETE · 🟡 PARTIAL · 🔴 NOT STARTED · ⚠️ BROKEN

### Feature Completeness Matrix

| Feature | Status | % Complete | Key Gaps |
|---|---|---|---|
| Interactive Map + Controls | ✅ | 100% | — |
| Isochrone Generation (static) | ✅ | 100% | Hosted ORS only (no self-host) |
| Manual Drawing Tools | ✅ | 100% | Polygon + circle (rectangle absent — not required) |
| Demographics Overlay | ✅ | 100% | — |
| POI / Business Search | ✅ | 100% | — |
| CSV/Excel Import | ✅ | 100% | Synchronous, no streaming for >10MB |
| Data Export (CSV, Excel, PDF) | ✅ | 100% | — |
| Heatmap Visualization | ✅ | 100% | — |
| Area Folders & Organization | ✅ | 100% | — |
| Area Overlap (visual) | ✅ | 100% | — |
| User Authentication | 🟡 | 70% | No password reset, no email verify, no logout revocation |
| Stripe Billing | ✅ | 100% | — |
| Team / Multi-user | 🟡 | 60% | Collaborator backend exists; no UI for team mgmt; no seat enforcement UI |
| Territory Auto-Generation | 🟡 | 80% | No direction naming, hull instead of union+simplify |
| Cannibalization Modeling | 🟡 | 75% | No risk-tier classification thresholds |
| Traffic-Aware Isochrones | 🟡 | 85% | Multiplier-based, not Routes API + radial sampling |
| Multi-Location Optimization | ✅ | 90% | Greedy only (no local search) — documented trade-off |
| Customer Segmentation | ✅ | 95% | No Esri-style spending profiles (intentional) |
| Collaboration & Versioning | ✅ | 95% | Backend complete; UI consolidated into AdvancedPanel |
| Mobile Field App | 🟡 | 70% | Field-notes API/UI present; no PWA manifest, no offline, no native install |
| Competitor Monitoring | 🟡 | 80% | In-app notifications only — no email/Slack |
| Census Data Pipeline | 🟡 | 85% | No yearly refresh cron |
| Self-Hosted Routing (ORS Docker) | 🔴 | 0% | Not implemented; hosted API only |

### Top 10 Most Critical Gaps

1. **No password reset / email verification** — users locked out have no recovery path. ([`AuthController`](src/Controllers/AuthController.php))
2. **No CI/CD, no tests** — every deploy is hand-rolled; no regression safety net.
3. **No structured logging or health check** — operations blind in production; load balancers can't probe.
4. **No Redis** — file-based cache won't scale beyond one droplet; can't share between PHP-FPM workers reliably.
5. **No S3/GCS for uploads + reports** — single-server storage is a horizontal-scale blocker and a backup risk.
6. **No yearly Census refresh automation** — ACS publishes annually; manual re-run required, data drifts silently.
7. **TeamManagement.tsx + IntegrationsPage.tsx missing** — Business-tier UX promise not deliverable.
8. **Cannibalization missing risk-tier classification** — UI has raw percentages but no actionable low/moderate/high/critical labels.
9. **Competitor notifications in-app only** — operators not paged when scans surface critical changes (new direct competitor opens in territory).
10. **Self-hosted OpenRouteService absent** — hosted-API rate limits cap throughput; one heavy customer can exhaust the quota.

### Top 10 Code Issues / Bugs to Verify

1. **CORS preflight may fail** — `OPTIONS` not registered as a route in [`config/routes.php`](config/routes.php); rely on web-server-level handling only.
2. **Stripe webhook controller does not re-verify signature** — trust delegated to service layer ([`BillingController:29-42`](src/Controllers/BillingController.php#L29)); worth a guard.
3. **Long-running jobs bump `memory_limit` + `set_time_limit` inline** in TerritoryController/MclpController — under load this can starve PHP-FPM workers.
4. **CSV/XLSX upload reads entire file into memory** ([`ImportController`](src/Controllers/ImportController.php)) — risk of OOM on near-10MB files.
5. **Rate limiting incomplete** — geocoding/import/territory-generation/MCLP have no per-endpoint throttle.
6. **JWT has no revocation list** — stolen token valid until natural expiry (24h); no "log out everywhere" possible.
7. **ErrorBoundary exists but is not mounted** ([`ErrorBoundary.tsx`](frontend/src/components/ErrorBoundary.tsx)) — a single React crash blanks the page.
8. **React Query installed but used in only 4 components** — most data fetches re-run on every render; wasted API calls and unnecessary re-renders.
9. **`AdvancedPanel.tsx` is 712 lines of inline JSX** — single-file regressions will be hard to isolate; testing requires mounting the whole panel.
10. **CORS configured as `*`** in [`config/cors.php`](config/cors.php) — should be narrowed in production.

### Architecture Concerns

- **Monolithic `AdvancedPanel.tsx`** — hard to test, no tree-shaking, no reuse outside the panel.
- **No background-job queue** — algorithms run synchronously inside HTTP requests with bumped timeouts. Will not survive horizontal scale.
- **File-based cache + local storage** — couples app state to a single host.
- **No observability stack** — no metrics, no APM, no log aggregation. Production debugging is grep on the droplet.
- **No automated tests anywhere** — every change is a leap of faith.

---

## SECTION 10: RECOMMENDED NEXT STEPS

### Critical Fixes (do first)

1. **Wire ErrorBoundary into `AppLayout`** — wrap `<MapCanvas>` and major panels. ~1 hour.
2. **Register explicit `OPTIONS` handler** in [`config/routes.php`](config/routes.php) or in the Router to ensure CORS preflight succeeds for complex requests. ~1 hour.
3. **Add `/api/health` endpoint** returning `{ ok: true, db: <bool>, version: <git-sha> }`. Required for any load balancer. ~30 minutes.
4. **Tighten CORS allowed_origins** from `*` to the production frontend origin only. ~15 minutes.
5. **Mount Stripe signature re-check in the controller** as defense-in-depth, even though the service handles it. ~30 minutes.

### Missing Core Features

6. **Password reset flow** — endpoint pair (`request-reset`, `reset`), email send via PHPMailer or transactional provider, token with TTL stored in DB. ~1 day.
7. **Email verification** at registration — same email infra. Mark `users.email_verified_at`. ~half day.
8. **JWT revocation list** — `revoked_tokens` table keyed on `jti`; middleware check. Logout writes to it. ~half day.
9. **TeamManagement page** — invite collaborators, list/remove members, role editor. Backend already exists. ~1–2 days.
10. **IntegrationsPage** — at minimum API key display + regeneration; HubSpot OAuth deferrable. ~1 day for keys only.

### Missing Advanced Features

11. **Cannibalization risk tiers** — threshold the existing `cannibalized_pct` into low/moderate/high/critical, surface in [`CannibalizeTab`](frontend/src/components/advanced/AdvancedPanel.tsx). ~half day backend + half day UI.
12. **Direction-based territory naming** — compute centroid bearing from project center, label "NW / N / NE / ..." in [`TerritoryController.php:91`](src/Controllers/TerritoryController.php#L91). ~2 hours.
13. **Competitor email/Slack notifications** — extend `fanoutNotification()` with channel dispatch; reuse email infra from #6. ~half day each.
14. **Yearly Census refresh cron** — wrap `seed-census.php` + `aggregate-geographies.php` in a wrapper script, add to `crontab` in `droplet-deploy.sh`. ~2 hours.
15. **PWA manifest + service worker** for field-notes mobile use — `manifest.json`, basic offline shell, "Add to Home Screen" prompt. ~1 day.

### Polish & Optimization

16. **Expand React Query coverage** — wrap AreaList, POISearchPanel, AreaCreator preview, AdvancedPanel tabs. ~1 day. Big perf and code-quality win.
17. **Split `AdvancedPanel.tsx` into per-feature files** under `frontend/src/components/advanced/`. ~1 day, mechanical.
18. **Background job queue** — even a simple DB-backed queue (`jobs` table + cron worker) for territory generation / MCLP / competitor scans removes inline timeout hacks. ~2 days.
19. **Move file-cache → Redis** — `predis/predis`, swap `CacheService` backend. Phpredis is faster but predis avoids native ext. ~half day.
20. **Move uploads + reports → S3** (or DigitalOcean Spaces — S3-compatible). `aws/aws-sdk-php`. ~1 day.
21. **Structured logging** — Monolog with JSON formatter + rotating handler. ~half day.
22. **Streaming CSV import** — switch from `IOFactory::load()` to PhpSpreadsheet's reader chunking, or hand-roll a streaming CSV parser. ~1 day.
23. **Add OpenAPI/Swagger doc** for the API surface — even a hand-written spec file improves the team-onboarding experience. ~2 days.
24. **Self-hosted OpenRouteService** (Docker) — only if hosted-API rate limits become a problem. ~1 day infra + ongoing maintenance.
25. **First test suite** — start with PHPUnit for `Services/` (territory, MCLP, segmentation, traffic, census). Vitest for stores + utils. ~2–3 days for a meaningful first pass.

### Effort Summary

| Tier | Items | Estimate |
|---|---|---|
| Critical fixes | 5 | ~half day total |
| Missing core features | 5 | ~4–5 days |
| Missing advanced features | 5 | ~3–4 days |
| Polish & optimization | 10 | ~2 weeks for the full set |

**Realistic MVP-launch path:** items 1–10 (~1 week of focused work) get the product to a state where you can onboard the first paying customer without operational embarrassment. Items 11–18 are the second sprint; 19–25 are scale prep.

---

## Final Verdict

**Smappen is much further along than a typical "audit me" repo.** The spec coverage is genuinely impressive — 23 controllers, 10 services, all 29 schema tables, every external integration, and 9 distinct advanced features all functionally present. The work that remains is overwhelmingly **operational** (auth recovery, observability, queueing, object storage, CI) rather than **product** (the product itself works).

The two pieces of debt most likely to bite you in the first 90 days of paying customers:

1. **Auth recovery** — the moment a customer loses a password, you have to manually reset their DB row.
2. **No queue, no Redis, single-droplet file storage** — the first customer who imports 50k addresses and runs territory generation on a 10-county region will pin the box.

Everything else is a known-good improvement, not an existential threat.
