<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Google;

use App\Core\Config;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Data\DriveLegRepository;
use App\TruckPlanner\Services\Http\OutboundHttp;
use App\TruckPlanner\Services\Support\Redactor;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * One call of Google's legacy Distance Matrix API, parsing included (04_BACKEND.md 5.3). It is the single
 * second attempt after the Routes API refused the key (DECISIONS section 9), and nothing else calls it.
 *
 *     GET https://maps.googleapis.com/maps/api/distancematrix/json
 *         ?origins=<lat,lng|lat,lng>&destinations=<lat,lng|...>&mode=driving&units=metric
 *         [&avoid=tolls|highways]&key=<GOOGLE_API_KEY>
 *
 * No departure time, so the duration does not depend on the hour of the request. Google takes at most 25
 * origins, 25 destinations and 100 elements per request; the caller keeps to that. The API knows no toll
 * amounts: every element comes back with toll state "not asked".
 *
 * This API answers HTTP 200 also when it refuses, with a `status` of its own at the top (OK,
 * REQUEST_DENIED, OVER_QUERY_LIMIT, OVER_DAILY_LIMIT, INVALID_REQUEST, MAX_ELEMENTS_EXCEEDED,
 * MAX_DIMENSIONS_EXCEEDED, UNKNOWN_ERROR) and one per element (OK, ZERO_RESULTS, NOT_FOUND,
 * MAX_ROUTE_LENGTH_EXCEEDED). The result has the shape and the error words of RoutesMatrixClient::matrix().
 *
 * The key travels in the address of this request. So the address is never logged, stored or returned:
 * the log and the ledger hold a status word only.
 */
class DistanceMatrixClient
{
    public const URL = 'https://maps.googleapis.com/maps/api/distancematrix/json';

    public const MAX_ORIGINS = 25;
    public const MAX_DESTINATIONS = 25;
    public const MAX_ELEMENTS = 100;

    private const REFUSED_STATUSES = ['REQUEST_DENIED'];
    private const QUOTA_STATUSES = ['OVER_QUERY_LIMIT', 'OVER_DAILY_LIMIT'];
    private const BAD_REQUEST_STATUSES = ['INVALID_REQUEST', 'MAX_ELEMENTS_EXCEEDED', 'MAX_DIMENSIONS_EXCEEDED'];
    private const NO_ROUTE_STATUSES = ['ZERO_RESULTS', 'NOT_FOUND'];
    private const MAX_INT = 4000000000;

    private OutboundHttp $http;
    private ApiLedger $ledger;

    public function __construct(?OutboundHttp $http = null, ?ApiLedger $ledger = null)
    {
        $this->http = $http ?? new OutboundHttp();
        $this->ledger = $ledger ?? new ApiLedger();
    }

    /**
     * @param list<array<int, int>> $origins point keys [lat_e4, lng_e4]
     * @param list<array<int, int>> $destinations point keys
     * @param string $routeKey "d", "dt", "dh" or "dth" (LegKey::routeKey())
     * @return array{ok: bool, error: ?string, elements: list<array<string, mixed>>}
     */
    public function matrix(array $origins, array $destinations, string $routeKey): array
    {
        $origins = array_values($origins);
        $destinations = array_values($destinations);
        $elements = count($origins) * count($destinations);
        if (count($origins) > self::MAX_ORIGINS || count($destinations) > self::MAX_DESTINATIONS || $elements > self::MAX_ELEMENTS) {
            throw new \LogicException('a distance matrix request holds at most ' . self::MAX_ELEMENTS . ' elements');
        }
        $key = (string) Config::get('GOOGLE_API_KEY', '');
        if ($key === '') {
            return self::failed(RoutesMatrixClient::NO_KEY);
        }
        if ($elements === 0) {
            return ['ok' => true, 'error' => null, 'elements' => []];
        }

        /** @var array<string, string> $skus */
        $skus = TpConfig::get('routing.skus');
        $sku = $skus['legacy'];

        [$status, $body, $latencyMs, $transport] = $this->http->request(
            'GET',
            self::URL . '?' . self::query($origins, $destinations, $routeKey, $key),
            ['Accept: application/json'],
            null,
            (int) TpConfig::get('routing.connect_timeout_s'),
            (int) TpConfig::get('routing.timeout_s')
        );

        $decoded = $body === '' ? null : json_decode($body, true);
        $top = is_array($decoded) ? self::codeName($decoded['status'] ?? null) : null;
        if ($transport === null && $top === 'OK') {
            $this->ledger->record($sku, $elements, $status, $latencyMs, null);
            return [
                'ok' => true,
                'error' => null,
                'elements' => self::elements((array) $decoded, count($origins), count($destinations)),
            ];
        }

        $kind = self::classify($status, $transport, $top);
        $code = ($top !== null && $top !== 'OK') ? $top : ($transport ?? 'bad_body');
        $this->ledger->record($sku, 0, $status === 0 ? null : $status, $latencyMs, $code);
        if ($kind === RoutesMatrixClient::BAD_REQUEST || $kind === RoutesMatrixClient::REFUSED) {
            // The API says in one sentence why (which restriction, which parameter): the operator needs it.
            $message = is_array($decoded) ? ($decoded['error_message'] ?? null) : null;
            $what = $kind === RoutesMatrixClient::BAD_REQUEST ? 'bad request' : 'refused';
            error_log('[tp] distance matrix ' . $what . ': ' . $code . ' ' . self::safeMessage($message));
        } else {
            error_log('[tp] distance matrix ' . $kind . ': ' . $code);
        }
        return self::failed($kind);
    }

