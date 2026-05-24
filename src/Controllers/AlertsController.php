<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Generic alert rules. Expands beyond the existing CompetitorController by
 * covering: 'competitor_new', 'demographics_changed', 'ai_score_drop',
 * 'metric_threshold' (e.g. POI count, foot-traffic, traffic minutes).
 *
 * Routes:
 *   GET    /api/alerts                   list alerts for the org
 *   POST   /api/alerts                   create
 *   PATCH  /api/alerts/{id}              update (toggle active, edit config)
 *   DELETE /api/alerts/{id}              remove
 *   POST   /api/alerts/{id}/test         fire a synthetic delivery (sandbox)
 *   GET    /api/alerts/digest/recent     last 30d of deliveries — used by
 *                                        the weekly email digest cron.
 *
 * The actual scanning/firing is done by bin/run-alert-scans.php (operator
 * cron — daily). This controller only manages rules + lists deliveries.
 */
class AlertsController
{
    private const KINDS = ['competitor_new', 'demographics_changed', 'ai_score_drop', 'metric_threshold'];

    public function index(Request $request): void
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, area_id, kind, config_json, active, last_fired_at, fire_count, created_at
               FROM alerts WHERE organization_id = ? ORDER BY created_at DESC',
            [$request->user['organization_id']]
        );
        foreach ($rows as &$r) {
            $r['config'] = json_decode($r['config_json'] ?? '{}', true);
            unset($r['config_json']);
        }
        Response::success(['alerts' => $rows]);
    }

    public function create(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $kind = $b['kind'] ?? '';
        if (!in_array($kind, self::KINDS, true)) Response::error('Invalid kind', 422);
        $areaId = $b['area_id'] ?? null;
        $config = $b['config'] ?? [];
        if (!is_array($config)) Response::error('config must be an object', 422);

        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO alerts (id, organization_id, user_id, area_id, kind, config_json, active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW())',
            [$id, $request->user['organization_id'], $request->user['id'], $areaId, $kind, json_encode($config)]
        );
        Response::success(['id' => $id], 'Alert created', 201);
    }

    public function update(Request $request): void
    {
        $id = $request->getParam('id');
        $row = $this->loadOwned($request, $id);
        $b = $request->getBody() ?? [];
        $fields = []; $params = [];
        if (array_key_exists('active', $b))  { $fields[] = 'active = ?';      $params[] = $b['active'] ? 1 : 0; }
        if (array_key_exists('config', $b))  { $fields[] = 'config_json = ?'; $params[] = json_encode($b['config']); }
        if (!$fields) Response::error('Nothing to update', 422);
        $params[] = $id;
        Database::getInstance()->query('UPDATE alerts SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
        Response::success([]);
    }

    public function destroy(Request $request): void
    {
        $id = $request->getParam('id');
        $this->loadOwned($request, $id);
        Database::getInstance()->query('DELETE FROM alerts WHERE id = ?', [$id]);
        Response::success([]);
    }

    public function test(Request $request): void
    {
        $id = $request->getParam('id');
        $alert = $this->loadOwned($request, $id);
        $payload = [
            'sample' => true,
            'kind' => $alert['kind'],
            'message' => self::sampleMessage($alert['kind']),
        ];
        Database::getInstance()->query(
            'INSERT INTO alert_deliveries (alert_id, fired_at, payload_json, email_sent, slack_sent)
             VALUES (?, NOW(), ?, 0, 0)',
            [$id, json_encode($payload)]
        );
        Response::success(['payload' => $payload]);
    }

    /**
     * Recent deliveries for the weekly digest. Operator cron pulls this
     * (no auth — would be invoked from inside the server) and emails each
     * org's owner a summary. Currently auth-protected; flip to a webhook
     * secret if you switch to a centralized worker later.
     */
    public function recentDigest(Request $request): void
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT d.id, d.alert_id, d.fired_at, d.payload_json, a.kind, a.user_id, a.area_id
               FROM alert_deliveries d
               JOIN alerts a ON a.id = d.alert_id
              WHERE a.organization_id = ? AND d.fired_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
              ORDER BY d.fired_at DESC LIMIT 200',
            [$request->user['organization_id']]
        );
        foreach ($rows as &$r) {
            $r['payload'] = json_decode($r['payload_json'] ?? '{}', true);
            unset($r['payload_json']);
        }
        Response::success(['deliveries' => $rows]);
    }

    private function loadOwned(Request $r, string $id): array
    {
        $row = Database::getInstance()->fetch(
            'SELECT * FROM alerts WHERE id = ? AND organization_id = ?',
            [$id, $r->user['organization_id']]
        );
        if (!$row) Response::error('Alert not found', 404);
        return $row;
    }

    private static function sampleMessage(string $kind): string
    {
        return match ($kind) {
            'competitor_new'        => 'A new competitor opened in this area.',
            'demographics_changed'  => 'Median income shifted >5% in the last vintage.',
            'ai_score_drop'         => 'AI score dropped 10+ points compared to last scan.',
            'metric_threshold'      => 'A metric crossed your configured threshold.',
            default                 => 'Test alert.',
        };
    }
}
