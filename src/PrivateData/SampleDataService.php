<?php
declare(strict_types=1);

namespace App\PrivateData;

use App\Core\Database;
use App\Services\PlateCostService;
use App\Services\MenuEngineeringService;
use App\SharedRef\CogsBenchmarkRepository;

/**
 * Sample-data orchestrator — builds a fully-populated "Trattoria Verde"
 * restaurant in the caller's org so a first-time operator sees every
 * dashboard module render populated instead of as an empty-state tombstone.
 *
 * Idempotent. Re-running for an org that already has a sample restaurant
 * upserts the same identifiers — no duplicates, no version drift.
 *
 * NEVER calls external services (Square, Places, USDA). The "connected POS"
 * is `pos_integrations.is_sample = 1` and PosService::sync short-circuits
 * on that flag; the COGS lookups go through CogsBenchmarkRepository against
 * the existing stub feed.
 *
 * Lives in App\PrivateData\* because every table it writes is in the
 * private reservoir (pos_integrations, menu_items, recipes, plate_costs,
 * pos_sales, recommendations, labor_shifts, goals). cogs_benchmark sits in
 * SharedRef\* and is read-only here.
 *
 * Data wall: this class must NEVER write to vendors, supplier_leads,
 * comparison_requests, or any App\MarketData\* table. The funnel must stay
 * empty for sample orgs.
 */
class SampleDataService
{
    public const SAMPLE_NAME = 'Trattoria Verde';
    // Match the convention used by seed-cogs-benchmark-stub.php so the
    // bulkLookup region predicate (`region = ? OR region IS NULL`) finds
    // every benchmark row the sample recipes need.
    public const REGION     = 'US';

    private Database $db;

    public function __construct(
        private RestaurantRepository $restaurants,
        private MenuItemRepository $items,
        private RecipeRepository $recipes,
        private PlateCostRepository $plateCosts,
        private PosIntegrationRepository $integrations,
        private PosSalesRepository $sales,
        private RecommendationRepository $recs,
        private LaborShiftRepository $shifts,
        private GoalRepository $goals,
        private CogsBenchmarkRepository $benchmark,
    ) {
        $this->db = Database::getInstance();
    }

    /**
     * Build (or rebuild idempotently) the sample restaurant for one org.
     * Returns: [restaurant_id, created (bool), counts].
     */
    public function seedForOrganization(string $organizationId): array
    {
        $created = false;
        $restaurantId = $this->ensureRestaurant($organizationId, $created);
        $this->ensurePosIntegration($organizationId, $restaurantId);
        // NB: cogs_benchmark stubs the sample recipes need (guanciale,
        // pecorino, San Marzano, etc.) are seeded in migration 041 so the
        // SharedRef "read-only at runtime" wall stays intact.

        $itemMap   = $this->ensureMenuItemsAndRecipes($organizationId, $restaurantId);
        $this->computeDerivedCosts($organizationId, $restaurantId);
        $this->ensurePosSales($organizationId, $restaurantId, $itemMap);
        $this->ensureLaborShifts($organizationId, $restaurantId);
        $this->ensureGoals($organizationId, $restaurantId);
        $this->generateRecommendations($organizationId, $restaurantId, $itemMap);
        $this->stampActivation($organizationId, $restaurantId);

        $counts = $this->countsFor($restaurantId);

        return [
            'restaurant_id' => $restaurantId,
            'created'       => $created,
            'counts'        => $counts,
        ];
    }

    /**
     * Tear down the sample restaurant in FK-safe order. Idempotent — safe
     * to call when no sample exists. Leaves shared cogs_benchmark stub rows
     * untouched (they're harmless shared ref).
     */
    public function removeForOrganization(string $organizationId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id FROM restaurants WHERE organization_id = ? AND is_sample = 1',
            [$organizationId]
        );
        if (!$rows) return ['removed' => false, 'restaurant_ids' => []];

        $ids = [];
        foreach ($rows as $r) $ids[] = (string) $r['id'];

