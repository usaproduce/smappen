<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\LegKey;
use App\TruckPlanner\Data\PlaceRepository;
use App\TruckPlanner\Data\PointRepository;
use App\TruckPlanner\Data\ScoutLeadRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Data\VectorCodec;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Google\PlacesContactClient;
use App\TruckPlanner\Services\Http\UpstreamGuard;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\JsonSafe;
use App\TruckPlanner\Services\Support\MapsUrl;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpNotFound;
use App\TruckPlanner\Services\Support\TpRateLimited;
use App\TruckPlanner\Services\Support\TpUnavailable;

/**
 * Scout: the named places within reach of the truck's base that could host it, in rank order, and what the
 * owner keeps about each of them (04_BACKEND.md 4.15, 5.4, 5.9; the math is 02_MODEL.md 4.16).
 *
 * Ranking is a funnel of two deterministic stages, because the model's own function for one place costs a
 * few milliseconds and a region holds thousands of places:
 *
 *   1. Screen. Every possible host inside the reach of the drive limit and the licence counties is read
 *      with its stored location vector (Q6, a page at a time) and given one number by ScoutScreen: what its
 *      best three hours of a typical week might leave, weighed by how commonly that kind of place hosts
 *      trucks, less a straight-line estimate of the drive. The shortlist is the best of them whose
 *      estimated round trip is within the limit, then those estimated a little over it.
 *   2. Exact. The shortlist goes through Estimator::scoutEstimate a batch at a time, with the drive legs
 *      of the leg provider. A place whose round trip takes more than twice the limit is outside the limit.
 *      A further batch is looked at only while fewer places than the list holds have passed. The model
 *      ranks what passed.
 *
 * Both stages are cached for a day, under keys made of everything they were computed from. What is added
 * afterwards is never cached: the place's display columns, the owner's lead, the map link, where the legs
 * came from.
 *
 * Places are OpenStreetMap rows. Phone and website come from OpenStreetMap unless the owner asks Google for
 * one place (lookupContact): that answer belongs to the lead, is served for 30 days and is never copied to
 * a spot or to the places table.
 *
 * Every figure is the model's range with its confidence label. Nothing here knows who owns a place or what
 * the local rules are, and nothing it returns says that a place would take the truck.
 *
 * `$truck` is the truck value of TruckBaseController::truck().
 */
class ScoutingService
{
    public const STATUSES = ['new', 'shortlisted', 'contacted', 'booked', 'declined', 'hidden'];

    public const NO_REGION = 'Scouting needs a loaded region';
    public const PLACE_NOT_FOUND = 'Place not found';
    public const LOOKUP_UNAVAILABLE = 'Contact lookup is not available on this server';
    public const LOOKUP_BUSY = 'Too many lookups right now. Try again in a minute';
    public const ALREADY_SAVED = 'This place is already saved as a spot';

    /** The standing line that travels with every ranked list. */
    public const NOTICE = 'Permission to trade here and local rules are yours to check.';

    // Strings 1 and 2 of 03_DATA.md section 14. String 9 (drive times) is the setting `routing.attribution`.
    private const ATTRIBUTION_PLACES = "\u{00A9} OpenStreetMap contributors";
    private const ATTRIBUTION_PLACES_SENTENCE = "Place data \u{00A9} OpenStreetMap contributors, available under the Open Database License (ODbL).";


    private const PLACE_KEY_FORM = '/^[A-Za-z0-9_.:-]{1,20}$/D';
    private const BASE_ID = 'base';
    private const MAX_NOTES = 4000;
    private const MAX_NAME = 120;
    private const MAX_ADDRESS = 255;
    private const MAX_HOST_SIZE = 200000.0;

    private ScoutLeadRepository $leads;
    private PlaceRepository $places;
    private SpotRepository $spots;
    private SpotService $spotService;
    private RegionService $regions;
    private ?PlacesContactClient $contacts;
    private ?UpstreamGuard $guard;
    private Clock $clock;

    public function __construct(
        ?ScoutLeadRepository $leads = null,
        ?PlaceRepository $places = null,
        ?SpotRepository $spots = null,
        ?SpotService $spotService = null,
        ?RegionService $regions = null,
        ?PlacesContactClient $contacts = null,
        ?UpstreamGuard $guard = null,
        ?Clock $clock = null
    ) {
        $this->leads = $leads ?? new ScoutLeadRepository();
        $this->places = $places ?? new PlaceRepository();
        $this->spots = $spots ?? new SpotRepository();
        $this->regions = $regions ?? new RegionService();
        $this->spotService = $spotService ?? new SpotService($this->spots, null, $this->regions);
        $this->contacts = $contacts;
        $this->guard = $guard;
        $this->clock = $clock ?? new Clock();
    }

    // ------------------------------------------------------------------------------------ the ranked list

