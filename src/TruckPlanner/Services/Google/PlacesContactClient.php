<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Google;

use App\Core\Config;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Http\OutboundHttp;
use App\TruckPlanner\Services\Support\JsonSafe;
use App\TruckPlanner\Services\Support\Redactor;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * Google Places (New): phone and website of one Scout candidate, when the owner asks for them
 * (04_BACKEND.md 5.4, 03_DATA.md 13.4, DECISIONS sections 0 and 9). Two calls, one per request:
 *
 * find(): the first lookup of a place, a Text Search by its name.
 *
 *     POST https://places.googleapis.com/v1/places:searchText
 *     Content-Type: application/json
 *     X-Goog-Api-Key: <GOOGLE_API_KEY>
 *     X-Goog-FieldMask: places.id,places.displayName,places.formattedAddress,places.location,
 *                       places.nationalPhoneNumber,places.websiteUri,places.googleMapsUri
 *
 *     {"textQuery":"<place name>","languageCode":"en","pageSize":1,
 *      "locationBias":{"circle":{"center":{"latitude":39.01,"longitude":-77.41},"radius":500.0}}}
 *
 * The bias circle only prefers results near the candidate: Google may still answer with a place of the
 * same name somewhere else. So the mask asks for the result's location, and a result farther than 300 m
 * from the candidate, or one without a location, is no confident match: it is answered as no place at all.
 * The location is used for that check only and is not returned.
 *
 * details(): a later lookup of a place whose Google id is known, a Place Details request by that id.
 *
 *     GET https://places.googleapis.com/v1/places/<place id>?languageCode=en
 *     X-Goog-Api-Key: <GOOGLE_API_KEY>
 *     X-Goog-FieldMask: id,displayName,formattedAddress,nationalPhoneNumber,websiteUri,googleMapsUri
 *
 * What comes back is handed to the caller and kept nowhere: this class has no cache and writes no text of
 * an answer to a log line or to the ledger. Google's terms allow storing a place id and nothing else of
 * Places content, so the caller stores the id and passes the rest on to the browser.
 *
 * The key is read here and travels in a header: it is never in a URL, a log line, a ledger row or an
 * answer. Every call writes one ledger row. Nothing here throws for what Google or the network can do.
 */
class PlacesContactClient
{
    public const URL = 'https://places.googleapis.com/v1/places:searchText';
    public const DETAILS_URL = 'https://places.googleapis.com/v1/places/';

    /** The response fields of a search. Phone and website put the call in the "Text Search Enterprise" SKU. */
    public const FIELD_MASK = 'places.id,places.displayName,places.formattedAddress,places.location,'
        . 'places.nationalPhoneNumber,places.websiteUri,places.googleMapsUri';

    /** The same contact fields of one place by id: the "Place Details Enterprise" SKU. No location is asked for. */
    public const DETAILS_FIELD_MASK = 'id,displayName,formattedAddress,nationalPhoneNumber,websiteUri,googleMapsUri';

    public const ERROR_NO_KEY = 'no_key';
    public const ERROR_REFUSED = 'refused';
    public const ERROR_QUOTA = 'quota';
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_UPSTREAM = 'upstream';

    public const MATCH_FOUND = 'found';
    public const MATCH_NONE = 'none';
    public const MATCH_FAR = 'far';
    public const MATCH_GONE = 'gone';

    /** What Google's id of a place looks like. */
    private const PLACE_ID_FORM = '/^[A-Za-z0-9_\-]{1,255}$/D';

    /** Bounds of the texts that are passed on: an answer is shown on a card, not stored. */
    private const MAX_NAME = 160;
    private const MAX_ADDRESS = 255;
    private const MAX_PHONE = 40;
    private const MAX_URL = 2048;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private ?OutboundHttp $http;
    private ?ApiLedger $ledger;

    public function __construct(?OutboundHttp $http = null, ?ApiLedger $ledger = null)
    {
        $this->http = $http;
        $this->ledger = $ledger;
    }

