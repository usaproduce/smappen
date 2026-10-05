<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Services\ExportService;
use App\TruckPlanner\Services\PlanningService;
use App\TruckPlanner\Services\ScoutingService;
use App\TruckPlanner\Services\SimulateService;
use App\TruckPlanner\Services\SourcesService;
use App\TruckPlanner\Services\Support\TpConfig;
use PHPUnit\Framework\TestCase;

/**
 * Rules that two packages each keep a copy of, held to one meaning.
 *
 * The packages were written side by side and call one another through the five contracts only, so a few
 * rules exist twice. Each copy has the tests of its own package; this file holds the copies to each other,
 * so that changing one without the other fails here.
 *
 *   - the state of a plan's stored result (04_BACKEND.md 5.8): PlanningService for the API, ExportService
 *     for the export
 *   - the source lines of 03_DATA.md section 14: SourcesService holds all twelve (and is tested against
 *     the document); SimulateService and ScoutingService answer some of them, and the drive-time line is
 *     a setting
 */
final class CrossPackageParityTest extends TestCase
{
    private const AT = '2026-10-05 12:00:00';
    private const BEFORE = '2026-10-05 11:59:59';
    private const AFTER = '2026-10-05 12:00:01';

    public function testTheApiAndTheExportJudgeAStoredResultAlike(): void
    {
        $planning = new \ReflectionMethod(PlanningService::class, 'stateOf');
        $planning->setAccessible(true);
        $export = new \ReflectionMethod(ExportService::class, 'resultState');
        $export->setAccessible(true);

        $versions = [
            'the present ones' => ['tps-0.1.0', 1, 'dc-20261003-d0514a63'],
            'another model' => ['tps-0.0.9', 1, 'dc-20261003-d0514a63'],
            'other seeds' => ['tps-0.1.0', 2, 'dc-20261003-d0514a63'],
            'another dataset' => ['tps-0.1.0', 1, 'dc-20250101-00000000'],
            'no dataset then' => ['tps-0.1.0', 1, null],
        ];
        $times = ['before' => self::BEFORE, 'the same second' => self::AT, 'after' => self::AFTER];
        $seen = [];
        $cases = 0;
        foreach ([null, self::AT] as $evaluatedAt) {
            foreach ([false, true] as $hasResult) {
                foreach ([false, true] as $expired) {
                    foreach ($versions as $versionName => [$model, $seeds, $dataset]) {
                        foreach ($times as $truckName => $truckChanged) {
                            foreach ([null] + $times as $logName => $logChanged) {
                                foreach ([null] + $times as $spotName => $spotChanged) {
                                    $apiState = $planning->invoke(
                                        null,
                                        [
                                            'evaluated_at' => $evaluatedAt, 'has_snapshot' => $hasResult, 'snapshot_expired' => $expired,
                                            'model_version' => $model, 'seeds_revision' => $seeds, 'dataset_version' => $dataset,
                                            'spots_changed_at' => $spotChanged,
                                        ],
                                        ['updated_at' => $truckChanged],
                                        ['model_version' => 'tps-0.1.0', 'seeds_revision' => 1],
                                        'dc-20261003-d0514a63',
                                        $logChanged
                                    );
                                    $exportState = $export->invoke(
                                        null,
                                        [
                                            'evaluated_at' => $evaluatedAt, 'has_result' => $hasResult, 'snapshot_expired' => $expired,
                                            'model_version' => $model, 'seeds_revision' => $seeds, 'dataset_version' => $dataset,
                                        ],
                                        [['spot_id' => 'spot-1'], ['spot_id' => null], ['spot_id' => 'a-spot-that-is-gone']],
                                        [
                                            'model_version' => 'tps-0.1.0', 'seeds_revision' => 1, 'dataset_version' => 'dc-20261003-d0514a63',
                                            'truck_changed' => $truckChanged, 'log_changed' => $logChanged,
                                        ],
                                        $spotChanged === null ? [] : ['spot-1' => ['lat' => 38.96, 'lng' => -77.36, 'updated_at' => $spotChanged]]
                                    );
                                    self::assertSame(
                                        $apiState,
                                        $exportState,
                                        sprintf(
                                            'evaluated %s, result %s, expired %s, versions: %s, truck changed %s, a log %s, a spot %s',
                                            $evaluatedAt ?? 'never',
                                            $hasResult ? 'there' : 'missing',
                                            $expired ? 'yes' : 'no',
                                            $versionName,
                                            $truckName,
                                            is_string($logName) ? $logName : 'none',
                                            is_string($spotName) ? $spotName : 'none'
                                        )
                                    );
                                    $seen[$apiState] = true;
                                    $cases++;
                                }
                            }
                        }
                    }
                }
            }
        }
        self::assertSame(1920, $cases);
        ksort($seen);
        self::assertSame(['expired', 'fresh', 'none', 'stale'], array_keys($seen), 'the cases reach every state');

        // the two ends of the rule, spelled out once
        $state = static fn (string $truck, ?string $log, ?string $spot): string => $export->invoke(
            null,
            ['evaluated_at' => self::AT, 'has_result' => true, 'snapshot_expired' => false, 'model_version' => 'tps-0.1.0', 'seeds_revision' => 1, 'dataset_version' => 'dc'],
            [['spot_id' => 'spot-1']],
            ['model_version' => 'tps-0.1.0', 'seeds_revision' => 1, 'dataset_version' => 'dc', 'truck_changed' => $truck, 'log_changed' => $log],
            $spot === null ? [] : ['spot-1' => ['lat' => 0.0, 'lng' => 0.0, 'updated_at' => $spot]]
        );
        self::assertSame('fresh', $state(self::AT, self::AT, self::AT), 'a change in the second of the evaluation is part of it');
        self::assertSame('stale', $state(self::AFTER, null, null), 'the truck row is what a deleted service or a changed correction stamps');
        self::assertSame('stale', $state(self::BEFORE, self::AFTER, null));
        self::assertSame('stale', $state(self::BEFORE, null, self::AFTER));
    }

    public function testASourceLineIsOneTextWhereverItIsKept(): void
    {
        $constant = static function (string $class, string $name): string {
            return (string) (new \ReflectionClassConstant($class, $name))->getValue();
        };
        self::assertSame(SourcesService::TEXTS[1], $constant(SimulateService::class, 'ATTRIBUTION_PLACES'));
        self::assertSame(SourcesService::TEXTS[3], $constant(SimulateService::class, 'ATTRIBUTION_RESIDENTS'));
        self::assertSame(SourcesService::TEXTS[4], $constant(SimulateService::class, 'ATTRIBUTION_JOBS'));
        self::assertSame(SourcesService::TEXTS[1], $constant(ScoutingService::class, 'ATTRIBUTION_PLACES'));
        self::assertSame(SourcesService::TEXTS[2], $constant(ScoutingService::class, 'ATTRIBUTION_PLACES_SENTENCE'));
        self::assertSame(SourcesService::TEXTS[9], TpConfig::get('routing.attribution'));
        self::assertSame('National Weather Service (weather.gov)', TpConfig::get('weather.source'));
        self::assertStringContainsString((string) TpConfig::get('weather.source'), SourcesService::TEXTS[6]);
    }
}
