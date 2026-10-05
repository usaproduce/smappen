<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpInvalid;

/**
 * Exact capture at one point with optional spot terms (04_BACKEND.md 4.7): the location vectors per
 * requested visibility, the outlets and possible hosts nearby, and the server's own estimate at the point.
 * Nothing is stored.
 *
 * The estimate is computed with the first requested visibility: the typical week hour by hour, its best
 * three-hour windows, the best of them as a full window result with its money, and, when a date was sent,
 * that date's window with its day context. Every figure is a range with a confidence label and carries
 * its breakdown, as the model returns it. The browser computes the same from the vectors; the server's
 * copy lets it check that the two runtimes agree.
 *
 * A point with no source in reach answers with zero vectors. `located.in_region` only labels: it never
 * zeroes a vector.
 */
class SimulateService
{
    private const WINDOW_HOURS = 3;
    private const WINDOW_COUNT = 3;
    private const MAX_MINUTE = 2880;
    private const MINUTES_PER_DAY = 1440;
    private const TREAT_AS_FIXED = ['normal', 'holiday'];

    // Strings 1, 3 and 4 of 03_DATA.md section 14.
    private const ATTRIBUTION_PLACES = "\u{00A9} OpenStreetMap contributors";
    private const ATTRIBUTION_RESIDENTS = 'Residents: U.S. Census Bureau, 2020 Census Redistricting Data (Public Law 94-171). '
        . 'Counts as of April 1, 2020, not adjusted for growth.';
    private const ATTRIBUTION_JOBS = 'Jobs: U.S. Census Bureau, LEHD Origin-Destination Employment Statistics (LODES), '
        . 'version 8.4, 2023, all jobs. Job counts are jobs of record with statistical noise added by the Census '
        . 'Bureau, not people present. {blocks_adjusted} payroll-address blocks holding {jobs_spread} jobs were '
        . 'spread over their county (corrections {corrections_version}); construction jobs count at '
        . '{cns04_weight_percent} %.';

    private SpotService $spotService;
    private SpotRepository $spots;
    private RegionRepository $regions;
    private Clock $clock;

    public function __construct(
        ?SpotService $spotService = null,
        ?SpotRepository $spots = null,
        ?RegionRepository $regions = null,
        ?Clock $clock = null
    ) {
        $this->spots = $spots ?? new SpotRepository();
        $this->spotService = $spotService ?? new SpotService($this->spots);
        $this->regions = $regions ?? new RegionRepository();
        $this->clock = $clock ?? new Clock();
    }

