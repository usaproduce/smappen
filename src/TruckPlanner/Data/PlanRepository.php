<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;
use App\TruckPlanner\Services\Support\JsonSafe;
use App\TruckPlanner\Services\Support\Money;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * `tp_plans` and `tp_plan_stops`: the day plans of a truck, one per service date, and their stops
 * (04_BACKEND.md 1.2, 1.4).
 *
 * The repository speaks the plan row and the stop row. Their keys are the column names without the storage
 * suffix and their values are in API units:
 *
 *     fee_flat, fee_min, cat_price_head, cat_guarantee, cat_food_cost   dollars (float)   <-> *_cents
 *     gap_before_unpaid, result_has_google                               bool              <-> 0 / 1
 *     lat, lng, fee_pct, ev_attendance, cat_headcount                    float, bound as Sql::f text
 *     result, context                                                    the stored snapshot, or null
 *                                                                        <-> result_json, context_json
 *
 * A plan row carries: id, organization_id, truck_id, created_by, the keys of COLUMNS, result, context,
 * result_has_google, evaluated_at, model_version, seeds_revision, dataset_version, created_at, updated_at,
 * and four values worked out by the query:
 *
 *     has_snapshot       a stored result is there (and, for find(), reads as JSON)
 *     snapshot_expired   the snapshot holds Google legs and was evaluated more than 30 days ago
 *     stop_count         the number of stops
 *     spots_changed_at   the newest `updated_at` among the spots its stops refer to, or null
 *
 * find() and findByDate() add `stops`, the stop rows in visiting order. listRange() answers lighter rows:
 * `summary` (orders, take-home and day hours of the stored result) stands in for `result` and `context`,
 * and `stops` is there only on request.
 *
 * The two snapshots are JSON text in MEDIUMTEXT columns: written with snapshotText(), read with
 * json_decode, never parsed by MySQL. Every number reads back as the double, or the whole number, that
 * was saved.
 *
 * Every statement carries the organization id, with one exception: purgeExpiredSnapshots(null) is the
 * sweep of the daily purge script over every organization. Stops are replaced as a set. Service logs
 * refer to plans and stops; they are detached, never deleted, when a plan or a stop goes.
 *
 * A change of a plan is several statements (its columns, its stops, its snapshot): transaction() makes
 * them one.
 */
class PlanRepository
{
    private const STRING = 's';
    private const NULLABLE_STRING = 'n';
    private const FLOAT = 'f';
    private const NULLABLE_FLOAT = 'g';
    private const INT = 'i';
    private const NULLABLE_INT = 'j';
    private const BOOL = 'b';
    private const CENTS = 'c';
    private const NULLABLE_CENTS = 'd';

    /**
     * Plan row key => [column, kind]: the columns of a plan a caller may set.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const COLUMNS = [
        'service_date' => ['service_date', self::STRING],
        'name' => ['name', self::STRING],
        'treat_as' => ['treat_as', self::NULLABLE_STRING],
        'notes' => ['notes', self::NULLABLE_STRING],
        'plan_state' => ['plan_state', self::STRING],
    ];

    /**
     * Stop row key => [column, kind], in table order. A stop row also has `id`, `plan_id` and `seq`.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const STOP_COLUMNS = [
        'stop_kind' => ['stop_kind', self::STRING],
        'spot_id' => ['spot_id', self::NULLABLE_STRING],
        'label' => ['label', self::STRING],
        'lat' => ['lat', self::NULLABLE_FLOAT],
        'lng' => ['lng', self::NULLABLE_FLOAT],
        'address' => ['address', self::STRING],
        'open_minute' => ['open_minute', self::INT],
        'close_minute' => ['close_minute', self::INT],
        'gap_before_unpaid' => ['gap_before_unpaid', self::BOOL],
        'setup_minutes' => ['setup_minutes', self::NULLABLE_INT],
        'teardown_minutes' => ['teardown_minutes', self::NULLABLE_INT],
        'fee_flat' => ['fee_flat_cents', self::CENTS],
        'fee_pct' => ['fee_pct', self::FLOAT],
        'fee_min' => ['fee_min_cents', self::CENTS],
        'ev_attendance' => ['ev_attendance', self::NULLABLE_FLOAT],
        'ev_vendor_count' => ['ev_vendor_count', self::NULLABLE_INT],
        'ev_type' => ['ev_type', self::NULLABLE_STRING],
        'cat_headcount' => ['cat_headcount', self::NULLABLE_FLOAT],
        'cat_price_head' => ['cat_price_head_cents', self::NULLABLE_CENTS],
        'cat_guarantee' => ['cat_guarantee_cents', self::NULLABLE_CENTS],
        'cat_food_cost' => ['cat_food_cost_cents', self::NULLABLE_CENTS],
    ];

    /** What a plan and a stop get for a key the caller leaves out. */
    private const PLAN_DEFAULTS = ['name' => '', 'plan_state' => 'draft'];
    private const STOP_DEFAULTS = [
        'label' => '',
        'address' => '',
        'gap_before_unpaid' => false,
        'fee_flat' => 0.0,
        'fee_pct' => 0.0,
        'fee_min' => 0.0,
    ];

