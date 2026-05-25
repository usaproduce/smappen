<?php
declare(strict_types=1);

namespace App\PrivateData;

use App\Core\Database;

/**
 * POS OAuth tokens. Mirrors the encryption pattern from `integrations`
 * (CRM tokens): AES-256-CBC with a per-row IV (`token_iv` hex-encoded).
 * Encryption/decryption helpers live in PosService to keep crypto in
 * one place.
 */
class PosIntegrationRepository
{
    /** All columns needed by PosService::sync(). */
    public function findActive(string $restaurantId, string $provider): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT id, organization_id, restaurant_id, provider,
                    access_token_enc, refresh_token_enc, token_iv,
                    expires_at, meta_json, connected_at, last_used_at, last_synced_at
               FROM pos_integrations
              WHERE restaurant_id = ? AND provider = ?',
            [$restaurantId, $provider]
        );
    }

    /**
     * Boolean: has the org ever connected ANY POS? Used as a verification
     * strength signal by `VendorReviewService` (a stronger review tier).
     * Returns just true/false — caller never sees token contents.
     */
    public function organizationHasAnyConnection(string $organizationId): bool
    {
        $row = Database::getInstance()->fetch(
            'SELECT 1 AS one FROM pos_integrations WHERE organization_id = ? LIMIT 1',
            [$organizationId]
        );
        return $row !== null;
    }

    public function listByRestaurant(string $restaurantId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT id, provider, connected_at, last_used_at, last_synced_at, expires_at
               FROM pos_integrations WHERE restaurant_id = ?',
            [$restaurantId]
        );
    }

    /** Upsert by (restaurant_id, provider) — same pattern as CrmController::persistTokens. */
    public function upsert(string $organizationId, string $restaurantId, string $provider, array $data): void
    {
        $existing = Database::getInstance()->fetch(
            'SELECT id FROM pos_integrations WHERE restaurant_id = ? AND provider = ?',
            [$restaurantId, $provider]
        );
        if ($existing) {
            Database::getInstance()->query(
                'UPDATE pos_integrations
                    SET access_token_enc = ?, refresh_token_enc = ?, token_iv = ?,
                        expires_at = ?, meta_json = ?, connected_at = NOW()
                  WHERE id = ?',
                [
                    $data['access_token_enc'], $data['refresh_token_enc'] ?? null, $data['token_iv'],
                    $data['expires_at'] ?? null, json_encode($data['meta'] ?? []),
                    $existing['id'],
                ]
            );
        } else {
            Database::getInstance()->query(
                'INSERT INTO pos_integrations
                    (id, organization_id, restaurant_id, provider, access_token_enc, refresh_token_enc,
                     token_iv, expires_at, meta_json, connected_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    Database::uuid(), $organizationId, $restaurantId, $provider,
                    $data['access_token_enc'], $data['refresh_token_enc'] ?? null, $data['token_iv'],
                    $data['expires_at'] ?? null, json_encode($data['meta'] ?? []),
                ]
            );
        }
    }

    public function touchUsed(string $id): void
    {
        Database::getInstance()->query('UPDATE pos_integrations SET last_used_at = NOW() WHERE id = ?', [$id]);
    }

    public function touchSynced(string $id): void
    {
        Database::getInstance()->query('UPDATE pos_integrations SET last_synced_at = NOW() WHERE id = ?', [$id]);
    }

    public function updateAccessToken(string $id, string $accessTokenEnc, string $tokenIvHex, ?string $expiresAt): void
    {
        Database::getInstance()->query(
            'UPDATE pos_integrations SET access_token_enc = ?, token_iv = ?, expires_at = ?, last_used_at = NOW()
              WHERE id = ?',
            [$accessTokenEnc, $tokenIvHex, $expiresAt, $id]
        );
    }
}
