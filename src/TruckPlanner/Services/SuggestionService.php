<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\JsonSafe;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * The best day and the best week from the owner's own saved spots (04_BACKEND.md 4.12, 5.10). The ranking
 * is the model's (02_MODEL.md 4.15): an exhaustive search over the windows of the spots, by expected
 * take-home. Every figure in the answer is a range with its confidence label. A suggestion says where the
 * numbers are best. It says nothing about whether a truck may stand there: that stays the owner's to check.
 *
 * This class gathers what the model needs: the spots with vectors that are current, the day contexts
 * (holidays, forecast, fuel price), the calibration from the owner's logged services, and the drive legs.
 *
 * Drive legs are asked for sparingly, because a suggestion over N spots could want N x N of them:
 *
 *   1. base to spot and spot to base are fetched for every spot (2N legs);
 *   2. spot to spot is read from the cache only. A pair that is not cached is left out of the leg map, and
 *      the model fills it with its own straight-line estimate, which is exactly what the leg provider would
 *      have answered for it;
 *   3. the model runs. If a suggestion it returns reads a spot-to-spot leg that is such an estimate,
 *      exactly those pairs are fetched and the model runs once more. That second answer stands, whatever it
 *      holds: there is no third run.
 *
 * `fallback_pairs` tells the owner how many drives in the answer are still straight-line estimates.
 *
 * An answer is kept for ten minutes under a key made of every input, the legs included, so an identical
 * question is answered without running the search again and anything that changes an input asks anew.
 *
 * `$truck` is the truck value of TruckBaseController::truck() and `$A` the truck's Assumptions.
 */
class SuggestionService
{
    private const BASE = 'base';
    private const TREAT_AS_FIXED = ['normal', 'holiday'];
    private const SPOT_ID_LENGTH = 36;
    private const WEEK_DAYS = 7;
    private const CACHE_PREFIX = 'tp:suggest:';

    /** The reason a leg provider gives for a pair it was told not to fetch. */
    private const NOT_FETCHED = 'cache_only';

    /**
     * SuggestOptions: name => [smallest, largest].
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const OPTION_RANGES = [
        'service_minutes' => [60, 480],
        'max_stops_per_day' => [1, 3],
        'max_days_per_week' => [1, 7],
        'max_visits_per_spot_per_week' => [1, 7],
        'limit' => [1, 10],
    ];

    private SpotRepository $spots;
    private SpotService $spotService;
    private Clock $clock;

    public function __construct(?SpotRepository $spots = null, ?SpotService $spotService = null, ?Clock $clock = null)
    {
        $this->spots = $spots ?? new SpotRepository();
        $this->spotService = $spotService ?? new SpotService($this->spots);
        $this->clock = $clock ?? new Clock();
    }

    /**
     * The best plans for one date.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param array<int|string, mixed> $body the request body of route 29
     * @return array{suggestions: list<array<string, mixed>>, spots_considered: int, fallback_pairs: int,
     *               context: array<string, mixed>} `suggestions` are Suggestion in rank order (their
     *         `result.stops[*].window.hours` emptied), `context` the DayContext of the date
     * @throws \App\TruckPlanner\Services\Support\TpInvalid for a body that fails validation
     * @throws \App\TruckPlanner\Services\Support\TpConflict while the region data does not fit this server
     */
    public function day(string $orgId, array $truck, array $A, array $body): array
    {
        $in = new Input($body);
        $date = (string) $in->date('date', true);
        self::requireDateAfter($in, 'date', $date, 1);
        $treatAs = $in->enum('treat_as', self::treatAsValues($A));
        $rows = $this->spotRows($in, $orgId, $truck);
        $options = self::optionsInput($in);

        $spots = $this->spotInputs($orgId, $truck, $rows);
        $contexts = Registry::dayContexts()->contexts($truck, $A, $date, 2, [$date => $treatAs]);
        $ctx = $contexts['days'][0]['context'];
        $ctxNext = $contexts['days'][1]['context'];
        $profile = $truck['profile'];
        $cal = $this->calibration($orgId, $truck, $A);

        return $this->answer(
            $orgId,
            $truck,
            $spots,
            ['kind' => 'day', 'start' => $date, 'contexts' => [$ctx, $ctxNext]] + self::commonInputs($A, $profile, $spots, $cal, $options),
            fn (array $legs): array => $this->suggestDay($A, $profile, $ctx, $ctxNext, $spots, $legs, $cal, $options),
            static fn (array $suggestions): array => $suggestions,
            static fn (array $suggestions, int $fallbackPairs): array => [
                'suggestions' => array_map(static fn (array $s): array => self::withoutHours($s), $suggestions),
                'spots_considered' => count($spots),
                'fallback_pairs' => $fallbackPairs,
                'context' => $ctx,
            ]
        );
    }

