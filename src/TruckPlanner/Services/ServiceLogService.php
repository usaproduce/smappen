<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\PlanRepository;
use App\TruckPlanner\Data\ServiceLogRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpInvalid;
use App\TruckPlanner\Services\Support\TpNotFound;

/**
 * Logged services (04_BACKEND.md 4.13, 5.7): how many orders the truck really served, kept with the
 * prediction the service is judged against.
 *
 * The prediction is fixed when the service is logged:
 *
 *   - `predicted`, `low`, `high` and the confidence label are what the owner was shown. Basis `plan`: the
 *     figures of the planned stop the log is linked to, when the logged spot, date, day type and window
 *     are the planned ones. Basis `log` otherwise: the model's window for the spot, calibrated only with
 *     the services dated before this one, so a service never vouches for its own estimate.
 *   - `predicted_raw` is the same window with no calibration. Calibration judges the model against it and
 *     computes it again when the model's inputs change (CalibrationService).
 *
 * A full prediction is kept for spot logs. An event or catering log that is linked to a planned stop
 * keeps the plan's figures for display only; such logs never enter calibration or the accuracy report.
 *
 * The owner's own results outrank the model: every write answers with the calibration state it leads to.
 *
 * `$truck` is the truck value of TruckBaseController::truck(), `$A` the truck's Assumptions.
 */
class ServiceLogService
{
    private const NOT_FOUND = 'Service not found';
    private const DUPLICATE = 'A service is already logged for this spot and time';
    private const BODY_KEYS = [
        'kind', 'spot_id', 'date', 'open_minute', 'close_minute', 'actual', 'sales', 'sold_out', 'notes',
        'plan_stop_id', 'treat_as',
    ];
    private const KINDS = ['spot', 'event', 'catering'];
    private const TREAT_AS_FIXED = ['normal', 'holiday'];
    private const MAX_MINUTE = 2880;
    private const MINUTES_PER_DAY = 1440;
    private const MAX_ORDERS = 5000;
    private const MAX_SALES = 1000000.0;
    private const ID_LENGTH = 36;

    /** The columns of a log that hold its prediction. A log without one has them all null. */
    private const NO_PREDICTION = [
        'weather' => null,
        'predicted_raw' => null,
        'pred_raw_basis' => null,
        'predicted' => null,
        'pred_low' => null,
        'pred_high' => null,
        'pred_confidence' => null,
        'pred_basis' => null,
        'prediction' => null,
        'pred_model_version' => null,
        'pred_seeds_rev' => null,
        'pred_dataset' => null,
    ];

    /** What a prediction is computed from: a change of one of these rebuilds it. */
    private const PREDICTION_INPUTS = [
        'log_kind', 'spot_id', 'service_date', 'open_minute', 'close_minute', 'treat_as', 'plan_stop_id',
    ];

    private ServiceLogRepository $logs;
    private SpotRepository $spots;
    private PlanRepository $plans;
    private CalibrationService $calibration;
    private Clock $clock;
    private TruckRepository $trucks;

    public function __construct(
        ?ServiceLogRepository $logs = null,
        ?SpotRepository $spots = null,
        ?PlanRepository $plans = null,
        ?CalibrationService $calibration = null,
        ?Clock $clock = null,
        ?TruckRepository $trucks = null
    ) {
        $this->logs = $logs ?? new ServiceLogRepository();
        $this->spots = $spots ?? new SpotRepository();
        $this->plans = $plans ?? new PlanRepository();
        $this->clock = $clock ?? new Clock();
        $this->calibration = $calibration ?? new CalibrationService($this->logs, $this->spots, null, $this->clock);
        $this->trucks = $trucks ?? new TruckRepository();
    }

    // ------------------------------------------------------------------------------------ reading

