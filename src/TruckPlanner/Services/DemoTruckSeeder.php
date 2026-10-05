<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\Core\Config;
use App\TruckPlanner\Data\PlanRepository;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Data\ServiceLogRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpInvalid;

/**
 * A demonstration truck for development (04_BACKEND.md 7.2, scripts/truck/seed-demo-truck.php): one
 * truck, five saved spots, one planned day and twelve logged services, written through the same services
 * the API uses, so every stored number is what the product would have computed.
 *
 * Everything is a plain function of the as-of date and of the region data that is loaded: no random
 * number, nothing learned, no clock. The same as-of date over the same data gives the same spots, the
 * same plan and the same services (ids apart). "Today" is the as-of date for everything the seeder does.
 *
 * The spots stand at real places of the Washington DC region and are named for what is there. Where
 * the truck's region is not loaded the spots are still saved, with empty surroundings: only a spot with
 * a host then has orders.
 *
 * It refuses to run in production, and for an organization that already has a truck.
 */
class DemoTruckSeeder
{
    /** The first save of the truck (route 3): everything else is the profile default. */
    public const TRUCK = [
        'name' => 'Smoke & Ember (demo)',
        'base' => ['lat' => 39.0030, 'lng' => -77.4050, 'address' => 'Sterling, VA'],
        'avg_ticket' => 15.0,
    ];

    /**
     * The five spots, as bodies of route 11. Each point was chosen from the loaded region data
     * (dataset dc-20261003): what stands there is what the name says.
     *
     *   office        Spring Street at Herndon Parkway, Town of Herndon: a block of about 1,800 office jobs
     *   taproom       a brewery taproom on Overland Drive, Sterling, that has no kitchen of its own
     *   apartments    an apartment community on Innovation Avenue, Sterling
     *   hospital      the hospital on Town Center Parkway, Reston
     *   town_center   Reston Town Center (the point the first draft of this demo called a Herndon office park)
     */
    public const SPOTS = [
        'office' => [
            'name' => 'Herndon office area, Spring Street',
            'point' => ['lat' => 38.9625, 'lng' => -77.3781],
            'address' => 'Spring Street, Herndon, VA 20170',
            'notes' => 'Demo spot. Weekday lunch for the offices along Spring Street and Herndon Parkway.',
        ],
        'taproom' => [
            'name' => 'Sterling taproom, Overland Drive',
            'point' => ['lat' => 38.9630, 'lng' => -77.4993],
            'address' => 'Overland Drive, Sterling, VA 20166',
            'notes' => 'Demo spot. The taproom has no kitchen: the truck is the only food on its evenings.',
            'terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 120.0, 'only_food' => true]],
        ],
        'apartments' => [
            'name' => 'Apartment community, Innovation Avenue',
            'point' => ['lat' => 38.9672, 'lng' => -77.4212],
            'address' => 'Innovation Avenue, Sterling, VA 20166',
            'notes' => 'Demo spot. Dinner service for the residents, by the leasing office.',
            'terms' => ['host' => ['segment' => 'res', 'size' => 600.0, 'only_food' => true]],
        ],
        'hospital' => [
            'name' => 'Hospital, Town Center Parkway',
            'point' => ['lat' => 38.9626, 'lng' => -77.3650],
            'address' => 'Town Center Parkway, Reston, VA 20190',
            'notes' => 'Demo spot. Lunch for staff and visitors of the hospital.',
        ],
        'town_center' => [
            'name' => 'Reston Town Center',
            'point' => ['lat' => 38.9600, 'lng' => -77.3600],
            'address' => 'Reston Town Center, Reston, VA 20190',
            'notes' => 'Demo spot. A managed plaza: 10 % of sales, at least $75.',
            'terms' => ['visibility' => 'prominent', 'fee_pct' => 0.1, 'fee_min' => 75.0],
        ],
    ];

    /** The planned day: the Thursday on or after the as-of date, lunch at the offices, then the taproom. */
    public const PLAN_NAME = 'Herndon lunch, then the Sterling taproom';
    public const PLAN_STOPS = [['office', 660, 840], ['taproom', 1020, 1200]];
    private const THURSDAY = 3;