    /**
     * Asks Google for the place of this name at this point (Text Search).
     *
     * @param string $name the candidate's name
     * @param float $lat the candidate's point
     * @return array{ok: bool, error: ?string, match: ?string,
     *               place: array{place_id: ?string, name: ?string, address: ?string, phone: ?string,
     *                            website: ?string, maps_uri: ?string}|null}
     *         `ok` true: Google answered. `match` is then "found" with the `place`, "none" when Google knows
     *         no such place, or "far" when its best answer is not at the candidate's point; `place` is null
     *         for the last two. `ok` false: `error` is "no_key" (nothing was sent), "quota" (HTTP 429 or the
     *         status RESOURCE_EXHAUSTED), "refused" (HTTP 403 or the status PERMISSION_DENIED: the API is not
     *         enabled for the key, or the key may not use it), "timeout" or "upstream" (anything else), and
     *         `match` and `place` are null.
     */
    public function find(string $name, float $lat, float $lng): array
    {
        $key = $this->apiKey();
        if ($key === '') {
            return self::failure(self::ERROR_NO_KEY);
        }

        JsonSafe::shortestFloats();
        $query = trim($name);
        $body = $query === '' ? false : json_encode([
            'textQuery' => $query,
            'languageCode' => 'en',
            'pageSize' => 1,
            'locationBias' => [
                'circle' => [
                    'center' => ['latitude' => $lat, 'longitude' => $lng],
                    'radius' => (float) TpConfig::get('places.bias_radius_m'),
                ],
            ],
        ], self::JSON_FLAGS);
        if ($body === false) {
            // Without a name in valid text there is nothing to ask about: nothing is sent, nothing is matched.
            return self::answer(self::MATCH_NONE, null);
        }

        $decoded = $this->call(
            'POST',
            self::URL,
            ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: ' . self::FIELD_MASK],
            $body,
            (string) TpConfig::get('places.sku'),
            self::FIELD_MASK,
            false
        );
        if (is_string($decoded)) {
            return self::failure($decoded);
        }

        // Google leaves an empty list out of its JSON: no `places` member means no result.
        $first = $decoded['places'][0] ?? null;
        if (!is_array($first)) {
            return self::answer(self::MATCH_NONE, null);
        }
        $pLat = $first['location']['latitude'] ?? null;
        $pLng = $first['location']['longitude'] ?? null;
        // A result farther than `places.match_radius_m` from the candidate is not taken for the candidate.
        if (!self::isCoordinate($pLat, 90.0) || !self::isCoordinate($pLng, 180.0)
            || Estimator::haversineM($lat, $lng, (float) $pLat, (float) $pLng) > (float) TpConfig::get('places.match_radius_m')) {
            return self::answer(self::MATCH_FAR, null);
        }
        return self::answer(self::MATCH_FOUND, self::place($first));
    }

    /**
     * Asks Google for the contact fields of the place with this id (Place Details).
     *
     * @param string $placeId Google's id of the place, as an earlier find() answered it
     * @return array{ok: bool, error: ?string, match: ?string,
     *               place: array{place_id: ?string, name: ?string, address: ?string, phone: ?string,
     *                            website: ?string, maps_uri: ?string}|null}
     *         as find(), with two outcomes when Google answered: "found" with the `place`, or "gone" when
     *         Google no longer knows the id (HTTP 404 or the status NOT_FOUND: the place closed or moved,
     *         or Google gave it another id) and for a text that cannot be an id, which is not sent
     */
    public function details(string $placeId): array
    {
        $key = $this->apiKey();
        if ($key === '') {
            return self::failure(self::ERROR_NO_KEY);
        }
        if (preg_match(self::PLACE_ID_FORM, $placeId) !== 1) {
            return self::answer(self::MATCH_GONE, null);
        }
        $decoded = $this->call(
            'GET',
            self::DETAILS_URL . rawurlencode($placeId) . '?languageCode=en',
            ['X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: ' . self::DETAILS_FIELD_MASK],
            null,
            (string) TpConfig::get('places.sku_details'),
            self::DETAILS_FIELD_MASK,
            true
        );
        if ($decoded === self::MATCH_GONE) {
            return self::answer(self::MATCH_GONE, null);
        }
        if (is_string($decoded)) {
            return self::failure($decoded);
        }
        return self::answer(self::MATCH_FOUND, self::place($decoded));
    }

    /** The server's Google key, or "" when it has none. Read here and nowhere else in the lookup. */
    protected function apiKey(): string
    {
        return (string) Config::get('GOOGLE_API_KEY', '');
    }

