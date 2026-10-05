<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * Calibration against the owner's logged services, and the accuracy report (02_MODEL.md 4.13).
 *
 * Shrinkage on log ratios of actual to predicted orders, weighted by recency. Ordinary statistics; nothing
 * here is learned by anything other than these sums.
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Calibration
{
    /**
     * What the owner's logged services say: one factor for the truck, one per spot. A sold-out service is a
     * lower bound on demand: it is used only if it says more than the other logs say about its own spot.
     *
     * @param array<string, mixed> $A
     * @param array<int, array<string, mixed>> $services ServiceLogEntry list
     * @return array<string, mixed> CalibrationState
     */
    public static function calibrate(array $A, array $services, mixed $asOf): array
    {
        $kTruck = Num::f(Seeds::read($A, 'calibration.k_truck'));
        $kSpot = Num::f(Seeds::read($A, 'calibration.k_spot'));
        $halfLife = Num::f(Seeds::read($A, 'calibration.half_life_days'));
        $lnRatio = log(Num::f(Seeds::read($A, 'calibration.ratio_clamp')));
        $lnSpotRatio = log(Num::f(Seeds::read($A, 'calibration.spot_ratio_clamp')));
        $minPredicted = Num::f(Seeds::read($A, 'calibration.min_predicted'));
        $minActual = Num::f(Seeds::read($A, 'calibration.min_actual'));
        $asOfDay = Dates::dayNumber($asOf);

        $rows = [];
        $seen = [];
        foreach (self::inLogOrder($services) as $sv) {
            if ($sv['kind'] !== 'spot' || $sv['spot_id'] === null || !(Num::f($sv['predicted_raw']) > 0)) {
                continue;
            }
            $age = $asOfDay - Dates::dayNumber($sv['date']);
            if ($age < 0) {
                continue;
            }
            $predictedRaw = Num::f($sv['predicted_raw']);
            $actual = Num::f($sv['actual']);
            $w = exp(-Vocab::LN2 * $age / $halfLife);
            $R = log(($minActual > $actual ? $minActual : $actual) / ($minActual > $predictedRaw ? $minActual : $predictedRaw));
            $spotId = (string) $sv['spot_id'];
            $rows[] = [
                'spot_id' => $spotId,
                'sold_out' => (bool) $sv['sold_out'],
                'w' => $w,
                'Lt' => Num::clamp($R, -$lnRatio, $lnRatio),              // what the service tells the truck factor
                'Ls' => Num::clamp($R, -$lnSpotRatio, $lnSpotRatio),      // what it tells its own spot
                'in_truck' => $predictedRaw >= $minPredicted,
                'used' => false,
            ];
            $seen[$spotId] = true;
        }
        $spotIds = [];
        foreach ($seen as $spotId => $unused) {
            $spotIds[] = (string) $spotId;
        }
        usort($spotIds, static fn (string $a, string $b): int => strcmp($a, $b));

        // Pass A: services that were not sold out.
        $num = 0.0;
        $den = 0.0;
        foreach ($rows as $r) {
            if ($r['in_truck'] && !$r['sold_out']) {
                $num += $r['w'] * $r['Lt'];
                $den += $r['w'];
            }
        }
        $mA = $num / ($kTruck + $den);
        $sA = [];
        foreach ($spotIds as $spotId) {
            $num = 0.0;
            $den = 0.0;
            foreach ($rows as $r) {
                if ($r['spot_id'] === $spotId && !$r['sold_out']) {
                    $num += $r['w'] * ($r['Ls'] - $mA);
                    $den += $r['w'];
                }
            }
            $sA[$spotId] = $num / ($kSpot + $den);
        }
        foreach ($rows as $k => $r) {
            $rows[$k]['used'] = !$r['sold_out'] || $r['Ls'] > $mA + $sA[$r['spot_id']];
        }

        // Truck factor, from used rows with in_truck.
        $num = 0.0;
        $truckWeight = 0.0;
        $truckN = 0;
        foreach ($rows as $r) {
            if ($r['used'] && $r['in_truck']) {
                $num += $r['w'] * $r['Lt'];
                $truckWeight += $r['w'];
                $truckN += 1;
            }
        }
        $truckLogFactor = $num / ($kTruck + $truckWeight);

        // Spot factors, from every used row of the spot.
        $spots = [];
        foreach ($spotIds as $spotId) {
            $num = 0.0;
            $weight = 0.0;
            $count = 0;
            foreach ($rows as $r) {
                if ($r['used'] && $r['spot_id'] === $spotId) {
                    $num += $r['w'] * ($r['Ls'] - $truckLogFactor);
                    $weight += $r['w'];
                    $count += 1;
                }
            }
            if ($count === 0) {
                continue;
            }
            $logFactor = $num / ($kSpot + $weight);
            $spots[$spotId] = ['factor' => exp($logFactor), 'log_factor' => $logFactor, 'n' => $count, 'weight' => $weight];
        }

        // Residual spread, from used rows with in_truck that were not sold out.
        $num = 0.0;
        $residWeight = 0.0;
        $residN = 0;
        foreach ($rows as $r) {
            if ($r['used'] && $r['in_truck'] && !$r['sold_out']) {
                $resid = $r['Ls'] - $truckLogFactor - $spots[$r['spot_id']]['log_factor'];
                $num += $r['w'] * $resid * $resid;
                $residWeight += $r['w'];
                $residN += 1;
            }
        }
        $residSd = $residN >= Seeds::read($A, 'calibration.min_resid_n') ? sqrt($num / $residWeight) : null;

        // The mean of log ratios estimates a geometric mean; the correction turns the truck factor into a mean.
        $biasLog = $residSd !== null ? 0.5 * $residSd * $residSd * $truckWeight / ($kTruck + $truckWeight) : 0.0;
        $truckFactor = exp($truckLogFactor + $biasLog);
        return [
            'model_version' => $A['model_version'],
            'seeds_revision' => $A['seeds_revision'],
            'as_of' => $asOf,
            'truck_factor' => $truckFactor,
            'truck_log_factor' => $truckLogFactor,
            'bias_log' => $biasLog,
            'truck_n' => $truckN,
            'truck_weight' => $truckWeight,
            'spots' => $spots,
            'resid_sd' => $residSd,
            'resid_n' => $residN,
            'resid_weight' => $residWeight,
        ];
    }

    /**
     * How the estimates did against the logged services. Sold-out services are counted, not scored.
     *
     * @param array<int, array<string, mixed>> $entries ServiceLogEntry list
     * @return array<string, mixed> AccuracyReport
     */
    public static function accuracyReport(array $entries): array
    {
        $entries = array_values($entries);
        $report = self::accuracyBlock($entries);
        $seen = [];
        foreach ($entries as $e) {
            if ($e['spot_id'] !== null) {
                $seen[(string) $e['spot_id']] = true;
            }
        }
        $spotIds = [];
        foreach ($seen as $spotId => $unused) {
            $spotIds[] = (string) $spotId;
        }
        usort($spotIds, static fn (string $a, string $b): int => strcmp($a, $b));
        $bySpot = [];
        foreach ($spotIds as $spotId) {
            $own = [];
            foreach ($entries as $e) {
                if ($e['spot_id'] !== null && (string) $e['spot_id'] === $spotId) {
                    $own[] = $e;
                }
            }
            $block = self::accuracyBlock($own);
            $block['spot_id'] = $spotId;
            $bySpot[] = $block;
        }
        $report['by_spot'] = $bySpot;
        return $report;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed> AccuracyBlock
     */
    private static function accuracyBlock(array $rows): array
    {
        $scored = [];
        foreach (self::inLogOrder($rows) as $e) {
            if (!$e['sold_out']) {
                $scored[] = $e;
            }
        }
        $block = [
            'n_total' => count($rows),
            'n_scored' => count($scored),
            'n_sold_out' => count($rows) - count($scored),
            'bias' => null, 'mape' => null, 'coverage' => null, 'raw_bias' => null, 'raw_mape' => null,
        ];
        if ($scored === []) {
            return $block;
        }
        $sumActual = 0.0;
        $sumDiff = 0.0;
        $sumApe = 0.0;
        $rawDiff = 0.0;
        $rawApe = 0.0;
        $inside = 0;
        foreach ($scored as $e) {
            $actual = Num::f($e['actual']);
            $predicted = Num::f($e['predicted']);
            $predictedRaw = Num::f($e['predicted_raw']);
            $floor = 1.0 > $actual ? 1.0 : $actual;
            $sumActual += $actual;
            $sumDiff += $predicted - $actual;
            $sumApe += abs($predicted - $actual) / $floor;
            $rawDiff += $predictedRaw - $actual;
            $rawApe += abs($predictedRaw - $actual) / $floor;
            if (Num::f($e['low']) <= $actual && $actual <= Num::f($e['high'])) {
                $inside += 1;
            }
        }
        if ($sumActual != 0) {
            $block['bias'] = $sumDiff / $sumActual;                   // > 0: the model predicted too much
            $block['raw_bias'] = $rawDiff / $sumActual;
        }
        $nScored = (float) count($scored);
        $block['mape'] = $sumApe / $nScored;
        $block['raw_mape'] = $rawApe / $nScored;
        $block['coverage'] = $inside / $nScored;
        return $block;
    }

    /**
     * The entries in (date ascending, service_id ascending) order, byte-wise; equal keys keep their order.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private static function inLogOrder(array $entries): array
    {
        $keyed = [];
        $position = 0;
        foreach ($entries as $entry) {
            $keyed[] = [(string) $entry['date'], (string) $entry['service_id'], $position, $entry];
            $position += 1;
        }
        usort(
            $keyed,
            static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: (strcmp($a[1], $b[1]) ?: ($a[2] <=> $b[2]))
        );
        $out = [];
        foreach ($keyed as $item) {
            $out[] = $item[3];
        }
        return $out;
    }
}