    /**
     * The ranked list of route 38.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A the truck's Assumptions
     * @param array<int|string, mixed> $opts the query of the request: `hide` (a comma list of lead statuses
     *        whose places leave the ranking; `hidden` when absent, none when empty) and `refresh` (1
     *        computes both stages again)
     * @return array<string, mixed> `candidates` (ScoutCandidate, in rank order), `screened`, `truncated`,
     *         `limit_minutes`, `licence_counties`, `dataset_version`, `cached`, `notice`, `attribution`
     * @throws TpConflict while the truck's region has no data, or data that does not fit this server
     */
    public function rank(string $orgId, array $truck, array $A, array $opts): array
    {
        $in = Input::query($opts);
        $hide = self::hideInput($in);
        $refresh = $in->bool('refresh') ?? false;

        $active = $this->regions->active(self::regionId($truck));
        if ($active === null) {
            throw new TpConflict(self::NO_REGION);
        }
        if (!$active['usable']) {
            throw new TpConflict(CaptureService::BUILD_MISMATCH);
        }
        @set_time_limit((int) TpConfig::get('requests.long_request_seconds'));

        $regionId = (string) $active['region_id'];
        $version = (string) $active['dataset_version'];
        $truckId = (string) $truck['id'];
        $profile = $truck['profile'];
        $limit = (int) $profile['scout_drive_minutes_limit'];
        $counties = self::counties($profile);
        $base = ['lat' => (float) $profile['base']['lat'], 'lng' => (float) $profile['base']['lng']];

        // Google contact details are not kept past their 30 days, also for an owner who only reads.
        $this->leads->purgeExpiredGoogle($orgId);
        $leads = $this->leads->forTruck($orgId, $truckId, $regionId);
        [$only, $keys] = self::statusFilter($leads, $hide);

        $fuel = Registry::fuel()->resolve($truck);
        $fuelPrice = (float) $fuel['price_per_gal'];
        $cal = Registry::calibration()->state($orgId, $truck, $A, $this->clock->today(Clock::zoneOf($truck)));

        // ---- stage 1: the screen, and the shortlist it leaves
        $shortHash = sha1(JsonSafe::canonical([
            'region' => $regionId,
            'dataset' => $version,
            'model' => (string) $A['model_version'],
            'seeds' => (int) $A['seeds_revision'],
            'overrides' => $A['overrides'] === [] ? new \stdClass() : $A['overrides'],
            'assumed_region' => $A['region'],
            'profile' => $profile,
            'base_key' => LegKey::of($base['lat'], $base['lng']),
            'limit' => $limit,
            'counties' => $counties,
            'only_listed' => $only,
            'keys' => $keys,
            'truck_factor' => (float) $cal['truck_factor'],
            'fuel_price' => $fuelPrice,
            'settings' => TpConfig::get('scout'),
        ]));
        $ttl = (int) TpConfig::get('scout.cache_ttl_s');
        $shortKey = 'tp:scout:s:' . $orgId . ':' . $shortHash;
        $short = $refresh ? null : TpCache::get($shortKey);
        $cached = self::isShortlist($short);
        if (!$cached) {
            $short = $this->screen($A, $profile, $cal, $fuelPrice, $base, $regionId, $version, $limit, $counties, $only, $keys);
            TpCache::put($shortKey, $short, $ttl);
        }

        // ---- stage 2: the model on the shortlist, a batch at a time, with the legs of the leg provider
        $maxResults = (int) Estimator::seed($A, 'scout.max_results');
        $batches = array_chunk($short['places'], self::batchSize($A));
        // What a range and its label take from the owner's logged services.
        $evidence = Estimator::evidenceFrom($cal, null);
        $results = [];
        $legSources = [];
        $cached = $cached && $batches !== [];
        foreach ($batches as $n => $batch) {
            [$legInputs, $sources] = $this->legs($orgId, $truck, $base, $batch);
            $legSources += $sources;
            $batchKey = 'tp:scout:r:' . $orgId . ':'
                . sha1($shortHash . ':' . $n . ':' . JsonSafe::canonical(['legs' => $legInputs, 'evidence' => $evidence]));
            $stored = $refresh ? null : TpCache::get($batchKey);
            if (is_array($stored) && is_array($stored['results'] ?? null) && array_is_list($stored['results'])) {
                $passed = $stored['results'];
            } else {
                $cached = false;
                $passed = [];
                foreach ($batch as $entry) {
                    $key = (string) $entry['key'];
                    $legs = array_intersect_key($legInputs, [self::BASE_ID . '>' . $key => true, $key . '>' . self::BASE_ID => true]);
                    $result = Estimator::scoutEstimate($A, $profile, self::placeInput($entry, $regionId, $version), $legs, $cal, $fuelPrice);
                    // "Inside the drive limit" is decided here, on the legs a plan would drive.
                    if ($result !== null && (int) $result['round_trip']['minutes'] <= 2 * $limit) {
                        $passed[] = $result;
                    }
                }
                TpCache::put($batchKey, ['results' => $passed], $ttl);
            }
            foreach ($passed as $result) {
                $results[] = $result;
            }
            if (count($results) >= $maxResults) {
                break;
            }
        }
        $ranked = Estimator::scoutRank($results);

        // ---- what is never cached: the place as it is listed, the owner's lead, the links
        $rankedKeys = [];
        foreach ($ranked as $result) {
            $rankedKeys[] = (string) $result['place_id'];
        }
        $display = $rankedKeys === [] ? [] : $this->places->byKeys($regionId, $version, $rankedKeys);
        $entries = [];
        foreach ($short['places'] as $entry) {
            $entries[(string) $entry['key']] = $entry;
        }
        $shownLeads = array_intersect_key($leads, array_flip($rankedKeys));
        $liveSpots = $this->liveSpots($orgId, $shownLeads);

        $candidates = [];
        foreach ($ranked as $result) {
            $key = (string) $result['place_id'];
            $place = self::placeOf($key, $display[$key] ?? null, $entries[$key] ?? []);
            $lead = $leads[$key] ?? null;
            $candidates[] = [
                'result' => $result,
                'place' => $place,
                'lead' => self::lead($lead, $key, $liveSpots),
                'maps_url' => self::mapsUrl($place, $lead),
                'leg_sources' => [
                    'out' => $legSources[$key]['out'] ?? 'straight_line',
                    'back' => $legSources[$key]['back'] ?? 'straight_line',
                ],
            ];
        }

        return [
            'candidates' => $candidates,
            'screened' => (int) $short['screened'],
            'truncated' => (bool) $short['truncated'],
            'limit_minutes' => $limit,
            'licence_counties' => $counties,
            'dataset_version' => $version,
            'cached' => $cached,
            'notice' => self::NOTICE,
            'attribution' => [
                self::ATTRIBUTION_PLACES,
                self::ATTRIBUTION_PLACES_SENTENCE,
                (string) TpConfig::get('routing.attribution'),
            ],
        ];
    }