    /**
     * The query string of the request. The key is its last parameter.
     *
     * @param list<array<int, int>> $origins
     * @param list<array<int, int>> $destinations
     */
    public static function query(array $origins, array $destinations, string $routeKey, string $key): string
    {
        [$avoidTolls, $avoidHighways] = RoutesMatrixClient::avoids($routeKey);
        $query = [
            'origins' => self::places($origins),
            'destinations' => self::places($destinations),
            'mode' => 'driving',
            'units' => 'metric',
        ];
        $avoid = array_merge($avoidTolls ? ['tolls'] : [], $avoidHighways ? ['highways'] : []);
        if ($avoid !== []) {
            $query['avoid'] = implode('|', $avoid);
        }
        $query['key'] = $key;
        return http_build_query($query);
    }

    /**
     * "39.0030,-77.4050": a point key as the text the API takes, always with four decimals.
     *
     * @param array<int, int> $pointKey [lat_e4, lng_e4]
     */
    public static function place(array $pointKey): string
    {
        $pointKey = array_values($pointKey);
        if (count($pointKey) !== 2) {
            throw new \LogicException('a point key is [lat_e4, lng_e4]');
        }
        return self::decimal((int) $pointKey[0]) . ',' . self::decimal((int) $pointKey[1]);
    }

    /**
     * The elements of an answer whose top status is OK: rows follow the origins, the elements of a row
     * the destinations. An element with another status than OK, ZERO_RESULTS or NOT_FOUND is left out.
     *
     * @param array<int|string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    public static function elements(array $decoded, int $originCount, int $destinationCount): array
    {
        $out = [];
        $rows = is_array($decoded['rows'] ?? null) ? array_values($decoded['rows']) : [];
        for ($o = 0; $o < $originCount; $o++) {
            $cells = is_array($rows[$o]['elements'] ?? null) ? array_values($rows[$o]['elements']) : [];
            for ($d = 0; $d < $destinationCount; $d++) {
                $cell = $cells[$d] ?? null;
                $state = is_array($cell) ? ($cell['status'] ?? null) : null;
                if (in_array($state, self::NO_ROUTE_STATUSES, true)) {
                    $out[] = self::element($o, $d, false, 0, 0);
                    continue;
                }
                if ($state !== 'OK') {
                    continue;
                }
                $duration = self::whole($cell['duration']['value'] ?? null);
                $distance = self::whole($cell['distance']['value'] ?? null);
                if ($duration === null || $distance === null) {
                    continue;
                }
                $out[] = self::element($o, $d, true, $duration, $distance);
            }
        }
        return $out;
    }

    /**
     * @return array{ok: bool, error: ?string, elements: list<array<string, mixed>>}
     */
    private static function failed(string $kind): array
    {
        return ['ok' => false, 'error' => $kind, 'elements' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private static function element(int $o, int $d, bool $found, int $duration, int $distance): array
    {
        return [
            'o' => $o,
            'd' => $d,
            'found' => $found,
            'duration_s' => $duration,
            'distance_m' => $distance,
            'toll_state' => DriveLegRepository::TOLL_NOT_ASKED,
            'toll_cents' => null,
        ];
    }

    /**
     * @param list<array<int, int>> $pointKeys
     */
    private static function places(array $pointKeys): string
    {
        $texts = [];
        foreach ($pointKeys as $pointKey) {
            $texts[] = self::place($pointKey);
        }
        return implode('|', $texts);
    }

    /** Ten-thousandths of a degree as a decimal text: -774050 -> "-77.4050". Whole-number arithmetic only. */
    private static function decimal(int $e4): string
    {
        $size = abs($e4);
        return ($e4 < 0 ? '-' : '') . intdiv($size, 10000) . '.' . str_pad((string) ($size % 10000), 4, '0', STR_PAD_LEFT);
    }

    private static function classify(int $status, ?string $transport, ?string $top): string
    {
        if (in_array($top, self::REFUSED_STATUSES, true)) {
            return RoutesMatrixClient::REFUSED;
        }
        if (in_array($top, self::QUOTA_STATUSES, true)) {
            return RoutesMatrixClient::QUOTA;
        }
        if (in_array($top, self::BAD_REQUEST_STATUSES, true)) {
            return RoutesMatrixClient::BAD_REQUEST;
        }
        if ($top === null || $top === 'OK') {
            // No verdict of the API itself: the transport decides.
            if ($status === 429) {
                return RoutesMatrixClient::QUOTA;
            }
            if ($status === 403) {
                return RoutesMatrixClient::REFUSED;
            }
            if ($transport === OutboundHttp::ERROR_TIMEOUT) {
                return RoutesMatrixClient::TIMEOUT;
            }
            if ($status === 400) {
                return RoutesMatrixClient::BAD_REQUEST;
            }
        }
        return RoutesMatrixClient::UPSTREAM;
    }

    /** A status word such as REQUEST_DENIED, or null when the value is not one. */
    private static function codeName(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $value) === 1 ? $value : null;
    }

    /** The API's sentence about a bad request, made safe for a log line: no key, no address. */
    private static function safeMessage(mixed $message): string
    {
        if (!is_string($message) || $message === '') {
            return '(no message)';
        }
        return Redactor::text($message);
    }

    /** A whole number of at least 0; null for anything else. */
    private static function whole(mixed $value): ?int
    {
        if (is_int($value)) {
            $n = $value;
        } elseif (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= self::MAX_INT) {
            $n = (int) $value;
        } else {
            return null;
        }
        return $n < 0 || $n > self::MAX_INT ? null : $n;
    }
}
