# Truck Planner - 05 Frontend specification

Binding inputs, in order of precedence: [DECISIONS.md](DECISIONS.md) (section 0 "Google Maps only" first, then sections 4, 5, 10), [04_BACKEND.md](04_BACKEND.md) for every route, request and response, [02_MODEL.md](02_MODEL.md) for model shapes and function names, [03_DATA.md](03_DATA.md) for the cell pack and the attribution strings. Where this file disagrees with one of them, that file wins and the difference is a defect here.

Written 2026-10-04 against branch `truck-planner` (React 18.3, react-router-dom 6.30 with plain `<Routes>`, TanStack Query 5, Zustand 4.5, Tailwind 4 configured in CSS, Vite 5 with `base: '/app/'`, Vitest 1.6 in a Node environment, `@react-google-maps/api` 2.20 on a raster Google map with no map id). Paths are relative to `frontend/src/` unless they start with `frontend/` or `tests/`.

Names. `TruckRecord`, `TruckProfileX`, `AssumptionsInfo`, `RegionInfo`, `FuelInfo`, `Located`, `OutletRow`, `HostHint`, `Spot`, `DriveLeg`, `PlanStop`, `Plan`, `ServiceLog`, `DayInfo`, `Lead` and `ScoutCandidate` are the shapes of 04_BACKEND 4.1; every other shape name is from 02_MODEL section 3. "Route 18" means row 18 of the route table in 04_BACKEND section 3. Tags: **[A]** assumption made here; **[M]** from memory, confirm before relying on it.

---

## 0. Ground rules

### 0.1 Rules every package follows