    /** The paths of a DayResult that are maps: written as {} also when they are empty. */
    private const RESULT_MAPS = ['warnings.*.data'];

    private const IDS_PER_STATEMENT = 200;
    private const SNAPSHOT_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private ?Database $db;
    private bool $open = false;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /** A new id for a plan or a stop. */
    public static function newId(): string
    {
        return Database::uuid();
    }

    /**
     * The text of a stored snapshot: JSON in which every float keeps all its digits and stays a float
     * (66.0 is written "66.0"), a number that cannot travel is null, and the maps at `$mapPaths` are
     * objects also when they are empty. json_decode() gives the value back, type for type.
     *
     * @param array<int|string, mixed> $value
     * @param list<string> $mapPaths dotted paths from the root; `*` matches the items of a list
     */
    public static function snapshotText(array $value, array $mapPaths = []): string
    {
        JsonSafe::shortestFloats();
        return json_encode(JsonSafe::clean($value, $mapPaths), self::SNAPSHOT_FLAGS);
    }

    // ------------------------------------------------------------------------------------ reads

    /**
     * The truck's plans dated `$from` to `$to` (both ends count), by date.
     *
     * @return list<array<string, mixed>> plan rows without `result` and `context`; `summary` is
     *         {orders, take_home, day_hours} of the stored result or null. With `$withStops` each row has
     *         `stops` (one more query for all of them)
     */
    public function listRange(string $orgId, string $truckId, string $from, string $to, bool $withStops = false): array
    {
        $rows = $this->db()->fetchAll(
            'SELECT ' . self::planSelect(false) . '
               FROM tp_plans p
              WHERE p.organization_id = ? AND p.truck_id = ? AND p.service_date BETWEEN ? AND ?
              ORDER BY p.service_date, p.id',
            [self::ttlDays(), $orgId, $truckId, $from, $to]
        );
        $plans = [];
        foreach ($rows as $row) {
            $plans[] = self::normalisePlan($row, false);
        }
        if ($withStops && $plans !== []) {
            $stops = $this->stopsOf(array_column($plans, 'id'), $orgId);
            foreach ($plans as $i => $plan) {
                $plans[$i]['stops'] = $stops[$plan['id']] ?? [];
            }
        }
        return $plans;
    }

    /**
     * One plan of the organization with its snapshot and its stops, or null.
     *
     * @return array<string, mixed>|null a plan row with `stops`
     */
    public function find(string $id, string $orgId): ?array
    {
        $row = $this->db()->fetch(
            'SELECT ' . self::planSelect(true) . '
               FROM tp_plans p
              WHERE p.id = ? AND p.organization_id = ?',
            [self::ttlDays(), $id, $orgId]
        );
        return $row === null ? null : $this->withStops(self::normalisePlan($row, true), $orgId);
    }

    /**
     * The truck's plan for a service date, or null: there is at most one.
     *
     * @return array<string, mixed>|null a plan row with `stops`
     */
    public function findByDate(string $orgId, string $truckId, string $date): ?array
    {
        $row = $this->db()->fetch(
            'SELECT ' . self::planSelect(true) . '
               FROM tp_plans p
              WHERE p.organization_id = ? AND p.truck_id = ? AND p.service_date = ?',
            [self::ttlDays(), $orgId, $truckId, $date]
        );
        return $row === null ? null : $this->withStops(self::normalisePlan($row, true), $orgId);
    }

    /**
     * One stop of one of the organization's plans, or null.
     *
     * @return array<string, mixed>|null a stop row (`plan_id` names its plan)
     */
    public function findStop(string $stopId, string $orgId): ?array
    {
        $row = $this->db()->fetch(
            'SELECT ' . self::stopSelect() . '
               FROM tp_plan_stops
              WHERE id = ? AND organization_id = ?',
            [$stopId, $orgId]
        );
        return $row === null ? null : self::normaliseStop($row);
    }