    /**
     * The best week: which days to work and which plan on each, within the limits on working days and on
     * visits to one spot.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param array<int|string, mixed> $body the request body of route 30
     * @return array{week: array<string, mixed>, spots_considered: int, fallback_pairs: int} `week` is a
     *         WeekSuggestion (the `hours` of every window emptied); its `visits` is a map by spot id
     * @throws \App\TruckPlanner\Services\Support\TpInvalid for a body that fails validation
     * @throws \App\TruckPlanner\Services\Support\TpConflict while the region data does not fit this server
     */
    public function week(string $orgId, array $truck, array $A, array $body): array
    {
        $in = new Input($body);
        $weekStart = (string) $in->date('week_start', true);
        self::requireDateAfter($in, 'week_start', $weekStart, self::WEEK_DAYS);
        if (Estimator::dayOfWeek($weekStart) !== 0) {
            throw $in->error('week_start', 'must be a Monday');
        }
        $treatAs = self::weekTreatAs($in, $weekStart, self::treatAsValues($A));
        $rows = $this->spotRows($in, $orgId, $truck);
        $options = self::optionsInput($in);

        $spots = $this->spotInputs($orgId, $truck, $rows);
        // Eight contexts: the seven days and the Monday after, for a window that would pass midnight.
        $answered = Registry::dayContexts()->contexts($truck, $A, $weekStart, self::WEEK_DAYS + 1, $treatAs);
        $contexts = [];
        foreach ($answered['days'] as $day) {
            $contexts[] = $day['context'];
        }
        $profile = $truck['profile'];
        $cal = $this->calibration($orgId, $truck, $A);

        return $this->answer(
            $orgId,
            $truck,
            $spots,
            ['kind' => 'week', 'start' => $weekStart, 'contexts' => $contexts] + self::commonInputs($A, $profile, $spots, $cal, $options),
            fn (array $legs): array => $this->suggestWeek($A, $profile, $weekStart, $contexts, $spots, $legs, $cal, $options),
            static function (array $week): array {
                $chosen = [];
                foreach ($week['days'] as $day) {
                    if (is_array($day['suggestion'] ?? null)) {
                        $chosen[] = $day['suggestion'];
                    }
                }
                return $chosen;
            },
            static function (array $week, int $fallbackPairs) use ($spots): array {
                foreach ($week['days'] as $d => $day) {
                    if (is_array($day['suggestion'] ?? null)) {
                        $week['days'][$d]['suggestion'] = self::withoutHours($day['suggestion']);
                    }
                }
                return ['week' => $week, 'spots_considered' => count($spots), 'fallback_pairs' => $fallbackPairs];
            }
        );
    }

    // ------------------------------------------------------------------------------------ the model

    /**
     * The model's suggest_day. (Its own method so that a test can count the runs.)
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $ctx
     * @param array<string, mixed>|null $ctxNext
     * @param list<array<string, mixed>> $spots SpotInput
     * @param array<string, array<string, mixed>> $legs LegInput by "<from_id>><to_id>"
     * @param array<string, mixed>|null $cal
     * @param array<string, int>|null $options
     * @return list<array<string, mixed>> Suggestion
     */
    protected function suggestDay(array $A, array $profile, array $ctx, ?array $ctxNext, array $spots, array $legs, ?array $cal, ?array $options): array
    {
        return Estimator::suggestDay($A, $profile, $ctx, $ctxNext, $spots, $legs, $cal, $options);
    }

