<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\CountsRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\MapsUrl;
use App\TruckPlanner\Services\Support\Redactor;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpNotFound;

/**
 * Saved spots (04_BACKEND.md 4.8): validation of a spot body, the host link rule, the stored location
 * vectors and their freshness.
 *
 * Three forms of a spot appear here:
 *
 *   - the spot row of SpotRepository (flat columns in API units, `vectors` as the three stored blocks);
 *   - the **spot value**, which is what the other services work with: the row with `point` {lat, lng},
 *     `terms` (the SpotTerms of 02_MODEL.md, `spot_id` set), `vectors` dressed as three complete
 *     LocationVectors keyed by visibility (null when none are stored) and `vectors_state` added.
 *     ensureFresh() returns it; terms() reads a row or a value;
 *   - the API's `Spot`, which list(), get(), create(), update() and refresh() return.
 *
 * `$truck` is the truck value of TruckBaseController::truck().
 *
 * Vectors are stored for the three visibility levels, with the dataset version and seeds revision they were
 * computed against. They are recomputed when the point, the host's segment, the host's size or the host's
 * link changes, never for a change of visibility alone, and lazily (refresh(), refreshStale(),
 * ensureFresh()) once the truck's region, its active dataset or the seeds revision has moved on.
 *
 * Capture reads only build- and fixed-scope seeds, so `A` here is Seeds::defaults(): the owner's overrides
 * never change a vector.
 */
class SpotService
{
    private const NOT_FOUND = 'Spot not found';
    private const BODY_KEYS = ['name', 'point', 'address', 'notes', 'terms', 'host_details'];
    private const SIZE_SOURCES = ['owner', 'default'];
    private const DETAILS = ['name' => 160, 'contact' => 160, 'phone' => 40, 'website' => 255];
    private const MAX_FEE = 100000.0;
    private const MAX_HOST_SIZE = 200000.0;
    private const MAX_MINUTE = 2880;
    private const PLACE_KEY_LENGTH = 20;
    private const PLACE_KEY_FORM = '/^[A-Za-z0-9_.:-]{1,20}$/D';
    private const GOOGLE_ID_LENGTH = 255;

    private SpotRepository $spots;
    private CountsRepository $counts;
    private RegionService $regions;

    /** @var array<string, mixed>|null */
    private ?array $seeds = null;

    /** @var array<string, array<string, mixed>|null> places read so far, by "region|version|key" */
    private array $places = [];

    public function __construct(
        ?SpotRepository $spots = null,
        ?CountsRepository $counts = null,
        ?RegionService $regions = null
    ) {
        $this->spots = $spots ?? new SpotRepository();
        $this->counts = $counts ?? new CountsRepository();
        $this->regions = $regions ?? new RegionService();
    }

    // ------------------------------------------------------------------------------------ reading

