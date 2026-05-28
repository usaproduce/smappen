<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\PrivateData\PosSalesRepository;
use App\PrivateData\RecommendationRepository;

/**
 * ROI ledger — measures the dollar impact of accepted recommendations
 * against actual pos_sales.
 *
 * For each accepted `price_raise` rec:
 *   - baseline qty/day = qty(item, last 30d BEFORE decided_at) / 30
 *   - post qty/day     = qty(item, decided_at .. min(decided_at+30d, now)) / days
 *   - measured impact  = (post_price - baseline_price) × post_qty/day × 30
 *
 * Conservative: if we don't have 14 days post yet, we don't claim a number
 * — `measured_at` stays null and `measured_impact_cents` stays null.
 */
class RoiService
{
    private const MIN_MEASURE_DAYS = 14;
    private const MEASURE_WINDOW   = 30;

    public function __construct(
        private RecommendationRepository $recs,
        private PosSalesRepository $sales,
    ) {}

    /** Measure all accepted-but-unmeasured recs across all orgs. Returns count measured. */
    public function measurePending(): int
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, organization_id, restaurant_id, menu_item_id, kind, payload, decided_at
               FROM recommendations
              WHERE status = "accepted"
                AND menu_item_id IS NOT NULL
                AND decided_at <= DATE_SUB(NOW(), INTERVAL ? DAY)',
            [self::MIN_MEASURE_DAYS]
        );
        $count = 0;
        foreach ($rows as $r) {
            $impact = $this->measureOne($r);
            if ($impact === null) continue;
            Database::getInstance()->query(
                'UPDATE recommendations
                    SET status = "measured", measured_impact_cents = ?, measured_at = NOW()
                  WHERE id = ?',
                [$impact, $r['id']]
            );
            // Activation stamp: first real dollar measured for the org. Find
            // the org's first user (idempotent — only writes when NULL).
            if ($impact > 0) {
                $user = Database::getInstance()->fetch(
                    'SELECT id FROM users WHERE organization_id = ? ORDER BY created_at ASC LIMIT 1',
                    [$r['organization_id']]
                );
                if ($user) {
                    \App\Controllers\OnboardingController::stampActivation(
                        (string) $user['id'],
                        (string) $r['organization_id'],
                        'first_dollar_measured_at'
                    );
                }
            }
            $count++;
        }
        return $count;
    }

    /** Returns measured impact (cents) for one rec, or null if not enough data. */
    public function measureOne(array $rec): ?int
    {
        $payload = is_string($rec['payload']) ? json_decode($rec['payload'], true) : ($rec['payload'] ?? []);
        if (($rec['kind'] ?? null) !== 'price_raise') return 0; // other kinds: nothing measurable yet
        $delta = (int) ($payload['price_delta_cents'] ?? 0);
        if ($delta <= 0) return 0;

        $decidedAt = strtotime((string) $rec['decided_at']);
        $end = min(time(), $decidedAt + self::MEASURE_WINDOW * 86400);
        $days = max(1, (int) (($end - $decidedAt) / 86400));
        if ($days < self::MIN_MEASURE_DAYS) return null;

        $post = $this->qtyBetween($rec['restaurant_id'], $rec['menu_item_id'], date('Y-m-d H:i:s', $decidedAt), date('Y-m-d H:i:s', $end));
        $perDay = $post / $days;
        $monthly = (int) round($perDay * 30);
        return $delta * $monthly;
    }

    private function qtyBetween(string $restaurantId, string $menuItemId, string $start, string $end): int
    {
        $row = Database::getInstance()->fetch(
            'SELECT COALESCE(SUM(qty), 0) AS qty FROM pos_sales
              WHERE restaurant_id = ? AND menu_item_id = ?
                AND sold_at BETWEEN ? AND ?',
            [$restaurantId, $menuItemId, $start, $end]
        );
        return (int) ($row['qty'] ?? 0);
    }

    /**
     * Monthly summary for a restaurant — drives "Carafe found you $X this month".
     *   - measured_impact_cents summed for recs measured within the calendar month
     *   - accepted_count for any rec accepted in the month
     *   - estimated_pending — sum of dollar_estimate_cents for accepted-but-not-yet-measured
     */
    public function monthlySummary(string $restaurantId, ?string $monthIso = null): array
    {
        $start = $monthIso ? date('Y-m-01 00:00:00', strtotime($monthIso)) : date('Y-m-01 00:00:00');
        $end   = date('Y-m-t 23:59:59', strtotime($start));

        $row = Database::getInstance()->fetch(
            'SELECT
                COALESCE(SUM(CASE WHEN status = "measured"  AND measured_at  BETWEEN ? AND ? THEN measured_impact_cents END), 0) AS measured_cents,
                COALESCE(SUM(CASE WHEN status = "accepted" AND decided_at   BETWEEN ? AND ? THEN dollar_estimate_cents END), 0) AS pending_cents,
                COALESCE(SUM(CASE WHEN status IN ("accepted","measured") AND decided_at BETWEEN ? AND ? THEN 1 END), 0)         AS accepted_count
             FROM recommendations
             WHERE restaurant_id = ?',
            [$start, $end, $start, $end, $start, $end, $restaurantId]
        );
        return [
            'month_start'      => $start,
            'measured_cents'   => (int) ($row['measured_cents']   ?? 0),
            'pending_cents'    => (int) ($row['pending_cents']    ?? 0),
            'accepted_count'   => (int) ($row['accepted_count']   ?? 0),
            'found_cents'      => (int) ($row['measured_cents'] ?? 0) + (int) ($row['pending_cents'] ?? 0),
        ];
    }

    /**
     * Trailing N-month ROI series — drives the sparkline next to the
     * hero number on the war-room. Returns oldest → newest so the
     * SVG can render left-to-right without re-sorting in the browser.
     *
     * Includes the current (partial) month at the end. Months with no
     * activity emit a zero value so the sparkline keeps its baseline.
     */
    public function trend(string $restaurantId, int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = date('Y-m-01 00:00:00', strtotime("-{$i} months"));
            $monthEnd   = date('Y-m-t 23:59:59',  strtotime($monthStart));
            $row = Database::getInstance()->fetch(
                'SELECT
                    COALESCE(SUM(CASE WHEN status = "measured"  AND measured_at BETWEEN ? AND ? THEN measured_impact_cents END), 0) AS measured,
                    COALESCE(SUM(CASE WHEN status = "accepted" AND decided_at  BETWEEN ? AND ? THEN dollar_estimate_cents END), 0) AS pending
                   FROM recommendations
                  WHERE restaurant_id = ?',
                [$monthStart, $monthEnd, $monthStart, $monthEnd, $restaurantId]
            );
            $out[] = [
                'month_start' => substr($monthStart, 0, 10),
                'found_cents' => (int) ($row['measured'] ?? 0) + (int) ($row['pending'] ?? 0),
            ];
        }
        return $out;
    }
}