    /**
     * The model's suggest_week.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param list<array<string, mixed>> $contexts eight DayContext from the Monday
     * @param list<array<string, mixed>> $spots SpotInput
     * @param array<string, array<string, mixed>> $legs
     * @param array<string, mixed>|null $cal
     * @param array<string, int>|null $options
     * @return array<string, mixed> WeekSuggestion
     */
    protected function suggestWeek(array $A, array $profile, string $weekStart, array $contexts, array $spots, array $legs, ?array $cal, ?array $options): array
    {
        return Estimator::suggestWeek($A, $profile, $weekStart, $contexts, $spots, $legs, $cal, $options);
    }

    // ------------------------------------------------------------------------------------ legs, runs, cache

    /**
     * Legs, cache, the model, at most one refinement, cache.
     *
     * @param array<string, mixed> $truck
     * @param list<array<string, mixed>> $spots SpotInput
     * @param array<string, mixed> $inputs every input of the model except the legs: with them, the cache key
     * @param callable(array<string, array<string, mixed>>): array<int|string, mixed> $model the model on a leg map
     * @param callable(array<int|string, mixed>): list<array<string, mixed>> $picked the suggestions of a
     *        model answer that the owner is shown
     * @param callable(array<int|string, mixed>, int): array<string, mixed> $respond the response of a
     *        model answer and its number of fallback pairs
     * @return array<string, mixed>
     */
    private function answer(string $orgId, array $truck, array $spots, array $inputs, callable $model, callable $picked, callable $respond): array
    {
        $points = self::points($truck, $spots);
        $legs = [];
        $notFetched = $this->firstLegs($orgId, $truck, $points, $legs);

        $ttl = (int) TpConfig::get('suggest.cache_ttl_s');
        $key = self::cacheKey($orgId, $inputs, $legs);
        $kept = TpCache::get($key);
        if ($kept !== null) {
            return $kept;
        }

        $result = $model($legs);
        $keys = [$key];
        if ($notFetched) {
            $pairs = self::estimatedSpotPairs($picked($result), $legs);
            if ($pairs !== [] && $this->fetchPairs($orgId, $truck, $points, $pairs, $legs)) {
                // The one refinement pass. Its answer stands, also when it brings other estimates in.
                $result = $model($legs);
                $keys[] = self::cacheKey($orgId, $inputs, $legs);
            }
        }

        $response = $respond($result, self::fallbackPairs($picked($result)));
        foreach ($keys as $k) {
            TpCache::put($k, $response, $ttl);
        }
        return $response;
    }

    /**
     * The leg map before the first run: base to and from every spot, fetched, then every ordered pair of
     * spots as far as the cache holds it.
     *
     * @param array<string, mixed> $truck
     * @param list<array{id: string, lat: float, lng: float}> $points the base first, then the spots
     * @param array<string, array<string, mixed>> $legs filled here
     * @return bool whether a spot-to-spot pair was left out because it is not cached yet
     */
    private function firstLegs(string $orgId, array $truck, array $points, array &$legs): bool
    {
        $ids = [];
        foreach ($points as $point) {
            if ($point['id'] !== self::BASE) {
                $ids[] = $point['id'];
            }
        }
        if ($ids === []) {
            return false;
        }
        $provider = Registry::legs();

        $pairs = [];
        foreach ($ids as $id) {
            $pairs[] = [self::BASE, $id];
            $pairs[] = [$id, self::BASE];
        }
        self::absorb($legs, $provider->legs($orgId, $truck, $points, $pairs, ['tolls' => true]));

        // Spot-to-spot pairs go to the leg provider `suggest.pairs_per_call` at a time.
        $perCall = max(1, (int) TpConfig::get('suggest.pairs_per_call'));
        $notFetched = false;
        $pairs = [];
        foreach ($ids as $from) {
            foreach ($ids as $to) {
                if ($from === $to) {
                    continue;
                }
                $pairs[] = [$from, $to];
                if (count($pairs) === $perCall) {
                    $notFetched = self::absorb($legs, $provider->legs($orgId, $truck, $points, $pairs, ['fetch' => false])) || $notFetched;
                    $pairs = [];
                }
            }
        }
        if ($pairs !== []) {
            $notFetched = self::absorb($legs, $provider->legs($orgId, $truck, $points, $pairs, ['fetch' => false])) || $notFetched;
        }
        return $notFetched;
    }

