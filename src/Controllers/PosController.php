<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\PrivateData\PosIntegrationRepository;
use App\PrivateData\RestaurantRepository;
use App\Services\PosService;

/**
 * POS OAuth + sync.
 *
 * Routes (auth except callback — callback validates via signed state):
 *   POST  /api/restaurants/{id}/pos/{provider}/connect    → returns auth_url
 *   GET   /api/integrations/pos/{provider}/callback       → token exchange, redirect
 *   POST  /api/restaurants/{id}/pos/{provider}/sync       → enqueue sync job
 *   GET   /api/restaurants/{id}/pos                        → list connections
 */
class PosController
{
    private PosService $pos;
    private PosIntegrationRepository $integrations;
    private RestaurantRepository $restaurants;

    public function __construct(
        ?PosService $pos = null,
        ?PosIntegrationRepository $integrations = null,
        ?RestaurantRepository $restaurants = null,
    ) {
        $this->pos = $pos ?? new PosService();
        $this->integrations = $integrations ?? new PosIntegrationRepository();
        $this->restaurants = $restaurants ?? new RestaurantRepository();
    }

    public function listForRestaurant(Request $request): void
    {
        $restaurant = $this->verifyOwnedRestaurant($request);
        $rows = $this->integrations->listByRestaurant($restaurant['id']);
        Response::success(['integrations' => $rows]);
    }

    public function connect(Request $request): void
    {
        $restaurant = $this->verifyOwnedRestaurant($request);
        $provider = self::normalizeProvider((string) $request->getParam('provider'));
        try {
            $url = $this->pos->beginOAuth($provider, $restaurant['id'], $request->user['organization_id']);
        } catch (\RuntimeException $e) {
            // Most likely: credentials missing for this provider on this server.
            Response::error(ucfirst($provider) . ' integration not configured on this server: ' . $e->getMessage(), 503);
        }
        Response::success(['auth_url' => $url]);
    }

    public function callback(Request $request): void
    {
        $provider = self::normalizeProvider((string) $request->getParam('provider'));
        $code  = $_GET['code']  ?? null;
        $state = $_GET['state'] ?? null;
        if (!$code || !$state) {
            Response::error('Invalid OAuth callback — missing code or state', 400);
        }
        try {
            [$restaurantId, $orgId] = $this->pos->completeOAuth(
                $provider,
                (string) $code,
                (string) $state,
                $this->integrations,
            );
        } catch (\RuntimeException $e) {
            Response::error('OAuth failed: ' . $e->getMessage(), 400);
        }
        // Activation milestone — first POS connected. Idempotent (only writes
        // when the column is NULL via stampActivation's COALESCE pattern).
        // We use the user_id from a fresh lookup since the callback is
        // unauthenticated — but signed state already proves the org.
        $user = Database::getInstance()->fetch(
            'SELECT id FROM users WHERE organization_id = ? ORDER BY created_at ASC LIMIT 1',
            [$orgId]
        );
        if ($user) {
            OnboardingController::stampActivation((string) $user['id'], $orgId, 'first_pos_connected_at');
        }

        // Best-effort enqueue of the first sync so the operator doesn't
        // have to click again.
        try {
            $this->enqueueSync($restaurantId, $orgId, $provider);
        } catch (\Throwable $e) {
            error_log('[pos.callback] enqueueSync failed: ' . $e->getMessage());
        }
        self::redirect('/settings/integrations?connected=' . $provider);
    }

    public function sync(Request $request): void
    {
        $restaurant = $this->verifyOwnedRestaurant($request);
        $provider = self::normalizeProvider((string) $request->getParam('provider'));
        $row = $this->integrations->findActive($restaurant['id'], $provider);
        if (!$row) Response::error('Connect ' . $provider . ' first', 400);
        $jobId = $this->enqueueSync($restaurant['id'], $request->user['organization_id'], $provider);
        Response::success(['job_id' => $jobId, 'queued' => true], 'Sync queued', 202);
    }

    // ──────────────────────────── helpers ────────────────────────────

    private function verifyOwnedRestaurant(Request $request): array
    {
        $id = (string) $request->getParam('id');
        $r = $this->restaurants->findById($id, $request->user['organization_id']);
        if (!$r) Response::error('Restaurant not found', 404);
        return $r;
    }

    private static function normalizeProvider(string $p): string
    {
        $p = strtolower(trim($p));
        if (!in_array($p, ['square'], true)) {
            // Phase 1 ships Square only. Toast + Clover land in 1.5 / 2.
            Response::error("Unsupported POS provider: $p", 422);
        }
        return $p;
    }

    private function enqueueSync(string $restaurantId, string $organizationId, string $provider): string
    {
        $jobId = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO jobs (id, organization_id, type, payload, status, attempts, max_attempts,
                               progress_pct, available_at, created_at)
             VALUES (?, ?, ?, ?, "queued", 0, 3, 0, NOW(), NOW())',
            [
                $jobId, $organizationId, 'pos.sync',
                json_encode([
                    'restaurant_id'   => $restaurantId,
                    'provider'        => $provider,
                    'organization_id' => $organizationId,
                ]),
            ]
        );
        return $jobId;
    }

    private static function redirect(string $path): void
    {
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'carafe.mygreendock.com';
        header('Location: ' . $scheme . '://' . $host . $path, true, 302);
        exit;
    }
}
