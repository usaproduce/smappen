<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * Scouting: one candidate host at a time, and the ranking of the results (02_MODEL.md 4.16).
 *
 * The score ranks; it is not shown as money. Which places are candidates at all is decided by the caller.
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Scouting
{
    /**
     * The week strip from the weight rows of 4.17 in one pass (truck factor only, no spot factor). Equal to
     * weekStrip to the tolerance of 1.4 when terms.spot_id is null.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $terms SpotTerms
     * @param array<string, mixed> $vectors LocationVectors
     * @param array<string, mixed> $rows { w_opp, w_people } of mapWeightRows
     * @return list<float>
     */
    public static function stripFromRows(array $A, array $profile, array $terms, array $vectors, array $rows): array
    {
        $regimeOfHour = Seeds::read($A, 'hours.regime_of_hour');
        $host = $terms['host'];
        $hasHost = $host !== null && Num::f($host['size']) > 0;
        $hc = null;
        $hs = 0;
        if ($hasHost) {
            $hc = Capture::hostCapture($A, $host, $terms['visibility'], $vectors['rivals']);
            $hs = Vocab::segmentIndex($host['segment']);
        }
        $capacity = Num::f($profile['capacity_orders_per_hour']);
        $captureOf = [
            'day' => Num::perSegment($vectors['capture']['day']),
            'eve' => Num::perSegment($vectors['capture']['eve']),
        ];
        $wOpp = $rows['w_opp'];
        $out = [];
        for ($how = 0; $how < 168; $how++) {
            $regime = $regimeOfHour[$how % 24];
            $capture = $captureOf[$regime];
            $row = $wOpp[$how];
            $o = 0.0;
            for ($s = 0; $s < Vocab::NSEG; $s++) {
                $o += $capture[$s] * $row[$s];
            }
            if ($hasHost) {
                $o += $hc[$regime] * $row[$hs];
            }
            $out[] = $capacity < $o ? $capacity : $o;
        }
        return $out;
    }

    /**
     * One candidate host: its best three hours in a typical week, what they would leave, the cost of
     * driving there and back, and a score that ranks. Null for a place type that does not host trucks.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $place PlaceInput
     * @param array<string, array<string, mixed>> $legs "base><place_id>" and "<place_id>>base"
     * @param array<string, mixed>|null $cal
     * @return array<string, mixed>|null ScoutResult
     */
    public static function scoutEstimate(array $A, array $profile, array $place, array $legs, ?array $cal, float $fuelPricePerGal): ?array
    {
        $row = Seeds::read($A, 'place_types.rows.' . $place['place_type']);
        $hostFit = Num::f($row['host_fit']);
        if ($hostFit <= 0) {
            return null;
        }
        $placeKitchen = $place['kitchen'] ?? null;
        $kitchen = ($placeKitchen === null || $placeKitchen === 'unknown') ? $row['kitchen_default'] : $placeKitchen;
        $sizeDefault = Num::f($place['size_default']);
        $host = null;
        if ($row['host_segment'] !== null && $sizeDefault > 0) {
            $host = [
                'segment' => $row['host_segment'],
                'size' => $sizeDefault,
                'size_source' => 'default',
                'only_food' => $kitchen === 'no',
                'point_id' => $place['point_id'] ?? null,
                'place_type' => $place['place_type'],
            ];
        }
        $terms = [
            'spot_id' => null, 'visibility' => 'normal', 'host' => $host,
            'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null,
        ];
        $memo = new Memo();
        $rows = FastPath::mapWeightRows($A, $profile, $cal, $memo);       // the same for every place of a request
        $strip = self::stripFromRows($A, $profile, $terms, $place['vectors'], $rows);
        $windowMinutes = Num::i(Seeds::read($A, 'scout.window_minutes'));
        $b = Demand::bestWindows($strip, Num::floorDiv($windowMinutes, 60), 1, true);
        $result = [
            'place_id' => $place['place_id'],
            'place_type' => $place['place_type'],
            'position' => 0,
            'host_fit' => $hostFit,
            'kitchen' => $kitchen,
            'host_segment' => $row['host_segment'],
            'host_size' => $host !== null ? $sizeDefault : 0.0,
            'size_source' => 'default',
        ];
        if ($b === []) {
            [$z] = Ranges::interval($A, 0.0, Ranges::evidenceFrom($cal, null));
            $result['best_window'] = null;
            $result['orders'] = ['value' => 0.0, 'low' => 0.0, 'high' => 0.0, 'confidence' => $z['confidence']];
            $result['contribution'] = ['value' => 0.0, 'low' => 0.0, 'high' => 0.0, 'confidence' => $z['confidence']];
            $result['round_trip'] = ['minutes' => 0, 'miles' => 0.0, 'cost' => 0.0];
            $result['score'] = 0.0;
            return $result;
        }
        $dow = Num::floorDiv($b[0]['start'], 24);
        $openMinute = Num::modFloor($b[0]['start'], 24) * 60;
        $closeMinute = $openMinute + $windowMinutes;
        $ctx = Contexts::typicalContext($A, $dow);
        $W = Demand::windowOrders(
            $A, $profile, $terms, $place['vectors'], $cal, $ctx,
            Contexts::typicalContext($A, Num::modFloor($dow + 1, 7)), $openMinute, $closeMinute, $memo
        );
        $money = MoneyLines::stopMoney($profile, $terms, $W['orders']);
        $stop = [
            'id' => $place['place_id'], 'kind' => 'spot', 'spot_id' => null, 'point' => $place['point'],
            'open_minute' => $openMinute, 'close_minute' => $closeMinute, 'gap_before_unpaid' => false,
            'setup_minutes' => null, 'teardown_minutes' => null,
        ];
        $T = Timeline::buildTimeline($A, $profile, $ctx, [$stop], $legs);
        $cost = $T['drive_minutes'] / 60.0 * Num::f($profile['paid_crew']) * Num::f($profile['wage_per_hour'])
            * (1.0 + Num::f($profile['payroll_burden_pct']))
            + $T['miles'] / Num::f($profile['mpg']) * $fuelPricePerGal + $T['tolls'];
        $result['best_window'] = ['dow' => $dow, 'open_minute' => $openMinute, 'close_minute' => $closeMinute];
        $result['orders'] = $W['orders'];
        $result['contribution'] = $money['contribution'];
        $result['round_trip'] = ['minutes' => $T['drive_minutes'], 'miles' => $T['miles'], 'cost' => $cost];
        $result['score'] = $hostFit * $money['contribution']['value'] - $cost;
        return $result;
    }

    /**
     * Best score first, ties by place_id; numbered from 1; at most scout.max_results (read from the seed
     * file: the scope is fixed).
     *
     * @param array<int, array<string, mixed>> $results ScoutResult list (no nulls)
     * @return list<array<string, mixed>>
     */
    public static function scoutRank(array $results): array
    {
        $keyed = [];
        $position = 0;
        foreach ($results as $r) {
            $keyed[] = [Num::rank(Num::f($r['score'])), (string) $r['place_id'], $position, $r];
            $position += 1;
        }
        usort(
            $keyed,
            static fn (array $a, array $b): int => ($b[0] <=> $a[0]) ?: (strcmp($a[1], $b[1]) ?: ($a[2] <=> $b[2]))
        );
        $maxResults = Num::i(Seeds::data()['scout']['max_results']['value']);
        $out = [];
        foreach (array_slice($keyed, 0, $maxResults) as $item) {
            $numbered = $item[3];
            $numbered['position'] = count($out) + 1;
            $out[] = $numbered;
        }
        return $out;
    }
}