    /**
     * The logged services of a date range, newest first (route 31).
     *
     * @param array<string, mixed> $truck
     * @param array<int|string, mixed> $query `from`, `to` and `spot_id`
     * @return list<array<string, mixed>> ServiceLog
     */
    public function list(string $orgId, array $truck, array $query): array
    {
        $q = Input::query($query);
        $today = $this->today($truck);
        $from = $q->date('from') ?? Estimator::addDays($today, -(int) TpConfig::get('requests.services_default_days_back'));
        $to = $q->date('to') ?? $today;
        $days = Estimator::daysFromCivil(...Estimator::parseDate($to)) - Estimator::daysFromCivil(...Estimator::parseDate($from)) + 1;
        if ($days < 1) {
            throw $q->error('to', 'must not be before from');
        }
        $max = (int) TpConfig::get('requests.services_range_max_days');
        if ($days > $max) {
            throw new TpInvalid('The date range must be at most ' . $max . ' days');
        }
        $spotId = $q->str('spot_id', self::ID_LENGTH);
        if ($spotId === '') {
            $spotId = null;
        }
        $out = [];
        foreach ($this->logs->listRange($orgId, (string) $truck['id'], $from, $to, $spotId) as $row) {
            $out[] = self::present($row);
        }
        return $out;
    }

    /**
     * One logged service (route 33).
     *
     * @return array<string, mixed> ServiceLog
     * @throws TpNotFound for an id the organization does not have
     */
    public function get(string $orgId, string $id): array
    {
        return self::present($this->mustFind($orgId, $id));
    }

    // ------------------------------------------------------------------------------------ writing

    /**
     * Validates a body, fixes the prediction the service will be judged against and stores the log
     * (route 32).
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param array<int|string, mixed> $body
     * @return array{service: array<string, mixed>, calibration: array<string, mixed>} the ServiceLog and
     *         the CalibrationState after it
     * @throws TpConflict when a service is already logged for the spot, date and window
     */
    public function create(string $orgId, array $truck, array $A, ?string $userId, array $body): array
    {
        $today = $this->today($truck);
        $in = new Input($body);
        $fields = $this->fieldsInput($in, $A, $orgId, $today, null);
        $link = $fields['link'];
        unset($fields['link']);

        $columns = $fields + ['log_kind' => self::KINDS[0], 'sold_out' => false];
        if (!array_key_exists('treat_as', $columns)) {
            // Without a value of its own the log takes the day type of the plan it is linked to.
            $columns['treat_as'] = $link === null ? null : $link['plan']['treat_as'];
        }
        $columns = self::settle($in, $columns);
        $this->refuseDuplicate($orgId, $columns, null);

        $columns = array_merge($columns, $this->prediction($orgId, $truck, $A, $columns, $link, null));
        $id = $this->logs->create($orgId, (string) $truck['id'], $userId, $columns);
        return [
            'service' => $this->get($orgId, $id),
            'calibration' => Registry::calibration()->state($orgId, $truck, $A, $today),
        ];
    }

    /**
     * Changes the keys the body carries (route 34). The prediction is built again when the kind, the
     * spot, the date, the window, the day type or the link to a planned stop changed; a change of the
     * count, the sales, the sold-out flag or the notes leaves it as it was.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param array<int|string, mixed> $body
     * @return array{service: array<string, mixed>, calibration: array<string, mixed>}
     */
    public function update(string $orgId, array $truck, array $A, string $id, array $body): array
    {
        $row = $this->mustFind($orgId, $id);
        $today = $this->today($truck);
        $in = new Input($body);
        $in->requireAny(self::BODY_KEYS);
        $fields = $this->fieldsInput($in, $A, $orgId, $today, $row);
        $link = $fields['link'];
        unset($fields['link']);

        $merged = self::settle($in, array_merge(array_intersect_key($row, ServiceLogRepository::COLUMNS), $fields));
        $changed = [];
        foreach (array_keys(ServiceLogRepository::COLUMNS) as $key) {
            if (array_key_exists($key, $merged) && array_key_exists($key, $row) && $merged[$key] !== $row[$key]) {
                $changed[$key] = $merged[$key];
            }
        }
        if (array_intersect_key($changed, array_flip(['spot_id', 'service_date', 'open_minute', 'close_minute', 'log_kind'])) !== []) {
            $this->refuseDuplicate($orgId, $merged, (string) $row['id']);
        }
        if (array_intersect_key($changed, array_flip(self::PREDICTION_INPUTS)) !== []) {
            if ($link === null && $merged['plan_stop_id'] !== null) {
                $link = $this->linkOf((string) $merged['plan_stop_id'], $orgId);
            }
            $changed = array_merge($changed, $this->prediction($orgId, $truck, $A, $merged, $link, (string) $row['id']));
        }
        $this->logs->update((string) $row['id'], $orgId, $changed);
        return [
            'service' => $this->get($orgId, (string) $row['id']),
            'calibration' => Registry::calibration()->state($orgId, $truck, $A, $today),
        ];
    }

