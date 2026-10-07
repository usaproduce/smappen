# Truck Planner - 04 Backend (specification)

**Status.** Written 2026-10-04 against model `tps-0.1.0`, seeds revision 1, branch `truck-planner` at `29d40ef`. Binding inputs in order of precedence: [DECISIONS.md](DECISIONS.md) (sections 0, 3, 5, 6, 9, 11), then [02_MODEL.md](02_MODEL.md) for shapes and functions, then [03_DATA.md](03_DATA.md) for the reference tables, the loader and the cell pack. DECISIONS section 0 ("Google Maps only") is newer than 02 and 03 and overrides them. Conflicts, assumptions and gaps are collected in section 10.

Evidence tags: **[R]** read in this repository today, or run against it (by this document's author or by today's reconnaissance). **[M]** written from memory of Google's documentation, nothing was requested: confirm before coding. **[S]** specified here and not exercised (the build machine has no MySQL 8 and no Google key).

**Fixed elsewhere and not re-specified here.** (a) `src/Migrations/042_tp_reference_data.sql` creates `tp_regions`, `tp_points`, `tp_places`, `tp_region_packs`, `tp_fuel_prices` exactly as 03_DATA.md 9.1. (b) `src/TruckPlanner/Model/` holds the pure model: `Estimator`, `Seeds`, `SeedsData.php`, with golden tests in `tests/TruckPlanner/Model/`. (c) `tools/truck-etl/` writes the five build files of 03_DATA.md 8. This document specifies everything else in the backend, and how it calls (b) and reads (a) and (c).

## 0. Conventions

| Item | Rule |
|---|---|
| PHP | 8.1-8.3 common subset. `declare(strict_types=1);` in every new file. PSR-4 `App\` -> `src/`, `App\Tests\` -> `tests/` [R]. No new Composer package |
| Shapes | `TruckProfile`, `SpotTerms`, `Host`, `LocationVectors`, `DayContext`, `HourForecast`, `LegInput`, `StopInput`, `PlanInput`, `DayResult`, `WindowResult`, `StopMoney`, `Estimate`, `CalibrationState`, `ServiceLogEntry`, `AccuracyReport`, `Suggestion`, `WeekSuggestion`, `PlaceInput`, `ScoutResult`, `Holiday` are the shapes of 02_MODEL.md section 3, unchanged. Shapes added here are in 4.1 |
| Money | Dollars (JSON numbers) in the API and the model. `*_cents INT UNSIGNED` in MySQL: `Money::toCents($d) = (int) Estimator::roundHalfAway($d * 100.0, 0)`, `Money::fromCents($c) = $c / 100.0`. Fuel prices are `*_milli` (x 1000) the same way. JSON columns that hold model shapes verbatim (result and prediction snapshots) keep the model's dollars unrounded |
| Numbers in JSON columns | A MySQL `JSON` column is not a bit-exact store of doubles [R, MySQL 8.0.45: of 1,113 doubles that need 16 or 17 significant digits, 131 came back one unit in the last place off (relative 1e-16); all 897 with at most 15 digits came back exact; the same texts in a `MEDIUMTEXT` column and `Sql::f` texts in a `DOUBLE` column all came back exact]. So: everything that carries model numbers and must read back as saved is a `DOUBLE` column, the binary vector codec, or JSON text in a `MEDIUMTEXT` column: `tp_trucks.overrides_json` and the four stored snapshots `tp_plans.result_json`, `tp_plans.context_json`, `tp_service_logs.weather_json` and `tp_service_logs.prediction_json`. Those five are encoded with `JsonSafe` and decoded with `json_decode`; MySQL never parses them and no query looks inside them. The two remaining `JSON` columns (`licence_counties_json`, `allowed_json`) hold only strings, booleans and whole numbers. A stored shape is still compared with a freshly computed one with the tolerance of 02_MODEL.md 1.4, never bit for bit |
| Floats into SQL | Never bind a PHP float: PDO sends 14 significant digits [R]. Bind `Sql::f($x)` = `json_encode((float) $x)` (shortest round-trip text, for example `1.0e-7`). Non-finite values are rejected before any SQL |
| Booleans | Bind `1` or `0`. Return JSON `true` or `false` |
| Time | No `date()`, `time()`, `strtotime()`, `mktime()`, `new DateTime()` in Truck Planner code. `Clock` (2.3) is the only reader of the wall clock and always takes an explicit `DateTimeZone`. Civil-date arithmetic uses `Estimator::addDays` and `Estimator::dayOfWeek`. Row timestamps and every freshness test use MySQL `NOW()` inside the SQL statement, so one clock decides age; cache entries are aged by `TpCache` (2.3) with the UTC epoch from `Clock` |
| Rounding | Only `Estimator::roundHalfAway`. `round()`, `number_format()` on unrounded values and `intdiv()` on signed minutes do not appear in Truck Planner code |
| Logging | `error_log('[tp] ...')` with text passed through `Redactor::text()`. Never a URL with a query string, never an upstream body |
| Legality wording | No field, message or log line contains `legal`, `permitted`, `allowed to park`, `approved` or `permit`. (`allowed` alone names the owner's own allowed days and hours in `SpotTerms`.) |

## 1. Migrations

### 1.1 Files

| File | Creates | Notes |
|---|---|---|
| `042_tp_reference_data.sql` | `tp_regions`, `tp_points`, `tp_places`, `tp_region_packs`, `tp_fuel_prices` | Fixed: 03_DATA.md 9.1 |
| `043_truck_planner_core.sql` | `tp_trucks`, `tp_spots`, `tp_plans`, `tp_plan_stops`, `tp_service_logs`, `tp_drive_legs`, `tp_drive_overrides`, `tp_scout_leads`, two rows in `places_rate_buckets` | This document, 1.2 |

Rules [R]: `scripts/migrate.php` splits on a semicolon followed by a line break and records a file by name after its last statement. So: every statement ends with `;` at the end of a line, no `;` inside a `--` comment, no stand-alone `SELECT`, no `DELIMITER`, every statement re-runnable, never edit a file after it ran anywhere. No foreign keys. No MySQL 8 reserved word as an identifier (`rank`, `window`, `groups`, `lead`, `lag`, `row`, `rows`, `leave`, `condition`, `range`, `usage`, `key`). Every table ends with the full engine clause.

### 1.2 `043_truck_planner_core.sql`

```sql
-- 043_truck_planner_core.sql - Truck Planner owner tables.
--
-- tp_trucks, tp_spots, tp_plans, tp_plan_stops, tp_service_logs, tp_drive_overrides and tp_scout_leads
-- are organization-scoped. tp_drive_legs is the shared 30-day cache of Google legs and has no tenant column.
-- Money is integer cents, fuel prices are thousandths of a dollar, fractions and model inputs are DOUBLE.
-- tp_trucks.overrides_json and the four stored snapshots (tp_plans.result_json, tp_plans.context_json,
-- tp_service_logs.weather_json, tp_service_logs.prediction_json) hold JSON text in MEDIUMTEXT columns,
-- because a JSON column hands some 16- and 17-digit numbers back one unit in the last place off.
-- Idempotent: CREATE TABLE IF NOT EXISTS and INSERT ... ON DUPLICATE KEY UPDATE only. No foreign keys.
-- Splitter rule: statement-ending semicolons at the end of a line, none inside comments.