    // ------------------------------------------------------------------------------------ leads

    /**
     * Sets the status or the note of the lead of a place, creating the lead on first touch (route 39).
     *
     * @param array<string, mixed> $truck
     * @param array<int|string, mixed> $body `status` (one of STATUSES) and `notes` (text or null)
     * @return array{lead: array<string, mixed>}
     * @throws TpNotFound for a key that is not a possible host of the truck's region
     */
    public function saveLead(string $orgId, array $truck, string $placeKey, array $body): array
    {
        [$regionId, , $place] = $this->place($truck, $placeKey);
        $in = new Input($body);
        $in->requireAny(['status', 'notes']);
        $columns = self::snapshot($place);
        if ($in->has('status')) {
            $columns['status'] = (string) $in->enum('status', self::STATUSES, true);
        }
        if ($in->has('notes')) {
            $notes = $in->str('notes', self::MAX_NOTES);
            $columns['notes'] = $notes === '' ? null : $notes;
        }
        $truckId = (string) $truck['id'];
        $this->leads->upsert($orgId, $truckId, $regionId, $placeKey, $columns);
        return ['lead' => $this->leadOfPlace($orgId, $truckId, $regionId, $placeKey)];
    }

    /**
     * Phone and website of one place from Google, when the owner asks (route 40).
     *
     * A lookup that is younger than 30 days is answered from the lead (`lookup: "cached"`), a found one and
     * a not-found one alike; `$force` asks again once it is older than a day. Otherwise Google is asked
     * once. Its match is kept on the lead only when it lies at the place; anything else is stored as
     * `not_found`, so the owner is never shown the details of another place.
     *
     * @param array<string, mixed> $truck
     * @return array{lead: array<string, mixed>, lookup: string} `lookup` is "found", "not_found" or "cached"
     * @throws TpNotFound for a key that is not a possible host of the truck's region
     * @throws TpUnavailable when this server has no Google key, or Google refused or failed lately
     * @throws TpRateLimited when the shared bucket of lookups is empty
     */
    public function lookupContact(string $orgId, array $truck, string $placeKey, bool $force): array
    {
        [$regionId, , $place] = $this->place($truck, $placeKey);
        $truckId = (string) $truck['id'];

        $lead = $this->leads->find($orgId, $truckId, $regionId, $placeKey);
        if ($lead !== null && is_array($lead['google'])) {
            $mayAskAgain = $force && (int) $lead['google']['age_hours'] >= (int) TpConfig::get('places.force_min_age_hours');
            if (!$mayAskAgain) {
                return ['lead' => self::lead($lead, $placeKey, $this->liveSpots($orgId, [$lead])), 'lookup' => 'cached'];
            }
        }

        $guard = $this->guard ??= new UpstreamGuard($this->clock);
        if (!$guard->hasGoogleKey() || $guard->refused('places') || $guard->inBackoff('places')) {
            throw new TpUnavailable(self::LOOKUP_UNAVAILABLE);
        }
        if (!$guard->takeTokens((string) TpConfig::get('places.bucket'), 1, (int) TpConfig::get('places.bucket_wait_s'))) {
            throw new TpRateLimited(self::LOOKUP_BUSY);
        }

        $answer = ($this->contacts ??= new PlacesContactClient())->find((string) ($place['name'] ?? ''), (float) $place['lat'], (float) $place['lng']);
        if (!$answer['ok']) {
            if ($answer['error'] === PlacesContactClient::ERROR_REFUSED) {
                $guard->markRefused('places');
            } elseif ($answer['error'] !== PlacesContactClient::ERROR_NO_KEY) {
                $reason = $answer['error'] === PlacesContactClient::ERROR_QUOTA ? 'quota' : 'upstream';
                $guard->backoff('places', (int) TpConfig::get('places.backoff_s'), $reason);
            }
            throw new TpUnavailable(self::LOOKUP_UNAVAILABLE);
        }

        $found = is_array($answer['place']);
        $leadId = $this->leads->upsert($orgId, $truckId, $regionId, $placeKey, self::snapshot($place));
        // A lookup that matched nothing leaves the place id of an earlier match as it is.
        $this->leads->setGoogle($leadId, $orgId, $found ? ['lookup_state' => 'found'] + $answer['place'] : ['lookup_state' => 'not_found']);
        return [
            'lead' => $this->leadOfPlace($orgId, $truckId, $regionId, $placeKey),
            'lookup' => $found ? 'found' : 'not_found',
        ];
    }

