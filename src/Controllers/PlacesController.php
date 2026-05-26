<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Models\Area;
use App\Models\Project;
use App\Models\POICache;
use App\Services\GoogleMapsService;
use App\Services\GeoUtils;

class PlacesController
{
    public function nearby(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $lat = (float)($body['lat'] ?? 0);
        $lng = (float)($body['lng'] ?? 0);
        $radius = min(50000, max(1, (int)($body['radius_meters'] ?? 5000)));
        $type = $body['type'] ?? null;
        $keyword = $body['keyword'] ?? null;
        $areaId = $body['area_id'] ?? null;

        // Size the search to the area's actual extent instead of the 5km
        // default. A 30-km² isochrone fits in a ~3km radius circle; a
        // 200-km² one needs ~8km. Without this the old code missed
        // businesses on the rim of large areas and over-fetched on small.
        // Also pull population from the demographics cache so we can
        // compute per-capita concentration later.
        $areaGeometry = null;
        $areaSqKm = null;
        $areaPop = null;
        if ($areaId) {
            $area = Area::findById($areaId);
            // Ownership check first — moved up so we don't read another
            // org's geometry into our request before failing.
            if ($area) {
                $proj = Project::findById($area['project_id']);
                if (!$proj || $proj['organization_id'] !== $request->user['organization_id']) {
                    Response::error('Access denied', 403);
                }
            }
            if ($area && !empty($area['geometry'])) {
                $geom = is_string($area['geometry']) ? json_decode($area['geometry'], true) : $area['geometry'];
                if (is_array($geom) && isset($geom['type'])) {
                    $areaGeometry = $geom;
                    $areaSqKm = GeoUtils::calculateArea($geom);
                    [$minLng, $minLat, $maxLng, $maxLat] = GeoUtils::getBoundingBox($geom);
                    $lat = ($minLat + $maxLat) / 2;
                    $lng = ($minLng + $maxLng) / 2;
                    // Half-diagonal of the bbox, in meters, gives a circle
                    // that fully contains the area. cos(lat) shrinks the lng
                    // axis at higher latitudes.
                    $halfLatM = (($maxLat - $minLat) / 2) * 111320.0;
                    $halfLngM = (($maxLng - $minLng) / 2) * 111320.0 * cos(deg2rad($lat));
                    $diag = sqrt($halfLatM * $halfLatM + $halfLngM * $halfLngM);
                    $radius = min(50000, max(1000, (int) ceil($diag)));
                }
                if (!empty($area['demographics_cache'])) {
                    // Area::findById decodes JSON columns to arrays on load,
                    // so demographics_cache is already an array. Older paths
                    // still hit this code with a raw string, so handle both.
                    $cache = is_string($area['demographics_cache'])
                        ? json_decode($area['demographics_cache'], true)
                        : $area['demographics_cache'];
                    if (is_array($cache)) $areaPop = $cache['population']['total'] ?? null;
                }
            }
        }

        // Places API (New) searchNearby ONLY accepts the Table A type
        // strings (https://developers.google.com/maps/documentation/places/web-service/place-types)
        // and REQUIRES includedTypes to be non-empty. The frontend chip
        // strip had "store" (not in Table A) and "Any" (empty) — both
        // produced silent 0-result responses. Map the chip values to
        // valid types here so existing UI doesn't need to change, then
        // route to searchText when there's no valid type.
        static $TABLE_A_MAP = [
            // chip value     => valid Table A type
            'restaurant'     => 'restaurant',
            'cafe'           => 'cafe',
            'pharmacy'       => 'pharmacy',
            'gym'            => 'gym',
            'school'         => 'school',
            'hospital'       => 'hospital',
            'bank'           => 'bank',
            'gas_station'    => 'gas_station',
            // "store" isn't in Table A — fall back to grocery_store which
            // is the closest single-category match for the user's intent.
            'store'          => 'grocery_store',
        ];
        $validType = $type ? ($TABLE_A_MAP[$type] ?? null) : null;

        try {
            $svc = new GoogleMapsService();
            if ($validType) {
                // Use searchNearby with a valid Table A type. Keyword (if
                // provided) is applied as a server-side name-substring
                // filter inside the service — fine when we have a type.
                $places = $svc->searchPlacesNearby($lat, $lng, $radius, $validType, $keyword);
            } else {
                // No usable type — route through searchText, which accepts
                // free-text queries and doesn't require includedTypes.
                // Build a query that approximates what the user wanted:
                //   keyword "coffee" + no type  → "coffee"
                //   no keyword + chip "Any"     → "businesses"
                $query = $keyword
                    ? trim($keyword)
                    : ($type ? trim($type) : 'businesses');
                $places = $svc->searchPlacesText($query, $lat, $lng, $radius);
                // searchText already keyword-matches in the query, no need
                // to re-filter client-side.
                $keyword = null;
            }
            // Log the actual number of upstream calls (tiling for nearby:
            // 1-5; text pagination: 1-3). Zero on cache hit — don't bill
            // the user for a free response.
            $apiName = $validType ? 'places_nearby' : 'places_text';
            if ($svc->lastCallCount > 0) {
                $svc->logApiUsage($apiName, $request->user['id'], $apiName, $svc->lastCallCount);
            }
        } catch (\Throwable $e) {
            self::handleGoogleError($e, 'places');
        }

        if ($areaGeometry) {
            $places = array_values(array_filter($places, function ($p) use ($areaGeometry) {
                $loc = $p['location'] ?? null;
                if (!$loc) return false;
                return GeoUtils::pointInPolygon(
                    (float)($loc['latitude'] ?? 0),
                    (float)($loc['longitude'] ?? 0),
                    $areaGeometry
                );
            }));
            POICache::store(md5('area:' . $areaId), $areaId, $places);
        }

        $count = count($places);
        // Concentration metrics — primarily so the operator can read at a
        // glance whether "16 cafes" is dense or sparse for the area's size.
        $densityPerSqKm = ($areaSqKm && $areaSqKm > 0) ? round($count / $areaSqKm, 2) : null;
        $densityPer1k   = ($areaPop && $areaPop > 0)   ? round($count / ($areaPop / 1000), 2) : null;
        $densityLabel   = self::densityLabel($densityPerSqKm);

        Response::success([
            'places' => $places,
            'count' => $count,
            'area_sq_km' => $areaSqKm === null ? null : round($areaSqKm, 2),
            'area_population' => $areaPop,
            'density_per_sq_km' => $densityPerSqKm,
            'density_per_1k_people' => $densityPer1k,
            'density_label' => $densityLabel,
            '_meta' => [
                'api_name' => $validType ? 'places_nearby' : 'places_text',
                'upstream_calls' => $svc->lastCallCount,
                'search_radius_meters' => $radius,
                'estimated_cost_usd' => \App\Services\GooglePricing::costFor(
                    $validType ? 'places_nearby' : 'places_text',
                    max(1, $svc->lastCallCount)
                ),
            ],
        ]);
    }