| # | Rule |
|---|---|
| R1 | The only map library is the Google Maps JavaScript API already loaded through `useJsApiLoader` with `GOOGLE_MAPS_LIBRARIES` from `utils/mapsLoader.ts`. No Leaflet, Mapbox, MapLibre, deck.gl or any other map, chart or geometry package. The only new dependency is `h3-js` 4.5.0 (exact pin, same version as the pipeline). |
| R2 | No `navigator.geolocation`, `watchPosition`, `getCurrentPosition` or Permissions API in truck code or its import closure. Places come from address search, typed coordinates or a map click. |
| R3 | No AI at runtime: no LLM or ML host, SDK, key name or first-party AI endpoint in truck code or its import closure. Copy never says "AI", "smart" or "magic". |
| R4 | Every estimate is rendered by `RangeValue` from an `Estimate` (`value`, `low`, `high`, `confidence`), with a confidence chip and a way to open "Why this number". A bare `number` is never shown as an estimate. |
| R5 | The app never states or implies that a spot may be used. Banned wording and the standing notice are in section 6. |
| R6 | All truck network I/O goes through `api` from `api/client.ts` to `/api/truck/...` (plus the existing `usageApi.logMapLoad()` and the existing address widget). Picking a suggestion in the address widget is a Google Places call made by the widget itself and is not metered by the app. No `fetch`, `XMLHttpRequest`, `WebSocket`, `EventSource` or `sendBeacon` in truck code. |
| R7 | Model maths is never re-implemented in a screen. Screens call the estimator through `utils/truck/model.ts` (2.5). |
| R8 | No `new Date`, `Date.now`, `Intl`, `toLocale*`, `toISOString`, `toFixed`, `Math.round` or `Math.random` in truck code. Exceptions: `utils/truck/clock.ts` (the one place that reads the clock and applies the truck's time zone), and `Math.round` for pixel snapping inside the map engine. Formatting is hand-rolled, fixed `en-US`. |
| R9 | Only relative imports (tsconfig `baseUrl` has no Vite alias). Never import the `components/carafe` barrel: it reaches `api/restaurants.ts` (an AI endpoint) and would fail the closure guard. Import `components/carafe/CarafeSkeleton` by path if needed. |
| R10 | Server data lives in TanStack Query. Zustand holds UI state only. Colours come from CSS variables; surfaces use the `bg-white` class, never inline `background: 'white'`; no Tailwind `dark:` classes. |
| R11 | Money is dollars (decimal) in every API payload and in the model. Times of day are integer minutes from local midnight of the service date. Dates are `YYYY-MM-DD`. `how = dow * 24 + hour`, `dow` 0 = Monday. |

### 0.2 What is computed where

| Shown on screen | Computed by | From |
|---|---|---|
| Map colours | browser: `fastPath.ts` bulk scorer | cell pack + `profile.daypart_fit` + `profile.capacity_orders_per_hour` + truck factor |
| Spot card, spot detail, compare: week strip, best windows, hour and window orders, who is here, money, break-even | browser: `weekStrip`, `bestWindows`, `windowOrders`, `hourlyOrders`, `stopMoney`, `dayPlan` | exact `LocationVectors` from `simulate` or from the `Spot` (never from the pack) |
| Planner, Today's plan, Week's planned days, day sheet, calendar file | browser: `dayPlan` | `Plan` stops + `Spot`s + `day-context` + `drive-times` + `CalibrationState` |
| Log: the estimate shown before a service is saved | the saved plan's stored figure (`result.stops[i].orders`) when the service comes from a planned stop whose window matches; else browser: `windowOrders` (4.7) | `getPlan`; else the `Spot`, `day-context` and a calibration from the services logged before that date |
| Suggested days and weeks | server (PHP estimator) | shown as returned: `Suggestion`, `WeekSuggestion` |
| Scout ranks and estimates | server | shown as returned: `ScoutResult` inside `ScoutCandidate` |
| The prediction kept with a logged service, accuracy, calibration factors | server | `ServiceLog.prediction`, `AccuracyReport`, `CalibrationState` |
| The data export | server | route 42 |

The two runtimes agree because both pass the same golden cases. The server also returns numbers the screens do not print: `simulate.estimate` and the `result` snapshot of a saved `Plan` (one exception: the Log shows a planned stop's orders from that snapshot, 4.7, because that is the figure the server stores with the service). In development builds only they are used as a drift check: after `simulate` the local `weekStrip` is compared with `estimate.week_strip`, and after "Save day" the local `totals.take_home` and every `stops[i].adds.take_home` with those of `plan.result`, all with the model tolerance (relative 1e-9); a mismatch logs `console.warn('[truck] estimator drift', ...)` and never changes what the owner sees.

### 0.3 Estimator port (given)

`utils/truck/estimator/` is built by another engineer and is treated as given: `types.ts` (every JSON shape of 02_MODEL section 3 as an exported type, PascalCase type names, snake_case fields), `index.ts` (every catalogue function in camelCase: `dayContext`, `typicalContext`, `hourlyOrders`, `windowOrders`, `weekStrip`, `bestWindows`, `stopMoney`, `unitMargins`, `breakEvenOrders`, `dayPlan`, `buildTimeline`, `fallbackLeg`, `eventOrders`, `cateringMoney`, `hostExclusion`, `validateOverrides`, `seed`, `holidayOn`, `dayOfWeek`, `addDays`, `parseDate`, `roundHalfAway`, `qkey`, `estSum`, `accuracyReport`, ...), `fastPath.ts` (`mapWeightRows`, the bulk cell scorer, `scoreByte` and the week-wide colour domain) and `seeds.generated.ts`. Its tests are `utils/truck/__tests__/estimator.*.test.ts`. No package in section 9 edits that directory.

Besides the catalogue, `index.ts` exports `SEEDS` (the seed copy, typed `SeedFile` and frozen all the way down: a write to it throws, so work on a copy to try other values), `MODEL_VERSION`, `SEEDS_REVISION`, `makeAssumptions(overrides, region)`, `REGION_NONE`, the vocabulary lists in index order (`SEGMENTS`, `REGIMES`, `RIVAL_KINDS`, `DAY_TYPES`, `DOW_KEYS`, `DAYPARTS`, `VISIBILITY_LEVELS`, `CONFIDENCE_LABELS`, `PLACE_TYPES`, `WARNING_CODES`), `ModelError` (thrown with `code` `invalid_date`, `invalid_window` or `missing_context`), the helpers `clockHours`, `dayNumber`, `dateOfDay`, `floorDiv`, `modFloor` and `clamp`, and everything `fastPath.ts` exports. A tuple of the model is an array: `interval` returns `[Estimate, Spread]`, `trafficFactor` `[factor, dow, hour]`, `parseDate` `[y, m, d]`. `Warning` is a union over the 21 codes, so `code` selects the type of `data`. Where the reference stops and JavaScript arithmetic would carry on, the port stops too: `dayCosts`, and with it `evaluate` and `dayPlan`, throw a `TypeError` when the fuel price is not a number (a missing price would otherwise count as 0) and a `RangeError` when `profile.mpg` is 0; `scoreByte` throws a `RangeError` for `hi` 0.

`fastPath.ts` scores cells straight out of `CellPack.features` (a row-major `Float32Array`, 50 numbers per cell in pack column order) into buffers the caller owns. No call allocates.

| Export | Signature | What it gives |
|---|---|---|
| `precomputeMapWeights` | `(A, profile, cal, ctx: DayContext \| null = null): MapWeightTable` | The 168 per-hour weight rows: `{ wOpp: Float64Array(168 * 16), wPeople: Float64Array(168 * 16), eve: Uint8Array(168) }`, the row of hour `how` starting at `how * 16`, `eve[how]` 1 in the evening regime. Without `ctx`: exactly the numbers of `mapWeightRows`. With the context of a date: the 24 rows of `ctx.dow` are built from that context (02_MODEL 4.17) and the other 144 stay typical, so a row is found at `how = dow * 24 + hour` in both cases. The table depends on `A`, `profile.daypart_fit`, the truck factor and `ctx` only |
| `scoreLayer` | `(layer, features, n, table, how, capacity, out: Float32Array): void` | One layer: `out[c]` is the score of cell `c` (`out.length >= n`). `capacity` is `profile.capacity_orders_per_hour`: it caps opportunity and is not read for the other two layers |
| `scoreCells` | `(features, n, table, how, capacity, out: Float32Array): void` | All three layers as planes of `out` (`out.length >= 3 * n`): opportunity at `[0, n)`, people at `[n, 2n)`, competition at `[2n, 3n)` |
| `scoreCellsWithRows` | `(features, n, wOppRow, wPeopleRow, regime, capacity, out: Float32Array): void` | The same three planes from explicit rows: the typed form of `cellScores` |
| `scoresToBytes` | `(scores, n, hi, out: Uint8Array, minByte = 0, offset = 0): void` | `out[c] = scoreByte(scores[offset + c], hi)`, or 0 when that byte is below `minByte` |
| `MAP_DOMAIN`, `MAP_LAYERS` | `{ opportunity: 45, people: 20000, competition: 100 }`, `['opportunity', 'people', 'competition']` | The fixed `hi` of each layer (seeds `map.*_hi`) and the layer ids (type `MapLayer`) |
| `FEATURE_COLUMNS`, `FEATURES_PER_CELL`, `COL_CAPTURE_DAY`, `COL_CAPTURE_EVE`, `COL_NEARBY`, `COL_RIVALS_DAY`, `COL_RIVALS_EVE`, `HOURS_PER_WEEK` | constants | The 50 column names in pack order; 50; 0, 16, 32, 48, 49; 168 |
| `mapWeightRows`, `dayWeightRows`, `cellScores`, `scoreByte` | as in 02_MODEL 4.17 | The plain-array definition the golden cases run. `dayWeightRows(A, profile, cal, ctx)` is the 24 rows of one context |

`how` outside 0..167 or a buffer that is too short throws a `RangeError`. For the same feature values the scores equal `cellScores` to a relative 1e-5 and the bytes to plus or minus 1 (02_MODEL 4.17); `estimator.fastpath.test.ts` holds that and logs the time. Measured in Node on the development machine (medians over repeated runs, the machine shared with other work): 10,000 cells on three layers in 0.2 to 0.3 ms, 60,000 cells in 1.3 to 3 ms; one layer of 10,000 cells with its bytes in about 0.2 ms.

---

## 1. Information architecture

### 1.1 Routes

All under `/truck`, all inside `ProtectedRoute`. Sub-navigation order is fixed: Today, Map, Spots, Planner, Week, Log, Scout, Settings.

| Path | Page (export of `TruckPages.ts`) | Tab | Wave |
|---|---|---|:---:|
| `/truck` | `TodayPage` | Today | 2 (starter page in 1) |
| `/truck/map` | `MapPage` | Map | 1 |
| `/truck/spots` | `SpotsPage` | Spots | 1 |
| `/truck/spots/compare` | `SpotComparePage` | Spots | 1 |
| `/truck/spots/:spotId` | `SpotDetailPage` | Spots | 1 |
| `/truck/plan` | `PlanIndexRedirect` -> `/truck/plan/<today>` | Planner | 2 |
| `/truck/plan/:date` | `PlannerPage` | Planner | 2 |
| `/truck/plan/:date/sheet` | `DaySheetPage` (print-styled) | Planner | 3 |
| `/truck/week` | `WeekIndexRedirect` -> `/truck/week/<Monday of this week>` | Week | 2 |
| `/truck/week/:weekStart` | `WeekPage` | Week | 2 |
| `/truck/log` | `LogPage` | Log | 2 |
| `/truck/scout` | `ScoutPage` | Scout | 3 |
| `/truck/settings` | `<Navigate to="/truck/settings/truck" replace />` | Settings | 1 |
| `/truck/settings/:tab` | `SettingsPage`, `tab` = `truck`, `assumptions`, `data` | Settings | 1 (`data` in 3) |
| `/truck/*` | `<Navigate to="/truck" replace />` | | |

Param validation, done by the page before anything else: `:date` must pass `parseDate` (1970-01-01 .. 2199-12-31), else redirect to `/truck/plan/<today>` (the planner also sends 2199-12-31 there: a day is evaluated with the context of the day after it, which the model's calendar does not have for its last date); `:weekStart` must be a Monday (`dayOfWeek(d) === 0`), else redirect to the Monday of that week (`addDays(d, -dayOfWeek(d))`); `:tab` outside the list redirects to `truck`; an unknown `:spotId` shows the not-found state of 4.4. "Today" always means the civil date in the truck's time zone (2.7). There is one plan per date; the Planner is addressed by date.

### 1.2 Query parameters and deep links

Read with `useSearchParams`. Writes use `{ replace: true }`. Values that change continuously (camera, hour) are written at most every 300 ms and never while the week is playing.

| Page | Param | Meaning | Default |
|---|---|---|---|
| Map | `lat`, `lng`, `z` | camera centre (6 decimals) and zoom (one decimal, 8 to 19) | the place a `pt` or a `spot` of the same link names, at zoom 14; else the persisted camera; else the base, else the region centre, at zoom 12 |
| Map | `how` | hour of week 0..167 | last used, else the current hour in the truck's time zone |
| Map | `layer` | `opportunity`, `people`, `competition` | last used, `opportunity` at first |
| Map | `pt=lat,lng` | open the spot card at this point (written with six decimals each) | none |
| Map | `spot=<id>` | open the spot card for a saved spot and centre on it; it wins over a `pt` in the same link | none |
| Map | `pick=base` or `pick=spot` | pick mode: the next click sets the base or starts "Add spot"; `return=<path>` says where to go afterwards and is taken only when it is a path under `/truck` | none |
| Map | `scout=1` | show Scout result dots (wave 3) | off |
| Map | `tp_basemap=blank`, `tp_perf=1` | test and measurement switches (5.6, 5.9) | off |
| Spots | `q`, `sort` (`best`, `name`), `new=1` (open "Add a spot") | list state | `sort=best` |
| Spot compare | `ids=a,b,c` (2..4), `win` (`best` or `dow-open-close`, for example `3-660-840`) | what to compare | `win=best` |
| Planner | `add=<spotId>` (add that spot once, placed by its window as "Add stop" places it in 4.5, then drop the three params; an id that is not a saved spot adds nothing and says "That spot is not in your list."), `open`, `close` (minutes, used with `add`; without a valid pair the stop gets the default window of 4.5), `suggest=1` | prefill | none |
| Log | `tab` (`services`, `accuracy`), `new=1`, `spot`, `date`, `open`, `close`, `stop` (a plan stop id) | prefill the quick entry | `tab=services` |
| Scout | `hide` (comma list of lead statuses, sent to the server), and the client-side filters `type` (comma list of place types), `county` (comma list of FIPS), `kitchen` (`any`, `no`), `contact` (`any`, `has`) | filters | `hide=declined,hidden`, the rest open |

On the map page the URL mirrors the two stores of 2.4. The layer, the hour and the camera are read from it once, when the page mounts; a value a link names is then the value last used (it is written to `truckUiStore` like a choice made by hand, which the current hour, taken when nothing names one, is not). `pt`, `spot` and `pick` are read on every render: which card is open and whether a pick is in progress is what the URL says. A value that is not valid counts as absent. A write keeps the parameters it does not own (`tp_basemap`, `tp_perf`) and never reaches the URL of another page. `readMapParams`, `writeMapParams` and `initialCamera` in `utils/truck/hourControl.ts` hold these rules.

Known limitation, not fixed here: `ProtectedRoute` does not remember the requested URL, so a logged-out deep link lands on `/truck` after sign-in.

### 1.3 Layout and sub-navigation

`components/truck/TruckLayout.tsx` is eager (it is in the main bundle so the top nav and sub-nav paint at once and `AppNav` is not remounted). Structure, top to bottom:

1. Root `div`: `min-h-screen flex flex-col`, or `h-dvh flex flex-col` when the section is `map`. Background `var(--bg)`.
2. `<AppNav />`, exactly once, no children.
3. Sub-nav: `<nav aria-label="Truck Planner sections" className="sticky top-12 z-20 border-b bg-white scroll-x overflow-x-auto">` with border colour `var(--nav-border)`, holding `<ul className="max-w-7xl mx-auto px-2 md:px-6 py-1.5 flex items-center gap-1 whitespace-nowrap">` of `NavLink`s: `inline-flex items-center gap-1.5 h-11 md:h-9 px-3.5 md:px-3 rounded-lg text-[13px] font-semibold`, active `background: var(--nav-active-bg); color: var(--nav-active-fg)`, inactive `color: var(--nav-text)`. Icon 14 px, `aria-hidden`. On a route change the active link is scrolled into view (`scrollIntoView({ inline: 'center', block: 'nearest' })`), and again whenever the rail or a tab changes size (a `ResizeObserver`: the web font arriving widens the tabs after the first paint, and a phone can be turned). At 375 px the rail scrolls sideways; nothing wraps.
4. `<main id="main-content" tabIndex={-1} className="flex-1 focus:outline-none">` (plus `relative min-h-0` for `map`), containing `<ErrorBoundary key={section} scope="Truck Planner" inline><Suspense fallback={...}><Outlet /></Suspense></ErrorBoundary>`. Every section except `map` is wrapped in `<div className="carafe-route-fade max-w-7xl mx-auto px-4 md:px-6 py-4 md:py-6">`. The class `carafe-route-fade` is dropped when its animation ends (`onAnimationEnd`): its last keyframe leaves `transform: translateY(0)` on the element, and a transformed element is the containing block of every `position: fixed` descendant, which would pin the phone planner's bottom bar to the end of the page content instead of the screen.

| Tab | `to` | lucide icon | `end` |
|---|---|---|:---:|
| Today | `/truck` | `CalendarDays` | yes |
| Map | `/truck/map` | `MapPinned` | |
| Spots | `/truck/spots` | `Store` | |
| Planner | `/truck/plan` | `Route` | |
| Week | `/truck/week` | `CalendarRange` | |
| Log | `/truck/log` | `NotebookPen` | |
| Scout | `/truck/scout` | `Compass` | |
| Settings | `/truck/settings` | `Settings2` | |

(All eight icon names exist in the installed lucide-react 0.408.) `section = pathname.split('/')[2] || 'today'`. `document.title` is set to `Truck Planner` while the layout is mounted and restored on unmount. The Suspense fallback is built from plain `.skeleton` blocks (one 96 px card and three 72 px rows, `aria-busy="true"`); for `map` it is a centred "Loading map..." line (the words of `MAP_TEXT.loading` in `wording.ts`, written out in this file, which may import no truck module: 1.7). The existing `ErrorBoundary` class must be the boundary: it is the only code that recovers from a stale lazy chunk after a deploy.

### 1.4 The gate and the first-run step

`TruckGate` (lazy, a pathless layout route) wraps every page. It runs the bootstrap query (route 1) and renders:

| State | Renders |
|---|---|
| Loading | the page-shaped skeleton |
| Request failed | `QueryError` with the server's sentence when there is one (403: no workspace), else "Could not load Truck Planner.", and "Try again" |
| `model_version` or `seeds_revision` in the answer differs from the estimator port's | a card: heading "This page is out of date", text "Truck Planner was updated. Reload to get the current version.", button "Reload" (`location.reload()`). No page renders, because browser and server numbers would disagree |
| `has_truck` is false | `SetupTruck` in place of the page, on every `/truck` route |
| Ready | `<TruckContext.Provider value={...}><Outlet /></TruckContext.Provider>`. When `region.unusable_reason` is `build_mismatch`, a strip above the page (`role="status"`): "Map data is being rebuilt after an update. New estimates are unavailable until it finishes." |

`TruckContext` (read with `useTruck()`) is what every page builds on: `{ truck: TruckRecord, profile: TruckProfileX, A: Assumptions, cal: CalibrationState, region: RegionInfo | null, fuel: FuelInfo, timezone: string, counts, routing: { state }, limits }`, all taken from the bootstrap answer. `A` is assembled in the browser from the port's seeds plus the answer's `assumptions` (2.5). `region` is null when the truck's region is `none`; a region with `usable: false` is treated as having no map data. While its `unusable_reason` is `build_mismatch` (the region data was built with other model constants and is being rebuilt) the strip of the table above shows on every page, and `simulate`, `fetchPack` and `refreshStaleSpots` are not sent; saved spots keep evaluating from their stored vectors (rule 5 of 2.5). Any later 409 "Set up your truck first" (the truck was deleted in another tab) invalidates the bootstrap query, which brings the first-run step back. The gate does this in one place for every request: it listens to the query cache and the mutation cache and reads the bootstrap answer again when a truck query or any mutation fails with that 409 or with the 409 `Region data was built with different model constants` (2.6).

The gate's own element carries the chunk sentinel as `data-tp-chunk` (8.4). On the `map` section, where the layout gives no page frame, that element fills the area under the sub-nav (`absolute inset-0 flex flex-col`): the strip sits above the page and the page gets a positioned box of what is left (`relative flex-1 min-h-0`), which is the box `MapPage` fills. The gate's other states (request failed, out of date, first run) are shown inside the page frame on every section, the map included.

**"Set up your truck"** (`SetupTruck.tsx`). One card, max width 560 px, centred. It does not use the existing onboarding-flags mechanism.

| Element | Text or behaviour |
|---|---|
| Heading | "Set up your truck" |
| Intro | "Three things to start. You can change them, and everything else, in Settings." |
| Field 1 | Label "Truck name". Text, 1..120 characters, required |
| Field 2 | Label "Where the truck starts and ends its day". `GooglePlaceAutocomplete` with the truck props of 1.6 (`types={[]}`, `countries={['us']}`, the four `fields`, `unavailableText`), placeholder "Search an address". Under it a disclosure "Enter coordinates instead" with one field, label "Latitude, longitude", placeholder "38.9696, -77.3861"; text that `parseCoords` cannot read shows "Enter latitude and longitude, like 38.9696, -77.3861." Coordinates, once typed, are the base (picking an address clears them; closing the disclosure clears them too). If Google is unavailable or has not loaded yet (`useAddressSearchAvailable`, 1.6) only the coordinates field shows. The label is the label of the group: the address widget owns its input. Helper: "This is your base: a commissary, a lot or your driveway. It is only used for drive times and weather." |
| Region check | A hint before saving, from the boxes alone. If the point is inside no `bbox` of the answer's `regions`: warning line "This is outside the area we have data for ({names of the regions}). You can save it, but the map and the estimates will be empty." (without the bracket when `regions` is empty). Saving stays possible. The box is not the membership test: that is the server's nearest-block lookup (04_BACKEND 5.1), whose verdict comes back with the save as the warning `base_outside_region` |
| Field 3 | `MoneyField`, label "Average ticket", helper "What one order comes to on average, before tax and tips.", default from seed `profile_defaults.avg_ticket` (15.00), range 1 to 200 |
| Button | Primary "Save and continue". Disabled until all three are valid. It sends route 3 (`PUT /api/truck/profile`) with `name`, `base: { lat, lng, address }` and `avg_ticket`; the server fills every other field from the defaults. Enter in a field commits that field and does not save (a browser would otherwise submit the form before the field's value has arrived), and while a field shows its own refusal (a ticket outside 1 to 200) the save is held back with "Check the marked fields first. Nothing was saved.", so what is saved is always what is on screen. On 201 the bootstrap query is refetched and the requested page renders. The warnings of the answer are shown as toasts kept for eight seconds, because by then the first-run step has made way for the page: `timezone_assumed` "We assumed Eastern time for this truck."; `base_outside_region` "Your base is outside the counties we have data for. Nothing can be estimated near it; the rest of the map works." The server sends the second one for a base inside the box of a region but outside its counties (04_BACKEND 4.4): the truck then has that region and its map, so the out-of-area line of the region check, which is about a base in no region at all, would be wrong there. Both sentences are `PROFILE_WARNING_TEXT` in `wording.ts`; "Set base" on the map (4.2) and Settings (4.9) show the same toasts |
| Footnote | "Truck Planner never tracks your location. The only places it knows are the ones you enter." |

### 1.5 `App.tsx`

Three edits, nothing else:

```tsx
import { lazy, useEffect } from 'react';                 // was: import { useEffect } from 'react';
import TruckLayout from './components/truck/TruckLayout';

// Truck Planner: ONE import() target, so the build emits one chunk (TruckPages-<hash>.js).
const loadTruck = () => import('./components/truck/TruckPages');
const TruckGate        = lazy(() => loadTruck().then((m) => ({ default: m.TruckGate })));
const TruckToday       = lazy(() => loadTruck().then((m) => ({ default: m.TodayPage })));
const TruckMap         = lazy(() => loadTruck().then((m) => ({ default: m.MapPage })));
const TruckSpots       = lazy(() => loadTruck().then((m) => ({ default: m.SpotsPage })));
const TruckSpotCompare = lazy(() => loadTruck().then((m) => ({ default: m.SpotComparePage })));
const TruckSpotDetail  = lazy(() => loadTruck().then((m) => ({ default: m.SpotDetailPage })));
const TruckPlanIndex   = lazy(() => loadTruck().then((m) => ({ default: m.PlanIndexRedirect })));
const TruckPlanner     = lazy(() => loadTruck().then((m) => ({ default: m.PlannerPage })));
const TruckDaySheet    = lazy(() => loadTruck().then((m) => ({ default: m.DaySheetPage })));
const TruckWeekIndex   = lazy(() => loadTruck().then((m) => ({ default: m.WeekIndexRedirect })));
const TruckWeek        = lazy(() => loadTruck().then((m) => ({ default: m.WeekPage })));
const TruckLog         = lazy(() => loadTruck().then((m) => ({ default: m.LogPage })));
const TruckScout       = lazy(() => loadTruck().then((m) => ({ default: m.ScoutPage })));
const TruckSettings    = lazy(() => loadTruck().then((m) => ({ default: m.SettingsPage })));
```

```tsx
<Route path="/" element={isAuthed ? <Navigate to="/truck" replace /> : <HomePage />} />   {/* was /dashboard */}

{/* Truck Planner: own layout and sub-nav; the gate and all pages load as one lazy chunk */}
<Route path="/truck" element={<ProtectedRoute><TruckLayout /></ProtectedRoute>}>
  <Route element={<TruckGate />}>
    <Route index                   element={<TruckToday />} />
    <Route path="map"              element={<TruckMap />} />
    <Route path="spots"            element={<TruckSpots />} />
    <Route path="spots/compare"    element={<TruckSpotCompare />} />
    <Route path="spots/:spotId"    element={<TruckSpotDetail />} />
    <Route path="plan"             element={<TruckPlanIndex />} />
    <Route path="plan/:date"       element={<TruckPlanner />} />
    <Route path="plan/:date/sheet" element={<TruckDaySheet />} />
    <Route path="week"             element={<TruckWeekIndex />} />
    <Route path="week/:weekStart"  element={<TruckWeek />} />
    <Route path="log"              element={<TruckLog />} />
    <Route path="scout"            element={<TruckScout />} />
    <Route path="settings"         element={<Navigate to="/truck/settings/truck" replace />} />
    <Route path="settings/:tab"    element={<TruckSettings />} />
    <Route path="*"                element={<Navigate to="/truck" replace />} />
  </Route>
</Route>
```

The block goes directly after the `/` route. React Router 6 ranks by specificity, so `spots/compare` wins over `spots/:spotId` wherever it is written. No server change is needed: Apache and the root `nginx.conf` already serve `app/index.html` for any non-file path. `LoginPage` and `RegisterPage` already navigate to `/`, so they need no edit.

### 1.6 `AppNav.tsx`, `CommandPalette.tsx`, `ProtectedRoute.tsx` and `GooglePlaceAutocomplete.tsx`

`AppNav.tsx`: add `Truck` to the lucide import and insert `{ to: '/truck', label: 'Truck', icon: Truck }` at index 0 of `ITEMS` (no `end`, so every `/truck/*` route keeps it lit). The four existing items stay in their order. Add one quick-create entry after "New project": `<CreateLink to="/truck/spots?new=1" icon={<Truck size={13} />} label="New spot" onPick={() => setCreateOpen(false)} />`. The brand link stays as it is.

`CommandPalette.tsx`: add `Truck` to its lucide import and push this block at the very top of the `items` memo (before the restaurant loop, so the entries survive the 24-item cap). Route strings only.

| Label | Sub | href | Keywords |
|---|---|---|---|
| Truck: Today | next stop, take-home | `/truck` | truck planner today |
| Truck: Map | who is where, by hour | `/truck/map` | truck map hour demand |
| Truck: Spots | saved spots | `/truck/spots` | truck spots saved |
| Truck: Plan a day | stops, drive times, costs | `/truck/plan` | truck plan day route |
| Truck: Week | seven days | `/truck/week` | truck week |
| Truck: Log a service | actual orders | `/truck/log?new=1` | truck log orders |
| Truck: Scout | places that could host a truck | `/truck/scout` | truck scout hosts |
| Truck: Settings | truck and costs | `/truck/settings/truck` | truck settings costs |

Each is `{ kind: 'nav', id: href, label, sub, icon: Truck, group: 'Truck Planner', keywords, run: () => navigate(href) }`.

`components/auth/ProtectedRoute.tsx`: every `/truck` route sits inside it, and while the user is loading it shows the shared loading screen (a gradient logo tile, a progress bar and "Loading your projects"). One edit: while the user is loading and `useLocation().pathname` starts with `/truck`, it renders the truck skeleton of 1.3 inline instead (`min-h-screen` on `var(--bg)`, two empty bars where the top nav and the sub-nav will be so that nothing jumps when they arrive, one 96 px `.skeleton` card and three 72 px rows in the page frame, `aria-busy="true"`): no logo tile, no progress bar, no text and no import from truck code (1.7). The path test is `/truck` itself or anything under `/truck/`. Every other path keeps today's screen.

`components/common/GooglePlaceAutocomplete.tsx`: behaviour is unchanged for existing callers. Three edits: an optional prop `fields?: string[]` (default: today's list, `['place_id', 'name', 'formatted_address', 'geometry', 'international_phone_number', 'website']`), an optional prop `unavailableText?: string` (default: today's sentence), and `types` is passed to Google only when the array is not empty. Truck call sites (1.4, 4.4, 4.9 and any other address field in truck code) pass `types={[]}`, `countries={['us']}`, `fields={['place_id', 'name', 'formatted_address', 'geometry']}` and `unavailableText="Address search is unavailable. Enter coordinates instead."`, so street addresses are suggested (with today's default `['establishment']` a lot or a driveway is not) and no phone or website field is requested.

`components/truck/data/useMapsLoader.ts` exports `useTruckMapsLoader()` = `useJsApiLoader({ googleMapsApiKey: (import.meta as any).env?.VITE_GOOGLE_MAPS_API_KEY ?? '', libraries: GOOGLE_MAPS_LIBRARIES })` with exactly these two options: every `useJsApiLoader` call on a page must pass identical options or the loader throws, and the widget calls it with the same two. `TruckMap`, `SetupTruck` and `SpotForm` load Google only through it and render the address widget only when `isLoaded && !loadError && !mapsAuthFailed` (`truckUiStore.mapsAuthFailed`, 5.6); otherwise the address field is left out and the coordinates field remains. The same file exports that test as `useAddressSearchAvailable()`.

### 1.7 What stays out of the eager import graph

The main chunk (`index-*.js`, 906 kB today) may contain exactly these truck-related things: `TruckLayout.tsx`, the `/truck` strings in `AppNav.tsx` and `CommandPalette.tsx`, the `/truck` path test and the inline skeleton in `ProtectedRoute.tsx`, and the `lazy()` wrappers in `App.tsx`.

| File | May import | Must not import (statically) |
|---|---|---|
| `App.tsx` | `./components/truck/TruckLayout`; `import('./components/truck/TruckPages')` | anything else under `components/truck/`, `utils/truck/`, `api/truck`, `stores/truck*` |
| `AppNav.tsx`, `CommandPalette.tsx`, `ProtectedRoute.tsx`, `GooglePlaceAutocomplete.tsx` | nothing from truck code | all of it |
| `TruckLayout.tsx` | `react`, `react-router-dom`, `lucide-react`, `../layout/AppNav`, `../ErrorBoundary` | `./TruckPages`, `./TruckGate`, `./pages/*`, `./ui/*`, `./map/*`, `./data/*`, `./truck.css`, `../../api/truck`, `../../stores/truck*`, `../../utils/truck/*`, `h3-js`, the `../carafe` barrel |

`TruckPages.ts` is the lazy boundary: it re-exports `TruckGate` and every page except the map, imports `./truck.css` and `./print.css` (so truck CSS ships with the chunk; both files are plain CSS with no Tailwind directives and no `@apply`), and imports `./map/authFailure` for its side effect (5.6). `h3-js`, the estimator, the seeds, the map engine, the truck stores and `api/truck.ts` are reachable only through it. The map page is a second `import()` made inside `TruckPages.ts` (its export `MapPage` is a small component around `lazy(() => import('./pages/MapPage'))`, which suspends into the layout's map-shaped placeholder), so the map engine and `h3-js` are a chunk of their own, `MapPage-<hash>.js`, fetched when the map is opened: Today, the planner and the log, which are opened on a phone, do not wait for it [R: build of 2026-10-07, one chunk was 274.7 kB gzip; split, `TruckPages` is 183.5 kB and `MapPage` 91.5 kB]. Enforced by `guards.eager.test.ts` and by `frontend/scripts/check-truck-chunks.mjs` (8.4). One thing does land in the main stylesheet and is expected: the Tailwind utility classes used by truck components, because Tailwind scans every source file.

---

## 2. State and data

### 2.1 `api/truck.ts`

One module, house style: `export const truckApi = { async x() { const { data } = await api.get(...); return data.data... } }`, plus `truckKeys` (2.2) and TypeScript types for the shapes of 04_BACKEND 4.1 (snake_case fields, exactly as returned; model shapes are imported with `import type` from `utils/truck/estimator/types`). Request bodies, validation rules and status codes are those of 04_BACKEND section 4 and are not repeated here; the bodies are typed here too (`ProfilePatch`, `SpotBody` and `SpotPatch` with `SpotTermsInput` and `HostInput`, `PlanBody` with `PlanStopBody`, `ServiceBody`, `LeadPatch`, `LeadSpotBody`). The error helpers of 2.6 (`apiErrorMessage`, `apiErrorStatus`, `apiErrorDetails`, `isNoTruckError`, `isRegionRebuildError`) are exported from this module as well: they read the error envelope. This module and `utils/truck/assemble.ts` are the only files that change if a payload changes.

| Client function | Route | What the screens use from the answer |
|---|:---:|---|
| `bootstrap()` | 1 | `has_truck`, `truck`, `assumptions`, `region`, `regions`, `calibration`, `fuel`, `timezone`, `today`, `now_minute`, `counts`, `routing.state`, `limits`, `model_version`, `seeds_revision` |
| `saveProfile(patch)` | 3 | `truck`, `region`, `fuel`, `warnings`. An upsert: the first call creates the truck. Later calls send only changed keys of `TruckProfileX` |
| `saveOverrides(changes)`, `resetOverrides(paths?)` | 5, 6 | `assumptions`. `changes` is `{ <seed path>: value \| null }`, merged on the server (null removes a path). A 422 carries `details: [{ path, error }]` with the codes of 02_MODEL 2.2 |
| `fetchPack(url)` -> `ArrayBuffer` | 8 | the binary of 03_DATA section 11; `url` is `region.pack.url`; `responseType: 'arraybuffer'`; not the JSON envelope |
| `simulate(body)` | 9 | `located`, `vectors` (one `LocationVectors` per requested visibility, host exclusion applied), `host` (the host after the server's link rule and defaults, or null), `outlets: [OutletRow]`, `outlets_total`, `hosts_nearby: [HostHint]`, `dataset_version`. `estimate` is read only by the drift check of 0.2 |
| `listSpots({ archived })`, `getSpot(id)`, `createSpot(body)`, `updateSpot(id, patch)`, `archiveSpot(id)`, `refreshStaleSpots()` | 10, 13, 11, 14, 15, 12 | `Spot`: `terms: SpotTerms`, `host_details`, `vectors: { hidden: LocationVectors; normal: LocationVectors; prominent: LocationVectors } \| null` (null when `vectors_state` is `none`; read only through `spotVectors`, 2.5), `vectors_state`, `maps_url`, `archived` |
| `dayContext(from, days)` | 17 | `fuel`, `forecast: { state, generated_at, generated_local }`, `days: [DayInfo]`. It sends `from` and `to = addDays(from, days - 1)`; `days` is 1 to 14. The forecast is for the truck's base point |
| `driveTimes({ points, mode, pairs? })` | 18 | `legs: [DriveLeg]` (each with `leg_input: LegInput`, `source`, `fallback_reason`, `toll_state`, `google_toll`, `override`), `routing.state` |
| `saveLegOverride({ from, to, minutes, toll })`, `deleteLegOverride(id)` | 20, 21 | `override` |
| `listPlans(from, to, { stops?: boolean })`, `getPlan(id)`, `createPlan(body)`, `updatePlan(id, body)`, `deletePlan(id)` | 22, 25, 23, 26, 27 | list rows `{ id, date, name, treat_as, status, stop_count, result_state, updated_at }` (type `PlanListRow`); with `stops: true` (query `stops=1`) each row is a `Plan` without `result` and `context` (type `PlanWithStops`), which adds `notes`, `stops: [PlanStop]`, `evaluated_at` and `maps_route_url`. Today, Week, Log, the Planner and the day sheet read plans with `stops: true` and build drafts, evaluations and `unloggedStops` from the rows. `Plan` carries `status`, `treat_as`, `stops` and `maps_route_url` (null without stops); `getPlan(id)` is used only where `result` is needed: the development drift check of 0.2 and the Log estimate of 4.7 |
| `suggestDay(body)`, `suggestWeek(body)` | 29, 30 | `suggestions: [Suggestion]`, `week: WeekSuggestion`, `fallback_pairs` |
| `listServices({ from, to, spot_id })`, `createService(body)`, `updateService(id, patch)`, `deleteService(id)` | 31, 32, 34, 35 | `ServiceLog` with its `prediction`; every write also returns the new `calibration` |
| `accuracy({ from, to })` | 37 | `accuracy: AccuracyReport`, `entries: [ServiceLogEntry]`, `unscored_without_prediction` |
| `scout({ hide, refresh })`, `saveLead(placeKey, { status, notes })`, `lookupContact(placeKey, force?)`, `saveLeadAsSpot(placeKey, body)` | 38, 39, 40, 41 | `candidates: [ScoutCandidate]`, `screened`, `truncated`, `limit_minutes`, `licence_counties`, `attribution`; `lead`, `lookup`; `spot`. `hide` is always sent, as a comma list; an empty list is sent as `hide=` and means that no status is hidden |
| `exportAll()` -> `{ blob, filename }` | 42 | one JSON document (`responseType: 'blob'`) and the file name the server put in `Content-Disposition` (`truck-planner-export.json` when the header cannot be read) |
| `deleteAllData()` | 43 | sends `{ confirm: 'delete my truck data' }`; `deleted` counts |
| `sources()` | 44 | `dataset`, `fuel`, `region`, `attribution: [{ id, text, url }]` |

Routes 2, 4, 7 and 36 are covered by `bootstrap`; 16, 19, 24, 28 and 33 are not called by any screen.

### 2.2 Query keys and stale times

```ts
export const truckKeys = {
  all:         ['truck'] as const,
  bootstrap:   () => ['truck', 'bootstrap'] as const,
  pack:        (url: string) => ['truck', 'pack', url] as const,
  simulate:    (version: string, lat6: string, lng6: string, hostKey: string, visKey: string) => ['truck', 'simulate', version, lat6, lng6, hostKey, visKey] as const,
  spots:       (archived: boolean) => ['truck', 'spots', 'list', archived] as const,
  spot:        (id: string) => ['truck', 'spots', 'one', id] as const,
  dayContext:  (from: string, days: number) => ['truck', 'day-context', from, days] as const,
  driveTimes:  (pointsKey: string) => ['truck', 'drive-times', pointsKey] as const,
  plans:       (from: string, to: string, stops: boolean) => ['truck', 'plans', 'list', from, to, stops] as const,
  plan:        (id: string) => ['truck', 'plans', 'one', id] as const,
  suggestDay:  (date: string, optionsKey: string) => ['truck', 'suggest', 'day', date, optionsKey] as const,
  suggestWeek: (weekStart: string, optionsKey: string) => ['truck', 'suggest', 'week', weekStart, optionsKey] as const,
  services:    (from: string, to: string, spotId: string) => ['truck', 'services', from, to, spotId] as const,
  accuracy:    (from: string, to: string) => ['truck', 'accuracy', from, to] as const,
  scout:       (hideKey: string) => ['truck', 'scout', hideKey] as const,
  sources:     () => ['truck', 'sources'] as const,
};
```

`lat6` and `lng6` are coordinates written with six fixed decimals through `roundHalfAway`. `hostKey` is `'-'` without a host, else the host's `place_key`, segment and, for worker and resident segments, its size, joined with `|` (the things that change the vectors the server computes). `visKey` is the sorted visibility list. `pointsKey` is the sorted list of `<id>@<lat6>,<lng6>` joined with `|`; a `pairs` request appends `#` and its pairs (`<from_id>><to_id>` joined with commas), so it never shares an entry with the matrix of the same points. Option keys are `JSON.stringify` of an object with sorted keys. The two `accuracy` arguments, the two date arguments of `services` and its spot id are `''` when left open. The builders of these keys are in `assemble.ts` (2.5).

The app defaults stay (`staleTime` 30 s, refetch on window focus), with one rule for every truck query (`truckRetry` in `components/truck/data/queryPolicy.ts`, which also holds the times of the table below): a request that got no answer or a 5xx answer is retried once; a 4xx answer and a 501 are never retried, because a 404, 409, 422 or 429 would only fail again a second later and the screen that shows it should not wait for that. Overrides:

| Query | staleTime | gcTime | Focus refetch | Notes |
|---|---|---|:---:|---|
| `bootstrap` | 5 min | default | yes | structural sharing keeps `TruckContext` stable when nothing changed |
| `pack` | Infinity | 10 min | no | immutable by URL; a request without an answer or with a 5xx answer is tried twice more, a 4xx, a 501 and a pack that does not decode are not (5.2); data is the decoded `CellPack` (about 12 MB), kept out of structural sharing |
| `simulate` | 10 min | 10 min | no | `enabled` only when a point is selected; `placeholderData: keepPreviousData` only when the point is unchanged and the host changed |
| `spots`, `spot` | 60 s | default | yes | |
| `dayContext` | 10 min if the range touches today .. today + 6, else 12 h | 30 min | yes | forecasts change hourly; beyond the horizon there is none |
| `driveTimes` | 24 h | 24 h | no | the server keeps Google legs up to 30 days; corrections update the cache directly, which is why the cached data keeps the points it was asked for next to the legs. Route 18 is limited to 240 requests an hour, shared with plan saves and evaluations, so nothing calls it per keystroke or per map click. `placeholderData: keepPreviousData`: while the legs of a changed set of points are on their way the previous legs stay available (`updating`, 2.5) |
| `plans`, `plan` | 30 s | default | yes | |
| `suggestDay`, `suggestWeek` | 5 min | 10 min | no | `enabled` only while the panel is open (30 requests an hour) |
| `services`, `accuracy` | 60 s | default | yes | |
| `scout` | 10 min | 10 min | no | never refetched by a timer (60 requests an hour); "Refresh" sends `refresh=1` |
| `sources` | 1 h | default | no | |

### 2.3 Mutations

Hooks live in `components/truck/data/mutations.ts`. Every mutation has `onError: (e) => { rollback(); const m = apiErrorMessage(e, '<fallback>'); if (m) toast.error(m); }` (2.6). Success toasts are one or two words.

| Hook | Optimistic | On success | Toast | Fallback error text |
|---|---|---|---|---|
| `useSaveProfile` (also creates the truck) | yes when a truck exists: patch `bootstrap` | write `truck`, `region` and `fuel` into `bootstrap`; invalidate `['truck','suggest']`, `['truck','scout']`; also `['truck','day-context']` when `base`, `fuel_type` or `fuel_price_override` changed, and `['truck','drive-times']` when `base`, `avoid_tolls` or `avoid_highways` changed. When the truck was just created, or `base`, `region_id` or `timezone` changed, `bootstrap` itself is read again (the region of the assumptions, the time zone, today and the counts follow the base); for a new truck that refetch is awaited, so the page behind the first-run step renders with its context. The mutation resolves with the answer, `warnings` included | "Saved" | "Could not save your settings." |
| `useSaveOverrides`, `useResetOverrides` | yes: patch `bootstrap` | write `assumptions` into `bootstrap`; invalidate `['truck','suggest']`, `['truck','scout']`, `['truck','plans']` | "Saved" | "Could not save the assumptions." |
| `useCreateSpot` | no (needs the id and vectors) | set `spot(id)`; invalidate `['truck','spots','list']`, `bootstrap` (counts) | "Spot saved" | "Could not save the spot." |
| `useUpdateSpot` | yes for fields that do not change vectors (name, address, notes, `host_details`, visibility, fees, `allowed`, `only_food`, size of a visitor host). No for point, host link, host segment, or size of a worker or resident host: wait for the stored vectors | set `spot(id)`; invalidate the lists and `['truck','suggest']` | "Saved" | "Could not save the spot." |
| `useArchiveSpot` | no; confirm first | invalidate the lists and `spot(id)`, `['truck','suggest']`, `bootstrap`; take the id out of `compareIds` | "Spot deleted" | "Could not delete the spot." |
| `useRefreshStaleSpots` | no | invalidate the lists when something was refreshed; run again while `remaining > 0` and the last round refreshed something (at most ten rounds); one run at a time for the whole page | none | none (silent; stale spots keep their tag) |
| `useSavePlan` (create or update; variables `{ date, planId, body, localResult? }`) | yes: set `plan(id)` from the draft when the id exists | `markSaved` in the draft store with the returned `Plan` (new stop ids); set `plan(id)`; write the plan into every cached list that was read with stops, then invalidate `['truck','plans','list']`. A first save that answers 409 `A plan already exists for this date` reads the date's plan and repeats the save as an update (rule 1 of 4.5). In development builds `localResult` is compared with the answer's `result` (0.2) | "Day saved" | "Could not save the day." |
| `useDeletePlan` (variables `{ id, date }`) | no; confirm first | remove `plan(id)` and its row in every cached list that was read with stops; discard the draft of the date; invalidate the lists | "Day cleared" | "Could not clear the day." |
| `useSaveLegOverride`, `useDeleteLegOverride` | yes: patch that directed pair in every cached `drive-times` result (the pair is matched on coordinates rounded to four decimals, the server's key for a correction, so one correction reaches every day that uses the drive) | invalidate `['truck','suggest']` | none | "Could not save the drive time." |
| `useSaveService` (variables `{ id?, body }`: no id creates), `useDeleteService` | create: insert at the top with a temporary id | write the returned `calibration` into `bootstrap`; invalidate `['truck','services']`, `['truck','accuracy']`, `['truck','suggest']`, `['truck','scout']`, `['truck','plans']`, the spot lists (their log counts) and `bootstrap` (counts) | "Logged"; after a delete "Deleted" | "Could not save the service."; for a delete "Could not delete the service." |
| `useSaveLead` | yes: patch the candidate's `lead` in every cached `scout` result | write the returned `lead` into the candidate | none | "Could not update the lead." |
| `useLookupContact` | no | write the returned `lead` into the cached candidate | none | "The lookup did not work. Try again later." |
| `useSaveLeadAsSpot` | no | set `spot(id)`; invalidate the spot lists and `bootstrap` (counts); patch the candidate's `lead` | "Spot saved" with a link "Open spot" | "Could not save the spot." |
| `useDeleteAllData` | no; typed confirmation | reset the three stores; reset `bootstrap`, which empties it at once (the gate takes the pages off the screen), reads it again and brings the first-run step back; then remove every other truck query. In that order: a query removed while a page is still mounted would be created again by its observer and ask for data of a truck that no longer exists | "Deleted" | "Could not delete the data."; a 403 is not toasted (the delete card says it in its own words, 4.9) |

### 2.4 Stores

Three small Zustand stores, selectors only (`useStore((s) => s.field)`), no server data.

```ts
// stores/truckUiStore.ts - persist, name 'smappen-truck-ui'
interface TruckUiState {
  mapLayer: 'opportunity' | 'people' | 'competition';   // default 'opportunity'
  mapCamera: { lat: number; lng: number; zoom: number } | null;
  lastHow: number | null;
  playSpeedMs: 1200 | 600 | 300;                         // default 600
  showSpotPins: boolean;                                 // default true
  showScoutDots: boolean;                                // default false
  windowHours: 2 | 3 | 4;                                // spot card window length, default 3
  compareIds: string[];                                  // at most 4 spot ids
  mapsAuthFailed: boolean;                               // NOT persisted
  patch(p: Partial<Omit<TruckUiState, 'patch'>>): void;
}
// partialize allow-list: mapLayer, mapCamera, lastHow, playSpeedMs, showSpotPins, showScoutDots, windowHours, compareIds

// stores/truckHourStore.ts - not persisted
interface TruckHourState {
  how: number;                  // integer 0..167
  playing: boolean;
  date: string | null;          // null = typical week; a date = the map uses that date's context (wave 2)
  setHow(how: number): void;    // wraps mod 168; no-op when unchanged
  step(delta: number): void;
  setPlaying(on: boolean): void;
  setDate(date: string | null): void;   // also moves how to dayOfWeek(date) * 24 + hour
}

// stores/truckPlanDraftStore.ts - not persisted
interface PlanDraftState {
  drafts: Record<string, PlanDraft>;                         // key = date (PlanDraft is defined in 4.5)
  load(date: string, saved: Plan | null): void;              // ignored while a dirty draft for that date exists
  patch(date: string, fn: (d: PlanDraft) => PlanDraft): void; // sets dirty
  markSaved(date: string, saved: Plan): void;
  discard(date: string): void;
}
```

The hooks are `useTruckUiStore`, `useTruckHourStore` and `useTruckPlanDraftStore`. Each file also exports a reset function (`resetTruckUiStore`, `resetTruckHourStore`, `resetTruckPlanDraftStore`), used when the owner deletes all data. What `truckUiStore` reads back from `localStorage` is checked field by field, so a key written by an older build never puts the map in a state the screens do not expect; `patch` keeps at most four `compareIds`. The hour store starts at `how` 84 (Thursday 12 PM) until the map page sets it (1.2). `load` and `markSaved` read only `id`, `date`, `treat_as`, `notes` and `stops` of the plan, so they take a `Plan` as well as a list row (2.1); `load` changes nothing when the saved plan is what the draft already shows. `truckPlanDraftStore.ts` also exports `DraftStop` and `PlanDraft` (4.5), `emptyPlanDraft(date)`, `draftFromPlan(saved)`, `newDraftStopId()` and `isTemporaryStopId(id)` (rule 4).

Rules for the hour store:

1. The map layer subscribes imperatively: `useTruckHourStore.subscribe((s, prev) => { if (s.how !== prev.how || s.date !== prev.date) layer.setHour(s.how, s.date); })`. No React render happens between a slider tick and the pixels.
2. React components that select `how` must be leaves that only print or move something small: the hour label, the week-strip cursor, the legend marker. Anything that computes (the spot card's "This hour", "Who is here") reads `useSettledHow(150)`: `how` once it has been unchanged for 150 ms, or at once when playback is off.
3. `how` is never put in `truckUiStore`, in the URL per tick, or in the component that renders `<GoogleMap>`. `lastHow` and `?how=` are written when playback stops and 300 ms after the last manual change.
4. New draft stops get temporary ids from a module counter (`newDraftStopId()`: `n1`, `n2`, ...), never from randomness and never the literal `base`. They are left out of the save body (`isTemporaryStopId`); the server assigns the real ids.

### 2.5 Feeding the estimator

`utils/truck/model.ts` is the single import point for model code: it re-exports the port's functions and types and exposes `SEEDS`, `MODEL_VERSION` (`tps-0.1.0`) and `SEEDS_REVISION` under those names, plus the chunk sentinel `TP_CHUNK_SENTINEL` (8.4). If the port names an export differently, only this file changes. `utils/truck/assemble.ts` (pure, tested) turns API payloads into model inputs:

| Function | Result | Rule |
|---|---|---|
| `buildAssumptions(info: AssumptionsInfo)` | `Assumptions` | `{ ...info, seeds: SEEDS }`; throws `VersionMismatch` when `model_version` or `seeds_revision` differs from the port's (the gate turns that into the reload card). `versionsMatch(modelVersion, seedsRevision)` is the same test as a boolean, for the top-level fields of the bootstrap answer |
| `buildContext(A, day: DayInfo, treatAs)` | `DayContext` | `dayContext(A, day.date, treatAs, day.context.forecast, day.context.fuel_price_per_gal, day.context.fuel_price_source)`. Always rebuilt in the browser, so "Treat this day as" is instant; with `treatAs` null it must equal `day.context` (asserted in development builds). The forecast passes through untouched (a null `precip_prob` stays null) |
| `degradedContext(A, date, treatAs, fuel: FuelInfo)` | `DayContext` | the same with `forecast: null` and the fuel price of the bootstrap answer; used when `day-context` cannot be loaded |
| `typicalWithFuel(A, dow, fuel: FuelInfo)` | `DayContext` | `{ ...typicalContext(A, dow), fuel_price_per_gal: fuel.price_per_gal, fuel_price_source: fuel.source }`, for one-stop-day figures on a typical week. `dayPlan` only needs a fuel price in its context, so a typical context with one added is valid input (**[A]**, pinned by `assemble.test.ts`); the `PlanInput.date` passed with it is `nextDateWithDow(today, dow)`, which the model only echoes |
| `spotVectors(spot, visibility = spot.terms.visibility)` | `LocationVectors \| null` | `spot.vectors[visibility]`, or null when `spot.vectors` is null. It is the only reader of `Spot.vectors`: `toStopInput`, `useSpotEstimate`, `spotSummaries` and `SpotAnalysis` take a spot's vectors only through it |
| `trafficIsNeutral(A)` | `boolean` | true when every value of `traffic.<A.region.traffic_matrix>` and its `_typical` value equal 1.0. Drive-time wording then drops the time-of-day adjustment (4.5, 6.4, 6.7) |
| `toStopInput(stop, spotsById)` | `StopInput \| null` | `spot` stops: `point` and `terms` (with `spot_id`) of the `Spot` and `vectors` = `spotVectors(spot)` (archived spots included). `event` stops: `terms = { spot_id: null, visibility: 'normal', host: null, fee_flat, fee_pct, fee_min, allowed: null }` and `event`. `catering` stops: `catering`. `id` = the stop's id (a temporary id for an unsaved stop). `stop` is a `PlanStop` or a draft stop; `spotsById` is a `Map` (`indexSpots(spots)`). The result is null for a stop without a place (a spot stop whose spot is not in the map, an event or catering stop without a point), and its `vectors` are null for a spot without stored vectors |
| `stopsEvaluable(stops)` | `boolean` | true when `dayPlan` can take the stops: none is null, every spot stop has terms and vectors, every event stop its event terms, every catering stop its catering terms. The model does not check this itself, so `usePlanEvaluation` does before every call |
| `toLegs(legs: DriveLeg[])` | `{ "<from_id>><to_id>": LegInput }` | `leg.leg_input` under the key `from_id + '>' + to_id`. A pair that is absent stays absent: the model fills it with `fallbackLeg` and raises `fallback_drive_time` |
| `toPlanInput(date, stops)` | `PlanInput` | stops in the owner's order |
| `drivePoints(base, stops)` | `[{ id, lat, lng }]` | the points of a day's drive-time request: the base as `base`, then each stop under its own id |
| `hostKey(host)`, `linkedHostKey(placeKey, host)`, `pointsKey(points)`, `visibilityKey(visibilities)`, `sortedJson(value)`, `coord6(x)` | string | the cache keys of 2.2 and their parts. `hostKey` takes a host as a request describes it (`place_key`, `segment`, `size`); `linkedHostKey` takes a model `Host` plus the place it is linked to (`Spot.host_details.place_key`), so a saved spot and a draft of it can be compared: equal keys mean the stored vectors still serve. A host whose segment the server has yet to derive from the linked place keeps its size in the key. `coord6` writes six fixed decimals and never a negative zero |
| `withinTolerance(a, b)`, `sameWithinTolerance(a, b)` | `boolean` | the golden-case tolerance of 02_MODEL 1.4 for two numbers and for two JSON values: what the development drift checks of 0.2 compare with |

Hooks in `components/truck/data/` wrap queries plus assembly and are the only way screens get model results:

| Hook | Returns |
|---|---|
| `useTruck()` | the `TruckContext` value (1.4) |
| `useNow()` | `{ date, minute, dow, how }` in the truck's time zone; re-renders on the minute (2.7) |
| `useSpots({ archived })`, `useSpot(id)` | the spot list as a query result; when any spot that is not archived has `vectors_state` other than `fresh` it fires `useRefreshStaleSpots` once per page visit (never while `region.unusable_reason` is `build_mismatch`, and never without a usable region, where nothing could be computed). `useSpot` is one spot (route 13), starting from its copy in a cached list; a 404 is the not-found state |
| `useDayContexts(from, days, treatAsByDate)` | `{ status: 'pending' \| 'ready' \| 'degraded', contexts: Record<date, DayContext>, forecast: { state, generated_at, generated_local }, fuel, refetch }`. `contexts` is empty while `pending`; when `degraded` every date of the range has a `degradedContext` |
| `useDriveTimes(points, pairs?)` | `{ status: 'pending' \| 'ready' \| 'error', legs: DriveLeg[], legInputs, routingState, updating, error, refetch }`. Fewer than two points ask nothing and are `ready` with no legs. `updating` is true while `legs` still belong to the previous set of points. Points are `{ id, lat, lng }` with id `base` for the truck's base and the stop id for a stop. Without `pairs` it asks for `mode: 'matrix'`: every ordered pair, because `dayPlan` needs the leg that skips a stop to work out what that stop adds (a day with four stops is 20 pairs). The matrix is a superset of the keys of `requiredLegKeys` (02_MODEL 4.11), which the server resolves for its own evaluation, and it also covers reordering; the same answer serves Planner, Week, Today and the day sheet. With `pairs` it asks for `mode: 'pairs'` (spot detail and compare: base to spot and back) |
| `useSpotEstimate({ point?, spot?, terms, hostPlaceKey?, editing?, withPlaces?, windowHours? })` | `{ status: 'pending' \| 'ready' \| 'error' \| 'outside' \| 'rebuilding' \| 'none', dim, terms, vectors, located, resolvedHost, outlets, outletsTotal, hostsNearby, placesStatus: 'idle' \| 'pending' \| 'ready' \| 'error', week: number[168], best: Window[], hour(how), windowOn(dow, open, close), oneStopDay(dow, open, close), driveLegs, error, refetch }`. `terms` in is a model `SpotTerms` (the spot's own, or a form's draft); `hostPlaceKey` is the place the draft host is linked to (default: the saved spot's link). A saved spot uses `spotVectors(spot, terms.visibility)` and makes no request while its point, host link, host segment and, for a worker or resident host, host size are unchanged (`linkedHostKey`), so a visibility change is instant. A clicked point, and a spot edit that changes one of those four, uses `simulate`: with one visibility for a point, with the three visibilities when `editing` is set (a form), in both cases with the draft host. So does a saved spot whose stored vectors fail `vectorsMatch` against its own saved terms (its host was saved while the vectors could not be recomputed): it is not left waiting for the refresh of rule 5. `outside` is `in_region` false with `points_used` 0 (4.3). `rebuilding` is a point, an edit or such a spot that needs `simulate` while `region.unusable_reason` is `build_mismatch`: no request is sent and no answer still in the cache counts, so the only numbers it can return are the last matching estimate with `dim` set. `none` is a saved spot without stored vectors ("No estimate yet"). `terms` out are the terms the numbers were computed with (the input's, with the host the vectors were computed for); `resolvedHost` is the `host` of the `simulate` answer, which a form adopts as its draft host (rule 7); `dim` is rule 6. `week`, the three functions and `vectors` are null while there is nothing to show; `oneStopDay` is also null while the drive legs of a saved spot are on their way, and `windowOn` for a window the model would refuse with `invalid_window` (anything but `0 <= open <= close <= 2880`), so a half-typed time shows no number instead of failing the page. `best` uses `windowHours` (default: the stored `truckUiStore.windowHours`). A saved spot asks `useDriveTimes` for base to spot and back (`driveLegs`); a clicked point never spends a drive-time request. `outlets`, `outletsTotal` and `hostsNearby` come with a `simulate` answer; for a saved spot evaluated from its stored vectors they are null unless `withPlaces` is set, which spends one `simulate` request on the spot's point for these three lists only. `placesStatus` says where the three lists stand, apart from `status`, because a saved spot has its numbers before, and whether or not, its lists arrive: `idle` (not asked for, or not to be had while the region data is being rebuilt), `pending`, `ready`, or `error` (their request failed and there is nothing to list; `refetch` asks again). The spot card and the spot form print their failure sentences from it (4.3 E, 4.4) |
| `useSimulate(request)` | the `simulate` query (route 9) under the key of 2.2 for `{ point, host, visibilities, spotId? }`, or idle for null; it also runs the week-strip drift check of 0.2. `useSpotEstimate` builds on it; a screen that needs the answer itself (the Scout card's "Why this number", 4.8) may use it directly |
| `usePlans(from, to)`, `usePlanForDate(date)`, `usePlan(id)` | `usePlans`: the plans of a range from `listPlans(from, to, { stops: true })`, each row turned into a `Plan` whose `result` and `context` are null (`planFromRow`), so every consumer works with one type. `usePlanForDate`: `{ status, plan: Plan \| null, error, refetch }`, the row of that date or null. `usePlan`: `getPlan(id)` with the server's snapshot, for the two uses 2.1 names |
| `usePlanEvaluation(date, stops, treatAs)` | `{ status: 'pending' \| 'ready' \| 'unavailable' \| 'error', result: DayResult \| null, legs: DriveLeg[], notes: { driveFallback, contextDegraded }, updating, error, refetch }`; needs the contexts of `date` and `addDays(date, 1)`, the spots (archived included), the drive matrix and calibration. `stops` are `PlanStop`s or draft stops. `unavailable` is a day that `stopsEvaluable` refuses (a spot without stored vectors, an event or catering stop without its place or terms): no result. `error` is a spot list that could not be loaded. Right after a stop is added or removed the day is evaluated at once with the legs already known (a pair they lack counts as a straight line for the moment) and `updating` is true until the new legs arrive: estimates are then dimmed and `driveFallback` stays false |
| `useServices({ from, to, spot_id })` | the logged services of a range as a query result (route 31); all three are optional |
| `useScout(hide)`, `useSuggestDay(body, enabled)`, `useSuggestWeek(body, enabled)`, `useAccuracy(from, to)`, `useSources()` | thin `useQuery` wrappers over the keys and stale times of 2.2 (files `useScout.ts`, `useSuggestions.ts`, `useAccuracy.ts`, `useSources.ts`); each returns the query result as it is. `refreshScout(queryClient, hide)` in `useScout.ts` is the "Refresh" button: it sends `refresh=1` and replaces the cached answer |
| `useTruckMapsLoader()`, `useAddressSearchAvailable()` | `{ isLoaded, loadError }` of the one shared Google loader call, and whether the address widget may be shown (1.6) |
| `useBootstrap(select?)` | the bootstrap query (route 1). Its data is the answer plus `clock_skew_minutes` (2.7); the gate and `useNow` are its users |

Rules: (1) derived results are `useMemo`-ed on the identities of their inputs; a week strip is 168 `hourlyOrders` calls and a day plan is a handful of evaluations, both far under a frame. (2) A screen never holds a rounded model value and feeds it back; rounding happens in formatters only. (3) Typical-week figures use `typicalContext`; dated figures use `buildContext`. A window that closes after midnight passes the next day's context as `ctx_next`, built with `treat_as` null. (4) When a profile or assumption draft is being edited, the preview is computed from the draft, the rest of the app from the saved value. (5) A spot with `vectors_state: 'stale'` is still evaluated from its stored vectors and tagged "Updating" until the refresh returns; while `region.unusable_reason` is `build_mismatch` no refresh is sent and the tag reads "Out of date" instead; `none` shows "No estimate yet". (6) Before any estimate `useSpotEstimate` tests `vectorsMatch(A, terms, vectors)` from the port (02_MODEL 4.7). When it is false the hook keeps the last matching estimate on screen dimmed (`RangeValue dim`) and waits for new vectors; the estimator is never called with terms and vectors that do not match. The stored vectors of a saved spot are tested the same way against the spot's saved terms, and a pair that fails is set aside in favour of a `simulate` answer. (7) When `simulate` answers, the draft's `terms.host` becomes the answer's `host` (the host with the server's link and defaults: `segment`, `size`, `size_source`, `only_food`, `point_id`, `place_type`), so `vectorsMatch` holds and `dayPlan` raises no `stale_vectors`. `useSpotEstimate` does this itself for the numbers it returns: its `terms` carry the answer's host with the draft's `only_food` and, for a visitor host, the draft's size (the two things that change no vectors). A form copies `resolvedHost` into its draft, so that what it saves is what was estimated.

### 2.6 Error handling

| Situation | Behaviour |
|---|---|
| Any 401 | already handled globally (logout, redirect). Truck code does nothing. The truck API never answers 401 for anything else |
| No response (offline) | the interceptor already toasts "Connection lost. Please check your network.". `apiErrorMessage(e, fallback)` returns null for these so call sites do not toast twice |
| Query pending | layout-shaped skeleton with `aria-busy="true"`; never the text "Loading..." for a whole page |
| Query failed | `QueryError` in place of the content: one sentence, "Try again" calls `refetch()`. Never `if (isLoading \|\| !data) return ...` (it hangs on errors) |
| 404 for a spot or plan | not-found state with a link back to the list |
| 409 "Set up your truck first" | invalidate `bootstrap` (1.4) |
| 409 whose text is exactly `Region data was built with different model constants` | invalidate `bootstrap` and show "Map data is being rebuilt after an update. New estimates are unavailable until it finishes." (the strip sentence of 1.4), never the server text |
| Any other 409 | a business conflict ("You can keep at most 500 spots", "A service is already logged for this spot and time", "This place is already saved as a spot"): show the server's sentence. The planner handles "A plan already exists for this date" itself (rule 1 of 4.5) |
| 422 on save | the server's sentence (it names the field) in the toast; for overrides the `details` list is mapped onto the fields. Forms validate first with the same ranges, so a 422 is the exception. Invalid values are rejected, never clamped |
| 429 | a sentence that starts with `Rate limit reached` (the rate-limit middleware's, which names an internal counter) is always replaced by "Too many requests right now. Try again in a minute."; any other 429 sentence is shown as sent |
| 501 | a route whose backend package has not landed yet (9.1). The server's "Not implemented yet" tells the owner nothing and is never shown: the call site's own sentence is ("Could not load the services logged here."), as for a request that failed without a reason |
| `day-context` failed | estimates still render (`degraded`), with a warning strip: "Could not load the forecast. Showing no weather adjustment." and "Try again" |
| `drive-times` failed, or legs came back as straight lines | the plan still evaluates; strip: "Google drive times are unavailable. Drive times are straight-line estimates." (the reason wording is in 6.7) |
| Pack failed, wrong version, or WebGL trouble | the map shows without colours (5.8); clicking still opens the spot card |
| `simulate` answered `located.in_region: false` | with `vectors.points_used` 0: the "outside" state of the spot card (4.3). With `points_used` above 0 the card renders normally with the line "Just outside the loaded counties. People across the county line are only partly counted." |
| Component crash | the keyed `ErrorBoundary` in the layout; independent regions on dense pages (map, spot card, planner summary) get their own `<ErrorBoundary scope="..." inline>` |

`apiErrorMessage(e, fallback)` (in `api/truck.ts`): `e?.response?.data?.error` if it is a non-empty string, else `fallback`; null when `e.response` is missing. It applies the three replacements of the table itself (the rate-limit sentence, the region rebuild sentence, and `fallback` for whatever a 501 says), so no call site can show any of those server texts. Server sentences are otherwise shown as they are unless a row above or a screen of section 4 names its own sentence; the backend guarantees they never contain a URL or a key. `apiErrorStatus(e)` is the HTTP status or null, `apiErrorDetails(e)` the `details` of a 422, and `isNoTruckError(e)` and `isRegionRebuildError(e)` recognise the two 409s the gate reacts to (1.4).

### 2.7 Clock

The model never reads a clock; the UI has to know "today" and "now" in the truck's time zone. `utils/truck/clock.ts` is the only file that may use `Date` and `Intl`:

```ts
export function nowEpochMs(): number;                                        // Date.now()
export function regionNow(timeZone: string, epochMs: number): { date: string; minute: number };
export function zonedToUtcStamp(timeZone: string, date: string, minute: number): string;   // 'YYYYMMDDTHHMMSSZ'
export function utcStamp(epochMs: number): string;                            // 'YYYYMMDDTHHMMSSZ'
export function clockSkewMinutes(serverToday: string, serverNowMinute: number, timeZone: string, epochMs: number): number;
```

`regionNow` formats `new Date(epochMs)` with `new Intl.DateTimeFormat('en-US', { timeZone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }).formatToParts(...)` and reads the parts by type. It never uses the device's time zone, `toISOString` or `getDay`. The server stays the authority: each bootstrap answer carries `timezone`, `today` and `now_minute`. At the moment an answer arrives (inside the query function, so a cached answer is never re-measured) the client computes `skew = (daysFromCivil(today) * 1440 + now_minute) - (the same from regionNow(timezone, nowEpochMs()))`, treats a skew of one minute or less as zero and keeps it with the cached data (`clockSkewMinutes`; the cached bootstrap data carries it as `clock_skew_minutes`). `useNow` reports `regionNow(timezone, nowEpochMs() + skew * 60000)`. A device with a wrong clock therefore still shows the server's day. `useNow` re-renders from one shared timeout aligned to the minute (never an interval) and again when the tab becomes visible, because browsers hold back the timers of hidden tabs. `utcStamp` is integer arithmetic on the epoch and uses no `Date` getter.

`zonedToUtcStamp` finds the instant whose wall-clock reading in `timeZone` is `date` plus `minute` (minutes below 0 or from 1440 are first moved to the neighbouring date with `addDays`): start from the reading taken as UTC, correct by the difference `regionNow` reports, repeat once; a wall time that occurs twice (the hour repeated when daylight time ends) takes the earlier instant, and a wall time that does not occur (the hour skipped when daylight time starts) is read with the offset in force before the change, as calendars do: 2:30 AM on 2026-03-08 in New York is `20260308T073000Z`. Check values: `regionNow('America/New_York', Date.UTC(2026, 9, 8, 3, 30))` = `{ date: '2026-10-07', minute: 1410 }`; `regionNow('America/New_York', Date.UTC(2026, 0, 15, 17, 0))` = `{ date: '2026-01-15', minute: 720 }`; `zonedToUtcStamp('America/New_York', '2026-10-08', 660)` = `20261008T150000Z`; `zonedToUtcStamp('America/New_York', '2026-12-10', 660)` = `20261210T160000Z`.

---

## 3. Shared UI kit

Built once by the foundation package under `components/truck/ui/` (components, exported through `ui/index.ts`) and `utils/truck/` (pure helpers). Screens do not re-implement any of it. What a component decides without the DOM (what a field commits, how a table sorts, where a key moves the cursor, what the weather chip says) is a pure function in `components/truck/ui/kit.ts`, tested in Node by `kit.test.ts` (8.2); the components are thin shells around those functions. Three more files of that folder are shared by the components and not exported: `overlay.ts` (scroll lock, focus trap and focus return of 3.7), `HintChip.tsx` (the neutral chip with a hint that `ConfidenceChip` and `SeedTag` are built on) and `useThemeName.ts` (the theme named by `<html data-theme>`, for the ramp of `WeekStrip`).

### 3.1 Formatters (`utils/truck/format.ts`)

Pure functions, no `Intl`, no `Date`, no locale. Every rounding goes through `roundHalfAway` from the model; digits are grouped by hand with commas. `null` or `undefined` gives the em dash (the house convention in `utils/format.ts`). All are prefixed `fmt` so they cannot be confused with the model's own `formatDate`. Ranges always use the word "to", never a dash, because lows can be negative.

| Function | Rule | Examples (also the test vectors) |
|---|---|---|
| `fmtMoney(x)` | whole dollars; minus sign before `$`; never `-$0` | `1564.2` -> `$1,564`; `-34.82` -> `-$35`; `-0.4` -> `$0`; `1234567.5` -> `$1,234,568` |
| `fmtMoneyCents(x)` | two decimals | `4.54` -> `$4.54`; `9.541` -> `$9.54`; `1500` -> `$1,500.00` |
| `fmtFuel(x)` | three decimals, per gallon | `4.195` -> `$4.195/gal` |
| `fmtCount(x)` | whole number | `60.49` -> `60`; `1244215.2` -> `1,244,215`; `0.3` -> `0` |
| `fmtCount1(x)` | one decimal (per-hour table cells under 10) | `5.25` -> `5.3`; `29.436` -> `29.4` |
| `fmtCeil(x)` | round up, for break-even orders: `ceil(x - 1e-9)` | `25.232` -> `26`; `24` -> `24` |
| `fmtNumber(x, decimals = 0)` | a plain figure with a fixed number of decimals, grouped: factors, gallons, bounds | `fmtNumber(0.963784, 2)` -> `0.96`; `fmtNumber(12345.678, 1)` -> `12,345.7` |
| `fmtPlain(x, maxDecimals = 3)` | the same with trailing zeros dropped: seed values, the text of a number field | `0.5` -> `0.5`; `200000` -> `200,000`; `fmtPlain(1.2815515655446004, 4)` -> `1.2816` |
| `fmtFixed(x, decimals)` | exactly `decimals` decimals and no grouping, as map links write coordinates (3.14) | `fmtFixed(38.96, 6)` -> `38.960000` |
| `fmtRange(e, unit)` | `unit` = `orders`, `money` or `money_per_hour` | `33 to 93`; `$85 to $659`; `-$43 to $353` |
| `fmtEstimate(e, unit)` | value, unit word, range in brackets; no range when `confidence` is `fixed` or when low and high print the same | `60 orders (33 to 93)`; `1 order (0 to 3)`; `$482 ($42 to $1,012)`; `$135 (-$43 to $353)`; `80 orders` (fixed); `$43 an hour ($4 to $90)` |
| `fmtEstimateSpoken(e, unit)` | the same estimate inside a sentence or a spoken label, without a full stop | `60 orders, likely between 33 and 93`; `$482, likely between $42 and $1,012`; `80 orders` (fixed) |
| `fmtPerHour(x)` | | `42.74` -> `$43 an hour` |
| `fmtAbout(x)` | for counts of people, which carry no range: a whole number below 100, two significant digits from 100 | `437.11` -> `440`; `1244` -> `1,200`; `74.4` -> `74`; `8.2` -> `8` |
| `fmtPercent(f, decimals = 0)` | input is a fraction of 1 | `0.3` -> `30%`; `fmtPercent(0.026, 1)` -> `2.6%` |
| `fmtMiles(mi)` | one decimal under 100, whole from 100, floor text under 0.05 | `9.7` -> `9.7 mi`; `123.4` -> `123 mi`; `0.02` -> `under 0.1 mi` |
| `fmtDuration(min)` | integer minutes | `45` -> `45 min`; `65` -> `1 h 5 min`; `120` -> `2 h`; `677` -> `11 h 17 min` |
| `fmtHours(h)` | one decimal, for "more hours" sentences | `5.8333` -> `5.8 hours`; `1` -> `1 hour` |
| `fmtClock(min)` | 12-hour; `day = floorDiv(min, 1440)`; day 1 adds " (next day)", day -1 adds " (day before)"; a day further off is counted: " (2 days later)", " (2 days before)" | `574` -> `9:34 AM`; `720` -> `12:00 PM`; `0` -> `12:00 AM`; `1251` -> `8:51 PM`; `1470` -> `12:30 AM (next day)`; `-30` -> `11:30 PM (day before)`; `2880` -> `12:00 AM (2 days later)`; `-1441` -> `11:59 PM (2 days before)` |
| `fmtClockShort(min)` | drops `:00` | `660` -> `11 AM`; `870` -> `2:30 PM` |
| `fmtWindow(open, close)` | short clocks joined by "to" | `660, 840` -> `11 AM to 2 PM`; `1290, 1500` -> `9:30 PM to 1 AM (next day)` |
| `fmtHourTick(h)` | chart axis only | `0` -> `12a`; `13` -> `1p` |
| `fmtHow(how)`, `fmtHowLong(how)` | | `84` -> `Thu 12 PM`; `Thursday, 12 PM to 1 PM` |
| `fmtDay(date, style)` | `long`, `medium`, `short`; weekday from `dayOfWeek`, month from the string | `2026-10-08` -> `Thu, Oct 8, 2026`; `Thu, Oct 8`; `Oct 8` |
| `fmtWeekday(dow, style)` | index 0 = Monday | `3` -> `Thursday`, `Thu` |
| `fmtTemp(f)` | | `62` -> `62°F` |
| `fmtPhone(s)` | `+1` and ten digits only; anything else unchanged | `+13017428261` -> `(301) 742-8261` |
| `fmtCoord(lat, lng)` | four decimals | `38.9600, -77.3600` |

Parsers in the same file: `parseNumber(text)` (strips `$`, commas, spaces and a trailing `%`; what is left must be a plain decimal number, that is digits, at most one point and an optional sign, so `1e3`, `0x10`, `1/2` and the empty string give null; `cleanNumberText(text)` returns that cleaned text unconverted, so a percent field can move the decimal point as text), `parseCoords(text)` (two decimal numbers separated by a comma or spaces, latitude -90..90, longitude -180..180), `parseClock(text, opts)` (3.6). Other pure helpers: `utils/truck/time.ts` (`howOf(dow, hour)`, `howParts(how)`, `mondayOf(date)`, `weekDates(weekStart)`, `nextDateWithDow(today, dow)`), `utils/truck/links.ts` (3.14), `utils/truck/wording.ts` (every fixed string of section 6), `utils/truck/warnings.ts` (6.5), `utils/truck/breakdown.ts` (3.4), `utils/truck/timelineView.ts` (3.10), `utils/truck/palette.ts` (5.5), `utils/truck/logView.ts` (the unlogged-stop list, the verdict words and `calibrationBefore` of 4.7).

### 3.2 `RangeValue`

```ts
interface RangeValueProps {
  estimate: Estimate;                                   // the only way to show an estimate
  unit: 'orders' | 'money' | 'money_per_hour';
  size?: 'sm' | 'md' | 'lg' | 'xl';                     // value at 14 / 18 / 24 / 30 px; default 'md'
  layout?: 'stack' | 'inline';                          // stack: value, range line, chip. inline: "60 orders (33 to 93)" + chip
  label?: string;                                       // uppercase caption: above the value in the stack, before it on the same line inline
  onWhy?: () => void;                                   // adds the "Why this number" button
  note?: string;                                        // one line under the range, for example "Limited by how fast the truck can serve."
  dim?: boolean;                                        // inputs are refreshing: 55 % opacity, aria-busy
}
```

- Behaviour: value, low and high are each rounded by the formatter for the unit. `fixed` shows the value and the chip "Fixed", no range. An estimate whose low and high print the same shows no range either: `low = high = value = 0` is `0 orders` and its chip. A negative money value keeps the minus sign and takes `--money-negative`; nothing is coloured green. The confidence chip is always shown: no prop hides it.
- Accessibility: the wrapper is a `role="group"` whose `aria-label` is `fmtEstimateSpoken(estimate, unit)`, a full stop, then the confidence label and its sentence, for example "60 orders, likely between 33 and 93. Rough: not yet checked against your own sales." A caption leads it in sentence case: "Take-home: $482, likely between $42 and $1,012. Rough: not yet checked against your own sales." The caption, the value, the unit word and the range are `aria-hidden`; the chip, the note and the "Why this number" button are not.
- Visual: value `font-extrabold tabular-nums` in `--ink`; unit word `text-sm font-bold` in `--body`; range line `text-[13px] font-semibold tabular-nums` in `--body`, written `33 to 93`; never `--slate` or lighter for any part of the number.

### 3.3 `ConfidenceChip`

`{ confidence: Estimate['confidence']; size?: 'sm' | 'md'; hint?: boolean }`. Labels and sentences are fixed (6.2). The chip is neutral on purpose: background `--bg-panel`, text `--ink` at weight 700, 11 px, height 24 px (`md`) or 20 px (`sm`), radius full. It does not use the freshness or money colours, which mean something else. Each label has its own lucide glyph so it never depends on colour: `Signal` variants for Very rough (`SignalLow`), Rough (`SignalMedium`), Fair (`SignalHigh`), Good (`Signal`), and `Lock` for Fixed. With `hint`, hover, focus or tap opens a 240 px popover with the sentence; `role="status"`, `aria-label` = label plus sentence. The popover is rendered through a portal into `document.body` and placed from the chip's box (under it, or above it near the bottom edge, and never past the side edges of the window), so an ancestor that scrolls or carries a transform cannot clip it; it follows the chip when the page scrolls and closes on Escape, on blur and on a press elsewhere. A click on the chip never reaches a clickable row or card around it.

### 3.4 `WhyDrawer` ("Why this number")

```ts
type WhySubject =
  | { kind: 'window'; title: string; window: WindowResult; vectors: LocationVectors; terms: SpotTerms; ctx: DayContext; ctxNext?: DayContext | null; money?: StopMoney }
  | { kind: 'event';  title: string; stop: DayStop; event: EventTerms; ctx: DayContext; ctxNext?: DayContext | null }
  | { kind: 'day';    title: string; result: DayResult; stopNames: string[]; ctx: DayContext };
// ctxNext: the context of the next date, given when the window or the event runs past midnight. Hours with
// day_index 1 read their day types and their forecast record there; without it their chance of
// precipitation is printed as the model's own figure.
interface WhyDrawerProps { open: boolean; onClose: () => void; subject: WhySubject | null }
```

A `Sheet` (3.7), 560 px wide on desktop, full screen under 768 px, heading "Why this number", with `subject.title` as its first line. The content comes from `whySteps(subject, A, profile, cal, opts?)` in `utils/truck/breakdown.ts`, which returns the thirteen steps of 02_MODEL section 5 in their fixed order, each `{ n, title, lines: string[], rows: { label, value, note? }[], more: { label, rows } | null, seedPaths: string[], seeds: WhySeed[] }`: `lines` are whole sentences printed above the figures, `rows` the figures, `more` one closed disclosure under them ("Show all 16", "Technical detail"), `seedPaths` the seeds the step rests on, and `seeds`, filled for step 13 only, every seed of steps 1 to 12 as `{ path, label, value, unit, tag, source, overridden, startingValue, placeholder, note }`. `opts.hourIndex` chooses the hour of a window that steps 1 to 9 describe. `A`, `profile` and `cal` are the ones the result was computed with; the drawer takes them from `useTruck()`. Steps may be collapsed, never reordered, merged or dropped; a step with nothing to say for this subject prints one line saying so (for example step 5 "No host at this spot.").

| n | Title (exact) | n | Title (exact) |
|---|---|---|---|
| 1 | Who is within walking distance | 8 | Your own results |
| 2 | How many of them buy a meal this hour | 9 | Capacity |
| 3 | What share the truck wins | 10 | Window |
| 4 | Menu fit | 11 | Range and label |
| 5 | Host | 12 | Money |
| 6 | Subtotal before adjustments | 13 | Where the assumptions come from |
| 7 | Weather | | |

- For a window, an hour picker at the top (the window's hours as chips, labelled by `whyHourLabels(window)`: "11 AM", "12 PM", "1 PM") selects which `HourResult` steps 1 to 9 describe; steps 10 to 13 are for the whole window. The drawer opens on the hour with the most orders (`defaultHourIndex(window)`; the first such hour on a tie), and a window of one hour shows no picker. Step 1 lists only segments holding at least 1 % of the hour's demand, with "Show all 16". For worker segments it shows jobs nearby and the on-site share as separate figures.
- Step 7 prints "No forecast for this hour: no weather adjustment." when `weather_state` is `missing` and "Typical week: no weather adjustment." when `typical`. With a forecast the chance of precipitation is printed as `fmtPercent(weather_detail.precip_p)` (`precip_p` is a fraction of 1); when the hour's `precip_prob` is null and the class is not `dry` the row reads "Chance of {class}: not given in the forecast. Assumed {fmtPercent(weather.pop_when_missing)}." ({class} is the chip's word of 3.13 in lower case) and step 13 lists that seed. Step 8 prints "No logged services yet." when there is no calibration.
- Step 11 prints one row per part of `spread`, each with its share of the total as a percent (`part / (sigma * sigma)`; 0% when `sigma` is 0): `v_truck` "How well the model fits your truck overall"; `v_spot` "How this spot differs from your average"; `v_day` "Normal day-to-day swings"; `v_weak` "Weak figures for hospitals, campuses and stations"; `v_size` "The host size is a typical figure, not yours"; `v_event` "Attendance is the organiser's guess"; `v_count` "Small numbers bounce around". Then "Together: {low} to {high} around {value}.", the label sentence of 6.2 and, when the cap holds the range back (for a window: `qkey(orders.high)` below `qkey` of the high that `interval(A, demand_adj, evidence)` returns), "The strong-day figure is limited by how fast the truck can serve." `sigma_model` and `sigma` appear only in a closed disclosure "Technical detail".
- Step 13 lists each seed used: its path, value, unit, `SeedTag`, source note, and "Your value" when overridden, with a link to Settings > Assumptions. Seeds tagged `tuned` read "Placeholder until you log services".
- Under the steps, always: the standing lines of 6.3.
- Accessibility: steps are `<section>`s with `<h3>` and a disclosure button (`aria-expanded`, `aria-controls`); figures sit in two-column description lists; focus moves to the heading on open and returns to the opener on close.

An event estimate opens from `DayStop.event` (`buyers`, `demand`, `hours`, `spread`; 02_MODEL section 3 and section 5 step 10); nothing is recomputed. Its subject is `{ kind: 'event'; title; stop: DayStop; event: EventTerms; ctx: DayContext }`. Under the thirteen fixed titles `whySteps` prints:

| n | Row for an event |
|---|---|
| 1 | "Expected attendance during your stop: {attendance}. Counted at {fmtPercent(events.attendance_haircut)}: {attendance x haircut}." |
| 2 | "{fmtPercent(events.p_buy.<type>)} buy a meal ({event type label}): {stop.event.buyers}." |
| 3 | "Shared between {vendors} food vendors, counting you: {buyers / max(1, vendors)} each." |
| 4 | "Menu fit is not applied to events." |
| 5 | "No host: this is an event stop." |
| 6 | the per-vendor figure of step 3, as the subtotal |
| 7 | the weather factor of each hour (`hours[].weather`) |
| 8 | "Truck factor x{cal.truck_factor}: {stop.event.demand} orders wanted. Results at single spots are not used for events." |
| 9 | per hour, `capacity` against `demand` |
| 10 | the hourly rows (`fraction`, `demand`, `orders`), summing to `stop.orders.value` |
| 11 | `stop.event.spread` in the rows of step 11 above, the event part included |
| 12 | `stop.money` |
| 13 | the seeds `events.attendance_haircut`, `events.p_buy.<type>`, `uncertainty.sd_event` |

The event type labels are those of rule 5 of 4.5.

### 3.5 `NumberField`, `MoneyField`

```ts
interface NumberFieldProps {
  id: string; label: string; value: number | null; onCommit: (v: number | null) => void;
  min?: number; max?: number; step?: number; decimals?: number;        // decimals shown after commit
  format?: 'number' | 'percent';                                        // percent: value is a fraction, the field shows 30 for 0.30
  prefix?: string; suffix?: string;                                     // "$", "mi", "orders an hour", "min", "%"
  help?: string; error?: string; required?: boolean; disabled?: boolean; integer?: boolean;
  placeholder?: string;                                                 // shown while the field is empty, for example "Truck default: 30"
  className?: string;                                                   // on the wrapper; TimeField and Field take it too
}
// MoneyField = NumberField with prefix "$", decimals 2, min 0 unless given
```

- Behaviour: `<input type="text" inputMode="decimal">` (`numeric` when `integer`; not `type="number"`: no wheel changes, no locale parsing). It keeps a draft string while focused and commits on blur or Enter by the rules of `parseNumber` (`commitNumber` in `kit.ts`). A field left as it was found commits nothing, so a value that is shown rounded is never written back rounded. Empty commits null (error "Required" when `required`). Text that is not a number, a fraction in an `integer` field and a value out of range do not commit: the text stays in the field, which shows "Enter a number from {min} to {max}." ("Enter a whole number from {min} to {max}." when `integer`; "... of {min} or more." or "... of {max} or less." with one bound; "Enter a number." with none). The bounds are printed as the field shows them, so `min: 0, max: 0.95` reads "from 0 to 95" in a percent field. Values are never clamped. Arrow Up and Down change by `step` (default 1 as shown, which is one percent in a percent field), commit at once and do nothing where the step would leave the range; from an empty field Arrow Up gives `min` when there is one. Escape restores the last committed value. At rest the field prints the value with `decimals` decimals, or with up to six and trailing zeros dropped when `decimals` is not given. On focus the figure is selected so that typing replaces it; the selection is made one frame later and only if the field still has focus (`select()` on a field that has lost focus would pull focus back).
- Accessibility: `<label htmlFor>`; help and error linked with `aria-describedby`; `aria-invalid` on error; the unit is real text inside the label for screen readers.
- Visual: `.input h-11 md:h-9 text-sm tabular-nums text-right`, prefix and suffix as inner adornments in `--body` at weight 600; label is `.label`; error text 12 px weight 600 in `--money-negative` with a `TriangleAlert` icon.

### 3.6 `TimeField`

`{ id; label; value: number | null; onCommit; min?: number; max?: number; after?: number; allowNextDay?: boolean; step?: 5 | 15; help?; error?; disabled? }`. The value is minutes from midnight (0..2880). Display is `fmtClock`. A text input with a minus and a plus button (44 px on touch) that move by `step` (default 15). `parseClock(text, { after, allowNextDay })`:

1. Trim, ASCII lower-case, remove spaces and dots. `noon` -> 720, `midnight` -> 0.
2. Match `^(\d{1,2})(?::?(\d{2}))?(a|am|p|pm)?$`; minutes above 59 fail.
3. With a suffix the hour must be 1..12: `12am` -> 0, `12pm` -> 720, otherwise add 720 for pm.
4. Without a suffix: 13..23 is 24-hour time; 0 is midnight; `24` with minutes 0 is 1440 and anything above fails; 1..12 has two readings, AM and PM. Without `after`, 7..11 are AM and 12, 1..6 are PM. With `after`, it is the first reading later than `after`, tried in this order: AM, PM and then, with `allowNextDay`, AM of the next day and PM of the next day; when none is later, the rule without `after` applies.
5. If `allowNextDay` and the result is not later than `after`, add 1440.
6. Outside `min`..`max`: no commit, error "Enter a time from {min} to {max}."

Examples: `11` -> 660; `2` -> 840; `930` -> 570; `14:15` -> 855; `12a` -> 0; `1am` with `after: 1320, allowNextDay` -> 1500; `2` with `after: 1290, allowNextDay` -> 1560.

The field keeps a draft while focused and commits on blur or Enter (`commitTime` in `kit.ts`); a field left as it was found commits nothing. Because the value is shown through `fmtClock`, text that still ends in "(next day)" keeps that meaning: the time before the marker is read without `after` and 1440 is added. Empty commits null. Text that is not a time does not commit and shows "That is not a time. Type one like 11 or 2:30 pm."; the range is `min`..`max`, by default 0 to 1440, or 0 to 2880 with `allowNextDay`, and its message prints both bounds with `fmtClock`. The two buttons and Arrow Up and Down move by `step`, commit at once and are disabled where the step would leave the range; from an empty field they give `after + step`, or 11:00 AM without `after`. Escape restores the last committed value. Accessibility: labelled input, `aria-describedby` for help ("Type a time, like 11 or 2:30 pm.") and the error, buttons labelled "15 minutes earlier" and "15 minutes later".

### 3.7 `Modal`, `Sheet`

```ts
interface ModalProps { open: boolean; onClose: () => void; title: string; children: ReactNode; footer?: ReactNode; size?: 'sm' | 'md' | 'lg'; dismissible?: boolean }
interface SheetProps { open: boolean; onClose: () => void; title: string; children: ReactNode; footer?: ReactNode; side?: 'right' | 'bottom'; width?: 420 | 560; modal?: boolean }
```

- Both render through a portal into `document.body`. The scrim is the class `.tp-scrim` from `truck.css` (`position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45); z-index: 50`), not the Tailwind classes `fixed inset-0`: that keeps the global rule `.fixed.inset-0 > div[class*="rounded"]` (which leaves a transform on the panel) and the orphan-overlay sweeper in `App.tsx` away from it. No blur.
- Modal: centred, max width 400 / 520 / 720 px, radius 12 px, `.shadow-float`; under 640 px it becomes a bottom panel (full width, top corners 12 px, max height `90dvh`, scrolls inside). Escape and scrim click close when `dismissible` (default true); a scrim click counts only when the press began on the scrim, so a drag that leaves a field does not close the dialog, and a modal that is not `dismissible` has no close button. Focus is trapped, moves to the first field or the heading on open and returns to the opener on close. `role="dialog" aria-modal="true" aria-labelledby`. The panel is `.tp-modal` (`max-height: 90dvh`).
- Sheet: `side="right"` is a panel of `width` px, full height under the sub-nav on desktop and full screen under 768 px; `side="bottom"` rests at 45 % of the height and expands to 92 % with a labelled button ("Show more" / "Show less"), no drag gesture. The right panel starts at the bottom edge of the sub-navigation, which it measures when it opens and on resize (`nav[aria-label="Truck Planner sections"]`; 98 px when there is none). With `modal` (the default) the sheet is a `role="dialog"` like the modal: scrim, focus trap, focus on the heading, Escape and scrim click close. With `modal={false}` (the spot card on the map) there is no scrim and no focus trap, focus stays where it was, the rest of the page stays usable, Escape closes the sheet only while focus is inside it, and the panel is a `<aside aria-label>` with a 44 px close button. Such a sheet sits in `.tp-layer` (`position: fixed; inset: 0; pointer-events: none`, the panel alone taking pointer events) at `z-index` 50 under 768 px, where it covers the screen like any sheet, and at 20 from 768 px, on the level of the floating map cards and under the menus of the top bar. Entry animation is the existing `.panel-slide-right` or `.panel-slide-up` (220 ms, off under reduced motion).
- Layers stack. Only the newest open layer answers Escape and traps Tab, so a confirmation opened from a sheet closes alone and leaves the sheet as it was; the page behind does not scroll while a modal layer is open. Escape goes first to the innermost thing that has a use for it: a number or time field holding an edit it has not committed, or showing its own refusal, restores its value, and a chip whose hint is open closes the hint. Such a control carries `data-tp-escape` while that is so; the layer leaves that Escape alone and closes on the next one. The panel of every layer carries `data-tp-layer`; a hint opened inside one is placed at `z-index` 60, above the layer, and at 40 elsewhere.

### 3.8 `Tabs`, `Toggle`, `Field`, `DateStepper`

- `Tabs`: `{ tabs: { id: string; label: string; count?: number }[]; value: string; onChange: (id: string) => void; variant: 'underline' | 'segmented'; ariaLabel: string }`. `role="tablist"`, roving tab index, Left and Right arrows move (they wrap) and select at once, Home and End jump, `aria-selected`. The panel of a tab is `<TabPanel tabs={ariaLabel} id={tabId} active?>`: `role="tabpanel"`, labelled by its tab, hidden while `active` is false. The ids of a tab and of its panel come from `tabDomIds(ariaLabel, tabId)` (`tp-<label>-<id>-tab` and `tp-<label>-<id>-panel`, both parts reduced to lower-case letters, digits and hyphens), and the selected tab carries `aria-controls` only while that panel is on the page: a tab list used as a plain switch (the map layer, a window length) has no panels. Segmented: track `--bg-panel`, selected pill `bg-white` with `--ink` text, others `--slate`, all weight 700, 36 px tall (44 px under `md`); each tab is as wide as its label and the tabs share what room is left equally, so labels of different lengths ("Each spot's best window", "The same window") are never cut, whether the control takes the width of its labels or is stretched over a card. Underline: 2 px `--brand` under the selected label.
- `Toggle`: `{ id; label; checked; onChange; help?; disabled? }`, `role="switch"`, 44 x 24 px track, the label is clickable, on = `--brand`; the knob carries a tick when on, so the state does not rest on colour.
- `Field`: `{ id; label; help?; error?; required?; unitWords?; className?; children }`, the label, help and error wrapper used by every control that is not one of the fields above (selects, text inputs, textareas) and by those fields themselves. `children` is the control, or a function that receives `{ id, 'aria-describedby', 'aria-invalid', 'aria-required' }` to spread on it; a control passed as a node takes the same attributes from `fieldControlProps(id, help, error, required)`. The help has the id `{id}-help` and the error `{id}-error`. `unitWords` ("dollars", "minutes") is screen-reader text inside the label.
- `DateStepper`: `{ date: string; onChange: (d: string) => void; min?: string; max?: string }`. Previous and next buttons ("Previous day", "Next day"), the date printed with `fmtDay(date, 'medium')`, a "Today" button, and a native `<input type="date">` behind a calendar icon for jumps. "Today" is `useNow().date`, the civil date in the truck's time zone (2.7), never the device's; the button is disabled on that date and when it lies outside `min`..`max`, and so are the two arrows at the ends of the range. Only `YYYY-MM-DD` strings cross its boundary.

### 3.9 `DataTable`

```ts
interface Column<T> { key: string; header: string; align?: 'left' | 'right'; width?: string; render: (row: T) => ReactNode; sortValue?: (row: T) => number | string }
interface DataTableProps<T> { caption: string; columns: Column<T>[]; rows: T[]; rowKey: (row: T) => string; sort?: { key: string; dir: 'asc' | 'desc' }; onSort?: (key: string) => void;
  onRowClick?: (row: T) => void; mobileCard?: (row: T) => ReactNode; empty?: ReactNode; dense?: boolean }
```

A real `<table>` in `.tp-scroll-x bg-white border rounded-xl` (border `--line-soft`; `.tp-scroll-x` of 7.1 scrolls sideways without widening anything around it), `<caption className="sr-only">`, header row `text-[11px] font-bold uppercase tracking-wider` in `--slate` on `--bg-panel`, body rows `border-t` with 12 px padding (8 px when `dense`), text cells weight 600 in `--ink`, number cells `text-right tabular-nums` weight 700. Sortable headers (the columns with a `sortValue`, when `onSort` is given) are buttons with `aria-sort`. The table orders the rows itself from `sort` and that column's `sortValue` (`sortRows` in `kit.ts`); the caller keeps `sort` in its own state and turns it in `onSort`, normally with `nextSort(sort, key)`: a new column starts ascending, the same column turns round. Strings sort byte-wise, numbers numerically, a missing number (`NaN`) last in either direction, ties by `rowKey` ascending. Under 640 px, when `mobileCard` is given, the table is replaced by a list of cards; otherwise it scrolls sideways. Clickable rows are also reachable by keyboard (Enter) and carry a visible chevron; a click on a link, a button or a field inside a row stays with that control. Without rows the table prints `empty`, or "Nothing here yet." when none is given.

### 3.10 `HourBars`, `WeekStrip`, `Timeline`

Hand-rolled SVG with a fixed `viewBox`, `width="100%"`, `role="img"` (`group` or `grid` when the chart takes picks), an `aria-label` that states the point in words, colours from CSS variables, axis text at weight 700 in `--slate`, gridlines `--line-soft`. Text sizes are units of the view box, so text scales with the drawing: `HourBars` (336 units wide) and `WeekStrip` (342) set their axis text at 11 units and are never wider than 440 and 470 px (342 px for a compact strip), which keeps that text between about 10 px across a phone card and 15 px on a desktop; they are never narrower than 288 and 300 px (280 px for a compact strip) and scroll sideways in a narrower box. The `Timeline` bar (720 units, text at 10) is never narrower than 650 px and scrolls sideways inside `.tp-scroll-x` below that. At those smallest widths the text is still 9 px. `HourBars` and `WeekStrip` take an `ariaSummary` string and the screen prints the same sentence above the chart; `Timeline` writes and prints its own.

- `HourBars`: `{ values: number[] /* 24 */; yMax: number; capacity?: number; cursorHour?: number; window?: { startHour: number; endHour: number }; onPickHour?: (h: number) => void; height?: number; ariaSummary: string }`. Twenty-four bars in `--accent-brand`, the selected window at full strength and the rest at 45 %, a dashed capacity line labelled "Truck limit", ticks at `12a 6a 12p 6p`. `yMax` is given by the caller (normally the truck's orders per hour) so bars are comparable between spots. `window.endHour` is not part of the window. A bar above `yMax` is cut at the top, and the capacity line is drawn when `0 < capacity <= yMax`. Gridlines sit at the thirds of `yMax` when those are whole numbers, else at the half and the top, else at the top alone. `height` is in view-box units (default 132). With `onPickHour` the hours are a row of buttons with one tab stop: Left and Right move (they wrap), Home and End jump, Enter or Space picks; each has a title such as "12 PM: 29.4".
- `WeekStrip`: `{ values: number[] /* 168 */; yMax: number; windows?: { how: number; hours: number; label: string }[]; cursorHow?: number; onPickHow?: (how: number) => void; compact?: boolean; ariaSummary: string }`. Seven rows (Mon to Sun) by 24 columns. A cell's colour is the opportunity ramp of 5.5 at `scoreByte(value, yMax)`; callers pass `yMax` = seed `map.opportunity_hi`, so a colour means the same number here and on the map; a cell under the floor of that layer (`minByte`, 5.5), zero included, is `--bg-panel`. The ramp follows the theme (`<html data-theme>`), as the map's does. Best windows are outlined in `--ink` (2 px) and numbered in the order given; a window that passes midnight continues on the next row and one that passes Sunday night on Monday's, and the number sits on the row that holds its first hour. The cursor is a 2 px ring. Row labels `Mon`..`Sun` at the left, hour ticks on top. With `onPickHow`, cells are a roving-focus grid: arrows move (they wrap within the row and the column), Home and End jump to the ends of the row, Enter or Space picks, each cell has a title such as "Thu 12 PM". A column is 13 of the strip's 342 units: about 13 px across a 375 px window, 10 to 11 px inside a padded card; `compact` drops the hour ticks.
- `Timeline`: `{ timeline: Timeline; stopNames: string[]; orientation?: 'auto' | 'list' | 'bar' }`. Driven by `timelineSegments(timeline)` in `utils/truck/timelineView.ts`, which turns the model's events into segments `{ kind: 'prep' | 'drive' | 'wait' | 'setup' | 'service' | 'teardown' | 'closeout'; from: number; to: number; stopIndex: number | null; unpaid: boolean }` (`stopIndex` is the stop a stretch belongs to, for a drive the stop it leads to; it is null for prep, close-out and the drive home; a stretch of no length is left out). For the blueprint day sheet it yields prep 574-619, drive 619-630, setup 630-660, service 660-840, teardown 840-860, drive 860-870, wait 870-990, setup 990-1020, service 1020-1200, teardown 1200-1220, drive 1220-1221, closeout 1221-1251. `bar` (default from 1024 px): one horizontal bar with segments in proportion and clock labels at the stop boundaries. The marks come from `timelineMarks(timeline)`: every opening and closing time (`kind: 'stop'`) and the start and the end of the day (`kind: 'day'`). A label that would come closer than 8 units to one already placed is dropped (`timelineLabelKeep` in `kit.ts`), and the opening and closing times are placed first, so it is the start or the end of the day that gives way: the blueprint day prints 9:34 AM, 11 AM, 2 PM, 5 PM and 8 PM and drops 8:51 PM. A service stretch carries the stop's name above the bar and a wait its label, cut to the width it has; every stretch has a title such as "Reston office park: Serving, 11:00 AM to 2:00 PM (3 h)"; a legend under the bar names the colours in use. `list` (default below 1024 px, and always on the day sheet): one row per event from `timelineRows(timeline, stopNames)`, time at the left in `tabular-nums` weight 700; under "Leave base" and "Leave {stop}" a second line gives the drive that follows ("Driving, 11 min") and under "Arrive at {stop}" the wait, when there is one ("Waiting, paid, 2 h"). Above either form the component prints `timelineSummary(timeline)`, which is also the bar's `aria-label`: "The day runs from 9:34 AM to 8:51 PM: 11 h 17 min with 2 stops and 22 min of driving."; an empty timeline prints "Nothing is planned for this day." and nothing else. A stop without a name reads "Stop 1", "Stop 2". Event labels: "Start prep", "Leave base", "Arrive at {stop}", "Start setting up" (only when there is a wait), "Open", "Close", "Leave {stop}", "Back at base", "Done". Segment colours are identity tokens from `truck.css` (7.1); a wait is hatched and labelled "Waiting, paid" or "Break, unpaid" so the difference never rests on colour.

### 3.11 `StatRow`, `EmptyState`, `QueryError`, `Skeletons`

- `StatRow`: `{ label: string; value: ReactNode; sub?: ReactNode; strong?: boolean; negative?: boolean }`: label left in `--body` weight 600, value right in `--ink` `tabular-nums` weight 700 (800 when `strong`), optional second line 12 px in `--body`; `negative` gives the value `--money-negative` (the caller writes the minus sign or the word). Rows sit in a `<dl>`: `StatList` (`{ children; className? }`), which draws a hairline between them. Used for cost lines, drive lines and settings summaries, never for an estimate (that is `RangeValue`).
- `EmptyState`: `{ icon: LucideIcon; title: string; body: string; action?: { label: string; onClick?: () => void; to?: string }; secondary?: {...} }`. A dashed 2 px `--brand-light` card, `bg-white`, radius 12 px, a 56 px icon tile (`--brand-light` background, `--brand` icon), title 18 px weight 800 in `--ink`, body 14 px in `--body`, one primary action 44 px tall.
- `QueryError`: `{ message: string; onRetry?: () => void }`, `role="alert"`, a bordered white card with the sentence in `--ink` weight 700 and a secondary button "Try again".
- `Skeletons`: `SkeletonCard` (`{ height?; className? }`, 96 px), `SkeletonRows` (`{ rows?; rowHeight?; className? }`, three rows of 72 px) and `SkeletonChart` (`{ height?; className? }`, 140 px), built on the existing `.skeleton` class, wrapped by the caller in `aria-busy="true"`.

### 3.12 `PermissionNotice`, `SourceLine`, `SeedTag`

- `PermissionNotice`: `{ variant: 'line' | 'block' }`, the standing notice of 6.3. `line` is one sentence at 12 px weight 600 in `--body` with an `Info` icon. `block` is a bordered panel on `--bg-panel` with the two-sentence version. It has no close button and no "do not show again".
- `SourceLine`: `{ kinds: ('map' | 'osm' | 'osm_sentence' | 'vintages' | 'drive')[]; vintages?: RegionInfo['vintages'] }` prints the attribution strings of 6.4 in the order of `kinds`, with the OpenStreetMap text linked to `https://www.openstreetmap.org/copyright` and the jobs line of `vintages` to `https://lehd.ces.census.gov/data/` (new tab, `rel="noopener noreferrer"`). `map` and `vintages` need `vintages` and are left out without it; with nothing to print the component renders nothing.
- `SeedTag`: `{ tag: 'measured' | 'derived' | 'assumed' | 'tuned'; size?: 'sm' | 'md' }` -> "Measured", "Derived", "Assumed", "Placeholder", a neutral chip like `ConfidenceChip`, with a hint (6.6) and a glyph of its own (`Ruler`, `Calculator`, `PenLine`, `Hourglass`).

### 3.13 `WeatherChip`, `HolidayChip`, `WarningList`

- `WeatherChip`: `{ forecast: (HourForecast | null)[] | null; fromHour: number; toHour: number; info?: Pick<ForecastInfo, 'state' | 'generated_local'> | null }` (`forecast` is the 24 hours of one date, `DayContext.forecast`; `info` is the `forecast` block of the day-context answer). Over the clock hours from `fromHour` up to, not including, `toHour` (at least the hour `fromHour`): the temperature range (`55 to 62°F`, or one figure), then " · " and the worst precipitation class among those hours as the model classifies it (`weatherMultiplier(...).precip_class`) with a percentage: "55 to 62°F · Rain 40%", "Storms 60%", "Snow 70%"; "Dry" carries none. The worst class is the one whose `open` multiplier in `weather.precip_classes.rows` is lowest; on a tie, the one earlier in `weather.precip_classes.order`. Class words: `storm` "Storms", `heavy_snow` "Heavy snow", `ice` "Ice", `heavy_rain` "Heavy rain", `snow` "Snow", `light_rain` "Light rain", `rain` "Rain", `dry` "Dry"; a class this build does not know reads "Rain or snow". The percentage is the highest non-null `precip_prob` among the hours of the worst class, printed as `fmtPercent(precip_prob / 100)` (`HourForecast.precip_prob` is 0 to 100; `fmtPercent` takes a fraction). When every such hour has a null `precip_prob` the chip prints the class word alone ("Rain") and its title ends with "The forecast gives no chance for these hours." Icon: `CloudRain` for any class but `dry`; on a dry stretch `Wind` when an hour's wind lowers demand, else `Thermometer` when an hour's temperature does, else `Sun`. No usable forecast: "No forecast yet" with helper "Forecasts cover about six days."; with the optional prop `passed` (the stretch is over: a stop of today that has closed, a planner date before today) it reads "No forecast" with helper "These hours have passed.", because the forecast only holds hours still to come and "not yet" would be wrong about hours behind us. The forecast is the one for the truck's base (the server fetches no other), so the chip's title reads "Forecast for the area around your base"; with `info.state` `stale` it adds " from {fmtClock(info.generated_local.minute)}, may be out of date" (`generated_local` is the server's reading of `generated_at` in the truck's time zone: no ISO string is parsed in the browser and `clock.ts` needs no function for it).
- `HolidayChip`: `{ context: DayContext }`: the holiday name with a `Flag` icon; "Treated as a Saturday" (and so on) when `treat_as` is a day; "Holiday ignored" when `treat_as` is `normal` on a holiday; "Treated as a holiday" when `treat_as` is `holiday` on a date that is not one; nothing on an ordinary day.
- `WarningList`: `{ result: DayResult; stopNames: string[]; context: DayContext }` prints the model's warnings in their given order with the sentences of 6.5. Level `error`: `TriangleAlert` in `--money-negative`, prefix "Problem:"; `warn`: `TriangleAlert` in `--fresh-aging`, prefix "Check:"; `info`: `Info` in `--slate`, prefix "Note:". The prefix is visible text.

### 3.14 `OpenInMaps` and `utils/truck/links.ts`

Free Google Maps URLs, no API call. The server already supplies `Spot.maps_url`, `Plan.maps_route_url` and `ScoutCandidate.maps_url`; those are used as they are. `Plan.maps_route_url` is null for a plan without stops, and the route link is then not shown. `links.ts` builds the same shapes for things the server has not seen (a clicked point, an unsaved day, one leg). Coordinates are written with exactly six decimals through `roundHalfAway` (`38.960000`), as the server writes them.

| Function | URL |
|---|---|
| `mapsSearchUrl({ lat, lng })` | `https://www.google.com/maps/search/?api=1&query=<lat>%2C<lng>` |
| `mapsDirUrl({ origin, destination, waypoints })` | `https://www.google.com/maps/dir/?api=1&origin=<lat>%2C<lng>&destination=<lat>%2C<lng>&waypoints=<lat>%2C<lng>%7C<lat>%2C<lng>&travelmode=driving`; `waypoints` is omitted when empty |

`OpenInMaps`: `{ href: string }` or `{ point: { lat; lng } }` or `{ route: { origin; destination; waypoints } }`, plus `label?` and `variant?: 'link' | 'button' | 'primary'` (an underlined text link, a secondary button, or the one primary action of a view such as the day sheet). An `<a target="_blank" rel="noopener noreferrer">` with an `ExternalLink` icon. Default labels: "Open in Google Maps" and "Open route in Google Maps". A day's route is base -> stops in order -> base, which `dayRoute(base, stops)` in `links.ts` builds. The origin is always given explicitly, so the link never asks Google for the device's position. Google documents a cap of nine waypoints, three in mobile browsers **[M]**; with more than three stops the Planner also shows a per-leg link on every leg row.

---

## 4. Screens

Conventions for this section. "Desktop" is 1024 px and wider, "tablet" 768 to 1023 px, "phone" 375 px (everything must also hold at 360 px). Quoted text is the exact wording. `{...}` marks a value filled by the formatter named in section 3. Every list of saved things stays visible on its page; nothing primary hides behind a tab or a modal. Each page has one `<h1>` (`text-2xl font-extrabold` in `--ink`, a 22 px lucide icon in `--brand` before it) and at most one primary button.

### 4.1 Today (`/truck`, package FE-5, wave 2)

Purpose: where am I going, what will I clear, what is the weather, what is left to log. No map on this page.

Needs: `useNow()`; `listPlans(today - 7, today + 6, { stops: true })`; today's plan (the row of that date) and its evaluation; `useDayContexts(today, 2)`; `listServices` for the last 7 days; the spot list (archived included).

| Width | Layout |
|---|---|
| Desktop | two columns, 8 + 4 of 12: left = Next, Today's plan; right = Log what happened, Weather, This week, Fuel |
| Tablet, phone | one column in this order: Next, Today's plan, Log what happened, Weather, This week, Fuel |

| Block | Content |
|---|---|
| Header | `<h1>` "Today", then `{fmtDay(today, 'medium')}`, `HolidayChip`, `WeatherChip` for the plan's hours (11 to 20 when nothing is planned) |
| Next (only with a plan that has a timeline) | Caption "NEXT". One sentence in `text-3xl font-extrabold`, chosen by `nextAction(timeline, minute)` from the first timeline event later than now: `start_prep` "Start prep at {t}"; `leave_base` "Leave base by {t}"; `arrive` "Arrive at {stop} by {t}"; `setup_start` "Start setting up at {t}"; `open` "Open at {t}"; `close` "Serving until {t}"; `leave` "Pack up and leave by {t}"; `back_at_base` "Back at base about {t}"; `done` "Finish close-out by {t}"; none left "Done for today." with the button "Log your orders". Under it, 12 px: "From your plan and the clock. Truck Planner does not know where the truck is." |
| Today's plan | Title "Today's plan". One row per stop: name, `{fmtWindow}`, `RangeValue` (orders, inline, sm). Then `RangeValue` (money, lg, label "TAKE-HOME", `onWhy`), a line "{fmtDuration(day_minutes)}, prep to done", and "{n} things to check" linking to the planner when there are warnings. Buttons: `OpenInMaps` with `plan.maps_route_url` (primary until the day is done), "Edit plan", "Day sheet" |
| Log what happened | Title "Log what happened". Up to five unlogged stops from the last seven days (`unloggedStops` in `logView.ts`: stops of plans that are not cancelled, whose closing time has passed, with no `ServiceLog` carrying their `plan_stop_id`): "{fmtDay} · {stop} · {fmtWindow}" with the button "Log it" (to `/truck/log?new=1&stop=..&spot=..&date=..&open=..&close=..`). Then the latest logged service: "{spot}, {weekday}: {actual} orders." and, when it has a `prediction`, "The estimate was" followed by `RangeValue` (orders, inline, sm) built from `prediction` (`value: predicted`, `low`, `high`, `confidence`) and the verdict tag (4.7) |
| Weather | One `WeatherChip` per stop for its own hours, then "Forecast for the area around your base. National Weather Service (weather.gov)." |
| This week | "{n} of 7 days planned" and the link "Open week" |
| Fuel | `StatRow` "Regular gasoline" (`product` `EPMR`) or "Diesel" (`EPD2D`), value `{fmtFuel(price_per_gal)}`, second line by `FuelInfo.source`: `eia` "EIA weekly average, week of {fmtDay(period, 'short')}"; `owner` "Your price"; `seed` "Default price from the week of {fmtDay(period, 'short')}. No current figure." Link "Change" to Settings |

States: each block has its own skeleton and its own `QueryError`; one failed request never blanks the page. Empty states: no spots (`counts.spots` is 0): `EmptyState` (icon `MapPinned`) "Start with the map" / "Open the map, click where you might park, and save the spots worth a closer look." / button "Open the map". Spots but no plan today: `EmptyState` (icon `Route`) "Nothing planned for today" / "Pick a saved spot and a time window to see drive times, costs and take-home." / button "Plan today". Nothing to log and nothing logged yet: "After a service, log your orders here. About ten logged services make the dollar figures worth trusting."

Starter page (ships with the foundation, replaced by FE-5): `<h1>` "Today" and three link cards: "Open the map" / "See where people are, hour by hour."; "Spots" / "{counts.spots} saved"; "Truck and costs" / "Ticket, crew, fuel and fees."

### 4.2 Map (`/truck/map`, package FE-2 on the engine of FE-1, wave 1)

Needs: `region` from the context (its `pack.url`, `pack.gz_bytes`, `vintages`, `center`, `bbox`); the decoded pack; the spot list; `profile.base`, `daypart_fit`, `capacity_orders_per_hour`; `cal.truck_factor`; the hour store and the UI store. Clicking needs `simulate` (through the spot card). Scout dots need the cached `scout` answer (wave 3).

| Width | Layout |
|---|---|
| Desktop | `TruckMap` fills the area above the hour bar. Floating, top left, 280 px: the layer switch card and the legend card. Floating, top right: the tools card. The spot card is a non-modal right `Sheet` (420 px) beside the map. The hour bar is docked under the map, 76 px, not floating |
| Tablet | the same; the legend starts collapsed to a button "Legend"; the spot card is a bottom `Sheet` in portrait and the right panel in landscape |
| Phone | layer switch as a full-width segmented control over the map; legend button; tools in one menu button ("Map options"); spot card as a bottom `Sheet`; hour bar in two rows (104 px) |

Floating cards are `bg-white rounded-xl border shadow-float`, 12 px from the edges, and never cover the bottom 28 px of the map: Google's logo and terms stay visible at every width. The hour bar is a flex child below the map for the same reason, and for the same reason the spot card takes its room from the page instead of lying over the map: while the card is open the page keeps its place free (420 px at the right, or the resting height of the bottom sheet, 45 % of the window), so the map and the hour bar end where the card begins. When the card opens, the map centres on the point or the spot it is about, which keeps the ring or the pin in view in the smaller map; while the card stays open, another click leaves the camera alone. The page's `<h1>` "Map" is visually hidden (`sr-only`): the map has the whole area.

Because the card takes room from both, the cards over the map arrange themselves by the width the map has and the hour bar by its own width, not by the window (`floatLayout` and `hourBarMode` in `utils/truck/hourControl.ts`, which also holds the rest of the page's logic that needs no DOM: playback, keys, the URL parameters, the hover hint, the status sentences):

| Map width | Cards over the map |
|---|---|
| from 880 px | layer switch and legend at the left, status chip in the middle, tools card at the right |
| 700 to 879 px | the same, with the status chip under the tools card |
| 560 to 699 px | the tools fold into the "Map options" button; the status chip sits under it. While the spot card stands beside a map of this width or less (a window from 1,024 to 1,119 px wide with the card open, a tablet held sideways), the legend closes to its "Legend" button (`legendGivesWay`): open, it would cover almost half of what is left of the map and the pin the card is about. The owner can open it there, and it returns to how it was when the card goes |
| under 560 px | the phone arrangement: the full-width layer switch, then the legend button beside "Map options", then the status chip. While the map is under 340 px high (a phone with the card open) the layer switch is left out |

| Hour bar width | Hour bar |
|---|---|
| from 960 px | one row with the seven day buttons |
| 640 to 959 px | one row with a day `<select>` |
| under 640 px | two rows (104 px): play, the day `<select>` and the label; then the slider, "Now" and the speed |

| Control | Content and behaviour |
|---|---|
| Layer switch | `Tabs` segmented, `ariaLabel="Map layer"`: "Opportunity", "People nearby", "Competition". Writes `truckUiStore.mapLayer` and `?layer=` |
| Legend | Title by layer: "Expected orders per hour", "People nearby", "Food competition". A ramp of 32 flat SVG rects sampled from the layer's colour table (rect `i` of 0..31 takes entry `8 * i + 4`; not a CSS gradient) with ticks at the values of 5.5, a marker at the hovered cell's value (none over a cell without colour; the page moves it imperatively, with the hint), the line "No colour: under {floor}", for Opportunity the line "Capped at your truck's {fmtCount(capacity)} an hour" under it (the scorer caps that layer at `profile.capacity_orders_per_hour`: with a truck that serves fewer than 45 an hour the dark end of the ramp never appears on the map, and the legend is where the owner learns why), a caption, and the source line (left out, with its divider, for a truck without a region, which has no vintages). The card has a button that collapses it ("Hide legend") at every width. Captions: Opportunity "Your truck parked at each hexagon in a typical week. No host, no weather. A rough guide for ranking places. Click a point for an estimate with its range. Colours near hospitals, campuses and stations rest on the weakest figures."; People nearby "People present within walking distance. Nearer people count more."; Competition "Pull of food outlets around each hexagon. 1 equals one quick-service outlet at the same spot." Source line (6.4 id 10), exact: "People: US Census 2020, LEHD 2023 · Venues: © OpenStreetMap contributors" (years from `region.vintages`, the OpenStreetMap part linked). When the legend is collapsed (tablet, phone) the source line stays visible beside the "Legend" button, in the same card (11 px, weight 600, `--body`, the OpenStreetMap part linked; beside the button it takes two or three lines on a phone) |
| Hour bar | Left to right: play button (`Play` / `Pause`, 44 px, `aria-label` "Play the week" / "Pause"); day picker (seven buttons "Mon" to "Sun" as a radio group with one tab stop; a `<select>` where the bar is narrower than 960 px); the hour slider (`<input type="range" min="0" max="23" step="1" aria-label="Hour of day">`, `aria-valuetext` = `fmtHowLong(how)`) with the hour strip above it; the label `{fmtHowLong(how)}` in weight 800; "Now" (jumps to the current hour in the truck's time zone); speed `<select aria-label="Playback speed">` "Slow", "Normal", "Fast" (1200, 600, 300 ms per hour). The bar is a `role="group"` named "Hour of the week". From wave 2 a mode switch "Typical week" / "Pick a date" with a `DateStepper` limited to today .. today + 6; a picked date uses that date's holiday pattern and never weather |
| Hour strip | 24 thin bars over the slider: the mean colour value of the cells in view for each hour of the selected day, relative heights only, no numbers, `aria-hidden`. Recomputed on map `idle` and on day or layer change, in an idle callback |
| Hover hint (fine pointers only) | A small fixed card near the pointer, moved imperatively. Line 1 is the legend band of the hovered cell, never a single number: "Orders an hour: {band}", "People nearby: {band}", "Competition: {band}" (bands in 5.5; an uncoloured cell reads the lowest band). Line 2: "Click for an estimate at this point". A 2 px `--ink` outline marks the hovered hexagon. The hint stays inside the map (at an edge it goes to the other side of the pointer, so it never lies over the spot card or the hour bar) and is not shown in pick mode, where a click does something else |
| Tools | Toggles "Saved spots" and "Scout results"; a button "Estimate at the centre of the map" (opens the spot card for the map centre, for keyboard and screen-reader use); "Go to base" (centres the map on the base). "Scout results" is shown only when there are results to show: a Scout answer in the query cache, `?scout=1` in the URL, or the toggle left on. That makes it a wave 3 control without a change to this page |
| Pins | Saved spots (not archived): a 28 px `--brand` teardrop with a white dot, as a `<button aria-label="Open spot {name}">`; the name shows in a white pill from zoom 13 and on hover or focus. Base: a 28 px `--ink` rounded square with the `Home` glyph, label "Base"; a click on it opens the spot card at the base. Selected point: a 20 px white ring with an `--ink` border, a button named "Close the estimate at this point". Scout results: 12 px `--accent-revenue` dots with a white border, label "{position}. {name}" on hover or focus. Pins are DOM elements above the canvas (5.7), each a 44 px button with its mark drawn inside; a click on a pin never also simulates the point under it. The light parts of the marks take `--bg`, so they turn with the theme like `--ink` does |
| Status chip (`role="status"`; top centre where the map is wide enough, see the table above) | One sentence with an icon; a second one for the last case, which the page says itself (`TruckMap` is given `hostNotice={false}`). By layer state (5.8): "Loading map data ({gz_bytes as MB, one decimal} MB, first time only)..."; "Zoom in to see the colours."; "Map colours are unavailable right now. You can still click the map for an estimate."; "The map data is from a different version. Reload the page."; "No map data for this area yet." (a null region or `not_loaded`) or, when `region.unusable_reason` is `build_mismatch`, "Map data is being rebuilt after an update. New estimates are unavailable until it finishes."; "The Google map could not load, so the background map is hidden. Estimates and saved spots still work." |
| Pick banner (`?pick=`) | "Click the map to set your base." or "Click the map where the spot is.", with "Use the centre of the map" (the way through without a pointer) and "Cancel". The cursor is a crosshair, no spot card is open, and a click on a pin picks that pin's place. A pick opens a `Modal`: "Set your base here?" with `{fmtCoord}`, the line "Where the truck starts and ends its day. It is only used for drive times and weather." and the buttons "Cancel" and "Set base" (route 3 with `base: { lat, lng, address: '' }`: a picked point has no address), or "Add a spot" with the spot form of 4.4 and the point filled in. After the base is set, or the spot saved, the page goes to `return` when the link gave one; without it pick mode ends and, for a spot, the card of the new spot opens. The warnings in the answer of route 3 (`base_outside_region`, `timezone_assumed`) show the two toasts of 1.4 (`PROFILE_WARNING_TEXT`, eight seconds each). "Cancel" and Escape leave pick mode the same way |

Interactions:

1. Click or tap on the map (not on a pin): open the spot card for the exact clicked coordinates (the hexagon is only a highlight), set `?pt=`, drop the selected-point ring. The card opens at once with its skeleton. Every place the page hands on (the point of the card, a picked base, the point of a new spot, the centre of the map) carries six decimals, the precision of `pt` (`placePoint`), so it is the same numbers whichever way the place was chosen.
2. Click a saved-spot pin: open the card for that spot, set `?spot=`. The pin of the open spot shows its name and an `--ink` outline.
3. Escape, the card's close button, or a click on the ring closes the card and clears the param. Escape does so with focus on the page, in the map or in the card; a dialog, a menu or a field that has a use for the key takes it first.
4. Keyboard while focus is on the page body or inside the hour bar, and never while typing (a text field, a `<select>`), with a modifier key, or while focus is inside the Google map (its own arrow keys pan): Space plays or pauses; Left and Right move one hour; Up and Down move one day (Up is the day before); Home is "Now". On the slider these keys replace its own, so the hour runs on through midnight instead of stopping at the end of the day. Space is left to a focused button, which it presses.
5. Playback runs from `requestAnimationFrame` with an elapsed-time accumulator (no `setInterval`, no React state), steps hour by hour without blending, wraps from Sunday 11 PM to Monday 12 AM, and stops when the tab is hidden, when the slider or a day is touched, when a best window or a week-strip cell of the card is picked, and on unmount. A frame that arrives late moves at most four hours, so playback picks up where it stopped (`advancePlayback`).
6. Camera changes are written to `truckUiStore.mapCamera` and the URL on map `idle`. A camera that reads the same in the URL as the one before (the map's first frame) is not a change.
7. The canvas is `aria-hidden`. The map region has `aria-label="Map of {layer title}. Use Spots and Scout for the same information as lists."`.

### 4.3 Spot card (`components/truck/spot/`, package FE-2, wave 1)

One component, `SpotAnalysis`, renders the sections below; `SpotCard` wraps it in the map's `Sheet`, and the spot detail page embeds it. Input: a point or a `Spot`, plus terms. Everything shown is computed by `useSpotEstimate` from exact vectors.

| # | Section title | Content |
|---|---|---|
| | Header | Title: the spot's name, or "This point". Under it `{fmtCoord}` and the county name (`located.county_fips` looked up in `region.counties`, with its state: "Fairfax County, VA"). `OpenInMaps` (`spot.maps_url`, or the point). Close button (`aria-label="Close"`). The title and the close button are the sheet's; in the `page` layout the page has its own title and Google Maps link, and `SpotAnalysis` prints the coordinates and the county only. A saved spot whose vectors are `stale` carries the tag "Updating" ("Out of date" while the region data is being rebuilt), an archived one "Deleted" (in the card; on the spot page that tag stands beside the page's own title and is not repeated here) |
| A | "This hour" | `RangeValue` (orders, lg, `onWhy` opening `WhyDrawer` for that hour) for the one-hour window at the settled hour, label `{fmtHowLong(how)}`, note "Limited by how fast the truck can serve." when that hour is capped. Line: "About {fmtAbout(people)} people within walking distance at this hour. Nearer people count more." (the sum of `nearby_present` over the segments: the distance-weighted count the "People nearby" layer of the map colours by. The second sentence is there because step 1 of the breakdown counts everybody within the walking cutoff where the vectors carry that figure, and so shows a larger number for the same hour; it is left out when the sum is under 1). A host's own people are not in that sum, so with a host the line goes on: " The host has about {fmtAbout(people_present)} of its own there." |
| B | "Best windows" | `Tabs` segmented "2 hours", "3 hours", "4 hours" (stored in `windowHours`). The top three non-overlapping windows of the typical week (`bestWindows(week, hours, 3, true)`), each a row: rank, `{fmtWeekday} {fmtWindow}`, `RangeValue` (orders, inline, sm). Selecting a row (its rank and window are a button with `aria-pressed`) moves the hour store to its first hour and makes it the window for section F. Saved spots get "Plan this" per row (to `/truck/plan/{nextDateWithDow(today, dow)}?add={spotId}&open=..&close=..`). No window: "No hour of the week reaches one order here."; sections F and G are then left out |
| C | "Week at a glance" | `WeekStrip` with `yMax` = seed `map.opportunity_hi`, the three windows outlined, the cursor at the current hour; picking a cell sets the hour. Above it, and as its `ariaSummary`: "The busiest stretch of a typical week here is {window 1}." (or the "No hour..." sentence of B). Caption: "Expected orders per hour in a typical week, capped at your truck's {capacity} an hour. Same colours as the map." |
| D | "Who is here" | For the settled hour: the host first when there is one ("Host: {name or segment label}", people present), then segments with at least 1 % of the hour's orders, largest first, at most six, with "Show all" (every segment that has people or orders). Each row: the seed's segment label, "about {fmtAbout(nearby_present)} people", a bar in the group colour (7.1) as long as the segment's share of the hour's orders, and `{fmtPercent(orders / the hour's orders)}` "of this hour's orders". A legend under the rows names the three group colours ("Residents", "Workers", "Visitors and hosts"). The arrangement is `whoIsHere(hour)` in `utils/truck/hourControl.ts`. Footnote: "Nearer people count more. Workers are counted from jobs at nearby addresses and the share usually on site at this hour." (the counts are distance-weighted, as in section A; the first sentence is left out when no segment row is shown or the summed `nearby_present` is under 1, because a host's own people are a plain head count). When the hour's orders are 0 the segments are listed by `nearby_present`, largest first, under the line "No orders expected at this hour." "Almost nobody is within walking distance at this hour." is shown only when the summed `nearby_present` is under 1 |
| E | "Competition" | `StatRow` "Pull of nearby food outlets" = `{fmtCount1(rivals[regime])}`, second line "day weights" or "evening weights" by the hour's regime. Helper: "1 equals one quick-service outlet at this exact point. Higher means more of the people here buy elsewhere." Then "Food outlets within walking distance": the nearest eight `OutletRow`s (name or "Unnamed", kind label, `{fmtMiles(distance_m / 1609.344)}`), "Show all" for the rest of the list, "and {outlets_total - shown} more" when the server cut it at 60, and `SourceLine` (`osm`). Kind labels by `rival_kind`: `quick` "Quick service", `full` "Sit-down restaurant", `cafe` "Cafe or bakery", `bar` "Bar", `convenience` "Convenience or grocery". None: "No food outlets within walking distance in our data." The list comes with a `simulate` answer. A saved spot is estimated from its stored vectors without one, so its card asks for the list separately (`withPlaces` of `useSpotEstimate`, 2.5) and shows a skeleton row for it until it arrives. When that request fails (`placesStatus` is `error`) the list is replaced by `QueryError` "Could not load the food outlets here." with "Try again", while every number of the card stands; nothing is listed and nothing is said about the list while the region data is being rebuilt (`idle`) |
| F | "Money" | For the selected window (default: best window 1; the heading repeats it). A three-column table "Expected / Weak day / Strong day" (`value`, `low`, `high` of each `StopMoney` line): "Sales", "Food cost", "Packaging", "Card fees", "Spot fee", "Tips" (only when tips are counted). The lines are the model's `stopMoney` on the window's orders, so the table never waits for a request. Then `RangeValue` (money, md, label "LEFT AFTER FOOD AND FEES") for `contribution`. `StatRow` "Each order leaves" `{fmtMoneyCents(unit margin)}`: `unit_margin.at_minimum` while the minimum fee is what is paid at the expected sales, else `at_percentage` (the rule of step 12 of the breakdown). Then the one-stop day (`oneStopDay`; a skeleton while the drive legs of a saved spot are on their way): `RangeValue` (money, md, label "TAKE-HOME FOR A ONE-STOP DAY", `onWhy` opening `WhyDrawer` with `{ kind: 'day' }` and that `DayResult`), sub-line "Includes prep, the drive from your base, wages and fuel."; `StatRow` "Break-even" `{fmtCeil(break_even_orders)} orders`, or, when it is null, the value "None" with the second line "This spot cannot break even at your ticket and costs."; `StatRow` "Drive from base" `{fmtDuration} each way, {fmtMiles}`, or "{fmtDuration} there, {fmtDuration} back, {fmtMiles}" when the two legs take different times (traffic at the two times of day), with the source label of 6.7 as its second line. For a clicked point the drive is always the straight-line estimate (no request is spent on a click); a saved spot uses `useDriveTimes` |
| G | | Secondary button, full width: "Why this number" (opens `WhyDrawer` for the selected window) |
| H | Footer | `PermissionNotice` (`line`), `SourceLine` (`vintages`). Primary button: "Save as spot" for a point; "Open spot" for a saved spot, with a secondary "Plan a day here" (to `/truck/plan/{today}?add={id}`). In the card the buttons are a bar that stays at the bottom edge of the sheet while the sections scroll. The `page` layout has no buttons for a saved spot (the page has its own), and an archived spot has none anywhere |

States: pending: skeleton blocks in the shape of sections A to F. Failed: `QueryError` "Could not estimate this point." Outside (`located.in_region` false and `vectors.points_used` 0; for a saved spot the same two fields of its vectors): heading "Outside the loaded area", text "This point is outside {region.name}. There is no local data here, so nothing can be estimated." and "Save as spot" stays available with the note "Only a host you describe will count." With a null region the text is "There is no local data for this area, so only a host you describe will count." and the note is not repeated. A place in that state whose terms do describe a host is estimated from the host alone: the card renders normally with the line "Outside the loaded area. Only the host you described counts." under the header. With `in_region` false and `points_used` above 0 the card renders normally with the line "Just outside the loaded counties. People across the county line are only partly counted." under the header. Rebuilding (while `region.unusable_reason` is `build_mismatch` no `simulate` is sent): for a clicked point the card shows "Map data is being rebuilt after an update. New estimates are unavailable until it finishes." in place of sections A to G, with "Save as spot" disabled (route 11 would answer the 409 of 2.6); a spot edit that needs new vectors keeps its last matching estimate dimmed. While vectors are being re-requested after a host edit, or terms and vectors do not match (rule 6 of 2.5), numbers stay on screen dimmed (`RangeValue dim`). Section F uses the fuel price of the context (`TruckContext.fuel`), so it never waits for a request. A saved spot without stored vectors (`none`) shows "No estimate yet" in place of sections A to G.

On the map the card is opened by `?spot=<id>` for a spot of the list (not archived). While the list is on its way the card shows a skeleton; when the list could not be loaded, `QueryError` "Could not load your spots." with "Try again"; when the id is not in the list, "This spot no longer exists." with the link "All spots".

`SpotAnalysis` marks `tp:spot-compute` (5.9) with `performance.measure`: from the start of the render that first has the vectors of an estimate to the commit that puts its numbers on screen.

"Save as spot" opens the spot form (4.4) in a `Modal` (lg) titled "Add a spot", with the point filled in and the `hosts_nearby` of the estimate handed over. After the save the card is the card of the new spot (`?spot=<id>`). If `hosts_nearby` holds a place within 60 m, the form starts with the question "Is this at {name} ({place type label}, {distance} away)?" and the buttons "Yes, link it" and "No", with `SourceLine` (`osm`) under the question.

### 4.4 Spots (`/truck/spots`, `/truck/spots/:spotId`, `/truck/spots/compare`, package FE-3, wave 1)

**List.** Needs `useSpots({ archived: false })`, profile, assumptions, calibration. `spotSummaries(spots, A, profile, cal, hours)` in `utils/truck/spotSummary.ts` computes, per spot, the best window of the typical week, its orders and its contribution in one pass from `spotVectors(spot)`. `hours` is the window length chosen on the spot card (`truckUiStore.windowHours`), so the list and the card name the same window. A spot whose stored vectors do not match its own terms (rule 6 of 2.5) gets no figure, since the estimator is never called on such a pair; it carries the tag of a stale spot until the refresh brings new vectors. The same file holds the rest of the package's logic that needs no DOM: the order and the search of the list, a chosen window and the one-stop day of a saved spot for the compare page (`spotWindowFigures`, `spotOneStopDay`, built from exactly the stop, contexts and leg keys `useSpotEstimate` uses, so the pages agree with the card), the fee and host phrases, and the spot form's draft, checks and request bodies.

| Element | Content |
|---|---|
| Header | `<h1>` "Spots", the count ("{n} saved"), primary button "Add spot" (opens the spot form in a `Modal` titled "Add a spot"; so does `?new=1`), secondary "Compare ({n})" (enabled with 2 to 4 rows ticked; the ticks are `truckUiStore.compareIds`), a search field (placeholder "Search spots"; it looks in the name, the host line and the address, and `?q=` follows 300 ms after the last keystroke), sort `<select>` "Best window first" (most expected orders first, spots without a figure last), "Name" |
| Table (`DataTable`, caption "Saved spots") | Columns: tick box; "Spot" (name, host name or segment label, address); "Best window (typical week)" (`{fmtWeekday} {fmtWindow}`); "Orders" (`RangeValue` inline sm); "Left after food and fees" (`RangeValue` inline sm); "Fee" ("No fee", "$75 flat", "10% of sales, $75 minimum", ...). A spot with `vectors_state` `stale` carries the tag "Updating" ("Out of date" while the region data is being rebuilt, rule 5 of 2.5); with `none` its estimate cells read "No estimate yet". Row click opens the spot. The six columns need about 960 px, with the two long headings on two lines there, so that the width goes to the figures and the fee. Where the list has less room (a phone, a tablet held upright) `SpotTable` shows the same facts as cards, two to a row from 620 px, with the spot's name as the link to its page; the choice follows the width the list itself has (a container query), so the table is never scrolled sideways |
| Under the table | `PermissionNotice` (`line`), rendered by `SpotsPage.tsx` itself (8.3) |
| Empty | `EmptyState` (icon `Store`): "No spots yet" / "Open the map, click a point and save it. Or add one by address." / button "Open the map", secondary "Add by address" |

**Spot form** (`SpotForm`, in a `Modal` (lg) for a new spot, inline on the detail page). Explicit "Save spot" / "Cancel"; nothing saves on blur, and Enter in a field commits that field without saving. It maps to the body of routes 11 and 14: `name`, `point`, `address`, `notes`, `terms.visibility`, `terms.fee_flat`, `terms.fee_pct`, `terms.fee_min`, `terms.allowed`, `terms.host` (or null), `host_details`. A new spot sends the whole body; a saved spot sends only what differs from what the form started with (`patchFromDrafts`: inside `terms` the changed keys, `host` and `allowed` whole), so an edit that changes no vectors is applied at once (`patchChangesVectors`, 2.3) and one that does waits for the server. The limits are those of 04_BACKEND 4.8 (`SPOT_LIMITS` in `spotSummary.ts`, the one place they are written): a value outside its range stays in its field with the range message, and "Save spot" is then held back with "Check the marked fields first. Nothing was saved." Nothing is clamped. The form's grids follow the width the form itself has (container queries), so the same form fits the modal and the narrow column of the detail page.

| Group | Fields (label -> body field) |
|---|---|
| Place | "Address or place" (`GooglePlaceAutocomplete` with the truck props of 1.6; under it "Enter coordinates instead" with the field "Latitude, longitude" and the button "Use these coordinates"; for a new spot "Pick on the map" goes to `/truck/map?pick=spot`, which starts a new spot there, so a saved spot's form does not offer it) -> `point`, `address`. Once there is a point the form shows the address and `{fmtCoord}` with "Change". Typed coordinates carry no address; a picked address fills an empty name. "Name" (required, 1..120) -> `name`; "Notes" (up to 4,000) -> `notes` |
| Host | "Is there a host?" Radio group. (1) "No host (street or lot)" -> `terms.host: null`. (2) "A place nearby": the `hosts_nearby` list (name, place type label, distance) with `SourceLine` (`osm`) under it -> `terms.host.place_key`; the server derives the segment, a default size and whether it has a kitchen, and the form sends along what the place's `HostHint` says it will derive (`segment` = `host_segment`, `size` = `default_size` with `size_source: default`, `only_food` = kitchen `no`), so that what is saved is what was estimated and an untouched host compares equal to the saved one. The place's kind and its point id are never sent. Choosing a place also puts its name into "Host name", unless the owner has typed a name of their own there (`linkPlace`), so the spot is listed with the name of its host. A distance to a nearby place is said in feet rounded to ten below 1,000 ft ("160 ft") and in miles from there on (`distanceText`): the places offered lie within 250 m, where tenths of a mile all read alike. A form that opens with a linked place lists that place alone, with "Show the other {n} places nearby". In place of the list the form says where it stands: before the spot has a place, "Pick the place of the spot first. The places around it are listed here."; two skeleton rows while it is on its way; "Could not load the places nearby. Describe the host instead, or try again later." when its request failed (`placesStatus` of `useSpotEstimate`, or a failed estimate); "The places nearby cannot be listed right now. Describe the host instead." while the region data is being rebuilt; "No place within reach of this point in our data. Describe the host instead." for an empty list. (3) "I will describe it": `<select>` "Who the host's people are" with the sixteen segment labels of the seed file -> `terms.host.segment`. Then, for (2) and (3): "Host size" -> `terms.host.size`, with the label following the segment's group: visitors "People there in its busiest hour"; workers "People who work there"; residents "People who live there"; range 1 to 200,000; placeholder = the linked place's `HostHint.default_size`, when above 0, with the helper "Typical for this kind of place: {n}. Enter the real figure if you know it." (`size_source` is `default` until a size is typed, then `owner`); when that size is 0 there is no placeholder and the field is required, as it always is for (3). A linked place whose `host_segment` is null gets no size field and no only-food toggle: the server saves the link without a host (04_BACKEND 4.8). `Toggle` "Your truck is the only food here" -> `terms.host.only_food`, helper "Turn this off if the host sells its own food." "Host name", "Contact name", "Phone", "Website" -> `host_details.name`, `.contact`, `.phone`, `.website`, shown for (2) and (3); with (1) the four are not on screen and are saved empty, so removing a host removes its details too. Both `SourceLine`s of the form (under the list of (2) and under the "Is this at {name}?" question of 4.3) are rendered in `SpotForm.tsx` itself, which the guard of 8.3 checks |
| Visibility | Label "How easy is the truck to see?" Radio group -> `terms.visibility`: "Hidden" - "Tucked away from where people walk."; "Normal"; "Prominent" - "On the main path, signposted or announced by the host." |
| Fee | `MoneyField` "Flat fee" -> `fee_flat`; `NumberField` percent "Share of sales" -> `fee_pct`; `MoneyField` "Minimum fee" -> `fee_min`. Helper: "You pay the flat fee plus the share of sales, or the minimum if that is more." |
| Days and hours | `Toggle` "Only on certain days or hours". When on: seven day tick boxes and `TimeField`s "From" and "Until" -> `terms.allowed`. Helper: "The planner warns when a stop falls outside these." |

Under the groups the form prints the preview, "With these terms": the best window of a typical week with the draft's terms, its orders and what it leaves after food and fees, as two `RangeValue`s (through `windowFigures`, the function the list uses, on the terms and vectors `useSpotEstimate` returns). For a new spot the preview comes from `simulate` with all three visibilities and the draft host, so switching visibility is instant; changing the point, the linked place, the segment, or the size of a worker or resident host re-requests it (debounced 400 ms: those four rest that long before the request goes out, the figures from before staying on screen dimmed, so a size stepped with the arrow keys is one request); every other field recomputes in the browser. Editing a saved spot starts from its stored vectors: a visibility change previews from `spotVectors(spot, draft.visibility)` with no request, and `simulate` (three visibilities, the draft host) is requested only when the point, the host link, the host segment or the size of a worker or resident host changed. After `simulate` the draft's `terms.host` is the answer's `host` (rule 7 of 2.5), so `vectorsMatch` holds; the form never sends `point_id`. On save the server recomputes and stores the vectors. The 409 "You can keep at most 500 spots" is shown as it is.

**Detail.** Desktop: two columns, 5 + 7. Left: "Terms" (the form), "Getting there" (`StatRow` "Drive from base" with the source label, `OpenInMaps` route from the base), "Your results here" (a table of this spot's `ServiceLog`s from `listServices({ spot_id, from: addDays(today, -729) })`: "Date", "Hours", "Orders", "Estimate" (the prediction kept with the service as a `RangeValue`, or "None kept"), "Result" (`resultText` of `logView.ts`); and, from `cal.spots[id]`, the line "Here you sell {fmtPercent(factor)} of what the model expects for your truck ({n} services)." or "No services logged here yet."; the five columns show where the card is at least 560 px wide, and the same facts as a list where it is narrower, which is the case in the left column). "Getting there" times the drive for the spot's best window of the typical week (a Thursday lunch when the week has none) and says so under the row: "Timed for {weekday} {fmtWindow}, the best window of a typical week." Right: `SpotAnalysis` (`layout="page"`, which draws its own cards, inside an `ErrorBoundary` of its own) and `PermissionNotice` (`block`). Tablet and phone: one column, analysis first, then "Terms" (a closed disclosure on phones), "Getting there", "Your results here". Header: link "All spots", `<h1>` = name, the address, `OpenInMaps`, primary "Plan a day here" (to `/truck/plan/{today}?add={id}`), secondary "Log a service", "Compare", and "Delete spot" in an overflow menu. Delete asks in a `Modal`: "Delete {name}?" / "It leaves your list. Days already planned there and its logged services are kept." / "Delete spot" (danger), "Keep it" (route 15 archives the spot). "Compare" adds the spot to the ticked ones (the oldest tick makes room when four are taken) and opens the comparison. On phones the closed "Terms" card prints the terms in one line (fee, visibility, host, days and hours). Unknown id: "This spot no longer exists." with the link "All spots". An archived spot opened by a direct link shows the tag "Deleted" and no actions: its terms as one line, its analysis and its results, without the form and without the header buttons.

**Compare.** `<h1>` "Compare spots". Controls: chips for the chosen spots (each removable), "Add spot" `<select>` (up to four), and "Compare on": "Each spot's best window" or "The same window" (a weekday `<select>` and two `TimeField`s). One column per spot, one row per fact:

| Row label | Value |
|---|---|
| "Window" | `{fmtWeekday} {fmtWindow}` |
| "Orders" | `RangeValue` (orders, md) |
| "Left after food and fees" | `RangeValue` (money, sm) |
| "Take-home for a one-stop day" | `RangeValue` (money, md); the column with the largest `qkey(value)` carries the tag "Highest expected" (ties: smallest id; no tag unless at least two columns have a figure) |
| "Break-even" | `{fmtCeil} orders`, or "This spot cannot break even at your ticket and costs." |
| "Drive from base" | `{fmtDuration}, {fmtMiles}` and the source label (one `useDriveTimes` request with the pairs base to each spot and back) |
| "Fee", "Host", "Only food here", "Visibility", "Services the model uses" (`cal.spots[id].n`, else 0) | plain text |
| "Week" | `WeekStrip` compact with the column's window outlined; above it, and as its `ariaSummary`, "Busiest hour of a typical week: {fmtHowLong}." (a sentence without a figure: a number there would be an estimate without its range) |

Every column is computed in the browser from the spot's stored vectors by `spotSummaries`, `spotWindowFigures` and `spotOneStopDay` (not by one `useSpotEstimate` per column, which would cost a drive-time request each). While the legs are on their way the take-home, break-even and drive cells show a skeleton; when the request fails, or a leg came back as a straight line, the strip of 2.6 shows above the table. "The same window" starts from the first spot's best window; a window whose closing time is not after its opening time prints no figures. A column without usable vectors reads "No estimate yet" in its figure cells. The chips, the "Add spot" `<select>` and the tick list write `?ids=` and `truckUiStore.compareIds`; ids that are not in the list of saved spots are left out.

Phones: columns are 260 px wide in a scroll-snap row and each cell repeats its row label as a caption. Fewer than two spots: "Pick at least two spots to compare." and a tick list of spots. `PermissionNotice` (`line`) under the table.

### 4.5 Planner (`/truck/plan/:date`, package FE-4, wave 2)

Needs: `usePlanForDate(date)` (`listPlans(date, date, { stops: true })`); the draft from `truckPlanDraftStore`; the spots (archived included); `useDayContexts(date, 2, { [date]: draft.treat_as })`; `useDriveTimes([base, ...stops])`; calibration. `usePlanEvaluation` returns the `DayResult` on every edit.

```ts
// exported from stores/truckPlanDraftStore.ts (foundation), because the seams of 9.2 use them
interface DraftStop { id: string;                       // a PlanStop id, or a temporary id n1, n2, ... for an unsaved stop
  kind: 'spot' | 'event' | 'catering'; spot_id: string | null; label: string; point: { lat: number; lng: number } | null; address: string;
  open_minute: number; close_minute: number; gap_before_unpaid: boolean; setup_minutes: number | null; teardown_minutes: number | null;
  fee_flat: number; fee_pct: number; fee_min: number; event: EventTerms | null; catering: CateringTerms | null }
interface PlanDraft { planId: string | null; date: string; treat_as: DayContext['treat_as']; notes: string; stops: DraftStop[]; dirty: boolean }
```

| Width | Layout |
|---|---|
| Desktop | header row; then two columns, 7 + 5: left = stops with drive rows between them, "Add stop", the timeline; right = the summary card, the unpaid-break card, things to check and the actions, as one column that is sticky under the sub-nav. When that column is taller than the window it scrolls on its own, and "Save day" stays in view at its foot |
| Tablet | one column: header, summary card, stops, "Add stop", timeline, unpaid-break card, things to check, the actions, "Save day" |
| Phone | one column: header, stops, "Add stop", timeline (list), costs (the summary card), unpaid-break card, things to check, actions. A bar fixed to the bottom shows `RangeValue` (money, inline, sm, label "TAKE-HOME") and "Save day" (44 px), which is the phone's only "Save day"; the page has bottom padding so nothing hides under it. While the day has no take-home to show, the bar says why it cannot be saved or prints the save state |

| Block | Content |
|---|---|
| Header | `<h1>` "Planner"; `DateStepper`; `HolidayChip`; `WeatherChip` for first opening to last closing (10 to 20 with no stops); a labelled `<select>` "Treat this day as": "Automatic", "A normal day (ignore the holiday)", "A holiday", "A Monday" ... "A Sunday" (values null, `normal`, `holiday`, `mon` .. `sun`), helper "For school breaks, local holidays and the days around Thanksgiving."; the save state: "Not saved yet", "Unsaved changes" or "All changes saved" (nothing while an empty day is untouched; a saved day whose `status` is `draft`, a suggested day nobody has confirmed, reads "Draft: save the day to confirm it" and can be saved as it stands). A past date adds the strip "This day is in the past." When `routing.state` is not `ok` or a drive of the day is a straight line (`usePlanEvaluation().notes.driveFallback`): the strip of 2.6. When the forecast could not be loaded: its strip of 2.6 with "Try again" |
| Drive row (before the first stop, between stops, after the last) | "Drive from base: {fmtDuration}, {fmtMiles}" / "Drive: ..." / "Drive back to base: ...", then the source label (6.7; a straight line with its reason), then the clock times of the model's timeline, so that the drive rows and the stop cards read as the day sheet at every width: "Start prep at {t}. Leave base by {t}" on the first row, "Leave at {t}" between two stops, and "Leave at {t}. Back at base about {t}, done by {t}" on the last. Tolls: "Toll {fmtMoneyCents}, your figure" (override toll, 0 included), "Toll {fmtMoneyCents}, Google's estimate" (`toll_state` `estimate`), "Tolls on this route, amount unknown" (`unknown`); nothing otherwise. Button "Edit" opens the leg editor (left out for a `same_point` drive, which has nothing to correct); a per-leg `OpenInMaps` link "Check this drive in Google Maps". The rows are there only for an evaluated day (`legViews` in `planDraft.ts`); they are dimmed while new legs are on their way |
| Leg editor (`Modal` sm) | Heading "Drive from {A} to {B}". Line: "Google: {fmtDuration(duration_s / 60)}, {fmtMiles}, before the time-of-day adjustment." (without ", before the time-of-day adjustment" when `trafficIsNeutral(A)`) or "Straight-line estimate: ..."; a drive of less than half a minute reads "under 1 min" there instead of "0 min", because its row counts a minute for it. `NumberField` (integer, 1..600, suffix "min") "It takes me", helper "Your own time replaces the estimate at every hour."; `MoneyField` (0..500) "Toll", helper "Google's estimate is used when you leave this empty."; buttons "Save" (route 20 with the two points and both values, null for an empty field), "Use the estimate" (route 21 with the correction's id; shown only when the drive has a correction), "Cancel". "Save" with both fields empty removes the correction through route 21 (the server keeps no empty one) and sends nothing when there was none; a field that refused what was typed keeps the dialog open. A straight line adds its reason of 6.7 under the first line; with Google's toll estimate at hand the toll field shows it as its placeholder |
| Stop card | Position badge; a `<select>` of saved spots (for events and catering a name field in its place, which writes `label`, so the forms of rule 5 do not repeat it); kind tag "Spot", "Event" or "Catering"; `OpenInMaps` (the spot's `maps_url`, or the stop's point); buttons "Move earlier", "Move later", "Remove stop" (icon buttons of 44 px on a phone, named by their `title` and, with the stop's name after a colon, by their `aria-label`); a drag handle (`GripVertical`) on desktop. `TimeField` "Open"; `TimeField` "Close" (`after` = open, next day allowed, at most 2880; while the stop closes at or before its opening the field says "Closing must be after opening."). Line "Arrive {t}, set up from {t}" from the timeline. Under it the card repeats the warnings of 6.5 that are about its own times (every one of level `error`, `late_arrival` and `outside_allowed_hours`), in the words of "Things to check". `RangeValue` (orders, md, label "ORDERS", `onWhy`, with the note "Limited by how fast the truck can serve." under `capacity_bound`); `RangeValue` (money, sm, label "LEFT AFTER FOOD AND FEES"). A catering stop has no `onWhy`: it is contracted, and the day's breakdown lists it. For stops after the first with a wait: `Toggle` "The {fmtDuration(gap)} wait before this stop is an unpaid break". Disclosure "Setup and pack-up times": `NumberField`s "Setup" and "Pack-up" (0..240 minutes, placeholder "Truck default: {n}"), open from the start when the stop sets one. A stop whose spot is archived carries the tag "Deleted spot" and still evaluates; it stays in its own select as "{name} (deleted)". A spot with `vectors_state` `stale` carries "Updating" ("Out of date" during a rebuild) and one with `none` "No estimate yet" (rule 5 of 2.5). A stop that cannot be evaluated says why in place of its figures: "This spot is no longer in your list. Choose another one or remove the stop.", "This spot has no estimate yet, so the day cannot be worked out with it." or "Fill in the details of this stop to see its figures."; the summary card then reads "No figures for this day yet", and so it does while the model refuses the day (`invalid_window`, `stops_overlap`). "Remove stop" asks nothing: a toast "Removed {name}." offers "Undo" for six seconds, which puts the same stop back where it was |
| What this stop adds (on every stop card) | Caption "WHAT THIS STOP ADDS". `RangeValue` (money, inline, sm) of `adds.take_home`, then, when `adds.per_hour` is not null, "for {fmtHours(adds.hours)} more:" and `RangeValue` (money per hour, inline, sm) of `adds.per_hour` (an `Estimate`, 02_MODEL section 3). Next line: "Needs {fmtCeil(break_even_orders)} orders to pay for itself." ("1 order") or "It cannot pay for itself at these terms." when null; a catering stop, whose break-even is null by definition (02_MODEL 4.12), prints neither. A loss is said in words (6.1), by the model's own warnings about the stop: "This stop is expected to lose money." under `below_break_even`, "On a weak day this stop loses money." under `weak_day_loss`. With `uses_fallback_leg`: "Uses a straight-line drive estimate." |
| "Add stop" | One dialog, "Add a stop" (`Modal` sm, a bottom panel on a phone): the saved spots as a list (name, then address or fee; a spot already in the day is tagged "In this day"; from nine spots on the list has a search field), then "Event" and "Catering job" (their stops are filled by FE-7). A new spot stop gets the spot's best three-hour window on that date that does not overlap the other stops (from `bestWindows` over that date's hours 6 to 24, an hour counting as taken when any part of it lies inside another stop; the days and hours set for the spot are respected when a window fits inside them), else 11 AM to 2 PM (`defaultSpotWindow`). An event or a catering stop starts at 11 AM to 2 PM when that is free, else on the first three free hours after the last stop, else on the last three free hours before it, and at 11 AM to 2 PM all the same when the day has no three free hours between 6 AM and midnight (`freeWindow`); an event's fee starts from the seeds `events.suggested_fee_pct` and `events.suggested_fee_min`. A new stop is placed where its window falls in the day: before the first stop that opens at or after its closing time, else last (`insertIndex`). The stops already in the day keep their order, and a live region says "Added {name} as stop {i} of {n}." At `limits.max_stops_per_plan` (8) the button is disabled with "A day holds at most 8 stops." |
| Timeline | Card titled "The day, start to finish" with `Timeline` |
| Summary card | `RangeValue` (money, xl, label "TAKE-HOME", `onWhy` opens the day breakdown). `RangeValue` (money per hour, md, label "PER HOUR OF YOUR DAY"), sub-line "{fmtHours(work_hours)} worked". The three-column table "Expected / Weak day / Strong day" for "Orders", "Sales", "Food cost", "Packaging", "Card fees", "Spot fees" and, when the profile counts tips, "Tips". `StatRow`s for the day's own costs: "Labour" `{fmtMoney}` with "{paid hours} paid hours x {crew} crew x {wage} + {burden}"; "Fuel" `{fmtMoneyCents}` with "{gal} gal driving + {gal} gal generator at {fmtFuel} ({fuel source})"; "Tolls"; "Fixed cost for the day". Facts, as one short list under the costs: "Day length" `{fmtDuration(day_minutes)}`; "Driving" `{fmtDuration}, {fmtMiles}`; "Unpaid break" when there is one |
| Unpaid-break card (when `unpaid_gap_alternative` is not null) | Title "If the wait were an unpaid break". `RangeValue` (money, md) of `take_home`, then `RangeValue` (money per hour, inline, sm) of `take_home_per_hour`, then "Saves {fmtMoney(labour_saved)} in wages." Button "Mark the wait as unpaid" (sets the flag on every stop that has a wait) |
| Things to check | `WarningList`, titled "Things to check", hidden when empty |
| Actions | Primary "Save day" (disabled when nothing changed, and while an `invalid_window` warning exists, with the hint "Fix the times first."; also while an event or catering stop lacks what route 23 requires of it, with "Finish the event or catering details first."; `saveBlocker` in `planDraft.ts`). It is one button per width: in the bottom bar on a phone, in its own row from 768 px (the last thing on a tablet page; at the foot of the summary column on a desktop, where it stays in view). "Print day sheet"; `CalendarButton`; `OpenInMaps` route (`plan.maps_route_url` when saved and unchanged, else built by `links.ts`); text button "Clear day" (asks first; route 27; a day that was never saved is only emptied). `PermissionNotice` (`line`) |

States and rules:

1. The date's plan is edited whatever its `status`. With none the page starts an empty draft and the first save is route 23; a 409 `A plan already exists for this date` (saved from another tab) refetches the date and repeats the save as route 26.
2. Saving sends route 23 (new) or route 26 (existing) with `date`, `treat_as`, `notes`, `status: 'planned'` and `stops` in order; stops with a temporary id are sent without `id`. The body is `planBody(draft)` in `planDraft.ts`: no `name` (a day keeps the name it has) and, for a spot stop, no fee, point or label (they are the spot's). The answer's `Plan` replaces the draft (`markSaved`), which gives new stops their real ids. Stops are never reordered by the app: a stop whose time is changed stays where it is, and the model says so when the day no longer follows the clock (`stops_overlap`); such a day can still be saved. Reordering is by the two buttons (always present, keyboard and touch) or by drag and drop on desktop (by the handle; the whole list takes the drop); a polite live region says "Moved {name} to position {i} of {n}." and the button that was pressed keeps focus.
3. Every edit patches the draft and re-evaluates locally. Whether there is something to save is decided by comparing the draft with the saved day (`draftChanged`: the save body of the one against that of the other), not by the store's `dirty` flag alone: an edit the owner takes back by hand leaves "All changes saved", and the draft is then the saved day again (`markSaved`), so that a later change from elsewhere is not held off. Leaving the page keeps the draft in memory; closing the tab with a dirty draft (of any date) triggers the browser's leave warning. A drive-time correction is not part of the day: it is saved at once and leaves the save state alone. "Print day sheet" and the calendar file with a dirty draft first ask "Save the day first?" with "Save and continue" / "Cancel" (the click on `CalendarButton` is held back and repeated once the saved day is on the page, so the button makes its file from the saved day, whose new stops carry their real ids by then; a save that fails keeps the question open and repeats nothing, and so does "Cancel").
4. No saved spots: `EmptyState` (icon `MapPinned`) "Save a spot first" / "The planner works from your saved spots." / "Open the map". No stops: `EmptyState` (icon `Route`) "Nothing planned for {fmtDay(date, 'medium')}" / "Add a stop to see drive times, costs and take-home." / "Add a stop" (FE-7 adds the secondary "Suggest a day"). An empty day that still has something to save (its stops were removed, or only "Treat this day as" is set) also shows "Save day" and the actions, and "Add a stop" is then the secondary button. A date beyond the forecast shows "No forecast yet" in the weather chip and the `no_forecast` note. While the day or the spots load the page shows its header and skeletons; a day that cannot be loaded shows `QueryError` with "Try again" under the header.
5. Event and catering stops (forms by FE-7). An event has "Name" (`label`), "Address or place" (`point`, `address`), "Expected attendance during your stop" (`event.attendance`, 1 to 2,000,000), "Food vendors, counting you" (`event.vendors`, 1 to 500), "Kind of event" (`event.event_type`: `general` "General (fair, market, sports)", `food_focused` "Food is the draw", `evening_show` "Evening show", `incidental` "Food is incidental"), and the three fee fields, prefilled with the seeds `events.suggested_fee_pct` and `events.suggested_fee_min` and marked "Typical terms, change to yours". Its estimate always reads "Very rough". A catering job has "Name", "Address or place", "Headcount" (`catering.headcount`), "Price per head", "Guaranteed minimum" (one of the two is required), "Food cost for this job (optional)"; its lines read "Fixed" and carry no range.
6. "Suggest a day" (panel by FE-7, `?suggest=1`): route 29 with the date and its `treat_as`. Up to three `Suggestion`s, each with its stops (spot names and windows), `RangeValue` (money, md) of `take_home`, `RangeValue` (orders, sm) and "{fmtDuration(day_minutes)}", the button "Use this plan" (replaces the draft's stops after a confirmation when the draft is not empty; the local evaluation then takes over. The panel only calls `onUse`: the planner asks "Replace the stops of this day?" with "Use this plan" / "Keep my stops", replaces the stops and closes the panel) and the button "Why this number", which evaluates the suggestion's stops with `usePlanEvaluation(date, stops, treatAs)` and opens `WhyDrawer` with kind `day`. With `fallback_pairs` above 0: "Some drive times behind these suggestions are straight-line estimates." Caption: "Worked out from your saved spots, costs and this date's forecast. A suggestion, not a booking."

### 4.6 Week (`/truck/week/:weekStart`, package FE-5, wave 2)

Needs: `listPlans(weekStart, weekStart + 6, { stops: true })` (each date's plan is the row of that date); `useDayContexts(weekStart, 8)`; one `usePlanEvaluation` per planned day; `listServices` for that week.

| Width | Layout |
|---|---|
| Desktop | header, totals strip, then a 4-column grid: the seven day cards and, as the eighth cell, the best-week panel |
| Tablet | 2 columns |
| Phone | one column of compact rows: date and chips left, `RangeValue` (sm) right |

| Block | Content |
|---|---|
| Header | `<h1>` "Week of {fmtDay(weekStart, 'short')}"; buttons "Previous week", "Next week", "This week" |
| Totals strip | `RangeValue` (money, lg, label "PLANNED TAKE-HOME THIS WEEK") = `estSum` of the planned days in date order; "{n} of 7 days planned"; helper "Each day's low and high are added up, so the week's range is wide on purpose." |
| Day card | "{fmtWeekday} {fmtDay(date, 'short')}", a "Today" tag, `HolidayChip`, `WeatherChip` (the stops' hours, else 11 to 20). Planned: each stop as "{name}, {fmtWindow}", `RangeValue` (money, md), "{n} things to check", link "Open"; a plan whose `status` is `draft` carries the tag "Draft". Not planned: "Nothing planned" and the button "Plan this day". Past days with logged services add "Logged: {n} orders" |
| Best week (panel by FE-7) | Button "Suggest a week" (route 30). Result: per day either a suggestion (stops, windows, `RangeValue`) or "Day off"; `RangeValue` (money, lg, label "SUGGESTED WEEK") of `total_take_home`; caption "At most {max_days} days out and {max_visits} visits to a spot. Days off are part of the suggestion."; button "Use for the empty days" (creates a plan with `status: 'draft'` through route 23 for each date that has no plan; planned days are never replaced) |

States: skeleton cards; a failed day evaluation shows `QueryError` inside that card only. No spots: the Today empty state "Start with the map".

### 4.7 Log and Accuracy (`/truck/log`, package FE-6, wave 2)

`<h1>` "Log". `Tabs` underline: "Services", "Accuracy". The address keeps the tab (`?tab=accuracy`; the services tab has no parameter).

A link that starts an entry (`new=1`, and `spot`, `date`, `open`, `close`, `stop` to fill it in; 1.2) is taken once: the page shows the services tab, fills the quick entry, puts the focus on its first control and removes those parameters from the address, so a reload does not fill the form in again. A value that is not valid counts as absent (`readLogParams`: a date after today, a time outside 0..2880, a closing time that is not after the opening time, an id longer than 36 characters). `logHref(stop)` writes the link of a planned stop, the one of 4.1. A planned event or catering job has no `spot` in its link, so the form takes its kind, and anything else the link left open, from the plans once they have arrived (`fillFromStop`); it also puts the stop's name into "Notes" while they are empty, because a logged service keeps no name of its own.

What the page decides without the DOM is in two files, tested in Node (8.2): `utils/truck/logForm.ts` (the address, the quick entry's draft, checks and request bodies, the estimate line, the sentences of the result card, the history's range and cells) and `utils/truck/accuracyView.ts` (the tiles, the chart's sentence and geometry, the rows of "By spot", the factor lines).

**Services.** Needs `listServices` (default: the last 90 days), the spots (deleted ones included, for their names), the plans of the last seven days (`listPlans(today - 7, today, { stops: true })`), and for the estimate line either `getPlan(plan_id)` or the day contexts of the date and the next date plus `accuracy()` without a range (route 37).

| Width | Layout |
|---|---|
| Desktop | two columns, 5 + 7 of 12: left = "Log a service"; right = the result card (after a save), "Not logged yet". Under them, at full width: the history |
| Tablet, phone | one column in this order: "Log a service", the result card, "Not logged yet", the history |

| Block | Content |
|---|---|
| "Log a service" (first on every width; left column on desktop, 5 of 12) | `<select>` "Spot" (saved spots, then "An event" and "A catering job", which set `kind`; a deleted spot is offered only to an entry that is already at it, that is a planned stop there or a service being edited); `DateStepper` "Date" (not after today); `TimeField` "Opened"; `TimeField` "Closed" (`after` = the opening time, next day allowed, at most 2880); `NumberField` integer "Orders served" (required, 0 to 5,000) -> `actual`; `MoneyField` "Sales (optional)" (0 to 1,000,000, sent in whole cents); `Toggle` "Sold out or at capacity" -> `sold_out`, helper "Turn this on if you ran out of food or could not serve everyone. Your count is then treated as a minimum."; "Notes" (2,000 characters). Coming from a planned stop, `plan_stop_id` is sent too (the server then takes the plan's `treat_as` and, when the window matches, the estimate the plan showed); the form itself never sends `treat_as`. The entry stays with its planned stop for as long as it still describes that stop (`linkHolds`: the same kind, the same date and, for a spot stop, the same spot; the hours may differ) and says so under the "Spot" list: "From your plan for {fmtDay(date, 'medium')}: {stop}, {fmtWindow}." An entry moved to another spot or date is a service of its own: `plan_stop_id` is not sent and the stop goes on waiting under "Not logged yet". Line "The estimate for this service" with `RangeValue` (orders, inline) and the helper "Uses only the services logged before this date.", or "No estimate for this spot and time." (the rule is under the table). Primary "Save service" (route 32). Nothing saves before the button is pressed, and Enter in a field commits that field only. A missing or refused value holds the save back with "Check the marked fields first. Nothing was saved.", and the first marked field takes the focus (the messages: "Choose a spot, an event or a catering job.", "Required", the range message of 3.5, "The closing time must be after the opening time."). A value its field refused stays there as typed under the field's own message (its range, or that it is not a time), and that message is never replaced by "Required"; nothing is clamped. Keys pressed in the frame after a field takes the focus are kept: the select-on-focus of 3.5 never costs the count a digit. After a save the form starts empty on the same date with the focus on "Spot", so lunch and dinner of one day are logged in a row |
| After saving | A result card from the answer: "Logged {actual} orders at {spot}." ("an event" or "a catering job" for those, or the name of the planned stop it was logged from) and, with a `prediction`, "The estimate was" followed by `RangeValue` (orders, inline, sm) built from the stored `prediction` (`value: predicted`, `low`, `high`, `confidence`) and the verdict, a neutral tag with a glyph of its own. Under it one sentence: "{n} more than the estimate.", "{n} fewer than the estimate." or "Right on the estimate."; for a sold-out service "You sold out, so this count is a minimum, not a measurement. It moves the estimates only when it says more than your other results at this spot." Then, from the returned `calibration`: "Your results now adjust estimates: truck x{truck_factor}, this spot x{factor}." (a spot without a factor of its own counts as x1.00), and, from the calibration of the context as it was before the save: "Before this service: truck x{truck_factor}, this spot x{factor}." ("Before this change: ..." after an edit; "The same as before." when the printed factors did not move). An event or a catering job reads "Events and catering jobs are kept for your records. They do not adjust estimates."; a spot service without a `prediction` reads "No estimate was kept with this service, so it is not scored and does not adjust estimates." Then the link "See how the estimates are doing" (the accuracy tab). The card has a close button, stays until it is closed or until the next save, sits in a polite live region that is always on the page, and is brought into view when it appears. `resultCardText` writes its sentences |
| "Not logged yet" | `unloggedStops` of the last seven days, newest first, each "{fmtDay} · {stop} · {fmtWindow}" with "Log it" (fills the form: the link of `logHref`). With none: "Nothing is waiting. A stop you plan shows up here once its closing time has passed." |
| History (`DataTable`, caption "Logged services") | "Date", "Spot", "Hours", "Orders", "Estimate" (`RangeValue` inline sm built from `prediction.predicted`, `.low`, `.high`, `.confidence`; the em dash without one), "Result", "Sold out" ("Yes" / "No"), "Actions". An event reads "Event" and a catering job "Catering job" in the "Spot" column; sales and notes, when a service has them, are a second line there. "Date" (newest first at the start, the services of one day by opening time), "Spot" and "Orders" sort. Filters: spot, from, to (at most 730 dates, both ends counted). They start at every spot and the last 90 days, which is asked for without a range (the server's own default, so the request is shared with "Not logged yet"). A range the server would refuse is not sent: its field says why ("Pick a date.", "The date must not be after today.", "The last date must not be before the first.", "A list holds at most 730 days. Pick a later first date or an earlier last date.") and the list stays as it was; the button "Last 90 days" goes back to the start. Row menu (a button named "Actions for {date}, {spot}"): "Edit" (route 34), "Delete" (asks "Delete this service? Estimates will stop using it."; route 35). The menu is drawn over the page, 4 px under its button, or 4 px above it where the window has no room below, and follows the button when the page scrolls; Up and Down move between its two entries and Escape gives the focus back to the button. A service that was just entered is in the list at once; until the server has answered, its result reads "Saving..." and its menu is disabled. The seven columns and the menu need about 1,040 px. Where the list has less room it shows the same facts as cards, two to a row from 620 px; the choice follows the width the list itself has (a container query, as in 4.4), so the table is never scrolled sideways |

**Edit** opens the form of "Log a service" in a `Modal` titled "Edit service", with "Save changes" and "Cancel". It sends only what differs from the logged service (`servicePatch`; with nothing changed the form says "Nothing has changed." and sends nothing), and `plan_stop_id: null` when the edit moves the service off the planned stop it was logged from. Its estimate line follows what the server does with an edit (04_BACKEND 4.13): while the kind, the spot, the date and the hours are as logged, it shows the stored `prediction` with the helper "The estimate kept with this service. It stays unless you change the spot, the date or the hours." ("No estimate was kept with this service." without one); once one of them changes it shows the estimate the server will work out again, by the two rules below, and the service being edited is never one of its own entries. After the save the result card shows as after a new service. **Delete** asks in a `Modal` (sm): "Delete this service?" / "Estimates will stop using it." / the service in one line / "Delete service" (danger), "Keep it". Afterwards the focus goes to the history, because the row and its menu are gone.

The estimate line is computed to equal what the server will store with the service (04_BACKEND 5.7), by `estimateLine`:

1. Coming from a planned stop (`plan_stop_id`, while the entry still describes that stop): read `getPlan(plan_id)`. When its `result` is not null and holds the stop, the plan's date and `treat_as` are the entry's, the stop is a spot stop of the chosen spot and `[timeline.stops[i].effective_open, close_minute)` equals the entered window, show `result.stops[i].orders`. An event or a catering job logged from a planned stop of its own kind shows that stop's `result.stops[i].orders` whatever its hours (5.7 item 1). The helper then reads "The figure your saved plan showed for this stop." and the line has no "Why this number": the figure is the one the plan stored.
2. Otherwise, for a spot: `windowOrders(A, profile, terms, spotVectors(spot), calBefore, ctx, ctxNext, open, close)` with the chosen spot's `terms`, `calBefore = calibrationBefore(A, entries, date)` (in `logView.ts`: `calibrate(A, entries.filter((e) => e.date < date), date)`), `entries` from `accuracy()` without a range (route 37), and the contexts of `date` and the next date, the first built with the `treat_as` the service will carry (the linked plan's, the logged service's own in an edit, else null). The spot's stored vectors are used as they are, as the server does. This figure has "Why this number": the `WhyDrawer` for the window, given the calibration the figure was computed with (`calBefore`, not the calibration of the context).

Where there is no figure the line says why. "Choose the spot and the hours to see the estimate." until both are there. "No estimate for this spot and time." for hours that do not make a window and for a spot without stored vectors (then with "This spot has no estimate yet."). "No estimate for this service." with "An event or a catering job has an estimate only when it is logged from a planned stop." for those. A skeleton line while what it needs is on its way. "The estimate could not be worked out: your logged services did not load." with "Try again" when route 37 failed. When `day-context` failed the estimate still shows, with the strip sentence of 2.6 and "Try again" under it.

After saving, the result card and the history print the stored `prediction`.

Verdict words (`verdictOf` in `logView.ts`, used here and on Today): actual within low..high "inside the range"; above high "above the range"; below low "below the range"; sold out "sold out, counted as a minimum". The "Result" cell adds the signed difference from the estimate, for example "+5, inside the range"; without an estimate it is the em dash, or "sold out, counted as a minimum" for a sold-out service. The server refuses a second service for the same spot and time (409); its sentence is shown under the form. Empty (nothing logged at all, `counts.services` 0; shown in place of the history): `EmptyState` (icon `NotebookPen`) "No services logged yet" / "After each service, enter how many orders you served. About ten logged services make the dollar figures worth trusting." / "Log a service" (a secondary button that puts the focus in the form: the page's one primary button is "Save service").

States: each block has its own skeleton and its own `QueryError` ("Could not load your spots.", "Could not load your planned stops.", "Could not load your logged services."), so one failed request never blanks the page.

**Accuracy.** Needs `accuracy()` and the calibration of the context. The figures are the server's report, shown as returned; nothing is computed again.

| Width | Layout |
|---|---|
| From 1280 px | the four tiles in a row; then the chart (8 of 12) beside "Your results in the model" (4); then "By spot" at full width |
| Below | one column in the order of the table; the tiles four to a row from 1024 px and two to a row below |

| Block | Content |
|---|---|
| Heading | "How the estimates are doing" |
| Four tiles | "Services logged": `{n_total}`, "{n_scored} scored, {n_sold_out} sold out". "Bias": "Estimates ran {p}% high" (bias above 0.02), "Estimates ran {p}% low" (below -0.02), else "Estimates were on target"; second line "Before your results were used: {p}% high / low", or "... on target" inside the same 2 %. "Typical miss": `{fmtPercent(mape)}`, "average gap between estimate and actual". "Inside the range": "{k} of {n_scored}" with k = `coverage * n_scored`, "About 8 in 10 is what the ranges aim for." With `n_scored` 0 the tiles show the em dash and "Needs a service that did not sell out." (a bias that is null although services were scored, because no order was served in them: the em dash and "Needs a scored service with at least one order.") |
| Note | With `unscored_without_prediction` above 0: "{n} logged services are not scored. Events, catering jobs and services without an estimate are left out." ("1 logged service is not scored. ..."). The server counts every log without a full prediction (04_BACKEND 4.14), so an event logged from a plan is among them although the history shows the figure its plan gave it: the sentence names the kinds and never says that these services have no estimate |
| Chart "Estimates against actuals" | Hand-rolled SVG over the last 30 `entries` in the order they were served (by date, the services of one day by opening time, as in the history), oldest at the left: a vertical bar from low to high in `--line`, a tick at the estimate in `--ink`, a dot at the actual in `--accent-brand` (a hollow ring when sold out), date ticks (those that fit, the first and the last before any other; a day with two services is named once). The orders axis ends at four equal whole steps that reach the highest value drawn. A view box of 720 units, drawn between 590 and 860 px wide, so its text stays between 9 and 13 px. Ten services fill the width and thirty share it; fewer keep to the left, so a short log does not look like a long one. In a narrower box (a phone) the drawing scrolls sideways: it then starts at the newest service, and the orders axis stays at the left edge of the box while the columns pass under it. Each column carries a title for the pointer: "{fmtDay}, {spot}: {actual} orders, {verdict}". Legend: "Estimated range", "Estimate", "Orders served" and, when one is drawn, "Sold out: a minimum". Summary sentence above it and as `aria-label`, about the services the chart shows: "{k} of {n} scored services landed inside the estimated range." ("1 of 1 scored service ..."; with more than 30 entries it starts "The last 30 services: "; when every service shown sold out: "Nothing is scored yet: every service here sold out, so its count is a minimum.") |
| "By spot" (`DataTable`, caption "Accuracy by spot") | "Spot", "Services", "Sold out", "Bias" ("7% high", "3% low", "On target"), "Typical miss", "Inside the range" ("2 of 2"), "Spot factor" ("x0.94" from `cal.spots[id].factor`); the em dash where a figure is missing. Every column sorts, the spot with the most services first at the start. The seven columns need about 820 px; where the block has less room it shows the same facts as cards, two to a row from 560 px |
| "Your results in the model" | "Truck factor x{truck_factor} from {truck_n} services." with the helper "Above 1 means you sell more than the generic model expects."; one line per spot "x{factor} from {n} services" ("1 service"), by name. Before any service counts: "No logged service adjusts the truck factor yet. It stays at x1.00." and "No spot has a factor of its own yet." |

Empty (`n_total` 0): `EmptyState` (icon `Target`) "Nothing to score yet" / "Log a few services and this page shows how close the estimates were." / "Log a service" (to the quick entry), with the note of the table under it when it applies. States: skeleton tiles and chart; `QueryError` with the server's sentence, else "Could not load how the estimates did.".

### 4.8 Scout (`/truck/scout`, package FE-7, wave 3)

Needs `scout({ hide })` (route 38): at most 50 `ScoutCandidate`s in rank order, each `{ result: ScoutResult, place, lead, maps_url, leg_sources }`, plus `screened`, `truncated`, `limit_minutes`, `licence_counties`, `attribution`. The rank comes from the server; the score itself is never shown. The drive limit and the counties are profile settings; the other filters narrow the returned list in the browser.

| Width | Layout |
|---|---|
| Desktop | filters in a left rail (260 px), results as a list of cards |
| Tablet, phone | a button "Filters ({n})" opens a `Sheet`; cards stack |

| Block | Content |
|---|---|
| Header | `<h1>` "Scout". Intro: "Places within {limit_minutes} minutes of your base that could host a truck, ranked by what their best three hours of a typical week might be worth after the drive." Count "{n} places shown, {screened} looked at". Button "Refresh" (`refresh=1`). With `truncated`: "Only the nearest places were looked at." With an empty `licence_counties`: strip "No counties chosen, so every county within reach is listed. Choose the counties you hold a licence for in Settings." |
| Filters | "Status": tick list of the six lead statuses; unticked statuses go into `hide` (default hides "Declined" and "Hidden"), so hidden places leave the ranking and make room for others. In the browser: "Kind of place" (tick list of the place types present); "County"; "Kitchen" ("Any", "No kitchen of its own"); "Contact" ("Any", "Has a phone or website") |
| Result card | "{result.position}. {place.name}" as `<h2>`, the place type label, `place.city` and the county name. "Best window in a typical week: {fmtWeekday} {fmtWindow}" (or "No hour of the week reaches one order." when `best_window` is null). `RangeValue` (orders, inline, sm) and `RangeValue` (money, inline, sm, label "LEFT AFTER FOOD AND FEES") of `contribution`. "Drive: {fmtDuration(round_trip.minutes)} round trip, {fmtMiles}, about {fmtMoney(cost)}", plus "(straight-line estimate)" when either of `leg_sources` is `straight_line`. "Our rule of thumb: this kind of place {often / sometimes / rarely} hosts trucks." (`host_fit` from 0.7 / from 0.4 / below). Kitchen from `place.kitchen`: `no` "No kitchen of its own"; `yes` "Has its own kitchen"; `unknown` "Kitchen unknown: assumed {yes / no} for this kind of place" (from `result.kitchen`). Size line: "Size assumed: {host_size} {unit phrase} (typical for a {place type label})."; when `result.host_size` is 0 it reads "No typical size for this kind of place. Ranked on the people nearby." When `lead.spot_id` is set, the two estimates and the size line are replaced by "Saved as a spot. Open it for the estimate that uses your size and your logged results." with the link "Open spot"; rank, drive line, contact details, status and notes stay. Contact: `place.phone` as a `tel:` link through `fmtPhone` and `place.website` as a link showing its host name, tagged "OpenStreetMap"; `lead.google` values captioned with string 12 of 6.4 ("Phone and website from Google Maps"), then "Looked up {fmtDay(fetched_on, 'medium')}." and "Google matched: {name}, {address}" so the owner can see it is the same place |
| Card actions | `OpenInMaps` with `maps_url`; "Look up phone and website" (route 40; secondary; shows a spinner; afterwards the Google values, or "Google found no phone or website." for `not_found`; first use shows "Looks this place up on Google. Results are kept for 30 days."; a 503 shows the server's sentence); "Show on map" (to `/truck/map?pt=..&scout=1`); "Why this number" (below; absent when `best_window` is null, and not shown on a card whose `lead.spot_id` is set); "Save as spot" (below; not offered when `lead.spot_id` is set, where the card links "Open spot"); `<select>` "Status": "New", "Shortlisted", "Contacted", "Booked", "Declined", "Hidden" (route 39); "Notes" (saved on blur) |
| Why this number (on click only) | Calls `simulate` with `point` = the place's point, `visibilities: ['normal']` and `terms: { visibility: 'normal', host: result.host_size > 0 ? { place_key } : null }`, then runs `windowOrders(A, profile, { spot_id: null, visibility: 'normal', host: <the answer's host>, fee_flat: 0, fee_pct: 0, fee_min: 0, allowed: null }, vectors.normal, cal, typicalContext(A, dow), typicalContext(A, (dow + 1) % 7), open_minute, close_minute)` for `result.best_window` and opens `WhyDrawer` with kind `window`. Development builds warn when its `orders.value` differs from `result.orders.value` beyond the model tolerance |
| Save as spot (`Modal` sm, route 41) | "Name" (prefilled), "Host size" (required when `result.host_size` is 0 and `result.host_segment` is not null; otherwise optional with `result.host_size` as placeholder), `Toggle` "Your truck is the only food here", the visibility radio group of 4.4. When `result.host_segment` is null neither the size field nor the only-food toggle is shown and the modal carries the line "Saved without a host. You can describe one on the spot page." On 201: toast "Spot saved" with "Open spot". The 409 "This place is already saved as a spot" is shown as it is |
| Footer | `SourceLine` (`osm_sentence`, `drive`); "Phone numbers and websites come from OpenStreetMap unless marked Google. About one place in three has either."; "Sizes are typical figures for the kind of place, so these ranges are wide. Save a place as a spot and enter its real size to tighten them."; `PermissionNotice` (`block`) |

Empty states: the 409 "Scouting needs a loaded region": `EmptyState` (icon `Compass`) "No data for your area yet" / "Scout needs a loaded region around your base." No candidates: "No places match" / "Widen the longest drive in Settings, or show more statuses." Failed request: `QueryError` "Could not load Scout."

### 4.9 Settings (`/truck/settings/:tab`, package FE-3; the `data` tab is FE-8)

`<h1>` "Settings". `Tabs` underline: "Truck and costs", "Assumptions", "Data and export". Each of the first two tabs edits a draft and has a save bar that appears when the draft differs: "Unsaved changes", "Discard", primary "Save changes". Valid ranges come from the seed metadata (`profile_defaults.<field>.min` and `.max`, and the inherited `min` and `max` of each overridable seed), never from literals in the page; they are the ranges the server enforces. `utils/truck/profileForm.ts` reads them (`profileRule`, `seedMeta`) and holds both drafts, their checks and their request bodies; the one range without a seed is the owner's own fuel price, whose 0.50 to 20 is the server's (04_BACKEND 4.4) and is written there once. A value outside its range stays in its field with the range message and is never clamped; while any field of the tab holds such a value, or a required number is empty, "Save changes" is held back with "Check the marked fields first. Nothing was saved." The two drafts live above the tabs, so looking at the other tab does not lose an edit. "Discard" puts every field back to the saved value.

**Truck and costs** (route 3, only changed keys are sent). Desktop: cards in the left 8 columns, the "What these settings mean" card sticky in the right 4. Tablet and phone: one column, that card last.

| Card | Fields (label -> `TruckProfileX` field, control) |
|---|---|
| "Truck" | "Truck name" -> `name`. "Base" -> `base`: the address, "Change" (search with the address widget and the truck props of 1.6, coordinates, or "Pick on the map" to `/truck/map?pick=base&return=/truck/settings/truck`); a base outside every region's box shows the out-of-area line of 1.4 before the save, and a save that moves the base shows the warnings of its answer as the two toasts of 1.4. "Region": read-only name ("No map data for this area" for a truck without a region), with "Set by where the base is." |
| "Sales" | "Average ticket" -> `avg_ticket` (money). "Orders per hour at full speed" -> `capacity_orders_per_hour`, helper "The most you can serve in an hour. Estimates never go above this." "How well your menu fits each part of the day" -> `daypart_fit`, four percent fields "Breakfast (5 to 11 AM)", "Lunch (11 AM to 4 PM)", "Dinner (4 to 10 PM)", "Late (10 PM to 5 AM)", helper "100% is a full fit. 30% means about a third of the people buying then would consider your menu." |
| "Crew" | "Paid crew" -> `paid_crew`, helper "Do not count yourself." "Wage per hour" -> `wage_per_hour`. "Payroll taxes and extras" -> `payroll_burden_pct` (percent) |
| "Food and fees" | "Food cost" -> `food_cost_pct` (percent), helper "The share of sales that goes on ingredients." "Packaging per order" -> `packaging_per_order`, helper "Set to $0 if your food cost already includes packaging." "Card fee" -> `card_fee_pct` (percent, one decimal) and "plus, per card order" -> `card_fee_fixed`. "Share of sales paid by card" -> `card_share`. `Toggle` "Count tips as take-home" -> `tips_include`; when on, "Tips as a share of card sales" -> `tips_pct_of_card_sales` |
| "Vehicle and fuel" | "Miles per gallon" -> `mpg`. "Fuel" -> `fuel_type` ("Gasoline", "Diesel"). "Generator fuel per hour" -> `generator_gal_per_hour` (gal), helper "Set to 0 on shore power." "Drives take this much longer than in a car" -> `truck_time_factor`, shown as a percent (`1.10` is 10). `Toggle`s "Avoid toll roads" -> `avoid_tolls` and "Avoid highways" -> `avoid_highways`. "Fuel price": the current price `{fmtFuel}` with its source line (as on Today), and `MoneyField` (three decimals, 0.50 to 20) "Use my own price" -> `fuel_price_override`, helper "Leave empty to follow the weekly average." |
| "Day routine" | "Prep before leaving" -> `prep_minutes`. "Setup at a stop" -> `setup_minutes`. "Pack-up at a stop" -> `teardown_minutes`. "Close-out back at base" -> `closeout_minutes`. "Fixed cost per service day" -> `fixed_cost_per_service_day`, helper "Commissary, insurance or anything else you pay on each day you go out." |
| "Where you trade" | "Counties you hold a licence for" -> `licence_counties`: tick boxes for `region.counties` grouped by state, with "Select all in {state}". Helper: "Scout only lists places in these counties. Leave all unticked to see every county within reach. Where your licence applies is yours to know." "Longest drive for Scout" -> `scout_drive_minutes_limit` (5 to 60 minutes) |
| "What these settings mean" (from the draft) | `StatRow`s: "Left per order after food, packaging and card fees" `{fmtMoneyCents(unitMargins.at_minimum)}`; "Crew cost per paid hour" `{fmtMoneyCents}`; "Fuel per mile" `{fmtMoneyCents}`; "Generator per hour" `{fmtMoneyCents}`. The last three are the model's `dayCosts` on a day of one paid hour, one mile and one generator hour (`settingsMeaning`), so no cost formula is written in the page. They follow the draft once it is valid; a value a field refused is not in the draft. The two fuel figures use the owner's own price when the draft has one, else the current price; while the draft changes the fuel, or drops the owner's price, they read "Shown once the fuel change is saved.", because the other price is only known to the server. Link "See the starting values and where they come from" opens a `Modal` ("Starting values") listing each `profile_defaults` seed with its value, `SeedTag` and source note |

**Assumptions.** Intro: "These are the model's starting assumptions. None is measured from food truck sales. Change one only if you know better for your truck. Your logged services correct the totals either way." Accordion groups ("Hosts" open at first; a group that holds changed values says "{n} changed"), each row = plain-language label (from `wording.ts`, keyed by seed path), editor, unit, `SeedTag`, a "Source" disclosure with the seed's source note, "Range: {min} to {max}", and, when overridden, a "Your value" tag, "Starting value: {value}" and "Reset". A percent editor holds a fraction and shows it times 100, and so do its range and its range message ("Range: 5% to 100%"). An emptied field goes back to the starting value, and a starting value typed back takes the override away: both are the same as "Reset".

| Group | Seed paths | Editor |
|---|---|---|
| "Hosts" | `host.captive_share`, `host.shared_kitchen_share`, `host.onsite_kitchen_weight` | percent, percent, number |
| "Weather" | `weather.floor`, `weather.pop_when_missing`, `weather.<table>.rows.<id>.open` and `.captive` for the temperature, precipitation and wind tables | the two single values as percent rows; then a table per group ("Temperature", "Rain and snow", "Wind") with two percent columns "Open-air spots" and "Inside a venue", one row per band with its tag, source and "Reset row". Where a table has less than 520 px (a phone) each band keeps its two fields side by side under its name, each with its own label, so no field is scrolled out of sight |
| "Events" | `events.attendance_haircut`, `events.p_buy.<type>` | percent |
| "People by hour" (one sub-group per segment, labelled by the seed's segment label) | `segments.<s>.presence.<day_type>`, `segments.<s>.intent.<day_type>`, `segments.<s>.dow_factor`, `segments.<s>.holiday_day_type.major` and `.minor` | per segment: six curves (people present and share buying a meal, each for a weekday, a Saturday and a Sunday), each a row that names its peak ("Highest at 10 AM: 36.9%") and opens its `CurveEditor` with "Edit curve" (24 percent fields in a 6 by 4 grid labelled `12a` .. `11p`, four or three to a row on narrower screens, with an `HourBars` preview in percent and "Reset curve"); five number fields "Mon" .. "Fri"; two `<select>`s ("Major holiday", "Minor holiday") with the options "Weekday", "Saturday", "Sunday". A segment's editors are mounted only while its sub-group is open. A curve travels as all 24 values, so its cells are held in the editor until every one holds a number |

Saving sends route 5 with only the changed paths (a reset row sends `null` for its path). The merged draft is first checked with `validateOverrides`; messages by code, also used for the `details` of a 422: `out_of_bounds` "Enter a number from {min} to {max}."; `wrong_shape` "Enter all 24 values."; `not_allowed` "Choose one of the listed options."; any other code "This value cannot be changed." "Reset all assumptions" (text button, shown while anything is changed) asks "Reset all {n} changed assumptions?" ({n} counts every overridden path, saved or still in the draft) and sends route 6 with an empty body. A closed section "Fixed in this version" lists the remaining seeds (scope `build` or `fixed`; the profile defaults are the other tab's) read-only with value, unit, tag and source, group by group (`fixedSeedGroups`); the kinds of place and the federal holidays are told one line each; seeds tagged `tuned` read "Placeholder until you log services". Every seed of the section is named in plain words by `seedLabel` in `wording.ts` ("Most stops in a suggested day", "Default gasoline price, Lower Atlantic (Virginia, West Virginia)", "Metres in a mile"): none is listed by the words of its path, which a test holds.

**Data and export** (FE-8). "Where the data comes from": the `attribution` list of `sources()` in id order, ids 3 to 9 and 11 (each `text`, linked when it has a `url`; the server leaves 11 out while the traffic table is neutral), then "Data version {dataset_version} · pipeline {pipeline_version} · model {model_version}" and "{residents} residents, {jobs} jobs, {places} places, {cells} map cells" from `dataset.totals` and `dataset.counts`. "Export": one button "Download my data (JSON)" (route 42 as a blob, saved under the file name the server sends), with the note "Everything you entered: truck, spots, plans, logged services, drive-time corrections and Scout notes. Place names carry the OpenStreetMap credit." "Delete": heading "Delete all Truck Planner data", text "This deletes your truck, spots, plans, logged services, drive-time corrections and Scout notes. It cannot be undone. Your smappen account stays.", danger button "Delete everything", which opens a `Modal` asking to type `delete my truck data` before its own "Delete everything" button is enabled (route 43). When that route answers 403 (`Requires role: owner/admin`) the delete card shows "Only the account owner or an admin can delete all data." instead of the server text.

### 4.10 Day sheet and calendar file (package FE-8, wave 3)

**Day sheet** (`/truck/plan/:date/sheet`). It shows the date's saved plan (`usePlanForDate`), evaluated in the browser like the Planner. A toolbar that does not print: link "Back to the planner", primary "Print" (`window.print()`), `CalendarButton`, `OpenInMaps` with `plan.maps_route_url`. With a dirty draft: strip "This sheet shows the saved day. You have unsaved changes." No saved plan: `EmptyState` "Nothing saved for this day" / "Save the day in the planner first." / "Open the planner". It must read well at 375 px and on Letter paper.

| # | Section | Content |
|---|---|---|
| 1 | Title | "Day sheet", `{fmtDay(date, 'long')}`, the truck's name, the holiday name if any, one weather line per stop |
| 2 | "Times" | A table "Time", "What", "Where": one row per timeline event with the labels of 3.10, and drive rows "Drive {fmtDuration}, {fmtMiles} ({source label})" |
| 3 | One block per stop | Name, address, host name, contact and phone (`host_details`), `{fmtWindow}`, "Estimate: {fmtEstimate(orders)}, {label in lower case}", the fee as a sentence, notes; on screen only, `OpenInMaps` for the stop |
| 4 | "The day in numbers" | "Orders", "Sales", "Left after food and fees", "Labour", "Fuel", "Tolls", "Fixed cost", "Take-home", "Take-home per hour", each as "{fmtEstimate}, {label in lower case}" as in row 3 (fixed amounts print as one figure, labelled "fixed") |
| 5 | "Things to check" | `WarningList` |
| 6 | Footer | `PermissionNotice` (`line`); `SourceLine` (`osm_sentence`) when any stop's spot has a `host_details.place_key`; the drive-time line of 6.4; "Estimates from model {MODEL_VERSION}. Printed {fmtDay} {fmtClock}." |

**Calendar file.** `buildDayIcs(input)` in `utils/truck/ics.ts` is pure: `{ date, timeZone, timeline, stops: { id, name, address, point, orders: Estimate }[], truckName, host, dtstamp }` -> a string. `CalendarButton` wraps it in a `Blob` (`text/calendar;charset=utf-8`) and downloads `truck-day-<date>.ics`.

1. `BEGIN:VCALENDAR`, `VERSION:2.0`, `PRODID:-//smappen//Truck Planner//EN`, `CALSCALE:GREGORIAN`, `METHOD:PUBLISH`.
2. One `VEVENT` for the whole day: `SUMMARY:Truck day: {stop names joined by ", "}`, from `start_prep` to `done`, `DESCRIPTION` = the timeline as lines "{fmtClock} {label}".
3. One `VEVENT` per stop: `SUMMARY:{name}`, from `effective_open` to `close`, `LOCATION` = the address or `{fmtCoord}`, `GEO:<lat>;<lng>`, `DESCRIPTION` = "Leave by {t}. Arrive {t}. Open {t}. Close {t}. Leave {t}." then "Estimate: {fmtEstimate(orders)}, {label}." then the standing notice (short form).
4. `UID:tp-<date>-<stop id or "day">@<host>`; `DTSTAMP` = the `dtstamp` argument (`utcStamp(nowEpochMs())` at the call site); `DTSTART` and `DTEND` are UTC instants from `zonedToUtcStamp` with the truck's time zone, so no time-zone block is needed.
5. Text values escape `\`, `;`, `,` and newlines (`\n`); lines end in CRLF and are folded at 75 octets with CRLF plus one space. The same input always gives the same bytes.

---

## 5. Map layer

Our own canvas inside a plain `google.maps.OverlayView` on the existing raster map (no map id, style arrays kept). One static mesh of true H3 cell outlines, one byte per cell recomputed per hour tick, one draw call. A 2D-canvas renderer sits behind the same interface. A second host draws the same layer over a blank grid when Google is unavailable and in tests.

### 5.1 Modules

| File | Runs in | Responsibility |
|---|---|---|
| `utils/truck/map/mercator.ts` | Node and browser | `worldX(lng) = 256 * (lng + 180) / 360`; `worldY(lat)`: `s = clamp(sin(lat * PI / 180), -0.9999, 0.9999)`, `256 * (0.5 - ln((1 + s) / (1 - s)) / (4 * PI))`; the inverses `lngOf`, `latOf` |
| `utils/truck/map/pack.ts` | both | `decodePack(buf): CellPack` (5.2), `PackError`; declares `CellPack` and `CellPackHeader` |
| `utils/truck/map/mesh.ts` | both | `buildMesh(ids, boundaryOf, bounds, onYield?): Promise<HexMesh>` (5.3); declares `HexMesh` |
| `utils/truck/map/frames.ts` | both | `createFrameSource(pack, inputs): FrameSource` with `setInputs`, `fill`, `has`, `warm` and `kept` (5.4), and `meanByte` for the hour strip; the only file that calls the bulk scorer of `fastPath.ts` |
| `utils/truck/map/pick.ts` | both | `buildCellIndex(ids): Map<string, number>`; `cellAt(index, lat, lng, latLngToCell, res)`: the index of the pack cell that holds the point, or -1 |
| `utils/truck/map/viewport.ts` | both | declares `Viewport`; its maths: `zoomOf(scale) = log2(scale)`, `alphaForScale`, `isZoomedOut`, the world rectangle in view (`worldRect`) and the cells that touch it (`cullCells`), the two shader uniforms of a viewport (`clipTransform`); and the camera of the blank host: `cameraAt`, `blankViewport`, pin projection (`projectLatLng`) and its inverse, `panByPixels`, `zoomAbout`, `gridLines` |
| `utils/truck/palette.ts` (foundation) | both | ramps, `buildLut`, legend ticks, bands, floors (5.5) |
| `components/truck/map/types.ts` | browser | the interfaces below; re-exports `CellPack`, `CellPackHeader`, `HexMesh` and `Viewport` |
| `components/truck/map/renderers/webgl2.ts`, `renderers/canvas2d.ts` | browser | the two `Renderer`s (5.7) |
| `components/truck/map/hosts/googleOverlayHost.ts`, `hosts/blankBasemapHost.ts` | browser | the two `MapHost`s (5.6) |
| `components/truck/map/HexLayer.ts` | browser | `createHexLayer({ host, pinLayer, onViewport?, mountedAt? })`: owns mesh, frames, renderer and its canvas, attaches them to the host; reacts to pack, inputs, layer, hour, theme; scores the hours ahead when idle; never throws (5.8). `prepareMesh(pack, datasetVersion)` starts the mesh of a pack before a layer exists (5.3) |
| `components/truck/map/TruckMap.tsx`, `MapPin.tsx` | browser | the React surface: loads Google through `useTruckMapsLoader` (1.6), picks the host, mounts the layer, forwards hover, click and camera, renders pins, the hover outline and the blank host's zoom buttons |
| `components/truck/map/useCellPack.ts` | browser | the `pack` query (route 8) plus `decodePack` |
| `components/truck/map/authFailure.ts` | browser | installs `window.gm_authFailure` once (5.6) |
| `components/truck/map/PerfHud.tsx` | browser | the measurement overlay behind `?tp_perf=1` (5.9) |

```ts
export type MapLayerId = 'opportunity' | 'people' | 'competition';
export interface Viewport { width: number; height: number; dpr: number;   // CSS px; dpr = min(devicePixelRatio, 2)
  scale: number;                                                        // CSS px per world unit (256-unit world)
  originX: number; originY: number }                                    // canvas position, CSS px, of the mesh origin
export interface Renderer { readonly kind: 'webgl2' | 'canvas2d';
  setMesh(mesh: HexMesh): void; setLut(lut: Uint8Array): void; setValues(values: Uint8Array): void;   // values: one byte per cell
  setOpacity(alpha: number): void; resize(width: number, height: number, dpr: number): void;
  render(vp: Viewport): void; dispose(): void }
export interface MapHost { readonly kind: 'google' | 'blank';
  attach(canvas: HTMLCanvasElement, pinLayer: HTMLElement, onViewport: (vp: Viewport) => void): void;   // again after detach, with another canvas
  setOrigin(lat: number, lng: number): void;                            // the place originX and originY are measured to: the mesh origin
  project(lat: number, lng: number): { x: number; y: number } | null;   // position inside pinLayer; null before the first viewport
  on(event: 'move' | 'click' | 'leave', cb: (e: { lat: number; lng: number; clientX: number; clientY: number }) => void): () => void;   // the map's own events, never a pin's
  getCamera(): { lat: number; lng: number; zoom: number }; setCamera(c: { lat: number; lng: number; zoom?: number }): void;
  detach(): void }
export type LayerStatus = 'no-region' | 'loading' | 'building' | 'ready' | 'ready-2d' | 'zoomed-out' | 'failed' | 'version-mismatch';
export interface HexLayer {
  setPack(pack: CellPack | null, state: 'idle' | 'loading' | 'error' | 'ready'): void;
  setInputs(i: { A: Assumptions; profile: TruckProfile; cal: CalibrationState | null }): void;
  setLayer(id: MapLayerId): void; setHour(how: number, date: string | null): void; setTheme(t: 'light' | 'dark'): void;
  cellAt(lat: number, lng: number): { index: number; id: string; byte: number } | null;
  hourStrip(dow: number, done: (bytes: Uint8Array) => void): void;       // 24 mean bytes of the cells in view, computed when idle; a newer request replaces one still waiting
  onStatus(cb: (s: LayerStatus) => void): () => void;                    // called at once with the current status, then on every change
  destroy(): void }
```

`TruckMap` props (`TruckMapProps`): `region: RegionInfo | null`, `initialCamera`, `layer`, `pack`, `packState`, `inputs`, `forceBlank?`, `cursor?: 'default' | 'crosshair'`, `onHover?(hit | null)` with `hit = { id, byte, clientX, clientY }`, `onClick?({ lat, lng })`, `onCamera?(camera)` (on idle: when the viewport has not changed for 250 ms), `onStatus?(status)`, `hostNotice?: boolean` (5.6, default true) and `children` (`MapPin` elements). `onHover` is called at most once per animation frame while the pointer moves over the map, and again when the byte of the hovered cell changes with the hour, the layer or the truck under a pointer that stays where it is; it is called with null when the pointer leaves the map, moves onto a pin or is over no cell of the pack. Its imperative handle (`TruckMapHandle`) has `flyTo(camera)`, `getCenter()` and `hourStrip(dow, done)`. `MapPin` props (`MapPinProps`): `lat`, `lng`, `kind?: 'scout' | 'spot' | 'selected' | 'base'` (the stacking order of 5.7, default `spot`) and `children`, the pin's own button. `useCellPack(region)` returns `{ pack: CellPack | null, state: PackState }`, which are the `pack` and `packState` props. `components/truck/map/types.ts` declares all of these next to the interfaces above, together with `PackState = 'idle' | 'loading' | 'error' | 'ready'`, `MapCamera`, `MapHit`, `LayerInputs` and `MapPointerEvent`, and re-exports the shapes that are declared beside the code that runs in Node too: `CellPack` and `CellPackHeader` (5.2), `HexMesh` (5.3: `n`, the origin as `originLat`, `originLng`, `originX`, `originY`, `positions`, `indices`, `centers`, `halfSizes`) and `Viewport`. `TruckMap` subscribes the layer to `truckHourStore` itself (rule 1 of 2.4). The component that renders `<GoogleMap>` holds no per-tick state; its `options` object is memoised and its `center` is a stable reference, otherwise every render would call `map.setOptions` and recentre. It fills its positioned parent and reads the two URL switches `tp_basemap=blank` and `tp_perf=1` itself, once per mount, so they work on any page that mounts it.

Map options: `styles` = `SMAPPEN_MAP_STYLE_MONO` (light) or `SMAPPEN_MAP_STYLE_DARK` (when `<html data-theme="dark">`, watched with a `MutationObserver`), `mapTypeControl: false`, `streetViewControl: false`, `fullscreenControl: false`, `clickableIcons: false`, `disableDoubleClickZoom: true`, `gestureHandling: 'greedy'`, `minZoom: 8`, `maxZoom: 19`. `usageApi.logMapLoad()` is called on every mount of `TruckMap` whose host is Google (each mount constructs a `google.maps.Map`, which Google bills as a map load), never for the blank host. The map is unmounted with the page; returning to the tab reuses the cached pack and mesh, and no keep-alive is added.

### 5.2 Pack download and decode

1. `useCellPack(region)` runs `truckApi.fetchPack(region.pack.url)` when the region is usable (axios, `responseType: 'arraybuffer'`; the browser has already removed the gzip encoding and caches the response by URL: `Cache-Control: private, max-age=31536000, immutable`). No `fetch`, no Cache API, no IndexedDB. Its state is `idle` without a usable region (nothing is requested), `loading`, `ready`, or `error` for a request that failed and for a pack that does not decode; neither is ever thrown into React. A request that got no answer or a 5xx answer is tried twice more; a 4xx answer, a 501 and a `PackError` are not retried (the same bytes would fail again). The decoded pack is kept out of structural sharing: it is never compared key by key.
2. `decodePack(buf)` follows 03_DATA 11.2 exactly: magic `TPCP` (`0x54504350` big-endian read), format version 1, header length `H` at byte 8, JSON header at 12, data offset `D = 12 + H + ((8 - ((12 + H) % 8)) % 8)`, `N = header.cell_count`, `K = header.columns.length`, total length `D + 8N + 2NK`. Ids: two little-endian `u32` per cell joined as `hi.toString(16) + lo.toString(16).padStart(8, '0')` (15 characters). Features: column-major `u16` codes decoded to a row-major `Float32Array(N * K)` with `value = scale[j] * (code / 65535)^2`. All reads through `DataView` with explicit little-endian.
3. It throws `PackError` with a code, checked in this order: `bad_magic` (not a pack at all: an error page, an empty or cut-off answer, something that is not a buffer), `bad_version`, `bad_header` (the header ends beyond the file, is not UTF-8 JSON, or lacks what the map reads: a whole `cell_count`, `columns`, a non-negative `scale` for every column, `h3_res`, `bounds`, the two version strings), `bad_columns` (unless `K` is 50 and `columns` equals `c_day_<seg>` x 16, `c_eve_<seg>` x 16, `n_<seg>` x 16, `r_day`, `r_eve` in the shared segment order, which is the feature row the fast path expects), `bad_length`. Nothing is allocated before the length has been checked.
4. `HexLayer.setPack` refuses a pack whose `model_version` differs from `MODEL_VERSION` or whose `dataset_version` differs from `region.dataset_version` (status `version-mismatch`).
5. `CellPack = { header, n, k, ids: string[], features: Float32Array }`. The pack feeds colours only. No number printed anywhere comes from it (0.2).

### 5.3 Mesh

`buildMesh(ids, boundaryOf, bounds, onYield)`; `boundaryOf = (id) => cellToBoundary(id)` from `h3-js`, which returns `[lat, lng]` pairs (the rest of the repository is `[lng, lat]`).

1. Origin = the world coordinates of the centre of `bounds` (the box of the cell centres in the pack header). Positions are stored relative to it (`Float32Array`, 12 numbers per cell: six vertices, `x` then `y`). Absolute world coordinates would jitter from zoom 18 in float32; relative ones stay under one world unit for a region the size of `dc`, so their rounding is at most 2^-25 units: 0.016 px at zoom 19, the largest zoom of the map.
2. Every cell uses its own six vertices. A cell with five (a pentagon) repeats its last vertex; an outline with more than six points (a cell that crosses an edge of the icosahedron carries extra points on its sides) loses the points that bend it least until six remain. One shared hexagon shape is not allowed: it is more than a pixel off at street zoom.
3. Indices: `Uint32Array`, 12 per cell, a fan over the six vertices: `(0,1,2) (0,2,3) (0,3,4) (0,4,5)` plus `6 * cell`.
4. Also kept per cell: the centre of its bounding box in the same relative coordinates and the half-width and half-height of that box (for culling and for the 2D renderer).
5. Every 1,024 cells the loop asks its caller (`await onYield()`); the layer answers by time: once a slice has run 10 ms it lets the event loop go first (a `MessageChannel` task, which no timer clamp slows down), so no task exceeds 50 ms on a device several times slower than the reference either, and frames keep coming while the mesh is built. `HexLayer.ts` keeps the result, and the cell index of 5.7, per pack in a `WeakMap` (the build is shared by layers that ask for it at the same time, and a failed build is not remembered), so a pack that is still in the query cache never builds its mesh twice. The build needs the pack and nothing else, so `TruckMap` starts it (`prepareMesh`) as soon as the pack is decoded, also while the Google map is still loading: when Google adds the overlay the mesh is already there, and the first frame Google draws of it is coloured. A pack the layer would refuse (5.2) is not built. A built mesh goes on screen in three more short tasks: the index of its ids; the frames, the first frame and the buffers on the GPU; then the origin is given to the host (`setOrigin`), which answers with a viewport measured to this mesh, and that one is drawn. Until then the status is `building` and nothing of the new mesh is drawn.

### 5.4 Scoring per tick

`createFrameSource(pack, { A, profile, cal })` prepares the week's weight rows with `precomputeMapWeights(A, profile, cal)`, the typed form of `mapWeightRows` (recomputed only when `A`, `profile.daypart_fit` or the truck factor changes; `setInputs` returns whether a frame may come out different, which is those three or the capacity), and reuses its buffers.

```
frame(layer, how, date):                       # one byte per cell
    rows   = week rows when date is null, else the 24 rows of that date (the hour of day of how decides)
    regime = regime_of_hour[how mod 24]
    scores = bulk scorer of fastPath.ts over pack.features for the active layer only
             (opportunity is capped at profile.capacity_orders_per_hour inside the scorer)
    for each cell c: b = scoreByte(scores[c], hi[layer]);  frame[c] = 0 if b < minByte[layer] else b

fill(layer, how, date, out: Uint8Array /* N */):   out = a copy of frame(layer, how, date)
warm(layer, how, date):                             score the frame and keep it, without handing it out
```

A frame is kept once it has been scored: the week of one layer is 168 frames of one byte per cell (10.3 MB for `dc`), so a tick on a kept frame is a copy. The kept frames belong to one layer and one date (or the typical week); another layer, another date or inputs that change the frames start them again, and they never take more than 16 MB (a region of more than 99,864 cells keeps fewer hours, the frame scored longest ago making room). After every tick the layer scores the hours ahead, in the order a playing week reaches them, in idle callbacks (`requestIdleCallback`, with a timer where the browser has none), a few frames per slice, none when less than 5 ms of the idle period is left and never more than 12 ms at a stretch; about half a second after the layer is ready the whole week is kept. A tick on an hour the idle work has not reached is scored on the spot.

The 24 rows of a date follow 02_MODEL 4.17: `w_opp[h][s] = presence[s][h] * intent[s][h] * daypart_fit[daypart_of_hour[h]] * truck_factor` and `w_people[h][s] = presence[s][h]`, with `presence` and `intent` from `hourWeights(A, dayContext(A, date, null, null, null, null))`. No request is needed for a date: the model works out the holiday itself, and forecast and fuel play no part in the map. A test asserts that for `typicalContext(A, dow)` these equal rows `dow * 24 .. dow * 24 + 23` of `mapWeightRows`. The map never applies weather, hosts or spot factors.

Per tick the layer does: `fill` -> `renderer.setValues` -> one coalesced `requestAnimationFrame` render. The competition layer has only two distinct frames (day and evening) and keeps both, apart from the week of the other two layers. Hover reads `out[index]`. A date the model refuses is reported once (`console.warn`) and the typical week is shown. `hourStrip(dow, done)` fills the 24 frames of that day, a few per idle slice, and averages the bytes of the cells whose bounding box touches the view (`meanByte`); while a date is selected its rows are used, as on the map.

### 5.5 One colour scale for the whole week

The domain is fixed and comes from the fast path: `byte = scoreByte(x, hi) = floor(255 * sqrt(clamp(x / hi, 0, 1)) + 0.5)` with `hi` = seeds `map.opportunity_hi` (45), `map.people_hi` (20000), `map.competition_hi` (100). It is the same for every hour, region and truck, so a colour always means the same number and dragging the hour shows real change. Nothing is rescaled per viewport or per hour.

| Layer | Stops, low to high (light theme) | Ticks on the legend | Bands for the hover hint | Floor ("No colour: under ...") |
|---|---|---|---|---|
| `opportunity` | `#fde725 #7ad151 #44bf70 #22a884 #21918c #2a788e #355f8d #414487 #482475 #440154` (Viridis, light end low) | 1, 5, 15, 30, 45 | "under 1", "1 to 5", "5 to 15", "15 to 30", "30 or more" | 0.1 orders an hour |
| `people` | `#d0e1f2 #b0d2e8 #89bedc #60a7d2 #3e8ec4 #2172b6 #0a549e #08306b` | 100, 1,000, 5,000, 10,000, 20,000 | "under 100", "100 to 1,000", "1,000 to 5,000", "5,000 to 10,000", "10,000 or more" | 50 people |
| `competition` | `#fee6ce #fdd0a2 #fdae6b #fd8d3c #f16913 #d94801 #a63603 #7f2704` | 1, 5, 25, 50, 100 | "under 1", "1 to 5", "5 to 25", "25 to 50", "50 or more" | 0.25 |

Rules: each ramp gets darker as the value rises (strictly falling relative luminance, checked by a test), which is what keeps it readable for colour-blind viewers and on the light grey `mono` base; one hue family per layer so the active layer is recognisable at a glance. `buildLut(layer, theme)` returns 256 RGBA entries: entry 0 is transparent, entries 1 to 255 interpolate the stops linearly in sRGB. On the dark base the stop order is reversed so high values are light. A tick or band edge at value `v` sits at `sqrt(v / hi)` along the bar and at byte `scoreByte(v, hi)`; `minByte = scoreByte(floor, hi)` (12, 13, 13). The layer is drawn at opacity 0.68 so roads and labels of the base map stay readable under it. `WeekStrip` uses the `opportunity` table.

### 5.6 Hosts

**Google host.** The overlay class is created after the API has loaded (`class extends google.maps.OverlayView` cannot be evaluated at module load). `onAdd`: put the canvas (`pointer-events: none`) in `getPanes().mapPane`, so Google's own shapes and its attribution stay above it; put the pin layer in `getPanes().overlayMouseTarget` and call `google.maps.OverlayView.preventMapHitsFrom(pinLayer)`. On every `draw()`:

```ts
const p  = this.getProjection();
const nw = p.fromContainerPixelToLatLng(new google.maps.Point(0, 0));
const d  = p.fromLatLngToDivPixel(nw);
const lx = Math.round(d.x), ly = Math.round(d.y);
canvas.style.left = lx + 'px'; canvas.style.top = ly + 'px';
const scale = p.getWorldWidth() / 256;                 // never map.getZoom(): it jumps to the target during an animated zoom
const o = p.fromLatLngToDivPixel(originLatLng);
onViewport({ width, height, dpr, scale, originX: o.x - lx, originY: o.y - ly });
```

`draw()` is called on every frame of a drag, wheel zoom, `panBy` and `fitBounds` on a raster map (measured on Maps 3.66); because that is observed rather than documented, the host also redraws on `bounds_changed`, `zoom_changed`, `idle` and from a `ResizeObserver` on the map container. The layer draws inside that callback, not on a later frame, which is what keeps it on the tiles: measured against the rectangles of Google's own tile images over some 900 frames of drags, flings, wheel zooms, `setZoom`, `panBy` and `fitBounds`, the fill was never more than 0.5 px and a pin never more than 1 px from where the tiles put the same place. Pointer events come from the map, not the canvas: `map.addListener('mousemove' | 'click' | 'mouseout')`, with hover picks throttled to one per animation frame. `project` is `fromLatLngToDivPixel`; pins are repositioned inside `draw()` by setting `transform` directly (whole pixels), with no React render.

A click on a pin is the pin's. `preventMapHitsFrom` keeps it from the map and keeps the keyboard focus on the clicked pin (measured: without the call Google moves the focus to the map), and React's `onClick` on the pin still fires. Behind it the host has a guard of its own, because the `click` Google hands to map listeners carries an event without a target: it remembers whether the last press went down on a pin and whether a click event has just passed through the pin layer, ignores the map's click in both cases, and is tested with Google's call switched off. The map can be dragged by a pin; the click that the browser fires when such a drag ends on the pin is stopped before it reaches the pin. One trait of Google's call, measured on 3.66: after a pin has been activated from the keyboard, Google ignores a single click that lands on the map without the pointer having travelled over the map first. A hand never does that; a scripted browser pass moves the pointer in steps before it clicks (8.5).

**Blank host.** A `div` with a neutral grid (a 2D canvas: background `--bg-panel`, lines in `--line-soft` that are fixed to the world, 64 px apart at whole zoom levels and spreading to 128 px in between, so the grid moves and grows with the camera), the hex canvas above it and the pin layer on top. It keeps its own camera (centre in world coordinates, fractional zoom 8 to 19) and implements drag to pan (pointer capture), wheel and two-finger pinch to zoom about the pointer, arrow keys and `+` / `-` (it stops those keys from reaching the page's own shortcuts); the "Zoom in" / "Zoom out" buttons are rendered by `TruckMap` at the bottom left of the map (44 px below `md`). Grid and viewport are produced in one animation-frame task, so base and layer move as one. It emits the same `move` (mouse and pen only), `click` (pointer up within 4 px of pointer down) and `leave` events and the same `Viewport`. For a mouse or a pen the `click` is emitted at `pointerup`. A finger's tap is remembered at `pointerup` and emitted when the browser's own `click` event arrives on the map: that event is the last one of a tap, and a browser aims it (and the mouse events it makes up before it) at whatever lies under the finger by then, so a click emitted at `pointerup` would open the spot card and the same tap would go on to press whichever of the card's buttons came up under the finger ("Show more", the close button, "Save as spot"). The Google map does the same by itself: its `click` follows the browser's. A press on a pin stays the pin's until it turns into a drag, which pans the map and does not open the pin: a finger that lands beside a pin is often given to the pin by the browser. It is used when `?tp_basemap=blank` is set, when the loader reports `loadError`, and after an authentication failure.

**Authentication failure.** With an invalid key Google replaces the map, removes our overlay and stops calling `draw()`, while `isLoaded` stays true and `loadError` stays empty. `authFailure.ts` therefore installs `window.gm_authFailure` once (keeping any earlier handler) and sets `truckUiStore.mapsAuthFailed`; `TruckMap` then switches to the blank host with the last camera. The sentence of 4.2 ("The Google map could not load, ...") is shown by `TruckMap` itself, in a status card beside the zoom buttons, whenever the blank host stands in for a Google map that failed (`loadError` or a refused key, never for `?tp_basemap=blank`), unless the page passes `hostNotice={false}` and says it in its own status chip, as the map page does. As a second line of defence, 1.5 s after the Google map mounts it checks the container for Google's error element (`.gm-err-container` **[M]**). With no key at all Google runs in development mode and everything works; in development builds only, its "Do you own this website?" dialog is dismissed by clicking `.dismissButton` once, as soon as it appears. Address search needs a working key, which is why every address field also accepts coordinates and a map click.

### 5.7 Renderers, pins, picking

**WebGL2.** Context: `canvas.getContext('webgl2', { alpha: true, premultipliedAlpha: true, antialias: false, depth: false, stencil: false })`; null means use the 2D renderer. Buffers: positions (`FLOAT` x 2, static), indices (`UNSIGNED_INT`), and one value per vertex (`UNSIGNED_BYTE`, normalised, dynamic): each tick the N cell bytes are expanded to 6N and uploaded with `bufferSubData`. The colour table is a 256 x 1 RGBA texture with nearest filtering. Blending `ONE, ONE_MINUS_SRC_ALPHA`. One `drawElements(TRIANGLES, 12 * N, UNSIGNED_INT, 0)` per frame.

```glsl
#version 300 es
// vertex shader
in vec2 a_pos; in float a_val;                 // a_val: the cell byte, normalised to 0..1
uniform vec2 u_scale, u_offset; out float v_val;
void main() { v_val = a_val; gl_Position = vec4(a_pos * u_scale + u_offset, 0.0, 1.0); }

#version 300 es
// fragment shader
precision mediump float;
in float v_val; uniform sampler2D u_lut; uniform float u_alpha; out vec4 o;
void main() {
  if (v_val <= 0.0) discard;
  vec4 c = texture(u_lut, vec2(v_val * (255.0 / 256.0) + 0.5 / 256.0, 0.5));
  o = vec4(c.rgb * c.a * u_alpha, c.a * u_alpha);
}
```

with `u_scale = (2 * scale / width, -2 * scale / height)` and `u_offset = (2 * originX / width - 1, 1 - 2 * originY / height)`. The backing store is `width * dpr` by `height * dpr`. The renderer keeps the typed arrays it was given (the mesh, the colour table, the six-fold bytes), because a lost context comes back empty. Right after setup it draws one triangle that shows nothing: a driver finishes compiling a program at its first draw, and that wait belongs to the time the pack is still on its way, not to the task that shows the first frame. On unmount the context is released (`WEBGL_lose_context`); creation and disposal are idempotent because `React.StrictMode` mounts effects twice in development (twenty unmounts and mounts in a row leak no context). The layer owns the canvas, each layer its own: a canvas that has had a WebGL context gives no 2D context, so the 2D renderer always gets a fresh canvas and the host is attached again with it.

**2D canvas.** `getContext('2d', { willReadFrequently: true })` at 1x backing resolution. Each frame: cull the cells whose bounding box touches the world rectangle in view, then draw by their number: up to 4,000, one `fill()` per cell in its table colour (256 precomputed CSS strings); from 4,001 to 15,000, each cell's bounding box with `fillRect` instead, on whole pixels; above 15,000 the 2D renderer draws nothing and the layer reports `zoomed-out` ("Zoom in to see the colours."). Cells are painted opaque and the canvas element carries the layer's opacity, so overlapping boxes are not blended twice; a hexagon is drawn three quarters of a pixel larger than it is, so the soft edges of two neighbours leave no seam. Ticks are coalesced to animation frames. Many hexagons are never merged into one path (measured slower).

**Zoom.** `alphaForScale`: opacity 0 below zoom 9.0, rising linearly to 1 at zoom 10.0 (cells are under a pixel there); status `zoomed-out` below 9.5.

**Pins.** `MapPin` renders its children through a portal into the host's pin layer and positions them with `host.project` on every viewport callback, so the same pins work on both hosts. The pin layer is a point that takes no pointer events; each pin has a zero-size anchor on its coordinate (class `tp-pin`, moved by `transform`, hidden until the host can place it), and the pin's own element is laid out from that point and moves itself to where it belongs: `translate(-50%, -100%)` for a teardrop whose tip marks the place, `translate(-50%, -50%)` for a dot, a ring or the base. Pins are real buttons; z-order: scout dots, saved spots, selected point, base. Outside a `TruckMap`, a `MapPin` renders nothing.

**Picking.** `cellAt(lat, lng)` = `index.get(latLngToCell(lat, lng, header.h3_res))` (two to three microseconds in Chrome; exact H3 membership, unlike nearest-centre). The hovered cell's outline is one SVG polygon in the pin layer, under the pins (`pointer-events: none`, 2 px `--ink` stroke, no fill), whose points are the cell's own outline (`cellToBoundary`) projected with `host.project` on every viewport callback. It is not a Google polygon and not a GL line (GL lines are one pixel wide), so it looks the same on both hosts.

### 5.8 Status, context loss, never throwing

| Status | When | What the page shows (4.2) |
|---|---|---|
| `no-region` | `region` is null, or `usable` is false, or it has no `pack` | "No map data for this area yet." for a null region and for `not_loaded`; "Map data is being rebuilt after an update. New estimates are unavailable until it finishes." when `unusable_reason` is `build_mismatch` |
| `loading`, `building` | pack in flight; decoding or building the mesh | "Loading map data ({size} MB, first time only)..." |
| `ready`, `ready-2d` | drawing with WebGL2 or with the 2D renderer | nothing |
| `zoomed-out` | zoom under 9.5, or the 2D renderer with more than 15,000 cells in view (5.7) | "Zoom in to see the colours." |
| `version-mismatch` | pack refused (5.2) | "The map data is from a different version. Reload the page." |
| `failed` | pack request failed, `PackError`, or the layer caught an exception | "Map colours are unavailable right now. You can still click the map for an estimate." |

- Every public method of `HexLayer` and every host callback runs inside `try`/`catch`. A caught error is logged once (`console.warn('[truck-map]', e)`), the layer clears its canvas and reports `failed`, and stays there until the pack state changes or the pack is set again, which is a new start. A pack that decodes but whose ids are not cells fails here, in the mesh build. Clicking the map, pins and the spot card keep working, because they do not depend on the layer; a failure in a callback of the page (pins, hover) is logged and does not fail the layer.
- `webglcontextlost`: call `preventDefault()`, stop drawing. `webglcontextrestored`: rebuild the program, buffers and texture from the typed arrays the renderer still holds, then draw. If the context does not come back within 3 s, or setup fails twice (at the start or after a restore), switch to the 2D renderer (`ready-2d`) on a canvas of its own: the host is detached and attached again with it, the pin layer moves along.
- `TruckMap` sits in its own `<ErrorBoundary scope="Map" inline>` as a last resort.

### 5.9 Budgets and how to measure them

Reference machine: the laptop used for the reconnaissance measurements (integrated graphics, Chrome, 1600 x 900), region `dc` with its 61,460 cells.

| Budget | Target | Measured by |
|---|---|---|
| Pack transfer | at most 4 MB compressed, once per data version | network panel |
| Decode | at most 150 ms | `performance.measure('tp:pack-decode')` |
| Mesh build | at most 200 ms of work in total, no task over 50 ms | `tp:mesh-work` (the slices of the build added up); `tp:mesh` is the same from start to finish, with whatever else the page did between the slices |
| First coloured frame, warm HTTP cache | at most 1.0 s after the map mounts | `tp:first-frame` |
| Hour tick: frame, bytes, upload | at most 2 ms of main-thread time at the 95th percentile while a week plays. A frame the idle work has not reached yet (5.4) is scored inside the tick and costs 2 to 3 ms more at the median, up to 7 ms | `tp:tick` |
| Tick to pixels | the next animation frame; 95th-percentile frame at most 18 ms while playing at "Fast" | frame deltas in `PerfHud` |
| Camera frame | at most 1 ms of script per `draw()` | `tp:draw` |
| Hover pick | at most 0.2 ms | sampled in `PerfHud` |
| 2D renderer | at least 30 frames a second with up to 15,000 cells in view; never a task over 50 ms | `PerfHud` |
| Spot card | numbers on screen within 150 ms of the `simulate` answer | `tp:spot-compute` (measured by `SpotAnalysis`, 4.3) |
| Memory for one region | at most 40 MB (features 12.3 MB, mesh 6.9 MB, the kept frames of one layer 10.3 MB, ids and index about 4.5 MB). The raw download is let go once it is decoded. `h3-js` reserves another 33.6 MB for itself as soon as its chunk is loaded | memory panel |
| Bundle | `TruckPages-*.js` at most 260 kB gzip; the map engine with `h3-js` (about 63 kB gzip) is not in it but in `MapPage-*.js` (1.7); `index-*.js` grows by at most 8 kB gzip | `check-truck-chunks.mjs` |

`?tp_perf=1` mounts `PerfHud` (bottom right of the map, clear of Google's terms): renderer kind, layer status, cells, cells in view, the last and 95th-percentile tick, tick-to-frame, frame, camera-frame and hover-pick times over the last 120 samples, decode and mesh times (work, start to finish and the longest slice), the time to the first coloured frame and the number of hours scored ahead; the `P` key prints them with `console.table`, and the same figures are on its element as JSON (`data-tp-perf-json`) for a script. While it is mounted every tick and camera frame is also written to the performance timeline (`tp:tick`, `tp:draw`); without it only the one-off measures are. The page clock moves in steps of 0.1 ms, so a hover pick (two to three microseconds) shows as 0.0 to 0.2 ms. Node tests hold loose machine-independent bounds (8.2).

Measured in headless Chrome 154 on the reference machine while it was shared with other work, `dc` in view at zoom 10 (about 50,000 of its cells), on both hosts, on the development server and, for the map page, on the production build: decode 18 to 95 ms; mesh work 136 to 229 ms over 57 loads (median 151 ms, one of them over 200 ms), after 147 to 293 ms in an earlier series of 35 (median 185 ms, nine over 200 ms); `cellToBoundary` of `h3-js` is about four fifths of that work, and every page load runs it cold; 142 to 370 ms from start to finish, the longest slice 12 to 22 ms. First coloured frame with the pack at hand: 290 to 554 ms after the mount on the blank host; on the Google map 563 to 859 ms in 43 of 45 loads and just over a second in two. There it is the frame in which Google first draws the overlay (within 2 ms of it in the twelve loads where both moments were recorded), 250 to 560 ms after the mesh was ready, so Google's own start decides it. Tick 0.4 to 1.0 ms at the 95th percentile and frames 16.8 to 17.4 ms while a week plays at "Fast" for 30 s, every tick drawn in the next animation frame; a frame scored inside its tick 2.0 to 2.9 ms at the median and up to 7.1 ms at the 95th percentile; 0.1 to 0.2 ms of script per camera frame, and once 2 to 5 ms in one of the first draws after a mount; the 2D renderer 50 to 58 frames a second with 3,240 hexagons and 57 to 60 with 12,340 boxes in view; 34.7 MB for the region. The pass of 8.5 repeated the measurement on the production build served together with the API (1440 x 900, both hosts, 48,200 of the cells in view, four runs): the pack is 3.41 MB over the wire; decode 23 to 40 ms; mesh work 139 to 272 ms in a new tab (medians 144 to 191 ms) and 196 to 303 ms when the page is reloaded in its tab (medians 203 to 276 ms: the heap of the page before is being collected meanwhile), the longest slice 27 ms; first coloured frame with the pack in the HTTP cache 263 to 569 ms after the mount on the blank host and 335 to 761 ms on the Google map (678 to 846 ms on the very first load), which is 0.5 to 1.1 s after the navigation started (1.1 to 1.6 s on that very first load, which also fetches Google's scripts); tick 0.4 to 0.5 ms at the 95th percentile and frames 16.9 to 17.4 ms over 30 s at "Fast", with one to eight single frames over 18 ms of some 1,800 and no task over 50 ms; 0.2 ms of script per camera frame at the 95th percentile while the map is dragged; a hover pick within two steps of the clock. Every row of the table held there except the mesh work, which was over 200 ms in 47 of 48 reloads and in 9 of 64 loads in a new tab. The layer has only been measured in headless Chrome; the browser pass of 8.5 must repeat the playback check in Safari and Firefox before wave 1 is called done.

---

## 6. Wording

The fixed strings of this section, and every string more than one screen prints, live in `utils/truck/wording.ts`. The labels and sentences of a single screen (section 4) are written in that screen or in its pure helper; the wording guard of 8.3 reads them there. Tone: plain, direct, second person ("your truck"), sentence case, numbers first, no exclamation marks, no emoji, no hype. Never "AI", "smart", "magic", "insights", "powered by", or sparkle icons: every figure is ordinary arithmetic on public data and the owner's settings.

### 6.1 How an estimate is phrased

| Rule | Example |
|---|---|
| Value first, then the range in brackets with "to", then the chip | "60 orders (33 to 93)" + "Rough" |
| In a sentence: "about" for the value, "likely between" for the range | "About 60 orders, likely between 33 and 93." |
| Verbs: "estimate", "about", "might", "could". Never "will", "expect to make", "guaranteed", "forecasted earnings" | "This stop could add $135." |
| A loss is said in words where there is room | "On a weak day this stop loses money." |
| Money in estimates is whole dollars; unit prices show cents; fuel shows three decimals | "$482", "$9.54 an order", "$4.195/gal" |
| Model placeholders are never presented as findings | "Placeholder until you log services" |
| The label is always shown with the number; the sentence is one tap away | chip + hint |

### 6.2 Confidence labels (fixed)

| `confidence` | Label | Sentence |
|---|---|---|
| `very_rough` | "Very rough" | "A guess from generic assumptions. Treat it as a ranking only." |
| `rough` | "Rough" | "Not yet checked against your own sales." |
| `fair` | "Fair" | "Adjusted with your logged services." |
| `good` | "Good" | "Backed by your results at this spot." |
| `fixed` | "Fixed" | "Set by your terms, not estimated." |

### 6.3 The standing notice and the standing lines

| Use | Text (exact) |
|---|---|
| `PermissionNotice` `line` (spot card, Spots list, spot detail, compare, planner, day sheet, calendar file) | "Permission to trade here and local rules are yours to check." |
| `PermissionNotice` `block` (Scout, spot detail) | "Truck Planner estimates demand. It does not know who owns this land or what the local rules say. Permission to trade here and local rules are yours to check." |
| Under every breakdown | "Estimates rank places and times. Before you log services they are poor at predicting dollars." |
| Data vintages (`SourceLine` `vintages`, from `region.vintages`) | "Residents: April 2020. Jobs: {lodes_year}. Places: OpenStreetMap, {fmtDay(osm_snapshot_date, 'long')}." |
| Deleting a spot | "It leaves your list. Days already planned there and its logged services are kept." |
| Deleting everything | "This deletes your truck, spots, plans, logged services, drive-time corrections and Scout notes. It cannot be undone. Your smappen account stays." The phrase to type is `delete my truck data` |
| The warnings of a profile save, as toasts (`PROFILE_WARNING_TEXT`, read through `profileWarningTexts(warnings)`, which says nothing for a code this build does not know: first-run step, "Set base" on the map, Settings) | `timezone_assumed`: "We assumed Eastern time for this truck." `base_outside_region`: "Your base is outside the counties we have data for. Nothing can be estimated near it; the rest of the map works." |
| The map's own strings (`MAP_TEXT`: the engine, the status chip and the gate's loading state) | "Loading map..."; "The Google map could not load, so the background map is hidden. Estimates and saved spots still work."; "Zoom in"; "Zoom out"; "Background grid. Arrow keys move it, plus and minus zoom." |

### 6.4 Attribution strings

Ids 1 to 12 are the twelve strings of 03_DATA section 14 with that document's numbering, which is also the `id` in the `attribution` list of `sources()` (route 44, placeholders already filled; a string whose placeholder cannot be filled is left out, and so is string 11 while the traffic table is neutral). The Data page prints what the server sends: ids 3 to 9 and 11. `wording.ts` holds strings 1, 2, 6, 9, 10 and 12 for the places that have no server list at hand, character for character as in 03_DATA section 14, and the row marked D, which exists only in the browser. String 10 is filled in the browser from `region.vintages`: `{census_year}` is the first four characters of `census_reference_date` and `{lodes_year}` is `lodes_year`. OpenStreetMap text links to `https://www.openstreetmap.org/copyright`; the jobs line links to `https://lehd.ces.census.gov/data/`.

| Id | Where | Text |
|---|---|---|
| 1 | Wherever OpenStreetMap places are listed: the spot card's outlet list, the spot form's lists of nearby places, Scout, the day sheet (`SourceLine` `osm`) | "© OpenStreetMap contributors" |
| 2 | Scout footer, day sheet footer (`osm_sentence`) | "Place data © OpenStreetMap contributors, available under the Open Database License (ODbL)." |
| 3 | Data page, residents | "Residents: U.S. Census Bureau, 2020 Census Redistricting Data (Public Law 94-171). Counts as of April 1, 2020, not adjusted for growth." |
| 4 | Data page, jobs | "Jobs: U.S. Census Bureau, LEHD Origin-Destination Employment Statistics (LODES), version 8.4, 2023, all jobs. Job counts are jobs of record with statistical noise added by the Census Bureau, not people present. {blocks_adjusted} payroll-address blocks holding {jobs_spread} jobs were spread over their county (corrections {corrections_version}); construction jobs count at {cns04_weight_percent} %." |
| 5 | Data page, places | "Places: OpenStreetMap snapshot of {osm_snapshot_date} (Geofabrik extracts). © OpenStreetMap contributors, ODbL 1.0. The places table is a database derived from OpenStreetMap and is available under the ODbL on request: {contact}." |
| 6 | Data page, weather; Today | "Forecast: National Weather Service (weather.gov)." |
| 7 | Data page, fuel | "Fuel price: U.S. Energy Information Administration, weekly retail prices, week of {period}." |
| 8 | Data page, boundaries | "County boundaries: U.S. Census Bureau, TIGERweb." |
| 9 | Data page, Scout footer (`SourceLine` `drive`) | "Drive times and distances: Google Maps Platform. Kept for at most 30 days." |
| 10 | Map legend source line (`SourceLine` `map`; DECISIONS 8), always visible with the legend, also when it is collapsed (4.2) | "People: US Census {census_year}, LEHD {lodes_year} · Venues: © OpenStreetMap contributors"; for `dc`: "People: US Census 2020, LEHD 2023 · Venues: © OpenStreetMap contributors" |
| 11 | Data page, traffic; left out by the server while the traffic table is neutral (`trafficIsNeutral`, 2.5) | "Time-of-day traffic factors: derived from the TomTom Traffic Index 2025." |
| 12 | Scout, next to any looked-up contact detail (4.8) | "Phone and website from Google Maps" |
| D | Day sheet drive line | "Drive times: Google Maps Platform, adjusted for the time of day." (when `trafficIsNeutral(A)`: "Drive times: Google Maps Platform.") or, when any leg is a straight line, "Some drive times are straight-line estimates, not Google drive times." |

### 6.5 Planner warnings

`warningText(w, result, stopNames, ctx)` in `utils/truck/warnings.ts`. Numbers are read from `Warning.data`, whose keys are fixed by 02_MODEL 4.12 (`late_arrival` `{late_minutes, effective_open}`, `long_gap` `{gap_before_minutes}`, `long_day` `{day_minutes}`, `fee_high` `{spot_fee, sales}`, `early_start` `{start_prep}`, `ends_after_midnight` `{done}`), and the holiday name from the `DayContext`. `{stop}` is the stop's name.

| Code | Level | Text |
|---|---|---|
| `invalid_window` | error | "{stop}: the closing time must be after the opening time." |
| `stops_overlap` | error | "{stop} opens before the stop before it closes." |
| `stop_unreachable` | error | "{stop}: you cannot arrive and set up before it closes." |
| `late_arrival` | warn | "{stop}: you would open {late_minutes} min late, at {fmtClock(effective_open)}." |
| `outside_region` | warn | "{stop} is outside the loaded counties. People around it are missing or only partly counted." |
| `stale_vectors` | error | "{stop}: this estimate is out of date. It updates when the spot's details finish saving." |
| `outside_allowed_hours` | warn | "{stop} falls outside the days or hours you set for this spot." |
| `fallback_drive_time` | warn | "Some drive times are straight-line estimates, not Google drive times." |
| `long_gap` | warn | "{fmtDuration(gap_before_minutes)} of paid waiting before {stop}." |
| `long_day` | warn | "This is a {fmtDuration(day_minutes)} day, prep to done." |
| `fee_high` | warn | "{stop}: the fee is {fmtPercent(spot_fee / sales)} of expected sales." |
| `below_break_even` | warn | "{stop} is expected to lose money once its added costs are counted." |
| `event_thin_crowd` | warn | "{stop}: a thin crowd for the number of food vendors." |
| `weak_day_loss` | info | "{stop} loses money on a weak day." |
| `capacity_bound` | info | "{stop}: demand is above what the truck can serve for part of the time." |
| `early_start` | info | "Prep starts at {fmtClock(start_prep)}." |
| `ends_after_midnight` | info | "The day ends after midnight, at {fmtClock(done)}." |
| `no_forecast` | info | "No forecast for some of these hours, so no weather adjustment there." |
| `holiday` | info | "{holiday name} is a federal holiday. Patterns follow the holiday settings."; when the date is no holiday and the class comes from "Treat this day as" (`ctx.holiday` is null): "This day is treated as a holiday. Patterns follow the holiday settings." |
| `weak_seed` | info | "{stop}: most of this estimate rests on hospital, campus or transit figures, the weakest in the model." |
| `default_host_size` | info | "{stop}: the host size is a typical figure for this kind of place. Enter the real size to tighten the range." |

An unknown code prints "Check this stop." and logs the code once, so a new model warning never crashes the page.

### 6.6 Seed tags

| `tag` | Chip | Hint |
|---|---|---|
| `measured` | "Measured" | "Published by a source we opened." |
| `derived` | "Derived" | "Worked out from measured figures." |
| `assumed` | "Assumed" | "Our judgement. Not measured." |
| `tuned` | "Placeholder" | "Placeholder until you log services." |

### 6.7 Drive-time source labels

The label of a leg comes from the model's `Leg.source` and, for its wording, from the `DriveLeg` the server sent.

| Case | Label |
|---|---|
| `Leg.source` `override` | "Your time" |
| `Leg.source` `google`, `DriveLeg.source` `google_routes` or `google_distance_matrix` | "Google drive time, adjusted for {fmtClockShort(depart_minute)} traffic"; "Google drive time" when `trafficIsNeutral(A)` |
| `DriveLeg.source` `same_point` | "Same place" |
| `Leg.source` `fallback` (`DriveLeg.source` `straight_line`, or no leg at all) | "Straight-line estimate" with a `TriangleAlert` icon, then the reason by `fallback_reason`: `no_key`, `refused` "Google drive times are not switched on for this server."; `quota`, `budget`, `rate` "The Google drive-time allowance is used up for now."; `timeout`, `upstream` "Google did not answer in time."; `route_not_found` "Google found no route."; `cache_only` or missing: no reason |

The page-level strip of 2.6 appears when any leg of the day is a straight line or when `routing.state` is `no_key`, `refused` or `backoff`.

### 6.8 Place type and segment labels

Segment labels come from the seed file (`segments.<s>.label`). Place type labels: `taproom` "Brewery or taproom"; `bar` "Bar or pub"; `restaurant` "Restaurant"; `fast_food` "Fast food"; `cafe` "Cafe"; `convenience` "Convenience or grocery store"; `gym` "Gym or sports centre"; `park` "Park"; `shopping_centre` "Shopping centre"; `big_box` "Big-box store"; `campus` "College campus"; `hospital` "Hospital"; `transit_station` "Transit station"; `events_venue` "Events venue"; `stadium` "Stadium"; `hotel` "Hotel"; `attraction` "Museum or attraction"; `farmers_market` "Farmers market"; `office_park` "Office park"; `apartment_community` "Apartment community"; `industrial_site` "Industrial site"; `car_dealership` "Car dealership". Unit phrases for a host size, by the segment's group: visitors "people in its busiest hour"; workers "people working there"; residents "people living there".

### 6.9 Banned wording

No truck string, label, tooltip, toast, printed line or file may match any of these (case-insensitive): `\b(il)?legal(ly|ity)?\b`, `\bpermit\w*` (this does not match "permission"), `allowed to (park|trade|sell|vend|operate)`, `\bapproved\b`, `\bauthori[sz]ed\b`, `\bcompliant\b`, `\blawful(ly)?\b`, `\bzoned\b`, `\bok to park\b`. "Permission" appears only inside the standing notice. "Licence" appears only in the Settings strings about counties and in the Scout strings that repeat them. The app has no field, badge, colour or filter that expresses whether a spot may be used, and the model has none either.

---

## 7. Visual rules

Page patterns follow the Carafe screens (the newest design pass), not the older map screens. Light theme is the launch target; dark mode keeps working because every colour is a variable, and it is not polished further.

### 7.1 Tokens

| Use | Token or class |
|---|---|
| Headings, numbers, table text | `--ink` |
| Sentences, helper text, range lines | `--body` |
| Uppercase captions, table headers, axis text | `--slate` at weight 700 |
| Placeholders only | `--muted` |
| Borders | `--line-soft` on cards, `--line` on inputs and secondary buttons |
| Page background, panel background | `--bg`, `--bg-panel` |
| Primary action, selection, active tab | `--brand`, `--brand-light`, the nav tokens |
| A loss, an error | `--money-negative` with a minus sign or the word, never colour alone |
| A saving ("saves $79 in wages") | `--money-positive` on the figure only |
| A caution icon | `--fresh-aging` |
| Card | `bg-white rounded-xl border p-4 sm:p-5`, border `--line-soft`, no shadow |
| Floating over the map | the same plus `.shadow-float` |

New identity tokens, declared in `components/truck/truck.css` under `:root` and repeated under `:root[data-theme="dark"]`. They say which thing a mark is, never whether it is good or bad:

```css
:root {
  --tp-group-res:   var(--accent-revenue);     /* residents */
  --tp-group-work:  var(--accent-cost-food);   /* workers */
  --tp-group-visit: var(--accent-brand);       /* visitors and hosts */
  --tp-tl-prep:     var(--slate);              /* prep and close-out */
  --tp-tl-drive:    var(--accent-revenue);
  --tp-tl-setup:    var(--accent-cost-food);   /* setup and pack-up */
  --tp-tl-service:  var(--accent-brand);
  --tp-tl-wait:     var(--line);               /* hatched; the label says paid or unpaid */
}
```

`truck.css` also holds `.tp-scrim`, `.tp-modal`, `.tp-layer`, `.tp-sheet-right` and `.tp-sheet-bottom` (3.7), `.tp-bottom-bar` (the phone planner bar: `position: fixed; bottom: 0; padding-bottom: env(safe-area-inset-bottom)`), `.tp-pin`, `.tp-hint`, `.tp-no-print`, and the classes of the kit: `.tp-chip` and `.tp-chip-sm` (the neutral chip), `.tp-popover` (a hint), `.tp-switch` (the track and knob of `Toggle`), `.tp-invalid` (the border of a field with an error), `.tp-dim` (55 % opacity), `.tp-stat-list` (a hairline between rows), `.tp-row-click` (a row or card that opens something), `.tp-svg-focus` (the focus ring of a chart cell), `.tp-date-overlay` (the date input laid over its calendar button), `.tp-address` and `.tp-scroll-x`. `.tp-scroll-x` is `position: relative; overflow-x: auto; width: 0; min-width: 100%`: a block that scrolls sideways (a table, the timeline bar) takes the width it is given and adds nothing to the width its ancestors ask for, so it can never push a grid column or the page wider than the window; it is positioned so that an absolutely placed child (the visually hidden header of a table column) scrolls with it instead of widening the page. `.tp-address` is the wrapper every truck address field puts around the shared address widget, whose own input (40 px, another border and radius) then takes the height, border and radius of the truck's fields. The file also sets the height of every `.input` and `.select` that truck code renders (7.2). A page that puts a wide block of its own in a grid or flex cell gives that cell `min-width: 0` for the same reason. No hex colour is written in a component, with two exceptions: the map ramps of 5.5 (data, in `palette.ts`) and literal colours passed to the canvas.

### 7.2 Text, numbers, spacing, controls

| Thing | Rule |
|---|---|
| Font | Nunito only (already global). Never `font-sans`, never a second family. Canvas text names `Nunito` explicitly |
| Page title | `text-2xl font-extrabold`, `--ink` |
| Section title | `text-base font-extrabold`, `--ink` |
| Caption above a number | `text-[10px]` or `text-[11px]`, `font-bold uppercase tracking-wider`, `--slate` |
| Body copy | `text-sm`, weight 500 or 600, `--body`. Never weight 400 for a caption, never `text-slate-300`, `text-slate-400` or `--muted` for anything people read |
| Decision numbers | `tabular-nums`, weight 800 for the page's main figure (take-home), 700 in tables and rows, never under 600, always `--ink` |
| Estimates | only through `RangeValue`: value, range with "to", confidence chip |
| Page container | `max-w-7xl mx-auto px-4 md:px-6 py-4 md:py-6`; stacks `space-y-4`; grids `gap-3` or `gap-4` |
| Radii | 8 px on buttons and inputs, 12 px on cards, panels, modals; full only on chips. Nothing rounder |
| Buttons | `.btn .btn-primary` once per view; `.btn-secondary` for the rest; text buttons for minor actions; danger actions use `.btn-danger` only inside the confirming modal. `h-9 px-3 text-sm` on desktop, at least 44 px tall below `md` |
| Inputs | `.input`; labels with `.label`; 44 px tall below `md` and 36 px from there on. `truck.css` sets the two heights itself, for every `.input` and `.select` inside the gate's element (`[data-tp-chunk]`) and inside a layer (`[data-tp-layer]`), with no vertical padding: the app's own `.input, .select { height: 40px }` stands outside any cascade layer, where it beats every Tailwind height utility, so `h-11 md:h-9` on such a field decides nothing. A field that wants another height says so with an important utility (`md:h-8!`) |
| Focus | the global `:focus-visible` ring (2 px `--nav-ring`, 2 px offset) is never removed; inputs keep the `.input` focus border; every custom control is reachable and operable by keyboard |
| Status | every status pairs a word with an icon; colour is never the only signal |
| Motion | existing classes only (`.panel-slide-*`, `.card-expand`, `.carafe-route-fade`), 150 to 250 ms, all off under reduced motion. Numbers do not count up: `AnimatedNumber` is not used, values change at once |
| Icons | lucide only, `currentColor`, 14 to 16 px inline, 20 to 22 px in titles |
| z-index | sub-nav 20, floating map cards 20, popovers 40, modal and sheet 50, a hint opened inside a modal or a sheet 60. A sheet that is not modal sits at 20 from 768 px and at 50 below it, where it covers the screen (3.7) |

### 7.3 Charts

House charts are hand-rolled SVG (3.10 and the accuracy chart): fixed `viewBox`, `width="100%"`, `role="img"` with an `aria-label`, colours from variables, axis text at weight 700 in `--slate` and 10 or 11 units of the view box (3.10: about 10 px on a phone, never under 9 px at the chart's smallest width), at most four gridlines in `--line-soft`, a one-sentence summary printed above the chart, a legend as `<ul aria-label="Chart legend">` when there is more than one series, and a horizontally scrolling wrapper with a `minWidth` on phones. No chart library is imported by truck code.

### 7.4 Print stylesheet (`components/truck/print.css`, package FE-8)

Plain CSS, loaded with the truck chunk and scoped so that it cannot touch another page: every rule sits inside `@media print` and is prefixed with `html.tp-printing`. `DaySheetPage` adds the class `tp-printing` to `document.documentElement` on mount and removes it on unmount.

| Rule | Value |
|---|---|
| Page | `@page { size: letter portrait; margin: 12mm; }`. This rule is not in the file (a class cannot scope an `@page` rule): `DaySheetPage` appends it as `<style id="tp-page">` on mount and removes it on unmount |
| Hidden in print | the top nav (`header`), the skip link, the sub-nav (`nav[aria-label="Truck Planner sections"]`), `.tp-no-print` (toolbars, buttons), toasts, the phone bottom bar |
| Colours | black text on white; no backgrounds, no shadows; borders 1 px `#000` at 40 % for tables; chips print as bordered text |
| Type | body 11 pt, section titles 13 pt bold, the day's title 18 pt bold; times in the "Times" table 12 pt bold, `tabular-nums` |
| Layout | one column, `max-width: none`; tables full width; `break-inside: avoid` on each stop block and on table rows; `break-after: avoid` on headings |
| Links | printed as their text only; no URLs appended |
| Ranges | always printed as text through `fmtEstimate`, so a black-and-white page carries the same information |

### 7.5 Do and do not (owner taste)

| Do | Do not |
|---|---|
| Open on operations: next stop, today's plan, what to log | Open on a map or a wall of widgets; do not copy `DashboardPage` |
| White cards on `--bg` with a 1 px border | Gradients of any kind (logo tile, progress bar, tinted cards), glows, gradient borders. The legend ramp and the week strip cells are data colours and are the only exception |
| Plain scrims `rgba(15, 23, 42, 0.45)` | `backdrop-blur`, translucent panels |
| `rounded-xl` at most | `rounded-2xl` and larger, bubbly pill buttons |
| `--ink` and `--body` text, labels at weight 700 | light grey reading text, thin captions |
| Purple for the primary action and selection only | purple fills behind content, the lighter accent stops (`--accent-cost-labor`, `--accent-margin`, `--accent-attention`) as text colours |
| A word and an icon for every status | red or green as the only difference |
| Lists that stay visible; one primary button per view | primary lists hidden behind tabs or modals; a second top bar or a second loading screen |
| Plain numbers with their range and label | emoji, "AI", "smart", sparkle icons, celebration effects |
| Real usefulness at 375 px for Today, Planner, Log and the day sheet; 44 px targets | shadows on page cards, bouncy motion, count-up numbers while the hour is dragged |
| Variables and the `bg-white` class | hex in components, inline `background: 'white'`, Tailwind `dark:` classes |

---

## 8. Tests

### 8.1 Layout and commands

Vitest collects only `src/**/__tests__/**/*.test.ts` in a Node environment: no DOM, no canvas, no `.test.tsx`. Everything worth testing is therefore kept in pure modules. Test files are not type-checked by `tsc`. All truck tests live in `utils/truck/__tests__/`, with the file names of 8.2 so packages never collide.

```bash
cd frontend
npx tsc --noEmit -p tsconfig.json          # not `tsc -b`: that rewrites the tracked tsconfig.tsbuildinfo
npm test                                   # vitest run
npx vite build --outDir "$TMP/tp-build" --emptyOutDir     # never build into public/app during checks
node scripts/check-truck-chunks.mjs "$TMP/tp-build"
```

### 8.2 Unit tests

| File | Owner | Must cover |
|---|---|---|
| `estimator.*.test.ts` | estimator engineer | every case of the golden file with the model tolerance; the seed copy equals `tp_seeds.json`; the three anchors; the fast path with `Float32Array` at its looser tolerance |
| `format.test.ts` | FE-0 | every example of 3.1; negative and zero money; `-0` never printed; grouping at 999 / 1,000 / 1,000,000; `fmtClock` at -30, 0, 720, 1439, 1440, 1470; `fmtEstimate` for `fixed`, zero, singular, negative low; `parseClock` examples of 3.6 and rejects (`25`, `12:60`, `13pm`, empty); `parseNumber`; `parseCoords`; `fmtPercent(40 / 100)` gives `40%` |
| `time.test.ts` | FE-0 | `howOf`, `howParts` round trip for 0..167; `mondayOf` across a year boundary; `nextDateWithDow` when today is that weekday (returns today) |
| `clock.test.ts` | FE-0 | the four check values of 2.7; the end of daylight time: `regionNow` at 2026-11-01 05:59 UTC is minute 119 and at 06:00 UTC minute 60, and `zonedToUtcStamp(..., '2026-11-01', 90)` is `20261101T053000Z` (the earlier 1:30 AM); on 2026-10-08, minute 1500 gives `20261009T050000Z` and minute -30 gives `20261008T033000Z`; a wall time that does not occur (2:30 AM on 2026-03-08 is `20260308T073000Z`) and the same two cases east of Greenwich (`Europe/Berlin`); `utcStamp`; the skew rule of 2.7 (one minute ignored, ten minutes applied, a device two days off still shows the server's day) |
| `links.test.ts` | FE-0 | the URL shapes of 3.14 character for character, including the server's own example `https://www.google.com/maps/search/?api=1&query=38.960000%2C-77.360000`; exactly six decimals; no `waypoints` param when empty; `mapsDirUrl` and `mapsSearchUrl` give the same strings as the server's `MapsUrl::route` and `MapsUrl::point` for the same inputs (the formats of 04_BACKEND 2.3) |
| `wording.test.ts` | FE-0 | every confidence label, seed tag and place type and each of the 21 warning codes has a string; the standing lines of 6.3 character for character, the two toasts of a profile save (`profileWarningTexts` says nothing for a code it does not know) and the map's own strings among them; no string in `wording.ts` matches a banned pattern (6.9) |
| `warnings.test.ts` | FE-0 | each of the 21 codes renders from a `DayResult` built by `dayPlan` (reuse the inputs of golden family g18); an unknown code gives the fallback text |
| `breakdown.test.ts` | FE-0 | thirteen steps, in order, with the exact titles, for a window, an event and a day; "no forecast", "typical week" and "no logged services" lines; both step-7 precipitation cases (a chance given; a null `precip_prob` with a class other than `dry`); the event rows of 3.4; seeds tagged `tuned` are flagged |
| `timelineView.test.ts` | FE-0 | the blueprint day sheet gives exactly the twelve segments of 3.10; an unpaid wait; a late arrival (no wait segment); an empty timeline |
| `assemble.test.ts` | FE-0 | fixtures in the shapes of 04_BACKEND 4.1 (`Plan`, `Spot`, `DayInfo`, `DriveLeg`) for the worked day of 02_MODEL 4.12 produce, through `toStopInput`, `toLegs`, `buildContext` and `dayPlan`, the event minutes 574, 619, 630, 660, 840, 860, 870, 1020, 1200, 1220, 1221, 1251 and take-home 482.20 (42.35 to 1011.86) within tolerance; `buildContext` with a null override equals the server's `DayInfo.context`; `buildAssumptions` throws on a version mismatch; an absent pair stays absent (never zero-filled); the keys of `toLegs` are `from_id>to_id`; `hostKey` ignores `only_food` and the size of a visitor host; a one-stop day on `typicalWithFuel` evaluates and raises neither `no_forecast` nor `holiday`; a `Spot` fixture with three different vector blocks yields the block of `terms.visibility` through `spotVectors(spot)`, and `spotVectors(spot, 'hidden')` yields the hidden block; `trafficIsNeutral` is false on the revision 1 seeds and true when the region's matrix and its typical value are all 1.0; `toStopInput` for an event stop, a catering stop, an archived spot, a stop without a place (null) and a spot without vectors, with `stopsEvaluable` for each; a missing leg gives `fallback_drive_time` with its key; `coord6`, `pointsKey` (order-free, code-unit order), `linkedHostKey`, `visibilityKey`, `sortedJson`; the tolerance of the drift checks. The fixtures are built from golden case g18-001, so the assembly is fed exactly what the reference was |
| `shell.test.ts` | FE-0 | `apiErrorMessage` (server sentence, fallback, null without a response, the two sentences that are always replaced, the fallback in place of whatever a 501 says) and the other error helpers of 2.6; `truckRetry`; the shapes of `truckKeys`; `fetchPack` refuses a URL that is not a pack path; the hour store (wrap, no notification when unchanged, `setDate`); the UI store (defaults, what is persisted, what is taken back from storage); the draft store (`load` ignored while dirty, `markSaved`, `discard`, temporary ids); `planFromRow`; `patchChangesVectors` |
| `palette.test.ts` | FE-0 | table length 1,024; entry 0 transparent; strictly falling relative luminance along each ramp; reversed order on the dark theme; tick position = `sqrt(v / hi)`; `minByte` = 12, 13, 13; band lookup at every tick byte |
| `logView.test.ts` | FE-0 | unlogged stops (closing time passed, no `ServiceLog` with that `plan_stop_id`, cancelled plans ignored, event and catering stops included); the four verdicts at the range edges; `calibrationBefore`: with the seven services of 02_MODEL 4.13, an entry dated 2026-08-10 is calibrated from s1, s2 and s3 only |
| `kit.test.ts` | FE-0 | the functions of `components/truck/ui/kit.ts`: the spoken label of 3.2; what a number field commits and refuses (empty, required, not a number, a fraction in a whole-number field, out of range, never clamped), its text at rest, a percent field, the arrow step; `commitTime` with the "(next day)" marker and the range message; `sortRows` (numbers, strings, a missing number last, ties by row key) and `nextSort`; tab ids and tab keys; gridlines and bar heights; week-strip runs across midnight and across Sunday night and its key moves; `keepLabels`, and `timelineLabelKeep` on the blueprint day (9:34 AM, 11 AM, 2 PM, 5 PM and 8 PM stay, 8:51 PM gives way); the weather chip (range of temperatures, worst class, highest chance, no chance given, no forecast) and its title; the holiday chip |
| `map.mercator.test.ts` | FE-1 | `worldX(-180) = 0`, `worldX(180) = 256`, `worldY(0) = 128`, round trip within 1e-9 degrees at the `dc` centre; the clamp near the poles |
| `map.pack.test.ts` | FE-1 | a pack written by the test helper `_packFixture.ts` (which mirrors 03_DATA section 11) decodes to the same ids and to values within the quantisation bound `sqrt(v * scale) / 65535 + scale / (4 * 65535^2)`; zero is exact; each `PackError` code; a header length that needs 0 and 7 padding bytes |
| `map.mesh.test.ts` | FE-1 | with real `h3-js`: `latLngToCell(38.9696, -77.3861, 9)` is `892aaab3043ffff`; that cell yields six distinct vertices around its centre; positions are relative to the origin and, for cells at the corners of the `dc` box, their float32 error is under 0.02 px at zoom 19 (the largest zoom of the map) and under 0.04 px at zoom 20, where absolute world coordinates are more than a pixel off; every cell has its own outline, vertex for vertex what `h3-js` gives; a five-vertex input repeats its last vertex; an outline of more than six points loses the ones on its sides; index pattern; the caller is asked every 1,024 cells whether to hand control back |
| `map.frames.test.ts` | FE-1 | a one-cell pack holding the cell of 02_MODEL 4.17 gives bytes 206, 38 and 23 (each within 1) at `how` 84; the capacity clamp; the floor zeroes bytes under `minByte`; a 500-cell pack matches `cellScores` and `scoreByte` to one byte at all 168 hours; date rows equal week rows for a typical context and a holiday changes its day; competition has two frames; `setInputs` says whether frames may have changed; the kept frames (a copy is handed out, `warm`, `has`, the whole week of one layer, a new start for another layer, date or inputs, the 16 MB limit); `meanByte` |
| `map.pick.test.ts`, `map.viewport.test.ts` | FE-1 | index lookup for a point inside and outside the pack; points 2 % either side of every edge of a cell fall in that cell and in the neighbour across the edge; `zoomOf`, `alphaForScale` at zoom 8.9, 9.5, 10; the world rectangle in view and the cells that touch it; the shader uniforms of a viewport; blank-host pin projection, its inverse, pan, zoom about a point, the zoom limits and the grid lines |
| `map.perf.test.ts` | FE-1 | loose bounds on any CI machine, on a synthetic pack with K = 50 features per cell: scoring 60,000 cells for one hour under 10 ms (median of 20 hours that are not kept yet); decoding a 60,000-cell pack under 500 ms; building its mesh under 1,500 ms |
| `hourControl.test.ts` | FE-2 | the playback accumulator: 2.5 x the step time advances two hours and carries the rest; wrap from 167 to 0; no advance while paused; a late frame moves at most four hours. The keys of 4.2 (never while typing, with a modifier or with focus outside the page and the hour bar; Space left to a focused button). The URL parameters of 1.2: every one read, a bad value counts as absent, the canonical spelling, other owners' keys kept, a way back only under `/truck`, the round trip, the six decimals a place is handed on with, and where the map starts. The layouts by width, and when the legend gives way to the card (`legendGivesWay`). The hover hint is a band of 5.5 for every byte of every layer and never a single figure; where the hint goes; the legend marker; the status sentences. The spot card: the two anchors of 02_MODEL 8.3 through `weekStrip`, `bestWindows` and `windowOrders` on the typical week print "60 orders (33 to 93)" and "39 orders (21 to 62)" with "Rough"; `whoIsHere` (the 1 % rule, at most six, the host first, ranked by people without orders); which unit margin "Each order leaves" shows; the drive row with and without a leg from the server |
| `spotSummary.test.ts`, `profileForm.test.ts` | FE-3 | best window, orders and contribution for the two anchors of 02_MODEL 8.3 (Tuesday 11 AM to 2 PM with 66.6553 orders, Saturday 5 PM to 8 PM with 64.5480), and the anchors themselves on a typical Thursday ("60 orders (33 to 93)", "39 orders (21 to 62)", rough); spots without vectors and spots whose vectors do not match their terms; the one-stop day equals `dayPlan` on the inputs of `useSpotEstimate`; the order and the search of the list; the compare parameters and "Highest expected"; the fee phrase and the distance in feet; the spot form: the body of route 11, a linked host as a place key and never a point id, the name of a linked place as the host name unless the owner typed one, no host details without a host, the size label by segment group, only changed keys in a patch, `patchChangesVectors` true for a moved point, another link, another segment and the size of a worker host and false for every other edit, refusals with the range message that leave the value as typed. Profile draft round trip; every range read from the seed metadata and none written in a component of the package (a source check); out-of-range values rejected, not clamped, with the sentence the number field gives; only changed profile keys; "What these settings mean" on the default profile (9.541 an order); the assumptions: the 194 owner-scope paths and no other, their bounds held by `validateOverrides`, only changed paths in a save and `null` for a reset, the four messages by code, the `details` of a 422; the seeds of "Fixed in this version", each with a plain-language label of its own from `seedLabel` (none named by the words of its path, no two alike) |
| `planDraft.test.ts` | FE-4 | add (a new stop lands where its window falls and the others keep their order), move (one place, to any position, nothing at the ends), remove; temporary ids from the counter, never `base`, and absent from the save body; default window avoids overlap (the best three free hours of the date, the spot's own days and hours, the fall-back to 11 AM to 2 PM); dirty detection (an edit taken back by hand is no change; a stop removed and added again is one); the save body matches 04_BACKEND 4.11: it carries `status` and no `state`; what holds a save back. On the worked day of 02_MODEL 4.12, assembled from golden case g18-001 as the planner assembles it: take-home "$482 ($42 to $1,012)", the second stop adding "$135 (-$43 to $353)" for 5.8 hours more with "Needs 26 orders to pay for itself.", the unpaid alternative "$561 ($122 to $1,091)" saving $79 in wages, the cost lines, and the twelve clock times of the blueprint day in the timeline rows and in the drive rows and stop cards. Drive rows: the source label of each kind of leg, every fallback reason, the three toll lines, the editor's first line |
| `nextAction.test.ts` | FE-5 | each event kind, before the first event, after the last, a day that ends after midnight |
| `logForm.test.ts` | FE-6 | the address of 1.2 (every parameter read, a bad value counts as absent, a link that starts an entry shows the services tab, `logHref` reads back); the draft (the "Spot" list with a deleted spot only for the entry that is at it, a link from Today, a planned event taking its kind and its name from the plan, what was typed in the meantime kept); `linkHolds` (the hours may differ; another spot, date or kind drops the link); the checks (what is missing, the range messages, nothing clamped, the date and the closing time); the body of route 32 against the example of 04_BACKEND 4.13, `plan_stop_id` only while the entry describes its stop and never `treat_as`, the sold-out switch, whole cents, no spot for an event; only changed keys in an edit and `plan_stop_id: null` when it leaves its stop; rule 1 on the worked day of 02_MODEL 4.12 ("60 orders (33 to 93)" and "39 orders (21 to 62)", rough; null for other hours, another spot, date or `treat_as` and for a stop that is not in the stored result; hours counted from `effective_open`; a planned event whatever its hours); rule 2 on anchor A2 saved as spot B with the seven services of 02_MODEL 4.13 (35.592 orders, 20.60 to 53.53, fair; "39 orders (21 to 62)" and rough without them; only the services before the date; never the service being edited); every state of the estimate line; the sentences of the result card (the answer of the example of 04_BACKEND 4.13, each verdict, a sold-out count as a minimum, the factors before and after, an event, a service without an estimate); the history (the result cell, a row still on its way, the order, the default range, the 730 dates) |
| `accuracyView.test.ts` | FE-6 | the example of 02_MODEL 4.13 through `accuracyReport` and `calibrate`: the four tiles ("7" with "6 scored, 1 sold out"; "Estimates ran 7% high" with "Before your results were used: 7% high"; "20%"; "6 of 6"), the chart sentence "6 of 6 scored services landed inside the estimated range.", the rows of "By spot" (3% low, 39% high, 5% high; 12%, 39%, 5%; 3 of 3, 2 of 2, 1 of 1; x1.03, x0.94, x1.00), "Truck factor x0.96 from 6 services." and the three spot lines; bias at the edges of 2 %; the tiles without a scored service and without an order; the note in the singular and the plural, naming the kinds that are left out; the chart: the last 30 services in date order, those of one day by opening time (the id only settles a tie), a sold-out service neither inside nor outside, the top of the orders axis, one column per service with its range, estimate and actual, the dates that fit, a day named once |
| `scoutView.test.ts` | FE-7 | host-fit words at 0.39, 0.4, 0.69, 0.7; the three kitchen sentences; the size phrase by segment group; the three size cases (`host_size` above 0; `host_size` 0 with a `host_segment`; `host_segment` null) on the card and in the save modal; a card whose `lead.spot_id` is set (no estimates, no size line, no "Why this number") |
| `ics.test.ts` | FE-8 | the worked day gives three events with `DTSTART:20261008T133400Z` for the day (9:34 AM Eastern) and `20261008T150000Z` for the first stop; CRLF endings; folding at 75 octets with a multi-byte character at the fold; escaping of comma, semicolon, backslash and newline; identical bytes for identical input |

### 8.3 Source guards

Guards are Vitest tests that read source files with `node:fs`. `_closure.ts` (a helper, not a test) provides `truckFiles()` (every `.ts`, `.tsx`, `.css` under `components/truck/`, `utils/truck/`, plus `api/truck.ts` and `stores/truck*.ts`, excluding `__tests__/`), `importClosure(entries)` (follows static `import`/`export ... from` and dynamic `import()` with relative specifiers, resolving `.ts`, `.tsx` and `/index.ts`; records bare package names) and `stripComments(source)` (so a comment that names a forbidden thing does not trip a guard; paths are normalised to forward slashes for Windows). `importClosure(entries, { dynamic: false })` leaves dynamic imports unfollowed, which is the set a bundler puts in one chunk; type-only imports are followed like any other; a relative specifier that resolves to no file is reported and fails the guard that walked it. `stripComments` is a scanner, not a parser: a quote that is not closed on its own line is taken for an apostrophe in JSX text, not for a string. Every guard ends with a test that feeds it a forbidden sample, so a guard that has stopped matching fails too. The closure today reaches the shared shell files (`AppNav`, `ErrorBoundary`, `api/client`, `api/advanced`, `api/usage`, `authStore`, `uiPrefsStore`, `costStore`, `GooglePlaceAutocomplete`, `mapsLoader`, `mapStyle`) and is clean. `api/geocoding.ts` is deliberately not used: its server route can echo a URL that carries the Google key (DECISIONS 13).

| Guard | Scope | Fails on |
|---|---|---|
| `guards.geolocation.test.ts` | truck files and their import closure | `geolocation`, `watchPosition`, `getCurrentPosition`, `navigator.permissions`, `permissions.query` |
| `guards.ai.test.ts` | truck files and their import closure | hosts: `api.anthropic.com`, `api.openai.com`, `openai.azure.com`, `generativelanguage.googleapis.com`, `aiplatform.googleapis.com`, `bedrock`, `sagemaker`, `api.mistral.ai`, `api.cohere.`, `api.groq.com`, `api.together.`, `openrouter.ai`, `api.perplexity.ai`, `api.x.ai`, `api.deepseek.com`, `api.fireworks.ai`, `api.replicate.com`, `huggingface.co`, `api.voyageai.com`, `api.ai21.com`, `:11434`, `:8088`, `ml-sidecar`. Packages: `@anthropic-ai/`, `openai`, `@google/generative-ai`, `langchain`, `@huggingface/`, `cohere-ai`, `@mistralai/`, `ollama`, `@tensorflow/`, `onnxruntime`. Key names: `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`. Endpoints: `/ai-score`, `/ai-rankings`, `/dashboard/briefing`, `/recommendations/run`, `/recommend`, `/restaurants/sample`, `/pos/`. First-party names on word boundaries: `AiScoringController`, `OpsController`, `MenuEngineeringService`, `MenuEngineeringController`, `SampleDataService`, `pos.sync`. The bare word "model" is not banned |
| `guards.network.test.ts` | truck files | `fetch(` as a call of its own or on `window`, `self` or `globalThis` (`refetch(` and `fetchPack(` are other names), `XMLHttpRequest`, `WebSocket`, `EventSource`, `sendBeacon`, an import of `axios`; any `api.get/post/put/delete` path literal that does not start with `/api/truck/` (the one non-literal path is `fetchPack(url)`, which itself rejects a URL that does not start with `/api/truck/regions/`); any `http://` or `https://` literal whose host and path prefix is not one of `www.google.com/maps/`, `www.openstreetmap.org/copyright`, `lehd.ces.census.gov/data/`, `www.w3.org/`, and any protocol-relative URL in a string; any bare import outside `react`, `react-dom`, `react-router-dom`, `@tanstack/react-query`, `zustand`, `zustand/middleware`, `react-hot-toast`, `lucide-react`, `@react-google-maps/api`, `h3-js` |
| `guards.wording.test.ts` | truck files, including the seeds | the patterns of 6.9; emoji and dingbat code points (U+1F300 to U+1FAFF, U+2600 to U+27BF); `\bAI\b`, `\bsmart\b`, `\bmagic`, `powered by` inside string literals and JSX text; the word "permission" in a string or in JSX text of any file but `utils/truck/wording.ts`, where the standing notice lives (6.9). Also asserts that `spot/SpotCard.tsx` or `spot/SpotAnalysis.tsx`, `pages/SpotsPage.tsx`, `pages/SpotDetailPage.tsx`, `pages/SpotComparePage.tsx`, `pages/ScoutPage.tsx`, `pages/PlannerPage.tsx` and `sheet/DaySheet.tsx` render `PermissionNotice`, and that `spot/SpotForm.tsx` renders a `SourceLine` with kind `osm` |
| `guards.determinism.test.ts` | truck files | `Math.random` everywhere; outside `utils/truck/clock.ts`: `new\s+Date\b`, `\bDate\.(now\|parse\|UTC)\b`, `\bDate\(\)`, `\bIntl\.`, `\bcrypto\.`, `toISOString` and `toLocale`; outside `components/truck/map/` and `utils/truck/map/`: `performance\.now` and `performance\.timeOrigin` (`performance.mark` and `performance.measure` stay allowed everywhere), `.toFixed(` and `Math.round(`; `localeCompare`; `setInterval(` (playback and clocks use animation frames and aligned timeouts) |
| `guards.honest.test.ts` | `components/truck/` except `ui/RangeValue.tsx` | a formatter applied straight to an estimate's parts: a call to `fmtMoney`, `fmtMoneyCents`, `fmtCount`, `fmtCount1` or `fmtPerHour` whose first argument ends in `.value`, `.low` or `.high`, unless the line carries `// tp-allow-bare: <reason>` (the three-column money table and chart ticks are the expected uses) |
| `guards.eager.test.ts` | the static import closure of `App.tsx` (dynamic imports not followed) | any file under `components/truck/` other than `TruckLayout.tsx`; anything under `utils/truck/`; `api/truck.ts`; `stores/truck*.ts`; the package `h3-js`. Also: `TruckLayout.tsx` imports only the five modules of 1.7; `App.tsx` holds exactly one `import()` expression and it targets `TruckPages`; `AppNav.tsx`, `CommandPalette.tsx`, `ProtectedRoute.tsx` and `GooglePlaceAutocomplete.tsx` import nothing from truck code; `TruckPages.ts` imports `./truck.css`, `./print.css` and `./map/authFailure` and exports the gate and the thirteen pages that `App.tsx` wraps; the sentinel of 8.4 is exported by `model.ts` and rendered by `TruckGate` |

### 8.4 Type-check and build checks

1. `npx tsc --noEmit -p tsconfig.json` exits 0 (strict mode).
2. `npm test` passes: the two existing test files, the estimator tests, and everything in 8.2 and 8.3.
3. The build succeeds and `frontend/scripts/check-truck-chunks.mjs <dir>` passes: exactly one `assets/TruckPages-*.js`; the sentinel string `tp-chunk-sentinel` (exported by `utils/truck/model.ts` and rendered by `TruckGate` as a `data-tp-chunk` attribute, so it cannot be tree-shaken) occurs in that file and in no other `.js` file; no `assets/index-*.js` contains `cellToBoundary`, and exactly one `assets/MapPage-*.js` is the only script that does; `index.html` mentions neither `TruckPages-` nor `MapPage-`; the gzip sizes meet the bundle budget of 5.9. The script prints the sizes it measured (gzip level 9, kB = 1,000 bytes) and exits 1 on a failed check, 2 when it is called on something that is not a build. The growth of the main chunk is measured against the gzip size of `index-*.js` before Truck Planner: 245,406 bytes for the build of commit `f3c49fc`, or the figure passed as `--main-baseline=<bytes>` (environment variable `TP_MAIN_BASELINE_GZIP`) when code outside Truck Planner has changed the main chunk since; `--main-growth` and `--chunk-budget` override the two budgets.
4. CI: `.github/workflows/truck-planner.yml` (owned by backend package P1, 04_BACKEND 8.3) runs `npm ci && npm test` in `frontend` on Node 20, which covers 8.2 and 8.3, and then the three checks of items 1 and 3 as further steps of the same job: `npx tsc --noEmit -p tsconfig.json`, `npx vite build --outDir "$RUNNER_TEMP/tp-build" --emptyOutDir` and `node scripts/check-truck-chunks.mjs "$RUNNER_TEMP/tp-build"`.

### 8.5 End-to-end browser pass

There is no browser test runner in the repository and none is added. The pass is a scripted checklist run by a person or a browser-driving agent against a local stack built as in 04_BACKEND 8.3: a scratch MySQL 8 with the migrations applied and a region loaded (the mini region fixture for function, the full `dc` build for row 5), the API under `php -d date.timezone=UTC -S 127.0.0.1:8080 -t public public/index.php`, from wave 2 on demo data from `php scripts/truck/seed-demo-truck.php --email=<user>` (a truck in Sterling, five spots, one planned Thursday, twelve logged services), and the app under `vite dev` (with the dev fallback of 9.3) or as a built copy under Apache. Rows 1 to 8 (wave 1) create their truck and spots through the screens. It is run at 1440 x 900, 820 x 1180 and 375 x 812, with `?tp_basemap=blank` for everything except the checks marked G, which need a real Google key. On the Google map a script moves the pointer to a place in a few steps before it clicks there, as a hand does (5.6), and a headless Chrome is started with `--enable-gpu --ignore-gpu-blocklist`: without them WebGL runs in software and the frame figures of row 5 mean nothing. Intercepting requests switches the browser's HTTP cache off, so the warm-cache figure of row 5 is taken without interception. No check may depend on the device's position, and the browser must show no location prompt at any point.

| # | After wave | Check |
|---|:---:|---|
| 1 | 1 | Hard-load `/truck`, `/truck/map`, `/truck/spots/x`, `/truck/nonsense`; refresh each; log out and in again and land on `/truck`. The Truck tab is first and lit on every sub-route; the sub-nav scrolls at 375 px |
| 2 | 1 | A new organization sees "Set up your truck" on every `/truck` URL; saving shows the requested page; an out-of-area base shows the warning and still saves |
| 3 | 1 | Map: the layer appears; switching layers changes the legend title, ramp and ticks; dragging the hour changes colours with no visible lag; Space plays and pauses; "Now" jumps; the hour and layer survive a reload through the URL |
| 4 | 1 | Map: hover shows a band, never a single number; clicking opens the spot card at once with a skeleton and then numbers; every number has a range and a chip; "Why this number" lists thirteen steps. On a touch screen, tap the map where the bottom sheet will come up: the card opens at its resting height and the tap presses nothing in it (5.6) |
| 5 | 1 | Map with `?tp_perf=1` on the reference machine: tick and frame figures within 5.9 while playing at "Fast" for 30 seconds over the whole region; repeat in Safari and Firefox (G) |
| 6 | 1 | Map resilience: block the pack request (colours unavailable, clicking still works); a truck whose region is `none` (no colours, the "outside" card on a click); force the 2D renderer (disable WebGL); G: an invalid key switches to the blank base with the notice; no key works in development mode |
| 7 | 1 | Save a point as a spot (with and without a linked host); edit fee, visibility and host size and watch the numbers change; the list, detail and compare pages agree with the card for the same window; delete asks first |
| 8 | 1 | Settings: change the ticket and capacity, save, and see the map's cap (the legend names it and the colours stop there) and the spot numbers follow; an out-of-range value is rejected with the range message; override an assumption, see the tag "Your value", reset it |
| 9 | 2 | Planner: rebuild the blueprint day (two stops, 11 AM to 2 PM and 5 PM to 8 PM); the timeline shows prep, leave-by, arrive, open, close, leave, back at base and done in order; mark the wait unpaid and watch labour and take-home change; reorder with the buttons at 375 px; override a drive time and enter a toll; save, reload, and find it again |
| 10 | 2 | Planner honesty: with drive times unavailable every leg reads "Straight-line estimate" and the warning shows; with the forecast unavailable the strip of 2.6 shows; a holiday date shows its chip and "Treat this day as" changes the numbers at once |
| 11 | 2 | Week: planned and empty days, the week total, navigation across a month end; Today: the "Next" sentence follows the clock, the plan card matches the planner, "Log it" prefills the form |
| 12 | 2 | Log: save a service, see the verdict and the changed factors; a second service for the same spot and time shows the server's refusal; the spot card's chip moves from "Rough" toward "Fair" only through logged services; a sold-out service is counted as a minimum; Accuracy fills in |
| 13 | 3 | Scout: ranked places with ranges reading "Very rough" where sizes are assumed; filters; lead status sticks and hidden places leave the list; "Look up phone and website" is only ever triggered by its button and shows what Google matched (G); "Save as spot" works; the OpenStreetMap credit and the standing notice are on the page |
| 14 | 3 | Suggestions: "Suggest a day" and "Suggest a week" fill only empty days after confirmation; an event stop reads "Very rough" and a catering stop "Fixed" |
| 15 | 3 | Day sheet: print preview on Letter shows no navigation, black on white, ranges as text, the notice and credits in the footer; the `.ics` file imports into a calendar at the right local times; every "Open in Google Maps" link opens the right pin or route (G); after leaving the day sheet, the print preview of another smappen page still shows its top bar and backgrounds |
| 16 | 3 | Data and export: the attribution strings match 6.4; the export downloads and parses as JSON; "Delete everything" needs the typed phrase and returns to "Set up your truck" |
| 17 | all | Keyboard only: reach and operate every control on Today, Planner and Log; focus is always visible; modals trap and return focus. Search the rendered pages for the patterns of 6.9: no match |

---

## 9. Work packages

### 9.1 Rules

1. Nine packages. FE-0 lands first. After it, the packages of one wave run in parallel; a later wave starts when the earlier wave's acceptance checks and its rows of 8.5 are green. The waves match the backend's (04_BACKEND section 9): wave 1 needs backend P1 to P4, wave 2 needs P5 and P6, wave 3 needs P7 and P8. Until a backend package lands its routes answer 501, which the screens show as an ordinary failed request.
2. File ownership is disjoint. A package creates or edits only the files in its row. The shared files (`App.tsx`, `AppNav.tsx`, `CommandPalette.tsx`, `components/auth/ProtectedRoute.tsx`, `components/common/GooglePlaceAutocomplete.tsx`, `api/truck.ts`, the three stores, `components/truck/ui/`, `components/truck/data/`, the `utils/truck/*.ts` files listed under FE-0, `truck.css`, `frontend/package.json`, `frontend/vite.config.ts`) belong to FE-0 for the whole build. A package that needs a change there asks FE-0's owner; it does not edit them.
3. `utils/truck/estimator/` and `utils/truck/__tests__/estimator.*.test.ts` sit inside FE-0's boundary but are written by the estimator engineer. FE-0 integrates them through `utils/truck/model.ts` only.
4. FE-0 creates every page and every cross-package component as a typed stub (9.2). From then on the stub's file belongs to the package named as its owner, which replaces the body and keeps the exported name and prop types. Because the stubs type-check and render, the app builds and every route resolves from the first day.
5. Pure helpers a package needs go in `utils/truck/<name>.ts` with tests in `utils/truck/__tests__/<name>.test.ts`, using the names listed in its row.
6. Every package finishes with: `npx tsc --noEmit -p tsconfig.json` clean, `npm test` green (guards included), the build and `check-truck-chunks.mjs` passing, and no file outside its row changed.

### 9.2 Seams created by FE-0

| File | Stub behaviour | Owner afterwards | Used by |
|---|---|---|---|
| `components/truck/pages/*.tsx` (the 11 pages and 2 redirects of 1.1) | a page with its `<h1>` and "Not built yet."; `TodayPage` is the starter page of 4.1; the redirects work (they keep the query string, so `/truck/plan?add=<spotId>` still reaches today). The param checks of 1.1 are already in the stubs of `PlannerPage`, `DaySheetPage` (date), `WeekPage` (week start) and `SettingsPage` (tab) and stay when the body is replaced. `MapPage` fills the positioned box the gate hands it (1.4) and brings its own padding. Every stub that `guards.wording.test.ts` names renders what the guard looks for: the stubs of `SpotsPage`, `SpotDetailPage`, `SpotComparePage`, `ScoutPage` and `PlannerPage` also render `<PermissionNotice variant="line" />` | the package of 9.3 | `App.tsx` through the barrel |
| `components/truck/map/types.ts` | the final interfaces of 5.1 and the shapes they refer to (`CellPack`, `HexMesh`, the props of `TruckMap` and `MapPin`). `CellPack`, `HexMesh` and `Viewport` are declared next to `decodePack`, `buildMesh` and the viewport maths (they run in Node too) and re-exported from here | FE-1 | FE-2 |
| `components/truck/map/TruckMap.tsx`, `MapPin.tsx`, `useCellPack.ts`, `authFailure.ts` | a grey panel "Map engine not installed" that still calls `onClick` with the region centre when clicked (the centre of `initialCamera` without a region), answers its handle (`getCenter`, `hourStrip` with 24 zero bytes), mounts its children and reports the status `no-region` or `failed`; no Google map is constructed. `MapPin` renders nothing; `useCellPack` returns idle; `authFailure` does nothing | FE-1 | FE-2 |
| `components/truck/spot/SpotAnalysis.tsx` | props `{ subject: { kind: 'point'; lat: number; lng: number } \| { kind: 'spot'; spot: Spot }; termsOverride?: SpotTerms; layout: 'card' \| 'page'; onSaveAsSpot?: (hosts: HostHint[]) => void }`; renders "Spot analysis not built yet." and `<PermissionNotice variant="line" />` | FE-2 | FE-3 |
| `components/truck/spot/SpotForm.tsx` | props `{ mode: 'create' \| 'edit'; initial: Partial<SpotBody>; spotId?: string; nearbyHosts?: HostHint[]; presentation: 'modal' \| 'inline'; onSaved: (spot: Spot) => void; onCancel: () => void }` (`SpotBody` = the body of route 11, typed in `api/truck.ts`); renders "Spot form not built yet." and `<SourceLine kinds={['osm']} />` | FE-3 | FE-2 |
| `components/truck/mapui/ScoutDotsLayer.tsx` | props `{ enabled: boolean; onPick: (p: { lat: number; lng: number; placeKey: string }) => void }`; renders null | FE-7 | FE-2 |
| `components/truck/mapui/DateMode.tsx` | no props (reads and writes `truckHourStore.date`); renders null | FE-5 | FE-2 |
| `components/truck/planner/EventTermsForm.tsx`, `CateringTermsForm.tsx` | props `{ stop: DraftStop; onChange: (patch: Partial<DraftStop>) => void }`; renders "Events and catering are not built yet." | FE-7 | FE-4 |
| `components/truck/planner/SuggestDayPanel.tsx` | props `{ date: string; treatAs: DayContext['treat_as']; open: boolean; onClose: () => void; onUse: (s: Suggestion) => void }`; renders null | FE-7 | FE-4 |
| `components/truck/week/BestWeekPanel.tsx` | props `{ weekStart: string; plannedDates: string[]; onApplied: () => void }`; renders null | FE-7 | FE-5 |
| `components/truck/sheet/CalendarButton.tsx` | props `{ date: string; result: DayResult \| null; stops: { id: string; name: string; address: string; point: { lat: number; lng: number } }[]; disabled?: boolean }`; renders a disabled "Calendar file" button | FE-8 | FE-4, FE-8 |
| `components/truck/sheet/DaySheet.tsx` | props `{ date: string }`; renders "Day sheet not built yet." and `<PermissionNotice variant="line" />` | FE-8 | FE-8 (`DaySheetPage`) |
| `components/truck/settings/DataTab.tsx` | no props; renders "Not built yet." | FE-8 | FE-3 |
| `components/truck/print.css` | empty | FE-8 | the barrel |

### 9.3 Packages

| Package | Wave | Creates or edits | Depends on | Acceptance checks |
|---|:---:|---|---|---|
| **FE-0 Foundation** | 1 (first) | Edits: `App.tsx`, `components/layout/AppNav.tsx`, `components/common/CommandPalette.tsx`, `components/auth/ProtectedRoute.tsx`, `components/common/GooglePlaceAutocomplete.tsx` (1.5, 1.6); `frontend/package.json` and `frontend/package-lock.json` (add `h3-js` 4.5.0, exact); `frontend/vite.config.ts` (a development-only middleware, `apply: 'serve'`, that rewrites HTML `GET` requests outside `/app/`, `/api` and `/@...` with no file extension to `/app/index.html`, so `/truck/...` can be hard-loaded under `vite dev`; and the target of the `/api` dev proxy read from the environment variable `VITE_DEV_API_PROXY`, default `http://localhost:8080`). Creates: `api/truck.ts`; `stores/truckUiStore.ts`, `truckHourStore.ts`, `truckPlanDraftStore.ts`; `components/truck/TruckLayout.tsx`, `TruckPages.ts`, `TruckGate.tsx`, `SetupTruck.tsx`, `truck.css`; `components/truck/data/` (`TruckContext.tsx`, `useBootstrap.ts`, `useNow.ts`, `useSettledHow.ts`, `useSpots.ts`, `useSimulate.ts`, `useSpotEstimate.ts`, `useDayContexts.ts`, `useDriveTimes.ts`, `usePlans.ts`, `usePlanEvaluation.ts`, `useServices.ts`, `useScout.ts`, `useSuggestions.ts`, `useAccuracy.ts`, `useSources.ts`, `useMapsLoader.ts`, `mutations.ts`, `queryPolicy.ts`, `index.ts`; the four hooks for scout, suggestions, accuracy and sources are thin `useQuery` wrappers over the keys of 2.2, created here because later packages may not add files to this directory; screens import from the barrel `components/truck/data`); `components/truck/ui/` (every component of section 3, `kit.ts`, `overlay.ts`, `HintChip.tsx`, `useThemeName.ts` and `index.ts`); `utils/truck/model.ts`, `format.ts`, `time.ts`, `clock.ts`, `links.ts`, `wording.ts`, `warnings.ts`, `breakdown.ts`, `timelineView.ts`, `assemble.ts`, `palette.ts`, `logView.ts`; the tests of 8.2 marked FE-0 with their fixture helper `__tests__/_kitFixtures.ts`; the seven guards and `_closure.ts` (8.3); `frontend/scripts/check-truck-chunks.mjs`; every stub of 9.2 | the estimator port's `types.ts` and `index.ts` (type-level at first; a compiling port before the hooks are finished); backend P1 (all routes registered) and P3 (bootstrap, profile, assumptions) | every route of 1.1 renders its stub inside the layout; the eight tabs highlight correctly; the first-run step creates a truck through route 3; `/` redirects to `/truck`; `useTruck()` gives profile, assumptions, calibration, region and fuel on every page; a version mismatch shows the reload card; a 409 "Set up your truck first" brings the first-run step back; all FE-0 tests and all seven guards are green on the stub tree; the chunk check passes and `index-*.js` grew by no more than 8 kB gzip; rows 1 and 2 of 8.5 |
| **FE-1 Map engine** | 1 | `utils/truck/map/mercator.ts`, `pack.ts`, `mesh.ts`, `frames.ts`, `pick.ts`, `viewport.ts`; everything under `components/truck/map/` (`types.ts`, `TruckMap.tsx`, `MapPin.tsx`, `HexLayer.ts`, `useCellPack.ts`, `authFailure.ts`, `PerfHud.tsx`, `renderers/webgl2.ts`, `renderers/canvas2d.ts`, `hosts/googleOverlayHost.ts`, `hosts/blankBasemapHost.ts`); tests `map.*.test.ts` and `_packFixture.ts` | FE-0 (`palette.ts`, `model.ts`, the hour and UI stores, `api/truck.ts`); `fastPath.ts`; backend P2 (a loaded region and route 8) for manual checks | the `map.*` tests are green; on the blank host and on Google the layer stays glued to the base during drag, wheel zoom and animated zoom; an hour tick recolours within the budgets of 5.9 (shown by `PerfHud`); hover returns the right cell near cell edges; pins stay on their coordinates on both hosts and a pin click does not reach the map; context loss recovers; with WebGL disabled the 2D renderer draws; an invalid key ends on the blank host with the notice; nothing in the layer can throw into React (kill the pack request and corrupt a pack by hand); rows 5 and 6 of 8.5 |
| **FE-2 Map page and spot card** | 1 | `components/truck/pages/MapPage.tsx`; `components/truck/mapui/LayerSwitch.tsx`, `Legend.tsx`, `HourControl.tsx`, `HourStrip.tsx`, `HoverHint.tsx`, `MapTools.tsx`, `SpotPins.tsx`, `BasePin.tsx`, `PickBanner.tsx`, `MapStatus.tsx`; `components/truck/spot/SpotCard.tsx`, `SpotAnalysis.tsx`, `spot/sections/` (`ThisHour.tsx`, `BestWindows.tsx`, `WeekSection.tsx`, `WhoIsHere.tsx`, `Competition.tsx`, `MoneySection.tsx`); `utils/truck/hourControl.ts`; test `hourControl.test.ts` | FE-0; FE-1's interfaces (develops against the stub map until FE-1 lands); backend P4 (route 9) | everything in 4.2 and 4.3 at the three widths; URL params of 1.2 round-trip; the hover hint never prints a single figure; every figure in the card comes from `useSpotEstimate`; the anchors of 02_MODEL 8.3, fed as fixtures, show "60 orders (33 to 93)" and "39 orders (21 to 62)" with "Rough"; a click spends one `simulate` and no drive-time request; pick mode sets the base; Google's logo and terms are never covered; rows 3 and 4 of 8.5 |
| **FE-3 Spots and Settings** | 1 | `components/truck/pages/SpotsPage.tsx`, `SpotDetailPage.tsx`, `SpotComparePage.tsx`, `SettingsPage.tsx`; `components/truck/spot/SpotForm.tsx`, `spot/HostPicker.tsx`; `components/truck/spots/` (`SpotTable.tsx`, `SpotResults.tsx`, `CompareTable.tsx`); `components/truck/settings/TruckCostsTab.tsx`, `AssumptionsTab.tsx`, `CurveEditor.tsx`, `BasePicker.tsx`, `CountyChecklist.tsx`, `FuelPriceCard.tsx`, `StartingValuesModal.tsx`; `utils/truck/spotSummary.ts`, `profileForm.ts`; tests `spotSummary.test.ts`, `profileForm.test.ts` | FE-0; `SpotAnalysis` from FE-2 (stub until it lands); backend P3 and P4 (routes 3, 5, 6, 9 to 15) | everything in 4.4 and the first two tabs of 4.9; a spot edit that changes vectors waits for the server, every other edit is instant; the form's body matches 04_BACKEND 4.8 (a linked host sends `place_key`, never a `point_id`); ranges and defaults come from the seed metadata; invalid values are rejected with the range message and never clamped; only changed profile keys and override paths are sent; the host-size label follows the segment group; deleting a spot archives it and planned days keep working; rows 7 and 8 of 8.5 |
| **FE-4 Planner and drive times** | 2 | `components/truck/pages/PlannerPage.tsx`, `PlanIndexRedirect.tsx`; `components/truck/planner/PlannerHeader.tsx`, `StopList.tsx`, `StopCard.tsx`, `AddStopMenu.tsx`, `LegRow.tsx`, `LegEditor.tsx`, `AddsLine.tsx`, `DaySummary.tsx`, `MoneyTable.tsx`, `UnpaidGapCard.tsx`, `PlannerActions.tsx`, `BottomBar.tsx`; `utils/truck/planDraft.ts`; test `planDraft.test.ts` | FE-0 (`usePlanForDate`, `usePlanEvaluation`, `useDriveTimes`, `useDayContexts`, the draft store, `Timeline`, `WarningList`); wave 1 spots; backend P5 and P6 (routes 17, 18, 20 to 23, 25 to 27) | everything in 4.5 except the forms and panel owned by FE-7; the blueprint day reproduces its twelve clock times exactly; the worked day of 02_MODEL 4.12, fed as fixtures, shows take-home "$482 ($42 to $1,012)", the second stop adding "$135 (-$43 to $353)", break-even 26 orders and the unpaid alternative "$561 ($122 to $1,091)"; every leg shows its source label and, for a straight line, its reason; a correction and a toll persist and can be removed; saving twice on one date updates the same plan; nothing reorders stops except the owner; usable one-handed at 375 px; rows 9 and 10 of 8.5 |
| **FE-5 Week, dates and Today** | 2 | `components/truck/pages/TodayPage.tsx`, `WeekPage.tsx`, `WeekIndexRedirect.tsx`; `components/truck/today/NextAction.tsx`, `TodayPlanCard.tsx`, `ToLogCard.tsx`, `WeatherAtStops.tsx`, `FuelLine.tsx`; `components/truck/week/DayCard.tsx`, `WeekTotals.tsx`; `components/truck/mapui/DateMode.tsx`; `utils/truck/nextAction.ts`; test `nextAction.test.ts` | FE-0; wave 1; backend P5 and P6 (routes 17, 22, 25, 31) | everything in 4.1 and 4.6 except the best-week panel; the week total equals `estSum` of the day results; holiday and weather chips match the day contexts; "Pick a date" on the map uses the date's holiday pattern and no weather; Today has no map and works at 375 px; "today" follows the truck's time zone when the device is set to another zone or a wrong date; row 11 of 8.5 |
| **FE-6 Log and Accuracy** | 2 | `components/truck/pages/LogPage.tsx`; `components/truck/log/QuickEntry.tsx`, `ResultCard.tsx`, `PendingList.tsx`, `HistoryTable.tsx`, `AccuracyTab.tsx`, `AccuracyTiles.tsx`, `AccuracyChart.tsx`, `FactorsCard.tsx`; `utils/truck/logForm.ts`, `accuracyView.ts`; tests `logForm.test.ts`, `accuracyView.test.ts` | FE-0 (`logView.ts`, `useServices`, mutations); wave 1; backend P6 (routes 22, 25, 31, 32, 34, 35, 37) | everything in 4.7; saving a service writes the returned calibration into the context and the spot card's numbers and label follow without a reload; a stop logged from a plan sends its `plan_stop_id`; the sold-out switch is stored and worded as a minimum; the accuracy sentences match the report for the example of 02_MODEL 4.13 (seven services, six scored, one sold out); the form is the first thing on the page at 375 px; row 12 of 8.5 |
| **FE-7 Scout, suggestions, events and catering** | 3 | `components/truck/pages/ScoutPage.tsx`; `components/truck/scout/ScoutFilters.tsx`, `ScoutCard.tsx`, `LeadControls.tsx`, `ContactLookup.tsx`, `SaveLeadModal.tsx`; `components/truck/mapui/ScoutDotsLayer.tsx`; `components/truck/planner/EventTermsForm.tsx`, `CateringTermsForm.tsx`, `SuggestDayPanel.tsx`; `components/truck/week/BestWeekPanel.tsx`; `utils/truck/scoutView.ts`; test `scoutView.test.ts` | FE-0; FE-4 and FE-5 (the mounts of its seams); backend P7 and P8 (routes 29, 30, 38 to 41) | everything in 4.8, rules 5 and 6 of 4.5, the best-week panel of 4.6; the contact lookup fires only from its button, its results are labelled Google with their date and show the matched name and address; the score is never shown; suggestions never overwrite a planned day and create drafts only; event estimates read "Very rough", catering lines "Fixed"; rows 13 and 14 of 8.5 |
| **FE-8 Day sheet, calendar file, export and the data page** | 3 | `components/truck/pages/DaySheetPage.tsx`; `components/truck/sheet/DaySheet.tsx`, `CalendarButton.tsx`; `components/truck/print.css`; `components/truck/settings/DataTab.tsx`; `utils/truck/ics.ts`; test `ics.test.ts` | FE-0; FE-4 (a saved plan to print); backend P8 (routes 42 to 44) | everything in 4.10 and the third tab of 4.9; the sheet prints on one or two Letter pages with no navigation; the calendar file passes `ics.test.ts` and imports at the right local times; the strings shown are the server's; the copies in `wording.ts` equal 03_DATA 14 character for character; the export saves under the server's file name; delete needs the typed phrase and ends on the first-run step; rows 15 and 16 of 8.5 |

---

## 10. Conflicts, assumptions and open issues

| # | Issue | Handling here |
|---|---|---|
| 1 | `simulate.estimate`, `Plan.result` and the plan list's `summary` are server-computed numbers that DECISIONS 10 says the screens should compute themselves | They are used only as a development drift check (0.2); the one figure shown from them is the Log's estimate for a planned stop, which has to equal what the server stores (4.7) |
| 2 | The forecast is for the truck's base point only (04_BACKEND C11) | Chips say "Forecast for the area around your base" |
| 3 | The export is one JSON document; there are no CSV files | One download button. A browser-made CSV of the service log would be a small later addition |
| 4 | Scout returns at most 50 ranked places; type, county, kitchen and contact filters therefore narrow those 50 in the browser, and only the status filter (`hide`) changes the ranking | Stated on the page ("{n} places shown, {screened} looked at") |
| 5 | The hover hint shows a legend band, not a figure, because a figure would come from the quantised pack and would lack a range and label | The owner may prefer a number; it would have to be a full estimate from `simulate` |
| 6 | From memory, unverified: Google's waypoint limits for Maps URLs; the `.gm-err-container` class; whether Google asks for a visible credit when its drive times are shown away from a Google map (day sheet) | Marked **[M]**; the day sheet prints a drive-time credit line regardless |
| 7 | The map layer was measured in headless Chrome only, without a key | Row 5 of 8.5 repeats it in Safari and Firefox and with the production key before wave 1 closes |
| 8 | `vite dev` has `base: '/app/'` and would answer a hard load of `/truck/...` with its 404 help page | The development fallback of 9.3 is registered ahead of Vite's own middlewares (it must run before the base middleware, which is the one that answers 404). It only touches browser navigations (`GET` with `Accept: text/html`): module, asset and `/api` requests pass untouched. A build never loads it |
| 9 | Shared shell behaviour left unchanged: the cost widget shown to owners, deep links lost at login, the once-per-tab stale-chunk reload, the PWA scope `/app/` | Owner decisions; none blocks the build |
| 10 | Editing 24-hour curves for 16 segments is offered as a plain grid of fields | Good enough for an owner who knows better for one segment; a richer editor is a later layer |
| 11 | No browser test runner exists; 8.5 is a scripted manual pass | Adding one is a separate decision |
