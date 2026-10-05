<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * Attendance-based event estimates and contracted catering stops (02_MODEL.md 4.14).
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Events
{
    /**
     * An attendance-based estimate. It replaces the map-based one entirely: the crowd is the organiser's,
     * not the neighbourhood's. Demand is spread evenly over the window and capped hour by hour; menu fit is
     * not applied; vendors counts every food vendor including this truck.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $ev EventTerms
     * @param array<string, mixed>|null $cal CalibrationState
     * @param array<string, mixed> $ctx DayContext
     * @param array<string, mixed>|null $ctxNext
     * @return array<string, mixed> EventResult
     */
    public static function eventOrders(
        array $A,
        array $profile,
        array $ev,
        ?array $cal,
        array $ctx,
        ?array $ctxNext,
        int $open,
        int $close
    ): array {
        if (!(0 <= $open && $open <= $close && $close <= 2880)) {
            throw new ModelError(ModelError::INVALID_WINDOW);
        }
        $buyers = Num::f($ev['attendance']) * Num::f(Seeds::read($A, 'events.attendance_haircut'))
            * Num::f(Seeds::read($A, 'events.p_buy.' . $ev['event_type']));
        $vendors = Num::f($ev['vendors']);
        $demand = $buyers / ($vendors > 1 ? $vendors : 1.0) * ($cal !== null ? Num::f($cal['truck_factor']) : 1.0);
        $minutes = $close - $open;
        $capacity = Num::f($profile['capacity_orders_per_hour']);
        $hours = [];
        $d = [];
        $c = [];
        foreach (Demand::clockHours($open, $close) as [$dayIndex, $hour, $start, $end, $fraction]) {
            $cx = $dayIndex === 0 ? $ctx : $ctxNext;
            if ($cx === null) {
                throw new ModelError(ModelError::MISSING_CONTEXT);
            }
            if ($cx['typical']) {
                $wx = 1.0;
            } else {
                $forecast = $cx['forecast'] ?? null;
                $fc = $forecast !== null ? ($forecast[$hour] ?? null) : null;
                $wx = Weather::multiplier($A, $fc, 'open')['multiplier'];
            }
            $dH = $demand * ($end - $start) / $minutes * $wx;
            $capH = $capacity * $fraction;
            $d[] = $dH;
            $c[] = $capH;
            $hours[] = [
                'day_index' => $dayIndex,
                'hour' => $hour,
                'fraction' => $fraction,
                'demand' => $dH,
                'capacity' => $capH,
                'weather' => $wx,
                'orders' => $capH < $dH ? $capH : $dH,
            ];
        }
        $evidence = Ranges::evidenceFrom($cal, null);     // events use the truck factor only
        $evidence['event'] = true;
        [$orders, $spread] = Ranges::intervalCapped($A, $d, $c, $evidence);
        return ['orders' => $orders, 'buyers' => $buyers, 'demand' => $demand, 'hours' => $hours, 'spread' => $spread];
    }

    /**
     * A guaranteed-fee stop: revenue is contracted, so every line is fixed and adds nothing to the width of
     * the day's range. It still takes time, fuel and labour through the timeline.
     *
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $ct CateringTerms
     * @return array<string, mixed> StopMoney
     */
    public static function cateringMoney(array $profile, array $ct): array
    {
        $headcount = Num::f($ct['headcount']);
        $price = ($ct['price_per_head'] ?? null) !== null ? Num::f($ct['price_per_head']) : 0.0;
        $guarantee = ($ct['guarantee'] ?? null) !== null ? Num::f($ct['guarantee']) : 0.0;
        $perHead = $headcount * $price;
        $sales = $guarantee > $perHead ? $guarantee : $perHead;
        $orders = $headcount * 1.0;
        $foodCost = ($ct['food_cost'] ?? null) !== null
            ? Num::f($ct['food_cost'])
            : $sales * Num::f($profile['food_cost_pct']);
        $packaging = $headcount * Num::f($profile['packaging_per_order']);
        $contribution = $sales - $foodCost - $packaging;
        return [
            'orders' => Estimates::fixed($orders),
            'sales' => Estimates::fixed($sales),
            'food_cost' => Estimates::fixed($foodCost),
            'packaging' => Estimates::fixed($packaging),
            'card_fees' => Estimates::fixed(0.0),
            'spot_fee' => Estimates::fixed(0.0),
            'tips' => Estimates::fixed(0.0),
            'contribution' => Estimates::fixed($contribution),
            'unit_margin' => ['at_minimum' => 0.0, 'at_percentage' => 0.0],      // not used for a contracted stop
        ];
    }
}
