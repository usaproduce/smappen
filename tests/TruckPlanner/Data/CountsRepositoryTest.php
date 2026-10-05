<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\CountsRepository;
use PHPUnit\Framework\TestCase;

final class CountsRepositoryTest extends TestCase
{
    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const TRUCK = '33333333-3333-4333-8333-333333333333';

    public function testCountsOfAnOrganization(): void
    {
        $db = (new RecordingDatabase())->queue(['spots' => '5', 'plans' => 1, 'services' => '12', 'leads' => 3]);
        $counts = (new CountsRepository($db))->forOrg(self::ORG);
        self::assertSame(['spots' => 5, 'plans' => 1, 'services' => 12, 'leads' => 3], $counts);

        self::assertCount(1, $db->calls);
        $call = $db->calls[0];
        self::assertSame('fetch', $call['kind']);
        // four owner tables, each with its own organization filter and its own bound id
        self::assertSame(4, substr_count($call['sql'], 'organization_id = ?'));
        self::assertSame([self::ORG, self::ORG, self::ORG, self::ORG], $call['params']);
        foreach (['tp_spots', 'tp_plans', 'tp_service_logs', 'tp_scout_leads'] as $table) {
            self::assertSame(1, substr_count($call['sql'], 'FROM ' . $table . ' WHERE organization_id = ?'));
        }
        // archived spots are not counted
        self::assertStringContainsString('FROM tp_spots WHERE organization_id = ? AND archived_at IS NULL', $call['sql']);
        self::assertStringNotContainsString('SELECT *', $call['sql']);
    }

    public function testCountsWithoutRows(): void
    {
        $counts = (new CountsRepository(new RecordingDatabase()))->forOrg(self::ORG);
        self::assertSame(['spots' => 0, 'plans' => 0, 'services' => 0, 'leads' => 0], $counts);
    }

    public function testLogsBySpot(): void
    {
        $db = (new RecordingDatabase())->queue([
            ['spot_id' => 'spot-a', 'log_count' => '3', 'last_date' => '2026-10-01'],
            ['spot_id' => 'spot-b', 'log_count' => 1, 'last_date' => '2026-08-14'],
        ]);
        $logs = (new CountsRepository($db))->logsBySpot(self::ORG, self::TRUCK);
        self::assertSame(
            [
                'spot-a' => ['count' => 3, 'last_date' => '2026-10-01'],
                'spot-b' => ['count' => 1, 'last_date' => '2026-08-14'],
            ],
            $logs
        );
        $call = $db->only('FROM tp_service_logs');
        self::assertSame('fetchAll', $call['kind']);
        self::assertStringContainsString('WHERE organization_id = ? AND truck_id = ? AND spot_id IS NOT NULL', $call['sql']);
        self::assertStringContainsString('GROUP BY spot_id', $call['sql']);
        self::assertSame([self::ORG, self::TRUCK], $call['params']);
    }

    public function testNoLogs(): void
    {
        self::assertSame([], (new CountsRepository(new RecordingDatabase()))->logsBySpot(self::ORG, self::TRUCK));
    }
}