    /**
     * Fetches exactly these spot-to-spot pairs.
     *
     * @param array<string, mixed> $truck
     * @param list<array{id: string, lat: float, lng: float}> $points
     * @param list<array{0: string, 1: string}> $pairs
     * @param array<string, array<string, mixed>> $legs changed in place
     * @return bool whether the leg map changed
     */
    private function fetchPairs(string $orgId, array $truck, array $points, array $pairs, array &$legs): bool
    {
        $needed = [];
        foreach ($pairs as [$from, $to]) {
            $needed[$from] = true;
            $needed[$to] = true;
        }
        $subset = [];
        foreach ($points as $point) {
            if (isset($needed[$point['id']])) {
                $subset[] = $point;
            }
        }
        $before = $legs;
        self::absorb($legs, Registry::legs()->legs($orgId, $truck, $subset, $pairs, ['tolls' => true]));
        return $legs !== $before;
    }

    /**
     * Takes the answer of a leg provider into the leg map. A leg that says more than the model's own
     * straight line goes in: a routed leg, a leg between two points that are one place, and any leg
     * that carries a correction of the owner. A plain straight-line estimate is left out (and taken
     * out), because the model computes the very same one for a pair it does not find.
     *
     * @param array<string, array<string, mixed>> $legs LegInput by "<from_id>><to_id>"
     * @param list<array<string, mixed>> $driveLegs DriveLeg
     * @return bool whether one of them was a pair the provider was told not to fetch
     */
    private static function absorb(array &$legs, array $driveLegs): bool
    {
        $notFetched = false;
        foreach ($driveLegs as $leg) {
            $key = $leg['from_id'] . '>' . $leg['to_id'];
            $notFetched = $notFetched || ($leg['fallback_reason'] ?? null) === self::NOT_FETCHED;
            if (($leg['source'] ?? null) === 'straight_line' && ($leg['override'] ?? null) === null) {
                unset($legs[$key]);
                continue;
            }
            $input = $leg['leg_input'];
            $legs[$key] = [
                'source' => (string) $input['source'],
                'distance_m' => (float) $input['distance_m'],
                'duration_s' => (float) $input['duration_s'],
                'override_minutes' => ($input['override_minutes'] ?? null) === null ? null : (int) $input['override_minutes'],
                'toll' => (float) $input['toll'],
            ];
        }
        return $notFetched;
    }

    /**
     * The spot-to-spot legs these suggestions read that rest on a straight-line estimate: the pair is not
     * in the leg map, or it is there only because the owner corrected it.
     *
     * The legs a suggestion reads are those the model looks up for its plan (Estimator::requiredLegKeys):
     * the drives between its stops in order and, for a day of three stops, the drive that leaves the
     * middle one out, which is what "this stop adds" is measured against.
     *
     * @param list<array<string, mixed>> $suggestions
     * @param array<string, array<string, mixed>> $legs
     * @return list<array{0: string, 1: string}> each pair once, in the order met
     */
    private static function estimatedSpotPairs(array $suggestions, array $legs): array
    {
        $pairs = [];
        foreach ($suggestions as $suggestion) {
            $stops = [];
            foreach ($suggestion['stops'] as $stop) {
                $stops[] = ['id' => (string) $stop['spot_id']];
            }
            foreach (Estimator::requiredLegKeys($stops) as $key) {
                [$from, $to] = explode('>', $key, 2);
                if ($from === self::BASE || $to === self::BASE) {
                    continue;
                }
                if (!isset($legs[$key]) || $legs[$key]['source'] === 'fallback') {
                    $pairs[$key] = [$from, $to];
                }
            }
        }
        return array_values($pairs);
    }

