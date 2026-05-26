<?php
declare(strict_types=1);

namespace App\Services;

use App\MarketData\VendorCoverageRepository;
use App\MarketData\VendorLocationRepository;

/**
 * Coverage geometry derivation + "who serves me" resolution.
 *
 * Three coverage strategies (parent spec §4.2):
 *   delivery          → polygon supplied by vendor / operator review
 *   pickup_drivetime  → ORS isochrone around the location (warehouse / will-call)
 *   radius            → fallback circle when nothing better is available
 *
 * Phase 2a ships the radius fallback path so seeded vendors are
 * immediately useful in the point-in-polygon "who serves me" query.
 * Real isochrone derivation hits IsochroneService (already in the base);
 * cron-driven recompute can swap radius → isochrone for vendor types
 * where pickup-drivetime makes more sense.
 */
class VendorGeometryService
{
    /** Circle approximation as a 24-vertex polygon. Cheap, no external calls. */
    private const CIRCLE_VERTICES = 24;
    /** Earth radius in miles for the back-of-envelope lat/lng conversion. */
    private const EARTH_MI = 3958.8;

    public function __construct(
        private VendorLocationRepository $locations,
        private VendorCoverageRepository $coverage,
        private ?IsochroneService $isochrone = null,
    ) {
        $this->isochrone ??= new IsochroneService();
    }

    /**
     * Drive-time minutes to fetch as an isochrone for each vendor type,
     * per spec §4.5: "60/90-min drive-time isochrones for delivery
     * vendors, 30-min for cash-and-carry; radius fallback when no road
     * result." Supports both the spec §2 types and the legacy
     * vendors.type enum values from migration 026.
     *
     * Returns null when this type should NOT get an isochrone (e.g.
     * local_grocery — too small-scale, falls back to radius).
     */
    public static function isochroneMinutesForType(?string $vendorType): ?int
    {
        return match ($vendorType) {
            'broadline'              => 90,   // national distributors — wide reach
            'cash_carry'             => 30,   // operators drive TO the warehouse
            'warehouse'              => 30,   // legacy enum equivalent of cash_carry
            'produce'                => 60,
            'meat',     'protein'    => 60,
            'seafood'                => 60,
            'dairy_bakery_bev', 'bakery_dairy_beverage' => 45,
            'specialty_ethnic', 'specialty'             => 45,
            'local_grocery', 'grocery'                  => null,
            'smallwares_equip'                          => null,
            default                                     => null,
        };
    }

    /**
     * Generate an isochrone for a vendor location and upsert a
     * vendor_coverage row. Falls back to a radius polygon on any ORS
     * failure so a single isochrone outage doesn't leave the vendor
     * with NO coverage. Returns the WKT that was written.
     *
     * Spec §4.5: "60/90-min drive-time isochrones for delivery vendors,
     * 30-min for cash-and-carry; radius fallback when no road result."
     */
    public function setIsochroneCoverage(string $vendorId, string $locationId, float $lat, float $lng, int $minutes, string $coverageType = 'pickup_drivetime', float $fallbackRadiusMiles = 25.0): string
    {
        try {
            $iso = $this->isochrone->calculate($lat, $lng, $minutes, 'driving-car');
            // IsochroneService returns axis-flipped GeoJSON (ORS gives lng,lat;
            // we store lat,lng per the project's SRID 4326 convention).
            $wkt = $iso['wkt'];
            $this->coverage->create($vendorId, $locationId, [
                'coverage_type'  => $coverageType,
                'wkt'            => $wkt,
                'travel_mode'    => 'driving-car',
                'travel_minutes' => $minutes,
                'confidence'     => 75,
                'source'         => 'ors_isochrone',
            ]);
            return $wkt;
        } catch (\Throwable $e) {
            error_log("[coverage] isochrone failed for vendor $vendorId loc $locationId: " . $e->getMessage() . ' — falling back to radius');
            return $this->setRadiusFallback($vendorId, $locationId, $lat, $lng, $fallbackRadiusMiles);
        }
    }

    /**
     * Generate a radius-fallback polygon centered on a vendor location and
     * upsert a vendor_coverage row. Returns the WKT for callers that want
     * to log it.
     */
    public function setRadiusFallback(string $vendorId, string $locationId, float $lat, float $lng, float $radiusMiles): string
    {
        $wkt = self::circleWkt($lat, $lng, $radiusMiles);
        $this->coverage->create($vendorId, $locationId, [
            'coverage_type' => 'radius',
            'wkt'           => $wkt,
            'radius_miles'  => $radiusMiles,
            'confidence'    => 30,
            'source'        => 'radius_fallback',
        ]);
        return $wkt;
    }

