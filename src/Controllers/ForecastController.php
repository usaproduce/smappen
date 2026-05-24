<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\AnalogService;

/**
 * NF3 — Demand Forecasting from Analogs.
 *
 * POST /api/areas/{id}/forecast
 *
 * Body: {
 *   training_data: [{area_id, revenue}],  // existing locations w/ known revenue
 *   confidence_interval?: number          // 0.95 default
 * }
 *
 * Algorithm: for each training point, find the analog-finder fingerprint;
 * then find the K nearest analogs (by cosine sim) of the *candidate* area
 * inside the training set; return a similarity-weighted revenue mean +
 * stddev as the confidence band.
 *
 * No ML model needed — pure k-NN regression over the existing analog
 * cosine-similarity space, which is already the right "what makes places
 * alike" metric for this. Stays interpretable (we return which training
 * locations contributed and by how much).
 */
class ForecastController
{
    public function predict(Request $request): void
    {
        @ini_set('memory_limit', '256M');
        @set_time_limit(60);

        $candidateId = $request->getParam('id');
        if (!$candidateId) Response::error('candidate area id required');
        $user = $request->user;
        $org = $user['organization_id'] ?? null;
        if (!$org) Response::error('User has no organization', 403);

        $db = Database::getInstance();
        $candidate = $db->fetch(
            'SELECT a.id, a.name, a.demographics_cache, a.center_lat, a.center_lng,
                    ST_AsText(a.geometry) AS geometry_wkt
               FROM areas a JOIN projects p ON p.id = a.project_id
              WHERE a.id = ? AND p.organization_id = ?',
            [$candidateId, $org]
        );
        if (!$candidate) Response::error('Area not found', 404);

        $body = $request->getBody() ?? [];
        $training = $body['training_data'] ?? null;
        if (!is_array($training) || count($training) < 3) {
            Response::error('At least 3 training_data entries required (area_id + revenue)', 422);
        }

        // Validate every training area belongs to this org.
        $trainIds = array_filter(array_map(fn($t) => $t['area_id'] ?? null, $training));
        if (empty($trainIds)) Response::error('Each training entry needs area_id', 422);
        $place = implode(',', array_fill(0, count($trainIds), '?'));
        $owned = $db->fetchAll(
            "SELECT a.id, a.name, a.demographics_cache, a.center_lat, a.center_lng,
                    ST_AsText(a.geometry) AS geometry_wkt
               FROM areas a JOIN projects p ON p.id = a.project_id
              WHERE a.id IN ($place) AND p.organization_id = ?",
            array_merge($trainIds, [$org])
        );
        if (count($owned) !== count($trainIds)) {
            Response::error('One or more training area_ids not in this org', 403);
        }

        // Build a quick id→revenue map.
        $revenueByArea = [];
        foreach ($training as $t) $revenueByArea[$t['area_id']] = (float)($t['revenue'] ?? 0);

        // Compute fingerprints by reusing AnalogService — we go through its
        // public path (cosineSimilarity) but build vectors via a small
        // helper. To keep this small, we use a tighter feature set: just
        // the publicly available demographics_cache, scoring on 11 dims.
        $candidateVec = self::fingerprint($candidate);
        if (!$candidateVec) Response::error('Candidate has no demographics — open it once first', 422);

        $weights = array_fill(0, 11, 1.0);
        $similarities = [];
        foreach ($owned as $a) {
            $v = self::fingerprint($a);
            if (!$v) continue;
            $sim = AnalogService::cosineSimilarity($candidateVec, $v, $weights);
            $similarities[] = [
                'area_id' => $a['id'], 'name' => $a['name'],
                'similarity' => round($sim, 4),
                'revenue' => $revenueByArea[$a['id']] ?? 0,
            ];
        }
        if (empty($similarities)) Response::error('No training areas have demographics yet', 422);

        // K-NN regression: take top-5 by similarity, weighted mean.
        usort($similarities, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        $k = min(5, count($similarities));
        $top = array_slice($similarities, 0, $k);
        $weightSum = 0; $weightedRevSum = 0;
        $revs = [];
        foreach ($top as $t) {
            $w = max(0.001, $t['similarity']);
            $weightSum += $w;
            $weightedRevSum += $w * $t['revenue'];
            $revs[] = $t['revenue'];
        }
        $predicted = $weightSum > 0 ? $weightedRevSum / $weightSum : 0;

        // Confidence band: simple weighted stddev across neighbors.
        $variance = 0;
        foreach ($top as $t) {
            $w = max(0.001, $t['similarity']);
            $variance += $w * ($t['revenue'] - $predicted) ** 2;
        }
        $stddev = $weightSum > 0 ? sqrt($variance / $weightSum) : 0;

        Response::success([
            'candidate'           => ['id' => $candidate['id'], 'name' => $candidate['name']],
            'predicted_revenue'   => round($predicted, 2),
            'confidence_low'      => round($predicted - 1.96 * $stddev, 2),
            'confidence_high'     => round($predicted + 1.96 * $stddev, 2),
            'stddev'              => round($stddev, 2),
            'k_neighbors'         => $top,
            'training_size'       => count($similarities),
        ]);
    }

    /**
     * Strip a demographics_cache into the same 11-dim fingerprint vector we
     * use for AnalogService (subset — no segments/POI/traffic to keep this
     * controller standalone and fast).
     */
    private static function fingerprint(array $a): ?array
    {
        $dc = $a['demographics_cache'] ?? null;
        $parsed = is_string($dc) ? json_decode($dc, true) : $dc;
        if (!$parsed || !is_array($parsed)) return null;
        $pop = (int)($parsed['population']['total'] ?? $parsed['total_population'] ?? 0);
        if ($pop <= 0) return null;
        $areaKm2 = (float)($parsed['meta']['area_sq_km'] ?? 1.0);
        if ($areaKm2 <= 0) $areaKm2 = 0.01;

        $safe = fn($n, $d) => $d > 0 ? $n / $d : 0.0;
        return [
            // 11 normalized dimensions (rough — these are unsupervised but
            // consistent across calls within a single forecast request).
            $pop / max($areaKm2, 1),
            ($parsed['income']['median_household'] ?? 0) / 200000,
            ($parsed['housing']['median_value'] ?? 0) / 1_000_000,
            min(($parsed['employment']['unemployed'] ?? 0) / max($parsed['employment']['labor_force'] ?? 1, 1), 1),
            $safe($parsed['age']['under_18'] ?? 0, $pop),
            $safe($parsed['age']['18_to_34'] ?? 0, $pop),
            $safe($parsed['age']['35_to_54'] ?? 0, $pop),
            $safe($parsed['age']['55_to_64'] ?? 0, $pop),
            $safe($parsed['age']['65_plus'] ?? 0, $pop),
            $safe(($parsed['income']['brackets']['under_25k'] ?? 0)
                  + ($parsed['income']['brackets']['25k_to_50k'] ?? 0), $pop),
            $safe($parsed['income']['brackets']['100k_plus'] ?? 0, $pop),
        ];
    }
}
