<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Model;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use PHPUnit\Framework\TestCase;

/**
 * Rules of 02_MODEL.md that the golden cases of seeds revision 1 do not pin down (a mutation of the port
 * that broke one of them still passed every golden case). Each expectation below was confirmed against the
 * Python reference on 2026-10-05 and is stated as the rule itself, not as a copied number.
 */
final class SpecRulesTest extends TestCase
{
    /** 4.16: round_trip.cost = paid drive time + fuel + tolls; the score falls by the tolls. */
    public function testScoutingCountsTollsInTheRoundTrip(): void
    {
        $A = self::assumptions();
        $place = [
            'place_id' => 'w100', 'place_type' => 'taproom', 'point' => ['lat' => 39.0035, 'lng' => -77.4035],
            'point_id' => 'pw100', 'size_default' => 40.0, 'kitchen' => null,
            'vectors' => self::zeroVectors(['point_ids' => ['pw100'], 'segment' => null, 'amount' => 0.0], true),
        ];
        $free = Estimator::scoutEstimate($A, self::profile(), $place, ['base>w100' => self::leg(7, 3.0, 0.0), 'w100>base' => self::leg(7, 3.0, 0.0)], null, 4.195);
        $tolled = Estimator::scoutEstimate($A, self::profile(), $place, ['base>w100' => self::leg(7, 3.0, 2.25), 'w100>base' => self::leg(7, 3.0, 1.5)], null, 4.195);

        self::assertNotNull($free);
        self::assertNotNull($tolled);
        self::assertSame(['dow' => 5, 'open_minute' => 1020, 'close_minute' => 1200], $free['best_window']);
        self::assertSame(14, $free['round_trip']['minutes']);
        self::assertSame($free['round_trip']['cost'] + 3.75, $tolled['round_trip']['cost']);
        self::assertSame($free['orders'], $tolled['orders']);
        self::assertEqualsWithDelta($free['score'] - 3.75, $tolled['score'], 1e-9);
        self::assertSame('very_rough', $free['orders']['confidence']);
    }

    /**
     * 4.15: "A strictly greater qkey is needed to replace the best, so among equal totals the first one
     * found wins: earlier days prefer higher-ranked plans and working over resting." Seven identical days
     * (each treated as a Friday) have one worthwhile plan each, so every choice of the same number of
     * working days ties exactly.
     */
    public function testWeekSearchKeepsTheFirstOfEqualTotals(): void
    {
        $A = self::assumptions();
        $taproom = [
            'spot_id' => 'taproom', 'point' => ['lat' => 39.0035, 'lng' => -77.4035],
            'terms' => self::terms('taproom', ['segment' => 'v_nightlife', 'size' => 120.0, 'size_source' => 'owner', 'only_food' => true, 'point_id' => null, 'place_type' => null]),
            'vectors' => self::zeroVectors(['point_ids' => [], 'segment' => null, 'amount' => 0.0], false),
        ];
        $legs = ['base>taproom' => self::leg(1, 0.2, 0.0), 'taproom>base' => self::leg(1, 0.2, 0.0)];
        $contexts = [];
        for ($d = 0; $d < 8; $d++) {
            $contexts[] = Estimator::dayContext($A, Estimator::addDays('2026-10-05', $d), 'fri', null, 4.195, 'seed');
        }
        $day = Estimator::suggestDay($A, self::profile(), $contexts[0], $contexts[1], [$taproom], $legs, null, ['max_stops_per_day' => 1, 'limit' => 5]);
        self::assertCount(2, $day);
        self::assertSame(1020, $day[0]['stops'][0]['open_minute']);
        self::assertGreaterThan(0.0, $day[0]['take_home']['value']);
        self::assertLessThan(0.0, $day[1]['take_home']['value'], 'the second window loses money and is no option for the week');
        $one = $day[0]['take_home']['value'];

        // [working days allowed, visits allowed] => days worked, leaves = number of ways to pick at most that many of 7 days
        $expectations = [
            [1, null, [0], 8],
            [2, null, [0, 1], 29],
            [3, 3, [0, 1, 2], 64],
            [7, 7, [0, 1, 2, 3, 4, 5, 6], 128],
            [5, 1, [0], 8],
        ];
        foreach ($expectations as [$maxDays, $maxVisits, $worked, $leaves]) {
            $options = ['service_minutes' => null, 'max_stops_per_day' => 1, 'max_days_per_week' => $maxDays, 'max_visits_per_spot_per_week' => $maxVisits, 'limit' => null];
            $week = Estimator::suggestWeek($A, self::profile(), '2026-10-05', $contexts, [$taproom], $legs, null, $options);
            $label = "max_days $maxDays, max_visits " . var_export($maxVisits, true);

            $chosen = [];
            $total = 0.0;
            foreach ($week['days'] as $d => $entry) {
                self::assertSame(Estimator::addDays('2026-10-05', $d), $entry['date'], $label);
                if ($entry['suggestion'] !== null) {
                    $chosen[] = $d;
                    $total += $one;
                    self::assertSame(1, $entry['suggestion']['position'], $label);
                    self::assertSame($one, $entry['suggestion']['take_home']['value'], $label);
                }
            }
            self::assertSame($worked, $chosen, $label . ': the earliest days, because they are found first');
            self::assertSame($leaves, $week['leaves_visited'], $label);
            self::assertSame(['taproom' => count($worked)], $week['visits'], $label);
            self::assertSame($total, $week['total_take_home']['value'], $label);
        }
    }

