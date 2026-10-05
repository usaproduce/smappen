<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Google;

use App\Core\Config;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Data\DriveLegRepository;
use App\TruckPlanner\Data\LegKey;
use App\TruckPlanner\Services\Http\OutboundHttp;
use App\TruckPlanner\Services\Support\JsonSafe;
use App\TruckPlanner\Services\Support\Money;
use App\TruckPlanner\Services\Support\Redactor;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * One call of the Google Routes API method computeRouteMatrix, parsing included (04_BACKEND.md 5.3).
 *
 *     POST https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix
 *     Content-Type: application/json
 *     X-Goog-Api-Key: <GOOGLE_API_KEY>
 *     X-Goog-FieldMask: originIndex,destinationIndex,status,condition,distanceMeters,duration
 *                       (plus ",travelAdvisory.tollInfo" when tolls are asked)
 *
 * Travel mode DRIVE, routing preference TRAFFIC_UNAWARE and no departure time: the same request always
 * has the same answer. The owner's routing options travel as `routeModifiers` on every origin, only when
 * one of them is on. Toll estimates are an extra computation and are asked only on request. Google takes
 * at most 625 elements (origins x destinations) per request; the caller keeps to that.
 *
 * Origins and destinations are point keys ([lat_e4, lng_e4] of LegKey::of()), so Google is asked about
 * exactly the rounded coordinates a cached leg is keyed by.
 *
 * The answer never raises for anything Google or the network can do:
 *
 *     { ok: bool, error: null|"no_key"|"refused"|"quota"|"timeout"|"upstream"|"bad_request",
 *       elements: [ { o: int, d: int, found: bool, duration_s: int, distance_m: int,
 *                     toll_state: 0..3, toll_cents: int? } ] }
 *
 * `o` and `d` are positions in the two lists that were sent. An element Google could not compute is
 * simply absent. `refused` means Google turned the key away: HTTP 403, the status PERMISSION_DENIED, or a
 * reason word that says so (the API is not enabled for the key's project, the key may not call it, the
 * key is not known). `quota` is HTTP 429, RESOURCE_EXHAUSTED or a quota reason word. `bad_request` is any
 * other HTTP 400 and is logged with Google's sentence. Everything else that went wrong is `timeout` or
 * `upstream`.
 *
 * Every HTTP call leaves one ledger row. The key is read here and travels in a header: it is never
 * logged, stored or returned.
 */
class RoutesMatrixClient
{
    public const URL = 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix';
    public const FIELD_MASK = 'originIndex,destinationIndex,status,condition,distanceMeters,duration';
    public const TOLL_FIELD = 'travelAdvisory.tollInfo';

    /** Google's limit of origins x destinations for a request that is not traffic-aware-optimal or transit. */
    public const MAX_ELEMENTS = 625;

    public const NO_KEY = 'no_key';
    public const REFUSED = 'refused';
    public const QUOTA = 'quota';
    public const TIMEOUT = 'timeout';
    public const UPSTREAM = 'upstream';
    public const BAD_REQUEST = 'bad_request';

    /**
     * Reason words of google.api.ErrorReason with which Google turns the key or its project away for this
     * API: not enabled, restricted, unknown, without billing, suspended.
     */
    private const REFUSAL_REASONS = [
        'SERVICE_DISABLED', 'BILLING_DISABLED', 'API_KEY_INVALID', 'API_KEY_SERVICE_BLOCKED',
        'API_KEY_HTTP_REFERRER_BLOCKED', 'API_KEY_IP_ADDRESS_BLOCKED', 'API_KEY_ANDROID_APP_BLOCKED',
        'API_KEY_IOS_APP_BLOCKED', 'CONSUMER_INVALID', 'CONSUMER_SUSPENDED',
    ];
    private const QUOTA_REASONS = ['RATE_LIMIT_EXCEEDED', 'RESOURCE_QUOTA_EXCEEDED'];

