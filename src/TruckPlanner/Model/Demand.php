<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * Hourly demand and orders, service windows, the week strip and the best windows (02_MODEL.md 4.7).
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Demand
{
    /**
     * [truck_factor, spot_factor]: what the owner's logged services say (4.13). [1.0, 1.0] without logs.
     *
     * @param array<string, mixed>|null $cal CalibrationState
     * @return array{0: float, 1: float}
     */
    public static function calibrationFactor(?array $cal, ?string $spotId): array
    {
        if ($cal === null) {
            return [1.0, 1.0];
        }
        if ($spotId !== null && array_key_exists($spotId, $cal['spots'])) {
            return [Num::f($cal['truck_factor']), Num::f($cal['spots'][$spotId]['factor'])];
        }
        return [Num::f($cal['truck_factor']), 1.0];
    }

    /**
     * Expected orders in one clock hour of one context, with every step of the breakdown.
     *
     *   demand_raw   before weather and calibration
     *   demand_adj   after them, before the capacity cap
     *   orders       after the cap, applied once to the hour's total; demand above capacity is lost
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $terms SpotTerms
     * @param array<string, mixed> $vectors LocationVectors
     * @param array<string, mixed>|null $cal CalibrationState
     * @param array<string, mixed> $ctx DayContext
     * @return array<string, mixed> HourResult
     */
    public static function hourlyOrders(
        array $A,
        array $profile,
        array $terms,
        array $vectors,
        ?array $cal,
        array $ctx,
        int $hour,
        ?Memo $memo = null
    ): array {
        if ($hour < 0 || $hour > 23) {
            throw new \OutOfRangeException('hour must be 0..23');
        }
        [$presenceAt, $intentAt] = Curves::hourColumn($A, $ctx, $hour, $memo);
        $regime = Seeds::read($A, 'hours.regime_of_hour')[$hour];
        $daypart = Seeds::read($A, 'hours.daypart_of_hour')[$hour];
        $fit = Num::f($profile['daypart_fit'][$daypart]);
        if ($ctx['typical']) {
            $wxOpen = 1.0;
            $wxCap = 1.0;
            $weatherState = 'typical';
            $detailOpen = null;
            $detailCap = null;
        } else {
            $forecast = $ctx['forecast'] ?? null;
            $fc = $forecast !== null ? ($forecast[$hour] ?? null) : null;
            $classified = Weather::classify($A, $fc);      // the same bands and class for both settings
            $detailOpen = Weather::detail($A, $classified, 'open');
            $detailCap = Weather::detail($A, $classified, 'captive');
            $wxOpen = $detailOpen['multiplier'];
            $wxCap = $detailCap['multiplier'];
            $weatherState = $detailOpen['missing'] ? 'missing' : 'forecast';
        }
        [$tf, $sf] = self::calibrationFactor($cal, $terms['spot_id']);
        $calib = $tf * $sf;

        $capture = Num::perSegment($vectors['capture'][$regime]);
        $nearby = Num::perSegment($vectors['nearby']);
        $within = ($vectors['within'] ?? null) !== null   // null when decoded from 50 stored numbers
            ? Num::perSegment($vectors['within'])
            : null;
        $segmentSeeds = $A['seeds']['segments'];
        $demandRaw = 0.0;
        $demandAdj = 0.0;
        $weak = 0.0;
        $defaultPart = 0.0;
        $segments = [];
        for ($s = 0; $s < Vocab::NSEG; $s++) {
            $name = Vocab::SEGMENTS[$s];
            $presence = $presenceAt[$s];
            $intent = $intentAt[$s];
            $rawS = $capture[$s] * $presence * $intent * $fit;
            $adjS = $rawS * $wxOpen * $calib;
            $demandRaw += $rawS;
            $demandAdj += $adjS;
            if ($segmentSeeds[$name]['weak']) {
                $weak += $adjS;
            }
            $segments[] = [
                'segment' => $name,
                'nearby_present' => $nearby[$s] * $presence,
                'within_present' => $within !== null ? $within[$s] * $presence : null,
                'capture' => $capture[$s],
                'presence' => $presence,
                'intent' => $intent,
                'demand_raw' => $rawS,
                'before_cap' => $adjS,
                'orders' => 0.0,
            ];
        }

        $hostRow = null;
        $host = $terms['host'];
        if ($host !== null && Num::f($host['size']) > 0) {
            $size = Num::f($host['size']);
            $hc = Capture::hostCapture($A, $host, $terms['visibility'], $vectors['rivals']);
            $hs = Vocab::segmentIndex($host['segment']);
            $presence = $presenceAt[$hs];
            $intent = $intentAt[$hs];
            $rawH = $hc[$regime] * $presence * $intent * $fit;
            $wxH = $hc['mode'] === 'captive' ? $wxCap : $wxOpen;
            $adjH = $rawH * $wxH * $calib;
            $demandRaw += $rawH;                          // the host is added after the 16 segments
            $demandAdj += $adjH;
            if ($segmentSeeds[$host['segment']]['weak']) {
                $weak += $adjH;
            }
            if ($host['size_source'] === 'default') {
                $defaultPart = $adjH;
            }
            $hostRow = [
                'segment' => $host['segment'],
                'mode' => $hc['mode'],
                'size' => $size,
                'share' => $hc['share'][$regime],
                'people_present' => $size * $presence,
                'presence' => $presence,
                'intent' => $intent,
                'demand_raw' => $rawH,
                'weather' => $wxH,
                'before_cap' => $adjH,
                'orders' => 0.0,
            ];
        }

        $capacity = Num::f($profile['capacity_orders_per_hour']);
        $orders = $capacity < $demandAdj ? $capacity : $demandAdj;
        $capped = $demandAdj > $capacity;
        $scale = $demandAdj > 0 ? $orders / $demandAdj : 0.0;
        for ($s = 0; $s < Vocab::NSEG; $s++) {            // who the customers would be, after the cap
            $segments[$s]['orders'] = $segments[$s]['before_cap'] * $scale;
        }
        if ($hostRow !== null) {
            $hostRow['orders'] = $hostRow['before_cap'] * $scale;
        }

        return [
            'date' => $ctx['date'],
            'hour' => $hour,
            'how' => Num::i($ctx['dow']) * 24 + $hour,
            'regime' => $regime,
            'daypart' => $daypart,
            'segments' => $segments,
            'host' => $hostRow,
            'factors' => [
                'menu_fit' => $fit,
                'weather_open' => $wxOpen,
                'weather_captive' => $wxCap,
                'weather_state' => $weatherState,
                'weather_detail' => $detailOpen,
                'weather_detail_captive' => $detailCap,
                'truck_factor' => $tf,
                'spot_factor' => $sf,
            ],
            'demand_raw' => $demandRaw,
            'demand_adj' => $demandAdj,
            'capacity' => $capacity,
            'orders' => $orders,
            'capped' => $capped,
            'weak_part' => $weak,
            'default_size_part' => $defaultPart,
        ];
    }

    /**
     * The loop of windowOrders: every clock hour overlapping [open, close), as
     * [day_index, hour, start, end, fraction]. Nothing when open == close.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: float}>
     */
    public static function clockHours(int $open, int $close): array
    {
        $out = [];
        if ($open === $close) {
            return $out;
        }
        $hAbs = Num::floorDiv($open, 60);
        while ($hAbs * 60 < $close) {
            $start = $open > $hAbs * 60 ? $open : $hAbs * 60;
            $end = $close < ($hAbs + 1) * 60 ? $close : ($hAbs + 1) * 60;
            $dayIndex = Num::floorDiv($hAbs, 24);
            $out[] = [$dayIndex, $hAbs - 24 * $dayIndex, $start, $end, ($end - $start) / 60.0];
            $hAbs += 1;
        }
        return $out;
    }

    /**
     * Expected orders over a service window [open, close) in minutes from local midnight of ctx.date. Hours
     * at or after 1440 belong to the next civil date and use ctx_next. A partial hour contributes its
     * fraction of that hour's capped orders.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $terms SpotTerms
     * @param array<string, mixed> $vectors LocationVectors
     * @param array<string, mixed>|null $cal CalibrationState
     * @param array<string, mixed> $ctx DayContext
     * @param array<string, mixed>|null $ctxNext DayContext of the next civil date
     * @return array<string, mixed> WindowResult
     */
    public static function windowOrders(
        array $A,
        array $profile,
        array $terms,
        array $vectors,
        ?array $cal,
        array $ctx,
        ?array $ctxNext,
        int $open,
        int $close,
        ?Memo $memo = null
    ): array {
        if (!(0 <= $open && $open <= $close && $close <= 2880)) {
            throw new ModelError(ModelError::INVALID_WINDOW);
        }
        $memo ??= new Memo();
        $adjTotal = 0.0;
        $weak = 0.0;
        $dflt = 0.0;
        $capTotal = 0.0;
        $hostOrders = 0.0;
        $bySegment = array_fill(0, Vocab::NSEG, 0.0);
        $cappedHours = 0;
        $hours = [];
        $d = [];
        $c = [];
        foreach (self::clockHours($open, $close) as [$dayIndex, $hour, $start, $end, $fraction]) {
            $cx = $dayIndex === 0 ? $ctx : $ctxNext;
            if ($cx === null) {
                throw new ModelError(ModelError::MISSING_CONTEXT);
            }
            $r = self::hourlyOrders($A, $profile, $terms, $vectors, $cal, $cx, $hour, $memo);
            $d[] = $r['demand_adj'] * $fraction;
            $c[] = $r['capacity'] * $fraction;
            $adjTotal += $r['demand_adj'] * $fraction;
            $capTotal += $r['capacity'] * $fraction;
            $weak += $r['weak_part'] * $fraction;
            $dflt += $r['default_size_part'] * $fraction;
            for ($s = 0; $s < Vocab::NSEG; $s++) {
                $bySegment[$s] += $r['segments'][$s]['orders'] * $fraction;
            }
            if ($r['host'] !== null) {
                $hostOrders += $r['host']['orders'] * $fraction;
            }
            if ($r['capped']) {
                $cappedHours += 1;
            }
            $hours[] = ['day_index' => $dayIndex, 'hour' => $hour, 'fraction' => $fraction, 'result' => $r];
        }
        $evidence = Ranges::evidenceFrom($cal, $terms['spot_id']);
        $evidence['weak_share'] = $adjTotal > 0 ? $weak / $adjTotal : 0.0;
        $evidence['default_size_share'] = $adjTotal > 0 ? $dflt / $adjTotal : 0.0;
        [$orders, $spread] = Ranges::intervalCapped($A, $d, $c, $evidence);
        return [
            'date' => $ctx['date'],
            'open_minute' => $open,
            'close_minute' => $close,
            'minutes' => $close - $open,
            'hours' => $hours,
            'orders' => $orders,
            'by_segment' => $bySegment,
            'host_orders' => $hostOrders,
            'demand_adj' => $adjTotal,
            'capacity_total' => $capTotal,
            'capped_hours' => $cappedHours,
            'evidence' => $evidence,
            'spread' => $spread,
        ];
    }

    /**
     * Expected orders for each of the 168 hours of a typical week (no date, no weather).
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $terms
     * @param array<string, mixed> $vectors
     * @param array<string, mixed>|null $cal
     * @return list<float>
     */
    public static function weekStrip(array $A, array $profile, array $terms, array $vectors, ?array $cal): array
    {
        $memo = new Memo();
        $out = [];
        for ($dow = 0; $dow < 7; $dow++) {
            $cx = Contexts::typicalContext($A, $dow);
            for ($hour = 0; $hour < 24; $hour++) {
                $out[] = self::hourlyOrders($A, $profile, $terms, $vectors, $cal, $cx, $hour, $memo)['orders'];
            }
        }
        return $out;
    }

    /**
     * Greedy: the best run of `length` consecutive values, then the best that does not overlap it, and so
     * on. Ties go to the earlier start. Windows whose total rounds to zero millionths are never returned.
     *
     * @param array<int, float|int> $values
     * @param array<int, mixed>|null $allowed
     * @return list<array{start: int, length: int, total: float}>
     */
    public static function bestWindows(array $values, int $length, int $topN, bool $circular, ?array $allowed = null): array
    {
        $values = array_values($values);
        if ($allowed !== null) {
            $allowed = array_values($allowed);
        }
        $n = count($values);
        if ($length > $n) {
            return [];
        }
        $candidates = [];
        $lastStart = $circular ? $n - 1 : $n - $length;
        for ($start = 0; $start <= $lastStart; $start++) {
            $total = 0.0;
            $ok = true;
            for ($k = 0; $k < $length; $k++) {
                $i = $start + $k;
                if ($i >= $n) {
                    $i -= $n;
                }
                if ($allowed !== null && !$allowed[$i]) {
                    $ok = false;
                    break;
                }
                $total += Num::f($values[$i]);
            }
            if ($ok) {
                $rank = Num::rank($total);
                if ($rank > 0) {
                    $candidates[] = [$rank, $start, $total];
                }
            }
        }
        usort($candidates, static fn (array $a, array $b): int => ($b[0] <=> $a[0]) ?: ($a[1] <=> $b[1]));
        $picked = [];
        $used = array_fill(0, $n, false);
        foreach ($candidates as [$rank, $start, $total]) {
            if (count($picked) === $topN) {
                break;
            }
            $indexes = [];
            for ($k = 0; $k < $length; $k++) {
                $i = $start + $k;
                if ($i >= $n) {
                    $i -= $n;
                }
                $indexes[] = $i;
            }
            $clash = false;
            foreach ($indexes as $i) {
                if ($used[$i]) {
                    $clash = true;
                }
            }
            if ($clash) {
                continue;
            }
            foreach ($indexes as $i) {
                $used[$i] = true;
            }
            $picked[] = ['start' => $start, 'length' => $length, 'total' => $total];
        }
        return $picked;
    }
}
