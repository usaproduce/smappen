<?php
namespace App\Services;

use App\Core\Database;

/**
 * VendorCacheService — three-tier volatility caching + request
 * coalescing for the Carafe enrich pipeline. Spec v3 §12.1 + §10
 * guardrail 8: "No live Places call without a cache check."
 *
 * Two responsibilities, both mandatory before any PlacesClient call:
 *
 *   1. Freshness check by tier
 *      isFreshFor($placeId, $tier) reads vendor_google_details
 *      .{tier}_fetched_at and returns true if it's still within the
 *      tier's TTL. The nightly refresh skips fresh rows; the on-demand
 *      enrich path skips fresh rows; only the genuinely stale fields
 *      ever cost a billable call.
 *
 *   2. Request coalescing via DB advisory lock
 *      When two operators view the same vendor at once and both find
 *      it stale, only one should actually fetch. Without coalescing,
 *      the `on_demand` enrich economics break (spec §12.1). We use
 *      MySQL's GET_LOCK as the lock primitive — session-scoped,
 *      named, with a configurable timeout. The second caller blocks
 *      briefly then re-checks freshness on lock release; if the first
 *      caller succeeded, the second finds the row fresh and skips.
 *
 * TTLs come from §12.1:
 *   - cold:  never (re-pull only on CLOSED/MOVED signal)
 *   - warm:  90 days
 *   - hot:   30 days
 *
 * The high-level `withCoalescedFetch()` wraps both pieces — almost
 * every caller wants the "check, lock, fetch-or-skip, unlock" sequence
 * and shouldn't re-implement it.
 */
class VendorCacheService
{
    /** TTL in seconds per tier. null = no TTL (cold tier never expires automatically). */
    public const TTL_SECONDS = [
        'cold' => null,
        'warm' => 90 * 86400,
        'hot'  => 30 * 86400,
    ];

    /** Max seconds to wait for the coalescing lock before giving up. */
    private const LOCK_WAIT_SECONDS = 10;

