<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * Ranges and confidence labels (02_MODEL.md 4.8).
 *
 * Demand has a log-normal predictive distribution whose mean is the expected demand; low and high are its
 * 10th and 90th percentiles. Where capacity binds, the two percentiles are carried through the hourly cap.
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Ranges
{
    /**
     * What the logged services say about how far to trust an estimate at this spot.
     *
     * @param array<string, mixed>|null $cal CalibrationState
     * @return array<string, mixed> Evidence
     */
    public static function evidenceFrom(?array $cal, ?string $spotId): array
    {
        $base = [
            'truck_weight' => 0.0, 'spot_weight' => 0.0, 'resid_sd' => null, 'resid_weight' => 0.0,
            'weak_share' => 0.0, 'default_size_share' => 0.0, 'event' => false, 'fixed' => false,
        ];
        if ($cal === null) {
            return $base;
        }
        $base['truck_weight'] = Num::f($cal['truck_weight']);
        $base['resid_sd'] = $cal['resid_sd'] === null ? null : Num::f($cal['resid_sd']);
        $base['resid_weight'] = Num::f($cal['resid_weight']);
        if ($spotId !== null && array_key_exists($spotId, $cal['spots'])) {
            $base['spot_weight'] = Num::f($cal['spots'][$spotId]['weight']);
        }
        return $base;
    }

    /**
     * An 80 % interval around an expected demand: [Estimate, spread].
     *
     * The spread combines model uncertainty (truck, spot, day, weak seeds, default host size, event), which
     * shrinks with the owner's logged services, and the counting noise of a finite number of orders. The
     * label depends on the model part only.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $ev Evidence
     * @return array{0: array<string, mixed>, 1: array<string, float>}
     */
    public static function interval(array $A, float $mean, array $ev): array
    {
        if ($ev['fixed']) {
            return [
                ['value' => $mean, 'low' => $mean, 'high' => $mean, 'confidence' => 'fixed'],
                [
                    'sigma_model' => 0.0, 'sigma' => 0.0, 'v_truck' => 0.0, 'v_spot' => 0.0, 'v_day' => 0.0,
                    'v_weak' => 0.0, 'v_size' => 0.0, 'v_event' => 0.0, 'v_count' => 0.0,
                ],
            ];
        }
        $kt = Num::f(Seeds::read($A, 'calibration.k_truck'));
        $ks = Num::f(Seeds::read($A, 'calibration.k_spot'));
        $n0 = Num::f(Seeds::read($A, 'uncertainty.resid_prior_weight'));
        $sdTruck = Num::f(Seeds::read($A, 'uncertainty.sd_truck'));
        $sdSpot = Num::f(Seeds::read($A, 'uncertainty.sd_spot'));
        $sdDay = Num::f(Seeds::read($A, 'uncertainty.sd_day'));
        $sdWeak = Num::f(Seeds::read($A, 'uncertainty.sd_weak'));
        $sdDefaultSize = Num::f(Seeds::read($A, 'uncertainty.sd_default_size'));
        $sdEvent = Num::f(Seeds::read($A, 'uncertainty.sd_event'));

        $shrink = $ks / ($ks + Num::f($ev['spot_weight']));
        $vTruck = ($sdTruck * $sdTruck) * $kt / ($kt + Num::f($ev['truck_weight']));
        $vSpot = ($sdSpot * $sdSpot) * $shrink;
        if ($ev['resid_sd'] === null) {
            $vDay = $sdDay * $sdDay;
        } else {
            $residSd = Num::f($ev['resid_sd']);
            $residWeight = Num::f($ev['resid_weight']);
            $vDay = ($n0 * ($sdDay * $sdDay) + $residWeight * ($residSd * $residSd)) / ($n0 + $residWeight);
        }
        $weakSd = Num::f($ev['weak_share']) * $sdWeak;
        $vWeak = ($weakSd * $weakSd) * $shrink;
        $sizeSd = Num::f($ev['default_size_share']) * $sdDefaultSize;
        $vSize = ($sizeSd * $sizeSd) * $shrink;
        $vEvent = $ev['event'] ? $sdEvent * $sdEvent : 0.0;
        $vModel = $vTruck + $vSpot + $vDay + $vWeak + $vSize + $vEvent;                  // added in this order
        $sigmaModel = sqrt($vModel);

        if ($sigmaModel < Seeds::read($A, 'uncertainty.label_good_below')) {
            $confidence = 'good';
        } elseif ($sigmaModel < Seeds::read($A, 'uncertainty.label_fair_below')) {
            $confidence = 'fair';
        } elseif ($sigmaModel < Seeds::read($A, 'uncertainty.label_rough_below')) {
            $confidence = 'rough';
        } else {
            $confidence = 'very_rough';
        }

        $spread = [
            'sigma_model' => $sigmaModel, 'sigma' => $sigmaModel, 'v_truck' => $vTruck, 'v_spot' => $vSpot,
            'v_day' => $vDay, 'v_weak' => $vWeak, 'v_size' => $vSize, 'v_event' => $vEvent, 'v_count' => 0.0,
        ];
        if ($mean <= 0) {
            return [['value' => 0.0, 'low' => 0.0, 'high' => 0.0, 'confidence' => $confidence], $spread];
        }
        $vCount = log(1.0 + Num::f(Seeds::read($A, 'uncertainty.count_dispersion')) / $mean);
        $sigma = sqrt($vModel + $vCount);
        $low = $mean * exp(-0.5 * $sigma * $sigma - Vocab::Z80 * $sigma);
        $high = $mean * exp(-0.5 * $sigma * $sigma + Vocab::Z80 * $sigma);
        if ($high < $mean) {
            $high = $mean;
        }
        $spread['sigma'] = $sigma;
        $spread['v_count'] = $vCount;
        return [['value' => $mean, 'low' => $low, 'high' => $high, 'confidence' => $confidence], $spread];
    }

    /**
     * The interval of a window whose hours have capacities: [Estimate, spread].
     *
     * d[k], c[k]: demand and capacity of loop hour k, each already multiplied by that hour's fraction. The
     * demand of every hour moves by one factor (k_low on a weak day, k_high on a strong one) and each hour's
     * cap is applied again. value is the orders at expected demand.
     *
     * @param array<string, mixed> $A
     * @param array<int, float|int> $d
     * @param array<int, float|int> $c
     * @param array<string, mixed> $ev Evidence
     * @return array{0: array<string, mixed>, 1: array<string, float>}
     */
    public static function intervalCapped(array $A, array $d, array $c, array $ev): array
    {
        $d = array_values($d);
        $c = array_values($c);
        $n = count($d);
        $demand = [];
        $cap = [];
        for ($k = 0; $k < $n; $k++) {
            $demand[] = Num::f($d[$k]);
            $cap[] = Num::f($c[$k]);
        }
        $total = 0.0;
        $value = 0.0;
        for ($k = 0; $k < $n; $k++) {
            $total += $demand[$k];
            $value += $cap[$k] < $demand[$k] ? $cap[$k] : $demand[$k];
        }
        [$e, $spread] = self::interval($A, $total, $ev);              // log-normal on demand, before the cap
        if ($total <= 0) {
            return [['value' => 0.0, 'low' => 0.0, 'high' => 0.0, 'confidence' => $e['confidence']], $spread];
        }
        $kLow = $e['low'] / $total;
        $kHigh = $e['high'] / $total;
        $low = 0.0;
        $high = 0.0;
        for ($k = 0; $k < $n; $k++) {
            $weak = $kLow * $demand[$k];
            $strong = $kHigh * $demand[$k];
            $low += $cap[$k] < $weak ? $cap[$k] : $weak;
            $high += $cap[$k] < $strong ? $cap[$k] : $strong;
        }
        return [
            [
                'value' => $value,
                'low' => $value < $low ? $value : $low,
                'high' => $value > $high ? $value : $high,
                'confidence' => $e['confidence'],
            ],
            $spread,
        ];
    }
}
