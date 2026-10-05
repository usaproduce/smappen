<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Support;

use App\TruckPlanner\Services\Support\Clock;

/**
 * A Clock that stands still until the test moves it. Everything Clock offers derives from nowUtc(), so
 * today(), minuteOfDay() and epoch() follow.
 *
 *     $clock = new FixedClock('2026-10-04 23:41:07');      // UTC
 *     $clock->today('America/New_York');                    // "2026-10-04"
 *     $clock->advance(3600);
 */
final class FixedClock extends Clock
{
    private \DateTimeImmutable $now;

    /** @param string $utc "YYYY-MM-DD HH:MM:SS", read as UTC */
    public function __construct(string $utc = '2026-10-04 12:00:00')
    {
        $this->set($utc);
    }

    public function nowUtc(): \DateTimeImmutable
    {
        return $this->now;
    }

    /** @param string $utc "YYYY-MM-DD HH:MM:SS", read as UTC */
    public function set(string $utc): self
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new \LogicException('FixedClock: not a "YYYY-MM-DD HH:MM:SS" instant: ' . $utc);
        }
        $this->now = $parsed;
        return $this;
    }

    public function advance(int $seconds): self
    {
        $this->now = $this->now->setTimestamp($this->now->getTimestamp() + $seconds);
        return $this;
    }
}
