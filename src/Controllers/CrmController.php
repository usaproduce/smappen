<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Salesforce + HubSpot OAuth + push. Tokens encrypted at rest with APP_KEY.
 *
 * Routes (auth required for all):
 *   POST /api/integrations/salesforce/connect   begin OAuth → returns auth_url
 *   GET  /api/integrations/salesforce/callback  OAuth callback → stores tokens, redirects to /settings/integrations
 *   POST /api/integrations/salesforce/push      push area demographics to Account records
 *
 *   POST /api/integrations/hubspot/connect
 *   GET  /api/integrations/hubspot/callback
 *   POST /api/integrations/hubspot/push
 *
 * Required env per provider — when missing, /connect returns 503 so the UI
 * shows "Contact your admin to enable":
 *   SALESFORCE_CLIENT_ID, SALESFORCE_CLIENT_SECRET, SALESFORCE_LOGIN_URL
 *     (defaults to login.salesforce.com — set to test.salesforce.com for sandboxes)
 *   HUBSPOT_CLIENT_ID, HUBSPOT_CLIENT_SECRET
 *
 * APP_KEY (base64-encoded 32 bytes) is shared with the JWT signing key; if
 * absent the controller refuses to persist tokens rather than store plaintext.
 */
class CrmController
{
    private const SF_SCOPES = 'api refresh_token offline_access';
    private const HS_SCOPES = 'crm.objects.companies.read crm.objects.companies.write';

    public function connectSalesforce(Request $request): void
    {
        $clientId = Config::get('SALESFORCE_CLIENT_ID');
        if (!$clientId) Response::error('Salesforce integration not configured on this server', 503);
        $state = self::signOAuthState($request->user['organization_id']);
        $loginUrl = rtrim((string) Config::get('SALESFORCE_LOGIN_URL', 'https://login.salesforce.com'), '/');
        $authUrl = $loginUrl . '/services/oauth2/authorize'
            . '?response_type=code'
            . '&client_id=' . urlencode($clientId)
            . '&redirect_uri=' . urlencode(self::baseUrl() . '/api/integrations/salesforce/callback')
            . '&scope=' . urlencode(self::SF_SCOPES)
            . '&state=' . urlencode($state);
        Response::success(['auth_url' => $authUrl]);
    }

