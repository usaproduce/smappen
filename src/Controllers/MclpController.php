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
            Response::error('Not enough candidates (' . count($candidates) . ') for pick_count ' . $pickCount);
        }
        if (count($candidates) > 1500) {
            Response::error('Too many candidate locations (' . count($candidates) . '). Reduce grid_step_km or pass an explicit candidates list.');
        }

        // For each candidate: list of (tract geoid, weight).
        // We do this in one SQL pass per candidate using ST_Distance_Sphere on
        // tract centroids — much cheaper than ST_Intersects against polygons.
        $candidateCoverage = [];
        foreach ($candidates as $idx => $c) {
            // Two MySQL 8 quirks compounded here:
            //   - ST_Centroid does not work on geographic SRS → relabel with ST_SRID(g, 0)
            //   - The centroid then loses its SRID, so ST_Distance_Sphere
            //     would reject it; we re-tag it with SRID 4326 before the
            //     distance check. The numeric coords are preserved.
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
                 WHERE ST_Distance_Sphere(
                         ST_SRID(ST_Centroid(ST_SRID(ct.geometry, 0)), 4326),
                         ST_GeomFromText(?, 4326)
                       ) <= ?",
                [$metric, "POINT({$c['lng']} {$c['lat']})", $radiusKm * 1000]
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
