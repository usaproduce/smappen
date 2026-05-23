<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;

/**
 * Choropleth/heatmap viewport API.
 *
 * Returns a GeoJSON FeatureCollection of census tracts intersecting a given
 * bounding box, with the requested metric attached to each tract's properties
 * for client-side coloring.
 */
class HeatmapController
{
    private const METRICS = [
        'population' => 'd.total_population',
        'population_density' => 'CASE WHEN ct.land_area_sqm > 0 THEN d.total_population / (ct.land_area_sqm / 1000000.0) ELSE NULL END',
        'median_income' => 'd.median_household_income',
        'median_home_value' => 'd.median_home_value',
        'unemployment_rate' => 'CASE WHEN d.labor_force_total > 0 THEN (d.unemployed_total * 100.0 / d.labor_force_total) ELSE NULL END',
        'housing_units' => 'd.housing_units_total',
    ];

    public function tracts(Request $request): void
    {
        $bbox = $request->getQuery('bbox', '');
        $metric = $request->getQuery('metric', 'population_density');
        $maxFeatures = min(2000, max(1, (int)$request->getQuery('limit', 1000)));

        if (!isset(self::METRICS[$metric])) {
            Response::error('Unknown metric: ' . $metric, 422);
        }
        $parts = array_map('floatval', explode(',', $bbox));
        if (count($parts) !== 4) Response::error('bbox must be lng1,lat1,lng2,lat2', 422);
        [$minLng, $minLat, $maxLng, $maxLat] = $parts;
        if ($minLng >= $maxLng || $minLat >= $maxLat) Response::error('Invalid bbox', 422);

        $metricExpr = self::METRICS[$metric];

        $wkt = sprintf(
            'POLYGON((%1$.7f %2$.7f, %3$.7f %2$.7f, %3$.7f %4$.7f, %1$.7f %4$.7f, %1$.7f %2$.7f))',
            $minLng, $minLat, $maxLng, $maxLat
        );

        // This MySQL 8 build interprets SRID 4326 as long-lat by default (verified empirically
        // against TIGER tracts loaded via ogr2ogr). Passing the explicit 'axis-order=long-lat'
        // hint actually inverts the coords here, so we omit it.
        $sql = "SELECT ct.geoid,
                       ct.name,
                       ST_AsGeoJSON(ct.geometry) AS geom,
                       ($metricExpr) AS metric_value
                FROM census_tracts ct
                LEFT JOIN census_demographics d ON d.geoid = ct.geoid
                WHERE ST_Intersects(ct.geometry, ST_GeomFromText(?, 4326))
                  AND ($metricExpr) IS NOT NULL
                ORDER BY metric_value DESC
                LIMIT $maxFeatures";

        try {
            $rows = Database::getInstance()->fetchAll($sql, [$wkt]);
        } catch (\Throwable $e) {
            // census_tracts table empty/missing OR the long-lat hint not supported by this
            // MySQL build — return empty collection so UI shows a friendly hint.
            Response::success([
                'type' => 'FeatureCollection',
                'features' => [],
                'meta' => [
                    'metric' => $metric,
                    'count' => 0,
                    'min' => 0,
                    'max' => 0,
                    'unit' => self::unitFor($metric),
                    'note' => 'No census data loaded. Run scripts/seed-census.php on the server.',
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
            // MySQL 8 ST_AsGeoJSON for SRID 4326 returns coords in [lat, lng] order,
            // but the GeoJSON spec + Google Maps Data layer both expect [lng, lat]. Swap.
            if ($geom && isset($geom['coordinates'])) {
                $geom['coordinates'] = self::swapCoords($geom['coordinates']);
            }
            $features[] = [
                'type' => 'Feature',
                'id' => $r['geoid'],
                'geometry' => $geom,
                'properties' => [
                    'geoid' => $r['geoid'],
                    'name' => $r['name'],
                    'value' => $val,
                ],
            ];
        }

        $min = $values ? min($values) : 0;
        $max = $values ? max($values) : 0;

        Response::success([
            'type' => 'FeatureCollection',
            'features' => $features,
            'meta' => [
                'metric' => $metric,
                'count' => count($features),
                'min' => $min,
                'max' => $max,
                'unit' => self::unitFor($metric),
            ],
        ]);
    }

    /**
     * Recursively swap nested coordinate pairs [a,b] → [b,a].
     * Works for Polygon (3 levels) and MultiPolygon (4 levels).
     */
    private static function swapCoords($coords)
    {
        if (!is_array($coords) || empty($coords)) return $coords;
        // Leaf coordinate pair? [number, number]
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
