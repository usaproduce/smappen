# Truck Planner - model specification (`tps-0.1.0`)

**Status.** This document is the definition of the math until `docs/truck-planner/reference/truck_planner_reference.py` is written from it; from then on the Python reference is the definition and this text must be kept equal to it. Seeds live in `docs/truck-planner/reference/tp_seeds.json` (revision 1, generated together with the tables below). [`DECISIONS.md`](DECISIONS.md) is binding, including its section 0 (Google Maps only: drive legs come from Google or from the straight-line fallback); section 9 lists where this document refines it.

**Who implements what.** Every function in section 4 exists in three runtimes: Python (reference and golden-case generator), PHP (`App\TruckPlanner\Model`, no I/O) and TypeScript (`frontend/src/utils/truck/`). All three run the same golden cases (section 8) and must agree to the tolerance in 1.4. The browser never calls `rivals_at_origin`, `capture_at_point` or `host_link_point` in production (it receives vectors), but it carries them so one golden file serves all runtimes.

**Nothing here is measured from food-truck sales.** Tags on seeds are `measured`, `derived`, `assumed`, `tuned` (2.1). Two constants are tuned (`kernel.outside_option_a0`, `host.captive_share`) and one curve is tuned with them (`segments.v_nightlife.intent`); they must never be shown to the owner as findings.

Every table of seed values and every worked number in this document was produced by running the formulas written here against `tp_seeds.json` (scratch scripts, 2026-10-05). As a portability check, throwaway JavaScript and PHP implementations were written from this text alone for the core functions (dates, context, curves, geometry, capture, host links, host, weather, hourly and window orders, ranges, money, legs, timeline, calibration, events, best windows, the scouting strip): 2,150 values matched the Python numbers, PHP bit for bit under three time zones and JavaScript to a relative 2.9e-16. If a worked number and a formula ever disagree, the formula wins and the number is a defect to report.

---

## 1. Conventions

### 1.1 Fixed vocabulary and index tables

| Thing | Values, in index order |
|---|---|
| Segments (index 0..15) | `res`, `w_office`, `w_health`, `w_edu`, `w_retail`, `w_industrial`, `w_hospitality`, `w_public`, `v_nightlife`, `v_shopping`, `v_leisure`, `v_campus`, `v_hospital`, `v_transit`, `v_events`, `v_lodging` |
| Regimes (0..1) | `day`, `eve` |
| Rival kinds | `quick`, `full`, `cafe`, `bar`, `convenience` |
| Day types | `weekday`, `saturday`, `sunday` |
| Day of week `dow` (0..6) | `mon`, `tue`, `wed`, `thu`, `fri`, `sat`, `sun` |
| Dayparts | `breakfast`, `lunch`, `dinner`, `late` |
| Visibility levels | `hidden`, `normal`, `prominent` |
| Confidence labels, weakest first | `very_rough`, `rough`, `fair`, `good`, `fixed` |

| Local clock hour `h` (bin `[h:00, h+1:00)`) | 0-4 | 5-10 | 11-15 | 16-21 | 22-23 |
|---|---|---|---|---|---|
| `regime_of_hour[h]` (seed `hours.regime_of_hour`) | `eve` | `day` | `day` | `eve` | `eve` |
| `daypart_of_hour[h]` (seed `hours.daypart_of_hour`) | `late` | `breakfast` | `lunch` | `dinner` | `late` |

A vector "per segment" is always an array of 16 numbers in segment index order. Loops over segments run 0, 1, ..., 15. Loops over regimes run `day` then `eve`.

### 1.2 Units and number types

| Quantity | Unit and type |
|---|---|
| Walking distance, kernel distances | metres, real |
| Road distance | metres in `Leg.distance_m`; miles = metres / 1609.344 |
| Latitude, longitude | decimal degrees, WGS84, real |
| Money | US dollars, real, in the model and the API (integer cents only in MySQL; the repository converts with `round_half_away(dollars * 100, 0)`) |
| Fractions ("percent" fields named `*_pct`, shares) | fractions of 1 (0.30 = 30 %), real |
| Clock times | integer minutes from local midnight of the service date |
| Dates | `"YYYY-MM-DD"` strings (civil dates, proleptic Gregorian), valid range 1970-01-01 .. 2199-12-31 |
| Counts of people, jobs, orders | real (orders are expected values, not integers) |
| Identifiers | strings; compared byte-wise (plain ASCII order) |

Reals are IEEE-754 binary64 in every runtime (Python `float`, PHP `float`, JavaScript `number`). Integers (minutes, day numbers, indexes, counts of services) are exact integers; never let an integer division produce a real unless the text says `/`. Notation used in pseudo-code:

| Notation | Meaning |
|---|---|
| `a / b` | real division |
| `floor(x)` | largest integer `<= x` |
| `floor_div(a, b)` | for integers, `b > 0`: `floor(a / b)` computed without reals (rounds toward minus infinity) |
| `mod_floor(a, b)` | `a - b * floor_div(a, b)`, always in `0 .. b-1` |
| `clamp(x, lo, hi)` | `lo` if `x < lo`, `hi` if `x > hi`, else `x` |
| `exp`, `ln`, `sqrt`, `sin`, `cos`, `asin` | the runtime's double-precision functions |
| `sum over ... in order` | an accumulator starting at `0.0`, one plain addition per term, in the stated order |

Constants (also in the seed file; write them as these literals, never as a runtime constant such as `M_PI`):

| Seed path | Value | Unit | Tag | Scope |
|---|---|---|---|---|
| `constants.earth_radius_m` | 6371008.8 | m | measured | build |
| `constants.pi` | 3.141592653589793 |  | derived | fixed |
| `constants.ln2` | 0.6931471805599453 |  | derived | fixed |
| `constants.z80` | 1.2815515655446004 | standard deviations | derived | fixed |
| `constants.meters_per_mile` | 1609.344 | m | measured | fixed |
| `constants.round_half` | 0.500000001 |  | derived | fixed |
| `constants.qkey_scale` | 1000000.0 |  | assumed | fixed |

### 1.3 Time

1. **No clock, no time zone.** No function reads the current time or a time zone. "Today" and "now" are inputs chosen by the caller. A date is a civil date in the truck's region; the model never converts between zones.
2. **Day of week** comes from 4.1 `day_of_week` only.
3. **Hour of week** `how = dow * 24 + hour`, `dow` 0 = Monday, so `how` runs 0 (Monday 00:00) .. 167 (Sunday 23:00).
4. **Minutes.** A minute value `m` on a service date `D` means `m` minutes after local midnight starting `D`. `m` may be negative (the previous civil day, used only by the timeline) or `>= 1440` (the next civil day). `day_shift = floor_div(m, 1440)`, `minute_of_day = m - 1440 * day_shift`, `hour = floor_div(minute_of_day, 60)`.
5. **Service windows** are half-open `[open, close)` with integers `0 <= open <= close <= 2880`. A window may cross midnight; hours at or after 1440 belong to civil date `D + 1` and use that date's `DayContext` (its own day types, holiday, forecast). `open == close` is a valid empty window.
6. **Curves** are indexed by the civil clock hour of the civil date they apply to: `saturday[0]` is Saturday 00:00-00:59, the tail of Friday night.
7. Daylight-saving changes are ignored: every civil date has hours 0..23. The backend maps forecast periods to wall-clock hours (first occurrence wins; a missing hour is a missing forecast).

### 1.4 Rounding, ranking keys, tolerance

**One rounding helper.** No runtime `round()` anywhere in Truck Planner code.

```
POW10 = [1.0, 10.0, 100.0, 1000.0, 10000.0, 100000.0, 1000000.0, 10000000.0, 100000000.0, 1000000000.0]

round_half_away(x: real, decimals: int 0..9) -> real
    p = POW10[decimals]
    a = abs(x) * p
    n = floor(a + 0.500000001)        # 0.5 plus a 1e-9 nudge, one addition, literal constants.round_half
    r = n / p
    if x < 0 and r != 0: return -r
    return r                          # never returns negative zero
```

The nudge makes decimal halves that binary cannot represent round the way a person expects (`2.675 -> 2.68`); the price is that anything within 1e-9 scaled units below a half also rounds up. Because the helper is a fixed sequence of binary64 operations, all runtimes return identical bits.

| x | decimals | round_half_away |
|---|---|---|
| 2.675 | 2 | 2.68 |
| 1.005 | 2 | 1.01 |
| 2.5 | 0 | 3.0 |
| -2.5 | 0 | -3.0 |
| -0.004 | 2 | 0.0 |
| 65.533 | 0 | 66.0 |
| 0.4999999995 | 0 | 1.0 |
| 1234.5678 | 1 | 1234.6 |
| -17.345 | 2 | -17.35 |

**Where rounding is allowed.**

| Place | Rule |
|---|---|
| Presentation (UI, day sheet, exports, stored cents) | `round_half_away`, any decimals. Never feed a rounded value back into the model. |
| Leg minutes (4.10) | `round_half_away(raw, 0)`, part of the model: timelines are in whole minutes. |
| Ranking keys | `qkey` below. |
| Map colour byte (4.17) | `floor(255.0 * sqrt(t) + 0.5)` with `t = clamp(x / hi, 0.0, 1.0)`. |
| Everything else | no rounding. `Estimate.low/high`, orders, dollars and factors stay full precision. |

**Ranking key.** Every comparison that decides an order or a tie uses whole millionths: `qkey(x) = floor(x * 1000000.0 + 0.5)` (an integer). "Larger `qkey` first, ties by ..." is how all rankings below are written; raw reals are never compared for ordering. `qkey(25.776)` = 25776000; `qkey(0.0000004)` = 0.

**Golden-case tolerance.** Numbers are compared as doubles whatever their JSON spelling: if both values are integral they must be equal, otherwise `abs(a - b) <= 1e-9 * max(1.0, abs(a), abs(b))`. Strings, booleans, `null`, array lengths and object key sets must match exactly. The map fast path has its own tolerance (4.17).

### 1.5 Determinism rules

1. Same inputs, same output, on every machine: no clock, no random numbers, no locale, no environment, no process time zone, no I/O inside the model.
2. Iterate arrays in index order. Iterate maps in ascending byte-wise key order; never rely on insertion or hash order.
3. Sort with an explicit, total comparator (all keys listed in the text). Source points and outlets are processed in ascending `id`.
4. Sums are plain left-to-right additions in the stated order: no pairwise, Kahan or library summation (Python's built-in `sum` is compensated since 3.12 and must not be used).
5. Multiply and divide in the order written in the formulas, left to right.
6. `model_version` is the string `tps-0.1.0`. Every stored result carries it together with `seeds_revision`; results from different versions are never mixed in one calculation.

---

## 2. Seeds

### 2.1 File format

`tp_seeds.json` is one JSON object. Top-level keys: `model_version`, `seeds_revision`, `as_of`, `about`, `entry_format`, `vocabulary`, then the groups `constants`, `hours`, `kernel`, `segments`, `etl`, `host`, `place_types`, `holidays`, `weather`, `traffic`, `drive_fallback`, `profile_defaults`, `money`, `events`, `uncertainty`, `calibration`, `timeline`, `suggest`, `scout`, `map`. The lists under `vocabulary` are in the index order of 1.1; the seed-integrity test compares them with the constants in code.

| Kind of entry | Shape |
|---|---|
| Scalar entry | `{ "value": <number, string, boolean or array>, "unit": string, "tag": "measured" or "derived" or "assumed" or "tuned", "scope": string, "min"?: number, "max"?: number, "source": string }` |
| Compound entry (a curve group, a weight table, a banded table) | one object holding shared metadata (`unit`, `tag`, `scope`, `min`, `max`, `source`) plus several named values (for example `weekday`, `saturday`, `sunday`, each an array of 24 numbers), or `order` (array of row ids) plus `rows` (object keyed by row id; a row may carry its own `tag` and `source`) |

Metadata is inherited: the `scope`, `min`, `max`, `tag` of a value are those of the nearest enclosing object that defines them. Tags: `measured` = published by a source that was opened; `derived` = arithmetic on measured inputs; `assumed` = engineering judgement; `tuned` = no outside evidence, set so the two anchors of section 8 land in range.

**Reading a seed.** A seed path is a dot-separated list of keys from the root.

```
seed(A: Assumptions, path: string) -> any
    if path is a key of A.overrides: return A.overrides[path]
    node = A.seeds
    for key in split(path, "."): node = node[key]           # a missing key is a programming error
    if node is an object that has the key "value": return node.value
    return node
```

Examples: `seed(A, "kernel.outside_option_a0")` = 1.6; `seed(A, "segments.w_office.presence.weekday")` = array of 24; `seed(A, "segments.w_office.dow_factor")` = `[0.9, 1.19, 1.16, 1.08, 0.67]`; `seed(A, "weather.temperature_bands.rows.50_59.open")` = 0.9; `seed(A, "place_types.rows.taproom")` = the row object. Model code reads every overridable seed by exactly the leaf path written in this document, so an override always takes effect.

### 2.2 Scopes and the override mechanism

| `scope` | Meaning | Who may change it |
|---|---|---|
| `build` | Baked into the region pack (per-point `rivals`, per-cell vectors) or into the place and point tables. The pack manifest records every `build` value it was built with; the server refuses to serve a pack whose recorded values differ from the seed file. | Nobody at runtime. Changing one means a new `seeds_revision` and a region rebuild. |
| `fixed` | Runtime constant of the model. | Nobody at runtime. |
| `owner` | Runtime assumption the owner may override for their truck. | The owner, through `Assumptions.overrides`. |
| `profile_default` | Initial value of a `TruckProfile` field; after that the profile holds the truth. | The owner, by editing the profile (not through overrides). |

`Assumptions.overrides` is a sparse map `seed path -> value`. The value replaces the seed value wholesale (a number, a string, or a whole array).

```
validate_overrides(seeds, overrides) -> list of { path, error }
    for (path, value) in overrides, ascending path:
        1. walk the path; a missing key                                  -> "unknown_path"
        2. the last key is one of seeds.vocabulary.structural_keys         -> "not_a_seed"
        3. scope inherited at the node is not "owner"                      -> "not_overridable"
        4. target = node.value if node is an object with "value" else node
           target is an object                                            -> "not_a_leaf"
        5. value must have the type and shape of target: number for number (finite), string for string,
           array of the same length with elements of the same type          -> "wrong_shape"
        6. every number must satisfy inherited min <= x <= max            -> "out_of_bounds"
        7. a string must be one of the inherited "allowed" list           -> "not_allowed"
```

Examples against revision 1: `{"host.captive_share": 0.6}` is valid; `{"host.captive_share": 1.5}` -> `out_of_bounds`; `{"host.captive_share.value": 0.5}` and `{"weather.temperature_bands.rows.50_59.upper_f": 61}` -> `not_a_seed`; `{"kernel.outside_option_a0": 2.0}` and `{"profile_defaults.avg_ticket": 12.0}` -> `not_overridable`; `{"segments.w_office.presence.weekday": [23 numbers]}` -> `wrong_shape`; `{"segments.w_office.presence": {...}}` -> `not_a_leaf`; `{"segments.res.holiday_day_type.major": "monday"}` -> `not_allowed`; `{"no.such.path": 1}` -> `unknown_path`.

Invalid overrides are rejected when saved (never clamped); the model functions assume a validated `Assumptions`. Paths that are overridable in revision 1: `segments.<s>.presence.<day_type>`, `segments.<s>.intent.<day_type>`, `segments.<s>.dow_factor`, `segments.<s>.holiday_day_type.major|minor`, `host.captive_share`, `host.shared_kitchen_share`, `host.onsite_kitchen_weight`, `weather.floor`, `weather.pop_when_missing`, `weather.<table>.rows.<id>.open|captive`, `events.attendance_haircut`, `events.p_buy.<type>`.

### 2.3 Seed values

The JSON file is the source of truth and also holds the `source` note of every entry and all curve arrays; the tables below show every scalar and a checksum row per curve. To change a seed, edit the file, raise `seeds_revision`, regenerate the golden cases and update these tables (the reference implementation should be able to print them).

**Kernel** (all `build`)

| Seed path | Value | Unit | Tag | Scope |
|---|---|---|---|---|
| `kernel.walk_decay_m` | 400.0 | m (e-folding distance) | assumed | build |
| `kernel.walk_cutoff_m` | 1200.0 | m | derived | build |
| `kernel.outside_option_a0` | 1.6 | truck-at-the-door equivalents | tuned | build |

`f(d) = exp(-d / walk_decay_m)` for `0 <= d <= walk_cutoff_m`, else 0. `outside_option_a0` is `A0`: a truck at the door (`f = 1`, `V = 1`) with no rival wins `1 / (1.6 + 1)` = 38.5 % of the people who buy a meal that hour.

| Rival kind | `day` weight | `eve` weight | Tag |
|---|---|---|---|
| `quick` | 1.0 | 1.0 | assumed |
| `full` | 0.6 | 1.0 | assumed |
| `cafe` | 0.5 | 0.2 | assumed |
| `bar` | 0.1 | 0.6 | assumed |
| `convenience` | 0.5 | 0.4 | assumed |

| Level | Multiplier `V` | Tag |
|---|---|---|
| `hidden` | 0.6 | assumed |
| `normal` | 1.0 | assumed |
| `prominent` | 1.3 | assumed |

**Segments.** `base` is what the data counts; `presence` turns one unit of base into people physically there in an hour; `intent` is the probability that a present person buys a meal or substantial snack from some outlet during that hour (a rate per person-hour, the outside option included). The `w_*` bases are sums of the LODES sectors listed per segment in the file (`lodes_cns`); construction jobs (CNS04) enter `w_industrial` at weight `etl.cns04_weight` = 0.3 because they are recorded at the contractor's office.

| # | Key | Label | Group | Base unit (what one unit of `base` is) | `host_mode` | Weak | Presence tag | Intent tag |
|---|---|---|---|---|---|---|---|---|
| 0 | `res` | Residents at home | residents | residents (2020 Census block count) | open |  | assumed | assumed |
| 1 | `w_office` | Office workers | workers | jobs (LODES WAC) | open |  | derived | assumed |
| 2 | `w_health` | Healthcare workers | workers | jobs (LODES WAC) | open |  | derived | assumed |
| 3 | `w_edu` | Education workers | workers | jobs (LODES WAC) | open |  | derived | assumed |
| 4 | `w_retail` | Retail workers | workers | jobs (LODES WAC) | open |  | derived | assumed |
| 5 | `w_industrial` | Industrial and logistics workers | workers | jobs (LODES WAC) | open |  | derived | assumed |
| 6 | `w_hospitality` | Hospitality workers | workers | jobs (LODES WAC) | open |  | derived | assumed |
| 7 | `w_public` | Public and other-services workers | workers | jobs (LODES WAC) | open |  | derived | assumed |
| 8 | `v_nightlife` | Taproom and bar patrons | visitors | people in the venue in its busiest regular hour | captive |  | assumed | tuned |
| 9 | `v_shopping` | Shoppers | visitors | people on site in the busiest regular hour | open |  | assumed | assumed |
| 10 | `v_leisure` | Park and gym visitors | visitors | people on site in the busiest regular hour | open |  | assumed | assumed |
| 11 | `v_campus` | Students on campus | visitors | students on campus in the busiest regular hour | open | yes | assumed | assumed |
| 12 | `v_hospital` | Hospital visitors | visitors | visitors in the building in the busiest regular hour | open | yes | assumed | assumed |
| 13 | `v_transit` | Transit riders | visitors | station entries in the busiest regular hour | open | yes | derived | assumed |
| 14 | `v_events` | Event and culture visitors | visitors | people on site in the busiest regular hour | captive |  | assumed | assumed |
| 15 | `v_lodging` | Hotel guests | visitors | guests in house overnight | open |  | assumed | assumed |

Rules that hold for the curve arrays (the seed-integrity test must assert them): 24 numbers each; presence in `[0, 1]`; intent in `[0, 0.5]`; for every visitor segment the largest value over its three presence arrays is exactly 1.0, so a venue's base is its headcount in the busiest regular hour before the Monday-Friday factor (after the Monday-Friday factor six `v_leisure` hours exceed 1: Monday, Tuesday and Wednesday at 17:00 and 18:00, at 1.15, 1.10 and 1.02; the test asserts presence x factor <= 1.2 everywhere); worker presence is bodies on site per job and peaks between 0.34 and 0.52. All Monday-Friday factors and holiday day types are tagged `assumed` (the office Tuesday value 1.19 is Kastle's measured ratio).

| Segment | Sum presence weekday | Sat | Sun | Weekday peak@hour | Saturday peak@hour | Sum intent weekday | Sat | `dow_factor` Mon..Fri | Holiday: major | minor |
|---|---|---|---|---|---|---|---|---|---|---|
| `res` | 17.540 | 18.800 | 19.870 | 0.96@01 | 0.96@02 | 0.1920 | 0.2610 | [1.0, 1.0, 1.0, 1.0, 1.0] | sunday | weekday |
| `w_office` | 3.485 | 0.253 | 0.210 | 0.369@10 | 0.027@10 | 0.5550 | 0.4717 | [0.9, 1.19, 1.16, 1.08, 0.67] | sunday | weekday |
| `w_health` | 5.335 | 2.139 | 2.016 | 0.507@10 | 0.203@10 | 0.5800 | 0.5800 | [1.0, 1.0, 1.0, 1.0, 1.0] | saturday | weekday |
| `w_edu` | 4.663 | 0.147 | 0.098 | 0.513@13 | 0.016@09 | 0.2300 | 0.2300 | [1.0, 1.0, 1.0, 1.0, 1.0] | sunday | sunday |
| `w_retail` | 4.778 | 3.897 | 3.064 | 0.433@12 | 0.353@11 | 0.5000 | 0.5000 | [1.0, 1.0, 1.0, 1.0, 1.0] | saturday | weekday |
| `w_industrial` | 5.249 | 2.500 | 1.501 | 0.46@10 | 0.219@10 | 0.5350 | 0.5350 | [1.0, 1.0, 1.0, 1.0, 1.0] | sunday | weekday |
| `w_hospitality` | 4.084 | 3.329 | 2.836 | 0.34@11 | 0.277@11 | 0.2300 | 0.2300 | [1.0, 1.0, 1.0, 1.0, 1.0] | saturday | weekday |
| `w_public` | 4.093 | 0.867 | 0.782 | 0.426@10 | 0.09@10 | 0.4300 | 0.4300 | [1.0, 1.0, 1.0, 1.0, 1.0] | sunday | sunday |
| `v_nightlife` | 3.480 | 8.870 | 4.250 | 0.62@18 | 1.0@16 | 2.1400 | 2.1400 | [0.5, 0.54, 0.79, 1.0, 1.5] | saturday | weekday |
| `v_shopping` | 5.480 | 8.650 | 6.680 | 0.56@17 | 1.0@13 | 0.6150 | 0.6150 | [0.95, 0.95, 0.98, 1.02, 1.1] | saturday | saturday |
| `v_leisure` | 9.730 | 10.150 | 9.170 | 1.0@17 | 1.0@10 | 0.2100 | 0.4400 | [1.15, 1.1, 1.02, 0.95, 0.78] | saturday | weekday |
| `v_campus` | 9.133 | 1.166 | 1.117 | 1.0@11 | 0.133@12 | 0.5800 | 0.5800 | [1.0, 1.0, 1.0, 1.0, 1.0] | sunday | weekday |
| `v_hospital` | 10.470 | 6.000 | 6.000 | 1.0@10 | 0.6@14 | 0.4000 | 0.4000 | [1.0, 1.0, 1.0, 1.0, 1.0] | saturday | weekday |
| `v_transit` | 10.091 | 6.476 | 5.373 | 1.0@17 | 0.495@16 | 0.4000 | 0.4000 | [1.0, 1.0, 1.0, 1.0, 1.0] | sunday | saturday |
| `v_events` | 2.300 | 8.550 | 5.850 | 0.27@12 | 1.0@13 | 1.4600 | 1.4600 | [0.6, 0.8, 0.9, 1.1, 1.6] | saturday | saturday |
| `v_lodging` | 16.323 | 16.399 | 16.272 | 1.0@01 | 1.0@02 | 0.4300 | 0.4300 | [1.0, 1.0, 1.0, 1.0, 1.0] | saturday | weekday |

Check on the worker curves: with the Washington CBSA job counts by segment (LODES 2023, recon 09; construction at weight 0.3) they put 1,244,215 people on site, 0.396 of the region's 3,140,158 jobs, at 12:00 on an average weekday.

