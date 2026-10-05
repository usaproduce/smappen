<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * The map fast path: weight rows per hour of the week and bulk cell scores (02_MODEL.md 4.17).
 *
 * The map never applies weather, spot factors or hosts; the spot card does.
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class FastPath
{
    /**
     * Per hour of the week and segment: w_opp turns a capture vector into expected orders, w_people a nearby
     * vector into people present.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed>|null $cal CalibrationState
     * @return array{w_opp: list<list<float>>, w_people: list<list<float>>}
     */
    public static function mapWeightRows(array $A, array $profile, ?array $cal, ?Memo $memo = null): array
    {
        $E = Curves::expandCurves($A, $memo);
        $tf = $cal !== null ? Num::f($cal['truck_factor']) : 1.0;
        $daypartOfHour = Seeds::read($A, 'hours.daypart_of_hour');
        $fitByHour = [];
        for ($h = 0; $h < 24; $h++) {
            $fitByHour[] = Num::f($profile['daypart_fit'][$daypartOfHour[$h]]);
        }
        $wOpp = [];
        $wPeople = [];
        for ($how = 0; $how < 168; $how++) {
            $fit = $fitByHour[$how % 24];
            $oppRow = [];
            $peopleRow = [];
            for ($s = 0; $s < Vocab::NSEG; $s++) {
                $oppRow[] = $E['presence'][$s][$how] * $E['intent'][$s][$how] * $fit * $tf;
                $peopleRow[] = $E['presence'][$s][$how];
            }
            $wOpp[] = $oppRow;
            $wPeople[] = $peopleRow;
        }
        return ['w_opp' => $wOpp, 'w_people' => $wPeople];
    }

    /**
     * Score n map cells for one hour. features holds 50 numbers per cell: capture.day[16], capture.eve[16],
     * nearby[16], rivals.day, rivals.eve.
     *
     * @param array<int, float|int> $features
     * @param array<int, float|int> $wOppRow
     * @param array<int, float|int> $wPeopleRow
     * @return array{opportunity: list<float>, people: list<float>, competition: list<float>}
     */
    public static function cellScores(array $features, int $n, array $wOppRow, array $wPeopleRow, string $regime, float $capacity): array
    {
        $off = $regime === 'day' ? 0 : 16;
        $ri = $regime === 'day' ? 48 : 49;
        $opportunity = [];
        $people = [];
        $competition = [];
        for ($cell = 0; $cell < $n; $cell++) {
            $b = 50 * $cell;
            $o = 0.0;
            for ($s = 0; $s < Vocab::NSEG; $s++) {
                $o += $features[$b + $off + $s] * $wOppRow[$s];
            }
            $opportunity[] = $capacity < $o ? $capacity : $o;
            $p = 0.0;
            for ($s = 0; $s < Vocab::NSEG; $s++) {
                $p += $features[$b + 32 + $s] * $wPeopleRow[$s];
            }
            $people[] = $p;
            $competition[] = (float) $features[$b + $ri];
        }
        return ['opportunity' => $opportunity, 'people' => $people, 'competition' => $competition];
    }

    /** Map colour byte 0..255 on a square-root scale with a fixed top. */
    public static function scoreByte(float $x, float $hi): int
    {
        $t = Num::clamp($x / $hi, 0.0, 1.0);
        return Num::toInt(floor(255.0 * sqrt($t) + 0.5));
    }
}