    /**
     * @param array<string, mixed> $truck the truck value of TruckBaseController::truck()
     * @param array<string, mixed> $A the truck's Assumptions
     * @param array<int|string, mixed> $body the request body of route 9
     * @return array<string, mixed> `located`, `vectors` (one LocationVectors per requested visibility),
     *                              `host`, `outlets`, `outlets_total`, `hosts_nearby`, `estimate`,
     *                              `calibration`, `dataset_version`, `model_version`, `seeds_revision`,
     *                              `attribution`
     * @throws \App\TruckPlanner\Services\Support\TpConflict while the region data does not fit this server
     */
    public function run(string $orgId, array $truck, array $A, array $body): array
    {
        $in = new Input($body);
        $point = $in->point('point', true);
        $levels = self::levelsInput($in);
        $terms = $this->spotService->termsInput($in->obj('terms', $in->has('terms')), $truck);
        $spotId = $this->spotIdInput($in, $orgId);
        $dated = self::datedInput($in, $A);

        $lat = (float) $point['lat'];
        $lng = (float) $point['lng'];
        $levels ??= [(string) ($terms['visibility'] ?? 'normal')];
        $first = $levels[0];

        $linked = $this->spotService->linkedHost(
            $truck,
            $lat,
            $lng,
            $terms['host'] ?? null,
            $terms['place_key'] ?? null,
            $terms['place_type'] ?? null
        );
        $host = $linked['host'];
        $answer = Registry::capture()->capture(self::regionId($truck), $lat, $lng, $levels, $host);
        $vectors = $answer['vectors'][$first] ?? null;
        if (!is_array($vectors)) {
            throw new \UnexpectedValueException('capture answered without visibility ' . $first);
        }

        // The model is given terms that match the vectors it is given: the first requested visibility.
        $modelTerms = [
            'spot_id' => $spotId,
            'visibility' => $first,
            'host' => $host,
            'fee_flat' => (float) ($terms['fee_flat'] ?? 0.0),
            'fee_pct' => (float) ($terms['fee_pct'] ?? 0.0),
            'fee_min' => (float) ($terms['fee_min'] ?? 0.0),
            'allowed' => $terms['allowed'] ?? null,
        ];
        $profile = $truck['profile'];
        $today = $this->clock->today(Clock::zoneOf($truck));
        $cal = Registry::calibration()->state($orgId, $truck, $A, $today);
        [$truckFactor, $spotFactor] = Estimator::calibrationFactor($cal, $spotId);

        $strip = Estimator::weekStrip($A, $profile, $modelTerms, $vectors, $cal);
        $best = Estimator::bestWindows($strip, self::WINDOW_HOURS, self::WINDOW_COUNT, true);

        $typical = null;
        if ($best !== []) {
            $start = (int) $best[0]['start'];
            $hour = $start % 24;
            $dow = (int) (($start - $hour) / 24);
            $open = $hour * 60;
            $close = $open + self::WINDOW_HOURS * 60;
            $window = Estimator::windowOrders(
                $A,
                $profile,
                $modelTerms,
                $vectors,
                $cal,
                Estimator::typicalContext($A, $dow),
                Estimator::typicalContext($A, ($dow + 1) % 7),
                $open,
                $close
            );
            $typical = [
                'dow' => $dow,
                'open_minute' => $open,
                'close_minute' => $close,
                'window' => $window,
                'money' => Estimator::stopMoney($profile, $modelTerms, $window['orders']),
            ];
        }

        $onDate = null;
        if ($dated !== null) {
            $days = $dated['close'] > self::MINUTES_PER_DAY ? 2 : 1;
            $contexts = Registry::dayContexts()->contexts($truck, $A, $dated['date'], $days, [$dated['date'] => $dated['treat_as']]);
            $context = $contexts['days'][0]['context'];
            $window = Estimator::windowOrders(
                $A,
                $profile,
                $modelTerms,
                $vectors,
                $cal,
                $context,
                $days === 2 ? $contexts['days'][1]['context'] : null,
                $dated['open'],
                $dated['close']
            );
            $onDate = [
                'date' => $dated['date'],
                'window' => $window,
                'money' => Estimator::stopMoney($profile, $modelTerms, $window['orders']),
                'context' => $context,
            ];
        }

        $regionRead = isset($vectors['region_id']) ? (string) $vectors['region_id'] : null;
        $dataset = isset($vectors['dataset_version']) ? (string) $vectors['dataset_version'] : null;

        return [
            'located' => $answer['located'],
            'vectors' => $answer['vectors'],
            'host' => $host,
            'outlets' => $answer['outlets'],
            'outlets_total' => (int) $answer['outlets_total'],
            'hosts_nearby' => $answer['hosts_nearby'],
            'estimate' => [
                'week_strip' => $strip,
                'best_windows' => $best,
                'typical' => $typical,
                'dated' => $onDate,
            ],
            'calibration' => ['truck_factor' => $truckFactor, 'spot_factor' => $spotFactor],
            'dataset_version' => $dataset,
            'model_version' => (string) $A['model_version'],
            'seeds_revision' => (int) $A['seeds_revision'],
            'attribution' => $this->attribution($regionRead, $dataset),
        ];
    }

    /**
     * `visibilities`: one to three levels, each once, in the order sent. Null when the body has none.
     *
     * @return list<string>|null
     */
    private static function levelsInput(Input $in): ?array
    {
        $items = $in->items('visibilities', 1, count(SpotRepository::BLOCKS), $in->has('visibilities'));
        if ($items === null) {
            return null;
        }
        $each = $in->each('visibilities');
        $levels = [];
        foreach (array_keys($items) as $i) {
            $level = (string) $each->enum($i, SpotRepository::BLOCKS, true);
            if (!in_array($level, $levels, true)) {
                $levels[] = $level;
            }
        }
        return $levels;
    }

