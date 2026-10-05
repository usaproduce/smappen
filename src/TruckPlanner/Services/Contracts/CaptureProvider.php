<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Contracts;

/**
 * Exact capture at one point, and the readers of the host link rule (04_BACKEND.md 5.1, 4.8).
 *
 * Resolved with Registry::capture(): the capture service when it is installed, else the fallback, which
 * answers as region `none` does (zero vectors, no rows).
 *
 * `$regionId` is the truck's region (`profile.region_id`). `$version` is a dataset version to read instead
 * of the region's active one; only the region loader passes it.
 *
 * Shapes:
 *
 *     Located     = { in_region: bool, region_id: string?, county_fips: string?, state: string? }
 *     SourcePoint = { id: string, lat: float, lng: float, base: [float x16], rivals: { day: float, eve: float } }
 *     OutletRow   = { place_key, name: string?, place_type, rival_kind, kitchen, lat, lng, distance_m: float }
 *     HostHint    = { place_key, name, place_type, lat, lng, distance_m, host_segment: string?,
 *                     default_size: float, kitchen: "yes"|"no", point_id: string? }
 */
interface CaptureProvider
{
    /**
     * Region membership, county and state of a point, from its nearest census block.
     *
     * @return array{in_region: bool, region_id: ?string, county_fips: ?string, state: ?string} Located
     */
    public function locate(string $regionId, float $lat, float $lng, ?string $version = null): array;

    /**
     * The location vectors of a truck at the point, one per requested visibility level, with the host's
     * exclusion applied.
     *
     * @param list<string> $visibilities any of "hidden", "normal", "prominent"
     * @param array<string, mixed>|null $host a Host of 02_MODEL.md section 3 (with its resolved point_id), or null
     * @return array{located: array<string, mixed>, vectors: array<string, array<string, mixed>>,
     *               outlets: list<array<string, mixed>>, outlets_total: int,
     *               hosts_nearby: list<array<string, mixed>>}
     *         `vectors` maps each visibility to a LocationVectors; `outlets` are OutletRow (nearest first,
     *         at most 60) and `outlets_total` their number before the cut; `hosts_nearby` are HostHint
     * @throws \App\TruckPlanner\Services\Support\TpConflict "Region data was built with different model
     *         constants" while the region's active version is unusable for that reason
     */
    public function capture(
        string $regionId,
        float $lat,
        float $lng,
        array $visibilities,
        ?array $host,
        ?string $version = null
    ): array;

    /**
     * The source points around the point: what capture() at the same point sums over.
     *
     * @return list<array<string, mixed>> SourcePoint, in ascending id
     */
    public function sources(string $regionId, float $lat, float $lng, ?string $version = null): array;

    /**
     * One place of the region's dataset by its key.
     *
     * @return array{place_key: string, place_type: string, visitor_segment: ?string, lat: float, lng: float,
     *               size_default: float, kitchen: string}|null null when the dataset has no such place
     */
    public function place(string $regionId, string $placeKey, ?string $version = null): ?array;

    /**
     * The possible hosts within `$radiusM` metres of the point, nearest first, then by place_key.
     *
     * @return list<array{place_key: string, place_type: string, visitor_segment: ?string, lat: float,
     *                    lng: float, distance_m: float}>
     */
    public function hostsNear(string $regionId, float $lat, float $lng, float $radiusM, ?string $version = null): array;
}
