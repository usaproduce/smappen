<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\ServiceLogRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\CalibrationProvider;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\JsonSafe;
use App\TruckPlanner\Services\Support\Redactor;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpInvalid;

/**
 * What the owner's logged services say about the model (04_BACKEND.md 5.7; 02_MODEL.md 4.13): the
 * calibration state, the accuracy report, and the raw predictions both are computed from.
 *
 * A logged service is judged against its raw prediction: what the model says for the logged window with
 * no calibration, under the model, seeds and settings of today, with the spot's terms and stored vectors
 * as they are now and the weather and day type that were stored with the log. Each log keeps the hash of
 * everything that number depends on (`pred_raw_basis`). Before the logs are read, every log whose hash is
 * no longer the present one gets its raw prediction computed again. That happens once after the owner
 * changes the truck's capacity, its daypart fit or an assumption, after a spot's host or vectors change
 * and after a new model or seeds revision; at other times it is one query and a hash per log.
 *
 * What the owner was shown when the service was planned or logged (`predicted`, `pred_low`, `pred_high`)
 * is history and is never touched here.
 *
 * Only spot logs that carry a full prediction (all four numbers) count. Event and catering logs, and logs
 * of a spot that had no vectors, are left out of calibration and of the accuracy report.
 *
 * Nothing here is learned by anything other than the sums of Estimator::calibrate.
 *
 * `$truck` is the truck value of TruckBaseController::truck(), `$A` the truck's Assumptions.
 */
class CalibrationService implements CalibrationProvider
{
    private const MINUTES_PER_DAY = 1440;

    private ServiceLogRepository $logs;
    private SpotRepository $spots;
    private SpotService $spotService;
    private Clock $clock;

    public function __construct(
        ?ServiceLogRepository $logs = null,
        ?SpotRepository $spots = null,
        ?SpotService $spotService = null,
        ?Clock $clock = null
    ) {
        $this->logs = $logs ?? new ServiceLogRepository();
        $this->spots = $spots ?? new SpotRepository();
        $this->spotService = $spotService ?? new SpotService($this->spots);
        $this->clock = $clock ?? new Clock();
    }

    // ------------------------------------------------------------------------------------ the contract

    /**
     * The calibration state as of a date, from every logged service that carries a full prediction.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param string $asOf civil date "YYYY-MM-DD": today in the truck's zone
     * @return array<string, mixed> CalibrationState
     */
    public function state(string $orgId, array $truck, array $A, string $asOf): array
    {
        return Estimator::calibrate($A, $this->entries($orgId, $truck, $A), $asOf);
    }

    // ------------------------------------------------------------------------------------ the two answers of 4.14

    /**
     * The answer of GET /calibration.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param string|null $asOf null: today in the truck's zone
     * @return array{calibration: array<string, mixed>, as_of: string, log_count: int, eligible_count: int,
     *               raw_recomputed: int} `log_count` is every logged service of the truck,
     *         `eligible_count` those that carry a full prediction (what calibration reads) and
     *         `raw_recomputed` the raw predictions this call had to compute again
     */
    public function summary(string $orgId, array $truck, array $A, ?string $asOf = null): array
    {
        $asOf ??= $this->clock->today(self::zoneOf($truck));
        $read = $this->read($orgId, $truck, $A);
        $entries = self::entriesOf($read['rows']);
        return [
            'calibration' => Estimator::calibrate($A, $entries, $asOf),
            'as_of' => $asOf,
            'log_count' => count($read['rows']),
            'eligible_count' => count($entries),
            'raw_recomputed' => $read['recomputed'],
        ];
    }

    /**
     * The answer of GET /accuracy: how the estimates did against the logged services dated `$from` to
     * `$to` (both ends count; null leaves that end open).
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @return array{accuracy: array<string, mixed>, entries: list<array<string, mixed>>,
     *               unscored_without_prediction: int} `entries` are the ServiceLogEntry values the report
     *         was computed from, oldest first; the last key counts the logs of the range that carry no
     *         full prediction
     * @throws TpInvalid when `$to` is before `$from`
     */
    public function accuracy(string $orgId, array $truck, array $A, ?string $from, ?string $to): array
    {
        if ($from !== null && $to !== null && strcmp($to, $from) < 0) {
            throw new TpInvalid('to must not be before from', 'to');
        }
        $rows = [];
        foreach ($this->read($orgId, $truck, $A)['rows'] as $row) {
            $date = $row['service_date'];
            if (($from === null || strcmp($date, $from) >= 0) && ($to === null || strcmp($date, $to) <= 0)) {
                $rows[] = $row;
            }
        }
        $entries = self::entriesOf($rows);
        return [
            'accuracy' => Estimator::accuracyReport($entries),
            'entries' => $entries,
            'unscored_without_prediction' => count($rows) - count($entries),
        ];
    }