    /**
     * Coarse density bucket. Intentionally generic — categorising "5 gyms
     * per km²" as Dense and "5 schools per km²" as Very Dense would need
     * per-category baselines we don't have benchmarked yet. The numbers
     * here roughly mirror US Census Bureau Business Patterns density
     * tiers for "all retail+services" in metro areas.
     */
    private static function densityLabel(?float $perSqKm): ?string
    {
        if ($perSqKm === null) return null;
        if ($perSqKm < 0.5) return 'Sparse';
        if ($perSqKm < 2)   return 'Moderate';
        if ($perSqKm < 10)  return 'Dense';
        return 'Very dense';
    }

    public function search(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $query = trim($body['query'] ?? '');
        if ($query === '') Response::error('query is required');
        $lat = isset($body['lat']) ? (float)$body['lat'] : null;
        $lng = isset($body['lng']) ? (float)$body['lng'] : null;
        $radius = isset($body['radius_meters']) ? (int)$body['radius_meters'] : null;

        try {
            $svc = new GoogleMapsService();
            $places = $svc->searchPlacesText($query, $lat, $lng, $radius);
            if ($svc->lastCallCount > 0) {
                $svc->logApiUsage('places_search', $request->user['id'], 'places_text', $svc->lastCallCount);
            }
            Response::success([
                'places' => $places,
                'count' => count($places),
                '_meta' => [
                    'api_name' => 'places_text',
                    'upstream_calls' => $svc->lastCallCount,
                    'estimated_cost_usd' => \App\Services\GooglePricing::costFor('places_text', max(1, $svc->lastCallCount)),
                ],
            ]);
        } catch (\Throwable $e) {
            self::handleGoogleError($e, 'places');
        }
    }

