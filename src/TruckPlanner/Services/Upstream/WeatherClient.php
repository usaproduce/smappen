<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Upstream;

use App\Core\Config;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Http\OutboundHttp;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\JsonSafe;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * The hourly forecast of the National Weather Service for one point (03_DATA.md 13.1, 04_BACKEND.md 5.5).
 *
 * Two requests, both without a key and both with a User-Agent that names a contact address
 * (TP_CONTACT_EMAIL, else MAIL_FROM; with neither nothing is requested):
 *
 *     GET https://api.weather.gov/points/{lat},{lng}                             the grid cell of the point
 *     GET https://api.weather.gov/gridpoints/{gridId}/{gridX},{gridY}/forecast/hourly
 *
 * The grid cell of a point is remembered for 14 days (a point outside the service's coverage for 24
 * hours). The forecast of a grid cell is kept until the service says it expires, at least 10 minutes,
 * and is asked for again after that. When the service does not answer, the last copy is served as `stale`
 * for up to 6 hours after it was fetched; after that there is no forecast. A failed request is not
 * repeated for this point for a minute, so an outage costs one slow request a minute and not every one.
 *
 * hourly() never raises:
 *
 *     { state: "fresh"|"stale"|"unavailable", generated_at: string?, periods: [period] }
 *     period = { startTime: string, temperature: number?, temperatureUnit: string?,
 *                probabilityOfPrecipitation: number?, windSpeed: string?, shortForecast: string? }
 *
 * `startTime` is the service's own text, an ISO 8601 instant with the local offset of the grid cell
 * ("2026-10-08T11:00:00-04:00"). `probabilityOfPrecipitation` is null when the service gives none: it is
 * never turned into 0. Periods are in the service's order. Every HTTP call leaves one ledger row.
 */
class WeatherClient
{
    public const FRESH = 'fresh';
    public const STALE = 'stale';
    public const UNAVAILABLE = 'unavailable';

    private const BASE = 'https://api.weather.gov';

    private OutboundHttp $http;
    private ApiLedger $ledger;
    private Clock $clock;

    /** @var callable(int): void */
    private $wait;

