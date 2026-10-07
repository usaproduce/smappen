<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\PlanRepository;
use App\TruckPlanner\Data\ServiceLogRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\MapsUrl;
use App\TruckPlanner\Services\Support\Money;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpInvalid;
use App\TruckPlanner\Services\Support\TpNotFound;

/**
 * Day plans (04_BACKEND.md 4.11, 5.8): the stops of one service date in the owner's order, evaluated by
 * the model into a timeline, the orders and money of every stop, the day's costs and take-home, what each
 * stop adds and the warnings.
 *
 * A truck has at most one plan per service date. The stops of a plan are replaced as a set and never
 * reordered. Every save evaluates the plan first and stores the result with the context it was computed
 * in (day contexts, drive legs, calibration, fuel price, versions): the **snapshot**. A snapshot is
 *
 *     none      not there
 *     fresh     what an evaluation would give now, as far as the server can tell
 *     stale     computed under another model, seeds or dataset version, or before the truck, a spot it
 *               refers to or a logged service changed
 *     expired   holding Google drive legs and older than 30 days: it is not shown and the next listing
 *               empties it
 *
 * Nothing re-evaluates by itself: the owner saves (routes 23, 26) or asks (route 28).
 *
 * Every figure of a result is a range with a confidence label, as the model returns it. A leg Google did
 * not supply is a labelled straight-line estimate, never an error. Nothing here says anything about
 * whether the truck may trade at a stop.
 *
 * `$truck` is the truck value of TruckBaseController::truck(), `$A` the truck's Assumptions. A stop
 * travels in two forms: the stop row of PlanRepository, and the model's StopInput built from it.
 */
class PlanningService
{
    private const NOT_FOUND = 'Plan not found';
    private const DATE_TAKEN = 'A plan already exists for this date';
    private const BODY_KEYS = ['date', 'name', 'notes', 'treat_as', 'status', 'stops'];
    private const STATES = ['draft', 'planned', 'done', 'cancelled'];
    private const KINDS = ['spot', 'event', 'catering'];
    private const EVENT_TYPES = ['general', 'food_focused', 'evening_show', 'incidental'];
    private const TREAT_AS_FIXED = ['normal', 'holiday'];
    private const SHOWN_STATES = ['fresh', 'stale'];

    private const MAX_MINUTE = 2880;
    private const MAX_STAGE_MINUTES = 240;
    private const MAX_FEE = 100000.0;
    private const MAX_ATTENDANCE = 2000000.0;
    private const MAX_VENDORS = 500;
    private const MAX_HEADCOUNT = 100000.0;
    private const MAX_PRICE_PER_HEAD = 1000.0;
    private const MAX_CONTRACT = 1000000.0;
    private const ID_LENGTH = 36;
    private const PREVIEW_ID_FORM = '/\A[A-Za-z0-9_-]{1,36}\z/';
    private const BASE = 'base';

    private PlanRepository $plans;
    private SpotRepository $spots;
    private SpotService $spotService;
    private ServiceLogRepository $logs;
    private RegionService $regions;
    private Clock $clock;

    public function __construct(
        ?PlanRepository $plans = null,
        ?SpotRepository $spots = null,
        ?SpotService $spotService = null,
        ?ServiceLogRepository $logs = null,
        ?RegionService $regions = null,
        ?Clock $clock = null
    ) {
        $this->plans = $plans ?? new PlanRepository();
        $this->spots = $spots ?? new SpotRepository();
        $this->regions = $regions ?? new RegionService();
        $this->spotService = $spotService ?? new SpotService($this->spots, null, $this->regions);
        $this->logs = $logs ?? new ServiceLogRepository();
        $this->clock = $clock ?? new Clock();
    }

    // ------------------------------------------------------------------------------------ reading

