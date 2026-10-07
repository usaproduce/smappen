<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\CountsRepository;
use App\TruckPlanner\Data\LegKey;
use App\TruckPlanner\Data\TruckDataRepository;
use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\JsonSafe;
use App\TruckPlanner\Services\Support\MapsUrl;
use App\TruckPlanner\Services\Support\Redactor;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;

/**
 * The owner's data as one JSON document (04_BACKEND.md 4.16): the truck, its assumptions, spots, plans,
 * logged services, drive-time corrections and Scout notes.
 *
 *     { "export": "truck-planner", "export_version": 1, "exported_at": "...", "model_version": "...",
 *       "seeds_revision": 1, "truck": {...}, "overrides": {...}, "spots": [...], "plans": [...],
 *       "services": [...], "drive_overrides": [...], "scout_leads": [...], "attribution": [...],
 *       "incomplete": false }
 *
 * The document is written straight to the client, a page of rows at a time. It is never kept on the
 * server: the house download folder hands a file to any signed-in user who knows its name.
 *
 * It holds what the owner entered and what was computed from it for display. It holds nothing fetched from
 * Google: no drive leg, no result or context of a plan (only the three figures of a result that is still
 * current). Looked-up contact details are stored nowhere, so there are none to leave out. A Google place
 * id may travel, as the terms allow.
 *
 * Everything that can fail before the first byte fails as an ordinary error. After the first byte the
 * document is always closed as valid JSON: when a read fails half-way, the list that was being written is
 * closed and the document ends with "incomplete": true.
 */
class ExportService
{
    public const NAME = 'truck-planner';
    public const VERSION = 1;

    private const NO_TRUCK = 'Set up your truck first';
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    private const OSM_SENTENCE = 2;           // the line of 03_DATA.md section 14 an export header carries

    private TruckDataRepository $data;
    private TruckRepository $trucks;
    private CountsRepository $counts;
    private SpotService $spots;
    private RegionService $regions;
    private ProfileMapper $mapper;
    private Clock $clock;

    /** @var callable(string, bool): void */
    private $sink;

    /**
     * @param callable(string, bool): void|null $sink receives each piece of the document in order; the
     *        flag asks for it to be sent on now. By default the pieces are echoed and flushed
     */
    public function __construct(
        ?TruckDataRepository $data = null,
        ?TruckRepository $trucks = null,
        ?CountsRepository $counts = null,
        ?SpotService $spots = null,
        ?RegionService $regions = null,
        ?Clock $clock = null,
        ?callable $sink = null
    ) {
        $this->data = $data ?? new TruckDataRepository();
        $this->trucks = $trucks ?? new TruckRepository();
        $this->counts = $counts ?? new CountsRepository();
        $this->regions = $regions ?? new RegionService();
        $this->spots = $spots ?? new SpotService(null, $this->counts, $this->regions);
        $this->mapper = new ProfileMapper();
        $this->clock = $clock ?? new Clock();
        $this->sink = $sink ?? static function (string $piece, bool $send): void {
            echo $piece;
            if ($send) {
                flush();
            }
        };
    }