**Place types** (`build`; `host_fit` is used only by scouting, never in an estimate). `Default size` is the `base` of the place's own source point in its visitor segment; "none" means the place has no people of its own (they are already in the census or jobs data, or the place is handled as an event). A place whose size resolves to 0 has no source point. The data document may also withhold the source point where a type default would mislead (at revision 1: `campus` elements that are not site polygons); that rule is accepted here. Scouting uses the place's own `size_default` from the data (`PlaceInput.size_default`), not the seed row. A place's own `kitchen` state is `yes`, `no` or `unknown`; `unknown` resolves to the type's `kitchen_default`, and the per-place rival kind is decided by the data document from the type's `rival_kind` and that state.

| Place type | Visitor segment | Default size | Rival kind | Host fit | Kitchen default | Host segment | Tag |
|---|---|---|---|---|---|---|---|
| `taproom` | `v_nightlife` | 40.0 | none | 1.0 | no | `v_nightlife` | assumed |
| `bar` | `v_nightlife` | 45.0 | `bar` | 0.3 | yes | `v_nightlife` | assumed |
| `restaurant` | none | 0 | `full` | 0.0 | yes | none | assumed |
| `fast_food` | none | 0 | `quick` | 0.0 | yes | none | assumed |
| `cafe` | none | 0 | `cafe` | 0.0 | yes | none | assumed |
| `convenience` | none | 0 | `convenience` | 0.0 | yes | none | assumed |
| `gym` | `v_leisure` | 50.0 | none | 0.4 | no | `v_leisure` | assumed |
| `park` | `v_leisure` | 38.0 | none | 0.4 | no | `v_leisure` | assumed |
| `shopping_centre` | `v_shopping` | 150.0 | none | 0.4 | yes | `v_shopping` | assumed |
| `big_box` | `v_shopping` | 200.0 | none | 0.5 | no | `v_shopping` | assumed |
| `campus` | `v_campus` | 400.0 | none | 0.4 | yes | `v_campus` | assumed |
| `hospital` | `v_hospital` | 120.0 | none | 0.4 | yes | `v_hospital` | assumed |
| `transit_station` | `v_transit` | 300.0 | none | 0.1 | no | `v_transit` | assumed |
| `events_venue` | `v_events` | 80.0 | none | 0.6 | no | `v_events` | assumed |
| `stadium` | `v_events` | 0 | none | 0.3 | yes | `v_events` | assumed |
| `hotel` | `v_lodging` | 90.0 | none | 0.3 | yes | `v_lodging` | assumed |
| `attraction` | `v_events` | 150.0 | none | 0.3 | yes | `v_events` | assumed |
| `farmers_market` | none | 0 | none | 0.8 | no | none | assumed |
| `office_park` | none | 0 | none | 0.8 | no | `w_office` | assumed |
| `apartment_community` | none | 0 | none | 0.7 | no | `res` | assumed |
| `industrial_site` | none | 0 | none | 0.6 | no | `w_industrial` | assumed |
| `car_dealership` | `v_shopping` | 15.0 | none | 0.5 | no | `v_shopping` | assumed |

**Federal holidays** (rules only; the classes are assumed)

| Id | Name | Rule (actual date) | Class |
|---|---|---|---|
| `new_year` | New Year's Day | 01-01 | major |
| `mlk` | Birthday of Martin Luther King, Jr. | third Monday of month 1 | minor |
| `washington` | Washington's Birthday | third Monday of month 2 | minor |
| `memorial` | Memorial Day | last Monday of month 5 | major |
| `juneteenth` | Juneteenth National Independence Day | 06-19; from 2021 | minor |
| `independence` | Independence Day | 07-04 | major |
| `labor` | Labor Day | first Monday of month 9 | major |
| `columbus` | Columbus Day | second Monday of month 10 | minor |
| `veterans` | Veterans Day | 11-11 | minor |
| `thanksgiving` | Thanksgiving Day | fourth Thursday of month 11 | major |
| `christmas` | Christmas Day | 12-25 | major |
| `inauguration` | Inauguration Day | 01-20 of years with (year - 1965) mod 4 = 0, year >= 1969; only when region flag `inauguration_day` is true | minor |

**Weather** (every `open` and `captive` multiplier is `owner`-overridable)

| Band id | Applies when | `open` | `captive` | Tag |
|---|---|---|---|---|
| `below_20` | temp_f < 20 | 0.4 | 0.7 | assumed |
| `20_31` | temp_f < 32 | 0.55 | 0.8 | assumed |
| `32_39` | temp_f < 40 | 0.66 | 0.88 | derived |
| `40_49` | temp_f < 50 | 0.78 | 0.93 | derived |
| `50_59` | temp_f < 60 | 0.9 | 0.97 | derived |
| `60_79` | temp_f < 80 | 1.0 | 1.0 | assumed |
| `80_89` | temp_f < 90 | 0.95 | 1.0 | assumed |
| `90_94` | temp_f < 95 | 0.85 | 0.95 | assumed |
| `95_up` | otherwise | 0.7 | 0.9 | assumed |

| Order | Class id | Substrings (any one present, after ASCII lower-casing) | `open` | `captive` | Tag |
|---|---|---|---|---|---|
| 1 | `storm` | "thunder", "t-storm", "tstorm", "tornado", "hurricane", "tropical storm" | 0.3 | 0.6 | assumed |
| 2 | `heavy_snow` | "heavy snow", "blizzard", "snow squall" | 0.25 | 0.5 | assumed |
| 3 | `ice` | "freezing rain", "freezing drizzle", "sleet", "ice pellets", "wintry mix", "ice storm" | 0.25 | 0.5 | assumed |
| 4 | `heavy_rain` | "heavy rain", "torrential", "downpour" | 0.3 | 0.6 | assumed |
| 5 | `snow` | "snow", "flurries" | 0.5 | 0.75 | assumed |
| 6 | `light_rain` | "light rain", "drizzle", "sprinkles", "light showers" | 0.8 | 0.92 | assumed |
| 7 | `rain` | "rain", "shower" | 0.55 | 0.8 | assumed |
| 8 | `dry` | (no match needed: default) | 1.0 | 1.0 | assumed |

| Band id | Applies when | `open` | `captive` | Tag |
|---|---|---|---|---|
| `calm` | wind_mph < 20 | 1.0 | 1.0 | assumed |
| `windy` | wind_mph < 30 | 0.85 | 0.95 | assumed |
| `very_windy` | otherwise | 0.6 | 0.85 | assumed |

**Traffic.** `traffic.dc` and `traffic.us_mean` are 7 x 24 matrices, rows Monday..Sunday, 24 local clock hours each: travel time divided by free-flow travel time (TomTom Traffic Index 2025; scope `fixed`; `dc` is tagged `measured`, `us_mean`, the mean of 11 US metros, `derived`). `seed(A, "traffic.dc")` returns the matrix. The source tables were Sunday-first; they are stored Monday-first: `stored[i] = source[(i + 1) mod 7]`. `traffic.dc_typical` (1.265) and `traffic.us_mean_typical` (1.249) are the means of each matrix's 168 values; 4.10 uses them to put Google durations on the same scale. Check values:

| Matrix | Row (dow) | 03:00 | 08:00 | 10:00 | 12:00 | 14:00 | 17:00 | 20:00 |
|---|---|---|---|---|---|---|---|---|
| `dc` | 0 Mon | 1.04 | 1.43 | 1.25 | 1.29 | 1.38 | 1.59 | 1.2 |
| `dc` | 3 Thu | 1.04 | 1.54 | 1.31 | 1.32 | 1.44 | 1.72 | 1.23 |
| `dc` | 5 Sat | 1.07 | 1.14 | 1.26 | 1.36 | 1.4 | 1.4 | 1.24 |
| `dc` | 6 Sun | 1.08 | 1.09 | 1.18 | 1.28 | 1.32 | 1.28 | 1.19 |
| `us_mean` | 1 Tue | 1.04 | 1.54 | 1.29 | 1.31 | 1.4 | 1.68 | 1.19 |
| `us_mean` | 6 Sun | 1.06 | 1.08 | 1.17 | 1.27 | 1.29 | 1.26 | 1.17 |

**Truck profile defaults** (`profile_default`; the valid range is what the profile form must enforce)

| TruckProfile field | Default | Unit | Tag | Valid range |
|---|---|---|---|---|
| `avg_ticket` | 15.0 | USD per order | assumed | 1.0 .. 200.0 |
| `capacity_orders_per_hour` | 45.0 | orders per hour | assumed | 0.0 .. 400.0 |
| `paid_crew` | 2 | people | assumed | 0 .. 12 |
| `wage_per_hour` | 18.0 | USD per hour | assumed | 0.0 .. 200.0 |
| `payroll_burden_pct` | 0.1 | fraction of wages | assumed | 0.0 .. 1.0 |
| `food_cost_pct` | 0.3 | fraction of sales | assumed | 0.0 .. 0.95 |
| `packaging_per_order` | 0.5 | USD per order | assumed | 0.0 .. 20.0 |
| `card_fee_pct` | 0.026 | fraction of card sales | measured | 0.0 .. 0.2 |
| `card_fee_fixed` | 0.15 | USD per card order | measured | 0.0 .. 5.0 |
| `card_share` | 0.85 | fraction of sales paid by card | assumed | 0.0 .. 1.0 |
| `tips_include` | false | boolean | assumed |  |
| `tips_pct_of_card_sales` | 0.1 | fraction of card sales | derived | 0.0 .. 0.5 |
| `mpg` | 9.0 | miles per gallon | assumed | 1.0 .. 60.0 |
| `fuel_type` | gasoline | gasoline or diesel | assumed |  |
| `generator_gal_per_hour` | 0.6 | gallons per hour | measured | 0.0 .. 5.0 |
| `prep_minutes` | 45 | minutes | assumed | 0 .. 600 |
| `setup_minutes` | 30 | minutes | assumed | 0 .. 240 |
| `teardown_minutes` | 20 | minutes | assumed | 0 .. 240 |
| `closeout_minutes` | 30 | minutes | assumed | 0 .. 600 |
| `fixed_cost_per_service_day` | 0.0 | USD per service day | assumed | 0.0 .. 5000.0 |
| `daypart_fit` | breakfast 0.3, lunch 1.0, dinner 1.0, late 0.8 | multiplier on meal intent in that daypart | assumed | 0.0 .. 1.0 |
| `avoid_tolls` | false | boolean | assumed |  |
| `avoid_highways` | false | boolean | assumed |  |
| `truck_time_factor` | 1.1 | multiplier on car drive time | assumed | 0.5 .. 3.0 |
| `scout_drive_minutes_limit` | 45 | minutes | assumed | 5 .. 60 |

**All other scalar seeds**

| Seed path | Value | Unit | Tag | Scope |
|---|---|---|---|---|
| `etl.cns04_weight` | 0.3 | weight of construction jobs (LODES CNS04) in w_industrial | assumed | build |
| `etl.cell_min_nearby` | 100.0 | distance-weighted people (sum of the nearby vector) | assumed | build |
| `etl.cell_min_venue` | 15.0 | distance-weighted venue visitors | assumed | build |
| `host.captive_share` | 0.75 | share of the venue's meal intent | tuned | owner |
| `host.shared_kitchen_share` | 0.3 | share of the venue's meal intent | assumed | owner |
| `host.onsite_kitchen_weight` | 2.0 | truck-at-the-door equivalents | assumed | owner |
| `host.exclusion_radius_m` | 250.0 | m | assumed | fixed |
| `host.venue_link_radius_m` | 75.0 | m | assumed | fixed |
| `weather.floor` | 0.15 | multiplier | assumed | owner |
| `weather.pop_when_missing` | 0.5 | probability | assumed | owner |
| `traffic.dc_typical` | 1.265 | travel time under average traffic / free-flow travel time | derived | fixed |
| `traffic.us_mean_typical` | 1.249 | travel time under average traffic / free-flow travel time | derived | fixed |
| `drive_fallback.detour_factor` | 1.3 | road distance / straight-line distance | assumed | fixed |
| `drive_fallback.local_miles` | 2.0 | mi | assumed | fixed |
| `drive_fallback.local_mph` | 25.0 | mph free-flow | assumed | fixed |
| `drive_fallback.trunk_mph` | 45.0 | mph free-flow | assumed | fixed |
| `money.fee_warn_share` | 0.1 | fraction of expected sales | assumed | fixed |
| `money.fuel_price_fallback.gasoline.R1Y` | 4.411 | USD per gallon, week of 2026-09-28 | measured | fixed |
| `money.fuel_price_fallback.gasoline.R1Z` | 4.195 | USD per gallon, week of 2026-09-28 | measured | fixed |
| `money.fuel_price_fallback.gasoline.NUS` | 4.465 | USD per gallon, week of 2026-09-28 | measured | fixed |
| `money.fuel_price_fallback.diesel.R1Y` | 6.531 | USD per gallon, week of 2026-09-28 | measured | fixed |
| `money.fuel_price_fallback.diesel.R1Z` | 5.953 | USD per gallon, week of 2026-09-28 | measured | fixed |
| `money.fuel_price_fallback.diesel.NUS` | 6.382 | USD per gallon, week of 2026-09-28 | measured | fixed |
| `events.attendance_haircut` | 0.6 | multiplier on the organiser's forecast | assumed | owner |
| `events.min_attendees_per_vendor` | 200.0 | expected attendees per food vendor | assumed | fixed |
| `events.suggested_fee_pct` | 0.1 | fraction of sales | assumed | fixed |
| `events.suggested_fee_min` | 75.0 | USD | assumed | fixed |
| `events.p_buy.general` | 0.35 | share of attendees who buy a meal | assumed | owner |
| `events.p_buy.food_focused` | 0.75 | share of attendees who buy a meal | assumed | owner |
| `events.p_buy.evening_show` | 0.28 | share of attendees who buy a meal | assumed | owner |
| `events.p_buy.incidental` | 0.15 | share of attendees who buy a meal | assumed | owner |
| `uncertainty.sd_truck` | 0.24 | natural-log units | derived | fixed |
| `uncertainty.sd_spot` | 0.19 | natural-log units | derived | fixed |
| `uncertainty.sd_day` | 0.2 | natural-log units | derived | fixed |
| `uncertainty.sd_weak` | 0.4 | natural-log units | assumed | fixed |
| `uncertainty.sd_default_size` | 0.6 | natural-log units | assumed | fixed |
| `uncertainty.sd_event` | 0.5 | natural-log units | assumed | fixed |
| `uncertainty.count_dispersion` | 2.0 | variance / mean | assumed | fixed |
| `uncertainty.resid_prior_weight` | 6.0 | services | assumed | fixed |
| `uncertainty.label_good_below` | 0.23 | natural-log units | assumed | fixed |
| `uncertainty.label_fair_below` | 0.31 | natural-log units | assumed | fixed |
| `uncertainty.label_rough_below` | 0.41 | natural-log units | assumed | fixed |
| `calibration.k_truck` | 4.0 | services | assumed | fixed |
| `calibration.k_spot` | 3.0 | services | assumed | fixed |
| `calibration.half_life_days` | 120.0 | days | assumed | fixed |
| `calibration.ratio_clamp` | 4.0 | ratio | assumed | fixed |
| `calibration.spot_ratio_clamp` | 50.0 | ratio | assumed | fixed |
| `calibration.min_predicted` | 3.0 | orders | assumed | fixed |
| `calibration.min_actual` | 0.5 | orders | assumed | fixed |
| `calibration.min_resid_n` | 3 | services | assumed | fixed |
| `timeline.long_gap_minutes` | 90 | minutes | assumed | fixed |
| `timeline.long_day_minutes` | 720 | minutes | assumed | fixed |
| `timeline.early_start_minute` | 300 | minute of day | assumed | fixed |
| `suggest.service_minutes` | 180 | minutes | assumed | fixed |
| `suggest.earliest_open_minute` | 360 | minute of day | assumed | fixed |
| `suggest.latest_close_minute` | 1440 | minute of day | assumed | fixed |
| `suggest.windows_per_spot` | 2 | windows | assumed | fixed |
| `suggest.max_candidates` | 24 | candidates | assumed | fixed |
| `suggest.max_stops_per_day` | 2 | stops | assumed | fixed |
| `suggest.max_day_minutes` | 840 | minutes | assumed | fixed |
| `suggest.min_stop_orders` | 5.0 | orders | assumed | fixed |
| `suggest.day_results` | 3 | plans | assumed | fixed |
| `suggest.week_day_options` | 5 | plans | assumed | fixed |
| `suggest.max_days_per_week` | 5 | days | assumed | fixed |
| `suggest.max_visits_per_spot_per_week` | 2 | visits | assumed | fixed |
| `suggest.min_day_take_home` | 0.0 | USD | assumed | fixed |
| `scout.window_minutes` | 180 | minutes | assumed | fixed |
| `scout.max_results` | 50 | places | assumed | fixed |
| `map.opportunity_hi` | 45.0 | orders per hour | assumed | fixed |
| `map.people_hi` | 20000.0 | people present, distance-weighted | assumed | fixed |
| `map.competition_hi` | 100.0 | rival weight | assumed | fixed |

### 2.4 What changed relative to the reconnaissance report (recon 10)

1. Segment names are the shared keys; curves otherwise as generated by the recon script (worker presence at 3 decimals, intent at 4).
2. `v_campus`, `v_transit` and `v_lodging` presence were rescaled (divided by 0.60, 0.099, 0.96) so every venue base is a busiest-hour headcount.
3. `v_nightlife` Sunday 00:00-01:59 presence set to 0.12 and 0.05 (recon had 0, which made Saturday night vanish at midnight).
4. Competition is per origin with weights by rival kind and regime (recon had one weight per kind).
5. Hosts whose people come and go (`host_mode` `open`) use the kernel at distance zero instead of a flat share; flat shares remain for `v_nightlife` and `v_events`.
6. The holiday rule covers all 16 segments and distinguishes major from minor holidays.
7. Weather: the class effect is blended with the probability of precipitation; an `ice` class was added.
8. Farmers markets and stadiums have no hourly presence; they are event stops.
9. The recon's "awareness ramp" for new spots and the taproom seasonal factor are not in the model (section 6).
10. The prior spread (one value, 0.47, in the recon) is split into truck 0.24, spot 0.19 and day 0.20 (DECISIONS 7.7).

---

## 3. Data shapes

Field names are snake_case and are the contract for the API and the frontend types. `number` = real, `int` = integer, `?` = may be `null`. Arrays written `[T x16]` have exactly that length.

