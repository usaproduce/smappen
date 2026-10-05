<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Model;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use PHPUnit\Framework\TestCase;

/**
 * 02_MODEL.md 1.5: sums are plain left-to-right additions in the stated order, and multiplications and
 * divisions run in the order written. A different order changes the last bit of a result, which the
 * golden tolerance (1e-9) cannot see.
 *
 * Each test here recomputes one formula of the document from the function's own inputs and outputs, in the
 * documented order, with +, -, *, / and sqrt only (IEEE-754 pins those down on every machine), and demands
 * the very same double. Each also proves that it would notice: with the numbers it uses, another order
 * gives another double.
 */
final class OperationOrderTest extends TestCase
{
    /** 4.7: raw_s = capture * presence * intent * fit; adj_s = raw_s * weather * (truck * spot); segments 0..15, then the host. */
    public function testHourlyOrdersMultipliesAndAddsInTheDocumentedOrder(): void
    {
        $A = self::assumptions();
        $forecast = array_fill(0, 24, null);
        $forecast[12] = ['hour' => 12, 'temp_f' => 45, 'precip_prob' => 80, 'short_forecast' => 'Rain', 'wind_mph' => 22];
        $ctx = Estimator::dayContext($A, '2026-10-08', null, $forecast, null, null);
        $profile = self::profile();
        $profile['daypart_fit']['lunch'] = 0.7;
        $profile['capacity_orders_per_hour'] = 41.3;                     // binds, so the rows are scaled
        $terms = self::terms('B', self::host('v_nightlife', 123.4, false));

        $r = Estimator::hourlyOrders($A, $profile, $terms, self::vectors(), self::calibration(), $ctx, 12);

        $fit = $r['factors']['menu_fit'];
        $weatherOpen = $r['factors']['weather_open'];
        $weatherCaptive = $r['factors']['weather_captive'];
        $calib = $r['factors']['truck_factor'] * $r['factors']['spot_factor'];
        self::assertSame(0.7, $fit);
        self::assertNotSame(1.0, $weatherOpen);
        self::assertNotSame($weatherOpen, $weatherCaptive);
        self::assertNotSame(1.0, $calib);

        $raw = 0.0;
        $adj = 0.0;
        $rawOtherOrder = 0.0;
        $regrouped = 0;
        foreach ($r['segments'] as $row) {
            $rawS = $row['capture'] * $row['presence'] * $row['intent'] * $fit;
            $adjS = $rawS * $weatherOpen * $calib;
            self::assertSame($rawS, $row['demand_raw'], $row['segment']);
            self::assertSame($adjS, $row['before_cap'], $row['segment']);
            $raw += $rawS;
            $adj += $adjS;
            $regrouped += ($rawS * ($weatherOpen * $calib) !== $adjS) ? 1 : 0;
            $regrouped += ($row['capture'] * ($row['presence'] * $row['intent']) * $fit !== $rawS) ? 1 : 0;
        }
        foreach (array_reverse($r['segments']) as $row) {
            $rawOtherOrder += $row['demand_raw'];
        }
        $host = $r['host'];
        self::assertSame('captive', $host['mode']);
        $rawH = $host['size'] * $host['share'] * $host['presence'] * $host['intent'] * $fit;
        $adjH = $rawH * $weatherCaptive * $calib;
        self::assertSame($rawH, $host['demand_raw']);
        self::assertSame($adjH, $host['before_cap']);
        self::assertSame($raw + $rawH, $r['demand_raw']);                // the host is added after the 16 segments
        self::assertSame($adj + $adjH, $r['demand_adj']);

        self::assertTrue($r['capped']);
        self::assertSame(41.3, $r['orders']);
        $scale = $r['orders'] / $r['demand_adj'];
        foreach ($r['segments'] as $row) {
            self::assertSame($row['before_cap'] * $scale, $row['orders'], $row['segment']);
        }
        self::assertSame($host['before_cap'] * $scale, $host['orders']);

        self::assertGreaterThan(0, $regrouped, 'with these numbers a regrouped product must differ somewhere');
        self::assertNotSame($raw, $rawOtherOrder, 'with these numbers a reversed sum must differ');
    }

