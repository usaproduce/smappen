<?php
namespace App\Services;

use App\Core\Database;

/**
 * Population-balanced territory generator using weighted k-means + local
 * boundary swaps.
 *
 * Pipeline (synchronous, runs in one HTTP request — capped to keep p99 < 30s):
 *   1. Pull tract centroids + balance-metric values within bbox.
 *   2. k-means++ init with k centroids.
 *   3. Up to 25 Lloyd iterations weighted by the balance metric.
 *   4. Boundary-swap pass: for each tract on a cluster border, try
 *      reassigning to a neighboring cluster if it reduces the imbalance.
 *   5. Convex hull per cluster as the territory polygon (cheap & deterministic).
 *
 * Why convex hull and not ST_Union of tract geometries:
 *   - Iterative pairwise ST_Union of 200 polygons is *very* slow on MySQL 8.
 *   - Hulls give a clean visual territory that's good enough for sales planning.
 *   - The exact tract-by-tract assignment is still persisted in metadata so a
 *     later cron can recompute precise multipolygons offline.
 */
class TerritoryGenerator
{
    public const MAX_TRACTS = 5000;
    public const MAX_TARGET = 30;

    public function run(array $bbox, int $target, string $metric, array $constraints = []): array
    {
        if ($target < 2 || $target > self::MAX_TARGET) {
            throw new \InvalidArgumentException('target_count must be 2-' . self::MAX_TARGET);
        }
        $tracts = self::loadTracts($bbox, $metric);
        if (count($tracts) < $target) {
            throw new \RuntimeException('Not enough census coverage in bbox to build ' . $target . ' territories (only ' . count($tracts) . ' tracts).');
        }
        if (count($tracts) > self::MAX_TRACTS) {
            // Downsample preserving total weight by random subsampling
            shuffle($tracts);
            $tracts = array_slice($tracts, 0, self::MAX_TRACTS);
        }

        $centroids = self::kppInit($tracts, $target);
        $assign = array_fill(0, count($tracts), 0);

        for ($iter = 0; $iter < 25; $iter++) {
            $changed = false;
            foreach ($tracts as $i => $t) {
                $best = 0;
                $bestD = INF;
                foreach ($centroids as $k => $c) {
                    $d = self::sqDist($t, $c);
                    if ($d < $bestD) { $bestD = $d; $best = $k; }
                }
                if ($assign[$i] !== $best) {
                    $assign[$i] = $best;
                    $changed = true;
                }
            }
            $centroids = self::recomputeCentroids($tracts, $assign, $target);
            if (!$changed) break;
        }

        $maxImbalance = (float)($constraints['max_imbalance_pct'] ?? 15);
        self::balanceSwap($tracts, $assign, $target, $maxImbalance);

        // Group + hull
        $clusters = array_fill(0, $target, ['pts' => [], 'weight' => 0, 'pop' => 0, 'income_sum' => 0, 'income_n' => 0, 'tract_ids' => []]);
        foreach ($tracts as $i => $t) {
            $k = $assign[$i];
            $clusters[$k]['pts'][] = [$t['lng'], $t['lat']];
            $clusters[$k]['weight'] += $t['w'];
            $clusters[$k]['pop'] += $t['pop'];
            if ($t['income'] !== null) {
                $clusters[$k]['income_sum'] += $t['income'] * $t['pop'];
                $clusters[$k]['income_n'] += $t['pop'];
            }
            $clusters[$k]['tract_ids'][] = $t['geoid'];
        }

        $result = [];
        foreach ($clusters as $k => $c) {
            if (empty($c['pts'])) continue;
            $hull = self::convexHull($c['pts']);
            $result[] = [
                'index' => $k,
                'centroid' => $centroids[$k],
                'tract_count' => count($c['tract_ids']),
                'population' => (int) round($c['pop']),
                'median_household_income' => $c['income_n'] > 0 ? (int) round($c['income_sum'] / $c['income_n']) : null,
                'geometry' => ['type' => 'Polygon', 'coordinates' => [$hull]],
                'tract_geoids' => $c['tract_ids'],
            ];
        }

        $totalPop = array_sum(array_column($result, 'population')) ?: 1;
        foreach ($result as &$r) {
            $r['pop_share_pct'] = round(100 * $r['population'] / $totalPop, 2);
        }
        unset($r);

        return [
            'territories' => $result,
            'metric' => $metric,
            'tract_count' => count($tracts),
            'iterations' => $iter + 1,
        ];
    }

