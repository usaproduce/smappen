<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\PrivateData\MenuItemRepository;
use App\PrivateData\PlateCostRepository;
use App\PrivateData\RecommendationRepository;
use App\PrivateData\PosSalesRepository;

/**
 * Menu engineering — Phase 1 vertical slice.
 *
 * For one menu_item: if contribution margin is below TARGET_MARGIN_FLOOR
 * (60% of price), recommend a price raise sized to lift margin to
 * TARGET_MARGIN_GOAL (65%). Dollar impact = recommended_delta ×
 * est_monthly_sales. Chunk 1 stubs est at MIN_EST_MONTHLY_SALES; Chunk 2
 * pulls real volume from pos_sales.
 *
 * Narrative generation follows the AI-with-local-fallback pattern from
 * AiScoringController exactly: ask Haiku if ANTHROPIC_API_KEY is set,
 * otherwise emit a deterministic templated sentence. Caller must work
 * either way (no key required to build the repo).
 */
class MenuEngineeringService
{
    private const TARGET_MARGIN_FLOOR     = 0.60;
    private const TARGET_MARGIN_GOAL      = 0.65;
    private const MIN_EST_MONTHLY_SALES   = 30;    // 1/day stub; Chunk 2 replaces with pos_sales lookup
    private const PRICE_ROUND_CENTS       = 25;    // round price recs to nearest $0.25
    private const MIN_DOLLAR_IMPACT_CENTS = 500;   // <$5/mo isn't worth surfacing

    public function __construct(
        private MenuItemRepository $items,
        private PlateCostRepository $plateCosts,
        private RecommendationRepository $recs,
        private ?PosSalesRepository $sales = null,
    ) {}

    /**
     * Menu-engineering 2x2: Star / Puzzle / Plowhorse / Dog.
     *   axis-x: profitability — true contribution margin per item
     *   axis-y: popularity   — qty sold over the trailing 90 days
     *
     * Thresholds are restaurant-median splits so the chart isn't dominated
     * by absolute-dollar bias. Returns a row per active menu item with the
     * quadrant label attached.
     */
    public function classify(string $restaurantId): array
    {
        $rows = $this->items->listByRestaurant($restaurantId);
        $volume = $this->sales !== null ? $this->sales->monthlyVolumeByItem($restaurantId) : [];

        $items = [];
        foreach ($rows as $r) {
            if ((int) $r['is_active'] !== 1) continue;
            $cost = $r['true_cost_cents'];
            $price = (int) $r['price_cents'];
            if ($cost === null || $price <= 0) continue;
            $items[] = [
                'id'              => (string) $r['id'],
                'name'            => (string) $r['name'],
                'category'        => $r['category'],
                'price_cents'     => $price,
                'true_cost_cents' => (int) $cost,
                'margin_cents'    => $price - (int) $cost,
                'volume_monthly'  => $volume[(string) $r['id']] ?? 0,
            ];
        }
        if (!$items) return ['items' => [], 'medians' => null];

        // Medians for the split.
        $margins = array_map(fn($x) => $x['margin_cents'], $items);
        $vols    = array_map(fn($x) => $x['volume_monthly'], $items);
        sort($margins);
        sort($vols);
        $marginMed = self::median($margins);
        $volMed    = self::median($vols);

        foreach ($items as &$it) {
            $highMargin = $it['margin_cents']   >= $marginMed;
            $highVolume = $it['volume_monthly'] >= $volMed;
            $it['quadrant'] = match (true) {
                $highMargin && $highVolume  => 'star',
                $highMargin && !$highVolume => 'puzzle',
                !$highMargin && $highVolume => 'plowhorse',
                default                     => 'dog',
            };
        }
        return ['items' => $items, 'medians' => ['margin_cents' => $marginMed, 'volume_monthly' => $volMed]];
    }

