<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Models\User;
use App\Models\Organization;
use App\Services\MailService;
use Firebase\JWT\JWT;

class AuthController
{
    public function register(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $email = strtolower(trim($body['email'] ?? ''));
        $password = $body['password'] ?? '';
        $name = trim($body['name'] ?? '');
        $orgName = trim($body['organization_name'] ?? '') ?: ($name . "'s Workspace");

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Invalid email');
        if (strlen($password) < 8) Response::error('Password must be at least 8 characters');
        if ($name === '') Response::error('Name is required');

        if (User::findByEmail($email)) Response::error('Email already registered', 409);

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $orgId = Organization::create(['name' => $orgName, 'plan' => 'free', 'max_seats' => 1]);
            $userId = User::create([
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'name' => $name,
                'organization_id' => $orgId,
                'role' => 'owner',
                'is_active' => 1,
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        // Fire-and-forget verification email — registration succeeds even if email infra is down.
        try {
            self::sendVerificationEmail($userId, $email, $name);
        } catch (\Throwable $e) {
            error_log('verification email failed: ' . $e->getMessage());
        }

        $user = User::getWithOrganization($userId);
        unset($user['password_hash']);
        $token = self::issueToken($user);
        Response::success(['user' => $user, 'token' => $token], 'Registered successfully', 201);
    }

    public function login(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $email = strtolower(trim($body['email'] ?? ''));
        $password = $body['password'] ?? '';

        $user = User::findByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Response::error('Invalid credentials', 401);
        }
        if (!$user['is_active']) {
            Response::error('Account is inactive', 403);
        }

        User::update($user['id'], ['last_login_at' => date('Y-m-d H:i:s')]);
        $user = User::getWithOrganization($user['id']);
        unset($user['password_hash']);
        $token = self::issueToken($user);
        Response::success(['user' => $user, 'token' => $token]);
    }

    public function logout(Request $request): void
    {
        // Revoke the bearer token by jti. The token's exp lives in the JWT so
        // we replay it from the auth payload — fall back to "now + 1 day" if
        // somehow missing (shouldn't be).
        $token = $request->getBearerToken();
        if (!$token) Response::success(['ok' => true]);
        try {
            $parts = explode('.', $token);
            $payload = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true) ?: [];
            $jti = $payload['jti'] ?? null;
            $exp = (int)($payload['exp'] ?? (time() + 86400));
            if ($jti) {
                Database::getInstance()->query(
                    'INSERT IGNORE INTO revoked_tokens (jti, user_id, revoked_at, expires_at, reason)
                     VALUES (?, ?, NOW(), FROM_UNIXTIME(?), "logout")',
                    [$jti, $request->user['id'], $exp]
                );
            }
        } catch (\Throwable $e) {
            error_log('logout revoke failed: ' . $e->getMessage());
        }
        Response::success(['ok' => true]);
    }

    public function me(Request $request): void
    {
        $user = User::getWithOrganization($request->user['id']);
        unset($user['password_hash']);
        Response::success(['user' => $user]);
    }

    public function refresh(Request $request): void
    {
        $token = self::issueToken($request->user);
        Response::success(['token' => $token]);
    }

