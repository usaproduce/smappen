<?php
namespace App\Services;

use App\Core\Config;
use App\Core\Database;

class GoogleMapsService
{
    private string $apiKey;

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

    public function searchPlacesNearby(float $lat, float $lng, int $radiusMeters, ?string $type = null, ?string $keyword = null): array
    {
        $cacheKey = 'places_nearby:' . md5("$lat,$lng,$radiusMeters,$type,$keyword");
        $cached = CacheService::getJson($cacheKey);
        if ($cached) return $cached;

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

        $resp = $this->makeRequest($url, 'POST', [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $this->apiKey,
            'X-Goog-FieldMask: ' . $fieldMask,
        ], json_encode($payload));
        $data = json_decode($resp, true);
        $places = $data['places'] ?? [];

        if ($keyword) {
            $kwLower = strtolower($keyword);
            $places = array_values(array_filter($places, function ($p) use ($kwLower) {
                $name = strtolower($p['displayName']['text'] ?? '');
                return strpos($name, $kwLower) !== false;
            }));
        }
        CacheService::set($cacheKey, $places, 172800); // 48h
        return $places;
    }

    public function searchPlacesText(string $query, ?float $lat = null, ?float $lng = null, ?int $radiusMeters = null): array
    {
        $cacheKey = 'places_text:' . md5("$query,$lat,$lng,$radiusMeters");
        $cached = CacheService::getJson($cacheKey);
        if ($cached) return $cached;

        $url = 'https://places.googleapis.com/v1/places:searchText';
        $fieldMask = 'places.id,places.displayName,places.formattedAddress,places.location,'
                   . 'places.types,places.businessStatus,places.nationalPhoneNumber,'
                   . 'places.websiteUri,places.rating,places.userRatingCount';
        $payload = ['textQuery' => $query, 'languageCode' => 'en'];
        if ($lat !== null && $lng !== null && $radiusMeters !== null) {
            $payload['locationBias'] = [
                'circle' => [
                    'center' => ['latitude' => $lat, 'longitude' => $lng],
                    'radius' => $radiusMeters,
                ],
            ];
        }
        $resp = $this->makeRequest($url, 'POST', [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $this->apiKey,
            'X-Goog-FieldMask: ' . $fieldMask,
        ], json_encode($payload));
        $data = json_decode($resp, true);
        $places = $data['places'] ?? [];
        CacheService::set($cacheKey, $places, 172800);
        return $places;
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

    public function logApiUsage(string $endpoint, ?string $userId): void
    {
        if (!$userId) return;
        try {
            // Raw INSERT — api_usage_log has BIGINT AUTO_INCREMENT id, not UUID.
            Database::getInstance()->query(
                'INSERT INTO api_usage_log (user_id, api_name, endpoint, request_count, created_at)
                 VALUES (?, ?, ?, 1, ?)',
                [$userId, 'google_maps', $endpoint, date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {}
    }
}
