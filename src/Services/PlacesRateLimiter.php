<?php
namespace App\Services;

use App\Core\Database;

/**
 * PlacesRateLimiter — shared token-bucket rate limiter for Places API
 * calls. Spec v3 §12.4 + §10 guardrail 10 ("One shared rate limiter
 * across all concurrent workers — never per-worker limits").
 *
 * Why shared: N concurrent tile workers all share Google's QPS ceiling.
 * Per-worker limits multiply by N and breach the ceiling. The single
 * `places_rate_buckets` row is the shared state; the row lock that
 * MySQL takes on UPDATE serializes refill+decrement across workers.
 *
 * Why DB, not Redis: Smappen has MySQL on the path already, no Redis.
 * For Carafe scale (a few workers, low double-digit QPS), one
 * single-row UPDATE per call is fine. Switch to Redis only if and
 * when contention on the row shows up in slow-query logs.
 *
 * Usage:
 *
 *   $rl = new PlacesRateLimiter();
 *   $rl->acquire('places_search');             // blocks (sleeps) until a token frees up
 *   $places->searchNearby($params);
 *
 *   // Or non-blocking:
 *   if (!$rl->acquire('places_details', 1, 0)) {
 *       throw new \RuntimeException('rate limited');
 *   }
 */
class PlacesRateLimiter
{
    public const BUCKET_SEARCH  = 'places_search';
    public const BUCKET_DETAILS = 'places_details';
    public const BUCKET_PHOTO   = 'places_photo';

    /** Sleep granularity when waiting for a token — short enough to feel responsive. */
    private const POLL_SLEEP_US = 50_000;

    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->pdo();
    }

    /**
     * Acquire $tokens from $bucket, waiting up to $waitSeconds for them
     * to become available. Returns true on success, false on timeout.
     *
     * Implementation: each call atomically refills the bucket (based on
     * elapsed wall-clock time × fill_rate, capped at capacity) then
     * decrements by $tokens — but only if enough are available. If not,
     * the caller sleeps briefly and retries.
     */
    public function acquire(string $bucket, int $tokens = 1, int $waitSeconds = 30): bool
    {
        if ($tokens <= 0) return true;
        $deadline = microtime(true) + max(0, $waitSeconds);
        do {
            if ($this->tryConsume($bucket, $tokens)) return true;
            // Don't busy-loop — give the bucket time to refill.
            usleep(self::POLL_SLEEP_US);
        } while (microtime(true) < $deadline);
        return false;
    }

    /**
     * Single attempt: refill + decrement in one UPDATE. Returns true if
     * the call consumed $tokens; false if there weren't enough.
     *
     * The UPDATE is atomic — the WHERE clause only matches when post-
     * refill tokens >= requested, and the SET writes both the new
     * balance and the new last_refill_at. Concurrent workers serialize
     * on the row lock inside the UPDATE.
     */
    public function tryConsume(string $bucket, int $tokens = 1): bool
    {
        // Refill formula: tokens_available + (elapsed_sec × fill_rate_per_sec), capped at capacity.
        // MySQL: TIMESTAMPDIFF(MICROSECOND, last_refill_at, NOW(3)) / 1e6 gives elapsed seconds.
        $sql = "UPDATE places_rate_buckets
                SET tokens_available = LEAST(
                        capacity,
                        tokens_available
                        + (TIMESTAMPDIFF(MICROSECOND, last_refill_at, NOW(3)) / 1000000.0)
                          * fill_rate_per_sec
                    ) - ?,
                    last_refill_at = NOW(3)
                WHERE bucket = ?
                  AND LEAST(
                        capacity,
                        tokens_available
                        + (TIMESTAMPDIFF(MICROSECOND, last_refill_at, NOW(3)) / 1000000.0)
                          * fill_rate_per_sec
                      ) >= ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tokens, $bucket, $tokens]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Inspect a bucket's current state. Used by the dashboard + tests;
     * does NOT refill (read-only).
     *
     * @return ?array{bucket:string, capacity:int, fill_rate_per_sec:float, tokens_available:float, last_refill_at:string}
     */
    public function inspect(string $bucket): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT bucket, capacity, fill_rate_per_sec, tokens_available, last_refill_at
             FROM places_rate_buckets WHERE bucket = ?'
        );
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$row) return null;
        $row['capacity']          = (int) $row['capacity'];
        $row['fill_rate_per_sec'] = (float) $row['fill_rate_per_sec'];
        $row['tokens_available']  = (float) $row['tokens_available'];
        return $row;
    }
}
