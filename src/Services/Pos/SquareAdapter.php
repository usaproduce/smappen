<?php
declare(strict_types=1);

namespace App\Services\Pos;

use App\Core\Config;

/**
 * Square POS adapter (sandbox + production).
 *
 * Sandbox URLs hit `connect.squareupsandbox.com`; production hits
 * `connect.squareup.com`. Toggle via env `SQUARE_ENV=sandbox|production`
 * (defaults to sandbox so dev work doesn't accidentally call live Square).
 *
 * Phase 1 scopes: just what the vertical slice needs. Chunk 2 adds
 * ORDERS_READ when we start pulling PMIX.
 *
 * Square refresh tokens last 30 days by default. CrmController's
 * `refreshTokenIfExpired` pattern transposes 1:1 here when we add
 * a refresh path (likely Chunk 2 or 3).
 */
class SquareAdapter implements PosAdapter
{
    private const SCOPES = ['MERCHANT_PROFILE_READ', 'ITEMS_READ', 'ORDERS_READ'];

    public function key(): string { return 'square'; }

    public function buildAuthUrl(string $state, string $redirectUri): string
    {
        $appId = (string) Config::get('SQUARE_APPLICATION_ID', '');
        if ($appId === '') {
            throw new \RuntimeException('SQUARE_APPLICATION_ID not configured');
        }
        return $this->baseUrl() . '/oauth2/authorize'
            . '?client_id=' . urlencode($appId)
            . '&scope=' . urlencode(implode('+', self::SCOPES))
            . '&session=false'
            . '&state=' . urlencode($state)
            . '&redirect_uri=' . urlencode($redirectUri);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $appId     = (string) Config::get('SQUARE_APPLICATION_ID', '');
        $appSecret = (string) Config::get('SQUARE_APPLICATION_SECRET', '');
        if ($appId === '' || $appSecret === '') {
            throw new \RuntimeException('Square credentials missing');
        }
        $resp = $this->postJson($this->baseUrl() . '/oauth2/token', [
            'client_id'     => $appId,
            'client_secret' => $appSecret,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $redirectUri,
        ]);
        if (empty($resp['access_token'])) {
            throw new \RuntimeException('Square token exchange returned no access_token: ' . substr(json_encode($resp), 0, 400));
        }
        $expiresIn = null;
        if (!empty($resp['expires_at'])) {
            $expiresIn = strtotime((string) $resp['expires_at']) - time();
        }
        return [
            'access_token'  => (string) $resp['access_token'],
            'refresh_token' => $resp['refresh_token'] ?? null,
            'expires_in'    => $expiresIn,
            'meta' => [
                'merchant_id' => $resp['merchant_id'] ?? null,
                'scopes'      => self::SCOPES,
                'env'         => $this->envName(),
            ],
        ];
    }

    public function pullMenu(string $accessToken, array $meta): array
    {
        // Catalog/list pages of objects of type ITEM. Each ITEM has
        // item_data.name + variations[]; price lives on the first variation.
        $items = [];
        $cursor = null;
        do {
            $url = $this->baseUrl() . '/v2/catalog/list?types=ITEM' . ($cursor ? '&cursor=' . urlencode($cursor) : '');
            try {
                $resp = $this->getJson($url, $accessToken);
            } catch (\Throwable $e) {
                error_log('[square] pullMenu page failed: ' . $e->getMessage());
                return $items;
            }
            foreach (($resp['objects'] ?? []) as $obj) {
                if (($obj['type'] ?? null) !== 'ITEM') continue;
                $itemId = (string) ($obj['id'] ?? '');
                $name   = (string) ($obj['item_data']['name'] ?? '');
                $cat    = $obj['item_data']['category_id'] ?? null;
                $price  = 0;
                $vars   = $obj['item_data']['variations'] ?? [];
                if (!empty($vars[0]['item_variation_data']['price_money']['amount'])) {
                    $price = (int) $vars[0]['item_variation_data']['price_money']['amount'];
                }
                if ($itemId !== '' && $name !== '') {
                    $items[] = [
                        'pos_item_id' => $itemId,
                        'name'        => $name,
                        'category'    => $cat,
                        'price_cents' => $price,
                    ];
                }
            }
            $cursor = $resp['cursor'] ?? null;
        } while ($cursor !== null);
        return $items;
    }