    /** 4.17: w_opp = presence * intent * fit * truck factor, left to right. */
    public function testMapWeightRowsMultiplyInTheDocumentedOrder(): void
    {
        $A = self::assumptions();
        $profile = self::profile();
        $cal = self::calibration();
        $rows = Estimator::mapWeightRows($A, $profile, $cal);
        $curves = Estimator::expandCurves($A);
        $daypartOfHour = Estimator::seed($A, 'hours.daypart_of_hour');

        $regrouped = 0;
        for ($how = 0; $how < 168; $how++) {
            $fit = $profile['daypart_fit'][$daypartOfHour[$how % 24]];
            for ($s = 0; $s < 16; $s++) {
                $presence = $curves['presence'][$s][$how];
                $intent = $curves['intent'][$s][$how];
                $expected = $presence * $intent * $fit * $cal['truck_factor'];
                self::assertSame($expected, $rows['w_opp'][$how][$s], "w_opp[$how][$s]");
                self::assertSame($presence, $rows['w_people'][$how][$s], "w_people[$how][$s]");
                $regrouped += ($presence * $intent * ($fit * $cal['truck_factor']) !== $expected) ? 1 : 0;
            }
        }
        self::assertGreaterThan(0, $regrouped, 'with these numbers a regrouped product must differ somewhere');
    }

    /** 4.16: the strip adds the 16 segments in order, then the host, then caps. */
    public function testStripFromRowsAddsTheHostAfterTheSegments(): void
    {
        $A = self::assumptions();
        $profile = self::profile();
        $vectors = self::vectors();
        $terms = self::terms(null, self::host('w_office', 612.5, false));
        $rows = Estimator::mapWeightRows($A, $profile, self::calibration());
        $regimeOfHour = Estimator::seed($A, 'hours.regime_of_hour');
        $hc = Estimator::hostCapture($A, $terms['host'], 'normal', $vectors['rivals']);
        self::assertSame('open', $hc['mode']);

        $roomy = $profile;
        $roomy['capacity_orders_per_hour'] = 1.0e12;                     // no hour is capped: the sums show
        $uncapped = Estimator::stripFromRows($A, $roomy, $terms, $vectors, $rows);
        $strip = Estimator::stripFromRows($A, $profile, $terms, $vectors, $rows);

        self::assertCount(168, $uncapped);
        self::assertCount(168, $strip);
        $capacity = $profile['capacity_orders_per_hour'];
        $hostFirstDiffers = 0;
        $capped = 0;
        for ($how = 0; $how < 168; $how++) {
            $regime = $regimeOfHour[$how % 24];
            $o = 0.0;
            for ($s = 0; $s < 16; $s++) {
                $o += $vectors['capture'][$regime][$s] * $rows['w_opp'][$how][$s];
            }
            $withHost = $o + $hc[$regime] * $rows['w_opp'][$how][1];
            $hostFirst = $hc[$regime] * $rows['w_opp'][$how][1];
            for ($s = 0; $s < 16; $s++) {
                $hostFirst += $vectors['capture'][$regime][$s] * $rows['w_opp'][$how][$s];
            }
            self::assertSame($withHost, $uncapped[$how], "hour $how");
            self::assertSame($capacity < $withHost ? $capacity : $withHost, $strip[$how], "hour $how, capped");
            $hostFirstDiffers += ($hostFirst !== $withHost) ? 1 : 0;
            $capped += ($withHost > $capacity) ? 1 : 0;
        }
        self::assertGreaterThan(0, $hostFirstDiffers, 'with these numbers adding the host first must differ somewhere');
        self::assertGreaterThan(0, $capped);
    }