    /**
     * Writes the organization's export.
     *
     * @param callable(string): void|null $begin called once, with the file name of the download, after
     *        everything that is read up front has been read and right before the first byte is written:
     *        the place to send the response headers. Nothing thrown after it reaches the caller
     * @throws TpConflict when the organization has no truck
     */
    public function stream(string $orgId, ?callable $begin = null): void
    {
        // ---- read up front: a failure here is still an ordinary error answer
        $row = $this->trucks->findByOrg($orgId);
        if ($row === null) {
            throw new TpConflict(self::NO_TRUCK);
        }
        $record = $this->mapper->toRecord($row);
        $truckId = (string) $row['id'];
        $active = $this->regions->active((string) $record['profile']['region_id']);
        $current = [
            'model_version' => Estimator::MODEL_VERSION,
            'seeds_revision' => Seeds::revision(),
            'dataset_version' => $active === null ? null : (string) $active['dataset_version'],
            'truck_changed' => (string) $row['updated_at'],
            'log_changed' => $this->data->lastLogChange($orgId, $truckId),
        ];
        $logs = $this->counts->logsBySpot($orgId, $truckId);
        $head = self::json(JsonSafe::clean([
            'export' => self::NAME,
            'export_version' => self::VERSION,
            'exported_at' => $this->clock->nowUtc()->format('Y-m-d\TH:i:s\Z'),
            'model_version' => $current['model_version'],
            'seeds_revision' => $current['seeds_revision'],
            'truck' => $record,
            'overrides' => is_array($row['overrides'] ?? null) ? $row['overrides'] : [],
        ], ['overrides']));
        $tail = ',"attribution":' . self::json([SourcesService::TEXTS[self::OSM_SENTENCE]]);
        $base = $record['profile']['base'];
        $pageRows = max(1, (int) TpConfig::get('requests.export_page_rows'));

        if ($begin !== null) {
            $begin($this->filename($row));
        }

        // ---- the document: from here on it is always closed as valid JSON
        $this->write(substr($head, 0, -1));
        $open = false;
        try {
            /** @var array<string, array{lat: float, lng: float, updated_at: string}> $spotIndex */
            $spotIndex = [];
            $this->writeList('spots', 'tp_spots', $orgId, $pageRows, $open, function (array $rows) use ($logs, &$spotIndex): array {
                $items = [];
                foreach ($rows as $spot) {
                    $spotIndex[$spot['id']] = ['lat' => $spot['lat'], 'lng' => $spot['lng'], 'updated_at' => $spot['updated_at']];
                    $items[] = $this->spot($spot, $logs[$spot['id']] ?? null);
                }
                return $items;
            });
            $this->writeList('plans', 'tp_plans', $orgId, $pageRows, $open, function (array $rows) use ($orgId, $current, $base, &$spotIndex): array {
                $stops = $rows === [] ? [] : $this->data->planStops($orgId, array_column($rows, 'id'));
                $items = [];
                foreach ($rows as $plan) {
                    $items[] = $this->plan($orgId, $plan, $stops[$plan['id']] ?? [], $current, $spotIndex, $base);
                }
                return $items;
            });
            $this->writeList('services', 'tp_service_logs', $orgId, $pageRows, $open, static function (array $rows): array {
                return array_map(static fn (array $row): array => self::service($row), $rows);
            }, ['prediction.detail']);
            $this->writeList('drive_overrides', 'tp_drive_overrides', $orgId, $pageRows, $open, static function (array $rows): array {
                return array_map(static fn (array $row): array => self::correction($row), $rows);
            });
            $this->writeList('scout_leads', 'tp_scout_leads', $orgId, $pageRows, $open, static function (array $rows): array {
                return array_map(static fn (array $row): array => self::lead($row), $rows);
            });
            $this->write($tail . ',"incomplete":false}', true);
        } catch (\Throwable $e) {
            error_log('[tp] export ended early: ' . get_class($e) . ': ' . Redactor::text($e->getMessage()));
            $this->write(($open ? ']' : '') . ',"incomplete":true}', true);
        }
    }

    /**
     * "truck-planner-export-YYYYMMDD.json", the date being today where the truck is.
     *
     * @param array<string, mixed> $truck a truck row or the truck value (its `timezone` is read)
     */
    public function filename(array $truck): string
    {
        $zone = (string) ($truck['timezone'] ?? '');
        if (!Clock::isZone($zone)) {
            $zone = (string) TpConfig::get('regions.default_timezone');
        }
        return 'truck-planner-export-' . str_replace('-', '', $this->clock->today($zone)) . '.json';
    }

    // ------------------------------------------------------------------------------------ writing

    /**
     * One list of the document: `,"<name>":[` then the rows of a table, page after page, then `]`. Each
     * page is shaped and encoded whole before any of it is written, and is sent on at once.
     *
     * @param bool $open set while the list stands open, so that a failure can close it
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $shape the items of a page
     *        of normalised rows
     * @param list<string> $mapPaths the maps inside one item (JsonSafe::clean)
     */
    private function writeList(
        string $name,
        string $table,
        string $orgId,
        int $pageRows,
        bool &$open,
        callable $shape,
        array $mapPaths = []
    ): void {
        $this->write(',' . self::json($name) . ':[');
        $open = true;
        $first = true;
        $after = '';
        do {
            $rows = $this->data->page($table, $orgId, $after, $pageRows);
            $piece = '';
            foreach ($shape($rows) as $item) {
                $piece .= ($first ? '' : ',') . self::json(JsonSafe::clean($item, $mapPaths));
                $first = false;
            }
            if ($rows !== []) {
                $after = (string) $rows[count($rows) - 1]['id'];
                $this->write($piece, true);
            }
        } while (count($rows) === $pageRows);
        $open = false;
        $this->write(']');
    }

    private function write(string $piece, bool $send = false): void
    {
        ($this->sink)($piece, $send);
    }

    private static function json(mixed $value): string
    {
        JsonSafe::shortestFloats();
        return json_encode($value, self::JSON_FLAGS);
    }

    // ------------------------------------------------------------------------------------ shapes

