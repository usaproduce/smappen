<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\MarketData\VendorCategoryRepository;
use App\MarketData\VendorCoverageRepository;
use App\MarketData\VendorLocationRepository;
use App\MarketData\VendorRepository;
use App\Services\VendorGeometryService;
use App\Services\VendorSearchService;

/**
 * The map-first surface — bbox pin queries, point-in-polygon "who serves
 * me", vendor detail with coverage geometry.
 *
 * Routes (auth):
 *   GET /api/vendors/map/bbox?minLat=&minLng=&maxLat=&maxLng=&type=&category=
 *   GET /api/vendors/map/serves?lat=&lng=
 *   GET /api/vendors/{id}/detail        — full vendor: locations + coverage + categories
 *
 * Browse is authed but cross-tenant by design — directory is shared
 * market reference.
 */
class VendorMapController
{
    private VendorRepository $vendors;
    private VendorLocationRepository $locations;
    private VendorCoverageRepository $coverage;
    private VendorCategoryRepository $categories;
    private VendorSearchService $search;
    private VendorGeometryService $geometry;

    public function __construct(
        ?VendorRepository $vendors = null,
        ?VendorLocationRepository $locations = null,
        ?VendorCoverageRepository $coverage = null,
        ?VendorCategoryRepository $categories = null,
        ?VendorSearchService $search = null,
        ?VendorGeometryService $geometry = null,
    ) {
        $this->vendors    = $vendors    ?? new VendorRepository();
        $this->locations  = $locations  ?? new VendorLocationRepository();
        $this->coverage   = $coverage   ?? new VendorCoverageRepository();
        $this->categories = $categories ?? new VendorCategoryRepository();
        $this->search     = $search     ?? new VendorSearchService();
        $this->geometry   = $geometry   ?? new VendorGeometryService($this->locations, $this->coverage);
    }

    /** Bbox query for map pins. Cap of 2000 — frontend clusters from there. */
    public function bbox(Request $request): void
    {
        $minLat = self::numQ($request, 'minLat');
        $minLng = self::numQ($request, 'minLng');
        $maxLat = self::numQ($request, 'maxLat');
        $maxLng = self::numQ($request, 'maxLng');
        if ($minLat === null || $minLng === null || $maxLat === null || $maxLng === null) {
            Response::error('minLat, minLng, maxLat, maxLng required', 422);
        }
        if ($minLat >= $maxLat || $minLng >= $maxLng) {
            Response::error('Invalid bbox: min must be < max', 422);
        }

        $rows = $this->locations->inBbox($minLat, $minLng, $maxLat, $maxLng, 2000);

        // Optional client-side filters layered on (cheap — already capped).
        $type = $request->getQuery('type');
        $cat  = $request->getQuery('category');
        if ($type) $rows = array_values(array_filter($rows, fn($r) => $r['type'] === $type));
        if ($cat)  $rows = array_values(array_filter($rows, fn($r) => $r['primary_category'] === $cat));

        Response::success(['pins' => $rows]);
    }

    /** "Drop a pin → who serves me." The spec's central interaction. */
    public function serves(Request $request): void
    {
        $lat = self::numQ($request, 'lat');
        $lng = self::numQ($request, 'lng');
        if ($lat === null || $lng === null) Response::error('lat + lng required', 422);
        $rows = $this->geometry->whoServesPoint($lat, $lng);
        // Annotate with primary-location distance so UI can sort by closeness.
        foreach ($rows as &$r) {
            $primary = $this->locations->primaryFor((string) $r['vendor_id']);
            $r['primary_location'] = $primary;
            $r['distance_miles']   = $primary ? self::haversineMiles($lat, $lng, (float)$primary['lat'], (float)$primary['lng']) : null;
        }
        // Honest ranking: affiliated → rating → distance.
        usort($rows, function ($a, $b) {
            if ((int)$a['is_affiliated'] !== (int)$b['is_affiliated']) return (int)$b['is_affiliated'] - (int)$a['is_affiliated'];
            $ar = (float)($a['aggregate_rating'] ?? 0);
            $br = (float)($b['aggregate_rating'] ?? 0);
            if ($ar !== $br) return $br <=> $ar;
            return (float)($a['distance_miles'] ?? 1e9) <=> (float)($b['distance_miles'] ?? 1e9);
        });
        Response::success([
            'pin'     => ['lat' => $lat, 'lng' => $lng],
            'vendors' => $rows,
        ]);
    }

    /** Full detail: vendor + locations + coverage geometry + categories. */
    public function detail(Request $request): void
    {
        $id = (string) $request->getParam('id');
        $vendor = $this->vendors->findById($id);
        if (!$vendor) Response::error('Vendor not found', 404);

        $locations  = $this->locations->listForVendor($id);
        $coverage   = $this->coverage->listForVendor($id);
        $categories = $this->categories->listForVendor($id);
        $listings   = $this->vendors->listingsFor($id);

        foreach ($coverage as &$c) {
            $c['geometry'] = $c['geom_json'] ? json_decode($c['geom_json'], true) : null;
            unset($c['geom_json']);
        }

        Response::success([
            'vendor'     => $vendor,
            'locations'  => $locations,
            'coverage'   => $coverage,
            'categories' => $categories,
            'listings'   => $listings,
        ]);
    }

    /** Search endpoint backing the map's filter chips + text box. */
    public function search(Request $request): void
    {
        $bboxRaw = $request->getQuery('bbox');
        $filters = [
            'q'            => $request->getQuery('q'),
            'type'         => $request->getQuery('type'),
            'category'     => $request->getQuery('category'),
            'min_rating'   => $request->getQuery('min_rating'),
            'claim_status' => $request->getQuery('claim_status'),
            'limit'        => $request->getQuery('limit'),
        ];
        if ($bboxRaw) {
            $parts = explode(',', (string) $bboxRaw);
            if (count($parts) === 4) $filters['bbox'] = array_map('floatval', $parts);
        }
        Response::success(['vendors' => $this->search->search($filters)]);
    }

    // ──────────────────────────── helpers ────────────────────────────

    private static function numQ(Request $request, string $name): ?float
    {
        $v = $request->getQuery($name);
        if ($v === null || $v === '') return null;
        return is_numeric($v) ? (float) $v : null;
    }

    /** Haversine distance in statute miles between two (lat,lng) points. */
    private static function haversineMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 3958.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return round(2 * $r * asin(min(1.0, sqrt($a))), 2);
    }
}