    /**
     * How many different drives of these suggestions the model timed from a straight-line estimate.
     *
     * @param list<array<string, mixed>> $suggestions
     */
    private static function fallbackPairs(array $suggestions): int
    {
        $pairs = [];
        foreach ($suggestions as $suggestion) {
            foreach ($suggestion['result']['timeline']['legs'] as $leg) {
                if ($leg['source'] === 'fallback') {
                    $pairs[$leg['from_id'] . '>' . $leg['to_id']] = true;
                }
            }
        }
        return count($pairs);
    }

    /**
     * @param array<string, mixed> $inputs
     * @param array<string, array<string, mixed>> $legs
     */
    private static function cacheKey(string $orgId, array $inputs, array $legs): string
    {
        return self::CACHE_PREFIX . $orgId . ':' . sha1(JsonSafe::canonical($inputs + ['legs' => $legs]));
    }

    /**
     * What a day and a week have in common among the inputs of the cache key.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param list<array<string, mixed>> $spots
     * @param array<string, mixed>|null $cal
     * @param array<string, int>|null $options
     * @return array<string, mixed>
     */
    private static function commonInputs(array $A, array $profile, array $spots, ?array $cal, ?array $options): array
    {
        return [
            'model_version' => $A['model_version'],
            'seeds_revision' => $A['seeds_revision'],
            'overrides' => $A['overrides'],
            'region' => $A['region'],
            'profile' => $profile,
            'spots' => $spots,
            'cal' => $cal,
            'options' => $options,
        ];
    }

    // ------------------------------------------------------------------------------------ model inputs

    /**
     * The spots as the model takes them, each with vectors that are current (recomputed and stored first
     * where they are not).
     *
     * @param array<string, mixed> $truck
     * @param list<array<string, mixed>> $rows spot rows
     * @return list<array{spot_id: string, point: array{lat: float, lng: float}, terms: array<string, mixed>,
     *                    vectors: array<string, mixed>}> SpotInput
     */
    private function spotInputs(string $orgId, array $truck, array $rows): array
    {
        $inputs = [];
        foreach ($rows as $row) {
            $spot = $this->spotService->ensureFresh($orgId, $truck, $row);
            $visibility = (string) $spot['terms']['visibility'];
            if (!is_array($spot['vectors'][$visibility] ?? null)) {
                throw new \UnexpectedValueException('a spot has no vectors for its visibility');
            }
            $inputs[] = [
                'spot_id' => (string) $spot['id'],
                'point' => ['lat' => (float) $spot['point']['lat'], 'lng' => (float) $spot['point']['lng']],
                'terms' => $spot['terms'],
                'vectors' => $spot['vectors'][$visibility],
            ];
        }
        return $inputs;
    }

    /**
     * @param array<string, mixed> $truck
     * @param list<array<string, mixed>> $spots SpotInput
     * @return list<array{id: string, lat: float, lng: float}> the base, then the spots
     */
    private static function points(array $truck, array $spots): array
    {
        $base = $truck['profile']['base'];
        $points = [['id' => self::BASE, 'lat' => (float) $base['lat'], 'lng' => (float) $base['lng']]];
        foreach ($spots as $spot) {
            $points[] = ['id' => $spot['spot_id'], 'lat' => $spot['point']['lat'], 'lng' => $spot['point']['lng']];
        }
        return $points;
    }

    /**
     * The calibration state as of today where the truck is.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @return array<string, mixed> CalibrationState
     */
    private function calibration(string $orgId, array $truck, array $A): array
    {
        $zone = (string) ($truck['timezone'] ?? '');
        if (!Clock::isZone($zone)) {
            $zone = (string) TpConfig::get('regions.default_timezone');
        }
        return Registry::calibration()->state($orgId, $truck, $A, $this->clock->today($zone));
    }