    /**
     * Deletes a logged service (route 35). Estimates stop using it at once. A deleted row leaves no time
     * stamp behind, so the truck is stamped instead: plan results computed with the service are stale.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @return array{id: string, deleted: true, calibration: array<string, mixed>}
     */
    public function delete(string $orgId, array $truck, array $A, string $id): array
    {
        $row = $this->mustFind($orgId, $id);
        $this->logs->delete((string) $row['id'], $orgId);
        $this->trucks->touch((string) $truck['id'], $orgId);
        return [
            'id' => (string) $row['id'],
            'deleted' => true,
            'calibration' => Registry::calibration()->state($orgId, $truck, $A, $this->today($truck)),
        ];
    }

    // ------------------------------------------------------------------------------------ the prediction

    /**
     * The prediction columns of a log: what the owner was shown, the raw prediction and its basis, the
     * weather and the detail kept with them. Every column is null when no prediction can be made.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param array<string, mixed> $log the log's `log_kind`, `spot_id`, `service_date`, `open_minute`,
     *                                  `close_minute` and `treat_as`
     * @param array{plan: array<string, mixed>, stop: array<string, mixed>}|null $link the planned stop the
     *        log is linked to, with its plan
     * @param string|null $exceptId the log itself when it is being changed: it never calibrates its own
     *                              prediction
     * @return array<string, mixed> the twelve prediction columns of ServiceLogRepository
     */
    public function prediction(string $orgId, array $truck, array $A, array $log, ?array $link, ?string $exceptId): array
    {
        $kind = (string) $log['log_kind'];
        $planned = $link === null ? null : self::plannedStop($link, $kind);
        if ($kind !== 'spot') {
            return $planned === null ? self::NO_PREDICTION : self::shownOnly($A, $link, $planned);
        }

        $spot = $log['spot_id'] === null ? null : $this->spots->find((string) $log['spot_id'], $orgId, true);
        if ($spot === null) {
            return self::NO_PREDICTION;
        }
        $date = (string) $log['service_date'];
        $open = (int) $log['open_minute'];
        $close = (int) $log['close_minute'];
        $treatAs = isset($log['treat_as']) ? (string) $log['treat_as'] : null;

        $fromPlan = $planned !== null
            && $link['plan']['service_date'] === $date
            && $link['plan']['treat_as'] === $treatAs
            && $link['stop']['spot_id'] === $spot['id']
            && $planned['effective_open'] === $open
            && $planned['close'] === $close
            && is_array($planned['stop']['window'] ?? null);

        $next = null;
        $shown = [];
        $window = [];
        if ($fromPlan) {
            // What the owner saw when the day was saved: the plan's figures and the forecast it read.
            $context = $link['plan']['context'];
            $ctx = $context['ctx'];
            $weather = self::weather($ctx, $close > self::MINUTES_PER_DAY ? ($context['ctx_next'] ?? null) : null);
            $shown = $planned['stop']['orders'];
            $window = $planned['stop']['window'];
        } else {
            $days = $close > self::MINUTES_PER_DAY ? 2 : 1;
            $contexts = Registry::dayContexts()->contexts($truck, $A, $date, $days, [$date => $treatAs]);
            $ctx = $contexts['days'][0]['context'];
            $next = $days === 2 ? $contexts['days'][1]['context'] : null;
            $weather = self::weather($ctx, $next);
        }

        $raw = $this->calibration->rawPrediction($truck, $A, $spot, $date, $open, $close, $treatAs, $weather);
        if ($raw === null) {
            return self::NO_PREDICTION;
        }
        if (!$fromPlan) {
            $before = [];
            foreach ($this->calibration->entries($orgId, $truck, $A) as $entry) {
                if (strcmp($entry['date'], $date) < 0 && $entry['service_id'] !== $exceptId) {
                    $before[] = $entry;
                }
            }
            $window = Estimator::windowOrders(
                $A,
                $truck['profile'],
                $raw['terms'],
                $raw['vectors'],
                Estimator::calibrate($A, $before, $date),
                $ctx,
                $next,
                $open,
                $close
            );
            $shown = $window['orders'];
        }

        return [
            'weather' => $weather,
            'predicted_raw' => $raw['predicted_raw'],
            'pred_raw_basis' => $raw['basis'],
            'predicted' => (float) $shown['value'],
            'pred_low' => (float) $shown['low'],
            'pred_high' => (float) $shown['high'],
            'pred_confidence' => (string) $shown['confidence'],
            'pred_basis' => $fromPlan ? 'plan' : 'log',
            'prediction' => [
                'basis' => $fromPlan ? 'plan' : 'log',
                'plan_id' => $fromPlan ? (string) $link['plan']['id'] : null,
                'ctx_date_types' => $ctx['day_type'],
                'holiday' => $ctx['holiday'] ?? null,
                'terms' => $raw['terms'],
                'spread' => $window['spread'],
                'evidence' => $window['evidence'],
            ],
            'pred_model_version' => (string) $A['model_version'],
            'pred_seeds_rev' => (int) $A['seeds_revision'],
            'pred_dataset' => $raw['dataset_version'],
        ];
    }

