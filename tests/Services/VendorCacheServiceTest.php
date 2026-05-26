<?php
namespace App\Tests\Services;

use App\Services\VendorCacheService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-math tests for VendorCacheService. The DB-touching parts
 * (isFreshFor, lock/unlock, staleForRefresh, withCoalescedFetch) need
 * an integration harness Smappen doesn't have yet — we cover them
 * indirectly by testing the pure predicate isFreshAt that isFreshFor
 * delegates to, plus the lockKey collision contract.
 *
 * Carafe Vendor Network Spec v3 §12.1.
 */
class VendorCacheServiceTest extends TestCase
{
    public function testTtlTableMatchesSpec(): void
    {
        $this->assertNull(VendorCacheService::TTL_SECONDS['cold']);            // never auto-expire
        $this->assertSame(90 * 86400, VendorCacheService::TTL_SECONDS['warm']);
        $this->assertSame(30 * 86400, VendorCacheService::TTL_SECONDS['hot']);
    }

    public function testFreshAtRejectsUnknownTier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VendorCacheService::isFreshAt('2026-05-25 10:00:00', 'made-up-tier');
    }

    // ─── Cold: never auto-expires (any non-null timestamp is fresh) ───

    public function testColdAnyTimestampIsFresh(): void
    {
        // Even something 5 years old in cold tier is still fresh — the
        // TTL is null, refresh is signal-driven (CLOSED/MOVED).
        $now = strtotime('2026-05-25 12:00:00');
        $old = '2021-05-25 12:00:00';
        $this->assertTrue(VendorCacheService::isFreshAt($old, 'cold', $now));
    }

    public function testColdNullTimestampIsStale(): void
    {
        // Never been fetched → must fetch.
        $this->assertFalse(VendorCacheService::isFreshAt(null,  'cold'));
        $this->assertFalse(VendorCacheService::isFreshAt('',    'cold'));
        $this->assertFalse(VendorCacheService::isFreshAt('0',   'cold'));
    }

    // ─── Hot: 30-day TTL ─────────────────────────────────────────────

    public function testHotWithin30DaysIsFresh(): void
    {
        $now = strtotime('2026-05-25 12:00:00');
        $oneDayAgo  = '2026-05-24 12:00:00';
        $fifteenAgo = '2026-05-10 12:00:00';
        $this->assertTrue(VendorCacheService::isFreshAt($oneDayAgo,  'hot', $now));
        $this->assertTrue(VendorCacheService::isFreshAt($fifteenAgo, 'hot', $now));
    }

    public function testHotPast30DaysIsStale(): void
    {
        $now = strtotime('2026-05-25 12:00:00');
        $thirtyOneDays = '2026-04-24 11:00:00';
        $this->assertFalse(VendorCacheService::isFreshAt($thirtyOneDays, 'hot', $now));
    }

    public function testHotExactlyAtBoundaryIsStale(): void
    {
        // age == ttl is NOT fresh — strict less-than is the contract.
        $now = strtotime('2026-05-25 12:00:00');
        $exactly30Days = '2026-04-25 12:00:00';
        $this->assertFalse(VendorCacheService::isFreshAt($exactly30Days, 'hot', $now));
    }

    // ─── Warm: 90-day TTL ────────────────────────────────────────────

    public function testWarmWithin90DaysIsFresh(): void
    {
        $now = strtotime('2026-05-25 12:00:00');
        $sixtyDays = '2026-03-26 12:00:00';
        $this->assertTrue(VendorCacheService::isFreshAt($sixtyDays, 'warm', $now));
    }

    public function testWarmPast90DaysIsStale(): void
    {
        $now = strtotime('2026-05-25 12:00:00');
        $hundredDays = '2026-02-14 12:00:00';
        $this->assertFalse(VendorCacheService::isFreshAt($hundredDays, 'warm', $now));
    }

    public function testWarmAcceptsBothStringAndUnixTimestamp(): void
    {
        $now = strtotime('2026-05-25 12:00:00');
        $threeDaysAgoString = '2026-05-22 12:00:00';
        $threeDaysAgoUnix   = strtotime($threeDaysAgoString);
        $this->assertSame(
            VendorCacheService::isFreshAt($threeDaysAgoString, 'warm', $now),
            VendorCacheService::isFreshAt($threeDaysAgoUnix,   'warm', $now)
        );
    }

    public function testWarmRejectsGarbageTimestamp(): void
    {
        $this->assertFalse(VendorCacheService::isFreshAt('not-a-date', 'warm'));
    }

    public function testFutureTimestampIsTreatedAsStale(): void
    {
        // age < 0 means clock skew or bug — be safe, treat as stale.
        $now = strtotime('2026-05-25 12:00:00');
        $future = '2026-06-25 12:00:00';
        $this->assertFalse(VendorCacheService::isFreshAt($future, 'hot', $now));
    }

    // ─── Lock-key contract ───────────────────────────────────────────

    public function testLockKeyIsDeterministic(): void
    {
        $a = VendorCacheService::lockKey('places/ABC123');
        $b = VendorCacheService::lockKey('places/ABC123');
        $this->assertSame($a, $b);
    }

    public function testLockKeyDifferentInputsProduceDifferentKeys(): void
    {
        $a = VendorCacheService::lockKey('places/ABC123');
        $b = VendorCacheService::lockKey('places/ABC124');
        $this->assertNotSame($a, $b);
    }

    public function testLockKeyFitsMysqlLockNameLimit(): void
    {
        // MySQL 8 caps GET_LOCK names at 64 bytes; we should stay well under.
        $key = VendorCacheService::lockKey('places/' . str_repeat('X', 500));
        $this->assertLessThanOrEqual(64, strlen($key));
        $this->assertStringStartsWith('carafe:vgd:', $key);
    }
}
