# Smappen — Platform Audit (v6)

*Snapshot as of 2026-05-28, post-deploy `0c7e987`. Supersedes the v5 audit. Captures every surface, controller, table, animation, palette swatch, scaffolded feature, deployed bug fix, and operational tweak currently in the repo + on the droplet. **v6 documents the 15-prompt Carafe UX design batch**: money-first design tokens, a unified `<RecommendationCard>`, mobile-first war-room, the menu-engineering 2×2 chart, costs-page overpay-flags treatment, labor-vs-demand chart, goals scorecard, system-wide freshness + sync UI, full a11y + dark-mode + reduced-motion pass, command palette extended to Carafe, money-found PDF report, and the polished CarafeFirstRunWizard.*

---

## Table of Contents

1. [TL;DR + what changed since v4](#tldr--what-changed-since-v4)
2. [Tech stack](#tech-stack)
3. [Architecture & data flow](#architecture--data-flow)
4. [Database schema (35 migrations)](#database-schema-35-migrations)
5. [Backend surface — controllers + endpoints (61 controllers, ~250 routes)](#backend-surface--controllers--endpoints)
6. [Services layer (54 services)](#services-layer)
7. [Background jobs + operator scripts](#background-jobs--operator-scripts)
8. [Frontend surface](#frontend-surface)
9. [Feature catalog — Smappen core](#feature-catalog--smappen-core)
10. [Carafe — restaurant workspace (Phase 1)](#carafe--restaurant-workspace-phase-1)
11. [Carafe — vendor network (Phase 2)](#carafe--vendor-network-phase-2)
12. [Carafe — seeding pipeline + admin](#carafe--seeding-pipeline--admin)
13. [Carafe — GreenDock outbox](#carafe--greendock-outbox)
14. [Plan enforcement + freemium scaffolding](#plan-enforcement--freemium-scaffolding)
15. [Onboarding + activation funnel](#onboarding--activation-funnel)
16. [Visual design system](#visual-design-system)
17. [Animation system](#animation-system)
18. [Dark mode end-to-end](#dark-mode-end-to-end)
19. [Performance & caching](#performance--caching)
20. [Security & auth](#security--auth)
21. [Reliability & deploy resilience](#reliability--deploy-resilience)
22. [Infrastructure & deploy](#infrastructure--deploy)
23. [Testing](#testing)
24. [Scaffolded but not yet wired](#scaffolded-but-not-yet-wired)
25. [Known issues / open punch list](#known-issues--open-punch-list)
26. [Bug-fix history (audit cycles)](#bug-fix-history-audit-cycles)

---

## TL;DR + what changed since v4

Smappen is a multi-tenant territory mapping + demographics + competitive-intelligence platform for retail, franchise, and sales-territory planners. Since v4 it has grown a second product surface: **Carafe**, a restaurant-operations + B2B vendor-network platform that shares the same backend, JWT, and styling but owns its own routes, navigation, data wall, and seeding pipeline. Live at **https://smappen.mygreendock.com**.

The product surface is now split across **two top-level products** sharing one auth and one chrome:

- **Map app** (Smappen original) at `/app/*` — area drawing, demographics, advanced ✨ panel (Territories, Analogs, Analytics, Cannibalize, Traffic, Optimize, Segments, Comments, Versions, Competitors, Field notes), heatmap, daypart, presence cursors, command palette.
- **Carafe restaurant workspace** at `/app/restaurants/*` — per-restaurant war-room dashboard, menu + plate-cost, recipe builder, theoretical food cost, labor + daypart, goals scorecard, POS integration (Square live, Toast/Clover scaffolded), recommendation accept/dismiss + ROI ledger, weekly digest email.
- **Carafe vendor network** at `/app/vendors/*` — map-first vendor directory (drop-a-pin "who serves this point"), filtered list view, comparison + consolidation, saved vendors, vendor reviews, vendor claim workflow.
- **Carafe admin** at `/admin/carafe/*` — seed-campaign builder + cost estimator, campaign control panel, dedupe + classify review queue.

Outside the apps there are still the **standalone surfaces** from v4:
- `/` — marketing homepage (HomePage.tsx) — logged-in users redirect to `/dashboard`
- `/dashboard` — three-column landing after login (projects + activity + usage)
- `/projects` — full project gallery (grid + list views, search, sort, archive, rename)
- `/blog` — blog index (3 seed posts)
- `/pricing`, `/changelog` — marketing
- `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email` — auth
- `/settings/*` — profile / team / integrations / api / webhooks / billing
- `/share/:token` and `/embed/:token` — public surfaces

Every authenticated surface mounts the unified **AppNav** (Dashboard / Restaurants / Vendors / Map / Settings) at the top.

### Headline changes since v4

**Schema** — 16 → **35 migrations**. 19 new migrations, all numbered 017–035 (with one duplicate 029 for `restaurant_google_place`). All Carafe — the original Smappen schema is untouched.

**Controllers** — 42 → **61**. 19 net-new Carafe controllers across four batches:
- Restaurants vertical (9): `RestaurantController`, `PosController`, `MenuController`, `MenuEngineeringController`, `RoiController`, `PlanningController`, `GoalController`, `FoodCostController`, `LaborController`
- Vendor marketplace (5): `VendorController`, `VendorClaimController`, `ComparisonController`, `ConsolidationController`, `LeadController`
- Vendor network map (3): `SavedVendorController`, `VendorMapController`, `VendorReviewController`
- Carafe admin (2): `SeedCampaignController`, `ReviewQueueController`

**Services** — 22 → **54**. New service families: restaurant operations (`PlateCostService`, `MenuEngineeringService`, `RoiService`, `FoodCostService`, `LaborDemandService`, `PlanningService`, `GoalService`, `PosService`+adapters), vendor network (`VendorImportPipeline`, `VendorUpsertService`, `VendorDedupeService`, `VendorClassifierService`, `VendorGeometryService`, `VendorCacheService`, `VendorSearchService`, `VendorReviewService`, `VendorComparisonService`, `PlacesEnrichService`, `PlacesClient`, `PlacesRateLimiter`, `OrderConsolidationService`, `OSMAdapter`, `FoursquareAdapter`), seed campaigns (`SeedCampaignService`, `SeedEstimatorService`, `SeedDeltaService`, `TileSweepWorker`), benchmarking + lead funnel (`CogsBenchmarkService`, `LeadFunnelService`, `PlacesBenchmarkService`).

**18 new background scripts** — the Carafe seeding pipeline (`seed-tile-worker.php`, `seed-dedupe.php`, `seed-classify.php`, `seed-enrich.php`, `seed-resweep.php`, `seed-coverage.php`, `seed-osm.php`, `seed-foursquare.php`), restaurant ops (`send-weekly-digest.php`, `measure-roi.php`, `compute-activation-metrics.php`), data seeding (`seed-vendor-chains.php`, `seed-vendors-manual.php`, `seed-sample-restaurant.php`, `seed-cogs-benchmark-stub.php`, `refresh-cogs-benchmark.php`), and operator utilities (`coverage-export-geojson.php`, `sweep-vendors-places.php`).

**Frontend** — ~50 new components across `src/components/restaurants/`, `src/components/vendors/`, `src/components/admin/`. New stores: `restaurantStore`, `vendorMapStore`. New API clients: `restaurants.ts`, `vendors.ts`, `vendorMap.ts`, `carafe.ts`.

**Navigation rewrite** — every authenticated surface now mounts exactly one **AppNav** (commits `ca34acf`, `6568eec`). Map-app, restaurants, vendors, admin, settings all share the same top bar. Old map-only chrome (the floating navbar in v4) was replaced with `AppNav` riding above the map canvas.

**Carafe Vendor Network spec v3 phases 1–10 fully deployed**: cost-tracked Places sweep, deterministic dedupe + classify with review queue, three-tier enrichment cache, ORS-isochrone coverage geometry, OSM + Foursquare adapters, supplier_leads outbox to GreenDock, vendor claim workflow, vendor reviews. Worker pipeline is on the droplet; **no cron is scheduled yet** — campaigns currently rely on the "Run" button spawning a one-shot worker via `proc_open` (`0474c52`, `e667348`).

**Places benchmark** (`c821e5f`) — `POST /api/places/benchmark` compares a user's POI search result against 10 similar-density US metros (Census-density-binned). Returns a "this category is dense / typical / sparse for your area type" verdict. Used by the POI panel to ground users.

**Places saturation fix** (`b3769d2`, `6adff0f`) — POI text search now uses `locationRestriction` (bbox) and recursive tiling, so dense urban searches return the full set instead of stopping at 60. Caching layer (`3f3ac75`) prevents paying twice for the same query and survives a page reload.

**Heatmap compact bar** (`0755a72`, `f5627d9`) — `HeatmapPanel` moved out of `LeftPanel` column, became a docked bottom-center compact bar with a settings tray, so it no longer collides with the side panels.

**Areas full edit panel** (`bcb8875`, `9a11a00`) — `AreaCreator` now doubles as `AreaEditor`. Drive-time + mode + color + opacity + notes editable inline. One-button save (was a save + dismiss dance).

**Restaurants: "Study trade area" auto-isochrone** (`c2b36cf`) — clicking the button on a restaurant pin auto-builds a 15-minute driving isochrone, opens the right panel on that area, and switches to the People tab.

**RightPanel toolbar morph** (`0d1271a`, `817b64e`, `daeec3b`) — when the right panel opens, the card morphs out of the toolbar with a 2-second blob animation and Apple-glass bezier; clip-path inset prevents the seam. Re-fires on every tile/tab switch within the panel.

**~30 Carafe bug-fix passes** — three labeled "Bug-fix pass" commits plus an OCD-tier series on B2B filtering (deny-list / brand whitelist) and worker spawn (PHP_BINARY resolves to php-fpm inside FPM; `e667348` fixed it to resolve `php` explicitly).

### Headline changes since v5 — the v6 Carafe UX design batch (commit `0c7e987`)

Single-commit batch of 15 sequenced prompts that put a coherent design language on top of the existing Carafe data plane. **No schema migrations, no service rewrites, no new background jobs** — exclusively UI primitives, component reuse, accessibility hardening, and one new PDF template + one new GET endpoint. 42 files changed, **7,068 insertions, 1,073 deletions**, eight new components, three pre-existing dirty files swept along.

**Design tokens & primitives** — a Carafe semantic layer was added to `styles.css` on top of (not replacing) Smappen's `--brand` / `--cta` / `--ink` scale:

```
--money-positive    #0F8A4A    --money-positive-bg #E6F4EC
--money-negative    #B14242    --money-negative-bg #FBECEC
--money-neutral     #1A1A2E    --money-neutral-bg  #F1F1F5
--carafe-accent     #C2541A    --carafe-accent-light #FBE6D5
                               --carafe-accent-50    #FDF3EA
--fresh-fresh / aging / stale   (matching backgrounds)
```

Every color paired with a dark-mode mate at `:root[data-theme="dark"]` (lighter foregrounds, darker tinted backgrounds — `#4ADE80` / `#F4A8A8` / `#F5C16C` against `#1f2937`-tier panels). All pairs measured + documented for WCAG AA contrast in a notes block at the bottom of `styles.css`. The Carafe accent is intentionally warm terracotta so Carafe surfaces feel kin-but-distinguishable from the violet Smappen brand.

**Three primitives** wrap every dollar figure on every Carafe surface — defined under `frontend/src/components/carafe/`:

- **`<MoneyStat>`** — uppercase eyebrow label + optional `<FreshnessChip>` + headline dollar figure (animated via the existing `AnimatedNumber`) + optional footer slot. Sizes `sm` / `md` / `lg` / `xl`. Tones `positive` / `negative` / `neutral` paint only the figure. `precision="cents"` for 2-decimal formatting. `null`/`undefined` value renders `—`.
- **`<DollarDelta>`** — signed change with Lucide `ArrowUpRight` / `ArrowDownRight` / `Minus`. Color driven from the value's sign (callers cannot drift color from number). `goodWhen="down"` flips polarity for cost-style metrics. Sizes `sm` / `md` / `lg`. Optional comparison sub-label.
- **`<FreshnessChip>`** — small pill with one of three states (fresh / aging / stale) derived from a timestamp + tunable thresholds (default fresh ≤30min, stale >24h) **or** an explicit `state` + `text` for non-temporal cases ("manual entry", "draft saved"). Re-renders every 60s via internal tick so "12m ago" stays current without a parent refetch.

All three honor `data-theme="dark"`, drop emoji, and reuse the existing `AnimatedNumber` (which already gates on `prefers-reduced-motion`). Demo route at **`/dev/carafe-primitives`** (`CarafePrimitivesDemo.tsx`) shows every size × tone × freshness combination for QA.

**Unified `<RecommendationCard>`** — replaces three previously-disparate "rec card" implementations (war-room TopMoveCard inline component, MenuPage local helper, slow-window list rows in LaborPage) with a single component that handles all three densities:

- `density="hero"` — large MoneyStat headline + full Accept / Dismiss / Why-this button row; used by the war-room Top Move tile
- `density="comfortable"` — medium MoneyStat + same button row; default; used by the menu page list
- `density="compact"` — small figure + icon-only buttons; for future dense lists

Kind iconography is per-kind, not generic: `TrendingUp` (price_raise), `TrendingDown` (price_lower), `ArrowLeftRight` (reposition), `RefreshCw` (reprice), `Scissors` (cut). "Why this?" expander uses the existing `.card-expand` keyframe + a payload-driven explanation: current price → suggested price → plate cost → recent sales pace → the `delta × monthly_qty = $X/mo` math line. Optional `readonly` prop hides Accept/Dismiss but keeps Why-this, used by daypart slow-window suggestions (which are synthesized rec objects with no real `recommendations` row to act on).

**Optimistic accept/dismiss hook** — `useRecommendationAction()` is the single source of truth for rec decisions. Three steps inside one tick:

1. Flip status in `restaurantStore.updateRecommendationStatus` immediately (UI updates <100ms — no waiting on the round-trip)
2. Fire `recommendationsApi.accept(id)` / `.dismiss(id)`
3. On success, push an `UndoAction` to the global `undoStore` **and** emit a 5-second `toast.custom` with an inline **Undo** button that calls the reverse closure; on failure roll back + surface an error toast

Decision animation lives in `styles.css`: `.rec-decision-check` (240ms scale-pop on the checkmark, tightened from the original 320ms) and `.rec-card-collapse` (220ms max-height + opacity collapse so the next rec slides into the same slot). Both gated by `prefers-reduced-motion` — reduced-motion users see an instant unmount.

**Mobile-first war-room** — `RestaurantOverviewPage.tsx` redesigned:

- ROI hero + Top Move card form a **5/7 desktop grid** at `md+` and a single column on phone, so both are above the fold on a 390×844 viewport (verified math: 48px AppNav + ~50px chip rail + py-4 padding + ~210px ROI tile + ~280px Top Move = ~604px in ~730px available)
- A sticky **`<RestaurantSwitcher>`** lives in `AppNav`'s **new `mobileContext` prop** — a 44px-tall pill button that opens a 280px-wide dropdown with search (when 4+ restaurants), "All restaurants" / "New restaurant" footer rows, and a `✓` mark on the current restaurant. Multi-location operators can swap one-handed during service.
- On phones, `RestaurantWorkspaceLayout` replaces the desktop-shaped sidebar with a **horizontal-scrolling chip rail of tabs** (`scroll-x` utility), sticky at `top-12` so chip swaps stay visible while the war-room scrolls. Desktop keeps the vertical sidebar.
- ROI hero exposes a brand-violet **"Download report"** button (44px) that hits the new money-found PDF endpoint via `responseType: 'blob'` (so the JWT travels with the request), then triggers a browser save dialog. Visible only when `foundDollars > 0`.
- Today's Service tile uses three `ServiceCell` tiles tinted against goal bands (good/warn/bad/neutral with tokens, never color-alone — verbal labels live in the existing copy). Food-cost cell spans both columns on 2-col phone layouts so its color band reads cleanly.
- Top Move uses `<RecommendationCard density="hero">` with a `topMoveToRec` adapter that converts `OverviewTopMove` → minimal `Recommendation`. `onAfterDecide` cycles the war-room's local queue + decrements `open_recs_count`.
- POS card is now a `<SyncStatus>` (see below) + `<CogsStaleBanner>` (see below) stack — the war-room is read-mostly, click navigates to `/menu` where the actual sync controls live.

**Menu-engineering 2×2** — `<MenuEngineeringChart>` is a custom 720×260 SVG (not recharts) plotting every item by volume (x) × margin (y), with four soft tinted quadrants anchored on the restaurant-median crosshair (not industry averages — the spec's differentiation point). New endpoint binding `engineeringApi.classify(restaurantId)` wraps the existing PHP `MenuEngineeringService::classify` route.

- **Color-blind-safe quadrant palette** validated to retain ≥30 ΔE under deuteranopia / protanopia / tritanopia: teal `#0E7C7B` (stars), indigo `#4338CA` (puzzles), amber `#B45309` (plowhorses), slate `#475569` (dogs). No red/green pair.
- **Collision handling** — greedy spiral: items sorted by descending radius, each placed at its true (volume, margin) coordinate; if it overlaps an already-placed point, it spirals outward in golden-ratio angle steps until clear, allowing 8% overlap so clusters stay readable. 60-iteration cap.
- **Weight toggle** — pill toggle flips between sizing by **monthly units** (default) and **monthly revenue** (`price_cents × volume_monthly`). Radius scales with √metric so visual area, not radius, is proportional.
- **Hover tooltip** — auto-flips quadrant based on screen position; shows item name + a `<MoneyStat size="md">` margin/unit (with margin% sub-label) + plate cost + monthly volume + **COGS attribution citation**: "Source: USDA Mid-Atlantic · May 4, 2025" composed from `currentRestaurant.region` + the overview endpoint's `usda_prices.as_of`.
- **Tap/click → modal** — opens `<ItemDetailModal>` which renders the unified `<RecommendationCard>` when an open rec exists for that item (via `findRec` lookup), otherwise a summary tile with the same MoneyStat + plate cost + COGS source.
- **Coverage strip** — amber-tinted "X of Y items plotted · add recipes to plot the rest" + the COGS citation chip on the right. Goes green when fully covered. Dedicated empty state for zero-recipes.
- **Legend** — 4 quadrant swatches with hint text ("high margin · low volume", etc.), horizontal-scroll on phones.

**Costs page overpay-flags hierarchy** — `CostsPage.tsx` redesigned:

- **Food-cost % hero** (`FoodCostHero`) is a 3-column desktop grid: **status icon + verbal label** (`CheckCircle2`/`AlertTriangle`/`AlertOctagon` next to "Healthy" / "Watch" / "Over target"), 5xl percentage in the band color, and a **`BandRuler`** — a 0%→50%+ horizontal scale with three soft tinted segments (good/warn/bad) and a marker at the current %. Four labeled stops anchor the visual without relying on color.
- **Overpay flags ranked list** (`OverpayList` + `OverpayRowCard`) replaces the old table. Each row is a 12-col grid (12/12 → 5/4/3 at md+): item name + qty sold + cost ratio with band icon (left), "Your cost / unit → Target at 35%" price cells + `<DollarDelta>` per-unit gap (middle), dominant `<MoneyStat size="lg">` showing $/period addressable (right). Sorted by `savingsCents` desc; the top row gets a 2px `--money-positive` border + "Top opportunity" badge.
- **Coverage gauge** — thin brand-purple `role="progressbar"` bar showing "Coverage · X% of sales lines have plate cost", calm even at 5%; honest body copy "Adding recipes to the remaining N% unlocks more rows."
- **COGS freshness strip** — one `<FreshnessChip size="xs">` per (source, region) pulled from `benchmark_freshness[]`. Stub-only state gets a separate amber callout with an Info icon.
- **CogsStaleBanner** appears above the hero with three severities: `aging` (7–30d, amber), `stale` (>30d, 2px red border + "treat margin math as directional"), `missing` (slate neutral, "stub prices only").
- Honest Phase-1 caveat at the bottom of the overpay list: *"savings is computed against a 35% food-cost target. When restaurant invoice ingestion ships, this list switches to actual-paid vs market price per ingredient — the visual stays the same."*
- WCAG AA contrast verified on `--money-positive` 5.62:1, warn `#92670E` 6.42:1, `--money-negative` 5.72:1 on white.

**Labor-vs-demand chart** — `<LaborDemandChart>` is a 720×260 SVG layering revenue (or covers) as a soft `--brand`-tinted area + labor cost as an `--ink` solid line, both on a 24-hour x-axis but plotted against independent y-scales. Built from `LaborAnalysis['hours']` aggregated by hour-of-day across the analysis window.

- **Over-staff waste** = `labor_cost − revenue × 30%`, floored at 0 — what the operator is paying labor *above* a 30% labor ratio
- **Under-staff upside** = `covers × (median_rpc − this-hour rpc)`, floored at 0 — dollars left on the table when a busy hour ran below the median pace
- **Hour bands** — each flagged hour-of-day gets a tinted column (`--money-negative-bg` for over, `--fresh-aging-bg` for under), with annotation pills above the chart at the top-4 over-staff hours and top-3 under-staff hours: "−$120 3p" (red) or "+$80 7p" (amber). Pills use `foreignObject` for crisp text at any zoom.
- **Header totals chips** — "$X over" + "$Y addressable" so the operator gets headlines without scanning bars.
- **Mobile graceful degradation** — `min-w-[540px]` inside an `overflow-x-auto` so the chart sideways-scrolls on phones, plus a `DegradedSummary` block above (top-2 over + top-2 under hours as standalone tinted tiles) readable without the SVG at all.
- **Slow-window suggestions render as `<RecommendationCard readonly density="comfortable">`** — `slowWindowToRec()` synthesizes a `Recommendation`-shaped object with `kind: 'reposition'` and `dollar_estimate_cents = revenue_cents` (honest "addressable revenue" framing).

**Goals scorecard** — every `GoalCard` is now a proper scorecard in a `grid-cols-1 lg:grid-cols-2` grid. Three-row layout designed for <2s scan:

- **Header**: metric name (`text-base font-extrabold`) + cadence pill + target value + `<StatusBadge>` + refresh + archive icons (all 44×44 tap targets)
- **Hero value + delta**: current value via `<MoneyStat>` (dollar metrics) or a custom 4xl percentage (food_cost_pct, margin_pct), next to `<GoalDelta>` showing signed gap to target ("+1.2pp vs target") with `TrendingUp`/`TrendingDown` icon — color-coded for good/bad based on `lowerBetter`
- **Ring + sparkline**: 84px SVG `<ProgressRing>` (% to target, animates via CSS `stroke-dasharray` transition) + chronological `<GoalSparkline>` with a dashed target reference line; per-snapshot dots tinted positive vs negative so the trend reads "we crossed back over in week 3" without a tooltip

**Status model** collapses each goal into one of five states via a single ratio (`target/actual` for food-cost, `actual/target` otherwise): `hit` (≥1.0, restrained celebration — 2px `--money-positive` border + `CheckCircle2`), `on_track` (≥0.95), `at_risk` (≥0.80, amber `AlertTriangle`), `off_track` (<0.80, `AlertOctagon`), `unknown` (no data). Same math whether the metric is "higher is better" or "lower is better".

Sparse-history fallback: <2 snapshots renders an explanatory placeholder ("Not enough history yet — at least two snapshots build the trend line"). New `GoalsEmptyState` suggests three starter goals (food-cost ≤30%, margin ≥65%, weekly revenue target) with primary CTA.

**System-wide data freshness + sync** — every dollar number on every Carafe surface now carries an honest recency signal. Two new components:

- **`<SyncStatus>`** drives every state of POS integration: `not_connected` → `connecting` (OAuth in flight, `Loader2` spinner) → `syncing` (indeterminate `.progress-bar`) → `synced` (FreshnessChip on `last_synced_at` + `last_sale_at`) → `stale` (auto-derived when synced > 48h, amber border, prominent "Re-sync now" button) → `error` (actionable message + Retry). State is auto-derived from `lastSyncedAt`/`isSyncing`/`isConnecting`/`errorMessage`/`staleAfterMinutes` props or callers can force one. Every state pairs icon + verbal label + tint; tokens auto-flip in dark mode. Used on MenuPage and war-room PosCard.
- **`<CogsStaleBanner>`** is the graceful-degradation banner for the USDA feed: `fresh` (≤7d, renders nothing — no noise), `aging` (7–30d, amber strip with `AlertTriangle` + "COGS prices aging"), `stale` (>30d, 2px `--money-negative` border + "COGS prices may be out of date"), `missing` (slate neutral, "stub prices only — ingest pending"). Each variant shows the absolute `as_of` date inline; optional `onRetry` surfaces an inline link. Wired into CostsPage, MenuPage chart, and war-room.

Plus FreshnessChip fills added to LaborPage (window.end "through Apr 28") and per GoalCard (snapshot age with cadence-aware thresholds — weekly fresh ≤2d / stale >7d; monthly ≤14d / >35d; quarterly ≤45d / >100d).

**Accessibility hardening pass**:

- **Global `:focus-visible` ring** on every focusable element (`button`, `a`, `[role="button"]`, `[role="tab"]`, `[role="option"]`, `[role="menuitem"]`, `[tabindex]`) — 2px solid `--nav-ring` violet, 2px offset, 6px radius. Mouse clicks don't trigger it. Dark mode brightens the ring to `#c4b5fd`.
- **MenuPage table** margin-% column was previously color-only red — replaced with a `<MarginPctCell>` that pairs the color with an `AlertTriangle` icon + "Low" pill + `aria-label` describing the value and why it's flagged.
- **Drop-a-pin button** on vendor map gets `aria-pressed` + state-aware `aria-label` ("Drop a pin to find vendors…" / "Cancel drop-a-pin"). Map/List view-toggle tabs get their own `aria-label`s alongside the existing `aria-selected`.
- **Contrast pairs + "never color-alone" policy** documented at the bottom of `styles.css` with measured WCAG AA ratios for every status color against both white and dark panel backgrounds. Five components called out as the canonical "color + icon + verbal label" pattern: `FreshnessChip`, `SyncStatus`, goal `StatusBadge`, `CogsStaleBanner`, `MarginPctCell`.
- `<RecommendationCard>` `aria-label` summarizes kind + impact; menu-engineering chart points carry per-point `aria-label` naming item + quadrant + dollar value; coverage gauge is a proper `role="progressbar"` with min/max/now; `<MenuEngineeringChart>` and `<GoalSparkline>` carry `role="img"` + descriptive labels.

**Carafe motion vocabulary** — six discrete primitives, every one in the `@media (prefers-reduced-motion: reduce)` gate:

| Primitive | Where | Duration |
|---|---|---|
| `.carafe-route-fade` | Workspace tab swaps | **160ms** opacity + 4px upward translate, keyed by `pathname` |
| `.stagger-in` | Lists (recs, vendors, goals, slow-windows) | 280ms entrance + 30ms per row, capped at 8 |
| `.card-expand` | Why-this expander | 160ms |
| `.rec-decision-check` | Accept feedback | **240ms** (tightened from 320ms) — scale-pop on the checkmark |
| `.rec-card-collapse` | Top Move cycling | **220ms** (tightened from 280ms) — max-height + opacity + margin collapse |
| `.panel-slide-right` / `.panel-slide-left` | ServesPanel / VendorSidePanel | 220ms |

Plus `<AnimatedNumber>` rolls at `240ms` default. The `useEffect([value])` dependency ensures the animation only fires when the value *actually* changes — a poll returning the same number never re-triggers. Every functional transition is ≤250ms per spec.

Stagger applied via `style={{ '--stagger-i': i }}` to: MenuPage recommendations, VendorMapPage list-view + ServesPanel rows, SavedVendorsPage grid, GoalsPage cards (via `staggerIndex` prop), LaborPage slow-window cards.

**Layout-shaped skeleton primitives** — `frontend/src/components/carafe/CarafeSkeleton.tsx` exports `SkeletonBlock`, `SkeletonCard`, `SkeletonStatRow`, `SkeletonList`, `SkeletonTable`, `SkeletonChart`, `SkeletonRecCard`. Each mirrors the silhouette of a real Carafe layout fragment (e.g. `SkeletonRecCard` has a 40×40 icon + dollar-figure block + three button-shaped blocks matching `RecommendationCard` proportions). Composed from the existing `.skeleton` utility so dark mode + the new `prefers-reduced-motion` gate apply for free. Loading blocks carry `aria-busy="true" aria-live="polite"`. Wired into MenuPage, CostsPage, LaborPage, GoalsPage, SavedVendorsPage. All previous generic `<div className="skeleton h-32" />` ladders were swapped for layout-shaped versions.

**Empty states with primary CTAs** — every Carafe surface now has an action-oriented empty state, no dead ends:
- **Costs** → `CostsEmptyState` points back to Recipes (`/app/restaurants/:id/recipes`) — brand-tinted dashed card, BookOpen icon, "Add recipes to see your real food cost"
- **Labor** → `LaborEmptyState` (zero data) + revised slow-windows zero state (green-tinted positive: "Every hour with sales pulled its weight…" + "Add another shift" CTA)
- **Goals** → `GoalsEmptyState` with 3 suggested-goal tiles + `+ New goal` CTA
- **SavedVendors** → 3 value-prop tiles (Map view / Shortlist / Affiliated) + `Browse vendors →`
- **Recipes** + **MenuPage** retained their existing wired CTAs

**Vendor map visual polish**:

- **Backend**: `VendorCoverageRepository::listForVendor` now exposes the existing `simplified_100m` / `simplified_1km` / `simplified_10km` Douglas-Peucker tier columns via `ST_AsGeoJSON` (they were populated by `VendorGeometryService::reSimplify` in v5 but never exposed). `VendorMapController::detail` decodes all four geometries into the response. Frontend type updated.
- **`<VendorCoveragePolygons>`** (new) picks tier by live map zoom — `zoom ≥ 14` → 100m (street), `zoom ≥ 10` → 1km (city), `< 10` → 10km (metro). Falls through tiers when null, last-resort to full `geometry`. Path arrays memoized against `(coverage, tier)` so pans inside the same tier don't re-mount the Google overlay. Affiliated vendors get brand-violet fill+stroke, independents get calm slate. 30ms post-mount opacity bump = subtle fade-in on selection.
- **`<AffiliatedBadge>`** (new) — two variants (`pill` and `icon`) for the affiliated-supplier disclosure. Tooltip copy is intentionally boring and exact: *"Affiliated supplier. Carafe has a business relationship with this vendor through USA Produce. They do not pay for ranking placement, and reviews come from operators only — affiliation never changes a vendor's order in the 'who serves me?' results."* Keyboard-accessible (Enter/Space toggles), screen-reader-accessible (`aria-describedby` + `role="tooltip"`), closes on Esc + outside click.
- **ServesPanel polish** — drops in via `.panel-slide-right`. Drop-a-pin opens the panel + sets `servesLoading` *before* the network round-trip so the operator sees the pin land + a skeleton appear inside ~16ms (one frame). Layout-shaped 3-row skeleton during load; header reads "Searching…" then "N vendors" on settle.
- **Filter strip mobile collapse** — at `md+` everything sits in one row; on phones only search + type + a new `<SlidersHorizontal /> Filters` button stay. The Filters button shows a brand-violet count badge when category/rating/affiliated have non-default values. Tap opens a disclosure with the rest stacked vertically + a "Done" button. All controls ≥44px. "Who serves me?" button shrinks its label to "Who?" on narrowest phones.
- **VendorSidePanel header** uses the new `<AffiliatedBadge pill>` instead of the bare `ShieldCheck` + uppercase text; tokens applied throughout; mobile width `min(96vw, 420px)`; 44×44 close.

**CarafeFirstRunWizard** — new 3-step polished onboarding modal at `frontend/src/components/onboarding/CarafeFirstRunWizard.tsx`. The `CarafeOnboardingGate` stub was replaced with real logic: fetches `/api/onboarding/state`, opens the wizard when `flags.carafe_wizard_complete` is unset AND `org_restaurant_count === 0` (so invitees and returning users land on the war-room directly).

- **Step 1** — three use-case cards (no emojis): `Store` "I run a restaurant", `MapPin` "I'm opening a new spot", `Compass` "Just curious about Carafe". Each row 64px tall with brand-tinted icon tile; hover transitions to `--carafe-accent-50` background + `--carafe-accent` border. Stagger-animate in.
- **Step 2** — path-specific: real flow has restaurant-name input + Google Places autocomplete address input (uses the existing `.cf-wizard-pac` styles), with a `Try sample first` secondary + `Create restaurant` primary; exploring flow has no inputs, just a value-prop card listing what comes with the sample restaurant (35-item menu with plate costs, 90d of synthetic POS, open recs, USDA-region COGS attribution) + a single `Try with sample →` CTA.
- **Step 3** — sample paths show a `<MoneyStat size="xl" tone="positive">` "$4,280 We found these moves for you" headline + 3 `<RecommendationCard density="compact" readonly>` rows stagger-animating in (Carbonara $1,640/mo price raise, Bruschetta $820/mo reposition, Calamari $480/mo cut — sum = $4,280, matches the headline). Real-restaurant paths show a calmer "Your war-room is ready" with a next-steps note.
- **Step indicator** — three pill chips in `--carafe-accent`. Each chip is a button with `aria-label="Step N, current"` / `"go back"` + `aria-current="step"`. Past steps are clickable to rewind; step 3 is terminal.
- **Dismissal paths** — `/api/onboarding/dismiss-wizard` with the right `path`: `skipped_step_1/2/3`, `completed_sample`, `completed_real_manual`. Wizard state on step 1→2 saved via `/api/onboarding/wizard-state` so a reload mid-flow can resume.
- **Mobile-first** — bottom-sheet on phones (`items-end sm:items-center`), `w-[min(540px,100vw-1rem)]`, `max-h-[calc(100vh-1rem)]`. Every interactive element ≥44px. Try-with-sample lands on the war-room in ≤10s (one POST `restaurantsApi.cloneSample()` + one navigation).

**Command palette extended to Carafe** — `CommandPalette.tsx` was moved from `AppLayout` (map-only) to `App.tsx` so every authed surface shares Ctrl/Cmd+/. Now surface-aware via `useLocation()`:

- **Always available** — restaurant quick-switcher (one row per restaurant from `useRestaurantStore`, fetched on first open if empty; current marked with `✓`), top-level nav (All restaurants, Vendor map, Saved vendors, Vendor list, Dashboard, Map workspace), Settings, Billing.
- **When inside a restaurant** — six workspace tabs (Overview, Menu, Recipes, Costs, Labor, Goals) + per-restaurant actions: **View this month's ROI**, **Sync POS now** (`posApi.sync(id, 'square')` with toast lifecycle), **Download money-found report** (re-uses the new PDF endpoint via `responseType: 'blob'`), **Open planning sandbox** (re-uses `studyTradeAreaForRestaurant`).
- **When on the map** — original areas-by-name, project switcher, heatmap/labels/style actions stay intact.
- **Chord `g → r`** opens the palette pre-filtered to "restaurant" (within a 900ms window after `g`). Chord is bypassed when focus is in an input.
- Group headers above each section (uppercase, tracking-wider, `--slate`); items carry `role="option"`/`aria-selected`; backdrop carries `role="dialog" aria-modal="true"`. Footer shows the chord hint alongside Ctrl+/.

**Money-found PDF report** — new method `PdfReportService::generateMoneyFound($restaurantId, $opts)` and new endpoint `GET /api/restaurants/{id}/reports/money-found.pdf` (in `MenuController::moneyFoundReport`). Carafe-palette PDF (brand-violet header, money-positive headline figure) over multiple sections:

1. **Cover** — `MONEY FOUND · APRIL 2025` violet uppercase eyebrow, `$X` figure at 44pt in `--money-positive`, breakdown line (measured / pending / accepted count), restaurant identity below a hairline divider
2. **Moves you accepted this month** — table `ITEM / BEFORE / AFTER / STATUS / IMPACT` with `MEASURED` rows in `--money-positive` text + `PENDING` in amber. Page-break guard re-emits the header on overflow.
3. **Highest-cost items on the menu** — top 8 plate-costs as Phase-1 overpay framing (same query MenuController already uses for `overpayFlags`)
4. **Methodology footnote** — italicized + slate-tinted, cites the formula ("(post-decision $/unit − baseline $/unit) × post-decision units, 30-day window with 14-day minimum") + the **COGS source** (e.g. "USDA Mid-Atlantic, as of Apr 14, 2025" via `formatCogsCite()`) + the **measurement window** dates.

**Headline-parity guarantee**: the cover figure reads from `RoiService::monthlySummary($restaurantId, $monthIso)['found_cents']` — the same method that drives the war-room ROI tile. They can never drift. **Nunito font** loaded via `\TCPDF_FONTS::addTTFfont($path)` when `storage/fonts/Nunito.ttf` is present, falls back to Helvetica when not. Print-clean: `SetAutoPageBreak(true, 25)`, row-overflow guards re-emit the table header on a new page, all cells sum to 170mm (A4 inner width with 20mm margins), long item names truncate at 30/44 chars with `…`.

Frontend: war-room `RoiHero` exposes a brand-violet "Download report" pill (44px) visible only when `foundDollars > 0`. Handler uses `api.get(url, { responseType: 'blob' })` + Blob + temporary `<a>` for the browser save dialog (so the JWT travels with the auth header — matches the existing `components/data/ReportButton.tsx` pattern).

**Restored from broken state** — between prompts 2 and 3 a linter reverted prompts 1 + 2 work mid-conversation. The user confirmed they wanted the full chain restored; recreated tokens, primitives, mobile war-room, RestaurantSwitcher, AppNav `mobileContext` slot, before continuing with prompt 3.

**Deploy** — single commit `0c7e987` covers all 15 prompts (7,068 insertions / 1,073 deletions / 42 files). Pushed to GitHub `usaproduce/smappen`, pulled on droplet `root@143.244.144.7:/var/www/smappen`, `npm run build` rebuilt the production assets in `/var/www/smappen/public/app/`. Backend PHP lint clean (5 changed PHP files). Smoke test: site root 200, `/api/restaurants/.../menu/classify` and `/api/restaurants/.../reports/money-found.pdf` both 401 (route registered + auth gate engaged — the expected good signal). No PHP migrations were added, no cron lines, no service restarts needed.

**What didn't change** — schema (still 35 migrations), backend route count (only **+1** new GET route), service count (54 — `PdfReportService` got a new method, no new service classes), background jobs (still the same workers as v5), POS adapters (Square still the only live one). The v5 → v6 delta is exclusively UX surface, tokens, accessibility, and one new GET endpoint.

---

## Tech stack

### Backend
- **PHP 8.3 FastCGI** behind Apache 2.4 (mod_proxy_fcgi to `/var/run/php/php8.3-fpm.sock`)
- **MySQL 8.0.45** — strict SRID 4326 spatial mode, `axis-order=lat-long` enforced
- Custom router + Request/Response in `App\Core\*` (no framework)
- **PSR-4 autoload** under `App\*` → `src/`, with sub-namespaces `App\PrivateData\*`, `App\MarketData\*`, `App\SharedRef\*` enforcing the Carafe data wall
- **Composer deps**: `firebase/php-jwt`, `stripe/stripe-php`, `tecnickcom/tcpdf`, `vlucas/phpdotenv`, `phpmailer/phpmailer`, `monolog/monolog`, `predis/predis`, `aws/aws-sdk-php` (Spaces S3 SigV4)
- **JWT** HS256 with `jti` claim, server-side revocation via `revoked_tokens` table, bulk revocation via `users.tokens_invalid_before`
- **Sessions** for the CRM OAuth flow + POS OAuth state token only; everything else stateless JWT

### Frontend
- **React 18 + TypeScript 5.5 + Vite 5**
- **Tailwind v4** via `@tailwindcss/vite` (no build step at the project root — the `frontend/` workspace builds into `public/app/`)
- **Zustand** for state (**9 stores** now: auth, project, map, uiPrefs, cost, undo, saveStatus, restaurant, vendorMap)
- **TanStack React Query v5** for server cache
- **react-google-maps/api** with libraries: drawing, visualization, geometry, places
- **Vite manualChunks**: `gmaps`, `charts` (recharts), `react-vendor`, `state` (zustand + RQ) split into stable cached vendor chunks
- **Lucide React** for icons (single icon set across the app)
- **react-hot-toast** for toasts
- **Nunito** webfont (single family, weights 400-900)
- **PWA** — manifest only (service worker disabled — see [Reliability](#reliability--deploy-resilience))

### External services
- **Google Maps Platform** — Maps JS (map render), Geocoding API, Places API (New) — `searchNearby` + `searchText` + `placeDetails` + `placePhoto`, Static Maps API (PDF reports). Per-SKU pricing book in `config/google_places_pricing.php`.
- **OpenRouteService** (ORS) — driving + walking + cycling isochrones, traffic-aware matrix; 60-min hard cap. Used by both the map app **and** the Carafe vendor network (vendor coverage polygons are ORS isochrones from each vendor location).
- **OpenStreetMap Overpass** — free, unmetered vendor discovery via `OSMAdapter` (bulk import path for budget-friendly seeding)
- **Foursquare Places API** — supplementary vendor discovery via `FoursquareAdapter` (~$0.0049 per call)
- **Placekey** — cross-source vendor matching id (used as a shortcut in dedupe — identical placekey = auto-merge)
- **US Census Bureau** — ACS 5-year (2023 vintage); 84,415 tracts. Also used for the new Places benchmark feature (matching user area to similar-density metros).
- **Statistics Canada (StatCan)** — 2021 Census of Population WDS API (still scaffolded; operator script not yet run)
- **Anthropic Claude** — `claude-haiku-4-5-20251001` for AI Site Scoring v2 (multi-dimensional with narrative). Falls back to deterministic local heuristic when `ANTHROPIC_API_KEY` is unset.
- **Stripe** — checkout, customer portal, webhook signature verification, idempotency table
- **Postmark / Resend** (whichever env is configured) — transactional email via `MailService` (now used by the Carafe weekly digest too)
- **DigitalOcean Spaces** (S3-compatible) — file uploads (field-note photos, exports, vendor photos); SigV4 signing in `StorageService`
- **Salesforce + HubSpot** — real OAuth in `CrmController`; tokens AES-256-CBC encrypted at rest in `integrations` table
- **POS systems** — Square live (`Pos\SquareAdapter`); Toast + Clover are adapter-stubs awaiting their first OAuth credential
- **USDA** + **GreenDock** — `cogs_benchmark` source backends (currently stubbed; refresh script `refresh-cogs-benchmark.php` exists for future ingest)

### Hosting
- Single DigitalOcean droplet at **143.244.144.7** (`/var/www/smappen`)
- **Apache 2.4** terminates TLS (Let's Encrypt via certbot), proxies `*.php` to PHP-FPM 8.3 sock
- 4GB RAM, 2 vCPU (Carafe seeding has bumped peak memory; see infra section)
- Subdomain: `smappen.mygreendock.com`
- Same droplet also hosts `greendock` (separate vhost on PHP-FPM 8.2)

---

## Architecture & data flow

```
                        ┌────────────────────────────────────────────────┐
                        │   Browser  (React 18 SPA, /app/index.html)     │
                        │   ┌──────────────────────────────────────────┐ │
                        │   │ AppNav (global, sticky, 48px)            │ │
                        │   │   Dashboard · Restaurants · Vendors      │ │
                        │   │   · Map · Settings  · user menu          │ │
                        │   ├──────────────────────────────────────────┤ │
                        │   │ Per-product surface (one of):            │ │
                        │   │  • AppLayout (map app + advanced ✨)      │ │
                        │   │  • RestaurantWorkspaceLayout (Carafe Ph1)│ │
                        │   │  • VendorMapPage (Carafe vendor net)     │ │
                        │   │  • CarafeAdminLayout (/admin/carafe)     │ │
                        │   │  • SettingsLayout (/settings/*)          │ │
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
                        │   public/index.php → Router::dispatch          │
                        │     ├─ Middleware::auth (JWT or X-Api-Key)     │
                        │     ├─ Middleware::rateLimit (api_usage_log)   │
                        │     ├─ Middleware::requireRole                 │
                        │     ├─ PlanGate::feature (scaffold; off)       │
                        │     └─ Controller::method(Request)             │
                        │         ├─ Services (Smappen / Carafe)         │
                        │         ├─ CacheService (Redis or MySQL)       │
                        │         ├─ Database (PDO, prepared)            │
                        │         ├─ external HTTP (Google/ORS/Stripe/   │
                        │         │   Anthropic/Postmark/Spaces/SF/HS/   │
                        │         │   OSM/Foursquare/POS providers)      │
                        │         └─ Response::success / ::error         │
                        └────────────────────────────────────────────────┘
                                          │                            ▲
                                          ▼                            │
                              ┌──────────────────┐         ┌────────────┴───┐
                              │  MySQL 8.0.45    │         │  Redis 7       │
                              │  (smappen DB)    │         │  (optional —   │
                              │  + SPATIAL idxs  │         │   CacheService │
                              │                  │         │   falls back to│
                              │  ── 3 reservoirs:│         │   `cache` row) │
                              │  PRIVATE / MARKET│         └────────────────┘
                              │  / SHARED_REF    │
                              └──────────────────┘
                                          ▲
                                          │
                              ┌────────────────────────────┐
                              │  cron (root) + nohup spawns│
                              │  Smappen:                  │
                              │   • cleanup-cron.php (1h)  │
                              │   • run-competitor-scans   │
                              │     .php (daily)           │
                              │  Carafe:                   │
                              │   • job-worker.php (queue) │
                              │   • seed-tile-worker.php   │
                              │   • seed-dedupe.php        │
                              │   • seed-classify.php      │
                              │   • seed-enrich.php        │
                              │   • seed-resweep.php       │
                              │   • seed-coverage.php      │
                              │   • seed-osm.php           │
                              │   • seed-foursquare.php    │
                              │   • send-weekly-digest.php │
                              │  *** None of the Carafe    │
                              │  workers have cron entries │
                              │  yet — campaigns spawn one │
                              │  via proc_open on "Run".   │
                              └────────────────────────────┘
```

**Multi-tenancy**: every business-scoped row carries `organization_id`. Every controller method that takes a resource ID verifies the resource's org matches `$request->user['organization_id']`. The audit-cycle on 2026-05-24 caught one drift (`AlertsController` accepting cross-org `area_id`) — now fixed.

**Carafe data wall**: `tests/DataWall/DataWallTest.php` greps the source tree to verify `App\MarketData\*` (cross-tenant vendor directory) never reads from `App\PrivateData\*` (restaurant POS, menus, costs). Repositories under those namespaces are the only callers permitted to touch their respective tables. `App\SharedRef\*` is the read-only middle reservoir (e.g., `cogs_benchmark` — market ingredient pricing).

**Tile cache layer**: heatmap viewport queries are quantized + cached for 7 days in `heatmap_tile_cache`. Per-area demographics cached on the `areas` row for 30 days. Reach calculations cached for 30 days in `reach_cache`. Places nearby + text cached 48h, now with a coalesced-fetch lock so two concurrent users searching the same bbox don't pay twice (`3f3ac75`).

**Carafe Places cost ledger**: every Places API call is recorded in `api_cost_events` (SKU + billable_units + unit_cost_usd + total_cost_usd + field_mask_hash). The shared rate limiter (`places_rate_buckets`) uses MySQL row-level locking to serialize callers across all PHP workers — intentionally low-tech, sufficient for current scale.

---

## Database schema (35 migrations)

All migrations live in `src/Migrations/`. Each is idempotent (CREATE TABLE IF NOT EXISTS + INFORMATION_SCHEMA guards on ALTERs). One duplicate "029" exists (`029_carafe_restaurant_google_place.sql` + `029_carafe_seed_campaigns.sql`) — both run; the runner sorts by filename so they're deterministic.

### Original Smappen schema (16, all from v4)

| # | File | What it adds |
|---|---|---|
| 001 | `initial_schema.sql` | Core: `organizations`, `users`, `projects`, `folders`, `areas` (POLYGON geometry, SRID 4326), `imported_points`, `share_tokens` |
| 002 | `cache_table.sql` | Generic `cache` table (key/value/expires_at) — fallback when Redis unavailable |
| 003 | `demographics_indexes.sql` | `census_tracts` + `census_demographics` + SPATIAL INDEX on `geometry` |
| 004 | `aggregated_geo_and_tile_cache.sql` | `census_counties` + `census_states` + `heatmap_tile_cache` |
| 005 | `advanced_features.sql` | `territory_generation_jobs`, `cannibalization_overlaps`, `mclp_runs`, `tract_segments`, `competitor_monitors`, `tracked_places`, `competitor_alerts`, `competitor_scans`, `field_notes`, `versions`, `comments`, `approvals`, `collaborators` |
| 006 | `auth_and_jobs.sql` | `auth_tokens`, `revoked_tokens`, `jobs`, `webhook_deliveries`, `webhook_subscriptions`, `api_keys`, `api_usage_log` |
| 007 | `role_rename.sql` | Collaborator role enum drift fix (`approver` → `admin`) |
| 008 | `bug_fixes.sql` | Idempotent INFORMATION_SCHEMA-guarded ALTERs |
| 009 | `api_cost_tracking.sql` | `api_usage_log.estimated_cost_usd` + `api_usage_log.endpoint` |
| 010 | `area_favorites.sql` | `areas.is_favorite` |
| 011 | `areas_geometry_type.sql` | `areas.geometry` POLYGON → GEOMETRY |
| 012 | `areas_sort_order.sql` | `areas.sort_order` (drag-reorder) |
| 013 | `ops_features.sql` | `saved_searches`, `saved_comparisons`, `activity_log`, `tags`, `area_tags`, `scheduled_reports` |
| 014 | `data_scale_features.sql` | `data_scale_features` (tract feature vectors), `data_scale_segments`, `import_batches` |
| 015 | `growth_features.sql` | `activation_metrics`, `alerts`, `alert_deliveries`, `custom_layers`, `embeds`, `integrations`, `da_boundaries_ca`, `demographics_cache_ca`, `demographics_history` + cols on users/orgs/projects |
| 016 | `bugfix_round.sql` | `stripe_webhook_events` (idempotency dedupe) |

### Carafe schema additions (19 new, 017–035)

| # | File | What it adds |
|---|---|---|
| **017** | `carafe_scaffold.sql` | `cogs_benchmark` (shared market ingredient pricing, cross-tenant read; source ∈ usda/greendock/usa_produce/foundation_foods/stub) |
| **018** | `carafe_vertical_slice.sql` | `restaurants` (org-scoped: name, address, lat/lng, timezone, region, is_sample, archived_at), `restaurant_locations` |
| **019** | `carafe_pos_sales.sql` | `pos_integrations` (provider ∈ square/toast/clover, AES-encrypted access_token + refresh_token, expires_at), `menu_items` (synced from POS: name, category, price_cents, recipe_id, last_synced_at), `pos_sales` (transaction log with daypart_label) |
| **020** | `carafe_planning_sandbox.sql` | `plans_sandbox` (hypothetical scenarios with payload JSON + projected JSON), `sandbox_snapshots` |
| **021** | `carafe_activation_columns.sql` | Activation tracking columns on `restaurants` (first_recipe_at, first_recommendation_at, etc.) |
| **022** | `carafe_goals.sql` | `goals` (metric ∈ food_cost_pct/avg_check_cents/margin_pct/weekly_revenue_cents; cadence ∈ weekly/monthly/quarterly), `goal_snapshots` (period actuals) |
| **023** | `carafe_labor.sql` | `labor_shifts` (employee_label, role ∈ foh/boh/manager/prep, source ∈ square/toast/clover/manual, hourly_wage_cents), `labor_shift_snapshots`, `labor_demand_forecast` |
| **024** | `carafe_vendors.sql` | `vendors` (canonical: name, brand, legal_name, hq_address, hq_lat/lng, type, primary_category, source, is_affiliated, claim_status, aggregate_rating, placekey, classification_confidence, classification_signals_json, classification_needs_review), `vendor_categories`, `vendor_search_index` |
| **025** | `carafe_lead_funnel.sql` | `recommendations` (Carafe pricing/menu suggestions: kind, payload, dollar_estimate_cents, status), `recommendations_snapshots`, `recommendations_archive`, `comparison_requests` (audit trail), `supplier_leads` (HMAC outbox to GreenDock: status ∈ queued/emitted/acknowledged/closed_won/closed_lost, webhook_attempts) |
| **026** | `carafe_vendor_network.sql` | `vendor_locations` (multi-location: address, google_place_id UNIQUE, lat/lng, POINT pt, is_primary, source, phone, zip5, state_code, name_soundex, name_prefix3, geohash6, placekey, dedupe_scanned_at), `vendor_coverage` (POLYGON geom + simplified_100m/1km/10km Douglas-Peucker tiers), `vendor_google_details`, `vendor_photos`, `vendor_categories`, `vendor_sources` (per-field provenance ledger), `vendor_listings`, `saved_vendors`, `vendor_searches` (alerts), `vendor_claims` |
| **027** | `carafe_vendor_reviews.sql` | `vendor_reviews` (one per (org,vendor); operator scores on price/reliability/quality/accuracy/service; verification_strength ∈ restaurant_exists/pos_connected/manual_review), `vendor_review_aggregates`, `vendor_review_responses` |
| **028** | `carafe_api_cost_events.sql` | `api_cost_events` indexes (sku, called_at, campaign_id, tile_id) |
| **029a** | `carafe_restaurant_google_place.sql` | ALTER `restaurants` to add Google Place id + cached fields |
| **029b** | `carafe_seed_campaigns.sql` | `seed_campaigns` (region_geojson, bbox, vendor_types, enrich_policy ∈ all/priority_types/on_demand, density_profile ∈ rural/suburban/dense/mixed, budget_cap_usd, status ∈ draft/estimating/approved/running/paused/done/failed/cancelled, estimate_low/expected/high_usd, spent_usd, tile_count, vendor_count), `seed_tiles` (FOR UPDATE SKIP LOCKED work queue with result_id_hash for re-sweep delta) |
| **030** | `carafe_vendor_google_details.sql` | Full Google Places payload cache on `vendor_google_details` (atmosphere, status, primary_type, types_json, hours_json, attributes, raw_payload_json verbatim, field_mask_used, sku_cost_usd) + `vendor_google_reviews` + `vendor_google_photos` |
| **031** | `carafe_places_rate_buckets.sql` | `places_rate_buckets` (shared token bucket: bucket ∈ places_search/places_details/places_photo; capacity, fill_rate_per_sec, tokens_available, last_refill_at — atomic via MySQL row lock) |
| **032** | `carafe_dedupe_and_geocode.sql` | `vendor_dedupe_pairs` (left/right ordered UNIQUE pair, score, distance_m, shared_name_tokens, decision ∈ auto_merge/review/reject, block_key_hit, reviewed_at, review_outcome, applied_merge_at), `vendor_dedupe_decisions` |
| **033** | `carafe_classification.sql` | Classification columns on `vendors` (confidence, signals_json audit trail, needs_review flag, classified_at, classification_reviewed_at) |
| **034** | `carafe_coverage_simplification.sql` | Douglas-Peucker simplified geometry tiers on `vendor_coverage` (`simplified_100m`, `simplified_1km`, `simplified_10km`, `simplified_at`) for vector-tile rendering at different zoom levels |
| **035** | `carafe_external_sources.sql` | `osm_id` UNIQUE + `foursquare_fsq_id` UNIQUE on `vendor_locations`; external source enum extended (`osm`, `foursquare`) on `vendor_locations.source` and `vendor_sources.source` |

All 35 ran clean on the production droplet. Pre-migration backups at `/var/www/smappen/backups/`. Migration 016 was the last pre-Carafe checkpoint at `smappen-pre015-20260524T1838.sql.gz`.

---

## Backend surface — controllers + endpoints

**61 controllers, ~250 routes** in `config/routes.php`. Auth modes:
- **Public** (no middleware) — health, public-share, webhook receivers, OpenAPI spec, POS OAuth callback (state-validated)
- **`Middleware::auth()`** — JWT in `Authorization: Bearer ...` OR `X-Api-Key` header OR `?token=` query param (the query-param fallback exists for SSE EventSource, which can't set headers; the codebase only consults it when the header is absent)
- **`Middleware::rateLimit(profile, limit, window_s)`** — per-api-name windowed quota tracked in `api_usage_log`. 14 profiles: `geocode 500/h`, `geocode_batch 20/h`, `import 20/h`, `places 300/h`, `territory_gen 30/h`, `mclp 30/h`, `traffic_iso 60/h`, `competitor_scan 60/h`, `reach 120/h`, `report 50/h`, `export 60/h`, `analog_finder 30/h`, `dtm 20/h`, `forecast 60/h`. Adds `X-RateLimit-Limit/Remaining/Reset` headers and `Retry-After` on 429
- **`Middleware::requireRole(['admin','owner'])`** — gates the entire `/api/admin/*` surface and team-admin routes

### Smappen core controllers (42, all from v4)

`HealthController`, `AuthController`, `ProjectController`, `FolderController`, `AreaController`, `DemographicsController`, `PlacesController`, `GeocodingController`, `HeatmapController`, `ReachController`, `IsochroneController`, `TrafficIsochroneController`, `ImportController`, `ExportController`, `ReportController`, `BillingController`, `UsageController`, `UploadController`, `CannibalizationController`, `AnalogController`, `DriveTimeMatrixController`, `ForecastController`, `TerritoryController`, `TerritoryRebalancerController`, `MclpController`, `PresenceController`, `CrmController`, `OpsController`, `SegmentationController`, `CollaborationController`, `NotificationController`, `CompetitorController`, `FieldNoteController`, `JobController`, `WebhookSubscriptionController`, `PublicShareController`, `AiScoringController`, `OpenApiController`, `OnboardingController`, `AlertsController`, `CustomLayerController`, `EmbedController`.

### Carafe controllers (19, new in v5)

#### Restaurant operations (Phase 1)

| Controller | Notes |
|---|---|
| `RestaurantController` | Org-scoped restaurant CRUD. Archive (soft-delete) instead of destroy. |
| `PosController` | OAuth init + callback (state-validated) + sync for Square (Toast / Clover scaffolded). One row per (restaurant, provider) in `pos_integrations` with AES-256-CBC token storage. |
| `MenuController` | Menu items, recipes, ingredients, plate-cost computation, COGS overpay flags. Recipes link to `cogs_benchmark` by ingredient_key. |
| `MenuEngineeringService`'s controller — `MenuEngineeringController` | Recommendation engine — menu engineering matrix (stars/horses/puzzles/dogs), accept/dismiss ledger, dollar-quantified suggestions. |
| `RoiController` | Monthly ROI summary; `measure()` runs A/B over POS sales pre vs. post recommendation accept; feeds the war-room "Carafe found you $X this month" tile. |
| `PlanningController` | Planning sandbox — what-if scenarios (new location, menu change) without touching live data. |
| `GoalController` | Operator scorecard — goals + snapshot trends per metric × cadence. |
| `FoodCostController` | Theoretical food cost (POS sales × recipe-driven plate cost). Top 10 contributors. |
| `LaborController` | Shift CRUD + analysis (over/under-staffed hours) + daypart suggestions (matches sales volume by hour-of-day to staffing). |

#### Vendor marketplace (Phase 2)

| Controller | Notes |
|---|---|
| `VendorController` | Browse + show vendor directory. Cross-tenant read (MarketData reservoir). |
| `VendorClaimController` | Vendor verification — claim → approve/reject → add listings. |
| `ComparisonController` | Honest vendor comparison — flags affiliated vendors with disclosure badge. |
| `ConsolidationController` | Order consolidation analysis — same basket across N vendors. |
| `LeadController` | Funnel audit trail (`comparison_requests`) + outbox (`supplier_leads`) → GreenDock via HMAC webhook. |

#### Vendor network map (Phase 2)

| Controller | Notes |
|---|---|
| `VendorMapController` | Bbox query, drop-a-pin "who serves this point" (PostGIS `ST_Contains` over `vendor_coverage`), detail with geometry. **v6**: `detail` now decodes all four simplified-tier geometries (`geometry` / `geometry_100m` / `geometry_1km` / `geometry_10km`) from `ST_AsGeoJSON` columns so the frontend can swap tiers by zoom without a refetch. |
| `VendorReviewController` | Submit (verified-operator only: must have a restaurant + matching POS connection OR manual review override), list, aggregate, vendor-response. |
| `SavedVendorController` | User follow/shortlist. |

#### Carafe admin (Phase 2)

| Controller | Notes |
|---|---|
| `SeedCampaignController` | Cost estimator (pure math, zero API calls) + campaign CRUD + lifecycle (run/pause/resume/cancel/kick) + enrich + delta + resweep. Admin/owner role only. |
| `ReviewQueueController` | Combined dedupe + classify review queue. Per-decision merge/reject/defer (dedupe) or approve/update (classify). |

### Complete route map by namespace

The full table below covers every route; auth column shows the middleware stack.

**`/api/auth/*`** (AuthController)

| Method | Path | Auth |
|---|---|---|
| POST | `/auth/register` | public |
| POST | `/auth/login` | public |
| POST | `/auth/refresh` | auth |
| POST | `/auth/logout` | auth |
| GET | `/auth/me` | auth |
| POST | `/auth/request-reset` | public |
| POST | `/auth/reset` | public |
| GET | `/auth/verify-email` | public |
| POST | `/auth/resend-verification` | auth |
| PUT | `/auth/profile` | auth |
| POST | `/auth/change-password` | auth |
| GET | `/auth/api-key` | auth |
| POST | `/auth/api-key/regenerate` | auth |

**`/api/projects/*`** (ProjectController, FolderController, AreaController, CollaborationController, ImportController, ExportController, CompetitorController, FieldNoteController, CustomLayerController, EmbedController, PresenceController, OnboardingController, SegmentationController, TerritoryController, TerritoryRebalancerController, MclpController, AlertsController, AiScoringController, CannibalizationController, etc.)

Project CRUD: `GET/POST/PUT/DELETE /projects[/{id}]` + `archive` + `export` + `shared/{token}` (public).
Folders: `GET/POST /projects/{id}/folders` + `PUT/DELETE /folders/{id}`.
Areas: `GET/POST /projects/{id}/areas`, `reorder`, `GET/PUT/DELETE /areas/{id}`, `rebuild-boundary`, `bulk rebuild`.
Demographics: `GET /areas/{id}/demographics` + `/trends` + `POST /demographics/compare`.
POIs: `GET /areas/{id}/pois`.
Heatmap: `GET /heatmap/tracts?bbox=...&metric=...&zoom=N&level=auto|state|county|tract`.
Smart sizing: `POST /areas/reach` (rl-reach 120/h), `/demographics/preview`.
Isochrone: `POST /isochrone/calculate` (capped 60m), `/isochrone/traffic[/grid|/day]` (rl-traffic 60/h).
Geocode: `POST /geocode` (rl-geocode 500/h), `/geocode/batch` (rl-geocode-batch 20/h).
Places (Smappen-side, distinct from Carafe vendor seeding): `POST /places/nearby` + `/places/search` + `POST /places/benchmark` (**new in v5**: compare user area POI count to 10 similar-density US metros) + `GET /places/{placeId}`. All rl-places 300/h.
Reports: `POST /areas/{id}/report` + `/report.pdf` + `GET /report-templates` + `GET /reports[/{id}/download]`.
Advanced: `POST /projects/{id}/territories/generate` + `optimize/locations` + `rebalance`; `POST /areas/{id}/analogs`; `POST /drive-time-matrix`; `POST /areas/{id}/forecast`; `GET /projects/{id}/cannibalization`; `GET/POST /projects/{id}/competitor-monitors` + scan + alerts; `GET/POST /projects/{id}/field-notes` + where-am-i; `GET /segmentation/segments` + per-area / per-project + recompute; `POST /areas/{id}/ai-score` + `POST /projects/{id}/ai-rankings`.
Collaboration: versions, comments, change log, collaborators, approvals.
Realtime: `POST /projects/{id}/presence/ping`, `GET /projects/{id}/presence/stream` (SSE; short-circuits to `retry: 30000` on empty peer list).
Custom layers: `GET/POST /projects/{id}/custom-layers` + `PUT/DELETE /custom-layers/{id}` + `GET /custom-layers/{id}/points`.
Embeds: `GET/POST /projects/{id}/embeds` + `PUT/DELETE /embeds/{id}`.
Notifications: `GET /notifications`, `POST /notifications/{id}/read`, `read-all`.
Alerts: 4-kind generic rules + test + recent-digest.
Billing: `POST /billing/checkout`, `webhook` (public, HMAC + dedupe), `GET /subscription`, `POST /portal`, `cancel`.
Usage: `GET /usage/today` + `/days` + `/pricing`, `POST /usage/log-map-load`.
Jobs: `GET /jobs/{id}`, `POST /jobs/{id}/cancel`.
Webhooks: `GET/POST/PUT/DELETE /webhooks/{id}`, `/test`, `/deliveries`, `/api/webhooks/deliveries` (recent log).
Onboarding: `POST /onboarding/use-case`, `seen`, `GET /onboarding/state`, `POST /onboarding/clone-sample`, `activate`.
Integrations (CRM): `POST /integrations/salesforce/connect` → `GET /integrations/salesforce/callback` (public) → `POST /integrations/salesforce/push`; same triplet for HubSpot.
Public: `GET /public/projects/{token}` + `/public/projects/{token}/embed` + `/public/embeds/{token}` + `GET /openapi.json` + `/docs` + `robots.txt` + `sitemap.xml` + `POST /billing/webhook`.

**`/api/restaurants/*`** (Carafe Phase 1)

| Method | Path | Controller@Method |
|---|---|---|
| GET | `/restaurants` | RestaurantController@index |
| POST | `/restaurants` | RestaurantController@create |
| GET | `/restaurants/{id}` | RestaurantController@show |
| DELETE | `/restaurants/{id}` | RestaurantController@destroy (archive) |
| GET | `/restaurants/{id}/pos` | PosController@listForRestaurant |
| POST | `/restaurants/{id}/pos/{provider}/connect` | PosController@connect |
| GET | `/integrations/pos/{provider}/callback` | PosController@callback (public, state-validated) |
| POST | `/restaurants/{id}/pos/{provider}/sync` | PosController@sync |
| GET | `/restaurants/{id}/menu` | MenuController@listMenu |
| POST | `/restaurants/{id}/menu` | MenuController@createMenuItem |
| PUT | `/menu-items/{id}/price` | MenuController@setPrice |
| PUT | `/menu-items/{id}/recipe` | MenuController@setRecipe |
| POST | `/restaurants/{id}/recipes` | MenuController@createRecipe |
| GET | `/restaurants/{id}/recipes` | MenuController@listRecipes |
| GET | `/recipes/{id}` | MenuController@showRecipe |
| POST | `/recipes/{id}/ingredients` | MenuController@addIngredient |
| DELETE | `/recipe-ingredients/{id}` | MenuController@removeIngredient |
| GET | `/ingredient-catalog` | MenuController@listIngredientCatalog |
| POST | `/restaurants/{id}/plate-costs/recompute` | MenuController@recomputePlateCosts |
| GET | `/restaurants/{id}/cogs/overpay` | MenuController@overpayFlags |
| GET | `/restaurants/{id}/reports/money-found.pdf` | MenuController@moneyFoundReport **(NEW v6 — streams the Carafe money-found PDF via `PdfReportService::generateMoneyFound`; optional `?month=YYYY-MM-01` for historical months, defaults to current)** |
| POST | `/menu-items/{id}/recommend` | MenuEngineeringController@recommendForItem |
| POST | `/restaurants/{id}/recommendations/run` | MenuEngineeringController@recommendForRestaurant |
| GET | `/restaurants/{id}/recommendations` | MenuEngineeringController@listForRestaurant |
| GET | `/restaurants/{id}/menu/classify` | MenuEngineeringController@classify |
| POST | `/recommendations/{id}/accept` | MenuEngineeringController@accept |
| POST | `/recommendations/{id}/dismiss` | MenuEngineeringController@dismiss |
| GET | `/restaurants/{id}/roi/monthly` | RoiController@monthly |
| POST | `/restaurants/{id}/roi/measure` | RoiController@measure |
| GET/POST/GET/POST/DELETE | `/sandbox` / `/sandbox/{id}` / `/sandbox/{id}/compute` | PlanningController |
| GET/POST/POST/DELETE | `/restaurants/{id}/goals` / `/goals/{id}/snapshot` / `/goals/{id}` | GoalController |
| GET | `/restaurants/{id}/food-cost/theoretical` | FoodCostController@theoretical |
| GET | `/restaurants/{id}/labor/analysis` | LaborController@analysis |
| GET/POST | `/restaurants/{id}/labor/shifts` | LaborController |

**`/api/vendors/*`** (Carafe Phase 2)

| Method | Path | Controller@Method |
|---|---|---|
| GET | `/vendors` | VendorController@index |
| GET | `/vendors/{id}` | VendorController@show |
| GET | `/vendors/map/bbox` | VendorMapController@bbox |
| GET | `/vendors/map/serves` | VendorMapController@serves |
| GET | `/vendors/map/search` | VendorMapController@search |
| GET | `/vendors/{id}/detail` | VendorMapController@detail |
| GET | `/vendors/{id}/reviews` | VendorReviewController@list |
| POST | `/vendors/{id}/reviews` | VendorReviewController@submit |
| GET | `/vendors/{id}/reviews/aggregate` | VendorReviewController@aggregate |
| POST | `/vendor-reviews/{id}/respond` | VendorReviewController@respond |
| GET/POST/DELETE | `/saved-vendors` / `/vendors/{id}/save` | SavedVendorController |
| POST | `/vendors/{id}/claims` | VendorClaimController@create |
| GET | `/vendors/{id}/claims` | VendorClaimController@listForVendor |
| POST | `/vendor-claims/{id}/approve` | VendorClaimController@approve |
| POST | `/vendor-claims/{id}/reject` | VendorClaimController@reject |
| POST | `/vendors/{id}/listings` | VendorClaimController@addListing |
| POST | `/vendors/compare` | ComparisonController@compare |
| POST | `/vendors/consolidate` | ConsolidationController@compare |
| POST | `/vendors/compare/log` | LeadController@logComparison |
| POST | `/leads` | LeadController@create |
| GET | `/leads` | LeadController@index |
| POST | `/leads/{id}/emit` | LeadController@emit |

**`/api/admin/*`** (Carafe admin — `Middleware::auth() + Middleware::requireRole(['admin','owner'])`)

| Method | Path | Controller@Method |
|---|---|---|
| POST | `/admin/seed-campaigns/estimate` | SeedCampaignController@estimate |
| GET | `/admin/seed-campaigns` | SeedCampaignController@index |
| POST | `/admin/seed-campaigns` | SeedCampaignController@create |
| GET | `/admin/seed-campaigns/{id}` | SeedCampaignController@show |
| POST | `/admin/seed-campaigns/{id}/run` | SeedCampaignController@run |
| POST | `/admin/seed-campaigns/{id}/pause` | SeedCampaignController@pause |
| POST | `/admin/seed-campaigns/{id}/resume` | SeedCampaignController@resume |
| POST | `/admin/seed-campaigns/{id}/cancel` | SeedCampaignController@cancel |
| POST | `/admin/seed-campaigns/{id}/kick` | SeedCampaignController@kick |
| POST | `/admin/seed-campaigns/{id}/enrich` | SeedCampaignController@enrich |
| POST | `/admin/vendors/{id}/enrich` | SeedCampaignController@enrichVendor |
| GET | `/admin/seed-campaigns/{id}/delta` | SeedCampaignController@delta |
| POST | `/admin/seed-campaigns/{id}/resweep` | SeedCampaignController@resweep |
| GET | `/admin/review-queue` | ReviewQueueController@index |
| POST | `/admin/review-queue/dedupe/{id}/merge` | ReviewQueueController@dedupeMerge |
| POST | `/admin/review-queue/dedupe/{id}/reject` | ReviewQueueController@dedupeReject |
| POST | `/admin/review-queue/dedupe/{id}/defer` | ReviewQueueController@dedupeDefer |
| POST | `/admin/review-queue/classify/{id}/approve` | ReviewQueueController@classifyApprove |
| POST | `/admin/review-queue/classify/{id}/update` | ReviewQueueController@classifyUpdate |

---

## Services layer

**54 services** in `src/Services/` (plus `App\PrivateData\*` and `App\MarketData\*` repositories under separate sub-namespaces). Grouped by domain.

### Smappen core (22, all from v4 — unchanged)

`GoogleMapsService`, `GooglePricing`, `CensusService`, `DemographicsHistoryService`, `StatCanService`, `IsochroneService`, `TrafficService`, `DriveTimeMatrixService`, `TerritoryGenerator`, `AnalogService`, `SegmentationService`, `CompetitorScanner`, `PdfReportService` (**v6: new method `generateMoneyFound($restaurantId, $opts)` adds a Carafe-palette PDF template — cover with `RoiService::monthlySummary` headline + accepted/measured recs table + highest-cost items + methodology footnote citing COGS source & measurement window; tries `\TCPDF_FONTS::addTTFfont($nunitoPath)` and falls back to Helvetica; page-break guards re-emit table headers; cell widths sum to 170mm on A4**), `StripeService`, `MailService`, `StorageService`, `WebhookDispatcher`, `CacheService`, `Permissions`, `GeoUtils`, `FootTrafficService`, `PermitsService`.

### New: Carafe restaurant operations

| Service | Purpose |
|---|---|
| `PlateCostService` | Computes `true_cost_cents` per menu item from `recipe_ingredients` × `cogs_benchmark` lookups. Unit conversion (oz/lb/each/cup/tbsp). Returns `coverage_pct` so the UI can warn "based on X of Y ingredients". |
| `MenuEngineeringService` | Classifies items into menu engineering matrix (stars / plowhorses / puzzles / dogs); generates dollar-quantified pricing + repositioning + cut recommendations. `recommendForItem`, `recommendForRestaurant`, `layoutRecsForRestaurant` (UI grid hints). |
| `RoiService` | A/B over POS sales pre vs. post-accept; idempotent monthly summary; populates the "Carafe found you $X this month" war-room tile. `measureOne`, `measurePending`, `monthlySummary`. |
| `FoodCostService` | `theoretical(restaurantId, start, end)` returns theoretical $/revenue/cost%, top 10 contributors, and a coverage % gauge. |
| `LaborDemandService` | Median revenue-per-cover, over/understaffed hour flags, daypart suggestions (cross-references `pos_sales.daypart_label` against `labor_shifts`). |
| `PlanningService` | `compute()` runs what-if scenarios in `plans_sandbox` (new_location or menu_change kind). Returns projected JSON. |
| `GoalService` | `snapshot(goal_id)` + `snapshotRestaurant(restaurant_id)`. Period-aware (weekly/monthly/quarterly), idempotent. |
| `PosService` | Provider-agnostic orchestrator. `adapter(provider)`, `supportedProviders()`, `beginOAuth(restaurant, provider)`, `completeOAuth(state, code)`, `sync(restaurant, provider)`. |
| `Pos\PosAdapter` (interface) | Contract: `key()`, `buildAuthUrl()`, `exchangeCode()`, `pullMenu()`, `pullSales()`. |
| `Pos\SquareAdapter` | Live. Square API v2 with OAuth + Catalog + Orders APIs. |
| `OrderConsolidationService` | Same basket across N vendors → comparison rows + savings projection. |
| `CogsBenchmarkService` | `isConfigured()` returns true when at least one non-stub source has a row in the last 30 days; `ingest()` is the entry for the USDA + GreenDock pipelines (currently stubbed). |

### New: Carafe vendor network

| Service | Purpose |
|---|---|
| `VendorImportPipeline` | Single entry for any source (Places, OSM, Foursquare, manual). `importBatch`, `importOne`. |
| `VendorUpsertService` | Idempotent INSERT ON DUPLICATE KEY UPDATE on `google_place_id` (or `osm_id`, `foursquare_fsq_id`). Hydrates multi-row (locations, categories, details). Owns the **B2B keep/deny filter** (`isLikelyJunk`, regex deny + regex keep + brand whitelist). |
| `VendorDedupeService` | Block-key hashing (`zip5+name_prefix3`, `state+soundex`, `geohash6`) + Jaro-Winkler scoring + Placekey shortcut. Bands: ≥0.85 auto_merge, ≥0.60 review, <0.60 reject. Union-find clustering on auto_merge pairs; deterministic survivor (oldest created_at). |
| `VendorClassifierService` | Deterministic cascade: brand_map (Sysco/US Foods/Restaurant Depot/...) → primary_type_strong (produce_market/butcher_shop) → primary_type_generic + name_keyword (wholesaler+meat) → fallback (broadline at 40% confidence, flag for review). Writes `classification_confidence`, `classification_signals_json` (audit trail), `classification_needs_review` if <60%. |
| `VendorGeometryService` | Coverage polygons. `setIsochroneCoverage` (ORS), `setRadiusFallback` (circle from declared territory), `ensureCoverageForVendor`, `simplifyCoverage` (Douglas-Peucker → 100m/1km/10km tiers for vector tiles), `whoServesPoint` (PostGIS `ST_Contains` over `vendor_coverage`). |
| `VendorCacheService` | Coalesced fetch + advisory lock — `isFreshFor`, `lock`, `unlock`, `withCoalescedFetch`, `staleForRefresh`. Prevents two concurrent users searching the same bbox from paying Places twice. |
| `VendorSearchService` | Full-text + filter search across `vendors` × `vendor_locations` × `vendor_categories`. |
| `VendorReviewService` | `submit` (verification-strength gated), `refreshAggregate` (rolling rating + count). |
| `VendorComparisonService` | `compare(category, vendor_ids, basket)`, `priceBasket(basket, vendor_id)`. Honors affiliation disclosure. |
| `PlacesClient` | The cost-accounting wrapper around Google Places (New). `setCampaignContext`/`clearCampaignContext` so every call gets tagged to a `seed_campaigns.id`. `searchNearby`, `searchText`, `placeDetails`, `placePhoto`. `record()` writes a row to `api_cost_events` after every call. `maskFor(tier)` → field mask; `skusForDetailsMask` → cost projection. `isStorageAllowed()` honors the Google Places storage grant gate. |
| `PlacesEnrichService` | `enrichVendor(vendor_id, tier)` pulls Place Details at one of three tiers (hot 6h / warm 24h / cold 7d). `enrichCampaign(policy)` is the batch driver per campaign's `enrich_policy`. `refreshStaleTier` is the nightly worker driver. |
| `PlacesRateLimiter` | Shared MySQL-row token bucket. `acquire(bucket)` blocks until token available; `tryConsume(bucket)` returns immediately; `inspect(bucket)` for diagnostics. Three buckets: `places_search` (capacity 30, 10/s refill), `places_details` (20, 5/s), `places_photo` (10, 2/s). |
| `PlacesBenchmarkService` | The "this category is dense / typical / sparse for your area type" comparator. Bins US metros by Census density, returns user vs. peer counts. |
| `OSMAdapter` | Overpass query wrapper — `discover(bbox, types)`. Free + unmetered; produces the same shape `VendorImportPipeline` consumes. |
| `FoursquareAdapter` | Foursquare Places v3 wrapper — `discover(bbox, types, limit)`. ~$0.0049/call. |

### New: Seed campaign orchestration

| Service | Purpose |
|---|---|
| `SeedCampaignService` | Campaign lifecycle (draft → estimating → approved → running → paused/done/failed/cancelled). `create`, `run`, `pause`, `resume`, `cancel`, `findById`, `summary`, `index`, `materializeTiles` (grid generation), `subdivideTile` (auto-subdivide on saturation). |
| `SeedEstimatorService` | Pure-math cost projection. `estimate` returns low/expected/high. `priceSweep` + `priceEnrich` use `google_places_pricing.php` (per-SKU tiered rates) and subtract `freeRemaining()` (current month's free tier budget). `bboxAreaKm2` for tile-count math. |
| `SeedDeltaService` | `scheduleResweepForCampaign` (flip done tiles older than `--max-age-days` back to queued), `recoverStuckTiles` (running > N seconds → re-queued), `deltaSummary` (per-campaign), `deltaSummaryAll`. |
| `TileSweepWorker` | The per-tile work unit. `runOne()` loads a queued tile (FOR UPDATE SKIP LOCKED), runs `searchNearby`/`searchText` for each vendor type, acquires rate-limit tokens, upserts vendors, writes `result_id_hash` (SHA256 of sorted place-id set — enables the §12.3 zero-cost re-sweep skip when nothing changed), checks budget cap, auto-subdivides on saturation (4 child tiles at radius/√2). |

### New: Lead funnel

| Service | Purpose |
|---|---|
| `LeadFunnelService` | The only service permitted to INSERT into `supplier_leads` (enforced by data wall grep test). `createLead` writes both the audit row (`comparison_requests`) and outbox row; `emit` dispatches HMAC webhook via `WebhookDispatcher::fanout`. |

### Exception classes

| Exception | Purpose |
|---|---|
| `BudgetCapExceededException` | Thrown by `TileSweepWorker` + `PlacesEnrichService` when campaign `spent_usd >= budget_cap_usd`. Caller pauses the campaign + re-queues the tile (does **not** mark as failed — operator can raise cap + resume). |

---

## Background jobs + operator scripts

`scripts/` directory. New scripts in v5 are bolded.

### Cron-scheduled (or expected to be)

| Script | Cadence | What it does |
|---|---|---|
| `cleanup-cron.php` | Hourly | Purges expired cache, auth_tokens, revoked_tokens, old jobs (>30d), webhook_deliveries (>30d), export files (>1h), upload temp (>24h). Stuck-job sweeper marks `jobs` / `territory_generation_jobs` `status='failed'` if running > 30 min. |
| `run-competitor-scans.php` | Daily | Iterates `competitor_monitors`, scans each, fans alerts to email + Slack + in-app. |
| `job-worker.php` | Continuous (cron 1-2m or daemon) | Reads from `jobs` table with `SELECT ... FOR UPDATE SKIP LOCKED`. Used by territory gen + competitor scans + (now) Carafe POS sync + plate-cost recompute + menu engineering. |
| **`seed-tile-worker.php`** | **Cron 1–5m (NOT YET SCHEDULED)** | Pop queued tiles from `seed_tiles`, sweep Places, upsert vendors. Multiple instances run in parallel via `FOR UPDATE SKIP LOCKED`. Args: `--max-tiles=50 --max-seconds=240 --quiet`. |
| **`seed-dedupe.php`** | **After seed-tile (NOT YET SCHEDULED)** | Block-key + Jaro-Winkler + union-find merge of duplicates. Two phases: scan new rows + apply pending merges. Args: `--batch-size=5000 --quiet`. |
| **`seed-classify.php`** | **After seed-dedupe (NOT YET SCHEDULED)** | `VendorClassifierService::classifyPending` cascade. Args: `--batch-size=5000 --quiet`. |
| **`seed-enrich.php`** | **Nightly (NOT YET SCHEDULED)** | Three modes: `--campaign=ID` (sweep one), `--refresh-tier=cold|warm|hot` (nightly stale refresh), `--all-campaigns`. Args: `--batch-size=100/200 --quiet`. |
| **`seed-resweep.php`** | **Cron 1–5m (NOT YET SCHEDULED)** | Two jobs: recover stuck tiles (running > `--stuck-after`s → back to queued), schedule re-sweeps (done tiles older than `--max-age-days` → back to queued). Args: `--campaign=ID --all-campaigns --max-age-days=30 --stuck-after=1800`. |
| **`seed-coverage.php`** | **After seed-classify (NOT YET SCHEDULED)** | `VendorGeometryService` — ensures every vendor has delivery/drivetime/radius coverage; runs Douglas-Peucker simplification. Args: `--batch-size=200 --simplify-only --quiet`. |
| **`seed-osm.php`** | **Weekly per region (manual)** | OSM Overpass bulk import. Modes: `--campaign=ID` (use campaign bbox) or `--bbox=lat1,lng1,lat2,lng2 --types=produce,meat`. Free + unmetered. |
| **`seed-foursquare.php`** | **Weekly per region (manual)** | Foursquare bulk import. Same modes as OSM. ~$0.0049/call. |
| **`send-weekly-digest.php`** | **Weekly Mon 13:00 UTC (NOT YET SCHEDULED)** | Emails top 3 dollar-impact recommendations per restaurant. Idempotent per (org_id, week_start). Args: `--dry`. |
| Daily mysqldump → `/var/www/smappen/backups/smappen-$(date +%F).sql.gz` | Daily | Backup. |

### Operator scripts (manual)

| Script | Purpose |
|---|---|
| `seed-census.php` | Seeds census tracts + demographics for one state (mode: `tracts <path>`, `demographics <fips>`, `all-states`). |
| `seed-all-states.sh` | Loops `seed-census.php` for 50 states + DC. |
| `aggregate-geographies.php` | Builds `census_counties` + `census_states` aggregated polygons. |
| `compute-tract-features.php` | Pre-computes the 18-dim feature vectors for `AnalogService`. |
| `compute-sri.php` | Computes Subresource Integrity hashes for CDN assets. |
| `verify-backup.php` | Smoke-tests latest `mysqldump`. |
| `import-statcan-da.php` | **Not yet written.** Will seed `da_boundaries_ca`. |
| `ingest-demographics-history.php` | **Not yet written.** Will backfill `demographics_history` for 2019-2022. |
| `normalize-areas-geometry.php` | One-shot fix for old POLYGON-only column. |
| `normalize-dmv-geometry.sql` | DC/MD/VA tract-axis normalization (one-shot). |
| `debug-places.php` | Smoke-tests Google Places client. |
| `test-api.php` | Smoke-tests Places client (sandbox variant). |
| `competitor-scan.php` | Manually triggers scan for a project/area. |
| `refresh-census.php` | Refreshes Census data for a region. |
| **`seed-sample-restaurant.php`** | Creates a sample restaurant for Carafe onboarding. |
| **`seed-vendor-chains.php`** | Seeds vendor brands (Sysco, US Foods, Restaurant Depot, Gordon Food Service, etc.) with chain metadata so the classifier brand_map matches. |
| **`seed-vendors-manual.php`** | CSV ingest of vendor records (operator path). |
| **`seed-cogs-benchmark-stub.php`** | Populates `cogs_benchmark` with stub data until the GreenDock pipe exists. |
| **`refresh-cogs-benchmark.php`** | Refresh COGS pricing from USDA/external (currently scaffold). |
| **`measure-roi.php`** | Manually trigger ROI measurement job (also runs from `job-worker`). |
| **`compute-activation-metrics.php`** | Compute restaurant activation health scores. |
| **`coverage-export-geojson.php`** | Export vendor coverage geometry as GeoJSON (for mapping/QA). |
| **`sweep-vendors-places.php`** | Operator-only one-shot Places sweep. **Dry-run by default**; needs `--live --confirm` to actually call. |
| `migrate.php` | Core SQL migration runner. Reads `src/Migrations/*.sql`, tracks in `migrations` table, applies idempotent statements. **Brittle parser**: splits on `/;\s*[\r\n]/` without stripping comments. Don't end SQL `-- comment` lines with `;` — it breaks the splitter mid-statement (found + fixed 3 such lines on first Carafe deploy, see commits `e177943`, `76510bb`). |

**Ad-hoc one-shot workflow** (current Carafe state): on campaign Run, `SeedCampaignController::run` calls `proc_open(PHP_BINARY, [...])` to spawn `seed-tile-worker.php` in the background. Same path for resume + kick. This works but leaves no daemon — once the worker exits, nothing runs until next admin action. The fix (`e667348`) was to resolve `PHP_BINARY` explicitly to `php` rather than `php-fpm` (which is what `PHP_BINARY` evaluates to inside FPM and which can't execute CLI scripts).

---

## Frontend surface

### Routing (`App.tsx`)

```
/                                  → HomePage (anonymous) | <Navigate to="/dashboard"> (auth'd)
/blog                              → BlogPage
/dashboard                         → DashboardPage  (auth)
/projects                          → ProjectGalleryPage  (auth)
/login | /register | /forgot-password | /reset-password | /verify-email
/pricing                           → PricingPage
/changelog                         → ChangelogPage
/share/:token                      → SharedProjectPage  (public)
/embed/:token                      → EmbedProjectPage  (public)
/settings/profile|team|integrations|api|webhooks|billing  (auth)

/app/restaurants                   → RestaurantsPage  (auth)
/app/restaurants/:id               → RestaurantOverviewPage  (auth)
/app/restaurants/:id/menu          → MenuPage  (auth)
/app/restaurants/:id/recipes       → RecipesPage  (auth)
/app/restaurants/:id/costs         → CostsPage  (auth)
/app/restaurants/:id/labor         → LaborPage  (auth)
/app/restaurants/:id/goals         → GoalsPage  (auth)

/app/vendors                       → VendorMapPage  (auth — default to map view)
/app/vendors/map                   → VendorMapPage  (auth)
/app/vendors/list                  → VendorsPage  (auth)
/app/vendors/saved                 → SavedVendorsPage  (auth)

/app/*                             → AppLayout  (auth) — the actual map app
/admin/carafe                      → CarafeAdminHome  (admin/owner only)
/admin/carafe/campaigns            → SeedCampaignsListPage
/admin/carafe/campaigns/new        → SeedCampaignBuilderPage
/admin/carafe/campaigns/:id        → SeedCampaignDetailPage
/admin/carafe/review               → ReviewQueuePage
/*                                 → <Navigate to="/" replace />
```

### Unified navigation — AppNav

`src/components/layout/AppNav.tsx` is the **single** top bar across every authenticated surface. 48px tall, sticky `top-0 z-30`, `max-w-7xl` content. Brand logo → `/dashboard`. Center tabs (responsive hamburger <768px): Dashboard, Restaurants, Vendors, Map, Settings. Active tab: `bg-violet-100 text-violet-800`. Optional page-context slot (children) — used by the map app for the project switcher, by Carafe surfaces for the restaurant picker. Right: user menu (initial + email + logout).

**Pattern**: every authenticated page mounts exactly ONE AppNav. NEVER nest inside another component that already renders it. The unification commits (`ca34acf`, `6568eec`) deleted the previous map-only navbar.

### AppLayout structure (map app — unchanged from v4)

`AppLayout.tsx` mounts the map, all four chrome surfaces (LeftPanel, MapCanvas, RightPanel, RightToolbar, AdvancedPanel, HeatmapPanel, TimeMachinePanel), and the global modals (FirstRunWizard, CommandPalette, WhatsNewModal, ShortcutsModal, OnboardingChecklist).

### Component tree (new components in v5 bolded)

```
src/components/
├── auth/           LoginPage, RegisterPage, ForgotPasswordPage, ResetPasswordPage,
│                   VerifyEmailPage, ProtectedRoute
├── billing/        PricingPage, BillingSettings, UpgradeGate
├── settings/       SettingsLayout, ProfileSettings, TeamSettings,
│                   IntegrationsSettings, ApiKeySettings, WebhookSettings
├── share/          SharedProjectPage, EmbedProjectPage, SmappenBadge
├── marketing/      HomePage, BlogPage, ChangelogPage
├── dashboard/      DashboardPage
├── projects/       ProjectGalleryPage
├── onboarding/     FirstRunWizard
├── layout/         AppLayout, **AppNav**, Header, LeftPanel, RightPanel, RightToolbar,
│                   **FreeBanner**
├── map/            MapCanvas, AreaPolygon, AreaCenterPins, POIMarkers,
│                   ImportedMarkers, **CustomLayerMarkers**, DrawingTools,
│                   HeatmapLayer, ChoroplethLayer, ChoroplethWebGL, HeatmapPanel,
│                   TimeMachinePanel, PresenceCursors, StreetViewModal, **MiniMapToggle**
├── areas/          AreaList, AreaCard, **AreaCreator** (now doubles as AreaEditor),
│                   FolderTree, QuickStatsStrip
├── analytics/      DemographicsPanel, **POISearchPanel**, ComparisonView, RadarChart,
│                   ChartWidgets
├── advanced/       AdvancedPanel + lazy tabs:
│                   AnalogTab, AnalyticsTab, CannibalizeTab, CommentsTab, CompetitorsTab,
│                   FieldTab, **LayersTab**, OptimizeTab, SegmentsTab, TerritoriesTab,
│                   TrafficTab, VersionsTab, shared.tsx
├── common/         AnimatedNumber, CommandPalette (**v6: now globally mounted in App.tsx,
│                   surface-aware, with `g→r` chord**), EmptyState, GooglePlaceAutocomplete,
│                   HelpHint, OnboardingChecklist, SaveStatus, ShortcutsModal,
│                   WhatsNewModal, Spinner
├── data/           ImportWizard, ExportDialog, ReportButton
├── **carafe/       ★ NEW IN v6 — Carafe design system primitives:**
│                   **MoneyStat, DollarDelta, FreshnessChip, RecommendationCard,**
│                   **useRecommendationAction (hook), MenuEngineeringChart,**
│                   **LaborDemandChart, SyncStatus, CogsStaleBanner, CarafeSkeleton**
│                   **(SkeletonBlock/Card/StatRow/List/Table/Chart/RecCard helpers),**
│                   **CarafePrimitivesDemo (dev route /dev/carafe-primitives), index.ts barrel**
├── restaurants/    RestaurantsPage, RestaurantWorkspaceLayout, RestaurantOverviewPage,
│                   MenuPage, RecipesPage, CostsPage, LaborPage, GoalsPage
│                   **+ v6: RestaurantSwitcher (sticky AppNav context dropdown)**
├── vendors/        VendorMapPage, VendorsPage, VendorSidePanel, SavedVendorsPage
│                   **+ v6: VendorCoveragePolygons (zoom-aware tier picker),**
│                   **AffiliatedBadge (pill + icon, keyboard-accessible disclosure tooltip)**
├── onboarding/     **+ v6: CarafeFirstRunWizard.tsx** (3-step polished onboarding —
│                   use case → real address or sample → animated reveal with
│                   sample recs + MoneyStat headline); **CarafeOnboardingGate.tsx**
│                   (was a stub in v5, now real — fetches `/api/onboarding/state`,
│                   opens wizard when `flags.carafe_wizard_complete` unset AND
│                   `org_restaurant_count === 0`)
├── **admin/        AdminOnlyRoute, CarafeAdminLayout, CarafeAdminHome,
│                   SeedCampaignBuilderPage, SeedCampaignDetailPage,
│                   SeedCampaignsListPage, ReviewQueuePage**
└── ErrorBoundary
```

### Hooks

- `useTheme` — sets `data-theme="dark|light"` on `<html>`, prefers user setting → localStorage → system preference
- `useShortcuts` — global keyboard map (Ctrl+/, Ctrl+S, Cmd+Z/Shift+Z, ? for shortcut help, etc.)
- `useDynamicFavicon` — favicon color reflects unread notifications
- `useViewUrl` — `#map=lat,lng,zoom` URL hash sync (one-shot read on map ready, debounced write on idle)
- `useClickOutside` — generic dropdown-dismissal helper
- **`useOrphanOverlayCleanup`** — runs on every navigation, removes stuck modal-backdrop elements

### Stores (Zustand — 9, **2 new in v5**)

| Store | Purpose |
|---|---|
| `authStore` | JWT + user. `partialize` persists only the token. |
| `projectStore` | Current project + areas + folders + importedPoints |
| `mapStore` | Map instance ref, viewport, drawing mode, heatmap state, time-machine state, presence peers, right-panel tab. **New in v5**: `customLayersVersion`, `bumpCustomLayers`, `editingAreaId`, `openAreaEditor`, `closeAreaEditor`, `hiddenAreaIds`, `toggleAreaVisibility`, `isAreaHidden`, `heatmapFeatures` snapshot. |
| `uiPrefsStore` | recentColors, areaListFilter/groupBy/order, mapStyle (5 presets), showPolygonLabels, onboardingCompleted |
| `costStore` | totalUsdToday + callCountToday + per-session deltas |
| `undoStore` | reversible action stack (Cmd+Z / Shift+Cmd+Z) |
| `saveStatusStore` | pending count, lastSavedAt, lastError; `trackSave(promise)` wrapper |
| **`restaurantStore`** | currentRestaurant, restaurants, menuItems, recommendations; `updateRecommendationStatus(id, status)` to flip suggested → accepted/dismissed/measured |
| **`vendorMapStore`** | Filters (q, type, category, minRating, affiliatedOnly), pins, selectedVendorId, servesPin, servesResults, servesLoading; `setFilter`, `setPins`, `setServes`, `selectVendor` |

### API clients (`frontend/src/api/`) — new in v5 bolded, v6 additions noted inline

`client.ts` (axios instance + auth header injection + error handling), `auth.ts`, `projects.ts`, `folders.ts`, `areas.ts`, `analogs.ts`, `analytics`, `advanced.ts`, `billing.ts`, `customLayers.ts`, `exports.ts`, `features.ts`, `geocoding.ts`, `heatmap.ts`, `imports.ts`, `isochrone.ts`, `places.ts`, `reach.ts`, `reports.ts`, `usage.ts`, **`restaurants.ts`** (**v6: new `engineeringApi.classify(restaurantId)` for the menu-engineering 2×2 chart; `MenuEngineeringPayload` + `MenuQuadrant` types; `VendorDetail.coverage` extended with `geometry_100m`/`_1km`/`_10km` optional fields for the zoom-aware polygon tier picker**), **`vendors.ts`**, **`vendorMap.ts`**, **`carafe.ts`** (admin: estimate, campaigns, review queue, dedupe/classify decisions, vendor types catalog).

### Utilities

Same as v4: `format.ts`, `colors.ts`, `mapStyle.ts` (5 presets), `mapAnim.ts`, `mapExport.ts`, `confetti.ts`, `sessionRecord.ts`, `snapToRoads.ts`, `toastBatch.ts`. Plus the new `frontend/src/api/carafe.ts` static export `VENDOR_TYPES`.

---

## Feature catalog — Smappen core

(Unchanged unless noted; refer to v4 audit for full detail.)

### Marketing surface
HomePage (gradient hero, value-props, pricing teaser), BlogPage (3 seed posts), `robots.txt`, `sitemap.xml`.

### Dashboard + project gallery
`/dashboard` — three-column landing (projects ≤8 + recent activity + usage summary). Empty-state CTA clones the sample project.
`/projects` — grid/list views (persisted), search, sort, per-card rename/archive/delete.

### First-run wizard
3-step modal: use-case picker → address → auto-generated 15-min isochrone + AnimatedNumber population. Gated on `onboarding_flags.wizard_complete`.

### Mapping core
Free-draw polygon, isochrone (drive/walk/cycle), radius circle, pin-drop. Auto-fit / fly-to / fitBoundsToArea. Polygon hover infowindow with population + median income. Centroid badges. **Updated in v5**: `AreaCreator` is now also the editor (drive time + mode + color + opacity + notes inline; one-button save).

### Demographics
Population, income, housing, unemployment, density. **Trends sub-tab** (per-vintage 2019-2023). DataFreshnessFooter.

### Heatmap (choropleth)
6 metrics, 5 boundary levels, 11 palettes, 3000-tract cap, 7-day server tile cache. **Updated in v5**: panel is now a docked bottom-center compact bar with settings tray (was a left-rail panel that collided with LeftPanel column).

### POI / Businesses
Chip strip (Restaurant/Cafe/Pharmacy/Gym/School/Hospital/Bank/Gas/Store/custom). **Updated in v5**: text search now uses `locationRestriction` (bbox) with recursive tiling, so dense urban searches return the full set instead of stopping at 60. **New**: response caching layer prevents paying twice for the same query and survives a page reload. **New**: `POST /api/places/benchmark` compares the user's POI count to 10 similar-density US metros and returns "dense / typical / sparse for your area type" — the POI panel now shows this badge above the count.

### Reports
TCPDF, 4 templates (executive / site_selection / franchise_pitch / demographics_only), per-area static map.

### Import / Export
CSV / XLSX import (streamed + preview). Export: CSV / XLSX / GeoJSON / KML (per-color KML PolyStyle).

### Auth & accounts
Email/password + JWT + revocation. Password reset, email verification, API keys. Bulk JWT revocation via `users.tokens_invalid_before`.

### Settings
Profile, Team (admin/editor/viewer), Integrations (Salesforce/HubSpot OAuth), API keys, Webhooks, Billing.

### Realtime collaboration
Presence cursors (SSE), versions, comments, change log, approvals.

### Notifications
Bell icon (badge, 60s poll), mark-read, dynamic favicon. **Per CLAUDE.md memory**: bell is for **decisions and abnormal events**, never routine activity logging.

### Public sharing
`/share/:token` + `/embed/:token`. "Powered by Smappen" badge on embeds.

### Daypart (24-hour traffic animation)
Bottom strip, ORS traffic-aware `/api/isochrone/traffic/day`. Play/pause + 4-speed scrubber.

### Cost tracking
Header widget "$X.XX today" + per-API breakdown. Toasts batched 600ms.

### Advanced features (the ✨ panel — 11 lazy tabs)
Territories, Analogs, Analytics, Cannibalize, Traffic, Optimize, Segments, Comments, Versions, Competitors, Field notes. **New tab**: `LayersTab` (custom layer management UI).

### Alerts
4 kinds (`competitor_new` / `demographics_changed` / `ai_score_drop` / `metric_threshold`). Test-fire, weekly digest endpoint.

### Custom data layers
Upload customer CSV → marker layer or derived heatmap. Palette + radius per layer. Visibility toggle. **Now wired in v5** via `LayersTab` in the advanced panel.

### Embed builder
Generate iframe snippets per project. Width/height/show_legend/show_controls/show_branding. View-count tracker.

### CRM integrations
Salesforce (full OAuth, AES-256-CBC tokens, push to Account custom fields). HubSpot (same triplet + hub_id introspection). Refuses to start without `APP_KEY` in `.env`.

### OpenAPI / docs
`/api/openapi.json` (3.1), `/api/docs` (Swagger UI).

---

## Carafe — restaurant workspace (Phase 1)

Carafe is a B2B platform for restaurant operators: connect your POS, build recipes, compute true plate cost from market ingredient pricing, generate dollar-quantified pricing recommendations, track ROI, and find vendors.

Live surface: **`/app/restaurants/*`**, mounted under `RestaurantWorkspaceLayout` (sidebar tabs: Overview / Menu / Recipes / Costs / Labor / Goals).

### Restaurant entity

`restaurants` (org-scoped) — name, address, lat/lng, timezone, region, is_sample, archived_at. Created via Google Places autocomplete or manual entry. One org can own many; each has its own POS integrations, menu items, recipes, recommendations, goals, shifts.

### RestaurantWorkspaceLayout — the shell

**v6 rewrite for mobile.** Wraps the AppNav with two new context slots:

- **Desktop (`md+`)** — `<RestaurantSwitcher>` (44px pill button + dropdown of all org restaurants with search at 4+, "All restaurants" + "New restaurant" footer rows, current marked with `✓`) lives in `AppNav`'s `children` prop alongside the sample-restaurant badge. Vertical sidebar of tabs (Overview / Menu / Recipes / Costs / Labor / Goals).
- **Phone (`<md`)** — `<RestaurantSwitcher>` lives in the new `AppNav.mobileContext` prop (renders inline between brand and hamburger so it stays one-handed without opening the drawer); below the nav, a sticky horizontal chip rail of tabs at `top-12` lets operators swap tabs while keeping the dollar tile in view. Vertical sidebar hidden.

Tab content wrapped in `<div key={location.pathname} className="carafe-route-fade">` — the 160ms upward-translate + opacity fade re-triggers on every tab swap (gated by `prefers-reduced-motion`).

### RestaurantOverviewPage — the war-room (**v6 mobile-first redesign**)

The landing page after picking a restaurant. Hierarchy from top, designed so ROI + Top Move fit above the fold on a 390×844 phone:

1. **ROI hero + Top Move pair** — 5/7 grid at `md+`, stacked single-column on phone.
   - **ROI hero** (`RoiHero`) uses `<MoneyStat size="xl" tone="positive">` for "Carafe found you $X this month" (figure from `RoiService::monthlySummary['found_cents']`); `<FreshnessChip>` on `data.roi.last_updated_at`; `<DollarDelta>` in footer for month-over-month vs. trend point `[length-2]`; 6-month inline-SVG sparkline at bottom. Now also exposes a brand-violet **"Download report"** pill (44px, `FileText` icon) that hits the new `/api/restaurants/{id}/reports/money-found.pdf` endpoint via `responseType: 'blob'`, visible only when `foundDollars > 0`.
   - **Top Move section** uses `<RecommendationCard density="hero">` via `topMoveToRec()` adapter that converts `OverviewTopMove` → minimal `Recommendation`. From-digest badge when applicable. Local queue advances via `onAfterDecide`, decrementing `open_recs_count`.
2. **Today's Service tile** — three `ServiceCell` tiles (Covers / $/cover / Food cost). Grid 2-col on phone (Food cost spans both columns so the tint reads), 3-col `sm+`. Tones via `--money-positive` / `--money-negative` / `var(--ink)` paired with hint text. Section header shows `<FreshnessChip>` on `last_sale_at` (or `last_synced_at` fallback when POS connected but no sales).
3. **Digest callout** — only renders for 48h after the Monday digest send; brand-50 tinted; `Mail` icon; "See all →" 44px tap target back to `/menu?source=digest`.
4. **PosCard** — now a `<SyncStatus>` + optional `<CogsStaleBanner>` stack. The war-room is read-mostly; primary CTA navigates to `/menu` where the actual sync controls live.
5. **Study trade area** — secondary 44px footer button (`MapPin` icon). Now slim, never competing with dollar moves.

Loading state uses layout-shaped skeletons matching the real silhouette.

### MenuPage (**v6**)

- **`<SyncStatus>`** replaces the bespoke "Square — connected" strip; drives every state of the integration (not_connected → connecting → syncing → synced → stale → error).
- **`<CogsStaleBanner>`** sits above the menu-engineering chart so the caveat is read before the operator trusts the math.
- **`<MenuEngineeringChart>`** — the 2×2 quadrant chart (full description above in the v6 headline section). Wires `engineeringApi.classify()` + the overview endpoint's `usda_prices.as_of` for COGS attribution; `findRec` callback so clicking a point opens the matching open recommendation in the modal.
- **Recommendations strip** — filtered to `status === 'suggested'`, each row wrapped in `<li className="stagger-in">` with `<RecommendationCard density="comfortable">`. The old local `RecommendationCard` helper and `acceptRec`/`dismissRec` handlers were deleted — the unified card + `useRecommendationAction()` own the entire flow.
- **Menu items table** — margin-% column previously colored red alone; now `<MarginPctCell>` pairs the color with an `AlertTriangle` icon + "Low" pill + `aria-label`. Dollar-margin column uses tokens so it auto-flips in dark mode.

### RecipesPage

Unchanged in v6 functionally — the 3-button `EmptyStatePicker` (paste / suggest / manual) shipped in the previous Carafe set. v6 only verified its CTAs all route correctly and bumped the skeleton to layout-shaped.

**Critical UX gate**: without recipes, plate cost is unknown → all downstream features (food cost, recommendations, ROI) degrade. The empty state nudges hard.

### CostsPage (**v6 overpay-flags hierarchy**)

- **`<CogsStaleBanner>`** at the top (severity-based, see system-wide section).
- **`FoodCostHero`** — 3-col grid: status icon + verbal label ("Healthy" / "Watch" / "Over target") next to a 5xl `pct%` in the band color (`--money-positive`/`#92670E`/`--money-negative`); `<BandRuler>` (0%→50%+ with three tinted segments + a marker dot for the current %); `<DollarDelta>` showing $ over target. WCAG AA verified on all three band colors.
- **`<CoverageGauge>`** — calm brand-purple progress bar `role="progressbar"` ("Coverage · X% of sales lines have plate cost"). Honest body copy at low coverage.
- **`<CogsFreshnessStrip>`** — `<FreshnessChip>` per (source, region) from `benchmark_freshness[]`. Stub-only state gets a separate amber callout.
- **`<OverpayList>`** + **`<OverpayRowCard>`** — replaces the old table. Each row is a 12-col grid: name + qty sold + cost ratio with band icon (left), "Your cost / unit → Target at 35%" price cells + `<DollarDelta>` per-unit gap (middle), dominant `<MoneyStat size="lg">` for addressable $/period (right). Sorted by `savingsCents` desc; top row gets 2px `--money-positive` border + "Top opportunity" badge. Honest Phase-1 caveat below.
- **`CostsEmptyState`** points back to Recipes when no data exists.

### LaborPage (**v6**)

- Median-RPC header tile gained a `<FreshnessChip timestamp={data.window.end}>` "through Apr 28" with cadence-aware thresholds (fresh ≤36h, stale >7d).
- **`<LaborDemandChart>`** replaces the old FlagList rows entirely. 720×260 SVG with revenue (or covers) area + labor cost line + over/under-staff hour bands + dollar-annotation pills + revenue/covers toggle. Mobile sideways-scrolls inside `min-w-[540px]` + a `DegradedSummary` grid above (top-2 over + top-2 under as tinted summary tiles, readable without the SVG at all).
- **Slow-window suggestions** render as `<RecommendationCard readonly density="comfortable">` via the new `slowWindowToRec()` adapter — synthesized `Recommendation` with `kind: 'reposition'` and `dollar_estimate_cents = revenue_cents` (honest "addressable revenue" framing). Each `<li>` stagger-animates in.
- **Empty states**: `<LaborEmptyState>` when no data; slow-windows zero state now reads as positive (green `--money-positive-bg` tile: "Every hour with sales pulled its weight…" + "Add another shift" CTA).

### GoalsPage — operator scorecard (**v6 redesign**)

Goals: `food_cost_pct`, `avg_check_cents`, `margin_pct`, `weekly_revenue_cents`. Cadence: weekly / monthly / quarterly. Snapshot trend lines via `goal_snapshots`. Cards now in a `grid-cols-1 lg:grid-cols-2` grid (single column up through 1024px so 390px viewports get full-width cards).

Each `<GoalCard>` is a 3-row scorecard:
1. **Header** — metric name + cadence pill + target value + `<StatusBadge>` (icon + verbal label + color) + 44×44 refresh + 44×44 archive icons. New `<FreshnessChip>` shows snapshot age with cadence-aware thresholds (weekly fresh ≤2d / stale >7d; monthly ≤14d / >35d; quarterly ≤45d / >100d).
2. **Hero value + delta** — `<MoneyStat>` for dollar metrics, custom 4xl percentage for `food_cost_pct`/`margin_pct`. Next to it, `<GoalDelta>` shows signed gap ("+1.2pp vs target" / "+$340 vs target") color-coded for good/bad based on `lowerBetter`. "On target" gets its own positive-tinted text.
3. **Ring + sparkline** — 84px SVG `<ProgressRing>` (% to target with figure centered; animates `stroke-dasharray` via 600ms CSS transition) + chronological `<GoalSparkline>` with dashed target reference line + per-snapshot dots tinted positive vs negative.

Status model collapses each goal into one ratio: `hit` (≥1.0 — restrained celebration, 2px `--money-positive` border, `CheckCircle2` "Hit"), `on_track` (≥0.95), `at_risk` (≥0.80, amber), `off_track` (<0.80), `unknown`. Same math whether the metric is "higher is better" or "lower is better".

Sparse-history fallback: <2 snapshots renders a soft "Not enough history yet" tile. New `<GoalsEmptyState>` suggests three starter goals + primary CTA.

### POS integration

| Provider | Status | Notes |
|---|---|---|
| Square | **Live** | Full OAuth (Catalog + Orders + Labor scopes), AES-256-CBC token storage in `pos_integrations`, refresh-token flow, sync endpoint. |
| Toast | Adapter stubbed | OAuth flow scaffolded; needs production credentials. |
| Clover | Adapter stubbed | Same. |

OAuth flow:
1. `POST /api/restaurants/{id}/pos/{provider}/connect` returns the auth URL with a state token in the user's session
2. Provider redirects to `GET /api/integrations/pos/{provider}/callback` (public route, state-validated)
3. `PosController::callback` exchanges code → tokens, encrypts at rest, redirects browser to `/app/restaurants/{id}/menu?pos_connected=square`
4. `POST /api/restaurants/{id}/pos/{provider}/sync` (manual button; eventually cron'd) pulls menu items + recent sales

### Recommendation engine

`MenuEngineeringService::recommendForRestaurant` classifies items into the menu engineering matrix (stars/plowhorses/puzzles/dogs) and generates kind-specific recommendations:

| Kind | Trigger |
|---|---|
| `price_raise` | Star with margin below median |
| `price_lower` | Plowhorse with low elasticity signal |
| `reposition` | Puzzle (low pop, high margin) — design hint |
| `reprice` | Dog with margin > 0 — last-chance |
| `cut` | Dog with margin ≤ 0 — drop the item |

Each recommendation carries a `dollar_estimate_cents` and a `payload JSON` (item context). Operator accepts or dismisses inline. On accept, `RoiService::measureOne` schedules an A/B over the next 28 days of POS sales pre vs. post-accept and writes `measured_impact_cents` on the same row.

### Planning sandbox

`/api/sandbox` — `kind ∈ {new_location, menu_change}` with `payload JSON` (e.g., a hypothetical menu price list) and `projected JSON` (what the recommendation engine would say). `PlanningService::compute` runs the same engine over the sandbox payload without touching the live menu.

### Weekly digest email

`send-weekly-digest.php` (idempotent per `(org_id, week_start)`) emails the top 3 dollar-impact recommendations per restaurant. **Not yet scheduled** — runs manually for now.

### Data wall

The `App\PrivateData\*` namespace (`restaurants`, `menu_items`, `pos_sales`, `recipes`, `goals`, `labor_shifts`, etc.) is the only one allowed to touch these tables. `tests/DataWall/DataWallTest.php` greps the source tree to verify no `App\MarketData\*` (vendor directory) file ever reads from these tables. Reverse direction also locked — restaurant ops never reads from `vendor_locations` directly; goes through `App\MarketData\*` repositories.

`App\SharedRef\*` is the read-only middle reservoir — `cogs_benchmark` lives here because both PrivateData (for plate cost) and the vendor pricing comparator need to read it.

---

## Carafe — vendor network (Phase 2)

Live surface: **`/app/vendors/*`**.

### Vendor entity (canonical record)

`vendors` — the de-duplicated, classified, multi-source record:
- Identity: `id`, `name`, `brand`, `legal_name`, `placekey` (cross-source matcher)
- Contact: `hq_address`, `hq_lat`, `hq_lng`, `phone`, `website`
- Classification: `type` (enum: broadline / warehouse / produce / protein / seafood / specialty / grocery / bakery_dairy_beverage), `primary_category`, `completeness_score (0..100)`
- Quality: `aggregate_rating (1..5)`, `rating_count`, `last_verified_at`
- Source provenance: `source` (manual / public_directory / usda / greendock_affiliate / ...)
- **Affiliation disclosure**: `is_affiliated` boolean — surfaces a "Partner of Smappen / GreenDock" badge in UI (legal gate §1.4)
- Claim: `claim_status` (unclaimed / pending / claimed / disputed)
- Classifier audit: `classification_confidence (0..100)`, `classification_signals_json` (cascade trail), `classification_needs_review (0|1)`, `classified_at`, `classification_reviewed_at`
- Lifecycle: `merged_into` (null = active; set when union-find merged into a survivor)

### Multi-location

`vendor_locations` — one row per physical site:
- `vendor_id` → vendors
- `label` (e.g., "Bronx DC"), `address`, `is_primary`
- Geo: `lat`, `lng`, `pt POINT SRID 4326`, `geohash6`
- Identifiers (one or more, UNIQUE per source): `google_place_id`, `osm_id`, `foursquare_fsq_id`, `placekey`
- Dedupe blocking: `zip5`, `state_code`, `name_soundex`, `name_prefix3`, `dedupe_scanned_at`
- Source: `manual / public_directory / places / chain_seed / vendor_claimed / osm / foursquare`

### Coverage geometry

`vendor_coverage` — the service area polygon:
- `coverage_type ∈ delivery / pickup_drivetime / declared_territory / radius`
- `geom POLYGON SRID 4326`
- For drive-time: `travel_mode ∈ driving/walking/cycling`, `travel_minutes`
- For radius: `radius_miles`
- `confidence (0..100)`, `source`
- **Douglas-Peucker simplified tiers** for vector-tile rendering at different zoom levels: `simplified_100m` (for street zoom), `simplified_1km` (city zoom), `simplified_10km` (metro/state zoom), `simplified_at`

`VendorGeometryService::ensureCoverageForVendor` falls back to a radius if no isochrone is computable; `whoServesPoint(lat, lng)` is the drop-a-pin query (PostGIS `ST_Contains` over the simplified geometry at the right zoom tier).

### VendorMapPage — the main vendor surface (**v6 visual polish**)

Full-bleed Google Map (US-centered). **Filter strip** at the top:
- At `md+`: name search + vendor type + category + min rating + affiliated-only checkbox all sit in one row, all 44px height
- On phone: only search + type stay in the bar; the rest collapse behind a `<SlidersHorizontal /> Filters` button that shows a brand-violet count badge when category/rating/affiliated have non-default values. Tap opens a disclosure with the remaining controls stacked vertically + a "Done" button. "Who serves me?" button shrinks to "Who?" on narrowest phones

Pins fetch via bbox query (`GET /api/vendors/map/bbox`) on map idle (debounced — 350ms, with antimeridian-clamp safety). Affiliated vendors get a brand-violet halo (3px stroke at scale 9); others use a thin white stroke at scale 6.

**Drop-a-pin** mode: click → `GET /api/vendors/map/serves?lat=...&lng=...`. **v6 polish**: the panel opens + `servesLoading=true` is set *before* the network round-trip via `setServes({lat,lng}, [])`, so the operator sees the pin land + a layout-shaped 3-row skeleton appear inside one frame (~16ms). Header reads "Searching…" then "N vendors" on settle. Result rows stagger-animate in.

**Coverage polygons (v6)**: when a vendor is selected, `<VendorCoveragePolygons>` paints the vendor's coverage geometry over the map. Picks one of the Douglas-Peucker simplified tiers based on live map zoom — `zoom ≥ 14` → `simplified_100m` (street detail), `zoom ≥ 10` → `simplified_1km` (city), `< 10` → `simplified_10km` (metro). Falls through tiers when null. Path arrays memoized so panning inside the same tier doesn't tear down the overlay; tier swaps on zoom-boundary cross are path replacements, no stutter. Affiliated vendors get brand-violet fill+stroke (0.12 fill opacity), independents calm slate. 30ms post-mount opacity bump for a fade-in on selection. The backend wiring: `VendorCoverageRepository::listForVendor` now exposes all four geometries (`ST_AsGeoJSON(geom)` + `simplified_100m/1km/10km`); `VendorMapController::detail` decodes them all into the JSON response.

**`<VendorSidePanel>` polish (v6)** — slide-in via `.panel-slide-left`; mobile width `min(96vw, 420px)`; 44×44 close. Header shows the new `<AffiliatedBadge variant="pill">` instead of the bare `ShieldCheck` + uppercase text:
- **`<AffiliatedBadge>`** — two variants (`pill` brand-violet chip with `ShieldCheck` + "AFFILIATED" + Info dot; `icon` just the ShieldCheck for dense rows). Both open the same disclosure tooltip on hover, focus, or click. Tooltip copy is intentionally boring: *"Affiliated supplier. Carafe has a business relationship with this vendor through USA Produce. They do not pay for ranking placement, and reviews come from operators only — affiliation never changes a vendor's order in the 'who serves me?' results."* Keyboard-accessible (Enter/Space toggles), screen-reader-accessible (`aria-describedby` + `role="tooltip"`), closes on Esc + outside click. Used in side-panel header (pill), list-view rows (icon), serves-panel result rows (icon).

List view toggle: `/app/vendors/list` (VendorsPage) — same filters, grid/table layout, ranked by comparison score within the selected category.

Saved vendors: `/app/vendors/saved` (SavedVendorsPage) — **v6**: stagger-in grid, brand-tinted empty state with 3 value-prop tiles (Map view / Shortlist / Affiliated) explaining why saving vendors matters, primary `Browse vendors →` CTA at 44px.

### Reviews

`vendor_reviews` — one per (org, vendor). Verification strength gates submission:
- `restaurant_exists` — caller's org has at least one restaurant
- `pos_connected` — caller's org has a Square (etc.) integration on a restaurant in the same region as the vendor
- `manual_review` — admin override

Score columns: `overall (1..5)`, `score_price / reliability / quality / accuracy / service`, `body`, `photo_url`, `categories_bought JSON`, `volume_band ∈ light/moderate/heavy`, `delivery_or_pickup`. Hidden by admin if flagged.

`VendorReviewService::refreshAggregate` is called on insert/update — rolls up to `vendor_review_aggregates` for cheap reads.

Vendor responses: `vendor_review_responses` — a vendor (`claim_status=claimed`) can reply once per review.

### Vendor claim workflow

1. Operator clicks "Claim this listing" on `VendorSidePanel` → `POST /api/vendors/{id}/claims`
2. Admin reviews in `/admin/carafe/review` (claim queue not yet UI-wired — claims show in the admin's general queue)
3. Admin approves/rejects → `POST /api/vendor-claims/{id}/approve|reject`
4. Approved claim flips `vendors.claim_status = claimed` and links `vendor_claims.organization_id` so the claimant can manage listings
5. Claimant adds listings: `POST /api/vendors/{id}/listings` (`category × region × service_radius_mi × min_order_cents`)

### Comparison + consolidation

`POST /api/vendors/compare` — `VendorComparisonService::compare(category, vendor_ids, basket)`. The "honest" piece: always shows the affiliated disclosure badge when an affiliated vendor is in the comparison.

`POST /api/vendors/consolidate` — `OrderConsolidationService::compare(basket, vendor_ids)`. Returns "if you bought all of this from vendor X you'd save $Y vs. splitting across N" projection.

`POST /api/vendors/compare/log` — audit trail (`comparison_requests`) for the funnel — even comparisons that don't end in a quote request get logged.

### Saved searches + alerts

`vendor_searches` — `(organization_id, user_id, filters_json, alert_on_new, last_alerted_at)`. If `alert_on_new=1`, the resweep worker fires a notification when a new vendor matches the saved filters. **Not yet wired** — table + read endpoint exist; the alert dispatcher isn't built.

---

## Carafe — seeding pipeline + admin

Live surface: **`/admin/carafe/*`** (admin/owner role only).

### Three-stage worker chain

```
seed_campaigns (admin creates draft)
  ↓ [SeedCampaignController::run]
SeedCampaignService::materializeTiles  (writes seed_tiles grid rows)
  ↓ [proc_open spawns]
seed-tile-worker.php
  ↓ TileSweepWorker::runOne per tile (FOR UPDATE SKIP LOCKED)
  →  PlacesClient::searchNearby + searchText (cost-tracked, rate-limited)
  →  + OSMAdapter / FoursquareAdapter when policy allows
  →  VendorUpsertService::upsertVendorFromPlace
       — keep/deny filter (B2B-only)
       — ON DUPLICATE KEY on google_place_id / osm_id / foursquare_fsq_id
  →  result_id_hash = SHA256(sorted place-id set)  — for §12.3 delta
  →  budget_cap check → BudgetCapExceededException → pause campaign
  →  auto-subdivide on saturation (results == maxResultCount → 4 child tiles)
  ↓
seed-dedupe.php
  ↓ VendorDedupeService::dedupeNewLocations
  →  block keys: (zip5+name_prefix3), (state+soundex), geohash6
  →  Jaro-Winkler score + Placekey shortcut
  →  decision bands: ≥0.85 auto_merge, ≥0.60 review, <0.60 reject
  ↓ VendorDedupeService::applyPendingAutoMerges
  →  union-find clustering; survivor = oldest created_at
  ↓
seed-classify.php
  ↓ VendorClassifierService::classifyPending
  →  cascade: brand_map → primary_type_strong → primary_type_generic+keyword → fallback
  →  writes type, confidence, signals_json, needs_review (<60% → review queue)
  ↓
seed-coverage.php  (parallel to enrich)
  ↓ VendorGeometryService::ensureCoverageForVendor
  →  ORS isochrone OR radius fallback
  →  Douglas-Peucker simplification → 3 tiers
  ↓
seed-enrich.php  (off-line stale-refresh)
  ↓ PlacesEnrichService::refreshStaleTier(cold|warm|hot)
```

### Cost ledger

Every Places call writes a row to `api_cost_events`:

| Column | Notes |
|---|---|
| `campaign_id`, `tile_id` | NULL if not part of a campaign (ad-hoc enrich) |
| `sku` | `places_nearby_pro`, `places_text_pro`, `place_details_pro`, `place_details_contact`, `place_details_atmosphere` |
| `billable_units` | 1 per call for search; 1 per detail field-mask group for details |
| `unit_cost_usd`, `total_cost_usd` | Per `config/google_places_pricing.php` (tiered: 0–100K, 100K–500K, 500K+) |
| `field_mask_hash` | SHA256 truncated — for nightly billing reconciliation |
| `http_status`, `latency_ms`, `error_message` | |
| `called_at DATETIME(3)` | Millisecond precision for cost-spike forensics |

### Shared rate limiter

`places_rate_buckets` (one row per SKU family):

| Bucket | Capacity | Refill |
|---|---|---|
| `places_search` | 30 | 10/s (so 150 tokens / 15s) |
| `places_details` | 20 | 5/s (75 tokens / 15s) |
| `places_photo` | 10 | 2/s (30 tokens / 15s) |

Atomic via MySQL row-level locking — `SELECT ... FOR UPDATE`, decrement, commit. Intentionally low-tech (no Redis) — at expected scale (few tile workers, double-digit QPS) it's fine.

### Budget cap

`seed_campaigns.budget_cap_usd` is enforced in both `TileSweepWorker::runOne` and `PlacesEnrichService::enrichCampaign`:

```php
if ($campaign['budget_cap_usd'] !== null && $campaign['spent_usd'] >= $campaign['budget_cap_usd']) {
    throw new BudgetCapExceededException;
}
```

Caller catches it and:
- Marks the **campaign** as `paused` (with `pause_reason='budget cap halted'`)
- Re-queues the **tile** (`seed_tiles.status='queued'`) — does **not** mark as failed
- Logs the halt to `api_cost_events` as informational

The operator can raise the cap + resume; the next worker picks up where it left off.

### Estimator (zero API calls)

`SeedEstimatorService::estimate(payload)` is pure math:
1. Compute bbox area km² → tile_count via `TILE_SIZE_KM = { rural:12, suburban:6, dense:2.5, mixed:6 }`
2. For each vendor_type: `places_types.length × tiles × 1` searchNearby + `text_queries.length × tiles × estimated_pages` searchText
3. Multiply by per-SKU price (tiered) from `google_places_pricing.php`
4. Subtract `freeRemaining()` (current month's unused free tier)
5. Enrich: vendor_count × per-vendor SKU cost based on `enrich_policy`
6. Return `{ low, expected, high }` with per-SKU breakdown

Returns immediately; spec §10 Guardrail 2: "The estimator makes zero API calls."

### Enrich policies

| Policy | When | Coverage |
|---|---|---|
| `all` | At seed time, cost-intensive | Every vendor discovered |
| `priority_types` (default) | At seed time | `['broadline','cash_carry','produce','seafood']` only (`PlacesEnrichService::PRIORITY_TYPES`) |
| `on_demand` | First view, lazy | Only enriched when an admin or operator opens the vendor detail page |

### Three-tier volatility cache (§12.1)

Google Places details change at different rates. Tier-specific TTL + field mask:

| Tier | TTL | Fields pulled (field mask) |
|---|---|---|
| `hot` | 6h | `rating`, `userRatingCount`, `currentOpeningHours`, top-5 reviews |
| `warm` | 24h | `regularOpeningHours`, `currentOpeningHours`, `paymentOptions`, `delivery`, `takeout`, `dineIn` |
| `cold` | 7d | `displayName`, `types`, `formattedAddress`, `addressComponents`, `postalAddress` |
| `full` | (special) | `places.*, reviews.*, photos.authorAttributions` (every field; used on first ingest) |

Nightly worker (`seed-enrich.php --refresh-tier=cold|warm|hot`) pulls only the expired tier's field set — cuts re-enrich volume ~80% vs. always pulling full.

### B2B filtering

`VendorUpsertService::isLikelyJunk` runs two-stage on every insert:

**Stage 1 — Deny patterns (return true → REJECT)**:
- Restaurants / cafés / DTC: `cafe / coffee shop / restaurant / grill / diner / bistro / pizzeria / brewery / wine bar / tea house / pastry / ice cream / gelato / juicery / donuts / bagel / sandwich shop / sushi / ramen / food truck / cocktails ...`
- Major consumer-retail chains: `7-Eleven / Safeway / Aldi / Wegmans / Whole Foods / Trader Joe / Harris Teeter / Walmart / Target / Food Lion / Giant / Publix / Kroger / Albertsons / ShopRite / Sam's Club / BJ's Wholesale / Dollar Tree / Sprouts / H-E-B / Meijer / Costco Wholesale / Fresh Direct ...`
- QSR / coffee + bakery: `Starbucks / Dunkin / Panera / Chick-fil-A / Chipotle / McDonald / Burger King / Wendy / Taco Bell / Subway / Domino / KFC / Popeyes / Tim Hortons / Krispy Kreme / Five Guys / In-N-Out / Shake Shack / Sweetgreen / Cava / Pret a Manger ...`
- Gas + convenience + pharmacy + retail liquor: `Shell / Exxon / Chevron / Sunoco / Valero / Wawa / Sheetz / Circle K / BP / CVS / Walgreens / Rite Aid / Duane Reade / liquor store`
- Non-food retail: `car park / parking / hair salon / nail salon / gym / fitness / spa / florist / jeweler / laundry / dry clean / barber / tobacco / smoke shop / vape / cigars / hardware / auto parts / tire shop / nursery`
- Hotels + commissaries: `hotel / motel / inn / hostel / resort / commissary / naval station / air force / fort ...`
- Generic small-mart: `mini super market / discount market / food & grocery / grocery market / grocery shop / food store`
- Standalone `supermarket`

**Stage 2 — Keep patterns (return false → ACCEPT only if ANY match)**:
- Operational B2B markers: `wholesale / wholesalers / distributor / distribution / distributing / foodservice / food service / purveyors / importers / cash & carry / terminal market / food supply / restaurant supply / smallwares`
- Food-product noun at end-of-name: `foods / meats / seafoods / produce / dairy / poultry / beverages / bakery / fish / fishery / provisions / deli meats / deli products` (with optional `Co./Inc./LLC/Corp./Ltd.`)
- Known B2B brand whitelist: `Sysco / US Foods / PFG / Performance Food / Gordon Food / Reinhart / Baldor / Coosemans / Cuisine Solutions / Jetro / Restaurant Depot / Chef's Warehouse / Costco Business / A. Litteri / Saval / Coastal Sunbelt / Lancaster Foods / Pat LaFrieda / USA Produce / Hunts Point / Maine Avenue Fish / Fulton Fish / Empson / Euro Foods / Fruver`

**Net**: ~15 deny regexes + ~3 keep regexes + 27-name brand whitelist. Bias is toward false negatives (over-rejecting). Adding a legit B2B vendor that's filtered requires either a brand-whitelist code change OR running it through `seed-vendors-manual.php` (which bypasses `isLikelyJunk`).

`config/carafe_vendor_types.php` also constrains the search side: each vendor type lists its valid Google Places `includedTypes` and `text_queries`. After the bug-audit pass (commits `73c1b2f`, `f254155`, `6f333ee`, `ba110b0`, `662bf91`, `daeec3b`), only `['wholesaler', 'warehouse_store', 'butcher_shop', 'asian_grocery_store', 'farm', 'bakery', 'supermarket', 'grocery_store', 'convenience_store', 'food_store']` are used (and `food_store` was removed from `broadline` since it returns Starbucks / 7-Eleven). The previously-invalid Places types (`produce_market`, `seafood_market`, `fish_market`, `market`, `dairy`, `beverages`, `ethnic`, `asian`, `kitchen`) return HTTP 400 and have been deleted from the config.

### Deduplication mechanics

Block keys make pair enumeration O(n) instead of O(n²):
1. `(zip5, name_prefix3)` — catches "Joe's Produce" + "Joe's Produce Co" in the same ZIP
2. `(state_code, name_soundex)` — soundalike catch across the state
3. `geohash6` — ~1.2 km cell, catches same-place-different-spelling
4. **Placekey shortcut** — identical placekey is an instant `auto_merge` at score 1.0 (zero compute)

Scoring: Jaro-Winkler on `name`. Tie-break: distance_m + shared_name_tokens count.

Decision bands:
- score ≥ 0.85 OR (distance ≤ 100m AND shared_tokens ≥ 2) → `auto_merge`
- 0.60 ≤ score < 0.85 → `review` (goes to admin queue)
- score < 0.60 → `reject`

Union-find clustering on auto_merge pairs: A↔B + B↔C = all three merge into the survivor (oldest created_at; deterministic).

Merge: keeps the survivor's `vendors.id`, sets the merged row's `merged_into=survivor_id` (no DELETE — preserves audit trail), re-points all `vendor_locations.vendor_id` to the survivor.

### Classification cascade

`VendorClassifierService::classify` runs a deterministic, auditable cascade:

| Step | Trigger | Confidence | Example |
|---|---|---|---|
| 1. Brand-name hit | Name matches `BRAND_MAP` | 95% | "Sysco of Baltimore" → broadline |
| 2. Strong primaryType | Google primaryType ∈ `TYPE_DIRECT_MAP` | 85% | `butcher_shop` → meat |
| 3. Generic primaryType + name keyword | primaryType ∈ generic set + name matches `NAME_KEYWORD_MAP` | 70% | `wholesaler` + "meat" in name → meat |
| 4. Generic only | primaryType in generic set, no keyword | 40% | `wholesaler` alone → broadline (safe fallback), flag for review |

Every decision writes:
- `vendors.type`
- `vendors.classification_confidence`
- `vendors.classification_signals_json` — the trail of which step matched + which evidence string (auditable when an operator overrides)
- `vendors.classification_needs_review = 1` if confidence < 60

### Admin pages

#### CarafeAdminHome (`/admin/carafe`)
- Grant banner — references `config/google_places_grant.php`, signals that the Google Places storage exception is active (legal gate §6 — full payload storage requires written grant; without it, fall back to place-id-only)
- Queue counts (dedupe + classify pending, click-through to /review)
- Recent campaigns (last 10) — name, status badge, density, enrich_policy, tile_count, spent_usd
- Pipeline + safety cards (docstring of the 3-stage chain and the cost/rate-limit controls)

#### SeedCampaignBuilderPage (`/admin/carafe/campaigns/new`)
- Bbox (lat/lng min+max) — manual entry; future: draw on a map
- Vendor types multiselect (from `VENDOR_TYPES` static catalog in `frontend/src/api/carafe.ts`)
- Enrich policy radio: `all / priority_types / on_demand`
- Density profile: `rural / suburban / dense / mixed`
- Budget cap (USD, optional)
- **Live cost estimator** — debounced 400ms, posts to `/api/admin/seed-campaigns/estimate`, displays `{low, expected, high}` and per-SKU breakdown
- **Free-tier badge** ("free tier covers it", commit `3d89f7a`) — surfaces when `expected ≤ freeRemaining()`
- "Create & run" + "Save draft"

#### SeedCampaignDetailPage (`/admin/carafe/campaigns/:id`)
- Status badge (draft / estimating / approved / running / paused / done / failed / cancelled)
- Estimate preview + spent_usd
- Tile counters: total / done / failed / running / queued (real-time poll every 5s)
- Vendor count
- Action buttons:
  - **Run** — approve + materialize + spawn worker via proc_open
  - **Pause** (with reason)
  - **Resume** — spawn worker (status flips back to running)
  - **Cancel** — skip remaining tiles
  - **Kick** — spawn worker without status change (re-drain queue if it stalled)
  - **Enrich** — run `PlacesEnrichService::enrichCampaign` per the campaign's policy
  - **Resweep** — call `/delta` first (pre-flight summary), then `/resweep`
- Stall-safe loading screen with build-stamp + escape hatch (commit `7a00bcb`)

#### ReviewQueuePage (`/admin/carafe/review`)

Two tabs (query param `?kind=dedupe|classify`):

**Dedupe tab** — rows from `vendor_dedupe_pairs WHERE decision='review' AND reviewed_at IS NULL`:
- Left/right vendor side-by-side (name, address, phone, primary_type)
- Score, distance_m, shared_name_tokens, block_key_hit
- Actions: Merge (promote to auto_merge + apply via union-find), Reject, Defer

**Classify tab** — rows from `vendors WHERE classification_needs_review=1 AND classification_reviewed_at IS NULL`:
- Vendor (name, address)
- Current type, confidence, signals_json (audit trail of cascade)
- Actions: Approve (clear flag), Update (override type/category)

---

## Carafe — GreenDock outbox

Spec §1a Pipe B — when an operator runs a vendor comparison + clicks "Request quote", Carafe emits an HMAC-signed webhook to GreenDock.

### Flow

```
Operator → Comparison ranks vendors → "Request quote"
  ↓
LeadController::create
  ↓ INSERT INTO comparison_requests  (audit trail, org-private)
  ↓ INSERT INTO supplier_leads       (outbox, status='queued')
  ↓
LeadController::emit (manual button OR async dispatcher)
  ↓ LeadFunnelService::emit
  ↓ WebhookDispatcher::fanout (HMAC-signed)
  ↓
GreenDock receives webhook
  ↓ supplier_leads.status='emitted', webhook_attempts++
  ↓
GreenDock ACK callback
  ↓ supplier_leads.status='acknowledged', external_ref=<GreenDock ID>
  ↓
Eventually
  ↓ supplier_leads.status='closed_won' | 'closed_lost'
```

### Data model

`comparison_requests` — audit, private:
```
id, organization_id, restaurant_id (nullable),
category, region,
basket_json,           -- snapshot of items compared
vendor_ids_json        -- which vendors returned in the comparison
```

`supplier_leads` — outbox:
```
id, organization_id, restaurant_id, comparison_id, vendor_id, is_affiliated,
contact_name, contact_email, contact_phone, message,
basket_json,
status,                -- queued / emitted / acknowledged / closed_won / closed_lost
webhook_attempts, webhook_last_at, webhook_last_code, external_ref,
created_at
```

### Enforcement

`LeadFunnelService` is the ONLY file allowed to INSERT into `supplier_leads`. Grep-test in `tests/DataWall/DataWallTest.php`:

```
\binsert\b.+supplier_leads
```

…must only match in `src/Services/LeadFunnelService.php`. CI fails if any other file inserts.

### Webhook payload

```json
{
  "lead_id": 1234,
  "organization_id": 7,
  "restaurant_id": 42,
  "comparison_id": 91,
  "vendor": {
    "id": 5567,
    "name": "Coastal Sunbelt Produce",
    "is_affiliated": 0
  },
  "contact": {
    "name": "...",
    "email": "...",
    "phone": "..."
  },
  "message": "...",
  "basket": [...],
  "created_at": "2026-05-27T..."
}
```

HMAC signature header (`X-Smappen-Signature`) is per-subscription secret in `webhook_subscriptions.secret`. Retry semantics still single-shot — `webhook_attempts` is tally-only, no exponential backoff yet (carryover from v4 known issue).

---

## Plan enforcement + freemium scaffolding

**Per durable user directive: no restrictions are active on the free tier.** All cells in the feature matrix evaluate to `true` for every plan. The scaffolding is in place so individual flags can flip later without code changes elsewhere.

- `config/plans.php` — plan metadata + feature matrix per plan + trial config + dunning grace
- `src/Core/Middleware/PlanGate.php` — `PlanGate::feature($featureName)` + `PlanGate::quota($limit, $usageProvider)` + `cheapestPlanWith($feature)`
- `frontend/src/components/billing/UpgradeGate.tsx` — wrapper component

Plan IDs: `free`, `starter`, `pro`, `team`, `enterprise`. Trial target: `pro`, 14 days.

---

## Onboarding + activation funnel

- **`activation_metrics`** table (one row per user): `signed_up_at`, `first_area_at`, `first_demographic_at`, `first_export_at`, `first_share_at`, `first_report_at`, `returned_in_week_2`, `health_score`. **New for Carafe**: tracked in `restaurants` activation columns (`first_recipe_at`, `first_recommendation_at`, etc. from migration 021).
- **Auto-stamps from controllers** (set on first occurrence via `INSERT … ON DUPLICATE KEY UPDATE col = COALESCE(col, VALUES(col))`):
  - `AreaController::store` → `first_area_at`
  - `DemographicsController::show` → `first_demographic_at`
  - `ExportController::exportAreas` → `first_export_at`
  - `ReportController::generate` → `first_report_at`
- **`POST /api/onboarding/activate`** for any frontend-driven step the backend can't observe

### Smappen first-run wizard (`FirstRunWizard.tsx`)

3-step modal: use-case picker → address → auto-generated 15-min isochrone + AnimatedNumber population. Gated on `onboarding_flags.wizard_complete`. Opens automatically when the user has no flag AND the project area count is 0. "Skip" also stamps the flag so dismissive users aren't pestered.

### Carafe first-run wizard (`CarafeFirstRunWizard.tsx`) — ★ NEW IN v6

Lives at `frontend/src/components/onboarding/CarafeFirstRunWizard.tsx`. Mounted via `CarafeOnboardingGate.tsx` (was a stub in v5, now real). The gate fetches `/api/onboarding/state` when the user lands on any `/app/restaurants/*` surface and opens the wizard when **both** conditions hold: `flags.carafe_wizard_complete` is unset AND `org_restaurant_count === 0`. The second condition prevents the modal from popping for invitees or returning users — they land on data directly.

**Step 1** — use-case picker (no emojis): three cards, each 64px tall:
- `Store` "I run a restaurant" — "Wire up your menu and start finding margin"
- `MapPin` "I'm opening a new spot" — "Study a neighborhood before you sign a lease"
- `Compass` "Just curious about Carafe" — "Tour with a fully-loaded sample restaurant"

Cards stagger-animate in. Icon tile uses `--carafe-accent-light`. Hover transitions to `--carafe-accent-50` background + `--carafe-accent` border.

**Step 2** — path-specific:
- `existing` / `planning` → real input flow: restaurant-name input + Google Places autocomplete address input (uses the existing `.cf-wizard-pac` body-mounted dropdown styles). Two CTAs: "Try sample first" (secondary) and "Create restaurant" (primary, brand-accent). Primary stays disabled until the user picks from suggestions, with `✓ Picked from suggestions` confirmation below.
- `exploring` → no inputs, just a value-prop card listing what comes with the sample restaurant (35-item menu with plate costs already computed · 90 days of synthetic POS sales · open recommendations · sample USDA-region COGS attribution). Single CTA: "Try with sample → ".

**Step 3 — reveal**:
- Sample paths: `<MoneyStat size="xl" tone="positive">` "$4,280 — We found these moves for you" headline + 3 `<RecommendationCard density="compact" readonly>` rows stagger-animating in (fixture: Carbonara $1,640/mo price raise, Bruschetta $820/mo reposition, Calamari $480/mo cut; sum = $4,280 matches the headline). Single CTA "Open the war-room → " navigates to `/app/restaurants/{id}`.
- Real-restaurant paths: calmer "Your war-room is ready" with a next-steps note about POS + recipes.

**Step indicator** — three pill chips in `--carafe-accent`. Each chip is a `<button>` with `aria-label="Step N, current"` or `"go back"` + `aria-current="step"`. Past steps clickable to rewind; step 3 is terminal (restaurant created).

**Dismissal paths** stamp `/api/onboarding/dismiss-wizard` with `wizard: "carafe"` and a `path`: `skipped_step_1/2/3` (backdrop click or X), `completed_sample`, `completed_real_manual`. Wizard state on step 1→2 saved via `/api/onboarding/wizard-state` so reloads mid-flow resume cleanly.

**Mobile-first** — bottom-sheet alignment on phones (`items-end sm:items-center`), `w-[min(540px,100vw-1rem)]`, `max-h-[calc(100vh-1rem)]`. Every interactive element ≥44px. "Try with sample" path completes in ≤10s (one POST `restaurantsApi.cloneSample()` + one navigation).

---

## Visual design system

### Typography
- **Nunito** webfont, weights 400 / 500 / 600 / 700 / 800 / 900
- Loaded once in `styles.css` via Google Fonts
- No competing font families
- (v6) PDF reports use the same Nunito family via `\TCPDF_FONTS::addTTFfont()` when `storage/fonts/Nunito.ttf` is deployed; falls back to Helvetica when not — `PdfReportService::generateMoneyFound` attempts the load and gracefully degrades

### Color tokens (CSS variables on `:root`)

**Smappen base scale** (unchanged from v5):
- **Brand**: `--brand: #7848BB`, `--brand-dark: #6B37A6`, `--brand-light: #EDE5F7`, `--brand-50: #F6F2FB`
- **CTA**: `--cta: #E53935`, `--cta-dark: #D42A2A`
- **Ink scale**: `--ink: #1A1A2E`, `--ink-2: #2D2D44`, `--body: #4A4A5A`, `--slate: #6B6B7B`, `--muted: #8E8E9A`
- **Borders + bgs**: `--line: #D1D1DB`, `--line-soft: #E8E8EE`, `--bg-panel: #F3F3F7`, `--bg: #F9F9FB`
- **Nav tokens**: `--nav-bg`, `--nav-border`, `--nav-text`, `--nav-text-strong`, `--nav-active-bg`, `--nav-active-fg`, `--nav-ring` (#a78bfa) — single point of retheme for every authenticated surface

**★ Carafe semantic layer (v6)** — added on top of, not replacing, the Smappen scale. Lives in the same `:root` block:

| Token | Light | Dark | Used for |
|---|---|---|---|
| `--money-positive` | `#0F8A4A` | `#4ADE80` | MoneyStat positive tone, DollarDelta up, status "synced"/"hit" |
| `--money-positive-bg` | `#E6F4EC` | `#0E2E1F` | Status pill backgrounds (Accepted, etc.) |
| `--money-negative` | `#B14242` | `#F4A8A8` | MoneyStat negative tone, DollarDelta down, MarginPctCell "Low" |
| `--money-negative-bg` | `#FBECEC` | `#3A1A1A` | Stale-COGS banner background, over-staff hour bands |
| `--money-neutral` | `#1A1A2E` | `#F3F4F6` | MoneyStat default tone (matches `--ink`) |
| `--money-neutral-bg` | `#F1F1F5` | `#1F2937` | Neutral money chip background |
| `--carafe-accent` | `#C2541A` | `#F5A77A` | Carafe brand accent — pairs with Smappen violet without colliding; used in CarafeFirstRunWizard primary CTAs, icon tiles |
| `--carafe-accent-dark` | `#A6440E` | `#E78D5C` | Hover/active state of accent |
| `--carafe-accent-light` | `#FBE6D5` | `#3A2418` | Icon-tile background paired with accent foreground |
| `--carafe-accent-50` | `#FDF3EA` | `#2A1B12` | Wizard step-2 exploring value-prop card background |
| `--fresh-fresh` | `#0F8A4A` | `#4ADE80` | FreshnessChip fresh state |
| `--fresh-fresh-bg` | `#E6F4EC` | `#0E2E1F` | (matching) |
| `--fresh-aging` | `#92670E` | `#F5C16C` | Aging state; warn tone on bands and CogsStaleBanner |
| `--fresh-aging-bg` | `#FBEFD2` | `#2E2210` | Under-staff hour bands, aging chip body |
| `--fresh-stale` | `#8A4A4A` | `#F4A8A8` | Stale state — dot opacity drops to 0.7 to signal "old" |
| `--fresh-stale-bg` | `#F1DEDE` | `#3A1A1A` | (matching) |

**Contrast verified** (full block at the bottom of `styles.css`):

| Pair | Ratio | Grade |
|---|---|---|
| `--money-positive` on white | 5.62:1 | AA |
| `--money-negative` on white | 5.72:1 | AA |
| warn `#92670E` on white | 6.42:1 | AAA |
| `--brand` on white | 5.85:1 | AA |
| `--money-positive` on `--money-positive-bg` | 4.92:1 | AA |
| `--money-negative` on `--money-negative-bg` | 5.06:1 | AA |
| warn on `--fresh-aging-bg` | 5.78:1 | AA |
| `--brand` on `--brand-light` | 4.85:1 | AA |
| Dark: `--money-positive` (#4ADE80) on #1f2937 | 7.82:1 | AAA |
| Dark: `--money-negative` (#F4A8A8) on #1f2937 | 6.34:1 | AAA |

### Area palette (24 named colors)
Smappen Violet + 23-color preset row in AreaCard's color picker.

### Heatmap palettes (11)
Viridis, Plasma, Magma, Inferno, Cividis, Smappen Pastel, Smappen Hot, Smappen Cool, RdBu, BrBG, Spectral.

### Menu-engineering quadrant palette (v6, 4 colors — color-blind-safe)
Validated to retain ≥30 ΔE under deuteranopia / protanopia / tritanopia (Coblis/Vischeck): **teal `#0E7C7B`** (stars), **indigo `#4338CA`** (puzzles), **amber `#B45309`** (plowhorses), **slate `#475569`** (dogs). No red/green pair. Quadrant tints are 10% blends of the same hue.

### Daypart palette (24 colors)
One per hour, matched to a sun/moon arc.

### Radii & shadows
- `--radius-sm 6px`, `--radius 10px`, `--radius-lg 14px`, `--radius-xl 16px`
- `--shadow-sm`, `--shadow-md`, `--shadow-lg`, `--shadow-float`

### Focus indicator (v6, global)
2px solid `outline: var(--nav-ring)` with 2px offset + 6px radius on every focusable element (`button`, `a`, `[role="button"]`, `[role="tab"]`, `[role="option"]`, `[role="menuitem"]`, `[tabindex]:not([tabindex="-1"])`). Selector uses `:focus-visible` so mouse clicks don't trigger the ring. Dark mode brightens the ring to `#c4b5fd` for visibility against dark panel backgrounds. Per-page Tailwind `focus-visible:ring-*` utilities continue to override on a case-by-case basis where they exist.

### Skeleton loaders
Global `.skeleton` class with shimmer; AreaList, POI panel, dashboard, project gallery.

**v6 additions**:
- `.skeleton` is now in the `prefers-reduced-motion` gate — shimmer animation pauses for users who've asked the OS not to animate
- Carafe surfaces use layout-shaped skeletons via `frontend/src/components/carafe/CarafeSkeleton.tsx` — exports `SkeletonBlock`, `SkeletonCard`, `SkeletonStatRow`, `SkeletonList`, `SkeletonTable`, `SkeletonChart`, `SkeletonRecCard`. Each helper mirrors the silhouette of the real component (e.g. `SkeletonRecCard` has a 40×40 icon block + dollar-figure block + three button-shaped blocks matching `RecommendationCard` proportions)
- Loading states across MenuPage, CostsPage, LaborPage, GoalsPage, SavedVendorsPage, RestaurantOverviewPage now show layout-shaped silhouettes (no generic gray ladders); all carry `aria-busy="true" aria-live="polite"`

### "Never color-alone" policy (v6)
Status across Carafe pairs **color + icon + verbal label**. Five components documented as canonical:
- `FreshnessChip` — colored dot + relative-time text
- `SyncStatus` — Lucide icon + uppercase state label + token color
- `<StatusBadge>` (goal) — kind icon (`CheckCircle2`/`TrendingUp`/`AlertTriangle`/`AlertOctagon`/`TrendingDown`) + verbal label (`Hit`/`On track`/`At risk`/`Off track`/`No data`)
- `CogsStaleBanner` — `AlertTriangle`/`Info` icon + headline + body copy
- `MarginPctCell` — `AlertTriangle` + percentage + "Low" pill

Map pins encode vendor type by color but the side panel + list rows + ServesPanel rows carry the same data in text. Affiliated badge is icon + word + color; never color-only.

### Density target
"Real usefulness over visual sophistication." Designed for any-age operator on any screen.

### Anti-slop rules (per CLAUDE.md)
No purple→pink gradients, no glassmorphism (unless context already has it), no Poppins/DM Sans/JetBrains Mono, no 24px+ rounded corners on cards, no emojis in copy.

---

## Animation system

Global classes in `styles.css`:
- `.panel-slide-right/left/up/down` — cubic-bezier ease-out for floating panels (220ms)
- `.card-expand` — transform-origin top, auto-flips to bottom when portaled above trigger (160ms)
- `.stagger-in` — per-row delay via inline `--stagger-i` CSS var (28ms × index, capped at 8 rows × 30ms ≈ 280ms total max-tail entrance + 280ms per-row entrance)
- `.fade-in` — hover-revealed buttons
- `.sparkle-pulse` — featured CTAs
- `.hover-lift` — toolbar buttons
- `.brand-logo-tile` — gradient sweep + shimmer
- `.spinner`, `.progress-bar`, `.page-loading-logo`, `.shimmer-text`
- `.polygon-glow-pulse` — selected polygon halo
- **(v5)** `.toolbar-card-morph` (2s blob morph from toolbar icon to right-panel card, Apple-glass bezier `cubic-bezier(0.34, 1.56, 0.64, 1)`, clip-path inset prevents seam — `0d1271a`, `817b64e`, `daeec3b`). Re-fires on every tile/tab switch within the right panel.

**★ Carafe motion vocabulary (v6)** — added a small, named, all-gated set on top of the global classes. Operator-serious tone: nothing decorative, every functional transition ≤250ms, every animation in the `prefers-reduced-motion` gate:

| Primitive | Where | Duration | What it does |
|---|---|---|---|
| `.carafe-route-fade` | Workspace tab swaps | **160ms** | 4px upward translate + opacity, on `<main>` keyed by `pathname` |
| `.stagger-in` (existing) | Carafe lists (recs, vendors, goals, slow-windows) | 280ms entrance + 30ms × index, capped at 8 | Per-row slide-from-left cascade |
| `.card-expand` (existing) | Why-this inline expander on `<RecommendationCard>` | 160ms | Transform-origin scale + translate |
| `.rec-decision-check` | Accept feedback on `<RecommendationCard>` | **240ms** | Restrained scale-pop on the checkmark (tightened from original 320ms to hit the 250ms ceiling) |
| `.rec-card-collapse` | Top Move cycling, rec list dismissal | **220ms** | Max-height + opacity + margin/padding collapse so the next rec slides up into the same slot (tightened from 280ms) |

Plus `<AnimatedNumber>` (existing) rolls at `240ms` default in Carafe contexts. The `useEffect([value])` dependency ensures the animation only fires when the value *actually* changes — a poll returning the same number never re-triggers (verified by `React.Object.is` comparison on primitives).

All Carafe-specific primitives are listed in the `@media (prefers-reduced-motion: reduce)` block at the bottom of `styles.css` (line ~947). The decision-check + card-collapse pair has its own gate ten lines above because `.rec-card-collapse` needs different "kill" behavior — even with motion off, it must still `max-height: 0` the card so the next rec appears.

Stagger applied (with `style={{ '--stagger-i': i }}`) on:
- MenuPage recommendations
- VendorMapPage list-view rows + ServesPanel result rows
- SavedVendorsPage grid
- GoalsPage cards (via new `staggerIndex` prop on `<GoalCard>`)
- LaborPage slow-window cards
- CarafeFirstRunWizard step-1 use-case cards and step-3 reveal recs

**What's intentionally absent**: no bounce springs, no celebration confetti, no hover-lift on data cards, no scroll-triggered choreography. The only spin is the live `<Loader2 className="animate-spin">` inside `<SyncStatus>` for `connecting` / `syncing` states — genuinely indicating in-progress work.

All honor `prefers-reduced-motion`.

---

## Dark mode end-to-end

Toggled via `data-theme="dark"` on `<html>`. Pre-paint script inlines `localStorage.getItem('smappen-theme')` so there's no flash. Set via Profile settings (`user.theme`), or `data-theme` attribute, or system preference fallback.

**Coverage**:
- `:root[data-theme="dark"]` overrides for every `bg-white`, `bg-slate-50/100`, `bg-violet-50/100`, `bg-emerald-50`, `bg-rose-50`, `bg-amber-50`, `bg-blue-50`
- Text overrides for slate-300 through 900
- Brand-ink inline-style hook (`[style*="color:#1A1A2E"]` re-mapped to `#f3f4f6`)
- Input + textarea borders + bgs
- Map style auto-switches to dark Google Maps style when in dark mode
- Dashboard + project gallery use Tailwind `bg-white` so they pick up the dark override
- **New in v5**: Carafe surfaces (RestaurantWorkspaceLayout + VendorMapPage + CarafeAdminLayout) all use the same Tailwind class system, so they pick up dark mode automatically.

---

## Performance & caching

### Frontend bundle (gzipped sizes after deploy on `e667348`)

| Chunk | Approx size | Notes |
|---|---|---|
| `index-*.js` | ~120 KB | main app (grew slightly from Carafe surfaces) |
| `charts-*.js` | ~101 KB | recharts |
| `react-vendor-*.js` | ~54 KB | react + react-dom + router |
| `gmaps-*.js` | ~40 KB | @react-google-maps/api + markerclusterer |
| `state-*.js` | ~14 KB | zustand + RQ |
| 11 lazy advanced-tab chunks | 1-5 KB each | one per advanced tab |
| Restaurant pages | ~25 KB each lazy | RestaurantOverview / Menu / Recipes / Costs / Labor / Goals |
| Vendor pages | ~35 KB total lazy | VendorMapPage / VendorsPage / SavedVendorsPage |
| Carafe admin | ~20 KB total lazy | SeedCampaignBuilder + Detail + List + ReviewQueue |

### Backend caching
- **Demographics** cached on `areas.demographics_cache` (JSON) for 30 days
- **POI** cached on `poi_cache` (md5 of area + caller params) for 48h. **New in v5**: `VendorCacheService` coalesces concurrent fetches with the same key (lock + wait) so two simultaneous users searching the same bbox don't pay twice (`3f3ac75`)
- **Geocode** cached in `cache` (Redis primary, MySQL fallback) for 1 year
- **Heatmap tiles** cached in `heatmap_tile_cache` for 7 days
- **Reach** cached in `reach_cache` for 30 days
- **Place details (Carafe)** — three-tier cache (hot 6h / warm 24h / cold 7d) on `vendor_google_details.*_fetched_at`
- **Demographics cache_version** (`dbae88a`) — when ingestion logic changes, bump cache_version → auto-invalidate all cached areas

### Hot-path optimizations
- Heatmap state ST_AsGeoJSON precision: state p=2, county p=3, tract p=4 (~25% payload reduction)
- Heatmap tract cap: 3000 (was 10K — OOM at 84K-tract scale)
- MCLP: `MBRIntersects(geometry, bbox_buffer)` precedes `ST_Distance_Sphere` — uses SPATIAL INDEX
- MCLP candidate cap: 500
- Places nearby: tile into 5 sub-calls when saturated
- Places text: bbox `locationRestriction` + recursive tiling (was 60-result cap, even in dense urban — `b3769d2`, `6adff0f`)
- SSE presence stream short-circuits on empty peer list
- PHP-FPM pool 20 workers
- Apache `mod_deflate` gzips heatmap responses (12MB → ~2MB)
- Vite manualChunks split
- **New in v5**: `AreaController` casts numeric columns out of PDO string-land before shipping to frontend — fixed N+1 + decimal-string drift (`9a6caf3`, `3138cec`, `ebe9453`, `3e840db`)

---

## Security & auth

- **JWT HS256 + jti + revoked_tokens + tokens_invalid_before** for per-token and bulk revocation
- **CSRF** — N/A (stateless JWT, no session cookies — only for the OAuth state token)
- **Rate limits** per api_name in `api_usage_log` + `X-RateLimit-*` headers + `Retry-After` on 429. 14 named profiles (see [Backend surface](#backend-surface--controllers--endpoints))
- **Prepared statements** everywhere
- **Multi-tenant scoping** — every business-scoped query verified by org_id check
- **Carafe data wall** — `tests/DataWall/DataWallTest.php` greps the source to enforce PrivateData / MarketData / SharedRef segregation
- **Stripe webhook**: HMAC signature verified at controller + service layers
- **POS webhook (callback)**: state token validated against session
- **Outbound webhook delivery**: HMAC signed with per-subscription secret
- **CRM + POS tokens**: AES-256-CBC at rest, IV per-row, key derived from `APP_KEY`
- **Security headers on every response** (set in `public/index.php`):
  - `Strict-Transport-Security: max-age=31536000; includeSubDomains`
  - `X-Frame-Options: SAMEORIGIN` (relaxed to `*` for `/api/public/*` embed surfaces only)
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy: geolocation=(self), microphone=(), camera=()`
  - `Content-Security-Policy: frame-ancestors 'self'` (relaxed for embeds)
- **CORS**: same-origin only by default; preflight short-circuits at 204
- **Stripe webhook idempotency**: `stripe_webhook_events` table

### Carafe-specific security notes

- **Vendor reviews** are gated by `verification_strength`: caller must have a restaurant (and ideally a connected POS in the same region) to submit, or admin manual-review approval. Anonymous reviews are not accepted
- **Vendor claims** require admin approval before the claimant can edit listings or respond to reviews
- **Supplier leads (outbox)** — `LeadFunnelService` is the only insertion point; data wall test enforces
- **Affiliation disclosure** is on the vendor row (`is_affiliated`), not the comparison response — UI surfaces a badge regardless of who's calling

---

## Reliability & deploy resilience

### Service worker — KILLED in v4 (still dead in v5)
`/app/sw.js` is a self-uninstalling kill-switch. `main.tsx` no longer registers a SW; on every load it unregisters any existing SW + purges caches.

### Stale-chunk auto-recovery
Three guards (unchanged from v4):
1. **`ErrorBoundary`** detects `Failed to fetch dynamically imported module` → purge + unregister + reload (once, sessionStorage-guarded)
2. **`main.tsx` window-level `unhandledrejection`** catches non-React.lazy dynamic imports
3. Calm "Updating to latest version…" spinner

### Orphan-overlay sweeper
`App.tsx` runs `useOrphanOverlayCleanup()` on every navigation. Sweeps body for orphan modal-backdrops React already discarded.

### Isochrone failure UX
60-min hard cap, 422 with friendly hint, ORS error-code translation.

### Territory + MCLP status codes
"Not enough census coverage" → 422 (was 500). "Too many candidates" → 422.

### Carafe-specific reliability

- **Stuck tile recovery** — `seed-resweep.php --stuck-after=1800` flips tiles in `running` status > 30 min back to `queued`. Tile resumes from scratch (worker is idempotent via ON DUPLICATE KEY UPSERT on `google_place_id`).
- **Budget cap pause, not fail** — when `BudgetCapExceededException` fires, the campaign pauses and the tile re-queues. Operator can raise the cap + resume; no work is lost.
- **Result hash delta** (§12.3) — re-sweep skips dedupe + classify entirely if the new `result_id_hash` matches the previous run. Saves cost when re-sweeping a stable region.
- **PHP_BINARY resolution fix** (`e667348`) — `proc_open` for the worker spawn now resolves `php` explicitly, not via `PHP_BINARY` (which evaluates to `php-fpm` inside FPM and can't execute CLI scripts).
- **Loader-options crash fix** (`dc18d9d`) — `react-google-maps/api`'s Loader can throw "Loader must not be called again with different options" if a page mounts the Loader with one config and another with another. Now guarded.
- **Stall-safe admin loading** (`7a00bcb`, `2d83163`) — `/admin/carafe` direct-hit no longer hangs; shows a build-stamp escape hatch.

---

## Infrastructure & deploy

### Local dev
- `frontend/`: `npm run dev` → Vite on http://localhost:5173 with `/api/*` proxied to `http://localhost:8080`
- Backend dev: `php -S localhost:8080 -t public public/index.php` (or use the docker-compose stack)
- No service worker in dev

### Production droplet (`143.244.144.7`)
- `/var/www/smappen` — code (`git pull` to deploy)
- `/var/www/smappen/.env` — secrets (gitignored)
- `/var/www/smappen/storage/exports`, `/storage/uploads`, `/storage/logs`
- `/var/www/smappen/backups/` — `mysqldump` snapshots

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
- **Carafe cron — none yet scheduled**. Workers exist; operator runs them on demand via SSH or via the admin "Run" button (which spawns via proc_open).

### Failover plan (`docs/failover.md`)
Manual fallback: secondary droplet with identical Apache + PHP-FPM config, `mysqldump` restore, DNS cutover. Operational; not auto-failover.

### Logs
- PHP: `/var/www/smappen/storage/logs/php-error.log`
- Apache: `/var/log/apache2/smappen-{access,error}.log`
- FPM: `/var/log/php8.3-fpm.log`
- Monolog: `/var/www/smappen/storage/logs/app.log` (config-gated)

### PHP-FPM tuning
```
pm = dynamic
pm.max_children      = 20
pm.start_servers     = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
```
**New in v5**: memory_limit bumped (`ebe9453`) — Carafe seeding workers can hold larger result sets in memory; raised limit to absorb without OOMs.

### Apache `mod_deflate`
Enabled for `text/*`, `application/javascript`, `application/json`.

---

## Testing

### PHPUnit
- `tests/Services/GeoUtilsTest.php` — bbox, point-in-polygon, haversine
- `tests/Services/AnalogServiceTest.php` — similarity scoring
- **New in v5**: `tests/DataWall/DataWallTest.php` — grep-based enforcement that:
  - `App\MarketData\*` never reads from `App\PrivateData\*` tables (vendor directory can't see restaurant POS)
  - Only `LeadFunnelService` inserts into `supplier_leads`
  - Only `App\PrivateData\*` repositories touch restaurant-private tables
- **New in v5**: Carafe service tests — `VendorDedupeServiceTest`, `VendorClassifierServiceTest`, `SeedEstimatorServiceTest` (block-key generation, score banding, cost projection sanity)

### Vitest (frontend)
- Store-rehydration smoke test
- (Expansion still planned but not in-scope for this audit cycle)

### Manual smoke tests (verified in this audit cycle)
- `/api/health` returns `version: e667348` post-deploy
- All 35 migrations applied (`SELECT COUNT(*) FROM migrations` = 35)
- New routes return 401 without auth, 403 without admin role on `/api/admin/*`
- Carafe estimate endpoint: `POST /api/admin/seed-campaigns/estimate` returns cost projection in < 200ms (no external calls)
- Frontend `tsc -b` clean
- Vite build clean
- AppNav renders identically on `/dashboard`, `/app`, `/app/restaurants`, `/app/vendors`, `/admin/carafe` (no nested-nav regressions)
- Drop-a-pin on `/app/vendors` returns coverage hits within 2s (PostGIS `ST_Contains` on simplified geometry)
- Spawned worker (`seed-tile-worker.php`) terminates cleanly when tile queue empties
- B2B filter regression: name "Whole Foods Market" → rejected; "Sysco of Baltimore" → kept
- Classification cascade: brand-name "Sysco" → broadline at 95%; primary-type-only "wholesaler" → broadline at 40% with `needs_review=1`

---

## Scaffolded but not yet wired

| Feature | Status | What's missing |
|---|---|---|
| Canadian demographics (StatCan) | Service + schema | `scripts/import-statcan-da.php` not written |
| Time-series demographics (ACS history) | Service + schema | `scripts/ingest-demographics-history.php` not written |
| **Carafe cron** | All 8 workers exist and run on demand | No cron entries scheduled — campaigns currently spawn workers via proc_open. Add `*/5 * * * * php seed-tile-worker.php` + chain |
| **Toast + Clover POS adapters** | Class stubs + OAuth scaffold | Need production credentials + first-customer pilot |
| **Vendor claim queue UI** | Backend complete | Claims surface in the generic review queue; no dedicated tab yet |
| **Saved vendor searches alerts** | Schema + read endpoint | Alert dispatcher not built — `alert_on_new=1` does nothing |
| **`/app/vendors/saved` — saved comparisons** | Page exists, lists saved vendors | Saved-comparison workflow (basket + side-by-side snapshot) is the next layer |
| **GreenDock outbox dispatcher** | `LeadController::emit` exists | No async retry queue; webhook is single-shot per emit call |
| **COGS benchmark live ingest** | Schema + service + stub | USDA / GreenDock pipelines not built; `cogs_benchmark` currently has stub data only |
| **Carafe first-run wizard** | None | Manual "Create restaurant" flow only |
| **Vendor coverage from declared territories** | `coverage_type='declared_territory'` enum exists | No UI to draw declared coverage; only isochrone + radius implemented |
| Sample project seed | `projects.is_sample` exists | Need to mark a project as `is_sample=1` on the droplet |
| Activation `returned_in_week_2` + `health_score` | Columns exist | Computation logic not built |
| Embed view counter | `embeds.view_count` exists | Public render path doesn't bump it |

---

## Known issues / open punch list

### Resolved this audit cycle (`ae3e5d3` → `e667348`)

- ✅ Carafe vendor network spec v3 phases 1–10 deployed (755ea39, 1e2f05a, 588a128, 20bc2e0, a28c36c, 05b1595, 5c61422)
- ✅ Carafe Phase 1/2 (POS, plate-cost, recommendations, ROI, planning, goals, labor)
- ✅ AppNav unified across `/app`, `/dashboard`, `/settings`, `/app/restaurants`, `/app/vendors`, `/admin/carafe` (ca34acf, 6568eec)
- ✅ Carafe seeding bug-fix passes (3138cec, ebe9453, 3e840db) — GeoUtils axis-flip, PDO decimal-as-string, N+1 in list view, null safety
- ✅ B2B filter tightening (deny-list expansion, brand whitelist) — multiple commits (6f333ee, ba110b0, 662bf91, f254155, daeec3b)
- ✅ Invalid Places types dropped (`73c1b2f`) + per-call try/catch in tile worker
- ✅ Migration parser robustness (`e177943`, `76510bb`) — strip trailing semicolons from comment lines
- ✅ Stall-safe admin loading (`7a00bcb`, `2d83163`)
- ✅ POI panel caching + reload restore (`3f3ac75`)
- ✅ Places benchmark feature (`c821e5f`)
- ✅ Places text search 60-result cap fixed via bbox `locationRestriction` + recursive tiling (`b3769d2`, `6adff0f`)
- ✅ Heatmap compact bottom-center bar (`0755a72`, `f5627d9`)
- ✅ Area full-edit panel via AreaCreator-as-editor (`bcb8875`, `9a11a00`)
- ✅ Restaurants Google Places autocomplete (`0b9464c`, `f97749a`)
- ✅ Restaurants "Study trade area" auto-isochrone (`c2b36cf`)
- ✅ Loader-options crash fix (`dc18d9d`)
- ✅ RightPanel toolbar morph animation (`0d1271a`, `817b64e`, `daeec3b`)
- ✅ VendorMapPage horizontal filter strip + silence bbox-antimeridian toast (`f7a23c5`)
- ✅ Demographics histogram fake-zero-bracket fix + bolder labels + ingestion fix (`142b845`)
- ✅ AreaController numeric-string casting (`9a6caf3`)
- ✅ Census `cache_version` auto-invalidate (`dbae88a`)
- ✅ PlacesController demographics_cache decoded-array guard (`17bf6ba`)
- ✅ Carafe "free tier covers it" badge (`3d89f7a`)
- ✅ Carafe Run button spawns worker pipeline (`0474c52`)
- ✅ Carafe PHP_BINARY resolution fix (`e667348`) — resolves `php` not `php-fpm`

### Resolved this audit cycle (v6 — `0c7e987`, the Carafe UX design batch)
**No bug fixes per se** — this cycle was a 15-prompt UX-and-tokens batch, not a regression hunt. What it added rather than fixed:
- ✅ Carafe semantic design tokens + dark-mode mates + verified-contrast notes block
- ✅ Three primitives (MoneyStat / DollarDelta / FreshnessChip) wrapping every dollar figure
- ✅ Unified `<RecommendationCard>` across war-room / menu / labor (three densities + readonly variant) with optimistic accept/dismiss + undoStore + 5s undo toast
- ✅ Mobile-first war-room (ROI + Top Move above-the-fold at 390×844; RestaurantSwitcher in AppNav `mobileContext`; sticky chip-rail tabs on phones)
- ✅ Menu-engineering 2×2 chart (custom SVG, color-blind-safe palette, collision spiral, COGS attribution in tooltip, click→`<RecommendationCard>` modal)
- ✅ CostsPage hero hierarchy (band-coded food-cost % with icon + verbal label, BandRuler, OverpayList ranked by `savingsCents`)
- ✅ `<LaborDemandChart>` (24-hour demand area + staffing line + over/under bands + dollar annotations; mobile sideways-scroll + degraded summary tiles)
- ✅ GoalsPage scorecard (StatusBadge + ProgressRing + GoalSparkline with target reference line)
- ✅ `<SyncStatus>` covering all six POS states (not_connected / connecting / syncing / synced / stale / error)
- ✅ `<CogsStaleBanner>` graceful USDA-price degradation (fresh/aging/stale/missing)
- ✅ Layout-shaped skeletons across all Carafe pages
- ✅ Action-oriented empty states with primary CTAs (costs→recipes, labor→shift, goals→new goal, saved-vendors→browse)
- ✅ Accessibility hardening: global `:focus-visible` ring, MenuPage margin-% color-only fix, all icon-only buttons gain `aria-label`, contrast pairs documented
- ✅ Carafe motion vocabulary (≤250ms, all in reduced-motion gate)
- ✅ Vendor coverage polygons with zoom-aware Douglas-Peucker tier picker (backend extension to `VendorCoverageRepository::listForVendor` + new `<VendorCoveragePolygons>` component)
- ✅ `<AffiliatedBadge>` accessible disclosure tooltip (keyboard + SR + Esc close)
- ✅ ServesPanel skeleton + ≤16ms open-before-network
- ✅ Vendor filter strip mobile collapse (Filters disclosure button)
- ✅ Money-found PDF report (`PdfReportService::generateMoneyFound` + `GET /api/restaurants/{id}/reports/money-found.pdf`) — headline parity with `RoiService::monthlySummary`, methodology footnote cites COGS source + measurement window, Nunito with Helvetica fallback
- ✅ "Download report" button on war-room ROI tile
- ✅ Command palette extended to Carafe — globally mounted in App.tsx, surface-aware (restaurant switcher always, per-restaurant tab/sync/PDF/sandbox actions when inside, map items on /app), `g→r` chord pre-filters to restaurant switching
- ✅ CarafeFirstRunWizard (3 steps: use-case → real address or sample → animated reveal with MoneyStat + sample recs); CarafeOnboardingGate fetches `/api/onboarding/state` and opens when `carafe_wizard_complete` unset AND zero restaurants

### Resolved in earlier 2026-05-24 batches (v4)
All v4 punch-list resolutions remain resolved.

### Open

- **No Carafe cron scheduled** — every campaign requires an admin to click Run (which spawns one worker via proc_open). Once that worker exits, no further work runs. Should add cron lines:
  ```
  */5 * * * * php /var/www/smappen/scripts/seed-tile-worker.php --quiet
  */5 * * * * php /var/www/smappen/scripts/seed-dedupe.php --quiet
  */5 * * * * php /var/www/smappen/scripts/seed-classify.php --quiet
  */10 * * * * php /var/www/smappen/scripts/seed-coverage.php --quiet
  */10 * * * * php /var/www/smappen/scripts/seed-resweep.php --all-campaigns --quiet
  0 3 * * * php /var/www/smappen/scripts/seed-enrich.php --refresh-tier=hot --quiet
  0 4 * * 0 php /var/www/smappen/scripts/seed-enrich.php --refresh-tier=warm --quiet
  0 5 1 * * php /var/www/smappen/scripts/seed-enrich.php --refresh-tier=cold --quiet
  0 13 * * 1 php /var/www/smappen/scripts/send-weekly-digest.php
  ```
- **GreenDock outbox webhook retries** — `WebhookDispatcher` is still single-shot for `supplier_leads`; `webhook_attempts` only counts manual retries
- **POS adapter coverage** — only Square is live; Toast + Clover need production credentials + first pilot
- **COGS benchmark currently stubbed** — plate-cost calculation works against stub data; needs real USDA + GreenDock ingest before Carafe goes wide
- **Vendor claim approval UI** — claims surface in the general admin review queue; no dedicated claim-approval tab yet
- **Vendor saved-search alert dispatcher** — table exists, `alert_on_new=1` flag honored at schema level but no worker fires alerts
- **AnalogController generic 500** — flagged in v4; still in the catch-all; not reproduced since axis-order fix
- **No connection timeout on `WebhookDispatcher`** (only `CURLOPT_TIMEOUT => 10`) — set but pre-existing
- **OAuth state token in PHP session** — would break if droplet switches to multiple FPM hosts
- **CRM push doesn't refresh expired tokens** — long-lived integrations will 401 when access tokens expire
- **No connection pool / persistent PDO** — every request opens a new MySQL connection
- **No background scheduler service** — operator must add cron entries manually
- **Sample project for `cloneSample`** — `projects.is_sample=1` column exists; no project marked yet
- **Embed view counter not incrementing**

---

## Bug-fix history (audit cycles)

Each row below is a single deploy that bundled fixes from a focused review.

| Cycle / commit | Theme | Fixes shipped |
|---|---|---|
| `e667348` (this audit) | Carafe worker spawn | Resolve PHP CLI binary explicitly (PHP_BINARY = php-fpm inside FPM) for proc_open |
| `0474c52` | Carafe Run button | Spawn worker pipeline on Run (no cron wait) |
| `daeec3b` | RightPanel polish | Seamless toolbar morph (clip-path inset, Apple-glass bezier, no seam) |
| `662bf91`, `ba110b0`, `6f333ee`, `f254155`, `73c1b2f` | Carafe B2B filtering | Drop standalone 'depot' + 'farmers market' from B2B markers; deny + require B2B marker; broaden deny-list; strict B2B types; drop invalid Places types + per-call try/catch |
| `817b64e`, `0d1271a` | RightPanel polish | Morph card out of toolbar (no gap, scale-based); 2s blob morph on open + tile/tab switch |
| `e177943`, `76510bb` | Carafe migration parser | Strip trailing semicolons from `-- comment` lines (parser was splitting mid-statement) |
| `a81a8ea` | POI panel TS build | Import useEffect (was missing, ts-build red) |
| `3f3ac75` | Places + POI cache | Never pay twice for same Places search; POI panel restored on reload |
| `b3769d2` | Places text search | bbox `locationRestriction` + recursive tiling — fixes benchmark = 60/60/60 cap |
| `3d89f7a` | Carafe builder UX | "Free tier covers it" badge on $0 expected cost |
| `7a00bcb` | Carafe admin loading | Stall-safe loading screen with build-stamp + escape hatch |
| `c821e5f` | Places benchmark | New benchmark endpoint — user area vs. 10 similar-density US metros |
| `2d83163` | Carafe admin direct-hit | Fix infinite loading on direct /admin/carafe hit |
| `755ea39` | Carafe Vendor Network seeding | Full pipeline + admin surface |
| `17bf6ba` | PlacesController null-safety | Guard demographics_cache for already-decoded arrays (500 fix) |
| `6adff0f` | Places size search | Tile sized to area bbox, recursive tile, concentration label |
| `dbae88a` | Demographics cache_version | Auto-invalidate when ingestion logic changes |
| `9a6caf3` | AreaController number casting | Cast numeric columns out of PDO string-land |
| `0c7e987` (v6 cycle) | **Carafe UX design batch (15 prompts, 42 files, 7,068 insertions)** | Carafe semantic tokens · MoneyStat/DollarDelta/FreshnessChip · unified RecommendationCard + optimistic accept/dismiss + undo toast · mobile-first war-room · RestaurantSwitcher + AppNav mobileContext · MenuEngineeringChart 2×2 color-blind-safe · CostsPage band-coded hero + OverpayList · LaborDemandChart · GoalsPage scorecard + ProgressRing + GoalSparkline · SyncStatus 6-state · CogsStaleBanner · layout-shaped skeletons · action-oriented empty states · a11y hardening + global `:focus-visible` · Carafe motion vocab ≤250ms · vendor coverage polygons zoom-aware + AffiliatedBadge disclosure · ServesPanel polish + filter-strip mobile collapse · money-found PDF (`PdfReportService::generateMoneyFound` + new GET route) · CommandPalette globally mounted, surface-aware, `g→r` chord · CarafeFirstRunWizard + real CarafeOnboardingGate |
| `142b845` | Demographics histogram | Drop fake 0-bracket, bolder labels, fix census ingestion |
| `4e5eff6` | AreaCreator UX | Stop reading as 'cut off' against navbar |
| `a84b4e6` | AreaCreator UX | Close on Esc / click-outside, more space, bigger close button |
| `0755a72` | Heatmap UX | Compact bottom-center bar + settings tray |
| `f5627d9` | Map panels | Stop overlap (HeatmapPanel out of LeftPanel column), cleaner AreaCreator header |
| `9a11a00` | Areas create/edit | One-button save, compact colors, typed time/radius, no magnifier |
| `bcb8875` | Areas full edit | AreaCreator-as-editor — drive time + mode + color + opacity + notes inline |
| `c2b36cf` | Restaurants UX | "Study trade area" auto-builds 15-min isochrone on restaurant pin |
| `f97749a` | Restaurants UX | Google autocomplete dropdown fix + lighter create UI |
| `0b9464c` | Restaurants UX | Google Places autocomplete on create + manual entry toggle |
| `ca34acf` | Nav unification | Unify navbar across every authenticated surface |
| `6568eec` | Nav unification | AppNav rides above the map (was map-only navbar) |
| `dc18d9d` | Maps loader | Fix 'Loader must not be called again with different options' crash |
| `f7a23c5` | VendorMap UX | Horizontal filter strip + silence bbox-antimeridian toast |
| `3138cec` | Carafe bug-fix pass 3 | classify-on-no-PMIX false stars, PDO decimal-as-string across private repos |
| `ebe9453` | Carafe bug-fix pass 2 | List view empty, distance to closest covering location, seed UPSERT, infra memory bump |
| `3e840db` | Carafe bug-fix pass 1 | GeoUtils axis-flip root cause, USA Produce visibility, PDO decimal stringification, N+1, null safety |
| `fdf15a3` | seed-vendors-manual | Add Gordon Food Service to chain-seed |
| `5c61422` | Carafe Vendor Network | Map-first national directory, coverage geometry, drop-a-pin who serves me, reviews |
| `05b1595` | Carafe IA | Unified AppNav + RestaurantWorkspaceLayout + war-room + recipe builder + goals/costs/labor |
| `a28c36c` | Carafe Phase 2 (market) | Vendor directory, honest comparison, supplier_leads outbox to GreenDock |
| `20bc2e0` | Carafe Phase 2 (private) | Goals, theoretical food cost, labor + daypart, market-intel relabel |
| `588a128` | Carafe Phase 1 | POS sync, plate-cost, money-quantified recommendations, ROI ledger, planning sandbox, weekly digest |
| `1e2f05a` | Priority items batch | Sample project, CRM refresh, signed OAuth state, webhook retries, embed counter, activation rollup, custom layers UI |
| `ae3e5d3` (v4) | AUDIT v4 refresh | Full refresh post bug-audit batch (a21b00a) |
| `a21b00a` (v4 cycle) | Bug-audit fix batch | 6 spatial axis-order, ACL on AlertsController, 10 curl connect-timeouts, Stripe webhook idempotency, stuck-job sweeper |
| (earlier v4 rows) | (see v4 history) | Stale chunk auto-recovery, isochrone UX, SW kill-switch, PHP-FPM bump, Places > 20 results, growth/onboarding batch, ... |

---

*End of v6 audit. Next cycle should:*
1. *Schedule the Carafe worker cron lines so campaigns don't depend on admin clicks (carryover from v5)*
2. *Replace `cogs_benchmark` stub data with real USDA + GreenDock ingest pipelines — this unlocks honest overpay flags on CostsPage (currently Phase-1 framed against a 35% target; the visual shell stays the same when real data lands)*
3. *Build Toast + Clover POS adapters past the OAuth scaffold (carryover from v5)*
4. *Wire vendor saved-search alerts to the cron (carryover from v5)*
5. *Build a dedicated vendor-claim approval tab in `/admin/carafe/review` (carryover from v5)*
6. *Add an async retry queue for `supplier_leads` webhook delivery (carryover from v5)*
7. *Write `import-statcan-da.php` + `ingest-demographics-history.php` (carryover from v4)*
8. *Seed the sample project on the droplet so `cloneSample()` has source data (carryover from v4)*
9. *Deploy `storage/fonts/Nunito.ttf` to the droplet — `PdfReportService::generateMoneyFound` already detects it via `\TCPDF_FONTS::addTTFfont` and gracefully falls back to Helvetica when absent. With the TTF in place, every Carafe PDF report uses the same Nunito as the rest of the app*
10. *Bring the chunk size warning down — `index-*.js` is 902KB; should `build.rollupOptions.output.manualChunks` Carafe-specific code (RecommendationCard, MenuEngineeringChart, LaborDemandChart) into a separate chunk like the existing `charts` / `gmaps` splits*
11. *Add `frontend/src/components/carafe/__tests__/` covering at minimum: `useRecommendationAction` (optimistic flip + rollback + undo), `MenuEngineeringChart` (quadrant assignment matches backend `classify`), `SyncStatus` (state derivation table), `CogsStaleBanner` (severity boundaries)*
12. *Run an automated axe-style accessibility scan against the six core Carafe surfaces in CI — the manual audit found and fixed the obvious gaps; a scanner would catch the next ones earlier*
13. *Multi-month money-found report — current `generateMoneyFound` accepts a `month` query param but the UI only exposes the current month. Add a month picker to the war-room Download-report flow*