```
Estimate         = { value: number, low: number, high: number, confidence: "fixed"|"good"|"fair"|"rough"|"very_rough" }
                   # invariant: low <= value <= high

TruckProfile     = { name: string, region_id: string, base: { lat: number, lng: number, address: string },
                     avg_ticket: number, capacity_orders_per_hour: number, paid_crew: int, wage_per_hour: number,
                     payroll_burden_pct: number, food_cost_pct: number, packaging_per_order: number,
                     card_fee_pct: number, card_fee_fixed: number, card_share: number,
                     tips_include: bool, tips_pct_of_card_sales: number,
                     mpg: number, fuel_type: "gasoline"|"diesel", fuel_price_override: number?,
                     generator_gal_per_hour: number,
                     prep_minutes: int, setup_minutes: int, teardown_minutes: int, closeout_minutes: int,
                     fixed_cost_per_service_day: number,
                     daypart_fit: { breakfast: number, lunch: number, dinner: number, late: number },
                     avoid_tolls: bool, avoid_highways: bool, truck_time_factor: number,
                     licence_counties: [string], scout_drive_minutes_limit: int }

Assumptions      = { model_version: "tps-0.1.0", seeds_revision: int, seeds: <the tp_seeds.json object>,
                     overrides: { <seed path>: <value> },
                     region: { id: string, traffic_matrix: "dc"|"us_mean", flags: { inauguration_day: bool } } }
                   # region.traffic_matrix and region.flags come from the region definition (tp_regions.config_json: traffic_matrix, holidays.inauguration_day)
                   # a point outside every loaded region uses region = { id: "none", traffic_matrix: "us_mean", flags: { inauguration_day: false } }

SourcePoint      = { id: string, lat: number, lng: number, base: [number x16], rivals: { day: number, eve: number } }
Outlet           = { id: string, lat: number, lng: number, kind: "quick"|"full"|"cafe"|"bar"|"convenience" }
Exclusion        = { point_ids: [string], segment: <segment key>?, amount: number }

Host             = { segment: <segment key>, size: number, size_source: "owner"|"default", only_food: bool,
                     point_id: string?, place_type: string? }
                   # size is in the base unit of the segment: busiest-hour headcount for v_*, jobs for w_*, residents for res
SpotTerms        = { spot_id: string?, visibility: "hidden"|"normal"|"prominent", host: Host?,
                     fee_flat: number, fee_pct: number, fee_min: number,
                     allowed: { days: [bool x7], open_minute: int, close_minute: int }? }

LocationVectors  = { capture: { day: [number x16], eve: [number x16] }, nearby: [number x16],
                     within: [number x16]?, rivals: { day: number, eve: number },
                     visibility: "hidden"|"normal"|"prominent", in_region: bool, region_id: string?,
                     exclusion: Exclusion, excluded_amount: number, points_used: int?,
                     dataset_version: string?, model_version: "tps-0.1.0" }
                   # within and points_used are null when the vectors were decoded from 50 stored numbers (the cell pack, tp_places.host_vec)

HourForecast     = { hour: int, temp_f: number?, precip_prob: number?, short_forecast: string?, wind_mph: number? }
                   # precip_prob is 0..100; wind_mph is the largest number in the service's wind text

Holiday          = { id: string, name: string, class: "major"|"minor", date: string, observed: string? }
DayContext       = { date: string?, typical: bool, dow: int, eff_dow: int,
                     holiday: Holiday?, holiday_class: "major"|"minor"|null,
                     treat_as: null|"normal"|"holiday"|"mon"|"tue"|"wed"|"thu"|"fri"|"sat"|"sun",
                     day_type: [string x16], dow_factor: [number x16], traffic_dow: int,
                     forecast: [HourForecast? x24]?, fuel_price_per_gal: number?, fuel_price_source: "owner"|"eia"|"seed"|null }
                   # the two fuel fields are null in typical contexts and wherever the caller passes null; day_plan requires fuel_price_per_gal

CalibrationState = { model_version: string, seeds_revision: int, as_of: string,
                     truck_factor: number, truck_log_factor: number, bias_log: number, truck_n: int, truck_weight: number,
                     spots: { <spot_id>: { factor: number, log_factor: number, n: int, weight: number } },
                     resid_sd: number?, resid_n: int, resid_weight: number }
Evidence         = { truck_weight: number, spot_weight: number, resid_sd: number?, resid_weight: number,
                     weak_share: number, default_size_share: number, event: bool, fixed: bool }

HourSegment      = { segment: string, nearby_present: number, within_present: number?, capture: number, presence: number, intent: number,
                     demand_raw: number, before_cap: number, orders: number }
HourHost         = { segment: string, mode: "captive"|"open", size: number, share: number, people_present: number,
                     presence: number, intent: number, demand_raw: number, weather: number, before_cap: number, orders: number }
WeatherDetail    = { multiplier: number, missing: bool, temp: number, precip: number, wind: number,
                     temp_band: string?, precip_class: string?, precip_p: number?, wind_band: string? }
HourResult       = { date: string?, hour: int, how: int, regime: "day"|"eve", daypart: string,
                     segments: [HourSegment x16], host: HourHost?,
                     factors: { menu_fit: number, weather_open: number, weather_captive: number,
                                weather_state: "forecast"|"missing"|"typical", weather_detail: WeatherDetail?,
                                weather_detail_captive: WeatherDetail?, truck_factor: number, spot_factor: number },
                     demand_raw: number, demand_adj: number, capacity: number, orders: number, capped: bool,
                     weak_part: number, default_size_part: number }
WindowResult     = { date: string?, open_minute: int, close_minute: int, minutes: int,
                     hours: [ { day_index: 0|1, hour: int, fraction: number, result: HourResult } ],
                     orders: Estimate, by_segment: [number x16], host_orders: number,
                     demand_adj: number, capacity_total: number, capped_hours: int,
                     evidence: Evidence,
                     spread: { sigma_model: number, sigma: number, v_truck: number, v_spot: number, v_day: number,
                               v_weak: number, v_size: number, v_event: number, v_count: number } }

StopMoney        = { orders: Estimate, sales: Estimate, food_cost: Estimate, packaging: Estimate, card_fees: Estimate,
                     spot_fee: Estimate, tips: Estimate, contribution: Estimate,
                     unit_margin: { at_minimum: number, at_percentage: number } }

LegInput         = { source: "google"|"fallback", distance_m: number, duration_s: number, override_minutes: int?, toll: number }
                   # google: Google's traffic-unaware duration (average traffic) and road distance; fallback: fallback_leg (free-flow)
Leg              = { from_id: string?, to_id: string?, source: "google"|"fallback"|"override", distance_m: number, miles: number,
                     base_minutes: number, depart_minute: int?, traffic_lookup_minute: int, traffic_dow: int, traffic_hour: int,
                     traffic_factor: number, time_factor: number, truck_time_factor: number, raw_minutes: number, minutes: int, toll: number }
                   # from_id, to_id and depart_minute are null in a bare leg_minutes result; build_timeline fills them
TimelineEvent    = { kind: "start_prep"|"leave_base"|"arrive"|"setup_start"|"open"|"close"|"leave"|"back_at_base"|"done",
                     minute: int, stop_index: int? }
TimelineStop     = { stop_index: int, arrive: int, setup_start: int, open: int, effective_open: int, close: int, leave: int,
                     gap_before_minutes: int, gap_unpaid: bool, late_minutes: int }
Timeline         = { events: [TimelineEvent], stops: [TimelineStop], legs: [Leg],
                     start_prep: int?, leave_base: int?, back_at_base: int?, done: int?,
                     day_minutes: int, paid_minutes: int, unpaid_gap_minutes: int, drive_minutes: int,
                     service_minutes: int, generator_minutes: int, miles: number, tolls: number }

EventTerms       = { attendance: number, vendors: int, event_type: "general"|"food_focused"|"evening_show"|"incidental" }
EventHour        = { day_index: 0|1, hour: int, fraction: number, demand: number, capacity: number, weather: number, orders: number }
EventResult      = { orders: Estimate, buyers: number, demand: number, hours: [EventHour], spread: <the record of WindowResult.spread> }
CateringTerms    = { headcount: number, price_per_head: number?, guarantee: number?, food_cost: number? }
StopInput        = { id: string, kind: "spot"|"event"|"catering", spot_id: string?, point: { lat: number, lng: number },
                     open_minute: int, close_minute: int, gap_before_unpaid: bool,
                     setup_minutes: int?, teardown_minutes: int?,
                     terms: SpotTerms?, vectors: LocationVectors?, event: EventTerms?, catering: CateringTerms? }
                   # id is unique in the plan and never "base"; terms is required for spot and event (an event uses only fee_*)
PlanInput        = { date: string, stops: [StopInput] }
StopAdds         = { take_home: Estimate, hours: number, per_hour: Estimate?, added_costs: number, break_even_orders: number?,
                     uses_fallback_leg: bool }
DayStop          = { stop_index: int, id: string, kind: string, spot_id: string?, window: WindowResult?,
                     event: { buyers: number, demand: number, hours: [EventHour], spread: <the record of WindowResult.spread> }?,
                     orders: Estimate, money: StopMoney, adds: StopAdds }
                   # window is null unless kind is spot; event is null unless kind is event
DayTotals        = { orders: Estimate, sales: Estimate, food_cost: Estimate, packaging: Estimate, card_fees: Estimate,
                     spot_fees: Estimate, tips: Estimate, contribution: Estimate,
                     labour: Estimate, fuel: Estimate, tolls: Estimate, fixed_cost: Estimate,
                     take_home: Estimate, take_home_per_hour: Estimate,
                     day_hours: number, paid_hours: number, work_hours: number, unpaid_gap_hours: number,
                     drive_minutes: int, miles: number, drive_gallons: number, generator_gallons: number }
Warning          = { code: string, level: "info"|"warn"|"error", stop_index: int?, data: object }
DayResult        = { model_version: string, seeds_revision: int, date: string, timeline: Timeline, stops: [DayStop],
                     totals: DayTotals,
                     unpaid_gap_alternative: { take_home: Estimate, take_home_per_hour: Estimate, work_hours: number, labour_saved: number }?,
                     warnings: [Warning] }

SpotInput        = { spot_id: string, point: { lat: number, lng: number }, terms: SpotTerms, vectors: LocationVectors }
SuggestOptions   = { service_minutes: int?, max_stops_per_day: int?, max_days_per_week: int?, max_visits_per_spot_per_week: int?, limit: int? }
Suggestion       = { date: string, position: int, stops: [ { spot_id: string, open_minute: int, close_minute: int } ],
                     take_home: Estimate, orders: Estimate, day_minutes: int, result: DayResult }
WeekSuggestion   = { week_start: string, days: [ { date: string, suggestion: Suggestion? } x7 ],
                     total_take_home: Estimate, visits: { <spot_id>: int }, leaves_visited: int }

PlaceInput       = { place_id: string, place_type: string, point: { lat: number, lng: number }, point_id: string?,
                     size_default: number, kitchen: "yes"|"no"|"unknown"|null, vectors: LocationVectors }
                   # size_default is tp_places.size_default; vectors are decoded from tp_places.host_vec (4.4)
ScoutResult      = { place_id: string, place_type: string, position: int, host_fit: number, kitchen: "yes"|"no",
                     host_segment: string?, host_size: number, size_source: "default",
                     best_window: { dow: int, open_minute: int, close_minute: int }?,
                     orders: Estimate, contribution: Estimate,
                     round_trip: { minutes: int, miles: number, cost: number }, score: number }

ServiceLogEntry  = { service_id: string, kind: "spot"|"event"|"catering", spot_id: string?, date: string,
                     open_minute: int, close_minute: int, actual: number, sold_out: bool,
                     predicted_raw: number, predicted: number, low: number, high: number }
AccuracyBlock    = { n_total: int, n_scored: int, n_sold_out: int, bias: number?, mape: number?, coverage: number?,
                     raw_bias: number?, raw_mape: number? }
AccuracyReport   = AccuracyBlock + { by_spot: [ AccuracyBlock + { spot_id: string } ] }
```

Helpers on `Estimate` used below:

```
est_fixed(x)            = { value: x, low: x, high: x, confidence: "fixed" }
est_levels(v, l, h, c)  = { value: v, low: min(v, l, h), high: max(v, l, h), confidence: c }
weakest(labels)         = the label earliest in [very_rough, rough, fair, good, fixed]; "fixed" for an empty list
est_sum(list)           = { value: sum of values, low: sum of lows, high: sum of highs (each in list order), confidence: weakest(labels) }
```

`est_sum` is how lows and highs combine across stops and days: lows add to lows and highs to highs. This treats stops as moving together, so a day total is deliberately at least as wide as an 80 % interval.

---

## 4. Functions

In pseudo-code `seed("x.y")` is short for `seed(A, "x.y")`; a bare seed name such as `sd_truck` or `k_spot` means the seed of that name in the group the paragraph names; `seeds.segments[s].weak`, `.group` and `.host_mode` are read straight from the file (they are structural, not overridable). `regime_of_hour` and `daypart_of_hour` are the two `hours.*` seeds. In the examples `A_dc` is an `Assumptions` with no overrides and `region = { id: "dc", traffic_matrix: "dc", flags: { inauguration_day: true } }`.

### 4.1 Dates

```
days_from_civil(y: int, m: int, d: int) -> int          # days since 1970-01-01
    y2  = y - 1 if m <= 2 else y
    era = floor_div(y2, 400)
    yoe = y2 - era * 400
    mp  = mod_floor(m + 9, 12)                           # March = 0 ... February = 11
    doy = floor_div(153 * mp + 2, 5) + d - 1
    doe = yoe * 365 + floor_div(yoe, 4) - floor_div(yoe, 100) + doy
    return era * 146097 + doe - 719468

civil_from_days(z: int) -> (y, m, d)
    z   = z + 719468
    era = floor_div(z, 146097)
    doe = z - era * 146097
    yoe = floor_div(doe - floor_div(doe, 1460) + floor_div(doe, 36524) - floor_div(doe, 146096), 365)
    y   = yoe + era * 400
    doy = doe - (365 * yoe + floor_div(yoe, 4) - floor_div(yoe, 100))
    mp  = floor_div(5 * doy + 2, 153)
    d   = doy - floor_div(153 * mp + 2, 5) + 1
    m   = mp + 3 if mp < 10 else mp - 9
    return (y + 1 if m <= 2 else y, m, d)

parse_date(s) -> (y, m, d)      # exactly "YYYY-MM-DD", ASCII digits; error "invalid_date" unless 1970 <= y <= 2199 and
                                # civil_from_days(days_from_civil(y, m, d)) == (y, m, d)
format_date(y, m, d) -> string  # zero-padded "YYYY-MM-DD"
day_of_week(date) -> int        = mod_floor(days_from_civil(parse_date(date)) + 3, 7)        # 0 = Monday
add_days(date, n: int) -> date  = format_date(civil_from_days(days_from_civil(parse_date(date)) + n))
```

| Date | days_from_civil | day_of_week | Name |
|---|---|---|---|
| 1970-01-01 | 0 | 3 | thu |
| 2000-02-29 | 11016 | 1 | tue |
| 2026-10-08 | 20734 | 3 | thu |
| 2028-02-29 | 21243 | 1 | tue |
| 2100-03-01 | 47541 | 0 | mon |
| 2199-12-31 | 84005 | 1 | tue |

`add_days("2026-12-30", 3)` = `"2027-01-02"`; `add_days("2028-03-01", -1)` = `"2028-02-29"`; `add_days("2026-03-01", -1)` = `"2026-02-28"`; `add_days("2026-10-08", 0)` = `"2026-10-08"`.

**Federal holidays.**

```
nth_weekday(year, month, dow, n) -> date
    first = days_from_civil(year, month, 1)
    return civil_from_days(first + mod_floor(dow - mod_floor(first + 3, 7), 7) + 7 * (n - 1))
last_weekday(year, month, dow) -> date
    next_first = days_from_civil(year + 1, 1, 1) if month == 12 else days_from_civil(year, month + 1, 1)
    last = next_first - 1
    return civil_from_days(last - mod_floor(mod_floor(last + 3, 7) - dow, 7))

federal_holidays(year: int, flags: { inauguration_day: bool }) -> [Holiday]
    for each rule in seeds.holidays.rules, in file order (position = rule order 1..12):
        skip if rule.from_year exists and year < rule.from_year
        skip if rule.region_flag exists and flags[rule.region_flag] is not true
        "fixed":        date = (year, month, day); dow = day_of_week(date)
                        observed = date - 1 day if dow == 5; date + 1 day if dow == 6; else date
        "nth_weekday":  date = nth_weekday(year, month, rule.dow, rule.n); observed = date
        "last_weekday": date = last_weekday(year, month, rule.dow);        observed = date
        "inauguration": skip unless year >= 1969 and mod_floor(year - 1965, 4) == 0
                        date = (year, 1, 20); dow = day_of_week(date)
                        observed = date + 1 day if dow == 6; null if dow == 5 (no day in lieu); else date
    return the list sorted by (date ascending, rule order ascending)

holiday_on(date, flags) -> Holiday?
    Y = year of date
    candidates = every h in federal_holidays(Y, flags) followed by federal_holidays(Y + 1, flags) with h.observed == date
    return the candidate with the smallest (0 if class == "major" else 1, rule order), or null
```

`federal_holidays(Y)` lists holidays whose actual date is in `Y`; the observed date of New Year's Day can fall on 31 December of `Y - 1`, which is why `holiday_on` looks at two years. Only the observed date changes behaviour; an actual date that differs from it is a Saturday or Sunday and already uses weekend curves. Every (actual, observed) pair that differs in 2026-2029, with the region flag on:

| Year | Holiday | Actual date | Observed |
|---|---|---|---|
| 2026 | `independence` | 2026-07-04 (sat) | 2026-07-03 |
| 2027 | `juneteenth` | 2027-06-19 (sat) | 2027-06-18 |
| 2027 | `independence` | 2027-07-04 (sun) | 2027-07-05 |
| 2027 | `christmas` | 2027-12-25 (sat) | 2027-12-24 |
| 2028 | `new_year` | 2028-01-01 (sat) | 2027-12-31 |
| 2028 | `veterans` | 2028-11-11 (sat) | 2028-11-10 |
| 2029 | `inauguration` | 2029-01-20 (sat) | null (no day in lieu) |
| 2029 | `veterans` | 2029-11-11 (sun) | 2029-11-12 |

`holiday_on("2025-01-20", {inauguration_day: true})` returns `mlk` (both `mlk` and `inauguration` are observed that day; both minor; `mlk` has the earlier rule order). `holiday_on("2026-07-04", ...)` is null (a Saturday; the holiday is observed on the 3rd).

**Day context.**

```
day_context(A, date, treat_as, forecast, fuel_price_per_gal, fuel_price_source) -> DayContext
                                                      # every argument is required; null is allowed for the last four
    dow = day_of_week(date);  hol = holiday_on(date, A.region.flags)
    eff_dow = dow;  cls = hol.class if hol else null
    if treat_as in ["mon".."sun"]: eff_dow = index of treat_as; cls = null
    else if treat_as == "holiday": cls = "major"
    else if treat_as == "normal":  cls = null
    return make_context(A, date, dow, eff_dow, cls, hol, treat_as, forecast, fuel_price_per_gal, fuel_price_source, typical = false)

typical_context(A, dow) -> DayContext                 # a typical week: no date, no holiday, no weather, no fuel price
    return make_context(A, null, dow, dow, null, null, null, null, null, null, typical = true)

make_context(A, date, dow, eff_dow, cls, hol, treat_as, forecast, fuel_price_per_gal, fuel_price_source, typical)
    base_type = "weekday" if eff_dow <= 4 else ("saturday" if eff_dow == 5 else "sunday")
    for s in 0..15:
        t = base_type
        if cls != null:
            t = seed(A, "segments.<s>.holiday_day_type.<cls>")
            if t == "weekday": t = base_type
        day_type[s]   = t
        dow_factor[s] = seed(A, "segments.<s>.dow_factor")[eff_dow] if t == "weekday" else 1.0
    traffic_dow = 6 if cls == "major" else eff_dow
    holiday = hol (kept even when treat_as suppresses its effect); holiday_class = cls
    forecast, fuel_price_per_gal and fuel_price_source are stored as given
```

`treat_as`: `null` = automatic; `"normal"` = ignore a holiday; `"holiday"` = treat as a major holiday; a day key = behave like that day of the week (no holiday). `forecast` and the fuel price fields are copied into the context unchanged (null in a typical context). `HourResult.how` is `ctx.dow * 24 + hour` (the real `dow`, not `eff_dow`) and `HourResult.date` is `ctx.date`.

| Call | dow | eff_dow | holiday | class | w_office type | factor | v_nightlife type | factor | v_shopping type | w_public type | traffic_dow |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `day_context(A_dc, "2026-10-08", null, null, null, null)` | 3 | 3 | null | null | weekday | 1.08 | weekday | 1.0 | weekday | weekday | 3 |
| `day_context(A_dc, "2026-10-12", null, null, null, null)` Columbus Day | 0 | 0 | columbus | minor | weekday | 0.9 | weekday | 0.5 | saturday | sunday | 0 |
| `day_context(A_dc, "2026-11-26", null, null, null, null)` Thanksgiving | 3 | 3 | thanksgiving | major | sunday | 1.0 | saturday | 1.0 | saturday | sunday | 6 |
| `day_context(A_dc, "2026-07-03", null, null, null, null)` Independence Day observed | 4 | 4 | independence | major | sunday | 1.0 | saturday | 1.0 | saturday | sunday | 6 |
| `day_context(A_dc, "2026-10-08", "sat", null, null, null)` | 3 | 5 | null | null | saturday | 1.0 | saturday | 1.0 | saturday | saturday | 5 |

| Edge case | Result |
|---|---|
| Date outside 1970..2199 or not a real date | error `invalid_date` |
| `treat_as = "holiday"` on a Saturday | `cls = "major"`; segments whose holiday type is `weekday` use `saturday` |
| Region `none` | flags all false; national holidays still apply |

### 4.2 Curves

```
hour_weights(A, ctx) -> { presence: [[number x24] x16], intent: [[number x24] x16] }
    for s in 0..15, h in 0..23:
        presence[s][h] = seed(A, "segments.<s>.presence.<ctx.day_type[s]>")[h] * ctx.dow_factor[s]
        intent[s][h]   = seed(A, "segments.<s>.intent.<ctx.day_type[s]>")[h]

expand_curves(A) -> { presence: [[number x168] x16], intent: [[number x168] x16] }        # a typical week
    for dow in 0..6: w = hour_weights(A, typical_context(A, dow))
        for s, h: presence[s][dow*24 + h] = w.presence[s][h];  intent[s][dow*24 + h] = w.intent[s][h]
```

The Monday-Friday factor multiplies presence only, never intent. Saturday and Sunday arrays are used as given. Below, `presence168` and `intent168` are the two arrays returned by `expand_curves`.

| Quantity | Value |
|---|---|
| `presence168[w_office][34]` (Tue 10:00) = 0.369 x 1.19 | 0.43911 |
| `presence168[w_office][108]` (Fri 12:00) = 0.363 x 0.67 | 0.24321 |
| `presence168[v_nightlife][114]` (Fri 18:00) = 0.62 x 1.5 | 0.9299999999999999 |
| `presence168[v_nightlife][136]` (Sat 16:00) | 1.0 |
| `intent168[w_office][132]` (Sat 12:00) | 0.153 |
| `presence168[v_leisure][17]` (Mon 17:00) = 1.0 x 1.15, the largest value of the week | 1.15 |

### 4.3 Geometry

```
haversine_m(lat1, lng1, lat2, lng2) -> number            # metres on a sphere of radius 6371008.8
    PI = 3.141592653589793;  R = 6371008.8
    p1 = lat1 * PI / 180.0;  p2 = lat2 * PI / 180.0
    dp = (lat2 - lat1) * PI / 180.0;  dl = (lng2 - lng1) * PI / 180.0
    sp = sin(dp / 2.0);  sl = sin(dl / 2.0)
    a  = sp * sp + cos(p1) * cos(p2) * sl * sl
    a  = clamp(a, 0.0, 1.0)
    return 2.0 * R * asin(sqrt(a))

walk_weight(A, d) -> number                               # f(d)
    if d < 0 or d > seed(A, "kernel.walk_cutoff_m"): return 0.0
    return exp(-d / seed(A, "kernel.walk_decay_m"))
```

This is the only distance function in the model (no `ST_Distance_Sphere`, no other radius). The cutoff is inclusive.

| Call | Result |
|---|---|
| `haversine_m(38.96, -77.36, 38.9696, -77.3861)` | 2496.298749 m |
| `haversine_m(38.96, -77.36, 38.9635972815, -77.36)` (400 m due north, latitude rounded to ten decimals) | 400.000005 m |
| `haversine_m(38.96, -77.36, 38.96, -77.36)` | 0.0 m |
| `walk_weight(0)` | 1.0 |
| `walk_weight(50)` | 0.8824969025845955 |
| `walk_weight(400)` | 0.36787944117144233 |
| `walk_weight(1200)` | 0.049787068367863944 |
| `walk_weight(1200.01)` | 0.0 |

The latitude in the second row is rounded to ten decimals. For an exact 400 m use `lat = 38.96 + 400 / 6371008.8 * 180.0 / 3.141592653589793` as in 4.4; the function then returns 400.000000000 m.

### 4.4 Capture: the three location vectors

**Inputs.** A *source point* is a census block (its `base` holds residents in `res` and jobs in the seven `w_*` segments) or a place with a visitor segment (its `base` holds `default_size` in that segment). An *outlet* is a place with a rival kind. One place can be both. Ids are strings (03_DATA.md: `b` + block GEOID for blocks, `p` + place key for places; outlets are ordered by place key); the model only requires that they are unique and compares them byte-wise.

```
rivals_at_origin(A, lat, lng, outlets: [Outlet]) -> { day: number, eve: number }
    out = { day: 0.0, eve: 0.0 }
    for o in outlets, ascending o.id:
        f = walk_weight(A, haversine_m(lat, lng, o.lat, o.lng))
        if f == 0.0: continue
        w = seed(A, "kernel.rival_weight.<o.kind>")
        out.day += w.day * f;  out.eve += w.eve * f
    return out
```

At build time the region loader stores `rivals_at_origin` for every source point (`SourcePoint.rivals`), passing the region's outlets (only those within the cutoff of the point contribute). An outlet at the origin itself counts with `f = 1` (a bar's patrons face the bar's own kitchen).

Origin `b1` is 75 m north of the truck; the outlets are 415 m and 435 m from it. `rivals_c[day]` = 1.0 x exp(-415/400) + 1.0 x exp(-435/400) = 0.354339 + 0.337058 = **0.691398**; `eve` is the same because `quick` weighs 1.0 in both regimes. At the truck's own point the outlets are 340 m and 360 m away: `rivals[day]` = 0.833985.

```
host_exclusion(A, host: Host?) -> Exclusion
    if host == null: return { point_ids: [], segment: null, amount: 0.0 }
    ids = [host.point_id] if host.point_id != null else []
    group = seeds.segments[host.segment].group
    if group == "workers" or group == "residents": return { point_ids: ids, segment: host.segment, amount: host.size }
    return { point_ids: ids, segment: null, amount: 0.0 }

host_link_point(A, lat, lng, host: Host, sources: [SourcePoint]) -> string?
    si = index of host.segment;  best = null
    for c in sources, ascending c.id:
        if c.base[si] <= 0: continue
        d = haversine_m(lat, lng, c.lat, c.lng);  if d > seed(A, "host.venue_link_radius_m"): continue
        key = floor(d * 1000.0 + 0.5);  if best == null or key < best.key: best = { id: c.id, key }
    return best.id if best != null else null

capture_at_point(A, lat, lng, visibility, sources: [SourcePoint], outlets: [Outlet], exclusion: Exclusion) -> LocationVectors
    V  = seed(A, "kernel.visibility.<visibility>");  A0 = seed(A, "kernel.outside_option_a0")
    rows = []
    for c in sources, ascending c.id:
        if c.id in exclusion.point_ids: continue
        d = haversine_m(lat, lng, c.lat, c.lng);  f = walk_weight(A, d)
        if f == 0.0: continue
        rows.append({ id: c.id, d: d, f: f, base: copy of c.base, rivals: c.rivals })
    taken = 0.0
    if exclusion.segment != null and exclusion.amount > 0:
        si = index of exclusion.segment;  remaining = exclusion.amount
        near = rows with d <= seed(A, "host.exclusion_radius_m"), sorted by (floor(d * 1000.0 + 0.5) ascending, id ascending)
        for r in near:
            if remaining <= 0: break
            take = min(r.base[si], remaining)
            r.base[si] -= take;  remaining -= take;  taken += take
    capture = { day: [0.0 x16], eve: [0.0 x16] };  nearby = [0.0 x16];  within = [0.0 x16]
    for r in rows (still ascending id):
        for regime in [day, eve]:
            share = r.f * V / (A0 + r.f * V + r.rivals[regime])
            for s in 0..15: capture[regime][s] += r.base[s] * share
        for s in 0..15: nearby[s] += r.base[s] * r.f;  within[s] += r.base[s]
    return { capture, nearby, within, rivals: rivals_at_origin(A, lat, lng, outlets), visibility, in_region: true, region_id: null,
             exclusion, excluded_amount: taken, points_used: length(rows), dataset_version: null, model_version: "tps-0.1.0" }
             # the backend sets in_region, region_id and dataset_version on what it returns
```

Meaning: `capture[regime][s]` = units of segment `s` base the truck would win per unit of presence and intent; `nearby[s]` = distance-weighted base within walking distance; `within[s]` = base within the cutoff, after exclusion and without distance weighting; `rivals[regime]` = pull of food outlets at the truck's own position (shown as "competition", and used by 4.5). `capture[regime][s] / within[s]` is the truck's average share of segment `s` (417.134520 / 2000 = 0.208567 in the example below). `capture / nearby` is not a share (0.374124 there, larger than the share at any single point) and must never be shown as one.

