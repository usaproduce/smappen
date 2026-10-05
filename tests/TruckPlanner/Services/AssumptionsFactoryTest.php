<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\LogCapture;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\AssumptionsFactory;
use PHPUnit\Framework\TestCase;

final class AssumptionsFactoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function region(array $config, string $id = 'dc'): array
    {
        return ['region_id' => $id, 'name' => 'Washington, DC region', 'timezone' => 'America/New_York', 'config' => $config];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function truck(array $overrides, ?int $revision = null): array
    {
        return [
            'id' => 't1',
            'overrides' => $overrides,
            'overrides_seeds_rev' => $revision ?? Seeds::defaults()['seeds_revision'],
            'profile' => ['region_id' => 'dc'],
        ];
    }

    public function testWithoutATruckItIsTheSeedFileForRegionNone(): void
    {
        $A = (new AssumptionsFactory())->forTruck(null, null);
        self::assertSame(Seeds::defaults(), $A);
        self::assertSame(['model_version', 'seeds_revision', 'seeds', 'overrides', 'region'], array_keys($A));
        self::assertSame([], $A['overrides']);
        self::assertSame(['id' => 'none', 'traffic_matrix' => 'us_mean', 'flags' => ['inauguration_day' => false]], $A['region']);
    }

    public function testTheRegionBlockComesFromTheRegionDefinition(): void
    {
        $A = (new AssumptionsFactory())->forTruck(null, self::region(['traffic_matrix' => 'dc', 'holidays' => ['inauguration_day' => true]]));
        self::assertSame(['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => true]], $A['region']);
    }

    public function testARegionWithoutItsOwnMatrixUsesTheNationalMean(): void
    {
        $factory = new AssumptionsFactory();
        // The matrix is never derived from the region id: "dc" without the key is us_mean.
        $A = $factory->forTruck(null, self::region(['holidays' => ['inauguration_day' => false]]));
        self::assertSame(['id' => 'dc', 'traffic_matrix' => 'us_mean', 'flags' => ['inauguration_day' => false]], $A['region']);
        // A matrix the seed file does not hold would crash the model: it falls back too.
        $A = $factory->forTruck(null, self::region(['traffic_matrix' => 'atlanta'], 'atl'));
        self::assertSame('us_mean', $A['region']['traffic_matrix']);
        self::assertSame('atl', $A['region']['id']);
        // No holidays block: the flag is false. Only a JSON true turns it on.
        self::assertFalse($factory->forTruck(null, self::region([]))['region']['flags']['inauguration_day']);
        self::assertFalse($factory->forTruck(null, self::region(['holidays' => ['inauguration_day' => 1]]))['region']['flags']['inauguration_day']);
    }

    public function testTheModelReadsTheRegionBlock(): void
    {
        $A = (new AssumptionsFactory())->forTruck(null, self::region(['traffic_matrix' => 'dc', 'holidays' => ['inauguration_day' => true]]));
        self::assertSame('inauguration', Estimator::holidayOn('2033-01-20', $A['region']['flags'])['id']);
        $ctx = Estimator::dayContext($A, '2026-10-08', null, null, 4.195, 'seed');
        self::assertSame(1.72, Estimator::trafficFactor($A, $ctx, 17 * 60)[0]);
    }

    public function testTheOwnersOverridesBecomePartOfA(): void
    {
        $A = (new AssumptionsFactory())->forTruck(self::truck(['host.captive_share' => 0.6, 'weather.floor' => 0.30000000000000004]), null);
        self::assertSame(['host.captive_share' => 0.6, 'weather.floor' => 0.30000000000000004], $A['overrides']);
        self::assertSame(0.6, Estimator::seed($A, 'host.captive_share'));
        self::assertSame(0.75, Estimator::seed(Seeds::defaults(), 'host.captive_share'));
        self::assertSame([], Estimator::validateOverrides($A['seeds'], $A['overrides']));
    }

    public function testNumbersTakeTheTypeOfTheSeedTheyReplace(): void
    {
        // A JSON column gives back 1 for 1.0. The model is given floats where the seed is a float.
        $stored = [
            'host.onsite_kitchen_weight' => 2,
            'segments.w_office.dow_factor' => [1, 1.19, 1.16, 1, 0],
            'segments.res.holiday_day_type.major' => 'saturday',
        ];
        $A = (new AssumptionsFactory())->forTruck(self::truck($stored), null);
        self::assertSame(2.0, $A['overrides']['host.onsite_kitchen_weight']);
        self::assertSame([1.0, 1.19, 1.16, 1.0, 0.0], $A['overrides']['segments.w_office.dow_factor']);
        self::assertSame('saturday', $A['overrides']['segments.res.holiday_day_type.major']);
    }

    public function testOverridesSavedUnderTheCurrentRevisionAreTrusted(): void
    {
        $stored = ['host.captive_share' => 0.6, 'no.such.path' => 1];
        $A = null;
        $lines = LogCapture::during(static function () use ($stored, &$A): void {
            $A = (new AssumptionsFactory())->forTruck(self::truck($stored), null);
        });
        self::assertSame([], $lines);
        self::assertSame($stored, $A['overrides']);
    }

    public function testOverridesSavedUnderAnotherRevisionAreValidatedAgain(): void
    {
        $current = (int) Seeds::defaults()['seeds_revision'];
        $stored = [
            'host.captive_share' => 0.6,                       // still valid
            'no.such.path' => 1,                               // the seed is gone
            'weather.floor' => 5,                              // out of the seed's bounds now
            'segments.w_office.dow_factor' => [1, 1, 1],       // the seed has five values
            'kernel.outside_option_a0' => 2.0,                 // not the owner's to change
        ];
        $A = null;
        $lines = LogCapture::during(static function () use ($stored, $current, &$A): void {
            $A = (new AssumptionsFactory())->forTruck(self::truck($stored, $current + 1), null);
        });
        self::assertSame(['host.captive_share' => 0.6], $A['overrides']);
        self::assertSame(['[tp] 4 stored overrides do not fit seeds revision ' . $current . ' and were left out'], $lines);
        self::assertSame([], Estimator::validateOverrides($A['seeds'], $A['overrides']));
    }

    public function testATruckWithoutOverrides(): void
    {
        $factory = new AssumptionsFactory();
        self::assertSame([], $factory->forTruck(self::truck([]), null)['overrides']);
        self::assertSame([], $factory->forTruck(['id' => 't1', 'profile' => ['region_id' => 'none']], null)['overrides']);
    }

    public function testInfoIsAWithoutTheSeedFile(): void
    {
        $factory = new AssumptionsFactory();
        $A = $factory->forTruck(
            self::truck(['host.captive_share' => 0.6]),
            self::region(['traffic_matrix' => 'dc', 'holidays' => ['inauguration_day' => true]])
        );
        self::assertSame(
            [
                'model_version' => 'tps-0.1.0',
                'seeds_revision' => Seeds::defaults()['seeds_revision'],
                'overrides' => ['host.captive_share' => 0.6],
                'region' => ['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => true]],
            ],
            $factory->info($A)
        );
        $none = $factory->info($factory->forTruck(null, null));
        self::assertSame(['model_version', 'seeds_revision', 'overrides', 'region'], array_keys($none));
        self::assertSame([], $none['overrides']);
        self::assertSame(['id' => 'none', 'traffic_matrix' => 'us_mean', 'flags' => ['inauguration_day' => false]], $none['region']);
    }
}
