# Truck Planner · decisions (session 0)

This file maps the [blueprint](BLUEPRINT.md) onto the real smappen repository. It is binding for every later
session: where a later document disagrees with this file, this file wins until it is edited.

Written 2026-10-04 from a ten-part read of the codebase and a same-day check of every public data source.

---

## 0. Owner instruction, 2026-10-04: Google Maps only

> **"Stick with Google Maps only — the project already has the Google Maps API hooked up."**
> This section supersedes anything else in this file, in any prompt, or in any other document that mentions
> OpenRouteService or another map provider.

Truck Planner uses **one maps platform: the Google Maps Platform that smappen already has wired in**
(`VITE_GOOGLE_MAPS_API_KEY` in the browser, `GOOGLE_API_KEY` on the server). Concretely:

| Need | Provider | Notes |
|---|---|---|
| The map itself, pins, the hex layer's host | Google Maps JavaScript API (existing loader, raster map, no map id) | Our hex layer is our own canvas inside a Google `OverlayView`. No Leaflet, Mapbox, MapLibre, deck.gl or other map library |
| Address search and geocoding | Google (existing `GooglePlaceAutocomplete` widget; existing `/api/geocode`) | Unchanged |
| **Drive times and distances** | **Google Routes API** `computeRouteMatrix` (server-side, `GOOGLE_API_KEY`), falling back to the legacy Distance Matrix API if Routes is not enabled on the key, then to a labelled straight-line estimate | **OpenRouteService is not used anywhere in Truck Planner.** Version 1 asks for traffic-unaware durations and applies our own time-of-day factors, so results are deterministic; Google's toll estimate is taken when present and the owner can overwrite it |
| Scout: phone, website, "see it" | Whatever the place row already carries, plus a free **"Open in Google Maps"** link on every candidate, plus an on-demand **Google Places (New) Text Search** contact lookup when the owner asks for it | The Google place id is stored on the lead; looked-up details are cached for at most 30 days |
| "Open in Maps" for a spot or a day's route | Google Maps URLs | Free deep links, no API call |

Rules that follow:

- **No new map or routing provider, SDK or key.** External hosts Truck Planner may call at runtime:
  `routes.googleapis.com`, `maps.googleapis.com`, `places.googleapis.com`, `api.weather.gov`, `api.eia.gov`.
- **Google content is not kept forever.** Route durations/distances and place details fetched from Google are
  cached for at most **30 days** (place ids may be kept indefinitely). The leg cache therefore has a
  `fetched_at` and is refreshed lazily; the owner's own corrections are the owner's data and are permanent.
- Every Google call is metered the way the app already does it (`api_cost_events`, a rate-limit profile), is
  never plan-gated, and never puts a URL that carries the key into an error message or a log line.
- **The one thing Google cannot supply is the metro-wide list of every food outlet and venue** that the heat map
  and the share model need (its Places API is per-search, billed per call, capped at 20 results, and its terms
  forbid storing the results). That list comes from a free, public **OpenStreetMap data download** loaded once
  into MySQL — a dataset like the Census files, not a map API, with no key and no runtime call. It is isolated
  behind the pipeline so a different source can replace it later.

## 1. What we were given, and what that changes

- Only the blueprint README exists. The reference implementation, golden cases, PHP/TypeScript ports, ETL
  prototype, seed files and documents 01–07 it mentions were **never supplied**. We design and build all of it.
- Because the math here is derived independently, it carries its own model version: **`tps-0.1.0`**
  ("Truck Planner on smappen"). It is not the blueprint bundle's `tp-0.1.0` and must never be labelled as such.
  If the original bundle turns up, reconcile against it and bump the version.
- The blueprint's worked example (66 / 39 orders, the 9:34 → 20:51 day sheet) is used as a **shape check**, not as
  a golden value: the timeline arithmetic must reproduce it exactly for the same inputs; order counts must land in
  the same range for comparable inputs.

## 2. Stack: what the blueprint assumed vs what is here