    /**
     * One call: the request, its ledger row and, when it failed, its log line.
     *
     * @param list<string> $headers
     * @param bool $byId a request for one place by id: Google's "not found" is then an answer, not a failure
     * @return array<int|string, mixed>|string the decoded body of an HTTP 200; else one of the ERROR_ codes,
     *         or MATCH_GONE for a place id Google no longer knows
     */
    private function call(string $method, string $url, array $headers, ?string $body, string $sku, string $mask, bool $byId): array|string
    {
        [$status, $answer, $latencyMs, $transport] = $this->http()->request(
            $method,
            $url,
            $headers,
            $body,
            (int) TpConfig::get('places.connect_timeout_s'),
            (int) TpConfig::get('places.timeout_s')
        );

        if ($status === 0) {
            // No HTTP answer: a time-out, a failed connection, or an address the door does not open.
            $code = (string) ($transport ?? 'connect');
            $this->ledger()->record($sku, 0, null, $latencyMs, $code, $mask);
            error_log('[tp] places lookup failed: ' . Redactor::text($code));
            return $code === OutboundHttp::ERROR_TIMEOUT ? self::ERROR_TIMEOUT : self::ERROR_UPSTREAM;
        }

        $decoded = json_decode($answer, true, 32);
        if ($status !== 200) {
            $upstream = self::upstreamStatus($decoded);
            $this->ledger()->record($sku, 0, $status, $latencyMs, $upstream ?? 'http_' . $status, $mask);
            if ($byId && ($status === 404 || $upstream === 'NOT_FOUND')) {
                return self::MATCH_GONE;
            }
            $what = 'http_' . $status . ($upstream === null ? '' : ' ' . $upstream);
            if ($status === 400 && is_array($decoded) && is_string($decoded['error']['message'] ?? null)) {
                // A request Google could not read is a defect of ours: its sentence says which part. No
                // address is logged, whatever the sentence holds (Redactor::text).
                $what .= ' ' . $decoded['error']['message'];
            }
            error_log('[tp] places lookup failed: ' . Redactor::text($what));
            // Google names an exhausted quota and a refusal twice: in the HTTP status and in `error.status`.
            // Either sign counts. Quota is looked at first: it passes within minutes, a refusal does not.
            if ($status === 429 || $upstream === 'RESOURCE_EXHAUSTED') {
                return self::ERROR_QUOTA;
            }
            if ($status === 403 || $upstream === 'PERMISSION_DENIED') {
                return self::ERROR_REFUSED;
            }
            return self::ERROR_UPSTREAM;
        }
        // A search answers an object whose `places`, when present, is a list. A place by id answers the
        // place itself, with the id that is part of the mask.
        $readable = is_array($decoded) && ($byId
            ? is_string($decoded['id'] ?? null)
            : !array_key_exists('places', $decoded) || is_array($decoded['places']));
        if (!$readable) {
            $this->ledger()->record($sku, 1, $status, $latencyMs, 'bad_body', $mask);
            error_log('[tp] places lookup failed: bad_body');
            return self::ERROR_UPSTREAM;
        }
        $this->ledger()->record($sku, 1, $status, $latencyMs, null, $mask);
        return $decoded;
    }

    /**
     * A place of an answer as it is passed on. Nothing of it is kept by this class.
     *
     * @param array<int|string, mixed> $place one place object of Google's JSON
     * @return array{place_id: ?string, name: ?string, address: ?string, phone: ?string, website: ?string, maps_uri: ?string}
     */
    private static function place(array $place): array
    {
        $id = $place['id'] ?? null;
        return [
            'place_id' => is_string($id) && preg_match(self::PLACE_ID_FORM, $id) === 1 ? $id : null,
            'name' => self::text($place['displayName']['text'] ?? null, self::MAX_NAME),
            'address' => self::text($place['formattedAddress'] ?? null, self::MAX_ADDRESS),
            'phone' => self::text($place['nationalPhoneNumber'] ?? null, self::MAX_PHONE),
            'website' => self::link($place['websiteUri'] ?? null),
            'maps_uri' => self::link($place['googleMapsUri'] ?? null),
        ];
    }

    /**
     * @param array<string, ?string>|null $place
     * @return array{ok: bool, error: ?string, match: ?string, place: array<string, ?string>|null}
     */
    private static function answer(string $match, ?array $place): array
    {
        return ['ok' => true, 'error' => null, 'match' => $match, 'place' => $place];
    }

    /**
     * @return array{ok: bool, error: ?string, match: ?string, place: null}
     */
    private static function failure(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'match' => null, 'place' => null];
    }

    /** `error.status` of a Google error body ("PERMISSION_DENIED"), when it is a plain code. */
    private static function upstreamStatus(mixed $decoded): ?string
    {
        $status = is_array($decoded) && is_array($decoded['error'] ?? null) ? ($decoded['error']['status'] ?? null) : null;
        return is_string($status) && preg_match('/^[A-Z_]{1,40}$/D', $status) === 1 ? $status : null;
    }

    private static function isCoordinate(mixed $value, float $limit): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && abs((float) $value) <= $limit;
    }

    /** A text of the answer, trimmed and cut to its bound. Null when absent, empty or not valid text. */
    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
        if ($text === '') {
            return null;
        }
        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8') : $text;
    }

    /** A web address of the answer. A cut address is no address, so one that is too long is dropped. */
    private static function link(mixed $value): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $url = trim($value);
        if ($url === '' || mb_strlen($url, 'UTF-8') > self::MAX_URL || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }
        return preg_match('#^https?://[^/]#i', $url) === 1 ? $url : null;
    }

    private function http(): OutboundHttp
    {
        return $this->http ??= new OutboundHttp();
    }

    private function ledger(): ApiLedger
    {
        return $this->ledger ??= new ApiLedger();
    }
}