    /** 4.8: every variance part as written, and sigma_model = sqrt(v_truck + v_spot + v_day + v_weak + v_size + v_event). */
    public function testIntervalAddsTheVariancePartsInTheDocumentedOrder(): void
    {
        $A = self::assumptions();
        $ev = [
            'truck_weight' => 3.3, 'spot_weight' => 1.7, 'resid_sd' => 0.27, 'resid_weight' => 4.4,
            'weak_share' => 0.13, 'default_size_share' => 0.29, 'event' => true, 'fixed' => false,
        ];
        [$estimate, $spread] = Estimator::interval($A, 37.5, $ev);

        $kt = Estimator::seed($A, 'calibration.k_truck');
        $ks = Estimator::seed($A, 'calibration.k_spot');
        $n0 = Estimator::seed($A, 'uncertainty.resid_prior_weight');
        $sdTruck = Estimator::seed($A, 'uncertainty.sd_truck');
        $sdSpot = Estimator::seed($A, 'uncertainty.sd_spot');
        $sdDay = Estimator::seed($A, 'uncertainty.sd_day');
        $sdWeak = Estimator::seed($A, 'uncertainty.sd_weak');
        $sdSize = Estimator::seed($A, 'uncertainty.sd_default_size');
        $sdEvent = Estimator::seed($A, 'uncertainty.sd_event');
        $shrink = $ks / ($ks + 1.7);

        self::assertSame(($sdTruck * $sdTruck) * $kt / ($kt + 3.3), $spread['v_truck']);
        self::assertSame(($sdSpot * $sdSpot) * $shrink, $spread['v_spot']);
        self::assertSame(($n0 * ($sdDay * $sdDay) + 4.4 * (0.27 * 0.27)) / ($n0 + 4.4), $spread['v_day']);
        self::assertSame(((0.13 * $sdWeak) * (0.13 * $sdWeak)) * $shrink, $spread['v_weak']);
        self::assertSame(((0.29 * $sdSize) * (0.29 * $sdSize)) * $shrink, $spread['v_size']);
        self::assertSame($sdEvent * $sdEvent, $spread['v_event']);

        $sum = $spread['v_truck'] + $spread['v_spot'] + $spread['v_day'] + $spread['v_weak'] + $spread['v_size'] + $spread['v_event'];
        $reversed = $spread['v_event'] + $spread['v_size'] + $spread['v_weak'] + $spread['v_day'] + $spread['v_spot'] + $spread['v_truck'];
        self::assertSame(sqrt($sum), $spread['sigma_model']);
        self::assertSame(sqrt($sum + $spread['v_count']), $spread['sigma']);
        self::assertNotSame(sqrt($sum), sqrt($reversed), 'with these numbers a reversed sum must change sigma_model');
        self::assertSame(37.5, $estimate['value']);
        self::assertSame('very_rough', $estimate['confidence']);
    }

    /**
     * 4.4: sources are processed in ascending id whatever order they arrive in, and
     * share = f * V / (A0 + f * V + rivals). All three points stand at the truck (d = 0, f = exp(0) = 1), so
     * nothing here depends on a library function.
     */
    public function testCaptureAddsSourcesInAscendingIdAndFormsTheShareAsWritten(): void
    {
        $A = self::assumptions();
        $lat = 38.96;
        $lng = -77.36;
        $base = static function (float $scale): array {
            $out = [];
            for ($s = 0; $s < 16; $s++) {
                $out[] = $scale * (0.1 + 0.37 * $s);
            }
            return $out;
        };
        $sources = [
            ['id' => 'c', 'lat' => $lat, 'lng' => $lng, 'base' => $base(301.7), 'rivals' => ['day' => 0.1, 'eve' => 2.3]],
            ['id' => 'a', 'lat' => $lat, 'lng' => $lng, 'base' => $base(7.3), 'rivals' => ['day' => 0.7, 'eve' => 0.9]],
            ['id' => 'b', 'lat' => $lat, 'lng' => $lng, 'base' => $base(1234.5), 'rivals' => ['day' => 1.9, 'eve' => 0.3]],
        ];
        $none = ['point_ids' => [], 'segment' => null, 'amount' => 0.0];

        $v = Estimator::captureAtPoint($A, $lat, $lng, 'prominent', $sources, [], $none);

        self::assertSame(3, $v['points_used']);
        $V = Estimator::seed($A, 'kernel.visibility.prominent');
        $A0 = Estimator::seed($A, 'kernel.outside_option_a0');
        $f = 1.0;
        $byId = ['a' => $sources[1], 'b' => $sources[2], 'c' => $sources[0]];
        $inputOrderDiffers = 0;
        $regrouped = 0;
        foreach (['day', 'eve'] as $regime) {
            for ($s = 0; $s < 16; $s++) {
                $sum = 0.0;
                foreach ($byId as $source) {
                    $share = $f * $V / ($A0 + $f * $V + $source['rivals'][$regime]);
                    $sum += $source['base'][$s] * $share;
                    $regrouped += ($f * $V / ($A0 + ($f * $V + $source['rivals'][$regime])) !== $share) ? 1 : 0;
                }
                self::assertSame($sum, $v['capture'][$regime][$s], "capture.$regime.$s");
                $other = 0.0;
                foreach ($sources as $source) {
                    $other += $source['base'][$s] * ($f * $V / ($A0 + $f * $V + $source['rivals'][$regime]));
                }
                $inputOrderDiffers += ($other !== $sum) ? 1 : 0;
            }
        }
        for ($s = 0; $s < 16; $s++) {
            $sum = 0.0;
            foreach ($byId as $source) {
                $sum += $source['base'][$s];
            }
            self::assertSame($sum, $v['within'][$s], "within.$s");
            self::assertSame($sum, $v['nearby'][$s], "nearby.$s (f = 1)");
        }
        self::assertGreaterThan(0, $inputOrderDiffers, 'with these numbers the order of the list must matter somewhere');
        self::assertGreaterThan(0, $regrouped, 'with these numbers a regrouped denominator must differ somewhere');
    }