    /**
     * The API's Spot without its vectors (and so without `vectors_state`, which describes them).
     *
     * @param array<string, mixed> $spot a row of TruckDataRepository::page('tp_spots', ...)
     * @param array{count: int, last_date: ?string}|null $logs
     * @return array<string, mixed>
     */
    private function spot(array $spot, ?array $logs): array
    {
        $details = [
            'place_type' => $spot['host_place_type'],
            'name' => $spot['host_name'],
            'contact' => $spot['host_contact'],
            'phone' => $spot['host_phone'],
            'website' => $spot['host_website'],
            'place_key' => $spot['place_key'],
            'google_place_id' => $spot['google_place_id'],
        ];
        $any = false;
        foreach ($details as $detail) {
            $any = $any || $detail !== null;
        }
        return [
            'id' => $spot['id'],
            'name' => $spot['name'],
            'point' => ['lat' => $spot['lat'], 'lng' => $spot['lng']],
            'address' => $spot['address'],
            'county_fips' => $spot['county_fips'],
            'notes' => $spot['notes'],
            'terms' => $this->spots->terms($spot),
            'host_details' => $any ? $details : null,
            'logs' => $logs ?? ['count' => 0, 'last_date' => null],
            'maps_url' => MapsUrl::point($spot['lat'], $spot['lng']),
            'archived' => $spot['archived_at'] !== null,
            'created_at' => $spot['created_at'],
            'updated_at' => $spot['updated_at'],
        ];
    }

    /**
     * The API's Plan without `result` and `context`, with the `summary` of a result that is still current.
     *
     * @param array<string, mixed> $plan a row of TruckDataRepository::page('tp_plans', ...)
     * @param list<array<string, mixed>> $stops its rows of TruckDataRepository::planStops()
     * @param array<string, mixed> $current what a result must match to be current
     * @param array<string, array{lat: float, lng: float, updated_at: string}> $spotIndex every spot written so far
     * @param array<string, mixed> $base the truck's base point
     * @return array<string, mixed>
     */
    private function plan(string $orgId, array $plan, array $stops, array $current, array $spotIndex, array $base): array
    {
        $state = self::resultState($plan, $stops, $current, $spotIndex);
        $summary = null;
        if ($state === 'fresh') {
            $totals = $this->data->planResult((string) $plan['id'], $orgId)['totals'] ?? null;
            if (is_array($totals) && is_array($totals['orders'] ?? null) && is_array($totals['take_home'] ?? null)
                && (is_int($totals['day_hours'] ?? null) || is_float($totals['day_hours'] ?? null))) {
                $summary = [
                    'orders' => $totals['orders'],
                    'take_home' => $totals['take_home'],
                    'day_hours' => (float) $totals['day_hours'],
                ];
            }
        }

        $items = [];
        $route = [];
        foreach ($stops as $stop) {
            $items[] = self::stop($stop);
            if ($stop['stop_kind'] === 'spot') {
                $spot = $stop['spot_id'] === null ? null : ($spotIndex[$stop['spot_id']] ?? null);
                if ($spot !== null) {
                    $route[] = ['lat' => $spot['lat'], 'lng' => $spot['lng']];
                }
            } elseif ($stop['lat'] !== null && $stop['lng'] !== null) {
                $route[] = ['lat' => $stop['lat'], 'lng' => $stop['lng']];
            }
        }
        $home = ['lat' => (float) $base['lat'], 'lng' => (float) $base['lng']];

        return [
            'id' => $plan['id'],
            'date' => $plan['service_date'],
            'name' => $plan['name'],
            'treat_as' => $plan['treat_as'],
            'notes' => $plan['notes'],
            'status' => $plan['plan_state'],
            'stops' => $items,
            'result_state' => $state,
            'evaluated_at' => $plan['evaluated_at'],
            'summary' => $summary,
            'maps_route_url' => $route === [] ? null : MapsUrl::route(array_merge([$home], $route, [$home])),
            'created_at' => $plan['created_at'],
            'updated_at' => $plan['updated_at'],
        ];
    }

