<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Webhook subscriptions for API customers (#50). Each org can register up to
 * 10 webhook URLs. The job worker fans out events to all subscribers whose
 * `events` array contains the event type. Payloads are HMAC-signed with the
 * subscription's `secret` (issued at creation, never shown again).
 */
class WebhookSubscriptionController
{
    private const MAX_PER_ORG = 10;
    private const VALID_EVENTS = [
        'competitor.alert',
        'territory.generated',
        'import.completed',
        'comment.created',
        'approval.requested',
        'approval.decided',
        'project.shared',
    ];

    public function index(Request $request): void
    {
        $org = $request->user['organization_id'];
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, target_url, events, is_active, last_delivery_at, last_status_code,
                    failure_count, created_at
             FROM webhook_subscriptions WHERE organization_id = ?
             ORDER BY created_at DESC',
            [$org]
        );
        foreach ($rows as &$r) $r['events'] = json_decode($r['events'], true);
        Response::success(['webhooks' => $rows, 'available_events' => self::VALID_EVENTS]);
    }

    public function create(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $url = trim((string)($body['target_url'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL)) Response::error('target_url must be a valid URL');
        if (!str_starts_with($url, 'https://')) Response::error('target_url must be https://');
        $events = array_values((array)($body['events'] ?? []));
        foreach ($events as $e) {
            if (!in_array($e, self::VALID_EVENTS, true)) Response::error("Unknown event: $e");
        }
        if (empty($events)) Response::error('At least one event required');

        $org = $request->user['organization_id'];
        $count = (int) (Database::getInstance()->fetch(
            'SELECT COUNT(*) AS n FROM webhook_subscriptions WHERE organization_id = ?',
            [$org]
        )['n'] ?? 0);
        if ($count >= self::MAX_PER_ORG) {
            Response::error('Max ' . self::MAX_PER_ORG . ' webhooks per organization', 403);
        }

        $secret = 'whsec_' . bin2hex(random_bytes(24));
        $secretHash = hash('sha256', $secret);
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO webhook_subscriptions
               (id, organization_id, created_by, target_url, events, secret_hash, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW())',
            [$id, $org, $request->user['id'], $url, json_encode($events), $secretHash]
        );
        Response::success([
            'id' => $id,
            'target_url' => $url,
            'events' => $events,
            'secret' => $secret,
            'note' => 'Save this secret — it is shown only once. Used to verify HMAC signatures on incoming payloads.',
        ]);
    }

    public function update(Request $request): void
    {
        $id = $request->getParam('id');
        $row = self::loadOwned($request, $id);
        $body = $request->getBody() ?? [];
        $sets = [];
        $params = [];
        if (array_key_exists('target_url', $body)) {
            $url = trim((string) $body['target_url']);
            if (!filter_var($url, FILTER_VALIDATE_URL)) Response::error('Invalid URL');
            if (!str_starts_with($url, 'https://')) Response::error('Must be https://');
            $sets[] = 'target_url = ?'; $params[] = $url;
        }
        if (array_key_exists('events', $body)) {
            $events = array_values((array) $body['events']);
            foreach ($events as $e) if (!in_array($e, self::VALID_EVENTS, true)) Response::error("Unknown event: $e");
            $sets[] = 'events = ?'; $params[] = json_encode($events);
        }
        if (array_key_exists('is_active', $body)) {
            $sets[] = 'is_active = ?'; $params[] = $body['is_active'] ? 1 : 0;
        }
        if (empty($sets)) Response::error('No fields to update');
        $params[] = $id;
        Database::getInstance()->query(
            'UPDATE webhook_subscriptions SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
        Response::success(['id' => $id, 'updated' => true]);
    }

    public function destroy(Request $request): void
    {
        $id = $request->getParam('id');
        self::loadOwned($request, $id);
        Database::getInstance()->query('DELETE FROM webhook_subscriptions WHERE id = ?', [$id]);
        Response::success(['id' => $id, 'deleted' => true]);
    }

    public function test(Request $request): void
    {
        $id = $request->getParam('id');
        $row = self::loadOwned($request, $id);
        // Send a ping. The actual signing happens in WebhookDispatcher.
        require_once dirname(__DIR__) . '/Services/WebhookDispatcher.php';
        $result = (new \App\Services\WebhookDispatcher())->dispatch(
            $row,
            'test.ping',
            ['hello' => 'from smappen', 'timestamp' => date('c')]
        );
        Response::success($result);
    }

    /**
     * Recent delivery attempts for one webhook. Used by the Webhooks settings
     * page to show a log of last 50 events — status code, response excerpt,
     * timestamp — so operators can debug failing receivers.
     */
    public function deliveries(Request $request): void
    {
        $id = $request->getParam('id');
        self::loadOwned($request, $id);
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, event_type, status_code, attempt_count, delivered_at, next_retry_at,
                    SUBSTRING(response_excerpt, 1, 500) AS response_excerpt, created_at
             FROM webhook_deliveries
             WHERE subscription_id = ?
             ORDER BY created_at DESC
             LIMIT 50',
            [$id]
        );
        Response::success(['deliveries' => $rows]);
    }

    private static function loadOwned(Request $request, string $id): array
    {
        $row = Database::getInstance()->fetch(
            'SELECT * FROM webhook_subscriptions WHERE id = ? AND organization_id = ?',
            [$id, $request->user['organization_id']]
        );
        if (!$row) Response::error('Webhook not found', 404);
        return $row;
    }
}
