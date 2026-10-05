<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Contracts;

/**
 * The fuel price a truck's costs are computed with (04_BACKEND.md 5.6).
 *
 * Resolved with Registry::fuel(): the fuel price service when it is installed, else the fallback, which
 * knows the owner's own price and the seed price but no weekly prices.
 */
interface FuelPriceProvider
{
    /**
     * @param array<string, mixed> $truck the truck value of TruckBaseController::truck()
     * @return array{price_per_gal: float, source: "owner"|"eia"|"seed", area: string,
     *               product: "EPMR"|"EPD2D", period: ?string} FuelInfo. `area` is the EIA area of the
     *         truck's base state ("NUS" when unknown), `period` the date the price is of (null for the
     *         owner's own price)
     */
    public function resolve(array $truck): array;
}
