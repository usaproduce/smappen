<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\MemoryCache;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Services\Http\UpstreamGuard;
use App\TruckPlanner\Services\Support\TpCache;
use PHPUnit\Framework\TestCase;

final class UpstreamGuardTest extends TestCase
{
    private const ORG = '11111111-1111-4111-8111-111111111111';

    private FixedClock $clock;
    private MemoryCache $store;
    private RecordingDatabase $db;

    /** @var list<array{0: string, 1: int, 2: int}> */
    private array $bucketCalls = [];
    private bool $bucketAnswer = true;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-10-04 23:30:00');
        $this->store = new MemoryCache();
        $this->db = new RecordingDatabase();
        TpCache::wire($this->clock, $this->store);
    }

    protected function tearDown(): void
    {
        TpCache::wire();
    }

    private function guard(): UpstreamGuard
    {
        return new UpstreamGuard($this->clock, new ApiLedger($this->db), function (string $bucket, int $tokens, int $wait): bool {
            $this->bucketCalls[] = [$bucket, $tokens, $wait];
            return $this->bucketAnswer;
        });
    }

    public function testKeyPresenceComesFromTheEnvironment(): void
    {
        $envBefore = $_ENV['GOOGLE_API_KEY'] ?? null;
        $processBefore = getenv('GOOGLE_API_KEY');
        try {
            unset($_ENV['GOOGLE_API_KEY']);
            putenv('GOOGLE_API_KEY');
            self::assertFalse($this->guard()->hasGoogleKey());
            putenv('GOOGLE_API_KEY=');
            self::assertFalse($this->guard()->hasGoogleKey());
            putenv('GOOGLE_API_KEY=test-key-not-real');
            self::assertTrue($this->guard()->hasGoogleKey());
        } finally {
            putenv($processBefore === false ? 'GOOGLE_API_KEY' : 'GOOGLE_API_KEY=' . $processBefore);
            if ($envBefore !== null) {
                $_ENV['GOOGLE_API_KEY'] = $envBefore;
            }
        }
    }

    public function testARefusalIsRememberedForAnHourPerApi(): void
    {
        $guard = $this->guard();
        self::assertFalse($guard->refused('routes'));
        $guard->markRefused('routes');
        self::assertTrue($guard->refused('routes'));
        self::assertFalse($guard->refused('legacy'));
        self::assertFalse($guard->refused('places'));
        self::assertArrayHasKey('tp:routes:refused:routes', $this->store->values);

        $this->clock->advance(3599);
        self::assertTrue($guard->refused('routes'));
        $this->clock->advance(1);
        self::assertFalse($guard->refused('routes'));
    }

    public function testEachApiHasItsOwnRefusalKey(): void
    {
        $guard = $this->guard();
        $guard->markRefused('legacy');
        $guard->markRefused('places');
        self::assertSame(
            ['tp:places:refused:places', 'tp:routes:refused:legacy'],
            self::sorted(array_keys($this->store->values))
        );
        self::assertTrue($guard->refused('legacy'));
        self::assertTrue($guard->refused('places'));
        self::assertFalse($guard->refused('routes'));
    }

    public function testBackOffKeepsItsReasonAndEnds(): void
    {
        $guard = $this->guard();
        self::assertFalse($guard->inBackoff('routes'));
        self::assertNull($guard->backoffReason('routes'));

        $guard->backoff('routes', 120, 'quota');
        self::assertTrue($guard->inBackoff('routes'));
        self::assertSame('quota', $guard->backoffReason('routes'));
        $this->clock->advance(119);
        self::assertTrue($guard->inBackoff('routes'));
        $this->clock->advance(1);
        self::assertFalse($guard->inBackoff('routes'));
        self::assertNull($guard->backoffReason('routes'));

        $guard->backoff('routes', 30);
        self::assertSame('upstream', $guard->backoffReason('routes'));
    }

    public function testTheTwoRoutingApisShareOneBackOffAndPlacesHasItsOwn(): void
    {
        $guard = $this->guard();
        $guard->backoff('routes', 30, 'upstream');
        self::assertArrayHasKey('tp:routes:backoff', $this->store->values);
        self::assertTrue($guard->inBackoff('legacy'));
        self::assertFalse($guard->inBackoff('places'));

        $guard->backoff('places', 60);
        self::assertArrayHasKey('tp:places:backoff', $this->store->values);
        self::assertTrue($guard->inBackoff('places'));
        $this->clock->advance(31);
        self::assertFalse($guard->inBackoff('routes'));
        self::assertTrue($guard->inBackoff('places'));
    }

    public function testTokensAreTakenFromTheSharedBucket(): void
    {
        $guard = $this->guard();
        self::assertTrue($guard->takeTokens('tp_routes_elements', 625, 2));
        $this->bucketAnswer = false;
        self::assertFalse($guard->takeTokens('tp_places_lookup', 1, 2));
        self::assertSame([['tp_routes_elements', 625, 2], ['tp_places_lookup', 1, 2]], $this->bucketCalls);
    }

    public function testTheOrganizationBudgetIsCountedPerUtcDay(): void
    {
        $guard = $this->guard();
        self::assertSame(3000, $guard->orgElementsLeft(self::ORG));
        $guard->spendOrgElements(self::ORG, 625);
        $guard->spendOrgElements(self::ORG, 25);
        self::assertSame(2350, $guard->orgElementsLeft(self::ORG));
        self::assertArrayHasKey('tp:routes:day:' . self::ORG . ':20261004', $this->store->values);
        self::assertSame(3000, $guard->orgElementsLeft('another-org'));

        $guard->spendOrgElements(self::ORG, 5000);
        self::assertSame(0, $guard->orgElementsLeft(self::ORG));

        // 23:30 UTC plus 31 minutes is the next UTC day: a fresh budget, under a new key.
        $this->clock->advance(31 * 60);
        self::assertSame(3000, $guard->orgElementsLeft(self::ORG));
        $guard->spendOrgElements(self::ORG, 10);
        self::assertArrayHasKey('tp:routes:day:' . self::ORG . ':20261005', $this->store->values);
        self::assertSame(2990, $guard->orgElementsLeft(self::ORG));
    }

    public function testSpendingNothingWritesNothing(): void
    {
        $this->guard()->spendOrgElements(self::ORG, 0);
        self::assertSame([], $this->store->values);
    }

    public function testTheGlobalBudgetIsCountedFromTheLedger(): void
    {
        $this->db->when('FROM api_cost_events', ['units' => 1875]);
        self::assertSame(18125, $this->guard()->globalElementsLeft());
        $call = $this->db->only('FROM api_cost_events');
        self::assertSame(
            ['tp_routes_matrix', 'tp_routes_matrix_pro', 'tp_routes_matrix_ent', 'tp_distance_matrix'],
            $call['params']
        );
    }

    public function testASpentOrUnreadableLedgerLeavesNothing(): void
    {
        $spent = (new RecordingDatabase())->when('FROM api_cost_events', ['units' => 20000]);
        self::assertSame(0, (new UpstreamGuard($this->clock, new ApiLedger($spent), static fn (): bool => true))->globalElementsLeft());
        $over = (new RecordingDatabase())->when('FROM api_cost_events', ['units' => 20650]);
        self::assertSame(0, (new UpstreamGuard($this->clock, new ApiLedger($over), static fn (): bool => true))->globalElementsLeft());

        $broken = (new RecordingDatabase())->failOn('FROM api_cost_events');
        $left = null;
        LogCapture::during(function () use ($broken, &$left): void {
            $left = (new UpstreamGuard($this->clock, new ApiLedger($broken), static fn (): bool => true))->globalElementsLeft();
        });
        self::assertSame(0, $left);
    }

    public function testTheSpendingAllowanceIsWhatIsLeftOfTheDayOrOfTheMonthWhicheverIsLess(): void
    {
        // settings: 5 dollars a day, 40 a month
        $this->db->when('FROM api_cost_events', ['day_usd' => '1.250000', 'month_usd' => '12.000000']);
        self::assertEqualsWithDelta(3.75, $this->guard()->spendLeftUsd(), 1e-9, 'the day is the tighter one');
        $call = $this->db->only('FROM api_cost_events');
        // every SKU that costs money, and none of the free ones (forecast, fuel price)
        self::assertSame(
            ['tp_routes_matrix', 'tp_routes_matrix_pro', 'tp_routes_matrix_ent', 'tp_distance_matrix', 'tp_places_text', 'tp_places_details'],
            $call['params']
        );
        self::assertStringContainsString('SUM(total_cost_usd)', $call['sql']);

        $month = (new RecordingDatabase())->when('FROM api_cost_events', ['day_usd' => '0.500000', 'month_usd' => '39.100000']);
        self::assertEqualsWithDelta(0.9, $this->guardOn($month)->spendLeftUsd(), 1e-9, 'the month is the tighter one');
    }

    public function testASpentOrUnreadableLedgerLeavesNoMoney(): void
    {
        $day = (new RecordingDatabase())->when('FROM api_cost_events', ['day_usd' => '5.000000', 'month_usd' => '5.000000']);
        self::assertSame(0.0, $this->guardOn($day)->spendLeftUsd());
        $over = (new RecordingDatabase())->when('FROM api_cost_events', ['day_usd' => '0.000000', 'month_usd' => '41.300000']);
        self::assertSame(0.0, $this->guardOn($over)->spendLeftUsd());

        $broken = (new RecordingDatabase())->failOn('FROM api_cost_events');
        $left = null;
        LogCapture::during(function () use ($broken, &$left): void {
            $left = $this->guardOn($broken)->spendLeftUsd();
        });
        self::assertSame(0.0, $left, 'a ledger that cannot be read is a spent allowance');
    }

    public function testTheOwnerCanSetTheAllowancesInTheEnvironment(): void
    {
        $before = [];
        foreach (['TP_GOOGLE_DAILY_USD', 'TP_GOOGLE_MONTHLY_USD'] as $name) {
            $before[$name] = [getenv($name), $_ENV[$name] ?? null];
            unset($_ENV[$name]);
        }
        $spent = static fn (): RecordingDatabase => (new RecordingDatabase())->when('FROM api_cost_events', ['day_usd' => '1.000000', 'month_usd' => '1.000000']);
        try {
            putenv('TP_GOOGLE_DAILY_USD=2.5');
            putenv('TP_GOOGLE_MONTHLY_USD=100');
            self::assertSame(2.5, UpstreamGuard::allowanceUsd('daily'));
            self::assertSame(100.0, UpstreamGuard::allowanceUsd('monthly'));
            self::assertEqualsWithDelta(1.5, $this->guardOn($spent())->spendLeftUsd(), 1e-9);

            // 0 switches Google off for Truck Planner
            putenv('TP_GOOGLE_DAILY_USD=0');
            self::assertSame(0.0, $this->guardOn((new RecordingDatabase())->when('FROM api_cost_events', ['day_usd' => '0', 'month_usd' => '0']))->spendLeftUsd());

            // what is not a number that makes sense is ignored: the settings count
            foreach (['', 'lots', '-3', 'INF', 'NAN'] as $text) {
                putenv('TP_GOOGLE_DAILY_USD=' . $text);
                putenv('TP_GOOGLE_MONTHLY_USD=' . $text);
                self::assertSame(5.0, UpstreamGuard::allowanceUsd('daily'), 'daily for ' . var_export($text, true));
                self::assertSame(40.0, UpstreamGuard::allowanceUsd('monthly'), 'monthly for ' . var_export($text, true));
            }
        } finally {
            foreach ($before as $name => [$process, $env]) {
                putenv($process === false ? $name : $name . '=' . $process);
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
            }
        }
    }

    public function testACallIsPricedFromThePriceList(): void
    {
        self::assertEqualsWithDelta(0.045, UpstreamGuard::costUsd('tp_routes_matrix_ent', 3), 1e-12);
        self::assertEqualsWithDelta(3.25, UpstreamGuard::costUsd('tp_routes_matrix', 650), 1e-12);
        self::assertSame(0.035, UpstreamGuard::costUsd('tp_places_text', 1));
        self::assertSame(0.0, UpstreamGuard::costUsd('tp_nws_hourly', 400), 'the forecast is free');
        self::assertSame(0.0, UpstreamGuard::costUsd('tp_no_such_sku', 5));
        self::assertSame(0.0, UpstreamGuard::costUsd('tp_routes_matrix', -4));
    }

    private function guardOn(RecordingDatabase $db): UpstreamGuard
    {
        return new UpstreamGuard($this->clock, new ApiLedger($db), static fn (): bool => true);
    }

    public function testAnUnknownApiIsAProgrammingError(): void
    {
        $guard = $this->guard();
        foreach ([
            static fn () => $guard->refused('ors'),
            static fn () => $guard->markRefused('weather'),
            static fn () => $guard->backoff('eia', 30),
            static fn () => $guard->inBackoff(''),
        ] as $call) {
            try {
                $call();
                self::fail('an unknown API name was accepted');
            } catch (\LogicException $e) {
                self::assertStringContainsString('unknown upstream API', $e->getMessage());
            }
        }
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values, SORT_STRING);
        return $values;
    }
}
