<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Builds a category-density benchmark by running the same Places search
 * against 10 reference US metros that match the user area's density tier.
 * Lets the panel say "73rd percentile vs comparable suburbs" instead of
 * the made-up Sparse/Dense bucket that was wrong for niche keywords.
 *
 * References are hand-picked to span major US regions (NE / Mid-Atlantic /
 * SE / Midwest / South / Mountain / West / PNW) inside each tier so the
 * benchmark isn't biased to one coast.
 */
class PlacesBenchmarkService
{
    private const REFERENCES = [
        // pop density >= 2000/km² — dense urban cores
        'urban' => [
            ['name' => 'Manhattan, NY',     'lat' => 40.7589, 'lng' => -73.9851],
            ['name' => 'San Francisco, CA', 'lat' => 37.7749, 'lng' => -122.4194],
            ['name' => 'Brooklyn, NY',      'lat' => 40.6782, 'lng' => -73.9442],
            ['name' => 'Boston, MA',        'lat' => 42.3656, 'lng' => -71.0531],
            ['name' => 'Chicago, IL',       'lat' => 41.8781, 'lng' => -87.6298],
            ['name' => 'Washington, DC',    'lat' => 38.9072, 'lng' => -77.0369],
            ['name' => 'Philadelphia, PA',  'lat' => 39.9526, 'lng' => -75.1652],
            ['name' => 'Seattle, WA',       'lat' => 47.6062, 'lng' => -122.3321],
            ['name' => 'Los Angeles, CA',   'lat' => 34.0522, 'lng' => -118.2437],
            ['name' => 'Miami, FL',         'lat' => 25.7617, 'lng' => -80.1918],
        ],
        // 300–2000/km² — typical US suburbs
        'suburban' => [
            ['name' => 'Arlington, VA',  'lat' => 38.8816, 'lng' => -77.0910],
            ['name' => 'Bellevue, WA',   'lat' => 47.6101, 'lng' => -122.2015],
            ['name' => 'Plano, TX',      'lat' => 33.0198, 'lng' => -96.6989],
            ['name' => 'Naperville, IL', 'lat' => 41.7508, 'lng' => -88.1535],
            ['name' => 'Sterling, VA',   'lat' => 39.0062, 'lng' => -77.4286],
            ['name' => 'Pasadena, CA',   'lat' => 34.1478, 'lng' => -118.1445],
            ['name' => 'Cary, NC',       'lat' => 35.7915, 'lng' => -78.7811],
            ['name' => 'Sugar Land, TX', 'lat' => 29.5994, 'lng' => -95.6147],
            ['name' => 'San Mateo, CA',  'lat' => 37.5630, 'lng' => -122.3255],
            ['name' => 'Evanston, IL',   'lat' => 42.0451, 'lng' => -87.6877],
        ],
        // < 300/km² — exurban / small-metro
        'exurban' => [
            ['name' => 'Boise, ID',        'lat' => 43.6150, 'lng' => -116.2023],
            ['name' => 'Asheville, NC',    'lat' => 35.5951, 'lng' => -82.5515],
            ['name' => 'Madison, WI',      'lat' => 43.0731, 'lng' => -89.4012],
            ['name' => 'Knoxville, TN',    'lat' => 35.9606, 'lng' => -83.9207],
            ['name' => 'Fort Collins, CO', 'lat' => 40.5853, 'lng' => -105.0844],
            ['name' => 'Boulder, CO',      'lat' => 40.0150, 'lng' => -105.2705],
            ['name' => 'Bend, OR',         'lat' => 44.0582, 'lng' => -121.3153],
            ['name' => 'Frederick, MD',    'lat' => 39.4143, 'lng' => -77.4105],
            ['name' => 'Greenville, SC',   'lat' => 34.8526, 'lng' => -82.3940],
            ['name' => 'Lexington, KY',    'lat' => 38.0406, 'lng' => -84.5037],
        ],
    ];

    public static function tierForDensity(?float $popPerSqKm): string
    {
        if ($popPerSqKm === null) return 'suburban';
        if ($popPerSqKm >= 2000) return 'urban';
        if ($popPerSqKm >= 300)  return 'suburban';
        return 'exurban';
    }

    public static function tierLabel(string $tier): string
    {
        return match ($tier) {
            'urban'    => 'urban-core US areas',
            'exurban'  => 'exurban / small-metro US areas',
            default    => 'US suburbs',
        };
    }

    public static function getReferences(string $tier): array
    {
        return self::REFERENCES[$tier] ?? self::REFERENCES['suburban'];
    }

    /**
     * Convert area_sq_km → equivalent circle radius in meters. Used so the
     * reference searches probe the same areal footprint as the user's area,
     * not a hardcoded 5km.
     */
    public static function equivalentCircleRadiusM(float $areaSqKm): int
    {
        $radiusKm = sqrt(max(0.0, $areaSqKm) / M_PI);
        return (int) max(1000, min(50000, ceil($radiusKm * 1000)));
    }

    /**
     * Given the user's count + the per-reference counts, compute summary
     * stats + a one-sentence English insight the panel can show without
     * the user having to interpret raw numbers.
     */
    public static function summarize(int $userCount, array $referenceResults, ?string $tier = null): array
    {
        $counts = array_map(fn($r) => (int) $r['count'], $referenceResults);
        sort($counts);
        $n = count($counts);
        if ($n === 0) {
            return [
                'min' => null, 'max' => null, 'median' => null,
                'p25' => null, 'p75' => null,
                'user_percentile' => null,
                'insight' => 'No comparable references available.',
            ];
        }
        $median = $counts[(int) floor(($n - 1) / 2)];
        $p25 = $counts[(int) floor(($n - 1) * 0.25)];
        $p75 = $counts[(int) floor(($n - 1) * 0.75)];

        // Percentile = % of references at or below user's count, integer 0-100.
        $atOrBelow = count(array_filter($counts, fn($c) => $c <= $userCount));
        $pct = (int) round(($atOrBelow / $n) * 100);

        return [
            'min' => $counts[0],
            'max' => end($counts),
            'median' => $median,
            'p25' => $p25,
            'p75' => $p75,
            'user_percentile' => $pct,
            'insight' => self::buildInsight($userCount, $median, $pct, $tier),
        ];
    }

    private static function buildInsight(int $userCount, int $median, int $pct, ?string $tier): string
    {
        $cohort = $tier ? self::tierLabel($tier) : 'comparable US areas';
        if ($median === 0) {
            return $userCount > 0
                ? "Highly unusual — comparable $cohort typically have none, but yours has $userCount."
                : "Comparable $cohort also typically have none. No real signal here.";
        }
        $diff = $userCount - $median;
        $pctDiff = (int) round(($diff / max(1, $median)) * 100);
        $absPct = abs($pctDiff);

        if ($pct >= 90) {
            return "Well above average. Yours has $userCount; the median across 10 $cohort is $median ($absPct% fewer).";
        }
        if ($pct >= 70) {
            return "Above average. Yours has $userCount vs a median of $median in $cohort.";
        }
        if ($pct >= 40) {
            return "About average. Median across $cohort is $median; yours is $userCount.";
        }
        if ($pct >= 20) {
            return "Below average. The typical $cohort has $median; yours has $userCount.";
        }
        return "Well below average. Comparable $cohort have $median on average ($absPct% more than yours).";
    }
}