    /**
     * Saves a place as a spot at its own point, linked to the place, and links the lead to the spot
     * (route 41). The host is the one the place's type describes; the owner may give its size, whether the
     * truck is the only food there, the visibility and another name. A new lead becomes `shortlisted`.
     *
     * The spot takes its name, address, phone and website from the OpenStreetMap columns. Of what Google
     * said only the place id travels (as the last argument of SpotService::create): looked-up phone,
     * website and address stay on the lead.
     *
     * @param array<string, mixed> $truck
     * @param array<int|string, mixed> $body optional `name`, `visibility`, `host_size`, `only_food`
     * @return array{spot: array<string, mixed>, lead: array<string, mixed>}
     * @throws TpNotFound for a key that is not a possible host of the truck's region
     * @throws TpConflict when the place is saved as a spot already, at the limit of spots, and while the
     *         region data does not fit this server
     */
    public function saveAsSpot(string $orgId, array $truck, ?string $userId, string $placeKey, array $body): array
    {
        [$regionId, , $place] = $this->place($truck, $placeKey);
        $truckId = (string) $truck['id'];

        $in = new Input($body);
        $name = $in->str('name', self::MAX_NAME);
        $visibility = $in->enum('visibility', SpotRepository::BLOCKS);
        $hostSize = $in->num('host_size', 1.0, self::MAX_HOST_SIZE);
        $onlyFood = $in->bool('only_food');

        // A type with a host segment and no typical size needs the owner's figure.
        $segment = self::hostSegment((string) $place['place_type']);
        if ($segment !== null && !((float) $place['size_default'] > 0.0) && $hostSize === null) {
            throw $in->error('host_size', 'is required for this kind of place');
        }

        $lead = $this->leads->find($orgId, $truckId, $regionId, $placeKey);
        if ($lead !== null && $this->liveSpots($orgId, [$lead]) !== []) {
            throw new TpConflict(self::ALREADY_SAVED);
        }

        // The host of the spot body: the link, and the owner's figures where the type has a host at all.
        $host = ['place_key' => $placeKey];
        if ($segment !== null) {
            if ($hostSize !== null) {
                $host['size'] = $hostSize;
            }
            if ($onlyFood !== null) {
                $host['only_food'] = $onlyFood;
            }
        }
        $placeName = isset($place['name']) ? (string) $place['name'] : '';
        $spot = $this->spotService->create(
            $orgId,
            $truck,
            $userId,
            [
                'name' => $name !== null && $name !== '' ? $name : mb_substr($placeName, 0, self::MAX_NAME, 'UTF-8'),
                'point' => ['lat' => (float) $place['lat'], 'lng' => (float) $place['lng']],
                'address' => self::address($place),
                'terms' => ['visibility' => $visibility ?? 'normal', 'host' => $host],
                'host_details' => [
                    'name' => $place['name'] ?? null,
                    'phone' => $place['phone'] ?? null,
                    'website' => $place['website'] ?? null,
                ],
            ],
            $lead['google_place_id'] ?? null
        );

        $columns = self::snapshot($place) + ['spot_id' => (string) $spot['id']];
        if ($lead === null || $lead['status'] === 'new') {
            $columns['status'] = 'shortlisted';
        }
        $this->leads->upsert($orgId, $truckId, $regionId, $placeKey, $columns);
        return ['spot' => $spot, 'lead' => $this->leadOfPlace($orgId, $truckId, $regionId, $placeKey)];
    }

    // ------------------------------------------------------------------------------------ stage 1

