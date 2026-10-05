<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Data\TruckDataFixtures;
use App\Tests\TruckPlanner\Data\TruckTables;
use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\MemoryCache;
use App\TruckPlanner\Data\TruckDataRepository;
use App\TruckPlanner\Services\DataPurgeService;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpCacheStore;
use App\TruckPlanner\Services\Support\TpInvalid;
use PHPUnit\Framework\TestCase;

// The in-memory owner tables and their fixture rows live with the repository's test.
require_once dirname(__DIR__) . '/Data/TruckDataRepositoryTest.php';

/**
 * DataPurgeService: the owner's "delete everything" over an in-memory copy of the owner tables, and the
 * daily sweep of Google content with the three repositories it asks stood in for.
 */
final class DataPurgeServiceTest extends TestCase
{
    private const ORG = TruckDataFixtures::ORG;
    private const OTHER_ORG = TruckDataFixtures::OTHER_ORG;
    private const ZEROS = ['services' => 0, 'plan_stops' => 0, 'plans' => 0, 'leads' => 0, 'drive_overrides' => 0, 'spots' => 0, 'trucks' => 0];

    private TruckTables $tables;
    private MemoryCache $cache;

    protected function setUp(): void
    {
        $this->tables = TruckDataFixtures::tables();
        $this->cache = new MemoryCache();
        TpCache::wire(new FixedClock('2026-10-05 16:00:00'), $this->cache);
    }

    protected function tearDown(): void
    {
        TpCache::wire();
    }

    private function service(): DataPurgeService
    {
        return new DataPurgeService(new TruckDataRepository($this->tables));
    }

    // ------------------------------------------------------------------------------------ delete everything

    public function testDeleteTruckDataRemovesTheSevenTablesRowsOfOneOrganization(): void
    {
        $before = $this->tables->rows;

        $deleted = $this->service()->deleteTruckData(self::ORG);

        // The fixture: 2 logs, 5 stops, 5 plans, 3 leads, 1 correction, 3 spots and the truck.
        self::assertSame(
            ['services' => 2, 'plan_stops' => 5, 'plans' => 5, 'leads' => 3, 'drive_overrides' => 1, 'spots' => 3, 'trucks' => 1],
            $deleted
        );
        foreach (['tp_service_logs', 'tp_plan_stops', 'tp_plans', 'tp_scout_leads', 'tp_drive_overrides', 'tp_spots', 'tp_trucks'] as $table) {
            self::assertSame(0, $this->tables->count($table, self::ORG), $table);
            $theirs = array_values(array_filter($before[$table], static fn (array $row): bool => $row['organization_id'] === self::OTHER_ORG));
            self::assertNotSame([], $theirs);
            self::assertSame($theirs, $this->tables->rows[$table], $table . ' of the second organization is untouched');
        }
        self::assertSame($before['tp_drive_legs'], $this->tables->rows['tp_drive_legs'], 'the shared leg cache is nobody\'s truck data');
        // No other statement ran than the seven counts and the seven deletes.
        self::assertCount(14, $this->tables->statements());

        // A second time there is nothing left: zeros, not an error.
        self::assertSame(self::ZEROS, $this->service()->deleteTruckData(self::ORG));
    }

