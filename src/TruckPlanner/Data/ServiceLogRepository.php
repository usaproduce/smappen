<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;
use App\TruckPlanner\Services\Support\Money;

/**
 * `tp_service_logs`: what the owner logged after a service, and the prediction each log is judged against
 * (04_BACKEND.md 1.2, 1.4, 5.7).
 *
 * The repository speaks the log row. Its keys are the column names without the storage suffix and its
 * values are in API units:
 *
 *     sales                      dollars (float) or null                 <-> sales_cents
 *     sold_out                   bool                                    <-> 0 / 1
 *     predicted_raw, predicted, pred_low, pred_high      float or null, bound as Sql::f text
 *     weather                    {ctx, ctx_next}: the hourly forecast of the service date and of the next
 *                                date as the prediction read them, or null   <-> weather_json
 *     prediction                 the detail kept with the prediction, or null  <-> prediction_json
 *
 * A read row carries, beyond the keys of COLUMNS: id, organization_id, truck_id, created_by, created_at,
 * updated_at. `weather` is the one exception: it can be large and only the recomputation of a raw
 * prediction reads it, so find() and listRange() leave it out and weatherOf() fetches it.
 *
 * `weather_json` and `prediction_json` are JSON text in MEDIUMTEXT columns, written with
 * PlanRepository::snapshotText() and read with json_decode. MySQL never parses them. allForCalibration()
 * asks MySQL for the SHA-1 of the stored weather text, which is the same as weatherSha1() of the value
 * that was written: a hash of the bytes, not a look inside them.
 *
 * Every statement carries the organization id.
 */
class ServiceLogRepository
{
    private const STRING = 's';
    private const NULLABLE_STRING = 'n';
    private const NULLABLE_FLOAT = 'g';
    private const INT = 'i';
    private const NULLABLE_INT = 'j';
    private const BOOL = 'b';
    private const NULLABLE_CENTS = 'd';
    private const NULLABLE_SNAPSHOT = 't';

    /**
     * Row key => [column, kind], in table order. These are the columns a caller may set.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const COLUMNS = [
        'log_kind' => ['log_kind', self::STRING],
        'spot_id' => ['spot_id', self::NULLABLE_STRING],
        'plan_id' => ['plan_id', self::NULLABLE_STRING],
        'plan_stop_id' => ['plan_stop_id', self::NULLABLE_STRING],
        'service_date' => ['service_date', self::STRING],
        'open_minute' => ['open_minute', self::INT],
        'close_minute' => ['close_minute', self::INT],
        'actual_orders' => ['actual_orders', self::INT],
        'sales' => ['sales_cents', self::NULLABLE_CENTS],
        'sold_out' => ['sold_out', self::BOOL],
        'notes' => ['notes', self::NULLABLE_STRING],
        'src' => ['src', self::STRING],
        'external_key' => ['external_key', self::NULLABLE_STRING],
        'treat_as' => ['treat_as', self::NULLABLE_STRING],
        'weather' => ['weather_json', self::NULLABLE_SNAPSHOT],
        'predicted_raw' => ['predicted_raw', self::NULLABLE_FLOAT],
        'pred_raw_basis' => ['pred_raw_basis', self::NULLABLE_STRING],
        'predicted' => ['predicted', self::NULLABLE_FLOAT],
        'pred_low' => ['pred_low', self::NULLABLE_FLOAT],
        'pred_high' => ['pred_high', self::NULLABLE_FLOAT],
        'pred_confidence' => ['pred_confidence', self::NULLABLE_STRING],
        'pred_basis' => ['pred_basis', self::NULLABLE_STRING],
        'prediction' => ['prediction_json', self::NULLABLE_SNAPSHOT],
        'pred_model_version' => ['pred_model_version', self::NULLABLE_STRING],
        'pred_seeds_rev' => ['pred_seeds_rev', self::NULLABLE_INT],
        'pred_dataset' => ['pred_dataset', self::NULLABLE_STRING],
    ];

    /** What create() writes for a key the caller leaves out. The date, the window and the count have no default. */
    private const CREATE_DEFAULTS = ['log_kind' => 'spot', 'sold_out' => false, 'src' => 'manual'];