    /**
     * Reads the possible hosts within reach, screens them and keeps the best for the model.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed>|null $cal
     * @param array{lat: float, lng: float} $base
     * @param list<string> $counties
     * @param bool $only true: `$keys` are the only places to look at; false: `$keys` are left out
     * @param list<string> $keys
     * @return array{screened: int, truncated: bool, places: list<array<string, mixed>>} the shortlist in the
     *         order stage 2 takes it (ScoutScreen::shortlist), at most `scout.max_batches` batches long: each place
     *         with what stage 2 needs (`key`, `type`, `lat`, `lng`, `county`, `kitchen`, `segment`, `size`
     *         and `vec`, the hexadecimal text of its stored vector)
     */
    private function screen(
        array $A,
        array $profile,
        ?array $cal,
        float $fuelPrice,
        array $base,
        string $regionId,
        string $version,
        int $limit,
        array $counties,
        bool $only,
        array $keys
    ): array {
        $slack = (float) TpConfig::get('scout.reach_slack');
        $timeFactor = (float) $profile['truck_time_factor'];
        $reachMinutes = $slack * $limit;
        $box = PointRepository::box($base['lat'], $base['lng'], self::reachMetres($A, $reachMinutes / $timeFactor));
        $listed = array_flip($keys);
        $hostTypes = self::hostTypes($A);

        $rows = [];
        $pageRows = PlaceRepository::pageRows();
        $after = '';
        do {
            $page = $this->places->hostVectorPage($regionId, $version, $box, $counties, $after);
            foreach ($page as $row) {
                $key = (string) $row[PlaceRepository::VEC_KEY];
                $after = $key;
                if (isset($listed[$key]) !== $only) {
                    continue;
                }
                if (!isset($hostTypes[$row[PlaceRepository::VEC_TYPE]])
                    || strlen((string) $row[PlaceRepository::VEC_BYTES]) !== VectorCodec::BLOCK_BYTES) {
                    continue;
                }
                // The box holds more than the reach: the straight-line estimate decides.
                $leg = Estimator::fallbackLeg(
                    $A,
                    $base['lat'],
                    $base['lng'],
                    (float) $row[PlaceRepository::VEC_LAT],
                    (float) $row[PlaceRepository::VEC_LNG]
                );
                if ((float) $leg['duration_s'] / 60.0 * $timeFactor <= $reachMinutes) {
                    $rows[] = $row;
                }
            }
        } while (count($page) >= $pageRows);

        // Too many to screen: the nearest are kept. The rows stay in place_key order.
        $truncated = false;
        $max = max(1, (int) TpConfig::get('scout.max_screen'));
        if (count($rows) > $max) {
            $byDistance = [];
            foreach ($rows as $i => $row) {
                $metres = Estimator::haversineM(
                    $base['lat'],
                    $base['lng'],
                    (float) $row[PlaceRepository::VEC_LAT],
                    (float) $row[PlaceRepository::VEC_LNG]
                );
                $byDistance[] = [Estimator::qkey($metres), (string) $row[PlaceRepository::VEC_KEY], $i];
            }
            usort($byDistance, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: strcmp($a[1], $b[1]));
            $kept = [];
            foreach (array_slice($byDistance, 0, $max) as [, , $i]) {
                $kept[] = $i;
            }
            sort($kept);
            $nearest = [];
            foreach ($kept as $i) {
                $nearest[] = $rows[$i];
            }
            $rows = $nearest;
            $truncated = true;
        }

        $scores = ScoutScreen::scores($A, $profile, $cal, $fuelPrice, $base, $rows);
        // `scout.max_batches`: how many batches of the shortlist the model may be asked about in one request.
        // A batch is the length of the list plus `scout.shortlist_extra` places, two drive legs for each.
        $picked = ScoutScreen::shortlist($A, $profile, $base, $rows, $scores, $limit, $slack, self::batchSize($A) * (int) TpConfig::get('scout.max_batches'));

        $places = [];
        foreach ($picked as $i) {
            $row = $rows[$i];
            $places[] = [
                'key' => (string) $row[PlaceRepository::VEC_KEY],
                'type' => (string) $row[PlaceRepository::VEC_TYPE],
                'lat' => (float) $row[PlaceRepository::VEC_LAT],
                'lng' => (float) $row[PlaceRepository::VEC_LNG],
                'county' => $row[PlaceRepository::VEC_COUNTY],
                'kitchen' => (string) $row[PlaceRepository::VEC_KITCHEN],
                'segment' => $row[PlaceRepository::VEC_SEGMENT],
                'size' => (float) $row[PlaceRepository::VEC_SIZE],
                'vec' => bin2hex((string) $row[PlaceRepository::VEC_BYTES]),
            ];
        }
        return ['screened' => count($rows), 'truncated' => $truncated, 'places' => $places];
    }

