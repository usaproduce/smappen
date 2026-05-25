<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\TerritoryGenerator;
use App\Services\CompetitorScanner;
use App\Services\WebhookDispatcher;
use App\Services\GeoUtils;
use App\Services\PosService;
use App\Services\PlateCostService;
use App\Services\MenuEngineeringService;
use App\Controllers\OnboardingController;
use App\PrivateData\PosIntegrationRepository;
use App\PrivateData\MenuItemRepository;
use App\PrivateData\RecipeRepository;
use App\PrivateData\PlateCostRepository;
use App\PrivateData\RecommendationRepository;
use App\PrivateData\RestaurantRepository;
use App\PrivateData\PosSalesRepository;
use App\SharedRef\CogsBenchmarkRepository;

Config::load(dirname(__DIR__));
ini_set('memory_limit', '1024M');
set_time_limit(0);

$db = Database::getInstance();

/**
 * Pull pending jobs, claim them atomically (reserved_at = NOW()), execute,
 * persist result/error. Designed to be invoked every ~10 seconds from cron.
 * Multiple workers can run in parallel — the UPDATE ... LIMIT 1 keeps each
 * job claimed by exactly one worker.
 */
$maxJobs = 5;
$claimed = 0;

while ($claimed < $maxJobs) {
    // Atomic claim: SELECT … FOR UPDATE locks the row so a concurrent worker
    // can't grab the same job between our SELECT and UPDATE. The previous
    // approach (UPDATE-then-SELECT by sentinel) could double-claim if two
    // workers raced. Wrap in a transaction so the lock is released cleanly.
    $job = null;
    try {
        $db->beginTransaction();
        $row = $db->fetch(
            "SELECT * FROM jobs
             WHERE status = 'queued' AND available_at <= NOW()
             ORDER BY created_at ASC
             LIMIT 1 FOR UPDATE SKIP LOCKED"
        );
        if ($row) {
            $db->query(
                "UPDATE jobs
                 SET status = 'running', reserved_at = NOW(), started_at = NOW(),
                     attempts = attempts + 1, progress_message = 'running'
                 WHERE id = ?",
                [$row['id']]
            );
            $job = $row;
        }
        $db->commit();
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) {}
        error_log('[job-worker] claim failed: ' . $e->getMessage());
        break;
    }
    if (!$job) break;
    $claimed++;
    echo "==> running job {$job['id']} ({$job['type']})\n";

    $payload = json_decode($job['payload'] ?? '{}', true) ?: [];
    $result = null;
    $error = null;

    try {
        switch ($job['type']) {
            case 'territory.generate':
                $gen = new TerritoryGenerator();
                $r = $gen->run(
                    $payload['bbox'],
                    (int)($payload['target_count'] ?? 8),
                    $payload['balance_metric'] ?? 'population',
                    (array)($payload['constraints'] ?? [])
                );
                // Persist the territories as areas (mirrors TerritoryController inline path).
                $palette = ['#7848BB', '#22c55e', '#3b82f6', '#f59e0b', '#ef4444', '#ec4899',
                    '#06b6d4', '#a855f7', '#14b8a6', '#f97316'];
                $areaIds = [];
                foreach ($r['territories'] as $i => $t) {
                    $wkt = GeoUtils::geoJsonToWkt($t['geometry']);
                    $color = $palette[$i % count($palette)];
                    $aid = Database::uuid();
                    $db->query(
                        "INSERT INTO areas
                           (id, project_id, name, area_type, center_lat, center_lng,
                            geometry, fill_color, fill_opacity, stroke_color, stroke_weight,
                            demographics_cache, demographics_cached_at,
                            created_by, generation_job_id, territory_index,
                            created_at, updated_at)
                         VALUES (?, ?, ?, 'manual', ?, ?,
                                 ST_GeomFromText(?, 4326), ?, 0.30, ?, 2,
                                 ?, NOW(), ?, ?, ?, NOW(), NOW())",
                        [
                            $aid, $job['project_id'], ($payload['name'] ?? 'Territory') . ' ' . ($i + 1),
                            $t['centroid']['lat'], $t['centroid']['lng'],
                            $wkt, $color, $color,
                            json_encode([
                                'population' => $t['population'],
                                'median_household_income' => $t['median_household_income'],
                                'tract_count' => $t['tract_count'],
                                'pop_share_pct' => $t['pop_share_pct'],
                            ]),
                            $job['user_id'], $job['id'], $i,
                        ]
                    );
                    $areaIds[] = $aid;
                }
                $result = [
                    'territory_count' => count($r['territories']),
                    'tract_count' => $r['tract_count'],
                    'area_ids' => $areaIds,
                ];
                fanout($job, 'territory.generated', $result);
                break;

            case 'competitor.scan':
                $m = $db->fetch('SELECT * FROM competitor_monitors WHERE id = ?', [$payload['monitor_id']]);
                if (!$m) throw new RuntimeException('Monitor not found');
                $scanner = new CompetitorScanner();
                $result = $scanner->scan($m);
                if (!empty($result['alert_count'])) {
                    fanout($job, 'competitor.alert', $result);
                }
                break;

            case 'webhook.deliver':
                $sub = $db->fetch('SELECT * FROM webhook_subscriptions WHERE id = ?', [$payload['subscription_id']]);
                if ($sub) {
                    $result = (new WebhookDispatcher())->dispatch($sub, $payload['event'], $payload['data']);
                }
                break;

            case 'pos.sync':
                $restaurantId = $payload['restaurant_id'] ?? null;
                $provider = $payload['provider'] ?? null;
                if (!$restaurantId || !$provider) throw new RuntimeException('pos.sync missing restaurant_id/provider');
                $integrations = new PosIntegrationRepository();
                $items = new MenuItemRepository();
                $sales = new PosSalesRepository();
                $pos = new PosService();
                $syncResult = $pos->sync($restaurantId, $provider, $integrations, $items, $sales);

                // Refresh plate costs for any items with linked recipes — new
                // items default to no recipe, so this is a no-op for the very
                // first sync but useful on subsequent syncs.
                $restaurant = $db->fetch('SELECT organization_id, region FROM restaurants WHERE id = ?', [$restaurantId]);
                if ($restaurant) {
                    $plateCostSvc = new PlateCostService(
                        $items,
                        new RecipeRepository(),
                        new PlateCostRepository(),
                        new CogsBenchmarkRepository(),
                    );
                    $computedCount = $plateCostSvc->computeForRestaurant(
                        $restaurantId,
                        $restaurant['organization_id'],
                        $restaurant['region'] ?? null,
                    );

                    // Then refresh recommendations — now backed by real PMIX volume.
                    $engine = new MenuEngineeringService(
                        $items,
                        new PlateCostRepository(),
                        new RecommendationRepository(),
                        $sales,
                    );
                    $recCount = $engine->recommendForRestaurant($restaurantId, $restaurant['organization_id']);
                    $syncResult['plate_costs_computed'] = $computedCount;
                    $syncResult['recommendations_created'] = $recCount;
                }
                $result = $syncResult;

                // Activation stamp: first time we successfully pull menu items.
                if (($syncResult['pulled_count'] ?? 0) > 0 && !empty($job['user_id'])) {
                    OnboardingController::stampActivation(
                        (string) $job['user_id'],
                        (string) $job['organization_id'],
                        'first_menu_synced_at'
                    );
                }

                fanout($job, 'pos.sync.completed', $syncResult);
                break;

            default:
                throw new RuntimeException('Unknown job type: ' . $job['type']);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log('[job-worker] ' . $job['type'] . ' ' . $job['id'] . ' failed: ' . $error);
    }

    if ($error !== null) {
        $shouldRetry = ((int)$job['attempts']) < (int)$job['max_attempts'];
        $db->query(
            'UPDATE jobs SET status = ?, error_message = ?,
                             available_at = ?, finished_at = ?
             WHERE id = ?',
            [
                $shouldRetry ? 'queued' : 'failed',
                substr($error, 0, 4000),
                $shouldRetry ? date('Y-m-d H:i:s', time() + 60 * (int)$job['attempts']) : $job['available_at'],
                $shouldRetry ? null : date('Y-m-d H:i:s'),
                $job['id'],
            ]
        );
        echo "    " . ($shouldRetry ? 'retry queued' : 'failed permanently') . "\n";
        continue;
    }
    $db->query(
        'UPDATE jobs SET status = "done", result = ?, progress_pct = 100,
                         finished_at = NOW(), progress_message = NULL
         WHERE id = ?',
        [$result !== null ? json_encode($result) : null, $job['id']]
    );
    echo "    done\n";
}

if ($claimed === 0) echo "no jobs\n";

function fanout(array $job, string $event, $data): void
{
    try {
        $db = Database::getInstance();
        $org = $db->fetch('SELECT organization_id FROM jobs WHERE id = ?', [$job['id']])['organization_id'] ?? null;
        if (!$org && !empty($job['project_id'])) {
            $org = $db->fetch('SELECT organization_id FROM projects WHERE id = ?', [$job['project_id']])['organization_id'] ?? null;
        }
        if (!$org) return;
        (new WebhookDispatcher())->fanout($org, $event, ['job_id' => $job['id']] + (array)$data);
    } catch (Throwable $e) {
        error_log('fanout failed: ' . $e->getMessage());
    }
}
