<?php
namespace App\Services;

use App\Core\Config;
use App\Core\Database;

class GoogleMapsService
{
    private string $apiKey;
    /** Number of upstream sub-calls made by the most recent searchPlaces* call.
     *  searchPlacesNearby tiles into up to 5 sub-calls (1 + 4 quadrants);
     *  searchPlacesText paginates up to 3 pages. Controllers read this so
     *  api_usage_log records the real billable count, not "1". */
    public int $lastCallCount = 0;

    public function __construct()
    {
        $this->apiKey = Config::get('GOOGLE_API_KEY', '');
        if (!$this->apiKey) {
            // do not throw at construction — allow code paths that don't call APIs
        }
    }

    public function geocode(string $address): array
    {
        $cacheKey = 'geocode:' . md5(strtolower(trim($address)));
        $cached = CacheService::getJson($cacheKey);
        if ($cached) return $cached;

        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address='
             . urlencode($address) . '&key=' . $this->apiKey;
        $resp = $this->makeRequest($url, 'GET');
        $data = json_decode($resp, true);
        if (($data['status'] ?? '') !== 'OK' || empty($data['results'])) {
            throw new \RuntimeException('Geocoding failed: ' . ($data['status'] ?? 'unknown'));
        }
        $result = $data['results'][0];
        $loc = $result['geometry']['location'];
        $components = $this->parseComponents($result['address_components'] ?? []);
        $out = [
            'lat' => (float)$loc['lat'],
            'lng' => (float)$loc['lng'],
            'formatted_address' => $result['formatted_address'],
            'place_id' => $result['place_id'] ?? null,
            'components' => $components,
        ];
        CacheService::set($cacheKey, $out, 86400 * 365); // 1 year
        return $out;
    }

    public function reverseGeocode(float $lat, float $lng): array
    {
        $cacheKey = 'revgeo:' . md5("$lat,$lng");
        $cached = CacheService::getJson($cacheKey);
        if ($cached) return $cached;

        $url = 'https://maps.googleapis.com/maps/api/geocode/json?latlng='
             . $lat . ',' . $lng . '&key=' . $this->apiKey;
        $resp = $this->makeRequest($url, 'GET');
        $data = json_decode($resp, true);
        if (($data['status'] ?? '') !== 'OK' || empty($data['results'])) {
            throw new \RuntimeException('Reverse geocoding failed');
        }
        $result = $data['results'][0];
        $out = [
            'formatted_address' => $result['formatted_address'],
            'components' => $this->parseComponents($result['address_components'] ?? []),
        ];
        CacheService::set($cacheKey, $out, 86400 * 365);
        return $out;
    }

    public function batchGeocode(array $addresses, int $concurrency = 5): array
    {
        $results = [];
        $failures = [];
        $successCount = 0;

        $chunks = array_chunk($addresses, $concurrency, true);
        foreach ($chunks as $chunk) {
            foreach ($chunk as $idx => $addr) {
                try {
                    $geo = $this->geocode($addr);
                    $results[$idx] = $geo;
                    $successCount++;
                } catch (\Throwable $e) {
                    $results[$idx] = null;
                    $failures[] = ['index' => $idx, 'address' => $addr, 'error' => $e->getMessage()];
                }
            }
            usleep(20000); // 20ms between batches
        }
        return [
            'results' => $results,
            'success_count' => $successCount,
            'failure_count' => count($failures),
            'failures' => $failures,
        ];
    }