Example: `host_exclusion` of `{ segment: "w_office", size: 600, point_id: null }` is `{ point_ids: [], segment: "w_office", amount: 600 }`; of a taproom host linked to point `p77` it is `{ point_ids: ["p77"], segment: null, amount: 0 }`. The two exclusion rules exist so a declared host is not counted twice: (1) a host linked to a place removes that place's own source point; (2) a host that declares workers or residents removes up to that many units of the same segment from the nearest points within `host.exclusion_radius_m`, nearest first. The removed people come back through the host term (4.5). Exclusion applies to `capture`, `nearby` and `within` alike, never to `rivals`.

`host_link_point` covers a venue host that carries no link: without it the venue's own default source point would stay in the catchment while its people also enter through the host term. It returns the nearest source point that holds the host's segment within `host.venue_link_radius_m` (nearest by whole millimetres, ties to the smaller id). A `v_nightlife` source point `pw1` of 40 lies 10 m north of the truck and the owner declares a taproom host of 120 without a link: `host_link_point` returns `"pw1"`, the exclusion becomes `{ point_ids: ["pw1"], segment: null, amount: 0 }` and Thursday 17:00-20:00 gives 39.38 orders. Left unlinked, the point would stay in the catchment (`capture.eve[v_nightlife]` = 15.148622) and the same window would read 46.01. With the point 90 m away the function returns null.

**Which vectors are used where.**

| For | Computed with |
|---|---|
| Every map cell (the region pack, built by the loader) | cell centre, visibility `normal`, no exclusion. Feature row of 50 numbers: `capture.day[0..15]`, `capture.eve[0..15]`, `nearby[0..15]`, `rivals.day`, `rivals.eve`. |
| A place being scouted | precomputed by the region loader for every possible host and stored in `tp_places.host_vec` (03_DATA.md 9.1, 10): the place's coordinates, visibility `normal`, exclusion `{ point_ids: [the place's own point id, if it has one], segment: null, amount: 0 }`. Scouting decodes the 50 numbers and never calls `capture_at_point` per candidate; the decoded `LocationVectors` has visibility `normal`, `in_region: true`, that exclusion, `excluded_amount: 0.0`, `within: null` and `points_used: null`. |
| A clicked point | exact coordinates, visibility `normal`, no exclusion. |
| A saved spot | exact coordinates, the spot's visibility and the exclusion of its host. `Host.point_id` comes from the spot's place link (host link rule, 03_DATA.md 6.2); when the host's segment group is visitors and `host.point_id` is still null, the server first sets `point_id = host_link_point(A, lat, lng, host, sources)` (it stays null when nothing is found) and stores it with the spot; the exclusion is then `host_exclusion(A, host)`. Recompute whenever point, visibility, host segment, host size, host link, `dataset_version` or `seeds_revision` changes. Capture is not linear in `V`, so the API returns one vector set per visibility level it is asked for. |
| A point with no block within 2,400 m (03_DATA.md Q4) | all zeros (unless a venue stands alone within the cutoff: the vectors are computed as for any point), `in_region: false`. For every other point `in_region` is the flag of the nearest block (Q4): a point just outside a county line keeps its computed vectors with `in_region: false`. |

Worked example (this layout is anchor A1 of section 8). Truck at (38.96, -77.36). Eight source points `b1..b8`, each with 250 `w_office` jobs, due north at `d` = 75, 125, ..., 425 m: `lat = 38.96 + d / 6371008.8 * 180.0 / 3.141592653589793` (evaluated left to right), `lng = -77.36`. Two `quick` outlets `o1`, `o2` due south at 340 m and 360 m (the same formula with `d` = -340 and -360). Each point's `rivals` comes from `rivals_at_origin` with these two outlets. `A0 = 1.6`, `V = 1.0`:

| Point | d (m) | base[w_office] | f(d) | rivals_c[day] | share | base x share |
|---|---|---|---|---|---|---|
| b1 | 75 | 250 | 0.829029 | 0.691398 | 0.265678 | 66.4195 |
| b2 | 125 | 250 | 0.731616 | 0.610156 | 0.248699 | 62.1747 |
| b3 | 175 | 250 | 0.645649 | 0.538461 | 0.231905 | 57.9762 |
| b4 | 225 | 250 | 0.569783 | 0.475190 | 0.215421 | 53.8553 |
| b5 | 275 | 250 | 0.502832 | 0.419354 | 0.199363 | 49.8409 |
| b6 | 325 | 250 | 0.443747 | 0.370078 | 0.183836 | 45.9589 |
| b7 | 375 | 250 | 0.391606 | 0.326593 | 0.168927 | 42.2317 |
| b8 | 425 | 250 | 0.345591 | 0.288217 | 0.154709 | 38.6773 |

Sum of the last column = `capture.day[w_office]`. Results for several calls (average share at `normal`: 417.134520 / 2000 = 0.208567):

| Call | capture.day[w_office] | capture.eve[w_office] | nearby[w_office] | within[w_office] | rivals.day |
|---|---|---|---|---|---|
| visibility `normal`, no exclusion | 417.134520 | 417.134520 | 1114.962841 | 2000.0 | 0.833985 |
| visibility `hidden` | 273.891217 | 273.891217 | 1114.962841 | 2000.0 | 0.833985 |
| visibility `prominent` | 509.479077 | 509.479077 | 1114.962841 | 2000.0 | 0.833985 |
| `normal`, exclusion `{point_ids: ["b1"]}` | 350.714987 | 350.714987 | 907.705562 | 1750.0 | 0.833985 |
| `prominent`, exclusion `{segment: "w_office", amount: 600}` (takes 250 from b1, 250 from b2, 100 from b3) | 326.105662 | 326.105662 | 660.236802 | 1400.0 | 0.833985 |

| Edge case | Result |
|---|---|
| No source within the cutoff | all zeros, `points_used: 0` |
| Source exactly at the truck | `d = 0`, `f = 1` |
| `exclusion.amount` larger than what is within the radius | everything within the radius is removed; `excluded_amount` reports what was actually taken |
| Exclusion of a segment the points do not hold | nothing removed |

### 4.5 Host term

A host adds its own people. How the truck's share of them is set depends on `seeds.segments[host.segment].host_mode`:

```
host_capture(A, host: Host?, visibility, rivals_here: { day, eve }) -> { day: number, eve: number, share: { day, eve }, mode }
    if host == null or host.size <= 0: return zeros, mode null
    mode = seeds.segments[host.segment].host_mode
    if mode == "captive":                                   # v_nightlife, v_events: people inside a venue
        sh = seed(A, "host.captive_share") if host.only_food else seed(A, "host.shared_kitchen_share")
        share = { day: sh, eve: sh }
    else:                                                   # "open": workers, residents, shoppers, students, ...
        V = seed(A, "kernel.visibility.<visibility>");  A0 = seed(A, "kernel.outside_option_a0")
        K = 0.0 if host.only_food else seed(A, "host.onsite_kitchen_weight")
        share[regime] = V / (A0 + V + rivals_here[regime] + K)      for each regime
    return { day: host.size * share.day, eve: host.size * share.eve, share, mode }
```

`rivals_here` is `LocationVectors.rivals`. `host_capture[regime]` plays the role of `capture[regime][host.segment]` for the host's people: it is multiplied by presence, intent and menu fit in 4.7. In `captive` mode nearby rivals and visibility do not matter and the weather table for `captive` settings applies; in `open` mode the host's people are treated as a source at distance zero with the on-site kitchen (if any) as an extra rival of weight `K`, and the `open` weather table applies. `only_food = false` means the host sells its own food.

| Host | Mode | share day | share eve | host_capture.day | host_capture.eve |
|---|---|---|---|---|---|
| Taproom, size 120, only food | captive | 0.750000 | 0.750000 | 90.0000 | 90.0000 |
| Taproom, size 120, own kitchen | captive | 0.300000 | 0.300000 | 36.0000 | 36.0000 |
| Office building, 600 workers, no cafeteria, `prominent`, rivals 0.833985 | open | 0.348154 | 0.348154 | 208.8921 | 208.8921 |
| Same with a cafeteria | open | 0.226718 | 0.226718 | 136.0311 | 136.0311 |
| Apartments, 500 residents, `normal`, no rivals | open | 0.384615 | 0.384615 | 192.3077 | 192.3077 |
| Apartments, 500 residents, `prominent`, no rivals | open | 0.448276 | 0.448276 | 224.1379 | 224.1379 |

### 4.6 Weather

```
weather_multiplier(A, fc: HourForecast?, setting: "open"|"captive") -> WeatherDetail
    if fc == null, or temp_f, precip_prob, short_forecast and wind_mph are all null:
        return { multiplier: 1.0, missing: true, temp: 1.0, precip: 1.0, wind: 1.0, bands null }
    temp = 1.0;  if fc.temp_f != null:   (temp_band, temp) = band("temperature_bands", "upper_f", fc.temp_f)
    wind = 1.0;  if fc.wind_mph != null: (wind_band, wind) = band("wind_bands", "upper_mph", fc.wind_mph)
    cls = "dry"
    if fc.short_forecast != null:
        text = ascii_lower(fc.short_forecast)                 # only A-Z become a-z; every other character unchanged
        for id in seed(A, "weather.precip_classes.order"):
            if any substring of seed(A, "weather.precip_classes.rows.<id>.match") occurs in text: cls = id; stop
    m = seed(A, "weather.precip_classes.rows.<cls>.<setting>")
    p = 0.0 if cls == "dry"
        else seed(A, "weather.pop_when_missing") if fc.precip_prob == null
        else clamp(fc.precip_prob / 100.0, 0.0, 1.0)
    precip = 1.0 - p * (1.0 - m)
    multiplier = max(seed(A, "weather.floor"), temp * precip * wind)
    return { multiplier, missing: false, temp, precip, wind, temp_band, precip_class: cls, precip_p: p, wind_band }

band(table, key, x): for id in seed(A, "weather.<table>.order"):
                         upper = seed(A, "weather.<table>.rows.<id>.<key>")
                         if upper == null or x < upper: return (id, seed(A, "weather.<table>.rows.<id>.<setting>"))
```

The class tables are tested in the listed order and the first hit wins, so "Rain And Snow" is `snow` and "Showers And Thunderstorms" is `storm`. The class sets how bad it is if it does precipitate; the forecast probability says how likely that is. A dry class ignores the probability. Bands are step functions.

| temp_f | precip_prob | short_forecast | wind_mph | temp band | precip class | p | wind band | open parts (temp x precip x wind) | open | captive |
|---|---|---|---|---|---|---|---|---|---|---|
| 62 | 0 | "Cloudy" | 2 | 60_79 | dry | 0.0 | calm | 1.0 x 1.0000 x 1.0 | 1.000000 | 1.000000 |
| 55 | 40 | "Chance Rain Showers" | 8 | 50_59 | rain | 0.4 | calm | 0.9 x 0.8200 x 1.0 | 0.738000 | 0.892400 |
| 45 | 80 | "Rain" | 22 | 40_49 | rain | 0.8 | windy | 0.78 x 0.6400 x 0.85 | 0.424320 | 0.742140 |
| 88 | 60 | "Showers And Thunderstorms Likely" | 12 | 80_89 | storm | 0.6 | calm | 0.95 x 0.5800 x 1.0 | 0.551000 | 0.760000 |
| 28 | 90 | "Heavy Snow" | 31 | 20_31 | heavy_snow | 0.9 | very_windy | 0.55 x 0.3250 x 0.6 | 0.150000 | 0.374000 |
| 97 | null | "Sunny" | 5 | 95_up | dry | 0.0 | calm | 0.7 x 1.0000 x 1.0 | 0.700000 | 0.900000 |
| 50 | null | "Light Rain Likely" | null | 50_59 | light_rain | 0.5 | null | 0.9 x 0.9000 x 1.0 | 0.810000 | 0.931200 |
| 34 | 70 | "Rain And Snow" | 10 | 32_39 | snow | 0.7 | calm | 0.66 x 0.6500 x 1.0 | 0.429000 | 0.726000 |
| 60 | 30 | "Patchy Fog" | 20 | 60_79 | dry | 0.0 | windy | 1.0 x 1.0000 x 0.85 | 0.850000 | 0.950000 |
| (record is null) |  |  |  |  |  |  |  |  | 1.000000 (missing = true) | 1.000000 (missing = true) |

### 4.7 Demand and orders

```
calibration_factor(cal: CalibrationState?, spot_id: string?) -> (truck_factor, spot_factor)
    if cal == null: return (1.0, 1.0)
    return (cal.truck_factor, cal.spots[spot_id].factor if spot_id != null and spot_id in cal.spots else 1.0)

hourly_orders(A, profile, terms: SpotTerms, vectors: LocationVectors, cal, ctx: DayContext, hour: int 0..23) -> HourResult
    w       = hour_weights(A, ctx)                                    # may be computed once per context
    regime  = regime_of_hour[hour];  daypart = daypart_of_hour[hour];  fit = profile.daypart_fit[daypart]
    if ctx.typical: wx_open = wx_cap = 1.0; weather_state = "typical"
    else: fc = ctx.forecast[hour] if ctx.forecast != null else null
          wx_open = weather_multiplier(A, fc, "open").multiplier;  wx_cap = weather_multiplier(A, fc, "captive").multiplier
          weather_state = "missing" if the open result has missing == true else "forecast"
    (tf, sf) = calibration_factor(cal, terms.spot_id);  calib = tf * sf
    demand_raw = 0.0;  demand_adj = 0.0;  weak = 0.0;  default_part = 0.0
    for s in 0..15:
        raw_s = vectors.capture[regime][s] * w.presence[s][hour] * w.intent[s][hour] * fit
        adj_s = raw_s * wx_open * calib
        demand_raw += raw_s;  demand_adj += adj_s
        if seeds.segments[s].weak: weak += adj_s
        nearby_present_s = vectors.nearby[s] * w.presence[s][hour]
        within_present_s = vectors.within[s] * w.presence[s][hour] if vectors.within != null else null
    if terms.host != null and terms.host.size > 0:
        hc   = host_capture(A, terms.host, terms.visibility, vectors.rivals);  hs = index of terms.host.segment
        raw_h = hc[regime] * w.presence[hs][hour] * w.intent[hs][hour] * fit
        wx_h  = wx_cap if hc.mode == "captive" else wx_open
        adj_h = raw_h * wx_h * calib
        demand_raw += raw_h;  demand_adj += adj_h                     # the host is added after the 16 segments
        if seeds.segments[hs].weak: weak += adj_h
        if terms.host.size_source == "default": default_part = adj_h
        people_present = terms.host.size * w.presence[hs][hour]
    capacity = profile.capacity_orders_per_hour
    orders   = min(demand_adj, capacity);  capped = demand_adj > capacity
    scale    = orders / demand_adj if demand_adj > 0 else 0.0
    segments[s] = { segment, nearby_present: nearby_present_s, within_present: within_present_s, capture: vectors.capture[regime][s],
                    presence: w.presence[s][hour], intent: w.intent[s][hour], demand_raw: raw_s, before_cap: adj_s, orders: adj_s * scale }
    host        = { segment, mode: hc.mode, size, share: hc.share[regime], people_present, presence, intent,
                    demand_raw: raw_h, weather: wx_h, before_cap: adj_h, orders: adj_h * scale }      (null without a host of size > 0)
    factors     = { menu_fit: fit, weather_open: wx_open, weather_captive: wx_cap, weather_state,
                    weather_detail: the open-setting WeatherDetail (null when typical),
                    weather_detail_captive: the captive-setting WeatherDetail (null when typical), truck_factor: tf, spot_factor: sf }
    weak_part = weak;  default_size_part = default_part;  how = ctx.dow * 24 + hour;  date = ctx.date
```

The cap is applied once per hour to the total; demand above capacity is lost, not carried to the next hour. `demand_raw` is before weather and calibration, `demand_adj` after them and before the cap, `orders` after the cap. Segment rows after the cap ("who the customers would be") are the before-cap rows scaled by the same factor.

Office-park layout of 4.4, Thursday 2026-10-08, hour 12, default profile, no calibration, typical weather:

| Step | Value |
|---|---|
| regime, daypart, menu_fit | day, lunch, 1.0 |
| `capture.day[w_office]` | 417.134520 |
| presence = 0.363 x 1.08 (Thursday factor) | 0.39204 |
| intent | 0.18 |
| `demand_raw` = capture x presence x intent x menu_fit | 29.436015 |
| weather (typical context), truck factor, spot factor | 1.0, 1.0, 1.0 |
| `demand_adj` | 29.436015 |
| capacity 45, so `orders` | 29.436015 |
| `nearby_present` = nearby x presence (people on site within reach, distance-weighted) | 437.1100 |
| `within_present` = within x presence (people on site within 1,200 m) | 784.0800 |
| average share = capture / within | 0.208567 |

A taproom host of size 120 with no kitchen (`segment: "v_nightlife"`, `only_food: true`) on zero vectors, same Thursday, hour 18:

| Step | Value |
|---|---|
| regime, daypart, menu_fit | eve, dinner, 1.0 |
| host mode, share | captive, 0.75 |
| `host_capture.eve` = 120 x 0.75 | 90.0 |
| presence (Thursday factor 1.0), intent | 0.62, 0.28 |
| people in the venue = 120 x 0.62 | 74.4 |
| `demand_raw` = 90 x 0.62 x 0.28 x 1.0 | 15.624000 |
| `orders` (no weather, no calibration, under capacity) | 15.624000 |

**Matching terms and vectors.** `terms` and `vectors` travel separately: the browser re-estimates at once from the terms the owner is editing, while the vectors were built by the server for one visibility and one host exclusion.

```
vectors_match(A, terms: SpotTerms, vectors: LocationVectors) -> bool
    e = host_exclusion(A, terms.host);  x = vectors.exclusion
    return vectors.visibility == terms.visibility
           and x.point_ids == e.point_ids                 # the same ids in the same order
           and x.segment == e.segment and x.amount == e.amount
```

A venue host compares after `host_link_point`: the comparison uses the `point_id` the server stored for it (4.4), so a venue host the server has not linked yet (just added, or moved) counts as not matching whatever the function returns. The two amounts are compared as doubles for exact equality. `hourly_orders` does not check; `day_plan` emits warning `stale_vectors` for every spot stop where the function returns false (4.12), and the UI shows the last matching estimate greyed until new vectors arrive. With the vectors of 4.4 (`pw1` is the venue point of the example there):

| `terms` | `vectors` computed with | `vectors_match` |
|---|---|---|
| visibility `normal`, no host | `normal`, no exclusion | true |
| visibility `prominent`, no host | `normal`, no exclusion | false |
| `prominent`, host of 600 office workers | `prominent`, exclusion `{segment: "w_office", amount: 600}` | true |
| `prominent`, host of 1,000 office workers | the same vectors | false |
| `normal`, taproom host linked to `pw1` | `normal`, exclusion `{point_ids: ["pw1"]}` | true |
| `normal`, taproom host linked to `pw1` | `normal`, no exclusion | false |

```
window_orders(A, profile, terms, vectors, cal, ctx, ctx_next: DayContext?, open: int, close: int) -> WindowResult
    require 0 <= open <= close <= 2880, else error "invalid_window"
    adj_total = weak = dflt = cap_total = host_orders = 0.0;  by_segment = [0.0 x16];  capped_hours = 0;  hours = [];  d = [];  c = []
    h_abs = floor_div(open, 60)
    while h_abs * 60 < close:
        start = max(h_abs * 60, open);  end = min((h_abs + 1) * 60, close)
        fraction  = (end - start) / 60.0
        day_index = floor_div(h_abs, 24);  cx = ctx if day_index == 0 else ctx_next         # error "missing_context" if cx is null
        r = hourly_orders(A, profile, terms, vectors, cal, cx, h_abs - 24 * day_index)
        d.append(r.demand_adj * fraction);  c.append(r.capacity * fraction)
        adj_total += r.demand_adj * fraction;  cap_total += r.capacity * fraction
        weak += r.weak_part * fraction;  dflt += r.default_size_part * fraction
        for s in 0..15: by_segment[s] += r.segments[s].orders * fraction
        if r.host != null: host_orders += r.host.orders * fraction
        if r.capped: capped_hours += 1
        hours.append({ day_index, hour: h_abs - 24 * day_index, fraction, result: r });  h_abs += 1
    evidence = evidence_from(cal, terms.spot_id)                      # 4.8
    evidence.weak_share         = weak / adj_total if adj_total > 0 else 0.0
    evidence.default_size_share = dflt / adj_total if adj_total > 0 else 0.0
    (orders, spread) = interval_capped(A, d, c, evidence)             # 4.8
```

A partial hour contributes its fraction of that hour's capped orders (equivalently, capacity is prorated): `orders.value` is the sum of `min(d[k], c[k])`, which equals the sum of `r.orders * fraction`. `minutes = close - open`, `date = ctx.date`, `demand_adj = adj_total`, `capacity_total = cap_total`, and `spread` is the record returned by `interval_capped`. `ctx_next` is the context of `add_days(date, 1)`; it may be null only when `close <= 1440`. The owner's "treat this day as" override belongs to one civil date; hours after midnight use the next date's own context.

Anchor A1 (4.4 layout, default profile, no calibration, weather factor 1):

| Case | Orders by hour | value | low | high | confidence |
|---|---|---|---|---|---|
| Office park, 2026-10-06 (tue) 11:00-14:00 | 14.455, 32.434, 19.766 | 66.6553 | 36.60 | 97.93 | rough |
| Office park, 2026-10-08 (thu) 11:00-14:00 | 13.119, 29.436, 17.939 | 60.4938 | 33.01 | 93.19 | rough |
| Office park, 2026-10-09 (fri) 11:00-14:00 | 8.138, 18.261, 11.129 | 37.5286 | 19.69 | 59.41 | rough |
| Office park, 2026-10-10 (sat) 11:00-14:00 | 0.766, 1.723, 1.053 | 3.5421 | 1.00 | 7.04 | rough |

On Tuesday and Thursday `high` is held down by capacity: at the strong-day factor `k_high` the 12:00 hour would exceed 45 orders (4.8).

| Variation (Thursday 11:00-14:00) | Orders |
|---|---|
| No rival outlets | 73.81 |
| Base case (two `quick` outlets) | 60.49 |
| Visibility `hidden` | 39.72 |
| Visibility `prominent` | 73.89 |
| `prominent` + host of 600 office workers, no cafeteria (host part 30.29) | 77.59 |
| `prominent` + host of 600 office workers with a cafeteria (host part 19.73) | 67.02 |

With an hourly forecast of 55 F / 40 % / "Chance Rain Showers", 56 F / 70 % / "Rain Showers Likely", 57 F / 30 % / "Chance Rain Showers" for hours 11, 12, 13, the open-setting multipliers are 0.7380, 0.6165, 0.7785 and the office-park Thursday becomes 9.682 + 18.147 + 13.966 = **41.79** orders (22.16 to 65.82).

Anchor A2 (zero vectors, host `v_nightlife` 120, `only_food`, default profile) and variations:

| Case | Orders by hour | value | low | high | confidence |
|---|---|---|---|---|---|
| Taproom 120, 2026-10-05 (mon) 17:00-20:00 | 5.400, 7.812, 6.480 | 19.6920 | 9.49 | 32.47 | rough |
| Taproom 120, 2026-10-08 (thu) 17:00-20:00 | 10.800, 15.624, 12.960 | 39.3840 | 20.76 | 62.20 | rough |
| Taproom 120, 2026-10-09 (fri) 17:00-20:00 | 16.200, 23.436, 19.440 | 59.0760 | 32.19 | 91.75 | rough |
| Taproom 120, 2026-10-10 (sat) 17:00-20:00 | 21.600, 23.940, 19.008 | 64.5480 | 35.37 | 99.95 | rough |
| Same Thursday, taproom has its own kitchen (share 0.30) | 4.320, 6.250, 5.184 | 15.7536 | 7.28 | 26.45 | rough |
| Same Thursday, size 40 from the place-type default | 3.600, 5.208, 4.320 | 13.1280 | 3.44 | 26.54 | very_rough |
| Friday 21:30-01:00 (close = 1500; hours 22, 23 and 00 are `late`, menu fit 0.8; hour 00 uses Saturday's context) | 4.455 x 0.5, 1.555, 0.691, 0.691 | 5.1651 | 1.72 | 9.79 | rough |
| Saturday 17:00-20:00, size 400 (demand 72.00, 79.80, 63.36: capped every hour) | 45.000, 45.000, 45.000 | 135.0000 | 122.63 | 135.00 | rough |
| Zero-length window 17:00-17:00 | (none) | 0.0000 | 0.00 | 0.00 | rough |

```
week_strip(A, profile, terms, vectors, cal) -> [number x168]
    for dow in 0..6, hour in 0..23: out[dow*24 + hour] = hourly_orders(A, profile, terms, vectors, cal, typical_context(A, dow), hour).orders