    /** A toll above this many cents is not believed and is stored as "amount unknown". */
    private const MAX_TOLL_CENTS = 10000000;
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
     * @param bool $tolls ask Google for toll estimates
     * @return array{ok: bool, error: ?string, elements: list<array<string, mixed>>}
     */
    public function matrix(array $origins, array $destinations, string $routeKey, bool $tolls): array
    {
        $origins = array_values($origins);
        $destinations = array_values($destinations);
        $elements = count($origins) * count($destinations);
        if ($elements > self::MAX_ELEMENTS) {
            throw new \LogicException('a route matrix request holds at most ' . self::MAX_ELEMENTS . ' elements');
        }
        $key = (string) Config::get('GOOGLE_API_KEY', '');
        if ($key === '') {
            return self::failed(self::NO_KEY);
        }
        if ($elements === 0) {
            return ['ok' => true, 'error' => null, 'elements' => []];
        }

        $mask = self::FIELD_MASK . ($tolls ? ',' . self::TOLL_FIELD : '');
        /** @var array<string, string> $skus */
        $skus = TpConfig::get('routing.skus');
        $sku = $tolls ? $skus['tolls'] : $skus['plain'];

        [$status, $body, $latencyMs, $transport] = $this->http->request(
            'POST',
            self::URL,
            ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: ' . $mask],
            self::body($origins, $destinations, $routeKey, $tolls),
            (int) TpConfig::get('routing.connect_timeout_s'),
            (int) TpConfig::get('routing.timeout_s')
        );

        $decoded = $body === '' ? null : json_decode($body, true);
        $error = self::errorOf($decoded);
        if ($transport === null && $error === null && is_array($decoded) && array_is_list($decoded)) {
            $this->ledger->record($sku, $elements, $status, $latencyMs, null, $mask);
            return [
                'ok' => true,
                'error' => null,
                'elements' => self::elements($decoded, count($origins), count($destinations), $tolls),
            ];
        }

        $name = self::codeName($error['status'] ?? null);
        $reason = self::reasonOf($error);
        $kind = self::classify($status, $transport, $name, $reason);
        $code = $name ?? $transport ?? 'bad_body';
        $this->ledger->record($sku, 0, $status === 0 ? null : $status, $latencyMs, $code, $mask);
        if ($kind === self::BAD_REQUEST) {
            error_log('[tp] routes bad request: ' . self::safeMessage($error['message'] ?? null));
        } else {
            error_log('[tp] routes ' . $kind . ': ' . $code . ($reason === null ? '' : ' ' . $reason));
        }
        return self::failed($kind);
    }

