<?php
declare(strict_types=1);

use App\Core\Router;

/**
 * Smoke step 00: two accounts, and what stands in front of every Truck Planner route.
 *
 *   1. Two users register. Each becomes the owner of an organization of its own, so the later steps have
 *      two tenants.
 *   2. Every Truck Planner route is behind the login: without a token each answers 401, and that is the
 *      only place a 401 comes from.
 *   3. With a token and no truck yet, every route that needs a truck answers 409 "Set up your truck
 *      first". The seven that work without one (bootstrap, profile, regions, pack, delete, sources) are
 *      exercised by the steps of their packages.
 *
 * The routes are read from config/routes.php itself, so this step follows the route table.
 */
return function (SmokeClient $c, array &$state): void {
    // ---- 1. two users, two organizations
    for ($n = 1; $n <= 2; $n++) {
        $email = 'tp-smoke-' . bin2hex(random_bytes(6)) . '@example.test';
        $c->as(0)->post('/api/auth/register', [
            'email' => $email,
            'password' => 'Smoke-' . bin2hex(random_bytes(9)),
            'name' => 'Truck Planner smoke ' . $n,
        ])->status(201)->path('data.user.email', $email)->path('data.user.role', 'owner');

        $token = $c->value('data.token');
        $organization = $c->value('data.user.organization_id');
        $c->check(is_string($token) && $token !== '', 'registration returns a token');
        $c->check(is_string($organization) && $organization !== '', 'a new user has an organization');
        $c->setToken($n, (string) $token);
        $state['users'][$n] = [
            'email' => $email,
            'user_id' => (string) $c->value('data.user.id'),
            'organization_id' => (string) $organization,
        ];
    }
    $c->check(
        $state['users'][1]['organization_id'] !== $state['users'][2]['organization_id'],
        'the two users belong to two organizations'
    );
    foreach ([1, 2] as $n) {
        $c->as($n)->get('/api/auth/me')->status(200)->path('data.user.id', $state['users'][$n]['user_id']);
    }

    // ---- the route table, as the router builds it
    $router = new Router();
    $register = require dirname(__DIR__, 3) . '/config/routes.php';
    $register($router);
    $routes = [];
    foreach ((new ReflectionProperty(Router::class, 'routes'))->getValue($router) as $route) {
        if (str_starts_with($route['pattern'], '/api/truck/')) {
            $routes[] = [$route['method'], $route['pattern']];
        }
    }
    if (count($routes) !== 44) {
        throw new SmokeFailure('config/routes.php registers ' . count($routes) . ' Truck Planner routes, expected 44');
    }

    // The routes that work before a truck exists (04_BACKEND.md 4.1).
    $withoutTruck = [
        'GET /api/truck/bootstrap',
        'GET /api/truck/profile',
        'PUT /api/truck/profile',
        'GET /api/truck/regions',
        'GET /api/truck/regions/{region_id}/pack/{dataset_version}',
        'POST /api/truck/data/delete',
        'GET /api/truck/sources',
    ];
    $sample = [
        '{id}' => '00000000-0000-4000-8000-000000000000',
        '{region_id}' => 'nowhere',
        '{dataset_version}' => 'nowhere-00000000-00000000',
        '{place_key}' => 'n0',
    ];
    $call = static function (SmokeClient $c, string $method, string $path): SmokeClient {
        // A body is sent with POST and PUT so that the answer does not depend on which a route reads first.
        switch ($method) {
            case 'GET':
                return $c->get($path);
            case 'POST':
                return $c->post($path, []);
            case 'PUT':
                return $c->put($path, []);
            case 'DELETE':
                return $c->delete($path);
        }
        throw new SmokeFailure('a Truck Planner route with the verb ' . $method);
    };

    // ---- 2. no token, no entry
    foreach ($routes as [$method, $pattern]) {
        $call($c->as(0)->allow(401), $method, strtr($pattern, $sample))->status(401);
    }

    // ---- 3. a token and no truck
    $needTruck = 0;
    foreach ($routes as [$method, $pattern]) {
        if (in_array($method . ' ' . $pattern, $withoutTruck, true)) {
            continue;
        }
        $call($c->as(1), $method, strtr($pattern, $sample))
            ->status(409)
            ->path('success', false)
            ->path('error', 'Set up your truck first');
        $needTruck++;
    }
    $c->check($needTruck === 37, $needTruck . ' routes need a truck, expected 37');
};