    /**
     * Places API (New) searchNearby caps maxResultCount at 20 — so a 5km
     * circle in a busy metro returns 20 even if there are 200 matches.
     * When the first call saturates (returns exactly 20), tile the original
     * circle into 4 quadrant sub-circles at radius/√2 and merge results.
     * Total cost on a saturated call: 5× the single-call cost (~$0.16 vs
     * $0.032); the cache then absorbs all repeats for 48h.
     *
     * Returned: deduplicated by place.id, capped at 200 to keep payloads
     * sane. Empirically a 5km saturated metro search yields ~80-140
     * unique POIs after tiling.
     */
    public function searchPlacesNearby(float $lat, float $lng, int $radiusMeters, ?string $type = null, ?string $keyword = null): array
    {
        $cacheKey = 'places_nearby_v2:' . md5("$lat,$lng,$radiusMeters,$type,$keyword");
        $cached = CacheService::getJson($cacheKey);
        if ($cached) {
            $this->lastCallCount = 0; // cache hit — no billable call
            return $cached;
        }

        $this->lastCallCount = 0;
        // First call — full radius.
        $places = $this->_searchNearbyCircle($lat, $lng, $radiusMeters, $type);
        $this->lastCallCount++;

        // Saturation heuristic: a saturated response is exactly maxResultCount.
        // Only tile when the user gave a radius large enough that splitting
        // it meaningfully samples different sub-areas (≥ 1km original).
        if (count($places) >= 20 && $radiusMeters >= 1000) {
            $byId = [];
            foreach ($places as $p) {
                if (!empty($p['id'])) $byId[$p['id']] = $p;
            }
            $subR = (int) round($radiusMeters / sqrt(2));
            $offsetM = $radiusMeters / 2; // distance from center to each quadrant center
            foreach ([[1, 1], [-1, 1], [1, -1], [-1, -1]] as [$dx, $dy]) {
                [$qLat, $qLng] = self::offsetMeters($lat, $lng, $dx * $offsetM, $dy * $offsetM);
                foreach ($this->_searchNearbyCircle($qLat, $qLng, $subR, $type) as $p) {
                    if (!empty($p['id'])) $byId[$p['id']] = $p;
                }
                $this->lastCallCount++;
                if (count($byId) >= 200) break;
            }
            $places = array_values($byId);
        }

        // Server-side keyword filter (case-insensitive name substring).
        if ($keyword) {
            $kwLower = strtolower($keyword);
            $places = array_values(array_filter($places, function ($p) use ($kwLower) {
                $name = strtolower($p['displayName']['text'] ?? '');
                return strpos($name, $kwLower) !== false;
            }));
        }

        // Sort by distance from the original center so the closest hits
        // come first — tiled merges otherwise jumble the order.
        usort($places, function ($a, $b) use ($lat, $lng) {
            $da = self::haversine($lat, $lng, $a['location']['latitude'] ?? 0, $a['location']['longitude'] ?? 0);
            $db = self::haversine($lat, $lng, $b['location']['latitude'] ?? 0, $b['location']['longitude'] ?? 0);
            return $da <=> $db;
        });

        CacheService::set($cacheKey, $places, 172800); // 48h
        return $places;
    }

    /** One Places searchNearby request — no tiling. */
    private function _searchNearbyCircle(float $lat, float $lng, int $radiusMeters, ?string $type): array
    {
        $url = 'https://places.googleapis.com/v1/places:searchNearby';
        $fieldMask = 'places.id,places.displayName,places.formattedAddress,places.location,'
                   . 'places.types,places.businessStatus,places.nationalPhoneNumber,'
                   . 'places.websiteUri,places.rating,places.userRatingCount';
        $payload = [
            'locationRestriction' => [
                'circle' => [
                    'center' => ['latitude' => $lat, 'longitude' => $lng],
                    'radius' => $radiusMeters,
                ],
            ],
            'maxResultCount' => 20,
            'languageCode' => 'en',
        ];
        if ($type) $payload['includedTypes'] = [$type];

        try {
            $resp = $this->makeRequest($url, 'POST', [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $this->apiKey,
                'X-Goog-FieldMask: ' . $fieldMask,
            ], json_encode($payload));
        } catch (\Throwable $e) {
            // Sub-tile failures shouldn't kill the whole search — log + skip.
            error_log('[places] sub-tile failed at ' . $lat . ',' . $lng . ': ' . $e->getMessage());
            return [];
        }
        $data = json_decode($resp, true);
        return $data['places'] ?? [];
    }