best_windows(values: [number], length: int >= 1, top_n: int, circular: bool, allowed: [bool]? = null) -> [ { start: int, length: int, total: number } ]
    n = length(values);  if length > n: return []
    candidates = []
    for start in 0 .. (n - 1 if circular else n - length):
        total = 0.0;  ok = true
        for k in 0 .. length-1:
            i = start + k;  if i >= n: i -= n
            if allowed != null and not allowed[i]: ok = false; break
            total += values[i]
        if ok and qkey(total) > 0: candidates.append({ start, total })
    sort candidates by (qkey(total) descending, start ascending)
    picked = [];  used = [false x n]
    for c in candidates:
        if length(picked) == top_n: break
        if any index of c's window is used: continue
        mark c's indexes used;  picked.append({ start: c.start, length, total: c.total })
    return picked
```

`week_strip` for anchor A2 has 98 non-zero hours summing to 441.4130 orders; indexes 137-139 (Saturday 17:00-19:59) are 21.600, 23.940, 19.008.

`best_windows` is greedy: best window first, then the best one that does not overlap it, and so on. With the week strip use `circular = true` (a window may run from Sunday into Monday); with one day's hours use `circular = false`. Windows whose total rounds to zero millionths are never returned.

| Input | Result (start index, total) |
|---|---|
| Taproom-120 week strip, length 3, top 3, circular | 137 (sat 17:00) 64.5480; 113 (fri 17:00) 59.0760; 89 (thu 17:00) 39.3840 |
| Office-park week strip, length 3, top 3, circular | 35 (tue 11:00) 66.6553; 59 (wed 11:00) 64.9749; 83 (thu 11:00) 60.4938 |
| `values = [1,5,5,1,5,5,1]`, length 2, top 2, not circular (tie on 10.0: earlier start first) | 1 10.0; 4 10.0 |
| `values = [0,0,0]`, length 2, top 2 | (empty list) |

| Edge case | Result |
|---|---|
| No people (all vectors zero, no host) | every hour 0; `orders = { 0, 0, 0, label }` |
| `capacity_orders_per_hour = 0` | orders 0 in every hour, `capped` true wherever demand > 0 |
| `open == close` | `hours: []`, orders `{ 0, 0, 0 }`, `capacity_total: 0` |
| Daypart fit 0 | demand 0 in that daypart |
| `vectors.in_region == false` | no function reads the flag: demand follows the vectors as given (4.4); a host term still counts; callers add warning `outside_region` |

### 4.8 Ranges and confidence

Demand has a log-normal predictive distribution whose mean is the expected demand; `low` and `high` are its 10th and 90th percentiles (an 80 % interval). Where the truck's capacity binds, the two percentiles are carried through the hourly cap (`interval_capped`) and `value` is the orders at expected demand, not the mean of the capped distribution (section 6 item 10).

```
evidence_from(cal: CalibrationState?, spot_id: string?) -> Evidence
    base = { truck_weight: 0.0, spot_weight: 0.0, resid_sd: null, resid_weight: 0.0,
             weak_share: 0.0, default_size_share: 0.0, event: false, fixed: false }
    if cal == null: return base
    base.truck_weight = cal.truck_weight;  base.resid_sd = cal.resid_sd;  base.resid_weight = cal.resid_weight
    if spot_id != null and spot_id is a key of cal.spots: base.spot_weight = cal.spots[spot_id].weight
    return base

interval(A, mean: number, ev: Evidence) -> (Estimate, spread)       # spread = the record of WindowResult.spread
    if ev.fixed: return { value: mean, low: mean, high: mean, confidence: "fixed" }               # spread: all zeros
    kt = seed("calibration.k_truck");  ks = seed("calibration.k_spot")
    shrink  = ks / (ks + ev.spot_weight)
    v_truck = sd_truck^2 * kt / (kt + ev.truck_weight)
    v_spot  = sd_spot^2 * shrink
    v_day   = sd_day^2                                                  if ev.resid_sd == null
              (n0 * sd_day^2 + ev.resid_weight * ev.resid_sd^2) / (n0 + ev.resid_weight)   otherwise      # n0 = uncertainty.resid_prior_weight
    v_weak  = (ev.weak_share * sd_weak)^2 * shrink
    v_size  = (ev.default_size_share * sd_default_size)^2 * shrink
    v_event = sd_event^2 if ev.event else 0.0
    sigma_model = sqrt(v_truck + v_spot + v_day + v_weak + v_size + v_event)          # added in this order
    confidence  = "good" if sigma_model < label_good_below
                  "fair" if sigma_model < label_fair_below
                  "rough" if sigma_model < label_rough_below
                  "very_rough" otherwise
    if mean <= 0: return { value: 0.0, low: 0.0, high: 0.0, confidence }      # spread: v_count = 0.0, sigma = sigma_model
    v_count = ln(1.0 + count_dispersion / mean)
    sigma   = sqrt(sigma_model^2 + v_count)          # computed as sqrt((v_truck + ... + v_event) + v_count)
    low     = mean * exp(-0.5 * sigma * sigma - z80 * sigma)
    high    = mean * exp(-0.5 * sigma * sigma + z80 * sigma)
    if high < mean: high = mean
    return { value: mean, low, high, confidence }      # spread: { sigma_model, sigma, v_truck, v_spot, v_day, v_weak, v_size, v_event, v_count }

interval_capped(A, d: [number], c: [number], ev: Evidence) -> (Estimate, spread)
    # d[k], c[k]: demand and capacity of loop hour k, each already multiplied by that hour's fraction
    D = sum of d;  value = sum over k of min(d[k], c[k])              # list order
    (e, spread) = interval(A, D, ev)                                  # log-normal on demand, before the cap
    if D <= 0: return ({ value: 0.0, low: 0.0, high: 0.0, confidence: e.confidence }, spread)
    k_low = e.low / D;  k_high = e.high / D
    low  = sum over k of min(k_low * d[k], c[k])
    high = sum over k of min(k_high * d[k], c[k])
    return ({ value, low: min(low, value), high: max(high, value), confidence: e.confidence }, spread)
```

`x^2` means `x * x`. All `sd_*`, `label_*`, `count_dispersion`, `z80` come from `uncertainty.*` and `constants.z80`. `interval_capped` moves the demand of every hour by one factor (`k_low` on a weak day, `k_high` on a strong one) and applies each hour's cap again. A window with no hour near capacity therefore gets `e.low` and `e.high` (up to rounding in the last place); an hour that reaches its cap only on a strong day limits `high`; a window that is over capacity in every hour even at `k_low` gets `low = value = high`. The counting noise `v_count` is computed from the demand `D`, not from the capped orders.

| Part | What it stands for | How it shrinks |
|---|---|---|
| `v_truck` | Is the model's level right for this truck at all (0.24 before logs) | with `truck_weight`, the recency-weighted number of logged services |
| `v_spot` | Is this spot different from the truck's average (0.19) | with `spot_weight` at this spot |
| `v_day` | Day-to-day scatter (0.20) | blended with the logged residual spread: weight `resid_weight` for the logs against `resid_prior_weight` (6) for the prior |
| `v_weak` | Weak-seed segments (`v_campus`, `v_hospital`, `v_transit`) supply `weak_share` of the demand | with `spot_weight` |
| `v_size` | A host size taken from a place-type default supplies `default_size_share` of the demand | with `spot_weight` (in practice: until the owner types the size) |
| `v_event` | Attendance-based estimate | never |
| `v_count` | Counting noise of a finite number of orders | larger counts |

Labels depend only on `sigma_model` (not on the size of the count): before any log `sigma_model = sqrt(0.0576 + 0.0361 + 0.04) = 0.3657`, label `rough` (0.31 <= 0.3657 < 0.41). Still without a log, the label is `very_rough` when weak-seed segments supply at least 0.464 of the demand or a default host size at least 0.310 of it, and always for an event. `fair` needs logged services: `truck_weight` above 7.52 while the day spread is still the prior, so ten fresh services at other spots give `fair`. `good` needs many logs at the spot itself (for example `truck_weight` 24, `spot_weight` 12 and a residual spread of 0.18); with no log at the spot it is reached only by a very long log elsewhere whose residual spread is under 0.13. `fixed` is used only for contracted amounts and for costs that follow arithmetically from the plan.

Office-park Thursday, no logs, hourly demand 13.119, 29.436, 17.939 against a capacity of 45 each, so `D` = 60.493849: `v_truck` = 0.24^2 x 4/(4+0) = 0.0576; `v_spot` = 0.19^2 x 3/(3+0) = 0.0361; `v_day` = 0.20^2 = 0.0400; `sigma_model` = sqrt(0.1337) = 0.365650 (label `rough`: 0.31 <= 0.3657 < 0.41); `v_count` = ln(1 + 2/60.4938) = 0.032526; `sigma` = sqrt(0.166226) = 0.407709. `interval(A, D, ev)` gives `low` = D x exp(-sigma^2/2 - 1.2815515655446004 x sigma) = 33.0139 and `high` = D x exp(-sigma^2/2 + 1.2815515655446004 x sigma) = 93.8716, so `k_low` = 0.545740 and `k_high` = 1.551755. No hour reaches 45 at `k_low`: `orders.low` = 33.0139. At `k_high` the 12:00 hour would need 45.677 and is held at 45: `orders.high` = 20.3570 + 45 + 27.8371 = 93.1942.

| Evidence | mean | sigma_model | sigma | low | high | confidence |
|---|---|---|---|---|---|---|
| No logs | 60.0 | 0.36565 | 0.40803 | 32.7265 | 93.1316 | rough |
| No logs, small count | 5.0 | 0.36565 | 0.68569 | 1.6415 | 9.5172 | rough |
| Zero | 0.0 | 0.36565 | 0.36565 | 0.0000 | 0.0000 | rough |
| truck_weight 10 | 60.0 | 0.30423 | 0.35404 | 35.7999 | 88.7121 | fair |
| truck_weight 10, spot_weight 5, resid_sd 0.20 (weight 9) | 60.0 | 0.26457 | 0.32060 | 37.7916 | 85.9545 | fair |
| truck_weight 10, spot_weight 5, resid_sd 0.30 (weight 9) | 60.0 | 0.31622 | 0.36440 | 35.1970 | 89.5632 | rough |
| truck_weight 24, spot_weight 12, resid_sd 0.18 (weight 22) | 60.0 | 0.22243 | 0.28682 | 39.8704 | 83.1618 | good |
| No logs, weak_share 1.0 | 60.0 | 0.54194 | 0.57139 | 24.5038 | 105.9926 | very_rough |
| No logs, default_size_share 1.0 | 13.128 | 0.70264 | 0.79718 | 3.4396 | 26.5393 | very_rough |
| Event | 100.0 | 0.61944 | 0.63522 | 36.2107 | 184.4695 | very_rough |
| Fixed (catering) | 500.0 | 0.00000 | 0.00000 | 500.0000 | 500.0000 | fixed |

The fifth and sixth rows differ only in the logged scatter: a residual spread of 0.30 is wider than the prior 0.20, so the label falls back to `rough` although the truck and the spot have logs.

`interval_capped` with no logs (`sigma_model` 0.3657, label `rough` in every row):

| Case (no logs) | `d` | `c` | `D` | `interval(A, D, ev)` low to high | value | low | high |
|---|---|---|---|---|---|---|---|
| No hour near capacity | [10.00, 20.00, 10.00] | [45.0, 45.0, 45.0] | 40.00 | 21.1189 to 63.1239 | 40.0000 | 21.1189 | 63.1239 |
| One hour at capacity on a strong day only | [20.00, 40.00, 20.00] | [45.0, 45.0, 45.0] | 80.00 | 44.3801 to 123.0843 | 80.0000 | 44.3801 | 106.5422 |
| One hour over capacity | [30.00, 60.00, 30.00] | [45.0, 45.0, 45.0] | 120.00 | 67.7385 to 182.9292 | 105.0000 | 67.7385 | 135.0000 |
| Demand equal to capacity in every hour | [45.00, 45.00, 45.00] | [45.0, 45.0, 45.0] | 135.00 | 76.5063 to 205.3612 | 135.0000 | 76.5063 | 135.0000 |
| Size-400 taproom, Saturday 17:00-20:00 (4.7) | [72.00, 79.80, 63.36] | [45.0, 45.0, 45.0] | 215.16 | 123.3916 to 325.2026 | 135.0000 | 122.6273 | 135.0000 |
| Half hour, then a full hour | [10.00, 50.00] | [22.5, 45.0] | 60.00 | 32.7265 to 93.1316 | 55.0000 | 32.7265 | 60.5219 |
| No demand | [0.00, 0.00] | [45.0, 45.0] | 0.00 | 0 to 0 | 0.0000 | 0.0000 | 0.0000 |

### 4.9 Money

```
stop_money_at(profile, terms, orders: number) -> lines
    sales     = orders * profile.avg_ticket
    food_cost = sales * profile.food_cost_pct
    packaging = orders * profile.packaging_per_order
    card_fees = sales * profile.card_share * profile.card_fee_pct + orders * profile.card_share * profile.card_fee_fixed
    spot_fee  = max(terms.fee_min, terms.fee_flat + terms.fee_pct * sales)
    tips      = sales * profile.card_share * profile.tips_pct_of_card_sales if profile.tips_include else 0.0
    contribution = sales - food_cost - packaging - card_fees - spot_fee + tips

stop_money(profile, terms, orders: Estimate) -> StopMoney
    v = stop_money_at(profile, terms, orders.value);  l = ...(orders.low);  h = ...(orders.high)
    for each line (orders, sales, food_cost, packaging, card_fees, spot_fee, tips, contribution):
        line = est_levels(v.line, l.line, h.line, orders.confidence)
    unit_margin = unit_margins(profile, terms)

unit_margins(profile, terms) -> { at_minimum, at_percentage }
    tip  = profile.card_share * profile.tips_pct_of_card_sales if profile.tips_include else 0.0
    base = profile.avg_ticket * (1.0 - profile.food_cost_pct - profile.card_share * profile.card_fee_pct + tip)
           - profile.packaging_per_order - profile.card_share * profile.card_fee_fixed
    return { at_minimum: base, at_percentage: base - profile.avg_ticket * terms.fee_pct }

break_even_orders(profile, terms, fixed_costs: number) -> number?
    m = unit_margins(profile, terms)
    if m.at_minimum <= 0: return null
    x = (fixed_costs + terms.fee_min) / m.at_minimum                     # the minimum fee is what is paid
    if terms.fee_flat + terms.fee_pct * profile.avg_ticket * x <= terms.fee_min: return max(x, 0.0)
    if m.at_percentage <= 0: return null
    return max((fixed_costs + terms.fee_flat) / m.at_percentage, 0.0)      # flat + percentage is what is paid
```

The spot fee is `fee_flat + fee_pct * sales`, with `fee_min` as a floor on the total. `contribution` is what a stop leaves before the day's own costs. `break_even_orders` is the number of orders at which contribution equals `fixed_costs` (the result is a real; show it rounded up). With the default profile and no fee the unit margin is `15 * (1 - 0.30 - 0.85 * 0.026) - 0.50 - 0.85 * 0.15` = **9.541** dollars per order; with a 10 % fee it is 8.041 once the percentage exceeds the minimum.

Anchor A1's Thursday window through `stop_money` (no fee):

| Line | value | low | high |
|---|---|---|---|
| orders | 60.49 | 33.01 | 93.19 |
| sales | 907.41 | 495.21 | 1397.91 |
| food_cost | 272.22 | 148.56 | 419.37 |
| packaging | 30.25 | 16.51 | 46.60 |
| card_fees | 27.77 | 15.15 | 42.78 |
| spot_fee | 0.00 | 0.00 | 0.00 |
| tips | 0.00 | 0.00 | 0.00 |
| contribution | 577.17 | 314.99 | 889.17 |

Fee terms `fee_flat 0, fee_pct 0.10, fee_min 75`:

| orders | sales | food_cost | packaging | card_fees | spot_fee | contribution |
|---|---|---|---|---|---|---|
| 20.0 | 300.00 | 90.00 | 10.00 | 9.18 | 75.00 | 115.82 |
| 50.0 | 750.00 | 225.00 | 25.00 | 22.95 | 75.00 | 402.05 |
| 80.0 | 1200.00 | 360.00 | 40.00 | 36.72 | 120.00 | 643.28 |

`break_even_orders` for several fee terms:

| fixed_costs | no fee | 10 % with a 75 minimum | flat 100 | 10 % with a 30 minimum |
|---|---|---|---|---|
| 200.0 | 20.9622 | 28.8230 | 31.4432 | 24.8725 |
| 400.0 | 41.9243 | 49.7851 | 52.4054 | 49.7451 |
| 0.0 | 0.0000 | 7.8608 | 10.4811 | 3.1443 |

```
day_costs(profile, timeline, fuel_price_per_gal) -> { labour, fuel, tolls, fixed, total, paid_hours, drive_gallons, generator_gallons }
    paid_hours        = timeline.paid_minutes / 60.0
    labour            = paid_hours * profile.paid_crew * profile.wage_per_hour * (1.0 + profile.payroll_burden_pct)
    drive_gallons     = timeline.miles / profile.mpg
    generator_gallons = timeline.generator_minutes / 60.0 * profile.generator_gal_per_hour
    fuel              = (drive_gallons + generator_gallons) * fuel_price_per_gal
    tolls             = timeline.tolls
    fixed             = profile.fixed_cost_per_service_day if the plan has at least one stop else 0.0
    total             = labour + fuel + tolls + fixed
```

Paid crew are paid from the start of prep to "done", except gaps marked unpaid. The owner's own time is not a cost; it is the denominator of take-home per hour. The fuel price is `ctx.fuel_price_per_gal`, resolved by the backend in this order: the profile's `fuel_price_override`; the latest stored EIA weekly price for the base state's PADD and the profile's fuel type; `money.fuel_price_fallback`. Tips, when included, count toward take-home. Totals are assembled in 4.12.

### 4.10 Driving

```
fallback_leg(A, lat1, lng1, lat2, lng2) -> LegInput
    road_m = haversine_m(lat1, lng1, lat2, lng2) * seed("drive_fallback.detour_factor")
    miles  = road_m / 1609.344
    local  = min(miles, seed("drive_fallback.local_miles"))
    ff_min = local / seed("drive_fallback.local_mph") * 60.0 + (miles - local) / seed("drive_fallback.trunk_mph") * 60.0
    return { source: "fallback", distance_m: road_m, duration_s: ff_min * 60.0, override_minutes: null, toll: 0.0 }

traffic_factor(A, ctx: DayContext, minute: int) -> (factor, dow, hour)
    day_shift = floor_div(minute, 1440);  m = minute - 1440 * day_shift
    dow  = ctx.traffic_dow if day_shift == 0 else mod_floor(ctx.dow + day_shift, 7)
    hour = floor_div(m, 60)
    return (seed(A, "traffic.<A.region.traffic_matrix>")[dow][hour], dow, hour)

leg_minutes(A, profile, leg: LegInput, ctx: DayContext, lookup_minute: int) -> Leg
    base_minutes = leg.duration_s / 60.0
    (factor, dow, hour) = traffic_factor(A, ctx, lookup_minute)
    if leg.source == "google": time_factor = factor / seed(A, "traffic.<A.region.traffic_matrix>_typical")
    else:                      time_factor = factor
    raw = base_minutes * time_factor * profile.truck_time_factor
    if leg.override_minutes != null: minutes = leg.override_minutes;  source = "override"
    else: minutes = round_half_away(raw, 0) as int
          if minutes < 1 and leg.distance_m > 0: minutes = 1
          source = leg.source
    miles = leg.distance_m / 1609.344
    return { from_id: null, to_id: null, source, distance_m: leg.distance_m, miles, base_minutes, depart_minute: null,
             traffic_lookup_minute: lookup_minute, traffic_dow: dow, traffic_hour: hour, traffic_factor: factor, time_factor,
             truck_time_factor: profile.truck_time_factor, raw_minutes: raw, minutes, toll: leg.toll }
```

`LegInput` requires `distance_m >= 0` and `duration_s >= 0`. The two sources mean different things by "duration". A `fallback` leg is built from free-flow speeds, so the table factor (travel time over free-flow time) applies as it is. A `google` leg carries the Routes API duration for routing preference `TRAFFIC_UNAWARE`, which Google's reference describes as "based on road network and average time-independent traffic conditions" (read 2026-10-04): average traffic is already in it, so the factor is divided by the matrix's typical value. A Google leg therefore comes out shorter than Google's figure at night (1.04 / 1.265 = 0.82) and longer in the evening peak (1.72 / 1.265 = 1.36). The backend keeps Google legs for at most 30 days (DECISIONS section 0) and must label the source truthfully; the same `LegInput` always gives the same minutes. The traffic factor is a step function of the departure hour. On the service date the lookup uses `ctx.traffic_dow` (Sunday on a major holiday, the chosen day under a "treat this day as" override); a minute before that date's midnight or after its end belongs to a neighbouring civil date and uses that date's real day of the week (`ctx.dow` shifted), because a holiday or an override belongs to one civil date. The owner's override replaces the whole computation at every hour (it is "what this drive takes me"); distance and toll are kept. `profile.truck_time_factor` multiplies both sources. `profile.avoid_tolls` and `profile.avoid_highways` are not read by the model: the backend sends them to Google as route modifiers, keys its 30-day leg cache on them and hands the model the matching `LegInput`; the fallback leg ignores them. Leg distance for fuel is `miles`. `toll` is the owner's figure if entered, else Google's estimate when the backend has one, else 0.

From (39.0030, -77.4050) to (38.9600, -77.3600): straight line 6163.71 m; road distance = x 1.30 = 8012.82 m = 4.9789 mi; free-flow minutes = 2.0/25 x 60 + (4.9789 - 2.0)/45 x 60 = 4.8 + 3.9719 = **8.7719**; `duration_s` = 526.315.

The same fallback leg at different lookup minutes, in the context of an ordinary Thursday and of Thanksgiving (a Thursday with `traffic_dow` 6):

| lookup_minute | clock | context date | ctx.dow | ctx.traffic_dow | lookup dow | lookup hour | traffic_factor (`dc`) | raw = 8.7719 x factor x 1.10 | minutes |
|---|---|---|---|---|---|---|---|---|---|
| 619 | 10:19 | 2026-10-08 | 3 | 3 | 3 thu | 10 | 1.31 | 12.6403 | 13 |
| 860 | 14:20 | 2026-10-08 | 3 | 3 | 3 thu | 14 | 1.44 | 13.8947 | 14 |
| -30 | 23:30 | 2026-10-08 | 3 | 3 | 2 wed | 23 | 1.12 | 10.8070 | 11 |
| 1470 | 0:30 | 2026-10-08 | 3 | 3 | 4 fri | 0 | 1.09 | 10.5175 | 11 |
| 619 | 10:19 | 2026-11-26 Thanksgiving | 3 | 6 | 6 sun | 10 | 1.18 | 11.3859 | 11 |
| 1470 | 0:30 | 2026-11-26 Thanksgiving | 3 | 6 | 4 fri | 0 | 1.09 | 10.5175 | 11 |

A Google leg with `duration_s` 600 and `distance_m` 7805 in region `dc`:

| lookup_minute | clock (Thursday) | traffic_factor | time_factor = factor / 1.265 | raw = 10.0 x time_factor x 1.10 | minutes |
|---|---|---|---|---|---|
| 619 | 10:19 | 1.31 | 1.035573 | 11.3913 | 11 |
| 1030 | 17:10 | 1.72 | 1.359684 | 14.9565 | 15 |
| 180 | 3:00 | 1.04 | 0.822134 | 9.0435 | 9 |

A 55.6 m fallback hop (free-flow 0.1078 min) rounds to 0 and is raised to **1 min**. Two identical points give distance 0 and **0 min**. With `override_minutes` 14 a leg is **14 min** at any hour and `source` becomes `override`. The Google leg above is 4.8498 mi.

### 4.11 Timeline

Inputs: `profile`, `ctx` (for the traffic lookups), ordered `stops` (as the owner ordered them; the function never reorders), and `legs`, a map keyed `"<from_id>><to_id>"` (ids are stop ids and the literal `base`). A missing key is filled with `fallback_leg` between the two points (`profile.base` for `base`).

```
required_leg_keys(stops: [StopInput]) -> [string]        # every key day_plan can look up, no duplicates, in this order
    if stops is empty: return []
    ids = ["base"] + [s.id for s in stops] + ["base"]
    for i in 0 .. length(ids) - 2: emit ids[i] + ">" + ids[i+1]
    if length(stops) >= 2: for i in 1 .. length(stops): emit ids[i-1] + ">" + ids[i+1]
