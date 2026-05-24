<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * AnalogService — given a "source" area, finds the N closest matching census
 * tracts across the loaded geography by reducing each candidate to an 18-
 * dimensional feature fingerprint (demographics + segments + competition +
 * accessibility) and ranking by cosine similarity.
 *
 * This is the "find me places that look like my best store" feature. Buxton
 * sells it for ~$50K/yr; we expose it free under the Advanced panel.
 *
 * Implementation notes
 * ────────────────────
 *  - Uses `Database::getInstance()` singleton (NOT the spec's PDO + DI pattern).
 *  - CacheService is static (NOT instance — spec assumed instance).
 *  - CensusService::getDemographicsForArea(areaId) returns a nested array
 *    {population, age, income, employment, housing, meta}.
 *  - TrafficService::multiplier expects ('monday', 8) — string day + int hour.
 *  - tract_segments PK is `geoid` (not `tract_geoid` as in the spec).
 *  - reach_cache stores `response` LONGTEXT (not `result_json`).
 *  - Nullable POI/reach dimensions are skipped from the similarity dot/mag
 *    so candidates without that data don't get unfairly penalized.
 */
class AnalogService
{
    // Default per-dimension weights. Demographics dominate; segments matter
    // a lot for "feel"; POI/competition are quieter signals.
    public const DEFAULT_WEIGHTS = [
        1.0, 1.0, 1.0, 1.0,           // density, income, home value, unemployment
        0.8, 0.8, 0.8, 0.8, 0.8,      // age buckets
        0.9, 0.9,                       // income low %, income high %
        0.7, 0.6, 0.8,                 // segment index, concentration, affluence
        0.5, 0.4,                       // POI density, category diversity
        0.6, 0.7,                       // traffic penalty, 15-min reach
    ];

    // Friendly axis-name mapping for the segment vector dimension.
    private const SEGMENT_INDEX = [
        'affluent-suburbs'    => 0,
        'urban-professionals' => 1,
        'family-suburbs'      => 2,
        'working-class-urban' => 3,
        'rural-stable'        => 4,
        'retirement'          => 5,
        'college-towns'       => 6,
        'low-income-urban'    => 7,
        'moderate-suburbs'    => 8,
        'emerging-growth'     => 9,
    ];

    // Higher = wealthier signal. Used to build the "affluence index"
    // dimension as a weighted blend of segments inside the source area.
    private const AFFLUENCE_WEIGHT = [
        'affluent-suburbs'    => 1.0,
        'urban-professionals' => 0.7,
        'family-suburbs'      => 0.3,
        'moderate-suburbs'    => 0.2,
        'college-towns'       => 0.4,
        'retirement'          => 0.4,
        'rural-stable'        => 0.15,
        'working-class-urban' => 0.10,
        'low-income-urban'    => 0.05,
        'emerging-growth'     => 0.30,
    ];

    private CensusService $census;

    public function __construct(?CensusService $census = null)
    {
        $this->census = $census ?? new CensusService();
    }

    /**
     * Main entrypoint. Returns the response payload the controller emits.
     *
     * @param array        $sourceArea  Row from `areas` (must include id, name, center_lat, center_lng, geometry as ST_AsText)
     * @param int          $maxResults  1-50
     * @param float|null   $radiusKm    Optional spatial pre-filter
     * @param array|null   $weights     Optional 18-elt weight override
     */
    public function findAnalogs(
        array $sourceArea,
        int $maxResults = 25,
        ?float $radiusKm = null,
        ?array $weights = null
    ): array {
        $cacheKey = 'analogs:' . $sourceArea['id'] . ':' . $maxResults . ':' . ($radiusKm ?? 'all');
        $cached = CacheService::getJson($cacheKey);
        if ($cached) return $cached;

        $weights = $weights ?? self::DEFAULT_WEIGHTS;

        // Step 1: source fingerprint
        $sourceVector = $this->buildAreaVector($sourceArea);
        if (!$sourceVector) {
            throw new \RuntimeException('Cannot build fingerprint — area has no demographics data');
        }

        // Step 2 + 3: normalization stats + normalized source
        $stats = $this->getNormalizationStats();
        $normalizedSource = $this->normalize($sourceVector, $stats);

        // Step 4: candidates (tracts NOT inside the source area)
        $tracts = $this->loadCandidateTracts($sourceArea, $radiusKm);

        // Step 5: score every tract
        $scored = [];
        foreach ($tracts as $tract) {
            $tractVector = $this->buildTractVector($tract);
            if (!$tractVector) continue;
            $normalizedTract = $this->normalize($tractVector, $stats);
            $sim = self::cosineSimilarity($normalizedSource, $normalizedTract, $weights);
            if ($sim < 0.5) continue; // drop very poor matches outright
            $scored[] = [
                'geoid'      => $tract['geoid'],
                'name'       => $tract['name'],
                'state_fips' => $tract['state_fips'],
                'county_fips'=> $tract['county_fips'],
                'lat'        => (float)$tract['centroid_lat'],
                'lng'        => (float)$tract['centroid_lng'],
                'similarity' => round($sim, 4),
                'raw'        => $tractVector,
                'norm'       => $normalizedTract,
            ];
        }

        // Step 6: rank + slice
        usort($scored, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        $top = array_slice($scored, 0, $maxResults);

        // Step 7: shape output
        $out = [];
        foreach ($top as $r) {
            $out[] = [
                'geoid'       => $r['geoid'],
                'name'        => $r['name'],
                'state_fips'  => $r['state_fips'],
                'county_fips' => $r['county_fips'],
                'lat'         => $r['lat'],
                'lng'         => $r['lng'],
                'similarity'  => $r['similarity'],
                'demographics' => [
                    'population'        => $r['raw']['population'] ?? null,
                    'density_per_sqkm'  => isset($r['raw']['density']) ? round($r['raw']['density'], 1) : null,
                    'median_income'     => $r['raw']['median_income'] ?? null,
                    'median_home_value' => $r['raw']['median_home_value'] ?? null,
                    'dominant_segment'  => $r['raw']['dominant_segment_name'] ?? null,
                ],
                'radar' => $this->buildRadarData($normalizedSource, $r['norm']),
            ];
        }

        $response = [
            'source_area_id'   => $sourceArea['id'],
            'source_area_name' => $sourceArea['name'],
            'source_vector'    => $this->buildRadarData($normalizedSource, $normalizedSource),
            'total_candidates' => count($tracts),
            'results'          => $out,
        ];

        CacheService::set($cacheKey, $response, 86400);
        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Vector building
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Source area: nested CensusService demographics + segments + POI + traffic.
     * Returns null if the area has 0 population (e.g., manual area in
     * unpopulated area or demographics never resolved).
     */
    private function buildAreaVector(array $area): ?array
    {
        $db = Database::getInstance();
        // Pull demographics via the cached areas.demographics_cache lookup
        // (same path the right panel uses). Falls back to an on-demand fetch.
        $demo = null;
        $row = $db->fetch(
            'SELECT demographics_cache FROM areas WHERE id = ?',
            [$area['id']]
        );
        if ($row && !empty($row['demographics_cache'])) {
            $demo = json_decode($row['demographics_cache'], true);
        }
        if (!$demo) {
            $demo = $this->census->getDemographicsForArea($area['id']);
        }
        $pop = (int)($demo['population']['total'] ?? 0);
        if ($pop <= 0) return null;

        $areaKm2 = (float)($demo['meta']['area_sq_km'] ?? 1.0);
        if ($areaKm2 <= 0) $areaKm2 = 0.01;

        // Map the nested demographics shape into the flat shape used by the
        // tract path so assembleVector() can be shared.
        $flat = [
            'total_population'        => $pop,
            'male_total'              => $demo['population']['male'] ?? 0,
            'female_total'            => $demo['population']['female'] ?? 0,
            'median_household_income' => $demo['income']['median_household'] ?? 0,
            'median_home_value'       => $demo['housing']['median_value'] ?? 0,
            'labor_force_total'       => $demo['employment']['labor_force'] ?? 0,
            'unemployed_total'        => $demo['employment']['unemployed'] ?? 0,
            'age_under_18'            => $demo['age']['under_18'] ?? 0,
            'age_18_to_34'            => $demo['age']['18_to_34'] ?? 0,
            'age_35_to_54'            => $demo['age']['35_to_54'] ?? 0,
            'age_55_to_64'            => $demo['age']['55_to_64'] ?? 0,
            'age_65_plus'             => $demo['age']['65_plus'] ?? 0,
            'income_under_25k'        => $demo['income']['brackets']['under_25k'] ?? 0,
            'income_25k_to_50k'       => $demo['income']['brackets']['25k_to_50k'] ?? 0,
            'income_100k_plus'        => $demo['income']['brackets']['100k_plus'] ?? 0,
        ];

        $segments = $this->getAreaSegments($area['id']);
        $poiStats = $this->getAreaPOIStats($area['id']);
        $traffic  = TrafficService::multiplier('monday', 8);
        $reachPop = $this->getReachPopulation($area);

        return $this->assembleVector($flat, $pop, $areaKm2, $segments, $poiStats, $traffic, $reachPop);
    }

    private function buildTractVector(array $tract): ?array
    {
        $pop = (int)($tract['total_population'] ?? 0);
        if ($pop <= 0) return null;

        // land_area_sqm is meters², convert to km². Clamp the floor so tiny
        // census slivers (water-edge tracts) don't blow up density to infinity.
        $landKm2 = max(((float)($tract['land_area_sqm'] ?? 0)) / 1_000_000, 0.01);

        $segmentName = (string)($tract['segment_name'] ?? 'moderate-suburbs');
        $segments = [
            'dominant_index' => self::SEGMENT_INDEX[$segmentName] ?? 8,
            'concentration'  => 1.0, // single tract → 100% concentrated
            'affluence'      => self::AFFLUENCE_WEIGHT[$segmentName] ?? 0.2,
            'dominant_name'  => $segmentName,
        ];

        // POI & reach not computed per-tract (too expensive). Those dims drop
        // out of the cosine; the formula tolerates nulls per-pair.
        $poiStats = null;
        $reachPop = null;
        $traffic  = TrafficService::multiplier('monday', 8);

        return $this->assembleVector($tract, $pop, $landKm2, $segments, $poiStats, $traffic, $reachPop);
    }

    /**
     * Build the raw (unnormalized) 18-dim feature vector from components.
     * Caller normalizes via $this->normalize($vec, $stats).
     */
    private function assembleVector(
        array $demo,
        int $pop,
        float $areaKm2,
        ?array $segments,
        ?array $poiStats,
        float $trafficMult,
        ?int $reachPop
    ): array {
        $safeDiv = fn($n, $d) => $d > 0 ? $n / $d : 0.0;
        return [
            // Demographics (0..10)
            'density'           => $safeDiv($pop, $areaKm2),
            'median_income'     => (float)($demo['median_household_income'] ?? 0),
            'median_home_value' => (float)($demo['median_home_value'] ?? 0),
            'unemployment_rate' => $safeDiv($demo['unemployed_total'] ?? 0, $demo['labor_force_total'] ?? 1),
            'pct_under_18'      => $safeDiv($demo['age_under_18'] ?? 0, $pop),
            'pct_18_34'         => $safeDiv($demo['age_18_to_34'] ?? 0, $pop),
            'pct_35_54'         => $safeDiv($demo['age_35_to_54'] ?? 0, $pop),
            'pct_55_64'         => $safeDiv($demo['age_55_to_64'] ?? 0, $pop),
            'pct_65_plus'       => $safeDiv($demo['age_65_plus'] ?? 0, $pop),
            'pct_income_low'    => $safeDiv(($demo['income_under_25k'] ?? 0) + ($demo['income_25k_to_50k'] ?? 0), $pop),
            'pct_income_high'   => $safeDiv($demo['income_100k_plus'] ?? 0, $pop),

            // Segments (11..13)
            'segment_dominant'      => $segments['dominant_index'] ?? 8,
            'segment_concentration' => $segments['concentration']  ?? 0.5,
            'affluence_index'       => $segments['affluence']      ?? 0.2,

            // Competition (14..15) — null when no POI data
            'poi_density'        => $poiStats ? $poiStats['count'] / max($areaKm2, 0.01) : null,
            'category_diversity' => $poiStats ? min($poiStats['unique_types'] / 20, 1.0) : null,

            // Accessibility (16..17)
            'traffic_penalty'  => $trafficMult,
            'reach_population' => $reachPop,

            // Metadata for display (not part of the vector index)
            'population'            => $pop,
            'dominant_segment_name' => $segments['dominant_name'] ?? null,
        ];
    }

    /**
     * Pull every tract that COULD be a candidate. The big SQL JOIN lets us
     * fold demographics + segment in one fetch so the PHP-side loop is pure
     * math. Excludes tracts that intersect the source area (we want analogs,
     * not the source itself).
     */
    private function loadCandidateTracts(array $sourceArea, ?float $radiusKm): array
    {
        $db = Database::getInstance();
        // Entire-US searches sort 84K rows by computed distance — the default
        // 256K sort_buffer_size triggers "1038 Out of sort memory". Bump for
        // this connection only so we don't bloat memory permanently. 64MB
        // is enough for the full national set + headroom.
        try { $db->query('SET SESSION sort_buffer_size = 67108864'); } catch (\Throwable $e) {}
        try { $db->query('SET SESSION tmp_table_size = 134217728'); } catch (\Throwable $e) {}
        try { $db->query('SET SESSION max_heap_table_size = 134217728'); } catch (\Throwable $e) {}
        $centerLat = (float)($sourceArea['center_lat'] ?? 38.9);
        $centerLng = (float)($sourceArea['center_lng'] ?? -77.0);
        // Parameterize the centroid coords as WKT — avoids string interpolation
        // into SQL. Even though the values are float-cast (un-injectable),
        // parameterizing keeps the convention consistent across the service.
        // MySQL 8 SRID 4326 axis order is (lat lng) — earlier (lng lat) build
        // threw "Latitude out of range" for any US center point.
        $centerWkt = 'POINT(' . $centerLat . ' ' . $centerLng . ')';

        // Resolve the source area's intersecting tract geoids ONCE, up front.
        // Old approach embedded a NOT IN (SELECT ... ST_Intersects ...) subquery
        // that forced MySQL to re-evaluate spatial intersection against every
        // one of the 84K candidate tracts — pushing the query past 60s on
        // continental searches. Splitting it into a pre-step turns it into a
        // single SPATIAL-indexed lookup, then a flat `NOT IN (?, ?, ...)` on
        // the main query.
        $excludeGeoids = [];
        if (!empty($sourceArea['id'])) {
            $rows = $db->fetchAll(
                "SELECT ct.geoid FROM census_tracts ct
                 JOIN areas a ON a.id = ?
                 WHERE ST_Intersects(ct.geometry, a.geometry)",
                [$sourceArea['id']]
            );
            foreach ($rows as $r) $excludeGeoids[] = $r['geoid'];
        }

        // Param order MUST match the order of `?` placeholders in the SQL:
        //   1) ST_PointFromText(?) in the SELECT (centroid distance)
        //   2..N) NOT IN (?, ?, ...) excluded geoids
        //   N+1) HAVING distance_km <= ? (optional radius)
        $params = [$centerWkt];
        $where = ['cd.total_population > 100'];

        if (!empty($excludeGeoids)) {
            $placeholders = implode(',', array_fill(0, count($excludeGeoids), '?'));
            $where[] = "ct.geoid NOT IN ($placeholders)";
            foreach ($excludeGeoids as $g) $params[] = $g;
        }

        // Optional radius filter — uses the source area's center, not centroid.
        // When the source area has no center (manual polygons), skip the filter.
        $havingClause = '';
        if ($radiusKm && !empty($sourceArea['center_lat']) && !empty($sourceArea['center_lng'])) {
            $havingClause = 'HAVING distance_km <= ?';
            $params[] = (float)$radiusKm;
        }

        // Hard cap on candidates scored by PHP. The cosine loop is fast
        // (~1ms per candidate on a JIT-warm worker), but pulling 84K rows
        // into a PHP array blows up memory. 5000 closest tracts is more than
        // enough — even at continental scope the top matches always cluster
        // within the nearest few thousand by distance.
        $hardLimit = 5000;
        $sql = "
            SELECT
                ct.geoid,
                ct.name,
                ct.state_fips,
                ct.county_fips,
                ct.land_area_sqm,
                ST_Y(ST_Centroid(ST_SRID(ct.geometry, 0))) AS centroid_lat,
                ST_X(ST_Centroid(ST_SRID(ct.geometry, 0))) AS centroid_lng,
                ST_Distance_Sphere(
                    ST_Centroid(ST_SRID(ct.geometry, 0)),
                    ST_SRID(ST_PointFromText(?), 0)
                ) / 1000 AS distance_km,
                cd.total_population,
                cd.median_household_income,
                cd.median_home_value,
                cd.labor_force_total,
                cd.unemployed_total,
                cd.age_under_18,
                cd.age_18_to_34,
                cd.age_35_to_54,
                cd.age_55_to_64,
                cd.age_65_plus,
                cd.income_under_25k,
                cd.income_25k_to_50k,
                cd.income_100k_plus,
                ts.segment_name
            FROM census_tracts ct
            JOIN census_demographics cd ON ct.geoid = cd.geoid
            LEFT JOIN tract_segments ts ON ct.geoid = ts.geoid
            WHERE " . implode(' AND ', $where) . "
            {$havingClause}
            ORDER BY distance_km ASC
            LIMIT $hardLimit
        ";

        return $db->fetchAll($sql, $params);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Normalization
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Compute global min/max + sorted-density percentile array. Cached 7d.
     */
    private function getNormalizationStats(): array
    {
        // #10 — prefer the materialized `analog_norm_stats` table. The
        // older on-demand query sorted 84K density values + JSON-encoded
        // them every cache-miss; the materialized row is a single SELECT
        // populated by `scripts/compute-tract-features.php` (run via cron).
        $mat = Database::getInstance()->fetch('SELECT * FROM analog_norm_stats WHERE id = 1');
        if ($mat) {
            $values = $mat['density_values'] ? json_decode(gzuncompress($mat['density_values']), true) : [];
            return [
                'density_min'    => (float)$mat['density_min'],
                'density_max'    => (float)$mat['density_max'],
                'income_min'     => (float)$mat['income_min'],
                'income_max'     => (float)$mat['income_max'],
                'home_value_min' => (float)$mat['home_value_min'],
                'home_value_max' => (float)$mat['home_value_max'],
                'density_values' => is_array($values) ? $values : [],
            ];
        }

        // Fallback path — only hit when the materialized table is empty
        // (i.e., the cron hasn't run yet on a fresh deploy).
        $cached = CacheService::getJson('analog:norm_stats');
        if ($cached) return $cached;

        $db = Database::getInstance();
        $stats = $db->fetch("
            SELECT
                MIN(cd.total_population / GREATEST(ct.land_area_sqm / 1000000, 0.01)) AS density_min,
                MAX(cd.total_population / GREATEST(ct.land_area_sqm / 1000000, 0.01)) AS density_max,
                MIN(cd.median_household_income) AS income_min,
                MAX(cd.median_household_income) AS income_max,
                MIN(cd.median_home_value) AS home_value_min,
                MAX(cd.median_home_value) AS home_value_max
            FROM census_tracts ct
            JOIN census_demographics cd ON ct.geoid = cd.geoid
            WHERE cd.total_population > 100
        ");

        // Densities for percentile-rank normalization. Sort once, binary
        // search later in normalize() to keep the per-tract loop cheap.
        $rows = $db->fetchAll("
            SELECT cd.total_population / GREATEST(ct.land_area_sqm / 1000000, 0.01) AS d
            FROM census_tracts ct
            JOIN census_demographics cd ON ct.geoid = cd.geoid
            WHERE cd.total_population > 100
            ORDER BY d
        ");
        $stats['density_values'] = array_map(fn($r) => (float)$r['d'], $rows);

        CacheService::set('analog:norm_stats', $stats, 604800);
        return $stats;
    }

    /**
     * Project a raw vector into [0,1] (per dim, with nulls preserved).
     * Returns an indexed 18-element array matching DEFAULT_WEIGHTS order.
     */
    private function normalize(array $v, array $stats): array
    {
        $minMax = function ($val, $min, $max) {
            $val = (float)$val; $min = (float)$min; $max = (float)$max;
            return $max > $min ? max(0.0, min(1.0, ($val - $min) / ($max - $min))) : 0.5;
        };
        // Binary search → O(log n) per call vs O(n) for a linear scan.
        $pctRank = function (float $val, array $sorted): float {
            $n = count($sorted);
            if ($n === 0) return 0.5;
            $lo = 0; $hi = $n;
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                if ($sorted[$mid] <= $val) $lo = $mid + 1;
                else $hi = $mid;
            }
            return $lo / $n;
        };

        $densities = $stats['density_values'] ?? [];

        return [
            $pctRank((float)$v['density'], $densities),
            $minMax($v['median_income'],     $stats['income_min'],     $stats['income_max']),
            $minMax($v['median_home_value'], $stats['home_value_min'], $stats['home_value_max']),
            min(max((float)$v['unemployment_rate'], 0.0), 1.0),
            (float)$v['pct_under_18'],
            (float)$v['pct_18_34'],
            (float)$v['pct_35_54'],
            (float)$v['pct_55_64'],
            (float)$v['pct_65_plus'],
            (float)$v['pct_income_low'],
            (float)$v['pct_income_high'],
            ((float)$v['segment_dominant']) / 9.0,
            min(max((float)$v['segment_concentration'], 0.0), 1.0),
            min(max((float)$v['affluence_index'], 0.0), 1.0),
            $v['poi_density'] !== null ? $pctRank((float)$v['poi_density'], $densities) : null,
            $v['category_diversity'] !== null ? min(max((float)$v['category_diversity'], 0.0), 1.0) : null,
            $minMax($v['traffic_penalty'], 1.0, 2.0),
            $v['reach_population'] !== null ? $pctRank((float)$v['reach_population'], $densities) : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Similarity & radar
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Cosine similarity with per-dimension weights. Skips index pairs where
     * either vector has null (so candidates without POI/reach data don't
     * artificially drag the score down).
     */
    public static function cosineSimilarity(array $a, array $b, array $weights): float
    {
        $dot = 0.0; $magA = 0.0; $magB = 0.0;
        $n = min(count($a), count($b), count($weights));
        for ($i = 0; $i < $n; $i++) {
            if ($a[$i] === null || $b[$i] === null) continue;
            $w = (float)$weights[$i];
            $av = (float)$a[$i]; $bv = (float)$b[$i];
            $dot  += $w * $av * $bv;
            $magA += $w * $av * $av;
            $magB += $w * $bv * $bv;
        }
        $denom = sqrt($magA) * sqrt($magB);
        return $denom > 0 ? $dot / $denom : 0.0;
    }

    /**
     * Collapse the 18 dims into a 6-axis radar for the comparison chart.
     * Each axis is the mean of the contributing normalized values (nulls
     * skipped so the visual stays balanced).
     */
    private function buildRadarData(array $sourceNorm, array $candNorm): array
    {
        // Indices that contribute to each radar axis.
        $axes = [
            'Income & wealth'    => [1, 2, 10],
            'Age profile'        => [4, 5, 6, 7, 8],
            'Density & housing'  => [0, 3, 9],
            'Segment fit'        => [11, 12, 13],
            'Competition'        => [14, 15],
            'Accessibility'      => [16, 17],
        ];

        $mean = function (array $vec, array $idx): ?float {
            $s = 0.0; $c = 0;
            foreach ($idx as $i) {
                if (($vec[$i] ?? null) !== null) { $s += (float)$vec[$i]; $c++; }
            }
            return $c > 0 ? round($s / $c, 3) : null;
        };

        return [
            'axes'      => array_keys($axes),
            'source'    => array_map(fn($idx) => $mean($sourceNorm, $idx), array_values($axes)),
            'candidate' => array_map(fn($idx) => $mean($candNorm,   $idx), array_values($axes)),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Segment distribution for the source area — joins areas → census_tracts
     * → tract_segments. Returns dominant segment + Herfindahl-ish
     * concentration + a blended affluence index.
     */
    private function getAreaSegments(string $areaId): array
    {
        $rows = Database::getInstance()->fetchAll("
            SELECT ts.segment_name, COUNT(*) AS cnt
            FROM census_tracts ct
            JOIN tract_segments ts ON ct.geoid = ts.geoid
            JOIN areas a ON a.id = ?
            WHERE ST_Intersects(ct.geometry, a.geometry)
            GROUP BY ts.segment_name
            ORDER BY cnt DESC
        ", [$areaId]);

        if (empty($rows)) {
            return ['dominant_index' => 8, 'concentration' => 0.5, 'affluence' => 0.2, 'dominant_name' => 'moderate-suburbs'];
        }

        $total = array_sum(array_column($rows, 'cnt'));
        $dominant = $rows[0];
        $affluence = 0.0;
        foreach ($rows as $r) {
            $w = self::AFFLUENCE_WEIGHT[$r['segment_name']] ?? 0.0;
            $affluence += $w * ((int)$r['cnt'] / max($total, 1));
        }
        return [
            'dominant_index' => self::SEGMENT_INDEX[$dominant['segment_name']] ?? 8,
            'concentration'  => (int)$dominant['cnt'] / max($total, 1),
            'affluence'      => min(1.0, $affluence),
            'dominant_name'  => $dominant['segment_name'],
        ];
    }

    /**
     * POI counts + category diversity from `poi_cache`. Null when no cached
     * POI scan exists for this area.
     */
    private function getAreaPOIStats(string $areaId): ?array
    {
        $row = Database::getInstance()->fetch(
            'SELECT results FROM poi_cache WHERE area_id = ? ORDER BY cached_at DESC LIMIT 1',
            [$areaId]
        );
        if (!$row || empty($row['results'])) return null;
        $pois = json_decode($row['results'], true);
        if (!is_array($pois)) return null;

        $types = [];
        foreach ($pois as $p) {
            foreach (($p['types'] ?? []) as $t) $types[$t] = true;
        }
        return ['count' => count($pois), 'unique_types' => count($types)];
    }

    /**
     * 15-minute drive reach. Reads `reach_cache.response` JSON, returns the
     * total population field. Returns null if nothing cached.
     */
    private function getReachPopulation(array $area): ?int
    {
        if (empty($area['center_lat']) || empty($area['center_lng'])) return null;
        $key = sprintf('%.3f,%.3f,15', $area['center_lat'], $area['center_lng']);
        $row = Database::getInstance()->fetch(
            'SELECT response FROM reach_cache WHERE cache_key = ?',
            [$key]
        );
        if (!$row || empty($row['response'])) return null;
        $data = json_decode($row['response'], true);
        return isset($data['population']) ? (int)$data['population'] : null;
    }
}