    /** 4.9: every money line as written. */
    public function testMoneyLinesAreFormedAsWritten(): void
    {
        $p = self::profile();
        $p['tips_include'] = true;
        $terms = self::terms(null, null);
        $terms['fee_flat'] = 12.3;
        $terms['fee_pct'] = 0.07;
        $terms['fee_min'] = 20.0;
        $orders = 41.37;

        $m = Estimator::stopMoneyAt($p, $terms, $orders);

        $sales = $orders * $p['avg_ticket'];
        $foodCost = $sales * $p['food_cost_pct'];
        $packaging = $orders * $p['packaging_per_order'];
        $cardFees = $sales * $p['card_share'] * $p['card_fee_pct'] + $orders * $p['card_share'] * $p['card_fee_fixed'];
        $spotFee = $terms['fee_flat'] + $terms['fee_pct'] * $sales;
        $tips = $sales * $p['card_share'] * $p['tips_pct_of_card_sales'];
        self::assertGreaterThan($terms['fee_min'], $spotFee);
        self::assertSame($sales, $m['sales']);
        self::assertSame($foodCost, $m['food_cost']);
        self::assertSame($packaging, $m['packaging']);
        self::assertSame($cardFees, $m['card_fees']);
        self::assertSame($spotFee, $m['spot_fee']);
        self::assertSame($tips, $m['tips']);
        self::assertSame($sales - $foodCost - $packaging - $cardFees - $spotFee + $tips, $m['contribution']);
        self::assertNotSame($sales - ($foodCost + $packaging + $cardFees + $spotFee) + $tips, $m['contribution'], 'with these numbers a regrouped sum must differ');

        $margins = Estimator::unitMargins($p, $terms);
        $tip = $p['card_share'] * $p['tips_pct_of_card_sales'];
        $baseMargin = $p['avg_ticket'] * (1.0 - $p['food_cost_pct'] - $p['card_share'] * $p['card_fee_pct'] + $tip)
            - $p['packaging_per_order'] - $p['card_share'] * $p['card_fee_fixed'];
        self::assertSame($baseMargin, $margins['at_minimum']);
        self::assertSame($baseMargin - $p['avg_ticket'] * $terms['fee_pct'], $margins['at_percentage']);
    }

