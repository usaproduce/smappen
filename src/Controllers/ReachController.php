<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\GeoUtils;

/**
 * "Reach N people" — given a center point and a population target, find the
 * smallest circle around the center whose tract-weighted population meets the
 * target. Uses an expanding doubling phase to bracket the answer, then binary
 * search to refine.
 *
 * Also exposes a quick "demographics preview" for any drafted geometry, so the
 * AreaCreator can show population/income live as the user adjusts a slider.
 */
class ReachController
{
    private const MIN_TARGET = 100;
    private const MAX_RADIUS_KM = 250;
    private const MIN_RADIUS_KM = 0.2;

    public function calculate(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $lat = (float)($body['lat'] ?? 0);
        $lng = (float)($body['lng'] ?? 0);
        $target = (int)($body['target_population'] ?? 0);

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) Response::error('Invalid coordinates');
        if ($target < self::MIN_TARGET) Response::error('target_population must be at least ' . self::MIN_TARGET);

        // Cache by 3-decimal coordinates (~110m granularity) + target snapped to 500.
        $latR = round($lat, 3);
        $lngR = round($lng, 3);
        $targetSnap = (int) round($target / 500) * 500;
        $cacheKey = "reach:{$latR}:{$lngR}:{$targetSnap}";
        $cached = self::reachCacheGet($cacheKey);
        if ($cached !== null) {
            $hit = json_decode($cached, true);
            if (is_array($hit)) {
                $hit['cached'] = true;
                Response::success($hit);
            }
        }

        // Expanding bracket: start tiny, grow until pop >= target.
        $radius = 0.5;
        $popAt = 0;
        $iters = 0;
        while ($radius <= self::MAX_RADIUS_KM) {
            $popAt = self::populationInCircle($lat, $lng, $radius);
            if ($popAt >= $target) break;
            $radius *= 1.7;
            $iters++;
            if ($iters > 18) break;
        }
        if ($popAt < $target) {
            Response::error("Couldn't reach $target people within " . self::MAX_RADIUS_KM . " km. Census coverage may be missing for this area.", 422);
        }

        // Binary search for smallest radius meeting target.
        $high = $radius;
        $low = max(self::MIN_RADIUS_KM, $radius / 1.7);
        while ($high - $low > 0.1) {
            $mid = ($low + $high) / 2;
            $pop = self::populationInCircle($lat, $lng, $mid);
            if ($pop >= $target) {
                $high = $mid;
                $popAt = $pop;
            } else {
                $low = $mid;
            }
        }

        $finalRadius = round($high, 2);
        $popAt = self::populationInCircle($lat, $lng, $finalRadius);
        $geom = GeoUtils::generateCirclePolygon($lat, $lng, $finalRadius);

        $result = [
            'geometry' => $geom,
            'center' => ['lat' => $lat, 'lng' => $lng],
            'radius_km' => $finalRadius,
            'radius_mi' => round($finalRadius * 0.621371, 2),
            'area_sq_km' => round(M_PI * $finalRadius * $finalRadius, 2),
            'population' => $popAt,
            'target_population' => $target,
            'cached' => false,
        ];
        self::reachCacheSet($cacheKey, json_encode($result), 86400 * 30); // 30 days
        Response::success($result);
    }

    private static function reachCacheGet(string $key): ?string
    {
        try {
            $row = Database::getInstance()->fetch(
                'SELECT response FROM reach_cache WHERE cache_key = ? AND expires_at > NOW()',
                [$key]
            );
            if ($row) {
                Database::getInstance()->query('UPDATE reach_cache SET hits = hits + 1 WHERE cache_key = ?', [$key]);
                return $row['response'];
            }
        } catch (\Throwable $e) {}
        return null;
    }

    private static function reachCacheSet(string $key, string $body, int $ttlSeconds): void
    {
        try {
            Database::getInstance()->query(
                'REPLACE INTO reach_cache (cache_key, response, hits, created_at, expires_at)
                 VALUES (?, ?, 0, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))',
                [$key, $body, $ttlSeconds]
            );
        } catch (\Throwable $e) {}
    }

    public function preview(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $geometry = $body['geometry'] ?? null;
        if (!$geometry || ($geometry['type'] ?? '') !== 'Polygon') {
            Response::error('Polygon geometry required');
        }
        try {
            $wkt = GeoUtils::geoJsonToWkt($geometry);
        } catch (\Throwable $e) {
            Response::error('Invalid geometry: ' . $e->getMessage());
        }
        $stats = self::statsForWkt($wkt);
        $areaSqKm = GeoUtils::calculateArea($geometry);
        Response::success([
            'population' => (int) round($stats['pop']),
            'median_household_income' => $stats['median_income'] === null ? null : (int) round($stats['median_income']),
            'tracts_intersected' => (int) $stats['tracts'],
            'area_sq_km' => round($areaSqKm, 2),
            'density_per_sq_km' => $areaSqKm > 0 ? (int) round($stats['pop'] / $areaSqKm) : 0,
        ]);
    }

    private static function populationInCircle(float $lat, float $lng, float $radiusKm): int
    {
        $geom = GeoUtils::generateCirclePolygon($lat, $lng, $radiusKm);
        $wkt = GeoUtils::geoJsonToWkt($geom);
        return (int) round(self::statsForWkt($wkt)['pop']);
    }

    /**
     * Weighted population + population-weighted median income for tracts intersecting $wkt.
     *
     * NOTE: ST_Intersection of two polygons that share only an edge returns a
     * GEOMETRYCOLLECTION, which ST_Area rejects with "unexpected type" (MySQL 3516).
     * We filter by ST_GeometryType so only real-area overlaps contribute.
     */
    private static function statsForWkt(string $wkt): array
    {
        $sql = "
            SELECT
              COALESCE(SUM(d.total_population * overlap_pct), 0) AS pop,
              CASE WHEN SUM(d.total_population * overlap_pct) > 0 THEN
                SUM(d.median_household_income * d.total_population * overlap_pct)
                / NULLIF(SUM(CASE WHEN d.median_household_income IS NOT NULL
                                  THEN d.total_population * overlap_pct ELSE 0 END), 0)
              END AS median_income,
              COUNT(*) AS tracts
            FROM (
              SELECT ct.geoid,
                     CASE
                       WHEN ST_GeometryType(ST_Intersection(ct.geometry, ST_GeomFromText(?, 4326)))
                            IN ('Polygon', 'MultiPolygon')
                         THEN ST_Area(ST_Intersection(ct.geometry, ST_GeomFromText(?, 4326)))
                              / NULLIF(ST_Area(ct.geometry), 0)
                       ELSE 0
                     END AS overlap_pct
              FROM census_tracts ct
              WHERE ST_Intersects(ct.geometry, ST_GeomFromText(?, 4326))
            ) inter
            JOIN census_demographics d ON d.geoid = inter.geoid
            WHERE inter.overlap_pct > 0
        ";
        try {
            $row = Database::getInstance()->fetch($sql, [$wkt, $wkt, $wkt]);
            return [
                'pop' => (float)($row['pop'] ?? 0),
                'median_income' => isset($row['median_income']) && $row['median_income'] !== null
                    ? (float)$row['median_income'] : null,
                'tracts' => (int)($row['tracts'] ?? 0),
            ];
        } catch (\Throwable $e) {
            error_log('ReachController statsForWkt: ' . $e->getMessage());
            return ['pop' => 0, 'median_income' => null, 'tracts' => 0];
        }
    }
}
