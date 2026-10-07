<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;
use App\TruckPlanner\Services\Support\Money;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * An organization's truck data as a whole (04_BACKEND.md 4.16): read page by page for the export, and
 * deleted in one transaction. It also holds the few reads the two operator scripts make across
 * organizations (7.2).
 *
 * The export never holds a table in memory: page() reads one table by ascending id, a page at a time
 * (keyset on `id`, so a page costs the same wherever it starts). Rows come back normalised like the rows of
 * the other repositories: keys without the storage suffix and values in API units (dollars for `*_cents`,
 * booleans for 0/1, decoded JSON, floats cast).
 *
 * What page() does not read is as much part of the contract as what it reads. No statement here selects
 * Google content: not the context of a plan (its drive legs), and not the stored vectors of a spot (they
 * are derived data, recomputed from the region). The result of a plan is read on its own, by planResult(),
 * and only for the three figures of a summary. A lead holds no Google content to begin with: of a contact
 * lookup it keeps Google's id of the place, which may be kept and travels with the lead.
 *
 * Every statement on an owner table carries the organization id, with two exceptions that serve an
 * operator and return no row of any tenant: expiredGoogleCounts() (numbers only) and
 * truckOrganizations() (the ids of the organizations that have a truck).
 */
class TruckDataRepository
{
    /** The tables page() reads. */
    public const PAGE_TABLES = ['tp_spots', 'tp_plans', 'tp_service_logs', 'tp_drive_overrides', 'tp_scout_leads'];

    /**
     * Table => key of its count in the answer of deleteAll(), in the order the rows go: what refers to
     * a row is deleted before that row. There are no foreign keys, so the order is kept here.
     */
    public const DELETE_ORDER = [
        'tp_service_logs' => 'services',
        'tp_plan_stops' => 'plan_stops',
        'tp_plans' => 'plans',
        'tp_scout_leads' => 'leads',
        'tp_drive_overrides' => 'drive_overrides',
        'tp_spots' => 'spots',
        'tp_trucks' => 'trucks',
    ];

    private const SPOT_COLUMNS = 'id, truck_id, name, lat, lng, address, county_fips, notes, visibility,
                    host_segment, host_size, host_size_source, host_only_food, host_place_type,
                    host_name, host_contact, host_phone, host_website, place_key, host_point_id, google_place_id,
                    fee_flat_cents, fee_pct, fee_min_cents, allowed_json, archived_at, created_at, updated_at';

    private const PLAN_COLUMNS = 'id, truck_id, service_date, name, treat_as, notes, plan_state, result_has_google,
                    evaluated_at, model_version, seeds_revision, dataset_version,
                    (result_json IS NOT NULL) AS has_result,
                    (result_has_google = 1 AND evaluated_at < NOW() - INTERVAL ? DAY) AS snapshot_expired,
                    created_at, updated_at';

    private const STOP_COLUMNS = 'id, plan_id, seq, stop_kind, spot_id, label, lat, lng, address,
                    open_minute, close_minute, gap_before_unpaid, setup_minutes, teardown_minutes,
                    fee_flat_cents, fee_pct, fee_min_cents, ev_attendance, ev_vendor_count, ev_type,
                    cat_headcount, cat_price_head_cents, cat_guarantee_cents, cat_food_cost_cents';

    private const LOG_COLUMNS = 'id, truck_id, log_kind, spot_id, plan_id, plan_stop_id, service_date,
                    open_minute, close_minute, actual_orders, sales_cents, sold_out, notes, src, external_key, treat_as,
                    predicted_raw, predicted, pred_low, pred_high, pred_confidence, pred_basis, prediction_json,
                    pred_model_version, pred_seeds_rev, pred_dataset, created_at, updated_at';

    private const OVERRIDE_COLUMNS = 'id, truck_id, o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4,
                    override_minutes, toll_cents, note, created_at, updated_at';

    private const LEAD_COLUMNS = 'id, truck_id, region_id, place_key, place_name, place_type, lat, lng,
                    lead_state, notes, spot_id, google_place_id, created_at, updated_at';

    private const IDS_PER_STATEMENT = 200;

    private ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------------------------ export