    /**
     * The planned stop of a link as the plan's snapshot evaluated it, or null: when the snapshot is
     * missing or expired, when the plan was not evaluated, or when the stop is of another kind than the
     * log. A stale snapshot counts: it is what the owner was shown when the day was saved.
     *
     * @param array{plan: array<string, mixed>, stop: array<string, mixed>} $link
     * @return array{stop: array<string, mixed>, effective_open: int, close: int}|null `stop` is the
     *         DayStop of the result, the two minutes are its served window
     */
    private static function plannedStop(array $link, string $kind): ?array
    {
        $plan = $link['plan'];
        if (($plan['has_snapshot'] ?? false) !== true || ($plan['snapshot_expired'] ?? false) === true
            || !is_array($plan['result'] ?? null) || !is_array($plan['context'] ?? null)
            || $link['stop']['stop_kind'] !== $kind) {
            return null;
        }
        foreach ((array) ($plan['result']['stops'] ?? []) as $dayStop) {
            if (!is_array($dayStop) || ($dayStop['id'] ?? null) !== $link['stop']['id'] || !is_array($dayStop['orders'] ?? null)) {
                continue;
            }
            $times = $plan['result']['timeline']['stops'][(int) ($dayStop['stop_index'] ?? -1)] ?? null;
            if (!is_array($times) || !isset($times['effective_open'], $times['close'])) {
                return null;
            }
            return ['stop' => $dayStop, 'effective_open' => (int) $times['effective_open'], 'close' => (int) $times['close']];
        }
        return null;
    }