    /**
     * The twelve logged services, oldest first: [spot, weeks before the week of the as-of date, day of
     * the week (0 = Monday), opening minute, closing minute]. No two fall on one date.
     */
    public const SERVICES = [
        ['office', 8, 3, 660, 840],
        ['taproom', 7, 4, 1020, 1200],
        ['hospital', 6, 1, 660, 840],
        ['office', 6, 3, 660, 840],
        ['taproom', 5, 4, 1020, 1200],
        ['apartments', 4, 2, 1020, 1200],
        ['office', 4, 3, 660, 840],
        ['taproom', 3, 4, 1020, 1200],
        ['town_center', 3, 5, 690, 870],
        ['hospital', 2, 1, 660, 840],
        ['office', 2, 3, 660, 840],
        ['taproom', 1, 4, 1020, 1200],
    ];

    public const HAS_TRUCK = 'This workspace already has a truck';

    private const PRODUCTION = 'production';

    private TruckRepository $trucks;
    private RegionRepository $regionRows;
    private ProfileService $profiles;
    private SpotRepository $spots;
    private SpotService $spotService;
    private PlanRepository $plans;
    private ServiceLogRepository $logs;
    private ?RegionService $regions;
    private ?string $environment;

    /**
     * @param string|null $environment the name of the environment; null reads APP_ENV, where an unset
     *                                 value counts as production
     */
    public function __construct(
        ?TruckRepository $trucks = null,
        ?RegionRepository $regionRows = null,
        ?ProfileService $profiles = null,
        ?SpotRepository $spots = null,
        ?SpotService $spotService = null,
        ?PlanRepository $plans = null,
        ?ServiceLogRepository $logs = null,
        ?RegionService $regions = null,
        ?string $environment = null
    ) {
        $this->trucks = $trucks ?? new TruckRepository();
        $this->regionRows = $regionRows ?? new RegionRepository();
        $this->regions = $regions;
        $this->profiles = $profiles ?? new ProfileService($this->trucks, $regions);
        $this->spots = $spots ?? new SpotRepository();
        $this->spotService = $spotService ?? new SpotService($this->spots, null, $regions);
        $this->plans = $plans ?? new PlanRepository();
        $this->logs = $logs ?? new ServiceLogRepository();
        $this->environment = $environment;
    }

    /** May the seeder run here? Never in production. */
    public function allowed(): bool
    {
        $environment = $this->environment ?? (string) Config::get('APP_ENV', self::PRODUCTION);
        return $environment !== self::PRODUCTION;
    }

