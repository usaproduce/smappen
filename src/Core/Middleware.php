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
            if (!$token) {
                Response::error('Authentication required', 401);
                return false;
            }
            try {
                $decoded = JWT::decode($token, new Key(Config::get('JWT_SECRET'), 'HS256'));
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
                unset($user['password_hash']);
                $request->user = $user;
                return true;
            } catch (\Throwable $e) {
                Response::error('Invalid or expired token: ' . $e->getMessage(), 401);
                return false;
            }
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
            if ((int)$row['total'] >= $maxRequests) {
                Response::error('Rate limit exceeded. Upgrade your plan for higher limits.', 429);
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
                    Database::getInstance()->insert('api_usage_log', [
                        'user_id' => $request->user['id'],
                        'api_name' => $apiName,
                        'endpoint' => $request->getPath(),
                        'request_count' => 1,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (\Throwable $e) {}
            }
            return true;
        };
    }
}