    // ------------------------------------------------------------------------------------ raw predictions

    /**
     * Computes again the raw prediction of every log whose stored basis is not the present one, and
     * stores it with its new basis.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @return int the number of raw predictions that were computed again
     */
    public function ensureRawPredictions(string $orgId, array $truck, array $A): int
    {
        return $this->read($orgId, $truck, $A)['recomputed'];
    }

    /**
     * The truck's logged services that carry a full prediction, as the model reads them, oldest first.
     * Their raw predictions are the present ones.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @return list<array<string, mixed>> ServiceLogEntry
     */
    public function entries(string $orgId, array $truck, array $A): array
    {
        return self::entriesOf($this->read($orgId, $truck, $A)['rows']);
    }

    /**
     * The raw prediction of a spot for a window, computed from what a log stores: the day type and the
     * weather kept with it, and the spot's terms and stored vectors as they are now. The contexts are
     * built again from those, so that computing the number a second time repeats the first exactly.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @param array<string, mixed> $spot a spot row of SpotRepository
     * @param string|null $treatAs the "treat this day as" value stored with the log
     * @param array<string, mixed>|null $weather {ctx, ctx_next}: the two hourly forecasts stored with the
     *                                           log, or null when it has none
     * @return array{predicted_raw: float, basis: string, terms: array<string, mixed>, vectors: array<string, mixed>,
     *               dataset_version: ?string}|null null for a spot without stored vectors: nothing can
     *         be predicted there. `basis` is the hash to store as `pred_raw_basis`; `terms` and `vectors`
     *         are what the number was computed with
     */
    public function rawPrediction(
        array $truck,
        array $A,
        array $spot,
        string $date,
        int $open,
        int $close,
        ?string $treatAs,
        ?array $weather
    ): ?array {
        $terms = $this->spotService->terms($spot);
        $vectors = self::vectorsOf($spot, $terms);
        if ($vectors === null) {
            return null;
        }
        return [
            'predicted_raw' => self::rawOrders($A, $truck['profile'], $terms, $vectors, $date, $open, $close, $treatAs, $weather),
            'basis' => self::basis(
                self::truckPart($truck, $A),
                self::spotPart($spot, $terms),
                $date,
                $open,
                $close,
                $treatAs,
                ServiceLogRepository::weatherSha1($weather)
            ),
            'terms' => $terms,
            'vectors' => $vectors,
            'dataset_version' => isset($spot['vec_dataset']) ? (string) $spot['vec_dataset'] : null,
        ];
    }

    // ------------------------------------------------------------------------------------ for the other services

    /**
     * The LocationVectors of a spot at the visibility of its terms, from the stored vectors as they are:
     * nothing is computed again, whatever region, dataset or seeds revision they were computed for.
     * Logging a service and judging it never wait for region data.
     *
     * @param array<string, mixed> $spot a spot row of SpotRepository
     * @param array<string, mixed> $terms SpotService::terms() of that row
     * @return array<string, mixed>|null null when the spot has no stored vectors
     */
    public static function vectorsOf(array $spot, array $terms): ?array
    {
        $level = (string) $terms['visibility'];
        $block = $spot['vectors'][$level] ?? null;
        if (!is_array($block) || !isset($block['capture'], $block['nearby'], $block['rivals'])) {
            return null;
        }
        $dataset = isset($spot['vec_dataset']) ? (string) $spot['vec_dataset'] : null;
        return [
            'capture' => $block['capture'],
            'nearby' => $block['nearby'],
            'within' => null,
            'rivals' => $block['rivals'],
            'visibility' => $level,
            'in_region' => (bool) ($spot['vec_in_region'] ?? false),
            'region_id' => $dataset === null || !isset($spot['vec_region_id']) ? null : (string) $spot['vec_region_id'],
            'exclusion' => Estimator::hostExclusion(Seeds::defaults(), $terms['host']),
            'excluded_amount' => (float) ($spot['vec_excluded'] ?? 0.0),
            'points_used' => (int) ($spot['vec_points_used'] ?? 0),
            'dataset_version' => $dataset,
            'model_version' => Estimator::MODEL_VERSION,
        ];
    }