    /**
     * Wholesale "ensure every primary location has at least some coverage".
     * Used after a chain seed or sweep+classify. Prefers isochrone over
     * radius when the vendor type warrants it (spec §4.5); fallback to
     * radius when isochrone isn't appropriate or ORS errors out.
     */
    public function ensureCoverageForVendor(string $vendorId, string $vendorType): int
    {
        $locs = $this->locations->listForVendor($vendorId);
        $existing = $this->coverage->listForVendor($vendorId);
        $covered = [];
        foreach ($existing as $c) $covered[(string) $c['location_id']] = true;

        $minutes = self::isochroneMinutesForType($vendorType);
        $radius  = self::defaultRadiusMiles($vendorType);
        $count = 0;
        foreach ($locs as $loc) {
            if (isset($covered[(string) $loc['id']])) continue;
            $lat = (float) $loc['lat'];
            $lng = (float) $loc['lng'];
            if ($minutes !== null) {
                // pickup_drivetime is the spec §4.5 coverage_type for
                // operator-comes-to-warehouse vendors (cash_carry); for
                // delivery vendors we approximate the delivery zone
                // with the drive-time isochrone and tag it 'delivery'.
                $coverageType = self::coverageTypeForVendorType($vendorType);
                $this->setIsochroneCoverage(
                    $vendorId, (string) $loc['id'], $lat, $lng, $minutes,
                    $coverageType, $radius
                );
            } else {
                $this->setRadiusFallback($vendorId, (string) $loc['id'], $lat, $lng, $radius);
            }
            $count++;
        }
        return $count;
    }

    /** Spec §4.5: cash_carry → pickup_drivetime; delivery types → delivery. */
    public static function coverageTypeForVendorType(?string $vendorType): string
    {
        return match ($vendorType) {
            'cash_carry', 'warehouse' => 'pickup_drivetime',
            default                   => 'delivery',
        };
    }

    /**
     * Populate the three Douglas-Peucker simplified columns for one
     * coverage row, via MySQL ST_Simplify. Tolerances are in degrees
     * (SRID 4326): 0.001 ≈ 100 m, 0.01 ≈ 1 km, 0.1 ≈ 10 km. The
     * vector-tile pipeline (tippecanoe) reads the coarse column at
     * low zoom levels (§12.5).
     *
     * Returns true if a row was updated.
     */
    public function simplifyCoverage(string $coverageId): bool
    {
        $db = \App\Core\Database::getInstance();
        $stmt = $db->query(
            "UPDATE vendor_coverage
             SET simplified_100m = ST_Simplify(geom, ?),
                 simplified_1km  = ST_Simplify(geom, ?),
                 simplified_10km = ST_Simplify(geom, ?),
                 simplified_at   = NOW()
             WHERE id = ?",
            [0.001, 0.01, 0.1, $coverageId]
        );
        return $stmt->rowCount() === 1;
    }

    /** Returns the number of coverage rows simplified. Idempotent — skips already-fresh rows. */
    public function simplifyPending(int $batchSize = 500): int
    {
        $db = \App\Core\Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT id FROM vendor_coverage
             WHERE simplified_at IS NULL OR simplified_at < updated_at
             LIMIT ?",
            [$batchSize]
        );
        $count = 0;
        foreach ($rows as $r) {
            try {
                if ($this->simplifyCoverage($r['id'])) $count++;
            } catch (\Throwable $e) {
                error_log('[coverage simplify] ' . $r['id'] . ': ' . $e->getMessage());
            }
        }
        return $count;
    }

    /** The core spec query — drop a pin, get vendors that serve it. */
    public function whoServesPoint(float $lat, float $lng): array
    {
        return $this->coverage->vendorsServingPoint($lat, $lng);
    }

    // ──────────────────────────── helpers ────────────────────────────

    public static function defaultRadiusMiles(string $type): float
    {
        return match ($type) {
            'broadline'                                 => 80.0,  // national distributors deliver wide
            'warehouse', 'cash_carry'                   => 25.0,  // operators drive to the warehouse
            'produce'                                   => 50.0,
            'protein', 'meat'                           => 50.0,
            'seafood'                                   => 60.0,
            'specialty', 'specialty_ethnic'             => 60.0,
            'grocery', 'local_grocery'                  => 5.0,   // small radius — local supplier
            'bakery_dairy_beverage', 'dairy_bakery_bev' => 30.0,
            'smallwares_equip'                          => 25.0,
            default                                     => 25.0,
        };
    }

    /**
     * Polygon approximation of a circle around (lat,lng) of radius `miles`.
     * (lat lng) axis order — matches the project's SRID 4326 convention.
     */
    public static function circleWkt(float $lat, float $lng, float $radiusMiles): string
    {
        $latDeg = $radiusMiles / 69.0;                                      // ~degrees lat per mile
        $lngDeg = $radiusMiles / (69.0 * max(0.01, cos(deg2rad($lat))));    // adjusted for latitude

        $points = [];
        for ($i = 0; $i < self::CIRCLE_VERTICES; $i++) {
            $angle = (2 * M_PI * $i) / self::CIRCLE_VERTICES;
            $points[] = sprintf(
                '%f %f',
                $lat + $latDeg * cos($angle),
                $lng + $lngDeg * sin($angle)
            );
        }
        $points[] = $points[0]; // close the ring
        return 'POLYGON((' . implode(', ', $points) . '))';
    }
}
