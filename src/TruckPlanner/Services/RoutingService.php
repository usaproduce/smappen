<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\DriveLegRepository;
use App\TruckPlanner\Data\DriveOverrideRepository;
use App\TruckPlanner\Data\LegKey;
use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Services\Contracts\LegProvider;
use App\TruckPlanner\Services\Fallback\StraightLineLegs;
use App\TruckPlanner\Services\Google\DistanceMatrixClient;
use App\TruckPlanner\Services\Google\RoutesMatrixClient;
use App\TruckPlanner\Services\Http\UpstreamGuard;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\Money;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpInvalid;
use App\TruckPlanner\Services\Support\TpNotFound;

/**
 * Drive legs between points, and the owner's corrections of them (04_BACKEND.md 4.10 and 5.3).
 *
 * A leg comes from Google (Routes API; the legacy Distance Matrix API once, when Routes refuses the key),
 * is kept in a shared cache for at most 30 days, and is asked for again after that. A leg Google could
 * not supply is never an error: it is the model's straight-line estimate, labelled `straight_line` with
 * the reason. The label is always the truth: a straight line is never stored and never called a Google leg.
 *
 * What stands between a request and Google, in this order, each with its reason when it stops a batch:
 * no key (`no_key`); Routes and the legacy API both refused within the hour (`refused`); a back-off after
 * a failure (`quota` or `upstream`); more than 12 seconds spent in this call (`timeout`); the daily
 * element budget of the organization or of the server, or 650 elements fetched in this call (`budget`);
 * no tokens in the shared bucket (`rate`). Nothing is retried.
 *
 * The owner's corrections (minutes and toll per directed leg) sit on top of whatever leg there is. They
 * are the owner's data and never expire. A correction that changes what a plan would drive stamps the
 * truck, so plan results computed before it are stale.
 *
 * Every legs() call ends by deleting cached legs whose 30 days have ended.
 */
class RoutingService implements LegProvider
{
    public const MODES = ['loop', 'chain', 'matrix', 'pairs'];

    private const API_ROUTES = 'routes';
    private const API_LEGACY = 'legacy';
    private const NOT_FOUND = 'Correction not found';

    private ?DriveLegRepository $legRows;
    private ?DriveOverrideRepository $overrideRows;
    private ?UpstreamGuard $guard;
    private ?RoutesMatrixClient $routes;
    private ?DistanceMatrixClient $legacy;
    private ?Clock $clock;
    private ?TruckRepository $trucks;

    /** @var callable(): float seconds on a clock that only moves forward */
    private $stopwatch;

    /**
     * @param (callable(): float)|null $stopwatch what elapsed time is measured with (tests); null uses
     *                                            the process clock
     */
    public function __construct(
        ?DriveLegRepository $legRows = null,
        ?DriveOverrideRepository $overrideRows = null,
        ?UpstreamGuard $guard = null,
        ?RoutesMatrixClient $routes = null,
        ?DistanceMatrixClient $legacy = null,
        ?Clock $clock = null,
        ?callable $stopwatch = null,
        ?TruckRepository $trucks = null
    ) {
        $this->legRows = $legRows;
        $this->overrideRows = $overrideRows;
        $this->guard = $guard;
        $this->routes = $routes;
        $this->legacy = $legacy;
        $this->clock = $clock;
        $this->trucks = $trucks;
        $this->stopwatch = $stopwatch ?? static function (): float {
            return microtime(true);
        };
    }

    // ---------------------------------------------------------------------------------------------------
    // The contract
    // ---------------------------------------------------------------------------------------------------

