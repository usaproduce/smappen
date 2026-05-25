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
    ) {}

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
     * Used after a chain seed. Defaults a radius based on the vendor's
     * type so a broadliner gets a larger reach than a corner grocery.
     */
    public function ensureCoverageForVendor(string $vendorId, string $vendorType): int
    {
        $locs = $this->locations->listForVendor($vendorId);
        $existing = $this->coverage->listForVendor($vendorId);
        $covered = [];
        foreach ($existing as $c) $covered[(string) $c['location_id']] = true;

        $radius = self::defaultRadiusMiles($vendorType);
        $count = 0;
        foreach ($locs as $loc) {
            if (isset($covered[(string) $loc['id']])) continue;
            $this->setRadiusFallback(
                $vendorId,
                (string) $loc['id'],
                (float) $loc['lat'],
                (float) $loc['lng'],
                $radius
            );
            $count++;
        }
        return $count;
    }

    /** The core spec query — drop a pin, get vendors that serve it. */
    public function whoServesPoint(float $lat, float $lng): array
    {
        return $this->coverage->vendorsServingPoint($lat, $lng);
    }

    // ──────────────────────────── helpers ────────────────────────────

    private static function defaultRadiusMiles(string $type): float
    {
        return match ($type) {
            'broadline'        => 80.0,  // national distributors deliver wide
            'warehouse'        => 25.0,  // will-call — drive-time worth
            'produce'          => 50.0,
            'protein'          => 50.0,
            'seafood'          => 60.0,
            'specialty'        => 60.0,
            'grocery'          => 5.0,   // small radius — local supplier
            'bakery_dairy_beverage' => 30.0,
            default            => 25.0,
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
