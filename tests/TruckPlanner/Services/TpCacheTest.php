<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\MemoryCache;
use App\TruckPlanner\Services\Support\TpCache;
use PHPUnit\Framework\TestCase;

final class TpCacheTest extends TestCase
{
    private FixedClock $clock;
    private MemoryCache $store;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-10-04 12:00:00');
        $this->store = new MemoryCache();
        TpCache::wire($this->clock, $this->store);
    }

    protected function tearDown(): void
    {
        TpCache::wire();
    }

    public function testAnEntryIsAHitBeforeItsTimeAndAMissAfterIt(): void
    {
        TpCache::put('tp:test:a', ['ok' => true, 'n' => 3], 600);
        self::assertSame(['ok' => true, 'n' => 3], TpCache::get('tp:test:a'));

        $this->clock->advance(599);
        self::assertSame(['ok' => true, 'n' => 3], TpCache::get('tp:test:a'));

        $this->clock->advance(1);
        self::assertNull(TpCache::get('tp:test:a'), 'the entry is over at exactly its TTL');
        $this->clock->advance(86400);
        self::assertNull(TpCache::get('tp:test:a'));
    }

    public function testAgeIsDecidedByTheEnvelopeWhateverTheStoreStillHolds(): void
    {
        TpCache::put('tp:test:b', ['v' => 1], 30);
        // The store was asked to keep the row 26 hours longer than the TTL, so that a time-zone
        // difference between PHP and MySQL can never drop it early.
        self::assertSame(30 + 93600, $this->store->ttls['tp:test:b']);
        $envelope = json_decode($this->store->values['tp:test:b'], true);
        self::assertSame($this->clock->epoch() + 30, $envelope['exp']);
        self::assertSame(['v' => 1], $envelope['v']);

        // The store still holds the row a day later: TpCache alone says it is over.
        $this->clock->advance(31);
        self::assertArrayHasKey('tp:test:b', $this->store->values);
        self::assertNull(TpCache::get('tp:test:b'));
    }

    public function testAStoreThatForgotIsAMiss(): void
    {
        TpCache::put('tp:test:c', ['v' => 1], 600);
        $this->store->values = [];
        self::assertNull(TpCache::get('tp:test:c'));
    }

    public function testAnEmptyValueIsAHitNotAMiss(): void
    {
        TpCache::put('tp:test:empty', [], 60);
        self::assertSame([], TpCache::get('tp:test:empty'));
        self::assertNull(TpCache::get('tp:test:never'));
    }

    public function testNumbersKeepTheirTypeAndEveryDigit(): void
    {
        $value = ['whole' => 66.0, 'int' => 66, 'sum' => 0.1 + 0.2, 'tiny' => 1.0e-7, 'list' => [1.0, 2, 'x', null, false]];
        TpCache::put('tp:test:numbers', $value, 60);
        self::assertSame($value, TpCache::get('tp:test:numbers'));
    }

    public function testForeignOrBrokenRowsAreMisses(): void
    {
        foreach (['not json', '[]', '{"v": {"a": 1}}', '{"exp": "soon", "v": []}', '{"exp": 99999999999, "v": 5}', '{"exp": 99999999999}'] as $raw) {
            $this->store->values['tp:test:raw'] = $raw;
            self::assertNull(TpCache::get('tp:test:raw'), 'accepted: ' . $raw);
        }
    }

    public function testACounterAddsUp(): void
    {
        self::assertSame(0, TpCache::count('tp:routes:day:org:20261004'));
        self::assertSame(25, TpCache::add('tp:routes:day:org:20261004', 25, 172800));
        self::assertSame(650, TpCache::add('tp:routes:day:org:20261004', 625, 172800));
        self::assertSame(650, TpCache::count('tp:routes:day:org:20261004'));
        $this->clock->advance(172800);
        self::assertSame(0, TpCache::count('tp:routes:day:org:20261004'));
        self::assertSame(4, TpCache::add('tp:routes:day:org:20261004', 4, 172800));
    }

    public function testForgetPrefix(): void
    {
        TpCache::put('tp:scout:s:org-1:abc', ['x' => 1], 600);
        TpCache::put('tp:scout:s:org-1:def', ['x' => 2], 600);
        TpCache::put('tp:scout:s:org-2:abc', ['x' => 3], 600);
        TpCache::put('tp:suggest:org-1:abc', ['x' => 4], 600);
        TpCache::forgetPrefix('tp:scout:s:org-1');
        self::assertNull(TpCache::get('tp:scout:s:org-1:abc'));
        self::assertNull(TpCache::get('tp:scout:s:org-1:def'));
        self::assertSame(['x' => 3], TpCache::get('tp:scout:s:org-2:abc'));
        self::assertSame(['x' => 4], TpCache::get('tp:suggest:org-1:abc'));
    }

    public function testForgetDropsOneKeyAndLeavesTheKeysThatStartLikeIt(): void
    {
        TpCache::put('tp:nws:pt:39.003,-77.405', ['gridId' => 'LWX'], 600);
        TpCache::put('tp:nws:pt:39.003,-77.4051', ['gridId' => 'LWX'], 600);
        TpCache::forget('tp:nws:pt:39.003,-77.405');
        self::assertNull(TpCache::get('tp:nws:pt:39.003,-77.405'));
        self::assertSame(['gridId' => 'LWX'], TpCache::get('tp:nws:pt:39.003,-77.4051'), 'a neighbour whose key starts with the same text stays');
        // forgetting what is not there is no error, and the key can be used again
        TpCache::forget('tp:nws:pt:1,1');
        self::assertNull(TpCache::get('tp:nws:pt:1,1'));
        TpCache::put('tp:nws:pt:39.003,-77.405', ['gridId' => 'AKQ'], 600);
        self::assertSame(['gridId' => 'AKQ'], TpCache::get('tp:nws:pt:39.003,-77.405'));
    }

    public function testOnlyTruckPlannerKeysPass(): void
    {
        foreach ([
            static fn () => TpCache::put('geocode:abc', ['x' => 1], 60),
            static fn () => TpCache::get('iso:v2:abc'),
            static fn () => TpCache::add('ai_score:1', 1, 60),
            static fn () => TpCache::get('tp:' . str_repeat('k', 253)),
            static fn () => TpCache::forgetPrefix(''),
            static fn () => TpCache::forgetPrefix('tp:'),
            static fn () => TpCache::forgetPrefix('geocode:'),
        ] as $call) {
            try {
                $call();
                self::fail('a key outside tp: was accepted');
            } catch (\LogicException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame([], $this->store->values);
    }

    public function testAValueThatCannotBeEncodedIsNotStoredAndDoesNotThrow(): void
    {
        $lines = LogCapture::during(static function (): void {
            TpCache::put('tp:test:bad', ['x' => NAN], 60);
        });
        self::assertSame(['[tp] cache value not stored: tp:test:bad'], $lines);
        self::assertNull(TpCache::get('tp:test:bad'));
    }
}
