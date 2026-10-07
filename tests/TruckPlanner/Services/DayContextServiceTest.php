<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FakeHttp;
use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\MemoryCache;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\DayContextProvider;
use App\TruckPlanner\Services\Contracts\FuelPriceProvider;
use App\TruckPlanner\Services\DayContextService;
use App\TruckPlanner\Services\FuelPriceService;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpInvalid;
use App\TruckPlanner\Services\Upstream\WeatherClient;
use PHPUnit\Framework\TestCase;

/**
 * Day contexts: the holiday of each date, the forecast hours as the wall clock of the place counts them,
 * and the fuel price, handed to the model's day_context unchanged.
 */
final class DayContextServiceTest extends TestCase
{
    private const FUEL = ['price_per_gal' => 4.195, 'source' => 'eia', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28'];

    /** Every observed federal holiday of 2026 to 2029 with the Washington region's flag on (02_MODEL.md 4.1). */
    private const OBSERVED = [
        '2026-01-01' => 'new_year', '2026-01-19' => 'mlk', '2026-02-16' => 'washington', '2026-05-25' => 'memorial',
        '2026-06-19' => 'juneteenth', '2026-07-03' => 'independence', '2026-09-07' => 'labor', '2026-10-12' => 'columbus',
        '2026-11-11' => 'veterans', '2026-11-26' => 'thanksgiving', '2026-12-25' => 'christmas',
        '2027-01-01' => 'new_year', '2027-01-18' => 'mlk', '2027-02-15' => 'washington', '2027-05-31' => 'memorial',
        '2027-06-18' => 'juneteenth', '2027-07-05' => 'independence', '2027-09-06' => 'labor', '2027-10-11' => 'columbus',
        '2027-11-11' => 'veterans', '2027-11-25' => 'thanksgiving', '2027-12-24' => 'christmas', '2027-12-31' => 'new_year',
        '2028-01-17' => 'mlk', '2028-02-21' => 'washington', '2028-05-29' => 'memorial', '2028-06-19' => 'juneteenth',
        '2028-07-04' => 'independence', '2028-09-04' => 'labor', '2028-10-09' => 'columbus', '2028-11-10' => 'veterans',
        '2028-11-23' => 'thanksgiving', '2028-12-25' => 'christmas',
        '2029-01-01' => 'new_year', '2029-01-15' => 'mlk', '2029-02-19' => 'washington', '2029-05-28' => 'memorial',
        '2029-06-19' => 'juneteenth', '2029-07-04' => 'independence', '2029-09-03' => 'labor', '2029-10-08' => 'columbus',
        '2029-11-12' => 'veterans', '2029-11-22' => 'thanksgiving', '2029-12-25' => 'christmas',
    ];

    /** The rows of that table whose observed date differs from the actual one: [id, actual, observed]. */
    private const MOVED = [
        ['independence', '2026-07-04', '2026-07-03'], ['juneteenth', '2027-06-19', '2027-06-18'],
        ['independence', '2027-07-04', '2027-07-05'], ['christmas', '2027-12-25', '2027-12-24'],
        ['new_year', '2028-01-01', '2027-12-31'], ['veterans', '2028-11-11', '2028-11-10'],
        ['veterans', '2029-11-11', '2029-11-12'],
    ];

    private FixedClock $clock;

    /** @var array<string, string|false> */
    private array $envBefore = [];

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-10-05 09:36:06');
        Registry::reset();
        Registry::set('fuel', new class implements FuelPriceProvider {
            public function resolve(array $truck): array
            {
                return ['price_per_gal' => 4.195, 'source' => 'eia', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28'];
            }
        });
        foreach (['TP_CONTACT_EMAIL', 'MAIL_FROM'] as $name) {
            $this->envBefore[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        Registry::reset();
        TpCache::wire();
        foreach ($this->envBefore as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function truck(string $zone = 'America/New_York'): array
    {
        return [
            'id' => 't1',
            'timezone' => $zone,
            'base_state' => 'VA',
            'profile' => ['region_id' => 'dc', 'fuel_type' => 'gasoline', 'fuel_price_override' => null,
                'base' => ['lat' => 39.003, 'lng' => -77.405, 'address' => 'Sterling, VA']],
        ];
    }

    /**
     * @return array<string, mixed> the Assumptions of a truck in the Washington region
     */
    private static function dc(): array
    {
        $A = Seeds::defaults();
        $A['region'] = ['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => true]];
        return $A;
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed> a period as WeatherClient::hourly() hands it over
     */
    private static function period(string $start, array $over = []): array
    {
        return $over + [
            'startTime' => $start, 'temperature' => 58, 'temperatureUnit' => 'F',
            'probabilityOfPrecipitation' => 20, 'windSpeed' => '5 mph', 'shortForecast' => 'Partly Cloudy',
        ];
    }

    /**
     * @param list<array<string, mixed>> $periods
     */
    private function service(array $periods = [], string $state = 'fresh', ?string $generatedAt = '2026-10-05T08:58:32+00:00'): DayContextService
    {
        $this->weather = new StubWeather(['state' => $state, 'generated_at' => $generatedAt, 'periods' => $periods]);
        return new DayContextService($this->weather, $this->clock);
    }

    private StubWeather $weather;

    // ------------------------------------------------------------------------------------ contexts

    public function testItIsADayContextProviderThatNeedsNoArguments(): void
    {
        self::assertInstanceOf(DayContextProvider::class, new DayContextService());
    }

    public function testOneDayInfoPerDateBuiltByTheModelFromTheForecastAndTheFuelPrice(): void
    {
        $periods = [];
        for ($hour = 4; $hour < 24; $hour++) {
            $periods[] = self::period(sprintf('2026-10-05T%02d:00:00-04:00', $hour), ['temperature' => 40 + $hour]);
        }
        for ($hour = 0; $hour < 24; $hour++) {
            $periods[] = self::period(sprintf('2026-10-06T%02d:00:00-04:00', $hour), ['temperature' => 50 + $hour]);
        }
        $A = self::dc();
        $answer = $this->service($periods)->contexts(self::truck(), $A, '2026-10-05', 3);

        self::assertSame(['days', 'forecast', 'fuel'], array_keys($answer));
        self::assertSame(['2026-10-05', '2026-10-06', '2026-10-07'], array_column($answer['days'], 'date'));
        foreach ($answer['days'] as $day) {
            self::assertSame(['date', 'holiday', 'context'], array_keys($day));
            self::assertSame($day['context']['holiday'], $day['holiday']);
            self::assertSame(4.195, $day['context']['fuel_price_per_gal']);
            self::assertSame('eia', $day['context']['fuel_price_source']);
            self::assertNull($day['context']['treat_as']);
        }

        // the first date: the hours before the forecast starts are null, the others are HourForecast records
        $first = $answer['days'][0]['context']['forecast'];
        self::assertCount(24, $first);
        self::assertSame([null, null, null, null], array_slice($first, 0, 4));
        self::assertSame(
            ['hour' => 4, 'temp_f' => 44.0, 'precip_prob' => 20.0, 'short_forecast' => 'Partly Cloudy', 'wind_mph' => 5.0],
            $first[4]
        );
        self::assertSame(63.0, $first[23]['temp_f']);
        // the second date is complete; the third is beyond the forecast: null, not 24 nulls
        self::assertCount(24, array_filter($answer['days'][1]['context']['forecast'], static fn (?array $h): bool => $h !== null));
        self::assertNull($answer['days'][2]['context']['forecast']);

        // and each context is exactly the model's
        foreach ($answer['days'] as $day) {
            self::assertSame(
                Estimator::dayContext($A, $day['date'], null, $day['context']['forecast'], 4.195, 'eia'),
                $day['context']
            );
        }
        self::assertSame(self::FUEL, $answer['fuel']);
    }

    public function testTheForecastIsForTheBasePointAndIsAskedOnceWhateverTheNumberOfDays(): void
    {
        $answer = $this->service()->contexts(self::truck(), self::dc(), '2026-10-05', 14);
        self::assertCount(14, $answer['days']);
        self::assertSame([[39.003, -77.405]], $this->weather->asked);
        self::assertSame(
            [
                'state' => 'fresh',
                'generated_at' => '2026-10-05T08:58:32+00:00',
                'point' => ['lat' => 39.003, 'lng' => -77.405],
                'source' => 'National Weather Service (weather.gov)',
            ],
            $answer['forecast']
        );
    }

    public function testAStaleAndAMissingForecastAreSaidSoAndTheContextsStillCome(): void
    {
        $stale = $this->service([self::period('2026-10-05T12:00:00-04:00')], 'stale', '2026-10-05T03:00:00+00:00')
            ->contexts(self::truck(), self::dc(), '2026-10-05', 1);
        self::assertSame('stale', $stale['forecast']['state']);
        self::assertSame('2026-10-05T03:00:00+00:00', $stale['forecast']['generated_at']);
        self::assertSame(58.0, $stale['days'][0]['context']['forecast'][12]['temp_f']);

        $none = $this->service([], 'unavailable', null)->contexts(self::truck(), self::dc(), '2026-10-12', 2);
        self::assertSame('unavailable', $none['forecast']['state']);
        self::assertNull($none['forecast']['generated_at']);
        self::assertCount(2, $none['days']);
        self::assertNull($none['days'][0]['context']['forecast']);
        // the holiday and the fuel price need no upstream
        self::assertSame('columbus', $none['days'][0]['holiday']['id']);
        self::assertSame(4.195, $none['days'][1]['context']['fuel_price_per_gal']);
    }

    public function testNoDaysNoContexts(): void
    {
        self::assertSame([], $this->service()->contexts(self::truck(), self::dc(), '2026-10-05', 0)['days']);
    }

    public function testTreatAsBelongsToItsOwnDate(): void
    {
        $answer = $this->service()->contexts(self::truck(), self::dc(), '2026-10-08', 2, ['2026-10-08' => 'sat', '2026-10-10' => 'holiday']);
        self::assertSame('sat', $answer['days'][0]['context']['treat_as']);
        self::assertSame(5, $answer['days'][0]['context']['eff_dow']);
        self::assertNull($answer['days'][1]['context']['treat_as']);
        self::assertSame(4, $answer['days'][1]['context']['eff_dow']);
    }

    // ------------------------------------------------------------------------------------ hours

    public function testOnTheDayClocksGoBackTheFirstOfTheTwoOneOClocksWins(): void
    {
        // 1 November 2026 in New York has 25 hours: 01:00 comes twice, first at -04:00, then at -05:00.
        $periods = [];
        $labels = ['00:00:00-04:00', '01:00:00-04:00', '01:00:00-05:00'];
        for ($hour = 2; $hour < 24; $hour++) {
            $labels[] = sprintf('%02d:00:00-05:00', $hour);
        }
        self::assertCount(25, $labels);
        foreach ($labels as $i => $label) {
            $periods[] = self::period('2026-11-01T' . $label, ['temperature' => 100 + $i]);
        }
        $hours = DayContextService::hoursByDate($periods)['2026-11-01'];
        self::assertCount(24, $hours);
        self::assertSame(100.0, $hours[0]['temp_f']);
        self::assertSame(101.0, $hours[1]['temp_f'], 'the first 01:00, in summer time');
        self::assertSame(103.0, $hours[2]['temp_f'], 'the second 01:00 (102) is passed over');
        self::assertSame(124.0, $hours[23]['temp_f']);
        foreach ($hours as $hour => $record) {
            self::assertSame($hour, $record['hour']);
        }
        // and through contexts(): every civil date has hours 0 to 23
        $context = $this->service($periods)->contexts(self::truck(), self::dc(), '2026-11-01', 1)['days'][0]['context'];
        self::assertSame($hours, $context['forecast']);
    }

    public function testOnTheDayClocksGoForwardTheSkippedHourStaysNull(): void
    {
        // 14 March 2027 in New York has 23 hours: 02:00 does not exist.
        $periods = [
            self::period('2027-03-14T00:00:00-05:00', ['temperature' => 30]),
            self::period('2027-03-14T01:00:00-05:00', ['temperature' => 31]),
        ];
        for ($hour = 3; $hour < 24; $hour++) {
            $periods[] = self::period(sprintf('2027-03-14T%02d:00:00-04:00', $hour), ['temperature' => 30 + $hour]);
        }
        self::assertCount(23, $periods);
        $hours = DayContextService::hoursByDate($periods)['2027-03-14'];
        self::assertCount(24, $hours);
        self::assertNull($hours[2]);
        self::assertSame(31.0, $hours[1]['temp_f']);
        self::assertSame(33.0, $hours[3]['temp_f']);
        self::assertSame(53.0, $hours[23]['temp_f']);
        self::assertCount(23, array_filter($hours, static fn (?array $h): bool => $h !== null));
    }

    public function testTheHourIsTheOneWrittenInTheStartTimeWhateverZoneThisProcessRunsIn(): void
    {
        // The same instant written for three places: each belongs to the date and hour on its own wall clock.
        $byDate = DayContextService::hoursByDate([
            self::period('2026-10-05T23:00:00-04:00', ['temperature' => 1]),     // New York
            self::period('2026-10-06T03:00:00+00:00', ['temperature' => 2]),     // the same instant in UTC
            self::period('2026-10-06T17:00:00+14:00', ['temperature' => 3]),     // and on Kiritimati
        ]);
        self::assertSame(['2026-10-05', '2026-10-06'], array_keys($byDate));
        self::assertSame(1.0, $byDate['2026-10-05'][23]['temp_f']);
        self::assertSame(2.0, $byDate['2026-10-06'][3]['temp_f']);
        self::assertSame(3.0, $byDate['2026-10-06'][17]['temp_f']);
    }

    public function testAStartTimeOfAnotherFormIsPassedOver(): void
    {
        $good = self::period('2026-10-05T12:00:00-04:00');
        $byDate = DayContextService::hoursByDate([
            self::period('2026-10-05 13:00:00'),
            self::period('05/10/2026T14:00:00'),
            self::period('2026-10-05T24:00:00-04:00'),
            self::period('2026-10-05T7:00:00-04:00'),
            self::period(''),
            ['temperature' => 60],
            ['startTime' => 20261005] + self::period('x'),
            $good,
        ]);
        self::assertSame(['2026-10-05'], array_keys($byDate));
        self::assertSame([12], array_keys(array_filter($byDate['2026-10-05'], static fn (?array $h): bool => $h !== null)));
    }

    public function testAProbabilityOfPrecipitationThatIsMissingStaysNullAndIsNeverZero(): void
    {
        $record = DayContextService::hourForecast(9, self::period('2026-10-05T09:00:00-04:00', [
            'temperature' => 50, 'probabilityOfPrecipitation' => null, 'shortForecast' => 'Light Rain Likely', 'windSpeed' => null,
        ]));
        self::assertSame(['hour' => 9, 'temp_f' => 50.0, 'precip_prob' => null, 'short_forecast' => 'Light Rain Likely', 'wind_mph' => null], $record);
        // which is what lets the model read the text instead: the row "50, null, Light Rain Likely" of 02_MODEL.md 4.6
        $detail = Estimator::weatherMultiplier(Seeds::defaults(), $record, 'open');
        self::assertSame(0.5, $detail['precip_p']);
        self::assertEqualsWithDelta(0.81, $detail['multiplier'], 1e-12);

        // a real zero stays a zero, and a percentage with a fraction keeps it
        self::assertSame(0.0, DayContextService::hourForecast(0, self::period('x', ['probabilityOfPrecipitation' => 0]))['precip_prob']);
        self::assertSame(35.5, DayContextService::hourForecast(0, self::period('x', ['probabilityOfPrecipitation' => 35.5]))['precip_prob']);
        self::assertNull(DayContextService::hourForecast(0, self::period('x', ['probabilityOfPrecipitation' => '40']))['precip_prob']);
        self::assertNull(DayContextService::hourForecast(0, ['startTime' => 'x'])['precip_prob']);
    }

    public function testWindIsTheLargestNumberInTheText(): void
    {
        foreach ([
            '2 mph' => 2.0, '5 to 10 mph' => 10.0, '10 to 5 mph' => 10.0, '0 mph' => 0.0, '15 mph with gusts to 35 mph' => 35.0,
            '7' => 7.0, '12mph' => 12.0,
        ] as $text => $mph) {
            self::assertSame($mph, DayContextService::windMph((string) $text), (string) $text);
        }
        foreach (['', 'Calm', 'light and variable', null, 10, 10.5, ['5 mph']] as $none) {
            self::assertNull(DayContextService::windMph($none), var_export($none, true));
        }
    }

    public function testTemperatureIsFahrenheitAndCelsiusIsConverted(): void
    {
        $temp = static fn (array $over): ?float => DayContextService::hourForecast(0, self::period('x', $over))['temp_f'];
        self::assertSame(58.0, $temp(['temperature' => 58, 'temperatureUnit' => 'F']));
        self::assertSame(58.5, $temp(['temperature' => 58.5]));
        self::assertSame(50.0, $temp(['temperature' => 10, 'temperatureUnit' => 'C']));
        self::assertSame(-40.0, $temp(['temperature' => -40, 'temperatureUnit' => 'C']));
        self::assertSame(32.0, $temp(['temperature' => 0, 'temperatureUnit' => 'C']));
        self::assertSame(58.0, $temp(['temperature' => 58, 'temperatureUnit' => null]));
        self::assertNull($temp(['temperature' => null]));
        self::assertNull($temp(['temperature' => '58']));
    }

    public function testATextThatIsNotValidUtf8IsNoForecastText(): void
    {
        self::assertNull(DayContextService::hourForecast(0, self::period('x', ['shortForecast' => "Sunny \xC3\x28"]))['short_forecast']);
        self::assertNull(DayContextService::hourForecast(0, self::period('x', ['shortForecast' => null]))['short_forecast']);
        self::assertSame('Mostly Sunny', DayContextService::hourForecast(0, self::period('x', ['shortForecast' => 'Mostly Sunny']))['short_forecast']);
    }

    // ------------------------------------------------------------------------------------ holidays

    public function testTheHolidayTableOf2026To2029(): void
    {
        $service = $this->service();
        $A = self::dc();
        $found = [];
        foreach ([2026, 2027, 2028, 2029] as $year) {
            $days = Estimator::daysFromCivil($year + 1, 1, 1) - Estimator::daysFromCivil($year, 1, 1);
            foreach ($service->contexts(self::truck(), $A, $year . '-01-01', $days)['days'] as $day) {
                if ($day['holiday'] !== null) {
                    $found[$day['date']] = $day['holiday']['id'];
                    self::assertSame($day['date'], $day['holiday']['observed']);
                    self::assertSame($day['holiday']['class'], $day['context']['holiday_class']);
                }
            }
        }
        self::assertSame(self::OBSERVED, $found);
        self::assertCount(44, $found);
    }

    public function testAHolidayThatFallsOnAWeekendIsObservedOnTheDayBesideIt(): void
    {
        $service = $this->service();
        $A = self::dc();
        foreach (self::MOVED as [$id, $actual, $observed]) {
            $on = static fn (string $date): ?array => $service->contexts(self::truck(), $A, $date, 1)['days'][0]['holiday'];
            self::assertSame(['id' => $id, 'date' => $actual, 'observed' => $observed], array_intersect_key((array) $on($observed), ['id' => 0, 'date' => 0, 'observed' => 0]));
            self::assertNull($on($actual), $id . ' is not a holiday on its weekend date ' . $actual);
        }
        // Inauguration Day 2029 is a Saturday and has no day in lieu
        foreach (['2029-01-19', '2029-01-20', '2029-01-21', '2029-01-22'] as $date) {
            self::assertNull($service->contexts(self::truck(), $A, $date, 1)['days'][0]['holiday'], $date);
        }
    }

    public function testInaugurationDayFollowsTheFlagOfTheRegion(): void
    {
        // 20 January 2033 is a Thursday in an inauguration year
        $with = $this->service()->contexts(self::truck(), self::dc(), '2033-01-20', 1);
        self::assertSame('inauguration', $with['days'][0]['holiday']['id']);
        $without = $this->service()->contexts(self::truck(), Seeds::defaults(), '2033-01-20', 1);
        self::assertNull($without['days'][0]['holiday']);
        // national holidays do not depend on it
        self::assertSame('thanksgiving', $this->service()->contexts(self::truck(), Seeds::defaults(), '2026-11-26', 1)['days'][0]['holiday']['id']);
    }

    // ------------------------------------------------------------------------------------ the range of route 17

    public function testByDefaultTheRangeIsTodayInTheTrucksZoneAndTheSevenDaysAfterIt(): void
    {
        // 03:30 UTC on 5 October: still 4 October in New York, already 5 October on Kiritimati
        $this->clock->set('2026-10-05 03:30:00');
        $answer = $this->service()->range(self::truck(), self::dc(), []);
        self::assertSame(['timezone', 'today', 'fuel', 'forecast', 'days'], array_keys($answer));
        self::assertSame('America/New_York', $answer['timezone']);
        self::assertSame('2026-10-04', $answer['today']);
        self::assertSame(
            ['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'],
            array_column($answer['days'], 'date')
        );
        self::assertSame(self::FUEL, $answer['fuel']);

        $far = $this->service()->range(self::truck('Pacific/Kiritimati'), self::dc(), []);
        self::assertSame('2026-10-05', $far['today']);
        self::assertSame('2026-10-05', $far['days'][0]['date']);
        self::assertSame('2026-10-12', $far['days'][7]['date']);
    }

    public function testFromAndToAreTakenAsGivenAndBothEndsCount(): void
    {
        $answer = $this->service()->range(self::truck(), self::dc(), ['from' => '2026-10-08', 'to' => '2026-10-09']);
        self::assertSame(['2026-10-08', '2026-10-09'], array_column($answer['days'], 'date'));
        self::assertSame('2026-10-05', $answer['today']);
        // one date
        self::assertCount(1, $this->service()->range(self::truck(), self::dc(), ['from' => '2026-11-26', 'to' => '2026-11-26'])['days']);
        // `from` alone: seven days after it; `to` alone: from today
        self::assertSame('2026-12-08', $this->service()->range(self::truck(), self::dc(), ['from' => '2026-12-01'])['days'][7]['date']);
        self::assertSame(['2026-10-05', '2026-10-06'], array_column($this->service()->range(self::truck(), self::dc(), ['to' => '2026-10-06'])['days'], 'date'));
        // fourteen dates are the most
        self::assertCount(14, $this->service()->range(self::truck(), self::dc(), ['from' => '2026-10-01', 'to' => '2026-10-14'])['days']);
        // at the end of the model's calendar the default range stops there
        self::assertSame(['2199-12-30', '2199-12-31'], array_column($this->service()->range(self::truck(), self::dc(), ['from' => '2199-12-30'])['days'], 'date'));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: ?string, 3: ?string}> query, message, field, rule
     */
    public static function refusals(): array
    {
        return [
            'from is not a date' => [['from' => '10/08/2026'], 'from must be a date in the form YYYY-MM-DD', 'from', 'V7'],
            'from is not a real date' => [['from' => '2026-02-30'], 'from must be a date in the form YYYY-MM-DD', 'from', 'V7'],
            'to is not a date' => [['from' => '2026-10-08', 'to' => 'tomorrow'], 'to must be a date in the form YYYY-MM-DD', 'to', 'V7'],
            'to is before from' => [['from' => '2026-10-08', 'to' => '2026-10-07'], 'to must not be before from', 'to', null],
            'to is before today' => [['to' => '2026-10-01'], 'to must not be before from', 'to', null],
            'fifteen dates' => [['from' => '2026-10-01', 'to' => '2026-10-15'], 'The date range must be at most 14 days', null, null],
            'a year' => [['from' => '2026-01-01', 'to' => '2026-12-31'], 'The date range must be at most 14 days', null, null],
        ];
    }

    /**
     * @dataProvider refusals
     * @param array<string, string> $query
     */
    public function testARangeThatIsRefused(array $query, string $message, ?string $field, ?string $rule): void
    {
        try {
            $this->service()->range(self::truck(), self::dc(), $query);
            self::fail('the range was accepted');
        } catch (TpInvalid $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame($field, $e->field());
            self::assertSame($rule, $e->rule());
        }
        self::assertSame([], $this->weather->asked, 'a refused range asks for no forecast');
    }

    public function testTheMomentTheForecastWasMadeIsAlsoGivenOnTheTrucksClock(): void
    {
        $answer = $this->service([], 'fresh', '2026-10-04T23:41:07+00:00')->range(self::truck(), self::dc(), []);
        self::assertSame('2026-10-04T23:41:07+00:00', $answer['forecast']['generated_at']);
        self::assertSame(['date' => '2026-10-04', 'minute' => 1181], $answer['forecast']['generated_local']);
        self::assertSame(
            ['state', 'generated_at', 'generated_local', 'point', 'source'],
            array_keys($answer['forecast'])
        );
        // the same instant on another clock
        $far = $this->service([], 'fresh', '2026-10-04T23:41:07+00:00')->range(self::truck('Pacific/Kiritimati'), self::dc(), []);
        self::assertSame(['date' => '2026-10-05', 'minute' => 13 * 60 + 41], $far['forecast']['generated_local']);
        // without a forecast there is no such moment
        $none = $this->service([], 'unavailable', null)->range(self::truck(), self::dc(), []);
        self::assertNull($none['forecast']['generated_at']);
        self::assertNull($none['forecast']['generated_local']);
    }

    public function testATruckWhoseZoneThisServerDoesNotKnowIsAnsweredInTheDefaultZone(): void
    {
        $this->clock->set('2026-10-05 03:30:00');
        $answer = null;
        $lines = LogCapture::during(function () use (&$answer): void {
            $answer = $this->service()->range(self::truck('Mars/Olympus'), self::dc(), []);
        });
        self::assertSame('America/New_York', $answer['timezone']);
        self::assertSame('2026-10-04', $answer['today']);
        self::assertCount(1, $lines);
        self::assertStringStartsWith('[tp] a truck has a time zone this server does not know', $lines[0]);
    }

    public function testTheWeeklyFuelPricesAreRefreshedBeforeThePriceIsResolved(): void
    {
        $fuel = new class extends FuelPriceService {
            /** @var list<string> */
            public array $calls = [];

            public function refreshIfDue(): void
            {
                $this->calls[] = 'refresh';
            }

            public function resolve(array $truck): array
            {
                $this->calls[] = 'resolve';
                return ['price_per_gal' => 4.201, 'source' => 'eia', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-10-05'];
            }
        };
        Registry::set('fuel', $fuel);
        $answer = $this->service()->range(self::truck(), self::dc(), []);
        self::assertSame(['refresh', 'resolve'], $fuel->calls);
        self::assertSame(4.201, $answer['fuel']['price_per_gal']);
        self::assertSame(4.201, $answer['days'][0]['context']['fuel_price_per_gal']);

        // a refused range refreshes nothing
        $fuel->calls = [];
        try {
            $this->service()->range(self::truck(), self::dc(), ['from' => 'x']);
        } catch (TpInvalid $e) {
            // expected
        }
        self::assertSame([], $fuel->calls);
    }

    // ------------------------------------------------------------------------------------ with the real client

    public function testWithTheRealClientAndAStubbedServiceTheHoursAndTheNullsArriveInTheContexts(): void
    {
        TpCache::wire($this->clock, new MemoryCache());
        putenv('TP_CONTACT_EMAIL=ops@example.test');
        $http = new FakeHttp();
        $http->json(200, ['properties' => ['gridId' => 'LWX', 'gridX' => 83, 'gridY' => 74]]);
        $http->json(200, ['properties' => ['generatedAt' => '2026-10-05T08:58:32+00:00', 'periods' => [
            ['startTime' => '2026-10-05T11:00:00-04:00', 'temperature' => 66, 'temperatureUnit' => 'F',
                'probabilityOfPrecipitation' => ['unitCode' => 'wmoUnit:percent', 'value' => null],
                'windSpeed' => '5 to 10 mph', 'shortForecast' => 'Chance Rain Showers'],
            ['startTime' => '2026-10-05T12:00:00-04:00', 'temperature' => 68, 'temperatureUnit' => 'F',
                'probabilityOfPrecipitation' => ['unitCode' => 'wmoUnit:percent', 'value' => 0],
                'windSpeed' => '14 mph', 'shortForecast' => 'Sunny'],
        ]]], ['expires' => 'Mon, 05 Oct 2026 09:58:32 GMT']);
        $service = new DayContextService(new WeatherClient($http, new ApiLedger(new RecordingDatabase()), $this->clock), $this->clock);

        $answer = $service->range(self::truck(), self::dc(), ['from' => '2026-10-05', 'to' => '2026-10-06']);
        self::assertSame('fresh', $answer['forecast']['state']);
        self::assertSame(['date' => '2026-10-05', 'minute' => 4 * 60 + 58], $answer['forecast']['generated_local']);
        $hours = $answer['days'][0]['context']['forecast'];
        self::assertSame(['hour' => 11, 'temp_f' => 66.0, 'precip_prob' => null, 'short_forecast' => 'Chance Rain Showers', 'wind_mph' => 10.0], $hours[11]);
        self::assertSame(['hour' => 12, 'temp_f' => 68.0, 'precip_prob' => 0.0, 'short_forecast' => 'Sunny', 'wind_mph' => 14.0], $hours[12]);
        self::assertNull($hours[10]);
        self::assertNull($answer['days'][1]['context']['forecast']);
        self::assertSame('https://api.weather.gov/points/39.003,-77.405', $http->requests[0]['url']);
    }

    public function testWithoutAContactAddressTheDayContextsStillAnswer(): void
    {
        TpCache::wire($this->clock, new MemoryCache());
        putenv('TP_CONTACT_EMAIL');
        putenv('MAIL_FROM');
        unset($_ENV['TP_CONTACT_EMAIL'], $_ENV['MAIL_FROM']);
        $http = new FakeHttp();
        $service = new DayContextService(new WeatherClient($http, new ApiLedger(new RecordingDatabase()), $this->clock), $this->clock);
        $answer = $service->range(self::truck(), self::dc(), ['from' => '2026-10-12', 'to' => '2026-10-13']);
        self::assertSame([], $http->requests);
        self::assertSame('unavailable', $answer['forecast']['state']);
        self::assertSame('columbus', $answer['days'][0]['holiday']['id']);
        self::assertNull($answer['days'][0]['context']['forecast']);
        self::assertSame(4.195, $answer['days'][1]['context']['fuel_price_per_gal']);
    }
}

/**
 * A weather client that answers what the test prepared and notes what it was asked.
 */
final class StubWeather extends WeatherClient
{
    /** @var list<array{0: float, 1: float}> */
    public array $asked = [];

    /** @var array{state: string, generated_at: ?string, periods: list<array<string, mixed>>} */
    private array $prepared;

    /**
     * @param array{state: string, generated_at: ?string, periods: list<array<string, mixed>>} $prepared
     */
    public function __construct(array $prepared)
    {
        $this->prepared = $prepared;
    }

    public function hourly(float $lat, float $lng): array
    {
        $this->asked[] = [$lat, $lng];
        return $this->prepared;
    }
}
