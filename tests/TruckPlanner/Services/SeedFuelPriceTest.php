<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Services\Fallback\SeedFuelPrice;
use App\TruckPlanner\Services\RegionService;
use PHPUnit\Framework\TestCase;

final class SeedFuelPriceTest extends TestCase
{
    private static function provider(): SeedFuelPrice
    {
        $db = (new RecordingDatabase())->when('FROM tp_regions WHERE region_id = ?', [
            'region_id' => 'dc', 'name' => 'Washington, DC region', 'cbsa' => '47900', 'timezone' => 'America/New_York',
            'h3_res' => 9, 'bbox_lat_min' => 37.9907, 'bbox_lng_min' => -78.3947, 'bbox_lat_max' => 39.7201,
            'bbox_lng_max' => -76.6625, 'center_lat' => 38.9072, 'center_lng' => -77.0369,
            'active_version' => null, 'previous_version' => null,
            'config_json' => '{"fuel_area_by_state": {"DC": "R1Y", "MD": "R1Y", "VA": "R1Z", "WV": "R1Z"}}',
            'created_at' => '2026-10-04 20:00:00', 'updated_at' => '2026-10-04 20:00:00',
        ]);
        return new SeedFuelPrice(new RegionService(new RegionRepository($db)));
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private static function truck(array $profile, ?string $state = 'VA'): array
    {
        return [
            'id' => 't1',
            'timezone' => 'America/New_York',
            'base_state' => $state,
            'profile' => $profile + ['region_id' => 'dc', 'fuel_type' => 'gasoline', 'fuel_price_override' => null],
        ];
    }

    public function testTheSeedPriceOfTheBaseStatesArea(): void
    {
        self::assertSame(
            ['price_per_gal' => 4.195, 'source' => 'seed', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28'],
            self::provider()->resolve(self::truck([]))
        );
        self::assertSame(
            ['price_per_gal' => 4.411, 'source' => 'seed', 'area' => 'R1Y', 'product' => 'EPMR', 'period' => '2026-09-28'],
            self::provider()->resolve(self::truck([], 'MD'))
        );
    }

    public function testDiesel(): void
    {
        self::assertSame(
            ['price_per_gal' => 5.953, 'source' => 'seed', 'area' => 'R1Z', 'product' => 'EPD2D', 'period' => '2026-09-28'],
            self::provider()->resolve(self::truck(['fuel_type' => 'diesel']))
        );
    }

    public function testAStateWithoutAnAreaAndATruckWithoutARegionUseTheNationalPrice(): void
    {
        $national = ['price_per_gal' => 4.465, 'source' => 'seed', 'area' => 'NUS', 'product' => 'EPMR', 'period' => '2026-09-28'];
        self::assertSame($national, self::provider()->resolve(self::truck([], 'PA')));
        self::assertSame($national, self::provider()->resolve(self::truck([], null)));
        self::assertSame($national, self::provider()->resolve(self::truck(['region_id' => 'none'])));
        $diesel = self::provider()->resolve(self::truck(['region_id' => 'none', 'fuel_type' => 'diesel']));
        self::assertSame(6.382, $diesel['price_per_gal']);
        self::assertSame('NUS', $diesel['area']);
    }

    public function testTheOwnersOwnPriceAlwaysWins(): void
    {
        self::assertSame(
            ['price_per_gal' => 3.899, 'source' => 'owner', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => null],
            self::provider()->resolve(self::truck(['fuel_price_override' => 3.899]))
        );
        $whole = self::provider()->resolve(self::truck(['fuel_price_override' => 4, 'fuel_type' => 'diesel']));
        self::assertSame(4.0, $whole['price_per_gal']);
        self::assertSame('owner', $whole['source']);
        self::assertSame('EPD2D', $whole['product']);
    }

    public function testRegionNoneNeedsNoDatabase(): void
    {
        $db = new RecordingDatabase();
        $provider = new SeedFuelPrice(new RegionService(new RegionRepository($db)));
        $answer = $provider->resolve(self::truck(['region_id' => 'none']));
        self::assertSame('NUS', $answer['area']);
        self::assertSame([], $db->calls);
    }

    public function testTheBareTruckRecordOfTheApiIsEnough(): void
    {
        // Only `profile` and `base_state` are read: a hand-built TruckRecord works as the truck value.
        $answer = self::provider()->resolve(['base_state' => 'VA', 'profile' => ['region_id' => 'dc', 'fuel_type' => 'gasoline']]);
        self::assertSame(4.195, $answer['price_per_gal']);
        self::assertSame('seed', $answer['source']);
    }
}