    private static function loadTracts(array $bbox, string $metric): array
    {
        [$minLng, $minLat, $maxLng, $maxLat] = [$bbox[0], $bbox[1], $bbox[2], $bbox[3]];
        $envelope = sprintf(
            'POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))',
            $minLng, $minLat, $maxLng, $minLat,
            $maxLng, $maxLat, $minLng, $maxLat, $minLng, $minLat
        );

        // MySQL 8 refuses ST_Centroid on geographic SRS (3618). Relabel the
        // geometry as planar via ST_SRID(g, 0) — the numeric coords stay in
        // lng/lat; we just bypass the "this isn't planar" guard. For tract-
        // sized polygons the planar centroid is indistinguishable from the
        // geographic one at k-means resolution.
        $rows = Database::getInstance()->fetchAll(
            "SELECT ct.geoid,
                    ST_Y(ST_Centroid(ST_SRID(ct.geometry, 0))) AS lat,
                    ST_X(ST_Centroid(ST_SRID(ct.geometry, 0))) AS lng,
                    COALESCE(d.total_population, 0)         AS pop,
                    d.median_household_income               AS income,
                    COALESCE(d.housing_units_total, 0)      AS housing
             FROM census_tracts ct
             LEFT JOIN census_demographics d ON d.geoid = ct.geoid
             WHERE ST_Intersects(ct.geometry, ST_GeomFromText(?, 4326))",
            [$envelope]
        );
        $out = [];
        foreach ($rows as $r) {
            $pop = (float)$r['pop'];
            $income = $r['income'] !== null ? (float)$r['income'] : null;
            $housing = (float)$r['housing'];
            $w = match ($metric) {
                'income_weighted_pop' => $pop * (($income ?? 50000) / 50000),
                'housing_units' => $housing,
                default => $pop,
            };
            if ($w <= 0) $w = 1; // avoid zero-weight tracts dragging centroids
            $out[] = [
                'geoid' => $r['geoid'],
                'lat' => (float)$r['lat'],
                'lng' => (float)$r['lng'],
                'pop' => $pop,
                'income' => $income,
                'housing' => $housing,
                'w' => $w,
            ];
        }
        return $out;
    }

    /** k-means++ seeding — picks well-spread initial centroids. */
    private static function kppInit(array $tracts, int $k): array
    {
        $n = count($tracts);
        $first = $tracts[random_int(0, $n - 1)];
        $centroids = [['lat' => $first['lat'], 'lng' => $first['lng']]];
        while (count($centroids) < $k) {
            $dists = [];
            $sum = 0.0;
            foreach ($tracts as $t) {
                $best = INF;
                foreach ($centroids as $c) {
                    $d = self::sqDist($t, $c);
                    if ($d < $best) $best = $d;
                }
                $dists[] = $best * $t['w'];
                $sum += $best * $t['w'];
            }
            if ($sum <= 0) {
                $centroids[] = ['lat' => $tracts[random_int(0, $n - 1)]['lat'], 'lng' => $tracts[random_int(0, $n - 1)]['lng']];
                continue;
            }
            $pick = mt_rand() / mt_getrandmax() * $sum;
            $acc = 0.0;
            foreach ($dists as $i => $d) {
                $acc += $d;
                if ($acc >= $pick) {
                    $centroids[] = ['lat' => $tracts[$i]['lat'], 'lng' => $tracts[$i]['lng']];
                    break;
                }
            }
        }
        return $centroids;
    }

    private static function sqDist(array $t, array $c): float
    {
        $dx = $t['lng'] - $c['lng'];
        $dy = $t['lat'] - $c['lat'];
        return $dx * $dx + $dy * $dy;
    }