    public function callbackSalesforce(Request $request): void
    {
        [$code, $orgId] = self::validateCallback();
        $clientId     = Config::get('SALESFORCE_CLIENT_ID');
        $clientSecret = Config::get('SALESFORCE_CLIENT_SECRET');
        $loginUrl = rtrim((string) Config::get('SALESFORCE_LOGIN_URL', 'https://login.salesforce.com'), '/');
        if (!$clientId || !$clientSecret) Response::error('Salesforce integration misconfigured', 503);

        $token = self::httpForm($loginUrl . '/services/oauth2/token', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => self::baseUrl() . '/api/integrations/salesforce/callback',
        ]);
        if (empty($token['access_token'])) Response::error('Salesforce did not return an access token', 502);
        self::persistTokens($orgId, 'salesforce', $token, [
            'instance_url' => $token['instance_url'] ?? null,
            'scope'        => $token['scope'] ?? null,
            'id'           => $token['id'] ?? null,
        ]);
        self::redirect('/settings/integrations?connected=salesforce');
    }

    public function pushSalesforce(Request $request): void
    {
        $row = self::loadIntegration($request->user['organization_id'], 'salesforce');
        if (!$row) Response::error('Connect Salesforce first', 400);
        try {
            $row = self::refreshSalesforceTokenIfExpired($row);
        } catch (\RuntimeException $e) {
            Response::error('Salesforce session expired. Please reconnect in Settings → Integrations.', 401);
        }
        $meta = json_decode($row['meta_json'] ?? '{}', true) ?: [];
        $instanceUrl = $meta['instance_url'] ?? null;
        if (!$instanceUrl) Response::error('Missing Salesforce instance URL', 500);

        $b = $request->getBody() ?? [];
        $items = $b['areas'] ?? [];
        $accessToken = self::decryptToken($row['access_token_enc'], $row['token_iv']);
        $pushed = 0; $errors = [];
        foreach ($items as $it) {
            $accountId = $it['account_id'] ?? null;
            $payload   = $it['fields'] ?? [];
            if (!$accountId || !is_array($payload) || !$payload) continue;
            try {
                self::httpJson(
                    'PATCH',
                    rtrim($instanceUrl, '/') . '/services/data/v60.0/sobjects/Account/' . urlencode((string) $accountId),
                    $payload,
                    ['Authorization: Bearer ' . $accessToken]
                );
                $pushed++;
            } catch (\Throwable $e) {
                $errors[] = ['account_id' => $accountId, 'error' => $e->getMessage()];
            }
        }
        self::touchLastUsed($row['id']);
        Response::success(['pushed' => $pushed, 'errors' => $errors]);
    }

    public function connectHubspot(Request $request): void
    {
        $clientId = Config::get('HUBSPOT_CLIENT_ID');
        if (!$clientId) Response::error('HubSpot integration not configured on this server', 503);
        $state = self::signOAuthState($request->user['organization_id']);
        $authUrl = 'https://app.hubspot.com/oauth/authorize'
            . '?client_id=' . urlencode($clientId)
            . '&redirect_uri=' . urlencode(self::baseUrl() . '/api/integrations/hubspot/callback')
            . '&scope=' . urlencode(self::HS_SCOPES)
            . '&state=' . urlencode($state);
        Response::success(['auth_url' => $authUrl]);
    }

    public function callbackHubspot(Request $request): void
    {
        [$code, $orgId] = self::validateCallback();
        $clientId     = Config::get('HUBSPOT_CLIENT_ID');
        $clientSecret = Config::get('HUBSPOT_CLIENT_SECRET');
        if (!$clientId || !$clientSecret) Response::error('HubSpot integration misconfigured', 503);

        $token = self::httpForm('https://api.hubapi.com/oauth/v1/token', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => self::baseUrl() . '/api/integrations/hubspot/callback',
        ]);
        if (empty($token['access_token'])) Response::error('HubSpot did not return an access token', 502);

        // HubSpot's token introspection returns the hub_id, which we need to
        // distinguish portals when an org connects multiple.
        $info = self::httpJson('GET', 'https://api.hubapi.com/oauth/v1/access-tokens/' . urlencode($token['access_token']), null, []);
        self::persistTokens($orgId, 'hubspot', $token, [
            'hub_id'      => $info['hub_id'] ?? null,
            'hub_domain'  => $info['hub_domain'] ?? null,
            'user'        => $info['user'] ?? null,
            'scopes'      => $info['scopes'] ?? null,
        ]);
        self::redirect('/settings/integrations?connected=hubspot');
    }

    public function pushHubspot(Request $request): void
    {
        $row = self::loadIntegration($request->user['organization_id'], 'hubspot');
        if (!$row) Response::error('Connect HubSpot first', 400);
        try {
            $row = self::refreshHubspotTokenIfExpired($row);
        } catch (\RuntimeException $e) {
            Response::error('HubSpot session expired. Please reconnect in Settings → Integrations.', 401);
        }
        $b = $request->getBody() ?? [];
        $items = $b['areas'] ?? [];
        $accessToken = self::decryptToken($row['access_token_enc'], $row['token_iv']);
        $pushed = 0; $errors = [];
        foreach ($items as $it) {
            $companyId = $it['company_id'] ?? null;
            $properties = $it['properties'] ?? [];
            if (!$companyId || !$properties) continue;
            try {
                self::httpJson(
                    'PATCH',
                    'https://api.hubapi.com/crm/v3/objects/companies/' . urlencode((string) $companyId),
                    ['properties' => $properties],
                    ['Authorization: Bearer ' . $accessToken]
                );
                $pushed++;
            } catch (\Throwable $e) {
                $errors[] = ['company_id' => $companyId, 'error' => $e->getMessage()];
            }
        }
        self::touchLastUsed($row['id']);
        Response::success(['pushed' => $pushed, 'errors' => $errors]);
    }

    // ---------- helpers ----------

    /**
     * Sign an OAuth state token carrying the originating org_id + expiry.
     *
     * Stateless verification — no PHP session storage, so a load-balanced
     * deployment where the callback hits a different FPM host still works.
     * Format: base64url(nonce|orgId|expires|hex_hmac).
     */
    private static function signOAuthState(string $orgId): string
    {
        $nonce = bin2hex(random_bytes(16));
        $expires = time() + 600; // 10 minutes
        $payload = $nonce . '|' . $orgId . '|' . $expires;
        $sig = hash_hmac('sha256', $payload, self::appKey());
        return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
    }

    /** @return array{0:string,1:string} [code, orgId] */
    private static function validateCallback(): array
    {
        $code = $_GET['code'] ?? null;
        $state = $_GET['state'] ?? null;
        if (!$code || !$state) {
            Response::error('Invalid OAuth state — please retry from /settings/integrations', 400);
        }
        $decoded = base64_decode(strtr((string) $state, '-_', '+/'), true);
        if ($decoded === false) {
            Response::error('Invalid OAuth state — please retry from /settings/integrations', 400);
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 4) {
            Response::error('Invalid OAuth state — please retry from /settings/integrations', 400);
        }
        [$nonce, $orgId, $expires, $sig] = $parts;
        $expected = hash_hmac('sha256', $nonce . '|' . $orgId . '|' . $expires, self::appKey());
        if (!hash_equals($expected, $sig)) {
            Response::error('Invalid OAuth state — please retry from /settings/integrations', 400);
        }
        if (time() > (int) $expires) {
            Response::error('OAuth flow took too long — please retry from /settings/integrations', 400);
        }
        return [(string) $code, $orgId];
    }

    /**
     * Persist an OAuth token bundle (upsert by org+provider). Encrypts the
     * access + refresh tokens with AES-256-CBC + a fresh IV per row.
     */
    private static function persistTokens(string $orgId, string $provider, array $token, array $meta): void
    {
        $key = self::appKey();
        $iv = random_bytes(16);
        $access  = self::encrypt($token['access_token'], $key, $iv);
        $refresh = !empty($token['refresh_token']) ? self::encrypt($token['refresh_token'], $key, $iv) : null;
        $expiresAt = null;
        if (!empty($token['expires_in'])) {
            $expiresAt = (new \DateTime('@' . (time() + (int) $token['expires_in'])))->format('Y-m-d H:i:s');
        }
        $db = Database::getInstance();
        $existing = $db->fetch('SELECT id FROM integrations WHERE organization_id = ? AND provider = ?',
            [$orgId, $provider]);
        if ($existing) {
            $db->query(
                'UPDATE integrations SET access_token_enc = ?, refresh_token_enc = ?, token_iv = ?,
                                          expires_at = ?, meta_json = ?, connected_at = NOW()
                  WHERE id = ?',
                [$access, $refresh, bin2hex($iv), $expiresAt, json_encode($meta), $existing['id']]
            );
        } else {
            $db->query(
                'INSERT INTO integrations (id, organization_id, provider, access_token_enc,
                                            refresh_token_enc, token_iv, expires_at, meta_json, connected_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [Database::uuid(), $orgId, $provider, $access, $refresh,
                 bin2hex($iv), $expiresAt, json_encode($meta)]
            );
        }
    }

    private static function loadIntegration(string $orgId, string $provider): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT id, access_token_enc, refresh_token_enc, token_iv, expires_at, meta_json
               FROM integrations WHERE organization_id = ? AND provider = ?',
            [$orgId, $provider]
        );
    }

    private static function touchLastUsed(string $id): void
    {
        Database::getInstance()->query('UPDATE integrations SET last_used_at = NOW() WHERE id = ?', [$id]);
    }

    private static function decryptToken(string $cipherB64, string $ivHex): string
    {
        $key = self::appKey();
        $iv = hex2bin($ivHex);
        if ($iv === false || strlen($iv) !== 16) throw new \RuntimeException('Bad IV');
        $plain = openssl_decrypt(base64_decode($cipherB64), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) throw new \RuntimeException('Token decryption failed');
        return $plain;
    }

    private static function encrypt(string $plain, string $key, string $iv): string
    {
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) throw new \RuntimeException('Token encryption failed');
        return base64_encode($cipher);
    }

    /**
     * Refresh the Salesforce access token if it's within 5 minutes of expiry.
     * Returns the (possibly mutated) integration row with fresh ciphertext +
     * IV in place. Salesforce returns a new access_token but keeps the same
     * refresh_token; instance_url can shift between sandboxes/orgs so we
     * persist it back into meta_json when present.
     */
    private static function refreshSalesforceTokenIfExpired(array $row): array
    {
        if (empty($row['expires_at']) || strtotime((string) $row['expires_at']) > time() + 300) {
            return $row;
        }
        if (empty($row['refresh_token_enc'])) {
            throw new \RuntimeException('No refresh token on file');
        }
        $refreshToken = self::decryptToken($row['refresh_token_enc'], $row['token_iv']);
        $clientId     = Config::get('SALESFORCE_CLIENT_ID');
        $clientSecret = Config::get('SALESFORCE_CLIENT_SECRET');
        $loginUrl = rtrim((string) Config::get('SALESFORCE_LOGIN_URL', 'https://login.salesforce.com'), '/');
        if (!$clientId || !$clientSecret) throw new \RuntimeException('Salesforce credentials missing');

        $token = self::httpForm($loginUrl . '/services/oauth2/token', [
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);
        if (empty($token['access_token'])) throw new \RuntimeException('Salesforce refresh returned no access_token');

        // Salesforce omits expires_in on refresh; access tokens default to ~2h
        // (session policy can shorten). 3600s is the documented minimum we
        // can safely assume across editions.
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);
        $key = self::appKey();
        $iv  = random_bytes(16);
        $access = self::encrypt($token['access_token'], $key, $iv);

        $meta = json_decode($row['meta_json'] ?? '{}', true) ?: [];
        if (!empty($token['instance_url'])) $meta['instance_url'] = $token['instance_url'];

        Database::getInstance()->query(
            'UPDATE integrations SET access_token_enc = ?, token_iv = ?, expires_at = ?, meta_json = ?, last_used_at = NOW()
              WHERE id = ?',
            [$access, bin2hex($iv), $expiresAt, json_encode($meta), $row['id']]
        );

        $row['access_token_enc'] = $access;
        $row['token_iv']         = bin2hex($iv);
        $row['expires_at']       = $expiresAt;
        $row['meta_json']        = json_encode($meta);
        return $row;
    }

    /**
     * Refresh the HubSpot access token if it's within 5 minutes of expiry.
     * HubSpot rotates BOTH access_token and refresh_token on each refresh —
     * if we don't persist the new refresh_token we'll be unable to refresh
     * again next time.
     */
    private static function refreshHubspotTokenIfExpired(array $row): array
    {
        if (empty($row['expires_at']) || strtotime((string) $row['expires_at']) > time() + 300) {
            return $row;
        }
        if (empty($row['refresh_token_enc'])) {
            throw new \RuntimeException('No refresh token on file');
        }
        $refreshToken = self::decryptToken($row['refresh_token_enc'], $row['token_iv']);
        $clientId     = Config::get('HUBSPOT_CLIENT_ID');
        $clientSecret = Config::get('HUBSPOT_CLIENT_SECRET');
        if (!$clientId || !$clientSecret) throw new \RuntimeException('HubSpot credentials missing');

        $token = self::httpForm('https://api.hubapi.com/oauth/v1/token', [
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);
        if (empty($token['access_token']) || empty($token['refresh_token'])) {
            throw new \RuntimeException('HubSpot refresh returned an incomplete token bundle');
        }

        $expiresAt = date('Y-m-d H:i:s', time() + (int) ($token['expires_in'] ?? 21600));
        $key = self::appKey();
        $iv  = random_bytes(16);
        $access  = self::encrypt($token['access_token'], $key, $iv);
        $refresh = self::encrypt($token['refresh_token'], $key, $iv);

        Database::getInstance()->query(
            'UPDATE integrations SET access_token_enc = ?, refresh_token_enc = ?, token_iv = ?,
                                      expires_at = ?, last_used_at = NOW()
              WHERE id = ?',
            [$access, $refresh, bin2hex($iv), $expiresAt, $row['id']]
        );

        $row['access_token_enc']  = $access;
        $row['refresh_token_enc'] = $refresh;
        $row['token_iv']          = bin2hex($iv);
        $row['expires_at']        = $expiresAt;
        return $row;
    }

    /** Returns the 32-byte symmetric key derived from APP_KEY. */
    private static function appKey(): string
    {
        $raw = Config::get('APP_KEY');
        if (!$raw) {
            // We refuse rather than fall back to a static key — storing OAuth
            // tokens unencrypted in MySQL is the kind of "small omission"
            // that becomes a Have-I-Been-Pwned headline.
            throw new \RuntimeException('APP_KEY env not set — required to encrypt CRM tokens');
        }
        // Accept base64-encoded 32B or any 32-char string.
        $decoded = base64_decode((string) $raw, true);
        $key = ($decoded !== false && strlen($decoded) === 32) ? $decoded : substr(hash('sha256', (string) $raw, true), 0, 32);
        return $key;
    }

    private static function httpForm(string $url, array $form): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new \RuntimeException('CRM token exchange failed: ' . $err);
        if ($code >= 400) throw new \RuntimeException("CRM token exchange HTTP $code: " . substr((string) $body, 0, 400));
        $parsed = json_decode((string) $body, true);
        if (!is_array($parsed)) throw new \RuntimeException('CRM returned non-JSON token response');
        return $parsed;
    }

    private static function httpJson(string $method, string $url, ?array $body, array $headers): array
    {
        $ch = curl_init($url);
        $h = array_merge(['Accept: application/json'], $headers);
        if ($body !== null) $h[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new \RuntimeException('CRM HTTP error: ' . $err);
        if ($code >= 400) throw new \RuntimeException("CRM HTTP $code: " . substr((string) $resp, 0, 400));
        if ($resp === '' || $resp === null) return [];
        $parsed = json_decode((string) $resp, true);
        return is_array($parsed) ? $parsed : [];
    }

    private static function baseUrl(): string
    {
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'smappen.mygreendock.com';
        return $scheme . '://' . $host;
    }

    private static function redirect(string $path): void
    {
        header('Location: ' . self::baseUrl() . $path, true, 302);
        exit;
    }
}
