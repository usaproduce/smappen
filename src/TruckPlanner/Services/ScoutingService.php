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
 * Scout: the named places within reach of the truck's base that could be asked to host it, the best few of
 * every kind, and what the owner keeps about each of them (04_BACKEND.md 4.15, 5.4, 5.9; the math is
 * 02_MODEL.md 4.16).
 *
 * The list is balanced by kind of place. One ranking over all places is a map of demand: around a job
 * centre it is fifty office buildings. So every kind (the place types of the seed file that host at all:
 * taprooms, markets, offices, apartment communities, venues, ...) gets its own short list, and a site that
 * is mapped as several buildings is listed once.
 *
 * Ranking is a funnel of two deterministic stages, because the model's own function for one place costs a
 * few milliseconds and a region holds thousands of places:
 *
 *   1. Screen. Every possible host of the kinds asked for, inside the reach of the drive limit and the
 *      licence counties, is read with its stored location vector (Q6, a page at a time) and given one
 *      number by ScoutScreen: what its best three hours of a typical week might leave, weighed by how
 *      commonly that kind of place hosts trucks, less a straight-line estimate of the drive. Each kind
 *      keeps a pool of its best places whose estimated round trip is within the limit, then those
 *      estimated a little over it, one entry for each site.
 *   2. Exact. The pools go through Estimator::scoutEstimate a batch of each kind at a time, with the
 *      drive legs of the leg provider. A place whose round trip takes more than twice the limit is outside
 *      the limit. A further batch of a kind is looked at only while that kind is short of its quota. The
 *      model ranks each kind; neighbours in its order that all fill the truck are then told apart by
 *      demand (ScoutScreen::capacityOrder).
 *
 * Both stages are cached for a day, under keys made of everything they were computed from. What is added
 * afterwards is never cached: the place's display columns, the owner's lead, the map link, where the legs
 * came from.
 *
 * Places are OpenStreetMap rows. Phone and website come from OpenStreetMap unless the owner asks Google
 * for one place (lookupContact): that answer is passed to the browser and kept nowhere. The lead keeps
 * Google's id of the place, which Google's terms let a customer store, and nothing else of the answer.
 *
 * Every figure is the model's range with its confidence label. Nothing here knows who owns a place or what
 * the local rules are, and nothing it returns says that a place would take the truck: these are places
 * that could be asked.
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

    /** The standing line that travels with every list. */
    public const NOTICE = 'Permission to trade here and local rules are yours to check.';

    /** How a contact answer was obtained: a search by name, or a request by the stored place id. */
    public const SOURCE_SEARCH = 'text_search';
    public const SOURCE_DETAILS = 'place_details';

    // Strings 1, 2 and 12 of 03_DATA.md section 14. String 9 (drive times) is the setting `routing.attribution`.
    private const ATTRIBUTION_PLACES = "\u{00A9} OpenStreetMap contributors";
    private const ATTRIBUTION_PLACES_SENTENCE = "Place data \u{00A9} OpenStreetMap contributors, available under the Open Database License (ODbL).";
    private const ATTRIBUTION_CONTACT = 'Phone and website from Google Maps';

    /**
     * The version of the rules that build the list (the order inside a kind, the pools, one entry for a
     * site). It is part of the cache keys: raise it when one of these rules changes, so that no list
     * that was computed under the old rules is served from the cache.
     */
    private const LIST_RULES = 1;

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

    // ------------------------------------------------------------------------------------ the list

    /**
     * The list of route 38: the best few places of every kind, grouped by kind.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A the truck's Assumptions
     * @param array<int|string, mixed> $opts the query of the request: `hide` (a comma list of lead statuses
     *        whose places leave the list; `hidden` when absent, none when empty), `types` (a comma list of
     *        place types: only these kinds are looked at, each in more depth) and `refresh` (1 computes
     *        both stages again)
     * @return array<string, mixed> `candidates` (ScoutCandidate, kind by kind in the order of `kinds`, inside
     *         a kind by position), `kinds` (for each kind asked for: how many places were screened, listed
     *         and merged), `quota`, `screened`, `truncated`, `limit_minutes`, `licence_counties`,
     *         `dataset_version`, `cached`, `notice`, `attribution`
     * @throws TpConflict while the truck's region has no data, or data that does not fit this server
     */
    public function rank(string $orgId, array $truck, array $A, array $opts): array
    {
        $in = Input::query($opts);
        $hide = self::hideInput($in);
        $hostable = self::kinds($A);
        $types = self::typesInput($in, $hostable);
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

        $leads = $this->leads->forTruck($orgId, $truckId, $regionId);
        [$only, $keys] = self::statusFilter($leads, $hide);
        $kinds = $types ?? $hostable;
        $plan = self::plan($A, count($kinds), $types !== null, $only);
        // A place the owner has a lead for is listed in its own right: it is never merged into another.
        $touched = $plan['merge'] ? array_map('strval', array_keys($leads)) : [];

        $fuel = Registry::fuel()->resolve($truck);
        $fuelPrice = (float) $fuel['price_per_gal'];
        $cal = Registry::calibration()->state($orgId, $truck, $A, $this->clock->today(Clock::zoneOf($truck)));

        // ---- stage 1: the screen, and the pool it leaves for every kind
        $shortHash = sha1(JsonSafe::canonical([
            'rules' => self::LIST_RULES,
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
            'kinds' => $kinds,
            'plan' => $plan,
            'only_listed' => $only,
            'keys' => $keys,
            'touched' => $touched,
            'truck_factor' => (float) $cal['truck_factor'],
            'fuel_price' => $fuelPrice,
            'settings' => TpConfig::get('scout'),
        ]));
        $ttl = (int) TpConfig::get('scout.cache_ttl_s');
        $shortKey = 'tp:scout:s:' . $orgId . ':' . $shortHash;
        $short = $refresh ? null : TpCache::get($shortKey);
        $cached = self::isShortlist($short);
        if (!$cached) {
            $short = $this->screen($A, $profile, $cal, $fuelPrice, $base, $regionId, $version, $limit, $counties, $only, $keys, $kinds, $plan, $touched);
            TpCache::put($shortKey, $short, $ttl);
        }

        // ---- stage 2: the model on the pools, a batch of each kind at a time, with the legs of the leg provider
        // What a range and its label take from the owner's logged services.
        $evidence = Estimator::evidenceFrom($cal, null);
        $quota = $plan['quota'];
        $passed = [];
        $legSources = [];
        $rounds = 0;
        for ($round = 0; $round < $plan['batches']; $round++) {
            // Only the kinds that are still short of their quota are asked about again.
            $batch = [];
            foreach ($kinds as $kind) {
                if (count($passed[$kind] ?? []) >= $quota) {
                    continue;
                }
                foreach (array_slice($short['pools'][$kind] ?? [], $round * $plan['batch'], $plan['batch']) as $entry) {
                    $batch[] = $entry;
                }
            }
            if ($batch === []) {
                break;
            }
            $rounds++;
            [$legInputs, $sources] = $this->legs($orgId, $truck, $base, $batch);
            $legSources += $sources;
            $batchKey = 'tp:scout:r:' . $orgId . ':'
                . sha1($shortHash . ':' . $round . ':' . JsonSafe::canonical(['legs' => $legInputs, 'evidence' => $evidence]));
            $stored = $refresh ? null : TpCache::get($batchKey);
            if (is_array($stored) && is_array($stored['results'] ?? null) && array_is_list($stored['results'])) {
                $results = $stored['results'];
            } else {
                $cached = false;
                $results = [];
                foreach ($batch as $entry) {
                    $key = (string) $entry['key'];
                    $legs = array_intersect_key($legInputs, [self::BASE_ID . '>' . $key => true, $key . '>' . self::BASE_ID => true]);
                    $result = Estimator::scoutEstimate($A, $profile, self::placeInput($entry, $regionId, $version), $legs, $cal, $fuelPrice);
                    // "Inside the drive limit" is decided here, on the legs a plan would drive.
                    if ($result !== null && (int) $result['round_trip']['minutes'] <= 2 * $limit) {
                        $results[] = $result;
                    }
                }
                TpCache::put($batchKey, ['results' => $results], $ttl);
            }
            foreach ($results as $result) {
                $passed[(string) $result['place_type']][] = $result;
            }
        }
        $cached = $cached && $rounds > 0;

        // ---- the list of every kind: the model's order, neighbours at capacity by demand, the quota
        $entries = [];
        foreach ($short['pools'] as $pool) {
            foreach ($pool as $entry) {
                $entries[(string) $entry['key']] = $entry;
            }
        }
        $capacityKey = ScoutScreen::capacityKey($A, $profile);
        $lists = [];
        $full = [];
        foreach ($kinds as $kind) {
            $byKey = [];
            $order = [];
            $atCapacity = [];
            $demand = [];
            foreach (Estimator::scoutRank($passed[$kind] ?? []) as $result) {
                $key = (string) $result['place_id'];
                $byKey[$key] = $result;
                $order[] = $key;
                if ($capacityKey > 0 && Estimator::qkey((float) $result['orders']['value']) >= $capacityKey) {
                    $atCapacity[$key] = true;
                    $demand[$key] = (float) ($entries[$key]['demand'] ?? 0.0);
                }
            }
            $list = [];
            foreach (array_slice(ScoutScreen::capacityOrder($order, $atCapacity, $demand), 0, $quota) as $n => $key) {
                $result = $byKey[(string) $key];
                $result['position'] = $n + 1;
                $list[] = $result;
                $full[(string) $key] = isset($atCapacity[$key]);
            }
            $lists[$kind] = $list;
        }
        $lists = self::capped($lists, (int) TpConfig::get('scout.max_listed'));

        // ---- what is never cached: the place as it is listed, the owner's lead, the links
        $listedKeys = [];
        foreach ($lists as $list) {
            foreach ($list as $result) {
                $listedKeys[] = (string) $result['place_id'];
            }
        }
        $display = $listedKeys === [] ? [] : $this->places->byKeys($regionId, $version, $listedKeys);
        $shownLeads = array_intersect_key($leads, array_flip($listedKeys));
        $liveSpots = $this->liveSpots($orgId, $shownLeads);

        $candidates = [];
        $summary = [];
        foreach ($kinds as $kind) {
            $merged = 0;
            foreach ($lists[$kind] as $result) {
                $key = (string) $result['place_id'];
                $entry = $entries[$key] ?? [];
                $place = self::placeOf($key, $display[$key] ?? null, $entry);
                $lead = $leads[$key] ?? null;
                $merged += (int) ($entry['merged'] ?? 0);
                $candidates[] = [
                    'kind' => $kind,
                    'result' => $result,
                    'place' => $place,
                    'lead' => self::lead($lead, $key, $liveSpots),
                    'maps_url' => self::mapsUrl($place, $lead),
                    'leg_sources' => [
                        'out' => $legSources[$key]['out'] ?? 'straight_line',
                        'back' => $legSources[$key]['back'] ?? 'straight_line',
                    ],
                    'at_capacity' => $full[$key] ?? false,
                    // The key that orders neighbours at capacity. It ranks; it is not an estimate.
                    'demand_key' => ($full[$key] ?? false) ? (float) ($entry['demand'] ?? 0.0) : null,
                    'merged' => (int) ($entry['merged'] ?? 0),
                ];
            }
            $summary[] = [
                'kind' => $kind,
                'screened' => (int) ($short['kinds'][$kind] ?? 0),
                'listed' => count($lists[$kind]),
                'merged' => $merged,
            ];
        }

        return [
            'candidates' => $candidates,
            'kinds' => $summary,
            'quota' => $quota,
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

    /**
     * The kinds of place Scout lists: the place types of the seed file that host at all, those that host
     * most commonly first (the seed's `host_fit`), equal ones in the order of the seed vocabulary.
     *
     * @param array<string, mixed> $A
     * @return list<string> place types
     */
    public static function kinds(array $A): array
    {
        $rows = [];
        foreach (array_values(Estimator::seed($A, 'vocabulary.place_types')) as $n => $type) {
            $fit = (float) Estimator::seed($A, 'place_types.rows.' . $type)['host_fit'];
            if ($fit > 0.0) {
                $rows[] = [Estimator::qkey($fit), $n, (string) $type];
            }
        }
        usort($rows, static fn (array $a, array $b): int => ($b[0] <=> $a[0]) ?: ($a[1] <=> $b[1]));
        return array_column($rows, 2);
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
     * Google is asked on every call and its answer goes to the browser: nothing of it is written to a
     * column, a cache entry or a log line. What stays on the lead is Google's id of the place, whether
     * the search found the place, and when.
     *
     * The first lookup of a place searches for it by name. The match is taken only when it lies at the
     * place; anything else is answered as not found, so the owner is never shown the details of another
     * place. A later lookup asks for the place by the id that was kept and writes nothing at all. `$force`
     * searches by name again although an id is kept (for a match that turned out to be another place), and
     * so does a lookup whose id Google no longer knows.
     *
     * @param array<string, mixed> $truck
     * @return array{lead: array<string, mixed>, contact: array<string, mixed>} `contact` is the answer as
     *         it is passed on: `found`, the matched `name` and `address`, `phone`, `website`, `maps_uri`,
     *         `fetched_at`, `source`, `saved` (always false) and `attribution`
     * @throws TpNotFound for a key that is not a possible host of the truck's region
     * @throws TpUnavailable when this server has no Google key, or Google refused or failed lately
     * @throws TpRateLimited when the shared bucket of lookups is empty
     */
    public function lookupContact(string $orgId, array $truck, string $placeKey, bool $force): array
    {
        [$regionId, , $place] = $this->place($truck, $placeKey);
        $truckId = (string) $truck['id'];
        $lead = $this->leads->find($orgId, $truckId, $regionId, $placeKey);
        $client = $this->contacts ??= new PlacesContactClient();

        $placeId = $lead['google_place_id'] ?? null;
        if ($lead !== null && is_string($placeId) && $placeId !== '' && !$force) {
            $this->admit();
            $answer = $this->settled($client->details($placeId));
            if (is_array($answer['place'])) {
                // Nothing is written: the lead keeps the id it has.
                return [
                    'lead' => self::lead($lead, $placeKey, $this->liveSpots($orgId, [$lead])),
                    'contact' => $this->contact($answer['place'], self::SOURCE_DETAILS),
                ];
            }
            // Google no longer knows the id: the place is searched for by name again.
        }

        $this->admit();
        $answer = $this->settled($client->find((string) ($place['name'] ?? ''), (float) $place['lat'], (float) $place['lng']));
        $match = is_array($answer['place']) ? $answer['place'] : null;
        $leadId = $this->leads->upsert($orgId, $truckId, $regionId, $placeKey, self::snapshot($place));
        // Of the answer the lead takes the id of the place and nothing else.
        $this->leads->setMatch($leadId, $orgId, $match !== null, $match['place_id'] ?? null);
        return [
            'lead' => $this->leadOfPlace($orgId, $truckId, $regionId, $placeKey),
            'contact' => $this->contact($match, self::SOURCE_SEARCH),
        ];
    }

    /**
     * Saves a place as a spot at its own point, linked to the place, and links the lead to the spot
     * (route 41). The host is the one the place's type describes; the owner may give its size, whether the
     * truck is the only food there, the visibility and another name. A new lead becomes `shortlisted`.
     *
     * The spot takes its name, address, phone and website from the OpenStreetMap columns. Of Google only
     * the place id that the lead keeps travels (as the last argument of SpotService::create).
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
     * How long the list of one request is: the quota of a kind, and what the two stages take to fill it.
     *
     * The quota is `scout.kind_quota` places of every kind, `scout.kind_quota_deep` when the request names
     * its kinds, and in both cases no more than an even share of `scout.max_listed`. When only the owner's
     * own places are listed (`new` is hidden) there is no balance to keep: every kind may fill the list.
     * The model ranks at most `scout.max_results` places at a time (a fixed seed), so no quota and no pool
     * is longer than that.
     *
     * @param array<string, mixed> $A
     * @param int $kinds how many kinds the request asks for
     * @param bool $deep the request names its kinds
     * @param bool $own only places the owner has a lead for are listed
     * @return array{quota: int, batch: int, batches: int, pool: int, scan: int, merge: bool} `batch` places
     *         of a kind go through the model at a time, at most `batches` times; `pool` is the most places
     *         of a kind the screen keeps for that; `scan` the most places of a kind that are looked at to
     *         find them; `merge` says whether a site is listed once
     */
    private static function plan(array $A, int $kinds, bool $deep, bool $own): array
    {
        $most = max(1, (int) Estimator::seed($A, 'scout.max_results'));
        $maxListed = max(1, (int) TpConfig::get('scout.max_listed'));
        if ($own) {
            $quota = min($most, $maxListed);
        } else {
            $share = (int) ceil($maxListed / max(1, $kinds));
            $quota = max(1, min((int) TpConfig::get($deep ? 'scout.kind_quota_deep' : 'scout.kind_quota'), $most, $share));
        }
        $batch = $quota + max(0, (int) TpConfig::get('scout.shortlist_extra'));
        $batches = max(1, (int) TpConfig::get('scout.max_batches'));
        $pool = min($most, $batch * $batches);
        return [
            'quota' => $quota,
            'batch' => $batch,
            'batches' => $batches,
            'pool' => $pool,
            'scan' => $own ? $pool : $pool * max(1, (int) TpConfig::get('scout.site_scan')),
            'merge' => !$own,
        ];
    }

    /**
     * Reads the possible hosts within reach, screens them and keeps a pool of every kind for the model.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed>|null $cal
     * @param array{lat: float, lng: float} $base
     * @param list<string> $counties
     * @param bool $only true: `$keys` are the only places to look at; false: `$keys` are left out
     * @param list<string> $keys
     * @param list<string> $kinds the place types to look at
     * @param array<string, mixed> $plan what plan() answered
     * @param list<string> $touched the keys of the places that are never merged into another
     * @return array{screened: int, truncated: bool, kinds: array<string, int>, pools: array<string, list<array<string, mixed>>>}
     *         `kinds`: how many places of each kind were screened. `pools`: for each kind its places in the
     *         order stage 2 takes them, each with what stage 2 needs (`key`, `type`, `lat`, `lng`, `county`,
     *         `kitchen`, `segment`, `size` and `vec`, the hexadecimal text of its stored vector), its
     *         `demand` and how many places of the same site were `merged` into it
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
        array $keys,
        array $kinds,
        array $plan,
        array $touched
    ): array {
        $slack = (float) TpConfig::get('scout.reach_slack');
        $timeFactor = (float) $profile['truck_time_factor'];
        $reachMinutes = $slack * $limit;
        $box = PointRepository::box($base['lat'], $base['lng'], self::reachMetres($A, $reachMinutes / $timeFactor));
        $listed = array_flip($keys);
        $asked = array_flip($kinds);

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
                // The kinds are chosen before anything is screened.
                if (!isset($asked[$row[PlaceRepository::VEC_TYPE]])
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
        $orders = ScoutScreen::kindOrders($rows, $scores, ScoutScreen::capacityKey($A, $profile));
        $reachable = ScoutScreen::reachable($A, $profile, $base, $rows, $scores, $orders, $limit, $slack, $plan['scan']);

        // Of each kind: the places inside the limit, and places near it only as far as the pool has room.
        $walks = [];
        $named = [];
        foreach ($kinds as $kind) {
            $taken = $reachable[$kind] ?? [];
            $inside = 0;
            foreach ($taken as [, $isInside]) {
                $inside += $isInside ? 1 : 0;
            }
            $room = max(0, $plan['pool'] - $inside) * ($plan['merge'] ? max(1, (int) TpConfig::get('scout.site_scan')) : 1);
            $walk = [];
            foreach ($taken as [$i, $isInside]) {
                if (!$isInside && $room-- <= 0) {
                    continue;
                }
                $walk[] = [$i, $isInside];
                $named[] = (string) $rows[$i][PlaceRepository::VEC_KEY];
            }
            $walks[$kind] = $walk;
        }

        // A site that is mapped as several places is one entry: the names say which places are one site.
        $names = $plan['merge'] && $named !== [] ? $this->places->byKeys($regionId, $version, $named) : [];
        $exempt = array_flip($touched);
        $radius = (float) TpConfig::get('scout.same_site_m');
        $screened = [];
        $pools = [];
        foreach ($kinds as $kind) {
            $screened[$kind] = count($orders[$kind] ?? []);
            $walk = $walks[$kind];
            $sites = [];
            foreach ($walk as [$i]) {
                $key = (string) $rows[$i][PlaceRepository::VEC_KEY];
                $sites[] = [
                    'site' => ScoutScreen::siteName((string) ($names[$key]['name'] ?? '')),
                    'lat' => (float) $rows[$i][PlaceRepository::VEC_LAT],
                    'lng' => (float) $rows[$i][PlaceRepository::VEC_LNG],
                    'exempt' => isset($exempt[$key]),
                ];
            }
            $kept = ScoutScreen::sameSites($sites, $radius);
            // The places inside the limit lead the pool; those near it follow while there is room.
            $pool = [];
            foreach ([true, false] as $wanted) {
                foreach ($kept as [$n, $merged]) {
                    if (count($pool) >= $plan['pool']) {
                        break 2;
                    }
                    [$i, $isInside] = $walk[$n];
                    if ($isInside !== $wanted) {
                        continue;
                    }
                    $row = $rows[$i];
                    $pool[] = [
                        'key' => (string) $row[PlaceRepository::VEC_KEY],
                        'type' => (string) $row[PlaceRepository::VEC_TYPE],
                        'lat' => (float) $row[PlaceRepository::VEC_LAT],
                        'lng' => (float) $row[PlaceRepository::VEC_LNG],
                        'county' => $row[PlaceRepository::VEC_COUNTY],
                        'kitchen' => (string) $row[PlaceRepository::VEC_KITCHEN],
                        'segment' => $row[PlaceRepository::VEC_SEGMENT],
                        'size' => (float) $row[PlaceRepository::VEC_SIZE],
                        'vec' => bin2hex((string) $row[PlaceRepository::VEC_BYTES]),
                        'demand' => (float) $scores['demand'][$i],
                        'merged' => count($merged),
                    ];
                }
            }
            $pools[$kind] = $pool;
        }
        return ['screened' => count($rows), 'truncated' => $truncated, 'kinds' => $screened, 'pools' => $pools];
    }

    /**
     * The drive legs of a batch, there and back for each place, from the leg provider. Tolls are not asked
     * for: a scouting round trip is compared on time, miles and fuel. A batch that would ask for more
     * elements than one call may fetch is asked for in several calls.
     *
     * @param array<string, mixed> $truck
     * @param array{lat: float, lng: float} $base
     * @param list<array<string, mixed>> $batch places of the pools
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array{out?: string, back?: string}>}
     *         [LegInput by "base><key>" and "<key>>base", the DriveLeg source of each leg by place key]
     */
    private function legs(string $orgId, array $truck, array $base, array $batch): array
    {
        $perCall = (int) TpConfig::get('routing.max_elements_per_call');
        $inputs = [];
        $sources = [];
        foreach (array_chunk($batch, max(1, (int) (($perCall - $perCall % 2) / 2))) as $part) {
            $points = [['id' => self::BASE_ID, 'lat' => $base['lat'], 'lng' => $base['lng']]];
            $pairs = [];
            foreach ($part as $entry) {
                $key = (string) $entry['key'];
                $points[] = ['id' => $key, 'lat' => (float) $entry['lat'], 'lng' => (float) $entry['lng']];
                $pairs[] = [self::BASE_ID, $key];
                $pairs[] = [$key, self::BASE_ID];
            }
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
     * @param array<int|string, mixed>|null $value what the cache holds
     */
    private static function isShortlist(?array $value): bool
    {
        return $value !== null && is_int($value['screened'] ?? null) && is_bool($value['truncated'] ?? null)
            && is_array($value['kinds'] ?? null) && is_array($value['pools'] ?? null);
    }

    // ------------------------------------------------------------------------------------ stage 2

    /**
     * The PlaceInput of the model for a place of a pool. Its vectors are the stored ones: computed by the
     * region loader at the place's point, visibility normal, the place's own source point left out.
     *
     * @param array<string, mixed> $entry a place of a pool
     * @return array<string, mixed>
     */
    private static function placeInput(array $entry, string $regionId, string $version): array
    {
        $key = (string) $entry['key'];
        $bytes = hex2bin((string) $entry['vec']);
        if ($bytes === false || strlen($bytes) !== VectorCodec::BLOCK_BYTES) {
            throw new \UnexpectedValueException('a place of a pool has no usable vector');
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

    /**
     * The overall cap. When the lists of the kinds hold more places than one answer may, places are taken
     * a round at a time (the first of every kind, then the second of every kind, and so on, kinds in their
     * order) until the answer is full, so that no kind loses its best places to another.
     *
     * @param array<string, list<array<string, mixed>>> $lists the list of every kind
     * @return array<string, list<array<string, mixed>>>
     */
    private static function capped(array $lists, int $max): array
    {
        $total = 0;
        foreach ($lists as $list) {
            $total += count($list);
        }
        if ($total <= $max) {
            return $lists;
        }
        $keep = [];
        $left = max(0, $max);
        for ($depth = 0; $left > 0; $depth++) {
            $any = false;
            foreach ($lists as $kind => $list) {
                if ($left > 0 && isset($list[$depth])) {
                    $keep[$kind] = $depth + 1;
                    $left--;
                    $any = true;
                }
            }
            if (!$any) {
                break;
            }
        }
        foreach ($lists as $kind => $list) {
            $lists[$kind] = array_slice($list, 0, $keep[$kind] ?? 0);
        }
        return $lists;
    }

    // ------------------------------------------------------------------------------------ shapes

    /**
     * The `place` of a ScoutCandidate: the OpenStreetMap columns the list shows.
     *
     * @param array<string, mixed>|null $display the Q7 row of the place
     * @param array<string, mixed> $entry its pool entry, used where the Q7 row is missing
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
     * `google` is what a contact lookup left on the lead, and null before any lookup: Google's id of the
     * place, whether the search by name found it, when, and a link that opens the place in Google Maps.
     * The link is built here from the id; it is not something Google answered.
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
        if ($row['lookup_state'] !== null) {
            $placeId = $row['google_place_id'];
            $name = $row['place_name'];
            $google = [
                'place_id' => $placeId,
                'lookup_state' => (string) $row['lookup_state'],
                'matched_at' => $row['matched_at'],
                'maps_url' => is_string($placeId) && $placeId !== '' && is_string($name) && $name !== ''
                    ? MapsUrl::place($name, $placeId)
                    : null,
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
     * What Google answered about a place, as the browser is told it: found or not, what was matched, when,
     * and that it is not saved. This is the only place the texts of an answer go.
     *
     * @param array<string, ?string>|null $place the place of PlacesContactClient, null when none was matched
     * @param string $source SOURCE_SEARCH or SOURCE_DETAILS
     * @return array<string, mixed>
     */
    private function contact(?array $place, string $source): array
    {
        return [
            'found' => $place !== null,
            'name' => $place['name'] ?? null,
            'address' => $place['address'] ?? null,
            'phone' => $place['phone'] ?? null,
            'website' => $place['website'] ?? null,
            'maps_uri' => $place['maps_uri'] ?? null,
            'fetched_at' => $this->clock->nowUtc()->format('Y-m-d\TH:i:s\Z'),
            'source' => $source,
            'saved' => false,
            'attribution' => self::ATTRIBUTION_CONTACT,
        ];
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

    // ------------------------------------------------------------------------------------ Google

    /**
     * May Google be asked now? One token of the shared bucket is taken for the call that follows.
     *
     * @throws TpUnavailable without a key, while Google refuses the key or a back-off runs, and when the
     *                       day's or the month's spending allowance does not cover one more lookup
     * @throws TpRateLimited when the bucket is empty
     */
    private function admit(): void
    {
        $guard = $this->guard ??= new UpstreamGuard($this->clock);
        if (!$guard->hasGoogleKey() || $guard->refused('places') || $guard->inBackoff('places')) {
            throw new TpUnavailable(self::LOOKUP_UNAVAILABLE);
        }
        // The dearer of the two lookups is the price asked for: a first lookup is a text search.
        if ($guard->spendLeftUsd() < UpstreamGuard::costUsd((string) TpConfig::get('places.sku'), 1)) {
            throw new TpUnavailable(self::LOOKUP_UNAVAILABLE);
        }
        if (!$guard->takeTokens((string) TpConfig::get('places.bucket'), 1, (int) TpConfig::get('places.bucket_wait_s'))) {
            throw new TpRateLimited(self::LOOKUP_BUSY);
        }
    }

    /**
     * An answer of the Places client, or the refusal its failure stands for: a refused key is remembered
     * for an hour, anything else backs off for a minute.
     *
     * @param array{ok: bool, error: ?string, match: ?string, place: array<string, ?string>|null} $answer
     * @return array{ok: bool, error: ?string, match: ?string, place: array<string, ?string>|null}
     * @throws TpUnavailable
     */
    private function settled(array $answer): array
    {
        if ($answer['ok']) {
            return $answer;
        }
        $guard = $this->guard ??= new UpstreamGuard($this->clock);
        if ($answer['error'] === PlacesContactClient::ERROR_REFUSED) {
            $guard->markRefused('places');
        } elseif ($answer['error'] !== PlacesContactClient::ERROR_NO_KEY) {
            $reason = $answer['error'] === PlacesContactClient::ERROR_QUOTA ? 'quota' : 'upstream';
            $guard->backoff('places', (int) TpConfig::get('places.backoff_s'), $reason);
        }
        throw new TpUnavailable(self::LOOKUP_UNAVAILABLE);
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
     * `hide`: the lead statuses whose places leave the list, in the order of STATUSES.
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
     * `types`: the kinds the request asks for, in the order of the list, or null when it names none.
     *
     * @param list<string> $hostable every kind there is (kinds())
     * @return list<string>|null
     */
    private static function typesInput(Input $in, array $hostable): ?array
    {
        if (!$in->has('types')) {
            return null;
        }
        $raw = $in->all()['types'];
        $items = is_string($raw) ? array_map('trim', explode(',', $raw)) : [null];
        foreach ($items as $item) {
            if (!in_array($item, $hostable, true)) {
                throw $in->error('types', 'must be one of: ' . implode(', ', $hostable), 'V4');
            }
        }
        return array_values(array_intersect($hostable, $items));
    }

    /**
     * Which places the hidden statuses take out of the list.
     *
     * A place the owner has not touched has the status `new`. So when `new` is hidden only the touched
     * places with a status that is shown stay; otherwise the touched places with a hidden status go.
     *
     * @param array<string, array<string, mixed>> $leads read rows keyed by place_key
     * @param list<string> $hide
     * @return array{0: bool, 1: list<string>} [true: the keys are the only places to list; false: the keys
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
