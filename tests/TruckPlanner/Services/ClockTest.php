<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\TruckPlanner\Services\Support\Clock;
use PHPUnit\Framework\TestCase;

/**
 * The suite runs under several process time zones (date.timezone): nothing here may depend on it.
 */
final class ClockTest extends TestCase
{
    public function testNowIsUtcWhateverTheProcessZone(): void
    {
        $clock = new Clock();
        $now = $clock->nowUtc();
        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertSame(0, $now->getOffset());
        self::assertEqualsWithDelta($now->getTimestamp(), $clock->epoch(), 2);
        self::assertGreaterThan(1_700_000_000, $clock->epoch());
    }

    public function testTodayAndMinuteFollowTheNamedZoneNotTheProcess(): void
    {
        // 03:30 UTC on 5 October is still the evening of the 4th in New York and already 17:30 on Kiritimati.
        $clock = new FixedClock('2026-10-05 03:30:00');
        self::assertSame('2026-10-05', $clock->today('UTC'));
        self::assertSame(210, $clock->minuteOfDay('UTC'));
        self::assertSame('2026-10-04', $clock->today('America/New_York'));
        self::assertSame(23 * 60 + 30, $clock->minuteOfDay('America/New_York'));
        self::assertSame('2026-10-04', $clock->today('America/Los_Angeles'));
        self::assertSame(20 * 60 + 30, $clock->minuteOfDay('America/Los_Angeles'));
        self::assertSame('2026-10-05', $clock->today('Pacific/Kiritimati'));
        self::assertSame(17 * 60 + 30, $clock->minuteOfDay('Pacific/Kiritimati'));
        self::assertSame(1791171000, $clock->epoch());
    }

    public function testMidnightAndTheLastMinute(): void
    {
        $clock = new FixedClock('2026-01-01 05:00:00');
        self::assertSame('2026-01-01', $clock->today('America/New_York'));
        self::assertSame(0, $clock->minuteOfDay('America/New_York'));
        $clock->advance(-60);
        self::assertSame('2025-12-31', $clock->today('America/New_York'));
        self::assertSame(1439, $clock->minuteOfDay('America/New_York'));
    }

    public function testDaylightSavingDaysUseTheWallClock(): void
    {
        // 2026-11-01 06:30 UTC is 01:30 EST, after the clocks went back at 06:00 UTC.
        $clock = new FixedClock('2026-11-01 05:30:00');
        self::assertSame(90, $clock->minuteOfDay('America/New_York'));          // 01:30 EDT
        $clock->advance(3600);
        self::assertSame(90, $clock->minuteOfDay('America/New_York'));          // 01:30 EST, the repeated hour
        self::assertSame('2026-11-01', $clock->today('America/New_York'));
    }

    public function testLocalOfInstant(): void
    {
        $clock = new Clock();
        self::assertSame(
            ['date' => '2026-10-04', 'minute' => 19 * 60 + 41],
            $clock->localOfInstant('2026-10-04T23:41:07+00:00', 'America/New_York')
        );
        self::assertSame(
            ['date' => '2026-10-05', 'minute' => 13 * 60 + 41],
            $clock->localOfInstant('2026-10-04T23:41:07+00:00', 'Pacific/Kiritimati')
        );
        self::assertSame(
            ['date' => '2026-10-04', 'minute' => 1181],
            $clock->localOfInstant('2026-10-04T19:41:07-04:00', 'America/New_York')
        );
        self::assertSame(['date' => '2026-10-04', 'minute' => 1421], $clock->localOfInstant('2026-10-04T23:41:07Z', 'UTC'));
        self::assertSame(['date' => '2026-10-04', 'minute' => 1421], $clock->localOfInstant('2026-10-04T23:41:07.250+00:00', 'UTC'));
    }

    public function testLocalOfInstantRefusesWhatIsNotAnInstant(): void
    {
        $clock = new Clock();
        foreach (['', 'tomorrow', '2026-10-04', '2026-10-04 23:41:07', '2026-10-04T23:41:07', '2026-02-30T10:00:00+00:00', '2026-10-04T25:00:00Z', 'x2026-10-04T23:41:07Z'] as $text) {
            self::assertNull($clock->localOfInstant($text, 'America/New_York'), 'accepted: ' . $text);
        }
    }

    public function testParseHttpDate(): void
    {
        $clock = new Clock();
        self::assertSame(1791157267, $clock->parseHttpDate('Sun, 04 Oct 2026 23:41:07 GMT'));
        self::assertSame(0, $clock->parseHttpDate('Thu, 01 Jan 1970 00:00:00 GMT'));
        self::assertSame(1798761599, $clock->parseHttpDate('Thu, 31 Dec 2026 23:59:59 GMT'));
    }

    public function testParseHttpDateRefusesAnythingElse(): void
    {
        $clock = new Clock();
        $bad = [
            '',
            'Sun, 04 Oct 2026 23:41:07',                 // no zone
            'Sun, 04 Oct 2026 23:41:07 UTC',
            'Sun, 04 Oct 2026 23:41:07 +0000',
            'Mon, 04 Oct 2026 23:41:07 GMT',             // 4 October 2026 is a Sunday
            'Sun, 4 Oct 2026 23:41:07 GMT',
            'Sun, 31 Feb 2026 10:00:00 GMT',
            'Sunday, 04-Oct-26 23:41:07 GMT',
            '2026-10-04T23:41:07Z',
            'Sun, 04 Oct 2026 23:41:07 GMT ',
        ];
        foreach ($bad as $text) {
            self::assertNull($clock->parseHttpDate($text), 'accepted: "' . $text . '"');
        }
    }

    public function testIsZone(): void
    {
        self::assertTrue(Clock::isZone('America/New_York'));
        self::assertTrue(Clock::isZone('UTC'));
        self::assertTrue(Clock::isZone('Pacific/Kiritimati'));
        foreach (['', 'EST', 'New York', 'america/new_york', 'America/Sterling', '+05:00', 'US/Eastern'] as $name) {
            self::assertFalse(Clock::isZone($name), 'accepted: ' . $name);
        }
    }

    public function testTheZoneOfATruckIsItsOwnOrTheDefaultWhenThisServerDoesNotKnowIt(): void
    {
        $lines = LogCapture::during(static function (): void {
            self::assertSame('America/Chicago', Clock::zoneOf(['timezone' => 'America/Chicago']));
        });
        self::assertSame([], $lines, 'a zone the server knows is taken as it is, without a word');

        foreach ([['timezone' => 'Mars/Olympus_Mons'], ['timezone' => 'US/Eastern'], ['timezone' => ''], ['timezone' => null], []] as $truck) {
            $lines = LogCapture::during(static function () use ($truck): void {
                $zone = Clock::zoneOf($truck);
                self::assertSame('America/New_York', $zone);
                // and a clock can read the day there: nothing is thrown for the stored name
                self::assertSame('2026-10-04', (new FixedClock('2026-10-05 03:30:00'))->today($zone));
            });
            self::assertSame(['[tp] a truck has a time zone this server does not know, the default zone is used'], $lines);
        }
    }

    public function testFixedClockMoves(): void
    {
        $clock = new FixedClock('2026-10-04 12:00:00');
        $start = $clock->epoch();
        self::assertSame($start + 90, $clock->advance(90)->epoch());
        self::assertSame('2026-12-25', $clock->set('2026-12-25 00:00:00')->today('UTC'));
    }
}