    /**
     * The state of a plan's stored result, by the rule of 04_BACKEND.md 5.8: `none` without one; `expired`
     * when it used Google legs and is past their lifetime; `stale` when the model, the seeds or the region
     * data moved on, or the truck, a spot of the plan or any logged service changed after it was computed;
     * else `fresh`. Row time stamps and `evaluated_at` are all written by the database clock in one form,
     * so they compare as text.
     *
     * @param array<string, mixed> $plan
     * @param list<array<string, mixed>> $stops
     * @param array<string, mixed> $current
     * @param array<string, array{lat: float, lng: float, updated_at: string}> $spotIndex
     */
    private static function resultState(array $plan, array $stops, array $current, array $spotIndex): string
    {
        $at = $plan['evaluated_at'];
        if (!$plan['has_result'] || $at === null) {
            return 'none';
        }
        if ($plan['snapshot_expired']) {
            return 'expired';
        }
        if ($plan['model_version'] !== $current['model_version']
            || $plan['seeds_revision'] !== $current['seeds_revision']
            || $plan['dataset_version'] !== $current['dataset_version']
            || strcmp($current['truck_changed'], $at) > 0
            || ($current['log_changed'] !== null && strcmp($current['log_changed'], $at) > 0)) {
            return 'stale';
        }
        foreach ($stops as $stop) {
            $spot = $stop['spot_id'] === null ? null : ($spotIndex[$stop['spot_id']] ?? null);
            if ($spot !== null && strcmp($spot['updated_at'], $at) > 0) {
                return 'stale';
            }
        }
        return 'fresh';
    }

    /**
     * The API's PlanStop.
     *
     * @param array<string, mixed> $stop
     * @return array<string, mixed>
     */
    private static function stop(array $stop): array
    {
        $kind = (string) $stop['stop_kind'];
        return [
            'id' => $stop['id'],
            'kind' => $kind,
            'spot_id' => $stop['spot_id'],
            'label' => $stop['label'],
            'point' => $stop['lat'] === null || $stop['lng'] === null ? null : ['lat' => $stop['lat'], 'lng' => $stop['lng']],
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
     * The API's ServiceLog. `prediction` is null for a log that was stored without one.
     *
     * @param array<string, mixed> $log a row of TruckDataRepository::page('tp_service_logs', ...)
     * @return array<string, mixed>
     */
    private static function service(array $log): array
    {
        $prediction = null;
        if ($log['predicted'] !== null) {
            $prediction = [
                'predicted_raw' => $log['predicted_raw'],
                'predicted' => $log['predicted'],
                'low' => $log['pred_low'],
                'high' => $log['pred_high'],
                'confidence' => $log['pred_confidence'],
                'basis' => $log['pred_basis'],
                'model_version' => $log['pred_model_version'],
                'seeds_revision' => $log['pred_seeds_rev'],
                'dataset_version' => $log['pred_dataset'],
                'detail' => is_array($log['prediction']) ? $log['prediction'] : [],
            ];
        }
        return [
            'id' => $log['id'],
            'kind' => $log['log_kind'],
            'spot_id' => $log['spot_id'],
            'plan_id' => $log['plan_id'],
            'plan_stop_id' => $log['plan_stop_id'],
            'date' => $log['service_date'],
            'open_minute' => $log['open_minute'],
            'close_minute' => $log['close_minute'],
            'actual' => $log['actual_orders'],
            'sales' => $log['sales'],
            'sold_out' => $log['sold_out'],
            'notes' => $log['notes'],
            'source' => $log['src'],
            'external_key' => $log['external_key'],
            'treat_as' => $log['treat_as'],
            'prediction' => $prediction,
            'created_at' => $log['created_at'],
            'updated_at' => $log['updated_at'],
        ];
    }

    /**
     * An owner's correction of one directed drive, as the corrections list of 4.10 shows it. The two
     * points are the rounded keys the correction is stored under.
     *
     * @param array<string, mixed> $row a row of TruckDataRepository::page('tp_drive_overrides', ...)
     * @return array<string, mixed>
     */
    private static function correction(array $row): array
    {
        return [
            'id' => $row['id'],
            'from' => ['lat' => LegKey::deg((int) $row['o_lat_e4']), 'lng' => LegKey::deg((int) $row['o_lng_e4'])],
            'to' => ['lat' => LegKey::deg((int) $row['d_lat_e4']), 'lng' => LegKey::deg((int) $row['d_lng_e4'])],
            'minutes' => $row['override_minutes'],
            'toll' => $row['toll'],
            'note' => $row['note'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * A Scout lead: the place as it was noted, what the owner wrote about it, and Google's id of the place
     * when a contact lookup matched one. Nothing else of a lookup exists on the server.
     *
     * @param array<string, mixed> $row a row of TruckDataRepository::page('tp_scout_leads', ...)
     * @return array<string, mixed>
     */
    private static function lead(array $row): array
    {
        return [
            'place_key' => $row['place_key'],
            'place_name' => $row['place_name'],
            'place_type' => $row['place_type'],
            'lat' => $row['lat'],
            'lng' => $row['lng'],
            'status' => $row['lead_state'],
            'notes' => $row['notes'],
            'spot_id' => $row['spot_id'],
            'google_place_id' => $row['google_place_id'],
        ];
    }
}
