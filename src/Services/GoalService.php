<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\PrivateData\GoalRepository;

/**
 * Compute actuals for a goal over a period and write the snapshot row.
 * Pulls from pos_sales + plate_costs joined on menu_items — no new data
 * pipes, just composition of what Phase 1 already collects.
 */
class GoalService
{
    public function __construct(private GoalRepository $goals) {}

    public function snapshot(string $goalId, string $organizationId, ?string $start = null, ?string $end = null): ?array
    {
        $goal = $this->goals->findById($goalId, $organizationId);
        if (!$goal) return null;
        [$start, $end] = $this->windowFor($goal['cadence'], $start, $end);

        $actual = match ($goal['metric']) {
            'food_cost_pct'        => $this->foodCostPct($goal['restaurant_id'], $start, $end),
            'avg_check_cents'      => $this->avgCheckCents($goal['restaurant_id'], $start, $end),
            'margin_pct'           => $this->marginPct($goal['restaurant_id'], $start, $end),
            'weekly_revenue_cents' => $this->revenueCents($goal['restaurant_id'], $start, $end),
            default                => null,
        };
        if ($actual === null) return null;

        $this->goals->recordSnapshot($goalId, $start, $end, (float) $actual);
        return [
            'goal_id'      => $goalId,
            'period_start' => $start,
            'period_end'   => $end,
            'target_value' => (float) $goal['target_value'],
            'actual_value' => (float) $actual,
        ];
    }

    /** Roll all active goals for one restaurant. Returns count snapshotted. */
    public function snapshotRestaurant(string $restaurantId, string $organizationId): int
    {
        $count = 0;
        foreach ($this->goals->listByRestaurant($restaurantId) as $g) {
            $r = $this->snapshot((string) $g['id'], $organizationId);
            if ($r !== null) $count++;
        }
        return $count;
    }

    private function windowFor(string $cadence, ?string $start, ?string $end): array
    {
        if ($start && $end) return [$start, $end];
        return match ($cadence) {
            'weekly'    => [date('Y-m-d', strtotime('monday last week')), date('Y-m-d', strtotime('sunday last week'))],
            'quarterly' => [date('Y-m-01', strtotime('first day of -2 months')), date('Y-m-t')],
            default     => [date('Y-m-01'), date('Y-m-t')],
        };
    }

    /** food cost % = Σ(plate_cost × qty) / Σ(gross) over the period. */
    private function foodCostPct(string $restaurantId, string $start, string $end): float
    {
        $row = Database::getInstance()->fetch(
            'SELECT
                COALESCE(SUM(ps.qty * pc.true_cost_cents), 0) AS total_cost,
                COALESCE(SUM(ps.gross_cents), 0)              AS total_gross
              FROM pos_sales ps
              LEFT JOIN plate_costs pc ON pc.menu_item_id = ps.menu_item_id
             WHERE ps.restaurant_id = ?
               AND ps.sold_at BETWEEN ? AND ?',
            [$restaurantId, $start . ' 00:00:00', $end . ' 23:59:59']
        );
        $cost = (int) ($row['total_cost'] ?? 0);
        $gross = (int) ($row['total_gross'] ?? 0);
        return $gross > 0 ? round($cost / $gross, 4) : 0.0;
    }

    private function avgCheckCents(string $restaurantId, string $start, string $end): float
    {
        $row = Database::getInstance()->fetch(
            'SELECT COALESCE(SUM(gross_cents), 0) AS total, COUNT(DISTINCT pos_order_id) AS orders
               FROM pos_sales
              WHERE restaurant_id = ? AND sold_at BETWEEN ? AND ?',
            [$restaurantId, $start . ' 00:00:00', $end . ' 23:59:59']
        );
        $orders = (int) ($row['orders'] ?? 0);
        return $orders > 0 ? (float) round((int) $row['total'] / $orders) : 0.0;
    }

    private function marginPct(string $restaurantId, string $start, string $end): float
    {
        $foodCost = $this->foodCostPct($restaurantId, $start, $end);
        // Margin = 1 - food cost % (ignoring labor + overhead; that's a
        // gross margin proxy, not net. Operators ask for "margin" meaning
        // gross — labor is a separate goal metric).
        return round(1.0 - $foodCost, 4);
    }

    private function revenueCents(string $restaurantId, string $start, string $end): float
    {
        $row = Database::getInstance()->fetch(
            'SELECT COALESCE(SUM(gross_cents), 0) AS total FROM pos_sales
              WHERE restaurant_id = ? AND sold_at BETWEEN ? AND ?',
            [$restaurantId, $start . ' 00:00:00', $end . ' 23:59:59']
        );
        return (float) ($row['total'] ?? 0);
    }
}
