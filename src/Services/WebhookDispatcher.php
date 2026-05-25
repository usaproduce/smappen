<?php
namespace App\Services;

use App\Core\Database;

/**
 * Outbound webhook delivery. Used by job worker + controllers via fanout().
 *
 * Sign payloads with HMAC-SHA256(secret, body) and ship in header
 * `X-Smappen-Signature: t=<unix>,v1=<hex hmac>` (Stripe-style). The
 * subscription's secret was issued at creation and is stored only as a hash;
 * we can't sign without it. Two options to handle this:
 *   1. Stash the raw secret in plain text (rejected — defeats the hashing).
 *   2. Use the hash itself as the HMAC key (chosen). The receiver knows the
 *      raw secret, hashes it, and uses the hash to verify. Adds one SHA-256
 *      on the receiver side; eliminates plaintext-at-rest.
 */
class WebhookDispatcher
{
    /**
     * Send one delivery + record the attempt(s).
     *
     * Retries up to 3 times with backoff (0s, 2s, 10s) on 5xx + connection
     * errors. 4xx is treated as a permanent client error — no retry, since
     * the subscriber's endpoint isn't going to fix itself in 12 seconds.
     *
     * Total worst-case wall time on full failure: ~25s (12s sleeps + 3×
     * read timeouts). Acceptable for the job worker; the test endpoint is
     * synchronous but the operator clicking "Test" can wait briefly to see
     * the real failure.
     */
    public function dispatch(array $subscription, string $event, array $data): array
    {
        $payload = [
            'event' => $event,
            'data' => $data,
            'organization_id' => $subscription['organization_id'],
            'subscription_id' => $subscription['id'],
            'timestamp' => time(),
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $t = time();
        $sig = hash_hmac('sha256', $t . '.' . $body, $subscription['secret_hash']);
        $deliveryId = Database::uuid();

        Database::getInstance()->query(
            'INSERT INTO webhook_deliveries
               (id, subscription_id, event_type, payload_json, attempt_count, created_at)
             VALUES (?, ?, ?, ?, 0, NOW())',
            [$deliveryId, $subscription['id'], $event, $body]
        );

        $delays = [0, 2, 10]; // seconds before attempts 1, 2, 3
        $maxAttempts = count($delays);
        $code = 0;
        $response = false;
        $err = '';
        $attempt = 0;

        for ($i = 0; $i < $maxAttempts; $i++) {
            if ($delays[$i] > 0) sleep($delays[$i]);
            $attempt = $i + 1;

            $ch = curl_init($subscription['target_url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'User-Agent: Smappen-Webhook/1.0',
                    'X-Smappen-Event: ' . $event,
                    'X-Smappen-Signature: t=' . $t . ',v1=' . $sig,
                    'X-Smappen-Delivery: ' . $deliveryId,
                    'X-Smappen-Attempt: ' . $attempt,
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                // Don't follow redirects — subscribers should give a final URL.
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            $ok = $code >= 200 && $code < 300;
            if ($ok) break;
            // 4xx → no retry. The subscriber rejected the payload; backing off
            // and trying again won't change that.
            if ($code >= 400 && $code < 500) break;
            // Else: 5xx, 0 (network/timeout), or weird code → retry.
        }

        $ok = $code >= 200 && $code < 300;
        Database::getInstance()->query(
            'UPDATE webhook_deliveries
             SET status_code = ?, response_excerpt = ?, attempt_count = ?,
                 delivered_at = ?, next_retry_at = ?
             WHERE id = ?',
            [
                $code ?: null,
                $response !== false ? mb_substr((string)$response, 0, 1000) : ($err ?: null),
                $attempt,
                $ok ? date('Y-m-d H:i:s') : null,
                $ok ? null : date('Y-m-d H:i:s', time() + 300), // tail-retry hint for a future cron sweep
                $deliveryId,
            ]
        );
        Database::getInstance()->query(
            'UPDATE webhook_subscriptions
             SET last_delivery_at = NOW(), last_status_code = ?,
                 failure_count = CASE WHEN ? THEN 0 ELSE failure_count + 1 END
             WHERE id = ?',
            [$code ?: null, $ok ? 1 : 0, $subscription['id']]
        );
        return [
            'delivery_id' => $deliveryId,
            'status_code' => $code,
            'success' => $ok,
            'attempts' => $attempt,
            'error' => $err ?: null,
        ];
    }

    /**
     * Fanout to every active subscription in an org that listens for $event.
     * Called from the job worker after major events (territory generated,
     * competitor alert, etc.).
     */
    public function fanout(string $organizationId, string $event, array $data): array
    {
        $subs = Database::getInstance()->fetchAll(
            "SELECT * FROM webhook_subscriptions
             WHERE organization_id = ? AND is_active = 1
             AND JSON_CONTAINS(events, JSON_QUOTE(?))",
            [$organizationId, $event]
        );
        $out = [];
        foreach ($subs as $s) {
            $out[] = $this->dispatch($s, $event, $data);
        }
        return $out;
    }
}