    /**
     * 4.13: the sums of calibrate run over the services in (date, service_id) order, and
     * num += w * resid * resid. exp and ln are called here exactly as the port calls them, in the same
     * process, so their last bits cannot differ; everything after them is exactly rounded arithmetic.
     *
     * Sixty logs that differ in two sales figures are checked: whether another order of the residual sum
     * changes resid_sd depends on the last bits of exp and ln, so no single log is sure to show it on every
     * machine, but some of sixty do.
     */
    public function testCalibrationAddsInLogOrder(): void
    {
        $A = self::assumptions();
        $kTruck = Estimator::seed($A, 'calibration.k_truck');
        $kSpot = Estimator::seed($A, 'calibration.k_spot');
        $halfLife = Estimator::seed($A, 'calibration.half_life_days');
        $asOf = Estimator::daysFromCivil(2026, 10, 4);
        $service = static fn (string $id, string $spot, string $date, float $actual, float $predicted): array => [
            'service_id' => $id, 'kind' => 'spot', 'spot_id' => $spot, 'date' => $date, 'open_minute' => 660, 'close_minute' => 840,
            'actual' => $actual, 'sold_out' => false, 'predicted_raw' => $predicted, 'predicted' => $predicted,
            'low' => $predicted * 0.5, 'high' => $predicted * 1.6,
        ];

        $regroupedDiffers = 0;
        $reversedDiffers = 0;
        for ($variant = 0; $variant < 60; $variant++) {
            $log = [                                      // deliberately not in log order
                $service('s5', 'B', '2026-09-12', 66.3, 60.5),
                $service('s1', 'A', '2026-06-06', 52.7, 60.1),
                $service('s7', 'B', '2026-10-03', (289 + intdiv($variant, 20)) / 10.0, 41.3),
                $service('s3', 'B', '2026-08-01', 30.1, 39.4),
                $service('s2', 'A', '2026-07-11', 70.9, 62.2),
                $service('s6', 'A', '2026-09-26', 47.2, 51.7),
                $service('s4', 'A', '2026-08-22', (456 + $variant % 20) / 10.0, 39.4),
            ];
            $cal = Estimator::calibrate($A, $log, '2026-10-04');
            $label = 'variant ' . $variant;

            $ordered = $log;
            usort($ordered, static fn (array $a, array $b): int => strcmp($a['date'] . $a['service_id'], $b['date'] . $b['service_id']));
            $rows = [];
            foreach ($ordered as $sv) {
                $age = $asOf - Estimator::daysFromCivil(...Estimator::parseDate($sv['date']));
                $rows[] = [
                    'spot' => $sv['spot_id'],
                    'w' => exp(-0.6931471805599453 * $age / $halfLife),
                    'L' => log($sv['actual'] / $sv['predicted_raw']),     // every ratio lies inside both clamps
                ];
            }
            $num = 0.0;
            $weight = 0.0;
            foreach ($rows as $r) {
                $num += $r['w'] * $r['L'];
                $weight += $r['w'];
            }
            $truckLogFactor = $num / ($kTruck + $weight);
            self::assertSame($weight, $cal['truck_weight'], $label);
            self::assertSame($truckLogFactor, $cal['truck_log_factor'], $label);
            self::assertSame(7, $cal['truck_n'], $label);

            $spotLog = [];
            foreach (['A', 'B'] as $spot) {
                $num = 0.0;
                $spotWeight = 0.0;
                foreach ($rows as $r) {
                    if ($r['spot'] === $spot) {
                        $num += $r['w'] * ($r['L'] - $truckLogFactor);
                        $spotWeight += $r['w'];
                    }
                }
                $spotLog[$spot] = $num / ($kSpot + $spotWeight);
                self::assertSame($spotWeight, $cal['spots'][$spot]['weight'], $label . ' ' . $spot);
                self::assertSame($spotLog[$spot], $cal['spots'][$spot]['log_factor'], $label . ' ' . $spot);
                self::assertSame(exp($spotLog[$spot]), $cal['spots'][$spot]['factor'], $label . ' ' . $spot);
            }

            $num = 0.0;
            $regrouped = 0.0;
            $reversed = 0.0;
            foreach ($rows as $r) {
                $resid = $r['L'] - $truckLogFactor - $spotLog[$r['spot']];
                $num += $r['w'] * $resid * $resid;
                $regrouped += $r['w'] * ($resid * $resid);
            }
            foreach (array_reverse($rows) as $r) {
                $resid = $r['L'] - $truckLogFactor - $spotLog[$r['spot']];
                $reversed += $r['w'] * $resid * $resid;
            }
            $residSd = sqrt($num / $weight);
            self::assertSame($weight, $cal['resid_weight'], $label);
            self::assertSame($residSd, $cal['resid_sd'], $label);
            $biasLog = 0.5 * $residSd * $residSd * $weight / ($kTruck + $weight);
            self::assertSame($biasLog, $cal['bias_log'], $label);
            self::assertSame(exp($truckLogFactor + $biasLog), $cal['truck_factor'], $label);

            $regroupedDiffers += ($residSd !== sqrt($regrouped / $weight)) ? 1 : 0;
            $reversedDiffers += ($residSd !== sqrt($reversed / $weight)) ? 1 : 0;
        }
        self::assertGreaterThan(0, $regroupedDiffers, 'a regrouped product must change resid_sd for some of these logs');
        self::assertGreaterThan(0, $reversedDiffers, 'a reversed sum must change resid_sd for some of these logs');
    }

