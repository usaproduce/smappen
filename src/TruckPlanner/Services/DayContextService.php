<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Contracts\DayContextProvider;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpInvalid;
use App\TruckPlanner\Services\Upstream\WeatherClient;

/**
 * Day contexts for a run of civil dates: the holiday, the hourly forecast and the fuel price of each
 * (04_BACKEND.md 4.9 and 5.5).
 *
 * The forecast is the National Weather Service's for the truck's base point, and for that point only:
 * one grid cell per truck, so no request can multiply upstream calls. A forecast period belongs to the
 * civil date and the wall-clock hour written in its own start time, which carries the local offset of
 * the place. Nothing is converted between time zones here. Of two periods for one hour the first wins
 * (the repeated hour when clocks go back), and an hour without a period stays null (the skipped hour when
 * they go forward, and every hour beyond the six and a half days the service covers).
 *
 * A probability of precipitation the service does not give stays null. It is never made 0: the model
 * then reads the text of the forecast.
 *
 * Without a forecast (no contact address configured, the service down, the dates out of its reach) the
 * contexts still come back, with the holidays and the fuel price, and the answer says so in
 * `forecast.state`.
 */
class DayContextService implements DayContextProvider
{
    private const HOURS = 24;

    private ?WeatherClient $weather;
    private ?Clock $clock;

    public function __construct(?WeatherClient $weather = null, ?Clock $clock = null)
    {
        $this->weather = $weather;
        $this->clock = $clock;
    }

    public function contexts(array $truck, array $A, string $from, int $days, array $treatAs = []): array
    {
        $base = is_array($truck['profile']['base'] ?? null) ? $truck['profile']['base'] : [];
        $point = ['lat' => (float) ($base['lat'] ?? 0.0), 'lng' => (float) ($base['lng'] ?? 0.0)];

        $forecast = ($this->weather ??= new WeatherClient())->hourly($point['lat'], $point['lng']);
        $byDate = self::hoursByDate($forecast['periods']);
        $fuel = Registry::fuel()->resolve($truck);

        $list = [];
        for ($i = 0; $i < $days; $i++) {
            $date = Estimator::addDays($from, $i);
            $context = Estimator::dayContext(
                $A,
                $date,
                $treatAs[$date] ?? null,
                $byDate[$date] ?? null,
                (float) $fuel['price_per_gal'],
                (string) $fuel['source']
            );
            $list[] = ['date' => $date, 'holiday' => $context['holiday'], 'context' => $context];
        }
        return [
            'days' => $list,
            'forecast' => [
                'state' => (string) $forecast['state'],
                'generated_at' => $forecast['generated_at'],
                'point' => $point,
                'source' => (string) TpConfig::get('weather.source'),
            ],
            'fuel' => $fuel,
        ];
    }

    /**
     * The answer of GET /api/truck/day-context (4.9): the contexts of the dates `from` to `to`, both
     * included, built without a "treat this day as" value.
     *
     * @param array<string, mixed> $truck the truck value of TruckBaseController::truck()
     * @param array<string, mixed> $A the truck's Assumptions
     * @param array<int|string, mixed> $query the query string: `from` (default today in the truck's zone)
     *                                        and `to` (default seven days after `from`)
     * @return array<string, mixed> `timezone`, `today`, `fuel`, `forecast`, `days`
     * @throws TpInvalid for a value that is not a date, a `to` before `from`, or more than 14 dates
     */
    public function range(array $truck, array $A, array $query): array
    {
        $clock = $this->clock ??= new Clock();
        $zone = self::zoneOf($truck);
        $today = $clock->today($zone);

        $in = Input::query($query);
        $from = $in->date('from') ?? $today;
        $to = $in->date('to') ?? self::defaultTo($from);
        $span = self::dayNumber($to) - self::dayNumber($from) + 1;
        if ($span < 1) {
            throw $in->error('to', 'must not be before from');
        }
        $max = (int) TpConfig::get('limits.day_context_max_days');
        if ($span > $max) {
            throw new TpInvalid('The date range must be at most ' . $max . ' days');
        }

        // Route 17 is where the weekly fuel prices are refreshed, before the price is resolved.
        $fuelProvider = Registry::fuel();
        if ($fuelProvider instanceof FuelPriceService) {
            $fuelProvider->refreshIfDue();
        }

        $answer = $this->contexts($truck, $A, $from, $span);
        $generatedAt = $answer['forecast']['generated_at'] ?? null;
        return [
            'timezone' => $zone,
            'today' => $today,
            'fuel' => $answer['fuel'],
            'forecast' => [
                'state' => $answer['forecast']['state'],
                'generated_at' => $generatedAt,
                'generated_local' => is_string($generatedAt) ? $clock->localOfInstant($generatedAt, $zone) : null,
                'point' => $answer['forecast']['point'],
                'source' => $answer['forecast']['source'],
            ],
            'days' => $answer['days'],
        ];
    }

