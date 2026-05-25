<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\PrivateData\LaborShiftRepository;

/**
 * Labor vs demand. For each (date, hour) bucket in the window:
 *   - covers = count of active labor_shifts overlapping the hour
 *   - revenue_cents = Σ pos_sales.gross_cents in that hour
 *   - revenue_per_cover = revenue / covers
 *
 * Flags windows where revenue_per_cover deviates >2x from the restaurant
 * median — high = understaffed, low = overstaffed. Dollar quantification
 * comes from labor cost (hourly_wage_cents × hours).
 *
 * Also surfaces the slow windows for the daypart demand-filling lens
 * (spec §5.7): bottom-quartile hours by revenue → suggest a testable move.
 */
class LaborDemandService
{
    public function __construct(private LaborShiftRepository $shifts) {}

    public function analyze(string $restaurantId, string $startDate, string $endDate): array
    {
        $start = $startDate . ' 00:00:00';
        $end   = $endDate . ' 23:59:59';

        // Sales per hour.
        $salesRows = Database::getInstance()->fetchAll(
            'SELECT DATE(sold_at) AS d, HOUR(sold_at) AS h,
                    SUM(gross_cents) AS revenue,
                    SUM(qty)         AS units,
                    COUNT(DISTINCT pos_order_id) AS orders
               FROM pos_sales
              WHERE restaurant_id = ? AND sold_at BETWEEN ? AND ?
              GROUP BY DATE(sold_at), HOUR(sold_at)',
            [$restaurantId, $start, $end]
        );

        // Labor shifts in the window — bucket each shift across the hours it covers.
        $shifts = $this->shifts->listInWindow($restaurantId, $start, $end);
        $laborByHour = [];  // "Y-m-d H" => ['covers' => int, 'wage_cents_per_hour' => int]
        foreach ($shifts as $s) {
            $startTs = strtotime((string) $s['starts_at']);
            $endTs   = $s['ends_at'] ? strtotime((string) $s['ends_at']) : $startTs + 8 * 3600;
            $cur = $startTs - ($startTs % 3600); // floor to top of hour
            while ($cur < $endTs) {
                $key = date('Y-m-d H', $cur);
                $laborByHour[$key]['covers'] = ($laborByHour[$key]['covers'] ?? 0) + 1;
                $laborByHour[$key]['wage_cents'] = ($laborByHour[$key]['wage_cents'] ?? 0)
                    + (int) ($s['hourly_wage_cents'] ?? 0);
                $cur += 3600;
            }
        }

        $hours = [];
        $rpcSeries = [];
        foreach ($salesRows as $sr) {
            $key = $sr['d'] . ' ' . str_pad((string) $sr['h'], 2, '0', STR_PAD_LEFT);
            $covers = $laborByHour[$key]['covers'] ?? 0;
            $wage   = $laborByHour[$key]['wage_cents'] ?? 0;
            $rpc    = $covers > 0 ? (int) round((int) $sr['revenue'] / $covers) : 0;
            $hours[] = [
                'date'              => $sr['d'],
                'hour'              => (int) $sr['h'],
                'revenue_cents'     => (int) $sr['revenue'],
                'units'             => (int) $sr['units'],
                'orders'            => (int) $sr['orders'],
                'covers'            => $covers,
                'labor_cost_cents'  => $wage,
                'revenue_per_cover' => $rpc,
            ];
            if ($rpc > 0) $rpcSeries[] = $rpc;
        }
        sort($rpcSeries);
        $median = self::median($rpcSeries);

        $understaffed = [];
        $overstaffed  = [];
        foreach ($hours as $h) {
            if ($h['covers'] === 0) continue;
            if ($median <= 0) continue;
            $ratio = $h['revenue_per_cover'] / $median;
            if ($ratio >= 2.0 && $h['revenue_cents'] > 0) {
                $understaffed[] = $h + ['note' => 'High revenue-per-cover — consider adding staff this hour next week.'];
            } elseif ($ratio <= 0.5) {
                $overstaffed[]  = $h + ['note' => 'Low revenue-per-cover — staff cost is eating margin; trim a cover.'];
            }
        }

        // Daypart demand-filling: bottom-quartile hours by revenue.
        $slow = self::slowHours($hours);

        return [
            'window'        => ['start' => $startDate, 'end' => $endDate],
            'median_rpc'    => (int) round($median),
            'hours'         => $hours,
            'understaffed'  => $understaffed,
            'overstaffed'   => $overstaffed,
            'slow_windows'  => $slow,
        ];
    }

    private static function slowHours(array $hours): array
    {
        $rev = array_map(fn($h) => $h['revenue_cents'], $hours);
        sort($rev);
        if (!$rev) return [];
        $q1 = $rev[(int) floor(count($rev) * 0.25)];
        $slow = [];
        foreach ($hours as $h) {
            if ($h['revenue_cents'] <= $q1 && $h['revenue_cents'] > 0) {
                $slow[] = $h + ['suggestion' => self::suggestFor($h['hour'])];
            }
        }
        return $slow;
    }

    private static function suggestFor(int $hour): string
    {
        return match (true) {
            $hour >= 14 && $hour <= 17 => 'Try a happy hour with a $5 small plate + $7 cocktail anchor.',
            $hour >= 11 && $hour <= 13 => 'Try a $12 prix-fixe lunch — anchor a fixed 3-item combo.',
            $hour >= 21               => 'Late-night menu: 3 items at half-price after 9 with social push.',
            default                   => 'Test a daypart-anchored promo with a single hero item.',
        };
    }

    private static function median(array $sorted): float
    {
        $n = count($sorted);
        if ($n === 0) return 0.0;
        return $n % 2 === 0 ? (($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2.0) : (float) $sorted[(int) ($n / 2)];
    }
}