    public function testNothingIsDeletedWithoutTheExactPhrase(): void
    {
        $before = $this->tables->rows;
        $key = 'tp:suggest:' . self::ORG . ':' . str_repeat('c', 40);
        TpCache::put($key, ['n' => 1], 3600);

        $refusals = [
            [],
            ['confirm' => null],
            ['confirm' => ''],
            ['confirm' => 'Delete my truck data'],
            ['confirm' => 'DELETE MY TRUCK DATA'],
            ['confirm' => 'delete my truck data '],
            ['confirm' => ' delete my truck data'],
            ['confirm' => 'delete my truck data.'],
            ['confirm' => 'delete my truck'],
            ['confirm' => 'delete  my truck data'],
            ['confirm' => true],
            ['confirm' => 1],
            ['confirm' => ['delete my truck data']],
            ['confirmation' => 'delete my truck data'],
            ['Confirm' => 'delete my truck data'],
            ['delete my truck data'],
        ];
        foreach ($refusals as $body) {
            try {
                $this->service()->deleteConfirmed(self::ORG, $body);
                self::fail('deleted with ' . json_encode($body));
            } catch (TpInvalid $e) {
                self::assertSame('confirm must be exactly: delete my truck data', $e->getMessage());
                self::assertSame(['field' => 'confirm'], $e->details());
            }
        }
        self::assertSame($before, $this->tables->rows, 'not one row is gone');
        self::assertSame([], $this->tables->statements(), 'no statement ran');
        self::assertSame(['n' => 1], TpCache::get($key));

        // With it, to the letter, the data goes. An extra key beside it is ignored, as unknown keys are.
        $deleted = $this->service()->deleteConfirmed(self::ORG, ['confirm' => 'delete my truck data', 'organization_id' => self::OTHER_ORG]);
        self::assertSame(['services' => 2, 'plan_stops' => 5, 'plans' => 5, 'leads' => 3, 'drive_overrides' => 1, 'spots' => 3, 'trucks' => 1], $deleted);
        self::assertSame(0, $this->tables->count('tp_trucks', self::ORG));
        self::assertSame(1, $this->tables->count('tp_trucks', self::OTHER_ORG), 'the organization is the caller\'s, never one the body names');
        self::assertNull(TpCache::get($key));
        self::assertSame('delete my truck data', DataPurgeService::CONFIRM_PHRASE);
    }

    public function testAnOrganizationWithoutATruckDeletesNothing(): void
    {
        $before = $this->tables->rows;
        self::assertSame(self::ZEROS, $this->service()->deleteTruckData('an-organization-without-a-truck'));
        self::assertSame($before, $this->tables->rows);
    }

    public function testTheResultsCachedForTheOrganizationAreForgottenAndItsGoogleBudgetIsNot(): void
    {
        $kept = [
            'tp:routes:day:' . self::ORG . ':20261005',                   // deleting data must not reset the budget
            'tp:scout:s:' . self::OTHER_ORG . ':' . str_repeat('a', 40),
            'tp:scout:r:' . self::OTHER_ORG . ':' . str_repeat('b', 40),
            'tp:suggest:' . self::OTHER_ORG . ':' . str_repeat('c', 40),
            'tp:nws:pt:39.003,-77.405',
            'tp:regionok:dc:dc-20261003-d0514a63:1',
            'tp:routes:refused:routes',
        ];
        $forgotten = [
            'tp:scout:s:' . self::ORG . ':' . str_repeat('a', 40),
            'tp:scout:r:' . self::ORG . ':' . str_repeat('b', 40),
            'tp:suggest:' . self::ORG . ':' . str_repeat('c', 40),
            'tp:suggest:' . self::ORG . ':' . str_repeat('d', 40),
        ];
        foreach (array_merge($kept, $forgotten) as $key) {
            TpCache::put($key, ['n' => 7], 3600);
        }

        $this->service()->deleteTruckData(self::ORG);

        foreach ($kept as $key) {
            self::assertSame(['n' => 7], TpCache::get($key), $key);
        }
        foreach ($forgotten as $key) {
            self::assertNull(TpCache::get($key), $key);
        }
        self::assertSame(7, TpCache::count('tp:routes:day:' . self::ORG . ':20261005'));
    }

    public function testACacheThatCannotBeFlushedDoesNotUndoOrFailTheDeletion(): void
    {
        TpCache::wire(new FixedClock(), new class implements TpCacheStore {
            public function get(string $key): ?string
            {
                return null;
            }

            public function set(string $key, string $value, int $ttlSeconds): void
            {
            }

            public function flush(string $prefix): void
            {
                throw new \RuntimeException('the cache table is locked, key=SECRET-VALUE');
            }
        });

        $deleted = [];
        $lines = LogCapture::during(function () use (&$deleted): void {
            $deleted = $this->service()->deleteTruckData(self::ORG);
        });

        self::assertSame(1, $deleted['trucks']);
        self::assertSame(0, $this->tables->count('tp_trucks', self::ORG));
        self::assertCount(3, $lines);
        foreach ($lines as $line) {
            self::assertStringStartsWith('[tp] cached results not forgotten after a data deletion: RuntimeException: ', $line);
            self::assertStringNotContainsString('SECRET-VALUE', $line, 'a log line is redacted');
        }
    }