    /**
     * The forecast periods as 24 entries per civil date: date => [HourForecast or null x24].
     *
     * A period belongs to the date (characters 0 to 9) and the hour (characters 11 and 12) written in its
     * start time. The first period of an hour wins. A start time of another form is passed over.
     *
     * @param list<array<string, mixed>> $periods as WeatherClient::hourly() returns them
     * @return array<string, list<array<string, mixed>|null>>
     */
    public static function hoursByDate(array $periods): array
    {
        $byDate = [];
        foreach ($periods as $period) {
            $start = is_array($period) ? ($period['startTime'] ?? null) : null;
            if (!is_string($start) || preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):/', $start, $m) !== 1) {
                continue;
            }
            $date = $m[1];
            $hour = (int) $m[2];
            if ($hour >= self::HOURS) {
                continue;
            }
            $byDate[$date] ??= array_fill(0, self::HOURS, null);
            if ($byDate[$date][$hour] === null) {
                $byDate[$date][$hour] = self::hourForecast($hour, $period);
            }
        }
        return $byDate;
    }

    /**
     * HourForecast = { hour, temp_f, precip_prob, short_forecast, wind_mph } of one period.
     *
     * @param array<string, mixed> $period
     * @return array{hour: int, temp_f: ?float, precip_prob: ?float, short_forecast: ?string, wind_mph: ?float}
     */
    public static function hourForecast(int $hour, array $period): array
    {
        $temperature = $period['temperature'] ?? null;
        $tempF = null;
        if (is_int($temperature) || is_float($temperature)) {
            $tempF = (float) $temperature;
            if (($period['temperatureUnit'] ?? null) === 'C') {
                $tempF = $tempF * 9.0 / 5.0 + 32.0;
            }
        }
        $chance = $period['probabilityOfPrecipitation'] ?? null;
        $text = $period['shortForecast'] ?? null;
        return [
            'hour' => $hour,
            'temp_f' => $tempF,
            'precip_prob' => (is_int($chance) || is_float($chance)) ? (float) $chance : null,
            'short_forecast' => is_string($text) && mb_check_encoding($text, 'UTF-8') ? $text : null,
            'wind_mph' => self::windMph($period['windSpeed'] ?? null),
        ];
    }

    /** The largest whole number in the service's wind text: "5 to 10 mph" is 10.0. Null without one. */
    public static function windMph(mixed $windSpeed): ?float
    {
        if (!is_string($windSpeed) || preg_match_all('/\d{1,4}/', $windSpeed, $m) < 1) {
            return null;
        }
        return (float) max(array_map('intval', $m[0]));
    }

    /** Seven days after `from`, or the last date the model knows when that would be beyond it. */
    private static function defaultTo(string $from): string
    {
        $to = Estimator::addDays($from, (int) TpConfig::get('requests.day_context_default_days'));
        try {
            Estimator::parseDate($to);
            return $to;
        } catch (\InvalidArgumentException $e) {
            return Estimator::formatDate(2199, 12, 31);
        }
    }

    private static function dayNumber(string $date): int
    {
        [$y, $m, $d] = Estimator::parseDate($date);
        return Estimator::daysFromCivil($y, $m, $d);
    }

    /**
     * The truck's time zone, or the default zone when this server does not know the stored name.
     *
     * @param array<string, mixed> $truck
     */
    private static function zoneOf(array $truck): string
    {
        $zone = (string) ($truck['timezone'] ?? '');
        if (Clock::isZone($zone)) {
            return $zone;
        }
        error_log('[tp] a truck has a time zone this server does not know, the default zone is used');
        return (string) TpConfig::get('regions.default_timezone');
    }
}