        $this->db->beginTransaction();
        try {
            foreach ($ids as $rid) {
                // Goals + snapshots first (snapshots FK to goals; nothing FKs to either).
                $this->db->query(
                    'DELETE gs FROM goal_snapshots gs
                       JOIN goals g ON g.id = gs.goal_id
                      WHERE g.restaurant_id = ?',
                    [$rid]
                );
                $this->db->query('DELETE FROM goals WHERE restaurant_id = ?', [$rid]);

                // Recommendations.
                $this->db->query('DELETE FROM recommendations WHERE restaurant_id = ?', [$rid]);

                // Labor.
                $this->db->query('DELETE FROM labor_shifts WHERE restaurant_id = ?', [$rid]);

                // Plate costs (FK menu_item_id → menu_items).
                $this->db->query(
                    'DELETE pc FROM plate_costs pc
                       JOIN menu_items mi ON mi.id = pc.menu_item_id
                      WHERE mi.restaurant_id = ?',
                    [$rid]
                );

                // POS sales (FK to menu_items).
                $this->db->query('DELETE FROM pos_sales WHERE restaurant_id = ?', [$rid]);

                // Recipe ingredients then recipes (menu_items.recipe_id needs nulling first).
                $this->db->query('UPDATE menu_items SET recipe_id = NULL WHERE restaurant_id = ?', [$rid]);
                $this->db->query(
                    'DELETE ri FROM recipe_ingredients ri
                       JOIN recipes r ON r.id = ri.recipe_id
                      WHERE r.restaurant_id = ?',
                    [$rid]
                );
                $this->db->query('DELETE FROM recipes WHERE restaurant_id = ?', [$rid]);

                // Menu items, pos integrations, plans, the restaurant itself.
                $this->db->query('DELETE FROM menu_items WHERE restaurant_id = ?', [$rid]);
                $this->db->query('DELETE FROM pos_integrations WHERE restaurant_id = ?', [$rid]);
                // plans_sandbox is optional — guard if the table is absent
                // (covered by 020 migration; should always exist on a current droplet).
                try {
                    $this->db->query('DELETE FROM plans_sandbox WHERE restaurant_id = ?', [$rid]);
                } catch (\Throwable $_) { /* table absent — ignore */ }
                $this->db->query('DELETE FROM restaurants WHERE id = ?', [$rid]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return ['removed' => true, 'restaurant_ids' => $ids];
    }

    public function findExistingForOrganization(string $organizationId): ?array
    {
        $row = $this->db->fetch(
            'SELECT id, name, is_sample FROM restaurants
              WHERE organization_id = ? AND is_sample = 1 AND archived_at IS NULL
              ORDER BY created_at DESC LIMIT 1',
            [$organizationId]
        );
        return $row ?: null;
    }

    // ──────────────────────────── steps ────────────────────────────

    private function ensureRestaurant(string $organizationId, bool &$created): string
    {
        $row = $this->db->fetch(
            'SELECT id FROM restaurants
              WHERE organization_id = ? AND is_sample = 1 AND name = ?
              LIMIT 1',
            [$organizationId, self::SAMPLE_NAME]
        );
        if ($row) return (string) $row['id'];

        $created = true;
        // Fairfax, VA — Northern Virginia, fits the NoVA Italian framing.
        return $this->restaurants->create($organizationId, [
            'name'      => self::SAMPLE_NAME,
            'address'   => '4023 Chain Bridge Rd, Fairfax, VA 22030',
            'lat'       => 38.8462,
            'lng'       => -77.3064,
            'timezone'  => 'America/New_York',
            'region'    => self::REGION,
            'cuisine'   => 'italian',
            'is_sample' => true,
        ]);
    }

    private function ensurePosIntegration(string $organizationId, string $restaurantId): void
    {
        $existing = $this->db->fetch(
            'SELECT id FROM pos_integrations WHERE restaurant_id = ? AND provider = ?',
            [$restaurantId, 'square']
        );
        // Synthetic placeholder — never decrypted by anything (PosService::sync
        // short-circuits on is_sample before it would try). The cipher text
        // is a marker, NOT a real token.
        $marker = base64_encode('sample-' . bin2hex(random_bytes(8)));
        $iv     = bin2hex(random_bytes(16));
        $meta   = json_encode([
            'merchant_id' => 'sample-merchant',
            'scopes'      => ['ITEMS_READ', 'ORDERS_READ', 'MERCHANT_PROFILE_READ'],
            'note'        => 'synthetic — no Square tokens stored',
        ]);

        if ($existing) {
            $this->db->query(
                'UPDATE pos_integrations
                    SET is_sample = 1,
                        access_token_enc = ?,
                        refresh_token_enc = NULL,
                        token_iv = ?,
                        expires_at = NULL,
                        meta_json = ?,
                        last_synced_at = NOW(),
                        connected_at = COALESCE(connected_at, NOW())
                  WHERE id = ?',
                [$marker, $iv, $meta, $existing['id']]
            );
            return;
        }
        $this->db->query(
            'INSERT INTO pos_integrations
                (id, organization_id, restaurant_id, provider, is_sample,
                 access_token_enc, refresh_token_enc, token_iv, expires_at,
                 meta_json, connected_at, last_synced_at)
             VALUES (?, ?, ?, "square", 1, ?, NULL, ?, NULL, ?, NOW(), NOW())',
            [Database::uuid(), $organizationId, $restaurantId, $marker, $iv, $meta]
        );
    }

    /**
     * Idempotently upsert the menu items + recipes. Returns:
     *   [external_id => ['id'=>menu_item_id, 'price_cents'=>int, 'category'=>string]]
     * so the rest of the seeder can look items up by stable slug.
     */
    private function ensureMenuItemsAndRecipes(string $organizationId, string $restaurantId): array
    {
        $menu = $this->menuBlueprint();

        $out = [];
        foreach ($menu as $slug => $row) {
            $recipeId = null;
            if (!empty($row['ingredients'])) {
                $recipeId = $this->upsertRecipe($organizationId, $restaurantId, $slug, $row['name'], $row['ingredients']);
            }
            $menuItemId = $this->upsertMenuItem(
                $organizationId, $restaurantId, $slug,
                $row['name'], $row['category'], $row['price_cents'], $recipeId
            );
            $out[$slug] = [
                'id'          => $menuItemId,
                'price_cents' => $row['price_cents'],
                'category'    => $row['category'],
                'name'        => $row['name'],
            ];
        }
        return $out;
    }

    private function upsertRecipe(string $organizationId, string $restaurantId, string $slug, string $name, array $ingredients): string
    {
        $externalId = 'sample:' . $slug;
        $row = $this->db->fetch(
            'SELECT id FROM recipes WHERE restaurant_id = ? AND external_id = ? LIMIT 1',
            [$restaurantId, $externalId]
        );
        if ($row) {
            $recipeId = (string) $row['id'];
            // Clear + re-insert ingredients so re-seeds reflect blueprint changes.
            $this->db->query('DELETE FROM recipe_ingredients WHERE recipe_id = ?', [$recipeId]);
        } else {
            $recipeId = Database::uuid();
            $this->db->query(
                'INSERT INTO recipes (id, organization_id, restaurant_id, name, external_id, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$recipeId, $organizationId, $restaurantId, $name, $externalId, 'Sample recipe — seeded by SampleDataService.']
            );
        }
        foreach ($ingredients as [$key, $qty, $unit]) {
            $this->db->query(
                'INSERT INTO recipe_ingredients (id, recipe_id, ingredient_key, qty, unit, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, NULL, NOW())',
                [Database::uuid(), $recipeId, $key, $qty, $unit]
            );
        }
        return $recipeId;
    }

    private function upsertMenuItem(
        string $organizationId,
        string $restaurantId,
        string $slug,
        string $name,
        ?string $category,
        int $priceCents,
        ?string $recipeId
    ): string {
        $externalId = 'sample:' . $slug;
        $row = $this->db->fetch(
            'SELECT id FROM menu_items WHERE restaurant_id = ? AND external_id = ? LIMIT 1',
            [$restaurantId, $externalId]
        );
        if ($row) {
            $this->db->query(
                'UPDATE menu_items
                    SET name = ?, category = ?, price_cents = ?, recipe_id = ?, is_active = 1, updated_at = NOW()
                  WHERE id = ?',
                [$name, $category, $priceCents, $recipeId, $row['id']]
            );
            return (string) $row['id'];
        }
        $id = Database::uuid();
        $this->db->query(
            'INSERT INTO menu_items
                (id, organization_id, restaurant_id, external_id, name, category,
                 price_cents, recipe_id, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [$id, $organizationId, $restaurantId, $externalId, $name, $category, $priceCents, $recipeId]
        );
        return $id;
    }

    private function computeDerivedCosts(string $organizationId, string $restaurantId): void
    {
        $svc = new PlateCostService($this->items, $this->recipes, $this->plateCosts, $this->benchmark);
        $svc->computeForRestaurant($restaurantId, $organizationId, self::REGION);
    }

    /**
     * Generate ~60 days of pos_sales targeting ~$3k/day average and ~$84k MTD.
     * Daypart distribution intentionally has weak afternoon + strong dinner
     * so the war-room's "Sales by daypart" panel renders the right shape.
     *
     * Deterministic where possible — items get a popularity weight and a
     * daypart bias, sales counts are derived from those. Adds ±10% jitter
     * so the daily curve doesn't look like a calculator output.
     */
    private function ensurePosSales(string $organizationId, string $restaurantId, array $itemMap): void
    {
        // Hard idempotency — wipe any prior sample sales for this restaurant
        // and rewrite from scratch each seed. Cheap (it's one restaurant).
        $this->db->query('DELETE FROM pos_sales WHERE restaurant_id = ?', [$restaurantId]);

        // Item-level POS metadata for daypart bias + popularity weight.
        $bias = $this->itemMixBlueprint();

        // Walk 60 days back through today.
        $end = strtotime('today');
        $start = $end - 60 * 86400;

        // Deterministic RNG-ish jitter so re-seeds produce the same dataset.
        $hash = function (string $s): float {
            $h = crc32($s);
            return ($h % 1000) / 1000.0;
        };

        for ($d = 0; $d < 60; $d++) {
            $dayTs = $start + $d * 86400;
            $dow   = (int) date('w', $dayTs);  // 0 Sun … 6 Sat
            $dayBucket = $this->dayMultiplier($dow);

            // Recent-week lift: most recent 7 days are ~6% above prior week.
            $recentLift = $d >= 53 ? 1.06 : 1.00;

            // Per-day cover target lands aggregate at ~$3k/day → ~$84k/mo.
            // Each item gets popularity_weight × daypart slice × bias, then we
            // emit individual line rows.
            foreach ($itemMap as $slug => $row) {
                $b = $bias[$slug] ?? null;
                if (!$b) continue;

                $popularity = $b['popularity'];            // 0..1
                if ($popularity <= 0) continue;
                $dayparts   = $b['dayparts'];              // ['lunch'=>0.4,'dinner'=>0.5,...]

                foreach ($dayparts as $daypart => $share) {
                    $jitter = 0.9 + 0.2 * $hash($slug . $d . $daypart);
                    // Base qty: popularity × daypart share × day multiplier × scale.
                    // Scale tuned so weekday avg lands ≈ $2.8k/day; weekend ≈ $4k.
                    // Aggregate MTD ≈ $84k for a 28-day window (matches the
                    // spec's headline target).
                    $qty = (int) round($popularity * $share * $dayBucket * 18 * $jitter * $recentLift);
                    if ($qty <= 0) continue;

                    // Emit one row per qty (each row is one ticket-line for that item).
                    for ($k = 0; $k < $qty; $k++) {
                        $hour = $this->daypartHour($daypart, ($hash($slug . $d . $daypart . $k) * 10000));
                        $minute = (int) (60 * $hash('m' . $slug . $d . $daypart . $k));
                        $soldAt = date('Y-m-d H:i:s', $dayTs + $hour * 3600 + $minute * 60);

                        $price = $row['price_cents'];
                        $lineUid = 'sample-' . $slug . '-' . $d . '-' . $daypart . '-' . $k;
                        $orderId = 'sample-ord-' . $d . '-' . substr(md5($lineUid), 0, 8);

                        try {
                            $this->db->query(
                                'INSERT INTO pos_sales
                                    (id, organization_id, restaurant_id, menu_item_id, pos_provider,
                                     pos_order_id, pos_line_uid, qty, gross_cents, net_cents,
                                     sold_at, daypart_label, raw_json, created_at)
                                 VALUES (?, ?, ?, ?, "square", ?, ?, 1, ?, ?, ?, ?, NULL, NOW())',
                                [
                                    Database::uuid(), $organizationId, $restaurantId, $row['id'],
                                    $orderId, $lineUid, $price, (int) round($price * 0.92), $soldAt, $daypart,
                                ]
                            );
                        } catch (\Throwable $e) {
                            // Duplicate line uid (re-seed) — fine.
                            if (!str_contains($e->getMessage(), '1062')) {
                                error_log('[sample-data] pos_sales insert: ' . $e->getMessage());
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Generate ~60 days of labor shifts. One ~9-hour open+close per role
     * per day, plus an intentionally over-staffed Tuesday lunch (extra
     * prep cook 11–2) so LaborDemandService flags it.
     */
    private function ensureLaborShifts(string $organizationId, string $restaurantId): void
    {
        // Wipe + rewrite. The labor_shifts.source ENUM has no 'sample' value,
        // so the rows go in as 'manual' (via LaborShiftRepository::createManual).
        // Teardown is keyed on restaurant_id, so the missing 'sample' source
        // doesn't matter for removal.
        $this->db->query('DELETE FROM labor_shifts WHERE restaurant_id = ?', [$restaurantId]);

        $end = strtotime('today');
        $start = $end - 60 * 86400;

        $template = [
            // (role, label, starts_hour, ends_hour, wage_cents)
            ['foh',     'Server A',     11.0, 22.0,  900],
            ['foh',     'Server B',     17.0, 23.0, 1000],
            ['boh',     'Line Cook A',  10.0, 22.0, 1800],
            ['boh',     'Line Cook B',  16.0, 23.0, 1800],
            ['prep',    'Prep',          8.0, 16.0, 1600],
            ['manager', 'Manager',      10.0, 22.0, 2800],
        ];

        for ($d = 0; $d < 60; $d++) {
            $dayTs = $start + $d * 86400;
            $dow   = (int) date('w', $dayTs);
            // Mondays the restaurant is closed — keep it dark for some shape.
            if ($dow === 1) continue;

            foreach ($template as [$role, $label, $startH, $endH, $wage]) {
                $startsAt = date('Y-m-d H:i:s', $dayTs + (int) ($startH * 3600));
                $endsAt   = date('Y-m-d H:i:s', $dayTs + (int) ($endH * 3600));
                $this->shifts->createManual($organizationId, $restaurantId, [
                    'employee_label'     => $label,
                    'role'               => $role,
                    'starts_at'          => $startsAt,
                    'ends_at'            => $endsAt,
                    'hourly_wage_cents'  => $wage,
                ]);
            }
            // Intentional over-staff: Tuesday 11–2 extra prep cook.
            if ($dow === 2) {
                $startsAt = date('Y-m-d H:i:s', $dayTs + 11 * 3600);
                $endsAt   = date('Y-m-d H:i:s', $dayTs + 14 * 3600);
                $this->shifts->createManual($organizationId, $restaurantId, [
                    'employee_label'    => 'Prep B (excess)',
                    'role'              => 'prep',
                    'starts_at'         => $startsAt,
                    'ends_at'           => $endsAt,
                    'hourly_wage_cents' => 1600,
                ]);
            }
        }
    }

    private function ensureGoals(string $organizationId, string $restaurantId): void
    {
        $existing = $this->db->fetchAll(
            'SELECT id, metric FROM goals WHERE restaurant_id = ?',
            [$restaurantId]
        );
        $existingByMetric = [];
        foreach ($existing as $r) $existingByMetric[$r['metric']] = (string) $r['id'];

        $blueprint = [
            // (metric, target, cadence, label)
            ['food_cost_pct',        0.30,   'weekly',  'Food cost ≤ 30%'],
            ['avg_check_cents',      4500.0, 'weekly',  'Avg check $45'],
            ['weekly_revenue_cents', 21000_00.0, 'weekly', 'Weekly revenue $21k'],
        ];

        foreach ($blueprint as [$metric, $target, $cadence, $label]) {
            if (isset($existingByMetric[$metric])) {
                $goalId = $existingByMetric[$metric];
                $this->db->query(
                    'UPDATE goals SET target_value = ?, cadence = ?, label = ?, is_active = 1, updated_at = NOW()
                      WHERE id = ?',
                    [$target, $cadence, $label, $goalId]
                );
            } else {
                $goalId = $this->goals->create($organizationId, $restaurantId, $metric, $target, $cadence, $label);
            }

            // 8 weekly snapshots so the trend line has shape.
            for ($w = 7; $w >= 0; $w--) {
                $start = date('Y-m-d', strtotime("-{$w} weeks Monday"));
                $end   = date('Y-m-d', strtotime("-{$w} weeks Sunday"));
                // Synthesize an actual value that trends toward target.
                $actual = $this->fakeGoalActual($metric, $w);
                $this->goals->recordSnapshot($goalId, $start, $end, $actual);
            }
        }
    }

    private function fakeGoalActual(string $metric, int $weeksAgo): float
    {
        // Curve: starts further from target, drifts in over time.
        $progress = max(0.0, min(1.0, (7 - $weeksAgo) / 7));
        switch ($metric) {
            case 'food_cost_pct':
                // Target 0.30, started at 0.34 → drifted toward 0.312.
                return 0.34 - 0.028 * $progress;
            case 'avg_check_cents':
                // Target 4500, currently ~4200 → started 3900.
                return 3900 + 300 * $progress;
            case 'weekly_revenue_cents':
                // Target 21k, currently ~20.5k.
                return 19500_00 + 1000_00 * $progress;
        }
        return 0.0;
    }

    /**
     * Generate the open + measured recommendations. Runs the real engine
     * first so payloads/dollar estimates are computed by production code,
     * then overrides status on a few rows to simulate history. Measured
     * impacts sum to ~$3,180.
     */
    private function generateRecommendations(string $organizationId, string $restaurantId, array $itemMap): void
    {
        // Wipe any prior sample-generated recs so re-seeds don't pile up.
        $this->db->query('DELETE FROM recommendations WHERE restaurant_id = ?', [$restaurantId]);

        $engine = new MenuEngineeringService($this->items, $this->plateCosts, $this->recs, $this->sales);
        $engine->recommendForRestaurant($restaurantId, $organizationId);

        // Add three "measured" recs by hand — these represent already-accepted
        // moves whose dollar impact has rolled up. RoiService::monthlySummary
        // sums them into the "Carafe found you $X" tile.
        $measuredBlueprint = [
            // (slug, kind, delta_cents, est_monthly_units, measured_cents, days_ago_decided)
            ['carbonara',  'price_raise', 150,  720, 108000, 42],  // $1,080
            ['margherita', 'price_raise', 100,  580,  58000, 35],  // $580
            ['bruschetta', 'reposition',  0,    480, 152000, 21],  // $1,520
        ];
        $now = time();
        foreach ($measuredBlueprint as [$slug, $kind, $delta, $units, $measuredCents, $daysAgo]) {
            $item = $itemMap[$slug] ?? null;
            if (!$item) continue;
            $decided = date('Y-m-d H:i:s', $now - $daysAgo * 86400);
            $measuredAt = date('Y-m-d H:i:s', $now - max(1, $daysAgo - 14) * 86400);
            $payload = [
                'current_price_cents'     => $item['price_cents'],
                'recommended_price_cents' => $item['price_cents'] + $delta,
                'price_delta_cents'       => $delta,
                'est_monthly_sales'       => $units,
                'current_margin_pct'      => 0.42,
                'target_margin_pct'       => 0.65,
                'note'                    => 'sample: pre-accepted, measured',
            ];
            $this->db->query(
                'INSERT INTO recommendations
                    (id, organization_id, restaurant_id, menu_item_id, kind, payload, narrative,
                     dollar_estimate_cents, status, measured_impact_cents,
                     created_at, decided_at, measured_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "measured", ?, ?, ?, ?)',
                [
                    Database::uuid(), $organizationId, $restaurantId, $item['id'], $kind,
                    json_encode($payload),
                    'Already measured. ' . MenuEngineeringService::summarize(
                        ['kind' => $kind, 'payload' => $payload, 'dollar_estimate_cents' => $measuredCents],
                        ['name' => $item['name']]
                    ),
                    $measuredCents,
                    $measuredCents,
                    date('Y-m-d H:i:s', $now - ($daysAgo + 2) * 86400),
                    $decided,
                    $measuredAt,
                ]
            );
        }
    }

    private function stampActivation(string $organizationId, string $restaurantId): void
    {
        // The Carafe activation_columns migration added timestamps that drive
        // the "Getting started 100%" tile. Find an owner user for the org
        // and stamp them all — idempotent because each column only writes
        // when NULL.
        $user = $this->db->fetch(
            'SELECT id FROM users WHERE organization_id = ? ORDER BY created_at ASC LIMIT 1',
            [$organizationId]
        );
        if (!$user) return;
        $cols = [
            'first_pos_connected_at',
            'first_menu_synced_at',
            'first_recommendation_accepted_at',
            'first_dollar_measured_at',
        ];
        foreach ($cols as $col) {
            try {
                \App\Controllers\OnboardingController::stampActivation(
                    (string) $user['id'],
                    $organizationId,
                    $col
                );
            } catch (\Throwable $e) {
                error_log('[sample-data] activation stamp ' . $col . ': ' . $e->getMessage());
            }
        }
    }

    private function countsFor(string $restaurantId): array
    {
        $row = $this->db->fetch(
            'SELECT
                (SELECT COUNT(*) FROM menu_items      WHERE restaurant_id = ?) AS menu_items,
                (SELECT COUNT(*) FROM recipes         WHERE restaurant_id = ?) AS recipes,
                (SELECT COUNT(*) FROM pos_sales       WHERE restaurant_id = ?) AS pos_sales,
                (SELECT COUNT(*) FROM labor_shifts    WHERE restaurant_id = ?) AS labor_shifts,
                (SELECT COUNT(*) FROM recommendations WHERE restaurant_id = ?) AS recommendations,
                (SELECT COUNT(*) FROM goals           WHERE restaurant_id = ?) AS goals',
            [$restaurantId, $restaurantId, $restaurantId, $restaurantId, $restaurantId, $restaurantId]
        );
        $out = [];
        foreach (($row ?? []) as $k => $v) $out[$k] = (int) $v;
        return $out;
    }

    // ──────────────────────────── blueprints ────────────────────────────

    /**
     * 24 menu items across 6 categories. Each row: name, category,
     * price_cents, ingredients ([key, qty, unit]).
     *
     * Two items deliberately have no `ingredients` — that leaves them
     * recipe-less so the menu-coverage gauge reads ~92% (22/24) instead of
     * a suspicious 100%.
     */
    private function menuBlueprint(): array
    {
        return [
            // ─── Antipasti ───
            'burrata' => [
                'name' => 'Burrata di Bufala', 'category' => 'Antipasti', 'price_cents' => 1650,
                'ingredients' => [
                    ['mozzarella_bufala', 0.25, 'lb'],
                    ['tomato_san_marzano', 0.20, 'lb'],
                    ['basil_fresh',        0.5,  'oz'],
                    ['olive_oil_xv',       0.05, 'cup'],
                ],
            ],
            'bruschetta' => [
                'name' => 'Bruschetta al Pomodoro', 'category' => 'Antipasti', 'price_cents' => 1100,
                'ingredients' => [
                    ['tomato_roma',  0.30, 'lb'],
                    ['basil_fresh',  0.3,  'oz'],
                    ['olive_oil_xv', 0.03, 'cup'],
                    ['flour_00',     0.15, 'lb'],
                ],
            ],
            'calamari' => [
                'name' => 'Calamari Fritti', 'category' => 'Antipasti', 'price_cents' => 1450,
                'ingredients' => [
                    ['squid_tube',  0.35, 'lb'],
                    ['flour_ap',    0.10, 'lb'],
                    ['lemon',       0.5,  'each'],
                    ['olive_oil_xv', 0.10, 'cup'],
                ],
            ],
            'arugula_salad' => [
                'name' => 'Insalata di Rucola', 'category' => 'Antipasti', 'price_cents' => 1250,
                'ingredients' => [
                    ['arugula',          0.20, 'lb'],
                    ['parmesan_grated',  0.5,  'oz'],
                    ['lemon',            0.5,  'each'],
                    ['olive_oil_xv',     0.04, 'cup'],
                ],
            ],

            // ─── Pasta ───
            'carbonara' => [
                'name' => 'Spaghetti alla Carbonara', 'category' => 'Pasta', 'price_cents' => 1950,
                'ingredients' => [
                    ['pasta_spaghetti', 0.30, 'lb'],
                    ['guanciale',       0.20, 'lb'],
                    ['egg_large',       2,    'each'],
                    ['pecorino_romano', 1.0,  'oz'],
                    ['pepper_black',    0.05, 'oz'],
                ],
            ],
            'cacio_e_pepe' => [
                'name' => 'Cacio e Pepe', 'category' => 'Pasta', 'price_cents' => 1750,
                'ingredients' => [
                    ['pasta_spaghetti', 0.30, 'lb'],
                    ['pecorino_romano', 1.5,  'oz'],
                    ['pepper_black',    0.1,  'oz'],
                    ['butter_unsalted', 0.5,  'oz'],
                ],
            ],
            'bolognese' => [
                'name' => 'Tagliatelle alla Bolognese', 'category' => 'Pasta', 'price_cents' => 2150,
                'ingredients' => [
                    ['flour_00',         0.20, 'lb'],
                    ['egg_large',        1,    'each'],
                    ['veal_ground',      0.15, 'lb'],
                    ['pork_ground',      0.15, 'lb'],
                    ['tomato_san_marzano', 0.20, 'lb'],
                    ['wine_red_house',   0.1,  'cup'],
                    ['onion_yellow',     0.10, 'lb'],
                ],
            ],
            'lasagna' => [
                'name' => 'Lasagna al Forno', 'category' => 'Pasta', 'price_cents' => 2250,
                'ingredients' => [
                    ['flour_00',         0.20, 'lb'],
                    ['mozzarella_fresh', 0.20, 'lb'],
                    ['parmesan_grated',  0.5,  'oz'],
                    ['veal_ground',      0.15, 'lb'],
                    ['tomato_san_marzano', 0.25, 'lb'],
                    ['butter_unsalted',  0.5,  'oz'],
                ],
            ],

            // ─── Pizza ───
            'margherita' => [
                'name' => 'Pizza Margherita', 'category' => 'Pizza', 'price_cents' => 1850,
                'ingredients' => [
                    ['flour_00',           0.40, 'lb'],
                    ['mozzarella_bufala',  0.20, 'lb'],
                    ['tomato_san_marzano', 0.20, 'lb'],
                    ['basil_fresh',        0.4,  'oz'],
                    ['olive_oil_xv',       0.04, 'cup'],
                ],
            ],
            'diavola' => [
                'name' => 'Pizza Diavola', 'category' => 'Pizza', 'price_cents' => 2050,
                'ingredients' => [
                    ['flour_00',           0.40, 'lb'],
                    ['mozzarella_fresh',   0.20, 'lb'],
                    ['tomato_san_marzano', 0.20, 'lb'],
                    ['prosciutto',         0.10, 'lb'],
                    ['chili_flake',        0.1,  'oz'],
                ],
            ],
            'quattro_formaggi' => [
                'name' => 'Pizza Quattro Formaggi', 'category' => 'Pizza', 'price_cents' => 2150,
                'ingredients' => [
                    ['flour_00',         0.40, 'lb'],
                    ['mozzarella_fresh', 0.15, 'lb'],
                    ['parmesan_grated',  0.5,  'oz'],
                    ['pecorino_romano',  0.5,  'oz'],
                    ['mascarpone',       0.15, 'lb'],
                ],
            ],

            // ─── Secondi ───
            'bistecca' => [
                'name' => 'Bistecca alla Fiorentina', 'category' => 'Secondi', 'price_cents' => 4250,
                'ingredients' => [
                    ['ribeye',         0.80, 'lb'],
                    ['olive_oil_xv',   0.05, 'cup'],
                    ['oregano_dry',    0.1,  'oz'],
                    ['butter_unsalted', 0.5, 'oz'],
                ],
            ],
            'branzino_intero' => [
                'name' => 'Branzino al Limone', 'category' => 'Secondi', 'price_cents' => 3450,
                'ingredients' => [
                    ['branzino',       1.10, 'lb'],
                    ['lemon',          1,    'each'],
                    ['parsley_fresh',  0.5,  'oz'],
                    ['olive_oil_xv',   0.06, 'cup'],
                ],
            ],
            'pollo_milanese' => [
                'name' => 'Pollo alla Milanese', 'category' => 'Secondi', 'price_cents' => 2650,
                'ingredients' => [
                    ['chicken_thigh', 0.45, 'lb'],
                    ['egg_large',     1,    'each'],
                    ['flour_ap',      0.05, 'lb'],
                    ['parmesan_grated', 0.5, 'oz'],
                    ['arugula',       0.10, 'lb'],
                    ['lemon',         0.5,  'each'],
                ],
            ],

            // ─── Dolci ───
            'tiramisu' => [
                'name' => 'Tiramisu', 'category' => 'Dolci', 'price_cents' => 1050,
                'ingredients' => [
                    ['mascarpone',     0.20, 'lb'],
                    ['ladyfingers',    0.12, 'lb'],
                    ['egg_large',      2,    'each'],
                    ['sugar_granulated', 0.05, 'lb'],
                    ['espresso_beans', 0.05, 'lb'],
                ],
            ],
            'panna_cotta' => [
                'name' => 'Panna Cotta', 'category' => 'Dolci', 'price_cents' => 950,
                'ingredients' => [
                    ['cream_heavy',      0.5,  'cup'],
                    ['sugar_granulated', 0.05, 'lb'],
                    ['gelatin_sheet',    0.1,  'oz'],
                ],
            ],

            // ─── Beverages — trivial cost; recipes simulate bar cost ───
            'wine_glass_red' => [
                'name' => 'House Red — by the glass', 'category' => 'Beverage', 'price_cents' => 1200,
                'ingredients' => [
                    ['wine_red_house', 0.5, 'cup'],
                ],
            ],
            'espresso_single' => [
                'name' => 'Espresso', 'category' => 'Beverage', 'price_cents' => 350,
                'ingredients' => [
                    ['espresso_beans', 0.02, 'lb'],
                ],
            ],
            'cappuccino' => [
                'name' => 'Cappuccino', 'category' => 'Beverage', 'price_cents' => 525,
                'ingredients' => [
                    ['espresso_beans', 0.02, 'lb'],
                    ['milk_whole',     0.3,  'cup'],
                ],
            ],

            // ─── More antipasti / pasta to reach 24 ───
            'caprese' => [
                'name' => 'Insalata Caprese', 'category' => 'Antipasti', 'price_cents' => 1400,
                'ingredients' => [
                    ['tomato_roma',      0.50, 'lb'],
                    ['mozzarella_fresh', 0.25, 'lb'],
                    ['basil_fresh',      0.5,  'oz'],
                    ['olive_oil_xv',     0.10, 'cup'],
                ],
            ],
            'gnocchi_pomodoro' => [
                'name' => 'Gnocchi al Pomodoro', 'category' => 'Pasta', 'price_cents' => 1850,
                'ingredients' => [
                    ['flour_00',           0.20, 'lb'],
                    ['tomato_san_marzano', 0.25, 'lb'],
                    ['basil_fresh',        0.3,  'oz'],
                    ['butter_unsalted',    0.5,  'oz'],
                ],
            ],

            // ─── Two intentionally-uncovered items so coverage reads ~92% ───
            'sparkling_water' => [
                'name' => 'San Pellegrino', 'category' => 'Beverage', 'price_cents' => 650,
                'ingredients' => [], // intentionally bare — no plate cost
            ],
            'amaro' => [
                'name' => 'Amaro Digestivo', 'category' => 'Beverage', 'price_cents' => 1050,
                'ingredients' => [], // intentionally bare — no plate cost
            ],
        ];
    }

    /**
     * Per-item daypart bias + popularity weight. Sales generator multiplies
     * popularity × daypart-share × day-multiplier to land at ~$84k MTD.
     * Afternoon is intentionally weak; dinner heavy. One or two "dogs" with
     * popularity ≈ 0.05 to feed the cut recommendation.
     */
    private function itemMixBlueprint(): array
    {
        return [
            // Antipasti — even spread, popular at dinner.
            'burrata'         => ['popularity' => 0.6,  'dayparts' => ['lunch' => 0.15, 'dinner' => 0.6, 'late' => 0.05]],
            'bruschetta'      => ['popularity' => 0.55, 'dayparts' => ['lunch' => 0.3,  'dinner' => 0.5, 'late' => 0.05]],
            'calamari'        => ['popularity' => 0.5,  'dayparts' => ['lunch' => 0.15, 'dinner' => 0.6, 'late' => 0.10]],
            'arugula_salad'   => ['popularity' => 0.45, 'dayparts' => ['lunch' => 0.4,  'dinner' => 0.3, 'late' => 0.0 ]],
            'caprese'         => ['popularity' => 0.5,  'dayparts' => ['lunch' => 0.3,  'dinner' => 0.45, 'late' => 0.0]],

            // Pasta — dinner heavy. Carbonara is the volume star.
            'carbonara'       => ['popularity' => 1.0,  'dayparts' => ['lunch' => 0.25, 'dinner' => 0.65, 'late' => 0.05]],
            'cacio_e_pepe'    => ['popularity' => 0.7,  'dayparts' => ['lunch' => 0.20, 'dinner' => 0.65, 'late' => 0.05]],
            'bolognese'       => ['popularity' => 0.75, 'dayparts' => ['lunch' => 0.10, 'dinner' => 0.75, 'late' => 0.05]],
            'lasagna'         => ['popularity' => 0.55, 'dayparts' => ['lunch' => 0.20, 'dinner' => 0.60, 'late' => 0.05]],
            'gnocchi_pomodoro'=> ['popularity' => 0.4,  'dayparts' => ['lunch' => 0.30, 'dinner' => 0.45, 'late' => 0.0 ]],

            // Pizza — dinner heavy, takeout bias late.
            'margherita'      => ['popularity' => 0.85, 'dayparts' => ['lunch' => 0.20, 'dinner' => 0.60, 'late' => 0.10]],
            'diavola'         => ['popularity' => 0.6,  'dayparts' => ['lunch' => 0.10, 'dinner' => 0.65, 'late' => 0.10]],
            'quattro_formaggi'=> ['popularity' => 0.45, 'dayparts' => ['lunch' => 0.10, 'dinner' => 0.65, 'late' => 0.10]],

            // Secondi — dinner-only, premium price.
            'bistecca'        => ['popularity' => 0.45, 'dayparts' => ['lunch' => 0.02, 'dinner' => 0.85, 'late' => 0.05]],
            'branzino_intero' => ['popularity' => 0.30, 'dayparts' => ['lunch' => 0.05, 'dinner' => 0.85, 'late' => 0.0]],
            'pollo_milanese'  => ['popularity' => 0.35, 'dayparts' => ['lunch' => 0.20, 'dinner' => 0.60, 'late' => 0.0]],

            // Dolci.
            'tiramisu'        => ['popularity' => 0.55, 'dayparts' => ['lunch' => 0.05, 'dinner' => 0.75, 'late' => 0.10]],
            'panna_cotta'     => ['popularity' => 0.20, 'dayparts' => ['lunch' => 0.05, 'dinner' => 0.75, 'late' => 0.05]],

            // Beverages — dinner heavy + afternoon weak.
            'wine_glass_red'  => ['popularity' => 0.9,  'dayparts' => ['lunch' => 0.15, 'afternoon' => 0.05, 'dinner' => 0.65, 'late' => 0.10]],
            'espresso_single' => ['popularity' => 0.6,  'dayparts' => ['lunch' => 0.20, 'afternoon' => 0.05, 'dinner' => 0.55, 'late' => 0.10]],
            'cappuccino'      => ['popularity' => 0.4,  'dayparts' => ['breakfast' => 0.20, 'lunch' => 0.50, 'afternoon' => 0.10, 'dinner' => 0.15]],
            'sparkling_water' => ['popularity' => 0.45, 'dayparts' => ['lunch' => 0.35, 'dinner' => 0.55, 'late' => 0.05]],

            // The dogs — low popularity, low volume. Feeds the "cut" rec.
            'amaro'           => ['popularity' => 0.08, 'dayparts' => ['dinner' => 0.5, 'late' => 0.5]],
        ];
    }

    /**
     * Friday/Saturday heavier, Monday closed-ish, Sunday brunch-ish.
     * Returns a multiplier on top of base popularity.
     */
    private function dayMultiplier(int $dow): float
    {
        // Sun=0, Mon=1, Tue=2, Wed=3, Thu=4, Fri=5, Sat=6.
        return match ($dow) {
            0 => 0.95,
            1 => 0.0,    // closed Monday
            2 => 0.75,   // Tuesday — slow start of week
            3 => 0.85,
            4 => 1.00,
            5 => 1.40,
            6 => 1.55,
            default => 1.0,
        };
    }

    /**
     * Map a daypart label to a representative hour-of-day. The PosSales
     * dayPart helper buckets by H<11 breakfast / H<16 lunch / H<22 dinner
     * / else late — we feed it back-derived hours so the bucket label and
     * the sold_at hour agree.
     */
    private function daypartHour(string $daypart, float $jitter): int
    {
        $base = match ($daypart) {
            'breakfast' => 9,
            'lunch'     => 12,
            'afternoon' => 14,
            'dinner'    => 19,
            'late'      => 22,
            default     => 13,
        };
        $offset = (int) floor($jitter) % 2; // 0..1
        return $base + $offset;
    }
}