    /**
     * The places stage 2 looks at in one go: the length of the ranked list (seed `scout.max_results`) plus
     * `scout.shortlist_extra`, so that real legs can move a place up or down a few ranks without loss.
     *
     * @param array<string, mixed> $A
     */
    private static function batchSize(array $A): int
    {
        return max(1, (int) Estimator::seed($A, 'scout.max_results') + (int) TpConfig::get('scout.shortlist_extra'));
    }

    /**
     * The drive legs of a batch, there and back for each place, from the leg provider. Tolls are not asked
     * for: a scouting round trip is compared on time, miles and fuel.
     *
     * @param array<string, mixed> $truck
     * @param array{lat: float, lng: float} $base
     * @param list<array<string, mixed>> $batch places of the shortlist
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array{out?: string, back?: string}>}
     *         [LegInput by "base><key>" and "<key>>base", the DriveLeg source of each leg by place key]
     */
    private function legs(string $orgId, array $truck, array $base, array $batch): array
    {
        $points = [['id' => self::BASE_ID, 'lat' => $base['lat'], 'lng' => $base['lng']]];
        $pairs = [];
        foreach ($batch as $entry) {
            $key = (string) $entry['key'];
            $points[] = ['id' => $key, 'lat' => (float) $entry['lat'], 'lng' => (float) $entry['lng']];
            $pairs[] = [self::BASE_ID, $key];
            $pairs[] = [$key, self::BASE_ID];
        }
        $inputs = [];
        $sources = [];
        foreach (Registry::legs()->legs($orgId, $truck, $points, $pairs, ['tolls' => false]) as $leg) {
            $from = (string) $leg['from_id'];
            $to = (string) $leg['to_id'];
            $inputs[$from . '>' . $to] = $leg['leg_input'];
            if ($from === self::BASE_ID) {
                $sources[$to]['out'] = (string) $leg['source'];
            } else {
                $sources[$from]['back'] = (string) $leg['source'];
            }
        }
        return [$inputs, $sources];
    }

    /**
     * How far, in a straight line, the fallback leg of the model gets in `$minutes` of free-flow driving:
     * the first miles at the local speed, the rest at the trunk speed, over the detour factor.
     *
     * @param array<string, mixed> $A
     */
    private static function reachMetres(array $A, float $minutes): float
    {
        $localMiles = (float) Estimator::seed($A, 'drive_fallback.local_miles');
        $localMph = (float) Estimator::seed($A, 'drive_fallback.local_mph');
        $trunkMph = (float) Estimator::seed($A, 'drive_fallback.trunk_mph');
        $local = $localMiles / $localMph * 60.0;
        $miles = $minutes <= $local
            ? $minutes * $localMph / 60.0
            : $localMiles + ($minutes - $local) * $trunkMph / 60.0;
        return $miles * 1609.344 / (float) Estimator::seed($A, 'drive_fallback.detour_factor');
    }

    /**
     * The place types the model scores: those of the seed file with a host fit above zero.
     *
     * @param array<string, mixed> $A
     * @return array<string, true>
     */
    private static function hostTypes(array $A): array
    {
        $types = [];
        foreach (Estimator::seed($A, 'vocabulary.place_types') as $type) {
            if ((float) Estimator::seed($A, 'place_types.rows.' . $type)['host_fit'] > 0.0) {
                $types[(string) $type] = true;
            }
        }
        return $types;
    }

    /**
     * @param array<int|string, mixed>|null $value what the cache holds
     */
    private static function isShortlist(?array $value): bool
    {
        return $value !== null && is_int($value['screened'] ?? null) && is_bool($value['truncated'] ?? null)
            && is_array($value['places'] ?? null) && array_is_list($value['places']);
    }

    // ------------------------------------------------------------------------------------ stage 2

    /**
     * The PlaceInput of the model for a shortlisted place. Its vectors are the stored ones: computed by the
     * region loader at the place's point, visibility normal, the place's own source point left out.
     *
     * @param array<string, mixed> $entry a place of the shortlist
     * @return array<string, mixed>
     */
    private static function placeInput(array $entry, string $regionId, string $version): array
    {
        $key = (string) $entry['key'];
        $bytes = hex2bin((string) $entry['vec']);
        if ($bytes === false || strlen($bytes) !== VectorCodec::BLOCK_BYTES) {
            throw new \UnexpectedValueException('a shortlisted place has no usable vector');
        }
        $pointId = ($entry['segment'] ?? null) === null ? null : 'p' . $key;
        $vectors = VectorCodec::fromFlat(VectorCodec::fromBytes($bytes)) + [
            'within' => null,
            'visibility' => 'normal',
            'in_region' => true,
            'region_id' => $regionId,
            'exclusion' => ['point_ids' => $pointId === null ? [] : [$pointId], 'segment' => null, 'amount' => 0.0],
            'excluded_amount' => 0.0,
            'points_used' => null,
            'dataset_version' => $version,
            'model_version' => Estimator::MODEL_VERSION,
        ];
        return [
            'place_id' => $key,
            'place_type' => (string) $entry['type'],
            'point' => ['lat' => (float) $entry['lat'], 'lng' => (float) $entry['lng']],
            'point_id' => $pointId,
            'size_default' => (float) $entry['size'],
            'kitchen' => (string) $entry['kitchen'],
            'vectors' => $vectors,
        ];
    }