    /**
     * The truck's spots ordered by name, then id.
     *
     * @param array<string, mixed> $truck
     * @return list<array<string, mixed>> Spot
     */
    public function list(string $orgId, array $truck, bool $withArchived = false): array
    {
        $truckId = (string) $truck['id'];
        $rows = $this->spots->listActive($orgId, $truckId, $withArchived);
        $logs = $rows === [] ? [] : $this->counts->logsBySpot($orgId, $truckId);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->present($this->value($row, $truck), $logs[$row['id']] ?? null);
        }
        return $out;
    }

    /**
     * One spot. An archived spot is still found by its id.
     *
     * @param array<string, mixed> $truck
     * @return array<string, mixed> Spot
     * @throws TpNotFound for an id the organization does not have
     */
    public function get(string $orgId, array $truck, string $spotId): array
    {
        $row = $this->mustFind($orgId, $spotId);
        $logs = $this->counts->logsBySpot($orgId, (string) $truck['id']);
        return $this->present($this->value($row, $truck), $logs[$row['id']] ?? null);
    }

    // ------------------------------------------------------------------------------------ writing

    /**
     * Validates a create body, links the host, computes the vectors and inserts the spot.
     *
     * @param array<string, mixed> $truck
     * @param array<int|string, mixed> $body the request body of route 11
     * @param string|null $googlePlaceId a Google place id to keep with the spot. Never read from the
     *                                   body: only the Scout "save as spot" action passes one
     * @return array<string, mixed> Spot
     * @throws TpConflict at the limit of spots, and while the region data does not fit this server
     */
    public function create(string $orgId, array $truck, ?string $userId, array $body, ?string $googlePlaceId = null): array
    {
        $in = new Input($body);
        $columns = $this->plainInput($in, true);
        $terms = $this->termsInput($in->obj('terms', $in->has('terms')), $truck);
        $columns += $this->detailsInput($in->obj('host_details', $in->has('host_details')));

        $max = (int) TpConfig::get('limits.max_spots');
        $truckId = (string) $truck['id'];
        if ($this->spots->countActive($orgId, $truckId) >= $max) {
            throw new TpConflict('You can keep at most ' . $max . ' spots');
        }

        foreach (['visibility', 'fee_flat', 'fee_pct', 'fee_min', 'allowed'] as $key) {
            if (array_key_exists($key, $terms)) {
                $columns[$key] = $terms[$key];
            }
        }
        $lat = (float) $columns['lat'];
        $lng = (float) $columns['lng'];
        $linked = $this->linkedHost($truck, $lat, $lng, $terms['host'] ?? null, $terms['place_key'] ?? null, $terms['place_type'] ?? null);
        $columns = array_merge($columns, self::hostColumns($linked), $this->captured($truck, $lat, $lng, $linked['host']));
        if ($googlePlaceId !== null && $googlePlaceId !== '' && strlen($googlePlaceId) <= self::GOOGLE_ID_LENGTH) {
            $columns['google_place_id'] = $googlePlaceId;
        }

        $id = $this->spots->create($orgId, $truckId, $userId, $columns);
        // A new spot has no logged service yet: the log counts are not asked for.
        return $this->present($this->value($this->mustFind($orgId, $id), $truck), null);
    }

    /**
     * Changes the keys the body carries. A nested object (`terms`, `host_details`) changes only its own
     * keys; `terms.host` and `terms.allowed` are replaced whole, and null removes them.
     *
     * The host link rule runs when the point or the host is touched. The vectors are recomputed when the
     * point, the host's segment, its size or its link really changed.
     *
     * @param array<string, mixed> $truck
     * @param array<int|string, mixed> $body the request body of route 14
     * @return array<string, mixed> Spot
     */
    public function update(string $orgId, array $truck, string $spotId, array $body): array
    {
        $row = $this->mustFind($orgId, $spotId);
        $in = new Input($body);
        $in->requireAny(self::BODY_KEYS);
        $columns = $this->plainInput($in, false);
        $terms = $this->termsInput($in->obj('terms', $in->has('terms')), $truck);
        $columns += $this->detailsInput($in->obj('host_details', $in->has('host_details')));
        foreach (['visibility', 'fee_flat', 'fee_pct', 'fee_min', 'allowed'] as $key) {
            if (array_key_exists($key, $terms)) {
                $columns[$key] = $terms[$key];
            }
        }

        $old = ['lat' => (float) $row['lat'], 'lng' => (float) $row['lng']];
        $lat = (float) ($columns['lat'] ?? $old['lat']);
        $lng = (float) ($columns['lng'] ?? $old['lng']);
        $moved = $lat !== $old['lat'] || $lng !== $old['lng'];
        $hostSent = array_key_exists('host', $terms);

        if ($moved || $hostSent) {
            $linked = $hostSent
                ? $this->linkedHost($truck, $lat, $lng, $terms['host'], $terms['place_key'], $terms['place_type'])
                : $this->linkedHost($truck, $lat, $lng, $this->terms($row)['host'], $row['place_key'], $row['host_place_type']);
            $next = self::hostColumns($linked);
            $columns = array_merge($columns, $next);
            $changed = $moved
                || $next['host_segment'] !== $row['host_segment']
                || $next['host_size'] !== $row['host_size']
                || $next['place_key'] !== $row['place_key']
                || $next['host_point_id'] !== $row['host_point_id'];
            if ($changed) {
                $columns = array_merge($columns, $this->captured($truck, $lat, $lng, $linked['host']));
            }
        }

        $this->spots->update($spotId, $orgId, $columns);
        if ($moved) {
            $this->carryCorrections($orgId, $truck, $old, ['lat' => $lat, 'lng' => $lng]);
        }
        return $this->get($orgId, $truck, $spotId);
    }

    /**
     * Archives a spot. The row stays, so plans and logs keep working. Archiving twice changes nothing.
     *
     * @return array{id: string, archived: true}
     */
    public function archive(string $orgId, string $spotId): array
    {
        $row = $this->mustFind($orgId, $spotId);
        if ($row['archived_at'] === null) {
            $this->spots->archive($spotId, $orgId);
        }
        return ['id' => (string) $row['id'], 'archived' => true];
    }

    /**
     * Applies the host link rule and recomputes the vectors of one spot, whatever their state.
     *
     * @param array<string, mixed> $truck
     * @return array<string, mixed> Spot
     */
    public function refresh(string $orgId, array $truck, string $spotId): array
    {
        $this->recompute($orgId, $truck, $this->mustFind($orgId, $spotId));
        return $this->get($orgId, $truck, $spotId);
    }

    /**
     * Recomputes up to `$limit` spots of the truck whose vectors are not fresh. Archived spots are left
     * to ensureFresh(), which serves the plans that still refer to them.
     *
     * @param array<string, mixed> $truck
     * @return array{refreshed: int, remaining: int} `remaining` = spots that are still not fresh
     */
    public function refreshStale(string $orgId, array $truck, int $limit = 50): array
    {
        [$regionId, $version] = $this->expected($truck);
        $truckId = (string) $truck['id'];
        $revision = Seeds::revision();
        $refreshed = 0;
        foreach ($this->spots->staleIds($orgId, $truckId, $regionId, $version, $revision, max(0, $limit)) as $id) {
            $row = $this->spots->find($id, $orgId);
            if ($row === null) {
                continue;
            }
            $this->recompute($orgId, $truck, $row);
            $refreshed++;
        }
        // Counted after the work, from the table: a spot that could not be made fresh is still reported.
        $ceiling = (int) TpConfig::get('limits.max_spots');
        $remaining = count($this->spots->staleIds($orgId, $truckId, $regionId, $version, $revision, $ceiling));
        return ['refreshed' => $refreshed, 'remaining' => $remaining];
    }

    // ------------------------------------------------------------------------------------ for the other services

    /**
     * The SpotTerms of a spot (02_MODEL.md section 3), `spot_id` set. `host` is null when the spot has no
     * host segment.
     *
     * @param array<string, mixed> $spot a spot row or a spot value
     * @return array<string, mixed> SpotTerms
     */
    public function terms(array $spot): array
    {
        $host = null;
        if (($spot['host_segment'] ?? null) !== null) {
            $host = [
                'segment' => (string) $spot['host_segment'],
                'size' => (float) ($spot['host_size'] ?? 0.0),
                'size_source' => ($spot['host_size_source'] ?? null) === 'default' ? 'default' : 'owner',
                'only_food' => (bool) ($spot['host_only_food'] ?? false),
                'point_id' => isset($spot['host_point_id']) ? (string) $spot['host_point_id'] : null,
                'place_type' => isset($spot['host_place_type']) ? (string) $spot['host_place_type'] : null,
            ];
        }
        return [
            'spot_id' => (string) $spot['id'],
            'visibility' => (string) $spot['visibility'],
            'host' => $host,
            'fee_flat' => (float) $spot['fee_flat'],
            'fee_pct' => (float) $spot['fee_pct'],
            'fee_min' => (float) $spot['fee_min'],
            'allowed' => is_array($spot['allowed'] ?? null) ? $spot['allowed'] : null,
        ];
    }

    /**
     * The spot value of a spot, with vectors that are fresh: when they are missing or were computed for
     * another region, dataset version or seeds revision, the host link rule runs and they are recomputed
     * and stored first.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $spot a spot row (SpotRepository::find, findMany) or a spot value
     * @return array<string, mixed> the spot value: `point`, `terms`, `vectors` (three LocationVectors keyed
     *                              by visibility) and `vectors_state` beside the columns of the row
     * @throws TpConflict while the region data does not fit this server
     */
    public function ensureFresh(string $orgId, array $truck, array $spot): array
    {
        if ($this->vectorsState($spot, $truck) !== 'fresh') {
            $this->recompute($orgId, $truck, $spot);
            $spot = $this->mustFind($orgId, (string) $spot['id']);
        }
        return $this->value($spot, $truck);
    }

    /**
     * The `terms` object of a request body (routes 9, 11 and 14), validated. Only what was sent comes
     * back: `visibility`, `fee_flat`, `fee_pct`, `fee_min`, `allowed` (the object, or null to remove it)
     * and, when `host` was sent, the three keys `host`, `place_key` and `place_type`.
     *
     * `host` is a Host with the defaults of a linked place applied (segment, size, only-food) and
     * `point_id` still null: the host link rule sets it. It is null when the body removes the host, and
     * when it links a place whose type hosts nothing and names no segment.
     *
     * @param array<string, mixed> $truck
     * @return array<string, mixed>
     */
    public function termsInput(?Input $terms, array $truck): array
    {
        $out = [];
        if ($terms === null) {
            return $out;
        }
        $visibility = $terms->enum('visibility', SpotRepository::BLOCKS, $terms->has('visibility'));
        if ($visibility !== null) {
            $out['visibility'] = $visibility;
        }
        foreach (['fee_flat' => self::MAX_FEE, 'fee_min' => self::MAX_FEE, 'fee_pct' => 1.0] as $key => $max) {
            $amount = $terms->num($key, 0.0, $max, $terms->has($key));
            if ($amount !== null) {
                $out[$key] = $amount;
            }
        }
        if ($terms->has('allowed')) {
            $out['allowed'] = $terms->isNull('allowed') ? null : self::allowedInput($terms);
        }
        if ($terms->has('host')) {
            [$out['host'], $out['place_key'], $out['place_type']] = $terms->isNull('host')
                ? [null, null, null]
                : $this->hostInput($terms, $truck);
        }
        return $out;
    }

    /**
     * Runs the host link rule for a host at a point and writes its result into the host.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed>|null $host
     * @return array{host: ?array<string, mixed>, place_key: ?string, place_type: ?string, point_id: ?string}
     *         `host` carries the resolved `point_id` and `place_type`; it is what capture is asked with
     */
    public function linkedHost(array $truck, float $lat, float $lng, ?array $host, ?string $placeKey, ?string $placeType): array
    {
        $sources = [];
        if ($host !== null && $this->group((string) $host['segment']) === 'visitors') {
            $sources = Registry::capture()->sources(self::regionId($truck), $lat, $lng);
        }
        $link = $this->resolveHostLink($truck, $lat, $lng, $host, $placeKey, $placeType, $sources);
        if ($host !== null) {
            $host['point_id'] = $link['point_id'];
            $host['place_type'] = $link['place_type'];
        }
        return ['host' => $host] + $link;
    }

    /**
     * The host link rule (04_BACKEND.md 4.8, 03_DATA.md 6.2): which place a spot is linked to and which
     * source point its host takes out of the catchment, so that a host's people are not counted twice.
     *
     *   1. A link the dataset knows: the place's type, and its own source point when it is a visitor source.
     *   2. A link the dataset no longer knows: the nearest possible host of the stored type within 100 m
     *      takes its place; without one the link is cleared and the type stays as a label.
     *   3. No link and a visitor host: the nearest possible host of the same visitor segment within 100 m.
     *   4. Still no point and a visitor host: the nearest source point that holds the host's segment
     *      within the venue link radius (Estimator::hostLinkPoint). This step never changes the link.
     *
     * Each step works on what the one before left. Nearest is by haversine distance from the spot, ties
     * to the smaller place_key. Without a host only steps 1 and 2 apply and there is no point to take
     * out. While the truck's region has no active dataset nothing can be looked up and the link is left
     * as it is.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed>|null $host a Host, or null for a spot without one
     * @param string|null $placeKey the spot's `place_key` (in a request: the key the body sends)
     * @param string|null $placeType the spot's `host_place_type` (in a request: the type of that place)
     * @param list<array<string, mixed>> $sources the source points around the point (CaptureProvider::sources)
     * @return array{place_key: ?string, place_type: ?string, point_id: ?string}
     */
    public function resolveHostLink(
        array $truck,
        float $lat,
        float $lng,
        ?array $host,
        ?string $placeKey,
        ?string $placeType,
        array $sources
    ): array {
        $regionId = self::regionId($truck);
        $stored = $host === null || !isset($host['point_id']) ? null : (string) $host['point_id'];
        if ($this->regions->active($regionId) === null) {
            return ['place_key' => $placeKey, 'place_type' => $placeType, 'point_id' => $stored];
        }

        $segment = $host === null ? null : (string) $host['segment'];
        $pointId = null;
        $near = null;

        if ($placeKey !== null) {
            $place = $this->place($regionId, $placeKey);
            if ($place === null) {
                $near = $this->hostsWithinReach($regionId, $lat, $lng);
                $place = $placeType === null ? null : self::nearest($near, 'place_type', $placeType);
                $placeKey = $place === null ? null : (string) $place['place_key'];
            }
            if ($place !== null) {
                $placeType = (string) $place['place_type'];
                $pointId = ($place['visitor_segment'] ?? null) !== null ? 'p' . $placeKey : null;
            }
        }
        if ($placeKey === null && $segment !== null && str_starts_with($segment, 'v_')) {
            $place = self::nearest($near ?? $this->hostsWithinReach($regionId, $lat, $lng), 'visitor_segment', $segment);
            if ($place !== null) {
                $placeKey = (string) $place['place_key'];
                $placeType = (string) $place['place_type'];
                $pointId = 'p' . $placeKey;
            }
        }
        if ($pointId === null && $host !== null && $this->group((string) $segment) === 'visitors') {
            $pointId = Estimator::hostLinkPoint($this->seeds(), $lat, $lng, $host, $sources);
        }
        return ['place_key' => $placeKey, 'place_type' => $placeType, 'point_id' => $host === null ? null : $pointId];
    }

    // ------------------------------------------------------------------------------------ vectors

    /**
     * Host link rule, capture, one UPDATE: the stored link and the vectors of a spot as they are today.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $spot a spot row or a spot value
     */
    private function recompute(string $orgId, array $truck, array $spot): void
    {
        $lat = (float) $spot['lat'];
        $lng = (float) $spot['lng'];
        $linked = $this->linkedHost(
            $truck,
            $lat,
            $lng,
            $this->terms($spot)['host'],
            isset($spot['place_key']) ? (string) $spot['place_key'] : null,
            isset($spot['host_place_type']) ? (string) $spot['host_place_type'] : null
        );
        $link = self::hostColumns($linked);
        $columns = [
            'place_key' => $link['place_key'],
            'host_place_type' => $link['host_place_type'],
            'host_point_id' => $link['host_point_id'],
        ];
        $this->spots->update((string) $spot['id'], $orgId, $columns + $this->captured($truck, $lat, $lng, $linked['host']));
    }

    /**
     * Asks the capture provider for the three visibility levels and returns what is stored of the answer:
     * the county and the seven vector columns.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed>|null $host the host after the host link rule
     * @return array<string, mixed> columns of SpotRepository
     */
    private function captured(array $truck, float $lat, float $lng, ?array $host): array
    {
        $regionId = self::regionId($truck);
        $answer = Registry::capture()->capture($regionId, $lat, $lng, SpotRepository::BLOCKS, $host);
        $vectors = [];
        foreach (SpotRepository::BLOCKS as $level) {
            if (!is_array($answer['vectors'][$level] ?? null)) {
                throw new \UnexpectedValueException('capture answered without visibility ' . $level);
            }
            $vectors[$level] = $answer['vectors'][$level];
        }
        $normal = $vectors['normal'];
        return [
            'county_fips' => isset($answer['located']['county_fips']) ? (string) $answer['located']['county_fips'] : null,
            'vectors' => $vectors,
            'vec_in_region' => (bool) ($normal['in_region'] ?? false),
            'vec_points_used' => (int) ($normal['points_used'] ?? 0),
            'vec_excluded' => (float) ($normal['excluded_amount'] ?? 0.0),
            'vec_region_id' => $regionId,
            'vec_dataset' => isset($normal['dataset_version']) ? (string) $normal['dataset_version'] : null,
            'vec_seeds_rev' => Seeds::revision(),
        ];
    }

    /**
     * `fresh` when the vectors were computed for the truck's region, that region's active dataset version
     * and the current seeds revision; `none` without vectors; else `stale`.
     *
     * @param array<string, mixed> $spot
     * @param array<string, mixed> $truck
     */
    private function vectorsState(array $spot, array $truck): string
    {
        if (!is_array($spot['vectors'] ?? null)) {
            return 'none';
        }
        [$regionId, $version] = $this->expected($truck);
        $fresh = ($spot['vec_region_id'] ?? null) === $regionId
            && ($spot['vec_dataset'] ?? null) === $version
            && ($spot['vec_seeds_rev'] ?? null) === Seeds::revision();
        return $fresh ? 'fresh' : 'stale';
    }

    /**
     * What fresh vectors of this truck are labelled with: its region and that region's active version.
     *
     * @param array<string, mixed> $truck
     * @return array{0: string, 1: ?string}
     */
    private function expected(array $truck): array
    {
        $regionId = self::regionId($truck);
        $active = $this->regions->active($regionId);
        return [$regionId, $active === null ? null : (string) $active['dataset_version']];
    }

    // ------------------------------------------------------------------------------------ shapes

    /**
     * The spot value of a row: `point`, `terms`, dressed `vectors` and `vectors_state` added.
     *
     * @param array<string, mixed> $row a spot row (a spot value is accepted and dressed again)
     * @param array<string, mixed> $truck
     * @return array<string, mixed>
     */
    private function value(array $row, array $truck): array
    {
        $terms = $this->terms($row);
        $state = $this->vectorsState($row, $truck);
        $vectors = null;
        if (is_array($row['vectors'] ?? null)) {
            $exclusion = Estimator::hostExclusion($this->seeds(), $terms['host']);
            $dataset = isset($row['vec_dataset']) ? (string) $row['vec_dataset'] : null;
            $vectors = [];
            foreach (SpotRepository::BLOCKS as $level) {
                $block = $row['vectors'][$level];
                $vectors[$level] = [
                    'capture' => $block['capture'],
                    'nearby' => $block['nearby'],
                    'within' => null,
                    'rivals' => $block['rivals'],
                    'visibility' => $level,
                    'in_region' => (bool) $row['vec_in_region'],
                    'region_id' => $dataset === null ? null : (string) $row['vec_region_id'],
                    'exclusion' => $exclusion,
                    'excluded_amount' => (float) $row['vec_excluded'],
                    'points_used' => (int) $row['vec_points_used'],
                    'dataset_version' => $dataset,
                    'model_version' => Estimator::MODEL_VERSION,
                ];
            }
        }
        $row['point'] = ['lat' => (float) $row['lat'], 'lng' => (float) $row['lng']];
        $row['terms'] = $terms;
        $row['vectors'] = $vectors;
        $row['vectors_state'] = $state;
        return $row;
    }

    /**
     * The API's Spot of a spot value.
     *
     * @param array<string, mixed> $spot
     * @param array{count: int, last_date: ?string}|null $logs
     * @return array<string, mixed>
     */
    private function present(array $spot, ?array $logs): array
    {
        $details = [
            'place_type' => $spot['host_place_type'],
            'name' => $spot['host_name'],
            'contact' => $spot['host_contact'],
            'phone' => $spot['host_phone'],
            'website' => $spot['host_website'],
            'place_key' => $spot['place_key'],
            'google_place_id' => $spot['google_place_id'],
        ];
        $any = false;
        foreach ($details as $detail) {
            $any = $any || $detail !== null;
        }
        return [
            'id' => $spot['id'],
            'name' => $spot['name'],
            'point' => $spot['point'],
            'address' => $spot['address'],
            'county_fips' => $spot['county_fips'],
            'notes' => $spot['notes'],
            'terms' => $spot['terms'],
            'host_details' => $any ? $details : null,
            'vectors' => $spot['vectors'],
            'vectors_state' => $spot['vectors_state'],
            'logs' => $logs ?? ['count' => 0, 'last_date' => null],
            'maps_url' => MapsUrl::point($spot['point']['lat'], $spot['point']['lng']),
            'archived' => $spot['archived_at'] !== null,
            'created_at' => $spot['created_at'],
            'updated_at' => $spot['updated_at'],
        ];
    }

    /**
     * The host and link columns of a spot after the host link rule.
     *
     * @param array{host: ?array<string, mixed>, place_key: ?string, place_type: ?string, point_id: ?string} $linked
     * @return array<string, mixed>
     */
    private static function hostColumns(array $linked): array
    {
        $host = $linked['host'];
        return [
            'host_segment' => $host === null ? null : (string) $host['segment'],
            'host_size' => $host === null ? null : (float) $host['size'],
            'host_size_source' => $host === null ? null : (string) $host['size_source'],
            'host_only_food' => $host !== null && (bool) $host['only_food'],
            'place_key' => $linked['place_key'],
            'host_place_type' => $linked['place_type'],
            'host_point_id' => $host === null ? null : $linked['point_id'],
        ];
    }

    // ------------------------------------------------------------------------------------ request bodies

    /**
     * `name`, `point`, `address` and `notes` of a body, as columns. On create the first two are required
     * and the other two get their defaults.
     *
     * @return array<string, mixed>
     */
    private function plainInput(Input $in, bool $create): array
    {
        $columns = [];
        $name = $in->str('name', 120, $create || $in->has('name'));
        if ($name !== null) {
            if ($name === '') {
                throw $in->error('name', 'is required', 'V1');
            }
            $columns['name'] = $name;
        }
        $point = $in->point('point', $create || $in->has('point'));
        if ($point !== null) {
            $columns['lat'] = $point['lat'];
            $columns['lng'] = $point['lng'];
        }
        $address = $in->str('address', 255, $in->has('address'));
        if ($address !== null) {
            $columns['address'] = $address;
        }
        if ($in->has('notes')) {
            $notes = $in->str('notes', 4000);
            $columns['notes'] = $notes === '' ? null : $notes;
        }
        return $columns;
    }

    /**
     * `host_details` of a body, as columns: what the owner typed about the host. An empty text clears a
     * field, as null does.
     *
     * @return array<string, mixed>
     */
    private function detailsInput(?Input $details): array
    {
        $columns = [];
        if ($details === null) {
            return $columns;
        }
        foreach (self::DETAILS as $key => $max) {
            if ($details->has($key)) {
                $text = $details->str($key, $max);
                $columns['host_' . $key] = $text === '' ? null : $text;
            }
        }
        return $columns;
    }

    /**
     * `terms.allowed`: the days and hours the owner may trade at the spot.
     *
     * @return array{days: list<bool>, open_minute: int, close_minute: int}
     */
    private static function allowedInput(Input $terms): array
    {
        $allowed = $terms->obj('allowed', true);
        if ($allowed === null) {
            throw $terms->error('allowed', 'is required', 'V1');
        }
        $allowed->items('days', 7, 7, true);
        $each = $allowed->each('days');
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = (bool) $each->bool($i, true);
        }
        $open = (int) $allowed->int('open_minute', 0, self::MAX_MINUTE, true);
        $close = (int) $allowed->int('close_minute', 0, self::MAX_MINUTE, true);
        if ($close <= $open) {
            throw $allowed->error('close_minute', 'must be after open_minute');
        }
        return ['days' => $days, 'open_minute' => $open, 'close_minute' => $close];
    }

    /**
     * `terms.host`: a host as a request describes it, completed from the place it links.
     *
     * @param array<string, mixed> $truck
     * @return array{0: ?array<string, mixed>, 1: ?string, 2: ?string} [Host or null, place_key, place_type]
     */
    private function hostInput(Input $terms, array $truck): array
    {
        $host = $terms->obj('host', true);
        if ($host === null) {
            throw $terms->error('host', 'is required', 'V1');
        }

        $place = null;
        $placeKey = $host->str('place_key', self::PLACE_KEY_LENGTH, $host->has('place_key'));
        if ($placeKey !== null) {
            // Keys are plain ASCII; anything else cannot be a key and is not sent to the database.
            $place = preg_match(self::PLACE_KEY_FORM, $placeKey) === 1
                ? $this->place(self::regionId($truck), $placeKey)
                : null;
            if ($place === null) {
                throw $host->notFound('place_key');
            }
        }
        $placeType = $place === null ? null : (string) $place['place_type'];
        $typeSeed = $placeType === null ? null : $this->placeTypeSeed($placeType);

        $segment = $host->enum('segment', $this->segments(), $host->has('segment'));
        if ($segment === null) {
            if ($place === null) {
                throw $host->error('segment', 'is required', 'V1');
            }
            $segment = $typeSeed['host_segment'] ?? null;
            if ($segment === null) {
                return [null, $placeKey, $placeType];
            }
        }

        $size = $host->num('size', 1.0, self::MAX_HOST_SIZE, $host->has('size'));
        $default = $place === null ? 0.0 : (float) ($place['size_default'] ?? 0.0);
        if ($size === null && !($default > 0.0)) {
            throw $host->error('size', 'is required for this kind of place');
        }
        $source = $host->enum('size_source', self::SIZE_SOURCES, $host->has('size_source'));
        if ($size === null) {
            $size = $default;
            $source = 'default';
        }
        $onlyFood = $host->bool('only_food', $host->has('only_food'));
        if ($onlyFood === null) {
            $onlyFood = $place !== null && self::kitchen($place, $typeSeed) === 'no';
        }

        return [
            [
                'segment' => (string) $segment,
                'size' => $size,
                'size_source' => $source ?? 'owner',
                'only_food' => $onlyFood,
                'point_id' => null,
                'place_type' => $placeType,
            ],
            $placeKey,
            $placeType,
        ];
    }

    // ------------------------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed> a spot row, archived or not
     */
    private function mustFind(string $orgId, string $spotId): array
    {
        $row = $this->spots->find($spotId, $orgId, true);
        if ($row === null) {
            throw new TpNotFound(self::NOT_FOUND);
        }
        return $row;
    }

    /**
     * The owner's drive-time corrections follow a pin that moved a short way; a longer move leaves them
     * behind. A failure here leaves them behind as well: the spot itself is saved already.
     *
     * @param array<string, mixed> $truck
     * @param array{lat: float, lng: float} $old
     * @param array{lat: float, lng: float} $new
     */
    private function carryCorrections(string $orgId, array $truck, array $old, array $new): void
    {
        $limit = (float) TpConfig::get('requests.spot_move_keeps_corrections_m');
        if (Estimator::haversineM($old['lat'], $old['lng'], $new['lat'], $new['lng']) > $limit) {
            return;
        }
        try {
            Registry::legs()->movePoint($orgId, $truck, $old, $new);
        } catch (\Throwable $e) {
            error_log('[tp] corrections did not follow a moved spot: ' . get_class($e) . ': ' . Redactor::text($e->getMessage()));
        }
    }

    /**
     * One place of the region's active dataset. Validation and the link rule both ask for the linked
     * place, so an answer is kept for as long as that dataset version is the active one.
     *
     * @return array<string, mixed>|null
     */
    private function place(string $regionId, string $placeKey): ?array
    {
        $active = $this->regions->active($regionId);
        if ($active === null) {
            return null;
        }
        $memo = $regionId . '|' . $active['dataset_version'] . '|' . $placeKey;
        if (!array_key_exists($memo, $this->places)) {
            $this->places[$memo] = Registry::capture()->place($regionId, $placeKey);
        }
        return $this->places[$memo];
    }

    /**
     * The possible hosts within the re-link radius of a point, each with its own haversine distance.
     *
     * @return list<array<string, mixed>>
     */
    private function hostsWithinReach(string $regionId, float $lat, float $lng): array
    {
        $radius = (float) TpConfig::get('requests.host_relink_radius_m');
        $out = [];
        foreach (Registry::capture()->hostsNear($regionId, $lat, $lng, $radius) as $place) {
            $metres = Estimator::haversineM($lat, $lng, (float) $place['lat'], (float) $place['lng']);
            if ($metres <= $radius) {
                $place['metres'] = $metres;
                $out[] = $place;
            }
        }
        return $out;
    }

    /**
     * The nearest of the places whose `$field` equals `$value`; ties go to the smaller place_key.
     *
     * @param list<array<string, mixed>> $places rows of hostsWithinReach()
     * @return array<string, mixed>|null
     */
    private static function nearest(array $places, string $field, string $value): ?array
    {
        $best = null;
        $bestKey = 0;
        foreach ($places as $place) {
            if (($place[$field] ?? null) !== $value) {
                continue;
            }
            $key = Estimator::qkey((float) $place['metres']);
            if ($best === null || $key < $bestKey
                || ($key === $bestKey && strcmp((string) $place['place_key'], (string) $best['place_key']) < 0)) {
                $best = $place;
                $bestKey = $key;
            }
        }
        return $best;
    }

    /**
     * Does the place have a kitchen of its own? Its own state when known, else the default of its type.
     *
     * @param array<string, mixed> $place
     * @param array<string, mixed>|null $typeSeed
     */
    private static function kitchen(array $place, ?array $typeSeed): string
    {
        $state = $place['kitchen'] ?? null;
        if ($state === 'yes' || $state === 'no') {
            return $state;
        }
        return ($typeSeed['kitchen_default'] ?? null) === 'no' ? 'no' : 'yes';
    }

    /**
     * The seed row of a place type, or null for a type the seed file does not hold.
     *
     * @return array<string, mixed>|null
     */
    private function placeTypeSeed(string $placeType): ?array
    {
        $A = $this->seeds();
        if (!in_array($placeType, Estimator::seed($A, 'vocabulary.place_types'), true)) {
            return null;
        }
        $row = Estimator::seed($A, 'place_types.rows.' . $placeType);
        return is_array($row) ? $row : null;
    }

    /** `workers`, `residents` or `visitors`: structural in the seed file, never overridden. */
    private function group(string $segment): ?string
    {
        $group = $this->seeds()['seeds']['segments'][$segment]['group'] ?? null;
        return is_string($group) ? $group : null;
    }

    /**
     * @return list<string> the sixteen segment keys
     */
    private function segments(): array
    {
        return array_values(Estimator::seed($this->seeds(), 'vocabulary.segments'));
    }

    /**
     * @return array<string, mixed> Assumptions without overrides: all that capture and the link rule read
     */
    private function seeds(): array
    {
        return $this->seeds ??= Seeds::defaults();
    }

    /**
     * @param array<string, mixed> $truck
     */
    private static function regionId(array $truck): string
    {
        $regionId = $truck['profile']['region_id'] ?? null;
        return is_string($regionId) && $regionId !== '' ? $regionId : RegionService::NONE;
    }
}
