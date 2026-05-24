<?php
namespace App\Tests\Services;

use App\Services\TrafficService;
use PHPUnit\Framework\TestCase;

class TrafficServiceTest extends TestCase
{
    public function testMultiplierNeverBelowOne(): void
    {
        // Sundays at 3AM are about as free-flow as it gets.
        $this->assertEquals(1.0, TrafficService::multiplier('sunday', 3));
        $this->assertEquals(1.0, TrafficService::multiplier('saturday', 3));
    }

    public function testRushHourIsHeavier(): void
    {
        $rush = TrafficService::multiplier('monday', 8);
        $midday = TrafficService::multiplier('monday', 12);
        $this->assertGreaterThan($midday, $rush, 'Mon 8AM should be slower than Mon midday');
        $this->assertGreaterThanOrEqual(1.4, $rush);
    }

    public function testAdjustedMinutesScalesDownByMultiplier(): void
    {
        $mult = TrafficService::multiplier('friday', 17); // Fri 5PM
        $this->assertSame((int) round(15 / $mult), TrafficService::adjustedMinutes(15, 'friday', 17));
    }

    public function testInvalidDayDefaultsMonday(): void
    {
        // Unknown weekday names fall through to Monday — same as monday's profile.
        $this->assertEquals(
            TrafficService::multiplier('monday', 12),
            TrafficService::multiplier('not-a-day', 12)
        );
    }

    public function testHourClampedTo23(): void
    {
        // 25 → 23 (last valid hour). No throws, no PHP notices.
        $this->assertEquals(
            TrafficService::multiplier('monday', 23),
            TrafficService::multiplier('monday', 25)
        );
    }

    public function testWindowsCoverFullWeek(): void
    {
        $windows = TrafficService::windows();
        $this->assertGreaterThanOrEqual(6, count($windows));
        foreach ($windows as $w) {
            $this->assertArrayHasKey('key', $w);
            $this->assertArrayHasKey('day', $w);
            $this->assertArrayHasKey('hour', $w);
        }
    }
}