    // ------------------------------------------------------------------------------------ shapes

    /**
     * The `place` of a ScoutCandidate: the OpenStreetMap columns the list shows.
     *
     * @param array<string, mixed>|null $display the Q7 row of the place
     * @param array<string, mixed> $entry its shortlist entry, used where the Q7 row is missing
     * @return array<string, mixed>
     */
    private static function placeOf(string $key, ?array $display, array $entry): array
    {
        $display ??= [
            'place_type' => $entry['type'] ?? '',
            'lat' => $entry['lat'] ?? 0.0,
            'lng' => $entry['lng'] ?? 0.0,
            'county_fips' => $entry['county'] ?? null,
            'kitchen' => $entry['kitchen'] ?? 'unknown',
        ];
        return [
            'place_key' => $key,
            'name' => $display['name'] ?? null,
            'brand' => $display['brand'] ?? null,
            'place_type' => (string) $display['place_type'],
            'lat' => (float) $display['lat'],
            'lng' => (float) $display['lng'],
            'county_fips' => $display['county_fips'] ?? null,
            'addr_line' => $display['addr_line'] ?? null,
            'city' => $display['city'] ?? null,
            'state_code' => $display['state_code'] ?? null,
            'postcode' => $display['postcode'] ?? null,
            'phone' => $display['phone'] ?? null,
            'website' => $display['website'] ?? null,
            'opening_hours_raw' => $display['opening_hours_raw'] ?? null,
            'kitchen' => (string) $display['kitchen'],
        ];
    }

    /**
     * The API's Lead. A place the owner has not touched has the lead {id: null, status: "new"}.
     *
     * `spot_id` is the spot that was saved from the lead while that spot is live: once it is archived the
     * lead reads as not saved, and the place can be saved again.
     *
     * @param array<string, mixed>|null $row a read row of ScoutLeadRepository
     * @param array<string, true> $liveSpots the ids of the spots that are not archived
     * @return array<string, mixed>
     */
    private static function lead(?array $row, string $placeKey, array $liveSpots): array
    {
        if ($row === null) {
            return ['id' => null, 'place_key' => $placeKey, 'status' => 'new', 'notes' => null, 'spot_id' => null, 'google' => null];
        }
        $google = null;
        if (is_array($row['google'])) {
            $google = [
                'place_id' => $row['google_place_id'],
                'lookup_state' => (string) $row['google']['lookup_state'],
                'name' => $row['google']['name'],
                'address' => $row['google']['address'],
                'phone' => $row['google']['phone'],
                'website' => $row['google']['website'],
                'maps_uri' => $row['google']['maps_uri'],
                'fetched_on' => (string) $row['google']['fetched_on'],
            ];
        }
        $spotId = $row['spot_id'];
        return [
            'id' => (string) $row['id'],
            'place_key' => (string) $row['place_key'],
            'status' => (string) $row['status'],
            'notes' => $row['notes'],
            'spot_id' => $spotId !== null && isset($liveSpots[$spotId]) ? (string) $spotId : null,
            'google' => $google,
        ];
    }

    /**
     * @return array<string, mixed> the Lead of a place as it is stored now
     */
    private function leadOfPlace(string $orgId, string $truckId, string $regionId, string $placeKey): array
    {
        $row = $this->leads->find($orgId, $truckId, $regionId, $placeKey);
        return self::lead($row, $placeKey, $row === null ? [] : $this->liveSpots($orgId, [$row]));
    }

    /**
     * Which of the spots these leads point to are still live (not archived).
     *
     * @param array<int|string, array<string, mixed>> $leads read rows of ScoutLeadRepository
     * @return array<string, true> keyed by spot id
     */
    private function liveSpots(string $orgId, array $leads): array
    {
        $ids = [];
        foreach ($leads as $lead) {
            if (($lead['spot_id'] ?? null) !== null) {
                $ids[] = (string) $lead['spot_id'];
            }
        }
        $live = [];
        if ($ids !== []) {
            foreach ($this->spots->findMany($ids, $orgId) as $id => $spot) {
                if ($spot['archived_at'] === null) {
                    $live[(string) $id] = true;
                }
            }
        }
        return $live;
    }

    /**
     * The free "Open in Google Maps" link of a candidate. No API is called: with a stored place id the
     * link names the place, otherwise it drops a pin at its point.
     *
     * @param array<string, mixed> $place the `place` of the candidate
     * @param array<string, mixed>|null $lead a read row of ScoutLeadRepository
     */
    private static function mapsUrl(array $place, ?array $lead): string
    {
        $placeId = $lead['google_place_id'] ?? null;
        $name = $place['name'] ?? null;
        if (is_string($placeId) && $placeId !== '' && is_string($name) && $name !== '') {
            return MapsUrl::place($name, $placeId);
        }
        return MapsUrl::point((float) $place['lat'], (float) $place['lng']);
    }