CREATE TABLE IF NOT EXISTS tp_trucks (
  id                         CHAR(36)      PRIMARY KEY,
  organization_id            CHAR(36)      NOT NULL,
  created_by                 CHAR(36)      NULL,
  name                       VARCHAR(120)  NOT NULL,
  region_id                  VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',
  timezone                   VARCHAR(60)   NOT NULL,
  base_lat                   DOUBLE        NOT NULL,
  base_lng                   DOUBLE        NOT NULL,
  base_address               VARCHAR(255)  NOT NULL DEFAULT '',
  base_state                 VARCHAR(2)    NULL,
  base_county_fips           VARCHAR(5)    CHARACTER SET ascii COLLATE ascii_bin NULL,
  avg_ticket_cents           INT UNSIGNED  NOT NULL,
  capacity_orders_per_hour   DOUBLE        NOT NULL,
  paid_crew                  TINYINT UNSIGNED NOT NULL,
  wage_cents                 INT UNSIGNED  NOT NULL,
  payroll_burden_pct         DOUBLE        NOT NULL,
  food_cost_pct              DOUBLE        NOT NULL,
  packaging_cents            INT UNSIGNED  NOT NULL,
  card_fee_pct               DOUBLE        NOT NULL,
  card_fee_fixed_cents       INT UNSIGNED  NOT NULL,
  card_share                 DOUBLE        NOT NULL,
  tips_include               TINYINT UNSIGNED NOT NULL DEFAULT 0,
  tips_pct                   DOUBLE        NOT NULL,
  mpg                        DOUBLE        NOT NULL,
  fuel_type                  VARCHAR(8)    NOT NULL DEFAULT 'gasoline',
  fuel_price_override_milli  INT UNSIGNED  NULL,
  generator_gal_per_hour     DOUBLE        NOT NULL,
  prep_minutes               SMALLINT UNSIGNED NOT NULL,
  setup_minutes              SMALLINT UNSIGNED NOT NULL,
  teardown_minutes           SMALLINT UNSIGNED NOT NULL,
  closeout_minutes           SMALLINT UNSIGNED NOT NULL,
  fixed_cost_day_cents       INT UNSIGNED  NOT NULL DEFAULT 0,
  fit_breakfast              DOUBLE        NOT NULL,
  fit_lunch                  DOUBLE        NOT NULL,
  fit_dinner                 DOUBLE        NOT NULL,
  fit_late                   DOUBLE        NOT NULL,
  avoid_tolls                TINYINT UNSIGNED NOT NULL DEFAULT 0,
  avoid_highways             TINYINT UNSIGNED NOT NULL DEFAULT 0,
  truck_time_factor          DOUBLE        NOT NULL,
  licence_counties_json      JSON          NOT NULL,
  scout_drive_minutes_limit  SMALLINT UNSIGNED NOT NULL,
  overrides_json             MEDIUMTEXT    NOT NULL,
  overrides_seeds_rev        INT UNSIGNED  NOT NULL DEFAULT 1,
  created_at                 DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tptr_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tp_spots (
  id                 CHAR(36)      PRIMARY KEY,
  organization_id    CHAR(36)      NOT NULL,
  truck_id           CHAR(36)      NOT NULL,
  created_by         CHAR(36)      NULL,
  name               VARCHAR(120)  NOT NULL,
  lat                DOUBLE        NOT NULL,
  lng                DOUBLE        NOT NULL,
  address            VARCHAR(255)  NOT NULL DEFAULT '',
  county_fips        VARCHAR(5)    CHARACTER SET ascii COLLATE ascii_bin NULL,
  notes              TEXT          NULL,
  visibility         VARCHAR(10)   NOT NULL DEFAULT 'normal',
  host_segment       VARCHAR(16)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  host_size          DOUBLE        NULL,
  host_size_source   VARCHAR(8)    NULL,
  host_only_food     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  host_place_type    VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  host_name          VARCHAR(160)  NULL,
  host_contact       VARCHAR(160)  NULL,
  host_phone         VARCHAR(40)   NULL,
  host_website       VARCHAR(255)  NULL,
  place_key          VARCHAR(20)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  host_point_id      VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  google_place_id    VARCHAR(255)  NULL,
  fee_flat_cents     INT UNSIGNED  NOT NULL DEFAULT 0,
  fee_pct            DOUBLE        NOT NULL DEFAULT 0,
  fee_min_cents      INT UNSIGNED  NOT NULL DEFAULT 0,
  allowed_json       JSON          NULL,
  vectors_bin        VARBINARY(1200) NULL,
  vec_in_region      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  vec_points_used    INT UNSIGNED  NOT NULL DEFAULT 0,
  vec_excluded       DOUBLE        NOT NULL DEFAULT 0,
  vec_region_id      VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  vec_dataset        VARCHAR(48)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  vec_seeds_rev      INT UNSIGNED  NULL,
  vec_at             DATETIME      NULL,
  archived_at        DATETIME      NULL,
  created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tpsp_truck (organization_id, truck_id, archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tp_plans (
  id                 CHAR(36)      PRIMARY KEY,
  organization_id    CHAR(36)      NOT NULL,
  truck_id           CHAR(36)      NOT NULL,
  created_by         CHAR(36)      NULL,
  service_date       DATE          NOT NULL,
  name               VARCHAR(120)  NOT NULL DEFAULT '',
  treat_as           VARCHAR(8)    NULL,
  notes              TEXT          NULL,
  plan_state         VARCHAR(12)   NOT NULL DEFAULT 'draft',
  result_json        MEDIUMTEXT    NULL,
  context_json       MEDIUMTEXT    NULL,
  result_has_google  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  evaluated_at       DATETIME      NULL,
  model_version      VARCHAR(24)   NULL,
  seeds_revision     INT UNSIGNED  NULL,
  dataset_version    VARCHAR(48)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tppl_truck_date (truck_id, service_date),
  KEY idx_tppl_org_date (organization_id, service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tp_plan_stops (
  id                   CHAR(36)     PRIMARY KEY,
  organization_id      CHAR(36)     NOT NULL,
  plan_id              CHAR(36)     NOT NULL,
  seq                  SMALLINT UNSIGNED NOT NULL,
  stop_kind            VARCHAR(10)  NOT NULL,
  spot_id              CHAR(36)     NULL,
  label                VARCHAR(120) NOT NULL DEFAULT '',
  lat                  DOUBLE       NULL,
  lng                  DOUBLE       NULL,
  address              VARCHAR(255) NOT NULL DEFAULT '',
  open_minute          SMALLINT UNSIGNED NOT NULL,
  close_minute         SMALLINT UNSIGNED NOT NULL,
  gap_before_unpaid    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  setup_minutes        SMALLINT UNSIGNED NULL,
  teardown_minutes     SMALLINT UNSIGNED NULL,
  fee_flat_cents       INT UNSIGNED NOT NULL DEFAULT 0,
  fee_pct              DOUBLE       NOT NULL DEFAULT 0,
  fee_min_cents        INT UNSIGNED NOT NULL DEFAULT 0,
  ev_attendance        DOUBLE       NULL,
  ev_vendor_count      SMALLINT UNSIGNED NULL,
  ev_type              VARCHAR(16)  NULL,
  cat_headcount        DOUBLE       NULL,
  cat_price_head_cents INT UNSIGNED NULL,
  cat_guarantee_cents  INT UNSIGNED NULL,
  cat_food_cost_cents  INT UNSIGNED NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tpps_plan_seq (plan_id, seq),
  KEY idx_tpps_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tp_service_logs (
  id                 CHAR(36)     PRIMARY KEY,
  organization_id    CHAR(36)     NOT NULL,
  truck_id           CHAR(36)     NOT NULL,
  created_by         CHAR(36)     NULL,
  log_kind           VARCHAR(10)  NOT NULL DEFAULT 'spot',
  spot_id            CHAR(36)     NULL,
  plan_id            CHAR(36)     NULL,
  plan_stop_id       CHAR(36)     NULL,
  service_date       DATE         NOT NULL,
  open_minute        SMALLINT UNSIGNED NOT NULL,
  close_minute       SMALLINT UNSIGNED NOT NULL,
  actual_orders      INT UNSIGNED NOT NULL,
  sales_cents        INT UNSIGNED NULL,
  sold_out           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  notes              TEXT         NULL,
  src                VARCHAR(16)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'manual',
  external_key       VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NULL,
  treat_as           VARCHAR(8)   NULL,
  weather_json       MEDIUMTEXT   NULL,
  predicted_raw      DOUBLE       NULL,
  pred_raw_basis     CHAR(40)     CHARACTER SET ascii COLLATE ascii_bin NULL,
  predicted          DOUBLE       NULL,
  pred_low           DOUBLE       NULL,
  pred_high          DOUBLE       NULL,
  pred_confidence    VARCHAR(12)  NULL,
  pred_basis         VARCHAR(8)   NULL,
  prediction_json    MEDIUMTEXT   NULL,
  pred_model_version VARCHAR(24)  NULL,
  pred_seeds_rev     INT UNSIGNED NULL,
  pred_dataset       VARCHAR(48)  CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tpsv_ext (organization_id, src, external_key),
  KEY idx_tpsv_truck_date (organization_id, truck_id, service_date),
  KEY idx_tpsv_spot (organization_id, spot_id, service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shared cache of Google legs. Rows older than 30 days are never served. They are deleted by the routing
-- service at the end of each call and by the daily purge script.
-- A straight-line estimate is never written here.
CREATE TABLE IF NOT EXISTS tp_drive_legs (
  o_lat_e4      INT          NOT NULL,
  o_lng_e4      INT          NOT NULL,
  d_lat_e4      INT          NOT NULL,
  d_lng_e4      INT          NOT NULL,
  route_key     VARCHAR(8)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  src           VARCHAR(24)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  route_found   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  duration_s    INT UNSIGNED NOT NULL DEFAULT 0,
  distance_m    INT UNSIGNED NOT NULL DEFAULT 0,
  toll_state    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  toll_cents    INT UNSIGNED NULL,
  fetched_at    DATETIME     NOT NULL,
  PRIMARY KEY (o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4, route_key),
  KEY idx_tpdl_fetched (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tp_drive_overrides (
  id               CHAR(36)     PRIMARY KEY,
  organization_id  CHAR(36)     NOT NULL,
  truck_id         CHAR(36)     NOT NULL,
  o_lat_e4         INT          NOT NULL,
  o_lng_e4         INT          NOT NULL,
  d_lat_e4         INT          NOT NULL,
  d_lng_e4         INT          NOT NULL,
  override_minutes SMALLINT UNSIGNED NULL,
  toll_cents       INT UNSIGNED NULL,
  note             VARCHAR(160) NOT NULL DEFAULT '',
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tpdo_leg (truck_id, o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4),
  KEY idx_tpdo_org (organization_id, truck_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- g_* columns hold Google Places content and are emptied 30 days after g_fetched_at. google_place_id may stay.
CREATE TABLE IF NOT EXISTS tp_scout_leads (
  id               CHAR(36)     PRIMARY KEY,
  organization_id  CHAR(36)     NOT NULL,
  truck_id         CHAR(36)     NOT NULL,
  region_id        VARCHAR(24)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  place_key        VARCHAR(20)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  place_name       VARCHAR(160) NULL,
  place_type       VARCHAR(24)  CHARACTER SET ascii COLLATE ascii_bin NULL,
  lat              DOUBLE       NULL,
  lng              DOUBLE       NULL,
  lead_state       VARCHAR(12)  NOT NULL DEFAULT 'new',
  notes            TEXT         NULL,
  spot_id          CHAR(36)     NULL,
  google_place_id  VARCHAR(255) NULL,
  g_lookup_state   VARCHAR(12)  NULL,
  g_name           VARCHAR(160) NULL,
  g_address        VARCHAR(255) NULL,
  g_phone          VARCHAR(40)  NULL,
  g_website        VARCHAR(255) NULL,
  g_maps_uri       VARCHAR(255) NULL,
  g_fetched_at     DATETIME     NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tpsc_place (truck_id, region_id, place_key),
  KEY idx_tpsc_org (organization_id, truck_id, lead_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shared token buckets (table from migration 031). Tokens are matrix elements for the first, calls for the second.
INSERT INTO places_rate_buckets (bucket, capacity, fill_rate_per_sec, tokens_available, last_refill_at)
VALUES
  ('tp_routes_elements', 1250, 40.000, 1250, CURRENT_TIMESTAMP(3)),
  ('tp_places_lookup',      5,  0.500,    5, CURRENT_TIMESTAMP(3))
ON DUPLICATE KEY UPDATE bucket = bucket;
```

### 1.3 Deliberate choices

The profile columns of `tp_trucks` have no SQL defaults: the seed group `profile_defaults` is the only source of defaults and `TruckRepository::create` writes every column. `uk_tptr_org` enforces one truck per organization in version 1; a second truck later means dropping that index, not reshaping tables. `uk_tppl_truck_date` gives one plan per truck and service date, which is what the screens work with (05_FRONTEND.md reads and saves "the plan for a date"). Spots are soft-deleted (`archived_at`) because plans and service logs refer to them. Plan stops are replaced as a set on every plan update. `overrides_json` is a `MEDIUMTEXT` column holding JSON text, not a `JSON` column: the owner's overrides are inputs of the model in the browser and on the server and must read back as they were saved (section 0, numbers in JSON columns).

### 1.4 Column mapping

**Vector codec** (`VectorCodec`, used for `tp_spots.vectors_bin` and `tp_places.host_vec`): one block is 50 little-endian doubles in the order `capture.day[0..15]`, `capture.eve[0..15]`, `nearby[0..15]`, `rivals.day`, `rivals.eve` (400 bytes). `host_vec` is one block (visibility `normal`). `vectors_bin` is three blocks in the order `hidden`, `normal`, `prominent` (1,200 bytes), because capture is not linear in visibility and the spot card switches levels without a round trip. `vectors_bin` is written with `UNHEX(?)` and `bin2hex(pack('e*', ...$values))` (ASCII, so no binary travels through a string bind); `UNHEX(?)` is used for `tp_spots.vectors_bin` only. `host_vec` is written by the loader as 03_DATA.md section 10 step 8a says: `pack('e50', ...$v)` bound as `PDO::PARAM_LOB` (`RegionLoadRepository::setHostVec`). Read, for both columns: select the column as it is and `array_values(unpack('e' . $n, $bytes))`. The round trip is bit-exact [R, checked on PHP 8.2].

**`tp_trucks` <-> `TruckRecord` / `TruckProfileX` (4.1)**

| Column(s) | API field | Conversion |
|---|---|---|
| `id`, `timezone`, `base_state`, `base_county_fips`, `created_at`, `updated_at` | `TruckRecord.*` | as stored. `base_state` and `base_county_fips` are derived by the server (5.1 `locate`) |
| `name`, `region_id`, `fuel_type` | `profile.*` | text |
| `base_lat`, `base_lng`, `base_address` | `profile.base.lat`, `.lng`, `.address` | `Sql::f` |
| `avg_ticket_cents`, `wage_cents`, `packaging_cents`, `card_fee_fixed_cents`, `fixed_cost_day_cents` | `avg_ticket`, `wage_per_hour`, `packaging_per_order`, `card_fee_fixed`, `fixed_cost_per_service_day` | cents |
| `fuel_price_override_milli` | `fuel_price_override` | milli, `NULL` <-> `null` |
| `capacity_orders_per_hour`, `payroll_burden_pct`, `food_cost_pct`, `card_fee_pct`, `card_share`, `mpg`, `generator_gal_per_hour`, `truck_time_factor` | same names | DOUBLE |
| `tips_pct` | `tips_pct_of_card_sales` | DOUBLE |
| `paid_crew`, `prep_minutes`, `setup_minutes`, `teardown_minutes`, `closeout_minutes`, `scout_drive_minutes_limit` | same names | int |
| `tips_include`, `avoid_tolls`, `avoid_highways` | same names | 0/1 <-> bool |
| `fit_breakfast`, `fit_lunch`, `fit_dinner`, `fit_late` | `daypart_fit.breakfast`, `.lunch`, `.dinner`, `.late` | DOUBLE |
| `licence_counties_json` | `licence_counties` | JSON array of 5-digit county FIPS strings. `[]` = no county filter |
| `overrides_json`, `overrides_seeds_rev` | `Assumptions.overrides`, revision they were validated against | JSON object as text (`MEDIUMTEXT`), `{}` when empty. Every number reads back as the double that was saved |

**`tp_spots` <-> `Spot`**

| Column(s) | API field | Conversion |
|---|---|---|
| `name`, `address`, `notes`, `county_fips` | same | text. `county_fips` is derived by the server (5.1 `locate`) |
| `lat`, `lng` | `point.lat`, `point.lng` | `Sql::f` |
| `visibility`, `fee_flat_cents`, `fee_pct`, `fee_min_cents`, `allowed_json` | `terms.visibility`, `terms.fee_flat`, `terms.fee_pct`, `terms.fee_min`, `terms.allowed` | cents for the two amounts. `allowed_json` is `{days:[7 bool], open_minute, close_minute}` or `NULL` |
| `host_segment`, `host_size`, `host_size_source`, `host_only_food`, `host_point_id`, `host_place_type` | `terms.host.segment`, `.size`, `.size_source`, `.only_food`, `.point_id`, `.place_type` | `terms.host` is `null` when `host_segment` is `NULL` |
| `host_name`, `host_contact`, `host_phone`, `host_website`, `place_key`, `google_place_id` | `host_details.*` | owner-typed or OpenStreetMap values only. Google contact fields are never copied here (5.4). `host_details.place_type` is `host_place_type` again, so a linked place shows its type also when the spot has no host. `host_details` is `null` when all seven are `NULL` |
| `vectors_bin` | `vectors.hidden`, `vectors.normal`, `vectors.prominent`: each `.capture`, `.nearby`, `.rivals` | codec above. The model is given `vectors[terms.visibility]`. `.within` is `null`: it is not among the 50 stored numbers. `vectors` is `null` when `vectors_bin` is `NULL` |
| `vec_in_region`, `vec_points_used`, `vec_excluded`, `vec_region_id`, `vec_dataset` | in each of the three: `.in_region`, `.points_used`, `.excluded_amount`, `.region_id`, `.dataset_version` | `.visibility` = the level, `.exclusion` = `Estimator::hostExclusion(A, host)`, `.model_version` = current. `vec_region_id` is the truck's region when the vectors were computed (`none` included) and `vec_dataset` the dataset version capture read, `NULL` when it read none; `.region_id` is `vec_region_id` when `vec_dataset` is set and `null` otherwise, as capture labels its answer |
| `vec_dataset`, `vec_seeds_rev`, `vec_region_id` | `vectors_state` | `fresh` when all three equal the truck's region, its active version (`NULL` for region `none` and for a region without an active `ready` version) and the current seeds revision. `none` when `vectors_bin` is `NULL`. Else `stale` |
| `archived_at` | `archived` | bool |

**`tp_plans`, `tp_plan_stops` <-> `Plan`, `PlanStop`, `StopInput`**

| Column(s) | API field | Conversion |
|---|---|---|
| `service_date`, `name`, `treat_as`, `notes`, `plan_state` | `date`, `name`, `treat_as`, `notes`, `status` | `plan_state` one of `draft`, `planned`, `done`, `cancelled` (the column avoids the bare word; the API field is `status`) |
| `result_json`, `context_json`, `evaluated_at`, `result_has_google`, `model_version`, `seeds_revision`, `dataset_version` | `result` (`DayResult`), `context` (4.1 `EvalContext`), `evaluated_at`, `result_state` | model shapes verbatim, dollars. `result_state` rule in 5.8 |
| `seq`, `stop_kind`, `spot_id`, `label`, `address` | array position, `kind`, `spot_id`, `label`, `address` | |
| `lat`, `lng` | `point` | `NULL` for spot stops (the point is the spot's) |
| `open_minute`, `close_minute`, `gap_before_unpaid`, `setup_minutes`, `teardown_minutes` | same names | |
| `fee_flat_cents`, `fee_pct`, `fee_min_cents` | `fee_flat`, `fee_pct`, `fee_min` (event stops) | cents. Spot stops take their terms from the spot |
| `ev_attendance`, `ev_vendor_count`, `ev_type` | `event.attendance`, `event.vendors`, `event.event_type` | |
| `cat_headcount`, `cat_price_head_cents`, `cat_guarantee_cents`, `cat_food_cost_cents` | `catering.headcount`, `.price_per_head`, `.guarantee`, `.food_cost` | cents, `NULL` <-> `null` |

**`tp_service_logs` <-> `ServiceLog`, `ServiceLogEntry`**

| Column(s) | API field | `ServiceLogEntry` field |
|---|---|---|
| `id`, `log_kind`, `spot_id`, `service_date`, `open_minute`, `close_minute` | `id`, `kind`, `spot_id`, `date`, `open_minute`, `close_minute` | `service_id`, `kind`, `spot_id`, `date`, `open_minute`, `close_minute` |
| `actual_orders`, `sold_out` | `actual` (int), `sold_out` | `actual` (cast to float), `sold_out` |
| `sales_cents`, `notes`, `src`, `external_key`, `plan_id`, `plan_stop_id`, `treat_as` | `sales` (dollars), `notes`, `source`, `external_key`, `plan_id`, `plan_stop_id`, `treat_as` | not used by the model |
| `predicted_raw`, `predicted`, `pred_low`, `pred_high`, `pred_confidence`, `pred_basis` | `prediction.predicted_raw`, `.predicted`, `.low`, `.high`, `.confidence`, `.basis` | `predicted_raw`, `predicted`, `low`, `high` |
| `prediction_json`, `weather_json`, `pred_raw_basis`, `pred_model_version`, `pred_seeds_rev`, `pred_dataset` | `prediction.detail`, internal | 5.7 |

**`tp_drive_legs`, `tp_drive_overrides` <-> `DriveLeg`, `LegInput`**

| Column(s) | Meaning |
|---|---|
| `o_lat_e4`, `o_lng_e4`, `d_lat_e4`, `d_lng_e4` | `LegKey::e4($deg) = (int) Estimator::roundHalfAway($deg * 10000.0, 0)` for origin and destination (4 decimals, about 11 m). The request to Google uses `e4 / 10000.0` |
| `route_key` | `d` plus `t` when `avoid_tolls` plus `h` when `avoid_highways`: `d`, `dt`, `dh`, `dth` |
| `src` | `google_routes` or `google_distance_matrix` -> `DriveLeg.source` |
| `route_found` | 0 when Google answered "no route". The leg is then served as a straight line with reason `route_not_found` |
| `duration_s`, `distance_m` | `LegInput.duration_s`, `LegInput.distance_m` (cast to float) with `source: "google"` |
| `toll_state`, `toll_cents` | 0 `not_asked`, 1 `none` (asked, no toll on the route), 2 `estimate` (`toll_cents` set), 3 `unknown` (tolls on the route, no US dollar price) -> `DriveLeg.toll_state`, `DriveLeg.google_toll` |
| `fetched_at` | `DriveLeg.fetched_on` = `DATE(fetched_at)`, `age_days` = `TIMESTAMPDIFF(DAY, fetched_at, NOW())` |
| `tp_drive_overrides.override_minutes`, `.toll_cents`, `.note` | `DriveLeg.override.minutes`, `.toll`, `.note` -> `LegInput.override_minutes`, and `LegInput.toll` when set |

**`tp_scout_leads` <-> `Lead`**: `lead_state` -> `status` (one of `new`, `shortlisted`, `contacted`, `booked`, `declined`, `hidden`), `notes`, `spot_id`, `place_key`, `place_name` / `place_type` / `lat` / `lng` (a snapshot so the lead survives a dataset switch), `google_place_id` and `g_*` -> `google` (null when `g_fetched_at` is `NULL` or older than 30 days).

## 2. Class inventory

### 2.1 `App\TruckPlanner\Model` (fixed; this is how the rest of the backend calls it)

Pure: no `Database`, `Config`, clock, network, randomness, locale or process time zone. `Estimator` is `final` with only `public static` methods, one per function of 02_MODEL.md, named in camelCase, arguments in the documented order, arrays in the document's snake_case shapes. Two more serve the golden cases: `dispatch(string $function, array $args): mixed` runs a function by its canonical name with named arguments, and `functions(): array` lists those names.

| 02_MODEL | `Estimator::` methods as called from Data, Services and scripts |
|---|---|
| 1.4, 2.1, 2.2, 3 | `roundHalfAway(float $x, int $decimals): float`, `qkey(float $x): int`, `seed(array $A, string $path): mixed`, `validateOverrides(array $seeds, array $overrides): array`, `estFixed`, `estLevels`, `weakest`, `estSum` |
| 4.1 | `daysFromCivil(int $y, int $m, int $d): int`, `civilFromDays(int $z): array`, `parseDate(string $s): array`, `formatDate`, `dayOfWeek(string $date): int`, `addDays(string $date, int $n): string`, `nthWeekday`, `lastWeekday`, `federalHolidays(int $year, array $flags): array`, `holidayOn(string $date, array $flags): ?array`, `dayContext(array $A, string $date, ?string $treatAs, ?array $forecast, ?float $fuelPricePerGal, ?string $fuelPriceSource): array`, `typicalContext(array $A, int $dow): array` |
| 4.2, 4.3 | `hourWeights`, `expandCurves`, `haversineM(float $lat1, float $lng1, float $lat2, float $lng2): float`, `walkWeight(array $A, float $d): float` |
| 4.4, 4.5 | `rivalsAtOrigin(array $A, float $lat, float $lng, array $outlets): array`, `hostExclusion(array $A, ?array $host): array`, `hostLinkPoint(array $A, float $lat, float $lng, array $host, array $sources): ?string`, `captureAtPoint(array $A, float $lat, float $lng, string $visibility, array $sources, array $outlets, array $exclusion): array`, `hostCapture(array $A, ?array $host, string $visibility, array $rivalsHere): array` |
| 4.6, 4.7, 4.8 | `weatherMultiplier`, `calibrationFactor`, `hourlyOrders`, `vectorsMatch(array $A, array $terms, array $vectors): bool`, `windowOrders(array $A, array $profile, array $terms, array $vectors, ?array $cal, array $ctx, ?array $ctxNext, int $open, int $close): array`, `weekStrip(array $A, array $profile, array $terms, array $vectors, ?array $cal): array`, `bestWindows(array $values, int $length, int $topN, bool $circular, ?array $allowed = null): array`, `evidenceFrom`, `interval`, `intervalCapped(array $A, array $d, array $c, array $ev): array` |
| 4.9, 4.10, 4.11, 4.12 | `stopMoney`, `unitMargins(array $profile, array $terms): array`, `breakEvenOrders(array $profile, array $terms, float $fixedCosts): ?float`, `dayCosts`, `fallbackLeg(array $A, float $lat1, float $lng1, float $lat2, float $lng2): array`, `trafficFactor`, `legMinutes`, `requiredLegKeys(array $stops): array`, `buildTimeline`, `dayPlan(array $A, array $profile, array $plan, array $ctx, ?array $ctxNext, array $legs, ?array $cal): array` |
| 4.13, 4.14 | `calibrate(array $A, array $services, string $asOf): array`, `accuracyReport(array $entries): array`, `eventOrders`, `cateringMoney` |
| 4.15, 4.16, 4.17 | `suggestDay(array $A, array $profile, array $ctx, ?array $ctxNext, array $spots, array $legs, ?array $cal, ?array $options = null): array`, `suggestWeek(array $A, array $profile, string $weekStart, array $contexts, array $spots, array $legs, ?array $cal, ?array $options = null): array`, `scoutEstimate(array $A, array $profile, array $place, array $legs, ?array $cal, float $fuelPricePerGal): ?array`, `stripFromRows(array $A, array $profile, array $terms, array $vectors, array $rows): array`, `scoutRank(array $results): array`, `mapWeightRows(array $A, array $profile, ?array $cal): array`, `cellScores`, `scoreByte` |

Calling rules. (1) Only Services and scripts call the model, plus `Money` and `LegKey` for `roundHalfAway`. Controllers never do. (2) Every number passed in is a PHP `float` where the shape says `number` (cast after `json_decode` and after every database read) and an `int` where it says `int`. (3) Model errors surface as `App\TruckPlanner\Model\ModelError`, an `\InvalidArgumentException` whose message is the code (`errorCode()` returns it; `details()` holds what the code alone does not say): `invalid_date`, `invalid_window` and `missing_context` as 02_MODEL.md defines them, `non_finite` when a result would hold INF or NAN (every `Estimator` method checks what it returns), and `invalid_overrides` from `Seeds::withOverrides` only. A malformed argument is a programming error (02_MODEL.md section 7) and never becomes a silent zero or infinity: a missing field, `null` or a numeric string where a number is required and a float where an `int` is required surface as `\TypeError`; an unknown segment, label or seed path as `\OutOfBoundsException`; an hour outside 0..23 as `\OutOfRangeException`; a zero divisor (`profile.mpg`, the `hi` of `scoreByte`) as `\DivisionByZeroError`. Inputs are validated before the call (4.2), so any of these reaching a controller is a server defect: log it, answer 500. (4) `A` (the `Assumptions` shape) for a truck is built only by `AssumptionsFactory` (2.4). Code that reads only build- or fixed-scope seeds (capture, the loader, fallback legs) uses `Seeds::defaults()`.

**Seeds in PHP.** `SeedsData.php` is `<?php return [ ... ];`, the parsed content of `docs/truck-planner/reference/tp_seeds.json`, generated by `python docs/truck-planner/reference/generate_seed_copies.py` (the generator of 02_MODEL.md 2.3; it also writes the TypeScript copy, and `--check` verifies both) and committed. No JSON file is read at run time. `tests/TruckPlanner/Model/SeedSyncTest.php` fails when the parsed JSON and the PHP array differ (the same keys in the same order, lists of the same length, an integer stays an integer and a real a real). Interface of `Seeds`: `data(): array` is that array, loaded once per process; `defaults(): array` returns an `Assumptions` value with `overrides: []` and region `none`; `assumptions(array $overrides = [], ?array $region = null): array` returns one with the given overrides and region, unvalidated (for a map that was validated when it was saved); `withOverrides(array $overrides, ?array $region = null): array` validates first and, instead of returning, throws `ModelError` `invalid_overrides` whose `details()` are the `[{path, error}]` list of `Estimator::validateOverrides`; `revision(): int` is `seeds_revision`.

### 2.2 `App\TruckPlanner\Data` (literal SQL, positional `?`, no business rules)

Every repository takes `?Database $db = null` in its constructor and resolves `Database::getInstance()` lazily on first use, so tests pass a recording double (8.1). Repositories use only `fetch`, `fetchAll`, `query` (its return value is never used), `beginTransaction`, `commit` and `rollback`: not `insert()`, `update()`, `delete()` or `pdo()`. Affected-row counts are not relied on [R: `rowCount()` counts changed rows]: existence is checked with a read first, and deletion counts come from a `SELECT COUNT(*)` in the same transaction. The one exception is `RegionLoadRepository` (loader only), which uses `pdo()` for the pack blob and the host vectors (`PDO::PARAM_LOB`) and for batch counts. Every statement on an owner table carries `organization_id = ?`. Reads return normalised arrays (floats cast, cents converted, JSON decoded, 0/1 -> bool). A converted column is returned, and accepted by the write methods, under its name without the storage suffix and in API units: `avg_ticket_cents` is `avg_ticket` in dollars, `fuel_price_override_milli` is `fuel_price_override`, `overrides_json` is `overrides`. Where 03_DATA.md asks for `PDO::FETCH_NUM`, the repository returns positional rows in the stated column order (`array_values` of each `fetchAll` row: the same data). `SELECT *` is not used. Query numbers: Q0 to Q5 are the statements of 03_DATA.md 9.3 and 6.2; Q6, Q7 and Q8 are this document's own three (5.1).

| Class | Responsibility | Public methods |
|---|---|---|
| `Sql` (static) | SQL value helpers | `f(float $x): string`, `b(bool $v): int`, `json(array $v, bool $asObject = false): string`, `marks(int $n): string` |
| `VectorCodec` (static) | 1.4 codec | `flat(array $vectors): array` (50 doubles of one `LocationVectors`), `fromFlat(array $fifty): array` (`capture`, `nearby`, `rivals`), `toHex(array $doubles): string`, `fromBytes(string $bytes): array` (list of doubles, length = bytes / 8) |
| `LegKey` (static) | rounding for leg keys | `e4(float $deg): int`, `of(float $lat, float $lng): array` (the point key `[lat_e4, lng_e4]`), `deg(int $e4): float`, `routeKey(array $profile): string` |
| `ApiLedger` | one row in `api_cost_events` per upstream call; never throws | `record(string $sku, int $units, ?int $httpStatus, int $latencyMs, ?string $errorCode, ?string $fieldMask = null): void`, `unitsToday(array $skus): int` (billable units of those SKUs since the start of the database server's day; the largest integer when the ledger cannot be read, so the caller sees a spent budget), `static maskHash(?string $fieldMask): ?string` |
| `TruckRepository` | `tp_trucks`. `COLUMNS` lists the normalised keys a caller may set | `findByOrg(string $orgId): ?array` (the normalised row: the keys of `COLUMNS` plus `id`, `organization_id`, `created_by`, `overrides`, `overrides_seeds_rev`, `created_at`, `updated_at`), `create(string $orgId, ?string $userId, array $row): string` (every key of `COLUMNS`; `base_state`, `base_county_fips` and `fuel_price_override` may be left out and are then NULL; optionally `overrides` and `overrides_seeds_rev`), `update(string $id, string $orgId, array $columns): void` (any subset of `COLUMNS`; another key is refused; `updated_at` is left to the column's `ON UPDATE` rule, so it moves only when a value changes), `setOverrides(string $id, string $orgId, array $overrides, int $seedsRevision): void` |
| `CountsRepository` | row counts on the 043 tables for bootstrap and the spot list (literal SQL; it belongs to the foundation package, so no wave-1 package waits for a later one) | `forOrg(string $orgId): array` (`{spots, plans, services, leads}`, spots not archived), `logsBySpot(string $orgId, string $truckId): array` (`spot_id => {count, last_date}`) |
| `RegionRepository` | read side of `tp_regions`, `tp_region_packs` | `all(): array` (ascending `region_id`), `find(string $regionId): ?array` (Q0 with the rest of the region row beside it; `config_json` decoded as `config`), `packMeta(string $regionId, string $version): ?array` (no blob and no manifest: the ledger columns, `kernel` = the `kernel` object of `kernel_json` and `kernel_seeds_revision` the revision beside it, both null while the row is loading, and `vintages` = the `vintages` object of the manifest, taken with `JSON_EXTRACT`), `packBlob(string $regionId, string $version): ?string`, `manifest(string $regionId, string $version): ?array` |
| `RegionLoadRepository` | loader writes (03_DATA.md 9.2, 10) | `upsertRegion(array $region, array $bounds, ?string $configJson = null): void` (`$configJson` = the region definition as JSON text for `config_json`; null encodes `$region`), `packRowState(string $regionId, string $version): ?string`, `insertPackRow(string $regionId, string $version, array $meta): void`, `deleteVersionBatch(string $table, string $regionId, string $version, int $limit = 5000): int` (`tp_points`, `tp_places` or `tp_region_packs`; answers the rows deleted), `insertPlaces(string $regionId, string $version, array $rows): void` and `insertPoints(string $regionId, string $version, array $rows): void` (statements of 500 rows, all of one call in one transaction: the loader passes 5,000 rows at a time; a double that arrives as text is bound as it is, a float through `Sql::f`), `setHostVec(string $regionId, string $version, string $placeKey, string $bytes): void` (`$bytes` = `pack('e50', ...$v)`, bound as `PDO::PARAM_LOB`), `transaction(callable $work): void` (the writes made inside `$work` in one transaction: the loader groups 500 `setHostVec` calls), `finishPack(string $regionId, string $version, array $counts, string $packGz, array $kernel, string $manifestJson): void` (`$kernel` = `{seeds_revision, kernel}`; `$manifestJson` = the text of `manifest.json`, stored as it is), `setState(string $regionId, string $version, string $state): void`, `activate(string $regionId, string $version): void` (one transaction; a version that is not `ready` is refused, the version that is already active changes nothing), `versions(string $regionId): array` (the ledger rows of a region without pack and manifest) |
| `PointRepository` | `tp_points` reads | `near(string $regionId, string $version, float $lat, float $lng, float $radiusM): array` (Q1, positional rows), `nearestBlock(string $regionId, string $version, float $lat, float $lng): array` (Q4 with its r = 2,400 m, twice `kernel.walk_cutoff_m`: the block rows of that box, positional, in `point_id` order; 5.1 step 3 picks the nearest), `static box(float $lat, float $lng, float $radiusM): array` (the box of 03_DATA.md 9.3 as `{lat_min, lat_max, lng_min, lng_max}`), `static boxParams(string $regionId, string $version, array $box): array` (the six values a box statement binds first, the doubles through `Sql::f`). Constants name the positions in a row: `ID`, `KIND`, `REF`, `LAT`, `LNG`, `RIVALS_DAY`, `RIVALS_EVE`, `BASE` (the 16 bases follow) for Q1, `BLOCK_ID`, `BLOCK_REF`, `BLOCK_IN_REGION` (1 or 0), `BLOCK_LAT`, `BLOCK_LNG` for Q4 |
| `PlaceRepository` | `tp_places` reads. `host_vec` is returned as its 400 bytes (`VectorCodec::fromBytes` reads them) | `rivalsNear(string $regionId, string $version, float $lat, float $lng, float $radiusM): array` (Q2), `hostsNear(string $regionId, string $version, float $lat, float $lng, float $radiusM): array` (Q3 with the first-page key `''`: one page), `hostVectorPage(string $regionId, string $version, array $box, array $countyFips, string $afterKey): array` (Q6; `$box` as `PointRepository::box()` returns it; a page holds `static pageRows(): int` rows, the setting `scout.page_rows`), `byKeys(string $regionId, string $version, array $keys): array` (Q7, any number of keys read 100 at a time; the answer is keyed by `place_key`, each row with named fields), `findHost(string $regionId, string $version, string $placeKey): ?array` (Q5, named fields), `exportPage(string $regionId, string $version, string $afterKey, int $limit = 1000): array` (Q8, for `export-places.php` only). Q2, Q3 and Q6 return positional rows; constants name the positions (`RIVAL_*`, `HOST_*`, `VEC_*`) |
| `SpotRepository` | `tp_spots`. `COLUMNS` lists the keys of the spot row a caller may set. A read row carries them plus `id`, `organization_id`, `truck_id`, `created_by`, `vectors_sha1` (`sha1(vectors_bin)`, null without vectors; 5.7), `vec_at`, `archived_at`, `created_at`, `updated_at`. `vectors` is `{hidden, normal, prominent}`, each the stored part of a `LocationVectors` (`capture`, `nearby`, `rivals`), or null. The seven vector keys (`VECTOR_KEYS`: `vectors`, `vec_in_region`, `vec_points_used`, `vec_excluded`, `vec_region_id`, `vec_dataset`, `vec_seeds_rev`) are written together or not at all, with `vec_at = NOW()` | `listActive(string $orgId, string $truckId, bool $withArchived = false): array` (ordered by `name`, then `id`), `find(string $id, string $orgId, bool $withArchived = false): ?array`, `findMany(array $ids, string $orgId): array` (rows keyed by id, archived ones included), `countActive(string $orgId, string $truckId): int`, `create(string $orgId, string $truckId, ?string $userId, array $columns): string` (`name`, `lat`, `lng` required; every column is written), `update(string $id, string $orgId, array $columns): void` (any subset of `COLUMNS`, one statement), `setVectors(string $id, string $orgId, array $vectors): void` (exactly the seven vector keys), `archive(string $id, string $orgId): void`, `staleIds(string $orgId, string $truckId, string $regionId, ?string $version, int $seedsRev, int $limit): array` (ascending id, archived spots left out: `vectors_bin IS NULL`, or one of `vec_region_id`, `vec_dataset`, `vec_seeds_rev` differs from the three given under the null-safe `<=>`) |
| `PlanRepository` | `tp_plans`, `tp_plan_stops` | `listRange(string $orgId, string $truckId, string $from, string $to, bool $withStops = false): array`, `find(string $id, string $orgId): ?array` (with stops and `snapshot_expired`), `findByDate(string $orgId, string $truckId, string $date): ?array`, `create(string $orgId, string $truckId, ?string $userId, array $columns, array $stops): string`, `update(string $id, string $orgId, array $columns): void`, `replaceStops(string $planId, string $orgId, array $stops): void`, `saveSnapshot(string $id, string $orgId, array $result, array $context, bool $hasGoogle, array $versions): void`, `delete(string $id, string $orgId): void` (transaction: detach logs, stops, plan), `purgeExpiredSnapshots(?string $orgId = null): int` (null = every organization) |
| `ServiceLogRepository` | `tp_service_logs` | `listRange(string $orgId, string $truckId, string $from, string $to, ?string $spotId = null): array`, `find(string $id, string $orgId): ?array`, `allForCalibration(string $orgId, string $truckId): array`, `existsSame(string $orgId, string $spotId, string $date, int $open, int $close, ?string $exceptId): bool`, `create(string $orgId, string $truckId, ?string $userId, array $columns): string`, `update(string $id, string $orgId, array $columns): void`, `setRawPrediction(string $id, string $orgId, float $raw, string $basis, array $versions): void`, `delete(string $id, string $orgId): void`, `lastChangeAt(string $orgId, string $truckId): ?string` |
| `DriveLegRepository` | `tp_drive_legs` | `findFresh(string $routeKey, array $pairKeys): array`, `upsertMany(array $rows): void`, `purgeExpired(int $limit = 0): int` (0 = no limit) |
| `DriveOverrideRepository` | `tp_drive_overrides` | `listForTruck(string $orgId, string $truckId): array`, `find(string $id, string $orgId): ?array`, `findForPairs(string $orgId, string $truckId, array $pairKeys): array`, `upsert(string $orgId, string $truckId, array $fromKey, array $toKey, ?int $minutes, ?int $tollCents, string $note): string`, `delete(string $id, string $orgId): void`, `movePoint(string $orgId, string $truckId, array $oldKey, array $newKey): void` |
| `ScoutLeadRepository` | `tp_scout_leads` | `forTruck(string $orgId, string $truckId, string $regionId): array` (keyed by `place_key`), `find(string $orgId, string $truckId, string $regionId, string $placeKey): ?array`, `upsert(string $orgId, string $truckId, string $regionId, string $placeKey, array $columns): string`, `setGoogle(string $id, string $orgId, array $google): void`, `setSpot(string $id, string $orgId, ?string $spotId): void`, `purgeExpiredGoogle(?string $orgId = null): int` |
| `FuelPriceRepository` | `tp_fuel_prices` | `latest(string $duoarea, string $product): ?array`, `newestPeriod(): ?string`, `upsertMany(array $rows): int` |
| `TruckDataRepository` | export paging and deletion | `page(string $table, string $orgId, string $afterId, int $limit): array`, `deleteAll(string $orgId): array` (one transaction, order: `tp_service_logs`, `tp_plan_stops`, `tp_plans`, `tp_scout_leads`, `tp_drive_overrides`, `tp_spots`, `tp_trucks`) |

### 2.3 `App\TruckPlanner\Services` - support, contracts, transport

| Class | Responsibility | Public methods |
|---|---|---|
| `Support\Clock` | the only wall-clock reader and the only user of `DateTimeImmutable`; injectable | `nowUtc(): \DateTimeImmutable`, `epoch(): int` (UTC seconds), `today(string $tz): string`, `minuteOfDay(string $tz): int`, `localOfInstant(string $iso, string $tz): ?array` (`{date, minute}`: the civil date and minute of day of an ISO 8601 instant in that zone; null when it does not parse), `parseHttpDate(string $s): ?int` (the epoch of an HTTP date in the form `D, d M Y H:i:s \G\M\T`, UTC; null when empty or malformed), `static isZone(string $name): bool` (an IANA name of `DateTimeZone::listIdentifiers()`) |
| `Support\Money` (static) | cents and milli conversion | `toCents(float $dollars): int`, `fromCents(int $c): float`, `toMilli(float $d): int`, `fromMilli(int $m): float` |
| `Support\Input` | request validation with the fixed messages of 4.2. One `Input` wraps one JSON object and knows its dotted path. A getter returns the validated value, or null when the key is absent or holds JSON null; with `$required` those two cases are V1. For a field that may be set to null in an update, `has()` tells "sent as null" from "not sent". Keys are strings, or indexes inside `each()` | `__construct(array $data, string $prefix = '')`, `static query(array $query): Input` (query parameters: whole numbers from digits, flags from `1` and `0`), `has(string $k): bool`, `isNull(string $k): bool`, `all(): array`, `path(string $k): string`, `str(string $k, int $max, bool $required = false): ?string` (trimmed), `num(string $k, float $min, float $max, bool $required = false): ?float`, `int(string $k, int $min, int $max, bool $required = false): ?int`, `bool(string $k, bool $required = false): ?bool`, `enum(string $k, array $allowed, bool $required = false): ?string`, `date(string $k, bool $required = false): ?string`, `point(string $k, bool $required = false): ?array`, `obj(string $k, bool $required = false): ?Input`, `items(string $k, int $min, int $max, bool $required = false): ?array` (the list as received), `each(string $k): Input` (the list at `$k` as an `Input` whose keys are the indexes and whose paths print as `stops[1]`: `$in->each('stops')->obj(1, true)->int('open_minute', 0, 2880, true)`), `error(string $k, string $rest, ?string $code = null): TpInvalid` (a 422 in the house form `<path> <rest>`, for the messages of section 4 that are not V1 to V10), `notFound(string $k): TpInvalid` (V11), `static nothingToUpdate(): TpInvalid` and `requireAny(array $keys): void` (V12) |
| `Support\JsonSafe` (static) | response hygiene (section 6, rules 5-7) | `clean(array $data, array $mapPaths = []): array`, `canonical(array $data): string` (keys sorted byte-wise at every level; `66` and `66.0` give the same text; used for hashes), `float(float $x): string` (the shortest text that reads back as the same double; it is what `Sql::f` binds and what V2 prints), `shortestFloats(): void` (sets `serialize_precision` to -1 for the process) |
| `Support\Redactor` (static) | secrets never reach logs | `url(string $url): string` (scheme, host, path only), `text(string $s): string` (removes `key=`, `api_key=`, `token=` values and `AIza...` strings, cuts to 200 characters) |
| `Support\MapsUrl` (static) | free Google Maps links (no API call); the three formats are fixed below the table | `point(float $lat, float $lng): string`, `place(string $name, string $googlePlaceId): string`, `route(array $points): string` |
| `Support\TpCache` (static) | the only Truck Planner class that calls `CacheService` (DECISIONS 2 keeps TTL caches there); every `tp:` cache key of this document goes through it. The age of an entry is decided by the UTC epoch from `Clock`, never by the store's `expires_at` [R: `CacheService::set` stamps `expires_at` with PHP's clock, `get` compares it with MySQL `NOW()`, and there is no increment] | `put(string $key, array $value, int $ttlSeconds): void` stores the envelope `{"exp": Clock::epoch() + $ttlSeconds, "v": $value}`, encoded by `TpCache` itself, with `CacheService::set($key, <json>, $ttlSeconds + 93600)` (the 26-hour pad keeps the row alive under any zone offset; `exp` decides); `get(string $key): ?array` returns `v` only when the entry exists and `exp > Clock::epoch()`; `add(string $key, int $n, int $ttlSeconds): int` is get, add, put and returns the new total (not atomic: the per-organization daily element budget is approximate under concurrent requests; the ledger-based global budget and the console quota of 8.5 step 1 are the bounds); `count(string $key): int` reads such a counter without touching it; `forgetPrefix(string $prefix): void` = `CacheService::flush($prefix)`. A key starts with `tp:` and is at most 255 bytes long, and a prefix is longer than `tp:`: anything else is refused. A value that was stored as an empty array is a hit (`[]`), a miss is null. `wire(?Clock $clock = null, ?TpCacheStore $store = null): void` replaces the clock and the store in tests |
| `Support\TpCacheStore` (interface) | what `TpCache` needs from a store: `get(string $key): ?string`, `set(string $key, string $value, int $ttlSeconds): void`, `flush(string $prefix): void`. The adapter to `CacheService` lives inside `TpCache.php`; tests wire an in-memory store | |
| `Support\TpConfig` (static) | reads `config/truck_planner.php` (7.1) once per process | `all(): array`, `get(string $path): mixed` (dotted path such as `routing.timeout_s`; an unknown path is a programming error and throws), `replace(?array $config): void` (tests) |
| `Support\TpInvalid`, `TpNotFound`, `TpConflict`, `TpRateLimited`, `TpUnavailable` | exceptions mapped to 422, 404, 409, 429, 503. `TpInvalid extends \InvalidArgumentException` and carries `field` and `code` for `details`. Every validation failure in Truck Planner code is a `TpInvalid`; a bare `\InvalidArgumentException` can only come from the model and is a defect (500). The other four extend `\RuntimeException` and carry the sentence of the answer. `TpRateLimited` is for a service that finds a shared upstream bucket empty (5.4); the per-user limits of section 3 are the middleware's | `TpInvalid::__construct(string $message, ?string $field = null, ?string $code = null)`, `field(): ?string`, `rule(): ?string` (the message id), `details(): ?array` (`{field, code}` without the absent parts, null when there is neither) |
| `Support\Registry` (static) | resolves the five contracts; lets packages land in any order. One object per contract and process | `capture(): CaptureProvider`, `legs(): LegProvider`, `dayContexts(): DayContextProvider`, `fuel(): FuelPriceProvider`, `calibration(): CalibrationProvider`, `set(string $contract, object $impl): void` (`$contract` is `capture`, `legs`, `dayContexts`, `fuel` or `calibration`; an object of the wrong kind is refused), `reset(): void` |
| `Contracts\CaptureProvider` | | `locate(string $regionId, float $lat, float $lng, ?string $version = null): array`, `capture(string $regionId, float $lat, float $lng, array $visibilities, ?array $host, ?string $version = null): array`, and the three readers of the host link rule (4.8, 5.1): `sources(string $regionId, float $lat, float $lng, ?string $version = null): array`, `place(string $regionId, string $placeKey, ?string $version = null): ?array`, `hostsNear(string $regionId, float $lat, float $lng, float $radiusM, ?string $version = null): array` |
| `Contracts\LegProvider` | | `legs(string $orgId, array $truck, array $points, array $pairs, array $options = []): array` (`$points` = `[{id, lat, lng}]`, `$pairs` = `[[from_id, to_id]]`, `$options` = `{tolls: bool = true, fetch: bool = true}`; the answer is a list of `DriveLeg` in pair order), `status(): array` (`{state}`), `movePoint(string $orgId, array $truck, array $oldPoint, array $newPoint): void` (re-keys the owner's corrections of a moved point, 4.8) |
| `Contracts\DayContextProvider` | | `contexts(array $truck, array $A, string $from, int $days, array $treatAs = []): array` returning `{days: [DayInfo], forecast: {state, generated_at: string?, point: {lat, lng}, source}, fuel: FuelInfo}`: one `DayInfo` per date from `$from`, each context built with `$treatAs[date]` or null |
| `Contracts\FuelPriceProvider` | | `resolve(array $truck): array` (`FuelInfo`) |
| `Contracts\CalibrationProvider` | | `state(string $orgId, array $truck, array $A, string $asOf): array` (`CalibrationState`) |
| `Fallback\NoCapture`, `StraightLineLegs`, `PlainDayContexts`, `SeedFuelPrice`, `IdentityCalibration` | used while the real class is not installed: zero vectors with `in_region: false` (the model's own answer for a point with no source in reach), no sources and no places; `Estimator::fallbackLeg` legs labelled `straight_line`, reason `no_key` (`same_point` for a pair with equal leg keys), and a `movePoint` that does nothing; holidays and the fuel price without forecast (state `unavailable`); the owner's own fuel price when set, else the seed price with the seed's date; `Estimator::calibrate($A, [], $asOf)` | implement the contracts. `StraightLineLegs::straightLine(string $fromId, string $toId, array $from, array $to, string $reason): array` and `StraightLineLegs::samePoint(string $fromId, string $toId): array` build those two kinds of `DriveLeg` for the routing service as well |
| `Http\OutboundHttp` | the only file under `src/` with `curl_*`. HTTPS only, default port, no credentials in the address, no redirects, host allow-list `routes.googleapis.com`, `maps.googleapis.com`, `places.googleapis.com`, `api.weather.gov`, `api.eia.gov` (`ALLOWED_HOSTS` constant). An answer above 8 MB is cut off and reported as `curl_<n>` | `request(string $method, string $url, array $headers, ?string $body, int $connectTimeoutS, int $timeoutS): array` returning `[int $status, string $body, int $latencyMs, ?string $errorCode, array $headers]`; `$headers` maps lower-cased response header names to their last value (`CURLOPT_HEADERFUNCTION`). `$status` is 0 when no HTTP answer arrived. Error codes: null for a 2xx answer, else `host_not_listed` (nothing was sent), `timeout`, `connect`, `curl_<n>`, `http_<status>` (the body is still returned). Never returns or logs `curl_error()` text or the URL. `static isAllowedUrl(string $url): bool` |
| `Http\UpstreamGuard` | key presence, refusal memory, back-off, budgets, token bucket (5.3). Refusal memory, back-off and the per-organization counter live in `TpCache`. `$api` is `routes`, `legacy` or `places`: each has its own refusal entry (`tp:routes:refused:routes`, `tp:routes:refused:legacy`, `tp:places:refused:places`), the two routing APIs share one back-off (`tp:routes:backoff`) and Places has its own (`tp:places:backoff`). `takeTokens($bucket, $tokens, $waitSeconds)` delegates to `(new \App\Services\PlacesRateLimiter())->acquire($bucket, $tokens, $waitSeconds)`, the existing atomic UPDATE on `places_rate_buckets` [R] | `__construct(?Clock $clock = null, ?ApiLedger $ledger = null, ?callable $bucket = null)` (`$bucket` replaces the token bucket in tests), `hasGoogleKey(): bool`, `refused(string $api): bool`, `markRefused(string $api): void`, `inBackoff(string $api): bool`, `backoffReason(string $api): ?string` (the reason stored with an active back-off, else null), `backoff(string $api, int $seconds, string $reason = 'upstream'): void` (`$reason` is `quota` or `upstream`), `takeTokens(string $bucket, int $tokens, int $waitSeconds): bool`, `orgElementsLeft(string $orgId): int`, `spendOrgElements(string $orgId, int $n): void`, `globalElementsLeft(): int` |

`Registry` resolution: an object set with `set()`, else the real class when `class_exists` (`CaptureService`, `RoutingService`, `DayContextService`, `FuelPriceService`, `CalibrationService`), else the fallback. Feature packages therefore only add files.

`MapsUrl` formats. Coordinates are printed with exactly six decimals after `Estimator::roundHalfAway($x, 6)`.

| Method | Link |
|---|---|
| `point(lat, lng)` | `https://www.google.com/maps/search/?api=1&query=<lat>%2C<lng>` |
| `place(name, id)` | `https://www.google.com/maps/search/?api=1&query=<rawurlencode(name)>&query_place_id=<id>` |
| `route(points)` with points = base, the stops in order, base | `https://www.google.com/maps/dir/?api=1&origin=<lat>%2C<lng>&destination=<lat>%2C<lng>&waypoints=<lat>%2C<lng>%7C<lat>%2C<lng>&travelmode=driving` |

In `route`, `origin` and `destination` are both the base and always present, so the link never asks for the device's position; `waypoints` holds the stops in order, joined by `%7C`. A plan without stops has no route link: `Plan.maps_route_url` is null.

### 2.4 `App\TruckPlanner\Services` - domain services (details in section 5)

| Class | Responsibility | Public methods |
|---|---|---|
| `AssumptionsFactory` | builds `A` | `forTruck(?array $truck, ?array $regionRow): array` (`$truck` is the truck value of 2.5, read for `overrides` and `overrides_seeds_rev`; `$regionRow` is a row of `RegionRepository::find`), `info(array $A): array` (`AssumptionsInfo`). `region.traffic_matrix` = `config_json.traffic_matrix`, or `us_mean` when the key is absent or names a matrix the seed file does not hold (03_DATA.md sections 1 and 10); it is never derived from the region id. Region `none`: `us_mean`. `region.flags.inauguration_day` = `config_json.holidays.inauguration_day`. Stored overrides are trusted when `overrides_seeds_rev` is the current revision; a map saved under another revision is validated again and the paths that no longer validate are left out of `A` (logged; the stored map stays until the owner saves). A number takes the type of the seed it replaces (a float where the seed is a float) |
| `ProfileMapper` | row <-> `TruckProfileX`, defaults, validation of shape and ranges. What needs other data is the profile service's: the region and time zone of a new truck, whether a region is usable, whether a licence county belongs to the region, the state and county of the base | `toRecord(array $row): array` (the API's `TruckRecord` of a normalised row; the truck value of 2.5 is accepted as well), `toColumns(array $profile): array` (the normalised columns of a profile or of the part that is given, a nested object key by key; `timezone`, `base_state` and `base_county_fips` are not profile fields and are set by the profile service), `defaults(): array`, `validate(Input $in, bool $partial): array` (the fields that were sent, validated, in profile shape; with `$partial` false `name`, `base` with its point and `avg_ticket` are required and `base.address` defaults to `""`; with `$partial` true a body without a known key is V12. Two keys of the answer are not profile fields: `timezone`, a valid IANA name as sent, and `base.state`, two letters in upper case) |
| `RegionService` | region list, active version, usability (5.2). Text that does not have the form of a region id (`[a-z0-9]{1,24}`, 03_DATA.md section 1) is an unknown id and is never looked up [R: MySQL 8.0.45 refuses to compare text outside ASCII with the `ascii_bin` id column, error 3988, which would surface as a 500] | `list(): array`, `info(string $regionId): ?array` (`RegionInfo`; null for `none` and for an unknown id), `active(string $regionId): ?array` (null for `none`, an unknown id and a region without an active `ready` version; else `{region_id, dataset_version, timezone, h3_res, usable, unusable_reason: null or "build_mismatch", config}` with `config` = the region definition), `regionForPoint(float $lat, float $lng): string`, `kernelFromSeeds(): array`, `kernelMatches(?array $kernel): bool` (the kernel check of 03_DATA.md 9.3 on the `kernel` of `RegionRepository::packMeta`; null does not match), `parameterDifferences(array $parameters): array` (the names of the manifest's `parameters` that are not this server's seed values, in the order the pipeline records them; a missing value differs; the loader prints them when G19 fails), `buildScopeMatches(?array $kernel, array $parameters): bool` (`kernelMatches` and no `parameterDifferences`), `fuelArea(?string $regionId, ?string $state): string`, `countyFips(string $regionId): array` |
| `CaptureService` implements `CaptureProvider` | exact-point capture and the readers of the host link rule (5.1) | the contract methods, and `forget(): void` (drops the rows kept from the last point; for a long-running script after the rows of a version were rewritten) |
| `RegionLoader`, `CellPackWriter` (static, pure) | loader and pack (5.2, 7) | `RegionLoader::run(array $opts): int` (the options as `tp_args` returns them; the answer is the exit code; what it prints goes through two callables given to the constructor, by default standard output and the error stream), `CellPackWriter::build(array $header, array $h3Ids, array $columns): string` (`$header` = what the caller knows: `region_id`, `dataset_version`, `model_version`, `pipeline_version`, `h3_res`, `bounds`, `kernel`, `vintages`, `attribution`; `$columns` = 50 lists of one value per cell), `CellPackWriter::decode(string $bytes): array` (`{header, n, k, ids, columns}` with `columns[j][i]` the value the browser keeps as `features[i * k + j]`), and for a reader that takes one column at a time `layout(string $bytes): array`, `ids(string $bytes, ?array $layout = null): array`, `column(string $bytes, int $j, ?array $layout = null): array`; `code(float $v, float $scale): int`, `errorBound(float $v, float $scale): float`, `columnNames(): array`, `segments(): array` |
| `ProfileService`, `AssumptionsService`, `BootstrapService` | truck setup (4.3 to 4.5) | `ProfileService::upsert(string $orgId, ?string $userId, array $body): array` (the answer of route 3 with one key added, `created: bool`, which the controller turns into 201 or 200 and does not send), `AssumptionsService::merge(string $orgId, array $truck, array $changes): array` and `AssumptionsService::reset(string $orgId, array $truck, ?array $paths): array` (both return the `AssumptionsInfo` after the save; `$paths` null resets everything; `merge` validates with `Seeds::withOverrides`, so a map that does not validate surfaces as the `ModelError` `invalid_overrides` and nothing is stored), `static AssumptionsService::refusal(array $problems): array` (`{message, details}` of the 422 of 4.5, from the `details()` of that error), `BootstrapService::build(string $orgId, ?array $truck): array` (the fields of 4.3) |
| `SimulateService`, `SpotService` | 4.7, 4.8 | `SimulateService::run(string $orgId, array $truck, array $A, array $body): array`. `SpotService`, answering the API's `Spot`: `list(string $orgId, array $truck, bool $withArchived = false): array`, `get(string $orgId, array $truck, string $spotId): array`, `create(string $orgId, array $truck, ?string $userId, array $body, ?string $googlePlaceId = null): array` (`$body` is the body of route 11; `$googlePlaceId` is never read from a body: only save-as-spot of 4.15 passes one), `update(string $orgId, array $truck, string $spotId, array $body): array`, `refresh(string $orgId, array $truck, string $spotId): array`; and `archive(string $orgId, string $spotId): array` (`{id, archived: true}`), `refreshStale(string $orgId, array $truck, int $limit = 50): array` (`{refreshed, remaining}`). For the other services: `terms(array $spot): array` (`SpotTerms` with `spot_id` set, of a spot row or a spot value), `ensureFresh(string $orgId, array $truck, array $spot): array` (the spot value of 4.8, recomputed and stored first when its vectors are not `fresh`), `termsInput(?Input $terms, array $truck): array` (the `terms` object of a body, validated: only the keys that were sent, and with `host` also `place_key` and `place_type`), `linkedHost(array $truck, float $lat, float $lng, ?array $host, ?string $placeKey, ?string $placeType): array` (reads the sources a visitor host needs, runs the host link rule and returns `{host, place_key, place_type, point_id}` with the result written into `host`), `resolveHostLink(array $truck, float $lat, float $lng, ?array $host, ?string $placeKey, ?string $placeType, array $sources): array` (`{place_key, place_type, point_id}`: the host link rule of 4.8) |
| `RoutingService` implements `LegProvider` | 5.3 | contract methods (`movePoint` through `DriveOverrideRepository::movePoint`) plus `static pairs(string $mode, array $ids): array`, `static legInputMap(array $driveLegs): array` |
| `Google\RoutesMatrixClient`, `Google\DistanceMatrixClient`, `Google\PlacesContactClient` | one upstream call each, parsing included (5.3, 5.4) | `RoutesMatrixClient::matrix(array $origins, array $destinations, string $routeKey, bool $tolls): array`, `DistanceMatrixClient::matrix(array $origins, array $destinations, string $routeKey): array` (both return `{ok: bool, error: ?string, elements: [{o: int, d: int, found: bool, duration_s: int, distance_m: int, toll_state: int, toll_cents: ?int}]}`), `PlacesContactClient::find(string $name, float $lat, float $lng): array` (`{ok, error, place: ?array}`) |
| `Upstream\WeatherClient`, `Upstream\FuelClient` | 5.5, 5.6 | `WeatherClient::hourly(float $lat, float $lng): array`, `FuelClient::weekly(array $areas, string $start): array` (`{ok, error, rows}`) |
| `DayContextService` implements `DayContextProvider`, `FuelPriceService` implements `FuelPriceProvider` | 5.5, 5.6 | contract methods plus `FuelPriceService::refreshIfDue(): void` |
| `PlanningService` | builds model inputs, evaluates, stores snapshots (5.8) | `evaluate(string $orgId, array $truck, array $A, array $plan): array` (`{result, context}`), `evaluateStored(string $orgId, array $truck, array $A, string $planId): array` (`Plan`), `stopInputs(string $orgId, array $truck, array $stops): array`, `resultState(array $planRow, array $truck, array $A): string` |
| `ServiceLogService`, `CalibrationService` implements `CalibrationProvider` | 5.7 | `ServiceLogService::create(string $orgId, array $truck, array $A, ?string $userId, array $body): array`, `update(string $orgId, array $truck, array $A, string $id, array $body): array`; `CalibrationService::state` (contract), `accuracy(string $orgId, array $truck, array $A, ?string $from, ?string $to): array`, `ensureRawPredictions(string $orgId, array $truck, array $A): int` |
| `ScoutingService`, `ScoutScreen` (static, pure) | 5.9 | `ScoutingService::rank(string $orgId, array $truck, array $A, array $opts): array`, `saveLead(string $orgId, array $truck, string $placeKey, array $body): array`, `lookupContact(string $orgId, array $truck, string $placeKey, bool $force): array`, `saveAsSpot(string $orgId, array $truck, ?string $userId, string $placeKey, array $body): array`; `ScoutScreen::scores(array $A, array $profile, ?array $cal, float $fuelPrice, array $base, array $candidates): array` |
| `SuggestionService` | 5.10 | `day(string $orgId, array $truck, array $A, array $body): array`, `week(string $orgId, array $truck, array $A, array $body): array` |
| `ExportService`, `DataPurgeService`, `SourcesService`, `DemoTruckSeeder` | 4.16, 7 | `ExportService::stream(string $orgId): void`, `DataPurgeService::deleteTruckData(string $orgId): array`, `DataPurgeService::purgeGoogleCaches(): array`, `SourcesService::build(?array $truck): array`, `DemoTruckSeeder::seed(string $orgId, ?string $userId, string $asOf): array` |

### 2.5 Controllers (`src/Controllers/Truck*Controller.php`)

`TruckBaseController` (abstract) gives every controller the same guards. Constructors take no required arguments (the router calls `new $class()` [R]).

| Method of `TruckBaseController` | Behaviour |
|---|---|
| `protected function run(callable $fn): void` | Calls `$fn`. `TpInvalid` -> 422 with its message and `details`. `TpNotFound` -> 404. `TpConflict` -> 409. `TpRateLimited` -> 429. `TpUnavailable` -> 503. Any other `\Throwable` -> `error_log('[tp] ' . get_class($e) . ': ' . Redactor::text($e->getMessage()))` and 500 `Truck Planner could not complete this request` |
| `protected function orgId(Request $r): string` | `users.organization_id` is nullable [R]: when empty, 403 `This account has no workspace` |
| `protected function userId(Request $r): ?string` | the caller's `users.id` (for `created_by`) |
| `protected function truck(Request $r, bool $required = true): ?array` | `TruckRepository::findByOrg`. When required and missing: 409 `Set up your truck first`. The answer is the **truck value** that every service and contract takes as `$truck`: the normalised row with one key added, `profile`, the `TruckProfileX` built by `ProfileMapper`. So it reads as a `TruckRecord` (`id`, `timezone`, `base_state`, `base_county_fips`, `profile`, `created_at`, `updated_at`) and also carries `organization_id`, `overrides` and `overrides_seeds_rev`. Services read those keys only. It goes to a client through `ProfileMapper::toRecord` only |
| `protected function stub(Request $r, bool $needsTruck = true): void` | the body of an action that is registered and not built yet: the guards run as they will later, then 501 `Not implemented yet` (section 9) |
| `protected function assumptions(?array $truck): array` | `AssumptionsFactory::forTruck` with the truck's region row |
| `protected function body(Request $r, bool $optional = false): array` | 413 `Request body is too large` above 262,144 bytes. 422 `Request body must be a JSON object` when the body is missing, not JSON, or not an object (the first non-space character of the raw body, read through `Request::getRawBody()`, must be `{`). With `$optional`, an empty body is `[]` (routes 6, 12, 16, 28, 40, 41) |
| `protected function ok(array $data, array $mapPaths = [], ?string $message = null, int $status = 200): void` | `ini_set('serialize_precision', '-1')`, `JsonSafe::clean`, a trial `json_encode` (failure -> log and 500), then `Response::success` |

Controllers and their actions are the route table (section 3). Each action is `public function name(Request $request): void` and consists of `run()` around: guards, `Input` validation, one service call, `ok()`.

## 3. Route table

One block in `config/routes.php` (CRLF file: keep the line endings), inserted before the closing `};`, with the `use` lines beside the existing ones. Registration order is the table order: the router takes the first pattern that matches the path and verb [R], so literal paths come before parameterised ones. Patterns contain no `.`.

```php
    // Truck Planner. Not plan-gated. A rate-limit profile only where the request can reach a Google quota.
    $tpAuth    = [Middleware::auth()];
    $tpDrive   = [Middleware::auth(), Middleware::rateLimit('tp_drive',   240, 3600)];
    $tpSuggest = [Middleware::auth(), Middleware::rateLimit('tp_suggest',  30, 3600)];
    $tpScout   = [Middleware::auth(), Middleware::rateLimit('tp_scout',    60, 3600)];
    $tpContact = [Middleware::auth(), Middleware::rateLimit('tp_contact',  20, 3600)];
    $tpOwner   = [Middleware::auth(), Middleware::requireRole(['owner', 'admin'])];
```

| # | Verb | Path | Controller::method | Middleware | Package |
|---:|---|---|---|---|---|
| 1 | GET | `/api/truck/bootstrap` | `TruckBootstrapController::show` | `$tpAuth` | P3 |
| 2 | GET | `/api/truck/profile` | `TruckProfileController::show` | `$tpAuth` | P3 |
| 3 | PUT | `/api/truck/profile` | `TruckProfileController::upsert` | `$tpAuth` | P3 |
| 4 | GET | `/api/truck/assumptions` | `TruckAssumptionsController::show` | `$tpAuth` | P3 |
| 5 | PUT | `/api/truck/assumptions` | `TruckAssumptionsController::update` | `$tpAuth` | P3 |
| 6 | POST | `/api/truck/assumptions/reset` | `TruckAssumptionsController::reset` | `$tpAuth` | P3 |
| 7 | GET | `/api/truck/regions` | `TruckRegionController::index` | `$tpAuth` | P2 |
| 8 | GET | `/api/truck/regions/{region_id}/pack/{dataset_version}` | `TruckRegionController::pack` | `$tpAuth` | P2 |
| 9 | POST | `/api/truck/simulate` | `TruckSimulateController::simulate` | `$tpAuth` | P4 |
| 10 | GET | `/api/truck/spots` | `TruckSpotController::index` | `$tpAuth` | P4 |
| 11 | POST | `/api/truck/spots` | `TruckSpotController::create` | `$tpAuth` | P4 |
| 12 | POST | `/api/truck/spots/refresh` | `TruckSpotController::refreshStale` | `$tpAuth` | P4 |
| 13 | GET | `/api/truck/spots/{id}` | `TruckSpotController::show` | `$tpAuth` | P4 |
| 14 | PUT | `/api/truck/spots/{id}` | `TruckSpotController::update` | `$tpAuth` | P4 |
| 15 | DELETE | `/api/truck/spots/{id}` | `TruckSpotController::destroy` | `$tpAuth` | P4 |
| 16 | POST | `/api/truck/spots/{id}/refresh` | `TruckSpotController::refresh` | `$tpAuth` | P4 |
| 17 | GET | `/api/truck/day-context` | `TruckDayContextController::show` | `$tpAuth` | P5 |
| 18 | POST | `/api/truck/drive-times` | `TruckDriveController::compute` | `$tpDrive` | P5 |
| 19 | GET | `/api/truck/drive-times/overrides` | `TruckDriveController::overrides` | `$tpAuth` | P5 |
| 20 | PUT | `/api/truck/drive-times/overrides` | `TruckDriveController::saveOverride` | `$tpAuth` | P5 |
| 21 | DELETE | `/api/truck/drive-times/overrides/{id}` | `TruckDriveController::destroyOverride` | `$tpAuth` | P5 |
| 22 | GET | `/api/truck/plans` | `TruckPlanController::index` | `$tpAuth` | P6 |
| 23 | POST | `/api/truck/plans` | `TruckPlanController::create` | `$tpDrive` | P6 |
| 24 | POST | `/api/truck/plans/evaluate` | `TruckPlanController::preview` | `$tpDrive` | P6 |
| 25 | GET | `/api/truck/plans/{id}` | `TruckPlanController::show` | `$tpAuth` | P6 |
| 26 | PUT | `/api/truck/plans/{id}` | `TruckPlanController::update` | `$tpDrive` | P6 |
| 27 | DELETE | `/api/truck/plans/{id}` | `TruckPlanController::destroy` | `$tpAuth` | P6 |
| 28 | POST | `/api/truck/plans/{id}/evaluate` | `TruckPlanController::evaluate` | `$tpDrive` | P6 |
| 29 | POST | `/api/truck/suggest/day` | `TruckSuggestController::day` | `$tpSuggest` | P8 |
| 30 | POST | `/api/truck/suggest/week` | `TruckSuggestController::week` | `$tpSuggest` | P8 |
| 31 | GET | `/api/truck/services` | `TruckServiceLogController::index` | `$tpAuth` | P6 |
| 32 | POST | `/api/truck/services` | `TruckServiceLogController::create` | `$tpAuth` | P6 |
| 33 | GET | `/api/truck/services/{id}` | `TruckServiceLogController::show` | `$tpAuth` | P6 |
| 34 | PUT | `/api/truck/services/{id}` | `TruckServiceLogController::update` | `$tpAuth` | P6 |
| 35 | DELETE | `/api/truck/services/{id}` | `TruckServiceLogController::destroy` | `$tpAuth` | P6 |
| 36 | GET | `/api/truck/calibration` | `TruckCalibrationController::show` | `$tpAuth` | P6 |
| 37 | GET | `/api/truck/accuracy` | `TruckCalibrationController::accuracy` | `$tpAuth` | P6 |
| 38 | GET | `/api/truck/scout` | `TruckScoutController::index` | `$tpScout` | P7 |
| 39 | PUT | `/api/truck/scout/leads/{place_key}` | `TruckScoutController::saveLead` | `$tpAuth` | P7 |
| 40 | POST | `/api/truck/scout/leads/{place_key}/contact` | `TruckScoutController::contact` | `$tpContact` | P7 |
| 41 | POST | `/api/truck/scout/leads/{place_key}/spot` | `TruckScoutController::saveAsSpot` | `$tpAuth` | P7 |
| 42 | GET | `/api/truck/export` | `TruckDataController::export` | `$tpAuth` | P8 |
| 43 | POST | `/api/truck/data/delete` | `TruckDataController::destroy` | `$tpOwner` | P8 |
| 44 | GET | `/api/truck/sources` | `TruckDataController::sources` | `$tpAuth` | P8 |

The rate-limit names are not in `GooglePricing::COSTS`, so their `api_usage_log` rows cost 0 and no response carries `_meta.estimated_cost_usd` [R]. `tp_drive` is shared by routes 18, 23, 24, 26 and 28 on purpose: one counter for "requests that may fetch Google legs". `$tpOwner` guards route 43 only: the account owner or an admin passes, any other member gets 403 `Requires role: owner/admin` [R: `Middleware::requireRole`].

Routes 2, 4, 7, 16, 19, 24, 28, 33 and 36 are not called by the version 1 screens. They stay: DECISIONS 9 lists them and the smoke test exercises them.

## 4. Endpoints

### 4.1 Common behaviour and shapes added here

Envelope [R]: success `{"success": true, "data": ..., "message"?: "..."}`; error `{"success": false, "error": "one sentence"}`. Exceptions: route 8 (binary) and route 42 (a bare JSON document). Status codes used: 200, 201, 304 (route 8), 403 (no workspace; route 43 without the owner or admin role), 404 (unknown id or another tenant's id), 409 (`Set up your truck first`, `Region data was built with different model constants` (5.2), state conflicts), 413, 422 (validation), 429 (rate-limit middleware; a service through `TpRateLimited`, route 40 only), 500, 503 (a feature this server cannot offer). **Never 401** from Truck Planner code: 401 is produced only by `Middleware::auth()` and logs the browser out [R]. Every route except 1, 2, 3, 7, 8, 43 and 44 needs a truck and answers 409 `Set up your truck first` without one.

`DATETIME` values are returned as stored (`YYYY-MM-DD HH:MM:SS`, database server zone) and are for display and ordering only.

```
TruckProfileX  = TruckProfile                                                        # the API's name for the profile shape of 02_MODEL.md section 3
TruckRecord    = { id, timezone: string, base_state: string?, base_county_fips: string?, profile: TruckProfileX, created_at, updated_at }
AssumptionsInfo= { model_version, seeds_revision: int, overrides: { <seed path>: <value> },
                   region: { id, traffic_matrix: "dc"|"us_mean", flags: { inauguration_day: bool } } }      # Assumptions without `seeds`
RegionInfo     = { region_id, name, timezone, h3_res: int, center: {lat,lng}, bbox: {lat_min,lng_min,lat_max,lng_max},
                   dataset_version: string?, usable: bool, unusable_reason: null|"not_loaded"|"build_mismatch",      # dataset_version = tp_regions.active_version
                   pack: { url, format_version: int, bytes: int, gz_bytes: int, sha256, cell_count: int }?,
                   vintages: { census_reference_date, lodes_year: int, osm_snapshot_date }?, counties: [ {fips, name, state} ] }
FuelInfo       = { price_per_gal: number, source: "owner"|"eia"|"seed", area: string, product: "EPMR"|"EPD2D", period: string? }
Located        = { in_region: bool, region_id: string?, county_fips: string?, state: string? }
OutletRow      = { place_key, name: string?, place_type, rival_kind, kitchen, lat, lng, distance_m: number }
HostHint       = { place_key, name, place_type, lat, lng, distance_m, host_segment: string?, default_size: number,
                   kitchen: "yes"|"no", point_id: string? }                         # point_id = "p" + place_key when the place is a visitor source
                                                                                    # default_size = the place's size_default (0 when it has none)
Spot           = { id, name, point: {lat,lng}, address, county_fips: string?, notes: string?, terms: SpotTerms,
                   host_details: { place_type: string?, name: string?, contact: string?, phone: string?, website: string?,
                                   place_key: string?, google_place_id: string? }?,
                   vectors: { hidden: LocationVectors, normal: LocationVectors, prominent: LocationVectors }?,
                   vectors_state: "fresh"|"stale"|"none", logs: { count: int, last_date: string? }, maps_url, archived: bool, created_at, updated_at }
DriveLeg       = { from_id, to_id, source: "google_routes"|"google_distance_matrix"|"straight_line"|"same_point",
                   fetched_on: string?, age_days: int?, distance_m: number, duration_s: number,
                   toll_state: "not_asked"|"none"|"estimate"|"unknown", google_toll: number?, toll_source: "owner"|"google"|"none",
                   override: { id, minutes: int?, toll: number?, note }?,
                   fallback_reason: null|"no_key"|"refused"|"quota"|"budget"|"rate"|"timeout"|"upstream"|"route_not_found"|"cache_only",
                   leg_input: LegInput }
PlanStop       = { id, kind: "spot"|"event"|"catering", spot_id: string?, label, point: {lat,lng}?, address,      # point is null for spot stops (the point is the spot's)
                   open_minute: int, close_minute: int, gap_before_unpaid: bool, setup_minutes: int?, teardown_minutes: int?,
                   fee_flat: number, fee_pct: number, fee_min: number, event: EventTerms?, catering: CateringTerms? }
EvalContext    = { ctx: DayContext, ctx_next: DayContext, legs: [DriveLeg], calibration: { as_of, truck_factor, truck_n: int },
                   fuel: FuelInfo, model_version, seeds_revision: int, dataset_version: string?, uses_google_legs: bool }
Plan           = { id, date, name, treat_as, notes: string?, status: "draft"|"planned"|"done"|"cancelled", stops: [PlanStop],
                   result: DayResult?, context: EvalContext?, result_state: "fresh"|"stale"|"expired"|"none", evaluated_at: string?,
                   maps_route_url: string?, created_at, updated_at }                # maps_route_url is null for a plan without stops
ServiceLog     = { id, kind, spot_id: string?, plan_id: string?, plan_stop_id: string?, date, open_minute: int, close_minute: int,
                   actual: int, sales: number?, sold_out: bool, notes: string?, source, external_key: string?, treat_as,
                   prediction: { predicted_raw: number, predicted: number, low: number, high: number, confidence, basis: "plan"|"log",
                                 model_version, seeds_revision: int, dataset_version: string?, detail: object }?, created_at, updated_at }
DayInfo        = { date, holiday: Holiday?, context: DayContext }                     # context built with treat_as = null
Lead           = { id: string?, place_key, status, notes: string?, spot_id: string?,
                   google: { place_id, lookup_state: "found"|"not_found", name: string?, address: string?, phone: string?,
                             website: string?, maps_uri: string?, fetched_on }? }
ScoutCandidate = { result: ScoutResult, place: { place_key, name, brand: string?, place_type, lat, lng, county_fips, addr_line: string?,
                   city: string?, state_code: string?, postcode: string?, phone: string?, website: string?, opening_hours_raw: string?,
                   kitchen }, lead: Lead, maps_url, leg_sources: { out, back } }
```

`leg_input.source` is `google` for the two Google sources and for `same_point`, `fallback` for `straight_line`. `leg_input.toll` is the owner's toll when set (`toll_source: "owner"`), else `google_toll` when `toll_state` is `estimate` (`"google"`), else `0.0` (`"none"`). `leg_input.override_minutes` is the owner's minutes or `null`.

Every 422 raised by `Input` also carries `details: {"field": "<f>", "code": "V1".."V12"}` (third argument of `Response::error`), so a form can attach the message to its field. Other 422 messages carry `details.field` when they concern one field.

### 4.2 Validation messages (exact text; `{f}` is the dotted field path such as `stops[1].open_minute`)

| Id | Message | Raised when |
|---|---|---|
| V1 | `{f} is required` | key missing, or `null` where not nullable |
| V2 | `{f} must be a number between {min} and {max}` | not a JSON number, or out of range (bounds printed with `json_encode`) |
| V3 | `{f} must be a whole number between {min} and {max}` | not an integer-valued JSON number, or out of range |
| V4 | `{f} must be one of: {a, b, c}` | not in the list |
| V5 | `{f} must be text of at most {n} characters` | not a string, or longer after trimming (`mb_strlen`) |
| V6 | `{f} must be true or false` | not a JSON boolean |
| V7 | `{f} must be a date in the form YYYY-MM-DD` | `Estimator::parseDate` rejects it |
| V8 | `{f} must be a list of {min} to {max} items` | not a list, or wrong length |
| V9 | `{f} must be an object` | not a JSON object |
| V10 | `{f} must have lat between -90 and 90 and lng between -180 and 180` | point object invalid |
| V11 | `{f} was not found` | an id or key in the body that does not exist for this organization or region |
| V12 | `Nothing to update` | a PUT body with no known key |

In a JSON body numeric strings are not numbers. Query parameters are strings: a whole number must match `^-?[0-9]+$`, a flag is `1` or `0`, a date is V7. Unknown keys are ignored. The first failure wins. In a PUT, a nested object (`terms`, `base`, `daypart_fit`, `host_details`) changes only the keys it carries; `terms.host`, `terms.allowed`, `event` and `catering` are replaced whole.

A body is decoded into PHP arrays, which cannot tell two pairs of JSON spellings apart [R]. An empty list where an object is expected is read as an empty object (`"terms": []` changes nothing; it is not V9). An object whose keys are exactly `"0"` to `"n-1"` is read as a list of n items: it passes where a list is expected, and is V9 where an object is.

A range of at most N days means `daysFromCivil(to) - daysFromCivil(from) + 1 <= N` (both ends count). This holds for routes 17 (N = 14), 22 (N = 92) and 31 (N = 730).

### 4.3 `GET /api/truck/bootstrap`

Purpose: everything a `/truck` page needs before its first render. Works without a truck.

| Field | Type | Content |
|---|---|---|
| `model_version`, `seeds_revision` | string, int | the client refuses to run when its estimator's values differ |
| `has_truck`, `truck` | bool, `TruckRecord?` | |
| `profile_defaults` | object | `ProfileMapper::defaults()`: every `TruckProfileX` field except `name`, `region_id`, `base` |
| `assumptions` | `AssumptionsInfo` | region `none` without a truck |
| `region`, `regions` | `RegionInfo?`, `[RegionInfo]` | the truck's region (null for `none`), and all regions. An unusable region comes with `usable: false`, its `unusable_reason` and `pack: null` (5.2) |
| `calibration` | `CalibrationState` | `Registry::calibration()` as of `today`. Without a truck: the identity state (`Fallback\IdentityCalibration`) as of today in the default zone (`regions.default_timezone`) |
| `fuel` | `FuelInfo?` | `Registry::fuel()->resolve`. Null without a truck |
| `timezone`, `today`, `now_minute` | string?, string?, int? | from `Clock` in the truck's zone; `today` and `now_minute` (0 to 1439) belong to one moment. Null without a truck. A stored zone that the server's zone database does not hold is answered as the default zone (logged) |
| `counts` | `{spots, plans, services, leads}` | ints from `CountsRepository::forOrg` (spots not archived) |
| `routing` | `{state: "ok"\|"no_key"\|"refused"\|"backoff"}` | `Registry::legs()->status()` |
| `limits` | object | `config/truck_planner.php` `limits` (7.1) |

Status: 200. 403 without a workspace. Map paths: `assumptions.overrides`, `calibration.spots`.

```json
{"success":true,"data":{"model_version":"tps-0.1.0","seeds_revision":1,"has_truck":false,"truck":null,
 "profile_defaults":{"avg_ticket":15,"capacity_orders_per_hour":45,"paid_crew":2,"...":"..."},
 "assumptions":{"model_version":"tps-0.1.0","seeds_revision":1,"overrides":{},"region":{"id":"none","traffic_matrix":"us_mean","flags":{"inauguration_day":false}}},
 "region":null,"regions":[{"region_id":"dc","name":"Washington, DC region","usable":true,"...":"..."}],
 "calibration":{"truck_factor":1,"truck_n":0,"spots":{},"...":"..."},"fuel":null,"timezone":null,"today":null,"now_minute":null,
 "counts":{"spots":0,"plans":0,"services":0,"leads":0},"routing":{"state":"no_key"},"limits":{"max_spots":500,"...":"..."}}}
```

### 4.4 Profile: `GET /api/truck/profile`, `PUT /api/truck/profile`

GET: 200 `{truck: TruckRecord?, profile_defaults}`. No 404 when there is no truck.

PUT is an upsert. Without a truck it creates one: `name`, `base` and `avg_ticket` are required, everything else defaults from `profile_defaults`. With a truck it changes only the keys present. Response `{truck: TruckRecord, region: RegionInfo?, fuel: FuelInfo, warnings: [string]}`, status 201 on create, 200 on update. `fuel` is `Registry::fuel()->resolve` after the save. `warnings` holds codes: `timezone_assumed`, `base_outside_region`.

| Field | Type | Rule |
|---|---|---|
| `name` | string | V5 with 120, not empty (V1) |
| `base` | object | V9. `base.lat`, `base.lng`: V10 on `base` (`base must have lat between -90 and 90 and lng between -180 and 180`); in an update they are sent together or not at all. `base.address`: V5 with 255, default `""`. Optional `base.state`: two letters (V5 with 2, else `base.state must be a two-letter state code`), stored in upper case, used only when the server cannot derive the state |
| `region_id` | string | optional. V11 unless `none`, the truck's present region, or a region with a usable active version (text that does not have the form of a region id, `[a-z0-9]{1,24}`, is never a region and is not looked up: 2.4 `RegionService`). Without it the region follows the base: whenever `base.lat` and `base.lng` are saved (always for a new truck), the truck stays in its region when that region's bbox contains the point, and else gets the default: the first region (ascending id) whose bbox contains the base, else `none`. A save without a base point keeps the region |
| `timezone` | string | optional, read only when the region is `none`: an IANA name from `DateTimeZone::listIdentifiers()`, else V4-style `timezone must be an IANA time zone name`. Otherwise the region's zone. Region `none` without it: a new truck gets `America/New_York` and the warning `timezone_assumed`, an existing truck keeps the zone it has |
| numeric profile fields | number / int | V2 or V3 with the `min` and `max` of `profile_defaults.<field>` (table in 02_MODEL.md 2.3). Example: `avg_ticket must be a number between 1 and 200` |
| `daypart_fit` | object | V9. Each of `breakfast`, `lunch`, `dinner`, `late`: V2 with 0 and 1 |
| `tips_include`, `avoid_tolls`, `avoid_highways` | bool | V6 |
| `fuel_type` | string | V4: `gasoline, diesel` |
| `fuel_price_override` | number or null | V2 with 0.5 and 20 |
| `licence_counties` | list | V8 with 0 and 60 of strings matching `^[0-9]{5}$` (an item that is not text: V5 with 5; text of another form: `licence_counties[{i}] must be a 5-digit county code`). A code given twice is kept once. In a region other than `none` each must be a county of that region: `licence_counties[{i}] is not a county of this region` (`{i}` is the item's place in the list as sent). When a save changes the region to one other than `none` and carries no list, the stored codes that are not counties of the new region are dropped |
| `scout_drive_minutes_limit` | int | V3 with 5 and 60 |

Server side on save: money rounded to cents (the response shows the stored value); `base_state` and `base_county_fips` from `Registry::capture()->locate()` (5.1), falling back to `base.state`; the warning `base_outside_region` when the region is not `none` and that `locate()` of the base answers `in_region: false` (the region bbox only chooses the default region, it is never a membership test); when the base moved, `DriveOverrideRepository` rows are left alone (they are keyed by coordinates). Changing profile fields never recomputes spot vectors.

`locate()` is asked on create and on an update that carries `base.lat` and `base.lng`, carries `base.state`, or changes the region. Any other update leaves `base_state` and `base_county_fips` alone and raises no warning. Where `locate()` cannot tell, the state is `base.state` when the body carries it; beyond that a base point that did not move keeps its stored state and county, and a point that moved has neither.

Two first saves that arrive together both succeed: the one whose insert meets `uk_tptr_org` continues as an update of the truck that is there and answers 200.

```json
PUT {"name":"Smoke & Ember","base":{"lat":39.003,"lng":-77.405,"address":"Sterling, VA"},"avg_ticket":15}
201 {"success":true,"data":{"truck":{"id":"6f1c...","timezone":"America/New_York","base_state":"VA","base_county_fips":"51107",
     "profile":{"name":"Smoke & Ember","region_id":"dc","base":{"lat":39.003,"lng":-77.405,"address":"Sterling, VA"},"avg_ticket":15,"...":"..."}},
     "region":{"region_id":"dc","...":"..."},"fuel":{"price_per_gal":4.195,"source":"eia","area":"R1Z","product":"EPMR","period":"2026-09-28"},"warnings":[]},"message":"Truck created"}
```

### 4.5 Assumptions: `GET`, `PUT /api/truck/assumptions`, `POST /api/truck/assumptions/reset`

GET -> 200 `{assumptions: AssumptionsInfo}`. The seed values themselves are not shipped: the browser bundles the same seed file, and `seeds_revision` must match.

PUT body `{"overrides": {"<seed path>": <value> | null}}` (V1 without the key; V9; at most 200 keys, else `overrides must have at most 200 entries`). The changes are merged into the stored map (`null` removes a path), then the **whole** merged map goes through `Estimator::validateOverrides(seeds, merged)`. A stored map that was saved under another seeds revision first loses the paths that no longer validate, the ones `AssumptionsFactory` already leaves out of `A`: a save starts from the map the owner sees. Any error -> 422 with the first error in ascending path order as `overrides.{path}: {code}` where code is one of `unknown_path`, `not_a_seed`, `not_overridable`, `not_a_leaf`, `wrong_shape`, `out_of_bounds`, `not_allowed`, and `details` = the full `[{path, error}]` list (the controller calls `Response::error($message, 422, $details)` itself). Nothing is clamped. On success store with `overrides_seeds_rev` = current revision and return `{assumptions}`.

POST reset body `{"paths": ["<seed path>", ...]}` (V8 with 0 and 200; each item V5 with 200). Without `paths` (`{}`, `paths: null` or no body at all) every override is reset; an empty list resets none. Paths that are not overridden are ignored. Returns `{assumptions}`.

Effects: overrides are owner-scope seeds, so no vector is recomputed. Plan snapshots become `stale` and raw predictions of service logs are recomputed lazily (5.7). Map path: `assumptions.overrides`.

```json
PUT {"overrides":{"host.captive_share":0.6,"weather.floor":null}}
200 {"success":true,"data":{"assumptions":{"model_version":"tps-0.1.0","seeds_revision":1,"overrides":{"host.captive_share":0.6},"region":{"id":"dc","traffic_matrix":"dc","flags":{"inauguration_day":true}}}}}
PUT {"overrides":{"kernel.outside_option_a0":2}}
422 {"success":false,"error":"overrides.kernel.outside_option_a0: not_overridable","details":[{"path":"kernel.outside_option_a0","error":"not_overridable"}]}
```

### 4.6 Regions: `GET /api/truck/regions`, `GET /api/truck/regions/{region_id}/pack/{dataset_version}`

`GET /regions` -> 200 `{regions: [RegionInfo]}`. `pack.url` is the path of route 8 for the active version. `usable` is false, with `pack: null`, when there is no active version with `load_state = 'ready'` (`unusable_reason: "not_loaded"`) or when that version fails any other test of 5.2 (`"build_mismatch"`).

Route 8 serves the cell pack and follows 03_DATA.md 11.1 step by step:

1. `RegionRepository::packMeta` for the key (`pack_gz_len`, `pack_sha256`, `load_state`, `kernel_json`; never the blob yet). Missing or not `ready`, or path values that are not a region id (`[a-z0-9]{1,24}`) and a dataset version (`[a-z0-9-]{1,48}`): `Response::error('Not found', 404)`. Then the kernel check of 03_DATA.md 9.3 on this row's `kernel_json` (5.2): on a difference `Response::error('Region data was built with different model constants', 409)`.
2. `etag = '"' . substr(pack_sha256, 0, 32) . '-gz"'` when the request's `Accept-Encoding` contains `gzip`, without `-gz` otherwise. If `If-None-Match` names it (the header may hold a list; a weak mark `W/` is ignored): the headers of step 4, status 304, no body, `exit`. No blob is read for a 304.
3. `RegionRepository::packBlob`; without gzip `gzdecode` it. The blob is read before any header is sent, so that an error answer never carries the cache headers.
4. `ini_set('zlib.output_compression', 'Off')`; close every output buffer. `Response::corsHeaders()` first, then `Content-Type: application/octet-stream`, `Cache-Control: private, max-age=31536000, immutable`, `ETag`, `header('Vary: Accept-Encoding', false)` (append: `corsHeaders` already set `Vary: Origin`), `X-Content-Type-Options: nosniff`. Do not call `Response::cacheable()`.
5. With gzip accepted: `Content-Encoding: gzip`, `Content-Length: pack_gz_len`, echo as stored. Otherwise send the decoded bytes with their own length. `exit`.

The path contains the version, so a response never changes. No rate-limit middleware (it would insert a row per map load). Neither route needs a truck; both answer 403 `This account has no workspace` for an account without an organization.

```json
GET /api/truck/regions
200 {"success":true,"data":{"regions":[{"region_id":"dc","name":"Washington, DC region","timezone":"America/New_York","h3_res":9,"center":{"lat":38.9072,"lng":-77.0369},
 "bbox":{"lat_min":37.99069,"lng_min":-78.3947,"lat_max":39.72005,"lng_max":-76.66251},"dataset_version":"dc-20261003-d0514a63","usable":true,"unusable_reason":null,
 "pack":{"url":"/api/truck/regions/dc/pack/dc-20261003-d0514a63","format_version":1,"bytes":6640808,"gz_bytes":3413224,"sha256":"f5cee90fc008ae56ead2a140928c1bdad4c3c33fc14e9a119cefea7789cfa309","cell_count":61460},
 "vintages":{"census_reference_date":"2020-04-01","lodes_year":2023,"osm_snapshot_date":"2026-10-03"},"counties":[{"fips":"11001","name":"District of Columbia","state":"DC"},"..."]}]}}
GET /api/truck/regions/dc/pack/dc-20261003-d0514a63      (Accept-Encoding: gzip)
200 Content-Type: application/octet-stream, Content-Encoding: gzip, Content-Length: 3413224, ETag: "f5cee90fc008ae56ead2a140928c1bda-gz", Cache-Control: private, max-age=31536000, immutable, Vary: Accept-Encoding      body: bytes starting with TPCP after decoding
GET the same with If-None-Match: "f5cee90fc008ae56ead2a140928c1bda-gz"
304 the same ETag, Cache-Control and Vary, no body
```

The figures of this example are those of the first load of the real `dc` build (03_DATA.md 11: 61,460 cells, 6,640,808 bytes, 3,413,224 gzipped), served through `php -S`.

### 4.7 `POST /api/truck/simulate`

Purpose: exact capture at one point with optional spot terms. Returns the location vectors, the nearby rival outlets and a server-computed estimate. Nothing is stored.

| Field | Type | Rule |
|---|---|---|
| `point` | object | required, V10 |
| `visibilities` | list | V8 with 1 and 3 of V4 `hidden, normal, prominent`. A level sent twice counts once; the order sent is kept. Default `[terms.visibility]` or `["normal"]` |
| `terms` | object | optional `SpotTerms` input: `visibility` (V4), `fee_flat`, `fee_min` (V2 0..100000), `fee_pct` (V2 0..1), `allowed` (as 4.8), `host` (as 4.8: its defaults and the host link rule are applied, nothing is stored). Default: no host, no fee |
| `spot_id` | string | optional. V5 with 36, then V11 unless it is a spot of this organization (an archived one counts). Applies that spot's calibration factor (the terms still come from the body) |
| `date`, `open_minute`, `close_minute`, `treat_as` | date, int, int, string | optional, all three of date/open/close together (`date, open_minute and close_minute must be given together`). Minutes: V3 0..2880 and `close_minute must be after open_minute`. `treat_as`: V4 `normal, holiday, mon, tue, wed, thu, fri, sat, sun`, or `null`; it is read only with a date. A window that passes midnight needs the next civil date too, so the model's last date (2199-12-31) with `close_minute` above 1440 is V7 |

Response data:

| Field | Type | Content |
|---|---|---|
| `located` | `Located` | 5.1 |
| `vectors` | `{ <visibility>: LocationVectors }` | one entry per requested visibility, with the host exclusion applied |
| `host` | `Host?` | the host after the host link rule and after the defaults of 4.8 (`segment`, `size`, `size_source`, `only_food`, `point_id`, `place_type`); null without a host. Its `point_id` is the one the vectors were computed with |
| `outlets`, `outlets_total` | `[OutletRow]`, int | rivals within `kernel.walk_cutoff_m`, nearest first then `place_key`, at most 60 |
| `hosts_nearby` | `[HostHint]` | possible hosts within 250 m, nearest first, at most 10 (lets the form link a host) |
| `estimate` | object | computed with the first requested visibility: the model is given that level's vectors and terms of that visibility with the body's fee and host and `spot_id`. `week_strip` (168 numbers, `Estimator::weekStrip`), `best_windows` (`Estimator::bestWindows(week_strip, 3, 3, true)`), `typical` = `{dow, open_minute, close_minute, window: WindowResult, money: StopMoney}` for the best window (null when none): `Estimator::windowOrders` with the typical contexts of `dow` and of the day after it, and `Estimator::stopMoney` of its orders. `dated` = `{date, window: WindowResult, money: StopMoney, context: DayContext}` when a date was sent (else null): the contexts come from `Registry::dayContexts()` for the date, built with `treat_as`, and for the next date when `close_minute` is above 1440 |
| `calibration` | `{truck_factor, spot_factor}` | `Estimator::calibrationFactor` on `Registry::calibration()->state(...)` as of today in the truck's zone, for `spot_id` |
| `dataset_version`, `model_version`, `seeds_revision` | | |
| `attribution` | `[string]` | strings 1, 3 and 4 of 03_DATA.md 14, filled the same way as in 4.16: string 4 from the manifest of the dataset version that was read, and left out when none was read or a placeholder cannot be filled |

Status: 200; 409 without a truck; 409 `Region data was built with different model constants` while the truck's region is `build_mismatch` (5.2); 422. A point with no source within the cutoff answers 200 with zero vectors; `located.in_region` never zeroes vectors.

```json
POST {"point":{"lat":38.96,"lng":-77.36},"visibilities":["normal","prominent"]}
200 {"success":true,"data":{"located":{"in_region":true,"region_id":"dc","county_fips":"51059","state":"VA"},
 "vectors":{"normal":{"capture":{"day":[0,417.13,"..."],"eve":["..."]},"nearby":["..."],"rivals":{"day":0.834,"eve":0.834},"visibility":"normal","in_region":true,"points_used":8,"...":"..."},"prominent":{"...":"..."}},
 "host":null,"outlets":[{"place_key":"n100","name":"Example Grill","place_type":"fast_food","rival_kind":"quick","kitchen":"yes","lat":38.95694,"lng":-77.36,"distance_m":340},"..."],"outlets_total":2,
 "hosts_nearby":[],"estimate":{"week_strip":["..."],"best_windows":[{"start":35,"length":3,"total":66.66}],"typical":{"dow":1,"open_minute":660,"close_minute":840,"window":{"...":"..."},"money":{"...":"..."}},"dated":null},
 "calibration":{"truck_factor":1,"spot_factor":1},"dataset_version":"dc-20261003-3fa9c2d1","model_version":"tps-0.1.0","seeds_revision":1,"attribution":["..."]}}
```

### 4.8 Spots: routes 10-16

| Route | Request | Response | Status |
|---|---|---|---|
| `GET /spots` | query `archived=1` to include archived | `{spots: [Spot]}` ordered by `name`, then `id`. `logs` comes from one grouped query (`CountsRepository::logsBySpot`) | 200 |
| `POST /spots` | body below | `{spot: Spot}`, message `Spot saved` | 201, 409 `You can keep at most 500 spots`, 422 |
| `GET /spots/{id}` | | `{spot: Spot}`, an archived spot included. No Google contact field is returned here: looked-up fields stay on the Scout lead (5.4) | 200, 404 `Spot not found` |
| `PUT /spots/{id}` | any subset of the body | `{spot: Spot}` | 200, 404, 422 (V12 when empty) |
| `DELETE /spots/{id}` | | `{id, archived: true}` (soft delete; logs and plans keep working). Archiving an archived spot answers the same and changes nothing | 200, 404 |
| `POST /spots/{id}/refresh` | | `{spot: Spot}` with recomputed vectors | 200, 404 |
| `POST /spots/refresh` | | recomputes up to 50 spots whose `vectors_state` is not `fresh`, in ascending id; `{refreshed: int, remaining: int}`, `remaining` being the spots that are still not `fresh` afterwards | 200 |

An archived spot stays addressed by its id: routes 13, 14, 15 and 16 answer for it. Route 10 leaves it out unless asked, the limit of 500 does not count it, and route 12 passes over it (a plan that still refers to it refreshes it through `ensureFresh`).

| Body field | Type | Rule |
|---|---|---|
| `name` | string | required on create. V5 with 120, not empty (V1) |
| `point` | object | required on create. V10 |
| `address`, `notes` | string | V5 with 255, default `""`; V5 with 4000 (nullable; an empty text is stored as `null`) |
| `terms.visibility` | string | V4 `hidden, normal, prominent`. Default `normal` |
| `terms.fee_flat`, `terms.fee_min` | number | V2 0..100000. Default 0 |
| `terms.fee_pct` | number | V2 0..1. Default 0 |
| `terms.allowed` | object or null | `days`: V8 exactly 7 booleans. `open_minute`, `close_minute`: V3 0..2880 with `terms.allowed.close_minute must be after open_minute` |
| `terms.host` | object or null | below. `null` removes the host |
| `terms.host.place_key` | string | optional link. V5 with 20, then V11 unless a row of the region's active `tp_places` (a key that is not plain ASCII is not looked up: V11) |
| `terms.host.segment` | string | V4 over the 16 segment keys. Default with a link: `place_types.rows.<type>.host_segment`. Required otherwise. A link to a place whose type has no `host_segment`, sent without `terms.host.segment`, does not fail: the spot is saved with `place_key` and `host_place_type` set and no host (`terms.host: null` in the answer) |
| `terms.host.size` | number | V2 with 1 and 200000. Default with a link: the linked place's `size_default` when it is above 0 (then `size_source` = `default`). Otherwise the size is required: `terms.host.size is required for this kind of place` |
| `terms.host.size_source` | string | V4 `owner, default`. Default `owner` when `size` was sent. It is `default` whenever the size is the linked place's |
| `terms.host.only_food` | bool | V6. Default with a link: resolved kitchen state is `no`. Else `false` |
| `host_details.name`, `.contact`, `.phone`, `.website` | string | V5 with 160, 160, 40, 255 (nullable; an empty text clears the field as `null` does) |

Server rules. A linked place is read with `Registry::capture()->place()` (5.1): its type gives the default segment, its `size_default` and `kitchen` the default size and the default `only_food`. `host.point_id` and `host.place_type` are never accepted from the client: the host link rule below sets them. Vectors for all three visibility levels are computed synchronously on create and whenever point, host segment, host size or host link changes (the host link rule, then `Registry::capture()->capture(...)` with `["hidden", "normal", "prominent"]` and the resolved host) and stored with dataset, seeds revision, region and `county_fips`; a change of `visibility` alone needs no recomputation. An update runs the host link rule when it moves the point or carries `terms.host`, and recomputes when the point, `host_segment`, `host_size`, `place_key` or `host_point_id` then differs from what is stored, so a body that repeats the stored host changes no vector. The changed columns and the vectors are written by one `UPDATE`; when the region is `build_mismatch` and vectors are needed, nothing is written. When the point moves by 250 m or less (haversine), `Registry::legs()->movePoint(orgId, truck, oldPoint, newPoint)` re-keys the owner's corrections to the new rounded point (`RoutingService` does it through `DriveOverrideRepository::movePoint`; the straight-line fallback does nothing); a longer move leaves them behind, and so does a failing `movePoint`, which is logged while the spot stays saved. `maps_url` = `MapsUrl::point`.

**Spot value.** What `SpotService::ensureFresh` returns and the other services work with: the spot row of `SpotRepository` (2.2) with four keys added or dressed. `point` is `{lat, lng}`; `terms` is the `SpotTerms` with `spot_id` set; `vectors` is `{hidden, normal, prominent}` as complete `LocationVectors` exactly as in `Spot` (null when none are stored); `vectors_state` is as in `Spot`. So `spot.vectors[spot.terms.visibility]` goes to the model as it is, and the row's `vec_dataset`, `vectors_sha1` and `updated_at` are at hand for 5.7 and 5.8. `SpotService::terms` reads a row or a value.

**Host link rule.** `SpotService::resolveHostLink(array $truck, float $lat, float $lng, ?array $host, ?string $placeKey, ?string $placeType, array $sources): array` returns `{place_key, place_type, point_id}`. It is the whole host link rule of 03_DATA.md 6.2 plus `host_link_point` of 02_MODEL.md 4.4, in one place. It runs on create, on an update that moves the point or carries `terms.host`, before every vector recomputation (`refresh`, `refreshStale`, `ensureFresh`, `scripts/truck/refresh-spots.php`) and, without storing, in `simulate`. `placeKey` and `placeType` are the spot's `place_key` and `host_place_type` (in a request: the key the body sends and that place's type). `sources` are the Q1 source points of the capture that follows (`Registry::capture()->sources()`); Q5 is read through `Registry::capture()->place()` and Q3 through `Registry::capture()->hostsNear(..., 100.0)`. `A` is `Seeds::defaults()`: `host.venue_link_radius_m` is a fixed-scope seed. While the truck's region has no active `ready` version nothing can be looked up, and the stored link and `point_id` are left as they are. "Nearest" is by `Estimator::haversineM` from the spot, compared on `qkey`, ties to the smaller `place_key`. A spot can be linked without a host (a place whose type has no `host_segment`): steps 1 and 2 then keep the link and its type, and `point_id` is null. Steps, in order, each on what the one before left:

1. `place_key` set and Q5 finds the row: `place_type` = the row's; `point_id = "p" + place_key` when its `visitor_segment` is not null, else null.
2. A stored `place_key` that Q5 no longer finds (dangling after a dataset switch): take the nearest Q3 row within 100 m of the spot whose `place_type` equals the stored `host_place_type` (haversine, ties by `place_key`). Found: store the new key and apply step 1. None: `place_key` and `host_point_id` become `NULL` (`host_place_type` is kept as a label). An unknown `place_key` sent in a body stays V11.
3. No `place_key` and the host's segment starts with `v_`: take the nearest Q3 row within 100 m whose `visitor_segment` equals the host segment. Found: store its key and type, `point_id = "p" + place_key`.
4. Still no `point_id` and the host's segment group is visitors: `point_id = Estimator::hostLinkPoint(A, lat, lng, host, sources)` over the Q1 sources of this capture (seed `host.venue_link_radius_m`); `place_key` stays as it is (null, or the link of step 1 to a place that is not a visitor source).

The exclusion is then `Estimator::hostExclusion(A, host)`. `Spot.terms.host.point_id`, `.place_type` and `host_details.place_key` show the result.

```json
POST {"name":"Reston Town Center","point":{"lat":38.96,"lng":-77.36},"terms":{"visibility":"prominent","fee_pct":0.1,"fee_min":75}}
201 {"success":true,"data":{"spot":{"id":"b2f0...","name":"Reston Town Center","point":{"lat":38.96,"lng":-77.36},"address":"","county_fips":"51059","notes":null,
 "terms":{"spot_id":"b2f0...","visibility":"prominent","host":null,"fee_flat":0,"fee_pct":0.1,"fee_min":75,"allowed":null},"host_details":null,
 "vectors":{"hidden":{"...":"..."},"normal":{"...":"..."},"prominent":{"...":"..."}},"vectors_state":"fresh","logs":{"count":0,"last_date":null},
 "maps_url":"https://www.google.com/maps/search/?api=1&query=38.960000%2C-77.360000","archived":false}},"message":"Spot saved"}
```

### 4.9 `GET /api/truck/day-context`

Query: `from` (V7, default today in the truck's zone), `to` (V7, default `from` + 7 days; `to must not be before from`; more than 14 dates: `The date range must be at most 14 days`). Purpose: per civil date the holiday, the hourly weather records and the fuel price, as ready `DayContext` values with `treat_as = null`. The browser re-derives a context for an override with its own `day_context`.

Response: `{timezone, today, fuel: FuelInfo, forecast: {state: "fresh"|"stale"|"unavailable", generated_at: string?, generated_local: {date, minute}?, point: {lat, lng}, source: "National Weather Service (weather.gov)"}, days: [DayInfo]}`. `days[i].context.forecast` is 24 entries (`HourForecast` or `null`) or `null` when no period falls on that date (beyond about 6.5 days, or upstream down). `precip_prob` is `null` when the service gives none (never 0; 02_MODEL.md 9.1 item 10). The forecast is for the truck's base point (5.5). `forecast.generated_local` = `Clock::localOfInstant(generated_at, truck.timezone)`: the civil date and the minute of day of `generated_at` in the truck's zone, null when `generated_at` is null. Status 200, 409, 422.

```json
GET /api/truck/day-context?from=2026-10-08&to=2026-10-09
200 {"success":true,"data":{"timezone":"America/New_York","today":"2026-10-04","fuel":{"price_per_gal":4.195,"source":"eia","area":"R1Z","product":"EPMR","period":"2026-09-28"},
 "forecast":{"state":"fresh","generated_at":"2026-10-04T23:41:07+00:00","generated_local":{"date":"2026-10-04","minute":1181},"point":{"lat":39.003,"lng":-77.405},"source":"National Weather Service (weather.gov)"},
 "days":[{"date":"2026-10-08","holiday":null,"context":{"date":"2026-10-08","typical":false,"dow":3,"eff_dow":3,"holiday":null,"holiday_class":null,"treat_as":null,
   "day_type":["weekday","..."],"dow_factor":[1,1.08,"..."],"traffic_dow":3,"forecast":[null,{"hour":1,"temp_f":58,"precip_prob":10,"short_forecast":"Partly Cloudy","wind_mph":5},"..."],
   "fuel_price_per_gal":4.195,"fuel_price_source":"eia"}},{"date":"2026-10-09","...":"..."}]}}
```

### 4.10 Drive times: routes 18-21

`POST /drive-times` body:

| Field | Type | Rule |
|---|---|---|
| `points` | list | V8 with 2 and 60 of `{id, lat, lng}`. `id`: V5 with 64, unique (`points[{i}].id is repeated`). `lat`, `lng`: V10 |
| `mode` | string | V4 `loop, chain, matrix, pairs`. Default `loop`. `chain` = consecutive points; `loop` = chain plus last to first; `matrix` = every ordered pair of different points |
| `pairs` | list | required for `pairs`: V8 with 1 and 650 of `[from_id, to_id]`, ids must exist (V11). Any mode producing more than 650 pairs: `Too many legs in one request (at most 650)` |
| `tolls` | bool | V6, default `true` (ask Google for toll estimates) |
| `fetch` | bool | V6, default `true`. `false` serves the cache only |

Response `{legs: [DriveLeg], routing: {state, route_key, leg_ttl_days: 30, attribution}}` in pair order. A leg Google could not supply is a `straight_line` leg from `Estimator::fallbackLeg` with its `fallback_reason`: clearly labelled, never an error. Status 200, 409, 422, 429.

```json
POST {"points":[{"id":"base","lat":39.003,"lng":-77.405},{"id":"s1","lat":38.96,"lng":-77.36}],"mode":"loop"}
200 {"success":true,"data":{"legs":[
 {"from_id":"base","to_id":"s1","source":"google_routes","fetched_on":"2026-10-04","age_days":0,"distance_m":7805,"duration_s":600,"toll_state":"estimate","google_toll":3.75,"toll_source":"google",
  "override":null,"fallback_reason":null,"leg_input":{"source":"google","distance_m":7805,"duration_s":600,"override_minutes":null,"toll":3.75}},
 {"from_id":"s1","to_id":"base","source":"straight_line","fetched_on":null,"age_days":null,"distance_m":8012.82,"duration_s":526.315,"toll_state":"not_asked","google_toll":null,"toll_source":"none",
  "override":{"id":"9d1e...","minutes":14,"toll":null,"note":""},"fallback_reason":"quota","leg_input":{"source":"fallback","distance_m":8012.82,"duration_s":526.315,"override_minutes":14,"toll":0}}],
 "routing":{"state":"ok","route_key":"d","leg_ttl_days":30,"attribution":"Drive times and distances: Google Maps Platform. Kept for at most 30 days."}}}
PUT /api/truck/drive-times/overrides {"from":{"lat":38.96,"lng":-77.36},"to":{"lat":39.003,"lng":-77.405},"minutes":14,"toll":null}
200 {"success":true,"data":{"override":{"id":"9d1e...","from":{"lat":38.96,"lng":-77.36},"to":{"lat":39.003,"lng":-77.405},"minutes":14,"toll":null,"note":"","updated_at":"2026-10-04 23:58:40"}}}
```

Owner corrections (kept until the owner deletes them):

| Route | Request | Response | Status |
|---|---|---|---|
| `GET /drive-times/overrides` | | `{overrides: [{id, from: {lat,lng}, to: {lat,lng}, minutes: int?, toll: number?, note, updated_at}]}` (points are the rounded keys) | 200 |
| `PUT /drive-times/overrides` | `from`, `to` (V10, required), `minutes` (V3 1..600 or null), `toll` (V2 0..500 or null), `note` (V5 with 160). Both null: `Give minutes or toll`. Same rounded point: `from and to are the same place` | `{override}`; upsert on the rounded directed pair | 200, 422 |
| `DELETE /drive-times/overrides/{id}` | | `{id, deleted: true}` | 200, 404 `Correction not found` |

### 4.11 Plans: routes 22-28

| Route | Request | Response | Status |
|---|---|---|---|
| `GET /plans` | `from`, `to` (V7; default today - 7 and today + 21; `to must not be before from`; at most 92 days: `The date range must be at most 92 days`). `from` = `to` reads the plan of one date. `stops`: flag `1` or `0`, default `0` | `{plans: [{id, date, name, treat_as, status, stop_count, result_state, summary: {orders: Estimate, take_home: Estimate, day_hours: number}?, updated_at}]}` ordered by date. With `stops=1` each row is a `Plan` without `result` and `context`: `{id, date, name, treat_as, notes, status, stops: [PlanStop], stop_count, result_state, evaluated_at, summary?, maps_route_url, created_at, updated_at}` | 200 |
| `POST /plans` | body below | `{plan: Plan}` evaluated and stored, message `Plan saved`. One plan per date: a second one answers 409 `A plan already exists for this date` (the client then uses PUT) | 201, 409, 422 |
| `POST /plans/evaluate` | body below (`name`, `notes`, `status` ignored) | `{result: DayResult, context: EvalContext}`; nothing stored | 200, 422 |
| `GET /plans/{id}` | | `{plan: Plan}`; `result` and `context` are null unless `result_state` is `fresh` or `stale` | 200, 404 `Plan not found` |
| `PUT /plans/{id}` | subset of the body; `stops` replaces all stops; moving `date` onto a date that has a plan answers the same 409 | `{plan: Plan}` re-evaluated | 200, 404, 409, 422 |
| `DELETE /plans/{id}` | | `{id, deleted: true}`; logs keep their numbers, their plan link is cleared | 200, 404 |
| `POST /plans/{id}/evaluate` | | `{plan: Plan}` with a new snapshot | 200, 404 |

| Body field | Type | Rule |
|---|---|---|
| `date` | date | required on create, V7 |
| `name`, `notes` | string | V5 with 120; V5 with 4000 |
| `treat_as` | string or null | V4 `normal, holiday, mon, tue, wed, thu, fri, sat, sun` |
| `status` | string | V4 `draft, planned, done, cancelled`. Default `draft` |
| `stops` | list | V8 with 0 and 8. Array order is the visiting order (the server never reorders) |
| `stops[i].id` | string | optional; kept when it is a stop of this plan, otherwise a new UUID |
| `stops[i].kind` | string | required, V4 `spot, event, catering` |
| `stops[i].spot_id` | string | required for `spot`; V11 (archived spots are accepted) |
| `stops[i].point`, `.label`, `.address` | object, string, string | for `event` and `catering`: `point` required (V10), `label` V5 with 120, `address` V5 with 255 |
| `stops[i].open_minute`, `.close_minute` | int | required, V3 0..2880, `stops[{i}].close_minute must be after open_minute` |
| `stops[i].gap_before_unpaid` | bool | V6, default false |
| `stops[i].setup_minutes`, `.teardown_minutes` | int or null | V3 0..240 |
| `stops[i].fee_flat`, `.fee_min`, `.fee_pct` | number | event stops only: V2 0..100000, 0..100000, 0..1 |
| `stops[i].event` | object | required for `event`: `attendance` V2 1..2000000, `vendors` V3 1..500, `event_type` V4 `general, food_focused, evening_show, incidental` |
| `stops[i].catering` | object | required for `catering`: `headcount` V2 1..100000, `price_per_head` V2 0..1000 or null, `guarantee` V2 0..1000000 or null, `food_cost` V2 0..1000000 or null. Neither price nor guarantee: `stops[{i}].catering needs price_per_head or guarantee` |

`GET /plans` starts with `PlanRepository::purgeExpiredSnapshots($orgId)` (5.8). With `stops=1` it costs one extra query for the stops of the listed plans (`WHERE organization_id = ? AND plan_id IN (...) ORDER BY plan_id, seq`) and one `SpotRepository::findMany` for the route links; the repository call is `PlanRepository::listRange(..., $withStops = true)`.

Overlapping or unreachable stops are not validation errors: the model reports them as warnings in `result.warnings` (02_MODEL.md 4.12). Map paths: `plan.result.warnings.*.data`, `result.warnings.*.data`.

```json
POST {"date":"2026-10-08","status":"planned","stops":[{"kind":"spot","spot_id":"b2f0...","open_minute":660,"close_minute":840},{"kind":"spot","spot_id":"c7a1...","open_minute":1020,"close_minute":1200}]}
201 {"success":true,"data":{"plan":{"id":"e1d4...","date":"2026-10-08","status":"planned","stops":["..."],"result_state":"fresh","evaluated_at":"2026-10-04 23:50:12",
 "result":{"model_version":"tps-0.1.0","seeds_revision":1,"date":"2026-10-08","timeline":{"start_prep":574,"done":1251,"...":"..."},"stops":["..."],
  "totals":{"take_home":{"value":482.2,"low":42.35,"high":1011.86,"confidence":"rough"},"...":"..."},"unpaid_gap_alternative":{"...":"..."},"warnings":[{"code":"long_gap","level":"warn","stop_index":1,"data":{"gap_before_minutes":120}},"..."]},
 "context":{"uses_google_legs":false,"...":"..."},"maps_route_url":"https://www.google.com/maps/dir/?api=1&..."}},"message":"Plan saved"}
```

### 4.12 Suggestions: `POST /api/truck/suggest/day`, `POST /api/truck/suggest/week`

| Field | Type | Rule |
|---|---|---|
| `date` (day) | date | required, V7 |
| `week_start` (week) | date | required, V7, `week_start must be a Monday` |
| `treat_as` (day) / `treat_as` (week) | string / object | V4 as plans; for the week an object `{ "<date>": <value> }` (V9) |
| `spot_ids` | list | optional subset, V8 with 1 and 200, each V11. Default: all active spots |
| `options` | object | `SuggestOptions`: `service_minutes` V3 60..480 and `options.service_minutes must be a multiple of 60`; `max_stops_per_day` V3 1..3; `max_days_per_week` V3 1..7; `max_visits_per_spot_per_week` V3 1..7; `limit` V3 1..10 |

Day response: `{suggestions: [Suggestion], spots_considered: int, fallback_pairs: int, context: DayContext}`. Week response: `{week: WeekSuggestion, spots_considered: int, fallback_pairs: int}`. To bound the payload every `DayResult` inside a suggestion has `stops[*].window.hours` emptied (`[]`); the browser evaluates a chosen suggestion with its own estimator (05_FRONTEND.md 4.5 rule 6); route 24 gives the same result to other clients. With no active spot: 200 with an empty list (day) or seven `null` suggestions (week). Status 200, 409, 422, 429. Map paths: `week.visits`, `suggestions.*.result.warnings.*.data`, `week.days.*.suggestion.result.warnings.*.data`.

```json
POST /api/truck/suggest/day {"date":"2026-10-08"}
200 {"success":true,"data":{"suggestions":[{"date":"2026-10-08","position":1,"stops":[{"spot_id":"b2f0...","open_minute":660,"close_minute":840},{"spot_id":"c7a1...","open_minute":1020,"close_minute":1200}],
 "take_home":{"value":482.2,"low":42.35,"high":1011.86,"confidence":"rough"},"orders":{"...":"..."},"day_minutes":677,"result":{"...":"..."}}],"spots_considered":2,"fallback_pairs":0,"context":{"...":"..."}}}
```

### 4.13 Service logs: routes 31-35

| Route | Request | Response | Status |
|---|---|---|---|
| `GET /services` | `from`, `to` (V7, default today - 90 and today; `to must not be before from`; `The date range must be at most 730 days`), `spot_id` | `{services: [ServiceLog]}` newest first (`service_date` desc, `id` asc) | 200, 422 |
| `POST /services` | body below | `{service: ServiceLog, calibration: CalibrationState}`, message `Service logged` | 201, 409 `A service is already logged for this spot and time`, 422 |
| `GET /services/{id}` | | `{service: ServiceLog}` | 200, 404 `Service not found` |
| `PUT /services/{id}` | subset | `{service, calibration}`; the prediction is rebuilt when spot, date, window or `treat_as` changed | 200, 404, 409, 422 |
| `DELETE /services/{id}` | | `{id, deleted: true, calibration}` | 200, 404 |

| Body field | Type | Rule |
|---|---|---|
| `kind` | string | V4 `spot, event, catering`. Default `spot` |
| `spot_id` | string | required for `spot`, V11 |
| `date` | date | required, V7. Later than today in the truck's zone: `date must not be in the future` |
| `open_minute`, `close_minute` | int | required, V3 0..2880, `close_minute must be after open_minute` |
| `actual` | int | required, V3 0..5000 (orders served) |
| `sales` | number or null | V2 0..1000000 |
| `sold_out` | bool | V6, default false ("sold out or at capacity": a lower bound for calibration) |
| `notes` | string or null | V5 with 2000 |
| `plan_stop_id` | string or null | V11; sets `plan_id` too |
| `treat_as` | string or null | V4 as plans. Default: the linked plan's value |

`source` is always `manual` and `external_key` null through this API (later import layers write other values under the unique key). Each log stores the prediction it will be judged against (5.7). Map path: `calibration.spots`.

```json
POST {"spot_id":"b2f0...","date":"2026-10-01","open_minute":660,"close_minute":840,"actual":52,"sales":801.5}
201 {"success":true,"data":{"service":{"id":"77aa...","kind":"spot","date":"2026-10-01","actual":52,"sales":801.5,"sold_out":false,"source":"manual",
 "prediction":{"predicted_raw":60.49,"predicted":60.49,"low":33.01,"high":93.19,"confidence":"rough","basis":"log","model_version":"tps-0.1.0","seeds_revision":1,"dataset_version":"dc-20261003-3fa9c2d1","detail":{"...":"..."}}},
 "calibration":{"truck_factor":0.97,"truck_n":1,"spots":{"b2f0...":{"factor":0.97,"log_factor":-0.03,"n":1,"weight":0.98}},"...":"..."}},"message":"Service logged"}
```

### 4.14 `GET /api/truck/calibration`, `GET /api/truck/accuracy`

Calibration -> 200 `{calibration: CalibrationState, as_of, log_count: int, eligible_count: int, raw_recomputed: int}`. Accuracy query `from`, `to` (V7, optional) -> 200 `{accuracy: AccuracyReport, entries: [ServiceLogEntry], unscored_without_prediction: int}`: only logs that carry a full prediction enter the report. Both: 409 without a truck. Map path: `calibration.spots`.

```json
GET /api/truck/calibration
200 {"success":true,"data":{"calibration":{"model_version":"tps-0.1.0","seeds_revision":1,"as_of":"2026-10-04","truck_factor":0.963784,"truck_log_factor":-0.045458,"bias_log":0.00857,"truck_n":6,"truck_weight":4.457955,
 "spots":{"<spot A>":{"factor":1.034623,"log_factor":0.034037,"n":3,"weight":1.992693}},"resid_sd":0.180334,"resid_n":5,"resid_weight":3.67789},"as_of":"2026-10-04","log_count":7,"eligible_count":6,"raw_recomputed":0}}
GET /api/truck/accuracy
200 {"success":true,"data":{"accuracy":{"n_total":7,"n_scored":6,"n_sold_out":1,"bias":0.068548,"mape":0.196514,"coverage":1,"raw_bias":0.068548,"raw_mape":0.196514,"by_spot":["..."]},"entries":["..."],"unscored_without_prediction":0}}
```

### 4.15 Scout: routes 38-41

`GET /scout` query: `hide` (comma list, each item V4 over the six lead statuses, default `hidden` when the parameter is absent; an empty value, `hide=`, is the empty list and hides nothing, which is what the browser sends when the owner ticks every status; these leads are removed before ranking), `refresh` (`1` skips the result caches). Response: `{candidates: [ScoutCandidate], screened: int, truncated: bool, limit_minutes: int, licence_counties: [string], dataset_version, cached: bool, attribution: [string]}` with at most `scout.max_results` (50) candidates in rank order. `attribution` holds strings 1 and 2 of 03_DATA.md 14 and the drive-time string 9. `maps_url` is present on **every** candidate: `MapsUrl::place(name, google.place_id)` when a place id is stored, else `MapsUrl::point(lat, lng)`. Status 200; 409 `Scouting needs a loaded region` when the truck's region is `none` or `not_loaded`; 409 `Region data was built with different model constants` when it is `build_mismatch` (5.2); 429.

| Route | Request | Response | Status |
|---|---|---|---|
| `PUT /scout/leads/{place_key}` | `status` (V4 `new, shortlisted, contacted, booked, declined, hidden`), `notes` (V5 with 4000, nullable); V12 when neither | `{lead: Lead}`; creates the row on first touch | 200, 404 `Place not found`, 422 |
| `POST /scout/leads/{place_key}/contact` | `force` (V6, default false) | `{lead: Lead, lookup: "found"\|"not_found"\|"cached"}` | 200, 404, 429, 503 `Contact lookup is not available on this server` |
| `POST /scout/leads/{place_key}/spot` | optional `name` (V5 120), `visibility` (V4), `host_size` (V2 1..200000), `only_food` (V6) | `{spot: Spot, lead: Lead}`, message `Spot saved` | 201, 404, 409 `This place is already saved as a spot`, 422 |

`{place_key}` must be a row of the truck's region and active version with `in_region = 1` and `host_fit > 0`, else 404. The test reads the place's Q7 row: `host_fit > 0` and a non-empty `county_fips`, which only `in_region = 1` rows carry (03_DATA.md 5.5). Save-as-spot creates a spot at the place's point with host `{segment: host_segment of the type, size: host_size from the body, else the place's size_default, size_source: "owner" or "default", only_food: given or (kitchen resolved = "no")}`, `place_key` linked (the host link rule of 4.8 applies), `host_details` from the OpenStreetMap columns (`name`, `phone`, `website`) plus the lead's `google_place_id`, which travels as the last argument of `SpotService::create` because a spot body never carries one; it sets `lead.spot_id` and moves a `new` lead to `shortlisted`. When the type has a `host_segment` and the place's `size_default` is 0 (office parks, apartment communities, industrial sites, stadiums, campus buildings and points), the body must carry `host_size`: `host_size is required for this kind of place`. A type without `host_segment` (`farmers_market`) is saved without a host, and `host_size` and `only_food` are ignored.

```json
GET /api/truck/scout
200 {"success":true,"data":{"candidates":[{"result":{"place_id":"w264230766","place_type":"taproom","position":1,"host_fit":1,"kitchen":"no","host_segment":"v_nightlife","host_size":40,"size_source":"default",
  "best_window":{"dow":5,"open_minute":1020,"close_minute":1200},"orders":{"value":21.52,"low":6.05,"high":42.76,"confidence":"very_rough"},"contribution":{"...":"..."},"round_trip":{"minutes":22,"miles":9.7,"cost":19.04},"score":186.2},
  "place":{"place_key":"w264230766","name":"Example Brewing","place_type":"taproom","lat":39.01,"lng":-77.41,"county_fips":"51107","phone":null,"website":"https://example.com","kitchen":"unknown","...":"..."},
  "lead":{"id":null,"place_key":"w264230766","status":"new","notes":null,"spot_id":null,"google":null},"maps_url":"https://www.google.com/maps/search/?api=1&query=39.010000%2C-77.410000","leg_sources":{"out":"google_routes","back":"google_routes"}}],
 "screened":3120,"truncated":false,"limit_minutes":45,"licence_counties":["51107","51059"],"dataset_version":"dc-20261003-3fa9c2d1","cached":false,"attribution":["..."]}}
PUT /api/truck/scout/leads/w264230766 {"status":"contacted","notes":"Spoke to the taproom manager, call back Tuesday"}
200 {"success":true,"data":{"lead":{"id":"5c0e...","place_key":"w264230766","status":"contacted","notes":"Spoke to the taproom manager, call back Tuesday","spot_id":null,"google":null}}}
POST /api/truck/scout/leads/w264230766/contact {}
200 {"success":true,"data":{"lead":{"id":"5c0e...","place_key":"w264230766","status":"contacted","notes":"...","spot_id":null,
 "google":{"place_id":"ChIJ...","lookup_state":"found","name":"Example Brewing Co","address":"1 Example Rd, Sterling, VA 20166","phone":"(703) 555-0100","website":"https://example.com/","maps_uri":"https://maps.google.com/?cid=1","fetched_on":"2026-10-04"}},"lookup":"found"}}
POST /api/truck/scout/leads/w264230766/spot {"host_size":80}
201 {"success":true,"data":{"spot":{"id":"d9b3...","name":"Example Brewing","terms":{"visibility":"normal","host":{"segment":"v_nightlife","size":80,"size_source":"owner","only_food":true,"point_id":"pw264230766","place_type":"taproom"},"...":"..."},"...":"..."},"lead":{"status":"contacted","spot_id":"d9b3...","...":"..."}},"message":"Spot saved"}
```

### 4.16 `GET /api/truck/export`, `POST /api/truck/data/delete`, `GET /api/truck/sources`

**Export.** One JSON document streamed straight to the response (never written under `storage/exports`, whose download route does not check ownership [R]). Headers: `Content-Type: application/json; charset=utf-8`, `Content-Disposition: attachment; filename="truck-planner-export-YYYYMMDD.json"` (today in the truck's zone), `Cache-Control: no-store`, CORS headers. Not the envelope. Written in pieces with `flush()` after each 200 rows read through `TruckDataRepository::page` (keyset on `id`):

```
{ "export": "truck-planner", "export_version": 1, "exported_at": "<UTC ISO 8601>", "model_version": "tps-0.1.0", "seeds_revision": 1,
  "truck": TruckRecord, "overrides": { ... }, "spots": [Spot without vectors], "plans": [Plan without result/context, plus "summary" when fresh],
  "services": [ServiceLog], "drive_overrides": [ ... ], "scout_leads": [ {place_key, place_name, place_type, lat, lng, status, notes, spot_id, google_place_id} ],
  "attribution": [string 2 of 03_DATA.md 14], "incomplete": false }
```

Google content (cached legs, leg details inside plan snapshots, `g_*` contact fields) is not exported. If a read fails after the headers went out, the writer closes the open list, ends with `"incomplete": true` and logs the reason. Status 200, 409 without a truck.

**Delete.** Only the account owner or an admin may call it (`$tpOwner`, section 3): any other member gets 403 `Requires role: owner/admin`. Body `{"confirm": "delete my truck data"}`; anything else -> 422 `confirm must be exactly: delete my truck data`. Deletes the organization's rows of `tp_service_logs`, `tp_plan_stops`, `tp_plans`, `tp_scout_leads`, `tp_drive_overrides`, `tp_spots`, `tp_trucks` in that order in one transaction, then calls `TpCache::forgetPrefix` for `tp:scout:s:{org}`, `tp:scout:r:{org}` and `tp:suggest:{org}`. The daily counter `tp:routes:day:{org}` is not flushed: deleting data must not reset the Google budget. Untouched: the account and organization, shared reference data, `tp_drive_legs` (no tenant), `api_usage_log`. Response 200 `{deleted: {services: n, plan_stops: n, plans: n, leads: n, drive_overrides: n, spots: n, trucks: n}}`, message `Truck data deleted`. Without a truck: 200 with zeros.

**Sources.** 200 `{model_version, seeds_revision, region: RegionInfo?, dataset: {dataset_version, pipeline_version, corrections_version, places_source, vintages, counts: {points, places, cells}, totals: {residents, jobs}, warn_gates: [gate ids]}?, fuel: FuelInfo?, contact: string, attribution: [{id: 1..12, text, url: string?}]}`. `contact` is the address for the ODbL offer. `dataset` is read from `manifest_json` of the active version (keys `inputs`, `vintages`, `counts`, `totals`, `gates`). `attribution` is the twelve strings of 03_DATA.md section 14 in id order, every placeholder filled as that section's table says: `{osm_snapshot_date}`, `{census_year}` (the first four characters of `vintages.census_reference_date`) and `{lodes_year}` from `manifest_json.vintages`; `{blocks_adjusted}` and `{jobs_spread}` (a whole number through `Estimator::roundHalfAway`) from `manifest_json.totals`; `{corrections_version}` from `manifest_json.inputs`; `{cns04_weight_percent}` = `manifest_json.parameters.cns04_weight` x 100 without decimals; `{contact}` (`TP_CONTACT_EMAIL`, else `MAIL_FROM`); `{period}` (the `period` of the fuel price shown). A string whose placeholder cannot be filled (no dataset, no fuel period) is left out. String 11 is left out while the traffic table is neutral, which it is when every value of `traffic.<A.region.traffic_matrix>` and its `_typical` value equal 1.0. `url`: the OpenStreetMap copyright page (`https://www.openstreetmap.org/copyright`) for 1, 2 and 10, the LEHD page (`https://lehd.ces.census.gov/data/`) for 4, else null.

```json
POST /api/truck/data/delete {"confirm":"delete my truck data"}
200 {"success":true,"data":{"deleted":{"services":12,"plan_stops":2,"plans":1,"leads":3,"drive_overrides":1,"spots":5,"trucks":1}},"message":"Truck data deleted"}
GET /api/truck/sources
200 {"success":true,"data":{"model_version":"tps-0.1.0","seeds_revision":1,"region":{"region_id":"dc","...":"..."},
 "dataset":{"dataset_version":"dc-20261003-3fa9c2d1","pipeline_version":"tp-etl-1.0.0","corrections_version":"2026-10-04.1","places_source":"geofabrik",
  "vintages":{"census_reference_date":"2020-04-01","lodes_year":2023,"osm_snapshot_date":"2026-10-03"},"counts":{"points":60600,"places":25300,"cells":61228},"totals":{"residents":6278542,"jobs":3140158},"warn_gates":["G15"]},
 "fuel":{"price_per_gal":4.195,"source":"eia","area":"R1Z","product":"EPMR","period":"2026-09-28"},"contact":"owner@example.com",
 "attribution":["...",{"id":3,"text":"Residents: U.S. Census Bureau, 2020 Census Redistricting Data (Public Law 94-171). Counts as of April 1, 2020, not adjusted for growth.","url":null},"..."]}}
```

`counts.cells` in this example is the prototype figure of 03_DATA.md 7.2 (61,228 cells). The first pipeline run re-measures it.

## 5. Services

### 5.1 Capture service

`locate(regionId, lat, lng, version = null) -> Located` and `capture(regionId, lat, lng, visibilities, host, version = null) -> {located, vectors: {<visibility>: LocationVectors}, outlets, outlets_total, hosts_nearby}`. Q0 to Q5 are the statements of 03_DATA.md 9.3 and 6.2.

1. `RegionService::active(regionId)`. Region `none` or no active `ready` version: `located = {in_region: false, region_id: null, county_fips: null, state: null}`, zero vectors (`points_used: 0`), empty lists, no query. An explicit `version` (the loader's self-check, 03_DATA.md section 10 step 10) is used as given and skips the `ready` and build tests (the pack row is still `loading` and `kernel_json` unwritten); the region definition is then read with `RegionRepository::find`, and a region without a row has nothing to read. The region bbox is never tested. An active `ready` version that is `build_mismatch` makes `capture()` throw `TpConflict` (5.2); `locate()` needs only a `ready` version.
2. Box for radius `r` (03_DATA.md 9.3): `dLat = rad2deg(r / 6371008.8) * 1.01`, `dLng = dLat / max(0.01, cos(deg2rad(lat)))`. Run Q1 (source points) and Q2 (rivals) with `r = kernel.walk_cutoff_m`, positional rows in the column order of 03_DATA.md 9.3, floats bound with `Sql::f`. `A` here is `Seeds::defaults()`: capture reads only build- and fixed-scope seeds, so the owner's overrides cannot change vectors.
3. Locate is Q4: blocks within r = 2,400 m, nearest by haversine, ties first in `point_id` order. No row: `in_region: false`, county and state null. A row: `in_region = (row.in_region == 1)`, `county_fips = substr(src_ref, 0, 5)`, `state` = the upper-case `usps` of the `config_json.states` entry whose `fips` equals `substr(src_ref, 0, 2)`.
4. Vectors are always computed from the Q1 and Q2 rows; `in_region` is only the label on `Located` and on each `LocationVectors`.
5. Sources: Q1 rows as `SourcePoint {id: point_id, lat, lng, base: [16 floats], rivals: {day, eve}}`. Outlets: Q2 rows as `Outlet {id: place_key, lat, lng, kind: rival_kind}`. SQL only narrows; the model applies the cutoff.
6. `exclusion = Estimator::hostExclusion(A, host)`. For each requested visibility `Estimator::captureAtPoint(A, lat, lng, v, sources, outlets, exclusion)`; then set `in_region` (the label of step 3), `region_id`, `dataset_version` and `model_version` on the result. Whenever the region's rows were read, `region_id` and `dataset_version` are set on every `LocationVectors` and `region_id` on `Located` (that shape has no version), also for a point with no block in reach.
7. `outlets`: Q2 rows within the cutoff with `distance_m = Estimator::roundHalfAway(d, 1)`, sorted by `qkey(d)` then `place_key`, first 60; `outlets_total` is their number before the cut, and `kitchen` is the stored three-state value. `hosts_nearby`: Q3 with `r = 250` (first-page key `''`, one page), the rows within 250 m in the same ordering, first 10; `point_id` = `"p" + place_key` when `visitor_segment` is not null, else null; `default_size` = the place's `size_default`; `host_segment` = `place_types.rows.<type>.host_segment` of the seed file; `kitchen` = the stored value when it is `yes` or `no`, else the type's `kitchen_default`.

The three readers of the contract serve the host link rule of 4.8. Like `locate()` they need only a `ready` version; for region `none` or without such a version they return `[]`, `null` and `[]`. `sources(regionId, lat, lng, version = null)` returns the Q1 rows of step 2 as the `SourcePoint` list of step 5. `place(regionId, placeKey, version = null)` returns the Q5 row of the key (`place_key`, `place_type`, `visitor_segment`, `lat`, `lng`) with `size_default` and `kitchen` added from the place's Q7 row, or null when Q5 finds none. `hostsNear(regionId, lat, lng, r, version = null)` returns the Q3 rows within `r` metres as `{place_key, place_type, visitor_segment, lat, lng, distance_m}`, in the ordering of step 7. `CaptureService` keeps the Q1 and Q2 rows of the last point it read (same region, version and coordinates), so the `capture()` that follows `sources()` does not query them again.

This document adds three statements on `tp_places` to those of 03_DATA.md:

```sql
-- Q6 (Scout page query, hostVectorPage): a projection of Q3 with the same WHERE, ORDER BY and paging,
-- plus host_vec IS NOT NULL and the optional county list. The IN list is present only when licence counties are set.
SELECT place_key, place_type, lat, lng, county_fips, kitchen, visitor_segment, size_default, host_vec
  FROM tp_places
 WHERE region_id = ? AND dataset_version = ?
   AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?
   AND in_region = 1 AND host_fit > 0 AND host_vec IS NOT NULL
   AND county_fips IN (?, ?)
   AND place_key > ?
 ORDER BY place_key
 LIMIT 2000
-- Q7 (byKeys): display columns for up to 100 keys. The Q3 column list (03_DATA.md 9.3) without host_vec.
SELECT <Q3 columns without host_vec> FROM tp_places WHERE region_id = ? AND dataset_version = ? AND place_key IN (?, ...)
 ORDER BY place_key
-- Q8 (exportPage, scripts/truck/export-places.php only): every place of a version, a page at a time, without host_vec.
SELECT place_key, osm_type, osm_id, snapshot_date, place_type, geom_kind, in_region, county_fips, name, brand, lat, lng,
       rival_kind, visitor_segment, size_default, host_fit, kitchen, phone, website, addr_line, city, state_code, postcode,
       cuisine, opening_hours_raw, hours_mask, tags_json
  FROM tp_places
 WHERE region_id = ? AND dataset_version = ? AND place_key > ?
 ORDER BY place_key
 LIMIT 1000
```

Budget: one simulate is a handful of indexed box queries (Q1, Q2, Q4, the Q3 page of step 7 and, with a host, the reads of the host link rule) and at most three model calls over about 900 rows. Measured on the loaded `dc` region [R, medians of seven runs, PHP 8.2 and MySQL 8.0.45 on one laptop]: a capture for three visibility levels takes 27 ms at a downtown Washington block (636 source points and 747 outlets in the boxes) and 6 to 7 ms at an office area in Herndon and at a taproom in Sterling. Q1, Q2 and Q4 name their index (03_DATA.md 9.3): without that MySQL may read the whole dataset version, and Q4 alone then takes 32 to 37 ms at each of the three places and Q1 77 ms downtown. A web request never reads a region's points without a box.

### 5.2 Region service, loader and pack

**Usable region.** A region's active version is usable when `load_state = 'ready'`, its `model_version` equals the PHP model's, `kernel_json.kernel` equals `kernelFromSeeds()` and the manifest parameters equal the seed values. Comparisons are on decoded structures (numbers as doubles, lists and strings exactly, objects key by key); two numbers within two units in the last place of each other count as equal, because a `JSON` column can hand a long number back one unit off (section 0) and that is not another build. `kernel_json.seeds_revision` is informational. `RegionService::kernelMatches` is the kernel comparison alone (route 8, loader step 3); `buildScopeMatches` adds the manifest parameters (loader step 1, G19). `RegionService::active` reads Q0, then `load_state`, `model_version` and `kernel_json` of the active version. The kernel comparison runs once per PHP process (03_DATA.md 9.3: one small SELECT). Only the manifest-parameter verdict is cached: `TpCache` key `tp:regionok:{region}:{version}:{seeds_revision}`, value `{"ok": true|false}`, 24 hours, so `manifest_json.parameters` is read only when that entry is missing. `unusable_reason` is `not_loaded` (no active `ready` version) or `build_mismatch` (any other failure).

`not_loaded` keeps the region-`none` behaviour: zero vectors, no error. For `build_mismatch`, `CaptureService::capture()` throws `TpConflict` with the sentence `Region data was built with different model constants`, so routes 9, 11, 12, 14 (when vectors are recomputed), 16, 41 and, through `ensureFresh`, 23, 24, 26, 28, 29, 30 answer 409. Route 38 answers the same 409 for `build_mismatch` and keeps `Scouting needs a loaded region` for `none` and `not_loaded`. `locate()` needs only a `ready` version. Bootstrap and `GET /regions` return `usable: false`, the reason and `pack: null`. Route 8 follows 03_DATA.md 11.1: row missing or not `ready` -> 404 `Not found`; kernel difference on that row -> 409 `Region data was built with different model constants` (the refusal 02_MODEL.md 2.2 requires).

`kernelFromSeeds()` = `{earth_radius_m: constants.earth_radius_m, walk_decay_m, walk_cutoff_m, a0: kernel.outside_option_a0, visibility: kernel.visibility.normal, regime_of_hour: hours.regime_of_hour, rival_weights: {day: {<kind>: w}, eve: {<kind>: w}}}`: exactly the `kernel` block of the pack header (03_DATA.md 11).

**Loader.** `scripts/truck/load-region.php` drives `RegionLoader` through steps 1 to 13 of 03_DATA.md section 10 unchanged, including step 8a and the host sample of step 10 (gate G21). The loader has exactly the four command forms of that section (7.2). Host vectors are written as step 8a says: `RegionLoadRepository::setHostVec` binds `pack('e50', ...$v)` as `PDO::PARAM_LOB`. `A` in the loader is `Seeds::defaults()` with the region block of the manifest.

**Pack writer.** `CellPackWriter::build` implements 03_DATA.md 11 (prefix, JSON header, padding, `h3` section, 50 column-major `u16` sections, square-root quantisation). `decode` is the PHP mirror of 11.2 and is used by loader step 11 and by tests.

### 5.3 Routing service (Google Routes API)

**Request** [M: field names, limits and billing tiers must be checked against Google's current reference before coding].

```
POST https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix
Content-Type: application/json
X-Goog-Api-Key: <GOOGLE_API_KEY>
X-Goog-FieldMask: originIndex,destinationIndex,status,condition,distanceMeters,duration            (plus ",travelAdvisory.tollInfo" when tolls are asked)

{"origins":[{"waypoint":{"location":{"latLng":{"latitude":39.003,"longitude":-77.405}}},"routeModifiers":{"avoidTolls":false,"avoidHighways":true}}],
 "destinations":[{"waypoint":{"location":{"latLng":{"latitude":38.96,"longitude":-77.36}}}}],
 "travelMode":"DRIVE","routingPreference":"TRAFFIC_UNAWARE","extraComputations":["TOLLS"]}
```

`routeModifiers` (the same object on every origin) is sent only when a flag is true. This is the client specification; the [M] sketch in 03_DATA.md 13.3 that shows the object on every origin is superseded here (03_DATA.md section 13: clients are specified in 04). `extraComputations` is sent only when tolls are asked, which is `options.tolls` and not `avoid_tolls`. No departure time: the duration is deterministic. Coordinates are the rounded key values (`e4 / 10000.0`). A successful answer is HTTP 200 with a body such as `[{"originIndex":0,"destinationIndex":0,"status":{},"condition":"ROUTE_EXISTS","distanceMeters":7805,"duration":"600s","travelAdvisory":{"tollInfo":{"estimatedPrice":[{"currencyCode":"USD","units":"3","nanos":750000000}]}}}]`.

**Response parsing.** The body is a JSON array of elements in any order; an object (or an array item) with an `error` member is a failed call.

| Field | Rule |
|---|---|
| `originIndex`, `destinationIndex` | integers; **absent means 0** (default values are omitted) |
| `status` | `{}` when fine. A non-zero `status.code` fails that element only: straight line, reason `upstream`, not cached |
| `condition` | `ROUTE_EXISTS` -> a leg. `ROUTE_NOT_FOUND` -> cached with `route_found = 0`. Anything else -> element failed |
| `duration` | text matching `^(\d+)(\.\d{1,9})?s$`, for example `"713s"`. Seconds = the integer part, plus 1 when the first fractional digit is 5 or more. Missing or malformed on an existing route -> element failed |
| `distanceMeters` | integer metres; absent means 0 |
| `travelAdvisory.tollInfo` | absent -> `toll_state` 1 (`none`). Present with an `estimatedPrice` entry whose `currencyCode` is `USD` -> `toll_state` 2 and `toll_cents = Money::toCents((float) units + nanos / 1e9)` (`units` is a string, `nanos` an integer, either may be absent = 0). Present without a USD price -> `toll_state` 3 (`unknown`). Not asked -> 0 |

**Legacy second attempt** (only after a Routes refusal): `GET https://maps.googleapis.com/maps/api/distancematrix/json?origins=<lat,lng|lat,lng>&destinations=<...>&mode=driving&units=metric[&avoid=tolls|highways]&key=<GOOGLE_API_KEY>` built with `http_build_query`. HTTP 200 always; top-level `status` `OK` -> `rows[i].elements[j]` with `status` `OK` -> `duration.value` (s), `distance.value` (m); element `ZERO_RESULTS` or `NOT_FOUND` -> `route_found = 0`. Rows are stored with `src = 'google_distance_matrix'`, `toll_state` 0. The key is in this URL: the URL is never logged, stored or returned.

**Algorithm of `legs()`.**

1. `routeKey = LegKey::routeKey(profile)`. Each point gets its key `LegKey::of(lat, lng)`. A pair with equal keys is a `same_point` leg (0 m, 0 s) and needs nothing else.
2. Cache: `DriveLegRepository::findFresh(routeKey, pairKeys)`: `WHERE route_key = ? AND (o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4) IN ((?,?,?,?), ...) AND fetched_at >= NOW() - INTERVAL 30 DAY`, 200 pairs per statement. A row satisfies a pair when tolls are not needed, or `toll_state <> 0`, or its `src` is `google_distance_matrix` while Routes is marked refused (the legacy API has no toll data, so asking again would change nothing). Rows older than 30 days are never used, even when Google is unreachable.
3. Unsatisfied pairs are fetched when `UpstreamGuard` allows (below); with `options.fetch` false they become straight lines with reason `cache_only`. Batches: let `O` be the distinct origins and `D` the distinct destinations of the unsatisfied pairs and `n` their number. If `n >= 0.6 * |O| * |D|`, send the full grid `O x D` in chunks of at most 25 x 25 (625 elements [M]) and store every returned element whose two endpoints differ. Otherwise send one request per origin with exactly its missing destinations (chunks of 625). Legacy batches are at most 10 x 10 (100 elements [M]). Batches are issued in ascending order of the first origin key, one at a time.
4. Before each batch, in this order, a failed check turns the batch's pairs into straight lines with the reason shown: no key (`no_key`); Routes and legacy both refused within the hour (`refused`); back-off active (the reason stored with it, `quota` or `upstream`: `UpstreamGuard::backoffReason('routes')`); more than 12 s spent in this call (`timeout`); the organization's daily element budget or the global one would be exceeded, or 650 elements were already fetched in this call (`budget`); `takeTokens('tp_routes_elements', elements, 2)` false (`rate`).
5. Call. HTTP 200 -> parse, `upsertMany` (`INSERT ... ON DUPLICATE KEY UPDATE` with `fetched_at = NOW()`), spend budgets. HTTP 403 with `error.status = PERMISSION_DENIED` -> `markRefused('routes')` (1 hour), then **one** legacy attempt for the same batch unless legacy is refused; legacy `REQUEST_DENIED` -> `markRefused('legacy')`. While Routes is marked refused, batches go straight to legacy. HTTP 429 or `RESOURCE_EXHAUSTED`, legacy `OVER_QUERY_LIMIT` / `OVER_DAILY_LIMIT` -> `backoff('routes', 120, 'quota')`, reason `quota`. Time-out, connection failure, 5xx, unparsable body -> `backoff('routes', 30, 'upstream')`, reason `upstream` (`timeout` for a time-out). HTTP 400 -> log `[tp] routes bad request` with the redacted `error.message`, reason `upstream`. No retries.
6. Pairs still without a row: `Estimator::fallbackLeg(Seeds::defaults(), ...)` on the exact (unrounded) points -> `source: "straight_line"`, `leg_input.source: "fallback"`. A fallback is never written to `tp_drive_legs`.
7. Attach the owner's corrections (`findForPairs`) and build `DriveLeg` in pair order.
8. Every `legs()` call ends with `DriveLegRepository::purgeExpired(500)` (`DELETE FROM tp_drive_legs WHERE fetched_at < NOW() - INTERVAL 30 DAY LIMIT 500`): Google legs are deleted when their 30 days end, not only hidden.

| Setting (`config/truck_planner.php` -> `routing`) | Value |
|---|---|
| Timeouts | connect 3 s, total 8 s per call, 12 s per `legs()` call |
| Leg lifetime | 30 days (`fetched_at`), refreshed lazily on demand |
| Per-call fetch cap | 650 elements |
| Budgets per day | 3,000 elements per organization (`TpCache::add` counter `tp:routes:day:{org}:{YYYYMMDD}`, the date from `Clock::today('UTC')`, TTL 2 days; approximate under concurrent requests, 2.3), 20,000 for all tenants (`ApiLedger::unitsToday`). Placeholders: tune with real use |
| Token bucket | `tp_routes_elements`: capacity 1,250, 40 per second (2,400 elements per minute against Google's default 3,000 [M]); wait at most 2 s |
| Refusal memory | `TpCache` keys `tp:routes:refused:routes`, `tp:routes:refused:legacy`, TTL 3,600 s. Back-off `tp:routes:backoff`, TTL 120 or 30 s |

`status()` returns `no_key`, `refused` (both refused), `backoff`, or `ok`.

The in-app budgets (elements per organization and per day, the 650-element cap, the token bucket) limit what this server asks for. They are not a cost bound for the key: production builds the browser bundle with the same key (`scripts/droplet-deploy.sh`), so the bound is the quota set in the Google Cloud console (8.5 step 1).

**Ledger.** One `api_cost_events` row per HTTP call through `ApiLedger::record`: `sku`, `billable_units` (elements on a successful answer, else 0), `unit_cost_usd` from the config table below, `total_cost_usd` = units x unit cost, `http_status`, `latency_ms`, `error_message` = the transport or upstream code only (`http_403`, `PERMISSION_DENIED`, `timeout`), `field_mask_hash` = first 16 hex of the SHA-256 of the sorted mask tokens, `campaign_id` and `tile_id` null, `called_at` left to the column default. The campaign budget logic of `PlacesClient` is not used and that class is not referenced. `ApiLedger::record` inserts with `id = Database::uuid()` [R: `api_cost_events.id` is `CHAR(36)` with no default]: `INSERT INTO api_cost_events (id, sku, billable_units, unit_cost_usd, total_cost_usd, field_mask_hash, http_status, latency_ms, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`, the two costs bound as `Sql::f` strings. A caught failure is logged once per request as `[tp] ledger insert failed`.

| SKU | When | Unit cost USD |
|---|---|---|
| `tp_routes_matrix` | Routes, no modifiers, no tolls | 0.005 per element [R: `GooglePricing::COSTS['routes']`] |
| `tp_routes_matrix_pro` | Routes with route modifiers | 0.010 [M] |
| `tp_routes_matrix_ent` | Routes with `TOLLS` | 0.015 [M] |
| `tp_distance_matrix` | legacy attempt | 0.005 per element [R] |
| `tp_places_text` | contact lookup (5.4) | 0.035 per call [M] |
| `tp_nws_points`, `tp_nws_hourly`, `tp_eia_weekly` | weather and fuel | 0 |

### 5.4 Places contact lookup

On demand, one Scout candidate at a time (route 40).

```
POST https://places.googleapis.com/v1/places:searchText
Content-Type: application/json
X-Goog-Api-Key: <GOOGLE_API_KEY>
X-Goog-FieldMask: places.id,places.displayName,places.formattedAddress,places.nationalPhoneNumber,places.websiteUri,places.googleMapsUri

{"textQuery":"<place name>","languageCode":"en","pageSize":1,"locationBias":{"circle":{"center":{"latitude":39.01,"longitude":-77.41},"radius":500.0}}}
```

1. Lead with `g_fetched_at` younger than 30 days and not (`force` and older than 24 hours): return it, `lookup: "cached"`, no call. A stored `not_found` is cached the same way.
2. No key, `refused('places')` or `inBackoff('places')`: 503. `takeTokens('tp_places_lookup', 1, 2)` false: 429 `Too many lookups right now. Try again in a minute` (the service throws `TpRateLimited`).
3. Call with timeouts 3 s / 6 s. `places[0]` present -> upsert the lead: `google_place_id = id`, `g_name = displayName.text`, `g_address`, `g_phone`, `g_website`, `g_maps_uri` (each cut to its column length, a longer `websiteUri` or `googleMapsUri` is dropped), `g_lookup_state = 'found'`, `g_fetched_at = NOW()`. Empty result -> `g_lookup_state = 'not_found'`, `g_fetched_at = NOW()`. 403 -> `markRefused('places')`, 503. Other failures -> 503 and `backoff('places', 60)`.
4. The matched `name` and `address` are returned so the owner can see what Google matched: the mask has no location, so the server cannot check distance (C12).

Storage rule. The place id may stay. `g_name`, `g_address`, `g_phone`, `g_website`, `g_maps_uri` are treated as absent 30 days after `g_fetched_at` and are set to `NULL` by `ScoutLeadRepository::purgeExpiredGoogle` (called for the organization at the start of every `GET /scout`, and for everyone by `purge-google-cache.php`). They are never copied to `tp_spots`, never written to `tp_places`, never exported.

### 5.5 Weather client and day contexts

`WeatherClient::hourly(lat, lng)` -> `{state, generated_at, periods}`:

1. Round both coordinates to 4 decimals. `GET https://api.weather.gov/points/{lat},{lng}`, headers `User-Agent: (TruckPlanner, <contact>)` and `Accept: application/geo+json` (contact = `TP_CONTACT_EMAIL`, else `MAIL_FROM`; with neither, no request is made and the result is `state: 'unavailable'`). Never send `Feature-Flags`. Cache `{gridId, gridX, gridY}` through `TpCache` under `tp:nws:pt:{lat},{lng}` for 14 days. A 404 (`InvalidPoint`) is cached as "no coverage" for 24 hours.
2. `GET https://api.weather.gov/gridpoints/{gridId}/{gridX},{gridY}/forecast/hourly`. Keep per period only `startTime`, `temperature`, `temperatureUnit`, `probabilityOfPrecipitation.value`, `windSpeed`, `shortForecast`. Cache through `TpCache` under `tp:nws:hr:{gridId}:{gridX},{gridY}` for 7 days as `{fetched: <epoch>, expires: <epoch>, generated_at, periods}`, with `fetched` = `Clock::epoch()`. The copy is `fresh` until `max(expires, fetched + 600)`; `expires` = `Clock::parseHttpDate($headers['expires'] ?? '')` (format `D, d M Y H:i:s \G\M\T`, UTC; null when absent or malformed), else fetched + 3,600.
3. Not fresh: fetch (timeouts 3 s / 6 s), one retry after 1 s on 5xx or time-out. Still failing: serve the cached copy as `stale` while it is younger than 6 hours, else `unavailable` with no periods. Failures never raise.

`DayContextService::contexts(truck, A, from, days, treatAs)`:

1. Forecast for the truck's **base point** only (one grid cell per truck: no request parameter can multiply upstream calls).
2. Each period maps to the civil date and wall-clock hour written in its `startTime` (03_DATA.md 13.1): date = characters 0 to 9, hour = characters 11 and 12 as an integer. Periods are taken in array order and **the first one for a (date, hour) wins**, so the repeated hour of a 25-hour day keeps its first occurrence and the missing hour of a 23-hour day stays `null`. A malformed `startTime` is skipped. No time-zone conversion and no date parsing here.
3. `HourForecast = {hour, temp_f, precip_prob, short_forecast, wind_mph}`: `temp_f` = `(float) temperature` (converted with `c * 9.0 / 5.0 + 32.0` if the unit is `C`); `precip_prob` = the value or `null`; `wind_mph` = the largest integer found in `windSpeed` (`"5 to 10 mph"` -> 10.0) or `null`; `short_forecast` = the text when valid UTF-8, else `null`.
4. Fuel: `Registry::fuel()->resolve(truck)`.
5. Per date `d` = `Estimator::addDays(from, i)`: `context = Estimator::dayContext(A, d, treatAs[d] ?? null, forecast[d] ?? null, fuel.price_per_gal, fuel.source)`, `holiday = context.holiday`.

### 5.6 Fuel client and price resolution

`FuelPriceService::resolve(truck)` -> `FuelInfo`, first hit wins: (1) `profile.fuel_price_override` -> `source: "owner"`; (2) newest `tp_fuel_prices` row for `area` and `product` -> `"eia"` with its `period`; (3) `money.fuel_price_fallback.<fuel_type>.<area>` (unknown area: `NUS`) -> `"seed"` with the seed group's `as_of`. `area = RegionService::fuelArea(region_id, base_state)` = `config_json.fuel_area_by_state[base_state]`, else `NUS`. `product` = `EPMR` for gasoline, `EPD2D` for diesel.

`refreshIfDue()` is called by route 17 before resolving, and does nothing unless all hold: `EIA_API_KEY` is set; `TpCache` key `tp:eia:attempt` is absent (it is set for 6 hours **before** the call, so a failing upstream is tried at most four times a day); the newest stored period is older than the Monday of the current week in `America/New_York` (computed with `Estimator::dayOfWeek` and `addDays` from `Clock::today`), or, before Tuesday 10:00 there, older than the Monday before. Request (03_DATA.md 13.2): `GET https://api.eia.gov/v2/petroleum/pri/gnd/data/?` + `http_build_query` of `api_key`, `frequency=weekly`, `data[0]=value`, `facets[duoarea][]` = every area of every region's `fuel_area_by_state` plus `NUS`, `facets[product][]` = `EPMR`, `EPD2D`, `start` = that Monday minus 28 days, `sort[0][column]=period`, `sort[0][direction]=desc`, `offset=0`, `length=100`. Timeouts 3 s / 5 s. For each `response.data[]` row with a numeric `value` string: `price_milli = Money::toMilli((float) value)`, `series_id = series`, upsert on `(duoarea, product, period)`. The key travels in the URL, so the URL is never logged; the ledger row says `tp_eia_weekly` and the status only. Without a key nothing is requested and the stored or seed price is shown with its date.

### 5.7 Service logs, calibration, accuracy

**Prediction stored with a log** (computed in `ServiceLogService` before the insert):

1. A full prediction (all four numbers) is stored for `spot` logs only. An `event` or `catering` log that is plan-linked stores the plan's `predicted`, `low`, `high` for display and leaves `predicted_raw` null; an unlinked one stores none. Logs without a full prediction are left out of calibration and accuracy.
2. Basis `plan` (spot logs): `plan_stop_id` given, the plan has a snapshot that is not `expired`, the stop's spot equals the log's and `[effective_open, close)` of that stop in `result.timeline` equals the logged window. Then `predicted`, `low`, `high`, `confidence` = `result.stops[i].orders`, and the day contexts are the snapshot's `context.ctx` and `ctx_next` (the forecast the owner saw). A `stale` snapshot still gives basis `plan`: it is what the owner was shown when the day was saved; only an `expired` or missing snapshot falls back to `log`.
3. Basis `log` otherwise: contexts from `Registry::dayContexts()` for the date (forecast only if the date is still inside the forecast), `cal_before = Estimator::calibrate(A, entries dated strictly before the service date, as_of = service date)`, `W = Estimator::windowOrders(A, profile, terms, vectors, cal_before, ctx, ctx_next, open, close)`, `predicted`, `low`, `high`, `confidence` = `W.orders`.
4. Always: `predicted_raw = Estimator::windowOrders(..., cal = null, ...).orders.value` with the same contexts; `weather_json` = the two contexts' forecast arrays; `prediction_json` = `{basis, plan_id, ctx_date_types: ctx.day_type, holiday, terms, spread: W.spread, evidence: W.evidence}`; `pred_model_version`, `pred_seeds_rev`, `pred_dataset`; `pred_raw_basis` (below).

`predicted`, `pred_low`, `pred_high` are history (what the owner was shown) and are never recomputed. `predicted_raw` is what calibration judges against and follows 02_MODEL.md 4.13: `pred_raw_basis = sha1(JsonSafe::canonical([model_version, seeds_revision, overrides, capacity_orders_per_hour, daypart_fit, terms.visibility, terms.host, vec_dataset, sha1(vectors_bin), date, open, close, treat_as, weather_json]))`. `CalibrationService::ensureRawPredictions` recomputes `predicted_raw` (stored weather, stored `treat_as`, the spot's current terms and vectors) for every log whose stored basis differs from the current one, before calibrating, and reports the count. It is one `windowOrders` call per log, so even a few thousand logs take seconds, once, after a change of capacity, daypart fit, overrides, seeds or region data (`@set_time_limit(60)`).

**Calibration.** `state(orgId, truck, A, asOf)`: `ensureRawPredictions`, load `allForCalibration` as `ServiceLogEntry` (rows with a full prediction), `Estimator::calibrate(A, entries, asOf)`. `asOf` is today in the truck's zone. No cache: one indexed query and a pure function. **Accuracy**: the same entries, optionally filtered by date, through `Estimator::accuracyReport`.

### 5.8 Planning service

`evaluate(orgId, truck, A, plan)`:

1. `profile = truck.profile`; `cal = Registry::calibration()->state(orgId, truck, A, today)`.
2. Stops -> `StopInput` in order. Spot stop: load the spot (archived allowed), `SpotService::ensureFresh` (when `vectors_state` is not `fresh`: the host link rule of 4.8, then recompute and store the vectors; it returns the spot value of 4.8), `terms = SpotService::terms(spot)` with `spot_id` set, `vectors = spot.vectors[terms.visibility]`, `point` = `spot.point`. Event stop: `terms = {spot_id: null, visibility: "normal", host: null, fee_flat, fee_pct, fee_min, allowed: null}`, `event`. Catering stop: `catering`. `id` = the stop id; ids are UUIDs, never `base`.
3. Contexts from `Registry::dayContexts()->contexts(truck, A, date, 2, {date: treat_as})`: `ctx` for the date, `ctx_next` for the next date (always with `treat_as = null`: the override belongs to one civil date).
4. Legs: points `base` (the truck's base) and one per stop. The legs resolved before `dayPlan` are the keys of `Estimator::requiredLegKeys(stops)` (the loop plus the leg that skips each stop), each key `"<from_id>><to_id>"` split into the pair `[from_id, to_id]` and requested with mode `pairs`: `Registry::legs()->legs(orgId, truck, points, pairs, {tolls: true})`, then `RoutingService::legInputMap` keyed `"<from_id>><to_id>"`. `pairs('loop')` alone is not enough, because the model would fill the skip legs with straight lines and `adds` would differ from the browser's.
5. `result = Estimator::dayPlan(A, profile, {date, stops}, ctx, ctx_next, legs, cal)`.
6. `context` = `EvalContext`; `uses_google_legs` = any leg whose source starts with `google_`.

Stored snapshot: `result_json`, `context_json`, `result_has_google`, `evaluated_at = NOW()`, versions. `resultState`: `none` without a snapshot; `expired` when `result_has_google = 1` and `evaluated_at < NOW() - INTERVAL 30 DAY` (computed in the `find` query as `snapshot_expired`); `stale` when the model version, seeds revision or dataset version differ, or the truck row, any referenced spot or any service log changed after `evaluated_at`; else `fresh`. An expired snapshot is not returned and is set to `NULL` by `PlanRepository::purgeExpiredSnapshots` (for the organization at the start of every `GET /plans`, for everyone by `purge-google-cache.php`); the plan itself (date, stops, notes, status) is the owner's data and stays. Re-evaluation is always explicit (routes 23, 26, 28).

### 5.9 Scouting service

Exact scoring of every host with `Estimator::scoutEstimate` would cost seconds per hundred places, so ranking is a deterministic two-stage funnel: a cheap screen over all candidates, then the model function on a shortlist with real drive legs.

1. Inputs: `limit = profile.scout_drive_minutes_limit`, `counties = profile.licence_counties`, the leads to hide, `fuel = Registry::fuel()->resolve`, `cal = Registry::calibration()->state` (only its truck factor matters), the region's active version (the 409s of 4.15 when the region is `none`, `not_loaded` or `build_mismatch`).
2. Reach bound in metres: `B = 1.25 * limit / truck_time_factor`; `local = drive_fallback.local_miles / drive_fallback.local_mph * 60.0`; `miles = B * local_mph / 60.0` when `B <= local`, else `local_miles + (B - local) * trunk_mph / 60.0`; `r = miles * 1609.344 / detour_factor`. Box as in 5.1 around the base.
3. Candidates: Q6 pages (2,000 rows) into flat arrays; drop hidden leads; decode `host_vec`. Prefilter: `Estimator::fallbackLeg(A, base, place).duration_s / 60.0 * truck_time_factor <= 1.25 * limit`. More than 15,000 left: keep the 15,000 nearest (by `qkey` of the straight-line metres, then `place_key`) and set `truncated`.
4. Screen (`ScoutScreen::scores`, pure): with `W = Estimator::mapWeightRows(A, profile, cal).w_opp`, `cap = capacity_orders_per_hour`, `margin = Estimator::unitMargins(profile, zero-fee terms).at_minimum`, `L = floor(scout.window_minutes / 60)`, per candidate in `place_key` order (`place` is its Q6 row, `vec` its decoded `host_vec`):

```
row = seed("place_types.rows.<type>")
kitchen = place.kitchen when "yes" or "no", else row.kitchen_default
hc = Estimator::hostCapture(A, {segment: row.host_segment, size: place.size_default, size_source: "default", only_food: kitchen == "no", point_id: null},
                            "normal", {day: vec[48], eve: vec[49]})        when row.host_segment is not null and place.size_default > 0, else {day: 0.0, eve: 0.0}
for how in 0..167:  off = 0 when regime_of_hour[how mod 24] == "day" else 16
    o = sum over s in 0..15 of vec[off + s] * W[how][s];  with a host term: o += hc[regime] * W[how][index(row.host_segment)];  strip[how] = min(o, cap)
best = largest circular sum of L consecutive strip values
fb = Estimator::fallbackLeg(A, base, place);  ff = fb.duration_s / 60.0;  miles = fb.distance_m / 1609.344
trip = (2.0 * ff * seed("traffic.<matrix>_typical") * truck_time_factor / 60.0) * paid_crew * wage_per_hour * (1.0 + payroll_burden_pct) + 2.0 * miles / mpg * fuel_price
screen = 0.0 when qkey(best) == 0, else row.host_fit * margin * best - trip
```

5. Shortlist: the first `scout.max_results + 10` candidates by (`qkey(screen)` descending, `place_key` ascending). Cached through `TpCache` as `tp:scout:s:{org}:{sha1(JsonSafe::canonical(inputs))}` for 24 hours, where inputs = dataset version, seeds revision, overrides, profile, base key, limit, counties, hidden keys, truck factor, fuel price.
6. Legs for the shortlist: `Registry::legs()->legs(...)` for `base -> place` and `place -> base`, `{tolls: false}` (at most 120 elements when cold, then 30 days of cache).
7. Exact: `PlaceInput {place_id: place_key, place_type, point, point_id: "p" + place_key when visitor_segment is not null else null, size_default: place.size_default, kitchen, vectors: VectorCodec::fromBytes(host_vec) + {visibility: "normal", in_region: true, ...}}` -> `Estimator::scoutEstimate(A, profile, place, legs, cal, fuel.price_per_gal)`. Drop `null` results and results with `round_trip.minutes > 2 * limit` (this is what "inside the drive-time limit" means). `Estimator::scoutRank` gives the positions and the cut at `scout.max_results`. Cached through `TpCache` as `tp:scout:r:{org}:{sha1(shortlist key + canonical leg inputs)}` for 24 hours.
8. Decoration, never cached: Q7 display columns, leads (`forTruck`), `maps_url`, `leg_sources`.

Budget [S]: the screen measured 85 microseconds per candidate in PHP 8.2 (about 1 s for 12,000 places); cold call under 6 s for the whole region, warm call under 150 ms. `@set_time_limit(60)`.

### 5.10 Suggestion service

`day`: spots = the requested or all active spots with fresh vectors (`ensureFresh`, at most 200), as `SpotInput {spot_id, point, terms, vectors: spot.vectors[terms.visibility]}`. Legs: (a) fetch `base <-> spot` for every spot (`2N` elements, `{tolls: true}`); (b) `spot -> spot` pairs from the cache only (`{fetch: false}`); missing ones are simply absent from the map and the model fills them with `fallbackLeg`. Run `Estimator::suggestDay`. If any returned suggestion contains a `spot -> spot` leg that came from the fallback, fetch exactly those pairs and run once more; the second run is the answer. `fallback_pairs` counts the fallback legs left in the answer. `week`: contexts for the eight dates from `week_start`, the same leg map, `Estimator::suggestWeek`; the refinement pass covers the legs of the chosen days only. Results are cached through `TpCache` under `tp:suggest:{org}:{sha1(canonical inputs + leg inputs)}` for 10 minutes. `@set_time_limit(60)`. Budget [S]: day under 3 s, week under 15 s with 24 candidates.

## 6. Security and robustness rules (every controller)

1. **Tenant scoping.** The tenant key is `organization_id` from `$request->user`, never from the body or the query. Every repository call on an owner table takes it and every SQL statement carries it. `tp_drive_legs` and the reference tables have no tenant column by design and are only read through keys the caller supplies.
2. **404 for other tenants.** An id that exists for another organization answers exactly like a missing id (404 with the same message). Never 403 for an id.
3. **Never 401.** Only `Middleware::auth()` answers 401. "No workspace" is 403, "no truck" 409, an unavailable upstream 503, a refused or failing Google call is not an error at all (labelled fallback).
4. **Input bounds.** Every field goes through `Input` with a range or a length. Lists have a maximum. Bodies above 256 kB answer 413. Ids in paths are used only as bound parameters. No value from a request becomes a column or table name. `IN (...)` lists are built with `Sql::marks`.
5. **Numbers out.** `JsonSafe::clean` walks the payload: a non-finite float becomes `null` and is logged with its path (`[tp] non-finite at result.totals.take_home_per_hour.value`); strings must be valid UTF-8 (`mb_check_encoding`), otherwise `null`. A response is never an empty 200: `ok()` test-encodes first.
6. **Maps stay objects.** PHP encodes an empty array as `[]`. Each controller passes the dotted paths of its maps (`*` matches list items) and `JsonSafe::clean` casts empty arrays at those paths to objects. Paths in use: `assumptions.overrides`, `calibration.spots`, `week.visits`, every `warnings.*.data`, `vectors` (keyed by visibility), `prediction.detail`.
7. **No INF or NAN in, either.** JSON cannot carry them; `Sql::f` throws on a non-finite value so nothing reaches MySQL.
8. **Time zone always explicit.** "Today" and "now" come from `Clock` with the truck's `timezone`. The model receives dates and minutes, never a clock. Tests run under three process time zones (8.1).
9. **Secrets.** `GOOGLE_API_KEY` and `EIA_API_KEY` are read with `Config::get` inside the client classes only, never returned, never logged. Upstream bodies and exception texts are not passed to the client; the user sees a fixed sentence.
10. **Outbound calls** go through `OutboundHttp` only, to the five listed hosts, with short timeouts, without redirects. The one other user of curl is the smoke client, which reaches only `127.0.0.1` and `localhost` (7.2). No request-time call to OpenStreetMap, Overpass or any other host.
11. **Transactions.** Multi-statement writes (plan with stops, plan delete, data deletion, override re-keying) run in one transaction with the house idiom; no `Response` call inside an open transaction (`Response` exits [R]); no nested transactions.
12. **Side effects before the response.** Cache writes and ledger rows happen before `ok()`.
13. **Not plan-gated**, no `_meta.estimated_cost_usd`, no use of `jobs`, `WorkerHeartbeat` or the onboarding flags.
14. **Forbidden references** (enforced by 8.1): the classes and endpoints that reach an LLM, `App\PrivateData`, `App\MarketData`, `PermitsService`, `FootTrafficService`, `TrafficService`, `DriveTimeMatrixService`, `IsochroneService`, `OSMAdapter`, `GoogleMapsService`, `PlacesClient`, and the configuration keys of any routing provider other than Google (`ORS_API_KEY`, `ORS_BASE_URL` [R: the two keys the existing isochrone and drive-time services read]). `PlacesRateLimiter` is not on the list: `UpstreamGuard::takeTokens` uses it (5.3).

## 7. Configuration and operator CLI

### 7.1 `config/truck_planner.php`

Returns one array, read through `TpConfig` (2.3: `TpConfig::get('routing.timeout_s')`): `limits` (`max_spots` 500, `max_stops_per_plan` 8, `max_points_per_drive_request` 60, `max_pairs_per_drive_request` 650, `max_body_bytes` 262144, `day_context_max_days` 14, `max_suggest_spots` 200: exactly these seven, because bootstrap sends the group to the browser), `requests` (the other bounds of section 4: date ranges, list lengths, the 50 spots of a refresh, the 60 outlets and 10 hosts of a simulate answer, the 250 m and 100 m radii of 4.7 and 4.8, the 200 rows of an export page), `routing` (5.3 table, with the batch sizes, the purge size and the four SKU names), `places` (`contact_ttl_days` 30, `bias_radius_m` 500.0, timeouts, bucket, back-off), `weather` and `fuel` (the timeouts and lifetimes of 5.5 and 5.6), `regions` (the lifetime of the usability verdict, the 2,400 m of Q4, the default time zone), `plans` (`snapshot_ttl_days` 30), `scout` (`shortlist_extra` 10, `max_screen` 15000, `cache_ttl_s` 86400, the page size and the reach slack of 5.9), `suggest` (`cache_ttl_s` 600), `unit_cost_usd` (5.3 table). The file belongs to P1: a package that needs another setting asks for it. No secrets. Environment keys read by Truck Planner: `GOOGLE_API_KEY` (existing), `EIA_API_KEY`, `TP_CONTACT_EMAIL`, `MAIL_FROM` (existing), `APP_ENV`.

### 7.2 Scripts under `scripts/truck/`

All start with `require __DIR__ . '/_bootstrap.php';` (autoload, `Config::load(dirname(__DIR__, 2))`, `ini_set('serialize_precision', '-1')`, `ini_set('precision', '17')`, `set_time_limit(0)`, and the helpers `tp_args(array $spec, string $usage): array`, `tp_usage(string $problem, string $usage)`, `tp_out(string $line)`, `tp_fail(string $message, int $code)` with the constants `TP_EXIT_OK`, `TP_EXIT_USAGE`, `TP_EXIT_FAILED`). `tp_args` reads `--name` and `--name=value` options against a table of `flag`, `value` and `optional` (an option that takes a value or stands alone, such as `--activate`); an unknown option, a stray word or `--help` prints the usage. Exit 0 success, 1 usage or I/O error, 2 failed check. Run with the web tier's PHP (`php8.3` on the server). Only `purge-google-cache.php` is scheduled, by the deploy runbook (8.5 step 11); none uses `WorkerHeartbeat`.

| Script | Usage | Behaviour |
|---|---|---|
| `load-region.php` | `--build=<dir> [--activate] [--dry-run]`, `--region=<id> --activate=<version>`, `--region=<id> --list`, `--region=<id> --prune` | 03_DATA.md section 10 (5.2): exactly its four command forms. `memory_limit` 1024M. A changed build-scope seed is handled only through the version: raise `seeds_revision`, rebuild with `node tools/truck-etl/bin/build-region.mjs --region=<id> --offline` (a new `dataset_version`, because `model_version` and `seeds_revision` are hashed into it), load, activate |
| `refresh-spots.php` | `[--region=<id>] [--org=<organization id>] [--dry-run]` | After a dataset switch: for every spot whose stored region, dataset or seeds revision is not current, applies the host link rule of 4.8 and recomputes `vectors_bin`, in batches of 200, and prints `checked`, `refreshed`, `failed`. (Requests also refresh lazily.) |
| `seed-demo-truck.php` | `--email=<user email> [--as-of=YYYY-MM-DD] [--reset]` | Development only: exit 1 when `APP_ENV` is `production`. `DemoTruckSeeder` writes for that user's organization a truck based at 39.0030, -77.4050 ("Sterling, VA"), five spots (among them "Herndon office park" at 38.9600, -77.3600 and a taproom with host `v_nightlife`, size 120, `only_food`), one planned Thursday with the two stops 660-840 and 1020-1200, and twelve service logs over the eight weeks before `--as-of` whose `actual` is `predicted_raw` scaled by `0.75 + (crc32(spot_id . date) % 500) / 1000.0`. Deterministic, no random numbers, no LLM. `--reset` deletes that organization's truck data first through `DataPurgeService::deleteTruckData` (exit 1 with `--reset needs DataPurgeService` while that class is not installed) |
| `purge-google-cache.php` | `[--dry-run]` | `DataPurgeService::purgeGoogleCaches`: deletes `tp_drive_legs` older than 30 days, empties `g_*` of leads fetched more than 30 days ago, nulls plan snapshots with Google legs older than 30 days. Idempotent. It is the full sweep and is scheduled daily by the runbook (8.5 step 11): an account that sends no request triggers none of the request-time purges (5.3, 5.4, 5.8) |
| `export-places.php` | `--region=<id> > places.ndjson` | 03_DATA.md 14: NDJSON of the active `tp_places` rows (without `host_vec`) with a first line stating the ODbL licence: one JSON object with `licence`, `attribution`, `attribution_url`, `notice`, `region_id`, `dataset_version`, `osm_snapshot_date` and `rows`. Then one place per line in `place_key` order (Q8), with the keys of the pipeline's `places.ndjson` and `snapshot_date`. Read-only; exit 1 when the region has no active `ready` version |
| `smoke.php` | `--base-url=http://127.0.0.1:8080 [--no-region] [--keep]` | HTTP smoke test below |

**Smoke test.** Refuses any base URL whose host is not `127.0.0.1` or `localhost`. Runs every file `scripts/truck/smoke/NN_*.php` in name order; each returns `function (SmokeClient $c, array &$state): void`. The first, `00_auth.php`, registers two users through `POST /api/auth/register` (`tp-smoke-<hex>@example.test`, so two organizations) and checks what stands in front of every route, read from `config/routes.php` itself: all 44 answer 401 without a token, and with a token and no truck the 37 that need one answer 409 `Set up your truck first`. `$state` is shared by the files: `no_region`, `keep`, `users` (`[1 => {email, user_id, organization_id}, 2 => ...]`), and whatever a file adds under a key of its own for later files. The smoke client is the class file `scripts/truck/smoke/SmokeClient.php`: it uses curl over plain HTTP and refuses any host other than `127.0.0.1` and `localhost`. `SmokeClient` offers `get`, `post`, `put`, `delete` (a body is a PHP array and is sent as a JSON object), `sendRaw`, `as(int $user)` (1 or 2; 0 sends no token), `header(name, value)` and `allow(int ...$statuses)` for the next request only, the assertions `status`, `path` (JSON path equals; numbers compare by value), `finite` (no `null` where a number is required) and `check(bool, what)`, and the readers `value(path)`, `json()`, `body()`, `lastStatus()`, `responseHeader(name)`. JSON paths are dotted (`data.plan.stops.0.kind`) and `*` stands for every item. It fails on any 401, any 5xx, any empty body, and any response that is not the envelope (except successful answers of routes 8 and 42), unless the file said `allow(401)` or `allow(503)` first. Exit 1 on the first failed file, with the request, status and body printed. Required coverage, one file per package: every route of section 3 at least once on the happy path; one 422 per validation message id V1-V12; the 409 before a truck exists; user 2 gets 404 for user 1's spot, plan, service, lead and correction ids; the pack answers 200 with the right length, then 304 with `If-None-Match`; a JSON double round trip (`PUT /assumptions` with `weather.floor = 0.20000010000000001`, read back identical; a `JSON` column would hand back `0.2000001` [R], where `0.30000000000000004` survives one and proves nothing); with no `GOOGLE_API_KEY` every drive leg is `straight_line` with reason `no_key` and the contact lookup is 503; the export parses as JSON with `incomplete: false`; after `POST /data/delete` bootstrap says `has_truck: false`. `--no-region` skips checks that need a loaded region. Without `--keep` it finishes by deleting its truck data through route 43 (the two accounts stay); while that route still answers 501 it says so and leaves the data.

## 8. Tests, CI, environment, deploy

### 8.1 PHPUnit suite `truck-planner`

All under `tests/TruckPlanner/`, namespace `App\Tests\TruckPlanner\...`, no database, no network. Local run: `php vendor/phpunit/phpunit/phpunit --testsuite truck-planner`.

| Path | Content |
|---|---|
| `Model/GoldenCasesTest.php`, `Model/SeedSyncTest.php`, `Model/PurityTest.php`, `Model/OperationOrderTest.php`, `Model/SpecRulesTest.php`, `Model/EstimatorContractTest.php` (model package) | `GoldenCasesTest`: every case of `tests/fixtures/truck-planner/golden_cases.json` through `Estimator::dispatch` with the tolerance of 02_MODEL.md 1.4, each under three time zones (`UTC`, `America/Los_Angeles`, `Pacific/Kiritimati`) and two values of `precision` set inside the test; bit-identical results for the functions made of exactly rounded operations only; anchors A1, A2. `SeedSyncTest`: `SeedsData.php` equals `tp_seeds.json`, the vocabulary and the constants equal the code. `PurityTest`: the token scan of 02_MODEL.md 1.5 over `src/TruckPlanner/Model`. `OperationOrderTest`: the order of additions and multiplications of 02_MODEL.md 1.5, to the last bit. `SpecRulesTest`: rules the golden cases do not pin down (tolls in a scouting round trip, the first of equal week totals, inclusive coverage bounds). `EstimatorContractTest`: the errors of 2.1, `Seeds`, `dispatch`, the calendar against PHP's own for every day of 1970-2199. CI runs the whole suite under `date.timezone` = `UTC`, `America/New_York`, `Pacific/Auckland` |
| `Support/RecordingDatabase.php`, `FakeHttp.php`, `FixedClock.php`, `MemoryCache.php`, `LogCapture.php` | Doubles. `RecordingDatabase extends App\Core\Database` with an empty public constructor [R: the parent's is private], overriding `query`, `fetch`, `fetchAll`, `beginTransaction`, `commit`, `rollback` to record `(sql, params)` (in `$calls`, SQL with whitespace squashed) and return prepared rows: `queue(...)` answers reads in order, `when($needle, $result)` answers every read whose SQL contains the text, `failOn($needle)` makes a statement throw, `only($needle)` and `find($needle)` return the recorded calls. `FakeHttp extends OutboundHttp` returns queued five-element `[status, body, latency, error, headers]` tuples (`queue`, `json`, `fail`), records requests in `$requests`, refuses an address the real class refuses, and throws when nothing is queued. `FixedClock extends Clock` stands still at a UTC instant until `set` or `advance`. `MemoryCache implements TpCacheStore` is the store for `TpCache::wire($clock, $store)`. `LogCapture::during(callable): array` returns the `error_log` lines the code wrote |
| `Data/*RepositoryTest.php`, `Data/ApiLedgerTest.php` | Per repository: every statement on an owner table contains `organization_id = ?` and binds the caller's id; cents and milli conversions both ways; floats bound as `Sql::f` strings; booleans as 1/0; JSON encoded by hand with `{}` for empty maps; `UNHEX(?)` for `tp_spots.vectors_bin` only (`setHostVec` binds its 400 bytes as `PDO::PARAM_LOB`); no `SELECT *`; delete order of `TruckDataRepository::deleteAll`. `ApiLedgerTest`: the first bound value is a 36-character id |
| `Services/*Test.php` (pure or with doubles) | `Input` (each message V1-V12 verbatim), `JsonSafe`, `Money`, `LegKey`, `VectorCodec`, `MapsUrl` (`MapsUrlTest` asserts the three link shapes of 2.3), `Redactor`, `TpCache` (`TpCacheTest`: an entry is a hit before `exp` and a miss after it, whatever `expires_at` the store holds); `CaptureService` on fixture rows equals `Estimator::captureAtPoint`, locate rules; `SimulateService` on a fixture region behind the capture contract (the estimate equals `Estimator::weekStrip` on the answered vectors, anchors A1 and A2, zero vectors, a halo label, the calibration date in the truck's zone) and `SpotService` with the real `SpotRepository` over an in-memory `tp_spots` (the three vector sets read back bit for bit, recomputation only for the changes of 4.8, stale and fresh, each step of the host link rule, 404 for another tenant; the fixture classes live in `SpotServiceTest.php`, which `SimulateServiceTest.php` loads); `CellPackWriter` build/decode within the quantisation bound; `RoutesMatrixClient` parsing (`"713s"`, missing indexes = 0, `ROUTE_NOT_FOUND`, toll states, error bodies); `RoutingService` (batch plan, 30-day rule, refusal memory, legacy attempt once, every fallback reason, fallback never stored); `PlacesContactClient`; `WeatherClient` and `DayContextService` (period mapping on 2026-11-01 and 2027-03-14, null precipitation stays null, wind text, stale and unavailable states; `WeatherClientTest` has a case with and one without the `Expires` header); `FuelClient` (string values, key never in logs) and `FuelPriceService` order; `CalibrationService` basis hashing; `PlanningService` input building and `resultState`; `ScoutScreen`: per fixture the screen's `strip` equals `Estimator::stripFromRows(...)`, `best` equals `scoutEstimate(...).orders.value` and `margin * best` equals `contribution.value`, each to 1e-9 relative; the trip term is checked only against the formula of 5.9 step 4, because the model's `round_trip.cost` uses whole leg minutes at the hour of the window; `SuggestionService` leg strategy |
| `Guard/NoAiAtRuntimeTest.php` | Roots: `src/TruckPlanner`, `src/Controllers/Truck*Controller.php`, `scripts/truck`, `config/truck_planner.php`. PHP comments stripped with `token_get_all` (drop `T_COMMENT`, `T_DOC_COMMENT`), paths normalised to `/`. Check 1, direct scan: no file matches any pattern of lists A-F below (taken from the reconnaissance of the existing LLM call sites), first-party names on word boundaries, the bare word `model` not banned; plus the house list of rule 6.14, `overpass` and `openrouteservice`. Check 2, dependency closure: starting from the roots, follow every word-boundary occurrence of a class basename that exists under `src/` (an over-approximation on purpose) and assert no reached file matches lists A or B. If a harmless common word drags in a tainted file, reword the Truck Planner code; do not weaken the test. Check 3, outbound allow-list: on the token stream of every file in the roots, a plain call (a name followed by `(`, not preceded by `->`, `::`, `function` or `new`) to any `curl_*`, `fsockopen`, `pfsockopen`, `stream_socket_client`, `stream_context_create`, `socket_create`, `file_get_contents`, `fopen`, `file`, `readfile`, `copy`, `get_headers`, `simplexml_load_file`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen` or `mail` fails unless the file is allow-listed for that function (the backtick operator fails everywhere): `src/TruckPlanner/Services/Http/OutboundHttp.php` (`curl_*`); `scripts/truck/smoke/SmokeClient.php` (`curl_*`); `src/TruckPlanner/Services/RegionLoader.php`, `scripts/truck/load-region.php`, `scripts/truck/_bootstrap.php` (`fopen`, `file_get_contents`, `file`, local files only). Request bodies are read through `Request::getRawBody()` and exports are written with `echo`. `OutboundHttp::ALLOWED_HOSTS` equals the five hosts of DECISIONS 0. Every `http://` or `https://` literal in the roots has one of those five hosts or a link-only host (`www.google.com`, `www.openstreetmap.org`, `lehd.ces.census.gov`); `127.0.0.1` and `localhost` literals are allowed only in `scripts/truck/smoke.php` and under `scripts/truck/smoke/`, and `SmokeClient.php` may contain no other host literal. `composer.json` is scanned whole for the library list |
| `Guard/ModelPurityTest.php` | On the token stream of `src/TruckPlanner/Model` (comments dropped): no identifier `Database`, `Config`, `CacheService`, `DateTime`, `DateTimeImmutable`, `DateTimeZone`; no superglobal (`$_SERVER`, `$_ENV`, `$_GET`, `$_POST`); no plain function call (a name followed by `(`, not preceded by `->`, `::`, `function` or `new`) to `date`, `gmdate`, `time`, `microtime`, `hrtime`, `strtotime`, `mktime`, `getenv`, `setlocale`, `random_int`, `random_bytes`, `mt_rand`, `rand`, `shuffle`, `array_rand`, `str_shuffle`, `round`, `intdiv`, `pow`, `array_sum`, `number_format`, `file_get_contents`, `fopen`, `getdate`, `localtime`, `idate`, `date_create`, `date_create_immutable`, `gmmktime`, `gettimeofday`, `strftime`, `gmstrftime`, `date_default_timezone_get`, `date_default_timezone_set`, `lcg_value`, `uniqid`, `mt_srand`, `srand`, `mt_getrandmax`, `usleep`, `sleep`, `strtolower`, `strtoupper`, `ucfirst`, `ucwords`, `lcfirst`, `mb_strtolower`, `mb_strtoupper`, `localeconv`, `ini_get`, `ini_set`, `error_log` or any `curl_*`; no `**` operator; no constant `M_PI` |
| `Guard/HouseRulesTest.php` | In all roots, with the same token rules: no call to `round`; no call to `date`, `gmdate`, `time`, `strtotime`, `mktime`, `date_default_timezone_set` and no `new DateTime` anywhere; `DateTimeImmutable` (constructed or called statically) only in `Clock.php`; `CacheService` only in `Support/TpCache.php`; `microtime` only in `OutboundHttp.php` and `RoutingService.php` (elapsed time); under `src/TruckPlanner/Data` no `->insert(`, `->update(`, `->delete(` and, outside `RegionLoadRepository.php`, no `->pdo(` (literal SQL through `query`, `fetch`, `fetchAll` only); string literals contain none of the legality words of section 0; no `Response::` outside `src/Controllers`; under `src/TruckPlanner/Data` no string literal with `SELECT *`; every `error_log(` call starts with a string literal that begins `[tp] `; every file declares `strict_types=1` |
| `Guard/SpecCopiesTest.php` | The three files that are copies of a block of the specification equal their block line by line (line endings apart): `src/Migrations/042_tp_reference_data.sql` and the SQL block of 03_DATA.md 9.1, `src/Migrations/043_truck_planner_core.sql` and the SQL block of 1.2, `.github/workflows/truck-planner.yml` and the YAML block of 8.3. Whoever changes a block or its file changes both in the same commit |
| `Guard/RouteTableTest.php` | Parses the Truck block of `config/routes.php` (from its first comment line to the end of the closure, where it stands last): exactly the 44 routes of section 3 in order; every controller is imported, extends `TruckBaseController` and can be built without arguments, and every action exists as `public function name(Request $request): void`; the six middleware profiles as specified. It also loads the file into the real `Router` and checks the table a request meets: the 44 routes are its last 44 entries, each concrete path is matched first by its own route, no earlier pattern of the same verb matches a later literal path, and each route carries `auth` first and then the rate limit or role gate of its profile. The four rate-limit names are not in `GooglePricing::COSTS`. The file's line endings are all of one kind |
| `Guard/SourceScan.php` | Not a test: the token scanner the three scans above share (the roots, comments dropped, plain calls, method calls, class uses, identifiers, string literals) |

Forbidden patterns of `NoAiAtRuntimeTest` (plain entries are case-insensitive substrings, entries between slashes are regular expressions):

```
A hosts     api.anthropic.com  api.openai.com  openai.azure.com  generativelanguage.googleapis.com  aiplatform.googleapis.com  api.mistral.ai  api.cohere.ai  api.cohere.com
            api.groq.com  api.together.xyz  api.together.ai  openrouter.ai  api.perplexity.ai  api.x.ai  api.deepseek.com  api.fireworks.ai  api.replicate.com  huggingface.co
            api.voyageai.com  api.ai21.com  :11434  :8088  ml-sidecar  /bedrock(-runtime)?\.[a-z0-9-]+\.amazonaws\.com/  /(runtime\.)?sagemaker\.[a-z0-9-]+\.amazonaws\.com/
B keys      /\b(ANTHROPIC|OPENAI|AZURE_OPENAI|GEMINI|GOOGLE_AI|GOOGLE_GENAI|MISTRAL|COHERE|GROQ|TOGETHER|OPENROUTER|PERPLEXITY|XAI|DEEPSEEK|FIREWORKS|REPLICATE|HUGGINGFACE|HF|VOYAGE|AI21)_(API_)?(KEY|TOKEN)\b/
            ML_SIDECAR_URL  OLLAMA_HOST
C wire      anthropic-version  anthropic-beta  /v1/messages  /v1/chat/completions  /v1/completions  /v1/embeddings  /v1/responses  :generateContent  :streamGenerateContent  :embedContent
            /\bclaude[-_ ]/i  /\bgpt-?[0-9]/i  /\bo[134]-(mini|preview|pro)\b/i  /\bgemini-/i  /\btext-embedding-/i  /\b(haiku|sonnet|opus)\b/i  /\bllama[-0-9]/i  /\bmistral-(tiny|small|medium|large)/i  /\bcommand-r/i
D symbols   AiScoringController  MenuEngineeringService  MenuEngineeringController  SampleDataService  OpsController  narrateWithClaude  scoreWithClaude  briefingViaClaude
            dashboardBriefing  hasAnthropicKey  haveAnthropicKey  'pos.sync'  "pos.sync"  ai_score:  dash_briefing:  /\b(FROM|JOIN|INTO|UPDATE)\s+`?recommendations`?\b/i
E endpoints /ai-score  /ai-rankings  /dashboard/briefing  /recommendations/run  /restaurants/sample  /\/menu-items\/[^'"`]+\/recommend/  /\/pos\/[^'"`]+\/sync/
F libraries Anthropic\  OpenAI\  Gemini\  LLPhant\  Prism\  Phpml\  Rubix\ML\  openai-php/client  anthropic-ai/sdk  mozex/anthropic-php  google-gemini-php/client  theodo-group/llphant
            prism-php/prism  php-ml/php-ml  rubix/ml
```

### 8.2 `phpunit.xml.dist`

```xml
  <testsuites>
    <testsuite name="unit">
      <directory>tests</directory>
      <exclude>tests/TruckPlanner</exclude>
    </testsuite>
    <testsuite name="truck-planner">
      <directory>tests/TruckPlanner</directory>
    </testsuite>
  </testsuites>
```

and add `<directory suffix=".php">src/TruckPlanner</directory>` to `<source><include>`. The legacy suite stays red (9 of 220 at baseline) and is not touched. `tests/TruckPlanner/` must exist in the same commit [R: PHPUnit exits 2 on a missing suite directory].

### 8.3 CI: new file `.github/workflows/truck-planner.yml` (the existing `ci.yml` is not edited)

```yaml
name: Truck Planner
on:
  push:
    branches: [main, truck-planner]
  pull_request:
    branches: [main]
jobs:
  backend:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php-version: ['8.1', '8.3']
    env:
      DB_HOST: 127.0.0.1
      DB_NAME: smappen_ci
      DB_USER: root
      DB_PASS: root
      DB_PERSISTENT: 'false'
      APP_ENV: development
      JWT_SECRET: ci-only-0123456789abcdef0123456789abcdef
      APP_URL: http://127.0.0.1:8080
      TP_CONTACT_EMAIL: ci@example.test
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}
          extensions: mbstring, pdo_mysql, gd, zip, curl
          coverage: none
      - run: composer install --prefer-dist --no-progress
      - name: Syntax
        run: |
          set -e
          find src/TruckPlanner scripts/truck tests/TruckPlanner config -name '*.php' -print0 | xargs -0 -n1 -P4 php -l > /tmp/lint.out 2>&1 || (cat /tmp/lint.out; exit 1)
          for f in src/Controllers/Truck*Controller.php; do php -l "$f" > /dev/null; done
      - name: PHPUnit truck-planner under three time zones
        run: |
          set -e
          for tz in UTC America/New_York Pacific/Auckland; do
            php -d date.timezone=$tz vendor/bin/phpunit --testsuite truck-planner
          done
      - name: MySQL 8, migrations, mini region, HTTP smoke
        if: matrix.php-version == '8.3'
        run: |
          set -e
          sudo systemctl start mysql.service
          mysql -uroot -proot -e "CREATE DATABASE smappen_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
          php scripts/migrate.php
          mysql -uroot -proot smappen_ci -e "DELETE FROM migrations WHERE name LIKE '042\_tp%' OR name LIKE '043\_truck%'"
          php scripts/migrate.php
          REGION_FLAG=--no-region
          if [ -d tests/fixtures/truck-planner/region-mini/build ]; then
            php scripts/truck/load-region.php --build=tests/fixtures/truck-planner/region-mini/build --activate
            REGION_FLAG=
          fi
          php -d date.timezone=UTC -S 127.0.0.1:8080 -t public public/index.php > /tmp/php-server.log 2>&1 &
          sleep 2
          php scripts/truck/smoke.php --base-url=http://127.0.0.1:8080 $REGION_FLAG || (cat /tmp/php-server.log; exit 1)
  reference:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-python@v5
        with:
          python-version: '3.12'
      - run: python docs/truck-planner/reference/truck_planner_reference.py --self-test
      - name: Seed copies in sync
        run: python docs/truck-planner/reference/generate_seed_copies.py --check
  frontend-and-etl:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm ci && npm test
        working-directory: frontend
      - run: npx tsc --noEmit -p tsconfig.json
        working-directory: frontend
      - run: npx vite build --outDir "$RUNNER_TEMP/tp-build" --emptyOutDir
        working-directory: frontend
      - run: node scripts/check-truck-chunks.mjs "$RUNNER_TEMP/tp-build"
        working-directory: frontend
      - run: npm ci && node --test
        working-directory: tools/truck-etl
```

The `backend` job runs its Syntax and PHPUnit steps on PHP 8.1 and 8.3, which checks the common-subset rule of section 0; the MySQL and smoke step runs on 8.3 only. `php scripts/migrate.php` runs twice on purpose: between the runs the two Truck Planner files are un-recorded, so the second run re-applies them and proves every statement is re-runnable. Without the region fixture (C17) the smoke test runs with `--no-region`. The runner's MySQL version and root password are GitHub's defaults for `ubuntu-latest` [S: confirm on the first run]. The reference self-test flag and the `tools/truck-etl` test command belong to their owners; adjust the two lines if they differ. The three `frontend` steps after the tests are the type check, a production build into a temporary directory and the chunk check of 05_FRONTEND.md 8.4; `frontend/scripts/check-truck-chunks.mjs` belongs to the frontend.

### 8.4 `.env.example`, `.gitignore`, `.gitattributes`

Append to `.env.example` (plain `KEY=` lines, no spaces, no quotes; the file's existing lines 11 and 16 are known not to parse and are not fixed here):

```
# Truck Planner
# Weekly fuel prices (U.S. Energy Information Administration). Optional, free key: https://www.eia.gov/opendata/register.php
EIA_API_KEY=
# Contact address sent in the User-Agent of api.weather.gov requests. Falls back to MAIL_FROM
TP_CONTACT_EMAIL=
```

`.gitignore`: `.phpunit.cache/`, `storage/truck/` and `tools/truck-etl/node_modules/` are already present [R]; add `composer.lock` (DECISIONS 11: it stays untracked) and `__pycache__/` (running the Python reference or the seed generator leaves a bytecode cache next to the script). New `.gitattributes` with `tests/fixtures/truck-planner/** -text` and `tools/truck-etl/test/fixtures/** -text` so checksummed fixtures survive Windows checkouts (03_DATA.md 12), and with `docs/truck-planner/reference/tp_seeds.json text eol=lf`, `src/TruckPlanner/Model/SeedsData.php text eol=lf` and `frontend/src/utils/truck/estimator/seeds.generated.ts text eol=lf` so the seed file and its two generated copies keep LF endings there [R: `core.autocrlf` is on in this checkout].

### 8.5 Deploy runbook (production; nothing before step 3 touches it)

1. Get the owner's go-ahead (DECISIONS 14). Required check: TomTom's terms for the Traffic Index figures have been read and the use is recorded in DECISIONS 14; otherwise deploy seeds revision 2 with `traffic.dc` and `traffic.us_mean` all 1.0 and both `_typical` values 1.0. For the Google Cloud project of the server key: before enabling the **Routes API**, set its quota in the console to 20,000 matrix elements a day (the global budget of 5.3) and 3,000 a minute, and record the values; do not change the quotas or API restrictions of the APIs the existing products use (Places, Geocoding, Maps JavaScript) without checking their current usage. Then confirm that the Routes API is enabled (Places API (New) already is). Without it drive times are labelled straight-line estimates.
2. On a workstation: suite green (`--testsuite truck-planner`, three zones), smoke green against a scratch MySQL 8, region build produced (03_DATA.md 15.1 steps 1-7).
3. On the server: `scripts/backup-db.sh`. There are no down migrations.
4. `git pull` the release (never through the remote named `droplet` from a workstation). `/usr/bin/php8.3 /usr/local/bin/composer install --no-dev --optimize-autoloader` only if `composer.json` changed (it does not).
5. Add `EIA_API_KEY=` (optional) and `TP_CONTACT_EMAIL=` to `/var/www/smappen/.env`.
6. `php8.3 scripts/migrate.php`. Expect `042` and `043` applied. Verify: `SHOW TABLES LIKE 'tp\_%'` lists 13 tables; `SHOW COLUMNS FROM tp_places LIKE 'host_vec'` returns one row; `SELECT bucket FROM places_rate_buckets WHERE bucket LIKE 'tp\_%'` returns two rows.
7. Copy the build directory to `storage/truck/build/<region>/<dataset_version>/` and run `php8.3 scripts/truck/load-region.php --build=...`. It must end with every loader gate passed and `load_state ready`.
8. `php8.3 scripts/truck/load-region.php --region=dc --activate=<dataset_version>`.
9. Build the frontend (`cd frontend && npm ci && npm run build`) and `systemctl reload php8.3-fpm` (opcache does not revalidate files).
10. Check: `GET /api/health`; log in; `GET /api/truck/bootstrap` shows the region `usable: true`; the map loads the pack (200, then 304); simulate one office area; save a spot; `POST /api/truck/drive-times` returns `google_routes` legs (or a labelled fallback with a reason: read it); `SELECT sku, COUNT(*) FROM api_cost_events WHERE sku LIKE 'tp\_%' GROUP BY sku` shows the calls.
11. Required: add outside the managed Carafe block of root's crontab the daily purge `17 4 * * * /usr/bin/flock -n /tmp/tp-purge.lock /usr/bin/php8.3 /var/www/smappen/scripts/truck/purge-google-cache.php >> /var/www/smappen/storage/logs/cron/tp-purge.log 2>&1`. Google content is deleted when its 30 days end, also for an account that sends no request.
12. After a later dataset switch: `php8.3 scripts/truck/refresh-spots.php --region=dc`.
13. Roll back code: check out the previous release and reload FPM; the `tp_` tables can stay (nothing else reads them). Roll back data: activate the previous dataset version (03_DATA.md 15.1 step 12).

## 9. Work packages

Eight packages, disjoint files. **P1 lands first.** It registers all 44 routes and creates every `Truck*Controller.php` as a stub whose actions answer 501 `Not implemented yet` through `TruckBaseController`; from that moment each controller file belongs to the package named below and P1 does not touch it again. Shared files (`config/routes.php`, migrations, `config/truck_planner.php`, `phpunit.xml.dist`, the workflow, `.env.example`, `.gitignore`, `.gitattributes`, everything under `Services/Support`, `Services/Contracts`, `Services/Fallback`, `Services/Http`) belong to P1 only. Cross-package calls go through the five contracts and `Registry`, so a package runs against the fallbacks until its neighbour lands; no wave-1 package calls a class a later wave creates. All packages depend on the model package (`src/TruckPlanner/Model/*` with `Estimator`, `Seeds` and `ModelError` as its public classes, and `tests/TruckPlanner/Model/*`), which none of them edits. Paths below without a directory are under `src/Controllers/` (controllers) or `src/TruckPlanner/` (`Services/...`, `Data/...`). "Its tests" means exactly one file per class the package creates: `tests/TruckPlanner/Data/<Class>Test.php` or `tests/TruckPlanner/Services/<Class>Test.php`.

| Package | Wave | Creates or edits | Depends on | Acceptance checks |
|---|:---:|---|---|---|
| **P1 Foundation** | 1 | `src/Migrations/042_tp_reference_data.sql` (the text of 03_DATA.md 9.1) and `043_truck_planner_core.sql`; `config/routes.php` (Truck block), `config/truck_planner.php`; `src/Controllers/TruckBaseController.php` and the 14 controller stubs; `src/TruckPlanner/Services/Support/*`, `Contracts/*`, `Fallback/*`, `Http/*`; `Services/AssumptionsFactory.php`, `ProfileMapper.php`, `RegionService.php`; `Data/Sql.php`, `VectorCodec.php`, `LegKey.php`, `ApiLedger.php`, `TruckRepository.php`, `CountsRepository.php`, `RegionRepository.php`; `phpunit.xml.dist`, `.github/workflows/truck-planner.yml`, `.env.example`, `.gitignore`, `.gitattributes`; `tests/TruckPlanner/Support/*`, `Guard/*`, tests of its own classes; `scripts/truck/_bootstrap.php`, `smoke.php`, `smoke/SmokeClient.php`, `smoke/00_auth.php` | model package | Migrations 042 and 043 pass the splitter rules and run twice on MySQL 8 without change. Guard tests green. `RouteTableTest` green. Every route answers 501 (or 409 / 403 from the base guards) through `php -S`, never 500 or 401 with a valid token. Suite green under three zones |
| **P2 Region data** | 1 | `scripts/truck/load-region.php`, `export-places.php`; `Services/RegionLoader.php`, `CellPackWriter.php`, `CaptureService.php`; `Data/PointRepository.php`, `PlaceRepository.php`, `RegionLoadRepository.php`; `src/Controllers/TruckRegionController.php`; `tests/fixtures/truck-planner/region-mini/**`; its tests; `smoke/10_regions.php` | P1; build files from the pipeline | Mini region loads with gates G19 to G22 passed, twice (the second run replaces the non-active version). `--dry-run` writes nothing. Pack decodes within the quantisation bound; route 8 serves 200 then 304, `Content-Encoding: gzip`, immutable caching. `CaptureService` reproduces a pack cell's 50 numbers within 1e-12 and the 02_MODEL.md 4.4 worked layout from fixture rows. A changed build-scope seed gives `usable: false` with reason `build_mismatch`; route 8 and simulate answer 409 |
| **P3 Truck setup** | 1 | `TruckBootstrapController.php`, `TruckProfileController.php`, `TruckAssumptionsController.php`; `Services/ProfileService.php`, `AssumptionsService.php`, `BootstrapService.php`; its tests; `smoke/20_setup.php` | P1 | Bootstrap without a truck; create with three fields gives every default of `profile_defaults`; partial update; money round trip in cents; each override error code of 02_MODEL.md 2.2 returns its 422 text; reset; `overrides` is `{}` when empty; second tenant isolated |
| **P4 Simulate and spots** | 1 | `TruckSimulateController.php`, `TruckSpotController.php`; `Services/SimulateService.php`, `SpotService.php`; `Data/SpotRepository.php`; its tests; `smoke/30_simulate_spots.php` | P1; P2 through `CaptureProvider` | Simulate on the mini region returns vectors per visibility, outlets ordered by distance and an estimate equal to `Estimator::weekStrip` on those vectors; a point with no source within the cutoff answers 200 with zero vectors, and a point whose nearest block is a halo block keeps its vectors with `in_region: false`. Spot CRUD; vectors for the three visibility levels stored and decoded bit-exact; recomputed only for the listed changes (not for a visibility change); stale after a dataset switch and fresh after refresh; archive keeps the row; 404 for another tenant. Host link rule: a described taproom host at an existing taproom gives `vectors.normal.exclusion.point_ids` = that place's point; a dangling link is re-linked within 100 m or cleared; a host beside an unnamed venue 50 m away gets its `point_id` from `hostLinkPoint` |
| **P5 Drive times and day context** | 2 | `TruckDriveController.php`, `TruckDayContextController.php`; `Services/RoutingService.php`, `DayContextService.php`, `FuelPriceService.php`, `Services/Google/RoutesMatrixClient.php`, `Google/DistanceMatrixClient.php`, `Services/Upstream/WeatherClient.php`, `Upstream/FuelClient.php`; `Data/DriveLegRepository.php`, `DriveOverrideRepository.php`, `FuelPriceRepository.php`; its tests; `smoke/40_drive_daycontext.php` | P1 | With `FakeHttp`: exact request body, headers and mask; every parsing rule of 5.3; refusal remembered for an hour with exactly one legacy attempt; each fallback reason; nothing older than 30 days served; a leg row dated 31 days ago is gone after the next `legs()` call; no fallback row written; ledger rows with the right SKU and no URL. Overrides upsert and delete, tenant-isolated. Day context: holiday table of 02_MODEL.md 4.1 for 2026-2029, hour mapping on both daylight-saving days, null precipitation preserved, fuel order owner > EIA > seed. Without keys every leg is a labelled straight line and day context still answers |
| **P6 Plans, logs, calibration** | 2 | `TruckPlanController.php`, `TruckServiceLogController.php`, `TruckCalibrationController.php`; `Services/PlanningService.php`, `ServiceLogService.php`, `CalibrationService.php`, `DemoTruckSeeder.php`; `Data/PlanRepository.php`, `ServiceLogRepository.php`; `scripts/truck/seed-demo-truck.php`; its tests; `smoke/50_plans_logs.php` | P1, P4 (`SpotRepository`, `SpotService`); P5 through contracts | A stored plan's `result` equals `Estimator::dayPlan` on the same inputs (the blueprint day sheet with override legs 11, 10, 1 gives 574 ... 1251). Stops replaced as a set; a second plan on one date answers 409; snapshot states `fresh`, `stale`, `expired`; a snapshot with Google legs evaluated 31 days ago is NULL after the next `GET /plans`. A log stores `predicted_raw`, `predicted`, `low`, `high`; plan-linked basis used when the window matches; duplicate answers 409; calibration example of 02_MODEL.md 4.13 reproduced from stored rows; raw predictions recomputed after an override change; accuracy report matches the model; the demo seeder is deterministic and refuses production |
| **P7 Scout and contact lookup** | 3 | `TruckScoutController.php`; `Services/ScoutingService.php`, `ScoutScreen.php`, `Services/Google/PlacesContactClient.php`; `Data/ScoutLeadRepository.php`; its tests; `smoke/60_scout.php` | P1, P2 (host vectors), P4 (`SpotService`); P5, P6 through contracts | The screen equals the model on orders and contribution; licence counties and the drive limit filter; hidden leads leave the ranking; every candidate has `maps_url`; cold and warm timing within 5.9 on the full region; lookup: exact request, place id stored, 30-day cache (found and not found), expiry nulls `g_*`, 503 without a key; save-as-spot links `place_key` and never copies Google fields |
| **P8 Suggestions, data page, operations** | 3 | `TruckSuggestController.php`, `TruckDataController.php`; `Services/SuggestionService.php`, `ExportService.php`, `DataPurgeService.php`, `SourcesService.php`; `Data/TruckDataRepository.php`; `scripts/truck/refresh-spots.php`, `purge-google-cache.php`; its tests; `smoke/70_suggest.php`, `smoke/80_data.php` | P1, P4; P5, P6 through contracts; the `purgeExpired*` methods of the P5, P6 and P7 repositories (`DataPurgeService` skips a repository class that is not installed yet) | Suggest day and week reproduce the 02_MODEL.md 4.15 example with the same legs; refinement pass at most once; export is one valid JSON document with `incomplete: false`, no Google content, nothing written under `storage/`; delete needs the exact phrase and removes exactly the seven tables' rows of one organization; sources returns the attribution strings of 03_DATA.md 14 (eleven while the traffic table is neutral) with no brace left in any text |

Delivery order. Wave 1 (P1, then P2, P3, P4 in parallel) is the first usable slice: load a region, see the map layer, simulate a click, set up the truck, save spots. Wave 2 (P5, P6 in parallel) adds real drive times, dates, plans and logs. Wave 3 (P7, P8 in parallel) adds Scout, suggestions and the data page. The smoke script grows by one file per package and must be green at the end of each wave.

## 10. Conflicts, assumptions and open issues

| # | Item | What this document does |
|---|---|---|
| C2 | The blueprint names another routing provider and DECISIONS 6 still says "permanent cache" | DECISIONS 0 and 9 govern: Google only, 30-day legs, permanent owner corrections |
| C5 | `LegInput.source` knows `google` and `fallback`; the API needs four truthful labels | `DriveLeg.source` carries the label. A zero-length `same_point` leg is passed to the model as `google` with 0 m and 0 s so it does not raise `fallback_drive_time`. The model could gain a `none` source later |
| C6 | Google content inside stored plan snapshots (leg distances and durations) | Snapshots that used Google legs expire after 30 days and are re-evaluated on demand; predictions kept with service logs contain no Google content. Needs the lead's confirmation that this reading of DECISIONS 0 is the wanted one |
| C7 | Everything marked [M] in 5.3 and 5.4: Routes field names, the 625-element limit, the per-minute quota, billing tiers for modifiers and tolls, the Text Search price | Verify against Google's current documentation before P5 and P7 are coded; only constants in `config/truck_planner.php` and two client classes change |
| C8 | Return shape of `Seeds::defaults()` and `Seeds::withOverrides()` | Both return an `Assumptions` array; the bare seed file is `Seeds::data()` (2.1). `withOverrides` validates and throws on an invalid map; `Seeds::assumptions` builds the record without validating |
| C11 | Weather is fetched for the truck's base point only | One forecast per day context, as the model's `DayContext` holds one. Stops far from the base share it. A per-stop forecast is a later refinement |
| C12 | The contact lookup cannot verify that Google's match is the same place (DECISIONS fixes a field mask without location) | The matched name and address are shown. Adding `places.location` to the mask would allow a distance check and needs a DECISIONS edit |
| C13 | Scout ranks a screened shortlist, not every candidate through `scout_estimate` | Deterministic. The screen equals the model on orders and contribution: its `strip` equals `Estimator::stripFromRows(...)`, `best` equals `scoutEstimate(...).orders.value` and `margin * best` equals `contribution.value`, each to 1e-9 relative. The trip term follows only the formula of 5.9 step 4, because the model's `round_trip.cost` uses whole leg minutes at the hour of the window. A place outside the shortlist could differ from a full exact ranking only if real drive legs moved it up by more than ten places |
| C15 | Daily Google budgets (3,000 elements per organization, 20,000 overall), rate-limit numbers and the 650-element cap | Placeholders, not measured. The in-app budgets limit what this server asks for. They are not a cost bound for the key: production builds the browser bundle with the same key (`scripts/droplet-deploy.sh`), so the bound is the quota set in the Google Cloud console (5.3, 8.5 step 1) |
| C16 | Leg keys are coordinates rounded to 4 decimals; owner corrections share them | A pin moved by more than 250 m loses its corrections on purpose; smaller moves re-key them |
| C17 | The mini region fixture (`tests/fixtures/truck-planner/region-mini/build`) must be a valid pipeline build (real H3 ids) | Committed: the five files of `mini-20261003-8d5536a4` (Falls Church city: 363 source points, 420 places, 197 cells), built by the pipeline from its own test input with the repository seed file. `node tests/fixtures/truck-planner/region-mini/make-fixture.mjs` builds it again; that is due whenever a build-scope seed or a pipeline rule changes (03_DATA.md 12). `RegionLoaderTest` loads it without a database, CI loads it into MySQL before the smoke test |
| C18 | JSON columns: MySQL reorders object keys, re-prints numbers, and hands about one in nine of the doubles that need 16 or 17 digits back one unit in the last place off [R, section 0] | `overrides_json` is JSON text in a `MEDIUMTEXT` column, so the owner's overrides read back as saved, and the smoke test checks a 17-digit value. Vectors use the binary codec. The four stored snapshots of the plan and service-log tables (`result_json`, `context_json`, `prediction_json`, `weather_json`) are JSON text in `MEDIUMTEXT` columns as well (section 0, migration 043): encoded with `JsonSafe`, read with `json_decode`, never parsed by MySQL. Settled; nothing is open here |
| C19 | Behaviour not exercised: the two migrations on the CI runner's own MySQL, and the row-constructor `IN` of `findFresh`. (Exercised on MySQL 8.0.45: every migration from an empty database, then 042 and 043 again after being un-recorded as the CI step does, with the same 13 tables, columns and indexes as the result; the smoke test on that database with only the mini region loaded; and with the loaded `dc` region: the binary write and read of `host_vec`, which gate G21 compares bit for bit; the plans of Q1, Q2 and Q4, which name their index since the optimizer did not choose it, 03_DATA.md 9.3; and Q6, which read the 8,415 possible hosts within 35 km of Sterling with their vectors in five pages in 0.44 s, and 4,716 of three counties in 0.13 s) | First run of the CI MySQL step settles them; `EXPLAIN` the `findFresh` query on the loaded region before wave 2 ships |
| C20 | 05_FRONTEND.md 2.1 was written in parallel and lists what the screens need | Adopted here: one plan per date, `status` as the field name of plans and leads, three stored visibility levels per spot, `county_fips` and log counts on a spot, `point_id` on nearby hosts, `from`/`to` on day-context, `toll_source`, `details.field` on a 422, `contact` in sources. Still different, to be absorbed by `api/truck.ts`: `simulate` takes `point`, `visibilities` and `terms.host`; plans are addressed by id (read a date with `from` = `to`); corrections are deleted by id; `export` has one kind (JSON); a 422 is one message plus `details`, not a map of all fields |
