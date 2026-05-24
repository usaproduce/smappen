<?php
namespace App\Core;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Middleware
{
    public static function auth(): callable
    {
        return function (Request $request) {
            $token = $request->getBearerToken();
            // Allow API-key auth as an alternative to JWT for programmatic clients.
            $apiKey = $request->getHeader('X-Api-Key') ?? null;
            if (!$token && !$apiKey) {
                Response::error('Authentication required', 401);
                return false;
            }

            if ($token) {
                try {
                    $decoded = JWT::decode($token, new Key(Config::get('JWT_SECRET'), 'HS256'));
                } catch (\Throwable $e) {
                    Response::error('Invalid or expired token: ' . $e->getMessage(), 401);
                    return false;
                }
                $jti = $decoded->jti ?? null;
                if ($jti) {
                    $revoked = Database::getInstance()->fetch(
                        'SELECT jti FROM revoked_tokens WHERE jti = ? AND expires_at > NOW()',
                        [$jti]
                    );
                    if ($revoked) {
                        Response::error('Token has been revoked', 401);
                        return false;
                    }
                }
                $user = Database::getInstance()->fetch(
                    'SELECT u.*, o.plan, o.name AS organization_name
                     FROM users u
                     LEFT JOIN organizations o ON o.id = u.organization_id
                     WHERE u.id = ? AND u.is_active = 1',
                    [$decoded->user_id]
                );
                if (!$user) {
                    Response::error('User not found or inactive', 401);
                    return false;
                }
                // Bulk-revocation cutoff: tokens issued before the user's
                // `tokens_invalid_before` (set on password reset) are rejected.
                // This handles "log me out everywhere" without per-jti rows.
                if (!empty($user['tokens_invalid_before'])) {
                    $iat = (int)($decoded->iat ?? 0);
                    if ($iat > 0 && $iat < strtotime($user['tokens_invalid_before'])) {
                        Response::error('Token revoked — please sign in again', 401);
                        return false;
                    }
                }
                unset($user['password_hash']);
                $request->user = $user;
                return true;
            }

            // API-key path
            $hash = hash('sha256', $apiKey);
            $user = Database::getInstance()->fetch(
                'SELECT u.*, o.plan, o.name AS organization_name
                 FROM users u
                 LEFT JOIN organizations o ON o.id = u.organization_id
                 WHERE u.api_key_hash = ? AND u.is_active = 1',
                [$hash]
            );
            if (!$user) {
                Response::error('Invalid API key', 401);
                return false;
            }
            unset($user['password_hash']);
            $request->user = $user;
            return true;
        };
    }

    public static function optionalAuth(): callable
    {
        return function (Request $request) {
            $token = $request->getBearerToken();
            if (!$token) return true;
            try {
                $decoded = JWT::decode($token, new Key(Config::get('JWT_SECRET'), 'HS256'));
                $user = Database::getInstance()->fetch(
                    'SELECT u.*, o.plan FROM users u LEFT JOIN organizations o ON o.id = u.organization_id WHERE u.id = ?',
                    [$decoded->user_id]
                );
                if ($user) {
                    unset($user['password_hash']);
                    $request->user = $user;
                }
            } catch (\Throwable $e) {}
            return true;
        };
    }

    public static function rateLimit(string $apiName, int $maxRequests, int $windowSeconds = 86400): callable
    {
        return function (Request $request) use ($apiName, $maxRequests, $windowSeconds) {
            if (!$request->user) return true;
            $since = date('Y-m-d H:i:s', time() - $windowSeconds);
            $row = Database::getInstance()->fetch(
                'SELECT COALESCE(SUM(request_count),0) AS total FROM api_usage_log WHERE user_id = ? AND api_name = ? AND created_at >= ?',
                [$request->user['id'], $apiName, $since]
            );
            $used = (int)($row['total'] ?? 0);
            $remaining = max(0, $maxRequests - $used);
            $resetAt = time() + $windowSeconds;
            // Echo limit/remaining/reset headers on every call so API consumers
            // can pace themselves before hitting the wall. Frontend reads these
            // to warn the user when remaining drops low.
            header('X-RateLimit-Limit: ' . $maxRequests);
            header('X-RateLimit-Remaining: ' . max(0, $remaining - 1));
            header('X-RateLimit-Reset: ' . $resetAt);
            if ($used >= $maxRequests) {
                header('Retry-After: ' . max(1, $windowSeconds));
                Response::error("Rate limit reached ($apiName: $used/$maxRequests). Try again later.", 429);
                return false;
            }
            // Log this call so the next check sees it. Use raw INSERT — table has BIGINT auto-inc id.
            try {
                Database::getInstance()->query(
                    'INSERT INTO api_usage_log (user_id, api_name, endpoint, request_count, created_at)
                     VALUES (?, ?, ?, 1, ?)',
                    [$request->user['id'], $apiName, $request->getPath(), date('Y-m-d H:i:s')]
                );
            } catch (\Throwable $e) {}
            return true;
        };
    }

    /**
     * Require the authenticated user to have an organization role in the
     * given list. Useful for restricting team/billing actions to owners/admins.
     */
    public static function requireRole(array $roles): callable
    {
        return function (Request $request) use ($roles) {
            if (!$request->user) {
                Response::error('Authentication required', 401);
                return false;
            }
            $role = $request->user['role'] ?? 'member';
            if (!in_array($role, $roles, true)) {
                Response::error("Requires role: " . implode('/', $roles), 403);
                return false;
            }
            return true;
        };
    }

    /** Require verified email — used for actions that touch money or invitations. */
    public static function requireVerifiedEmail(): callable
    {
        return function (Request $request) {
            if (!$request->user) {
                Response::error('Authentication required', 401);
                return false;
            }
            if (empty($request->user['email_verified_at'])) {
                Response::error('Please verify your email address before doing this.', 403);
                return false;
            }
            return true;
        };
    }

    public static function planCheck(string $feature): callable
    {
        return function (Request $request) use ($feature) {
            if (!$request->user) return false;
            $plan = $request->user['plan'] ?? 'free';
            $limits = \App\Core\PlanLimits::getLimits($plan);
            if (!isset($limits[$feature]) || $limits[$feature] === false) {
                Response::error("Feature '$feature' is not available on the {$plan} plan. Please upgrade.", 403);
                return false;
            }
            return true;
        };
    }

    public static function logApiUsage(string $apiName): callable
    {
        return function (Request $request) use ($apiName) {
            if ($request->user) {
                try {
                    // Raw INSERT — api_usage_log has BIGINT AUTO_INCREMENT id, not UUID.
                    Database::getInstance()->query(
                        'INSERT INTO api_usage_log (user_id, api_name, endpoint, request_count, created_at)
                         VALUES (?, ?, ?, 1, ?)',
                        [$request->user['id'], $apiName, $request->getPath(), date('Y-m-d H:i:s')]
                    );
                } catch (\Throwable $e) {}
            }
            return true;
        };
    }
}
