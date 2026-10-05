<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Fallback;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Contracts\CalibrationProvider;

/**
 * The calibration provider while the calibration service is not installed: the state of a truck with no
 * logged service. The truck factor is 1, no spot has a factor, and estimates keep their widest range.
 */
final class IdentityCalibration implements CalibrationProvider
{
    public function state(string $orgId, array $truck, array $A, string $asOf): array
    {
        return Estimator::calibrate($A, [], $asOf);
    }
}
