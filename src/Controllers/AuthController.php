<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Models\User;
use App\Models\Organization;
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

    private static function issueToken(array $user): string
    {
        $payload = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'organization_id' => $user['organization_id'] ?? null,
            'role' => $user['role'] ?? 'member',
            'iat' => time(),
            'exp' => time() + 86400,
        ];
        return JWT::encode($payload, Config::get('JWT_SECRET'), 'HS256');
    }
}