    /**
     * The plans of a date range, by date (route 22). Expired snapshots of the organization are emptied
     * first.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param array<int|string, mixed> $query `from`, `to` and the flag `stops`
     * @return list<array<string, mixed>> without `stops=1`: {id, date, name, treat_as, status, stop_count,
     *         result_state, summary, updated_at}; with it a Plan without `result` and `context`, plus
     *         `stop_count` and `summary`
     */
    public function list(string $orgId, array $truck, array $A, array $query): array
    {
        $q = Input::query($query);
        $today = $this->today($truck);
        $from = $q->date('from') ?? Estimator::addDays($today, -(int) TpConfig::get('requests.plans_default_days_back'));
        $to = $q->date('to') ?? Estimator::addDays($today, (int) TpConfig::get('requests.plans_default_days_ahead'));
        self::checkRange($q, $from, $to, (int) TpConfig::get('requests.plans_range_max_days'));
        $withStops = $q->bool('stops') ?? false;

        $this->plans->purgeExpiredSnapshots($orgId);
        $rows = $this->plans->listRange($orgId, (string) $truck['id'], $from, $to, $withStops);
        if ($rows === []) {
            return [];
        }

        $logsChangedAt = $this->logs->lastChangeAt($orgId, (string) $truck['id']);
        $dataset = $this->dataset($truck);
        $points = [];
        if ($withStops) {
            $stops = [];
            foreach ($rows as $row) {
                foreach ($row['stops'] as $stop) {
                    $stops[] = $stop;
                }
            }
            $points = $this->spotPoints($stops, $orgId);
        }

        $out = [];
        foreach ($rows as $row) {
            $state = self::stateOf($row, $truck, $A, $dataset, $logsChangedAt);
            $summary = in_array($state, self::SHOWN_STATES, true) ? $row['summary'] : null;
            if (!$withStops) {
                $out[] = [
                    'id' => $row['id'],
                    'date' => $row['service_date'],
                    'name' => $row['name'],
                    'treat_as' => $row['treat_as'],
                    'status' => $row['plan_state'],
                    'stop_count' => $row['stop_count'],
                    'result_state' => $state,
                    'summary' => $summary,
                    'updated_at' => $row['updated_at'],
                ];
                continue;
            }
            $out[] = [
                'id' => $row['id'],
                'date' => $row['service_date'],
                'name' => $row['name'],
                'treat_as' => $row['treat_as'],
                'notes' => $row['notes'],
                'status' => $row['plan_state'],
                'stops' => array_map([self::class, 'presentStop'], $row['stops']),
                'stop_count' => $row['stop_count'],
                'result_state' => $state,
                'evaluated_at' => $row['evaluated_at'],
                'summary' => $summary,
                'maps_route_url' => self::routeUrl($truck, $row['stops'], $points),
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }
        return $out;
    }

    /**
     * One plan (route 25). `result` and `context` are there while the snapshot is fresh or stale.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @return array<string, mixed> Plan
     * @throws TpNotFound for an id the organization does not have
     */
    public function get(string $orgId, array $truck, array $A, string $planId): array
    {
        return $this->present($this->mustFind($orgId, $planId), $truck, $A);
    }

    // ------------------------------------------------------------------------------------ writing

    /**
     * Validates a plan body, evaluates the plan and stores it with its snapshot (route 23).
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param array<int|string, mixed> $body
     * @return array<string, mixed> Plan
     * @throws TpConflict when the date already has a plan, and while the region data does not fit this server
     */
    public function create(string $orgId, array $truck, array $A, ?string $userId, array $body): array
    {
        $in = new Input($body);
        $columns = $this->fieldsInput($in, $A, true) + ['name' => '', 'plan_state' => self::STATES[0]];
        $stops = $this->stopsInput($in, $orgId, null);
        $truckId = (string) $truck['id'];
        $date = (string) $columns['service_date'];
        if ($this->plans->findByDate($orgId, $truckId, $date) !== null) {
            throw new TpConflict(self::DATE_TAKEN);
        }

        $evaluation = $this->evaluate($orgId, $truck, $A, ['date' => $date, 'treat_as' => $columns['treat_as'] ?? null, 'stops' => $stops]);
        $id = '';
        try {
            $this->plans->transaction(function () use (&$id, $orgId, $truckId, $userId, $columns, $stops, $evaluation): void {
                $id = $this->plans->create($orgId, $truckId, $userId, $columns, $stops);
                $this->store($id, $orgId, $evaluation);
            });
        } catch (\PDOException $e) {
            // Two saves for one date at the same moment: the one-plan-per-date key let the other one in.
            if ($this->plans->findByDate($orgId, $truckId, $date) === null) {
                throw $e;
            }
            throw new TpConflict(self::DATE_TAKEN);
        }
        return $this->get($orgId, $truck, $A, $id);
    }

    /**
     * Evaluates a plan body and stores nothing (route 24). `name`, `notes` and `status` are not read.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param array<int|string, mixed> $body
     * @return array{result: array<string, mixed>, context: array<string, mixed>}
     */
    public function preview(string $orgId, array $truck, array $A, array $body): array
    {
        $in = new Input($body);
        $date = (string) $in->date('date', true);
        self::needNextDate($in, $date);
        $treatAs = $in->enum('treat_as', self::treatAsValues($A));
        $stops = $this->stopsInput($in, $orgId, null, true);
        return $this->evaluate($orgId, $truck, $A, ['date' => $date, 'treat_as' => $treatAs, 'stops' => $stops]);
    }

    /**
     * Changes the keys the body carries, evaluates the plan again and stores the new snapshot (route 26).
     * `stops` replaces all stops; a stop that comes with the id it had keeps that id.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param array<int|string, mixed> $body
     * @return array<string, mixed> Plan
     * @throws TpConflict when the date moves onto a date that has a plan
     */
    public function update(string $orgId, array $truck, array $A, string $planId, array $body): array
    {
        $row = $this->mustFind($orgId, $planId);
        $in = new Input($body);
        $in->requireAny(self::BODY_KEYS);
        $columns = $this->fieldsInput($in, $A, false);
        $newStops = $in->has('stops') ? $this->stopsInput($in, $orgId, array_column($row['stops'], 'id')) : null;

        $truckId = (string) $row['truck_id'];
        $date = (string) ($columns['service_date'] ?? $row['service_date']);
        if ($date !== $row['service_date']) {
            $other = $this->plans->findByDate($orgId, $truckId, $date);
            if ($other !== null && $other['id'] !== $row['id']) {
                throw new TpConflict(self::DATE_TAKEN);
            }
        }
        $treatAs = array_key_exists('treat_as', $columns) ? $columns['treat_as'] : $row['treat_as'];

        $evaluation = $this->evaluate($orgId, $truck, $A, ['date' => $date, 'treat_as' => $treatAs, 'stops' => $newStops ?? $row['stops']]);
        try {
            $this->plans->transaction(function () use ($planId, $orgId, $columns, $newStops, $evaluation): void {
                $this->plans->update($planId, $orgId, $columns);
                if ($newStops !== null) {
                    $this->plans->replaceStops($planId, $orgId, $newStops);
                }
                $this->store($planId, $orgId, $evaluation);
            });
        } catch (\PDOException $e) {
            $other = $this->plans->findByDate($orgId, $truckId, $date);
            if ($other === null || $other['id'] === $row['id']) {
                throw $e;
            }
            throw new TpConflict(self::DATE_TAKEN);
        }
        return $this->get($orgId, $truck, $A, $planId);
    }

    /**
     * Deletes a plan and its stops (route 27). Logged services keep their numbers; their link to the plan
     * is cleared.
     *
     * @return array{id: string, deleted: true}
     */
    public function delete(string $orgId, string $planId): array
    {
        $row = $this->mustFind($orgId, $planId);
        $this->plans->delete((string) $row['id'], $orgId);
        return ['id' => (string) $row['id'], 'deleted' => true];
    }

    /**
     * Evaluates a stored plan again and stores the new snapshot (route 28).
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @return array<string, mixed> Plan
     */
    public function evaluateStored(string $orgId, array $truck, array $A, string $planId): array
    {
        $row = $this->mustFind($orgId, $planId);
        $evaluation = $this->evaluate($orgId, $truck, $A, ['date' => $row['service_date'], 'treat_as' => $row['treat_as'], 'stops' => $row['stops']]);
        $this->store($planId, $orgId, $evaluation);
        return $this->get($orgId, $truck, $A, $planId);
    }

    // ------------------------------------------------------------------------------------ evaluation

    /**
     * Builds the model's inputs for a plan and evaluates it. Nothing is stored, except that a spot whose
     * vectors are not fresh is computed again first.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param array{date: string, treat_as?: ?string, stops: list<array<string, mixed>>} $plan the service
     *        date, its "treat this day as" value and the stop rows in visiting order (each with its `id`)
     * @return array{result: array<string, mixed>, context: array<string, mixed>} DayResult and EvalContext
     * @throws TpConflict while the region data does not fit this server and a spot needs new vectors
     */
    public function evaluate(string $orgId, array $truck, array $A, array $plan): array
    {
        $profile = $truck['profile'];
        $date = (string) $plan['date'];
        $treatAs = isset($plan['treat_as']) ? (string) $plan['treat_as'] : null;

        $cal = Registry::calibration()->state($orgId, $truck, $A, $this->today($truck));
        $stops = $this->stopInputs($orgId, $truck, array_values($plan['stops']));

        // The override belongs to one civil date: the next date is always built without it.
        $contexts = Registry::dayContexts()->contexts($truck, $A, $date, 2, [$date => $treatAs]);
        $ctx = $contexts['days'][0]['context'];
        $next = $contexts['days'][1]['context'];

        // Every leg the model can look up: the day as ordered, and the legs that appear when one stop is
        // left out. A leg that is not handed over would be filled with a straight line by the model.
        $driveLegs = [];
        $legs = [];
        $keys = Estimator::requiredLegKeys($stops);
        if ($keys !== []) {
            $points = [[
                'id' => self::BASE,
                'lat' => (float) $profile['base']['lat'],
                'lng' => (float) $profile['base']['lng'],
            ]];
            foreach ($stops as $stop) {
                $points[] = ['id' => $stop['id'], 'lat' => (float) $stop['point']['lat'], 'lng' => (float) $stop['point']['lng']];
            }
            $pairs = [];
            foreach ($keys as $key) {
                $cut = (int) strpos($key, '>');
                $pairs[] = [substr($key, 0, $cut), substr($key, $cut + 1)];
            }
            $driveLegs = array_values(Registry::legs()->legs($orgId, $truck, $points, $pairs, ['tolls' => true]));
            foreach ($driveLegs as $leg) {
                $legs[$leg['from_id'] . '>' . $leg['to_id']] = $leg['leg_input'];
            }
        }

        $result = Estimator::dayPlan($A, $profile, ['date' => $date, 'stops' => $stops], $ctx, $next, $legs, $cal);

        $usesGoogle = false;
        foreach ($driveLegs as $leg) {
            $usesGoogle = $usesGoogle || str_starts_with((string) $leg['source'], 'google_');
        }
        return [
            'result' => $result,
            'context' => [
                'ctx' => $ctx,
                'ctx_next' => $next,
                'legs' => $driveLegs,
                'calibration' => [
                    'as_of' => (string) $cal['as_of'],
                    'truck_factor' => (float) $cal['truck_factor'],
                    'truck_n' => (int) $cal['truck_n'],
                ],
                'fuel' => $contexts['fuel'],
                'model_version' => (string) $A['model_version'],
                'seeds_revision' => (int) $A['seeds_revision'],
                'dataset_version' => $this->dataset($truck),
                'uses_google_legs' => $usesGoogle,
            ],
        ];
    }

    /**
     * The model's StopInput of each stop row, in order. A spot stop takes its point, terms and vectors
     * from the spot (an archived spot still serves), with fresh vectors; an event stop carries its own
     * fee terms; a catering stop its contract.
     *
     * @param array<string, mixed> $truck
     * @param list<array<string, mixed>> $stops stop rows, each with its `id`
     * @return list<array<string, mixed>> StopInput
     * @throws TpInvalid when a spot stop names a spot the organization does not have
     * @throws TpConflict while the region data does not fit this server and a spot needs new vectors
     */
    public function stopInputs(string $orgId, array $truck, array $stops): array
    {
        $spotIds = [];
        foreach ($stops as $stop) {
            if ($stop['stop_kind'] === 'spot' && isset($stop['spot_id'])) {
                $spotIds[] = (string) $stop['spot_id'];
            }
        }
        $rows = $spotIds === [] ? [] : $this->spots->findMany($spotIds, $orgId);
        $fresh = [];

        $inputs = [];
        foreach (array_values($stops) as $i => $stop) {
            $kind = (string) $stop['stop_kind'];
            $input = [
                'id' => (string) $stop['id'],
                'kind' => $kind,
                'spot_id' => null,
                'point' => null,
                'open_minute' => (int) $stop['open_minute'],
                'close_minute' => (int) $stop['close_minute'],
                'gap_before_unpaid' => (bool) ($stop['gap_before_unpaid'] ?? false),
                'setup_minutes' => isset($stop['setup_minutes']) ? (int) $stop['setup_minutes'] : null,
                'teardown_minutes' => isset($stop['teardown_minutes']) ? (int) $stop['teardown_minutes'] : null,
                'terms' => null,
                'vectors' => null,
                'event' => null,
                'catering' => null,
            ];
            if ($kind === 'spot') {
                $spotId = (string) ($stop['spot_id'] ?? '');
                if (!isset($rows[$spotId])) {
                    $path = 'stops[' . $i . '].spot_id';
                    throw new TpInvalid($path . ' was not found', $path, 'V11');
                }
                $spot = $fresh[$spotId] ??= $this->spotService->ensureFresh($orgId, $truck, $rows[$spotId]);
                $terms = $spot['terms'];
                $vectors = $spot['vectors'][$terms['visibility']] ?? null;
                if (!is_array($vectors)) {
                    throw new \UnexpectedValueException('a spot has no vectors after they were computed');
                }
                $input['spot_id'] = (string) $spot['id'];
                $input['point'] = $spot['point'];
                $input['terms'] = $terms;
                $input['vectors'] = $vectors;
            } else {
                $input['point'] = ['lat' => (float) $stop['lat'], 'lng' => (float) $stop['lng']];
                if ($kind === 'event') {
                    $input['terms'] = [
                        'spot_id' => null,
                        'visibility' => 'normal',
                        'host' => null,
                        'fee_flat' => (float) ($stop['fee_flat'] ?? 0.0),
                        'fee_pct' => (float) ($stop['fee_pct'] ?? 0.0),
                        'fee_min' => (float) ($stop['fee_min'] ?? 0.0),
                        'allowed' => null,
                    ];
                    $input['event'] = [
                        'attendance' => (float) $stop['ev_attendance'],
                        'vendors' => (int) $stop['ev_vendor_count'],
                        'event_type' => (string) $stop['ev_type'],
                    ];
                } else {
                    $input['catering'] = [
                        'headcount' => (float) $stop['cat_headcount'],
                        'price_per_head' => isset($stop['cat_price_head']) ? (float) $stop['cat_price_head'] : null,
                        'guarantee' => isset($stop['cat_guarantee']) ? (float) $stop['cat_guarantee'] : null,
                        'food_cost' => isset($stop['cat_food_cost']) ? (float) $stop['cat_food_cost'] : null,
                    ];
                }
            }
            $inputs[] = $input;
        }
        return $inputs;
    }

    /**
     * The state of a plan's snapshot: `none`, `expired`, `stale` or `fresh`.
     *
     * @param array<string, mixed> $planRow a plan row of PlanRepository
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     */
    public function resultState(array $planRow, array $truck, array $A): string
    {
        return self::stateOf(
            $planRow,
            $truck,
            $A,
            $this->dataset($truck),
            $this->logs->lastChangeAt((string) $planRow['organization_id'], (string) $planRow['truck_id'])
        );
    }

    // ------------------------------------------------------------------------------------ state and shapes

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param string|null $dataset the active dataset version of the truck's region
     * @param string|null $logsChangedAt when a logged service of the truck last changed
     */
    private static function stateOf(array $row, array $truck, array $A, ?string $dataset, ?string $logsChangedAt): string
    {
        $at = $row['evaluated_at'] ?? null;
        if (($row['has_snapshot'] ?? false) !== true || $at === null) {
            return 'none';
        }
        if (($row['snapshot_expired'] ?? false) === true) {
            return 'expired';
        }
        if ($row['model_version'] !== (string) $A['model_version']
            || $row['seeds_revision'] !== (int) $A['seeds_revision']
            || $row['dataset_version'] !== $dataset) {
            return 'stale';
        }
        // Row time stamps and `evaluated_at` are all written by the database clock, in one form.
        foreach ([$truck['updated_at'] ?? null, $row['spots_changed_at'] ?? null, $logsChangedAt] as $changedAt) {
            if ($changedAt !== null && strcmp((string) $changedAt, (string) $at) > 0) {
                return 'stale';
            }
        }
        return 'fresh';
    }

    /**
     * The API's Plan of a plan row.
     *
     * @param array<string, mixed> $row a plan row with `stops`
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @return array<string, mixed>
     */
    private function present(array $row, array $truck, array $A): array
    {
        $state = $this->resultState($row, $truck, $A);
        $shown = in_array($state, self::SHOWN_STATES, true);
        return [
            'id' => $row['id'],
            'date' => $row['service_date'],
            'name' => $row['name'],
            'treat_as' => $row['treat_as'],
            'notes' => $row['notes'],
            'status' => $row['plan_state'],
            'stops' => array_map([self::class, 'presentStop'], $row['stops']),
            'result' => $shown ? $row['result'] : null,
            'context' => $shown ? $row['context'] : null,
            'result_state' => $state,
            'evaluated_at' => $row['evaluated_at'],
            'maps_route_url' => self::routeUrl($truck, $row['stops'], $this->spotPoints($row['stops'], (string) $row['organization_id'])),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * The API's PlanStop of a stop row.
     *
     * @param array<string, mixed> $stop
     * @return array<string, mixed>
     */
    private static function presentStop(array $stop): array
    {
        $kind = (string) $stop['stop_kind'];
        $hasPoint = $kind !== 'spot' && $stop['lat'] !== null && $stop['lng'] !== null;
        return [
            'id' => $stop['id'],
            'kind' => $kind,
            'spot_id' => $stop['spot_id'],
            'label' => $stop['label'],
            'point' => $hasPoint ? ['lat' => (float) $stop['lat'], 'lng' => (float) $stop['lng']] : null,
            'address' => $stop['address'],
            'open_minute' => $stop['open_minute'],
            'close_minute' => $stop['close_minute'],
            'gap_before_unpaid' => $stop['gap_before_unpaid'],
            'setup_minutes' => $stop['setup_minutes'],
            'teardown_minutes' => $stop['teardown_minutes'],
            'fee_flat' => $stop['fee_flat'],
            'fee_pct' => $stop['fee_pct'],
            'fee_min' => $stop['fee_min'],
            'event' => $kind !== 'event' ? null : [
                'attendance' => $stop['ev_attendance'],
                'vendors' => $stop['ev_vendor_count'],
                'event_type' => $stop['ev_type'],
            ],
            'catering' => $kind !== 'catering' ? null : [
                'headcount' => $stop['cat_headcount'],
                'price_per_head' => $stop['cat_price_head'],
                'guarantee' => $stop['cat_guarantee'],
                'food_cost' => $stop['cat_food_cost'],
            ],
        ];
    }

    /**
     * The points of the spots that the stops refer to.
     *
     * @param list<array<string, mixed>> $stops stop rows
     * @return array<string, array{lat: float, lng: float}> by spot id
     */
    private function spotPoints(array $stops, string $orgId): array
    {
        $ids = [];
        foreach ($stops as $stop) {
            if ($stop['stop_kind'] === 'spot' && $stop['spot_id'] !== null) {
                $ids[(string) $stop['spot_id']] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $points = [];
        foreach ($this->spots->findMany(array_map('strval', array_keys($ids)), $orgId) as $id => $spot) {
            $points[(string) $id] = ['lat' => (float) $spot['lat'], 'lng' => (float) $spot['lng']];
        }
        return $points;
    }

    /**
     * The route of the day as a free Google Maps link: base, the stops in order, base. Null for a plan
     * without stops.
     *
     * @param array<string, mixed> $truck
     * @param list<array<string, mixed>> $stops stop rows
     * @param array<string, array{lat: float, lng: float}> $spotPoints
     */
    private static function routeUrl(array $truck, array $stops, array $spotPoints): ?string
    {
        $base = ['lat' => (float) $truck['profile']['base']['lat'], 'lng' => (float) $truck['profile']['base']['lng']];
        $route = [$base];
        foreach ($stops as $stop) {
            if ($stop['stop_kind'] === 'spot') {
                $point = $spotPoints[(string) $stop['spot_id']] ?? null;
            } else {
                $point = $stop['lat'] === null || $stop['lng'] === null ? null : ['lat' => (float) $stop['lat'], 'lng' => (float) $stop['lng']];
            }
            if ($point !== null) {
                $route[] = $point;
            }
        }
        if (count($route) === 1) {
            return null;
        }
        $route[] = $base;
        return MapsUrl::route($route);
    }

    /**
     * @param array{result: array<string, mixed>, context: array<string, mixed>} $evaluation
     */
    private function store(string $planId, string $orgId, array $evaluation): void
    {
        $context = $evaluation['context'];
        $this->plans->saveSnapshot(
            $planId,
            $orgId,
            $evaluation['result'],
            $context,
            (bool) $context['uses_google_legs'],
            [
                'model_version' => (string) $context['model_version'],
                'seeds_revision' => (int) $context['seeds_revision'],
                'dataset_version' => $context['dataset_version'],
            ]
        );
    }

    // ------------------------------------------------------------------------------------ request bodies

    /**
     * `date`, `name`, `notes`, `treat_as` and `status` of a body, as plan columns: only what was sent,
     * except that a new plan needs its date.
     *
     * @param array<string, mixed> $A
     * @return array<string, mixed>
     */
    private function fieldsInput(Input $in, array $A, bool $create): array
    {
        $columns = [];
        $date = $in->date('date', $create || $in->has('date'));
        if ($date !== null) {
            self::needNextDate($in, $date);
            $columns['service_date'] = $date;
        }
        if ($in->has('name')) {
            $columns['name'] = $in->str('name', 120) ?? '';
        }
        if ($in->has('notes')) {
            $notes = $in->str('notes', 4000);
            $columns['notes'] = $notes === '' ? null : $notes;
        }
        if ($in->has('treat_as')) {
            $columns['treat_as'] = $in->enum('treat_as', self::treatAsValues($A));
        }
        $status = $in->enum('status', self::STATES, $in->has('status'));
        if ($status !== null) {
            $columns['plan_state'] = $status;
        }
        return $columns;
    }

    /**
     * `stops` of a body, as stop rows in the order sent, each with the id it will have.
     *
     * A stored plan: a stop keeps the `id` it was sent with when that is a stop of this plan, otherwise
     * it gets a new one. A preview stores nothing, so a well-formed `id` is kept as sent and a stop
     * without one is called by its position.
     *
     * @param list<string>|null $known the ids of the stops the plan has now; null for a new plan
     * @return list<array<string, mixed>>
     */
    private function stopsInput(Input $in, string $orgId, ?array $known, bool $preview = false): array
    {
        $max = (int) TpConfig::get('limits.max_stops_per_plan');
        $items = $in->items('stops', 0, $max, $in->has('stops'));
        if ($items === null) {
            return [];
        }

        // One read for every spot the stops name; the checks below then go stop by stop.
        $asked = [];
        foreach ($items as $item) {
            $spotId = is_array($item) ? ($item['spot_id'] ?? null) : null;
            if (is_string($spotId) && $spotId !== '' && strlen($spotId) <= self::ID_LENGTH) {
                $asked[] = $spotId;
            }
        }
        // Keyed in lower case: the id column compares without regard to case, and so does this lookup.
        $spots = [];
        foreach ($asked === [] ? [] : $this->spots->findMany($asked, $orgId) as $spot) {
            $spots[strtolower((string) $spot['id'])] = $spot;
        }

        $each = $in->each('stops');
        $used = [];
        $rows = [];
        foreach (array_keys($items) as $i) {
            $stop = $each->obj($i, true) ?? new Input([], $each->path($i) . '.');
            $sentId = $stop->str('id', self::ID_LENGTH);
            $kind = (string) $stop->enum('kind', self::KINDS, true);
            $row = ['stop_kind' => $kind, 'spot_id' => null, 'label' => '', 'lat' => null, 'lng' => null, 'address' => ''];

            if ($kind === 'spot') {
                $spotId = strtolower((string) $stop->str('spot_id', self::ID_LENGTH, true));
                if (!isset($spots[$spotId])) {
                    throw $stop->notFound('spot_id');
                }
                // The id as the table spells it: that is what the calibration state is keyed by.
                $row['spot_id'] = (string) $spots[$spotId]['id'];
            } else {
                $point = $stop->point('point', true);
                $row['lat'] = $point['lat'];
                $row['lng'] = $point['lng'];
                $row['label'] = $stop->str('label', 120) ?? '';
                $row['address'] = $stop->str('address', 255) ?? '';
            }

            $row['open_minute'] = (int) $stop->int('open_minute', 0, self::MAX_MINUTE, true);
            $row['close_minute'] = (int) $stop->int('close_minute', 0, self::MAX_MINUTE, true);
            if ($row['close_minute'] <= $row['open_minute']) {
                throw $stop->error('close_minute', 'must be after open_minute');
            }
            $row['gap_before_unpaid'] = $stop->bool('gap_before_unpaid') ?? false;
            $row['setup_minutes'] = $stop->int('setup_minutes', 0, self::MAX_STAGE_MINUTES);
            $row['teardown_minutes'] = $stop->int('teardown_minutes', 0, self::MAX_STAGE_MINUTES);

            $row['fee_flat'] = 0.0;
            $row['fee_pct'] = 0.0;
            $row['fee_min'] = 0.0;
            if ($kind === 'event') {
                $row['fee_flat'] = self::cents($stop->num('fee_flat', 0.0, self::MAX_FEE) ?? 0.0);
                $row['fee_min'] = self::cents($stop->num('fee_min', 0.0, self::MAX_FEE) ?? 0.0);
                $row['fee_pct'] = $stop->num('fee_pct', 0.0, 1.0) ?? 0.0;
                $event = $stop->obj('event', true);
                if ($event === null) {
                    throw $stop->error('event', 'is required', 'V1');
                }
                $row['ev_attendance'] = $event->num('attendance', 1.0, self::MAX_ATTENDANCE, true);
                $row['ev_vendor_count'] = $event->int('vendors', 1, self::MAX_VENDORS, true);
                $row['ev_type'] = $event->enum('event_type', self::EVENT_TYPES, true);
            } elseif ($kind === 'catering') {
                $catering = $stop->obj('catering', true);
                if ($catering === null) {
                    throw $stop->error('catering', 'is required', 'V1');
                }
                $row['cat_headcount'] = $catering->num('headcount', 1.0, self::MAX_HEADCOUNT, true);
                $row['cat_price_head'] = self::cents($catering->num('price_per_head', 0.0, self::MAX_PRICE_PER_HEAD));
                $row['cat_guarantee'] = self::cents($catering->num('guarantee', 0.0, self::MAX_CONTRACT));
                $row['cat_food_cost'] = self::cents($catering->num('food_cost', 0.0, self::MAX_CONTRACT));
                if ($row['cat_price_head'] === null && $row['cat_guarantee'] === null) {
                    throw $stop->error('catering', 'needs price_per_head or guarantee');
                }
            }

            $row['id'] = self::stopId($sentId, $known, $used, $preview, count($rows) + 1);
            $used[$row['id']] = true;
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The id of a stop that was sent with `$sentId` (or without one).
     *
     * @param list<string>|null $known the ids of the plan's present stops
     * @param array<string, true> $used the ids already given to earlier stops of this body
     */
    private static function stopId(?string $sentId, ?array $known, array $used, bool $preview, int $position): string
    {
        if ($preview) {
            $kept = $sentId !== null && $sentId !== self::BASE && !isset($used[$sentId])
                && preg_match(self::PREVIEW_ID_FORM, $sentId) === 1;
            return $kept ? (string) $sentId : 'stop:' . $position;
        }
        if ($sentId !== null && $known !== null && !isset($used[$sentId]) && in_array($sentId, $known, true)) {
            return $sentId;
        }
        return PlanRepository::newId();
    }

    /**
     * An amount as it is stored: in whole cents. A plan is evaluated with the amounts it is saved with.
     */
    private static function cents(?float $dollars): ?float
    {
        return $dollars === null ? null : Money::fromCents(Money::toCents($dollars));
    }

    /**
     * A plan is evaluated with the context of its date and of the next date, so the model's last date
     * cannot carry a plan.
     */
    private static function needNextDate(Input $in, string $date): void
    {
        try {
            Estimator::parseDate(Estimator::addDays($date, 1));
        } catch (\InvalidArgumentException $e) {
            throw $in->error('date', 'must be a date in the form YYYY-MM-DD', 'V7');
        }
    }

    /**
     * `to` not before `from`, and at most `$maxDays` dates, both ends counted.
     */
    private static function checkRange(Input $q, string $from, string $to, int $maxDays): void
    {
        $days = Estimator::daysFromCivil(...Estimator::parseDate($to)) - Estimator::daysFromCivil(...Estimator::parseDate($from)) + 1;
        if ($days < 1) {
            throw $q->error('to', 'must not be before from');
        }
        if ($days > $maxDays) {
            throw new TpInvalid('The date range must be at most ' . $maxDays . ' days');
        }
    }

    /**
     * @param array<string, mixed> $A
     * @return list<string> normal, holiday and the seven days of the week
     */
    private static function treatAsValues(array $A): array
    {
        return array_merge(self::TREAT_AS_FIXED, array_values(Estimator::seed($A, 'vocabulary.dow')));
    }

    // ------------------------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed> a plan row with its stops
     */
    private function mustFind(string $orgId, string $planId): array
    {
        $row = $planId === '' ? null : $this->plans->find($planId, $orgId);
        if ($row === null) {
            throw new TpNotFound(self::NOT_FOUND);
        }
        return $row;
    }

    /**
     * The active dataset version of the truck's region, null when it has none.
     *
     * @param array<string, mixed> $truck
     */
    private function dataset(array $truck): ?string
    {
        $regionId = $truck['profile']['region_id'] ?? null;
        if (!is_string($regionId) || $regionId === '' || $regionId === RegionService::NONE) {
            return null;
        }
        $active = $this->regions->active($regionId);
        return $active === null ? null : (string) $active['dataset_version'];
    }

    /**
     * Today in the truck's time zone.
     *
     * @param array<string, mixed> $truck
     */
    private function today(array $truck): string
    {
        return $this->clock->today(CalibrationService::zoneOf($truck));
    }
}