    // ── Password reset ──────────────────────────────────────────────────────
    public function requestPasswordReset(Request $request): void
    {
        $email = strtolower(trim((string)($request->input('email') ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Mirror the success shape so attackers can't enumerate users.
            Response::success(['ok' => true]);
        }
        $user = User::findByEmail($email);
        if ($user) {
            // Wipe any prior pending tokens to keep the table small.
            Database::getInstance()->query(
                'DELETE FROM auth_tokens WHERE user_id = ? AND purpose = "password_reset"',
                [$user['id']]
            );
            $token = self::mintToken($user['id'], 'password_reset', 3600); // 1 hour
            self::sendPasswordResetEmail($user['email'], $user['name'], $token);
        }
        Response::success(['ok' => true]);
    }

    public function resetPassword(Request $request): void
    {
        $token = (string)($request->input('token') ?? '');
        $newPassword = (string)($request->input('password') ?? '');
        if ($token === '') Response::error('Token required');
        if (strlen($newPassword) < 8) Response::error('Password must be at least 8 characters');

        $row = self::redeemToken($token, 'password_reset');
        // Rotate password + stamp `tokens_invalid_before` so every JWT issued
        // before this moment is rejected on next request. Cheaper and more
        // correct than enumerating pseudo-jtis.
        Database::getInstance()->query(
            'UPDATE users SET password_hash = ?, tokens_invalid_before = NOW() WHERE id = ?',
            [password_hash($newPassword, PASSWORD_BCRYPT), $row['user_id']]
        );
        Response::success(['ok' => true]);
    }

    // ── Email verification ──────────────────────────────────────────────────
    public function verifyEmail(Request $request): void
    {
        $token = (string) $request->getQuery('token', '');
        if ($token === '') Response::error('Token required');
        $row = self::redeemToken($token, 'email_verify');
        Database::getInstance()->query(
            'UPDATE users SET email_verified_at = NOW() WHERE id = ?',
            [$row['user_id']]
        );
        Response::success(['ok' => true, 'verified_at' => date('c')]);
    }

    public function resendVerification(Request $request): void
    {
        $user = $request->user;
        if (!empty($user['email_verified_at'])) Response::success(['ok' => true, 'already' => true]);
        Database::getInstance()->query(
            'DELETE FROM auth_tokens WHERE user_id = ? AND purpose = "email_verify"',
            [$user['id']]
        );
        self::sendVerificationEmail($user['id'], $user['email'], $user['name']);
        Response::success(['ok' => true]);
    }

    // ── Profile / password change ───────────────────────────────────────────
    public function updateProfile(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $sets = [];
        $params = [];
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') Response::error('Name cannot be empty');
            $sets[] = 'name = ?'; $params[] = $name;
        }
        if (isset($body['email'])) {
            $email = strtolower(trim((string) $body['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Invalid email');
            if ($email !== $request->user['email']) {
                $exists = User::findByEmail($email);
                if ($exists) Response::error('Email already taken', 409);
                $sets[] = 'email = ?'; $params[] = $email;
                // Force re-verification when email changes.
                $sets[] = 'email_verified_at = NULL';
            }
        }
        $prefs = ['notify_email', 'notify_competitor_alerts', 'notify_team_activity'];
        foreach ($prefs as $p) {
            if (array_key_exists($p, $body)) {
                $sets[] = "$p = ?"; $params[] = $body[$p] ? 1 : 0;
            }
        }
        if (array_key_exists('slack_webhook_url', $body)) {
            $url = trim((string) $body['slack_webhook_url']);
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) Response::error('Invalid Slack webhook URL');
            $sets[] = 'slack_webhook_url = ?'; $params[] = $url ?: null;
        }
        if (array_key_exists('theme', $body)) {
            $t = (string) $body['theme'];
            if (!in_array($t, ['light', 'dark', 'auto'], true)) Response::error('Invalid theme');
            $sets[] = 'theme = ?'; $params[] = $t;
        }
        if (empty($sets)) Response::error('No fields to update');
        $sets[] = 'updated_at = NOW()';
        $params[] = $request->user['id'];
        Database::getInstance()->query(
            'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
        $user = User::getWithOrganization($request->user['id']);
        unset($user['password_hash']);
        Response::success(['user' => $user]);
    }

    public function changePassword(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $current = (string)($body['current_password'] ?? '');
        $new = (string)($body['new_password'] ?? '');
        if (strlen($new) < 8) Response::error('New password must be at least 8 characters');

        $row = Database::getInstance()->fetch('SELECT password_hash FROM users WHERE id = ?', [$request->user['id']]);
        if (!$row || !password_verify($current, $row['password_hash'])) {
            Response::error('Current password is incorrect', 403);
        }
        Database::getInstance()->query(
            'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?',
            [password_hash($new, PASSWORD_BCRYPT), $request->user['id']]
        );
        Response::success(['ok' => true]);
    }

    // ── API keys ────────────────────────────────────────────────────────────
    public function showApiKey(Request $request): void
    {
        $row = Database::getInstance()->fetch(
            'SELECT api_key_last4 FROM users WHERE id = ?',
            [$request->user['id']]
        );
        Response::success([
            'has_key' => !empty($row['api_key_last4']),
            'last4' => $row['api_key_last4'] ?? null,
        ]);
    }

    public function regenerateApiKey(Request $request): void
    {
        $raw = 'sm_' . bin2hex(random_bytes(24)); // 51 chars, prefix marks as Smappen key
        $hash = hash('sha256', $raw);
        $last4 = substr($raw, -4);
        Database::getInstance()->query(
            'UPDATE users SET api_key_hash = ?, api_key_last4 = ?, updated_at = NOW() WHERE id = ?',
            [$hash, $last4, $request->user['id']]
        );
        // Show the raw key exactly once — UI should warn the user to copy it now.
        Response::success(['api_key' => $raw, 'last4' => $last4]);
    }

    // ── Internals ───────────────────────────────────────────────────────────
    private static function issueToken(array $user): string
    {
        $jti = self::uuid();
        $payload = [
            'jti' => $jti,
            'user_id' => $user['id'],
            'email' => $user['email'],
            'organization_id' => $user['organization_id'] ?? null,
            'role' => $user['role'] ?? 'member',
            'iat' => time(),
            'exp' => time() + 86400,
        ];
        return JWT::encode($payload, Config::get('JWT_SECRET'), 'HS256');
    }

    private static function uuid(): string
    {
        return Database::uuid();
    }

    /**
     * Create a token row. Return the *raw* token to embed in the email;
     * the DB stores only sha256 so a DB leak doesn't yield usable links.
     */
    private static function mintToken(string $userId, string $purpose, int $ttlSeconds): string
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Database::getInstance()->query(
            'INSERT INTO auth_tokens (id, user_id, purpose, token_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())',
            [self::uuid(), $userId, $purpose, hash('sha256', $raw), $ttlSeconds]
        );
        return $raw;
    }

    /** Validate + consume a token in one transaction. Bails on expired/used. */
    private static function redeemToken(string $raw, string $purpose): array
    {
        $hash = hash('sha256', $raw);
        $row = Database::getInstance()->fetch(
            'SELECT * FROM auth_tokens WHERE token_hash = ? AND purpose = ?',
            [$hash, $purpose]
        );
        if (!$row) Response::error('Invalid token', 400);
        if (!empty($row['used_at'])) Response::error('Token already used', 400);
        if (strtotime($row['expires_at']) < time()) Response::error('Token expired', 400);
        Database::getInstance()->query(
            'UPDATE auth_tokens SET used_at = NOW() WHERE id = ?',
            [$row['id']]
        );
        return $row;
    }

    private static function sendVerificationEmail(string $userId, string $email, string $name): void
    {
        $token = self::mintToken($userId, 'email_verify', 86400 * 7); // 7 days
        $base = rtrim((string) Config::get('FRONTEND_URL', 'https://smappen.mygreendock.com'), '/');
        $link = $base . '/verify-email?token=' . urlencode($token);
        $html = '<p>Hi ' . htmlspecialchars($name) . ',</p>'
              . '<p>Confirm your email for Smappen:</p>'
              . '<p><a href="' . htmlspecialchars($link) . '" style="display:inline-block;background:#7848BB;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600">Verify email</a></p>'
              . '<p style="color:#666;font-size:12px">This link expires in 7 days.</p>';
        (new MailService())->send($email, 'Verify your Smappen email', $html);
    }

    private static function sendPasswordResetEmail(string $email, string $name, string $token): void
    {
        $base = rtrim((string) Config::get('FRONTEND_URL', 'https://smappen.mygreendock.com'), '/');
        $link = $base . '/reset-password?token=' . urlencode($token);
        $html = '<p>Hi ' . htmlspecialchars($name) . ',</p>'
              . '<p>Someone asked to reset your Smappen password. If that was you, click below:</p>'
              . '<p><a href="' . htmlspecialchars($link) . '" style="display:inline-block;background:#7848BB;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600">Reset password</a></p>'
              . '<p style="color:#666;font-size:12px">This link expires in 1 hour. If you didn\'t ask, ignore this email.</p>';
        (new MailService())->send($email, 'Reset your Smappen password', $html);
    }
}