    private const REQUIRED = ['service_date', 'open_minute', 'close_minute', 'actual_orders'];
    private const IDS_PER_STATEMENT = 200;

    private ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * The SHA-1 of the text that is stored for a `weather` value: what allForCalibration() answers as
     * `weather_sha1` for the row. Null for null (nothing is stored then).
     *
     * @param array<string, mixed>|null $weather
     */
    public static function weatherSha1(?array $weather): ?string
    {
        return $weather === null ? null : sha1(PlanRepository::snapshotText($weather));
    }

    // ------------------------------------------------------------------------------------ reads

    /**
     * The truck's logs dated `$from` to `$to` (both ends count), newest first, then by id.
     *
     * @param string|null $spotId only the logs of this spot
     * @return list<array<string, mixed>> log rows without `weather`
     */
    public function listRange(string $orgId, string $truckId, string $from, string $to, ?string $spotId = null): array
    {
        $params = [$orgId, $truckId, $from, $to];
        if ($spotId !== null) {
            $params[] = $spotId;
        }
        $rows = $this->db()->fetchAll(
            'SELECT ' . self::columnList() . '
               FROM tp_service_logs
              WHERE organization_id = ? AND truck_id = ? AND service_date BETWEEN ? AND ?'
                . ($spotId === null ? '' : ' AND spot_id = ?') . '
              ORDER BY service_date DESC, id ASC',
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalise($row);
        }
        return $out;
    }

    /**
     * One log of the organization, or null.
     *
     * @return array<string, mixed>|null a log row without `weather`
     */
    public function find(string $id, string $orgId): ?array
    {
        $row = $this->db()->fetch(
            'SELECT ' . self::columnList() . '
               FROM tp_service_logs
              WHERE id = ? AND organization_id = ?',
            [$id, $orgId]
        );
        return $row === null ? null : self::normalise($row);
    }

    /**
     * Every log of the truck with what calibration reads of it, oldest first, then by id.
     *
     * @return list<array{id: string, log_kind: string, spot_id: ?string, service_date: string, open_minute: int,
     *                    close_minute: int, actual_orders: int, sold_out: bool, treat_as: ?string,
     *                    predicted_raw: ?float, pred_raw_basis: ?string, predicted: ?float, pred_low: ?float,
     *                    pred_high: ?float, weather_sha1: ?string}>
     */
    public function allForCalibration(string $orgId, string $truckId): array
    {
        $rows = $this->db()->fetchAll(
            'SELECT id, log_kind, spot_id, service_date, open_minute, close_minute, actual_orders, sold_out, treat_as,
                    predicted_raw, pred_raw_basis, predicted, pred_low, pred_high, SHA1(weather_json) AS weather_sha1
               FROM tp_service_logs
              WHERE organization_id = ? AND truck_id = ?
              ORDER BY service_date, id',
            [$orgId, $truckId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (string) $row['id'],
                'log_kind' => (string) $row['log_kind'],
                'spot_id' => $row['spot_id'] === null ? null : (string) $row['spot_id'],
                'service_date' => (string) $row['service_date'],
                'open_minute' => (int) $row['open_minute'],
                'close_minute' => (int) $row['close_minute'],
                'actual_orders' => (int) $row['actual_orders'],
                'sold_out' => (int) $row['sold_out'] === 1,
                'treat_as' => $row['treat_as'] === null ? null : (string) $row['treat_as'],
                'predicted_raw' => $row['predicted_raw'] === null ? null : (float) $row['predicted_raw'],
                'pred_raw_basis' => $row['pred_raw_basis'] === null ? null : (string) $row['pred_raw_basis'],
                'predicted' => $row['predicted'] === null ? null : (float) $row['predicted'],
                'pred_low' => $row['pred_low'] === null ? null : (float) $row['pred_low'],
                'pred_high' => $row['pred_high'] === null ? null : (float) $row['pred_high'],
                'weather_sha1' => $row['weather_sha1'] === null ? null : strtolower((string) $row['weather_sha1']),
            ];
        }
        return $out;
    }