    public function pullSales(string $accessToken, array $meta, ?string $since): array
    {
        // Square's /v2/orders/search needs at least one location_id. We use
        // /v2/locations to discover them, then page through orders filtered
        // on closed_at >= since (or last 30d if since is null).
        $locations = [];
        try {
            $resp = $this->getJson($this->baseUrl() . '/v2/locations', $accessToken);
            foreach (($resp['locations'] ?? []) as $loc) {
                if (!empty($loc['id'])) $locations[] = (string) $loc['id'];
            }
        } catch (\Throwable $e) {
            error_log('[square] pullSales locations failed: ' . $e->getMessage());
            return [];
        }
        if (!$locations) return [];

        $beginIso = $since ? date('c', strtotime($since)) : date('c', time() - 30 * 86400);

        $lines = [];
        $cursor = null;
        do {
            $payload = [
                'location_ids' => $locations,
                'query' => [
                    'filter' => [
                        'state_filter'  => ['states' => ['COMPLETED']],
                        'date_time_filter' => ['closed_at' => ['start_at' => $beginIso]],
                    ],
                    'sort' => ['sort_field' => 'CLOSED_AT', 'sort_order' => 'ASC'],
                ],
                'limit' => 500,
            ];
            if ($cursor) $payload['cursor'] = $cursor;
            try {
                $resp = $this->postJsonAuthed($this->baseUrl() . '/v2/orders/search', $payload, $accessToken);
            } catch (\Throwable $e) {
                error_log('[square] pullSales orders page failed: ' . $e->getMessage());
                return $lines;
            }
            foreach (($resp['orders'] ?? []) as $order) {
                $orderId = (string) ($order['id'] ?? '');
                $closedAt = $order['closed_at'] ?? $order['created_at'] ?? null;
                if (!$orderId || !$closedAt) continue;
                $lineIdx = 0;
                foreach (($order['line_items'] ?? []) as $li) {
                    $catalogId = $li['catalog_object_id'] ?? null;
                    if (!$catalogId) { $lineIdx++; continue; }
                    $qty = (int) ($li['quantity'] ?? 1);
                    $gross = (int) ($li['gross_sales_money']['amount'] ?? ($li['total_money']['amount'] ?? 0));
                    $net   = isset($li['variation_total_price_money']['amount']) ? (int) $li['variation_total_price_money']['amount'] : null;
                    $lines[] = [
                        'pos_order_id' => $orderId,
                        'pos_line_uid' => $orderId . ':' . $lineIdx,
                        'pos_item_id'  => (string) $catalogId,
                        'qty'          => max(1, $qty),
                        'gross_cents'  => $gross,
                        'net_cents'    => $net,
                        'sold_at'      => date('Y-m-d H:i:s', strtotime((string) $closedAt)),
                    ];
                    $lineIdx++;
                }
            }
            $cursor = $resp['cursor'] ?? null;
        } while ($cursor !== null);

        return $lines;
    }

    private function postJsonAuthed(string $url, array $body, string $accessToken): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Square-Version: 2024-09-19',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new \RuntimeException('Square HTTP error: ' . $err);
        if ($code >= 400) throw new \RuntimeException("Square HTTP $code: " . substr((string) $resp, 0, 400));
        $parsed = json_decode((string) $resp, true);
        return is_array($parsed) ? $parsed : [];
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    private function envName(): string
    {
        return strtolower((string) Config::get('SQUARE_ENV', 'sandbox')) === 'production' ? 'production' : 'sandbox';
    }

    private function baseUrl(): string
    {
        return $this->envName() === 'production'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
    }

    private function postJson(string $url, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Square-Version: 2024-09-19'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new \RuntimeException('Square HTTP error: ' . $err);
        if ($code >= 400) throw new \RuntimeException("Square HTTP $code: " . substr((string) $resp, 0, 400));
        $parsed = json_decode((string) $resp, true);
        return is_array($parsed) ? $parsed : [];
    }

    private function getJson(string $url, string $accessToken): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Square-Version: 2024-09-19',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new \RuntimeException('Square HTTP error: ' . $err);
        if ($code >= 400) throw new \RuntimeException("Square HTTP $code: " . substr((string) $resp, 0, 400));
        $parsed = json_decode((string) $resp, true);
        return is_array($parsed) ? $parsed : [];
    }
}