    /** `spot_id`: a spot of this organization whose calibration factor applies, or null. */
    private function spotIdInput(Input $in, string $orgId): ?string
    {
        $spotId = $in->str('spot_id', 36, $in->has('spot_id'));
        if ($spotId === null) {
            return null;
        }
        $spot = $spotId === '' ? null : $this->spots->find($spotId, $orgId, true);
        if ($spot === null) {
            throw $in->notFound('spot_id');
        }
        // The id as the table spells it: that is the key the calibration state uses.
        return (string) $spot['id'];
    }

    /**
     * `date`, `open_minute`, `close_minute` (all three or none) and `treat_as`.
     *
     * @param array<string, mixed> $A
     * @return array{date: string, open: int, close: int, treat_as: ?string}|null null when no date was sent
     */
    private static function datedInput(Input $in, array $A): ?array
    {
        $date = $in->date('date', $in->has('date'));
        $open = $in->int('open_minute', 0, self::MAX_MINUTE, $in->has('open_minute'));
        $close = $in->int('close_minute', 0, self::MAX_MINUTE, $in->has('close_minute'));
        $treatAs = $in->enum('treat_as', array_merge(self::TREAT_AS_FIXED, array_values(Estimator::seed($A, 'vocabulary.dow'))));
        if ($date === null && $open === null && $close === null) {
            return null;
        }
        if ($date === null || $open === null || $close === null) {
            throw new TpInvalid('date, open_minute and close_minute must be given together');
        }
        if ($close <= $open) {
            throw $in->error('close_minute', 'must be after open_minute');
        }
        if ($close > self::MINUTES_PER_DAY) {
            // The window runs into the next civil date, which the model must know as well.
            try {
                Estimator::parseDate(Estimator::addDays($date, 1));
            } catch (\InvalidArgumentException $e) {
                throw $in->error('date', 'must be a date in the form YYYY-MM-DD', 'V7');
            }
        }
        return ['date' => $date, 'open' => $open, 'close' => $close, 'treat_as' => $treatAs];
    }

    /**
     * The source lines for what the answer shows: places, residents and, when a dataset was read and its
     * manifest fills every placeholder, jobs.
     *
     * @return list<string>
     */
    private function attribution(?string $regionId, ?string $version): array
    {
        $lines = [self::ATTRIBUTION_PLACES, self::ATTRIBUTION_RESIDENTS];
        if ($regionId === null || $version === null) {
            return $lines;
        }
        $manifest = $this->regions->manifest($regionId, $version);
        $blocks = $manifest['totals']['blocks_adjusted'] ?? null;
        $spread = $manifest['totals']['jobs_spread'] ?? null;
        $corrections = $manifest['inputs']['corrections_version'] ?? null;
        $weight = $manifest['parameters']['cns04_weight'] ?? null;
        if (self::isNumber($blocks) && self::isNumber($spread) && self::isNumber($weight)
            && is_string($corrections) && $corrections !== '') {
            $lines[] = strtr(self::ATTRIBUTION_JOBS, [
                '{blocks_adjusted}' => self::whole((float) $blocks),
                '{jobs_spread}' => self::whole((float) $spread),
                '{corrections_version}' => $corrections,
                '{cns04_weight_percent}' => self::whole((float) $weight * 100.0),
            ]);
        }
        return $lines;
    }

    private static function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    /** A number as a whole number in digits, rounded with the model's one rule. */
    private static function whole(float $x): string
    {
        return (string) (int) Estimator::roundHalfAway($x, 0);
    }

    /**
     * @param array<string, mixed> $truck
     */
    private static function regionId(array $truck): string
    {
        $regionId = $truck['profile']['region_id'] ?? null;
        return is_string($regionId) && $regionId !== '' ? $regionId : RegionService::NONE;
    }
}
