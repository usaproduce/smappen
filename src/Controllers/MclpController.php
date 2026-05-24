<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Maximum Coverage Location Problem — greedy.
 *
 * POST /api/projects/{projectId}/optimize/locations
 * Body: {
 *   candidates: [{lat, lng, label?}, ...]   // OR
 *   bbox: [minLng,minLat,maxLng,maxLat],    // auto-grid candidates within bbox
 *   grid_step_km: 5,                        // when using bbox
 *   pick_count: 5,
 *   radius_km: 8,
 *   demand_metric: "population" | "housing_units" | "income_weighted_pop"
 * }
 *
 * Greedy MCLP is a 1-1/e (≈63%) approximation of optimal — fine for the
 * "open N new stores" use case where users iterate visually anyway.
 */
class MclpController
{
    public function optimize(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        $body = $request->getBody() ?? [];
        $candidatesIn = (array)($body['candidates'] ?? []);
        $bbox = $body['bbox'] ?? null;
        $gridStepKm = (float)($body['grid_step_km'] ?? 5);
        $pickCount = (int)($body['pick_count'] ?? 5);
        $radiusKm = (float)($body['radius_km'] ?? 8);
        $metric = (string)($body['demand_metric'] ?? 'population');

        if (!$projectId) Response::error('projectId required');
        if ($pickCount < 1 || $pickCount > 20) Response::error('pick_count must be 1-20');
        if ($radiusKm <= 0 || $radiusKm > 80) Response::error('radius_km must be 0-80');

        $org = $request->user['organization_id'] ?? null;
        if (!$org) Response::error('User has no organization', 403);
        $project = Database::getInstance()->fetch(
            'SELECT id FROM projects WHERE id = ? AND organization_id = ?',
            [$projectId, $org]
        );
        if (!$project) Response::error('Project not found', 404);

        ini_set('memory_limit', '512M');
        set_time_limit(45);

        $candidates = self::buildCandidates($candidatesIn, $bbox, $gridStepKm);
        if (count($candidates) < $pickCount) {
            Response::error('Not enough candidates (' . count($candidates) . ') for pick_count ' . $pickCount, 422);
        }
        // Old cap was 1500. Even with the spatial pre-filter below, each
        // candidate still does an indexed scan + per-row distance check —
        // 1500 candidates × ~150 tracts in-radius averages ~7s just for
        // SQL roundtrips. 500 keeps us well under the 45s wall-clock + 60s
        // Apache timeout. UI surfaces this as a 422 so the user can lower
        // pick_count or widen grid_step_km.
        if (count($candidates) > 500) {
            Response::error('Too many candidate locations (' . count($candidates) . '). Reduce by raising grid_step_km, narrowing the bbox, or passing an explicit candidates list (max 500).', 422);
        }

        // For each candidate, find tracts within radius_km. The old query did
        // ST_Distance_Sphere on EVERY tract (~84K nationwide) which couldn't
        // use the SPATIAL INDEX → full table scan per candidate. That caused
        // the 504 timeouts seen in earlier logs.
        //
        // Fix: pre-filter with ST_Intersects against a buffer polygon (uses
        // the SPATIAL INDEX on census_tracts.geometry), then refine with the
        // exact ST_Distance_Sphere check. Cuts the per-candidate row count
        // from ~84K to ~150 in a typical metro radius.
        $candidateCoverage = [];
        // 1 degree latitude ≈ 111km. Add a small fudge factor so we don't
        // accidentally exclude tracts whose centroid is just inside the
        // radius but bbox is outside.
        $bufferDeg = ($radiusKm / 111) * 1.1;

        foreach ($candidates as $idx => $c) {
            $minLat = $c['lat'] - $bufferDeg;
            $maxLat = $c['lat'] + $bufferDeg;
            // Longitude degrees shrink with latitude; widen accordingly.
            $cosLat = max(0.000001, cos(deg2rad($c['lat'])));
            $lngBuf = $bufferDeg / $cosLat;
            $minLng = $c['lng'] - $lngBuf;
            $maxLng = $c['lng'] + $lngBuf;
            // SRID-4326 WKT in (lat lng) axis order, matching how
            // census_tracts.geometry is stored (see seed-census.php).
            $bboxWkt = sprintf(
                'POLYGON((%1$.7f %2$.7f, %1$.7f %4$.7f, %3$.7f %4$.7f, %3$.7f %2$.7f, %1$.7f %2$.7f))',
                $minLat, $minLng, $maxLat, $maxLng
            );

            $rows = Database::getInstance()->fetchAll(
                "SELECT ct.geoid,
                        CASE ?
                          WHEN 'housing_units' THEN COALESCE(d.housing_units_total, 0)
                          WHEN 'income_weighted_pop'
                            THEN COALESCE(d.total_population, 0)
                               * (COALESCE(d.median_household_income, 50000) / 50000)
                          ELSE COALESCE(d.total_population, 0)
                        END AS w
                 FROM census_tracts ct
                 LEFT JOIN census_demographics d ON d.geoid = ct.geoid
                 WHERE MBRIntersects(ct.geometry, ST_GeomFromText(?, 4326))
                   AND ST_Distance_Sphere(
                         ST_SRID(ST_Centroid(ST_SRID(ct.geometry, 0)), 4326),
                         ST_GeomFromText(?, 4326)
                       ) <= ?",
                [$metric, $bboxWkt, "POINT({$c['lng']} {$c['lat']})", $radiusKm * 1000]
            );
            $cov = [];
            foreach ($rows as $r) {
                $w = (float)$r['w'];
                if ($w > 0) $cov[$r['geoid']] = $w;
            }
            $candidateCoverage[$idx] = $cov;
        }

        // Greedy pick
        $picked = [];
        $covered = [];
        for ($round = 0; $round < $pickCount; $round++) {
            $bestIdx = -1;
            $bestGain = 0.0;
            foreach ($candidateCoverage as $idx => $cov) {
                if (in_array($idx, $picked, true)) continue;
                $gain = 0.0;
                foreach ($cov as $g => $w) {
                    if (!isset($covered[$g])) $gain += $w;
                }
                if ($gain > $bestGain) {
                    $bestGain = $gain;
                    $bestIdx = $idx;
                }
            }
            if ($bestIdx === -1) break;
            $picked[] = $bestIdx;
            foreach ($candidateCoverage[$bestIdx] as $g => $w) $covered[$g] = $w;
        }

        // Local-search refinement (#43): for each selected location, try
        // swapping it with each non-selected candidate. If the swap raises
        // total covered demand, keep it. Bounded iterations so we don't
        // get stuck — the greedy already gets close enough.
        $maxPasses = 4;
        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $improvedThisPass = false;
            foreach ($picked as $pickIdx => $selectedCandIdx) {
                $totalBest = array_sum($covered);
                $bestSwap = null;
                $bestSwapCovered = null;
                foreach ($candidateCoverage as $altIdx => $altCov) {
                    if (in_array($altIdx, $picked, true)) continue;
                    // Rebuild "covered" without the swapped-out pick, then add the candidate.
                    $tempCovered = [];
                    foreach ($picked as $pi => $ci) {
                        if ($pi === $pickIdx) continue;
                        foreach ($candidateCoverage[$ci] as $g => $w) $tempCovered[$g] = $w;
                    }
                    foreach ($altCov as $g => $w) $tempCovered[$g] = $w;
                    $alt = array_sum($tempCovered);
                    if ($alt > $totalBest) {
                        $totalBest = $alt;
                        $bestSwap = $altIdx;
                        $bestSwapCovered = $tempCovered;
                    }
                }
                if ($bestSwap !== null) {
                    $picked[$pickIdx] = $bestSwap;
                    $covered = $bestSwapCovered;
                    $improvedThisPass = true;
                }
            }
            if (!$improvedThisPass) break;
        }