```

The first loop lists the legs of the plan as ordered, the second the legs that appear when one stop is left out (4.12 evaluates the plan without each stop). The backend resolves exactly these keys before calling `day_plan`. `suggest_day` needs every ordered pair among `base` and the spot ids (`n * (n + 1)` keys for `n` spots).

| Stops | `required_leg_keys` |
|---|---|
| (none) | `[]` |
| `a` | `["base>a", "a>base"]` |
| `office`, `taproom` | `["base>office", "office>taproom", "taproom>base", "base>taproom", "office>base"]` |
| `a`, `b`, `c` | `["base>a", "a>b", "b>c", "c>base", "base>b", "a>c", "b>base"]` |

```
build_timeline(A, profile, ctx, stops: [StopInput], legs) -> Timeline
    if stops is empty: return an empty timeline (all counts 0, start_prep .. done null)
    setup(s) = s.setup_minutes if not null else profile.setup_minutes;  teardown(s) likewise

    # 1. First stop: work backward from its opening time.
    s0 = stops[0];  arrive = s0.open_minute - setup(s0)
    L0 = legs["base>" + s0.id]
    lookup = arrive - floor(L0.duration_s / 60.0 * profile.truck_time_factor)        # departure guess for the traffic lookup
    leg0 = leg_minutes(A, profile, L0, ctx, lookup);  leg0.from_id = "base";  leg0.to_id = s0.id
    leave_base = arrive - leg0.minutes;  leg0.depart_minute = leave_base
    start_prep = leave_base - profile.prep_minutes
    events: start_prep, leave_base

    # 2. Each stop in order, forward.
    unpaid = 0;  prev_leave = null
    for i, s in stops:
        if i > 0:
            leg = leg_minutes(A, profile, legs[stops[i-1].id + ">" + s.id], ctx, prev_leave)
            leg.from_id = stops[i-1].id;  leg.to_id = s.id;  leg.depart_minute = prev_leave
            arrive = prev_leave + leg.minutes
        gap = (s.open_minute - setup(s)) - arrive;  late = 0
        if gap < 0: late = -gap;  gap = 0
        setup_start    = arrive + gap
        effective_open = min(setup_start + setup(s), s.close_minute)
        gap_unpaid     = s.gap_before_unpaid and i > 0
        if gap_unpaid: unpaid += gap
        leave = max(s.close_minute + teardown(s), setup_start)
        events: arrive, setup_start, open (minute = effective_open), close, leave      (stop_index = i)
        # event minutes are emitted non-decreasing: minute = max(minute, minute of the previous event)
        service_minutes += s.close_minute - effective_open;  generator_minutes += leave - setup_start
        prev_leave = leave

    # 3. Back to base.
    legN = leg_minutes(A, profile, legs[last.id + ">base"], ctx, prev_leave)
    legN.from_id = last.id;  legN.to_id = "base";  legN.depart_minute = prev_leave
    back_at_base = prev_leave + legN.minutes;  done = back_at_base + profile.closeout_minutes
    events: back_at_base, done
    day_minutes = done - start_prep;  unpaid_gap_minutes = unpaid;  paid_minutes = day_minutes - unpaid
    drive_minutes = sum of leg minutes;  miles = sum of leg miles;  tolls = sum of leg tolls        (leg order)
```

Rules: the first stop has no gap (the truck leaves base just in time). At later stops the gap is the wait between arriving and starting setup; it is paid unless the stop's `gap_before_unpaid` flag is set. The owner's opening and closing times are never moved; if the truck cannot be set up in time, `late_minutes > 0` and service starts at `effective_open` (orders are then counted over `[effective_open, close)`). The generator runs from the start of setup to the end of teardown at each stop. `start_prep` may be negative (previous evening) and `done` may exceed 1440. Time never runs backwards: a stop the truck reaches after its closing time keeps its entered `close` and gets `effective_open = close`, but `leave` is never earlier than `setup_start`, and the stop's `open` and `close` events are emitted at the minute of the event before them. The `TimelineStop` fields keep the unmoved values.

**Blueprint day sheet.** Profile minutes 45 / 30 / 20 / 30; stops 660-840 and 1020-1200; legs of 11, 10 and 1 minutes (given as overrides); Thursday. Events produced, as `kind[stop_index] minute (clock)`:

`start_prep 574 (9:34), leave_base 619 (10:19), arrive[0] 630 (10:30), setup_start[0] 630 (10:30), open[0] 660 (11:00), close[0] 840 (14:00), leave[0] 860 (14:20), arrive[1] 870 (14:30), setup_start[1] 990 (16:30), open[1] 1020 (17:00), close[1] 1200 (20:00), leave[1] 1220 (20:20), back_at_base 1221 (20:21), done 1251 (20:51)`

Without the two `setup_start` events this is exactly the blueprint's sheet: 9:34, 10:19, 10:30, 11:00, 14:00, 14:20, 14:30, 17:00, 20:00, 20:20, 20:21, 20:51. With leg distances of 4.85, 4.85 and 0.20 miles: day_minutes 677 (11.2833 h), paid_minutes 677, unpaid_gap_minutes 0, drive_minutes 22, service_minutes 360, generator_minutes 460, miles 9.90, stop 2 `gap_before_minutes` 120. The lunch-only day (return leg 11 minutes, 4.85 miles): start_prep 9:34, leave_base 10:19, arrive 10:30, open 11:00, close 14:00, leave 14:20, back_at_base 14:31, done 15:01; day_minutes 327 (5.4500 h), generator_minutes 230, miles 9.70.

Late example: same first stop, second stop planned 14:45-18:00: arrive 870 (14:30), planned setup start 855, `late_minutes` 15, `effective_open` 900 (15:00), `gap_before_minutes` 0.

Unreachable example: same first stop, second stop planned 14:00-14:08: arrive 870 (14:30), `late_minutes` 60, `effective_open` 848 = `close` (the stop is `stop_unreachable`), `leave` 870 (not 868), events of the stop `arrive 870, setup_start 870, open 870, close 870, leave 870`, back_at_base 871, done 901, day_minutes 327, generator_minutes 230, service_minutes 180.

### 4.12 Day plan

```
evaluate(A, profile, plan, ctx, ctx_next, legs, cal) -> internal result R
    T = build_timeline(A, profile, ctx, plan.stops, legs)
    for i, s in plan.stops:
        spot:     W = window_orders(A, profile, s.terms, s.vectors, cal, ctx, ctx_next, T.stops[i].effective_open, s.close_minute)
                  orders = W.orders;  money = stop_money(profile, s.terms, orders)
        event:    E = event_orders(A, profile, s.event, cal, ctx, ctx_next, T.stops[i].effective_open, s.close_minute)   (4.14)
                  orders = E.orders;  money = stop_money(profile, s.terms, orders)
                  event = { buyers: E.buyers, demand: E.demand, hours: E.hours, spread: E.spread }
        catering: money  = catering_money(profile, s.catering) (4.14);  orders = money.orders
    C = day_costs(profile, T, ctx.fuel_price_per_gal)
    totals: orders, sales, food_cost, packaging, card_fees, spot_fees, tips, contribution = est_sum over stops (stop order)
            labour, fuel, tolls, fixed_cost = est_fixed(C.*)
            take_home.x  = contribution.x - C.total     for x in value, low, high;  confidence = contribution.confidence
            day_hours = T.day_minutes / 60.0;  paid_hours = C.paid_hours;  unpaid_gap_hours = T.unpaid_gap_minutes / 60.0
            work_hours = (T.day_minutes - T.unpaid_gap_minutes) / 60.0
            take_home_per_hour.x = take_home.x / work_hours if work_hours > 0 else 0.0