    /**
     * The truck's time zone, for "today". A stored name this server's zone database does not know must
     * not take a request down: the default zone stands in, and the log says so.
     *
     * @param array<string, mixed> $truck
     */
    public static function zoneOf(array $truck): string
    {
        $zone = (string) ($truck['timezone'] ?? '');
        if (Clock::isZone($zone)) {
            return $zone;
        }
        error_log('[tp] a truck has a time zone this server does not know, the default zone is used');
        return (string) TpConfig::get('regions.default_timezone');
    }

    // ------------------------------------------------------------------------------------ internals

    /**
     * Reads every log of the truck and brings the raw predictions up to date.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     * @return array{rows: list<array<string, mixed>>, recomputed: int} the rows of
     *         ServiceLogRepository::allForCalibration with the present raw predictions
     */
    private function read(string $orgId, array $truck, array $A): array
    {
        $rows = $this->logs->allForCalibration($orgId, (string) $truck['id']);

        // The logs whose raw prediction is judged: spot logs that keep what the owner was shown.
        $judged = [];
        $spotIds = [];
        foreach ($rows as $i => $row) {
            if (self::isJudged($row)) {
                $judged[] = $i;
                $spotIds[(string) $row['spot_id']] = true;
            }
        }
        if ($judged === []) {
            return ['rows' => $rows, 'recomputed' => 0];
        }

        $spots = $this->spots->findMany(array_map('strval', array_keys($spotIds)), $orgId);
        $truckPart = self::truckPart($truck, $A);
        $spotParts = [];
        $terms = [];
        foreach ($spots as $id => $spot) {
            $terms[$id] = $this->spotService->terms($spot);
            $spotParts[$id] = self::spotPart($spot, $terms[$id]);
        }

        $behind = [];
        foreach ($judged as $i) {
            $row = $rows[$i];
            $spotId = (string) $row['spot_id'];
            if (!isset($spots[$spotId])) {
                continue;                     // the spot is gone: the stored number is all there is
            }
            $basis = self::basis(
                $truckPart,
                $spotParts[$spotId],
                $row['service_date'],
                $row['open_minute'],
                $row['close_minute'],
                $row['treat_as'],
                $row['weather_sha1']
            );
            if ($row['pred_raw_basis'] !== $basis) {
                $behind[$i] = $basis;
            }
        }
        if ($behind === []) {
            return ['rows' => $rows, 'recomputed' => 0];
        }

        // One model call per log: seconds for a few thousand logs, once after a change.
        @set_time_limit((int) TpConfig::get('requests.long_request_seconds'));
        $ids = [];
        foreach (array_keys($behind) as $i) {
            $ids[] = $rows[$i]['id'];
        }
        $weather = $this->logs->weatherOf($ids, $orgId);
        $versions = ['model_version' => (string) $A['model_version'], 'seeds_revision' => (int) $A['seeds_revision']];
        $recomputed = 0;
        $failed = 0;
        foreach ($behind as $i => $basis) {
            $row = $rows[$i];
            $spotId = (string) $row['spot_id'];
            $vectors = self::vectorsOf($spots[$spotId], $terms[$spotId]);
            if ($vectors === null) {
                continue;                     // no stored vectors: nothing to compute from
            }
            try {
                $raw = self::rawOrders(
                    $A,
                    $truck['profile'],
                    $terms[$spotId],
                    $vectors,
                    $row['service_date'],
                    $row['open_minute'],
                    $row['close_minute'],
                    $row['treat_as'],
                    $weather[$row['id']] ?? null
                );
            } catch (\InvalidArgumentException | \TypeError | \OutOfBoundsException | \OutOfRangeException $e) {
                // One log the model cannot read must not take calibration, and every page with it, down. It
                // is left without a raw prediction, so it is not judged, and it is not tried again until
                // its basis changes.
                if ($failed === 0) {
                    error_log('[tp] a raw prediction could not be recomputed: ' . get_class($e) . ': ' . Redactor::text($e->getMessage()));
                }
                $failed++;
                $raw = null;
            }
            $dataset = isset($spots[$spotId]['vec_dataset']) ? (string) $spots[$spotId]['vec_dataset'] : null;
            $this->logs->setRawPrediction($row['id'], $orgId, $raw, $basis, $versions + ['dataset_version' => $dataset]);
            $rows[$i]['predicted_raw'] = $raw;
            $rows[$i]['pred_raw_basis'] = $basis;
            if ($raw !== null) {
                $recomputed++;
            }
        }
        return ['rows' => $rows, 'recomputed' => $recomputed];
    }