| Blueprint assumed | Reality in this repo | Decision |
|---|---|---|
| PHP 8.3, custom framework | Yes. No container, ORM or validator. `config/routes.php` → `new Controller()` → `Response::success()` (which exits). Production web tier is PHP 8.3; this machine has 8.2; cron may run a newer PHP | Write to the **PHP 8.1–8.3 common subset**. No 8.3-only syntax |
| MySQL for app tables | MySQL 8.0.45, `utf8mb4_unicode_ci`, UUID `CHAR(36)` ids, no foreign keys, integer cents | Follow the Carafe-era conventions exactly |
| PostGIS for geodata | **Does not exist.** MySQL only | Plain InnoDB tables with `lat`/`lng` `DOUBLE`, a B-tree on `(region_id, lat, lng)`, bounding-box prefilter in SQL and great-circle maths in PHP. **No spatial columns, no `ST_*` in model maths** |
| Redis | Not in production | TTL caches go through `CacheService` (MySQL `cache` table). Anything permanent gets its own table |
| A job queue | `jobs` table exists but nothing schedules its worker | **Not used.** Request-time work stays well under a few seconds; operator work is CLI scripts; weather and fuel are fetched on demand with a TTL |
| React + TS + Zustand + TanStack Query + Tailwind | Yes (React 18, router 6, Query 5, Zustand 4, Tailwind 4, Vite 5), Google Maps raster via `@react-google-maps/api` | Use as is. TanStack Query for all truck data |

## 3. Where things live (resolves the blueprint's `{{…}}` placeholders)