    // ------------------------------------------------------------------------------------ writes

    /**
     * Inserts a plan with its stops, in one transaction, and returns the plan's id.
     *
     * @param array<string, mixed> $columns keys of COLUMNS; `service_date` is required
     * @param list<array<string, mixed>> $stops stop rows in visiting order: keys of STOP_COLUMNS
     *                                          (`stop_kind`, `open_minute` and `close_minute` required)
     *                                          and optionally `id`
     * @throws \PDOException when the truck already has a plan for that date (the unique key)
     */
    public function create(string $orgId, string $truckId, ?string $userId, array $columns, array $stops): string
    {
        self::checkKeys($columns, self::COLUMNS, 'tp_plans');
        if (!isset($columns['service_date'])) {
            throw new \LogicException('tp_plans: no value for service_date');
        }
        $id = self::newId();
        $names = ['id', 'organization_id', 'truck_id', 'created_by'];
        $params = [$id, $orgId, $truckId, $userId];
        foreach (self::COLUMNS as $key => [$column, $kind]) {
            $value = array_key_exists($key, $columns) ? $columns[$key] : (self::PLAN_DEFAULTS[$key] ?? null);
            $names[] = $column;
            $params[] = self::encode('tp_plans', $key, $kind, $value);
        }
        $this->transaction(function () use ($names, $params, $id, $orgId, $stops): void {
            $this->db()->query(
                'INSERT INTO tp_plans (' . implode(', ', $names) . ', created_at, updated_at)
                 VALUES (' . Sql::marks(count($params)) . ', NOW(), NOW())',
                $params
            );
            $this->insertStops($id, $orgId, $stops);
        });
        return $id;
    }

