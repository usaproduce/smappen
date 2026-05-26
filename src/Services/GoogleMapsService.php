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
        // Bump the cache namespace to v3 — the tiling strategy changed and
        // v2 entries would serve under-fetched results otherwise.
        $cacheKey = 'places_nearby_v3:' . md5("$lat,$lng,$radiusMeters,$type,$keyword");
        $cached = CacheService::getJson($cacheKey);
        if ($cached) {
            $this->lastCallCount = 0; // cache hit — no billable call
            return $cached;
        }

        $this->lastCallCount = 0;
        $byId = [];
        // Recursive quadrant tiling: every saturated tile (returns 20 = the
        // Places-New maxResultCount) is split into 4 sub-circles and re-
        // searched. Depth 2 = up to 1 + 4 + 16 = 21 calls per query, which
        // covers everything a 5-10 km dense-urban area can throw at us.
        // Hard cap at 1000 unique places to keep payloads sane on the rare
        // "find every cafe in Manhattan" case.
        $this->searchTile($lat, $lng, $radiusMeters, $type, 0, 2, $byId, 1000);
        $places = array_values($byId);

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

        CacheService::set($cacheKey, $places, 86400 * 90); // 90 days
        return $places;
    }

    /**
     * Recursive one-circle search. Accumulates unique results into $byId
     * (passed by reference) and recurses into 4 quadrants when the parent
     * tile saturates AND we haven't hit max depth or the cap.
     */
    private function searchTile(float $lat, float $lng, int $radiusMeters, ?string $type, int $depth, int $maxDepth, array &$byId, int $cap): void
    {
        if (count($byId) >= $cap) return;
        if ($radiusMeters < 200) return; // smaller than a city block — diminishing returns

        $places = $this->_searchNearbyCircle($lat, $lng, $radiusMeters, $type);
        $this->lastCallCount++;
        foreach ($places as $p) {
            if (!empty($p['id'])) $byId[$p['id']] = $p;
            if (count($byId) >= $cap) return;
        }
        if (count($places) >= 20 && $depth < $maxDepth) {
            $subR = (int) round($radiusMeters / sqrt(2));
            $offsetM = $radiusMeters / 2;
            foreach ([[1, 1], [-1, 1], [1, -1], [-1, -1]] as [$dx, $dy]) {
                [$qLat, $qLng] = self::offsetMeters($lat, $lng, $dx * $offsetM, $dy * $offsetM);
                $this->searchTile($qLat, $qLng, $subR, $type, $depth + 1, $maxDepth, $byId, $cap);
            }
        }
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
        // Bumped to v3: results from v2 saturated at the 60-place pagination
        // cap (3 pages × pageSize 20) with no geographic tiling, which made
        // the benchmark comparison meaningless (every reference returned
        // exactly 60). v3 hard-bounds each tile with a bbox locationRestriction
        // and recursively splits saturated tiles into quadrants.
        $cacheKey = 'places_text_v3:' . md5("$query,$lat,$lng,$radiusMeters");
        $cached = CacheService::getJson($cacheKey);
        if ($cached) {
            $this->lastCallCount = 0;
            return $cached;
        }

        $this->lastCallCount = 0;
        $byId = [];

        if ($lat !== null && $lng !== null && $radiusMeters !== null) {
            // Bounded search — recursive quadrant tiling. Depth 2 = up to
            // 1 + 4 + 16 = 21 tiles, each tile up to 3 paginated API calls,
            // worst-case 63 billable calls = ~$2.00 per query (rare; only
            // triggered when every tile hits 60 saturation). Cap at 500
            // unique places.
            $this->textSearchTile($query, $lat, $lng, $radiusMeters, 0, 2, $byId, 500);
        } else {
            // Unbounded search — original 3-page pagination, no tiling
            // possible without a center.
            $this->textSearchPage($query, null, null, null, $byId, 500);
        }

        $places = array_values($byId);

        if ($lat !== null && $lng !== null) {
            usort($places, function ($a, $b) use ($lat, $lng) {
                $da = self::haversine($lat, $lng, $a['location']['latitude'] ?? 0, $a['location']['longitude'] ?? 0);
                $db = self::haversine($lat, $lng, $b['location']['latitude'] ?? 0, $b['location']['longitude'] ?? 0);
                return $da <=> $db;
            });
        }

        CacheService::set($cacheKey, $places, 86400 * 90); // 90 days
        return $places;
    }

    /**
     * Recursive text-search tile. Splits saturated tiles into 4 quadrants
     * so dense urban searches (think "pizza" in NYC) can exceed the per-
     * query 60-result cap. Stops at min tile radius 500m or max depth.
     */
    private function textSearchTile(string $query, float $lat, float $lng, int $radiusMeters, int $depth, int $maxDepth, array &$byId, int $cap): void
    {
        if (count($byId) >= $cap) return;
        if ($radiusMeters < 500) return;

        $before = count($byId);
        $this->textSearchPage($query, $lat, $lng, $radiusMeters, $byId, $cap);
        $added = count($byId) - $before;

        if ($added >= 60 && $depth < $maxDepth) {
            $subR = (int) round($radiusMeters / sqrt(2));
            $offsetM = $radiusMeters / 2;
            foreach ([[1, 1], [-1, 1], [1, -1], [-1, -1]] as [$dx, $dy]) {
                [$qLat, $qLng] = self::offsetMeters($lat, $lng, $dx * $offsetM, $dy * $offsetM);
                $this->textSearchTile($query, $qLat, $qLng, $subR, $depth + 1, $maxDepth, $byId, $cap);
            }
        }
    }

    /**
     * One bounded text search, up to 3 paginated pages, dedupes into $byId.
     * Uses locationRestriction (rectangle) so tile splits actually return
     * different result sets — locationBias is a soft preference and would
     * leak global results across every tile, defeating recursion.
     */
    private function textSearchPage(string $query, ?float $lat, ?float $lng, ?int $radiusMeters, array &$byId, int $cap): void
    {
        $url = 'https://places.googleapis.com/v1/places:searchText';
        $fieldMask = 'places.id,places.displayName,places.formattedAddress,places.location,'
                   . 'places.types,places.businessStatus,places.nationalPhoneNumber,'
                   . 'places.websiteUri,places.rating,places.userRatingCount,nextPageToken';

        $payload = ['textQuery' => $query, 'languageCode' => 'en', 'pageSize' => 20];
        if ($lat !== null && $lng !== null && $radiusMeters !== null) {
            [$minLng, $minLat, $maxLng, $maxLat] = self::circleToBbox($lat, $lng, $radiusMeters);
            $payload['locationRestriction'] = [
                'rectangle' => [
                    'low'  => ['latitude' => $minLat, 'longitude' => $minLng],
                    'high' => ['latitude' => $maxLat, 'longitude' => $maxLng],
                ],
            ];
        }

        $pageToken = null;
        for ($page = 0; $page < 3; $page++) {
            if (count($byId) >= $cap) return;
            if ($pageToken) $payload['pageToken'] = $pageToken;
            try {
                $resp = $this->makeRequest($url, 'POST', [
                    'Content-Type: application/json',
                    'X-Goog-Api-Key: ' . $this->apiKey,
                    'X-Goog-FieldMask: ' . $fieldMask,
                ], json_encode($payload));
            } catch (\Throwable $e) {
                error_log('[places-text] tile failed at ' . ($lat ?? '?') . ',' . ($lng ?? '?') . ' r=' . ($radiusMeters ?? '?') . ': ' . $e->getMessage());
                return;
            }
            $this->lastCallCount++;
            $data = json_decode($resp, true);
            foreach ($data['places'] ?? [] as $p) {
                if (!empty($p['id']) && !isset($byId[$p['id']])) {
                    $byId[$p['id']] = $p;
                }
            }
            $pageToken = $data['nextPageToken'] ?? null;
            if (!$pageToken) break;
            usleep(200_000);
        }
    }

    /**
     * Circle (lat, lng, meters) → axis-aligned bbox [minLng, minLat, maxLng, maxLat]
     * using equirectangular projection. Good enough for the tile sizes we
     * search (up to ~50km, where the cos(lat) approximation is fine).
     */
    private static function circleToBbox(float $lat, float $lng, int $radiusMeters): array
    {
        $dLat = $radiusMeters / 111320.0;
        $dLng = $radiusMeters / (111320.0 * max(0.000001, cos(deg2rad($lat))));
        return [
            $lng - $dLng,
            $lat - $dLat,
            $lng + $dLng,
            $lat + $dLat,
        ];
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
                CURLOPT_CONNECTTIMEOUT => 3,
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