day_plan(A, profile, plan: PlanInput, ctx, ctx_next, legs, cal) -> DayResult
    V = the invalid_window and stops_overlap warnings of plan.stops (table below)
    if V is not empty: return the not-evaluated result (below) with warnings = V
    R = evaluate(plan)
    for each stop i:                                                    # what the stop adds
        R_i = evaluate(plan without stop i)                            # an empty plan evaluates to all zeros
        adds.take_home     = est_levels(R.take_home.value - R_i.take_home.value, R.take_home.low - R_i.take_home.low,
                                        R.take_home.high - R_i.take_home.high, stop i's orders confidence)
        adds.hours         = R.work_hours - R_i.work_hours
        adds.per_hour      = est_levels(adds.take_home.value / adds.hours, adds.take_home.low / adds.hours,
                                        adds.take_home.high / adds.hours, adds.take_home.confidence) if adds.hours > 0 else null
        adds.added_costs   = R.costs.total - R_i.costs.total
        adds.break_even_orders = break_even_orders(profile, s.terms, adds.added_costs) for spot and event; null for catering
        adds.uses_fallback_leg = any leg of R_i's timeline has source "fallback"
    unpaid_gap_alternative: if any stop i >= 1 has gap_before_minutes > 0 and gap_unpaid == false:
        R_u = evaluate(plan with gap_before_unpaid = true on every stop)
        { take_home: R_u.take_home, take_home_per_hour: R_u.take_home_per_hour, work_hours: R_u.work_hours,
          labour_saved: R.costs.labour - R_u.costs.labour }
      else null
    warnings = the table below, in table order, stops in index order within a code
```

"What a stop adds" is the whole day with the stop minus the whole day without it: dollars (expected, weak-day and strong-day), hours of the owner's day, dollars per added hour, and the number of orders the stop needs so that its contribution pays for the costs it adds. For a one-stop day this is the classic break-even of the day. Empty plan: empty timeline, every total zero with confidence `fixed`, no warnings.

The not-evaluated result is `{ model_version, seeds_revision, date: plan.date, timeline: <the empty timeline of 4.11>, stops: [], totals: <every Estimate est_fixed(0.0), every other field 0>, unpaid_gap_alternative: null, warnings }`.

Worked day (our numbers, not the blueprint's): Thursday 2026-10-08, default profile, fuel 4.195, anchor A1 at 11:00-14:00 then anchor A2 at 17:00-20:00, timeline of 4.11:

|  | Lunch only | Lunch, then the taproom |
|---|---|---|
| Office park 11:00-14:00, orders | 60.49 (33.01 to 93.19) | 60.49 (33.01 to 93.19) |
| Taproom 17:00-20:00 (only food), orders |  | 39.38 (20.76 to 62.20) |
| Sales | $907.41 | $1498.17 |
| Day, prep to done | 5.45 h | 11.28 h |
| Miles driven | 9.7 | 9.9 |
| Fuel: driving + generator | 1.078 gal + 2.300 gal = $14.17 | 1.100 gal + 4.600 gal = $23.91 |
| Labour (2 x $18 x 1.10 x paid hours) | $215.82 | $446.82 |
| **Take-home** | **$347.18 ($85.00 to $659.18)** | **$482.20 ($42.35 to $1011.86)** |
| Take-home per hour | $63.70 | $42.74 |

| Quantity | Value |
|---|---|
| Stop 2 `adds.take_home` = day with both stops minus day without stop 2 | $135.02 (-$42.64 to $352.69) |
| Stop 2 `adds.hours` | 5.8333 h |
| Stop 2 `adds.per_hour` = each level of `adds.take_home` / `adds.hours` | $23.15 (-$7.31 to $60.46) |
| Stop 2 `adds.added_costs` (labour + fuel + tolls + fixed) | $240.74 |
| Stop 2 `adds.break_even_orders` = added_costs / 9.541 | 25.232 |
| Stop 1 `adds.take_home` (against a taproom-only day of $163.31) | $318.90 ($56.71 to $630.89) |
| Lunch-only day: costs $229.99, so break-even = costs / 9.541 | 24.105 |
| `unpaid_gap_alternative.take_home` (120 unpaid minutes) | $561.40 ($121.55 to $1091.06) |
| `unpaid_gap_alternative.work_hours`, per hour, `labour_saved` | 9.2833 h, $60.47, $79.20 |
| Same day on Friday 2026-10-09: taproom orders | 59.08 instead of 39.38 |

The shape matches the blueprint's example: the second stop adds little per hour because the crew is paid through a two-hour gap; unpaid, the day clears clearly more per hour; on a weak day the second stop loses money; on a Friday the same taproom is worth half as much again. The dollar figures differ from the blueprint's because orders differ slightly (60.5 against 66) and this profile burns generator fuel.

**Warnings** (fixed list). A "stop" warning is emitted once per stop that triggers it, with `stop_index`; a "day" warning once, with `stop_index` null. `data` has exactly the keys of the last column (`{}` when there are none). If any stop raises `invalid_window` or `stops_overlap` the plan is not evaluated: empty timeline, `stops: []`, zero totals with confidence `fixed`, `unpaid_gap_alternative: null` and only those warnings.

| Code | Level | Per | Trigger | `data` |
|---|---|---|---|---|
| `invalid_window` | error | stop | `open_minute < 0`, `close_minute > 2880` or `close_minute <= open_minute` | `{ open_minute, close_minute }` |
| `stops_overlap` | error | stop | stop `i >= 1` with `open_minute < stops[i-1].close_minute` | `{ open_minute, previous_close_minute }` |
| `stop_unreachable` | error | stop | `effective_open >= close_minute` | `{ arrive, effective_open, close_minute }` |
| `late_arrival` | warn | stop | `late_minutes > 0` and the stop is not `stop_unreachable` | `{ late_minutes, effective_open }` |
| `outside_region` | warn | stop | spot stop whose `vectors.in_region` is false | `{}` |
| `stale_vectors` | error | stop | spot stop with `vectors_match(A, terms, vectors)` false (4.7) | `{}` |
| `outside_allowed_hours` | warn | stop | spot with `terms.allowed != null` and (`allowed.days[ctx.dow]` false, or `open_minute < allowed.open_minute`, or `close_minute > allowed.close_minute`) | `{ dow, open_minute, close_minute }` |
| `fallback_drive_time` | warn | day | any leg of the timeline has `source == "fallback"` | `{ legs: [string] }`: the keys `"<from_id>><to_id>"` of the fallback legs, in leg order |
| `long_gap` | warn | stop | `gap_before_minutes >= seed("timeline.long_gap_minutes")` and `gap_unpaid` is false | `{ gap_before_minutes }` |
| `long_day` | warn | day | `day_minutes > seed("timeline.long_day_minutes")` | `{ day_minutes }` |
| `fee_high` | warn | stop | the stop's `sales.value > 0` and `spot_fee.value > seed("money.fee_warn_share") * sales.value` | `{ spot_fee, sales }` |
| `below_break_even` | warn | stop | `adds.take_home.value < 0` | `{ take_home }` |
| `event_thin_crowd` | warn | stop | event stop with `attendance * seed("events.attendance_haircut") / max(1, vendors) < seed("events.min_attendees_per_vendor")` | `{ attendees_per_vendor }` |
| `weak_day_loss` | info | stop | `adds.take_home.value >= 0` and `adds.take_home.low < 0` | `{ take_home_low }` |
| `capacity_bound` | info | stop | spot: `window.capped_hours > 0`; event: some hour with `d_h > cap_h` | `{ capped_hours }` |
| `early_start` | info | day | `start_prep < seed("timeline.early_start_minute")` | `{ start_prep }` |
| `ends_after_midnight` | info | day | `done > 1440` | `{ done }` |
| `no_forecast` | info | day | the context is not typical and, for some clock hour overlapped by a spot or event stop, `weather_multiplier(record, "open").missing` is true | `{ hours }` |
| `holiday` | info | day | `ctx.holiday_class != null` | `{ holiday_id: string?, holiday_class }` |
| `weak_seed` | info | stop | spot stop with `window.evidence.weak_share >= 0.5` | `{ weak_share }` |
| `default_host_size` | info | stop | spot stop whose host has `size_source == "default"` and `window.host_orders > 0` | `{ size }` |

Values in `data`: timeline fields are those of the stop's `TimelineStop` or of the `Timeline`; `open_minute`, `close_minute` and `previous_close_minute` are the entered minutes of the stop and of the stop before it; `dow` is `ctx.dow`; `spot_fee` and `sales` are the `value` of the stop's money lines; `take_home` is `adds.take_home.value` and `take_home_low` is `adds.take_home.low`; `attendees_per_vendor` is the left side of the trigger; `capped_hours` is `window.capped_hours` for a spot and the number of event hours with `d_h > cap_h` for an event; `hours` counts the (stop, clock hour) pairs of spot and event stops in `[effective_open, close)` whose open-setting forecast is missing, each hour looked up in the context of its own civil date; `holiday_id` is `ctx.holiday.id` or null when the class comes from a "treat as holiday" override on an ordinary day; `weak_share` is `window.evidence.weak_share`; `size` is the host's `size`.

### 4.13 Calibration and accuracy

A logged service is judged against `predicted_raw`: the mean of `window_orders` for the logged window with `cal = null`, the context of that date (holiday, override, forecast as stored) and the vectors and terms in force, under the current model version and seeds. When `model_version`, `seeds_revision` or the region data changes, `predicted_raw` is recomputed for every stored service before calibrating.

```
calibrate(A, services: [ServiceLogEntry], as_of: date) -> CalibrationState
    rows = []
    for sv in services sorted by (date ascending, service_id ascending):
        skip unless sv.kind == "spot" and sv.spot_id != null and sv.predicted_raw > 0
        age = days_from_civil(as_of) - days_from_civil(sv.date);  skip if age < 0
        w  = exp(-ln2 * age / calibration.half_life_days)                                   # ln2 = 0.6931471805599453
        R  = ln(max(sv.actual, calibration.min_actual) / max(sv.predicted_raw, calibration.min_actual))
        Lt = clamp(R, -ln(calibration.ratio_clamp), ln(calibration.ratio_clamp))            # what the service tells the truck factor
        Ls = clamp(R, -ln(calibration.spot_ratio_clamp), ln(calibration.spot_ratio_clamp))  # what it tells its own spot
        in_truck = sv.predicted_raw >= calibration.min_predicted
        rows.append({ sv, w, Lt, Ls, in_truck })
    # Pass A: services that were not sold out.
    m_a = (sum of w * Lt over rows with in_truck and sold_out == false) / (k_truck + sum of w over the same rows)
    for each spot_id among rows:
        s_a[spot_id] = (sum of w * (Ls - m_a) over that spot's rows with sold_out == false) / (k_spot + sum of w over the same rows)
                       # 0.0 for a spot without such rows
    # A sold-out service is a lower bound on demand: it is used only if it says more than the other logs say about its own spot.
    used(row) = (not sold_out) or (Ls > m_a + s_a[row.spot_id])
    # Truck factor, from used rows with in_truck.
    W = sum of w;  truck_log_factor = (sum of w * Lt) / (k_truck + W);  truck_n = number of those rows;  truck_weight = W
    # Spot factors, from every used row of the spot.
    for each spot_id among used rows, ascending:
        W_j = sum of w;  log_factor = (sum of w * (Ls - truck_log_factor)) / (k_spot + W_j)
        spots[spot_id] = { factor: exp(log_factor), log_factor, n: number of those rows, weight: W_j }
    # Residual spread, from used rows with in_truck that were not sold out.
    r = Ls - truck_log_factor - spots[spot].log_factor
    resid_weight = sum of w;  resid_n = count;  resid_sd = sqrt((sum of w * r * r) / resid_weight) if resid_n >= calibration.min_resid_n else null
    # The mean of log ratios estimates a geometric mean; the correction turns the truck factor into a mean.
    bias_log = 0.5 * resid_sd * resid_sd * truck_weight / (k_truck + truck_weight) if resid_sd != null else 0.0
    truck_factor = exp(truck_log_factor + bias_log)
```

All sums run in the sorted row order. The result also carries `model_version` and `seeds_revision` from `A` and `as_of` as given. The factor for a spot is `truck_factor * spots[spot_id].factor`; for any other point it is `truck_factor`. Both multiply demand before the capacity cap (4.7). The prior weights mean the logs carry `W / (W + 4)` of the truck factor: 71 % after ten fresh services. `Lt` keeps one wild service from moving every estimate of the truck (ratio limited to 1/4 .. 4); `Ls` lets a spot the model gets badly wrong converge on the owner's results (1/50 .. 50). A service predicted below `calibration.min_predicted` orders (`in_truck` false) informs its own spot only and stays out of the truck factor and of the residual spread. `truck_log_factor` itself is not corrected: spot factors and residuals are measured against it. Events and catering never enter calibration, and events use the truck factor only.

Example, `as_of = 2026-10-04` (every ratio lies inside both clamps, so `R = Lt = Ls`):

| service | spot | date | actual | predicted_raw | sold out | age (days) | weight w | R = Lt = Ls = ln(actual / predicted_raw) | status |
|---|---|---|---|---|---|---|---|---|---|
| s1 | A | 2026-06-06 | 52 | 60.0 |  | 120 | 0.500000 | -0.143101 | used |
| s2 | A | 2026-07-11 | 70 | 62.0 |  | 85 | 0.612027 | +0.121361 | used |
| s3 | B | 2026-08-01 | 30 | 39.4 |  | 64 | 0.690956 | -0.272568 | used |
| s4 | B | 2026-08-22 | 45 | 39.4 | yes | 43 | 0.780065 | +0.132897 | used |
| s5 | A | 2026-09-12 | 66 | 60.5 |  | 22 | 0.880666 | +0.087011 | used |
| s6 | C | 2026-09-26 | 2 | 2.1 |  | 8 | 0.954842 | -0.048790 | spot C only: predicted_raw < 3 |
| s7 | B | 2026-10-03 | 28 | 41.0 |  | 1 | 0.994240 | -0.381368 | used |

| Result | Value |
|---|---|
| Pass A, truck (in_truck services that were not sold out): `m_a` = sum(w x Lt) / (4 + sum w) | -0.063579 |
| Pass A, spots: `s_a[A]`, `s_a[B]`, `s_a[C]` | +0.041270, -0.098259, +0.003570 |
| s4 is sold out and Ls = +0.132897 > m_a + s_a[B] = -0.161837, so it is kept as an ordinary observation | used |
| `truck_log_factor` = sum(w x Lt) / (4 + 4.457955) over s1, s2, s3, s4, s5, s7 | -0.045458 |
| `truck_n`; `truck_weight` | 6; 4.457955 |
| Spot A: `log_factor`, `factor`, `n`, `weight` | +0.034037, 1.034623, 3, 1.992693 |
| Spot B: `log_factor`, `factor`, `n`, `weight` | -0.064365, 0.937663, 3, 2.465262 |
| Spot C (s6 only): `log_factor`, `factor`, `n`, `weight` | -0.000804, 0.999196, 1, 0.954842 |
| `resid_sd`, `resid_n`, `resid_weight` (the five in_truck services that were not sold out) | 0.180334, 5, 3.677890 |
| `bias_log` = 0.5 x 0.180334^2 x 4.457955 / (4 + 4.457955) | +0.008570 |
| `truck_factor` = exp(truck_log_factor + bias_log) | 0.963784 |
| Factor applied at spot A / spot B / spot C / any other point | 0.997154 / 0.903705 / 0.963009 / 0.963784 |

Applied to anchor A2 saved as spot B: 39.384 x 0.903705 = **35.592** orders (20.60 to 53.53), confidence `fair` (sigma_model 0.2902). An empty log gives `truck_factor` 1.0, `truck_weight` 0.0, `resid_sd` null, `bias_log` 0.0. A log holding only s4 (sold out, ratio above 1) gives `truck_factor` 1.021924; the same service with 30 orders (sold out below the prediction) is dropped and gives 1.0.

The two clamps, the `in_truck` rule and the correction on logs of a single spot:

| Log (all dated `as_of`, one spot) | Lt (last row) | Ls (last row) | truck_log_factor | bias_log | truck_factor | spot factor | combined | predicted_raw x combined |
|---|---|---|---|---|---|---|---|---|
| One service: 500 sold against 50 predicted (ratio 10) | +1.386294 | +2.302585 | +0.277259 | +0.000000 | 1.319508 | 1.659193 | 2.1893 | 109.47 |
| One service: 5 sold against 50 predicted (ratio 0.1) | -1.386294 | -2.302585 | -0.277259 | +0.000000 | 0.757858 | 0.602703 | 0.4568 | 22.84 |
| One service: 60 sold against 2.9 predicted (not in_truck) | +1.386294 | +3.029634 | +0.000000 | +0.000000 | 1.000000 | 2.132742 | 2.1327 | 6.18 |
| 100 services: 80 sold against 10 predicted | +1.386294 | +2.079442 | +1.332975 | +0.000227 | 3.793172 | 2.064162 | 7.8297 | 78.30 |
| 100 services: 60 sold against 2.9 predicted | +1.386294 | +3.029634 | +0.000000 | +0.000000 | 1.000000 | 18.942197 | 18.9422 | 54.93 |
| 100 services: 9 sold against 60 predicted | -1.386294 | -1.897120 | -1.332975 | +0.000130 | 0.263726 | 0.578271 | 0.1525 | 9.15 |
| Six services of 50 predicted: five sold 50, one entered as 500 | +1.386294 | +2.302585 | +0.138629 | +0.222915 | 1.435545 | 1.177535 | 1.6904 | 84.52 |

After a hundred services the estimate has moved from 10 to 78.30 where the owner sells 80, from 2.9 to 54.93 where the owner sells 60 and from 60 to 9.15 where the owner sells 9. In the last row one mistyped service makes the residual spread 0.862 and with it the correction large, so the factor at that spot is 1.69 instead of 1.0: a wrong entry is the owner's to fix (section 6 item 16).

Sold-out test at a spot that runs above the truck's average. Ten services at ratio 1.0 at other spots and two at 1.65 at spot A give `m_a` = +0.062597 and `s_a[A]` = +0.175271. A sold-out service at A with ratio 1.10 has Ls = +0.095310: above `m_a` but below `m_a + s_a[A]` = +0.237868, so it is dropped and the factor at A stays 1.274117.

```
accuracy_report(entries: [ServiceLogEntry]) -> AccuracyReport
    block(rows):  scored = rows with sold_out == false, in (date, service_id) order
        n_total = count(rows);  n_sold_out = n_total - n_scored;  if n_scored == 0: metrics null
        bias     = (sum of (predicted - actual)) / (sum of actual)            # null if the sum of actual is 0
        mape     = (sum of abs(predicted - actual) / max(actual, 1.0)) / n_scored
        coverage = (number with low <= actual <= high) / n_scored
        raw_bias, raw_mape = the same with predicted_raw in place of predicted
    report = block(all entries) + by_spot: [ block(entries of the spot) + spot_id ], spots ascending
```

`predicted`, `low`, `high` are the calibrated figures the owner was shown when the service was planned or logged (the stored prediction snapshot); `predicted_raw` is the uncalibrated mean. Sold-out services are excluded from all three metrics (their actual is a lower bound) and counted separately. `bias > 0` means the model predicted too much. Example with `predicted = predicted_raw` and ranges from `interval` with no evidence:

| Scope | n_total | n_scored | n_sold_out | bias | mape | coverage |
|---|---|---|---|---|---|---|
| all | 7 | 6 | 1 | +0.068548 | 0.196514 | 1.0000 |
| spot A | 3 | 3 | 0 | -0.029255 | 0.117155 | 1.0000 |
| spot B | 3 | 2 | 1 | +0.386207 | 0.388810 | 1.0000 |
| spot C | 1 | 1 | 0 | +0.050000 | 0.050000 | 1.0000 |

### 4.14 Events and catering

```
event_orders(A, profile, ev: EventTerms, cal, ctx, ctx_next, open, close) -> EventResult
    buyers = ev.attendance * seed("events.attendance_haircut") * seed("events.p_buy.<ev.event_type>")
    demand = buyers / max(1, ev.vendors) * (cal.truck_factor if cal != null else 1.0)
    minutes = close - open;  hours = [];  d = [];  c = []
    for each clock hour overlapping [open, close), as in window_orders (start, end, fraction, day_index, context cx, hour):
        wx    = 1.0 if cx.typical else weather_multiplier(A, cx.forecast[hour] if cx.forecast != null else null, "open").multiplier
        d_h   = demand * (end - start) / minutes * wx
        cap_h = profile.capacity_orders_per_hour * fraction
        d.append(d_h);  c.append(cap_h)
        hours.append({ day_index, hour, fraction, demand: d_h, capacity: cap_h, weather: wx, orders: min(d_h, cap_h) })
    evidence = evidence_from(cal, null);  evidence.event = true
    (orders, spread) = interval_capped(A, d, c, evidence)             # 4.8
    return { orders, buyers, demand, hours, spread }
```

An event replaces the map-based estimate entirely: the crowd is the organiser's, not the neighbourhood's. Demand is spread evenly over the window, capped hour by hour; menu fit is not applied; `vendors` counts every food vendor including this truck. `attendance` is the organiser's expected attendance during the stop. Fees come from the stop's `terms`. A zero-length window gives 0. In the second example below demand is five times capacity in every hour, so even weak-day demand fills the truck and the range closes onto the value; the label stays `very_rough`.

| Event | buyers | demand | By hour: demand -> orders | orders | confidence |
|---|---|---|---|---|---|
| 2,000 expected, 6 vendors, `general`, 11:00-15:00 | 420.0 | 70.0000 | 11: 17.500 -> 17.500; 12: 17.500 -> 17.500; 13: 17.500 -> 17.500; 14: 17.500 -> 17.500 | 70.000 (25.031 to 129.674) | very_rough |
| 12,000 expected, 8 vendors, `food_focused`, 11:30-14:30 | 5400.0 | 675.0000 | 11: 112.500 -> 22.500; 12: 225.000 -> 45.000; 13: 225.000 -> 45.000; 14: 112.500 -> 22.500 | 135.000 (135.000 to 135.000) | very_rough |

```
catering_money(profile, ct: CateringTerms) -> StopMoney, every line est_fixed
    sales     = max(ct.headcount * (ct.price_per_head or 0.0), ct.guarantee or 0.0)
    orders    = ct.headcount
    food_cost = ct.food_cost if not null else sales * profile.food_cost_pct
    packaging = ct.headcount * profile.packaging_per_order
    card_fees = 0.0;  spot_fee = 0.0;  tips = 0.0
    contribution = sales - food_cost - packaging
    unit_margin = { at_minimum: 0.0, at_percentage: 0.0 }             # not used for a contracted stop
```

A catering stop is a guaranteed-fee stop: its revenue is contracted, so its lines carry confidence `fixed` and add nothing to the width of the day's range. It still takes time, fuel and labour through the timeline.

| Terms | sales | food_cost | packaging | contribution |
|---|---|---|---|---|
| 80 heads at $14, guarantee $1,000, no stated cost | $1120.00 | $336.00 | $40.00 | $744.00 |
| 100 heads, guarantee $1,500, stated food cost $420 | $1500.00 | $420.00 | $50.00 | $1030.00 |

### 4.15 Suggestions

```
suggest_day(A, profile, ctx, ctx_next, spots: [SpotInput], legs, cal, options) -> [Suggestion]
    service = options.service_minutes or suggest.service_minutes          # a multiple of 60, 60..480
    L = floor_div(service, 60);  first = floor_div(suggest.earliest_open_minute, 60);  last = floor_div(suggest.latest_close_minute, 60)      # hours [first, last)
    max_stops = options.max_stops_per_day or suggest.max_stops_per_day    # 1..3
    # 1. Candidates.
    for spot in spots, ascending spot_id:
        values[k]  = hourly_orders(A, profile, spot.terms, spot.vectors, cal, ctx, first + k).orders      for k in 0 .. last-first-1
        allowed[k] = true if spot.terms.allowed == null, else
                     allowed.days[ctx.dow] and (first + k) * 60 >= allowed.open_minute and (first + k + 1) * 60 <= allowed.close_minute
        for b in best_windows(values, L, suggest.windows_per_spot, circular = false, allowed):
            skip if b.total < suggest.min_stop_orders
            cand = { spot, open: (first + b.start) * 60, close: open + service }
            cand.single = evaluate(a one-stop plan of cand).take_home.value
    order the candidates by (qkey(single) descending, spot_id ascending, open ascending) and keep, in that order,
        first the best floor_div(suggest.max_candidates, 4) candidates of each daypart of the opening hour
              (daypart_of_hour[floor_div(open, 60)]; breakfast, lunch, dinner, late, in that order),
        then the best remaining candidates until suggest.max_candidates are kept
    # 2. Plans: every subset of 1..max_stops candidates, taken in (open ascending, spot_id ascending) order.
    a subset is feasible when: all spot_ids differ; each stop opens at or after the previous one closes;
        evaluate(plan) has late_minutes == 0 at every stop; day_minutes <= suggest.max_day_minutes
    # 3. Rank feasible plans by (qkey(take_home.value) descending, number of stops ascending, day_minutes ascending,
    #    then the list of (spot_id, open) compared element by element ascending).
    return the first (options.limit or suggest.day_results) as Suggestion with position 1, 2, ... and result = day_plan(plan)
```

The objective is expected take-home. A candidate becomes a `StopInput` of kind `spot` whose `id` is the `spot_id` (so `legs` is keyed by spot ids and `base`), with `gap_before_unpaid = false` and no setup or teardown override. Candidates are kept per daypart first, so a long list of lunch spots cannot push every evening window out. The search is exhaustive within exact limits: at most 24 candidates, so at most 24 + 276 + 2,024 = 2,324 plan evaluations for three stops (300 for two). A spot appears at most once per day.

```
suggest_week(A, profile, week_start: date (a Monday), contexts: [DayContext x8], spots, legs, cal, options) -> WeekSuggestion
    for d in 0..6: opts[d] = suggest_day(..., contexts[d], contexts[d+1], ..., limit = suggest.week_day_options)
                   keeping only plans with take_home.value > suggest.min_day_take_home
    max_days   = options.max_days_per_week or suggest.max_days_per_week                         # 1..7
    max_visits = options.max_visits_per_spot_per_week or suggest.max_visits_per_spot_per_week   # 1..7
    best = none
    search(d, total, picks, days_used, visits):
        if d == 7: leaves_visited += 1
                   if best is none or qkey(total) > qkey(best.total): best = (total, copy of picks);  return
        for idx, plan in opts[d] in rank order:                                                 # work options first, best first
            if days_used < max_days and for every spot in plan: visits[spot] + 1 <= max_visits:
                search(d + 1, total + plan.take_home.value, picks + [idx], days_used + 1, visits with the plan's spots added)
        search(d + 1, total, picks + [off], days_used, visits)                                  # then the day off
    search(0, 0.0, [], 0, {})
```

A strictly greater `qkey` is needed to replace the best, so among equal totals the first one found wins: earlier days prefer higher-ranked plans and working over resting. The search is exhaustive, at most `6^7 = 279,936` leaves. `total_take_home = est_sum` of the chosen days' take-home in day order. `contexts[7]` is the Monday after, needed only for windows past midnight (none in revision 1).

Example with two saved spots (anchor A1 as `office`, anchor A2 as `taproom`), blueprint legs, week of 2026-10-05, no forecast. Thursday's candidates: office 11:00-14:00 single-stop take-home $347.18; office 14:00-17:00 single-stop take-home -$153.87; taproom 17:00-20:00 single-stop take-home $163.31; taproom 20:00-23:00 single-stop take-home -$108.28; 6 feasible plans (office 11:00 + taproom 20:00 exceeds the 14-hour day; office 14:00 + taproom 17:00 would arrive late). Top two plans per day, Wednesday to Sunday:

| Date | position | Stops | take_home | day_minutes |
|---|---|---|---|---|
| 2026-10-07 wed | 1 | office 11:00-14:00 + taproom 17:00-20:00 | $446.05 ($21.75 to $925.98) | 677 |
|  | 2 | office 11:00-14:00 | $389.94 ($109.88 to $692.06) | 327 |
| 2026-10-08 thu | 1 | office 11:00-14:00 + taproom 17:00-20:00 | $482.20 ($42.35 to $1011.86) | 677 |
|  | 2 | office 11:00-14:00 | $347.18 ($85.00 to $659.18) | 327 |
| 2026-10-09 fri | 1 | office 11:00-14:00 + taproom 17:00-20:00 | $450.97 ($24.25 to $971.44) | 677 |
|  | 2 | taproom 17:00-20:00 | $351.19 ($94.66 to $662.90) | 307 |
| 2026-10-10 sat | 1 | taproom 17:00-20:00 | $403.40 ($125.05 to $741.13) | 307 |
|  | 2 | taproom 12:00-15:00 | $39.14 (-$85.78 to $193.90) | 307 |
| 2026-10-11 sun | 1 | taproom 16:00-19:00 | $33.47 (-$89.02 to $185.33) | 307 |
|  | 2 | taproom 12:00-15:00 | -$6.03 (-$111.48 to $125.49) | 307 |

| Limits | Chosen option per day | Total expected take-home | Leaves visited |
|---|---|---|---|
| 5 days, 2 visits per spot | mon off; tue #1 (office); wed #2 (office); thu off; fri #2 (taproom); sat #1 (taproom); sun off | $1550.49 | 407 |
| 5 days, 3 visits per spot | mon off; tue #1 (office); wed #2 (office); thu #1 (office + taproom); fri #2 (taproom); sat #1 (taproom); sun off | $2032.69 | 1419 |

On Sunday the windows 16:00-19:00 and 17:00-20:00 tie exactly (25.776 orders); the earlier start wins through `qkey`.

### 4.16 Scouting

```
scout_estimate(A, profile, place: PlaceInput, legs, cal, fuel_price_per_gal) -> ScoutResult?
    row = seed(A, "place_types.rows.<place.place_type>");  if row.host_fit <= 0: return null       # not a host
    kitchen = row.kitchen_default if place.kitchen is null or "unknown" else place.kitchen
    host  = { segment: row.host_segment, size: place.size_default, size_source: "default", only_food: kitchen == "no",
              point_id: place.point_id } if row.host_segment != null and place.size_default > 0 else null
    terms = { spot_id: null, visibility: "normal", host, fee_flat: 0, fee_pct: 0, fee_min: 0, allowed: null }
    rows  = map_weight_rows(A, profile, cal)              # 4.17; the same for every place of a request: compute it once
    strip = strip_from_rows(A, profile, terms, place.vectors, rows)          # truck factor only
    b = best_windows(strip, floor_div(scout.window_minutes, 60), 1, circular = true)
    result = { place_id: place.place_id, place_type: place.place_type, position: 0, host_fit: row.host_fit, kitchen,
               host_segment: row.host_segment, host_size: place.size_default if host != null else 0.0, size_source: "default" }
    if b is empty:
        z = interval(A, 0.0, evidence_from(cal, null))
        return result + { best_window: null, orders: { value: 0.0, low: 0.0, high: 0.0, confidence: z.confidence },
                          contribution: the same four values, round_trip: { minutes: 0, miles: 0.0, cost: 0.0 }, score: 0.0 }
    dow = floor_div(b[0].start, 24);  open = mod_floor(b[0].start, 24) * 60;  close = open + scout.window_minutes
    W = window_orders(A, profile, terms, place.vectors, cal, typical_context(A, dow), typical_context(A, mod_floor(dow + 1, 7)), open, close)
    money = stop_money(profile, terms, W.orders)
    T = build_timeline(A, profile, typical_context(A, dow), [one stop with id = place_id, open..close], legs)     # legs "base><place_id>", "<place_id>>base"
    round_trip.minutes = T.drive_minutes;  round_trip.miles = T.miles
    round_trip.cost = T.drive_minutes / 60.0 * profile.paid_crew * profile.wage_per_hour * (1.0 + profile.payroll_burden_pct)
                      + T.miles / profile.mpg * fuel_price_per_gal + T.tolls
    score = row.host_fit * money.contribution.value - round_trip.cost
    return result + { best_window: { dow, open_minute: open, close_minute: close }, orders: W.orders,
                      contribution: money.contribution, round_trip, score }

strip_from_rows(A, profile, terms, vectors, rows) -> [number x168]
    has_host = terms.host != null and terms.host.size > 0
    if has_host: hc = host_capture(A, terms.host, terms.visibility, vectors.rivals);  hs = index of terms.host.segment
    for how in 0..167:
        regime = regime_of_hour[mod_floor(how, 24)]
        o = 0.0;  for s in 0..15: o += vectors.capture[regime][s] * rows.w_opp[how][s]
        if has_host: o += hc[regime] * rows.w_opp[how][hs]
        out[how] = min(o, profile.capacity_orders_per_hour)

scout_rank(results) = sort by (qkey(score) descending, place_id ascending); position = 1, 2, ...; keep the first scout.max_results
```

The place's vectors exclude its own source point (4.4), so its visitors enter only through the host term at the place's own `size_default`, which is why scouting ranges are wide (`very_rough` whenever the default host supplies the demand). A place without a host segment or with `size_default` 0 (a farmers market, a stadium, a campus building, an office park) has no host term and is ranked on its catchment alone; `host_size` is then 0.0. The ranking key is the expected contribution of the place's best three hours in a typical week, discounted by `host_fit` (how commonly that kind of place hosts trucks), minus the cost of driving there and back. Which places are candidates (inside the drive-time limit and the licence counties, lead status) is decided by the backend before this function; a place type with `host_fit` 0 returns null. The score ranks; it is not shown as money.

`strip_from_rows` builds the week strip from the weight rows of 4.17 in one pass instead of 168 calls of `hourly_orders`, which is what lets one request rank thousands of candidates. It equals `week_strip` to the tolerance of 1.4 when `terms.spot_id` is null (largest relative difference over the three places below: 2.2e-16). `scout_estimate` picks the window from it and calls `window_orders` once, for the chosen window.

| position | place | type | size_default | host_fit | kitchen | best window | orders | confidence | contribution | round trip | score |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | w200 | office_park | 0.0 | 0.8 | no | tue 11:00-14:00 | 66.66 (36.60 to 97.93) | rough | $635.96 | 22 min, 9.7 mi, $19.04 | 489.7250 |
| 2 | w100 | taproom | 40.0 | 1.0 | no | sat 17:00-20:00 | 21.52 (6.05 to 42.76) | very_rough | $205.28 | 2 min, 0.4 mi, $1.51 | 203.7777 |
| 3 | n300 | bar | 45.0 | 0.3 | yes | sat 17:00-20:00 | 9.68 (2.39 to 19.84) | very_rough | $92.38 | 40 min, 18.0 mi, $34.79 | -7.0766 |

### 4.17 Map fast path

```
map_weight_rows(A, profile, cal) -> { w_opp: [[number x16] x168], w_people: [[number x16] x168] }
    E = expand_curves(A);  tf = cal.truck_factor if cal != null else 1.0
    for how in 0..167, s in 0..15:
        w_opp[how][s]    = E.presence[s][how] * E.intent[s][how] * profile.daypart_fit[daypart_of_hour[mod_floor(how, 24)]] * tf
        w_people[how][s] = E.presence[s][how]

cell_scores(features: [number x (50 * n)], n: int, w_opp_row: [number x16], w_people_row: [number x16], regime, capacity) -> { opportunity: [number x n], people: [number x n], competition: [number x n] }
    off = 0 if regime == "day" else 16;  ri = 48 if regime == "day" else 49
    for c in 0..n-1:  b = 50 * c
        o = 0.0;  for s in 0..15: o += features[b + off + s] * w_opp_row[s]
        opportunity[c] = min(o, capacity)
        p = 0.0;  for s in 0..15: p += features[b + 32 + s] * w_people_row[s]
        people[c] = p
        competition[c] = features[b + ri]

score_byte(x, hi) -> int 0..255
    t = clamp(x / hi, 0.0, 1.0)
    return floor(255.0 * sqrt(t) + 0.5)
```

| Layer | Meaning | `hi` (fixed colour domain) |
|---|---|---|
| Opportunity | expected orders per hour for the truck parked at the cell centre, visibility normal, no host, no weather, truck factor applied, capped at capacity | `map.opportunity_hi` = 45 |
| People nearby | people present within walking distance, distance-weighted | `map.people_hi` = 20000 |
| Competition | rival weight at the cell centre for the hour's regime | `map.competition_hi` = 100 |

For hour `how` call `cell_scores` with `w_opp[how]`, `w_people[how]` and `regime_of_hour[mod_floor(how, 24)]`. The colour domain is the same for all 168 hours, all regions and all trucks: a colour always means the same number, and a square-root scale keeps small values visible. For a selected date, build the weight rows from `hour_weights` of that date's context instead of `expand_curves`. The map never applies weather, spot factors or hosts; the spot card does.

Cell at the truck position of 4.4, Thursday 12:00 (`how` = 84, `w_opp[84][w_office]` = 0.070567):

| Layer | Score | `hi` | byte |
|---|---|---|---|
| opportunity = min(45, capture.day . w_opp[84]) | 29.436015 | 45 | 206 |
| people = nearby . w_people[84] | 437.1100 | 20000 | 38 |
| competition = rivals.day | 0.833985 | 100 | 23 |

score_byte(0.0, 45.0) = 0; score_byte(1.0, 45.0) = 38; score_byte(5.0, 45.0) = 85; score_byte(45.0, 45.0) = 255; score_byte(60.0, 45.0) = 255; score_byte(1000.0, 20000.0) = 57; score_byte(2.0, 100.0) = 36.

Tolerance: with binary64 arrays the results match the definition to 1.4. An implementation that keeps `features` in a `Float32Array` must, for the same feature values, match scores to a relative 1e-5 and bytes to plus or minus 1. The cell pack stores features as 16-bit codes (03_DATA.md section 11); that quantisation is a property of the data, not of this function, and is why every number shown to the owner comes from exact server vectors, never from the pack.

---

## 5. Why this number

Any orders estimate must be openable into the steps below. Each step names the fields that carry it; the UI shows them in this order and may collapse them, never reorder or merge them.

1. **Who is within walking distance.** Per segment: `HourSegment.within_present` (people present within 1,200 m, = `within * presence`) when it is not null, otherwise `HourSegment.nearby_present` (= `nearby * presence`) labelled "distance-weighted"; with `presence` and its day type and Monday-Friday factor from `DayContext`. For workers show jobs and the on-site share separately (`within[s]` jobs, or `nearby[s]` labelled "distance-weighted", and `presence`).
2. **How many of them buy a meal this hour.** `intent` per segment; the people of step 1 times `intent` is the number of meal purchases from any outlet.
3. **What share the truck wins.** `capture / within` per segment (average share after distance, the outside option and rival outlets) when `within` is not null; otherwise no share figure is shown, because `capture / nearby` is not a share (4.4). `LocationVectors.rivals[regime]` as the competition figure; the visibility level.
4. **Menu fit.** `factors.menu_fit` for the daypart.
5. **Host.** `HourHost`: size and its source (owner or place default), people present, intent, mode (`captive` or `open`), share, and whether the host sells food.
6. **Subtotal before adjustments.** `demand_raw` and each segment's `demand_raw`.
7. **Weather.** `factors.weather_state`; when `forecast`, the `WeatherDetail` parts (temperature band, precipitation class and probability, wind band) for the open setting (`factors.weather_detail`) and, when the host is captive, for the captive setting (`factors.weather_detail_captive`). When `missing`, the line "no forecast for this hour: no weather adjustment".
8. **Your own results.** `factors.truck_factor` and `factors.spot_factor` with `truck_n`, `truck_weight` and the spot's `n` and `weight`. With no logs: "no logged services yet".
9. **Capacity.** `demand_adj`, `capacity`, `capped`; when capped, how many orders were given up (`demand_adj - orders`).
10. **Window.** Per hour: `fraction` and `orders`; the sum is `orders.value`; `by_segment` and `host_orders` are who the customers would be. An event stop opens into `DayStop.event` instead of steps 1 to 9: `buyers`, `demand` per vendor, then per hour `demand`, `weather`, `capacity` and `orders`.
11. **Range and label.** `spread`: the seven variance parts by name, `sigma_model`, `sigma`, then `low` and `high`, and the sentence for the label (8.3). When capacity holds `low` or `high` back (4.8), say so: they are then what the truck can serve of a weak and of a strong day's demand, not `value` times a factor.
12. **Money.** Each `StopMoney` line at value, low and high; the fee rule that applied (minimum or flat plus percentage); day costs (`labour` with paid hours, `fuel` with gallons driven and generator gallons and the price and its source, `tolls`, `fixed_cost`); each `Leg` (source, base minutes, traffic factor and time factor, truck factor, or the owner's override); `take_home`; `adds` per stop.
13. **Where the assumptions come from.** For every seed used in steps 1-12: its path, value, `tag` and `source`, and whether the owner overrode it. Seeds tagged `tuned` are labelled "placeholder until you log services".

Standing lines, always shown with an estimate: the data vintages (residents April 2020, jobs 2023, places snapshot date); "estimates rank places and times; before you log services they are poor at predicting dollars"; "permission to trade here and local rules are yours to check" (the model knows nothing about rules or permissions at a spot and has no field for them).

---

## 6. Limits and honesty notes

1. No seed is measured from food-truck sales. `outside_option_a0`, `captive_share` and the nightlife intent curve are tuned to two anchors and are entangled with lunch intent and walk decay: a different trio gives the same anchor. Do not present any of them as a finding.
2. Before logs, an 80 % range is roughly the value divided by 1.8 to the value times 1.55. That is deliberate. The honest prior is probably wider still.
3. Worker base is jobs at the address of record. Remote and hybrid work is handled only through the national on-site average (office peak 0.37 bodies per job); a head office with 5,000 jobs and 400 desks is wrong until the jobs review list or the owner's logs correct it. Construction and temp-agency jobs are placed at the employer's office.
4. Venue sizes are place-type defaults unless the owner enters one; hospitals, campuses and transit stations have the weakest seeds and can dominate the map. The model says nothing about whether an institution allows a truck.
5. Presence curves are national averages: no school calendar, no summer, no term dates, no local events, no seasonality (measured taproom seasonality of +15-20 % and -25-30 % is not applied). Campus curves are term-time.
6. Dormitory residents are counted as residents and again as campus students in the daytime; hotel guests are visitors only; hospital inpatients are not modelled.
7. A new spot usually starts slow (trade sources: the first months). The model has no ramp; early logs pull the spot factor down and later logs bring it back.
8. Competition uses outlet kind and distance only: no opening hours, no quality, no capacity, no price. An outlet that is shut at 21:00 still counts in the `eve` regime with its `eve` weight.
9. Walking distance is straight-line with one decay length; barriers (highways, rivers, fences) are invisible.
10. Demand above capacity is lost. Queues, pre-orders and sell-outs before closing are not modelled; the owner's sold-out flag is the only signal. When expected demand is at or near capacity the stated value is the orders at expected demand; expected orders are up to about 15 % lower (demand equal to capacity: 114 of 135).
11. Weather effects are judgement placed between indoor-restaurant measurements (-2 to -11 % in rain), pedestrian counts (-32 % on wet days) and operator anecdotes (about -50 %). Nothing is measured for wind or for trucks. Only temperature, precipitation and wind are used; forecasts exist for about six days.
12. Holidays: only the eleven federal holidays (and Inauguration Day in the Washington region). On minor holidays offices are assumed to work normally and schools and government to be closed. State and local holidays, weather closures and the days around Thanksgiving and Christmas need the owner's "treat this day as".
13. Traffic factors are TomTom's, relative to its free-flow speeds; they are the same for a three-minute hop and a forty-minute run and ignore direction. For Google legs the model assumes that Google's traffic-unaware duration equals the all-hours average of that table. This has not been checked against Google data, so expect a constant offset that only the owner's corrections remove. The straight-line fallback is a guess and is labelled as one.
14. Money: sales tax is outside the model; card fees use one blended rate; tips are off unless the owner opts in; the owner's own labour is not costed; fixed costs are zero until entered; one fuel price covers truck and generator.
15. Events: organisers overstate attendance; the haircut (0.6) and buy rates are trade rules, some of them British. Event estimates are always `very_rough`.
16. Calibration learns one factor per truck and one per spot. It cannot learn by daypart, weekday or weather. A sold-out service is used only when it exceeds what the logs already say about its own spot; it can still move that spot's factor down by about 1 %. A logged count is taken at face value: one service entered at ten times its real count among six at a spot lifts that spot's factor from 1.00 to 1.69 until the entry is corrected.
17. Day totals add lows to lows and highs to highs, which overstates the width of a multi-stop day's range on purpose.
18. Residents are April 2020 counts, not scaled forward. Jobs are 2023. Places are a dated OpenStreetMap snapshot with about one third of host candidates carrying a phone or website.

---

## 7. Porting traps

| Trap | Rule |
|---|---|
| Summation order | Fixed, left to right (1.5). Python `sum()` is compensated from 3.12; NumPy and `array_sum` on mixed types differ; write explicit loops. |
| `exp`, `ln`, `sin`, `cos`, `asin` | Not correctly rounded and may differ by one unit in the last place between runtimes. That is inside the tolerance; never compare their results for exact equality or use them in a tie-break without `qkey`. `sqrt`, `+`, `-`, `*`, `/` and `floor` are exact per IEEE-754. |
| Powers | Write `x * x`. `pow`, `**` and `Math.pow` are never needed and must not be used. |
| Rounding | Only `round_half_away`. PHP `round()` changed behaviour in 8.4 and pre-rounds; JavaScript `Math.round(-2.5)` is -2; Python `round()` is banker's rounding. `toFixed`, `number_format`, `sprintf("%.2f")` and `format()` round differently again: round first with the helper, then format. |
| Integer division | PHP `intdiv` and JavaScript `Math.trunc(a / b)` round toward zero; Python `//` floors. Use `floor_div` (PHP: `(int) floor($a / $b)` is safe for the magnitudes here). JavaScript has no integers: keep minutes and day numbers integral with `Math.floor`. |
| Negative modulo | PHP `%` and JavaScript `%` take the sign of the dividend (`-30 % 1440` is `-30`); Python gives 1410. Use `mod_floor`. Needed for `day_of_week`, negative timeline minutes and `traffic_factor`. |
| Dates | No `DateTime`, `date()`, `strtotime`, `mktime`, `new Date()`, `Date.parse`, `datetime`, `time`. `new Date("2026-10-08")` is UTC midnight and `getDay()` is local: a different day west of Greenwich. PHP runs under whatever `date.timezone` the box has (tests run under three zones). |
| JSON numbers | `json_encode(66.0)` gives `66`; `json_decode` then returns an int and Python's `json` returns `int` for `66` and `float` for `66.0`. Cast every model number to float on the way in. PHP must run with `serialize_precision = -1` (shortest round-trip); with 17 it emits `0.10000000000000001`. Never encode `NaN` or infinities (the model cannot produce them from valid input; `json_encode` fails silently on them). |
| PHP float to string | `(string) $x`, string interpolation and array keys built from floats use `precision` (14 digits) and lose bits. Never build keys, hashes or SQL parameters from floats that way; the existing Database wrapper binds floats as 14-digit strings, so vectors go into MySQL as JSON text or DOUBLE via `json_encode`. |
| PHP array keys | Numeric strings become int keys (`"12"` to `12`) and iteration order is insertion order. Sort explicitly; compare ids with `strcmp`, never `<`, `sort()` default flags or `==` on numeric-looking strings. |
| String order | Byte-wise only: PHP `strcmp`, JavaScript `<` on strings (never `localeCompare`), Python `<`. Ids are ASCII. |
| Sorting | Always a full comparator with every tie-break listed. JavaScript's default `sort()` compares as strings; PHP `usort` is stable only from 8.0; do not depend on stability. |
| Lower-casing | Weather text: map only `A-Z` to `a-z`. PHP `strtolower` was locale-dependent before 8.2; JavaScript `toLowerCase` changes non-ASCII letters. |
| Empty maps | PHP encodes an empty array as `[]`; `overrides`, `spots`, `visits` and `data` must encode as `{}`. |
| Float equality | Only where the text does it (`f == 0.0` after the cutoff test, `r != 0`, the exclusion amount in `vectors_match`). All orderings go through `qkey`. |
| Float32 | Only the map fast path may use it (4.17). Vectors for spots, the reference and golden cases are binary64. Store per-point `rivals` and spot vectors as DOUBLE, never FLOAT or DECIMAL. |
| `min` / `max` | Two-argument forms on floats only; PHP `max()` on mixed int and string or on arrays compares differently. |
| Booleans in arithmetic | Never multiply by a boolean; branch. |
| Recursion depth and time | `suggest_week` is at most 279,936 leaves and depth 7; `suggest_day` at most 2,324 evaluations. Do not add early exits that change which equal-valued result is found first. |

---

## 8. Golden cases and anchors

### 8.1 File

`tests/fixtures/truck-planner/golden_cases.json`, written by the Python reference: `{ "model_version": "tps-0.1.0", "seeds_revision": 1, "cases": [ { "id": "g07-003", "family": "capture", "fn": "capture_at_point", "args": { ... named arguments ... }, "expect": <result> } ], "anchors": [ ... ] }`. `args` holds complete inputs (no reference to databases or files other than the seed file; `Assumptions.seeds` is implied and `overrides` and `region` are explicit). Runners compare `expect` with the result recursively using 1.4; object key order is irrelevant, array order is not. Each runtime must also assert that its copy of the seeds equals `tp_seeds.json` (parsed values, not file bytes: line endings differ between checkouts).

Encoding rules, so that three ports written apart produce the same `expect`:

1. The keys of `args` are exactly the parameter names of the function's signature in section 4; `A` is `{ overrides, region }`.
2. A tuple return is a JSON array in the written order: `[Estimate, spread]` for `interval` and `interval_capped`, `[truck_factor, spot_factor]` for `calibration_factor`, `[factor, dow, hour]` for `traffic_factor`, `[y, m, d]` for `civil_from_days`.
3. `leg_minutes` returns `from_id: null`, `to_id: null`, `depart_minute: null` and `traffic_lookup_minute` = the lookup minute.
4. `capture_at_point` returns `in_region: true`, `region_id: null`, `dataset_version: null` and `model_version: "tps-0.1.0"`.
5. `event_orders.hours` is `[ { day_index, hour, fraction, demand, capacity, weather, orders } ]`.
6. `catering_money.unit_margin` is `{ at_minimum: 0.0, at_percentage: 0.0 }`.
7. A plan that is not evaluated returns `stops: []`, the empty timeline, zero totals with confidence `fixed` and `unpaid_gap_alternative: null` (4.12).
8. `scout_estimate` with no window returns `best_window: null`, `orders` and `contribution` `{ 0.0, 0.0, 0.0, label of interval(A, 0.0, evidence_from(cal, null)) }`, `round_trip: { minutes: 0, miles: 0.0, cost: 0.0 }`, `host_size` and `kitchen` as resolved and `score: 0.0`. Every `scout_estimate` result has `position: 0`; `scout_rank` numbers them.
9. `anchors` is a top-level array `[ { id, case, path: "orders.value", min, max }, { id, case, path, greater_than_case } ]` whose entries point at ordinary `window_orders` cases (8.3).

**Margin rule.** The reference refuses to emit a case in which a comparison between two reals that selects a branch, a flag or a warning has `abs(a - b) <= 1e-6 * max(1.0, abs(a), abs(b))`, or in which the argument of `floor` in `round_half_away`, `qkey`, `score_byte` or the millimetre key is within 1e-6 of an integer; such a case is edited until it passes. Exact ties are tested only with inputs whose sums are equal by construction (the Sunday 16:00 and 17:00 windows of 4.15). Family g01 is exempt: it calls the rounding helpers directly with literal arguments, which involves exactly rounded operations only.

### 8.2 Families (310 cases)

| Family | Functions | Cases | Must include |
|---|---|---:|---|
| g01 rounding | `round_half_away`, `qkey` | 16 | the table in 1.4; negative values; 0; decimals 0 to 6 |
| g02 dates | `days_from_civil`, `civil_from_days`, `day_of_week`, `add_days` | 16 | 1970-01-01, 2000-02-29, 2100-02-28 to 03-01, 2199-12-31, year boundaries, negative `n` |
| g03 holidays | `federal_holidays`, `holiday_on` | 10 | years 2025-2030 against the OPM lists; observed date in the previous year; Inauguration Day on a Saturday, a Sunday and with the flag off |
| g04 day context | `day_context`, `typical_context` | 12 | ordinary weekday, Saturday, Sunday, major holiday, minor holiday, each `treat_as` kind, an override of `holiday_day_type` |
| g05 curves | `hour_weights`, `expand_curves` | 8 | all 168 hours for three segments; an overridden curve; an overridden `dow_factor` |
| g06 geometry | `haversine_m`, `walk_weight` | 10 | zero distance, the meridian points of 4.4, a pole-ward and an equatorial pair, the cutoff boundary |
| g07 rivals | `rivals_at_origin` | 6 | every rival kind, outlet beyond the cutoff, outlet at the origin |
| g08 capture | `capture_at_point`, `host_exclusion`, `host_link_point`, `vectors_match` | 22 | the 4.4 layout at three visibility levels; multi-segment points; both exclusion rules; exclusion larger than available; equidistant points (tie by id); empty sources; `within` in every result; `host_link_point`: the example of 4.4, a point beyond the radius, two points at the same millimetre distance (tie by id); an unlinked taproom host over a `v_nightlife` point 10 m away, which once linked must give the same vectors as the linked host; the `vectors_match` table of 4.7 |
| g09 host | `host_capture` | 10 | captive and open modes, with and without kitchen, each visibility, size 0, null host |
| g10 weather | `weather_multiplier` | 20 | every band boundary (19/20, 31/32, ..., 94/95), every precipitation class, priority conflicts, null fields, null record, the floor, upper-case and mixed-case text |
| g11 hourly orders | `hourly_orders` | 16 | anchors A1 and A2 hour by hour; host plus catchment; captive host in rain (two weather tables, both weather details); calibration factors; capacity cap with segment scaling; menu fit 0; weak and default-size parts; vectors with `within` null |
| g12 window orders | `window_orders` | 14 | whole hours, partial first and last hour, a window inside one hour, across midnight with two contexts, zero length, 24 hours, outside region; an hour that reaches capacity only at `k_high` (anchor A1); every hour over capacity |
| g13 week and windows | `week_strip`, `best_windows` | 10 | both anchors; circular wrap; exact ties; `allowed` mask; `top_n` larger than available; all zeros |
| g14 ranges | `interval`, `interval_capped`, `est_sum`, `est_levels` | 16 | the two tables in 4.8; each label boundary; mean 0 |
| g15 money | `stop_money`, `unit_margins`, `break_even_orders`, `day_costs` | 16 | each fee shape; tips on; negative unit margin (null break-even, low above high before `est_levels`); zero crew; zero generator |
| g16 driving | `fallback_leg`, `traffic_factor`, `leg_minutes` | 15 | the tables in 4.10; a `google` and a `fallback` leg at the same minute; both matrices; negative and next-day minutes; holiday `traffic_dow`; the night after a holiday (2026-11-26 at minute 1470: lookup `dow` 4); override; minimum of 1; same point |
| g17 timeline | `build_timeline`, `required_leg_keys` | 13 | the blueprint day sheet (exact integers); one stop; three stops; late arrival; unreachable stop (second stop 840-848 after the blueprint's first: `leave` 870, `generator_minutes` 230, `day_minutes` 327); unpaid gap; start before midnight; per-stop setup override; empty plan; `required_leg_keys` for two and for three stops |
| g18 day plan | `day_plan` | 12 | the worked day of 4.12 with every `adds` field and the unpaid-gap alternative; an event stop; a catering stop; overlapping stops (not evaluated); stale vectors; each warning code at least once across the family, with its `data` |
| g19 calibration | `calibrate`, `calibration_factor`, `accuracy_report` | 18 | the examples of 4.13; empty log; sold-out above and below; both ratio clamps (a ratio above 4 and one above 50); `predicted_raw` below 3 and below 0.5; a sold-out service above `m_a` but below `m_a + s_a`; zero actual; future-dated service; one spot only; all sold out |
| g20 events and catering | `event_orders`, `catering_money` | 8 | the examples of 4.14; one vendor; capacity-bound; weather; guarantee above and below per-head |
| g21 suggestions | `suggest_day`, `suggest_week` | 9 | the example of 4.15 (candidates, ranking, the tie); `allowed` hours; three stops; visit limit; day limit; no feasible plan; 30 lunch spots and one taproom, which must return a two-stop plan |
| g22 scouting | `scout_estimate`, `scout_rank`, `strip_from_rows` | 9 | the example of 4.16; a non-host type (`restaurant`); a place with zero vectors and size 0; a rank tie broken by id; `strip_from_rows` against `week_strip` for the three example places |
| g23 fast path | `map_weight_rows`, `cell_scores`, `score_byte` | 12 | the cell of 4.17; both regimes; capacity clamp; byte boundaries 0 and 255; a 1,000-cell block (TypeScript additionally with `Float32Array` at the looser tolerance) |
| g24 seeds and anchors | `seed`, `validate_overrides`, anchors | 12 | each error code of 2.2; a valid override changing an anchor; the three `window_orders` cases the `anchors` array points at (8.3) |

### 8.3 Sanity anchors

These are assertions on ranges, checked by every runtime in addition to the exact golden values; they are the `anchors` array of the golden file (8.1). If a seed change moves a result outside its range, the seed change is wrong (or the anchor is, and that is a decision for DECISIONS.md).

| Anchor | Setup | Must hold | tps-0.1.0 result |
|---|---|---|---|
| A1 office park | 4.4 layout: 2,000 `w_office` jobs in eight points 75-425 m north of the truck, two `quick` outlets 340 m and 360 m south; visibility `normal`; no host; default profile; no calibration; `day_context(A_dc, "2026-10-08", null, null, null, null)` (a Thursday; no forecast, so the weather factor is 1); window 660-840 | `45 <= orders.value <= 90` | 60.4938 (range 33.01 to 93.19, `rough`) |
| A2 taproom, Thursday | zero vectors; host `{ segment: "v_nightlife", size: 120, size_source: "owner", only_food: true }`; default profile; no calibration; the same `day_context`; window 1020-1200 | `30 <= orders.value <= 50` | 39.3840 (range 20.76 to 62.20, `rough`) |
| A2 taproom, Friday | the same with `day_context(A_dc, "2026-10-09", null, null, null, null)` | Friday `orders.value` > Thursday `orders.value` | 59.0760 |

The blueprint's own figures for comparable inputs are 66 (40 to 103), 39 (23 to 64) and 55 on a Friday. No tuned seed needed adjusting: `outside_option_a0` stays 1.6 and `captive_share` stays 0.75.

Sentences for the confidence labels (the wording belongs to the product document; the criteria are 4.8): `very_rough` - "a guess from generic assumptions; treat as a ranking only"; `rough` - "not yet checked against your own sales"; `fair` - "adjusted with your logged services"; `good` - "backed by your results at this spot"; `fixed` - "set by your terms, not estimated".

---

## 9. Decisions made here, and open issues

### 9.1 Choices other documents depend on

1. A location is 50 numbers: `capture.day[16]`, `capture.eve[16]`, `nearby[16]`, `rivals.day`, `rivals.eve`, in that order in the cell pack. Vectors the server computes for a point also carry `within[16]` (4.4); it is not one of the 50 numbers, so a store that keeps only those (the cell pack, `tp_places.host_vec`) gives back `within: null`.
2. `SourcePoint.rivals` is stored per source point at load time (two DOUBLE columns); source points need a stable string `id`; a place with a visitor segment has a source point whose id the spot's host can reference (`Host.point_id`); the backend derives it by the host link rule of 03_DATA.md 6.2, and `host_link_point` (4.4) covers a venue host that rule leaves without a link.
3. Spot vectors depend on visibility and on the host (exclusion): they are computed by the server and returned per visibility level; changing host size or link needs a server round trip. Until it returns, `vectors_match` is false and an estimate from the old vectors is stale (4.7).
4. `Host.size` is in the base unit of the host's segment (headcount in the busiest hour for venues, people employed for workplaces, residents for housing). The form labels must follow the segment group.
5. `DayContext` is per civil date; a plan evaluation needs the context of the date and of the next date. Fuel price is resolved by the backend and travels in the context.
6. `predicted_raw` is stored with each service log and recomputed when the model version, seed revision or region data version changes.
7. The golden file format of 8.1 and the family names of 8.2.
8. Warning codes with their `data` keys (4.12, `stale_vectors` among them), confidence labels (4.8), event types (4.14), `treat_as` values (4.1) and seed scopes (2.2) are fixed vocabularies.
9. `LegInput.source` is `google` or `fallback` and the two are scaled differently (4.10): the backend labels each leg truthfully, passes Google's traffic-unaware duration unchanged, and never stores a fallback as a Google leg.
10. `HourForecast.precip_prob` is passed as `null` when the weather service gives none; the backend must not turn it into 0 (the model then uses `weather.pop_when_missing`). `wind_mph` is the largest number in the wind text.
11. The pipeline reads `etl.cns04_weight`, `etl.cell_min_nearby` and `etl.cell_min_venue` from the seed file (03_DATA.md section 0.1); all three are `build` scope.
12. The backend resolves the leg keys of `required_leg_keys` (4.11) before `day_plan`, and every ordered pair among base and the spots before `suggest_day`.
13. Scouting takes its inputs from the data: `PlaceInput.vectors` from `tp_places.host_vec` and `PlaceInput.size_default` from `tp_places.size_default` (4.4, 4.16).
14. `TruckProfile.avoid_tolls` and `avoid_highways` are profile fields the model never reads; the backend turns them into Google route modifiers and into part of its leg cache key (4.10).

### 9.2 Refinements of DECISIONS.md (it remains binding; these need its next edit)

| DECISIONS | This document |
|---|---|
| Section 7.4: kernel constants are build-time | Visibility multipliers, the regime table and place-type defaults are build-time too; `host.*` shares are owner-overridable. |
| Section 7.4: three vectors describe a location | A fourth array, `within[16]`, travels with the vectors the server computes for a point; stored vectors keep 50 numbers (9.1 item 1). |
| Section 7.7: every count is a mean with an 80 % interval | Where capacity binds, the value is the orders at expected demand and the two percentiles are carried through the hourly cap (4.8). |
| Section 6: one "treat this day as" override | Values `normal`, `holiday` or a day of the week; holidays come in two classes. |
| Section 6: drive leg (permanent cache) | Google legs are kept for 30 days, as sections 0 and 9 of DECISIONS say; only the owner's corrections are permanent. |

### 9.3 Open issues

1. Terms of use: both traffic matrices are derived from TomTom's public Traffic Index pages. Someone must read TomTom's terms before they ship; until that is confirmed, or if they may not be used, the matrices ship as all 1.0 (and the typical values as 1.0) and only the owner's corrections adjust drive times (03_DATA.md 14).
2. Several seed sources were read through a summarising fetch tool (marked "summariser-read" in the seed notes and "S" in recon 10). Re-open them before any of those figures appears in product text.
3. The default generator burn (0.6 gal/h, a measured spec-sheet figure) lowers take-home relative to the blueprint's worked day, which shows driving fuel only. It is a profile field; set it to 0 for a truck on shore power.
4. Minor federal holidays keep office patterns (assumed). In the Washington region, where federal employers dominate some districts, this is probably too optimistic for those districts.
5. `farmers_market` and `stadium` have no hourly presence; scouting ranks them on catchment only. An attendance field on places would fix this and is a later layer.
6. `transit_station`, `campus`, `hospital`, `hotel`, `attraction` and `events_venue` default sizes are unsourced guesses; the data document may replace them with per-place sizes where OpenStreetMap or an agency file has them (the seed stays the fallback).
7. The first-leg traffic lookup uses an arrival-anchored departure guess (4.11); a leg whose true departure falls in the previous clock hour is looked up one hour late. Accepted for simplicity.
8. Calibration by daypart, real opening hours of rival outlets, a new-spot ramp and seasonality are deliberately absent (DECISIONS section 12 lists the first two as later layers).
9. Google legs: the assumption that Google's traffic-unaware duration equals the all-hours mean of the TomTom table (`traffic.*_typical`) is unverified. A dozen owner-timed drives, or a comparison of Google's traffic-aware and traffic-unaware durations for a few legs, would settle it; until then drive minutes may be off by a constant factor of up to about 7 % (the table's daytime mean is 1.352 against the all-hours 1.265).
10. Stored spot vectors are 50 numbers per visibility level (04_BACKEND.md), so a saved spot comes back with `within: null`: its breakdown shows the distance-weighted figure in step 1 of section 5 and no share in step 3 until the store also keeps the 16 `within` values.