| What | Path |
|---|---|
| Specification | `docs/truck-planner/` — `BLUEPRINT.md`, this file, `02_MODEL.md`, `03_DATA.md`, `04_BACKEND.md`, `05_FRONTEND.md` |
| Reference math (the definition) + seeds | `docs/truck-planner/reference/truck_planner_reference.py`, `docs/truck-planner/reference/tp_seeds.json` |
| Golden cases shared by all runtimes | `tests/fixtures/truck-planner/golden_cases.json` (+ `seeds` copy checks) |
| Backend code | `src/TruckPlanner/` — namespace `App\TruckPlanner`. `Model\` = pure math, no I/O. `Data\` = repositories with literal SQL. `Services\` = I/O (routing, weather, fuel, capture, planning, scouting, calibration) |
| Controllers | `src/Controllers/Truck*Controller.php` |
| Routes | one clearly marked block in `config/routes.php` (the file is CRLF — preserve it), prefix **`/api/truck`** |
| Migrations | `src/Migrations/042_…` onward, tables prefixed **`tp_`** |
| Operator CLI | `scripts/truck/*.php` |
| Geodata pipeline | `tools/truck-etl/` (Node ≥ 20, only dependency `h3-js`) |
| Backend tests | `tests/TruckPlanner/` — its own PHPUnit suite **`truck-planner`** |
| Frontend | `frontend/src/components/truck/` (`TruckLayout.tsx` eager, `TruckPages.ts` barrel = the lazy chunk, `pages/`, `map/`, `ui/`), `frontend/src/api/truck.ts`, `frontend/src/stores/truck*.ts`, `frontend/src/utils/truck/` (estimator port, formatters, pure map maths, `__tests__/`) |

Truck Planner code never lives in `src/PrivateData`, `src/MarketData` or `src/SharedRef`, and never imports from
`App\PrivateData` or `App\MarketData` (the existing data-wall test is a substring grep and is already red).

## 4. Product shape

- **Route** `/truck/*`, lazy-loaded as one chunk (the app's first lazy route). `/` redirects to `/truck`.
  "Truck" is the first tab in `AppNav`; the existing sections stay.
- **Sub-navigation**: Today · Map · Spots · Planner · Week · Log · Scout · Settings.
- **Landing is operations-first** (owner feedback from May: landing screens must not be map-heavy). *Today* answers
  "where am I going, what will I clear, what's the weather, what did yesterday do against the estimate". The map is
  a tool one tap away.
- First run: if the organization has no truck yet, every `/truck` page shows a short "Set up your truck" step
  (name, base, average ticket). No use of the existing onboarding-flags mechanism.
- US only in version 1: miles, °F, dollars, 12-hour clock, fixed `en-US` formatting.
- Light theme is the launch target; use CSS variables throughout so the existing partial dark mode keeps working.
- One truck per organization in version 1 (every sign-up already gets its own organization). Tables key on
  `truck_id` so a second truck is not a reshape later.

## 5. The three rules, and how each is enforced

| Rule | Enforcement |
|---|---|
| **One truck is enough** | No feature reads another organization's rows. No `navigator.geolocation`, `watchPosition` or Permissions API call anywhere under the truck frontend paths or their import closure — a Vitest source test fails the build if one appears. The base and every spot are typed, picked from address search, or clicked on the map |
| **No AI at runtime** | A PHPUnit source test and a Vitest source test scan Truck Planner code (comments stripped, paths normalised) for LLM/ML hosts, SDKs, key names and the first-party classes that reach an LLM today (`AiScoringController`, `OpsController`, `MenuEngineeringService`, `MenuEngineeringController`, `SampleDataService`, job type `pos.sync`, `ml-sidecar`), and for the endpoints `/ai-score`, `/ai-rankings`, `/dashboard/briefing`, `/recommendations/run`. External hosts are an allow-list: `routes.googleapis.com`, `maps.googleapis.com`, `places.googleapis.com`, `api.weather.gov`, `api.eia.gov`. No `random_int`, `mt_rand`, `shuffle`, `Math.random` or wall-clock reads inside the model |
| **Honest numbers** | Every estimate the UI shows is `value (low to high)` with a confidence label and an openable breakdown; a single shared component renders it, and model outputs carry `low`/`high`/`confidence` in their type so a bare number cannot be passed by accident. Logged results outrank the model through calibration. A wording test bans phrases that assert legality ("legal", "permitted", "allowed to park", "approved"); every spot and scout screen carries the standing line that permission and local rules are the owner's to check |

## 6. Domain model (provisional field lists; `02_MODEL.md` and `04_BACKEND.md` finalise them)

Money is **dollars (decimal numbers) in the model and in the API**, **integer cents in MySQL**; repositories
convert at the boundary. Times of day are **minutes from local midnight** (a close after midnight is > 1440).
Dates are `YYYY-MM-DD` civil dates in the truck's region time zone. Hour-of-week index is
`how = dow × 24 + hour`, **`dow` 0 = Monday … 6 = Sunday**.

- **Truck profile** — name, region, base `{lat, lng, address}`, average ticket (15.00), capacity orders/hour (45),
  paid crew (2) and wage (18.00), payroll burden %, food cost % (0.30), packaging per order, card fee % + fixed +
  card share, optional tips, mpg (9), fuel type, fuel price override, generator gallons/hour, prep / setup /
  teardown / close-out minutes (45 / 30 / 20 / 30), fixed cost per service day, daypart fit (breakfast, lunch,
  dinner, late), routing profile (car / truck) and truck time factor (1.10), licence counties, scouting drive-time
  limit, sparse overrides of seed assumptions.
- **Spot** — name, point, address, notes; host (kind, name, contact, phone, website, linked place), host size
  (busiest-hour headcount), "truck is the only food" flag, visibility (hidden / normal / prominent), fee (flat, %
  of sales, minimum), optional allowed days/hours, stored capture vectors with their data version.
- **Day plan** — service date, optional "treat this day as…" override, ordered stops, notes, status, a stored
  result snapshot. **Stop** — kind (spot / event / catering), open and close minutes, "gap before is an unpaid
  break" flag, event terms (attendance, vendors, type, fee) or catering terms (guarantee or per-head price,
  headcount, cost).
- **Service log** — spot, date, open/close, orders, optional sales, "sold out / at capacity" flag (a censored
  observation), notes, source + external key (so a later sales import can write here), and the prediction
  snapshot it will be judged against.
- **Drive leg** (permanent cache) and **owner correction** (minutes override, toll dollars) per directed leg.
- **Scout lead** — place, status (new / shortlisted / contacted / booked / declined / hidden), notes.

## 7. Model decisions (summary — the reference implementation is the definition)

1. **Sources are points, not cells.** Every census block (residents; jobs by seven worker groups) and every
   OpenStreetMap venue (default busiest-hour size by type) is a source point with a base count per segment.
2. **Sixteen segments**, fixed order: `res`; `w_office`, `w_health`, `w_edu`, `w_retail`, `w_industrial`,
   `w_hospitality`, `w_public`; `v_nightlife`, `v_shopping`, `v_leisure`, `v_campus`, `v_hospital`, `v_transit`,
   `v_events`, `v_lodging`. Worker base is **jobs** (presence converts jobs to bodies); venue base is
   **busiest-hour headcount** (presence peaks at 1.0).
3. **Share is an origin-based gravity model with an outside option.** For people at origin `c`, the truck's share is
   `f(d_truck)·V / (A0 + f(d_truck)·V + rivals_c)` with `f(d) = exp(−d / 400 m)`, cut off at 1,200 m, distance by
   haversine on a sphere of radius 6,371,008.8 m. `rivals_c` is the weighted, distance-discounted pull of food
   outlets around the origin, precomputed per source point for **two competition regimes** (day 05:00–15:59,
   evening 16:00–04:59) because outlet kinds weigh differently by daypart.
4. **Three vectors describe a location**: `capture[regime][segment]` (people the truck would win per unit of
   intent), `nearby[segment]` (distance-weighted people within walking distance), `rivals[regime]`. The geodata
   build precomputes them for every map cell centre (visibility = normal); the server computes them exactly for a
   clicked point or saved spot. Kernel constants (`walk_decay_m`, cutoff, `A0`, rival weights, regime table) are
   **build-time constants** recorded in the pack; changing them means rebuilding the region.
5. **Hourly demand** = Σ segments `capture × presence × day-of-week factor × meal intent × menu fit`, plus a
   **host term** when the spot has a venue host (size × presence × intent × captive or shared-kitchen share — the
   host's own default data point is excluded so it is not counted twice), × weather × calibration, then **capped
   once per hour at truck capacity**. Unserved demand is not carried forward.
6. **Curves** are three day types (weekday / Saturday / Sunday) × 24 hours per segment plus Monday–Friday factors,
   expanded to 168 hours. Federal holidays and the owner's "treat this day as…" remap day types per segment.
7. **Ranges**: every count is a mean with an 80 % interval from a log-normal model whose spread combines model
   uncertainty (wide before any logs, narrowing with the owner's logged services) and counting noise. Extra spread
   when weak-seed segments (hospitals, campuses, transit) dominate. Day totals add lows to lows and highs to highs.
8. **Calibration** is shrinkage on log ratios of actual to predicted orders: one factor for the truck, one per
   spot, recency-weighted, with sold-out services treated as lower bounds. Ordinary statistics only.
9. **Determinism**: no clocks, no randomness, no locale, no process time zone inside the model. One explicit
   rounding rule (half away from zero) implemented by hand in all three languages. Golden cases compare with a
   relative tolerance of 1e-9.

## 8. Data decisions

- **First region**: `dc` = Washington–Arlington–Alexandria CBSA 47900, **23 counties in DC, MD, VA and WV**
  (the blueprint's 5.8 M residents fits a smaller box; the official metro is 6,278,542 residents and 3,140,158
  jobs, and those are our check values). Time zone `America/New_York`.
- **Residents**: 2020 Census PL 94-171 bulk files (the Census API now needs a key for every query). **Block
  coordinates** come from the Census internal point for residents and jobs alike.
- **Jobs**: LEHD LODES 8.4 WAC, all jobs (`JT00`), data year 2023, CNS01–20 grouped into the seven worker
  segments. Payroll-address artefacts (86 blocks hold 18 % of metro jobs) go through a versioned corrections file
  plus an automatic cap, and every capped block is listed for review.
- **Places** (the metro-wide dataset behind the heat map, the share model and Scout's candidate list): one dated
  OpenStreetMap snapshot per region from Geofabrik state extracts, read by an in-repo zero-dependency PBF reader.
  Never Overpass at request time, never a bulk Google Places sweep, never the vendor tables. OSM-derived rows stay
  in their own table with `osm_type`/`osm_id`/snapshot date. Attribution "© OpenStreetMap contributors" appears
  wherever that data is listed (Scout, the spot card's nearby-outlets list, the day sheet, the Data page).
  Google Places is used only on demand, for one candidate at a time, to look up phone and website (section 0).
- **Map cells**: H3 **resolution 9** (≈ 350 m across). H3 ids travel as 15-character strings. PHP never does H3
  arithmetic; the pipeline (Node) and the browser (`h3-js`) do.
- **Pipeline**: `tools/truck-etl` (Node) downloads, parses and writes `points`, `places`, candidate `cells`, a job
  review list and a manifest. `scripts/truck/load-region.php` loads MySQL, computes rivals per point and the three
  vectors per cell with the same PHP code the API uses, and stores the **cell pack** (a compact binary blob,
  versioned, served with long-lived HTTP caching).
- **Tables** (shared reference, no organization column): `tp_regions`, `tp_points`, `tp_places`,
  `tp_region_packs`, `tp_fuel_prices`.
- Residents are April 2020 and are not scaled; every vintage is shown on the Data page.

## 9. Backend decisions

- Tenant key is `organization_id`; every query carries it; another tenant's id answers **404**. Never return 401
  for anything but "not authenticated" (the SPA logs out on any 401).
- Owner tables: `tp_trucks`, `tp_spots`, `tp_plans`, `tp_plan_stops`, `tp_service_logs`, `tp_drive_legs`
  (shared 30-day cache of Google legs), `tp_drive_overrides` (the owner's corrections and tolls, permanent),
  `tp_scout_leads`. No foreign keys; deletes are explicit, child-first,
  in a transaction. No MySQL 8 reserved words as identifiers (`rank`, `window`, `groups`, `lead`, `row`, …).
- **External services**, each behind a small client class with a short timeout, a labelled fallback and no
  exception text that could carry a key:
  - **Google Routes API** `POST https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix` — existing
    server `GOOGLE_API_KEY` in the `X-Goog-Api-Key` header, an explicit `X-Goog-FieldMask`, travel mode `DRIVE`,
    routing preference `TRAFFIC_UNAWARE`; if the key answers "API not enabled / permission denied", the legacy
    Distance Matrix API (`maps.googleapis.com/maps/api/distancematrix/json`) is tried once, and if that is also
    refused the service returns a **straight-line estimate labelled as such** and remembers the refusal for an
    hour so it does not hammer Google. Legs are cached per directed pair of rounded coordinates for **30 days**
    (`tp_drive_legs.fetched_at`); owner corrections and tolls sit on top and never expire; time-of-day factors
    come from our own seed table (not `TrafficService`). Google's toll estimate, when returned, pre-fills the toll.
    **OpenRouteService and the existing `DriveTimeMatrixService` are not used.**
  - **Google Places (New) Text Search** — on demand only, one Scout candidate at a time ("Look up phone and
    website"): `textQuery` = the place name, a 500 m location bias around it, field mask limited to id, display
    name, formatted address, national phone number, website URI and Google Maps URI. The place id is stored on
    the lead; the looked-up fields are cached for 30 days. Rate-limited per user.
  - `api.weather.gov` hourly forecast — `User-Agent` with a contact from `TP_CONTACT_EMAIL` (falls back to
    `MAIL_FROM`); cached per grid point until it expires.
  - EIA weekly retail fuel price — optional `EIA_API_KEY`; the PADD sub-district follows the base state
    (VA, WV → `R1Z`; DC, MD → `R1Y`); the owner's own price always wins; without a key the last stored value or
    the seed default is shown with its date.
- API surface (all under `/api/truck`, all behind the existing auth middleware): `bootstrap`, `profile`,
  `assumptions`, `regions` + cell pack, `simulate`, `spots`, `day-context` (holidays, weather, fuel),
  `drive-times` (+ overrides), `plans` (+ evaluate), `suggest/day`, `suggest/week`, `services`, `calibration`,
  `accuracy`, `scout` (+ lead status, contact lookup, save as spot), `export`, `data` (delete), `sources`.
- New endpoints are not plan-gated and never return `_meta.estimated_cost_usd`.
- Legality: `PermitsService` and any `permit*` wording stay out of Truck Planner.

## 10. Frontend decisions

- **Numbers shown are computed by the TypeScript estimator** from server-supplied inputs (capture vectors,
  profile, assumptions, day context, calibration, drive legs), so every tweak is instant. The PHP estimator
  produces the stored snapshots, suggestions, scouting ranks and exports. Golden cases guarantee the two agree.
- **Map layer**: our own WebGL2 canvas inside a plain `google.maps.OverlayView` on the existing raster map (no map
  id), true H3 vertices in metro-relative world coordinates, one byte per cell re-uploaded per tick, a single
  colour scale for the whole week; a 2D-canvas renderer behind the same interface as fallback. The hour lives in
  its own small store with imperative subscriptions. Events come from the map, not the canvas. Pins are DOM
  overlays. `window.gm_authFailure` is handled.
- Layers: **Opportunity** (expected orders/hour), **People nearby**, **Competition**.
- Page patterns follow the Carafe screens, not the older map screens: `--ink`/`--body` text, labels at weight 700,
  decision numbers at 600+ with tabular figures, flat bordered cards (12 px), no gradients, no backdrop blur, no
  emoji, no light-grey reading text. House charts are hand-rolled SVG on CSS variables.
- Missing shared primitives are built once under `components/truck/ui/` (range value, confidence chip, number
  field, modal/sheet, tabs, table, hour bars, week strip) and formatters under `utils/truck/`.
- Day sheet is a print-styled page; the calendar file (`.ics`) is generated in the browser.
- Today, Planner, Log and the day sheet must work at 375 px. The map must work on a tablet.

## 11. Tests and CI

- Three runtimes run the same golden cases: Python reference (self-test), PHP (`truck-planner` suite) and
  TypeScript (Vitest). PHP runs them under three time zones.
- The legacy PHPUnit suite is **red at baseline (9 of 220, before any Truck Planner change)** and stays as it is.
  Truck Planner gets a separate CI job: Python self-test, `phpunit --testsuite truck-planner`, `npm test`,
  ETL tests. `.phpunit.cache/` and `storage/truck/` are git-ignored; `composer.lock` stays untracked.
- Controllers cannot be unit-tested in-process (`Response` exits), so API behaviour is covered by an HTTP smoke
  script run against `php -S` and a scratch MySQL 8.

## 12. Later layers (not version 1)

Sales import (Square, Clover, CSV) writing to `tp_service_logs` through its `source`/`external_key` columns;
opt-in check-ins; anything needing other trucks; per-daypart calibration; real opening hours per outlet in the
share model; more regions; a reduced navigation for truck-only accounts; "suppliers near your base" from the
existing vendor network; **Google traffic-aware drive times** for a planned departure (Routes API
`TRAFFIC_AWARE` with a departure time) in place of the seed time-of-day factors; a Google Places sweep as an
alternative source for the metro-wide places table, if the owner prefers to pay Google for it.

## 13. Defects found in existing code while reading (deliberately not fixed here)

| Where | What |
|---|---|
| `src/Services/DriveTimeMatrixService.php:41` (also `FootTrafficService`, `PermitsService`) | `Config` used without `use App\Core\Config;` — `POST /api/drive-time-matrix` has always returned 500 |
| `src/Services/GoogleMapsService.php:440` → `GeocodingController.php:27` | Geocode error text can include the request URL with the server Google key |
| `src/Services/OSMAdapter.php` | Sends no `User-Agent`; Overpass answers 406 |
| `scripts/seed-census.php:121`, `scripts/aggregate-geographies.php:51,89` | Double axis swap: a fresh census seed loads transposed geometry |
| `GET /api/exports/{filename}` | Checks login, not ownership |
| `tests/` | 9 failing tests at HEAD, including 4 of 5 data-wall tests |
| `.env.example` lines 11 and 16 | File does not parse; `cp .env.example .env` gives an app that cannot boot |
| `scripts/deploy.sh` | Reloads `php8.2-fpm`; production runs 8.3 |

## 14. Needs the owner

- A go-ahead before anything touches production (migrations, the region load, the deploy).
- **Routes API enabled** on the Google Cloud project that owns the existing server key (Places API (New) and
  Geocoding already are). Until it is, drive times show as labelled straight-line estimates.
- `EIA_API_KEY` (free) if weekly fuel prices should update themselves; a contact address for `TP_CONTACT_EMAIL`.
- Confirmation that using a free OpenStreetMap **data download** for the metro-wide venue list is acceptable
  (ODbL: an attribution line where that data is listed; share-alike on the derived places table once there are
  customers). It is the only non-Google map-related source, and only because Google has no bulk equivalent.
- The original blueprint bundle, if it exists, to reconcile the model against.