    /**
     * The stored `weather` of the given logs.
     *
     * @param list<string> $ids
     * @return array<string, array<string, mixed>|null> by log id; a log of another organization is absent
     */
    public function weatherOf(array $ids, string $orgId): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('strval', $ids))), self::IDS_PER_STATEMENT) as $chunk) {
            $rows = $this->db()->fetchAll(
                'SELECT id, weather_json
                   FROM tp_service_logs
                  WHERE organization_id = ? AND id IN (' . Sql::marks(count($chunk)) . ')',
                array_merge([$orgId], $chunk)
            );
            foreach ($rows as $row) {
                $out[(string) $row['id']] = self::decoded($row['weather_json']);
            }
        }
        return $out;
    }

    /**
     * Is a service already logged for this spot, date and window?
     *
     * @param string|null $exceptId a log that does not count (the one being changed)
     */
    public function existsSame(string $orgId, string $spotId, string $date, int $open, int $close, ?string $exceptId): bool
    {
        $params = [$orgId, $spotId, $date, $open, $close];
        if ($exceptId !== null) {
            $params[] = $exceptId;
        }
        $row = $this->db()->fetch(
            'SELECT id
               FROM tp_service_logs
              WHERE organization_id = ? AND spot_id = ? AND service_date = ? AND open_minute = ? AND close_minute = ?'
                . ($exceptId === null ? '' : ' AND id <> ?') . '
              LIMIT 1',
            $params
        );
        return $row !== null;
    }

    /** When a log of the truck was last written or changed (database clock), or null without logs. */
    public function lastChangeAt(string $orgId, string $truckId): ?string
    {
        $row = $this->db()->fetch(
            'SELECT MAX(updated_at) AS changed_at
               FROM tp_service_logs
              WHERE organization_id = ? AND truck_id = ?',
            [$orgId, $truckId]
        );
        return ($row['changed_at'] ?? null) === null ? null : (string) $row['changed_at'];
    }

    // ------------------------------------------------------------------------------------ writes

    /**
     * Inserts a log and returns its id. Every column is written: a key that is left out is NULL, or its
     * default (kind `spot`, not sold out, source `manual`).
     *
     * @param array<string, mixed> $columns keys of COLUMNS; `service_date`, `open_minute`, `close_minute`
     *                                      and `actual_orders` are required
     * @throws \PDOException when (source, external_key) is already there (the unique key of imports)
     */
    public function create(string $orgId, string $truckId, ?string $userId, array $columns): string
    {
        self::checkKeys($columns);
        foreach (self::REQUIRED as $required) {
            if (!isset($columns[$required])) {
                throw new \LogicException('tp_service_logs: no value for ' . $required);
            }
        }
        $id = Database::uuid();
        $names = ['id', 'organization_id', 'truck_id', 'created_by'];
        $params = [$id, $orgId, $truckId, $userId];
        foreach (self::COLUMNS as $key => [$column, $kind]) {
            $value = array_key_exists($key, $columns) ? $columns[$key] : (self::CREATE_DEFAULTS[$key] ?? null);
            $names[] = $column;
            $params[] = self::encode($key, $kind, $value);
        }
        $this->db()->query(
            'INSERT INTO tp_service_logs (' . implode(', ', $names) . ', created_at, updated_at)
             VALUES (' . Sql::marks(count($params)) . ', NOW(), NOW())',
            $params
        );
        return $id;
    }

    /**
     * Changes the given columns of one log of the organization, in one statement.
     *
     * @param array<string, mixed> $columns any subset of the keys of COLUMNS
     */
    public function update(string $id, string $orgId, array $columns): void
    {
        self::checkKeys($columns);
        $sets = [];
        $params = [];
        foreach ($columns as $key => $value) {
            [$column, $kind] = self::COLUMNS[$key];
            $sets[] = $column . ' = ?';
            $params[] = self::encode($key, $kind, $value);
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $params[] = $orgId;
        $this->db()->query(
            'UPDATE tp_service_logs SET ' . implode(', ', $sets) . ' WHERE id = ? AND organization_id = ?',
            $params
        );
    }

    /**
     * Stores a recomputed raw prediction with the basis it was computed from. What the owner was shown
     * (`predicted`, `pred_low`, `pred_high`) is history and is left alone.
     *
     * @param float|null $raw null for a log the model could not read under this basis: it then carries
     *                        no full prediction, and is not tried again until its basis changes
     * @param array{model_version: string, seeds_revision: int, dataset_version: ?string} $versions
     */
    public function setRawPrediction(string $id, string $orgId, ?float $raw, string $basis, array $versions): void
    {
        $this->db()->query(
            'UPDATE tp_service_logs
                SET predicted_raw = ?, pred_raw_basis = ?, pred_model_version = ?, pred_seeds_rev = ?, pred_dataset = ?
              WHERE id = ? AND organization_id = ?',
            [
                $raw === null ? null : Sql::f($raw),
                $basis,
                (string) $versions['model_version'],
                (int) $versions['seeds_revision'],
                isset($versions['dataset_version']) ? (string) $versions['dataset_version'] : null,
                $id,
                $orgId,
            ]
        );
    }

    /** Deletes one log of the organization. */
    public function delete(string $id, string $orgId): void
    {
        $this->db()->query('DELETE FROM tp_service_logs WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    }

    // ------------------------------------------------------------------------------------ internals

    private static function columnList(): string
    {
        $names = ['id', 'organization_id', 'truck_id', 'created_by'];
        foreach (self::COLUMNS as $key => [$column]) {
            if ($key !== 'weather') {
                $names[] = $column;
            }
        }
        array_push($names, 'created_at', 'updated_at');
        return implode(', ', $names);
    }

    /**
     * Refuses a key that is not a settable column.
     *
     * @param array<int|string, mixed> $columns
     */
    private static function checkKeys(array $columns): void
    {
        foreach (array_keys($columns) as $key) {
            if (!is_string($key) || !isset(self::COLUMNS[$key])) {
                throw new \LogicException('tp_service_logs: not a column that can be set: ' . $key);
            }
        }
    }

    /** A row value as it is bound into SQL. */
    private static function encode(string $key, string $kind, mixed $value): mixed
    {
        if ($value === null) {
            if ($kind === self::STRING || $kind === self::INT || $kind === self::BOOL) {
                throw new \LogicException('tp_service_logs: ' . $key . ' cannot be null');
            }
            return null;
        }
        switch ($kind) {
            case self::STRING:
            case self::NULLABLE_STRING:
                return (string) $value;
            case self::NULLABLE_FLOAT:
                return Sql::f((float) $value);
            case self::INT:
            case self::NULLABLE_INT:
                return (int) $value;
            case self::BOOL:
                return Sql::b((bool) $value);
            case self::NULLABLE_CENTS:
                return Money::toCents((float) $value);
            case self::NULLABLE_SNAPSHOT:
                return PlanRepository::snapshotText((array) $value);
        }
        throw new \LogicException('tp_service_logs: unknown column kind');
    }

    /**
     * @param array<string, mixed> $row a database row
     * @return array<string, mixed>
     */
    private static function normalise(array $row): array
    {
        $out = [
            'id' => (string) $row['id'],
            'organization_id' => (string) $row['organization_id'],
            'truck_id' => (string) $row['truck_id'],
            'created_by' => $row['created_by'] === null ? null : (string) $row['created_by'],
        ];
        foreach (self::COLUMNS as $key => [$column, $kind]) {
            if ($key === 'weather') {
                continue;
            }
            $value = $row[$column];
            switch ($kind) {
                case self::STRING:
                    $out[$key] = (string) $value;
                    break;
                case self::NULLABLE_STRING:
                    $out[$key] = $value === null ? null : (string) $value;
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
                case self::NULLABLE_CENTS:
                    $out[$key] = $value === null ? null : Money::fromCents((int) $value);
                    break;
                case self::NULLABLE_SNAPSHOT:
                    $out[$key] = self::decoded($value);
                    break;
            }
        }
        $out['created_at'] = (string) $row['created_at'];
        $out['updated_at'] = (string) $row['updated_at'];
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
