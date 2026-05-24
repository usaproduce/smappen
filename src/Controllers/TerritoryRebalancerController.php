<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * NF2 — Sales-Territory Rebalancer (v1: analyzer).
 *
 * POST /api/projects/{projectId}/rebalance
 *
 * Body: {
 *   customers: [{lat, lng, revenue, name?}],   // up to 5000
 *   target_per_territory?: number              // optional — defaults to even split
 * }
 *
 * For v1 we DO NOT redraw geometry — that requires a constraint solver
 * (NP-hard for true min-perimeter equipartition). Instead we:
 *
 *  1. Point-in-polygon classify every customer into its current territory.
 *  2. Sum revenue per territory.
 *  3. Compute imbalance = (max-min)/avg.
 *  4. For each over-revenue territory, identify the K customers closest to a
 *     neighboring (lower-revenue) territory border and flag them as
 *     "candidates to reassign" along with the revenue delta if moved.
 *
 * The output is a *recommendation* the user can act on (manual reassignment
 * in v1, automated re-draw in v2). This is the most-useful 80% delivered now.
 */
class TerritoryRebalancerController
{
    public function analyze(Request $request): void
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(180);

        $projectId = $request->getParam('projectId');
        if (!$projectId) Response::error('projectId required');
        $user = $request->user;
        $org = $user['organization_id'] ?? null;
        if (!$org) Response::error('User has no organization', 403);

        $db = Database::getInstance();
        $project = $db->fetch('SELECT id FROM projects WHERE id = ? AND organization_id = ?', [$projectId, $org]);
        if (!$project) Response::error('Project not found', 404);

        $body = $request->getBody() ?? [];
        $customers = $body['customers'] ?? null;
        if (!is_array($customers) || empty($customers)) Response::error('customers array required', 422);
        if (count($customers) > 5000) Response::error('customers capped at 5000 for v1', 422);

        // Pull the project's territories (areas generated via the territory pipeline).
        $territories = $db->fetchAll(
            'SELECT id, name, ST_AsText(geometry) AS wkt, center_lat, center_lng, fill_color
               FROM areas
              WHERE project_id = ? AND generation_job_id IS NOT NULL',
            [$projectId]
        );
        if (count($territories) < 2) Response::error('Need at least 2 generated territories to rebalance', 422);

        // Classify each customer into a territory via MySQL ST_Contains.
        // Batched: build one query that returns territory_id per customer.
        $perTerritory = [];
        foreach ($territories as $t) $perTerritory[$t['id']] = ['id' => $t['id'], 'name' => $t['name'], 'color' => $t['fill_color'], 'revenue' => 0.0, 'count' => 0, 'customers' => []];
        $unassigned = [];

        foreach ($customers as $i => $c) {
            $lat = (float)($c['lat'] ?? 0);
            $lng = (float)($c['lng'] ?? 0);
            $rev = (float)($c['revenue'] ?? 0);
            $name = $c['name'] ?? ('Customer ' . ($i + 1));
            // Build a point WKT in the same axis order census/areas use
            // (lat lng, per the BF1 convention).
            $pt = "POINT($lat $lng)";
            $hit = $db->fetch(
                'SELECT id FROM areas
                  WHERE project_id = ? AND generation_job_id IS NOT NULL
                    AND ST_Contains(geometry, ST_GeomFromText(?, 4326))
                  LIMIT 1',
                [$projectId, $pt]
            );
            if ($hit && isset($perTerritory[$hit['id']])) {
                $perTerritory[$hit['id']]['revenue'] += $rev;
                $perTerritory[$hit['id']]['count']++;
                $perTerritory[$hit['id']]['customers'][] = ['name' => $name, 'lat' => $lat, 'lng' => $lng, 'revenue' => $rev];
            } else {
                $unassigned[] = ['name' => $name, 'lat' => $lat, 'lng' => $lng, 'revenue' => $rev];
            }
        }

        $totals = array_values($perTerritory);
        $totalRevenue = array_sum(array_column($totals, 'revenue'));
        $target = isset($body['target_per_territory'])
            ? (float)$body['target_per_territory']
            : ($totalRevenue / max(count($totals), 1));

        // Compute deltas vs target.
        foreach ($totals as &$t) {
            $t['delta_vs_target'] = round($t['revenue'] - $target, 2);
            $t['delta_pct'] = $target > 0 ? round((($t['revenue'] - $target) / $target) * 100, 1) : 0;
        }
        unset($t);