    public function legs(string $orgId, array $truck, array $points, array $pairs, array $options = []): array
    {
        $started = ($this->stopwatch)();
        $profile = is_array($truck['profile'] ?? null) ? $truck['profile'] : [];
        $routeKey = LegKey::routeKey($profile);
        $tolls = (bool) ($options['tolls'] ?? true);
        $mayFetch = (bool) ($options['fetch'] ?? true);

        $byId = [];
        foreach ($points as $point) {
            $lat = (float) $point['lat'];
            $lng = (float) $point['lng'];
            $byId[(string) $point['id']] = ['lat' => $lat, 'lng' => $lng, 'key' => LegKey::of($lat, $lng)];
        }
        // One entry per pair, in pair order: [from id, to id, pair id or null for one and the same place].
        $plan = [];
        $wanted = [];
        foreach ($pairs as $pair) {
            $fromId = (string) $pair[0];
            $toId = (string) $pair[1];
            if (!isset($byId[$fromId], $byId[$toId])) {
                throw new \LogicException('a leg names a point that was not given');
            }
            $fromKey = $byId[$fromId]['key'];
            $toKey = $byId[$toId]['key'];
            if ($fromKey === $toKey) {
                $plan[] = [$fromId, $toId, null];
                continue;
            }
            $pairKey = [$fromKey[0], $fromKey[1], $toKey[0], $toKey[1]];
            $pairId = DriveLegRepository::pairId($pairKey);
            $wanted[$pairId] = $pairKey;
            $plan[] = [$fromId, $toId, $pairId];
        }

        try {
            $rows = [];
            $reasons = [];
            $corrections = [];
            if ($wanted !== []) {
                $run = [
                    'org' => $orgId,
                    'route_key' => $routeKey,
                    'tolls' => $tolls,
                    'started' => $started,
                    'missing' => [],
                    'reasons' => [],
                    'answered' => [],
                    'fetched' => 0,
                    'bad_requests' => 0,
                    'routes_refused' => null,
                    'legacy_refused' => null,
                    'backoff' => false,
                    'org_left' => null,
                    'global_left' => null,
                ];
                $rows = $this->legRows()->findFresh($routeKey, array_values($wanted));
                foreach ($wanted as $pairId => $pairKey) {
                    if (!isset($rows[$pairId]) || !$this->satisfies($rows[$pairId], $run)) {
                        $run['missing'][$pairId] = $pairKey;
                    }
                }
                if ($run['missing'] !== [] && !$mayFetch) {
                    foreach ($run['missing'] as $pairId => $pairKey) {
                        $run['reasons'][$pairId] = 'cache_only';
                    }
                } elseif ($run['missing'] !== []) {
                    foreach (self::batches($run['missing']) as $batch) {
                        $this->send($batch, $run);
                    }
                    $rows = $this->withAnswered($rows, $run);
                }
                $reasons = $run['reasons'];
                $corrections = $this->overrideRows()->findForPairs($orgId, (string) ($truck['id'] ?? ''), array_values($wanted));
            }

            $legs = [];
            foreach ($plan as [$fromId, $toId, $pairId]) {
                if ($pairId === null) {
                    $legs[] = StraightLineLegs::samePoint($fromId, $toId);
                    continue;
                }
                $row = $rows[$pairId] ?? null;
                if ($row !== null && $row['route_found'] === true) {
                    $leg = self::googleLeg($fromId, $toId, $row);
                } else {
                    // A cached "no route" is Google's answer too: the straight line says so.
                    $reason = $row !== null ? 'route_not_found' : ($reasons[$pairId] ?? 'upstream');
                    $leg = StraightLineLegs::straightLine($fromId, $toId, $byId[$fromId], $byId[$toId], $reason);
                }
                $legs[] = isset($corrections[$pairId]) ? self::corrected($leg, $corrections[$pairId]) : $leg;
            }
            return $legs;
        } finally {
            // Google content is deleted when its 30 days end, not only hidden.
            try {
                $this->legRows()->purgeExpired((int) TpConfig::get('routing.purge_rows_per_call'));
            } catch (\Throwable $e) {
                error_log('[tp] expired drive legs were not deleted: ' . get_class($e));
            }
        }
    }

    public function status(): array
    {
        $guard = $this->guard();
        if (!$guard->hasGoogleKey()) {
            return ['state' => 'no_key'];
        }
        if ($guard->refused(self::API_ROUTES) && $guard->refused(self::API_LEGACY)) {
            return ['state' => 'refused'];
        }
        if ($guard->inBackoff(self::API_ROUTES)) {
            return ['state' => 'backoff'];
        }
        return ['state' => 'ok'];
    }

    public function movePoint(string $orgId, array $truck, array $oldPoint, array $newPoint): void
    {
        $this->overrideRows()->movePoint(
            $orgId,
            (string) $truck['id'],
            LegKey::of((float) $oldPoint['lat'], (float) $oldPoint['lng']),
            LegKey::of((float) $newPoint['lat'], (float) $newPoint['lng'])
        );
    }

    // ---------------------------------------------------------------------------------------------------
    // Helpers of the other services
    // ---------------------------------------------------------------------------------------------------