    /**
     * The prediction columns of an event or catering log that is linked to a planned stop: the plan's
     * figures, for display. There is no raw prediction, so the log is never judged.
     *
     * @param array<string, mixed> $A
     * @param array{plan: array<string, mixed>, stop: array<string, mixed>} $link
     * @param array{stop: array<string, mixed>, effective_open: int, close: int} $planned
     * @return array<string, mixed>
     */
    private static function shownOnly(array $A, array $link, array $planned): array
    {
        $shown = $planned['stop']['orders'];
        $ctx = $link['plan']['context']['ctx'] ?? [];
        return array_merge(self::NO_PREDICTION, [
            'predicted' => (float) $shown['value'],
            'pred_low' => (float) $shown['low'],
            'pred_high' => (float) $shown['high'],
            'pred_confidence' => (string) $shown['confidence'],
            'pred_basis' => 'plan',
            'prediction' => [
                'basis' => 'plan',
                'plan_id' => (string) $link['plan']['id'],
                'ctx_date_types' => $ctx['day_type'] ?? null,
                'holiday' => $ctx['holiday'] ?? null,
                'terms' => null,
                'spread' => $planned['stop']['event']['spread'] ?? null,
                'evidence' => null,
            ],
            'pred_model_version' => (string) $A['model_version'],
            'pred_seeds_rev' => (int) $A['seeds_revision'],
        ]);
    }

    /**
     * The hourly forecasts of the service date and of the next date, as the log stores them. Null when
     * neither date has one.
     *
     * @param array<string, mixed> $ctx DayContext
     * @param array<string, mixed>|null $next DayContext
     * @return array{ctx: ?list<mixed>, ctx_next: ?list<mixed>}|null
     */
    private static function weather(array $ctx, ?array $next): ?array
    {
        $first = is_array($ctx['forecast'] ?? null) ? array_values($ctx['forecast']) : null;
        $second = is_array($next['forecast'] ?? null) ? array_values($next['forecast']) : null;
        return $first === null && $second === null ? null : ['ctx' => $first, 'ctx_next' => $second];
    }

    // ------------------------------------------------------------------------------------ request bodies

    /**
     * The fields of a body as log columns: only what was sent, except that a new log needs its date,
     * window and count. `link` is added: the planned stop a sent `plan_stop_id` names, with its plan.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed>|null $row the stored log when it is being changed
     * @return array<string, mixed>
     */
    private function fieldsInput(Input $in, array $A, string $orgId, string $today, ?array $row): array
    {
        $create = $row === null;
        $columns = [];

        $kind = $in->enum('kind', self::KINDS, !$create && $in->has('kind'));
        if ($kind !== null) {
            $columns['log_kind'] = $kind;
        }
        $kind ??= $create ? self::KINDS[0] : (string) $row['log_kind'];

        if ($kind !== 'spot') {
            $columns['spot_id'] = null;
        } elseif ($create || $in->has('spot_id') || $row['spot_id'] === null) {
            $spotId = (string) $in->str('spot_id', self::ID_LENGTH, true);
            $spot = $spotId === '' ? null : $this->spots->find($spotId, $orgId, true);
            if ($spot === null) {
                throw $in->notFound('spot_id');
            }
            $columns['spot_id'] = (string) $spot['id'];
        }

        $date = $in->date('date', $create || $in->has('date'));
        if ($date !== null) {
            if (strcmp($date, $today) > 0) {
                throw $in->error('date', 'must not be in the future');
            }
            $columns['service_date'] = $date;
        }
        foreach (['open_minute', 'close_minute'] as $key) {
            $minute = $in->int($key, 0, self::MAX_MINUTE, $create || $in->has($key));
            if ($minute !== null) {
                $columns[$key] = $minute;
            }
        }
        $actual = $in->int('actual', 0, self::MAX_ORDERS, $create || $in->has('actual'));
        if ($actual !== null) {
            $columns['actual_orders'] = $actual;
        }
        if ($in->has('sales')) {
            $columns['sales'] = $in->num('sales', 0.0, self::MAX_SALES);
        }
        $soldOut = $in->bool('sold_out', !$create && $in->has('sold_out'));
        if ($soldOut !== null) {
            $columns['sold_out'] = $soldOut;
        }
        if ($in->has('notes')) {
            $notes = $in->str('notes', 2000);
            $columns['notes'] = $notes === '' ? null : $notes;
        }

        $columns['link'] = null;
        if ($in->has('plan_stop_id')) {
            $stopId = $in->str('plan_stop_id', self::ID_LENGTH);
            $columns['plan_id'] = null;
            $columns['plan_stop_id'] = null;
            if ($stopId !== null) {
                $link = $stopId === '' ? null : $this->linkOf($stopId, $orgId);
                if ($link === null) {
                    throw $in->notFound('plan_stop_id');
                }
                $columns['plan_id'] = (string) $link['plan']['id'];
                $columns['plan_stop_id'] = (string) $link['stop']['id'];
                $columns['link'] = $link;
            }
        }
        if ($in->has('treat_as')) {
            $columns['treat_as'] = $in->enum('treat_as', array_merge(self::TREAT_AS_FIXED, array_values(Estimator::seed($A, 'vocabulary.dow'))));
        }
        return $columns;
    }

