<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * Drive legs: the labelled straight-line fallback, the time-of-day factor and whole leg minutes
 * (02_MODEL.md 4.10).
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Driving
{
    /**
     * A labelled straight-line estimate for when no routed leg exists: road distance = straight line x
     * detour factor; the first local miles at the local speed, the rest at the trunk speed; free-flow.
     *
     * @param array<string, mixed> $A
     * @return array<string, mixed> LegInput
     */
    public static function fallbackLeg(array $A, float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $roadM = Geometry::haversineM($lat1, $lng1, $lat2, $lng2) * Num::f(Seeds::read($A, 'drive_fallback.detour_factor'));
        $miles = $roadM / 1609.344;
        $localMiles = Num::f(Seeds::read($A, 'drive_fallback.local_miles'));
        $local = $localMiles < $miles ? $localMiles : $miles;
        $ffMin = $local / Num::f(Seeds::read($A, 'drive_fallback.local_mph')) * 60.0
            + ($miles - $local) / Num::f(Seeds::read($A, 'drive_fallback.trunk_mph')) * 60.0;
        return [
            'source' => 'fallback',
            'distance_m' => $roadM,
            'duration_s' => $ffMin * 60.0,
            'override_minutes' => null,
            'toll' => 0.0,
        ];
    }

    /**
     * [factor, dow, hour]: travel time over free-flow time for the clock hour containing `minute`. On the
     * service date the context's traffic_dow applies; a minute before that date's midnight or after its end
     * belongs to a neighbouring civil date and uses that date's real day of the week.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $ctx DayContext
     * @return array{0: float, 1: int, 2: int}
     */
    public static function trafficFactor(array $A, array $ctx, int $minute): array
    {
        $dayShift = Num::floorDiv($minute, 1440);
        $m = $minute - 1440 * $dayShift;
        $dow = $dayShift === 0 ? Num::i($ctx['traffic_dow']) : Num::modFloor(Num::i($ctx['dow']) + $dayShift, 7);
        $hour = Num::floorDiv($m, 60);
        $matrix = Seeds::read($A, 'traffic.' . $A['region']['traffic_matrix']);
        return [Num::f($matrix[$dow][$hour]), $dow, $hour];
    }

    /**
     * Drive minutes of one leg for a departure in the clock hour of lookup_minute.
     *
     * fallback leg: free-flow minutes x table factor. google leg: Google's traffic-unaware duration already
     * holds average traffic, so it is scaled by factor / the table's typical value. The owner's override
     * replaces the whole computation. Whole minutes; at least 1 for any real distance.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $leg LegInput
     * @param array<string, mixed> $ctx DayContext
     * @return array<string, mixed> Leg
     */
    public static function legMinutes(array $A, array $profile, array $leg, array $ctx, int $lookupMinute): array
    {
        $distanceM = Num::f($leg['distance_m']);
        $truckTimeFactor = Num::f($profile['truck_time_factor']);
        $baseMinutes = Num::f($leg['duration_s']) / 60.0;
        [$factor, $dow, $hour] = self::trafficFactor($A, $ctx, $lookupMinute);
        if ($leg['source'] === 'google') {
            $timeFactor = $factor / Num::f(Seeds::read($A, 'traffic.' . $A['region']['traffic_matrix'] . '_typical'));
        } else {
            $timeFactor = $factor;
        }
        $raw = $baseMinutes * $timeFactor * $truckTimeFactor;
        $override = $leg['override_minutes'] ?? null;
        if ($override !== null) {
            $minutes = Num::i($override);
            $source = 'override';
        } else {
            $minutes = Num::toInt(Num::roundHalfAway($raw, 0));
            if ($minutes < 1 && $distanceM > 0) {
                $minutes = 1;
            }
            $source = $leg['source'];
        }
        return [
            'from_id' => null,
            'to_id' => null,
            'source' => $source,
            'distance_m' => $distanceM,
            'miles' => $distanceM / 1609.344,
            'base_minutes' => $baseMinutes,
            'depart_minute' => null,
            'traffic_lookup_minute' => $lookupMinute,
            'traffic_dow' => $dow,
            'traffic_hour' => $hour,
            'traffic_factor' => $factor,
            'time_factor' => $timeFactor,
            'truck_time_factor' => $truckTimeFactor,
            'raw_minutes' => $raw,
            'minutes' => $minutes,
            'toll' => Num::f($leg['toll']),
        ];
    }
}
