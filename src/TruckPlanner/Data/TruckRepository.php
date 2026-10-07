<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;
use App\TruckPlanner\Services\Support\Money;

/**
 * `tp_trucks`: one truck per organization (04_BACKEND.md 1.2, 1.4).
 *
 * The repository speaks the normalised row. Its keys are the column names without the storage suffix and
 * its values are in API units:
 *
 *     avg_ticket, wage, packaging, card_fee_fixed, fixed_cost_day   dollars (float)  <-> *_cents
 *     fuel_price_override                                           dollars per gallon or null <-> *_milli
 *     licence_counties                                              list of strings  <-> licence_counties_json
 *     overrides                                                     map (seed path => value) <-> overrides_json
 *     tips_include, avoid_tolls, avoid_highways                     bool             <-> 0 / 1
 *     every DOUBLE column                                           float, bound as Sql::f text
 *     every whole-number column                                     int
 *
 * ProfileMapper turns such a row into the API's TruckRecord and a profile back into columns. Every
 * statement carries the organization id. No SQL defaults are relied on: create() writes every column.
 */
class TruckRepository
{
    private const STRING = 's';
    private const NULLABLE_STRING = 'n';
    private const FLOAT = 'f';
    private const INT = 'i';
    private const BOOL = 'b';
    private const CENTS = 'c';
    private const NULLABLE_MILLI = 'm';
    private const JSON_LIST = 'l';

    /**
     * Normalised key => [column, kind], in table order. These are the columns a caller may set.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const COLUMNS = [
        'name' => ['name', self::STRING],
        'region_id' => ['region_id', self::STRING],
        'timezone' => ['timezone', self::STRING],
        'base_lat' => ['base_lat', self::FLOAT],
        'base_lng' => ['base_lng', self::FLOAT],
        'base_address' => ['base_address', self::STRING],
        'base_state' => ['base_state', self::NULLABLE_STRING],
        'base_county_fips' => ['base_county_fips', self::NULLABLE_STRING],
        'avg_ticket' => ['avg_ticket_cents', self::CENTS],
        'capacity_orders_per_hour' => ['capacity_orders_per_hour', self::FLOAT],
        'paid_crew' => ['paid_crew', self::INT],
        'wage' => ['wage_cents', self::CENTS],
        'payroll_burden_pct' => ['payroll_burden_pct', self::FLOAT],
        'food_cost_pct' => ['food_cost_pct', self::FLOAT],
        'packaging' => ['packaging_cents', self::CENTS],
        'card_fee_pct' => ['card_fee_pct', self::FLOAT],
        'card_fee_fixed' => ['card_fee_fixed_cents', self::CENTS],
        'card_share' => ['card_share', self::FLOAT],
        'tips_include' => ['tips_include', self::BOOL],
        'tips_pct' => ['tips_pct', self::FLOAT],
        'mpg' => ['mpg', self::FLOAT],
        'fuel_type' => ['fuel_type', self::STRING],
        'fuel_price_override' => ['fuel_price_override_milli', self::NULLABLE_MILLI],
        'generator_gal_per_hour' => ['generator_gal_per_hour', self::FLOAT],
        'prep_minutes' => ['prep_minutes', self::INT],
        'setup_minutes' => ['setup_minutes', self::INT],
        'teardown_minutes' => ['teardown_minutes', self::INT],
        'closeout_minutes' => ['closeout_minutes', self::INT],
        'fixed_cost_day' => ['fixed_cost_day_cents', self::CENTS],
        'fit_breakfast' => ['fit_breakfast', self::FLOAT],
        'fit_lunch' => ['fit_lunch', self::FLOAT],
        'fit_dinner' => ['fit_dinner', self::FLOAT],
        'fit_late' => ['fit_late', self::FLOAT],
        'avoid_tolls' => ['avoid_tolls', self::BOOL],
        'avoid_highways' => ['avoid_highways', self::BOOL],
        'truck_time_factor' => ['truck_time_factor', self::FLOAT],
        'licence_counties' => ['licence_counties_json', self::JSON_LIST],
        'scout_drive_minutes_limit' => ['scout_drive_minutes_limit', self::INT],
    ];

    private ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * The organization's truck as a normalised row, or null when it has none.
     *
     * @return array<string, mixed>|null the keys of COLUMNS plus id, organization_id, created_by,
     *                                   overrides, overrides_seeds_rev, created_at, updated_at
     */
    public function findByOrg(string $orgId): ?array
    {
        $row = $this->db()->fetch(
            'SELECT id, organization_id, created_by, ' . self::columnList() . ',
                    overrides_json, overrides_seeds_rev, created_at, updated_at
               FROM tp_trucks
              WHERE organization_id = ?',
            [$orgId]
        );
        return $row === null ? null : self::normalise($row);
    }

