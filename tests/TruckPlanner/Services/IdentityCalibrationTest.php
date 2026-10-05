<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Fallback\IdentityCalibration;
use PHPUnit\Framework\TestCase;

final class IdentityCalibrationTest extends TestCase
{
    public function testTheStateOfATruckWithoutLogs(): void
    {
        $A = Seeds::defaults();
        $state = (new IdentityCalibration())->state('org-1', ['id' => 't1'], $A, '2026-10-04');

        self::assertSame(Estimator::calibrate($A, [], '2026-10-04'), $state);
        self::assertSame('tps-0.1.0', $state['model_version']);
        self::assertSame($A['seeds_revision'], $state['seeds_revision']);
        self::assertSame('2026-10-04', $state['as_of']);
        self::assertSame(1.0, $state['truck_factor']);
        self::assertSame(0.0, $state['truck_log_factor']);
        self::assertSame(0, $state['truck_n']);
        self::assertSame([], $state['spots']);
        self::assertNull($state['resid_sd']);
    }

    public function testItChangesNoEstimate(): void
    {
        $state = (new IdentityCalibration())->state('org-1', ['id' => 't1'], Seeds::defaults(), '2026-10-04');
        $factor = Estimator::calibrationFactor($state, 'any-spot');
        $none = Estimator::calibrationFactor(null, 'any-spot');
        self::assertSame($none, $factor);
    }
}