    /**
     * The directed pairs of a list of point ids: `chain` = each point to the next, `loop` = the chain
     * and the last point back to the first, `matrix` = every ordered pair of two different points.
     *
     * @param list<string> $ids
     * @return list<array{0: string, 1: string}>
     */
    public static function pairs(string $mode, array $ids): array
    {
        $ids = array_values(array_map('strval', $ids));
        $n = count($ids);
        $pairs = [];
        switch ($mode) {
            case 'chain':
            case 'loop':
                for ($i = 0; $i + 1 < $n; $i++) {
                    $pairs[] = [$ids[$i], $ids[$i + 1]];
                }
                if ($mode === 'loop' && $n >= 2) {
                    $pairs[] = [$ids[$n - 1], $ids[0]];
                }
                return $pairs;
            case 'matrix':
                for ($i = 0; $i < $n; $i++) {
                    for ($j = 0; $j < $n; $j++) {
                        if ($i !== $j) {
                            $pairs[] = [$ids[$i], $ids[$j]];
                        }
                    }
                }
                return $pairs;
        }
        throw new \LogicException('no pairs can be derived for mode ' . $mode);
    }

    /**
     * The legs as the model takes them: a map "<from_id>><to_id>" => LegInput.
     *
     * @param list<array<string, mixed>> $driveLegs DriveLeg list
     * @return array<string, array<string, mixed>>
     */
    public static function legInputMap(array $driveLegs): array
    {
        $map = [];
        foreach ($driveLegs as $leg) {
            $map[$leg['from_id'] . '>' . $leg['to_id']] = $leg['leg_input'];
        }
        return $map;
    }

    // ---------------------------------------------------------------------------------------------------
    // Routes 18 to 21
    // ---------------------------------------------------------------------------------------------------