    /**
     * Inserts the truck of an organization and returns its id.
     *
     * @param array<string, mixed> $row every key of COLUMNS (base_state, base_county_fips and
     *                                  fuel_price_override may be left out and are then NULL); optionally
     *                                  `overrides` (default: none) and `overrides_seeds_rev` (default 1)
     */
    public function create(string $orgId, ?string $userId, array $row): string
    {
        $id = Database::uuid();
        $columns = ['id', 'organization_id', 'created_by'];
        $params = [$id, $orgId, $userId];
        foreach (self::COLUMNS as $key => [$column, $kind]) {
            if (!array_key_exists($key, $row)) {
                if ($kind !== self::NULLABLE_STRING && $kind !== self::NULLABLE_MILLI) {
                    throw new \LogicException('tp_trucks: no value for ' . $key);
                }
                $row[$key] = null;
            }
            $columns[] = $column;
            $params[] = self::encode($kind, $row[$key]);
        }
        $columns[] = 'overrides_json';
        $params[] = Sql::json(is_array($row['overrides'] ?? null) ? $row['overrides'] : [], true);
        $columns[] = 'overrides_seeds_rev';
        $params[] = (int) ($row['overrides_seeds_rev'] ?? 1);

        $this->db()->query(
            'INSERT INTO tp_trucks (' . implode(', ', $columns) . ', created_at, updated_at)
             VALUES (' . Sql::marks(count($params)) . ', NOW(), NOW())',
            $params
        );
        return $id;
    }

    /**
     * Changes the given columns of the organization's truck. `updated_at` moves only when a value really
     * changes (the column's ON UPDATE rule), which is what marks stored plan results as stale.
     *
     * @param array<string, mixed> $columns any subset of the keys of COLUMNS
     */
    public function update(string $id, string $orgId, array $columns): void
    {
        $sets = [];
        $params = [];
        foreach ($columns as $key => $value) {
            if (!isset(self::COLUMNS[$key])) {
                throw new \LogicException('tp_trucks: not a column that can be set: ' . $key);
            }
            [$column, $kind] = self::COLUMNS[$key];
            $sets[] = $column . ' = ?';
            $params[] = self::encode($kind, $value);
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $params[] = $orgId;
        $this->db()->query(
            'UPDATE tp_trucks SET ' . implode(', ', $sets) . ' WHERE id = ? AND organization_id = ?',
            $params
        );
    }

    /**
     * Stores the owner's seed overrides together with the seeds revision they were validated against.
     *
     * @param array<string, mixed> $overrides seed path => value; an empty map is stored as {}
     */
    public function setOverrides(string $id, string $orgId, array $overrides, int $seedsRevision): void
    {
        $this->db()->query(
            'UPDATE tp_trucks SET overrides_json = ?, overrides_seeds_rev = ? WHERE id = ? AND organization_id = ?',
            [Sql::json($overrides, true), $seedsRevision, $id, $orgId]
        );
    }

    /**
     * Stamps the truck as changed now, without changing a column. For a change that plan results depend
     * on and that leaves no time stamp of its own behind: a logged service that was deleted, a drive-time
     * correction that was saved or deleted. Plan results computed before it are stale (04_BACKEND.md 5.8).
     */
    public function touch(string $id, string $orgId): void
    {
        $this->db()->query('UPDATE tp_trucks SET updated_at = NOW() WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    }

    private static function columnList(): string
    {
        $names = [];
        foreach (self::COLUMNS as [$column]) {
            $names[] = $column;
        }
        return implode(', ', $names);
    }

    /** A normalised value as it is bound into SQL. */
    private static function encode(string $kind, mixed $value): mixed
    {
        switch ($kind) {
            case self::STRING:
                return (string) $value;
            case self::NULLABLE_STRING:
                return $value === null ? null : (string) $value;
            case self::FLOAT:
                return Sql::f((float) $value);
            case self::INT:
                return (int) $value;
            case self::BOOL:
                return Sql::b((bool) $value);
            case self::CENTS:
                return Money::toCents((float) $value);
            case self::NULLABLE_MILLI:
                return $value === null ? null : Money::toMilli((float) $value);
            case self::JSON_LIST:
                return Sql::json(is_array($value) ? array_values($value) : []);
        }
        throw new \LogicException('tp_trucks: unknown column kind');
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
            'created_by' => $row['created_by'] === null ? null : (string) $row['created_by'],
        ];
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
                case self::INT:
                    $out[$key] = (int) $value;
                    break;
                case self::BOOL:
                    $out[$key] = (int) $value === 1;
                    break;
                case self::CENTS:
                    $out[$key] = Money::fromCents((int) $value);
                    break;
                case self::NULLABLE_MILLI:
                    $out[$key] = $value === null ? null : Money::fromMilli((int) $value);
                    break;
                case self::JSON_LIST:
                    $decoded = json_decode((string) $value, true);
                    $out[$key] = is_array($decoded) ? array_values($decoded) : [];
                    break;
            }
        }
        $overrides = json_decode((string) $row['overrides_json'], true);
        $out['overrides'] = is_array($overrides) ? $overrides : [];
        $out['overrides_seeds_rev'] = (int) $row['overrides_seeds_rev'];
        $out['created_at'] = (string) $row['created_at'];
        $out['updated_at'] = (string) $row['updated_at'];
        return $out;
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }
}
