<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\PrivateData\PosIntegrationRepository;
use App\PrivateData\MenuItemRepository;
use App\PrivateData\PosSalesRepository;
use App\Services\Pos\PosAdapter;
use App\Services\Pos\SquareAdapter;

/**
 * POS coordinator. Owns:
 *   - token crypto (AES-256-CBC + per-row IV, same scheme as CrmController)
 *   - signed OAuth state (cloned from CrmController::signOAuthState)
 *   - sync orchestration: route to adapter → pull → upsert via PrivateData repos
 *
 * Adapters (`PosAdapter` implementations) are pure HTTP — they don't touch
 * the database. This keeps the data-wall boundary clean and makes adapters
 * unit-testable without a DB.
 */
class PosService
{
    /** @var array<string, PosAdapter> */
    private array $adapters;

    public function __construct(?array $adapters = null)
    {
        $this->adapters = $adapters ?? [
            'square' => new SquareAdapter(),
        ];
    }

    public function adapter(string $provider): PosAdapter
    {
        $a = $this->adapters[$provider] ?? null;
        if (!$a) throw new \RuntimeException("Unknown POS provider: $provider");
        return $a;
    }

    public function supportedProviders(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * Begin OAuth: sign state carrying (restaurant_id, organization_id),
     * return the provider's authorize URL.
     */
    public function beginOAuth(string $provider, string $restaurantId, string $organizationId): string
    {
        $state = self::signOAuthState($restaurantId, $organizationId, $provider);
        return $this->adapter($provider)->buildAuthUrl($state, self::callbackUrl($provider));
    }

    /**
     * Handle the OAuth callback: validate state, exchange code, persist.
     * Returns [restaurantId, organizationId] for the controller to use in
     * its redirect.
     */
    public function completeOAuth(string $provider, string $code, string $state, PosIntegrationRepository $repo): array
    {
        [$restaurantId, $organizationId] = self::validateOAuthState($state, $provider);
        $bundle = $this->adapter($provider)->exchangeCode($code, self::callbackUrl($provider));

        $key = self::appKey();
        $iv  = random_bytes(16);
        $accessEnc  = self::encrypt($bundle['access_token'], $key, $iv);
        $refreshEnc = !empty($bundle['refresh_token']) ? self::encrypt((string) $bundle['refresh_token'], $key, $iv) : null;
        $expiresAt  = !empty($bundle['expires_in'])
            ? date('Y-m-d H:i:s', time() + (int) $bundle['expires_in'])
            : null;

        $repo->upsert($organizationId, $restaurantId, $provider, [
            'access_token_enc'  => $accessEnc,
            'refresh_token_enc' => $refreshEnc,
            'token_iv'          => bin2hex($iv),
            'expires_at'        => $expiresAt,
            'meta'              => $bundle['meta'] ?? [],
        ]);
        return [$restaurantId, $organizationId];
    }

    /**
     * Sync entry-point — called from the controller (enqueue path) and from
     * the job worker (case 'pos.sync'). Pulls the merchant's menu and
     * upserts via MenuItemRepository.
     */
    public function sync(string $restaurantId, string $provider, PosIntegrationRepository $integrations, MenuItemRepository $items, ?PosSalesRepository $sales = null): array
    {
        $row = $integrations->findActive($restaurantId, $provider);
        if (!$row) throw new \RuntimeException("No $provider connection for restaurant $restaurantId");

        // Sample integrations carry pre-seeded menu items + pos_sales — never
        // call the provider. The "synced at" stamp is touched so the UI's
        // SyncStatus tile still renders fresh, and the menu/sales counts
        // reported reflect what's already on disk.
        if (!empty($row['is_sample']) && (int) $row['is_sample'] === 1) {
            $integrations->touchSynced($row['id']);
            $itemsCount = (int) (Database::getInstance()->fetch(
                'SELECT COUNT(*) AS n FROM menu_items WHERE restaurant_id = ? AND is_active = 1',
                [$restaurantId]
            )['n'] ?? 0);
            $salesCount = (int) (Database::getInstance()->fetch(
                'SELECT COUNT(*) AS n FROM pos_sales WHERE restaurant_id = ? AND sold_at > DATE_SUB(NOW(), INTERVAL 7 DAY)',
                [$restaurantId]
            )['n'] ?? 0);
            return [
                'provider'     => $provider,
                'pulled_count' => $itemsCount,
                'sales_pulled' => $salesCount,
                'is_sample'    => true,
            ];
        }

        $accessToken = self::decryptToken($row['access_token_enc'], $row['token_iv']);
        $meta = json_decode($row['meta_json'] ?? '{}', true) ?: [];
        $adapter = $this->adapter($provider);

        // 1. Menu pull + upsert.
        $pulled = $adapter->pullMenu($accessToken, $meta);
        foreach ($pulled as $it) {
            $items->upsertFromPos($row['organization_id'], $restaurantId, $provider, $it);
        }

        // 2. Sales pull — incremental from max(sold_at). Skip if PosSalesRepository
        //    isn't provided (Chunk 1 vertical slice didn't have it).
        $salesPulled = 0;
        if ($sales !== null) {
            $since = $sales->maxSoldAt($restaurantId, $provider);
            $lines = $adapter->pullSales($accessToken, $meta, $since);
            $idMap = $items->posIdMap($restaurantId, $provider);
            foreach ($lines as $line) {
                $menuItemId = $idMap[$line['pos_item_id']] ?? null;
                $line['menu_item_id'] = $menuItemId;
                $sales->upsertLine($row['organization_id'], $restaurantId, $provider, $line);
                $salesPulled++;
            }
        }

        $integrations->touchSynced($row['id']);

        return [
            'provider'      => $provider,
            'pulled_count'  => count($pulled),
            'sales_pulled'  => $salesPulled,
        ];
    }

    // ─────────────────────────────── crypto ───────────────────────────────
    // Pattern cloned from CrmController so we don't have two different
    // OAuth-token-at-rest schemes. If CrmController's scheme ever changes,
    // change this too.

    private static function appKey(): string
    {
        $raw = Config::get('APP_KEY');
        if (!$raw) {
            throw new \RuntimeException('APP_KEY env not set — required to encrypt POS tokens');
        }
        $decoded = base64_decode((string) $raw, true);
        return ($decoded !== false && strlen($decoded) === 32)
            ? $decoded
            : substr(hash('sha256', (string) $raw, true), 0, 32);
    }

    private static function encrypt(string $plain, string $key, string $iv): string
    {
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) throw new \RuntimeException('POS token encryption failed');
        return base64_encode($cipher);
    }

