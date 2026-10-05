<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Fallback;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\CaptureProvider;

/**
 * The capture provider while the capture service is not installed. It answers every point the way region
 * `none` is answered: outside every region, zero vectors, no source points, no places. Nothing is read.
 */
final class NoCapture implements CaptureProvider
{
    public function locate(string $regionId, float $lat, float $lng, ?string $version = null): array
    {
        return ['in_region' => false, 'region_id' => null, 'county_fips' => null, 'state' => null];
    }

    public function capture(
        string $regionId,
        float $lat,
        float $lng,
        array $visibilities,
        ?array $host,
        ?string $version = null
    ): array {
        $A = Seeds::defaults();
        $exclusion = Estimator::hostExclusion($A, $host);
        $vectors = [];
        foreach ($visibilities as $visibility) {
            // The model's own answer for a point with no source and no outlet in reach: all zeros.
            $one = Estimator::captureAtPoint($A, $lat, $lng, (string) $visibility, [], [], $exclusion);
            $one['in_region'] = false;
            $vectors[(string) $visibility] = $one;
        }
        return [
            'located' => $this->locate($regionId, $lat, $lng, $version),
            'vectors' => $vectors,
            'outlets' => [],
            'outlets_total' => 0,
            'hosts_nearby' => [],
        ];
    }

    public function sources(string $regionId, float $lat, float $lng, ?string $version = null): array
    {
        return [];
    }

    public function place(string $regionId, string $placeKey, ?string $version = null): ?array
    {
        return null;
    }

    public function hostsNear(string $regionId, float $lat, float $lng, float $radiusM, ?string $version = null): array
    {
        return [];
    }
}
