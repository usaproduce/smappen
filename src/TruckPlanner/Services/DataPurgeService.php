<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\TruckDataRepository;
use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\Redactor;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpInvalid;

/**
 * Two kinds of removal (04_BACKEND.md 4.16, 7.2).
 *
 * deleteTruckData() is the owner's "delete everything": the truck data of one organization, and nothing
 * else. The account and the organization stay, and so does every shared table.
 *
 * purgeGoogleCaches() is the daily sweep that keeps the 30-day promise for everyone: what was fetched from
 * Google and stored (drive legs, plan results computed with Google legs) is removed when its lifetime ends,
 * also for an account that sends no request. Each of the two parts belongs to the repository of another
 * package and is asked through its own purge method. A repository that is not installed has written
 * nothing, so its part is skipped. The owner's own data is never touched by the sweep: corrections of drive
 * times, typed contact details and the plans themselves stay.
 *
 * A Scout lead has nothing to sweep. Of a contact lookup it keeps Google's id of the place, which may be
 * kept for good; the looked-up name, address, phone, website and Maps link are never stored.
 */
class DataPurgeService
{
    private const DATA = 'App\\TruckPlanner\\Data\\';

    /**
     * Part of the sweep => [repository class, purge method, its one argument]. The argument is "no limit"
     * for the leg cache and "every organization" for the plan results.
     *
     * @var array<string, array{0: string, 1: string, 2: int|null}>
     */
    private const SWEEPS = [
        'drive_legs' => [self::DATA . 'DriveLegRepository', 'purgeExpired', 0],
        'plan_snapshots' => [self::DATA . 'PlanRepository', 'purgeExpiredSnapshots', null],
    ];

    /** What the owner types to confirm the deletion, to the letter. */
    public const CONFIRM_PHRASE = 'delete my truck data';

    /** The cache entries that hold results computed from an organization's data, by key prefix. */
    private const ORG_CACHE_PREFIXES = ['tp:scout:s:', 'tp:scout:r:', 'tp:suggest:'];

    private TruckDataRepository $data;

    public function __construct(?TruckDataRepository $data = null)
    {
        $this->data = $data ?? new TruckDataRepository();
    }

    /**
     * The deletion as route 43 asks for it: nothing is deleted unless the body carries the phrase as its
     * `confirm`, exactly. Another case, a space more, another type or another key is a refusal.
     *
     * @param array<int|string, mixed> $body the request body
     * @return array{services: int, plan_stops: int, plans: int, leads: int, drive_overrides: int,
     *               spots: int, trucks: int} as deleteTruckData()
     * @throws TpInvalid "confirm must be exactly: delete my truck data"
     */
    public function deleteConfirmed(string $orgId, array $body): array
    {
        if (($body['confirm'] ?? null) !== self::CONFIRM_PHRASE) {
            throw (new Input($body))->error('confirm', 'must be exactly: ' . self::CONFIRM_PHRASE);
        }
        return $this->deleteTruckData($orgId);
    }

    /**
     * Deletes the organization's truck, spots, plans with their stops, service logs, drive-time
     * corrections and Scout leads, in one transaction, then forgets the results cached for it.
     *
     * The day's count of Google elements of the organization is kept: deleting data must not hand out a
     * fresh budget.
     *
     * @return array{services: int, plan_stops: int, plans: int, leads: int, drive_overrides: int,
     *               spots: int, trucks: int} the rows that were deleted. All zero when there was nothing
     */
    public function deleteTruckData(string $orgId): array
    {
        $deleted = $this->data->deleteAll($orgId);
        foreach (self::ORG_CACHE_PREFIXES as $prefix) {
            try {
                TpCache::forgetPrefix($prefix . $orgId);
            } catch (\Throwable $e) {
                // The rows are gone already. What is left in the cache answers no request without a truck
                // and expires by itself within a day.
                error_log('[tp] cached results not forgotten after a data deletion: ' . get_class($e) . ': ' . Redactor::text($e->getMessage()));
            }
        }
        return $deleted;
    }

    /**
     * Removes every piece of Google content whose lifetime has ended, for every organization. Running it
     * again changes nothing.
     *
     * @param bool $dryRun count what would be removed and change nothing
     * @return array{drive_legs: ?int, plan_snapshots: ?int, failed: list<string>}
     *         per part the number of rows removed (cached legs deleted, plan results set to NULL), or
     *         null for a part that was skipped: its repository is not installed, or it failed. `failed`
     *         names the parts that failed (and were logged); the other part still ran
     */
    public function purgeGoogleCaches(bool $dryRun = false): array
    {
        $result = ['drive_legs' => null, 'plan_snapshots' => null, 'failed' => []];
        if ($dryRun) {
            $counts = $this->data->expiredGoogleCounts();
            foreach (array_keys(self::SWEEPS) as $part) {
                $result[$part] = (int) $counts[$part];
            }
            return $result;
        }
        foreach (self::SWEEPS as $part => [$class, $method, $argument]) {
            $repository = $this->repository($class);
            if ($repository === null) {
                continue;
            }
            try {
                $result[$part] = (int) $repository->{$method}($argument);
            } catch (\Throwable $e) {
                $result['failed'][] = $part;
                error_log('[tp] purge of ' . $part . ' failed: ' . get_class($e) . ': ' . Redactor::text($e->getMessage()));
            }
        }
        return $result;
    }

    /**
     * A repository of another package by its class name, or null while that package is not installed.
     * (A test answers its own object here.)
     */
    protected function repository(string $class): ?object
    {
        return class_exists($class) ? new $class() : null;
    }
}
