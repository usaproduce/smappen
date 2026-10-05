<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * The money of a stop and the day's own costs (02_MODEL.md 4.9). Dollars, full precision.
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class MoneyLines
{
    /** @var list<string> */
    public const LINES = ['orders', 'sales', 'food_cost', 'packaging', 'card_fees', 'spot_fee', 'tips', 'contribution'];

    /**
     * The money lines of one stop at one number of orders. The spot fee is fee_flat + fee_pct * sales with
     * fee_min as a floor on the total. contribution is what the stop leaves before the day's own costs.
     *
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $terms SpotTerms
     * @return array<string, float>
     */
    public static function stopMoneyAt(array $profile, array $terms, float $orders): array
    {
        $cardShare = Num::f($profile['card_share']);
        $sales = $orders * Num::f($profile['avg_ticket']);
        $foodCost = $sales * Num::f($profile['food_cost_pct']);
        $packaging = $orders * Num::f($profile['packaging_per_order']);
        $cardFees = $sales * $cardShare * Num::f($profile['card_fee_pct'])
            + $orders * $cardShare * Num::f($profile['card_fee_fixed']);
        $feeMin = Num::f($terms['fee_min']);
        $fee = Num::f($terms['fee_flat']) + Num::f($terms['fee_pct']) * $sales;
        $spotFee = $fee > $feeMin ? $fee : $feeMin;
        $tips = $profile['tips_include'] ? $sales * $cardShare * Num::f($profile['tips_pct_of_card_sales']) : 0.0;
        $contribution = $sales - $foodCost - $packaging - $cardFees - $spotFee + $tips;
        return [
            'orders' => $orders, 'sales' => $sales, 'food_cost' => $foodCost, 'packaging' => $packaging,
            'card_fees' => $cardFees, 'spot_fee' => $spotFee, 'tips' => $tips, 'contribution' => $contribution,
        ];
    }

    /**
     * Contribution per extra order: while the minimum fee is what is paid, and once flat + percentage is.
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $terms
     * @return array{at_minimum: float, at_percentage: float}
     */
    public static function unitMargins(array $profile, array $terms): array
    {
        $cardShare = Num::f($profile['card_share']);
        $avgTicket = Num::f($profile['avg_ticket']);
        $tip = $profile['tips_include'] ? $cardShare * Num::f($profile['tips_pct_of_card_sales']) : 0.0;
        $base = $avgTicket * (1.0 - Num::f($profile['food_cost_pct']) - $cardShare * Num::f($profile['card_fee_pct']) + $tip)
            - Num::f($profile['packaging_per_order']) - $cardShare * Num::f($profile['card_fee_fixed']);
        return ['at_minimum' => $base, 'at_percentage' => $base - $avgTicket * Num::f($terms['fee_pct'])];
    }

    /**
     * StopMoney for an orders Estimate: every line at value, low and high.
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $terms
     * @param array<string, mixed> $orders Estimate
     * @return array<string, mixed> StopMoney
     */
    public static function stopMoney(array $profile, array $terms, array $orders): array
    {
        $v = self::stopMoneyAt($profile, $terms, Num::f($orders['value']));
        $l = self::stopMoneyAt($profile, $terms, Num::f($orders['low']));
        $h = self::stopMoneyAt($profile, $terms, Num::f($orders['high']));
        $out = [];
        foreach (self::LINES as $line) {
            $out[$line] = Estimates::levels($v[$line], $l[$line], $h[$line], $orders['confidence']);
        }
        $out['unit_margin'] = self::unitMargins($profile, $terms);
        return $out;
    }

    /**
     * Orders at which a stop's contribution equals fixed_costs (a real; show it rounded up), or null when
     * no number of orders gets there.
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $terms
     */
    public static function breakEvenOrders(array $profile, array $terms, float $fixedCosts): ?float
    {
        $m = self::unitMargins($profile, $terms);
        if ($m['at_minimum'] <= 0) {
            return null;
        }
        $feeMin = Num::f($terms['fee_min']);
        $feeFlat = Num::f($terms['fee_flat']);
        $x = ($fixedCosts + $feeMin) / $m['at_minimum'];              // the minimum fee is what is paid
        if ($feeFlat + Num::f($terms['fee_pct']) * Num::f($profile['avg_ticket']) * $x <= $feeMin) {
            return 0.0 > $x ? 0.0 : $x;
        }
        if ($m['at_percentage'] <= 0) {
            return null;
        }
        $y = ($fixedCosts + $feeFlat) / $m['at_percentage'];          // flat + percentage is what is paid
        return 0.0 > $y ? 0.0 : $y;
    }

    /**
     * The day's own costs. Paid crew are paid from the start of prep to "done", except gaps marked unpaid.
     * The owner's own time is not a cost. One fuel price covers truck and generator.
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $timeline Timeline
     * @return array<string, float>
     */
    public static function dayCosts(array $profile, array $timeline, float $fuelPricePerGal): array
    {
        $paidHours = Num::f($timeline['paid_minutes']) / 60.0;
        $labour = $paidHours * Num::f($profile['paid_crew']) * Num::f($profile['wage_per_hour'])
            * (1.0 + Num::f($profile['payroll_burden_pct']));
        $driveGallons = Num::f($timeline['miles']) / Num::f($profile['mpg']);
        $generatorGallons = Num::f($timeline['generator_minutes']) / 60.0 * Num::f($profile['generator_gal_per_hour']);
        $fuel = ($driveGallons + $generatorGallons) * $fuelPricePerGal;
        $tolls = Num::f($timeline['tolls']);
        $fixed = count($timeline['stops']) > 0 ? Num::f($profile['fixed_cost_per_service_day']) : 0.0;
        $total = $labour + $fuel + $tolls + $fixed;
        return [
            'labour' => $labour, 'fuel' => $fuel, 'tolls' => $tolls, 'fixed' => $fixed, 'total' => $total,
            'paid_hours' => $paidHours, 'drive_gallons' => $driveGallons, 'generator_gallons' => $generatorGallons,
        ];
    }
}
