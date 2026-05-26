<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\PlacesEnrichService;
use App\Services\SeedCampaignService;
use App\Services\SeedDeltaService;
use App\Services\SeedEstimatorService;

/**
 * SeedCampaignController — admin surface for the vendor-network seeding
 * campaigns. Carafe Vendor Network Spec v3 §7 + §8.
 *
 * Phase 2 ships the estimator endpoint only. Future phases add:
 *   - POST   /api/admin/seed-campaigns          (create + persist a draft)
 *   - POST   /api/admin/seed-campaigns/{id}/run (approve + queue tiles)
 *   - POST   /api/admin/seed-campaigns/{id}/pause
 *   - GET    /api/admin/seed-campaigns         (list)
 *   - GET    /api/admin/seed-campaigns/{id}    (live status + spent)
 *   - POST   /api/admin/seed-campaigns/{id}/publish (move reviewed vendors to public)
 *
 * Admin gating: route is wrapped in Middleware::requireRole(['admin','owner']);
 * controller doesn't re-check, the middleware short-circuits with 403.
 */
class SeedCampaignController
{
    /**
     * POST /api/admin/seed-campaigns/estimate
     *
     * Body:
     *   {
     *     "bbox":            [lat_min, lng_min, lat_max, lng_max],   // required
     *     "vendor_types":    ["broadline","cash_carry","produce"],    // required
     *     "enrich_policy":   "all"|"priority_types"|"on_demand",      // optional, default priority_types
     *     "density_profile": "rural"|"suburban"|"dense"|"mixed"        // optional, default mixed
     *   }
     *
     * Returns the dual-pass estimate shape from SeedEstimatorService::estimate.
     * Makes ZERO Places API calls — spec §10 guardrail 2.
     */
    public function estimate(Request $request): void
    {
        $body = $request->getBody() ?? [];

        $bbox = $body['bbox'] ?? null;
        if (!is_array($bbox) || count($bbox) !== 4) {
            Response::error('bbox must be [lat_min, lng_min, lat_max, lng_max]', 422);
            return;
        }
        $bbox = array_map('floatval', $bbox);
        [$latMin, $lngMin, $latMax, $lngMax] = $bbox;
        if ($latMax <= $latMin || $lngMax <= $lngMin) {
            Response::error('bbox must satisfy lat_max > lat_min and lng_max > lng_min', 422);
            return;
        }
        if ($latMin < -90 || $latMax > 90 || $lngMin < -180 || $lngMax > 180) {
            Response::error('bbox lat/lng out of range', 422);
            return;
        }

        $vendorTypes = $body['vendor_types'] ?? null;
        if (!is_array($vendorTypes) || empty($vendorTypes)) {
            Response::error('vendor_types must be a non-empty array', 422);
            return;
        }

        $policy  = $body['enrich_policy']   ?? 'priority_types';
        $density = $body['density_profile'] ?? 'mixed';

        $monthlyVolume = self::currentMonthlyVolume();

        try {
            $estimator = new SeedEstimatorService();
            $result = $estimator->estimate(
                [
                    'bbox'            => $bbox,
                    'vendor_types'    => $vendorTypes,
                    'enrich_policy'   => $policy,
                    'density_profile' => $density,
                ],
                $monthlyVolume
            );
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
            return;
        }

        Response::success([
            'estimate'        => $result,
            'monthly_volume'  => $monthlyVolume,
        ]);
    }

    /** POST /api/admin/seed-campaigns — create a draft + run the estimator. */
    public function create(Request $request): void
    {
        try {
            $svc      = new SeedCampaignService();
            $campaign = $svc->create($request->getBody() ?? [], $request->user['id'] ?? null);
            Response::success(['campaign' => $campaign], null, 201);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Response::error('Failed to create campaign: ' . $e->getMessage(), 500);
        }
    }

