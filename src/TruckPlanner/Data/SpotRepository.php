<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;
use App\TruckPlanner\Services\Support\Money;

/**
 * `tp_spots`: the saved spots of a truck (04_BACKEND.md 1.2, 1.4).
 *
 * The repository speaks the spot row. Its keys are the column names without the storage suffix and its
 * values are in API units:
 *
 *     fee_flat, fee_min                 dollars (float)                      <-> *_cents
 *     allowed                           {days: [7 bool], open_minute, close_minute} or null   <-> allowed_json
 *     host_only_food, vec_in_region     bool                                 <-> 0 / 1
 *     lat, lng, fee_pct, host_size, vec_excluded       float, bound as Sql::f text
 *     vectors                           the three stored blocks, or null     <-> vectors_bin
 *
 * `vectors` is {hidden, normal, prominent}; each block holds the 50 stored numbers of one visibility level
 * as {capture: {day, eve}, nearby, rivals: {day, eve}}. The labels of a LocationVectors that are not
 * stored per block travel beside it: vec_in_region, vec_points_used, vec_excluded, vec_region_id,
 * vec_dataset, vec_seeds_rev. The seven are written together or not at all, and `vec_at` is stamped with
 * them. The bytes are exact: what was written is what is read, to the last bit.
 *
 * A read row carries, beyond the keys of COLUMNS: id, organization_id, truck_id, created_by, vectors_sha1
 * (the SHA-1 of the stored vector bytes, null without vectors), vec_at, archived_at, created_at,
 * updated_at.
 *
 * Every statement carries the organization id. A spot is never deleted: archive() stamps `archived_at`,
 * because plans and service logs refer to spots.
 */
class SpotRepository
{
    /** The order of the three blocks inside `vectors_bin`. */
    public const BLOCKS = ['hidden', 'normal', 'prominent'];

    /** The keys that make up a vector write. */
    public const VECTOR_KEYS = [
        'vectors', 'vec_in_region', 'vec_points_used', 'vec_excluded', 'vec_region_id', 'vec_dataset', 'vec_seeds_rev',
    ];

    private const STRING = 's';
    private const NULLABLE_STRING = 'n';
    private const FLOAT = 'f';
    private const NULLABLE_FLOAT = 'g';
    private const INT = 'i';
    private const NULLABLE_INT = 'j';
    private const BOOL = 'b';
    private const CENTS = 'c';
    private const NULLABLE_OBJECT = 'o';
    private const VECTORS = 'v';

    /**
     * Row key => [column, kind], in table order. These are the columns a caller may set.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const COLUMNS = [
        'name' => ['name', self::STRING],
        'lat' => ['lat', self::FLOAT],
        'lng' => ['lng', self::FLOAT],
        'address' => ['address', self::STRING],
        'county_fips' => ['county_fips', self::NULLABLE_STRING],
        'notes' => ['notes', self::NULLABLE_STRING],
        'visibility' => ['visibility', self::STRING],
        'host_segment' => ['host_segment', self::NULLABLE_STRING],
        'host_size' => ['host_size', self::NULLABLE_FLOAT],
        'host_size_source' => ['host_size_source', self::NULLABLE_STRING],
        'host_only_food' => ['host_only_food', self::BOOL],
        'host_place_type' => ['host_place_type', self::NULLABLE_STRING],
        'host_name' => ['host_name', self::NULLABLE_STRING],
        'host_contact' => ['host_contact', self::NULLABLE_STRING],
        'host_phone' => ['host_phone', self::NULLABLE_STRING],
        'host_website' => ['host_website', self::NULLABLE_STRING],
        'place_key' => ['place_key', self::NULLABLE_STRING],
        'host_point_id' => ['host_point_id', self::NULLABLE_STRING],
        'google_place_id' => ['google_place_id', self::NULLABLE_STRING],
        'fee_flat' => ['fee_flat_cents', self::CENTS],
        'fee_pct' => ['fee_pct', self::FLOAT],
        'fee_min' => ['fee_min_cents', self::CENTS],
        'allowed' => ['allowed_json', self::NULLABLE_OBJECT],
        'vectors' => ['vectors_bin', self::VECTORS],
        'vec_in_region' => ['vec_in_region', self::BOOL],
        'vec_points_used' => ['vec_points_used', self::INT],
        'vec_excluded' => ['vec_excluded', self::FLOAT],
        'vec_region_id' => ['vec_region_id', self::NULLABLE_STRING],
        'vec_dataset' => ['vec_dataset', self::NULLABLE_STRING],
        'vec_seeds_rev' => ['vec_seeds_rev', self::NULLABLE_INT],
    ];

    /** What create() writes for a key the caller leaves out. `name`, `lat` and `lng` have no default. */
    private const CREATE_DEFAULTS = [
        'address' => '',
        'visibility' => 'normal',
        'host_only_food' => false,
        'fee_flat' => 0.0,
        'fee_pct' => 0.0,
        'fee_min' => 0.0,
        'vec_in_region' => false,
        'vec_points_used' => 0,
        'vec_excluded' => 0.0,
    ];