    /**
     * A log whose prediction is judged: a spot log that keeps the figures the owner was shown.
     *
     * @param array<string, mixed> $row
     */
    private static function isJudged(array $row): bool
    {
        return $row['log_kind'] === 'spot' && $row['spot_id'] !== null
            && $row['predicted'] !== null && $row['pred_low'] !== null && $row['pred_high'] !== null;
    }

    /**
     * The rows that carry a full prediction, as ServiceLogEntry values, in the order given.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function entriesOf(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            if (!self::isJudged($row) || $row['predicted_raw'] === null) {
                continue;
            }
            $entries[] = [
                'service_id' => (string) $row['id'],
                'kind' => (string) $row['log_kind'],
                'spot_id' => (string) $row['spot_id'],
                'date' => (string) $row['service_date'],
                'open_minute' => (int) $row['open_minute'],
                'close_minute' => (int) $row['close_minute'],
                'actual' => (float) $row['actual_orders'],
                'sold_out' => (bool) $row['sold_out'],
                'predicted_raw' => (float) $row['predicted_raw'],
                'predicted' => (float) $row['predicted'],
                'low' => (float) $row['pred_low'],
                'high' => (float) $row['pred_high'],
            ];
        }
        return $entries;
    }

    /**
     * The mean orders of the window with no calibration, from stored inputs.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $terms SpotTerms
     * @param array<string, mixed> $vectors LocationVectors
     * @param array<string, mixed>|null $weather {ctx, ctx_next}
     */
    private static function rawOrders(
        array $A,
        array $profile,
        array $terms,
        array $vectors,
        string $date,
        int $open,
        int $close,
        ?string $treatAs,
        ?array $weather
    ): float {
        $ctx = Estimator::dayContext($A, $date, $treatAs, self::forecast($weather, 'ctx'), null, null);
        $next = null;
        if ($close > self::MINUTES_PER_DAY) {
            // The override belongs to one civil date: the next date is built without it.
            $next = Estimator::dayContext($A, Estimator::addDays($date, 1), null, self::forecast($weather, 'ctx_next'), null, null);
        }
        $window = Estimator::windowOrders($A, $profile, $terms, $vectors, null, $ctx, $next, $open, $close);
        return (float) $window['orders']['value'];
    }

    /**
     * @param array<string, mixed>|null $weather
     * @return list<mixed>|null the 24 hourly records of one date, or null
     */
    private static function forecast(?array $weather, string $which): ?array
    {
        $hours = $weather[$which] ?? null;
        return is_array($hours) && array_is_list($hours) ? $hours : null;
    }

    /**
     * What a raw prediction depends on through the truck: the model, the seeds with the owner's
     * overrides, the holiday flags of the region, and the two profile values demand is computed with.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $A
     */
    private static function truckPart(array $truck, array $A): string
    {
        $profile = is_array($truck['profile'] ?? null) ? $truck['profile'] : [];
        return sha1(JsonSafe::canonical([
            (string) $A['model_version'],
            (int) $A['seeds_revision'],
            is_array($A['overrides'] ?? null) ? $A['overrides'] : [],
            is_array($A['region']['flags'] ?? null) ? $A['region']['flags'] : [],
            $profile['capacity_orders_per_hour'] ?? null,
            $profile['daypart_fit'] ?? null,
        ]));
    }

    /**
     * What a raw prediction depends on through the spot: its visibility, its host and its stored vectors.
     *
     * @param array<string, mixed> $spot a spot row
     * @param array<string, mixed> $terms
     */
    private static function spotPart(array $spot, array $terms): string
    {
        return sha1(JsonSafe::canonical([
            $terms['visibility'],
            $terms['host'],
            $spot['vec_dataset'] ?? null,
            $spot['vectors_sha1'] ?? null,
        ]));
    }

    /** `pred_raw_basis`: 40 hexadecimal characters. */
    private static function basis(
        string $truckPart,
        string $spotPart,
        string $date,
        int $open,
        int $close,
        ?string $treatAs,
        ?string $weatherSha1
    ): string {
        return sha1(JsonSafe::canonical([$truckPart, $spotPart, $date, $open, $close, $treatAs, $weatherSha1]));
    }
}
