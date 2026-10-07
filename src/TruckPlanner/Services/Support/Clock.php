<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

/**
 * The only reader of the wall clock in Truck Planner, and the only user of DateTimeImmutable.
 *
 * Every other class asks a Clock for "today" and "now" and names the time zone it means, so nothing
 * depends on the process time zone. The model never sees a Clock: it is given dates and minutes.
 * Row timestamps and freshness tests in SQL use MySQL NOW() instead (one clock decides age there).
 *
 * Injectable: services take `?Clock $clock = null`. A test passes a fixed clock, which overrides nowUtc();
 * every other method derives from it.
 */
class Clock
{
    private const HTTP_DATE = 'D, d M Y H:i:s';

    /** The present instant, in UTC. */
    public function nowUtc(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** Seconds since 1970-01-01T00:00:00Z. */
    public function epoch(): int
    {
        return $this->nowUtc()->getTimestamp();
    }

    /** The civil date "YYYY-MM-DD" in the zone `$tz` (an IANA name, or "UTC"). */
    public function today(string $tz): string
    {
        return $this->nowUtc()->setTimezone(new \DateTimeZone($tz))->format('Y-m-d');
    }

    /** Minutes since local midnight (0 .. 1439) in the zone `$tz`. */
    public function minuteOfDay(string $tz): int
    {
        $local = $this->nowUtc()->setTimezone(new \DateTimeZone($tz));
        return (int) $local->format('G') * 60 + (int) $local->format('i');
    }

    /**
     * The civil date and the minute of day, in the zone `$tz`, of an ISO 8601 instant such as
     * "2026-10-04T23:41:07+00:00" (an offset or "Z" is required, a fraction of a second is accepted).
     *
     * @return array{date: string, minute: int}|null null when the text does not parse
     */
    public function localOfInstant(string $iso, string $tz): ?array
    {
        $instant = null;
        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $iso);
            if ($parsed !== false && self::cleanParse()) {
                $instant = $parsed;
                break;
            }
        }
        if ($instant === null) {
            return null;
        }
        $local = $instant->setTimezone(new \DateTimeZone($tz));
        return [
            'date' => $local->format('Y-m-d'),
            'minute' => (int) $local->format('G') * 60 + (int) $local->format('i'),
        ];
    }

    /**
     * The epoch of an HTTP date in the form "Sun, 04 Oct 2026 23:41:07 GMT" (always UTC).
     *
     * @return int|null null when the text is empty or is not exactly such a date
     */
    public function parseHttpDate(string $s): ?int
    {
        if ($s === '') {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $parsed = \DateTimeImmutable::createFromFormat('!' . self::HTTP_DATE . ' \G\M\T', $s, $utc);
        if ($parsed === false || !self::cleanParse()) {
            return null;
        }
        // A wrong day name or an impossible day is moved to another date by the parser: refuse it.
        if ($parsed->format(self::HTTP_DATE) . ' GMT' !== $s) {
            return null;
        }
        return $parsed->getTimestamp();
    }

    /** Is `$name` an IANA time zone name this PHP knows (for example "America/New_York")? */
    public static function isZone(string $name): bool
    {
        return in_array($name, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * The time zone in which "today" and "now" are read for a truck: its stored zone, or the default zone
     * (`regions.default_timezone`) when this server's zone database does not hold that name. A stored name
     * must not take a request down, and the log says when the default stood in.
     *
     * @param array<string, mixed> $truck the truck value or a truck row (its `timezone` is read)
     */
    public static function zoneOf(array $truck): string
    {
        $zone = (string) ($truck['timezone'] ?? '');
        if (self::isZone($zone)) {
            return $zone;
        }
        error_log('[tp] a truck has a time zone this server does not know, the default zone is used');
        return (string) TpConfig::get('regions.default_timezone');
    }

    /** True when the last createFromFormat reported neither an error nor a warning. */
    private static function cleanParse(): bool
    {
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors === false) {
            return true;
        }
        return $errors['warning_count'] === 0 && $errors['error_count'] === 0;
    }
}