    /**
     * Writes the demonstration truck of an organization.
     *
     * @param string $asOf civil date "YYYY-MM-DD" that stands for today: the services are logged in the
     *                     eight calendar weeks before its week, the plan is for the Thursday on or after it
     * @return array{as_of: string, truck: array<string, mixed>, spots: list<array<string, mixed>>,
     *               plan: array<string, mixed>, services: list<array<string, mixed>>,
     *               calibration: array<string, mixed>} the TruckRecord, per spot {key, id, name}, the
     *         plan's {id, date, result_state} and per service {id, date, spot, open_minute, close_minute,
     *         actual, predicted_raw, predicted}, and the CalibrationState the services lead to
     * @throws \LogicException in production
     * @throws TpInvalid for an as-of date the model's calendar does not hold with the weeks around it
     * @throws TpConflict when the organization already has a truck
     */
    public function seed(string $orgId, ?string $userId, string $asOf): array
    {
        if (!$this->allowed()) {
            throw new \LogicException('the demo truck is for development: it is never written in production');
        }
        [$planDate, $serviceDates] = self::dates($asOf);
        if ($this->trucks->findByOrg($orgId) !== null) {
            throw new TpConflict(self::HAS_TRUCK);
        }

        $this->profiles->upsert($orgId, $userId, self::TRUCK);
        $row = $this->trucks->findByOrg($orgId);
        if ($row === null) {
            throw new \UnexpectedValueException('the demo truck was not saved');
        }
        $mapper = new ProfileMapper();
        $truck = $row + ['profile' => $mapper->toRecord($row)['profile']];
        $regionId = (string) $truck['profile']['region_id'];
        $A = (new AssumptionsFactory())->forTruck($truck, $regionId === RegionService::NONE ? null : $this->regionRows->find($regionId));

        // "Today" is the as-of date for every service below.
        $clock = new class($asOf) extends Clock {
            private string $date;

            public function __construct(string $date)
            {
                $this->date = $date;
            }

            public function today(string $tz): string
            {
                return $this->date;
            }
        };
        $calibration = new CalibrationService($this->logs, $this->spots, $this->spotService, $clock);
        $logging = new ServiceLogService($this->logs, $this->spots, $this->plans, $calibration, $clock);
        $planning = new PlanningService($this->plans, $this->spots, $this->spotService, $this->logs, $this->regions, $clock);

        $spots = [];
        $listed = [];
        foreach (self::SPOTS as $key => $body) {
            $spot = $this->spotService->create($orgId, $truck, $userId, $body);
            $spots[$key] = $spot;
            $listed[] = ['key' => $key, 'id' => $spot['id'], 'name' => $spot['name']];
        }

        $services = [];
        foreach ($serviceDates as $i => $date) {
            [$key, , , $open, $close] = self::SERVICES[$i];
            $spot = $spots[$key];
            $log = [
                'log_kind' => 'spot',
                'spot_id' => $spot['id'],
                'service_date' => $date,
                'open_minute' => $open,
                'close_minute' => $close,
                'treat_as' => null,
            ];
            // What the model says for the window before the owner's results are used.
            $raw = (float) ($logging->prediction($orgId, $truck, $A, $log, null, null)['predicted_raw'] ?? 0.0);
            $actual = (int) Estimator::roundHalfAway($raw * self::scale((string) $spot['name'], $date), 0);
            $answer = $logging->create($orgId, $truck, $A, $userId, [
                'kind' => 'spot',
                'spot_id' => $spot['id'],
                'date' => $date,
                'open_minute' => $open,
                'close_minute' => $close,
                'actual' => $actual,
            ]);
            $service = $answer['service'];
            $services[] = [
                'id' => $service['id'],
                'date' => $date,
                'spot' => $key,
                'open_minute' => $open,
                'close_minute' => $close,
                'actual' => $actual,
                'predicted_raw' => $service['prediction']['predicted_raw'] ?? null,
                'predicted' => $service['prediction']['predicted'] ?? null,
            ];
        }

        $stops = [];
        foreach (self::PLAN_STOPS as [$key, $open, $close]) {
            $stops[] = ['kind' => 'spot', 'spot_id' => $spots[$key]['id'], 'open_minute' => $open, 'close_minute' => $close];
        }
        $plan = $planning->create($orgId, $truck, $A, $userId, [
            'date' => $planDate,
            'name' => self::PLAN_NAME,
            'status' => 'planned',
            'stops' => $stops,
        ]);

        return [
            'as_of' => $asOf,
            'truck' => $mapper->toRecord($row),
            'spots' => $listed,
            'plan' => ['id' => $plan['id'], 'date' => $plan['date'], 'result_state' => $plan['result_state']],
            'services' => $services,
            'calibration' => $calibration->state($orgId, $truck, $A, $asOf),
        ];
    }

    /**
     * How much of the raw prediction a demo service "really" sold: between 0.75 and 1.249, fixed by the
     * spot's name and the date.
     */
    public static function scale(string $spotName, string $date): float
    {
        return 0.75 + (crc32($spotName . $date) % 500) / 1000.0;
    }

    /**
     * The date of the planned day and the dates of the twelve services, in the order of SERVICES.
     *
     * @return array{0: string, 1: list<string>}
     * @throws TpInvalid
     */
    public static function dates(string $asOf): array
    {
        try {
            $dow = Estimator::dayOfWeek($asOf);
            $monday = Estimator::addDays($asOf, -$dow);
            $planDate = Estimator::addDays($asOf, (self::THURSDAY - $dow + 7) % 7);
            // The plan is evaluated with the context of the day after it as well.
            Estimator::parseDate(Estimator::addDays($planDate, 1));
            $dates = [];
            foreach (self::SERVICES as [, $weeksBack, $day]) {
                $date = Estimator::addDays($monday, $day - 7 * $weeksBack);
                Estimator::parseDate($date);
                $dates[] = $date;
            }
        } catch (\InvalidArgumentException $e) {
            throw new TpInvalid('as_of must be a date in the form YYYY-MM-DD, with nine weeks before it and one after', 'as_of', 'V7');
        }
        return [$planDate, $dates];
    }
}