    private const IDS_PER_STATEMENT = 200;

    private ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * The spots of a truck ordered by name, then id. Archived spots only on request.
     *
     * @return list<array<string, mixed>> spot rows
     */
    public function listActive(string $orgId, string $truckId, bool $withArchived = false): array
    {
        $rows = $this->db()->fetchAll(
            'SELECT ' . self::columnList() . '
               FROM tp_spots
              WHERE organization_id = ? AND truck_id = ?' . ($withArchived ? '' : ' AND archived_at IS NULL') . '
              ORDER BY name, id',
            [$orgId, $truckId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalise($row);
        }
        return $out;
    }

    /**
     * One spot of the organization, or null. An archived spot is found only with `$withArchived`.
     *
     * @return array<string, mixed>|null a spot row
     */
    public function find(string $id, string $orgId, bool $withArchived = false): ?array
    {
        $row = $this->db()->fetch(
            'SELECT ' . self::columnList() . '
               FROM tp_spots
              WHERE id = ? AND organization_id = ?' . ($withArchived ? '' : ' AND archived_at IS NULL'),
            [$id, $orgId]
        );
        return $row === null ? null : self::normalise($row);
    }

    /**
     * The organization's spots with these ids, archived ones included (plans and logs keep referring to
     * them). An id that is unknown, or another organization's, is simply absent.
     *
     * @param list<string> $ids
     * @return array<string, array<string, mixed>> spot rows keyed by id
     */
    public function findMany(array $ids, string $orgId): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            $wanted[(string) $id] = true;
        }
        $out = [];
        foreach (array_chunk(array_map('strval', array_keys($wanted)), self::IDS_PER_STATEMENT) as $chunk) {
            $rows = $this->db()->fetchAll(
                'SELECT ' . self::columnList() . '
                   FROM tp_spots
                  WHERE organization_id = ? AND id IN (' . Sql::marks(count($chunk)) . ')',
                array_merge([$orgId], $chunk)
            );
            foreach ($rows as $row) {
                $spot = self::normalise($row);
                $out[$spot['id']] = $spot;
            }
        }
        return $out;
    }

    /** The number of spots of a truck that are not archived. */
    public function countActive(string $orgId, string $truckId): int
    {
        $row = $this->db()->fetch(
            'SELECT COUNT(*) AS spot_count
               FROM tp_spots
              WHERE organization_id = ? AND truck_id = ? AND archived_at IS NULL',
            [$orgId, $truckId]
        );
        return (int) ($row['spot_count'] ?? 0);
    }

    /**
     * Inserts a spot and returns its id. Every column is written: a key that is left out gets its default
     * (empty address, visibility normal, no host, no fee, no vectors).
     *
     * @param array<string, mixed> $columns keys of COLUMNS; `name`, `lat` and `lng` are required. The seven
     *                                      VECTOR_KEYS are given together or not at all
     */
    public function create(string $orgId, string $truckId, ?string $userId, array $columns): string
    {
        self::checkKeys($columns);
        foreach (['name', 'lat', 'lng'] as $required) {
            if (!isset($columns[$required])) {
                throw new \LogicException('tp_spots: no value for ' . $required);
            }
        }
        $id = Database::uuid();
        $names = ['id', 'organization_id', 'truck_id', 'created_by'];
        $marks = ['?', '?', '?', '?'];
        $params = [$id, $orgId, $truckId, $userId];
        foreach (self::COLUMNS as $key => [$column, $kind]) {
            $value = array_key_exists($key, $columns) ? $columns[$key] : (self::CREATE_DEFAULTS[$key] ?? null);
            $names[] = $column;
            $marks[] = $kind === self::VECTORS ? 'UNHEX(?)' : '?';
            $params[] = self::encode($key, $kind, $value);
        }
        $hasVectors = ($columns['vectors'] ?? null) !== null;
        $this->db()->query(
            'INSERT INTO tp_spots (' . implode(', ', $names) . ', vec_at, created_at, updated_at)
             VALUES (' . implode(', ', $marks) . ', ' . ($hasVectors ? 'NOW()' : 'NULL') . ', NOW(), NOW())',
            $params
        );
        return $id;
    }

    /**
     * Changes the given columns of one spot of the organization, in one statement. `updated_at` moves
     * only when a value really changes (the column's ON UPDATE rule), which is what marks stored plan
     * results as stale.
     *
     * @param array<string, mixed> $columns any subset of the keys of COLUMNS. The seven VECTOR_KEYS are
     *                                      given together or not at all
     */
    public function update(string $id, string $orgId, array $columns): void
    {
        $this->write($id, $orgId, $columns);
    }

    /**
     * Stores recomputed vectors with their labels and stamps `vec_at`.
     *
     * @param array<string, mixed> $vectors exactly the seven VECTOR_KEYS: `vectors` = {hidden, normal,
     *                                      prominent}, each with `capture.day`, `capture.eve`, `nearby`
     *                                      (16 numbers each) and `rivals.day`, `rivals.eve`
     */
    public function setVectors(string $id, string $orgId, array $vectors): void
    {
        foreach (array_keys($vectors) as $key) {
            if (!in_array($key, self::VECTOR_KEYS, true)) {
                throw new \LogicException('tp_spots: not part of a vector write: ' . $key);
            }
        }
        if (($vectors['vectors'] ?? null) === null) {
            throw new \LogicException('tp_spots: a vector write needs the vectors');
        }
        $this->write($id, $orgId, $vectors);
    }

    /** Stamps `archived_at`. The row stays. A spot that is archived already keeps its first stamp. */
    public function archive(string $id, string $orgId): void
    {
        $this->db()->query(
            'UPDATE tp_spots SET archived_at = NOW() WHERE id = ? AND organization_id = ? AND archived_at IS NULL',
            [$id, $orgId]
        );
    }

    /**
     * The ids of the truck's spots (archived ones left out) whose vectors are missing or were computed for
     * another region, dataset version or seeds revision than the ones given. Ascending id.
     *
     * @param string|null $version the active dataset version of the region, null when it has none
     * @return list<string>
     */
    public function staleIds(
        string $orgId,
        string $truckId,
        string $regionId,
        ?string $version,
        int $seedsRev,
        int $limit
    ): array {
        $rows = $this->db()->fetchAll(
            'SELECT id
               FROM tp_spots
              WHERE organization_id = ? AND truck_id = ? AND archived_at IS NULL
                AND (vectors_bin IS NULL
                     OR NOT (vec_region_id <=> ? AND vec_dataset <=> ? AND vec_seeds_rev <=> ?))
              ORDER BY id
              LIMIT ?',
            [$orgId, $truckId, $regionId, $version, $seedsRev, max(0, $limit)]
        );
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (string) $row['id'];
        }
        return $ids;
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function write(string $id, string $orgId, array $columns): void
    {
        self::checkKeys($columns);
        $sets = [];
        $params = [];
        foreach ($columns as $key => $value) {
            [$column, $kind] = self::COLUMNS[$key];
            $sets[] = $column . ($kind === self::VECTORS ? ' = UNHEX(?)' : ' = ?');
            $params[] = self::encode($key, $kind, $value);
        }
        if ($sets === []) {
            return;
        }
        if (array_key_exists('vectors', $columns)) {
            $sets[] = $columns['vectors'] === null ? 'vec_at = NULL' : 'vec_at = NOW()';
        }
        $params[] = $id;
        $params[] = $orgId;
        $this->db()->query(
            'UPDATE tp_spots SET ' . implode(', ', $sets) . ' WHERE id = ? AND organization_id = ?',
            $params
        );
    }

    /**
     * Refuses a key that is not a settable column, and a vector write that is not whole.
     *
     * @param array<string, mixed> $columns
     */
    private static function checkKeys(array $columns): void
    {
        $vectorKeys = 0;
        foreach (array_keys($columns) as $key) {
            if (!is_string($key) || !isset(self::COLUMNS[$key])) {
                throw new \LogicException('tp_spots: not a column that can be set: ' . $key);
            }
            if (in_array($key, self::VECTOR_KEYS, true)) {
                $vectorKeys++;
            }
        }
        if ($vectorKeys !== 0 && $vectorKeys !== count(self::VECTOR_KEYS)) {
            throw new \LogicException('tp_spots: vectors and their labels are written together');
        }
    }

    private static function columnList(): string
    {
        $names = ['id', 'organization_id', 'truck_id', 'created_by'];
        foreach (self::COLUMNS as [$column]) {
            $names[] = $column;
        }
        array_push($names, 'vec_at', 'archived_at', 'created_at', 'updated_at');
        return implode(', ', $names);
    }

    /** A row value as it is bound into SQL. */
    private static function encode(string $key, string $kind, mixed $value): mixed
    {
        $nullable = in_array(
            $kind,
            [self::NULLABLE_STRING, self::NULLABLE_FLOAT, self::NULLABLE_INT, self::NULLABLE_OBJECT, self::VECTORS],
            true
        );
        if ($value === null) {
            if (!$nullable) {
                throw new \LogicException('tp_spots: ' . $key . ' cannot be null');
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
                return Money::toCents((float) $value);
            case self::NULLABLE_OBJECT:
                return Sql::json((array) $value, true);
            case self::VECTORS:
                return self::vectorHex((array) $value);
        }
        throw new \LogicException('tp_spots: unknown column kind');
    }

    /**
     * The three blocks as the hexadecimal text of 150 little-endian doubles, for `UNHEX(?)`.
     *
     * @param array<string, mixed> $vectors {hidden, normal, prominent}
     */
    private static function vectorHex(array $vectors): string
    {
        $doubles = [];
        foreach (self::BLOCKS as $level) {
            if (!is_array($vectors[$level] ?? null)) {
                throw new \LogicException('tp_spots: no vectors for visibility ' . $level);
            }
            foreach (VectorCodec::flat($vectors[$level]) as $x) {
                $doubles[] = $x;
            }
        }
        return VectorCodec::toHex($doubles);
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
        $bytes = null;
        foreach (self::COLUMNS as $key => [$column, $kind]) {
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
                case self::NULLABLE_OBJECT:
                    $out[$key] = self::allowed($value);
                    break;
                case self::VECTORS:
                    $bytes = is_string($value) && strlen($value) === count(self::BLOCKS) * VectorCodec::BLOCK_BYTES
                        ? $value
                        : null;
                    $out[$key] = $bytes === null ? null : self::blocks($bytes);
                    break;
            }
        }
        $out['vectors_sha1'] = $bytes === null ? null : sha1($bytes);
        $out['vec_at'] = $row['vec_at'] === null ? null : (string) $row['vec_at'];
        $out['archived_at'] = $row['archived_at'] === null ? null : (string) $row['archived_at'];
        $out['created_at'] = (string) $row['created_at'];
        $out['updated_at'] = (string) $row['updated_at'];
        return $out;
    }

    /**
     * The stored bytes as {hidden, normal, prominent}, each the stored part of a LocationVectors.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function blocks(string $bytes): array
    {
        $doubles = VectorCodec::fromBytes($bytes);
        $out = [];
        foreach (self::BLOCKS as $i => $level) {
            $out[$level] = VectorCodec::fromFlat(
                array_slice($doubles, $i * VectorCodec::BLOCK_DOUBLES, VectorCodec::BLOCK_DOUBLES)
            );
        }
        return $out;
    }

    /**
     * `allowed_json` as {days: [7 bool], open_minute, close_minute}, in that key order whatever order the
     * JSON column hands back. Null for SQL NULL and for anything that is not such an object.
     *
     * @return array{days: list<bool>, open_minute: int, close_minute: int}|null
     */
    private static function allowed(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !is_array($decoded['days'] ?? null) || count($decoded['days']) !== 7
            || !isset($decoded['open_minute'], $decoded['close_minute'])) {
            return null;
        }
        $days = [];
        foreach (array_values($decoded['days']) as $day) {
            $days[] = (bool) $day;
        }
        return [
            'days' => $days,
            'open_minute' => (int) $decoded['open_minute'],
            'close_minute' => (int) $decoded['close_minute'],
        ];
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }
}
