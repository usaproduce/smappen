<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Contracts;

/**
 * What the owner's logged services say about the model (04_BACKEND.md 5.7).
 *
 * Resolved with Registry::calibration(): the calibration service when it is installed, else the fallback,
 * which answers the state of a truck without logs (every factor 1).
 */
interface CalibrationProvider
{
    /**
     * @param array<string, mixed> $truck the truck value of TruckBaseController::truck()
     * @param array<string, mixed> $A the truck's Assumptions
     * @param string $asOf civil date "YYYY-MM-DD": today in the truck's zone
     * @return array<string, mixed> CalibrationState of 02_MODEL.md section 3
     */
    public function state(string $orgId, array $truck, array $A, string $asOf): array;
}
