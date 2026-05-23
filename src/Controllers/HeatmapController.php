<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;

/**
 * Choropleth/heatmap viewport API with zoom-based LOD + server-side tile cache.
 *
 * Levels:
 *   zoom ≤ 7   → states  (state polygons, aggregated)
 *   zoom 8-9   → counties (county polygons, aggregated)
 *   zoom ≥ 10  → tracts  (detail)
 *
 * Tile cache:
 *   bbox is quantized to a coarse grid (depending on level) so neighboring
 *   pan requests collapse to the same cache key. Cached responses live in
 *   heatmap_tile_cache and expire after 7 days.
 */
class HeatmapController
{
    private const METRICS_TRACT = [
        'population' => 'd.total_population',
        'population_density' => 'CASE WHEN ct.land_area_sqm > 0 THEN d.total_population / (ct.land_area_sqm / 1000000.0) ELSE NULL END',
        'median_income' => 'd.median_household_income',
        'median_home_value' => 'd.median_home_value',
        'unemployment_rate' => 'CASE WHEN d.labor_force_total > 0 THEN (d.unemployed_total * 100.0 / d.labor_force_total) ELSE NULL END',
        'housing_units' => 'd.housing_units_total',
    ];

    private const METRICS_AGG = [
        'population' => 'g.total_population',
        'population_density' => 'CASE WHEN g.land_area_sqm > 0 THEN g.total_population / (g.land_area_sqm / 1000000.0) ELSE NULL END',
        'median_income' => 'g.median_household_income',
        'median_home_value' => 'g.median_home_value',
        'unemployment_rate' => 'CASE WHEN g.labor_force_total > 0 THEN (g.unemployed_total * 100.0 / g.labor_force_total) ELSE NULL END',
        'housing_units' => 'g.housing_units_total',
    ];