    public function testAFailedDeletionKeepsEveryRowAndEveryCachedResult(): void
    {
        $before = $this->tables->rows;
        $key = 'tp:suggest:' . self::ORG . ':' . str_repeat('c', 40);
        TpCache::put($key, ['n' => 1], 3600);
        $this->tables->failOn('DELETE FROM tp_trucks');

        try {
            $this->service()->deleteTruckData(self::ORG);
            self::fail('the failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame('database failure (test)', $e->getMessage());
        }

        self::assertSame($before, $this->tables->rows);
        self::assertSame(['n' => 1], TpCache::get($key));
    }

    // ------------------------------------------------------------------------------------ the daily sweep

    public function testTheSweepAsksEachRepositoryForItsOwnPurge(): void
    {
        $service = new SweepingPurge(new TruckDataRepository($this->tables));
        $service->installed = [
            'DriveLegRepository' => new PurgingRepository(4),
            'ScoutLeadRepository' => new PurgingRepository(1),
            'PlanRepository' => new PurgingRepository(2),
        ];

        $result = $service->purgeGoogleCaches();

        self::assertSame(['drive_legs' => 4, 'lead_contacts' => 1, 'plan_snapshots' => 2, 'failed' => []], $result);
        // Every expired leg (no limit), every organization's leads, every organization's plan results.
        self::assertSame([['purgeExpired', [0]]], $service->installed['DriveLegRepository']->calls);
        self::assertSame([['purgeExpiredGoogle', [null]]], $service->installed['ScoutLeadRepository']->calls);
        self::assertSame([['purgeExpiredSnapshots', [null]]], $service->installed['PlanRepository']->calls);
        self::assertSame(
            ['App\\TruckPlanner\\Data\\DriveLegRepository', 'App\\TruckPlanner\\Data\\ScoutLeadRepository', 'App\\TruckPlanner\\Data\\PlanRepository'],
            $service->asked
        );
        // The sweep itself writes nothing to the owner tables.
        self::assertSame([], $this->tables->statements());
    }

    public function testARepositoryThatIsNotInstalledIsSkipped(): void
    {
        $service = new SweepingPurge(new TruckDataRepository($this->tables));
        $service->installed = ['PlanRepository' => new PurgingRepository(2)];

        self::assertSame(['drive_legs' => null, 'lead_contacts' => null, 'plan_snapshots' => 2, 'failed' => []], $service->purgeGoogleCaches());

        $service->installed = [];
        self::assertSame(['drive_legs' => null, 'lead_contacts' => null, 'plan_snapshots' => null, 'failed' => []], $service->purgeGoogleCaches());
    }

    public function testAPartThatFailsIsReportedAndTheOthersStillRun(): void
    {
        $service = new SweepingPurge(new TruckDataRepository($this->tables));
        $broken = new PurgingRepository(0);
        $broken->error = new \RuntimeException('deadlock on https://example.test/?key=AIzaSECRETSECRET');
        $service->installed = [
            'DriveLegRepository' => new PurgingRepository(4),
            'ScoutLeadRepository' => $broken,
            'PlanRepository' => new PurgingRepository(2),
        ];

        $result = [];
        $lines = LogCapture::during(function () use ($service, &$result): void {
            $result = $service->purgeGoogleCaches();
        });

        self::assertSame(['drive_legs' => 4, 'lead_contacts' => null, 'plan_snapshots' => 2, 'failed' => ['lead_contacts']], $result);
        self::assertCount(1, $lines);
        self::assertStringStartsWith('[tp] purge of lead_contacts failed: RuntimeException: deadlock', $lines[0]);
        self::assertStringNotContainsString('AIza', $lines[0]);
    }

    public function testRunningTheSweepAgainChangesNothing(): void
    {
        $service = new SweepingPurge(new TruckDataRepository($this->tables));
        $service->installed = [
            'DriveLegRepository' => new PurgingRepository(4),
            'ScoutLeadRepository' => new PurgingRepository(1),
            'PlanRepository' => new PurgingRepository(2),
        ];
        $service->purgeGoogleCaches();
        self::assertSame(['drive_legs' => 0, 'lead_contacts' => 0, 'plan_snapshots' => 0, 'failed' => []], $service->purgeGoogleCaches());
    }

    public function testADryRunCountsWhatHasExpiredAndAsksNoRepositoryToPurge(): void
    {
        $service = new SweepingPurge(new TruckDataRepository($this->tables));
        $service->installed = ['DriveLegRepository' => new PurgingRepository(4)];
        $before = $this->tables->rows;

        // The fixture, as of 2026-10-05 12:00: one cached leg of 31 days, one lead looked up 40 days ago,
        // one plan result with Google legs of 35 days.
        self::assertSame(['drive_legs' => 1, 'lead_contacts' => 1, 'plan_snapshots' => 1, 'failed' => []], $service->purgeGoogleCaches(true));

        self::assertSame([], $service->asked);
        self::assertSame([], $service->installed['DriveLegRepository']->calls);
        self::assertSame($before, $this->tables->rows);
        self::assertCount(1, $this->tables->statements());
        self::assertStringStartsWith('SELECT (SELECT COUNT(*) FROM tp_drive_legs', $this->tables->statements()[0]);
    }

    public function testARepositoryIsFoundByItsClassAndAMissingOneIsNot(): void
    {
        $service = new SweepingPurge(new TruckDataRepository($this->tables));
        self::assertNull($service->real('App\\TruckPlanner\\Data\\NoSuchRepository'));
        self::assertInstanceOf(TruckDataRepository::class, $service->real(TruckDataRepository::class));
    }

    public function testTheInstalledRepositoriesOfferThePurgeTheSweepCalls(): void
    {
        // The three belong to other packages. Where one is installed, it must answer the call of the sweep.
        $sweeps = (new \ReflectionClassConstant(DataPurgeService::class, 'SWEEPS'))->getValue();
        self::assertSame(['drive_legs', 'lead_contacts', 'plan_snapshots'], array_keys($sweeps));
        self::assertSame(
            [
                ['App\\TruckPlanner\\Data\\DriveLegRepository', 'purgeExpired', 0],
                ['App\\TruckPlanner\\Data\\ScoutLeadRepository', 'purgeExpiredGoogle', null],
                ['App\\TruckPlanner\\Data\\PlanRepository', 'purgeExpiredSnapshots', null],
            ],
            array_values($sweeps)
        );
        foreach ($sweeps as [$class, $method]) {
            if (!class_exists($class)) {
                continue;
            }
            $purge = new \ReflectionMethod($class, $method);
            self::assertTrue($purge->isPublic(), $class . '::' . $method);
            self::assertLessThanOrEqual(1, $purge->getNumberOfRequiredParameters(), $class . '::' . $method);
            self::assertSame('int', (string) $purge->getReturnType(), $class . '::' . $method);
            self::assertSame(0, (new \ReflectionClass($class))->getConstructor()?->getNumberOfRequiredParameters() ?? 0, $class . ' is built without arguments');
        }
    }
}

/**
 * The service under test with the repositories of the other packages replaced: `$installed` maps a class
 * base name to the object that stands in for it; a name that is absent is a package that is not installed.
 */
final class SweepingPurge extends DataPurgeService
{
    /** @var array<string, PurgingRepository> */
    public array $installed = [];

    /** @var list<string> the classes that were asked for, in order */
    public array $asked = [];

    protected function repository(string $class): ?object
    {
        $this->asked[] = $class;
        $name = substr($class, (int) strrpos($class, '\\') + 1);
        return $this->installed[$name] ?? null;
    }

    /** What the real lookup answers. */
    public function real(string $class): ?object
    {
        return parent::repository($class);
    }
}

/**
 * Stands in for a repository with a purge method: the first call answers the number it was given, every
 * later one 0 (nothing is left to purge), as the real methods do.
 */
final class PurgingRepository
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    public array $calls = [];
    public ?\Throwable $error = null;
    private int $expired;

    public function __construct(int $expired)
    {
        $this->expired = $expired;
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): int
    {
        $this->calls[] = [$method, $arguments];
        if ($this->error !== null) {
            throw $this->error;
        }
        $count = $this->expired;
        $this->expired = 0;
        return $count;
    }
}