        // Identify the most-imbalanced pair (over vs under) and suggest the
        // customers closest to each other across the territory line as
        // candidates to reassign.
        usort($totals, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        $over = $totals[0];
        $under = $totals[count($totals) - 1];
        $suggestions = [];
        if ($over['revenue'] > $under['revenue'] && !empty($over['customers'])) {
            // From the over-revenue territory, recommend the customers
            // geographically closest to the under-revenue territory's centroid.
            $u = null;
            foreach ($territories as $t) if ($t['id'] === $under['id']) { $u = $t; break; }
            if ($u && $u['center_lat'] && $u['center_lng']) {
                $uLat = (float)$u['center_lat'];
                $uLng = (float)$u['center_lng'];
                $ranked = $over['customers'];
                foreach ($ranked as &$c) {
                    $c['_dist'] = haversineKm($uLat, $uLng, $c['lat'], $c['lng']);
                }
                unset($c);
                usort($ranked, fn($a, $b) => $a['_dist'] <=> $b['_dist']);
                $needed = max(0, ($over['revenue'] - $under['revenue']) / 2);
                $accumulated = 0;
                foreach ($ranked as $c) {
                    if ($accumulated >= $needed) break;
                    $suggestions[] = [
                        'customer'        => ['name' => $c['name'], 'lat' => $c['lat'], 'lng' => $c['lng']],
                        'revenue'         => $c['revenue'],
                        'from_territory'  => $over['id'],
                        'to_territory'    => $under['id'],
                        'distance_to_target_km' => round($c['_dist'], 2),
                    ];
                    $accumulated += $c['revenue'];
                }
            }
        }

        // ── v2: optional FULL redraw ──────────────────────────────────────
        // When body.redraw === true, we ALSO compute new territory polygons
        // by k-means'ing customers into N clusters (k = current territory
        // count), weighted by revenue, then taking the convex hull of each
        // cluster's customers + a small buffer. Saved as a NEW set of
        // areas tagged with generation_job_id = "rebalance-{timestamp}" so
        // the user can preview before deleting the originals.
        $redraw = !empty($body['redraw']);
        $redrawnAreas = null;
        if ($redraw) {
            $k = count($territories);
            $clusters = self::kMeansRevenue($customers, $k, 25);
            $redrawnAreas = [];
            $stamp = 'rebalance-' . date('YmdHis');
            $db2 = $db;
            foreach ($clusters as $idx => $points) {
                if (count($points) < 3) continue;
                $hull = self::convexHullWithBuffer($points, 0.05); // ~5km buffer
                $id = \App\Core\Database::uuid();
                $wkt = 'POLYGON((' . implode(',', array_map(
                    fn($p) => sprintf('%.6f %.6f', $p['lat'], $p['lng']),
                    $hull
                )) . '))';
                // Use the matching color of the original territory if we
                // can (same index = same color), so users can visually
                // compare old vs new.
                $color = $territories[$idx]['fill_color'] ?? '#7848BB';
                try {
                    $db2->query(
                        'INSERT INTO areas
                           (id, project_id, name, area_type, geometry, fill_color, stroke_color,
                            fill_opacity, stroke_weight, generation_job_id, created_at, updated_at)
                         VALUES (?, ?, ?, "manual", ST_GeomFromText(?, 4326), ?, ?, 0.25, 2, ?, ?, ?)',
                        [$id, $projectId, "Rebalanced #" . ($idx + 1), $wkt,
                         $color, $color, $stamp, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
                    );
                    $redrawnAreas[] = ['id' => $id, 'name' => "Rebalanced #" . ($idx + 1),
                                       'color' => $color, 'customer_count' => count($points)];
                } catch (\Throwable $e) {
                    error_log('Rebalancer redraw save failed: ' . $e->getMessage());
                }
            }
        }

        Response::success([
            'project_id'          => $projectId,
            'target_per_territory'=> round($target, 2),
            'total_revenue'       => round($totalRevenue, 2),
            'imbalance_pct'       => $target > 0 && count($totals) > 0
                ? round((($totals[0]['revenue'] - end($totals)['revenue']) / max($target, 1)) * 100, 1)
                : 0,
            'territories'         => array_map(fn($t) => [
                'id' => $t['id'], 'name' => $t['name'], 'color' => $t['color'],
                'revenue' => round($t['revenue'], 2), 'count' => $t['count'],
                'delta_vs_target' => $t['delta_vs_target'], 'delta_pct' => $t['delta_pct'],
            ], $totals),
            'unassigned'          => $unassigned,
            'suggestions'         => $suggestions,
            'redrawn_areas'       => $redrawnAreas,
        ]);
    }

    /**
     * Revenue-weighted k-means in lat/lng space. 25 iterations is enough
     * for typical N=500-5000 customers + k=4-20 territories. Each cluster
     * targets equal total-revenue (the "balanced" part), not equal-count.
     */
    private static function kMeansRevenue(array $customers, int $k, int $maxIter = 25): array
    {
        if ($k < 2 || count($customers) < $k) return [];
        // Initial centroids — pick k customers spread out by revenue rank.
        $sorted = $customers;
        usort($sorted, fn($a, $b) => ($b['revenue'] ?? 0) <=> ($a['revenue'] ?? 0));
        $centers = [];
        $step = (int)floor(count($sorted) / $k);
        for ($i = 0; $i < $k; $i++) {
            $c = $sorted[min($i * $step, count($sorted) - 1)];
            $centers[] = ['lat' => (float)$c['lat'], 'lng' => (float)$c['lng']];
        }

        for ($iter = 0; $iter < $maxIter; $iter++) {
            $clusters = array_fill(0, $k, []);
            $revenueByCluster = array_fill(0, $k, 0.0);
            $targetRev = array_sum(array_column($customers, 'revenue')) / $k;

            foreach ($customers as $c) {
                $clat = (float)($c['lat'] ?? 0); $clng = (float)($c['lng'] ?? 0);
                $rev = (float)($c['revenue'] ?? 0);
                // Find nearest center, but PENALIZE clusters that are already
                // over target — that's the "balanced" part of revenue-weighted
                // k-means.
                $best = 0; $bestScore = INF;
                for ($j = 0; $j < $k; $j++) {
                    $dLat = $centers[$j]['lat'] - $clat;
                    $dLng = $centers[$j]['lng'] - $clng;
                    $dist = sqrt($dLat * $dLat + $dLng * $dLng);
                    $overshootPenalty = max(0, $revenueByCluster[$j] - $targetRev) / max($targetRev, 1);
                    $score = $dist * (1 + 0.5 * $overshootPenalty);
                    if ($score < $bestScore) { $bestScore = $score; $best = $j; }
                }
                $clusters[$best][] = $c;
                $revenueByCluster[$best] += $rev;
            }

            // Recompute centers as revenue-weighted means.
            $moved = false;
            for ($j = 0; $j < $k; $j++) {
                if (empty($clusters[$j])) continue;
                $sumLat = 0; $sumLng = 0; $sumW = 0;
                foreach ($clusters[$j] as $c) {
                    $w = max(1.0, (float)($c['revenue'] ?? 0));
                    $sumLat += $w * (float)$c['lat'];
                    $sumLng += $w * (float)$c['lng'];
                    $sumW   += $w;
                }
                $newLat = $sumW > 0 ? $sumLat / $sumW : $centers[$j]['lat'];
                $newLng = $sumW > 0 ? $sumLng / $sumW : $centers[$j]['lng'];
                if (abs($newLat - $centers[$j]['lat']) > 1e-6 || abs($newLng - $centers[$j]['lng']) > 1e-6) {
                    $moved = true;
                }
                $centers[$j] = ['lat' => $newLat, 'lng' => $newLng];
            }
            if (!$moved) break;
        }

        // Reconstruct final clusters with the converged centers.
        $final = array_fill(0, $k, []);
        foreach ($customers as $c) {
            $clat = (float)($c['lat'] ?? 0); $clng = (float)($c['lng'] ?? 0);
            $best = 0; $bestDist = INF;
            for ($j = 0; $j < $k; $j++) {
                $dLat = $centers[$j]['lat'] - $clat;
                $dLng = $centers[$j]['lng'] - $clng;
                $d = $dLat * $dLat + $dLng * $dLng;
                if ($d < $bestDist) { $bestDist = $d; $best = $j; }
            }
            $final[$best][] = $c;
        }
        return $final;
    }

    /**
     * Andrew's monotone chain convex hull, then expand each vertex outward
     * by `bufferDeg` degrees. Crude but good enough for a territory shape;
     * v3 will replace with a real alpha-shape / concave hull.
     */
    private static function convexHullWithBuffer(array $points, float $bufferDeg = 0.05): array
    {
        if (count($points) < 3) return $points;
        usort($points, fn($a, $b) =>
            $a['lng'] === $b['lng']
                ? ($a['lat'] <=> $b['lat'])
                : ($a['lng'] <=> $b['lng'])
        );
        $cross = fn($O, $A, $B) =>
            ($A['lng'] - $O['lng']) * ($B['lat'] - $O['lat'])
            - ($A['lat'] - $O['lat']) * ($B['lng'] - $O['lng']);
        $lower = [];
        foreach ($points as $p) {
            while (count($lower) >= 2 && $cross($lower[count($lower) - 2], $lower[count($lower) - 1], $p) <= 0) array_pop($lower);
            $lower[] = $p;
        }
        $upper = [];
        foreach (array_reverse($points) as $p) {
            while (count($upper) >= 2 && $cross($upper[count($upper) - 2], $upper[count($upper) - 1], $p) <= 0) array_pop($upper);
            $upper[] = $p;
        }
        array_pop($lower); array_pop($upper);
        $hull = array_merge($lower, $upper);

        // Centroid for buffer direction.
        $cx = array_sum(array_column($hull, 'lng')) / count($hull);
        $cy = array_sum(array_column($hull, 'lat')) / count($hull);
        $buffered = [];
        foreach ($hull as $p) {
            $dx = $p['lng'] - $cx; $dy = $p['lat'] - $cy;
            $len = sqrt($dx * $dx + $dy * $dy) ?: 1;
            $buffered[] = [
                'lng' => $p['lng'] + ($dx / $len) * $bufferDeg,
                'lat' => $p['lat'] + ($dy / $len) * $bufferDeg,
            ];
        }
        // Close the ring.
        $buffered[] = $buffered[0];
        return $buffered;
    }
}

function haversineKm(float $la1, float $lo1, float $la2, float $lo2): float
{
    $R = 6371.0;
    $dLat = deg2rad($la2 - $la1);
    $dLon = deg2rad($lo2 - $lo1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLon / 2) ** 2;
    return 2 * $R * asin(sqrt($a));
}