    private \PDO $pdo;
    /** @var array<string,true> Locks held in this PHP process; released on __destruct. */
    private array $heldLocks = [];

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->pdo();
    }

    /**
     * Is the given tier's data still within TTL for this place_id?
     * Returns false if the row doesn't exist yet (= must fetch).
     */
    public function isFreshFor(string $placeId, string $tier): bool
    {
        if (!array_key_exists($tier, self::TTL_SECONDS)) {
            throw new \InvalidArgumentException("unknown tier: $tier");
        }
        $col = $tier . '_fetched_at';
        $stmt = $this->pdo->prepare("SELECT `$col` AS fetched_at FROM vendor_google_details WHERE google_place_id = ? LIMIT 1");
        $stmt->execute([$placeId]);
        $fetchedAt = $stmt->fetchColumn();
        return self::isFreshAt($fetchedAt, $tier);
    }

    /**
     * Pure freshness predicate — given a fetched_at timestamp (or null /
     * empty) and a tier, decide whether it's still inside the TTL window.
     * Exposed for unit tests (no DB) and for callers that already have
     * the timestamp in hand. $now defaults to time() for testability.
     */
    public static function isFreshAt($fetchedAt, string $tier, ?int $now = null): bool
    {
        if (!array_key_exists($tier, self::TTL_SECONDS)) {
            throw new \InvalidArgumentException("unknown tier: $tier");
        }
        if (!$fetchedAt) return false;
        $ttl = self::TTL_SECONDS[$tier];
        if ($ttl === null) {
            // Cold tier never expires automatically — any non-null
            // fetched_at counts as fresh. Re-pull is signal-driven.
            return true;
        }
        $ts  = is_int($fetchedAt) ? $fetchedAt : strtotime((string) $fetchedAt);
        if ($ts === false) return false;
        $age = ($now ?? time()) - $ts;
        return $age >= 0 && $age < $ttl;
    }

    /**
     * Acquire a named DB lock for the given place_id. Returns true if
     * acquired, false on timeout. Lock survives until release() or
     * connection close. Each PHP process tracks its own holds so the
     * destructor can clean up if a caller forgets.
     */
    public function lock(string $placeId, int $waitSeconds = self::LOCK_WAIT_SECONDS): bool
    {
        $key  = self::lockKey($placeId);
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, ?) AS got');
        $stmt->execute([$key, $waitSeconds]);
        $got = (int) $stmt->fetchColumn();
        if ($got === 1) {
            $this->heldLocks[$key] = true;
            return true;
        }
        return false;
    }

    public function unlock(string $placeId): void
    {
        $key = self::lockKey($placeId);
        if (!isset($this->heldLocks[$key])) return;
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$key]);
        $stmt->fetchColumn();
        unset($this->heldLocks[$key]);
    }

    /**
     * Coalesced fetch — the high-level API.
     *
     * Flow:
     *   1. If fresh in cache → return ['cached' => true, 'fresh' => true].
     *   2. Acquire DB lock on place_id (waits up to LOCK_WAIT_SECONDS).
     *   3. Re-check freshness — a concurrent caller may have already
     *      refreshed. If fresh now → return ['cached' => true, 'coalesced' => true].
     *   4. Call $fetcher() (the actual PlacesClient round-trip). Caller
     *      writes the new row via VendorUpsertService — we don't do it
     *      here.
     *   5. Release lock.
     *
     * Returns:
     *   - ['cached' => true, 'fresh' => true]                — hit before lock
     *   - ['cached' => true, 'coalesced' => true]            — hit after lock (race won by another caller)
     *   - ['cached' => false, 'fetched' => true, 'result' => mixed]    — fetcher ran
     *   - ['cached' => false, 'locked_out' => true]          — gave up waiting for lock
     */
    public function withCoalescedFetch(string $placeId, string $tier, callable $fetcher): array
    {
        if ($this->isFreshFor($placeId, $tier)) {
            return ['cached' => true, 'fresh' => true];
        }
        if (!$this->lock($placeId)) {
            return ['cached' => false, 'locked_out' => true];
        }
        try {
            if ($this->isFreshFor($placeId, $tier)) {
                return ['cached' => true, 'coalesced' => true];
            }
            $result = $fetcher();
            return ['cached' => false, 'fetched' => true, 'result' => $result];
        } finally {
            $this->unlock($placeId);
        }
    }

    /**
     * Returns a list of place_ids whose given tier is stale or missing,
     * up to $limit. The nightly tier-aware refresh worker uses this to
     * pick its work batch per spec §4.6 — narrow tier-specific masks,
     * not full re-pulls.
     *
     * @return string[]
     */
    public function staleForRefresh(string $tier, int $limit = 500): array
    {
        if (!array_key_exists($tier, self::TTL_SECONDS)) {
            throw new \InvalidArgumentException("unknown tier: $tier");
        }
        $ttl = self::TTL_SECONDS[$tier];
        if ($ttl === null) {
            // Cold never auto-refreshes — return empty so the worker can no-op.
            return [];
        }
        $col = $tier . '_fetched_at';
        // OPERATIONAL only: we don't waste calls re-pulling CLOSED rows.
        $sql = "SELECT google_place_id
                FROM vendor_google_details
                WHERE (business_status IS NULL OR business_status = 'OPERATIONAL')
                  AND (`$col` IS NULL OR `$col` < DATE_SUB(NOW(), INTERVAL ? SECOND))
                ORDER BY `$col` IS NOT NULL, `$col` ASC
                LIMIT ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $ttl, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'google_place_id');
    }

    public function __destruct()
    {
        // Best-effort release of any forgotten locks. Connection close also
        // releases — this is belt + suspenders for long-lived workers.
        foreach (array_keys($this->heldLocks) as $key) {
            try {
                $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);
            } catch (\Throwable $e) {
                // ignore — destructor must not throw
            }
        }
    }

    /**
     * Lock key namespace. Prefix prevents collision with other GET_LOCK
     * users in the same database. place_id is hashed to fit MySQL's
     * 64-char lock-name cap with room for the prefix. Public-static so
     * tests can assert collision-resistance + stability.
     */
    public static function lockKey(string $placeId): string
    {
        return 'carafe:vgd:' . substr(hash('sha256', $placeId), 0, 32);
    }
}