    public function tracts(Request $request): void
    {
        $bbox = $request->getQuery('bbox', '');
        $metric = $request->getQuery('metric', 'population_density');
        $zoom = (float) $request->getQuery('zoom', 10);
        $levelOverride = $request->getQuery('level'); // 'state' | 'county' | 'tract' | null
        // Bumped 1000→5000 so wide tract-level bboxes don't have silent gaps for
        // tracts past the limit. ORDER BY metric DESC means we'd otherwise drop
        // the least-dense rural tracts first → big visual holes in the map.
        $maxFeatures = min(5000, max(1, (int)$request->getQuery('limit', 3000)));

        if (!isset(self::METRICS_TRACT[$metric])) {
            Response::error('Unknown metric: ' . $metric, 422);
        }
        $parts = array_map('floatval', explode(',', $bbox));
        if (count($parts) !== 4) Response::error('bbox must be lng1,lat1,lng2,lat2', 422);
        [$minLng, $minLat, $maxLng, $maxLat] = $parts;
        if ($minLng >= $maxLng || $minLat >= $maxLat) Response::error('Invalid bbox', 422);

        // Explicit level override wins; otherwise zoom decides.
        $level = in_array($levelOverride, ['state', 'county', 'tract'], true)
            ? $levelOverride
            : self::levelForZoom($zoom);

        // Quantize bbox so neighboring pans collide in cache.
        $q = self::quantizationFor($level);
        $qbbox = [
            floor($minLng / $q) * $q,
            floor($minLat / $q) * $q,
            ceil($maxLng / $q) * $q,
            ceil($maxLat / $q) * $q,
        ];
        $cacheKey = sprintf('hm:%s:%s:%d:%g_%g_%g_%g',
            $level, $metric, $maxFeatures,
            $qbbox[0], $qbbox[1], $qbbox[2], $qbbox[3]);

        $cached = self::cacheGet($cacheKey);
        if ($cached !== null) {
            self::cacheBump($cacheKey);
            $body = json_decode($cached, true);
            if (is_array($body)) {
                $body['meta']['cached'] = true;
                Response::success($body);
            }
        }

        $wkt = sprintf(
            'POLYGON((%1$.7f %2$.7f, %3$.7f %2$.7f, %3$.7f %4$.7f, %1$.7f %4$.7f, %1$.7f %2$.7f))',
            $qbbox[0], $qbbox[1], $qbbox[2], $qbbox[3]
        );

        try {
            $rows = match ($level) {
                'state' => self::fetchStates($metric, $wkt, $maxFeatures),
                'county' => self::fetchCounties($metric, $wkt, $maxFeatures),
                default => self::fetchTracts($metric, $wkt, $maxFeatures),
            };
        } catch (\Throwable $e) {
            error_log('Heatmap query failed (' . $level . '): ' . $e->getMessage());
            Response::success([
                'type' => 'FeatureCollection',
                'features' => [],
                'meta' => [
                    'metric' => $metric,
                    'level' => $level,
                    'count' => 0,
                    'min' => 0, 'max' => 0,
                    'unit' => self::unitFor($metric),
                    'note' => $level === 'tract' ? 'No tract data in this view.' : 'No aggregated data — run scripts/aggregate-geographies.php',
                ],
            ]);
            return;
        }

        $features = [];
        $values = [];
        foreach ($rows as $r) {
            $val = $r['metric_value'] === null ? null : (float)$r['metric_value'];
            if ($val !== null) $values[] = $val;
            $geom = $r['geom'] ? json_decode($r['geom'], true) : null;
            if ($geom && isset($geom['coordinates'])) {
                $geom['coordinates'] = self::swapCoords($geom['coordinates']);
            }
            $features[] = [
                'type' => 'Feature',
                'id' => $r['id'],
                'geometry' => $geom,
                'properties' => [
                    'geoid' => $r['id'],
                    'name' => $r['name'],
                    'value' => $val,
                ],
            ];
        }

        $min = $values ? min($values) : 0;
        $max = $values ? max($values) : 0;
        $breaks = self::computeQuantileBreaks($values, 10);

        $truncated = count($features) >= $maxFeatures;
        $body = [
            'type' => 'FeatureCollection',
            'features' => $features,
            'meta' => [
                'metric' => $metric,
                'level' => $level,
                'count' => count($features),
                'min' => $min, 'max' => $max,
                'breaks' => $breaks,
                'unit' => self::unitFor($metric),
                'cached' => false,
                'bbox_q' => $qbbox,
                // True if we hit the row cap — UI can suggest zooming in / coarser level.
                'truncated' => $truncated,
                'limit' => $maxFeatures,
            ],
        ];

        self::cacheSet($cacheKey, $metric, $level, json_encode($body), 86400 * 7);
        Response::success($body);
    }

    private static function fetchTracts(string $metric, string $wkt, int $limit): array
    {
        $expr = self::METRICS_TRACT[$metric];
        // Same sort/limit-first, JOIN-for-geometry pattern as counties/states.
        // Otherwise MySQL sort buffer can't hold 2K geom strings ordered by metric.
        $sql = "SELECT q.id, q.name, ST_AsGeoJSON(ct.geometry) AS geom, q.metric_value
                FROM (
                  SELECT ct.geoid AS id, ct.name AS name, ($expr) AS metric_value
                  FROM census_tracts ct
                  LEFT JOIN census_demographics d ON d.geoid = ct.geoid
                  WHERE ST_Intersects(ct.geometry, ST_GeomFromText(?, 4326))
                    AND ($expr) IS NOT NULL
                  ORDER BY metric_value DESC
                  LIMIT $limit
                ) q
                JOIN census_tracts ct ON ct.geoid = q.id";
        self::bumpSortBuffer();
        return Database::getInstance()->fetchAll($sql, [$wkt]);
    }

    private static function fetchCounties(string $metric, string $wkt, int $limit): array
    {
        $expr = self::METRICS_AGG[$metric];
        // Cap subquery to a sane number — county zoom rarely needs >300 in view.
        $limit = min($limit, 300);
        $sql = "SELECT q.id, q.name, ST_AsGeoJSON(g.geometry) AS geom, q.metric_value
                FROM (
                  SELECT g.geoid AS id, g.name AS name, ($expr) AS metric_value
                  FROM census_counties g
                  WHERE ST_Intersects(g.geometry, ST_GeomFromText(?, 4326))
                    AND ($expr) IS NOT NULL
                  ORDER BY metric_value DESC
                  LIMIT $limit
                ) q
                JOIN census_counties g ON g.geoid = q.id";
        self::bumpSortBuffer();
        return Database::getInstance()->fetchAll($sql, [$wkt]);
    }