    /**
     * One page of an organization's rows of one table, in ascending id.
     *
     * @param string $table one of PAGE_TABLES
     * @param string $afterId the id of the last row of the page before, '' for the first page
     * @return list<array<string, mixed>> at most `$limit` normalised rows, each with its `id`. A page
     *         shorter than `$limit` is the last one
     */
    public function page(string $table, string $orgId, string $afterId, int $limit): array
    {
        $params = [$orgId, $afterId, max(1, $limit)];
        switch ($table) {
            case 'tp_spots':
                $columns = self::SPOT_COLUMNS;
                break;
            case 'tp_plans':
                $columns = self::PLAN_COLUMNS;
                array_unshift($params, (int) TpConfig::get('plans.snapshot_ttl_days'));
                break;
            case 'tp_service_logs':
                $columns = self::LOG_COLUMNS;
                break;
            case 'tp_drive_overrides':
                $columns = self::OVERRIDE_COLUMNS;
                break;
            case 'tp_scout_leads':
                $columns = self::LEAD_COLUMNS;
                break;
            default:
                throw new \LogicException('not a table the export reads: ' . $table);
        }
        $rows = $this->db()->fetchAll(
            'SELECT ' . $columns . '
               FROM ' . $table . '
              WHERE organization_id = ? AND id > ?
              ORDER BY id
              LIMIT ?',
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalise($table, $row);
        }
        return $out;
    }

    /**
     * The stops of some plans of the organization, in visiting order.
     *
     * @param list<string> $planIds
     * @return array<string, list<array<string, mixed>>> plan id => its normalised stop rows by `seq`. A
     *         plan without stops is absent
     */
    public function planStops(string $orgId, array $planIds): array
    {
        $wanted = [];
        foreach ($planIds as $id) {
            $wanted[(string) $id] = true;
        }
        $out = [];
        foreach (array_chunk(array_map('strval', array_keys($wanted)), self::IDS_PER_STATEMENT) as $chunk) {
            $rows = $this->db()->fetchAll(
                'SELECT ' . self::STOP_COLUMNS . '
                   FROM tp_plan_stops
                  WHERE organization_id = ? AND plan_id IN (' . Sql::marks(count($chunk)) . ')
                  ORDER BY plan_id, seq',
                array_merge([$orgId], $chunk)
            );
            foreach ($rows as $row) {
                $out[(string) $row['plan_id']][] = self::stop($row);
            }
        }
        return $out;
    }

    /**
     * The stored result of one plan, decoded. It is JSON text (never parsed by MySQL), so it is read
     * for one plan at a time and only where its figures are wanted.
     *
     * @return array<string, mixed>|null a DayResult, or null when the plan has none (or is not this
     *         organization's)
     */
    public function planResult(string $planId, string $orgId): ?array
    {
        $row = $this->db()->fetch(
            'SELECT result_json FROM tp_plans WHERE id = ? AND organization_id = ?',
            [$planId, $orgId]
        );
        return $row === null ? null : self::decode($row['result_json']);
    }

    /** When a service log of the truck last changed ("YYYY-MM-DD HH:MM:SS"), null without logs. */
    public function lastLogChange(string $orgId, string $truckId): ?string
    {
        $row = $this->db()->fetch(
            'SELECT MAX(updated_at) AS last_change
               FROM tp_service_logs
              WHERE organization_id = ? AND truck_id = ?',
            [$orgId, $truckId]
        );
        return ($row['last_change'] ?? null) === null ? null : (string) $row['last_change'];
    }

    // ------------------------------------------------------------------------------------ deletion