    /**
     * Changes the given columns of one plan of the organization. The stops and the snapshot are untouched.
     *
     * @param array<string, mixed> $columns any subset of the keys of COLUMNS
     * @throws \PDOException when the date moves onto a date that has a plan (the unique key)
     */
    public function update(string $id, string $orgId, array $columns): void
    {
        self::checkKeys($columns, self::COLUMNS, 'tp_plans');
        $sets = [];
        $params = [];
        foreach ($columns as $key => $value) {
            [$column, $kind] = self::COLUMNS[$key];
            $sets[] = $column . ' = ?';
            $params[] = self::encode('tp_plans', $key, $kind, $value);
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $params[] = $orgId;
        $this->db()->query(
            'UPDATE tp_plans SET ' . implode(', ', $sets) . ' WHERE id = ? AND organization_id = ?',
            $params
        );
    }

    /**
     * Replaces the stops of a plan as a set, in one transaction. A stop that comes with the id it had
     * keeps it, so the service logs that name it stay linked; a log of a stop that is gone loses the
     * link to the stop and keeps the link to the plan.
     *
     * @param list<array<string, mixed>> $stops as for create()
     */
    public function replaceStops(string $planId, string $orgId, array $stops): void
    {
        $kept = [];
        foreach ($stops as $stop) {
            if (isset($stop['id'])) {
                $kept[] = (string) $stop['id'];
            }
        }
        $this->transaction(function () use ($planId, $orgId, $stops, $kept): void {
            $db = $this->db();
            // `updated_at = updated_at` holds the time stamp: losing a link is not a change of a log.
            $db->query(
                'UPDATE tp_service_logs SET plan_stop_id = NULL, updated_at = updated_at
                  WHERE organization_id = ? AND plan_id = ? AND plan_stop_id IS NOT NULL'
                    . ($kept === [] ? '' : ' AND plan_stop_id NOT IN (' . Sql::marks(count($kept)) . ')'),
                array_merge([$orgId, $planId], $kept)
            );
            $db->query('DELETE FROM tp_plan_stops WHERE organization_id = ? AND plan_id = ?', [$orgId, $planId]);
            $this->insertStops($planId, $orgId, $stops);
        });
    }

    /**
     * Stores the snapshot of an evaluation and stamps `evaluated_at` with the database clock.
     *
     * @param array<string, mixed> $result DayResult, as the model returned it
     * @param array<string, mixed> $context EvalContext
     * @param bool $hasGoogle whether a leg of the evaluation came from Google: such a snapshot is kept
     *                        for at most 30 days
     * @param array{model_version: string, seeds_revision: int, dataset_version: ?string} $versions
     */
    public function saveSnapshot(string $id, string $orgId, array $result, array $context, bool $hasGoogle, array $versions): void
    {
        $this->db()->query(
            'UPDATE tp_plans
                SET result_json = ?, context_json = ?, result_has_google = ?, evaluated_at = NOW(),
                    model_version = ?, seeds_revision = ?, dataset_version = ?
              WHERE id = ? AND organization_id = ?',
            [
                self::snapshotText($result, self::RESULT_MAPS),
                self::snapshotText($context),
                Sql::b($hasGoogle),
                (string) $versions['model_version'],
                (int) $versions['seeds_revision'],
                isset($versions['dataset_version']) ? (string) $versions['dataset_version'] : null,
                $id,
                $orgId,
            ]
        );
    }

    /**
     * Deletes a plan with its stops, in one transaction. Service logs that were linked to it keep their
     * numbers and lose the link.
     */
    public function delete(string $id, string $orgId): void
    {
        $this->transaction(function () use ($id, $orgId): void {
            $db = $this->db();
            $db->query(
                'UPDATE tp_service_logs SET plan_id = NULL, plan_stop_id = NULL, updated_at = updated_at
                  WHERE organization_id = ? AND plan_id = ?',
                [$orgId, $id]
            );
            $db->query('DELETE FROM tp_plan_stops WHERE organization_id = ? AND plan_id = ?', [$orgId, $id]);
            $db->query('DELETE FROM tp_plans WHERE id = ? AND organization_id = ?', [$id, $orgId]);
        });
    }

    /**
     * Empties every snapshot that holds Google legs and was evaluated more than 30 days ago: Google
     * content is deleted when its 30 days end, not only hidden. The plan itself (date, stops, notes,
     * status) is the owner's and stays, with its `updated_at`.
     *
     * @param string|null $orgId one organization, or null for every organization (the daily purge script)
     * @return int the number of snapshots emptied
     */
    public function purgeExpiredSnapshots(?string $orgId = null): int
    {
        $where = ($orgId === null ? '' : 'organization_id = ? AND ')
            . 'result_has_google = 1 AND evaluated_at < NOW() - INTERVAL ? DAY';
        $params = $orgId === null ? [self::ttlDays()] : [$orgId, self::ttlDays()];
        $expired = 0;
        $this->transaction(function () use ($where, $params, &$expired): void {
            $db = $this->db();
            $row = $db->fetch('SELECT COUNT(*) AS expired FROM tp_plans WHERE ' . $where, $params);
            $expired = (int) ($row['expired'] ?? 0);
            if ($expired > 0) {
                $db->query(
                    'UPDATE tp_plans
                        SET result_json = NULL, context_json = NULL, result_has_google = 0, evaluated_at = NULL,
                            model_version = NULL, seeds_revision = NULL, dataset_version = NULL, updated_at = updated_at
                      WHERE ' . $where,
                    $params
                );
            }
        });
        return $expired;
    }

    /**
     * Runs the writes made inside `$work` in one transaction: a plan's columns, its stops and its snapshot
     * change together or not at all. The methods of this class that open a transaction of their own
     * (create, replaceStops, delete, purgeExpiredSnapshots) join the open one when they are called inside.
     */
    public function transaction(callable $work): void
    {
        if ($this->open) {
            $work();
            return;
        }
        $db = $this->db();
        $db->beginTransaction();
        $this->open = true;
        try {
            $work();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        } finally {
            $this->open = false;
        }
    }

    // ------------------------------------------------------------------------------------ internals

    /** The lifetime of a snapshot that holds Google legs, in days. */
    private static function ttlDays(): int
    {
        return (int) TpConfig::get('plans.snapshot_ttl_days');
    }

    /**
     * The select list of a plan. Its first placeholder is the snapshot lifetime in days.
     */
    private static function planSelect(bool $withContext): string
    {
        return 'p.id, p.organization_id, p.truck_id, p.created_by, p.service_date, p.name, p.treat_as, p.notes, p.plan_state,
                    p.result_json, ' . ($withContext ? 'p.context_json, ' : '') . 'p.result_has_google, p.evaluated_at,
                    p.model_version, p.seeds_revision, p.dataset_version, p.created_at, p.updated_at,
                    (p.result_json IS NOT NULL) AS has_snapshot,
                    (p.result_has_google = 1 AND p.evaluated_at < NOW() - INTERVAL ? DAY) AS snapshot_expired,
                    (SELECT COUNT(*) FROM tp_plan_stops s
                      WHERE s.organization_id = p.organization_id AND s.plan_id = p.id) AS stop_count,
                    (SELECT MAX(sp.updated_at) FROM tp_plan_stops s
                       JOIN tp_spots sp ON sp.organization_id = s.organization_id AND sp.id = s.spot_id
                      WHERE s.organization_id = p.organization_id AND s.plan_id = p.id) AS spots_changed_at';
    }

    private static function stopSelect(): string
    {
        $names = ['id', 'plan_id', 'seq'];
        foreach (self::STOP_COLUMNS as [$column]) {
            $names[] = $column;
        }
        return implode(', ', $names);
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function withStops(array $plan, string $orgId): array
    {
        $plan['stops'] = $this->stopsOf([$plan['id']], $orgId)[$plan['id']] ?? [];
        return $plan;
    }

    /**
     * The stops of the given plans, in visiting order.
     *
     * @param list<string> $planIds
     * @return array<string, list<array<string, mixed>>> stop rows by plan id
     */
    private function stopsOf(array $planIds, string $orgId): array
    {
        $out = [];
        foreach (array_chunk(array_values($planIds), self::IDS_PER_STATEMENT) as $chunk) {
            $rows = $this->db()->fetchAll(
                'SELECT ' . self::stopSelect() . '
                   FROM tp_plan_stops
                  WHERE organization_id = ? AND plan_id IN (' . Sql::marks(count($chunk)) . ')
                  ORDER BY plan_id, seq',
                array_merge([$orgId], $chunk)
            );
            foreach ($rows as $row) {
                $stop = self::normaliseStop($row);
                $out[$stop['plan_id']][] = $stop;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $stops
     */
    private function insertStops(string $planId, string $orgId, array $stops): void
    {
        $db = $this->db();
        $seq = 0;
        foreach ($stops as $stop) {
            $id = isset($stop['id']) ? (string) $stop['id'] : self::newId();
            unset($stop['id'], $stop['plan_id'], $stop['seq']);
            self::checkKeys($stop, self::STOP_COLUMNS, 'tp_plan_stops');
            $names = ['id', 'organization_id', 'plan_id', 'seq'];
            $params = [$id, $orgId, $planId, $seq];
            foreach (self::STOP_COLUMNS as $key => [$column, $kind]) {
                $value = array_key_exists($key, $stop) ? $stop[$key] : (self::STOP_DEFAULTS[$key] ?? null);
                $names[] = $column;
                $params[] = self::encode('tp_plan_stops', $key, $kind, $value);
            }
            $db->query(
                'INSERT INTO tp_plan_stops (' . implode(', ', $names) . ', created_at, updated_at)
                 VALUES (' . Sql::marks(count($params)) . ', NOW(), NOW())',
                $params
            );
            $seq++;
        }
    }

    /**
     * Refuses a key that is not a settable column.
     *
     * @param array<int|string, mixed> $columns
     * @param array<string, array{0: string, 1: string}> $known
     */
    private static function checkKeys(array $columns, array $known, string $table): void
    {
        foreach (array_keys($columns) as $key) {
            if (!is_string($key) || !isset($known[$key])) {
                throw new \LogicException($table . ': not a column that can be set: ' . $key);
            }
        }
    }

    /** A row value as it is bound into SQL. */
    private static function encode(string $table, string $key, string $kind, mixed $value): mixed
    {
        $nullable = in_array(
            $kind,
            [self::NULLABLE_STRING, self::NULLABLE_FLOAT, self::NULLABLE_INT, self::NULLABLE_CENTS],
            true
        );
        if ($value === null) {
            if (!$nullable) {
                throw new \LogicException($table . ': ' . $key . ' cannot be null');
            }
            return null;
        }
        switch ($kind) {
            case self::STRING:
            case self::NULLABLE_STRING:
                return (string) $value;
            case self::FLOAT:
            case self::NULLABLE_FLOAT:
                return Sql::f((float) $value);
            case self::INT:
            case self::NULLABLE_INT:
                return (int) $value;
            case self::BOOL:
                return Sql::b((bool) $value);
            case self::CENTS:
            case self::NULLABLE_CENTS:
                return Money::toCents((float) $value);
        }
        throw new \LogicException($table . ': unknown column kind');
    }

    /**
     * @param array<string, mixed> $row a database row
     * @return array<string, mixed>
     */
    private static function normalisePlan(array $row, bool $full): array
    {
        $out = [
            'id' => (string) $row['id'],
            'organization_id' => (string) $row['organization_id'],
            'truck_id' => (string) $row['truck_id'],
            'created_by' => $row['created_by'] === null ? null : (string) $row['created_by'],
            'service_date' => (string) $row['service_date'],
            'name' => (string) $row['name'],
            'treat_as' => $row['treat_as'] === null ? null : (string) $row['treat_as'],
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'plan_state' => (string) $row['plan_state'],
        ];
        $result = self::decoded($row['result_json'] ?? null);
        if ($full) {
            $context = self::decoded($row['context_json'] ?? null);
            $whole = $result !== null && $context !== null;
            $out['result'] = $whole ? $result : null;
            $out['context'] = $whole ? $context : null;
            $out['has_snapshot'] = $whole;
        } else {
            $out['summary'] = self::summary($result);
            $out['has_snapshot'] = (int) ($row['has_snapshot'] ?? 0) === 1;
        }
        $out['result_has_google'] = (int) $row['result_has_google'] === 1;
        $out['evaluated_at'] = $row['evaluated_at'] === null ? null : (string) $row['evaluated_at'];
        $out['model_version'] = $row['model_version'] === null ? null : (string) $row['model_version'];
        $out['seeds_revision'] = $row['seeds_revision'] === null ? null : (int) $row['seeds_revision'];
        $out['dataset_version'] = $row['dataset_version'] === null ? null : (string) $row['dataset_version'];
        $out['created_at'] = (string) $row['created_at'];
        $out['updated_at'] = (string) $row['updated_at'];
        $out['snapshot_expired'] = (int) ($row['snapshot_expired'] ?? 0) === 1;
        $out['stop_count'] = (int) ($row['stop_count'] ?? 0);
        $out['spots_changed_at'] = ($row['spots_changed_at'] ?? null) === null ? null : (string) $row['spots_changed_at'];
        return $out;
    }

    /**
     * The three figures a list shows of a stored result, or null without one.
     *
     * @param array<int|string, mixed>|null $result DayResult
     * @return array{orders: array<string, mixed>, take_home: array<string, mixed>, day_hours: float}|null
     */
    private static function summary(?array $result): ?array
    {
        $totals = $result['totals'] ?? null;
        if (!is_array($totals) || !is_array($totals['orders'] ?? null) || !is_array($totals['take_home'] ?? null)
            || !(is_int($totals['day_hours'] ?? null) || is_float($totals['day_hours'] ?? null))) {
            return null;
        }
        return [
            'orders' => $totals['orders'],
            'take_home' => $totals['take_home'],
            'day_hours' => (float) $totals['day_hours'],
        ];
    }

    /**
     * @param array<string, mixed> $row a database row
     * @return array<string, mixed>
     */
    private static function normaliseStop(array $row): array
    {
        $out = [
            'id' => (string) $row['id'],
            'plan_id' => (string) $row['plan_id'],
            'seq' => (int) $row['seq'],
        ];
        foreach (self::STOP_COLUMNS as $key => [$column, $kind]) {
            $value = $row[$column];
            switch ($kind) {
                case self::STRING:
                    $out[$key] = (string) $value;
                    break;
                case self::NULLABLE_STRING:
                    $out[$key] = $value === null ? null : (string) $value;
                    break;
                case self::FLOAT:
                    $out[$key] = (float) $value;
                    break;
                case self::NULLABLE_FLOAT:
                    $out[$key] = $value === null ? null : (float) $value;
                    break;
                case self::INT:
                    $out[$key] = (int) $value;
                    break;
                case self::NULLABLE_INT:
                    $out[$key] = $value === null ? null : (int) $value;
                    break;
                case self::BOOL:
                    $out[$key] = (int) $value === 1;
                    break;
                case self::CENTS:
                    $out[$key] = Money::fromCents((int) $value);
                    break;
                case self::NULLABLE_CENTS:
                    $out[$key] = $value === null ? null : Money::fromCents((int) $value);
                    break;
            }
        }
        return $out;
    }

    /**
     * A stored snapshot as an array, or null for SQL NULL and for text that is not a JSON object or list.
     *
     * @return array<int|string, mixed>|null
     */
    private static function decoded(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }
}