    private static function fetchStates(string $metric, string $wkt, int $limit): array
    {
        $expr = self::METRICS_AGG[$metric];
        // State zoom: max ~50 states ever; clamp aggressively.
        $limit = min($limit, 60);
        $sql = "SELECT q.id, q.name, ST_AsGeoJSON(g.geometry) AS geom, q.metric_value
                FROM (
                  SELECT g.state_fips AS id, g.name AS name, ($expr) AS metric_value
                  FROM census_states g
                  WHERE ST_Intersects(g.geometry, ST_GeomFromText(?, 4326))
                    AND ($expr) IS NOT NULL
                  ORDER BY metric_value DESC
                  LIMIT $limit
                ) q
                JOIN census_states g ON g.state_fips = q.id";
        self::bumpSortBuffer();
        return Database::getInstance()->fetchAll($sql, [$wkt]);
    }

    /**
     * State/county GeoJSON polygons are huge and MySQL's default sort buffer
     * (256KB) can't hold even a small number of them when ordering by metric.
     * Bump to 64MB for this session only — applied before each big-geom query.
     */
    private static function bumpSortBuffer(): void
    {
        try {
            Database::getInstance()->pdo()->exec('SET SESSION sort_buffer_size = 67108864'); // 64MB
        } catch (\Throwable $e) {}
    }

    private static function levelForZoom(float $zoom): string
    {
        if ($zoom <= 7) return 'state';
        if ($zoom <= 9) return 'county';
        return 'tract';
    }

    private static function quantizationFor(string $level): float
    {
        return match ($level) {
            'state' => 5.0,
            'county' => 1.0,
            default => 0.05,
        };
    }

    private static function cacheGet(string $key): ?string
    {
        try {
            $row = Database::getInstance()->fetch(
                'SELECT response FROM heatmap_tile_cache WHERE cache_key = ? AND expires_at > NOW()',
                [$key]
            );
            return $row['response'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function cacheBump(string $key): void
    {
        try {
            Database::getInstance()->query(
                'UPDATE heatmap_tile_cache SET hits = hits + 1 WHERE cache_key = ?',
                [$key]
            );
        } catch (\Throwable $e) {}
    }

    private static function cacheSet(string $key, string $metric, string $level, string $body, int $ttlSeconds): void
    {
        try {
            Database::getInstance()->query(
                'REPLACE INTO heatmap_tile_cache (cache_key, response, metric, level, hits, created_at, expires_at)
                 VALUES (?, ?, ?, ?, 0, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))',
                [$key, $body, $metric, $level, $ttlSeconds]
            );
            if (random_int(1, 50) === 1) {
                Database::getInstance()->pdo()->exec('DELETE FROM heatmap_tile_cache WHERE expires_at < NOW()');
            }
        } catch (\Throwable $e) {}
    }

    private static function computeQuantileBreaks(array $values, int $buckets): array
    {
        if (count($values) < 2 || $buckets < 2) return [];
        sort($values);
        $n = count($values);
        $breaks = [];
        for ($i = 1; $i < $buckets; $i++) {
            $idx = (int) floor(($i / $buckets) * ($n - 1));
            $breaks[] = $values[$idx];
        }
        return $breaks;
    }

    private static function swapCoords($coords)
    {
        if (!is_array($coords) || empty($coords)) return $coords;
        if (count($coords) === 2 && is_numeric($coords[0]) && is_numeric($coords[1])) {
            return [$coords[1], $coords[0]];
        }
        $out = [];
        foreach ($coords as $c) $out[] = self::swapCoords($c);
        return $out;
    }

    private static function unitFor(string $metric): string
    {
        return match ($metric) {
            'population_density' => 'per km²',
            'median_income', 'median_home_value' => 'USD',
            'unemployment_rate' => '%',
            default => '',
        };
    }
}
