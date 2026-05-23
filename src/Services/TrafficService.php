<?php
namespace App\Services;

/**
 * Traffic multiplier table — by day-of-week × hour-of-day.
 *
 * Multipliers express "true travel time / free-flow travel time", calibrated
 * roughly from FHWA / INRIX studies for US metro driving. Values < 1 are
 * impossible in reality, so the minimum is clamped at 1.0.
 *
 * Why this lives in code (not a DB table): the values are empirical defaults,
 * not user-editable; baking them into the service keeps requests dependency-
 * free. They can be tuned per-market later by loading override data into
 * Config (TRAFFIC_OVERRIDES) without touching this class.
 */
class TrafficService
{
    /** index: dayOfWeek 0=Sun..6=Sat, then hour 0..23 */
    private const MATRIX = [
        // Sunday — mostly free-flow except midday churches/events
        [1.00, 1.00, 1.00, 1.00, 1.00, 1.00, 1.00, 1.05, 1.10, 1.15, 1.20, 1.20,
         1.15, 1.10, 1.10, 1.05, 1.05, 1.10, 1.10, 1.05, 1.00, 1.00, 1.00, 1.00],
        // Monday — heavy AM + PM peaks
        [1.00, 1.00, 1.00, 1.00, 1.00, 1.05, 1.20, 1.45, 1.55, 1.35, 1.15, 1.10,
         1.10, 1.10, 1.15, 1.30, 1.50, 1.60, 1.45, 1.20, 1.10, 1.05, 1.00, 1.00],
        // Tuesday
        [1.00, 1.00, 1.00, 1.00, 1.00, 1.05, 1.20, 1.50, 1.60, 1.35, 1.15, 1.10,
         1.10, 1.10, 1.15, 1.30, 1.55, 1.65, 1.45, 1.20, 1.10, 1.05, 1.00, 1.00],
        // Wednesday
        [1.00, 1.00, 1.00, 1.00, 1.00, 1.05, 1.20, 1.50, 1.60, 1.35, 1.15, 1.10,
         1.10, 1.10, 1.15, 1.30, 1.55, 1.65, 1.45, 1.20, 1.10, 1.05, 1.00, 1.00],
        // Thursday
        [1.00, 1.00, 1.00, 1.00, 1.00, 1.05, 1.20, 1.50, 1.65, 1.40, 1.20, 1.10,
         1.10, 1.10, 1.15, 1.35, 1.60, 1.70, 1.50, 1.20, 1.10, 1.05, 1.00, 1.00],
        // Friday — afternoon worst
        [1.00, 1.00, 1.00, 1.00, 1.00, 1.05, 1.20, 1.45, 1.55, 1.35, 1.15, 1.15,
         1.20, 1.25, 1.35, 1.50, 1.75, 1.80, 1.55, 1.25, 1.15, 1.10, 1.05, 1.00],
        // Saturday — midday + evening shopping
        [1.00, 1.00, 1.00, 1.00, 1.00, 1.00, 1.00, 1.05, 1.15, 1.25, 1.35, 1.40,
         1.40, 1.35, 1.35, 1.40, 1.40, 1.35, 1.30, 1.20, 1.15, 1.10, 1.05, 1.00],
    ];

    public static function multiplier(string $weekdayName, int $hour24): float
    {
        $hour24 = max(0, min(23, $hour24));
        $dayIdx = self::dayIndex($weekdayName);
        return max(1.0, self::MATRIX[$dayIdx][$hour24]);
    }

    /**
     * Translate a "departure-time" budget into an equivalent free-flow budget
     * we can request from ORS. For peak hour at 1.6x, asking ORS for
     * minutes/1.6 yields the polygon you can actually reach in `minutes`
     * during that traffic.
     */
    public static function adjustedMinutes(int $minutes, string $weekdayName, int $hour24): int
    {
        $m = self::multiplier($weekdayName, $hour24);
        return max(1, (int) round($minutes / $m));
    }

    /** Bucket the 7×24 matrix into "windows" for the time-of-day chart. */
    public static function windows(): array
    {
        return [
            ['key' => 'sun_morning',  'label' => 'Sun · morning',   'day' => 'sunday',    'hour' => 9],
            ['key' => 'sun_evening',  'label' => 'Sun · evening',   'day' => 'sunday',    'hour' => 18],
            ['key' => 'mon_am_peak',  'label' => 'Mon · AM peak',   'day' => 'monday',    'hour' => 8],
            ['key' => 'mon_midday',   'label' => 'Mon · midday',    'day' => 'monday',    'hour' => 12],
            ['key' => 'mon_pm_peak',  'label' => 'Mon · PM peak',   'day' => 'monday',    'hour' => 17],
            ['key' => 'mon_evening',  'label' => 'Mon · evening',   'day' => 'monday',    'hour' => 20],
            ['key' => 'fri_pm_peak',  'label' => 'Fri · PM peak',   'day' => 'friday',    'hour' => 17],
            ['key' => 'sat_midday',   'label' => 'Sat · midday',    'day' => 'saturday',  'hour' => 13],
        ];
    }

    private static function dayIndex(string $name): int
    {
        $map = [
            'sunday' => 0, 'sun' => 0,
            'monday' => 1, 'mon' => 1,
            'tuesday' => 2, 'tue' => 2,
            'wednesday' => 3, 'wed' => 3,
            'thursday' => 4, 'thu' => 4,
            'friday' => 5, 'fri' => 5,
            'saturday' => 6, 'sat' => 6,
        ];
        return $map[strtolower($name)] ?? 1;
    }
}