    /**
     * The answer of POST /api/truck/drive-times (4.10).
     *
     * @param array<string, mixed> $truck the truck value of TruckBaseController::truck()
     * @param array<int|string, mixed> $body the request body
     * @return array{legs: list<array<string, mixed>>, routing: array<string, mixed>}
     * @throws TpInvalid
     */
    public function driveTimes(string $orgId, array $truck, array $body): array
    {
        $in = new Input($body);
        $maxPairs = (int) TpConfig::get('limits.max_pairs_per_drive_request');

        $items = $in->items('points', 2, (int) TpConfig::get('limits.max_points_per_drive_request'), true);
        $each = $in->each('points');
        $points = [];
        $known = [];
        foreach (array_keys((array) $items) as $i) {
            $item = $each->obj($i, true);
            $id = (string) $item->str('id', 64, true);
            if ($id === '') {
                throw $item->error('id', 'is required', 'V1');
            }
            if (isset($known[$id])) {
                throw $item->error('id', 'is repeated');
            }
            $point = $each->point($i, true);
            $known[$id] = true;
            $points[] = ['id' => $id, 'lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
        }

        $mode = $in->enum('mode', self::MODES) ?? 'loop';
        if ($mode === 'pairs') {
            $given = $in->items('pairs', 1, $maxPairs, true);
            $eachPair = $in->each('pairs');
            $pairs = [];
            foreach (array_keys((array) $given) as $i) {
                $eachPair->items($i, 2, 2, true);
                $ends = $eachPair->each($i);
                $pair = [];
                foreach ([0, 1] as $end) {
                    $id = (string) $ends->str($end, 64, true);
                    if (!isset($known[$id])) {
                        throw $ends->notFound($end);
                    }
                    $pair[] = $id;
                }
                $pairs[] = $pair;
            }
        } else {
            $pairs = self::pairs($mode, array_column($points, 'id'));
        }
        if (count($pairs) > $maxPairs) {
            throw new TpInvalid('Too many legs in one request (at most ' . $maxPairs . ')');
        }
        $tolls = $in->bool('tolls') ?? true;
        $fetch = $in->bool('fetch') ?? true;

        $legs = $this->legs($orgId, $truck, $points, $pairs, ['tolls' => $tolls, 'fetch' => $fetch]);
        $profile = is_array($truck['profile'] ?? null) ? $truck['profile'] : [];
        return [
            'legs' => $legs,
            'routing' => [
                'state' => $this->status()['state'],
                'route_key' => LegKey::routeKey($profile),
                'leg_ttl_days' => (int) TpConfig::get('routing.leg_ttl_days'),
                'attribution' => (string) TpConfig::get('routing.attribution'),
            ],
        ];
    }

    /**
     * Every correction of the truck, as the API shows them: the two points are the rounded keys.
     *
     * @param array<string, mixed> $truck
     * @return list<array<string, mixed>> [{id, from: {lat, lng}, to: {lat, lng}, minutes, toll, note, updated_at}]
     */
    public function overrides(string $orgId, array $truck): array
    {
        $out = [];
        foreach ($this->overrideRows()->listForTruck($orgId, (string) $truck['id']) as $row) {
            $out[] = self::overrideView($row);
        }
        return $out;
    }

    /**
     * Saves the owner's correction of one directed leg (route 20): what the drive takes (`minutes`) and
     * what toll it costs (`toll`). There is one correction per pair of rounded points and direction; a
     * second save changes it, and a key the body does not carry keeps its stored value (null clears one).
     *
     * @param array<string, mixed> $truck
     * @param array<int|string, mixed> $body
     * @return array<string, mixed> the correction as overrides() shows it
     * @throws TpInvalid
     */
    public function saveOverride(string $orgId, array $truck, array $body): array
    {
        $in = new Input($body);
        $from = $in->point('from', true);
        $to = $in->point('to', true);
        $minutes = $in->int('minutes', 1, 600);
        $toll = $in->num('toll', 0.0, 500.0);
        $note = $in->str('note', 160);

        $fromKey = LegKey::of((float) $from['lat'], (float) $from['lng']);
        $toKey = LegKey::of((float) $to['lat'], (float) $to['lng']);
        if ($fromKey === $toKey) {
            throw new TpInvalid('from and to are the same place');
        }
        $truckId = (string) $truck['id'];
        $pairKey = [$fromKey[0], $fromKey[1], $toKey[0], $toKey[1]];
        $stored = $this->overrideRows()->findForPairs($orgId, $truckId, [$pairKey])[DriveLegRepository::pairId($pairKey)] ?? null;
        if ($stored !== null) {
            $minutes = $in->has('minutes') ? $minutes : $stored['minutes'];
            $toll = $in->has('toll') ? $toll : $stored['toll'];
            $note = $in->has('note') ? $note : (string) $stored['note'];
        }
        if ($minutes === null && $toll === null) {
            throw new TpInvalid('Give minutes or toll');
        }
        $id = $this->overrideRows()->upsert(
            $orgId,
            $truckId,
            $fromKey,
            $toKey,
            $minutes,
            $toll === null ? null : Money::toCents((float) $toll),
            (string) ($note ?? '')
        );
        $saved = $this->overrideRows()->find($id, $orgId);
        if ($saved === null) {
            throw new \RuntimeException('a correction that was just saved is not there');
        }
        // Minutes and toll are what a plan is evaluated with: a plan computed before they changed is stale.
        if ($stored === null || $stored['minutes'] !== $saved['minutes'] || $stored['toll'] !== $saved['toll']) {
            $this->trucks()->touch($truckId, $orgId);
        }
        return self::overrideView($saved);
    }

    /**
     * Deletes one correction (route 21). Another organization's id answers like a missing one.
     *
     * @return array{id: string, deleted: bool}
     * @throws TpNotFound
     */
    public function deleteOverride(string $orgId, string $id): array
    {
        $row = $this->overrideRows()->find($id, $orgId);
        if ($row === null) {
            throw new TpNotFound(self::NOT_FOUND);
        }
        $this->overrideRows()->delete($id, $orgId);
        // A deleted row leaves no time stamp behind: the truck carries it, and plans computed with the
        // correction are stale.
        $this->trucks()->touch((string) $row['truck_id'], $orgId);
        return ['id' => $id, 'deleted' => true];
    }

    // ---------------------------------------------------------------------------------------------------
    // Fetching
    // ---------------------------------------------------------------------------------------------------

    /**
     * Does a cached row answer a pair? Always when no toll is wanted, when the row carries toll
     * information, or when it says there is no route. A row of the legacy API, which knows no tolls, does
     * while Routes is refused: asking again would change nothing.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $run
     */
    private function satisfies(array $row, array &$run): bool
    {
        if ($run['tolls'] !== true || $row['route_found'] !== true || $row['toll_state'] !== DriveLegRepository::TOLL_NOT_ASKED) {
            return true;
        }
        return $row['src'] === DriveLegRepository::SRC_LEGACY && $this->refused(self::API_ROUTES, $run);
    }

    /**
     * The batches the missing pairs are fetched in, each {o: [point key], d: [point key]} with at most 625
     * elements, in ascending order of their first origin.
     *
     * With `O` the distinct origins, `D` the distinct destinations and `n` the number of pairs: when
     * `n >= 0.6 * |O| * |D|` the whole grid O x D is asked. Otherwise the pairs are asked as stars, largest
     * first: one origin with exactly its missing destinations, or one destination with exactly its missing
     * origins (every candidate back to the base is one request). A grid or star of more than 625 elements
     * is cut by parts().
     *
     * @param array<string, array<int, int>> $missing pair id => pair key
     * @return list<array{o: list<array{0: int, 1: int}>, d: list<array{0: int, 1: int}>}>
     */
    public static function batches(array $missing): array
    {
        $keys = [];
        $byOrigin = [];
        $byDestination = [];
        foreach ($missing as $pairKey) {
            $pairKey = array_values($pairKey);
            $o = $pairKey[0] . ',' . $pairKey[1];
            $d = $pairKey[2] . ',' . $pairKey[3];
            $keys[$o] = [(int) $pairKey[0], (int) $pairKey[1]];
            $keys[$d] = [(int) $pairKey[2], (int) $pairKey[3]];
            $byOrigin[$o][$d] = true;
            $byDestination[$d][$o] = true;
        }

        // Each grid is [origins, destinations]; a star is a grid with one point on one side.
        $grids = [];
        if (count($missing) >= (float) TpConfig::get('routing.grid_fill_ratio') * count($byOrigin) * count($byDestination)) {
            $grids[] = [self::sortedKeys(array_keys($byOrigin), $keys), self::sortedKeys(array_keys($byDestination), $keys)];
        } else {
            while ($byOrigin !== []) {
                $origin = self::largest($byOrigin, $keys);
                $destination = self::largest($byDestination, $keys);
                if (count($byDestination[$destination]) > count($byOrigin[$origin])) {
                    $others = array_keys($byDestination[$destination]);
                    $grids[] = [self::sortedKeys($others, $keys), [$keys[$destination]]];
                    foreach ($others as $other) {
                        unset($byOrigin[$other][$destination]);
                        if ($byOrigin[$other] === []) {
                            unset($byOrigin[$other]);
                        }
                    }
                    unset($byDestination[$destination]);
                } else {
                    $others = array_keys($byOrigin[$origin]);
                    $grids[] = [[$keys[$origin]], self::sortedKeys($others, $keys)];
                    foreach ($others as $other) {
                        unset($byDestination[$other][$origin]);
                        if ($byDestination[$other] === []) {
                            unset($byDestination[$other]);
                        }
                    }
                    unset($byOrigin[$origin]);
                }
            }
        }

        $side = (int) TpConfig::get('routing.routes_chunk_side');
        $most = (int) TpConfig::get('routing.routes_chunk_elements');
        $batches = [];
        foreach ($grids as [$origins, $destinations]) {
            foreach (self::parts($origins, $destinations, $side, $most, $most) as $part) {
                $batches[] = $part;
            }
        }
        usort($batches, static function (array $a, array $b): int {
            return [$a['o'][0], $a['d'][0]] <=> [$b['o'][0], $b['d'][0]];
        });
        return $batches;
    }

    /**
     * A grid cut into requests an API takes: at most `$most` elements each and at most `$longest` points
     * on a side. When the shorter side has at most `$side` points it is sent whole and the longer side is
     * cut to fit (1 x 625, 3 x 208, 25 x 25); otherwise both sides are cut into `$side`.
     *
     * @param list<array{0: int, 1: int}> $origins
     * @param list<array{0: int, 1: int}> $destinations
     * @return list<array{o: list<array{0: int, 1: int}>, d: list<array{0: int, 1: int}>}>
     */
    public static function parts(array $origins, array $destinations, int $side, int $most, int $longest): array
    {
        if ($origins === [] || $destinations === []) {
            return [];
        }
        $short = max(1, min(count($origins), count($destinations), $side));
        $long = max(1, min($longest, intdiv(max(1, $most), $short)));
        [$perOrigin, $perDestination] = count($origins) <= count($destinations) ? [$short, $long] : [$long, $short];
        $parts = [];
        foreach (array_chunk($origins, $perOrigin) as $o) {
            foreach (array_chunk($destinations, $perDestination) as $d) {
                $parts[] = ['o' => $o, 'd' => $d];
            }
        }
        return $parts;
    }

    /**
     * One batch: to Routes, or to the legacy API while Routes is refused. A refusal of Routes is
     * remembered for an hour and followed by one attempt at the legacy API for the same batch.
     *
     * @param array{o: list<array{0: int, 1: int}>, d: list<array{0: int, 1: int}>} $batch
     * @param array<string, mixed> $run
     */
    private function send(array $batch, array &$run): void
    {
        if ($this->guard()->hasGoogleKey() && $this->refused(self::API_ROUTES, $run)) {
            $this->sendLegacy($batch, $run);
            return;
        }
        $reason = $this->stopped($batch, $run);
        if ($reason !== null) {
            self::giveUp($batch, $run, $reason);
            return;
        }
        $answer = ($this->routes ??= new RoutesMatrixClient())->matrix($batch['o'], $batch['d'], $run['route_key'], $run['tolls']);
        if ($answer['ok'] === true) {
            $this->accept($batch, $answer['elements'], DriveLegRepository::SRC_ROUTES, $run);
            return;
        }
        if ($answer['error'] === RoutesMatrixClient::REFUSED) {
            $this->guard()->markRefused(self::API_ROUTES);
            $run['routes_refused'] = true;
            $this->sendLegacy($batch, $run);
            return;
        }
        self::giveUp($batch, $run, $this->afterFailure((string) $answer['error'], $run));
    }

    /**
     * The same batch through the legacy API, which takes at most 100 elements and 25 points on a side:
     * in parts of at most 10 x 10 (a star in parts of 25), each tried once.
     *
     * @param array{o: list<array{0: int, 1: int}>, d: list<array{0: int, 1: int}>} $batch
     * @param array<string, mixed> $run
     */
    private function sendLegacy(array $batch, array &$run): void
    {
        $parts = self::parts(
            $batch['o'],
            $batch['d'],
            (int) TpConfig::get('routing.legacy_chunk_side'),
            DistanceMatrixClient::MAX_ELEMENTS,
            min(DistanceMatrixClient::MAX_ORIGINS, DistanceMatrixClient::MAX_DESTINATIONS)
        );
        foreach ($parts as $part) {
            $reason = $this->stopped($part, $run);
            if ($reason !== null) {
                self::giveUp($part, $run, $reason);
                continue;
            }
            $answer = ($this->legacy ??= new DistanceMatrixClient())->matrix($part['o'], $part['d'], $run['route_key']);
            if ($answer['ok'] === true) {
                $this->accept($part, $answer['elements'], DriveLegRepository::SRC_LEGACY, $run);
            } elseif ($answer['error'] === RoutesMatrixClient::REFUSED) {
                $this->guard()->markRefused(self::API_LEGACY);
                $run['legacy_refused'] = true;
                self::giveUp($part, $run, 'refused');
            } else {
                self::giveUp($part, $run, $this->afterFailure((string) $answer['error'], $run));
            }
        }
    }

    /**
     * The checks before a batch, in the order of 5.3. The answer is the reason that stops the batch, or
     * null when it may be sent (its tokens are then taken).
     *
     * @param array{o: list<array{0: int, 1: int}>, d: list<array{0: int, 1: int}>} $batch
     * @param array<string, mixed> $run
     */
    private function stopped(array $batch, array &$run): ?string
    {
        $guard = $this->guard();
        if (!$guard->hasGoogleKey()) {
            return 'no_key';
        }
        if ($this->refused(self::API_ROUTES, $run) && $this->refused(self::API_LEGACY, $run)) {
            return 'refused';
        }
        if ($run['backoff'] === false) {
            $run['backoff'] = $guard->backoffReason(self::API_ROUTES);
        }
        if ($run['backoff'] !== null) {
            return $run['backoff'] === 'quota' ? 'quota' : 'upstream';
        }
        if (($this->stopwatch)() - $run['started'] > (float) TpConfig::get('routing.call_budget_s')) {
            return 'timeout';
        }
        $elements = count($batch['o']) * count($batch['d']);
        if ($run['fetched'] + $elements > (int) TpConfig::get('routing.max_elements_per_call')) {
            return 'budget';
        }
        $run['org_left'] ??= $guard->orgElementsLeft($run['org']);
        if ($run['org_left'] < $elements) {
            return 'budget';
        }
        $run['global_left'] ??= $guard->globalElementsLeft();
        if ($run['global_left'] < $elements) {
            return 'budget';
        }
        $bucket = (string) TpConfig::get('routing.bucket');
        if (!$guard->takeTokens($bucket, $elements, (int) TpConfig::get('routing.bucket_wait_s'))) {
            return 'rate';
        }
        return null;
    }

    /**
     * What a failed call leaves behind: a back-off for the next calls, and the reason of this batch.
     *
     * @param array<string, mixed> $run
     */
    private function afterFailure(string $error, array &$run): string
    {
        switch ($error) {
            case RoutesMatrixClient::NO_KEY:
                return 'no_key';
            case RoutesMatrixClient::BAD_REQUEST:
                // Google found this one request wrong (the client logged why): no reason to stop the others.
                // A second one in the same call is no accident any more, and is treated as a failing upstream.
                $run['bad_requests']++;
                if ($run['bad_requests'] < (int) TpConfig::get('routing.max_bad_requests_per_call')) {
                    return 'upstream';
                }
                break;
            case RoutesMatrixClient::QUOTA:
                $this->guard()->backoff(self::API_ROUTES, (int) TpConfig::get('routing.backoff_quota_s'), 'quota');
                $run['backoff'] = 'quota';
                return 'quota';
        }
        $this->guard()->backoff(self::API_ROUTES, (int) TpConfig::get('routing.backoff_upstream_s'), 'upstream');
        $run['backoff'] = 'upstream';
        return $error === RoutesMatrixClient::TIMEOUT ? 'timeout' : 'upstream';
    }

    /**
     * A successful answer: every element whose two ends differ is stored, the budgets are spent, and the
     * wanted pairs of the batch are answered. A wanted pair without an element could not be computed.
     *
     * @param array{o: list<array{0: int, 1: int}>, d: list<array{0: int, 1: int}>} $batch
     * @param list<array<string, mixed>> $elements
     * @param array<string, mixed> $run
     */
    private function accept(array $batch, array $elements, string $src, array &$run): void
    {
        $count = count($batch['o']) * count($batch['d']);
        $run['fetched'] += $count;
        $run['org_left'] -= $count;
        $run['global_left'] -= $count;
        $this->guard()->spendOrgElements($run['org'], $count);

        $rows = [];
        foreach ($elements as $element) {
            $o = $batch['o'][(int) $element['o']] ?? null;
            $d = $batch['d'][(int) $element['d']] ?? null;
            if ($o === null || $d === null || $o === $d) {
                continue;
            }
            $pairKey = [$o[0], $o[1], $d[0], $d[1]];
            $rows[DriveLegRepository::pairId($pairKey)] = [
                'pair' => $pairKey,
                'route_key' => $run['route_key'],
                'src' => $src,
                'route_found' => $element['found'] === true,
                'duration_s' => (int) $element['duration_s'],
                'distance_m' => (int) $element['distance_m'],
                'toll_state' => (int) $element['toll_state'],
                'toll' => $element['toll_cents'] === null ? null : Money::fromCents((int) $element['toll_cents']),
            ];
        }
        foreach (self::pairIds($batch) as $pairId) {
            if (!isset($run['missing'][$pairId])) {
                continue;
            }
            if (isset($rows[$pairId])) {
                $run['answered'][$pairId] = $rows[$pairId];
                unset($run['reasons'][$pairId]);
            } else {
                $run['reasons'][$pairId] = 'upstream';
            }
        }
        if ($rows === []) {
            return;
        }
        try {
            $this->legRows()->upsertMany(array_values($rows));
        } catch (\Throwable $e) {
            // The legs are still served from this answer. They will be asked for again next time.
            error_log('[tp] drive legs were not cached: ' . get_class($e));
        }
    }

    /**
     * The rows of the pairs that were just answered, as the cache now holds them (its clock dates them).
     * A row that could not be written is served from the answer itself, dated today.
     *
     * @param array<string, array<string, mixed>> $rows
     * @param array<string, mixed> $run
     * @return array<string, array<string, mixed>>
     */
    private function withAnswered(array $rows, array $run): array
    {
        if ($run['answered'] === []) {
            return $rows;
        }
        $stored = $this->legRows()->findFresh($run['route_key'], array_values(array_column($run['answered'], 'pair')));
        foreach ($run['answered'] as $pairId => $row) {
            $rows[$pairId] = $stored[$pairId] ?? ($row + [
                'fetched_on' => ($this->clock ??= new Clock())->today('UTC'),
                'age_days' => 0,
            ]);
        }
        return $rows;
    }

    /**
     * The wanted pairs of a batch that was not sent, or that failed, become straight lines with this reason.
     *
     * @param array{o: list<array{0: int, 1: int}>, d: list<array{0: int, 1: int}>} $batch
     * @param array<string, mixed> $run
     */
    private static function giveUp(array $batch, array &$run, string $reason): void
    {
        foreach (self::pairIds($batch) as $pairId) {
            if (isset($run['missing'][$pairId]) && !isset($run['answered'][$pairId])) {
                $run['reasons'][$pairId] = $reason;
            }
        }
    }

    /**
     * Did Google refuse this API for our key within the hour? Read once per call.
     *
     * @param array<string, mixed> $run
     */
    private function refused(string $api, array &$run): bool
    {
        $slot = $api === self::API_ROUTES ? 'routes_refused' : 'legacy_refused';
        return $run[$slot] ??= $this->guard()->refused($api);
    }

    // ---------------------------------------------------------------------------------------------------
    // Shapes
    // ---------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row a row of DriveLegRepository with a route
     * @return array<string, mixed> DriveLeg
     */
    private static function googleLeg(string $fromId, string $toId, array $row): array
    {
        $state = (int) $row['toll_state'];
        $googleToll = ($state === DriveLegRepository::TOLL_ESTIMATE && $row['toll'] !== null) ? (float) $row['toll'] : null;
        $distance = (float) $row['distance_m'];
        $duration = (float) $row['duration_s'];
        return [
            'from_id' => $fromId,
            'to_id' => $toId,
            'source' => (string) $row['src'],
            'fetched_on' => (string) $row['fetched_on'],
            'age_days' => (int) $row['age_days'],
            'distance_m' => $distance,
            'duration_s' => $duration,
            'toll_state' => DriveLegRepository::TOLL_STATES[$state] ?? DriveLegRepository::TOLL_STATES[0],
            'google_toll' => $googleToll,
            'toll_source' => $googleToll === null ? 'none' : 'google',
            'override' => null,
            'fallback_reason' => null,
            'leg_input' => [
                'source' => 'google',
                'distance_m' => $distance,
                'duration_s' => $duration,
                'override_minutes' => null,
                'toll' => $googleToll ?? 0.0,
            ],
        ];
    }

    /**
     * A leg with the owner's correction on top: the minutes replace the estimate at every hour, and a
     * toll of the owner's replaces Google's.
     *
     * @param array<string, mixed> $leg DriveLeg
     * @param array<string, mixed> $correction a row of DriveOverrideRepository
     * @return array<string, mixed> DriveLeg
     */
    private static function corrected(array $leg, array $correction): array
    {
        $leg['override'] = [
            'id' => (string) $correction['id'],
            'minutes' => $correction['minutes'],
            'toll' => $correction['toll'],
            'note' => (string) $correction['note'],
        ];
        $leg['leg_input']['override_minutes'] = $correction['minutes'];
        if ($correction['toll'] !== null) {
            $leg['leg_input']['toll'] = (float) $correction['toll'];
            $leg['toll_source'] = 'owner';
        }
        return $leg;
    }

    /**
     * @param array<string, mixed> $row a row of DriveOverrideRepository
     * @return array<string, mixed>
     */
    private static function overrideView(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'from' => ['lat' => LegKey::deg((int) $row['from_key'][0]), 'lng' => LegKey::deg((int) $row['from_key'][1])],
            'to' => ['lat' => LegKey::deg((int) $row['to_key'][0]), 'lng' => LegKey::deg((int) $row['to_key'][1])],
            'minutes' => $row['minutes'],
            'toll' => $row['toll'],
            'note' => (string) $row['note'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * The pair ids of every origin and destination of a batch.
     *
     * @param array{o: list<array{0: int, 1: int}>, d: list<array{0: int, 1: int}>} $batch
     * @return list<string>
     */
    private static function pairIds(array $batch): array
    {
        $ids = [];
        foreach ($batch['o'] as $o) {
            foreach ($batch['d'] as $d) {
                $ids[] = DriveLegRepository::pairId([$o[0], $o[1], $d[0], $d[1]]);
            }
        }
        return $ids;
    }

    /**
     * The point with the most pairs left; of equals the one with the smallest key.
     *
     * @param array<string, array<string, bool>> $groups point text => the points it is paired with
     * @param array<string, array{0: int, 1: int}> $keys
     */
    private static function largest(array $groups, array $keys): string
    {
        $best = null;
        foreach ($groups as $point => $others) {
            $point = (string) $point;
            if ($best === null
                || count($others) > count($groups[$best])
                || (count($others) === count($groups[$best]) && $keys[$point] < $keys[$best])) {
                $best = $point;
            }
        }
        return (string) $best;
    }

    /**
     * @param list<int|string> $points point texts
     * @param array<string, array{0: int, 1: int}> $keys
     * @return list<array{0: int, 1: int}> their keys in ascending order
     */
    private static function sortedKeys(array $points, array $keys): array
    {
        $out = [];
        foreach ($points as $point) {
            $out[] = $keys[(string) $point];
        }
        sort($out);
        return $out;
    }

    private function legRows(): DriveLegRepository
    {
        return $this->legRows ??= new DriveLegRepository();
    }

    private function overrideRows(): DriveOverrideRepository
    {
        return $this->overrideRows ??= new DriveOverrideRepository();
    }

    private function guard(): UpstreamGuard
    {
        return $this->guard ??= new UpstreamGuard();
    }

    private function trucks(): TruckRepository
    {
        return $this->trucks ??= new TruckRepository();
    }
}