    /**
     * The request body. Coordinates are the rounded key values.
     *
     * @param list<array<int, int>> $origins
     * @param list<array<int, int>> $destinations
     */
    public static function body(array $origins, array $destinations, string $routeKey, bool $tolls): string
    {
        [$avoidTolls, $avoidHighways] = self::avoids($routeKey);
        $modifiers = ($avoidTolls || $avoidHighways)
            ? ['avoidTolls' => $avoidTolls, 'avoidHighways' => $avoidHighways]
            : null;
        $from = [];
        foreach ($origins as $pointKey) {
            $origin = ['waypoint' => self::waypoint($pointKey)];
            if ($modifiers !== null) {
                $origin['routeModifiers'] = $modifiers;
            }
            $from[] = $origin;
        }
        $to = [];
        foreach ($destinations as $pointKey) {
            $to[] = ['waypoint' => self::waypoint($pointKey)];
        }
        $payload = [
            'origins' => $from,
            'destinations' => $to,
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_UNAWARE',
        ];
        if ($tolls) {
            $payload['extraComputations'] = ['TOLLS'];
        }
        JsonSafe::shortestFloats();
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * [avoid tolls, avoid highways] of a route key. A text that is not a route key is a programming error.
     *
     * @return array{0: bool, 1: bool}
     */
    public static function avoids(string $routeKey): array
    {
        if (preg_match('/^d(t?)(h?)$/', $routeKey, $m) !== 1) {
            throw new \LogicException('not a route key: ' . $routeKey);
        }
        return [$m[1] === 't', $m[2] === 'h'];
    }

    /**
     * Seconds of a duration text such as "713s" or "160.6s": the whole part, plus one when the first
     * digit after the point is 5 or more. Null for anything else.
     */
    public static function seconds(mixed $duration): ?int
    {
        if (!is_string($duration) || preg_match('/^(\d{1,10})(\.(\d)\d{0,8})?s$/', $duration, $m) !== 1) {
            return null;
        }
        $seconds = (int) $m[1] + ((isset($m[3]) && (int) $m[3] >= 5) ? 1 : 0);
        return $seconds > self::MAX_INT ? null : $seconds;
    }

    /**
     * The elements of a successful answer. One that failed (a status code other than 0, a condition that
     * is neither ROUTE_EXISTS nor ROUTE_NOT_FOUND, a malformed duration or distance, an index outside the
     * request) is left out; of two elements for one pair the first is kept.
     *
     * @param list<mixed> $list the decoded body
     * @return list<array<string, mixed>>
     */
    public static function elements(array $list, int $originCount, int $destinationCount, bool $tolls): array
    {
        $out = [];
        $seen = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $o = self::index($item, 'originIndex');
            $d = self::index($item, 'destinationIndex');
            if ($o === null || $d === null || $o >= $originCount || $d >= $destinationCount || isset($seen[$o . ':' . $d])) {
                continue;
            }
            $status = $item['status'] ?? [];
            if (!is_array($status) || (($status['code'] ?? 0) !== 0)) {
                continue;
            }
            $condition = $item['condition'] ?? null;
            if ($condition === 'ROUTE_NOT_FOUND') {
                $seen[$o . ':' . $d] = true;
                $out[] = [
                    'o' => $o,
                    'd' => $d,
                    'found' => false,
                    'duration_s' => 0,
                    'distance_m' => 0,
                    'toll_state' => $tolls ? DriveLegRepository::TOLL_NONE : DriveLegRepository::TOLL_NOT_ASKED,
                    'toll_cents' => null,
                ];
                continue;
            }
            if ($condition !== 'ROUTE_EXISTS') {
                continue;
            }
            $duration = self::seconds($item['duration'] ?? null);
            $distance = self::whole($item['distanceMeters'] ?? 0);
            if ($duration === null || $distance === null) {
                continue;
            }
            [$tollState, $tollCents] = self::toll($item, $tolls);
            $seen[$o . ':' . $d] = true;
            $out[] = [
                'o' => $o,
                'd' => $d,
                'found' => true,
                'duration_s' => $duration,
                'distance_m' => $distance,
                'toll_state' => $tollState,
                'toll_cents' => $tollCents,
            ];
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
     * @param array<int, int> $pointKey
     * @return array<string, mixed>
     */
    private static function waypoint(array $pointKey): array
    {
        $pointKey = array_values($pointKey);
        if (count($pointKey) !== 2) {
            throw new \LogicException('a point key is [lat_e4, lng_e4]');
        }
        return ['location' => ['latLng' => [
            'latitude' => LegKey::deg((int) $pointKey[0]),
            'longitude' => LegKey::deg((int) $pointKey[1]),
        ]]];
    }

    /**
     * The `error` object of a failed call: of the body itself, or of an item when the body is a list.
     *
     * @return array<string, mixed>|null
     */
    private static function errorOf(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            return $decoded['error'];
        }
        if (array_is_list($decoded)) {
            foreach ($decoded as $item) {
                if (is_array($item) && isset($item['error']) && is_array($item['error'])) {
                    return $item['error'];
                }
            }
        }
        return null;
    }

    /**
     * What kind of failure an answer is. Google says it three times over, and any of the three signs
     * counts: the HTTP status, the status name of the error, and the reason word in its details. Quota is
     * tested first, then a refusal of the key (also when it comes as "invalid argument", as a key Google
     * does not know does).
     */
    private static function classify(int $status, ?string $transport, ?string $name, ?string $reason): string
    {
        if ($name === 'RESOURCE_EXHAUSTED' || $status === 429 || in_array($reason, self::QUOTA_REASONS, true)) {
            return self::QUOTA;
        }
        if ($name === 'PERMISSION_DENIED' || $status === 403 || in_array($reason, self::REFUSAL_REASONS, true)) {
            return self::REFUSED;
        }
        if ($transport === OutboundHttp::ERROR_TIMEOUT) {
            return self::TIMEOUT;
        }
        if ($status === 400) {
            return self::BAD_REQUEST;
        }
        return self::UPSTREAM;
    }

    /** An upstream code such as PERMISSION_DENIED, or null when the value is not one. */
    private static function codeName(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{2,39}$/', $value) === 1 ? $value : null;
    }

    /**
     * The machine-readable reason Google gives with an error (SERVICE_DISABLED, API_KEY_SERVICE_BLOCKED,
     * BILLING_DISABLED ...), for the log line.
     *
     * @param array<string, mixed>|null $error
     */
    private static function reasonOf(?array $error): ?string
    {
        $details = $error['details'] ?? null;
        if (!is_array($details)) {
            return null;
        }
        foreach ($details as $detail) {
            $reason = is_array($detail) ? self::codeName($detail['reason'] ?? null) : null;
            if ($reason !== null) {
                return $reason;
            }
        }
        return null;
    }

    /** Google's sentence about a bad request, made safe for a log line: no key, no address. */
    private static function safeMessage(mixed $message): string
    {
        if (!is_string($message) || $message === '') {
            return '(no message)';
        }
        return Redactor::text($message);
    }

    /**
     * A zero-based index. A key that is absent means 0: JSON leaves default values out.
     *
     * @param array<int|string, mixed> $item
     */
    private static function index(array $item, string $key): ?int
    {
        if (!array_key_exists($key, $item)) {
            return 0;
        }
        $n = self::whole($item[$key]);
        return $n === null || $n > self::MAX_ELEMENTS ? null : $n;
    }

    /** A whole number of at least 0 written as a JSON number or as digits; null for anything else. */
    private static function whole(mixed $value): ?int
    {
        if (is_int($value)) {
            $n = $value;
        } elseif (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= self::MAX_INT) {
            $n = (int) $value;
        } elseif (is_string($value) && preg_match('/^\d{1,10}$/', $value) === 1) {
            $n = (int) $value;
        } else {
            return null;
        }
        return $n < 0 || $n > self::MAX_INT ? null : $n;
    }

    /**
     * [toll_state, toll_cents] of an element.
     *
     * @param array<int|string, mixed> $item
     * @return array{0: int, 1: ?int}
     */
    private static function toll(array $item, bool $asked): array
    {
        if (!$asked) {
            return [DriveLegRepository::TOLL_NOT_ASKED, null];
        }
        $advisory = $item['travelAdvisory'] ?? null;
        if (!is_array($advisory) || !array_key_exists('tollInfo', $advisory)) {
            return [DriveLegRepository::TOLL_NONE, null];
        }
        $prices = is_array($advisory['tollInfo']) ? ($advisory['tollInfo']['estimatedPrice'] ?? null) : null;
        foreach (is_array($prices) ? $prices : [] as $price) {
            if (!is_array($price) || ($price['currencyCode'] ?? null) !== 'USD') {
                continue;
            }
            $units = self::whole($price['units'] ?? 0);
            $nanos = self::whole($price['nanos'] ?? 0);
            if ($units === null || $nanos === null || $nanos > 999999999) {
                continue;
            }
            $cents = Money::toCents((float) $units + $nanos / 1e9);
            if ($cents <= self::MAX_TOLL_CENTS) {
                return [DriveLegRepository::TOLL_ESTIMATE, $cents];
            }
        }
        return [DriveLegRepository::TOLL_UNKNOWN, null];
    }
}
