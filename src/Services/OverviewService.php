<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\PrivateData\PosIntegrationRepository;
use App\PrivateData\RecommendationRepository;
use App\PrivateData\PosSalesRepository;

/**
 * War-room overview aggregator — single endpoint that bundles every
 * number the post-login dashboard renders (spec §1.6 / §9, audit item 7).
 *
 * One round trip instead of five so Lighthouse desktop can hit ≥ 90 even
 * on a cold cache. Every number is returned with a `last_updated_at` so
 * the UI can render a freshness chip beside it — operators trust numbers
 * they can date.
 *
 *   POS sync ........ pos_integrations.last_synced_at
 *   USDA prices ..... MAX(cogs_benchmark.as_of)
 *   Today service ... MAX(pos_sales.sold_at)
 *   ROI ............. MAX(recommendations.measured_at|decided_at)
 *   Top move ........ recommendations.created_at
 *
 * Failure mode: each sub-fetch is isolated. If pos_sales is empty, the
 * `today_service` block is null but the rest still renders.
 */
class OverviewService
{
    public function __construct(
        private RoiService $roi,
        private RecommendationRepository $recs,
        private PosIntegrationRepository $integrations,
        private PosSalesRepository $sales,
    ) {}

    public function build(string $restaurantId, string $timezone = 'UTC'): array
    {
        // Top move + a small bench of next-best recs. The bench lets the
        // war-room advance the card on Accept/Dismiss without a refetch
        // (acceptance criterion: "a different rec without a full page
        // reload").
        $queue = $this->topMoveQueue($restaurantId, 5);
        return [
            'roi'             => $this->roiBlock($restaurantId),
            'roi_trend'       => $this->roi->trend($restaurantId, 6),
            'today_service'   => $this->todayServiceBlock($restaurantId, $timezone),
            'pos'             => $this->posBlock($restaurantId),
            'usda_prices'     => $this->usdaPricesBlock(),
            'digest'          => $this->digestBlock($restaurantId),
            'top_move'        => $queue[0] ?? null,
            'next_moves'      => array_slice($queue, 1),
            'open_recs_count' => $this->openRecsCount($restaurantId),
            'goals'           => $this->goalsBlock($restaurantId),
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // ROI tile — "Carafe found you $X this month" + a per-rec timestamp
    // so the UI can render "as of 12 min ago".
    // ────────────────────────────────────────────────────────────────────
    private function roiBlock(string $restaurantId): array
    {
        $summary = $this->roi->monthlySummary($restaurantId);
        $start = $summary['month_start'];
        $end   = date('Y-m-t 23:59:59', strtotime($start));
        $row = Database::getInstance()->fetch(
            'SELECT GREATEST(
                COALESCE(MAX(measured_at), 0),
                COALESCE(MAX(decided_at),  0)
              ) AS last_updated_at
              FROM recommendations
             WHERE restaurant_id = ?
               AND ( (status = "measured"  AND measured_at BETWEEN ? AND ?)
                  OR (status IN ("accepted","measured") AND decided_at BETWEEN ? AND ?) )',
            [$restaurantId, $start, $end, $start, $end]
        );
        $summary['last_updated_at'] = !empty($row['last_updated_at']) && $row['last_updated_at'] !== '0'
            ? (string) $row['last_updated_at']
            : null;
        return $summary;
    }

    // ────────────────────────────────────────────────────────────────────
    // Today's service — covers / revenue / food-cost % to date.
    //
    // "Covers" is a guest count proxy: distinct pos_order_id today. Carafe
    // doesn't yet ingest seat counts from Square (some POSes don't expose
    // them at all). One order ≈ one cover is the convention MarginEdge
    // and Toast use when seat-level data is missing — operators read it
    // as "tickets today" and trust it as a tempo indicator.
    // ────────────────────────────────────────────────────────────────────
    private function todayServiceBlock(string $restaurantId, string $timezone): ?array
    {
        $tz = $this->safeTz($timezone);
        try {
            $today = new \DateTimeImmutable('today', $tz);
        } catch (\Throwable) {
            $today = new \DateTimeImmutable('today');
        }
        // pos_sales.sold_at is stored in UTC (Square sends ISO-Z timestamps,
        // SquareAdapter formats them via date() under the droplet's UTC
        // default tz). To get "today in restaurant-local time" we have to
        // convert the local midnight boundaries into the storage zone.
        $utc = new \DateTimeZone('UTC');
        $startLocal = $today->setTimezone($utc)->format('Y-m-d H:i:s');
        $endLocal   = $today->modify('+1 day')->modify('-1 second')
                            ->setTimezone($utc)->format('Y-m-d H:i:s');

        $row = Database::getInstance()->fetch(
            'SELECT COUNT(DISTINCT pos_order_id) AS covers,
                    COALESCE(SUM(gross_cents), 0) AS revenue_cents,
                    COUNT(*)                       AS `lines`,
                    MAX(sold_at)                   AS last_sale_at
               FROM pos_sales
              WHERE restaurant_id = ?
                AND sold_at BETWEEN ? AND ?',
            [$restaurantId, $startLocal, $endLocal]
        );
        $covers  = (int) ($row['covers']  ?? 0);
        $revenue = (int) ($row['revenue_cents'] ?? 0);
        $lines   = (int) ($row['lines'] ?? 0);
        $lastSale = $row['last_sale_at'] ?? null;

        // Food-cost % to date: theoretical cost / revenue across same window.
        $costRow = Database::getInstance()->fetch(
            'SELECT COALESCE(SUM(ps.qty * pc.true_cost_cents), 0) AS theoretical_cost,
                    COALESCE(SUM(ps.gross_cents), 0)              AS rev
               FROM pos_sales ps
               LEFT JOIN plate_costs pc ON pc.menu_item_id = ps.menu_item_id
              WHERE ps.restaurant_id = ?
                AND ps.sold_at BETWEEN ? AND ?
                AND pc.true_cost_cents IS NOT NULL',
            [$restaurantId, $startLocal, $endLocal]
        );
        $theoretical = (int) ($costRow['theoretical_cost'] ?? 0);
        $covRev      = (int) ($costRow['rev'] ?? 0);
        $foodCostPct = $covRev > 0 ? round($theoretical / $covRev, 4) : null;

        if ($lines === 0) {
            return [
                'date'                    => $today->format('Y-m-d'),
                'covers'                  => 0,
                'revenue_cents'           => 0,
                'revenue_per_cover_cents' => null,
                'food_cost_pct'           => null,
                'last_sale_at'            => null,
                'note'                    => 'No POS sales recorded today yet.',
            ];
        }

        return [
            'date'                    => $today->format('Y-m-d'),
            'covers'                  => $covers,
            'revenue_cents'           => $revenue,
            'revenue_per_cover_cents' => $covers > 0 ? (int) round($revenue / $covers) : null,
            'food_cost_pct'           => $foodCostPct,
            'last_sale_at'            => $lastSale,
            'note'                    => null,
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // POS connection state. The wire format mirrors PosController::listForRestaurant
    // so the frontend can use the same Square-specific copy already in
    // RestaurantOverviewPage/MenuPage. last_sale_at is included because
    // "POS synced" is only half the freshness story — what operators
    // really want is "is data coming through".
    // ────────────────────────────────────────────────────────────────────
    private function posBlock(string $restaurantId): array
    {
        $rows = $this->integrations->listByRestaurant($restaurantId);
        $square = null;
        foreach ($rows as $r) {
            if (($r['provider'] ?? '') === 'square') { $square = $r; break; }
        }
        $lastSaleRow = Database::getInstance()->fetch(
            'SELECT MAX(sold_at) AS last_sale_at FROM pos_sales WHERE restaurant_id = ?',
            [$restaurantId]
        );
        return [
            'connected'      => $square !== null,
            'provider'       => $square ? 'square' : null,
            'connected_at'   => $square['connected_at']   ?? null,
            'last_synced_at' => $square['last_synced_at'] ?? null,
            'last_sale_at'   => $lastSaleRow['last_sale_at'] ?? null,
            'integrations'   => $rows,
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // USDA / wholesale price freshness — driven by the most recent
    // cogs_benchmark.as_of we've ingested. Operators care because the
    // plate-cost number on Today's Service is calibrated against this.
    // ────────────────────────────────────────────────────────────────────
    private function usdaPricesBlock(): ?array
    {
        // Defensive: the table may not exist on very fresh installs.
        try {
            $row = Database::getInstance()->fetch(
                'SELECT MAX(as_of) AS as_of, MAX(updated_at) AS updated_at FROM cogs_benchmark'
            );
            if (!$row || empty($row['as_of'])) return null;
            return [
                'as_of'      => (string) $row['as_of'],
                'updated_at' => $row['updated_at'] ?? null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Digest callout — was a weekly digest sent in the last 48 hours?
    // If so, return the sent_at + the rec ids we mailed so the frontend
    // can highlight them inline. digest_sends.rec_ids is JSON (added in
    // migration 040); pre-migration rows return an empty array.
    // ────────────────────────────────────────────────────────────────────
    private function digestBlock(string $restaurantId): ?array
    {
        try {
            $row = Database::getInstance()->fetch(
                'SELECT sent_at, rec_count, total_cents, rec_ids, week_start
                   FROM digest_sends
                  WHERE restaurant_id = ?
                    AND sent_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
                  ORDER BY sent_at DESC
                  LIMIT 1',
                [$restaurantId]
            );
        } catch (\Throwable) {
            return null;
        }
        if (!$row) return null;
        $ids = [];
        if (!empty($row['rec_ids'])) {
            $decoded = is_string($row['rec_ids']) ? json_decode($row['rec_ids'], true) : $row['rec_ids'];
            if (is_array($decoded)) $ids = array_values(array_filter(array_map('strval', $decoded)));
        }
        return [
            'sent_at'     => (string) $row['sent_at'],
            'week_start'  => (string) $row['week_start'],
            'rec_count'   => (int) ($row['rec_count']   ?? 0),
            'total_cents' => (int) ($row['total_cents'] ?? 0),
            'rec_ids'     => $ids,
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // Top Move queue — highest-dollar suggested rec at index 0, with a
    // small bench of next-best moves behind it. The war-room promotes
    // index 0 and advances to index 1 on Accept/Dismiss without any
    // refetch (acceptance criterion: a different rec without a full
    // page reload).
    // ────────────────────────────────────────────────────────────────────
    private function topMoveQueue(string $restaurantId, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $rows = Database::getInstance()->fetchAll(
            'SELECT r.id, r.menu_item_id, r.kind, r.payload, r.narrative,
                    r.dollar_estimate_cents, r.created_at,
                    mi.name AS menu_item_name, mi.price_cents AS menu_item_price_cents,
                    pc.true_cost_cents AS plate_cost_cents
               FROM recommendations r
          LEFT JOIN menu_items  mi ON mi.id = r.menu_item_id
          LEFT JOIN plate_costs pc ON pc.menu_item_id = r.menu_item_id
              WHERE r.restaurant_id = ? AND r.status = "suggested"
              ORDER BY r.dollar_estimate_cents DESC, r.created_at DESC
              LIMIT ?',
            [$restaurantId, $limit]
        );
        $out = [];
        foreach ($rows as $row) {
            $payload = !empty($row['payload']) && is_string($row['payload'])
                ? json_decode($row['payload'], true)
                : ($row['payload'] ?? []);
            $out[] = [
                'id'                    => (string) $row['id'],
                'menu_item_id'          => $row['menu_item_id'] ? (string) $row['menu_item_id'] : null,
                'menu_item_name'        => $row['menu_item_name'] ? (string) $row['menu_item_name'] : null,
                'kind'                  => (string) $row['kind'],
                'payload'               => $payload,
                'narrative'             => $row['narrative'] !== null ? (string) $row['narrative'] : null,
                'dollar_estimate_cents' => (int) $row['dollar_estimate_cents'],
                'created_at'            => (string) $row['created_at'],
                'menu_item_price_cents' => isset($row['menu_item_price_cents']) ? (int) $row['menu_item_price_cents'] : null,
                'plate_cost_cents'      => isset($row['plate_cost_cents']) ? (int) $row['plate_cost_cents'] : null,
            ];
        }
        return $out;
    }

    private function openRecsCount(string $restaurantId): int
    {
        $row = Database::getInstance()->fetch(
            'SELECT COUNT(*) AS n FROM recommendations WHERE restaurant_id = ? AND status = "suggested"',
            [$restaurantId]
        );
        return (int) ($row['n'] ?? 0);
    }

    // ────────────────────────────────────────────────────────────────────
    // Goals — operator-set thresholds for color-coding the Today tile.
    // We pull only the food-cost goal here (the only metric the today
    // tile colors against). Defaults: 28% green / 32% amber / over 32% red
    // — those are MarginEdge's documented industry rules of thumb.
    // ────────────────────────────────────────────────────────────────────
    private function goalsBlock(string $restaurantId): array
    {
        try {
            $row = Database::getInstance()->fetch(
                'SELECT target_value FROM goals
                  WHERE restaurant_id = ? AND metric = "food_cost_pct" AND is_active = 1
                  ORDER BY created_at DESC LIMIT 1',
                [$restaurantId]
            );
        } catch (\Throwable) {
            $row = null;
        }
        $target = $row ? (float) $row['target_value'] : null;
        // target_value is stored as a percentage (e.g. 30.0) — normalize.
        $targetPct = $target !== null && $target > 1 ? $target / 100.0 : $target;
        return [
            'food_cost_pct_target' => $targetPct,
            // green if at-or-below target, amber within 4pp over, red beyond
            'food_cost_pct_warn'   => $targetPct !== null ? round($targetPct + 0.04, 4) : 0.32,
            'food_cost_pct_good'   => $targetPct ?? 0.28,
        ];
    }

    private function safeTz(string $tz): \DateTimeZone
    {
        try { return new \DateTimeZone($tz); } catch (\Throwable) {}
        try { return new \DateTimeZone('UTC'); } catch (\Throwable) {}
        return new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
    }
}