    /**
     * The checks that need the whole log: a window that ends after it starts.
     *
     * @param array<string, mixed> $columns the columns of the log as they will be
     * @return array<string, mixed>
     */
    private static function settle(Input $in, array $columns): array
    {
        if ((int) $columns['close_minute'] <= (int) $columns['open_minute']) {
            throw $in->error('close_minute', 'must be after open_minute');
        }
        return $columns;
    }

    /**
     * @param array<string, mixed> $columns the columns of the log as they will be
     * @throws TpConflict when another log of the spot has the same date and window
     */
    private function refuseDuplicate(string $orgId, array $columns, ?string $exceptId): void
    {
        if ($columns['log_kind'] !== 'spot' || $columns['spot_id'] === null) {
            return;
        }
        $same = $this->logs->existsSame(
            $orgId,
            (string) $columns['spot_id'],
            (string) $columns['service_date'],
            (int) $columns['open_minute'],
            (int) $columns['close_minute'],
            $exceptId
        );
        if ($same) {
            throw new TpConflict(self::DUPLICATE);
        }
    }

    /**
     * A planned stop of the organization with its plan and the plan's snapshot, or null.
     *
     * @return array{plan: array<string, mixed>, stop: array<string, mixed>}|null
     */
    private function linkOf(string $stopId, string $orgId): ?array
    {
        $stop = $this->plans->findStop($stopId, $orgId);
        $plan = $stop === null ? null : $this->plans->find((string) $stop['plan_id'], $orgId);
        return $stop === null || $plan === null ? null : ['plan' => $plan, 'stop' => $stop];
    }

    // ------------------------------------------------------------------------------------ shapes and helpers

    /**
     * The API's ServiceLog of a log row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $prediction = null;
        if ($row['predicted'] !== null) {
            $prediction = [
                'predicted_raw' => $row['predicted_raw'],
                'predicted' => $row['predicted'],
                'low' => $row['pred_low'],
                'high' => $row['pred_high'],
                'confidence' => $row['pred_confidence'],
                'basis' => $row['pred_basis'],
                'model_version' => $row['pred_model_version'],
                'seeds_revision' => $row['pred_seeds_rev'],
                'dataset_version' => $row['pred_dataset'],
                'detail' => is_array($row['prediction']) ? $row['prediction'] : [],
            ];
        }
        return [
            'id' => $row['id'],
            'kind' => $row['log_kind'],
            'spot_id' => $row['spot_id'],
            'plan_id' => $row['plan_id'],
            'plan_stop_id' => $row['plan_stop_id'],
            'date' => $row['service_date'],
            'open_minute' => $row['open_minute'],
            'close_minute' => $row['close_minute'],
            'actual' => $row['actual_orders'],
            'sales' => $row['sales'],
            'sold_out' => $row['sold_out'],
            'notes' => $row['notes'],
            'source' => $row['src'],
            'external_key' => $row['external_key'],
            'treat_as' => $row['treat_as'],
            'prediction' => $prediction,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * @return array<string, mixed> a log row
     */
    private function mustFind(string $orgId, string $id): array
    {
        $row = $id === '' ? null : $this->logs->find($id, $orgId);
        if ($row === null) {
            throw new TpNotFound(self::NOT_FOUND);
        }
        return $row;
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