    /**
     * The snapshot of a place that a lead keeps, so that it outlives a switch of the dataset.
     *
     * @param array<string, mixed> $place a Q7 row
     * @return array<string, mixed> columns of ScoutLeadRepository
     */
    private static function snapshot(array $place): array
    {
        return [
            'place_name' => $place['name'] ?? null,
            'place_type' => (string) $place['place_type'],
            'lat' => (float) $place['lat'],
            'lng' => (float) $place['lng'],
        ];
    }

    /**
     * One line of the OpenStreetMap address columns: "1 Example Rd, Sterling, VA 20166". Empty when the
     * place has none of them.
     *
     * @param array<string, mixed> $place a Q7 row
     */
    private static function address(array $place): string
    {
        $parts = [];
        foreach (['addr_line', 'city'] as $column) {
            $text = trim((string) ($place[$column] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        $tail = trim(trim((string) ($place['state_code'] ?? '')) . ' ' . trim((string) ($place['postcode'] ?? '')));
        if ($tail !== '') {
            $parts[] = $tail;
        }
        return mb_substr(implode(', ', $parts), 0, self::MAX_ADDRESS, 'UTF-8');
    }

    // ------------------------------------------------------------------------------------ helpers

    /**
     * The place of a lead route: a possible host of the truck's region in its active dataset.
     *
     * @param array<string, mixed> $truck
     * @return array{0: string, 1: string, 2: array<string, mixed>} [region id, dataset version, Q7 row]
     * @throws TpNotFound
     */
    private function place(array $truck, string $placeKey): array
    {
        // Keys are plain ASCII; anything else cannot be a key and is not sent to the database.
        $active = preg_match(self::PLACE_KEY_FORM, $placeKey) === 1 ? $this->regions->active(self::regionId($truck)) : null;
        if ($active === null) {
            throw new TpNotFound(self::PLACE_NOT_FOUND);
        }
        $regionId = (string) $active['region_id'];
        $version = (string) $active['dataset_version'];
        $place = $this->places->byKeys($regionId, $version, [$placeKey])[$placeKey] ?? null;
        // Only a place inside the region carries a county.
        if ($place === null || !((float) $place['host_fit'] > 0.0) || (string) ($place['county_fips'] ?? '') === '') {
            throw new TpNotFound(self::PLACE_NOT_FOUND);
        }
        return [$regionId, $version, $place];
    }

    /**
     * `hide`: the lead statuses whose places leave the ranking, in the order of STATUSES.
     *
     * @return list<string>
     */
    private static function hideInput(Input $in): array
    {
        if (!$in->has('hide')) {
            return ['hidden'];
        }
        $raw = $in->all()['hide'];
        $items = is_string($raw) ? array_map('trim', explode(',', $raw)) : [null];
        if ($items === ['']) {
            return [];
        }
        foreach ($items as $item) {
            if (!in_array($item, self::STATUSES, true)) {
                throw $in->error('hide', 'must be one of: ' . implode(', ', self::STATUSES), 'V4');
            }
        }
        return array_values(array_intersect(self::STATUSES, $items));
    }

    /**
     * Which places the hidden statuses take out of the ranking.
     *
     * A place the owner has not touched has the status `new`. So when `new` is hidden only the touched
     * places with a status that is shown stay; otherwise the touched places with a hidden status go.
     *
     * @param array<string, array<string, mixed>> $leads read rows keyed by place_key
     * @param list<string> $hide
     * @return array{0: bool, 1: list<string>} [true: the keys are the only places to rank; false: the keys
     *         are left out, the keys in ascending order]
     */
    private static function statusFilter(array $leads, array $hide): array
    {
        $only = in_array('new', $hide, true);
        $keys = [];
        foreach ($leads as $key => $lead) {
            if (in_array($lead['status'], $hide, true) !== $only) {
                $keys[] = (string) $key;
            }
        }
        sort($keys, SORT_STRING);
        return [$only, $keys];
    }

    /**
     * The licence counties of a profile, each once, as stored.
     *
     * @param array<string, mixed> $profile
     * @return list<string>
     */
    private static function counties(array $profile): array
    {
        $out = [];
        foreach ((array) ($profile['licence_counties'] ?? []) as $fips) {
            $fips = (string) $fips;
            if ($fips !== '' && !in_array($fips, $out, true)) {
                $out[] = $fips;
            }
        }
        return $out;
    }

    /** The host segment of a place type in the seed file, or null for a type that has none. */
    private static function hostSegment(string $placeType): ?string
    {
        $A = Seeds::defaults();
        if (!in_array($placeType, Estimator::seed($A, 'vocabulary.place_types'), true)) {
            return null;
        }
        $segment = Estimator::seed($A, 'place_types.rows.' . $placeType)['host_segment'] ?? null;
        return is_string($segment) ? $segment : null;
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
