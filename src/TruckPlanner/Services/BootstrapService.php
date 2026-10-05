<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\CountsRepository;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Services\Fallback\IdentityCalibration;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * Everything a /truck page needs before its first render, in one answer (04_BACKEND.md 4.3).
 *
 * It works without a truck: the browser then shows the first-run step, and still needs the model version,
 * the profile defaults and the regions to draw it. What belongs to a truck (its record, region, fuel price,
 * time zone and clock) is null then.
 *
 * "Today" and "now" are read from the clock in the truck's own time zone. The browser takes the day and the
 * minute from this answer, never from the device.
 */
class BootstrapService
{
    private RegionRepository $regionRows;
    private RegionService $regions;
    private CountsRepository $counts;
    private Clock $clock;
    private ProfileMapper $mapper;
    private AssumptionsFactory $factory;

    public function __construct(
        ?RegionRepository $regionRows = null,
        ?RegionService $regions = null,
        ?CountsRepository $counts = null,
        ?Clock $clock = null,
        ?ProfileMapper $mapper = null,
        ?AssumptionsFactory $factory = null
    ) {
        $this->regionRows = $regionRows ?? new RegionRepository();
        $this->regions = $regions ?? new RegionService($this->regionRows);
        $this->counts = $counts ?? new CountsRepository();
        $this->clock = $clock ?? new Clock();
        $this->mapper = $mapper ?? new ProfileMapper();
        $this->factory = $factory ?? new AssumptionsFactory();
    }

    /**
     * @param array<string, mixed>|null $truck the truck value of TruckBaseController::truck(), null when
     *                                         the organization has none yet
     * @return array<string, mixed> the fields of 4.3, in its order. `assumptions.overrides` and
     *         `calibration.spots` are maps: the controller names them when it answers
     */
    public function build(string $orgId, ?array $truck): array
    {
        $regionId = $truck === null ? RegionService::NONE : (string) $truck['profile']['region_id'];
        $regionRow = $regionId === RegionService::NONE ? null : $this->regionRows->find($regionId);
        $A = $this->factory->forTruck($truck, $regionRow);
        $regions = $this->regions->list();

        $zone = null;
        $today = null;
        $minute = null;
        $fuel = null;
        if ($truck === null) {
            // No truck, no logged service: the state every factor starts from.
            $asOf = $this->clock->today((string) TpConfig::get('regions.default_timezone'));
            $calibration = (new IdentityCalibration())->state($orgId, [], $A, $asOf);
        } else {
            $zone = $this->zoneOf($truck);
            [$today, $minute] = $this->localNow($zone);
            $calibration = Registry::calibration()->state($orgId, $truck, $A, $today);
            $fuel = Registry::fuel()->resolve($truck);
        }

        return [
            'model_version' => (string) $A['model_version'],
            'seeds_revision' => (int) $A['seeds_revision'],
            'has_truck' => $truck !== null,
            'truck' => $truck === null ? null : $this->mapper->toRecord($truck),
            'profile_defaults' => $this->mapper->defaults(),
            'assumptions' => $this->factory->info($A),
            'region' => $this->regions->info($regionId),
            'regions' => $regions,
            'calibration' => $calibration,
            'fuel' => $fuel,
            'timezone' => $zone,
            'today' => $today,
            'now_minute' => $minute,
            'counts' => $this->counts->forOrg($orgId),
            'routing' => Registry::legs()->status(),
            'limits' => TpConfig::get('limits'),
        ];
    }

    /**
     * The truck's time zone. A stored name this PHP does not know (a zone database that lost it) must not
     * take every page down: the default zone stands in, and the log says so.
     *
     * @param array<string, mixed> $truck
     */
    private function zoneOf(array $truck): string
    {
        $zone = (string) ($truck['timezone'] ?? '');
        if (Clock::isZone($zone)) {
            return $zone;
        }
        error_log('[tp] a truck has a time zone this server does not know, the default zone is used');
        return (string) TpConfig::get('regions.default_timezone');
    }

    /**
     * The civil date and the minute of day in a zone, both of one moment. The clock is read once for each,
     * so the date is read again afterwards: when midnight fell between the two, the pair is taken anew.
     *
     * @return array{0: string, 1: int}
     */
    private function localNow(string $zone): array
    {
        $today = $this->clock->today($zone);
        $minute = $this->clock->minuteOfDay($zone);
        $after = $this->clock->today($zone);
        if ($after !== $today) {
            $today = $after;
            $minute = $this->clock->minuteOfDay($zone);
        }
        return [$today, $minute];
    }
}
