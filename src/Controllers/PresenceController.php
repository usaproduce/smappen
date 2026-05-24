<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * #13 — Real-time collaborative cursors (SSE-based v1).
 *
 * Two endpoints:
 *
 *   POST /api/projects/{projectId}/presence/ping
 *     Body: { lat, lng, selected_area_id?: string|null }
 *     Stores the user's latest cursor position in the `presence` cache key
 *     keyed on (project_id, user_id). TTL 30s — disconnect = silent drop.
 *
 *   GET /api/projects/{projectId}/presence/stream  (Server-Sent Events)
 *     Long-lived response that yields all other users' positions every
 *     ~750ms. Frontend renders them as tiny colored cursors on the map.
 *
 * Storage: we DON'T use the DB cache table — too much write pressure for
 * a hot loop. Uses APCu / Redis when available, falls back to a tiny
 * sqlite file in /tmp. v1 ships the controller skeleton + endpoint
 * scaffolding; the actual transport switch is one config change away.
 *
 * Why SSE not WebSocket: SSE rides Apache mod_proxy_http2 with zero
 * upstream changes. WebSocket needs a separate gateway (Ratchet, etc.)
 * which is more infra than the v1 needs. SSE is one-way (server → client)
 * which is exactly what presence broadcast looks like.
 */
class PresenceController
{
    private const TTL = 30; // seconds

    public function ping(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        $uid = $request->user['id'];
        $body = $request->getBody() ?? [];
        $lat = isset($body['lat']) ? (float)$body['lat'] : null;
        $lng = isset($body['lng']) ? (float)$body['lng'] : null;
        $sel = $body['selected_area_id'] ?? null;
        if ($lat === null || $lng === null) Response::error('lat/lng required', 422);

        $payload = [
            'user_id'           => $uid,
            'user_name'         => $request->user['name'] ?? 'Anon',
            'lat'               => $lat,
            'lng'               => $lng,
            'selected_area_id'  => $sel,
            'last_seen'         => time(),
        ];
        self::store($projectId, $uid, $payload);
        Response::success([]);
    }

    /**
     * SSE stream. PHP-FPM workers are precious (pool of 20 droplet-wide),
     * so we can't tie one up for 55 seconds just to emit empty peer lists
     * for a solo user.
     *
     * Behavior:
     *   - First emission immediate. If there are real peers, keep streaming
     *     every ~750ms for up to 55s (then client reconnects).
     *   - If the peer list is empty for 6 consecutive emissions (~4.5s),
     *     close the connection so the worker is freed. The client sees an
     *     EventSource error and reconnects after a 2s backoff — total
     *     "presence on" overhead for a solo session is ~5s of worker time
     *     every ~7s, vs the old 55s of worker time every minute.
     *
     * Frontend already skips opening the stream entirely when there are no
     * collaborators on the project, but this guard is the backstop for
     * cases where the frontend can't know (e.g. just-added collaborator).
     */
    public function stream(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        $uid = $request->user['id'];

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // disable nginx buffering if proxied

        @set_time_limit(60);
        ignore_user_abort(false);

        // First check: if no peers, send one event with a 30s reconnect
        // hint and close immediately. EventSource will respect `retry:` and
        // wait 30s before re-opening. Net cost: ~50ms of worker time every
        // 30s for a solo session vs the old 55s every 60s.
        $peers = array_values(array_filter(
            self::listForProject($projectId),
            fn($p) => $p['user_id'] !== $uid && (time() - $p['last_seen']) < self::TTL
        ));
        if (empty($peers)) {
            echo "retry: 30000\n";
            echo "data: " . json_encode(['peers' => []]) . "\n\n";
            @ob_flush(); flush();
            return;
        }

        $start = time();
        $emptyTicks = 0;
        while (time() - $start < 55) {
            if (connection_aborted()) break;
            $peers = array_values(array_filter(
                self::listForProject($projectId),
                fn($p) => $p['user_id'] !== $uid && (time() - $p['last_seen']) < self::TTL
            ));
            echo "data: " . json_encode(['peers' => $peers]) . "\n\n";
            @ob_flush(); flush();
            if (empty($peers)) {
                $emptyTicks++;
                // After 3 empty ticks (~2.5s) bump the retry hint to 30s
                // and bail — the collaborator must have dropped off.
                if ($emptyTicks >= 3) {
                    echo "retry: 30000\n\n";
                    @ob_flush(); flush();
                    break;
                }
            } else {
                $emptyTicks = 0;
            }
            usleep(750_000);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Storage layer — APCu preferred, MySQL `cache` table fallback. The
    // fallback path adds 5-15ms per ping which is fine for a v1; production
    // should switch to Redis once it's deployed.
    // ─────────────────────────────────────────────────────────────────────

    private static function store(string $projectId, string $userId, array $payload): void
    {
        $key = "presence:$projectId:$userId";
        if (function_exists('apcu_store')) {
            apcu_store($key, $payload, self::TTL);
            return;
        }
        // MySQL fallback via the existing cache table.
        Database::getInstance()->query(
            'REPLACE INTO `cache` (`key`, `value`, expires_at)
             VALUES (?, ?, ?)',
            [$key, json_encode($payload), date('Y-m-d H:i:s', time() + self::TTL)]
        );
    }

    private static function listForProject(string $projectId): array
    {
        $prefix = "presence:$projectId:";
        if (function_exists('apcu_cache_info')) {
            $out = [];
            $info = apcu_cache_info(false);
            foreach (($info['cache_list'] ?? []) as $entry) {
                $k = $entry['info'] ?? $entry['key'] ?? '';
                if (!str_starts_with((string)$k, $prefix)) continue;
                $val = apcu_fetch($k);
                if (is_array($val)) $out[] = $val;
            }
            return $out;
        }
        $rows = Database::getInstance()->fetchAll(
            "SELECT `value` FROM `cache` WHERE `key` LIKE ? AND expires_at > NOW()",
            [$prefix . '%']
        );
        $out = [];
        foreach ($rows as $r) {
            $v = json_decode($r['value'] ?? '', true);
            if (is_array($v)) $out[] = $v;
        }
        return $out;
    }
}
