<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\FuelPriceProvider;
use App\TruckPlanner\Services\Fallback\PlainDayContexts;
use App\TruckPlanner\Services\Support\Registry;
use PHPUnit\Framework\TestCase;

final class PlainDayContextsTest extends TestCase
{
    private const TRUCK = [
        'id' => 't1',
        'timezone' => 'America/New_York',
        'base_state' => 'VA',
        'profile' => ['region_id' => 'none', 'fuel_type' => 'gasoline', 'fuel_price_override' => null, 'base' => ['lat' => 39.003, 'lng' => -77.405, 'address' => 'Sterling, VA']],
    ];

    protected function setUp(): void
    {
        Registry::reset();
        Registry::set('fuel', new class implements FuelPriceProvider {
            public function resolve(array $truck): array
            {
                return ['price_per_gal' => 4.195, 'source' => 'eia', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28'];
            }
        });
    }

    protected function tearDown(): void
    {
        Registry::reset();
    }

    public function testOneDayInfoPerDateFromTheFirst(): void
    {
        $A = Seeds::defaults();
        $answer = (new PlainDayContexts())->contexts(self::TRUCK, $A, '2026-10-08', 3);

        self::assertSame(['days', 'forecast', 'fuel'], array_keys($answer));
        self::assertSame(['2026-10-08', '2026-10-09', '2026-10-10'], array_column($answer['days'], 'date'));
        foreach ($answer['days'] as $day) {
            self::assertSame(['date', 'holiday', 'context'], array_keys($day));
            self::assertSame($day['date'], $day['context']['date']);
            self::assertNull($day['context']['forecast']);
            self::assertNull($day['context']['treat_as']);
            self::assertSame(4.195, $day['context']['fuel_price_per_gal']);
            self::assertSame('eia', $day['context']['fuel_price_source']);
            self::assertSame($day['context']['holiday'], $day['holiday']);
        }
        self::assertSame(
            Estimator::dayContext($A, '2026-10-08', null, null, 4.195, 'eia'),
            $answer['days'][0]['context']
        );
    }

    public function testThereIsNoForecast(): void
    {
        $answer = (new PlainDayContexts())->contexts(self::TRUCK, Seeds::defaults(), '2026-10-08', 1);
        self::assertSame(
            [
                'state' => 'unavailable',
                'generated_at' => null,
                'point' => ['lat' => 39.003, 'lng' => -77.405],
                'source' => 'National Weather Service (weather.gov)',
            ],
            $answer['forecast']
        );
        self::assertSame('eia', $answer['fuel']['source']);
    }

    public function testHolidaysAreKnownWithoutAnyUpstream(): void
    {
        // Columbus Day 2026 is Monday 12 October; the run crosses a month end as well.
        $answer = (new PlainDayContexts())->contexts(self::TRUCK, Seeds::defaults(), '2026-10-11', 3);
        self::assertNull($answer['days'][0]['holiday']);
        self::assertSame('columbus', $answer['days'][1]['holiday']['id']);
        self::assertSame('minor', $answer['days'][1]['context']['holiday_class']);
        self::assertNull($answer['days'][2]['holiday']);

        $christmas = (new PlainDayContexts())->contexts(self::TRUCK, Seeds::defaults(), '2026-12-31', 2);
        self::assertSame(['2026-12-31', '2027-01-01'], array_column($christmas['days'], 'date'));
        self::assertSame('new_year', $christmas['days'][1]['holiday']['id']);
    }

    public function testTreatAsAppliesToItsOwnDateOnly(): void
    {
        $answer = (new PlainDayContexts())->contexts(self::TRUCK, Seeds::defaults(), '2026-10-08', 2, ['2026-10-08' => 'sat']);
        self::assertSame('sat', $answer['days'][0]['context']['treat_as']);
        self::assertSame(5, $answer['days'][0]['context']['eff_dow']);
        self::assertNull($answer['days'][1]['context']['treat_as']);
        self::assertSame(4, $answer['days'][1]['context']['eff_dow']);
    }

    public function testTheRegionFlagsOfTheAssumptionsDecideInaugurationDay(): void
    {
        // 20 January 2033 is a Thursday in an inauguration year.
        $A = Seeds::defaults();
        $plain = (new PlainDayContexts())->contexts(self::TRUCK, $A, '2033-01-20', 1);
        self::assertNull($plain['days'][0]['holiday']);
        $A['region'] = ['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => true]];
        $dc = (new PlainDayContexts())->contexts(self::TRUCK, $A, '2033-01-20', 1);
        self::assertSame('inauguration', $dc['days'][0]['holiday']['id']);
    }

    public function testNoDaysNoContexts(): void
    {
        $answer = (new PlainDayContexts())->contexts(self::TRUCK, Seeds::defaults(), '2026-10-08', 0);
        self::assertSame([], $answer['days']);
    }
}