    /** Offset a (lat, lng) by (dx, dy) meters using a flat-earth approximation. */
    private static function offsetMeters(float $lat, float $lng, float $dxMeters, float $dyMeters): array
    {
        $dLat = $dyMeters / 111320.0;
        $dLng = $dxMeters / (111320.0 * max(0.000001, cos(deg2rad($lat))));
        return [$lat + $dLat, $lng + $dLng];
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000.0;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Text search — paginates up to 3 pages (60 total). Each page costs
     * the same as one searchText call; the nextPageToken from page N is
     * required to fetch page N+1 and stays valid ~2 minutes. Field mask
     * must include nextPageToken on every call or pagination silently
     * stops after page 1.
     */
    public function searchPlacesText(string $query, ?float $lat = null, ?float $lng = null, ?int $radiusMeters = null): array
    {
        $cacheKey = 'places_text_v2:' . md5("$query,$lat,$lng,$radiusMeters");
        $cached = CacheService::getJson($cacheKey);
        if ($cached) {
            $this->lastCallCount = 0;
            return $cached;
        }

        $url = 'https://places.googleapis.com/v1/places:searchText';
        $fieldMask = 'places.id,places.displayName,places.formattedAddress,places.location,'
                   . 'places.types,places.businessStatus,places.nationalPhoneNumber,'
                   . 'places.websiteUri,places.rating,places.userRatingCount,nextPageToken';

        $payload = ['textQuery' => $query, 'languageCode' => 'en', 'pageSize' => 20];
        if ($lat !== null && $lng !== null && $radiusMeters !== null) {
            $payload['locationBias'] = [
                'circle' => [
                    'center' => ['latitude' => $lat, 'longitude' => $lng],
                    'radius' => $radiusMeters,
                ],
            ];
        }

        $merged = [];
        $byId = [];
        $pageToken = null;
        $this->lastCallCount = 0;
        for ($page = 0; $page < 3; $page++) {
            if ($pageToken) $payload['pageToken'] = $pageToken;
            $resp = $this->makeRequest($url, 'POST', [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $this->apiKey,
                'X-Goog-FieldMask: ' . $fieldMask,
            ], json_encode($payload));
            $this->lastCallCount++;
            $data = json_decode($resp, true);
            foreach ($data['places'] ?? [] as $p) {
                if (!empty($p['id']) && !isset($byId[$p['id']])) {
                    $byId[$p['id']] = true;
                    $merged[] = $p;
                }
            }
            $pageToken = $data['nextPageToken'] ?? null;
            if (!$pageToken) break;
            // Google requires a brief delay before the next pageToken is
            // valid; without it the next call 400s with INVALID_ARGUMENT.
            usleep(200_000);
        }

        CacheService::set($cacheKey, $merged, 172800);
        return $merged;
    }

    public function getPlaceDetails(string $placeId): array
    {
        $cacheKey = 'place:' . $placeId;
        $cached = CacheService::getJson($cacheKey);
        if ($cached) return $cached;

        $url = 'https://places.googleapis.com/v1/places/' . urlencode($placeId);
        $fieldMask = 'id,displayName,formattedAddress,location,types,businessStatus,'
                   . 'nationalPhoneNumber,internationalPhoneNumber,websiteUri,rating,'
                   . 'userRatingCount,priceLevel,regularOpeningHours';
        $resp = $this->makeRequest($url, 'GET', [
            'X-Goog-Api-Key: ' . $this->apiKey,
            'X-Goog-FieldMask: ' . $fieldMask,
        ]);
        $data = json_decode($resp, true);
        CacheService::set($cacheKey, $data, 259200); // 72h
        return $data;
    }

    private function parseComponents(array $components): array
    {
        $out = ['city' => null, 'state' => null, 'zip' => null, 'country' => null];
        foreach ($components as $c) {
            $types = $c['types'] ?? [];
            if (in_array('locality', $types)) $out['city'] = $c['long_name'];
            elseif (in_array('administrative_area_level_1', $types)) $out['state'] = $c['short_name'];
            elseif (in_array('postal_code', $types)) $out['zip'] = $c['long_name'];
            elseif (in_array('country', $types)) $out['country'] = $c['short_name'];
        }
        return $out;
    }

    private function makeRequest(string $url, string $method, array $headers = [], ?string $body = null): string
    {
        $attempts = 0;
        $maxAttempts = 3;
        while ($attempts < $maxAttempts) {
            $attempts++;
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
            ];
            if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
            curl_setopt_array($ch, $opts);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($resp === false) {
                if ($attempts < $maxAttempts) { usleep(500000); continue; }
                throw new \RuntimeException('HTTP request failed: ' . $err);
            }
            if ($code >= 500 && $attempts < $maxAttempts) {
                usleep(500000);
                continue;
            }
            if ($code >= 400) {
                throw new \RuntimeException("HTTP $code from $url: $resp");
            }
            return $resp;
        }
        throw new \RuntimeException('Max retries exceeded');
    }

    /**
     * Record a Google API call + estimated cost. Caller passes a specific
     * api_name (e.g. 'geocode', 'places_nearby') which GooglePricing maps
     * to per-call USD. The cost lives in api_usage_log so the daily-spend
     * widget and the per-call toasts both read from one source of truth.
     */
    public function logApiUsage(string $endpoint, ?string $userId, ?string $apiName = null, int $count = 1): void
    {
        if (!$userId) return;
        // Default the api_name from the endpoint if the caller didn't specify
        // — old call sites pass just the endpoint name (e.g. 'geocode').
        $apiName = $apiName ?: $endpoint;
        $cost = \App\Services\GooglePricing::costFor($apiName, $count);
        try {
            Database::getInstance()->query(
                'INSERT INTO api_usage_log
                   (user_id, api_name, endpoint, request_count, estimated_cost_usd, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $apiName, $endpoint, $count, $cost, date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {}
    }
}