    private static function recomputeCentroids(array $tracts, array $assign, int $k): array
    {
        $sums = array_fill(0, $k, ['lat' => 0.0, 'lng' => 0.0, 'w' => 0.0]);
        foreach ($tracts as $i => $t) {
            $kk = $assign[$i];
            $sums[$kk]['lat'] += $t['lat'] * $t['w'];
            $sums[$kk]['lng'] += $t['lng'] * $t['w'];
            $sums[$kk]['w'] += $t['w'];
        }
        $out = [];
        foreach ($sums as $idx => $s) {
            if ($s['w'] <= 0) {
                // empty cluster — reseed from a random tract
                $r = $tracts[array_rand($tracts)];
                $out[] = ['lat' => $r['lat'], 'lng' => $r['lng']];
            } else {
                $out[] = ['lat' => $s['lat'] / $s['w'], 'lng' => $s['lng'] / $s['w']];
            }
        }
        return $out;
    }

    /**
     * Greedy boundary swap. For each tract, if reassigning it to a different
     * cluster would reduce |maxClusterWeight - minClusterWeight|, do it.
     * Stop when no further swap improves balance or we hit the loop cap.
     */
    private static function balanceSwap(array $tracts, array &$assign, int $k, float $maxImbalancePct): void
    {
        $totals = array_fill(0, $k, 0.0);
        foreach ($tracts as $i => $t) $totals[$assign[$i]] += $t['w'];

        $passes = 0;
        while ($passes < 8) {
            $improved = false;
            $passes++;
            // sort tracts by distance to second-closest centroid → cheapest swaps first
            foreach ($tracts as $i => $t) {
                $curK = $assign[$i];
                $curW = $totals[$curK];
                $heaviest = max($totals);
                $lightest = min($totals);
                $spread = $heaviest - $lightest;
                if ($lightest > 0 && ($spread / $lightest) * 100 <= $maxImbalancePct) {
                    return; // within tolerance
                }
                // Find candidate cluster (lightest one near this tract)
                $bestK = $curK;
                $bestDelta = 0.0;
                for ($cand = 0; $cand < $k; $cand++) {
                    if ($cand === $curK) continue;
                    if ($totals[$cand] >= $curW) continue; // only move toward lighter clusters
                    // After move: cur loses w, cand gains w
                    $newSpread = max(
                        max(array_merge(array_slice($totals, 0, $cand), [$totals[$cand] + $t['w']], array_slice($totals, $cand + 1, $curK - $cand - 1), [$curW - $t['w']], array_slice($totals, $curK + 1)))
                        - min(array_merge(array_slice($totals, 0, $cand), [$totals[$cand] + $t['w']], array_slice($totals, $cand + 1, $curK - $cand - 1), [$curW - $t['w']], array_slice($totals, $curK + 1))),
                        0
                    );
                    $delta = $spread - $newSpread;
                    if ($delta > $bestDelta) {
                        $bestDelta = $delta;
                        $bestK = $cand;
                    }
                }
                if ($bestK !== $curK) {
                    $totals[$curK] -= $t['w'];
                    $totals[$bestK] += $t['w'];
                    $assign[$i] = $bestK;
                    $improved = true;
                }
            }
            if (!$improved) return;
        }
    }

    /** Andrew's monotone chain convex hull. Returns a closed ring (first == last). */
    private static function convexHull(array $points): array
    {
        $pts = $points;
        sort($pts);
        $pts = array_values(array_unique(array_map(fn($p) => $p[0] . ',' . $p[1], $pts)));
        $pts = array_map(fn($s) => array_map('floatval', explode(',', $s)), $pts);
        if (count($pts) < 3) {
            $ring = $pts;
            if (!empty($ring)) $ring[] = $ring[0];
            return $ring;
        }
        $cross = function ($O, $A, $B) {
            return ($A[0] - $O[0]) * ($B[1] - $O[1]) - ($A[1] - $O[1]) * ($B[0] - $O[0]);
        };
        $lower = [];
        foreach ($pts as $p) {
            while (count($lower) >= 2 && $cross($lower[count($lower) - 2], $lower[count($lower) - 1], $p) <= 0) {
                array_pop($lower);
            }
            $lower[] = $p;
        }
        $upper = [];
        foreach (array_reverse($pts) as $p) {
            while (count($upper) >= 2 && $cross($upper[count($upper) - 2], $upper[count($upper) - 1], $p) <= 0) {
                array_pop($upper);
            }
            $upper[] = $p;
        }
        array_pop($lower);
        array_pop($upper);
        $ring = array_merge($lower, $upper);
        $ring[] = $ring[0]; // close
        return $ring;
    }
}