    /** 4.13: coverage counts low <= actual <= high, both bounds included. */
    public function testAccuracyCoverageIncludesBothBounds(): void
    {
        $entry = static fn (string $id, string $date, float $actual): array => [
            'service_id' => $id, 'kind' => 'spot', 'spot_id' => 'A', 'date' => $date, 'open_minute' => 660, 'close_minute' => 840,
            'actual' => $actual, 'sold_out' => false, 'predicted_raw' => 60.0, 'predicted' => 60.0, 'low' => 33.0, 'high' => 93.0,
        ];
        $report = Estimator::accuracyReport([
            $entry('s1', '2026-10-01', 33.0),             // exactly the low
            $entry('s2', '2026-10-02', 93.0),             // exactly the high
            $entry('s3', '2026-10-03', 93.5),             // outside
        ]);

        self::assertSame(3, $report['n_scored']);
        self::assertSame(2 / 3, $report['coverage']);
        self::assertSame(2 / 3, $report['by_spot'][0]['coverage']);
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

    /**
     * @param array<string, mixed>|null $host
     * @return array<string, mixed>
     */
    private static function terms(?string $spotId, ?array $host): array
    {
        return ['spot_id' => $spotId, 'visibility' => 'normal', 'host' => $host, 'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null];
    }

    /**
     * @param array<string, mixed> $exclusion
     * @return array<string, mixed> zero vectors; $stored: as decoded from 50 stored numbers
     */
    private static function zeroVectors(array $exclusion, bool $stored): array
    {
        $zeros = array_fill(0, 16, 0.0);
        return [
            'capture' => ['day' => $zeros, 'eve' => $zeros], 'nearby' => $zeros, 'within' => $stored ? null : $zeros,
            'rivals' => ['day' => 0.0, 'eve' => 0.0], 'visibility' => 'normal', 'in_region' => true, 'region_id' => null,
            'exclusion' => $exclusion, 'excluded_amount' => 0.0, 'points_used' => $stored ? null : 0,
            'dataset_version' => null, 'model_version' => 'tps-0.1.0',
        ];
    }

    /** @return array<string, mixed> a leg whose minutes the owner has set */
    private static function leg(int $minutes, float $miles, float $toll): array
    {
        return ['source' => 'google', 'distance_m' => $miles * 1609.344, 'duration_s' => 0.0, 'override_minutes' => $minutes, 'toll' => $toll];
    }
}
