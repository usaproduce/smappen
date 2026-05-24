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
    /** Send one delivery + record the attempt. */
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
             VALUES (?, ?, ?, ?, 1, NOW())',
            [$deliveryId, $subscription['id'], $event, $body]
        );

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
        Database::getInstance()->query(
            'UPDATE webhook_deliveries
             SET status_code = ?, response_excerpt = ?, delivered_at = ?, next_retry_at = ?
             WHERE id = ?',
            [
                $code ?: null,
                $response !== false ? mb_substr((string)$response, 0, 1000) : ($err ?: null),
                $ok ? date('Y-m-d H:i:s') : null,
                $ok ? null : date('Y-m-d H:i:s', time() + 300), // retry in 5 minutes
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
