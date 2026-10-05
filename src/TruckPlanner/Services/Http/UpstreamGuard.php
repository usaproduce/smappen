<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Http;

use App\Core\Config;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * What stands between a Google client and Google (04_BACKEND.md 5.3, 5.4): is there a key, did Google
 * refuse this key lately, are we backing off, is there budget left, is there a token in the shared bucket.
 *
 * `$api` is "routes" (Routes API), "legacy" (Distance Matrix API) or "places" (Places contact lookup).
 * The two routing APIs share one back-off; each API has its own refusal memory:
 *
 *     tp:routes:refused:routes, tp:routes:refused:legacy   1 hour       tp:routes:backoff
 *     tp:places:refused:places                             1 hour       tp:places:backoff
 *
 * Refusal memory, back-off and the per-organization day counter live in TpCache. The counter is
 * approximate under concurrent requests; the global budget is counted from the ledger. The token bucket is
 * the house one (table `places_rate_buckets`, an atomic UPDATE per call).
 *
 * The class is open for a test to replace hasGoogleKey(); the bucket and the ledger are constructor
 * arguments.
 */
class UpstreamGuard
{
    private const ROUTING = ['routes', 'legacy'];

    private Clock $clock;
    private ApiLedger $ledger;

    /** @var (callable(string, int, int): bool)|null */
    private $bucket;

    /**
     * @param (callable(string, int, int): bool)|null $bucket takes (bucket name, tokens, seconds to wait)
     *        and says whether the tokens were taken; null uses the house token bucket
     */
    public function __construct(?Clock $clock = null, ?ApiLedger $ledger = null, ?callable $bucket = null)
    {
        $this->clock = $clock ?? new Clock();
        $this->ledger = $ledger ?? new ApiLedger();
        $this->bucket = $bucket;
    }

    /** Is GOOGLE_API_KEY set on this server? The key itself is read by the client classes only. */
    public function hasGoogleKey(): bool
    {
        return (string) Config::get('GOOGLE_API_KEY', '') !== '';
    }

    /** Did Google refuse this API for our key within the last hour? */
    public function refused(string $api): bool
    {
        return TpCache::get(self::refusedKey($api)) !== null;
    }

    /** Remember for an hour that Google refused this API (not enabled, or permission denied). */
    public function markRefused(string $api): void
    {
        TpCache::put(self::refusedKey($api), ['refused' => true], self::refusalTtl($api));
    }

    public function inBackoff(string $api): bool
    {
        return $this->backoffReason($api) !== null;
    }

    /**
     * The reason stored with an active back-off ("quota" or "upstream"), or null when there is none.
     */
    public function backoffReason(string $api): ?string
    {
        $entry = TpCache::get(self::backoffKey($api));
        if ($entry === null) {
            return null;
        }
        return is_string($entry['reason'] ?? null) ? $entry['reason'] : 'upstream';
    }

    /**
     * Stop calling this API for `$seconds`.
     *
     * @param string $reason "quota" (Google said slow down) or "upstream" (it failed or timed out)
     */
    public function backoff(string $api, int $seconds, string $reason = 'upstream'): void
    {
        TpCache::put(self::backoffKey($api), ['reason' => $reason], max(1, $seconds));
    }

    /**
     * Take `$tokens` from a shared bucket, waiting up to `$waitSeconds` for them. False: not now.
     */
    public function takeTokens(string $bucket, int $tokens, int $waitSeconds): bool
    {
        if ($this->bucket !== null) {
            return (bool) ($this->bucket)($bucket, $tokens, $waitSeconds);
        }
        return (new \App\Services\PlacesRateLimiter())->acquire($bucket, $tokens, $waitSeconds);
    }

    /** Matrix elements this organization may still fetch today (UTC day). */
    public function orgElementsLeft(string $orgId): int
    {
        $budget = (int) TpConfig::get('routing.org_elements_per_day');
        return max(0, $budget - TpCache::count($this->orgDayKey($orgId)));
    }

    /** Count `$n` fetched matrix elements against the organization's day. */
    public function spendOrgElements(string $orgId, int $n): void
    {
        if ($n > 0) {
            TpCache::add($this->orgDayKey($orgId), $n, (int) TpConfig::get('routing.org_counter_ttl_s'));
        }
    }

    /** Matrix elements all organizations together may still fetch today, counted from the ledger. */
    public function globalElementsLeft(): int
    {
        $budget = (int) TpConfig::get('routing.global_elements_per_day');
        /** @var array<string, string> $skus */
        $skus = TpConfig::get('routing.skus');
        $used = $this->ledger->unitsToday(array_values($skus));
        return $used >= $budget ? 0 : $budget - $used;
    }

    private function orgDayKey(string $orgId): string
    {
        return 'tp:routes:day:' . $orgId . ':' . str_replace('-', '', $this->clock->today('UTC'));
    }

    private static function refusedKey(string $api): string
    {
        return 'tp:' . self::family($api) . ':refused:' . $api;
    }

    private static function backoffKey(string $api): string
    {
        return 'tp:' . self::family($api) . ':backoff';
    }

    private static function family(string $api): string
    {
        if (in_array($api, self::ROUTING, true)) {
            return 'routes';
        }
        if ($api === 'places') {
            return 'places';
        }
        throw new \LogicException('unknown upstream API: ' . $api);
    }

    private static function refusalTtl(string $api): int
    {
        return (int) TpConfig::get(self::family($api) === 'routes' ? 'routing.refusal_ttl_s' : 'places.refusal_ttl_s');
    }
}