    // --- fixtures ---------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function assumptions(): array
    {
        return Seeds::assumptions([], ['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => true]]);
    }

    /** @return array<string, mixed> the default truck of the seed file */
    private static function profile(): array
    {
        $profile = [
            'name' => 'Test truck', 'region_id' => 'dc',
            'base' => ['lat' => 39.003, 'lng' => -77.405, 'address' => 'Sterling, VA'],
            'fuel_price_override' => null, 'licence_counties' => [],
        ];
        foreach (Seeds::data()['profile_defaults'] as $field => $entry) {
            $profile[$field] = $field === 'daypart_fit'
                ? ['breakfast' => $entry['breakfast'], 'lunch' => $entry['lunch'], 'dinner' => $entry['dinner'], 'late' => $entry['late']]
                : $entry['value'];
        }
        return $profile;
    }

    /** @return array<string, mixed> */
    private static function host(string $segment, float $size, bool $onlyFood): array
    {
        return ['segment' => $segment, 'size' => $size, 'size_source' => 'owner', 'only_food' => $onlyFood, 'point_id' => null, 'place_type' => null];
    }

    /**
     * @param array<string, mixed>|null $host
     * @return array<string, mixed>
     */
    private static function terms(?string $spotId, ?array $host): array
    {
        return ['spot_id' => $spotId, 'visibility' => 'normal', 'host' => $host, 'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null];
    }

    /**
     * @return array<string, mixed> vectors with a different awkward number in every slot, of very different
     *                              sizes, so that the order of a sum over the segments shows in its last bit
     */
    private static function vectors(): array
    {
        $sizes = [1.0, 1000.0, 0.01, 37.0, 100000.0];
        $day = [];
        $eve = [];
        $nearby = [];
        $within = [];
        for ($s = 0; $s < 16; $s++) {
            $day[] = $sizes[$s % 5] * (13.7 + 41.3 * $s + 0.01 * $s * $s);
            $eve[] = $sizes[($s + 2) % 5] * (211.9 - 11.1 * $s + 0.03 * $s * $s);
            $nearby[] = 500.5 + 77.7 * $s;
            $within[] = 900.9 + 133.3 * $s;
        }
        return [
            'capture' => ['day' => $day, 'eve' => $eve], 'nearby' => $nearby, 'within' => $within,
            'rivals' => ['day' => 0.83, 'eve' => 1.71], 'visibility' => 'normal', 'in_region' => true, 'region_id' => null,
            'exclusion' => ['point_ids' => [], 'segment' => null, 'amount' => 0.0], 'excluded_amount' => 0.0,
            'points_used' => 9, 'dataset_version' => null, 'model_version' => 'tps-0.1.0',
        ];
    }

    /** @return array<string, mixed> a CalibrationState with a truck factor and a factor for spot B */
    private static function calibration(): array
    {
        return [
            'model_version' => 'tps-0.1.0', 'seeds_revision' => Seeds::revision(), 'as_of' => '2026-10-04',
            'truck_factor' => 0.963784, 'truck_log_factor' => -0.045458, 'bias_log' => 0.00857, 'truck_n' => 6, 'truck_weight' => 4.457955,
            'spots' => ['B' => ['factor' => 0.937663, 'log_factor' => -0.064365, 'n' => 3, 'weight' => 2.465262]],
            'resid_sd' => 0.180334, 'resid_n' => 5, 'resid_weight' => 3.67789,
        ];
    }
}
