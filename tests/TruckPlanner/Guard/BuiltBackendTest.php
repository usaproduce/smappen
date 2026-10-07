<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Guard;

use App\TruckPlanner\Services\CalibrationService;
use App\TruckPlanner\Services\CaptureService;
use App\TruckPlanner\Services\DayContextService;
use App\TruckPlanner\Services\FuelPriceService;
use App\TruckPlanner\Services\RoutingService;
use App\TruckPlanner\Services\Support\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The backend is whole (04_BACKEND.md section 9): the eight packages have landed, so nothing may fall back
 * to what stood in for a neighbour that was not there yet.
 *
 *   - no action of a Truck controller is still the 501 stub of the foundation package
 *   - each of the five contracts resolves to its real service, not to its fallback
 *   - the smoke test no longer takes a 501 for an answer
 *
 * A guard looks at code, never at what a comment says about code (SourceScan drops comments).
 */
final class BuiltBackendTest extends TestCase
{
    protected function setUp(): void
    {
        Registry::reset();
    }

    protected function tearDown(): void
    {
        Registry::reset();
    }

    public function testNoControllerActionIsStillAStub(): void
    {
        $controllers = array_values(array_diff(SourceScan::controllers(), ['src/Controllers/TruckBaseController.php']));
        self::assertCount(14, $controllers, 'the fourteen controllers of the route table');
        foreach ($controllers as $file) {
            $code = SourceScan::code($file);
            self::assertDoesNotMatchRegularExpression('/->\s*stub\s*\(/', $code, $file . ' still answers 501 somewhere');
            self::assertStringNotContainsString('501', $code, $file);
            self::assertStringNotContainsString('Not implemented yet', $code, $file);
        }
    }

    public function testEveryContractResolvesToItsRealService(): void
    {
        self::assertInstanceOf(CaptureService::class, Registry::capture());
        self::assertInstanceOf(RoutingService::class, Registry::legs());
        self::assertInstanceOf(DayContextService::class, Registry::dayContexts());
        self::assertInstanceOf(FuelPriceService::class, Registry::fuel());
        self::assertInstanceOf(CalibrationService::class, Registry::calibration());
    }

    public function testTheSmokeTestTakesNo501ForAnAnswer(): void
    {
        $files = array_merge(['scripts/truck/smoke.php'], SourceScan::phpFilesUnder('scripts/truck/smoke'));
        self::assertGreaterThanOrEqual(10, count($files), 'the runner, the client and one step per package');
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('/allow\s*\([^)]*\b501\b/', SourceScan::code($file), $file . ' still allows a 501');
        }
    }
}