    public function show(Request $request): void
    {
        $placeId = $request->getParam('placeId');
        try {
            $svc = new GoogleMapsService();
            $details = $svc->getPlaceDetails($placeId);
            $svc->logApiUsage('place_details', $request->user['id'] ?? null, 'place_details');
            $details['_meta'] = [
                'api_name' => 'place_details',
                'estimated_cost_usd' => \App\Services\GooglePricing::costFor('place_details'),
            ];
            Response::success($details);
        } catch (\Throwable $e) {
            self::handleGoogleError($e, 'place_details');
        }
    }

    public function forArea(Request $request): void
    {
        $areaId = $request->getParam('id');
        $area = Area::findById($areaId);
        if (!$area) Response::error('Area not found', 404);
        $project = Project::findById($area['project_id']);
        if (!$project || $project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        $cached = POICache::get(md5('area:' . $areaId));
        if ($cached) {
            Response::success(['places' => $cached['results'], 'cached_at' => $cached['cached_at']]);
        }
        Response::success(['places' => [], 'message' => 'No POIs cached. Run a nearby search first.']);
    }

    /**
     * Translate the raw Google API failure (which is a giant JSON blob in the
     * exception message) into a user-friendly error. Specifically detects the
     * "API not enabled" 403 and surfaces a direct enable URL pulled from the
     * details payload, so users don't have to parse Google's response themselves.
     */
    private static function handleGoogleError(\Throwable $e, string $kind = 'places'): void
    {
        $msg = $e->getMessage();
        // The exception from makeRequest looks like: "HTTP 403 from https://...: {json}"
        $reason = null;
        $enableUrl = null;
        $serviceTitle = null;
        if (preg_match('/HTTP\s+(\d+)/', $msg, $hm)) {
            $code = (int) $hm[1];
            // Try to extract the JSON body.
            $jsonStart = strpos($msg, '{');
            if ($jsonStart !== false) {
                $body = substr($msg, $jsonStart);
                $parsed = json_decode($body, true);
                if (is_array($parsed)) {
                    $reason = $parsed['error']['details'][0]['reason'] ?? null;
                    $serviceTitle = $parsed['error']['details'][0]['metadata']['serviceTitle'] ?? null;
                    foreach (($parsed['error']['details'] ?? []) as $d) {
                        if (!empty($d['metadata']['activationUrl'])) { $enableUrl = $d['metadata']['activationUrl']; break; }
                        if (!empty($d['links'][0]['url']) && str_contains($d['links'][0]['url'], 'console')) {
                            $enableUrl = $d['links'][0]['url']; break;
                        }
                    }
                }
            }
            if ($reason === 'SERVICE_DISABLED' || ($code === 403 && $enableUrl)) {
                $name = $serviceTitle ?: 'Google Places API';
                Response::error(
                    "$name is not enabled on your Google Cloud project. Enable it in the Google Cloud Console, then wait ~5 minutes for the change to propagate before retrying.",
                    403,
                    ['enable_url' => $enableUrl, 'service' => $name]
                );
            }
            if ($code === 429) {
                Response::error('Google rate-limited this request. Wait a moment and try again.', 429);
            }
        }
        // Generic fallback — don't leak the raw Google JSON payload to the user.
        error_log('[places] ' . $kind . ' upstream error: ' . substr($msg, 0, 1000));
        Response::error('Search failed upstream. Please try again.', 502);
    }
}