    /** POST /api/admin/seed-campaigns/{id}/run — approve + materialize tiles + status=running. */
    public function run(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        try {
            $svc = new SeedCampaignService();
            $campaign = $svc->run($id);
            // Clicking Run = the admin manually launching the pipeline.
            // Spawn the sweep → dedupe → classify chain in the background
            // so the user sees tile counters move within seconds, no cron
            // wait. Idempotent — multiple workers cooperate via
            // FOR UPDATE SKIP LOCKED.
            self::spawnPipeline();
            Response::success([
                'campaign'        => $campaign,
                'worker_spawned'  => true,
            ]);
        } catch (\DomainException $e) {
            Response::error($e->getMessage(), 409);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /** POST /api/admin/seed-campaigns/{id}/pause */
    public function pause(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        $reason = (string) (($request->getBody() ?? [])['reason'] ?? 'paused_by_admin');
        try {
            $svc = new SeedCampaignService();
            $svc->pause($id, $reason);
            Response::success(['campaign' => $svc->findById($id)]);
        } catch (\DomainException $e) {
            Response::error($e->getMessage(), 409);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /** POST /api/admin/seed-campaigns/{id}/resume */
    public function resume(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        try {
            $svc = new SeedCampaignService();
            $svc->resume($id);
            // Same as run() — clicking Resume = kick the worker now.
            self::spawnPipeline();
            Response::success([
                'campaign'        => $svc->findById($id),
                'worker_spawned'  => true,
            ]);
        } catch (\DomainException $e) {
            Response::error($e->getMessage(), 409);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /** POST /api/admin/seed-campaigns/{id}/cancel */
    public function cancel(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        try {
            $svc = new SeedCampaignService();
            $svc->cancel($id);
            Response::success(['campaign' => $svc->findById($id)]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * POST /api/admin/seed-campaigns/{id}/enrich — apply enrich_policy.
     * Body (all optional):
     *   { "batch_size": 100 }
     *
     * Returns the tally from PlacesEnrichService::enrichCampaign.
     * Synchronous for now — wrap in a job (spec §9 step 7 + jobs queue)
     * for long-running enriches in a later iteration.
     */
    public function enrich(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        $body = $request->getBody() ?? [];
        $batchSize = max(1, min(1000, (int) ($body['batch_size'] ?? 100)));
        try {
            $svc = new PlacesEnrichService();
            $tally = $svc->enrichCampaign($id, $batchSize);
            Response::success(['result' => $tally]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * POST /api/admin/vendors/{id}/enrich — single-vendor on-demand enrich.
     * For the spec's on_demand policy: identity is stored at seed time,
     * full detail is pulled the first time an admin/operator opens the
     * vendor. Subsequent views within the TTL hit the cache.
     */
    public function enrichVendor(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        $body = $request->getBody() ?? [];
        $tier = (string) ($body['tier'] ?? 'full');
        try {
            $svc = new PlacesEnrichService();
            $r = $svc->enrichVendor($id, $tier, null);
            Response::success(['result' => $r]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/seed-campaigns/{id}/delta — pre-flight summary for a re-sweep.
     * Returns counts of changed / unchanged / eligible / stuck tiles so the
     * admin sees what a Resweep button would actually do (§12.3).
     */
    public function delta(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        $maxAge = max(1, min(365, (int) $request->getQuery('max_age_days', SeedDeltaService::DEFAULT_RESWEEP_AGE_DAYS)));
        $svc = new SeedDeltaService();
        Response::success(['delta' => $svc->deltaSummary($id, $maxAge)]);
    }

    /**
     * POST /api/admin/seed-campaigns/{id}/resweep — re-queue stale tiles.
     * Body: { "max_age_days": 30 } (optional, defaults to 30)
     * Worker honors the existing result_id_hash so unchanged tiles
     * cost only the Search calls, not the upsert/dedupe/enrich cascade.
     */
    public function resweep(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        $body = $request->getBody() ?? [];
        $maxAge = max(1, min(365, (int) ($body['max_age_days'] ?? SeedDeltaService::DEFAULT_RESWEEP_AGE_DAYS)));
        try {
            $svc = new SeedDeltaService();
            $count = $svc->scheduleResweepForCampaign($id, $maxAge);
            Response::success(['requeued' => $count]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /** GET /api/admin/seed-campaigns/{id} — live status snapshot. */
    public function show(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        $svc = new SeedCampaignService();
        $c   = $svc->summary($id);
        if (!$c) { Response::error('not found', 404); return; }
        Response::success(['campaign' => $c]);
    }

    /** GET /api/admin/seed-campaigns — list (most recent first). */
    public function index(Request $request): void
    {
        $limit  = max(1, min(200, (int) $request->getQuery('limit',  50)));
        $offset = max(0,         (int) $request->getQuery('offset', 0));
        $svc    = new SeedCampaignService();
        Response::success(['campaigns' => $svc->index($limit, $offset)]);
    }

    /**
     * POST /api/admin/seed-campaigns/{id}/kick — spawn a worker run
     * without touching campaign status. Handy when a campaign is already
     * 'running' but the previous worker exited and you want to drain the
     * remaining queued tiles right now.
     */
    public function kick(Request $request): void
    {
        $id = $request->getParam('id');
        if ($id) {
            // Optional id is for parity with the other actions — workers
            // operate globally on queued tiles, but echo back the campaign
            // state so the UI can update.
            $svc = new SeedCampaignService();
            $c = $svc->findById($id);
            if (!$c) { Response::error('not found', 404); return; }
        }
        self::spawnPipeline();
        Response::success(['worker_spawned' => true]);
    }

    /**
     * Spawn the sweep → dedupe → classify chain in the background via
     * nohup so the HTTP request returns immediately. The polling on the
     * campaign detail page (5s while status=running) then visibly tracks
     * tile + vendor counters as the worker drains the queue.
     *
     * Concurrent invocations are safe — every worker uses FOR UPDATE
     * SKIP LOCKED so they cooperate rather than collide.
     */
    private static function spawnPipeline(): void
    {
        $base   = dirname(__DIR__, 2);
        $logDir = $base . '/storage/logs';
        if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
        $log = $logDir . '/seed-pipeline.log';
        $php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : '/usr/bin/php';

        $chain = sprintf(
            '%s %s --max-tiles=100 --max-seconds=540 --quiet && '
                . '%s %s --quiet && '
                . '%s %s --quiet',
            escapeshellarg($php), escapeshellarg($base . '/scripts/seed-tile-worker.php'),
            escapeshellarg($php), escapeshellarg($base . '/scripts/seed-dedupe.php'),
            escapeshellarg($php), escapeshellarg($base . '/scripts/seed-classify.php')
        );

        $cmd = sprintf(
            'nohup sh -c %s >> %s 2>&1 &',
            escapeshellarg($chain),
            escapeshellarg($log)
        );
        // Suppress any error from exec — if shell_exec is disabled in
        // production PHP-FPM config the campaign still works, the user
        // just needs to wait for cron (or kick manually).
        @exec($cmd);
        error_log("[seed-pipeline] spawned: $cmd");
    }

    /**
     * Current calendar-month billable units per SKU family, from the
     * api_cost_events ledger. The estimator subtracts these from each
     * SKU's free-tier credit before pricing the campaign.
     *
     * Returns ['search' => N, 'details' => N] — add-on SKUs roll up
     * into the 'details' family for tiering purposes (they all bill
     * together at /v1/places/{id}).
     */
    public static function currentMonthlyVolume(): array
    {
        try {
            $db = Database::getInstance();
            $row = $db->fetch(
                "SELECT
                   COALESCE(SUM(CASE WHEN sku IN ('places_nearby_pro','places_text_pro') THEN billable_units ELSE 0 END), 0) AS search_units,
                   COALESCE(SUM(CASE WHEN sku IN ('place_details_pro','place_details_contact','place_details_atmosphere') THEN billable_units ELSE 0 END), 0) AS details_units
                 FROM api_cost_events
                 WHERE called_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
            );
            return [
                'search'  => (int) ($row['search_units']  ?? 0),
                'details' => (int) ($row['details_units'] ?? 0),
            ];
        } catch (\Throwable $e) {
            // If the table doesn't exist yet (mig 028 not run), return zeros.
            error_log('SeedCampaignController::currentMonthlyVolume: ' . $e->getMessage());
            return ['search' => 0, 'details' => 0];
        }
    }
}
