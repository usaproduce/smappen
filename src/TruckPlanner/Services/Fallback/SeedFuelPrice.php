<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Fallback;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\FuelPriceProvider;
use App\TruckPlanner\Services\RegionService;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * The fuel price provider while the fuel price service is not installed. The owner's own price wins, as
 * it always does; otherwise the answer is the seed price for the truck's fuel and area, with the date the
 * seed is of. Weekly prices are the fuel price service's.
 */
final class SeedFuelPrice implements FuelPriceProvider
{
    private ?RegionService $regions;

    public function __construct(?RegionService $regions = null)
    {
        $this->regions = $regions;
    }

    public function resolve(array $truck): array
    {
        $profile = is_array($truck['profile'] ?? null) ? $truck['profile'] : [];
        $fuelType = ($profile['fuel_type'] ?? 'gasoline') === 'diesel' ? 'diesel' : 'gasoline';
        $product = (string) TpConfig::get('fuel.products')[$fuelType];
        $regionId = isset($profile['region_id']) ? (string) $profile['region_id'] : null;
        $state = isset($truck['base_state']) ? (string) $truck['base_state'] : null;
        $area = ($this->regions ??= new RegionService())->fuelArea($regionId, $state);

        $own = $profile['fuel_price_override'] ?? null;
        if (is_int($own) || is_float($own)) {
            return [
                'price_per_gal' => (float) $own,
                'source' => 'owner',
                'area' => $area,
                'product' => $product,
                'period' => null,
            ];
        }

        $A = Seeds::defaults();
        $table = Estimator::seed($A, 'money.fuel_price_fallback.' . $fuelType);
        $national = (string) TpConfig::get('fuel.national_area');
        $price = is_array($table) && isset($table[$area]) ? $table[$area] : $table[$national];
        return [
            'price_per_gal' => (float) $price,
            'source' => 'seed',
            'area' => $area,
            'product' => $product,
            'period' => (string) Estimator::seed($A, 'money.fuel_price_fallback.as_of'),
        ];
    }
}
