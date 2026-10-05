<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * The day's clock, in whole minutes from local midnight of the service date (02_MODEL.md 4.11).
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Timeline
{
    /**
     * Every leg key dayPlan can look up: the plan as ordered, then the legs that appear when one stop is
     * left out. No duplicates.
     *
     * @param array<int, array<string, mixed>> $stops
     * @return list<string>
     */
    public static function requiredLegKeys(array $stops): array
    {
        $stops = array_values($stops);
        $n = count($stops);
        if ($n === 0) {
            return [];
        }
        $ids = ['base'];
        foreach ($stops as $s) {
            $ids[] = (string) $s['id'];
        }
        $ids[] = 'base';
        $keys = [];
        for ($i = 0; $i < count($ids) - 1; $i++) {
            $keys[] = $ids[$i] . '>' . $ids[$i + 1];
        }
        if ($n >= 2) {
            for ($i = 1; $i <= $n; $i++) {
                $keys[] = $ids[$i - 1] . '>' . $ids[$i + 1];
            }
        }
        return $keys;
    }

    /** @return array<string, mixed> Timeline of a day without stops */
    public static function emptyTimeline(): array
    {
        return [
            'events' => [], 'stops' => [], 'legs' => [],
            'start_prep' => null, 'leave_base' => null, 'back_at_base' => null, 'done' => null,
            'day_minutes' => 0, 'paid_minutes' => 0, 'unpaid_gap_minutes' => 0, 'drive_minutes' => 0,
            'service_minutes' => 0, 'generator_minutes' => 0, 'miles' => 0.0, 'tolls' => 0.0,
        ];
    }

    /**
     * The first stop is worked backward from its opening time (the truck leaves base just in time); every
     * later stop forward. The owner's opening and closing times are never moved: a truck that cannot be set
     * up in time is late and serves from effective_open. Stops are never reordered. A leg missing from
     * `legs` is a fallback leg between the two points.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $ctx DayContext
     * @param array<int, array<string, mixed>> $stops StopInput list
     * @param array<string, array<string, mixed>> $legs LegInput by "<from_id>><to_id>"
     * @return array<string, mixed> Timeline
     */
    public static function buildTimeline(array $A, array $profile, array $ctx, array $stops, array $legs): array
    {
        $stops = array_values($stops);
        $n = count($stops);
        if ($n === 0) {
            return self::emptyTimeline();
        }

        $ids = [];
        $setup = [];
        $teardown = [];
        foreach ($stops as $s) {
            $ids[] = (string) $s['id'];
            $setup[] = Num::i($s['setup_minutes'] ?? $profile['setup_minutes']);
            $teardown[] = Num::i($s['teardown_minutes'] ?? $profile['teardown_minutes']);
        }
        $truckTimeFactor = Num::f($profile['truck_time_factor']);

        $events = [];
        $lastMinute = null;
        $emit = static function (string $kind, int $minute, ?int $stopIndex) use (&$events, &$lastMinute): void {
            if ($lastMinute !== null && $minute < $lastMinute) {
                $minute = $lastMinute;                    // time never runs backwards
            }
            $events[] = ['kind' => $kind, 'minute' => $minute, 'stop_index' => $stopIndex];
            $lastMinute = $minute;
        };

        // 1. First stop: work backward from its opening time.
        $arrive = Num::i($stops[0]['open_minute']) - $setup[0];
        $first = self::legInput($A, $profile, $stops, $legs, 'base', $ids[0]);
        $lookup = $arrive - Num::toInt(floor(Num::f($first['duration_s']) / 60.0 * $truckTimeFactor));   // departure guess
        $leg0 = Driving::legMinutes($A, $profile, $first, $ctx, $lookup);
        $leg0['from_id'] = 'base';
        $leg0['to_id'] = $ids[0];
        $leaveBase = $arrive - $leg0['minutes'];
        $leg0['depart_minute'] = $leaveBase;
        $startPrep = $leaveBase - Num::i($profile['prep_minutes']);
        $emit('start_prep', $startPrep, null);
        $emit('leave_base', $leaveBase, null);
        $outLegs = [$leg0];

        // 2. Each stop in order, forward.
        $outStops = [];
        $unpaid = 0;
        $serviceMinutes = 0;
        $generatorMinutes = 0;
        $prevLeave = 0;
        for ($i = 0; $i < $n; $i++) {
            $s = $stops[$i];
            $openMinute = Num::i($s['open_minute']);
            $closeMinute = Num::i($s['close_minute']);
            if ($i > 0) {
                $leg = Driving::legMinutes($A, $profile, self::legInput($A, $profile, $stops, $legs, $ids[$i - 1], $ids[$i]), $ctx, $prevLeave);
                $leg['from_id'] = $ids[$i - 1];
                $leg['to_id'] = $ids[$i];
                $leg['depart_minute'] = $prevLeave;
                $outLegs[] = $leg;
                $arrive = $prevLeave + $leg['minutes'];
            }
            $gap = ($openMinute - $setup[$i]) - $arrive;
            $late = 0;
            if ($gap < 0) {
                $late = -$gap;
                $gap = 0;
            }
            $setupStart = $arrive + $gap;
            $effectiveOpen = $closeMinute < $setupStart + $setup[$i] ? $closeMinute : $setupStart + $setup[$i];
            $gapUnpaid = (bool) $s['gap_before_unpaid'] && $i > 0;
            if ($gapUnpaid) {
                $unpaid += $gap;
            }
            $leave = $setupStart > $closeMinute + $teardown[$i] ? $setupStart : $closeMinute + $teardown[$i];
            $emit('arrive', $arrive, $i);
            $emit('setup_start', $setupStart, $i);
            $emit('open', $effectiveOpen, $i);
            $emit('close', $closeMinute, $i);
            $emit('leave', $leave, $i);
            $serviceMinutes += $closeMinute - $effectiveOpen;
            $generatorMinutes += $leave - $setupStart;      // setup start to the end of teardown
            $outStops[] = [
                'stop_index' => $i,
                'arrive' => $arrive,
                'setup_start' => $setupStart,
                'open' => $openMinute,
                'effective_open' => $effectiveOpen,
                'close' => $closeMinute,
                'leave' => $leave,
                'gap_before_minutes' => $gap,
                'gap_unpaid' => $gapUnpaid,
                'late_minutes' => $late,
            ];
            $prevLeave = $leave;
        }

        // 3. Back to base.
        $legN = Driving::legMinutes($A, $profile, self::legInput($A, $profile, $stops, $legs, $ids[$n - 1], 'base'), $ctx, $prevLeave);
        $legN['from_id'] = $ids[$n - 1];
        $legN['to_id'] = 'base';
        $legN['depart_minute'] = $prevLeave;
        $outLegs[] = $legN;
        $backAtBase = $prevLeave + $legN['minutes'];
        $done = $backAtBase + Num::i($profile['closeout_minutes']);
        $emit('back_at_base', $backAtBase, null);
        $emit('done', $done, null);

        $driveMinutes = 0;
        $miles = 0.0;
        $tolls = 0.0;
        foreach ($outLegs as $leg) {
            $driveMinutes += $leg['minutes'];
            $miles += $leg['miles'];
            $tolls += $leg['toll'];
        }
        $dayMinutes = $done - $startPrep;
        return [
            'events' => $events,
            'stops' => $outStops,
            'legs' => $outLegs,
            'start_prep' => $startPrep,
            'leave_base' => $leaveBase,
            'back_at_base' => $backAtBase,
            'done' => $done,
            'day_minutes' => $dayMinutes,
            'paid_minutes' => $dayMinutes - $unpaid,
            'unpaid_gap_minutes' => $unpaid,
            'drive_minutes' => $driveMinutes,
            'service_minutes' => $serviceMinutes,
            'generator_minutes' => $generatorMinutes,
            'miles' => $miles,
            'tolls' => $tolls,
        ];
    }

    /**
     * The LegInput of one leg: the routed one when the map has it, else the straight-line fallback between
     * the two points (the truck's base for "base").
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param list<array<string, mixed>> $stops
     * @param array<string, array<string, mixed>> $legs
     * @return array<string, mixed> LegInput
     */
    private static function legInput(array $A, array $profile, array $stops, array $legs, string $fromId, string $toId): array
    {
        $key = $fromId . '>' . $toId;
        if (array_key_exists($key, $legs)) {
            return $legs[$key];
        }
        $p1 = self::pointOf($profile, $stops, $fromId);
        $p2 = self::pointOf($profile, $stops, $toId);
        return Driving::fallbackLeg($A, Num::f($p1['lat']), Num::f($p1['lng']), Num::f($p2['lat']), Num::f($p2['lng']));
    }

    /**
     * @param array<string, mixed> $profile
     * @param list<array<string, mixed>> $stops
     * @return array<string, mixed> { lat, lng }
     */
    private static function pointOf(array $profile, array $stops, string $stopId): array
    {
        if ($stopId === 'base') {
            return $profile['base'];
        }
        foreach ($stops as $s) {
            if ((string) $s['id'] === $stopId) {
                return $s['point'];
            }
        }
        throw new \OutOfBoundsException('unknown stop id');
    }
}