    private static function median(array $sorted): float
    {
        $n = count($sorted);
        if ($n === 0) return 0.0;
        return $n % 2 === 0 ? (($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2.0) : (float) $sorted[(int) ($n / 2)];
    }

    /**
     * Run the engine for one menu item. Returns the new recommendation
     * row id, or null if no recommendation was warranted (already good
     * margin, no plate cost yet, or duplicate within the last week).
     */
    public function recommendForItem(string $menuItemId, string $organizationId): ?string
    {
        $item = $this->items->findById($menuItemId, $organizationId);
        if (!$item) return null;
        if ((int) $item['price_cents'] <= 0) return null;

        $pc = $this->plateCosts->findByMenuItem($menuItemId);
        if (!$pc) return null; // need cost to compute margin
        $cost   = (int) $pc['true_cost_cents'];
        $price  = (int) $item['price_cents'];
        if ($cost <= 0 || $cost >= $price) return null; // suspect data — skip

        $margin    = $price - $cost;
        $marginPct = $margin / $price;

        if ($marginPct >= self::TARGET_MARGIN_FLOOR) return null; // already healthy

        // New price = cost / (1 - target_margin_goal), rounded up to nearest $0.25.
        $rawNew = $cost / (1.0 - self::TARGET_MARGIN_GOAL);
        $newPrice = self::roundUpToCents((int) ceil($rawNew), self::PRICE_ROUND_CENTS);
        $delta = $newPrice - $price;
        if ($delta <= 0) return null;

        // Chunk 2: use real PMIX when available, else fall back to the
        // Chunk 1 stub. Either way, an item with zero recent sales doesn't
        // generate a recommendation (it's a Dog candidate — handle in cut()).
        $estMonthlySales = self::MIN_EST_MONTHLY_SALES;
        if ($this->sales !== null) {
            $volume = $this->sales->monthlyVolumeByItem((string) $item['restaurant_id']);
            $vol = $volume[$menuItemId] ?? null;
            if ($vol !== null && $vol > 0) {
                $estMonthlySales = $vol;
            } elseif ($vol === 0) {
                // No recent sales = price-raise wouldn't move dollars. Skip.
                return null;
            }
        }
        $dollarImpact = $delta * $estMonthlySales;
        if ($dollarImpact < self::MIN_DOLLAR_IMPACT_CENTS) return null;

        // Don't re-emit the same kind for the same item within 7 days.
        if ($this->recs->recentExistsFor($menuItemId, 'price_raise')) return null;

        $payload = [
            'current_price_cents' => $price,
            'true_cost_cents'     => $cost,
            'current_margin_pct'  => round($marginPct, 3),
            'recommended_price_cents' => $newPrice,
            'price_delta_cents'   => $delta,
            'est_monthly_sales'   => $estMonthlySales,
            'target_margin_pct'   => self::TARGET_MARGIN_GOAL,
        ];
        $narrative = $this->narrate($item, $payload);

        return $this->recs->create(
            $organizationId,
            (string) $item['restaurant_id'],
            $menuItemId,
            'price_raise',
            $payload,
            $narrative,
            $dollarImpact,
        );
    }

    /** Run for every active item with a plate cost. Returns count of recs created. */
    public function recommendForRestaurant(string $restaurantId, string $organizationId): int
    {
        $created = 0;
        foreach ($this->items->listByRestaurant($restaurantId) as $row) {
            if (empty($row['true_cost_cents'])) continue;
            $id = $this->recommendForItem((string) $row['id'], $organizationId);
            if ($id !== null) $created++;
        }
        // Layout guidance — uses classify() output to suggest reposition/cut.
        $created += $this->layoutRecsForRestaurant($restaurantId, $organizationId);
        return $created;
    }

    /**
     * Layout guidance — reposition stars (feature them), reprice puzzles
     * (drop slightly to lift volume), cut dogs (low margin AND low volume).
     * Conservative: at most one rec per quadrant per week per item.
     */
    public function layoutRecsForRestaurant(string $restaurantId, string $organizationId): int
    {
        $cls = $this->classify($restaurantId);
        $created = 0;
        foreach ($cls['items'] ?? [] as $it) {
            $kind = match ($it['quadrant']) {
                'star'      => 'reposition',  // already winning — make it the headline
                'puzzle'    => 'reprice',     // high margin / low vol — small price cut may lift volume
                'dog'       => 'cut',         // low margin / low vol — kill it
                default     => null,           // plowhorses are already pulling weight
            };
            if ($kind === null) continue;
            if ($this->recs->recentExistsFor((string) $it['id'], $kind)) continue;

            // Dollar impact estimate per kind. Conservative back-of-envelope —
            // ROI service will measure the real number for accepted recs.
            $impact = match ($kind) {
                'reposition' => (int) round($it['margin_cents'] * 0.10 * max(1, $it['volume_monthly'])), // +10% lift
                'reprice'    => (int) round($it['margin_cents'] * 0.20 * max(1, $it['volume_monthly'])), // +20% volume after small price cut
                'cut'        => 0, // no positive number to surface — just remove a money-loser
                default      => 0,
            };

            $narrative = self::layoutNarrative($it, $kind);
            $this->recs->create(
                $organizationId,
                $restaurantId,
                (string) $it['id'],
                $kind,
                [
                    'quadrant'        => $it['quadrant'],
                    'margin_cents'    => $it['margin_cents'],
                    'volume_monthly'  => $it['volume_monthly'],
                ],
                $narrative,
                $impact,
            );
            $created++;
        }
        return $created;
    }

    private static function layoutNarrative(array $it, string $kind): string
    {
        $name = (string) $it['name'];
        return match ($kind) {
            'reposition' => "Feature $name — it's a star (high margin, high volume). Move it to the top of the menu and put a callout box around it; tonight's specials should default to this one.",
            'reprice'    => "$name has healthy margin but moves slowly. Try a small price reduction or a daypart-anchored promo to lift volume — the margin headroom is there.",
            'cut'        => "$name is a dog — low margin AND low volume. Recipe rework, retire, or replace with something pulling in the same category.",
            default      => "$name: no action needed.",
        };
    }

    // ──────────────────────────── narrative ────────────────────────────

    private function narrate(array $item, array $payload): string
    {
        if (self::hasAnthropicKey()) {
            $ai = $this->narrateWithClaude($item, $payload);
            if ($ai !== null) return $ai;
        }
        return self::narrateLocal($item, $payload);
    }

    private static function narrateLocal(array $item, array $payload): string
    {
        $name      = (string) $item['name'];
        $cur       = '$' . number_format($payload['current_price_cents'] / 100, 2);
        $new       = '$' . number_format($payload['recommended_price_cents'] / 100, 2);
        $deltaUsd  = '$' . number_format($payload['price_delta_cents'] / 100, 2);
        $marginPct = (int) round($payload['current_margin_pct'] * 100);
        return sprintf(
            'Raise %s from %s to %s (+%s). Current margin is %d%%, below the 60%% floor — '
            . 'at the new price, projected ~%s/mo based on a conservative 1-sale-per-day estimate '
            . '(real volume from your POS will sharpen this).',
            $name, $cur, $new, $deltaUsd, $marginPct,
            '$' . number_format(($payload['price_delta_cents'] * $payload['est_monthly_sales']) / 100, 0)
        );
    }

    /** Single-sentence narrative from Claude Haiku. Returns null on any failure. */
    private function narrateWithClaude(array $item, array $payload): ?string
    {
        $apiKey = (string) Config::get('ANTHROPIC_API_KEY', '');
        if ($apiKey === '') return null;
        $prompt = "You are advising a restaurant operator. In ONE plain-English sentence, "
            . "tell them to raise the menu price and quantify the monthly impact. "
            . "No emojis. No exclamation marks. No sales-y language.\n\n"
            . "Item: " . $item['name'] . "\n"
            . "Current price: $" . number_format($payload['current_price_cents'] / 100, 2) . "\n"
            . "True plate cost: $" . number_format($payload['true_cost_cents'] / 100, 2) . "\n"
            . "Current margin: " . round($payload['current_margin_pct'] * 100) . "%\n"
            . "Recommended price: $" . number_format($payload['recommended_price_cents'] / 100, 2) . "\n"
            . "Estimated monthly sales: " . $payload['est_monthly_sales'];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 200,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code >= 400) return null;
        $parsed = json_decode((string) $resp, true);
        $text = $parsed['content'][0]['text'] ?? null;
        if (!is_string($text) || trim($text) === '') return null;
        return trim($text);
    }

    private static function hasAnthropicKey(): bool
    {
        return (string) Config::get('ANTHROPIC_API_KEY', '') !== '';
    }

    private static function roundUpToCents(int $cents, int $step): int
    {
        return (int) (ceil($cents / $step) * $step);
    }
}
