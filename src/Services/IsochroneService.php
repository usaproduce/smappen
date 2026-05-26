<?php
namespace App\Services;

use App\Core\Config;

class IsochroneService
{
    /**
     * Default endpoint when ORS_BASE_URL is not set. Carafe v3 §4.5
     * recommends self-hosted ORS at scale ("free public tier dies at
     * scale") — set ORS_BASE_URL=http://ors-host:8080/v2/isochrones/
     * to point at the self-hosted instance without touching code.
     */
    private const DEFAULT_ENDPOINT = 'https://api.openrouteservice.org/v2/isochrones/';
    private const VALID_MODES = ['driving-car', 'cycling-regular', 'foot-walking', 'wheelchair'];

    private static function endpoint(): string
    {
        return (string) (Config::get('ORS_BASE_URL', self::DEFAULT_ENDPOINT));
    }

    public function calculate(float $lat, float $lng, int $timeMinutes, string $travelMode = 'driving-car'): array
    {
        if (!in_array($travelMode, self::VALID_MODES, true)) {
            throw new \InvalidArgumentException('Invalid travel mode');
        }
        // v2 prefix invalidates pre-smoothing=0 cached results.
        $cacheKey = 'iso:v2:' . md5("{$lat},{$lng},{$timeMinutes},{$travelMode}");
        $cached = CacheService::getJson($cacheKey);
        if ($cached) return $cached;

        $apiKey       = Config::get('ORS_API_KEY');
        $isSelfHosted = Config::get('ORS_BASE_URL') !== null;
        // Public ORS requires the key; self-hosted normally runs without auth.
        if (!$apiKey && !$isSelfHosted) {
            throw new \RuntimeException('ORS_API_KEY not configured (or set ORS_BASE_URL for self-hosted ORS)');
        }

        $url = self::endpoint() . $travelMode;
        // smoothing=0 → max detail, polygon hugs the road network.
        // attributes: area + reachfactor for UI display.
        // area_units: km for consistency with our display.
        $payload = [
            'locations' => [[$lng, $lat]],
            'range' => [$timeMinutes * 60],
            'range_type' => 'time',
            'smoothing' => 0,
            'attributes' => ['area', 'reachfactor'],
            'area_units' => 'km',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array_values(array_filter([
                $apiKey ? 'Authorization: ' . $apiKey : null,
                'Content-Type: application/json; charset=utf-8',
                // ORS only serves application/geo+json — asking for application/json gives 406.
                'Accept: application/geo+json, application/json',
            ])),
            // smoothing=0 + long travel times can take 30-60s on the public ORS endpoint.
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) throw new \RuntimeException('ORS request failed: ' . $err);
        if ($code !== 200) throw new \RuntimeException('ORS HTTP ' . $code . ': ' . $response);

        $data = json_decode($response, true);
        if (!isset($data['features'][0]['geometry'])) {
            throw new \RuntimeException('Invalid ORS response');
        }
        $geometry = $data['features'][0]['geometry'];
        $wkt = GeoUtils::geoJsonToWkt($geometry);
        $bbox = GeoUtils::getBoundingBox($geometry);
        $areaSqKm = GeoUtils::calculateArea($geometry);

        $result = [
            'geojson' => $geometry,
            'wkt' => $wkt,
            'area_sq_km' => $areaSqKm,
            'bbox' => $bbox,
            'travel_mode' => $travelMode,
            'time_minutes' => $timeMinutes,
            'center' => ['lat' => $lat, 'lng' => $lng],
        ];
        CacheService::set($cacheKey, $result, 86400);
        return $result;
    }

    public function calculateRadius(float $lat, float $lng, float $radiusKm): array
    {
        $geometry = GeoUtils::generateCirclePolygon($lat, $lng, $radiusKm);
        return [
            'geojson' => $geometry,
            'wkt' => GeoUtils::geoJsonToWkt($geometry),
            'area_sq_km' => M_PI * $radiusKm * $radiusKm,
            'bbox' => GeoUtils::getBoundingBox($geometry),
            'travel_mode' => 'radius',
            'radius_km' => $radiusKm,
            'center' => ['lat' => $lat, 'lng' => $lng],
        ];
    }
}
