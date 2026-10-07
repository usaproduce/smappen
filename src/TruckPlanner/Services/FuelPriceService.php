<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\FuelPriceRepository;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Contracts\FuelPriceProvider;
use App\TruckPlanner\Services\Fallback\SeedFuelPrice;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\Redactor;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Upstream\FuelClient;

/**
 * The fuel price a truck's costs are computed with (04_BACKEND.md 5.6). First hit wins:
 *
 *   1. the owner's own price                                              source "owner"
 *   2. the newest stored weekly price of the U.S. Energy Information      source "eia", with its week
 *      Administration for the area of the base state and the fuel type
 *   3. the seed price for that fuel and area                              source "seed", with the seed's date
 *
 * The answer always says which of the three it is and what date it is of.
 *
 * refreshIfDue() fetches the weekly prices when a new week is out, at most once every six hours however
 * the attempt ends, and only when the server has a key. Without a key, or while the service does not
 * answer, the stored price (or the seed price) keeps being shown with its date. Nothing here raises for
 * anything the upstream can do.
 */
class FuelPriceService implements FuelPriceProvider
{
    private const ATTEMPT_KEY = 'tp:eia:attempt';

    private ?FuelPriceRepository $prices;
    private ?RegionService $regions;
    private ?RegionRepository $regionRows;
    private ?FuelClient $client;
    private ?Clock $clock;

    public function __construct(
        ?FuelPriceRepository $prices = null,
        ?RegionService $regions = null,
        ?RegionRepository $regionRows = null,
        ?FuelClient $client = null,
        ?Clock $clock = null
    ) {
        $this->prices = $prices;
        $this->regions = $regions;
        $this->regionRows = $regionRows;
        $this->client = $client;
        $this->clock = $clock;
    }

    /**
     * @param array<string, mixed> $truck the truck value of TruckBaseController::truck()
     * @return array{price_per_gal: float, source: string, area: string, product: string, period: ?string} FuelInfo
     */
    public function resolve(array $truck): array
    {
        // The owner's price and the seed price, with the area and the product, are the fallback's answer.
        $answer = (new SeedFuelPrice($this->regions ??= new RegionService($this->regionRows())))->resolve($truck);
        if ($answer['source'] === 'owner') {
            return $answer;
        }
        $stored = $this->prices()->latest((string) $answer['area'], (string) $answer['product']);
        if ($stored === null) {
            return $answer;
        }
        return [
            'price_per_gal' => (float) $stored['price'],
            'source' => 'eia',
            'area' => (string) $answer['area'],
            'product' => (string) $answer['product'],
            'period' => (string) $stored['period'],
        ];
    }

    /**
     * Fetches the weekly prices when all of this holds: the server has a key; no attempt was made in the
     * last six hours; the newest stored week is older than the week that is out by now (the Monday of
     * this week from Tuesday 10:00 in New York on, the Monday before until then).
     */
    public function refreshIfDue(): void
    {
        try {
            $client = $this->client ??= new FuelClient();
            if (!$client->hasKey() || TpCache::get(self::ATTEMPT_KEY) !== null) {
                return;
            }
            $week = $this->weekThatIsOut();
            $newest = $this->prices()->newestPeriod();
            if ($newest !== null && strcmp($newest, $week) >= 0) {
                return;
            }
            // Remembered before the call: a service that fails is asked at most four times a day.
            $clock = $this->clock ??= new Clock();
            TpCache::put(self::ATTEMPT_KEY, ['at' => $clock->epoch()], (int) TpConfig::get('fuel.attempt_ttl_s'));

            $start = Estimator::addDays($week, -(int) TpConfig::get('fuel.lookback_days'));
            $answer = $client->weekly($this->areas(), $start);
            if ($answer['ok'] && $answer['rows'] !== []) {
                $this->prices()->upsertMany($answer['rows']);
            }
        } catch (\Throwable $e) {
            error_log('[tp] fuel price refresh failed: ' . get_class($e) . ': ' . Redactor::text($e->getMessage()));
        }
    }

    /**
     * The Monday the newest published prices are dated: prices of a week come out on its Tuesday morning,
     * New York time.
     */
    private function weekThatIsOut(): string
    {
        $clock = $this->clock ??= new Clock();
        $zone = (string) TpConfig::get('fuel.release_zone');
        $today = $clock->today($zone);
        $minute = $clock->minuteOfDay($zone);
        $dow = Estimator::dayOfWeek($today);
        $monday = Estimator::addDays($today, -$dow);
        $releaseDow = (int) TpConfig::get('fuel.release_dow');
        $isOut = $dow > $releaseDow || ($dow === $releaseDow && $minute >= (int) TpConfig::get('fuel.release_minute'));
        return $isOut ? $monday : Estimator::addDays($monday, -7);
    }

    /**
     * Every area a truck can be priced with: the areas of every region's states, and the national one.
     *
     * @return list<string>
     */
    private function areas(): array
    {
        $areas = [(string) TpConfig::get('fuel.national_area') => true];
        foreach ($this->regionRows()->all() as $region) {
            $byState = $region['config']['fuel_area_by_state'] ?? null;
            foreach (is_array($byState) ? $byState : [] as $area) {
                if (is_string($area) && preg_match('/^[A-Z0-9]{2,8}$/', $area) === 1) {
                    $areas[$area] = true;
                }
            }
        }
        $list = array_map('strval', array_keys($areas));
        sort($list, SORT_STRING);
        return $list;
    }

    private function prices(): FuelPriceRepository
    {
        return $this->prices ??= new FuelPriceRepository();
    }

    private function regionRows(): RegionRepository
    {
        return $this->regionRows ??= new RegionRepository();
    }
}