    public static function decryptToken(string $cipherB64, string $ivHex): string
    {
        $key = self::appKey();
        $iv  = hex2bin($ivHex);
        if ($iv === false || strlen($iv) !== 16) throw new \RuntimeException('Bad IV');
        $plain = openssl_decrypt(base64_decode($cipherB64), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) throw new \RuntimeException('POS token decryption failed');
        return $plain;
    }

    // ──────────────────────────── signed state ────────────────────────────
    // Same shape as CrmController::signOAuthState but carries the
    // restaurant_id as well (POS connections are restaurant-scoped, not
    // org-scoped). Payload: nonce|restaurantId|orgId|provider|expires|hmac.

    public static function signOAuthState(string $restaurantId, string $organizationId, string $provider): string
    {
        $nonce = bin2hex(random_bytes(16));
        $expires = time() + 600;
        $payload = $nonce . '|' . $restaurantId . '|' . $organizationId . '|' . $provider . '|' . $expires;
        $sig = hash_hmac('sha256', $payload, self::appKey());
        return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
    }

    /** @return array{0:string,1:string} [restaurantId, organizationId] */
    public static function validateOAuthState(string $state, string $expectedProvider): array
    {
        $decoded = base64_decode(strtr($state, '-_', '+/'), true);
        if ($decoded === false) throw new \RuntimeException('Invalid OAuth state');
        $parts = explode('|', $decoded);
        if (count($parts) !== 6) throw new \RuntimeException('Invalid OAuth state shape');
        [$nonce, $restaurantId, $orgId, $provider, $expires, $sig] = $parts;
        if (!hash_equals($expectedProvider, $provider)) throw new \RuntimeException('OAuth state provider mismatch');
        $expected = hash_hmac('sha256', $nonce . '|' . $restaurantId . '|' . $orgId . '|' . $provider . '|' . $expires, self::appKey());
        if (!hash_equals($expected, $sig)) throw new \RuntimeException('OAuth state signature mismatch');
        if (time() > (int) $expires) throw new \RuntimeException('OAuth state expired');
        return [$restaurantId, $orgId];
    }

    private static function callbackUrl(string $provider): string
    {
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'carafe.mygreendock.com';
        return $scheme . '://' . $host . '/api/integrations/pos/' . $provider . '/callback';
    }
}