    /**
     * A suggestion with the hour-by-hour breakdown of its windows left out: the browser computes a chosen
     * suggestion again with its own estimator, and seven days of hours would be most of the payload.
     *
     * @param array<string, mixed> $suggestion
     * @return array<string, mixed>
     */
    private static function withoutHours(array $suggestion): array
    {
        foreach ($suggestion['result']['stops'] as $i => $stop) {
            if (is_array($stop['window'] ?? null)) {
                $suggestion['result']['stops'][$i]['window']['hours'] = [];
            }
        }
        return $suggestion;
    }

    // ------------------------------------------------------------------------------------ request bodies

    /**
     * `spot_ids`: the spots to choose from. Without it, every active spot of the truck in the order of the
     * spot list, up to the limit. With it, each id must be an active spot of this truck; one given twice
     * counts once.
     *
     * @param array<string, mixed> $truck
     * @return list<array<string, mixed>> spot rows
     */
    private function spotRows(Input $in, string $orgId, array $truck): array
    {
        $max = (int) TpConfig::get('limits.max_suggest_spots');
        $truckId = (string) $truck['id'];
        $items = $in->items('spot_ids', 1, $max);
        if ($items === null) {
            return array_slice($this->spots->listActive($orgId, $truckId), 0, $max);
        }

        $each = $in->each('spot_ids');
        $ids = [];
        foreach ($items as $i => $id) {
            if (!is_string($id) || $id === '' || strlen($id) > self::SPOT_ID_LENGTH) {
                throw $each->notFound($i);
            }
            $ids[$i] = $id;
        }
        $found = [];
        foreach ($this->spots->findMany(array_values($ids), $orgId) as $row) {
            $found[strtolower((string) $row['id'])] = $row;
        }
        $rows = [];
        foreach ($ids as $i => $id) {
            $row = $found[strtolower($id)] ?? null;
            if ($row === null || $row['archived_at'] !== null || $row['truck_id'] !== $truckId) {
                throw $each->notFound($i);
            }
            $rows[(string) $row['id']] = $row;
        }
        return array_values($rows);
    }

    /**
     * `options`: the SuggestOptions that were sent, each within its range. Null when none was.
     *
     * @return array<string, int>|null
     */
    private static function optionsInput(Input $in): ?array
    {
        $options = $in->obj('options');
        if ($options === null) {
            return null;
        }
        $sent = [];
        foreach (self::OPTION_RANGES as $name => [$min, $max]) {
            $value = $options->int($name, $min, $max);
            if ($value === null) {
                continue;
            }
            if ($name === 'service_minutes' && $value % 60 !== 0) {
                throw $options->error($name, 'must be a multiple of 60');
            }
            $sent[$name] = $value;
        }
        return $sent === [] ? null : $sent;
    }

    /**
     * `treat_as` of a week: an object of "treat this day as" values by date. Only the seven dates of the
     * week are read.
     *
     * @param list<string> $allowed
     * @return array<string, string> date => value
     */
    private static function weekTreatAs(Input $in, string $weekStart, array $allowed): array
    {
        $byDate = $in->obj('treat_as');
        $out = [];
        if ($byDate === null) {
            return $out;
        }
        for ($d = 0; $d < self::WEEK_DAYS; $d++) {
            $date = Estimator::addDays($weekStart, $d);
            $value = $byDate->enum($date, $allowed);
            if ($value !== null) {
                $out[$date] = $value;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $A
     * @return list<string> normal, holiday and the seven days of the week
     */
    private static function treatAsValues(array $A): array
    {
        return array_merge(self::TREAT_AS_FIXED, array_values(Estimator::seed($A, 'vocabulary.dow')));
    }

    /**
     * The model needs the context of the days that follow as well: the date `$days` later must be a date
     * it knows, else the field is not a usable date (V7).
     */
    private static function requireDateAfter(Input $in, string $field, string $date, int $days): void
    {
        try {
            Estimator::parseDate(Estimator::addDays($date, $days));
        } catch (\InvalidArgumentException $e) {
            throw $in->error($field, 'must be a date in the form YYYY-MM-DD', 'V7');
        }
    }
}
