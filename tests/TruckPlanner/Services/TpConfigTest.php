<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Services\Support\TpConfig;
use PHPUnit\Framework\TestCase;

/**
 * config/truck_planner.php holds the numbers 04_BACKEND.md fixes (7.1, 5.3, 5.4, 5.9).
 */
final class TpConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        TpConfig::replace(null);
    }

    public function testLimitsAreExactlyTheSevenTheBrowserGets(): void
    {
        self::assertSame(
            [
                'max_spots' => 500,
                'max_stops_per_plan' => 8,
                'max_points_per_drive_request' => 60,
                'max_pairs_per_drive_request' => 650,
                'max_body_bytes' => 262144,
                'day_context_max_days' => 14,
                'max_suggest_spots' => 200,
            ],
            TpConfig::get('limits')
        );
    }

    public function testRoutingSettings(): void
    {
        self::assertSame(3, TpConfig::get('routing.connect_timeout_s'));
        self::assertSame(8, TpConfig::get('routing.timeout_s'));
        self::assertSame(12, TpConfig::get('routing.call_budget_s'));
        self::assertSame(30, TpConfig::get('routing.leg_ttl_days'));
        self::assertSame(650, TpConfig::get('routing.max_elements_per_call'));
        self::assertSame(3000, TpConfig::get('routing.org_elements_per_day'));
        self::assertSame(20000, TpConfig::get('routing.global_elements_per_day'));
        self::assertSame('tp_routes_elements', TpConfig::get('routing.bucket'));
        self::assertSame(2, TpConfig::get('routing.bucket_wait_s'));
        self::assertSame(3600, TpConfig::get('routing.refusal_ttl_s'));
        self::assertSame(120, TpConfig::get('routing.backoff_quota_s'));
        self::assertSame(30, TpConfig::get('routing.backoff_upstream_s'));
        self::assertSame(2, TpConfig::get('routing.max_bad_requests_per_call'));
        self::assertSame(625, TpConfig::get('routing.routes_chunk_side') ** 2);
        self::assertSame(100, TpConfig::get('routing.legacy_chunk_side') ** 2);
    }

    public function testPlacesAndScoutSettings(): void
    {
        self::assertSame(30, TpConfig::get('places.contact_ttl_days'));
        self::assertSame(500.0, TpConfig::get('places.bias_radius_m'));
        self::assertSame(300.0, TpConfig::get('places.match_radius_m'));
        self::assertSame('tp_places_lookup', TpConfig::get('places.bucket'));
        self::assertSame(10, TpConfig::get('scout.shortlist_extra'));
        self::assertSame(3, TpConfig::get('scout.max_batches'));
        self::assertSame(15000, TpConfig::get('scout.max_screen'));
        self::assertSame(86400, TpConfig::get('scout.cache_ttl_s'));
    }

    public function testWeatherAndSuggestionSettings(): void
    {
        self::assertSame(60, TpConfig::get('weather.pause_after_failure_s'));
        self::assertSame(400, TpConfig::get('weather.max_periods'));
        self::assertSame(600, TpConfig::get('suggest.cache_ttl_s'));
        self::assertSame(2000, TpConfig::get('suggest.pairs_per_call'));
    }

    public function testEverySkuHasAUnitCost(): void
    {
        $costs = TpConfig::get('unit_cost_usd');
        self::assertSame(
            [
                'tp_routes_matrix' => 0.005,
                'tp_routes_matrix_pro' => 0.010,
                'tp_routes_matrix_ent' => 0.015,
                'tp_distance_matrix' => 0.005,
                'tp_places_text' => 0.035,
                'tp_nws_points' => 0.0,
                'tp_nws_hourly' => 0.0,
                'tp_eia_weekly' => 0.0,
            ],
            $costs
        );
        foreach (array_merge(
            array_values(TpConfig::get('routing.skus')),
            [TpConfig::get('places.sku'), TpConfig::get('weather.sku_points'), TpConfig::get('weather.sku_hourly'), TpConfig::get('fuel.sku')]
        ) as $sku) {
            self::assertArrayHasKey($sku, $costs);
            self::assertLessThanOrEqual(48, strlen($sku), 'api_cost_events.sku is VARCHAR(48)');
        }
    }

    public function testTheBucketsAreTheOnesTheMigrationSeeds(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Migrations/043_truck_planner_core.sql');
        self::assertStringContainsString("('" . TpConfig::get('routing.bucket') . "',", $sql);
        self::assertStringContainsString("('" . TpConfig::get('places.bucket') . "',", $sql);
    }

    public function testNoSecretsAndNoKeysLiveInTheFile(): void
    {
        $flat = json_encode(TpConfig::all());
        self::assertIsString($flat);
        self::assertStringNotContainsString('AIza', $flat);
        self::assertDoesNotMatchRegularExpression('/api_key|password|secret/i', $flat);
    }

    public function testAnUnknownSettingIsAProgrammingError(): void
    {
        foreach (['routing.timeout', 'nothing', 'limits.max_spots.more', ''] as $path) {
            try {
                TpConfig::get($path);
                self::fail('"' . $path . '" was answered');
            } catch (\LogicException $e) {
                self::assertStringContainsString('unknown Truck Planner setting', $e->getMessage());
            }
        }
    }

    public function testASettingsArrayCanBeReplaced(): void
    {
        TpConfig::replace(['routing' => ['org_elements_per_day' => 10]]);
        self::assertSame(10, TpConfig::get('routing.org_elements_per_day'));
        TpConfig::replace(null);
        self::assertSame(3000, TpConfig::get('routing.org_elements_per_day'));
    }
}
