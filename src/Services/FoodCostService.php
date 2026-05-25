<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Theoretical food cost — what the restaurant SHOULD have spent on
 * ingredients given pos_sales × plate_costs.
 *
 *   theoretical_cost = Σ(qty × plate_costs.true_cost_cents) over period
 *   revenue          = Σ(pos_sales.gross_cents)
 *   theoretical_pct  = theoretical_cost / revenue
 *
 * "Actual vs theoretical" is the table-stakes feature (MarginEdge does
 * this). Phase 2 ships the THEORETICAL half — actual purchases requires
 * restaurant-invoice ingestion which is out of scope for now. The view
 * still tells the operator their margin floor and which items dominate
 * the food-cost basis. Once restaurant invoices are pulled (Phase 3+),
 * the variance shows up automatically.
 */
class FoodCostService
{
    public function theoretical(string $restaurantId, string $start, string $end): array
    {
        $row = Database::getInstance()->fetch(
            'SELECT
                COALESCE(SUM(ps.qty * pc.true_cost_cents), 0)  AS theoretical_cost,
                COALESCE(SUM(ps.gross_cents), 0)                AS revenue,
                COUNT(*)                                         AS lines_with_cost,
                (SELECT COUNT(*) FROM pos_sales ps2
                  WHERE ps2.restaurant_id = ? AND ps2.sold_at BETWEEN ? AND ?) AS total_lines
              FROM pos_sales ps
              LEFT JOIN plate_costs pc ON pc.menu_item_id = ps.menu_item_id
             WHERE ps.restaurant_id = ?
               AND ps.sold_at BETWEEN ? AND ?
               AND pc.true_cost_cents IS NOT NULL',
            [
                $restaurantId, $start . ' 00:00:00', $end . ' 23:59:59',
                $restaurantId, $start . ' 00:00:00', $end . ' 23:59:59',
            ]
        );

        $cost = (int) ($row['theoretical_cost'] ?? 0);
        $rev  = (int) ($row['revenue'] ?? 0);
        $covered = (int) ($row['lines_with_cost'] ?? 0);
        $total   = (int) ($row['total_lines'] ?? 0);

        // Top contributors — items dominating the cost basis. Operator
        // sees where to focus margin work.
        $top = Database::getInstance()->fetchAll(
            'SELECT mi.id AS menu_item_id, mi.name,
                    SUM(ps.qty)                            AS qty_sold,
                    SUM(ps.qty * pc.true_cost_cents)       AS cost_cents,
                    SUM(ps.gross_cents)                    AS revenue_cents
               FROM pos_sales ps
               JOIN menu_items mi ON mi.id = ps.menu_item_id
               JOIN plate_costs pc ON pc.menu_item_id = mi.id
              WHERE ps.restaurant_id = ?
                AND ps.sold_at BETWEEN ? AND ?
              GROUP BY mi.id, mi.name
              ORDER BY cost_cents DESC
              LIMIT 10',
            [$restaurantId, $start . ' 00:00:00', $end . ' 23:59:59']
        );

        return [
            'period_start'        => $start,
            'period_end'          => $end,
            'theoretical_cost_cents' => $cost,
            'revenue_cents'       => $rev,
            'theoretical_pct'     => $rev > 0 ? round($cost / $rev, 4) : 0.0,
            'coverage_pct'        => $total > 0 ? (int) round(($covered / $total) * 100) : 0,
            'top_contributors'    => $top,
            'note'                => 'Theoretical only — actual purchase variance requires invoice ingestion (Phase 3+).',
        ];
    }
}