    /**
     * @param (callable(int): void)|null $wait waits the given number of seconds before the one retry;
     *                                        null really waits
     */
    public function __construct(
        ?OutboundHttp $http = null,
        ?ApiLedger $ledger = null,
        ?Clock $clock = null,
        ?callable $wait = null
    ) {
        $this->http = $http ?? new OutboundHttp();
        $this->ledger = $ledger ?? new ApiLedger();
        $this->clock = $clock ?? new Clock();
        $this->wait = $wait ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @return array{state: string, generated_at: ?string, periods: list<array<string, mixed>>}
     */
    public function hourly(float $lat, float $lng): array
    {
        try {
            return $this->forecast($lat, $lng);
        } catch (\Throwable $e) {
            // The cache store failed, or something nobody thought of: the day is planned without weather.
            error_log('[tp] weather failed: ' . get_class($e));
            return self::answer(self::UNAVAILABLE, null);
        }
    }

    /**
     * @return array{state: string, generated_at: ?string, periods: list<array<string, mixed>>}
     */
    private function forecast(float $lat, float $lng): array
    {
        $contact = self::contact();
        if ($contact === null || !is_finite($lat) || !is_finite($lng) || abs($lat) > 90.0 || abs($lng) > 180.0) {
            return self::answer(self::UNAVAILABLE, null);
        }
        // Four decimals at most: the service redirects a longer number, and redirects are not followed.
        $where = JsonSafe::float(Estimator::roundHalfAway($lat, 4) + 0.0)
            . ',' . JsonSafe::float(Estimator::roundHalfAway($lng, 4) + 0.0);

        $grid = $this->grid($where, $contact);
        if ($grid === null) {
            return self::answer(self::UNAVAILABLE, null);
        }
        $key = 'tp:nws:hr:' . $grid['gridId'] . ':' . $grid['gridX'] . ',' . $grid['gridY'];
        $copy = self::copyOf(TpCache::get($key));
        $now = $this->clock->epoch();
        if ($copy !== null && $now < max($copy['expires'], $copy['fetched'] + (int) TpConfig::get('weather.fresh_min_s'))) {
            return self::answer(self::FRESH, $copy);
        }
        if (!$this->paused($where)) {
            $fetched = $this->fetchForecast($grid, $where, $contact);
            if ($fetched !== null) {
                TpCache::put($key, $fetched, (int) TpConfig::get('weather.hourly_ttl_s'));
                return self::answer(self::FRESH, $fetched);
            }
        }
        if ($copy !== null && $now - $copy['fetched'] < (int) TpConfig::get('weather.stale_max_s')) {
            return self::answer(self::STALE, $copy);
        }
        return self::answer(self::UNAVAILABLE, null);
    }

    /**
     * The grid cell of a point: from the cache, else from the service. Null when the service does not
     * cover the point or did not answer.
     *
     * @return array{gridId: string, gridX: int, gridY: int}|null
     */
    private function grid(string $where, string $contact): ?array
    {
        $key = 'tp:nws:pt:' . $where;
        $cached = TpCache::get($key);
        if ($cached !== null) {
            if (($cached['none'] ?? false) === true) {
                return null;
            }
            $grid = self::gridOf($cached);
            if ($grid !== null) {
                return $grid;
            }
        }
        if ($this->paused($where)) {
            return null;
        }
        [$status, $body, $error] = $this->get(
            self::BASE . '/points/' . $where,
            $contact,
            (string) TpConfig::get('weather.sku_points')
        );
        if ($status === 404) {
            // "InvalidPoint": the service has no forecast for this place (outside the United States).
            TpCache::put($key, ['none' => true], (int) TpConfig::get('weather.no_coverage_ttl_s'));
            return null;
        }
        $decoded = $error === null ? json_decode($body, true) : null;
        $grid = is_array($decoded) && is_array($decoded['properties'] ?? null) ? self::gridOf($decoded['properties']) : null;
        if ($grid === null) {
            $this->failed($where, 'points', $error ?? 'bad_body');
            return null;
        }
        TpCache::put($key, $grid, (int) TpConfig::get('weather.point_ttl_s'));
        return $grid;
    }

    /**
     * One fetch of the hourly forecast, with one retry after a second on a 5xx answer or a time-out.
     *
     * @param array{gridId: string, gridX: int, gridY: int} $grid
     * @return array{fetched: int, expires: int, generated_at: ?string, periods: list<array<string, mixed>>}|null
     */
    private function fetchForecast(array $grid, string $where, string $contact): ?array
    {
        $url = self::BASE . '/gridpoints/' . $grid['gridId'] . '/' . $grid['gridX'] . ',' . $grid['gridY'] . '/forecast/hourly';
        $sku = (string) TpConfig::get('weather.sku_hourly');
        [$status, $body, $error, $headers] = $this->get($url, $contact, $sku);
        if ($status >= 500 || $error === OutboundHttp::ERROR_TIMEOUT) {
            ($this->wait)((int) TpConfig::get('weather.retry_wait_s'));
            [$status, $body, $error, $headers] = $this->get($url, $contact, $sku);
        }
        $periods = null;
        $generatedAt = null;
        if ($error === null) {
            $decoded = json_decode($body, true);
            $properties = is_array($decoded) ? ($decoded['properties'] ?? null) : null;
            if (is_array($properties) && is_array($properties['periods'] ?? null)) {
                $periods = self::periods($properties['periods']);
                $generatedAt = self::text($properties['generatedAt'] ?? null, 40);
            }
        }
        if ($periods === null || $periods === []) {
            if ($status === 404) {
                // The grid cell is not known any more: the point is looked up again next time.
                TpCache::forget('tp:nws:pt:' . $where);
            }
            $this->failed($where, 'hourly', $error ?? 'bad_body');
            return null;
        }
        $fetched = $this->clock->epoch();
        $expires = $this->clock->parseHttpDate((string) ($headers['expires'] ?? ''))
            ?? $fetched + (int) TpConfig::get('weather.fresh_default_s');
        return [
            'fetched' => $fetched,
            // A date far ahead is not believed: a copy is never fresh for longer than it may be served stale.
            'expires' => min($expires, $fetched + (int) TpConfig::get('weather.stale_max_s')),
            'generated_at' => $generatedAt,
            'periods' => $periods,
        ];
    }

    /**
     * One GET with its ledger row.
     *
     * @return array{0: int, 1: string, 2: ?string, 3: array<string, string>} [status, body, error code, headers]
     */
    private function get(string $url, string $contact, string $sku): array
    {
        [$status, $body, $latencyMs, $error, $headers] = $this->http->request(
            'GET',
            $url,
            ['User-Agent: (TruckPlanner, ' . $contact . ')', 'Accept: application/geo+json'],
            null,
            (int) TpConfig::get('weather.connect_timeout_s'),
            (int) TpConfig::get('weather.timeout_s')
        );
        $this->ledger->record($sku, $error === null ? 1 : 0, $status === 0 ? null : $status, $latencyMs, $error);
        return [$status, $body, $error, $headers];
    }

    /**
     * A request failed: say so once and leave the service alone for a minute
     * (`weather.pause_after_failure_s`): nothing is asked for the same point for that long.
     */
    private function failed(string $where, string $step, string $code): void
    {
        error_log('[tp] weather ' . $step . ' failed: ' . $code);
        TpCache::put('tp:nws:pause:' . $where, ['at' => $this->clock->epoch()], (int) TpConfig::get('weather.pause_after_failure_s'));
    }

    private function paused(string $where): bool
    {
        return TpCache::get('tp:nws:pause:' . $where) !== null;
    }

    /** The contact address for the User-Agent, or null when none is configured. */
    private static function contact(): ?string
    {
        foreach (['TP_CONTACT_EMAIL', 'MAIL_FROM'] as $name) {
            $value = trim((string) preg_replace('/[^\x21-\x7E]+/', '', (string) Config::get($name, '')));
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param array<int|string, mixed> $source
     * @return array{gridId: string, gridX: int, gridY: int}|null
     */
    private static function gridOf(array $source): ?array
    {
        $id = $source['gridId'] ?? null;
        $x = $source['gridX'] ?? null;
        $y = $source['gridY'] ?? null;
        if (!is_string($id) || preg_match('/^[A-Z0-9]{2,8}$/', $id) !== 1) {
            return null;
        }
        if (!is_int($x) || !is_int($y) || $x < 0 || $y < 0 || $x > 99999 || $y > 99999) {
            return null;
        }
        return ['gridId' => $id, 'gridX' => $x, 'gridY' => $y];
    }

    /**
     * What is kept of the service's periods: the six fields the day contexts are built from.
     *
     * @param array<int|string, mixed> $periods
     * @return list<array<string, mixed>>
     */
    private static function periods(array $periods): array
    {
        $out = [];
        foreach ($periods as $period) {
            if (!is_array($period) || !is_string($period['startTime'] ?? null) || strlen($period['startTime']) > 40) {
                continue;
            }
            $chance = is_array($period['probabilityOfPrecipitation'] ?? null)
                ? ($period['probabilityOfPrecipitation']['value'] ?? null)
                : null;
            $out[] = [
                'startTime' => $period['startTime'],
                'temperature' => self::number($period['temperature'] ?? null),
                'temperatureUnit' => self::text($period['temperatureUnit'] ?? null, 4),
                'probabilityOfPrecipitation' => self::number($chance),
                'windSpeed' => self::text($period['windSpeed'] ?? null, 60),
                'shortForecast' => self::text($period['shortForecast'] ?? null, 200),
            ];
            if (count($out) >= (int) TpConfig::get('weather.max_periods')) {
                break;
            }
        }
        return $out;
    }

    /**
     * A cached forecast as it was stored, or null when the entry is missing or not one.
     *
     * @param array<int|string, mixed>|null $cached
     * @return array{fetched: int, expires: int, generated_at: ?string, periods: list<array<string, mixed>>}|null
     */
    private static function copyOf(?array $cached): ?array
    {
        if ($cached === null || !is_int($cached['fetched'] ?? null) || !is_int($cached['expires'] ?? null)
            || !is_array($cached['periods'] ?? null)) {
            return null;
        }
        return [
            'fetched' => $cached['fetched'],
            'expires' => $cached['expires'],
            'generated_at' => is_string($cached['generated_at'] ?? null) ? $cached['generated_at'] : null,
            'periods' => array_values($cached['periods']),
        ];
    }

    /**
     * @param array{fetched: int, expires: int, generated_at: ?string, periods: list<array<string, mixed>>}|null $copy
     * @return array{state: string, generated_at: ?string, periods: list<array<string, mixed>>}
     */
    private static function answer(string $state, ?array $copy): array
    {
        return [
            'state' => $state,
            'generated_at' => $copy['generated_at'] ?? null,
            'periods' => $copy['periods'] ?? [],
        ];
    }

    private static function number(mixed $value): int|float|null
    {
        return (is_int($value) || (is_float($value) && is_finite($value))) ? $value : null;
    }

    private static function text(mixed $value, int $maxBytes): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        return strlen($value) > $maxBytes ? mb_strcut($value, 0, $maxBytes, 'UTF-8') : $value;
    }
}
