<?php
namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * AI-powered site scoring (#41). Bundles the area's demographics +
 * cannibalization context + segments and asks Claude for a 1–100 score
 * with reasoning. Cached in `cache` for 24h per area so repeat clicks
 * don't burn API budget.
 *
 * Falls back to a deterministic local heuristic when ANTHROPIC_API_KEY is
 * not set, so the endpoint always returns something usable.
 */
class AiScoringController
{
    /**
     * Score every area in a project and rank them. Returns ordered list with
     * the cached score for each area (recomputes any that are missing). The
     * AI calls — when ANTHROPIC_API_KEY is set — are expensive, so we hit the
     * existing 24h per-area cache aggressively.
     */
    public function rank(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        if (!$projectId) Response::error('projectId required');
        $project = Database::getInstance()->fetch(
            'SELECT id FROM projects WHERE id = ? AND organization_id = ?',
            [$projectId, $request->user['organization_id']]
        );
        if (!$project) Response::error('Project not found', 404);

        $areas = Database::getInstance()->fetchAll(
            "SELECT a.*, ST_AsText(a.geometry) AS wkt
             FROM areas a WHERE a.project_id = ?",
            [$projectId]
        );
        if (count($areas) > 50) Response::error('Too many areas to rank in one pass (max 50)');

        // Soft time + memory ceilings — AI calls are slow.
        set_time_limit(180);
        ini_set('memory_limit', '512M');

        $results = [];
        foreach ($areas as $area) {
            $cacheKey = 'ai_score:' . $area['id'] . ':' . substr(md5($area['wkt']), 0, 12);
            $cached = self::cacheGet($cacheKey);
            if ($cached) {
                $results[] = json_decode($cached, true);
                continue;
            }
            $facts = self::gatherFacts($area);
            $res = self::haveAnthropicKey()
                ? self::scoreWithClaude($area, $facts)
                : self::scoreLocal($area, $facts);
            $res['area_id'] = $area['id'];
            $res['area_name'] = $area['name'];
            self::cacheSet($cacheKey, json_encode($res), 86400);
            $results[] = $res;
        }
        // Sort by score desc — best site opportunity first.
        usort($results, fn($a, $b) => ($b['score'] ?? 0) - ($a['score'] ?? 0));
        Response::success([
            'project_id' => $projectId,
            'count' => count($results),
            'rankings' => $results,
        ]);
    }

    public function score(Request $request): void
    {
        $areaId = $request->getParam('id');
        $area = Database::getInstance()->fetch(
            "SELECT a.*, p.organization_id, p.name AS project_name,
                    ST_AsText(a.geometry) AS wkt
             FROM areas a JOIN projects p ON p.id = a.project_id
             WHERE a.id = ?",
            [$areaId]
        );
        if (!$area) Response::error('Area not found', 404);
        if ($area['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        $cacheKey = 'ai_score:' . $areaId . ':' . substr(md5($area['wkt']), 0, 12);
        $cached = self::cacheGet($cacheKey);
        if ($cached) Response::success(json_decode($cached, true));

        $facts = self::gatherFacts($area);
        $result = self::haveAnthropicKey()
            ? self::scoreWithClaude($area, $facts)
            : self::scoreLocal($area, $facts);
        self::cacheSet($cacheKey, json_encode($result), 86400);
        Response::success($result);
    }

    private static function gatherFacts(array $area): array
    {
        $demo = $area['demographics_cache'] ? json_decode($area['demographics_cache'], true) : [];
        // Tract-weighted segment mix
        $segments = [];
        try {
            $segments = Database::getInstance()->fetchAll(
                "SELECT ts.segment_id, ts.segment_name,
                        SUM(d.total_population *
                          CASE WHEN ST_GeometryType(ST_Intersection(ct.geometry, ST_GeomFromText(?, 4326)))
                                    IN ('Polygon','MultiPolygon')
                               THEN ST_Area(ST_Intersection(ct.geometry, ST_GeomFromText(?, 4326)))
                                    / NULLIF(ST_Area(ct.geometry), 0) ELSE 0 END
                        ) AS pop
                 FROM census_tracts ct
                 JOIN census_demographics d ON d.geoid = ct.geoid
                 JOIN tract_segments ts ON ts.geoid = ct.geoid
                 WHERE ST_Intersects(ct.geometry, ST_GeomFromText(?, 4326))
                 GROUP BY ts.segment_id, ts.segment_name
                 ORDER BY pop DESC LIMIT 5",
                [$area['wkt'], $area['wkt'], $area['wkt']]
            );
        } catch (\Throwable $e) {}
        // Competitor density in the area
        $competitors = 0;
        try {
            $row = Database::getInstance()->fetch(
                "SELECT COUNT(*) AS n FROM tracked_places tp
                 JOIN competitor_monitors cm ON cm.id = tp.monitor_id
                 WHERE cm.project_id = ? AND tp.is_gone = 0
                   AND tp.lat IS NOT NULL AND tp.lng IS NOT NULL
                   AND ST_Contains(ST_GeomFromText(?, 4326),
                                   ST_GeomFromText(CONCAT('POINT(', tp.lng, ' ', tp.lat, ')'), 4326))",
                [$area['project_id'], $area['wkt']]
            );
            $competitors = (int)($row['n'] ?? 0);
        } catch (\Throwable $e) {}
        return [
            'population' => (int)($demo['population'] ?? 0),
            'median_income' => $demo['median_household_income'] ?? null,
            'segments' => array_map(fn($s) => [
                'name' => $s['segment_name'],
                'population' => (int) round((float)$s['pop']),
            ], $segments),
            'competitor_count' => $competitors,
            'travel_minutes' => $area['travel_time_minutes'] ?? null,
        ];
    }

    private static function scoreLocal(array $area, array $facts): array
    {
        // Deterministic fallback — components weighted to roughly mirror Claude's reasoning.
        $score = 50;
        $reasons = [];
        $pop = $facts['population'];
        if ($pop >= 100000) { $score += 15; $reasons[] = 'High reach (>=100k people)'; }
        elseif ($pop >= 30000) { $score += 7; $reasons[] = 'Solid reach (' . number_format($pop) . ')'; }
        elseif ($pop < 5000) { $score -= 15; $reasons[] = 'Low reach (<5k people)'; }

        $inc = (int)($facts['median_income'] ?? 0);
        if ($inc >= 100000) { $score += 12; $reasons[] = 'High median income ($' . number_format($inc) . ')'; }
        elseif ($inc >= 70000) { $score += 6; $reasons[] = 'Upper-middle income ($' . number_format($inc) . ')'; }
        elseif ($inc > 0 && $inc < 40000) { $score -= 10; $reasons[] = 'Low median income ($' . number_format($inc) . ')'; }

        $comp = $facts['competitor_count'];
        if ($comp >= 15) { $score -= 18; $reasons[] = 'Saturated market (' . $comp . ' competitors)'; }
        elseif ($comp >= 5) { $score -= 8; $reasons[] = 'Moderate competition (' . $comp . ')'; }
        elseif ($comp <= 1) { $score += 5; $reasons[] = 'Low competition'; }

        $score = max(1, min(100, $score));
        return [
            'area_id' => $area['id'],
            'score' => $score,
            'verdict' => self::verdictFor($score),
            'reasons' => $reasons,
            'facts' => $facts,
            'source' => 'local_heuristic',
            'generated_at' => date('c'),
        ];
    }

    private static function scoreWithClaude(array $area, array $facts): array
    {
        $prompt = "You are a retail site selection analyst. Score this trade area from 1-100 "
                . "(higher = better site) and give a 2-sentence verdict in plain English.\n\n"
                . "AREA: " . ($area['name'] ?? 'unnamed') . "\n"
                . "FACTS: " . json_encode($facts) . "\n\n"
                . "Respond as JSON with keys: score (1-100), verdict (string), reasons (array of <=5 short strings).";
        $body = [
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 400,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . Config::get('ANTHROPIC_API_KEY'),
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT => 25,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            error_log('AI score Anthropic HTTP ' . $code . ': ' . $resp);
            return self::scoreLocal($area, $facts);
        }
        $data = json_decode($resp, true);
        $text = $data['content'][0]['text'] ?? '';
        $parsed = json_decode(preg_replace('/^[^{]*|[^}]*$/', '', $text), true);
        if (!is_array($parsed) || !isset($parsed['score'])) {
            return self::scoreLocal($area, $facts);
        }
        $score = max(1, min(100, (int)$parsed['score']));
        return [
            'area_id' => $area['id'],
            'score' => $score,
            'verdict' => $parsed['verdict'] ?? self::verdictFor($score),
            'reasons' => array_slice((array)($parsed['reasons'] ?? []), 0, 5),
            'facts' => $facts,
            'source' => 'claude_haiku',
            'generated_at' => date('c'),
        ];
    }

    private static function verdictFor(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Strong site — favorable demographics, low competition, good reach.',
            $score >= 60 => 'Promising site with some tradeoffs to weigh.',
            $score >= 40 => 'Marginal site — proceed with caution and a niche strategy.',
            default      => 'Weak site — demographics or competition work against it.',
        };
    }

    private static function haveAnthropicKey(): bool
    {
        return (string) Config::get('ANTHROPIC_API_KEY', '') !== '';
    }

    private static function cacheGet(string $key): ?string
    {
        // The `cache` table uses reserved-word columns `key` / `value`, so
        // queries must backtick-quote them. Earlier draft used `cache_key`
        // which doesn't exist and would 500 every AI-score request.
        try {
            $row = Database::getInstance()->fetch(
                'SELECT `value` FROM cache WHERE `key` = ? AND (expires_at IS NULL OR expires_at > NOW())',
                [$key]
            );
            return $row['value'] ?? null;
        } catch (\Throwable $e) { return null; }
    }
    private static function cacheSet(string $key, string $value, int $ttl): void
    {
        try {
            Database::getInstance()->query(
                'REPLACE INTO cache (`key`, `value`, expires_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
                [$key, $value, $ttl]
            );
        } catch (\Throwable $e) {}
    }
}