    /**
     * Deletes every row of the organization in the seven owner tables, children first, in one
     * transaction. The account, the organization, the shared reference data and the shared cache of
     * drive legs are not touched.
     *
     * @return array{services: int, plan_stops: int, plans: int, leads: int, drive_overrides: int,
     *               spots: int, trucks: int} the rows that were there, counted inside the transaction
     */
    public function deleteAll(string $orgId): array
    {
        $db = $this->db();
        $deleted = [];
        $db->beginTransaction();
        try {
            foreach (self::DELETE_ORDER as $table => $key) {
                $row = $db->fetch('SELECT COUNT(*) AS row_count FROM ' . $table . ' WHERE organization_id = ?', [$orgId]);
                $deleted[$key] = (int) ($row['row_count'] ?? 0);
                $db->query('DELETE FROM ' . $table . ' WHERE organization_id = ?', [$orgId]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        return $deleted;
    }

    // ------------------------------------------------------------------------------------ operator reads

    /**
     * How much Google content is past its lifetime right now, over every organization: what the daily
     * sweep would remove. Numbers only.
     *
     * @return array{drive_legs: int, plan_snapshots: int} cached legs older than their lifetime, and
     *         plan results that used Google legs and are older than theirs
     */
    public function expiredGoogleCounts(): array
    {
        $row = $this->db()->fetch(
            'SELECT (SELECT COUNT(*) FROM tp_drive_legs
                      WHERE fetched_at < NOW() - INTERVAL ? DAY) AS drive_legs,
                    (SELECT COUNT(*) FROM tp_plans
                      WHERE result_has_google = 1 AND evaluated_at < NOW() - INTERVAL ? DAY) AS plan_snapshots',
            [
                (int) TpConfig::get('routing.leg_ttl_days'),
                (int) TpConfig::get('plans.snapshot_ttl_days'),
            ]
        );
        return [
            'drive_legs' => (int) ($row['drive_legs'] ?? 0),
            'plan_snapshots' => (int) ($row['plan_snapshots'] ?? 0),
        ];
    }

    /**
     * The organizations that have a truck, in ascending id, a page at a time (keyset): where the
     * refresh script finds its work.
     *
     * @param string|null $regionId only trucks of this region; null for every region
     * @param string $afterOrgId the last id of the page before, '' for the first page
     * @return list<string> organization ids
     */
    public function truckOrganizations(?string $regionId, string $afterOrgId, int $limit): array
    {
        $rows = $this->db()->fetchAll(
            'SELECT organization_id
               FROM tp_trucks
              WHERE organization_id > ?' . ($regionId === null ? '' : ' AND region_id = ?') . '
              ORDER BY organization_id
              LIMIT ?',
            $regionId === null ? [$afterOrgId, max(1, $limit)] : [$afterOrgId, $regionId, max(1, $limit)]
        );
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (string) $row['organization_id'];
        }
        return $ids;
    }

    // ------------------------------------------------------------------------------------ rows

    /**
     * @param array<string, mixed> $row a database row
     * @return array<string, mixed>
     */
    private static function normalise(string $table, array $row): array
    {
        switch ($table) {
            case 'tp_spots':
                return self::spot($row);
            case 'tp_plans':
                return self::plan($row);
            case 'tp_service_logs':
                return self::log($row);
            case 'tp_drive_overrides':
                return self::override($row);
        }
        return self::lead($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed> the spot row of SpotRepository without its vectors
     */
    private static function spot(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'truck_id' => (string) $row['truck_id'],
            'name' => (string) $row['name'],
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lng'],
            'address' => (string) $row['address'],
            'county_fips' => self::text($row['county_fips']),
            'notes' => self::text($row['notes']),
            'visibility' => (string) $row['visibility'],
            'host_segment' => self::text($row['host_segment']),
            'host_size' => self::number($row['host_size']),
            'host_size_source' => self::text($row['host_size_source']),
            'host_only_food' => (int) $row['host_only_food'] === 1,
            'host_place_type' => self::text($row['host_place_type']),
            'host_name' => self::text($row['host_name']),
            'host_contact' => self::text($row['host_contact']),
            'host_phone' => self::text($row['host_phone']),
            'host_website' => self::text($row['host_website']),
            'place_key' => self::text($row['place_key']),
            'host_point_id' => self::text($row['host_point_id']),
            'google_place_id' => self::text($row['google_place_id']),
            'fee_flat' => Money::fromCents((int) $row['fee_flat_cents']),
            'fee_pct' => (float) $row['fee_pct'],
            'fee_min' => Money::fromCents((int) $row['fee_min_cents']),
            'allowed' => self::allowed($row['allowed_json']),
            'archived_at' => self::text($row['archived_at']),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function plan(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'truck_id' => (string) $row['truck_id'],
            'service_date' => (string) $row['service_date'],
            'name' => (string) $row['name'],
            'treat_as' => self::text($row['treat_as']),
            'notes' => self::text($row['notes']),
            'plan_state' => (string) $row['plan_state'],
            'result_has_google' => (int) $row['result_has_google'] === 1,
            'evaluated_at' => self::text($row['evaluated_at']),
            'model_version' => self::text($row['model_version']),
            'seeds_revision' => self::whole($row['seeds_revision']),
            'dataset_version' => self::text($row['dataset_version']),
            'has_result' => (int) $row['has_result'] === 1,
            'snapshot_expired' => (int) ($row['snapshot_expired'] ?? 0) === 1,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function stop(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'plan_id' => (string) $row['plan_id'],
            'seq' => (int) $row['seq'],
            'stop_kind' => (string) $row['stop_kind'],
            'spot_id' => self::text($row['spot_id']),
            'label' => (string) $row['label'],
            'lat' => self::number($row['lat']),
            'lng' => self::number($row['lng']),
            'address' => (string) $row['address'],
            'open_minute' => (int) $row['open_minute'],
            'close_minute' => (int) $row['close_minute'],
            'gap_before_unpaid' => (int) $row['gap_before_unpaid'] === 1,
            'setup_minutes' => self::whole($row['setup_minutes']),
            'teardown_minutes' => self::whole($row['teardown_minutes']),
            'fee_flat' => Money::fromCents((int) $row['fee_flat_cents']),
            'fee_pct' => (float) $row['fee_pct'],
            'fee_min' => Money::fromCents((int) $row['fee_min_cents']),
            'ev_attendance' => self::number($row['ev_attendance']),
            'ev_vendor_count' => self::whole($row['ev_vendor_count']),
            'ev_type' => self::text($row['ev_type']),
            'cat_headcount' => self::number($row['cat_headcount']),
            'cat_price_head' => self::dollars($row['cat_price_head_cents']),
            'cat_guarantee' => self::dollars($row['cat_guarantee_cents']),
            'cat_food_cost' => self::dollars($row['cat_food_cost_cents']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed> `prediction` is the decoded `prediction_json`
     */
    private static function log(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'truck_id' => (string) $row['truck_id'],
            'log_kind' => (string) $row['log_kind'],
            'spot_id' => self::text($row['spot_id']),
            'plan_id' => self::text($row['plan_id']),
            'plan_stop_id' => self::text($row['plan_stop_id']),
            'service_date' => (string) $row['service_date'],
            'open_minute' => (int) $row['open_minute'],
            'close_minute' => (int) $row['close_minute'],
            'actual_orders' => (int) $row['actual_orders'],
            'sales' => self::dollars($row['sales_cents']),
            'sold_out' => (int) $row['sold_out'] === 1,
            'notes' => self::text($row['notes']),
            'src' => (string) $row['src'],
            'external_key' => self::text($row['external_key']),
            'treat_as' => self::text($row['treat_as']),
            'predicted_raw' => self::number($row['predicted_raw']),
            'predicted' => self::number($row['predicted']),
            'pred_low' => self::number($row['pred_low']),
            'pred_high' => self::number($row['pred_high']),
            'pred_confidence' => self::text($row['pred_confidence']),
            'pred_basis' => self::text($row['pred_basis']),
            'prediction' => self::decode($row['prediction_json']),
            'pred_model_version' => self::text($row['pred_model_version']),
            'pred_seeds_rev' => self::whole($row['pred_seeds_rev']),
            'pred_dataset' => self::text($row['pred_dataset']),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function override(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'truck_id' => (string) $row['truck_id'],
            'o_lat_e4' => (int) $row['o_lat_e4'],
            'o_lng_e4' => (int) $row['o_lng_e4'],
            'd_lat_e4' => (int) $row['d_lat_e4'],
            'd_lng_e4' => (int) $row['d_lng_e4'],
            'override_minutes' => self::whole($row['override_minutes']),
            'toll' => self::dollars($row['toll_cents']),
            'note' => (string) $row['note'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function lead(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'truck_id' => (string) $row['truck_id'],
            'region_id' => (string) $row['region_id'],
            'place_key' => (string) $row['place_key'],
            'place_name' => self::text($row['place_name']),
            'place_type' => self::text($row['place_type']),
            'lat' => self::number($row['lat']),
            'lng' => self::number($row['lng']),
            'lead_state' => (string) $row['lead_state'],
            'notes' => self::text($row['notes']),
            'spot_id' => self::text($row['spot_id']),
            'google_place_id' => self::text($row['google_place_id']),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function whole(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /** Whole cents to dollars, SQL NULL to null. */
    private static function dollars(mixed $cents): ?float
    {
        return $cents === null ? null : Money::fromCents((int) $cents);
    }

    /**
     * JSON text as an array, null for SQL NULL and for text that is not a JSON object or list.
     *
     * @return array<int|string, mixed>|null
     */
    private static function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * `allowed_json` as {days: [7 bool], open_minute, close_minute}, or null.
     *
     * @return array{days: list<bool>, open_minute: int, close_minute: int}|null
     */
    private static function allowed(mixed $json): ?array
    {
        $decoded = self::decode($json);
        if ($decoded === null || !is_array($decoded['days'] ?? null) || count($decoded['days']) !== 7
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