        $totalCovered = array_sum($covered);
        $totalUniverse = self::totalUniverseDemand($metric, $bbox, $candidates);

        $out = [];
        foreach ($picked as $rank => $idx) {
            $c = $candidates[$idx];
            $uniqueCount = 0;
            $uniqueSum = 0.0;
            // figure out how much THIS pick uniquely contributed
            // by re-running the greedy in-order
        }
        // Re-running greedy to compute per-pick gains in order
        $coveredSoFar = [];
        foreach ($picked as $rank => $idx) {
            $c = $candidates[$idx];
            $unique = 0.0;
            $tracts = 0;
            foreach ($candidateCoverage[$idx] as $g => $w) {
                if (!isset($coveredSoFar[$g])) {
                    $unique += $w;
                    $tracts++;
                    $coveredSoFar[$g] = $w;
                }
            }
            $out[] = [
                'rank' => $rank + 1,
                'lat' => $c['lat'],
                'lng' => $c['lng'],
                'label' => $c['label'] ?? ('Candidate ' . ($idx + 1)),
                'unique_demand' => (int) round($unique),
                'tracts_added' => $tracts,
                'cumulative_demand' => (int) round(array_sum($coveredSoFar)),
            ];
        }

        Response::success([
            'project_id' => $projectId,
            'metric' => $metric,
            'radius_km' => $radiusKm,
            'pick_count' => $pickCount,
            'candidate_count' => count($candidates),
            'picks' => $out,
            'total_covered' => (int) round($totalCovered),
            'total_universe' => (int) round($totalUniverse),
            'coverage_pct' => $totalUniverse > 0 ? round(100 * $totalCovered / $totalUniverse, 2) : null,
        ]);
    }

    private static function buildCandidates(array $explicit, ?array $bbox, float $gridStepKm): array
    {
        if (!empty($explicit)) {
            $out = [];
            foreach ($explicit as $i => $c) {
                $lat = (float)($c['lat'] ?? 0);
                $lng = (float)($c['lng'] ?? 0);
                if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
                $out[] = [
                    'lat' => $lat,
                    'lng' => $lng,
                    'label' => $c['label'] ?? null,
                ];
            }
            return $out;
        }
        if (!$bbox || count($bbox) !== 4) {
            throw new \InvalidArgumentException('Either candidates or bbox required');
        }
        [$minLng, $minLat, $maxLng, $maxLat] = $bbox;
        if ($gridStepKm < 0.5) $gridStepKm = 0.5;
        // 1 deg latitude ≈ 111 km; 1 deg longitude ≈ 111 km × cos(lat)
        $midLat = ($minLat + $maxLat) / 2;
        $dLat = $gridStepKm / 111.0;
        $dLng = $gridStepKm / (111.0 * max(0.1, cos(deg2rad($midLat))));
        $out = [];
        for ($lat = $minLat; $lat <= $maxLat; $lat += $dLat) {
            for ($lng = $minLng; $lng <= $maxLng; $lng += $dLng) {
                $out[] = ['lat' => round($lat, 5), 'lng' => round($lng, 5)];
                if (count($out) > 1500) return $out;
            }
        }
        return $out;
    }

    private static function totalUniverseDemand(string $metric, ?array $bbox, array $candidates): float
    {
        // Universe = tracts within the convex envelope of all candidates,
        // expanded by ~max radius. We approximate with the bbox.
        if (!$bbox) {
            $lats = array_column($candidates, 'lat');
            $lngs = array_column($candidates, 'lng');
            if (empty($lats)) return 0.0;
            $bbox = [min($lngs), min($lats), max($lngs), max($lats)];
        }
        $envelope = sprintf(
            'POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))',
            $bbox[0], $bbox[1], $bbox[2], $bbox[1],
            $bbox[2], $bbox[3], $bbox[0], $bbox[3], $bbox[0], $bbox[1]
        );
        $row = Database::getInstance()->fetch(
            "SELECT SUM(
                CASE ?
                  WHEN 'housing_units' THEN COALESCE(d.housing_units_total, 0)
                  WHEN 'income_weighted_pop'
                    THEN COALESCE(d.total_population, 0)
                       * (COALESCE(d.median_household_income, 50000) / 50000)
                  ELSE COALESCE(d.total_population, 0)
                END
             ) AS u
             FROM census_tracts ct
             LEFT JOIN census_demographics d ON d.geoid = ct.geoid
             WHERE ST_Intersects(ct.geometry, ST_GeomFromText(?, 4326))",
            [$metric, $envelope]
        );
        return (float)($row['u'] ?? 0);
    }
}
