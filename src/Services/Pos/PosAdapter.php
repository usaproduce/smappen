<?php
declare(strict_types=1);

namespace App\Services\Pos;

/**
 * Per-POS-provider adapter. The base PosService routes by provider key.
 *
 * Adapters MUST:
 *   - never write to Carafe tables directly (PosService owns persistence)
 *   - never throw on non-fatal pull failures — return [] and log instead,
 *     mirroring GoogleMapsService's silent-skip-with-error_log pattern
 *   - set CURLOPT_CONNECTTIMEOUT => 3 on every external call (base learned
 *     this the hard way; see AUDIT.md bug-fix history)
 */
interface PosAdapter
{
    /** Unique provider key: 'square', 'toast', 'clover'. */
    public function key(): string;

    /** OAuth authorize URL for `redirect_uri` carrying the signed state. */
    public function buildAuthUrl(string $state, string $redirectUri): string;

    /**
     * Exchange an authorization code for tokens.
     * Returns: ['access_token', 'refresh_token'?, 'expires_in'?, 'meta' => [...]].
     * Throws \RuntimeException on hard failure.
     */
    public function exchangeCode(string $code, string $redirectUri): array;

    /**
     * Pull the merchant's menu items.
     * Returns: array of ['pos_item_id', 'name', 'category', 'price_cents'].
     * Returns [] on transient failure (do not throw).
     */
    public function pullMenu(string $accessToken, array $meta): array;

    /**
     * Pull sale lines since `since` (ISO-8601 UTC). Returns: array of
     *   ['pos_order_id', 'pos_line_uid', 'pos_item_id', 'qty',
     *    'gross_cents', 'net_cents'?, 'sold_at', 'raw'?]
     * `pos_item_id` is the catalog id — PosService maps it to menu_items.id
     * via the upsert dedupe key. Returns [] on failure (logs).
     */
    public function pullSales(string $accessToken, array $meta, ?string $since): array;
}
