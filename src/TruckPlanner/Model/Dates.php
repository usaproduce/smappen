<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * Civil dates and federal holidays, computed by hand on day numbers (02_MODEL.md 4.1).
 *
 * No clock and no time zone: a date is the string "YYYY-MM-DD" of a civil day in the truck's region, and
 * the only source of a day of the week is dayOfWeek(). Integers only.
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Dates
{
    /** Days since 1970-01-01 of a proleptic Gregorian civil date. */
    public static function daysFromCivil(int $y, int $m, int $d): int
    {
        $y2 = $m <= 2 ? $y - 1 : $y;
        $era = Num::floorDiv($y2, 400);
        $yoe = $y2 - $era * 400;
        $mp = Num::modFloor($m + 9, 12);                  // March = 0 ... February = 11
        $doy = Num::floorDiv(153 * $mp + 2, 5) + $d - 1;
        $doe = $yoe * 365 + Num::floorDiv($yoe, 4) - Num::floorDiv($yoe, 100) + $doy;
        return $era * 146097 + $doe - 719468;
    }

    /**
     * The inverse of daysFromCivil.
     *
     * @return array{0: int, 1: int, 2: int} [y, m, d]
     */
    public static function civilFromDays(int $z): array
    {
        $z += 719468;
        $era = Num::floorDiv($z, 146097);
        $doe = $z - $era * 146097;
        $yoe = Num::floorDiv(
            $doe - Num::floorDiv($doe, 1460) + Num::floorDiv($doe, 36524) - Num::floorDiv($doe, 146096),
            365
        );
        $y = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + Num::floorDiv($yoe, 4) - Num::floorDiv($yoe, 100));
        $mp = Num::floorDiv(5 * $doy + 2, 153);
        $d = $doy - Num::floorDiv(153 * $mp + 2, 5) + 1;
        $m = $mp < 10 ? $mp + 3 : $mp - 9;
        return [$m <= 2 ? $y + 1 : $y, $m, $d];
    }

    /**
     * Exactly "YYYY-MM-DD" in ASCII digits, a real date, 1970 <= y <= 2199; otherwise invalid_date.
     *
     * @return array{0: int, 1: int, 2: int} [y, m, d]
     */
    public static function parseDate(mixed $s): array
    {
        $ok = is_string($s) && strlen($s) === 10 && $s[4] === '-' && $s[7] === '-';
        if ($ok) {
            foreach ([0, 1, 2, 3, 5, 6, 8, 9] as $i) {
                $byte = ord($s[$i]);
                if ($byte < 48 || $byte > 57) {
                    $ok = false;
                }
            }
        }
        if (!$ok) {
            throw new ModelError(ModelError::INVALID_DATE);
        }
        $y = (int) substr($s, 0, 4);
        $m = (int) substr($s, 5, 2);
        $d = (int) substr($s, 8, 2);
        if ($y < 1970 || $y > 2199 || self::civilFromDays(self::daysFromCivil($y, $m, $d)) !== [$y, $m, $d]) {
            throw new ModelError(ModelError::INVALID_DATE);
        }
        return [$y, $m, $d];
    }

    /** Zero-padded "YYYY-MM-DD". */
    public static function formatDate(int $y, int $m, int $d): string
    {
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /** The day number of a date string (parses it: invalid_date). */
    public static function dayNumber(mixed $date): int
    {
        [$y, $m, $d] = self::parseDate($date);
        return self::daysFromCivil($y, $m, $d);
    }

    /** The date string of a day number. Never parses, so it accepts any year. */
    public static function dateOfDay(int $z): string
    {
        [$y, $m, $d] = self::civilFromDays($z);
        return self::formatDate($y, $m, $d);
    }

    /** 0 = Monday ... 6 = Sunday. */
    public static function dayOfWeek(mixed $date): int
    {
        return Num::modFloor(self::dayNumber($date) + 3, 7);
    }

    public static function addDays(mixed $date, int $n): string
    {
        return self::dateOfDay(self::dayNumber($date) + $n);
    }

    /** The n-th (1-based) given weekday of a month, as a date. */
    public static function nthWeekday(int $year, int $month, int $dow, int $n): string
    {
        return self::dateOfDay(self::nthWeekdayDay($year, $month, $dow, $n));
    }

    /** The last given weekday of a month, as a date. */
    public static function lastWeekday(int $year, int $month, int $dow): string
    {
        return self::dateOfDay(self::lastWeekdayDay($year, $month, $dow));
    }

    /**
     * Holidays whose actual date is in `year`, sorted by (date, rule order). Works on day numbers, so it
     * accepts any year (holidayOn asks for the year after the date's).
     *
     * @param array<string, mixed> $flags { inauguration_day: bool }
     * @return list<array{id: string, name: string, class: string, date: string, observed: ?string}>
     */
    public static function federalHolidays(int $year, array $flags): array
    {
        $found = [];
        $order = 0;
        foreach (Seeds::data()['holidays']['rules'] as $rule) {
            $order += 1;                                  // rule order 1..12 = position in the file
            if (array_key_exists('from_year', $rule) && $year < $rule['from_year']) {
                continue;
            }
            if (array_key_exists('region_flag', $rule) && ($flags[$rule['region_flag']] ?? null) !== true) {
                continue;
            }
            $kind = $rule['rule'];
            if ($kind === 'fixed') {
                $day = self::daysFromCivil($year, $rule['month'], $rule['day']);
                $dow = Num::modFloor($day + 3, 7);
                $observed = $dow === 5 ? $day - 1 : ($dow === 6 ? $day + 1 : $day);
            } elseif ($kind === 'nth_weekday') {
                $day = self::nthWeekdayDay($year, $rule['month'], $rule['dow'], $rule['n']);
                $observed = $day;
            } elseif ($kind === 'last_weekday') {
                $day = self::lastWeekdayDay($year, $rule['month'], $rule['dow']);
                $observed = $day;
            } else {                                      // "inauguration"
                if ($year < 1969 || Num::modFloor($year - 1965, 4) !== 0) {
                    continue;
                }
                $day = self::daysFromCivil($year, $rule['month'], $rule['day']);
                $dow = Num::modFloor($day + 3, 7);
                $observed = $dow === 6 ? $day + 1 : ($dow === 5 ? null : $day);      // no day in lieu of a Saturday
            }
            $found[] = [$day, $order, [
                'id' => $rule['id'],
                'name' => $rule['name'],
                'class' => $rule['class'],
                'date' => self::dateOfDay($day),
                'observed' => $observed === null ? null : self::dateOfDay($observed),
            ]];
        }
        usort($found, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
        $out = [];
        foreach ($found as $item) {
            $out[] = $item[2];
        }
        return $out;
    }

    /**
     * The federal holiday observed on `date`, or null. New Year's Day can be observed on 31 December of the
     * year before, so two years are searched. Major beats minor, then the earlier rule.
     *
     * @param array<string, mixed> $flags
     * @return array{id: string, name: string, class: string, date: string, observed: ?string}|null
     */
    public static function holidayOn(mixed $date, array $flags): ?array
    {
        [$y] = self::parseDate($date);
        $best = null;
        $bestClass = 0;
        $bestOrder = 0;
        foreach ([$y, $y + 1] as $year) {
            foreach (self::federalHolidays($year, $flags) as $h) {
                if ($h['observed'] !== $date) {
                    continue;
                }
                $class = $h['class'] === 'major' ? 0 : 1;
                $order = self::ruleOrder($h['id']);
                if ($best === null || $class < $bestClass || ($class === $bestClass && $order < $bestOrder)) {
                    $best = $h;
                    $bestClass = $class;
                    $bestOrder = $order;
                }
            }
        }
        return $best;
    }

    private static function nthWeekdayDay(int $year, int $month, int $dow, int $n): int
    {
        $first = self::daysFromCivil($year, $month, 1);
        return $first + Num::modFloor($dow - Num::modFloor($first + 3, 7), 7) + 7 * ($n - 1);
    }

    private static function lastWeekdayDay(int $year, int $month, int $dow): int
    {
        $nextFirst = $month === 12 ? self::daysFromCivil($year + 1, 1, 1) : self::daysFromCivil($year, $month + 1, 1);
        $last = $nextFirst - 1;
        return $last - Num::modFloor(Num::modFloor($last + 3, 7) - $dow, 7);
    }

    private static function ruleOrder(string $holidayId): int
    {
        $order = 0;
        foreach (Seeds::data()['holidays']['rules'] as $rule) {
            $order += 1;
            if ($rule['id'] === $holidayId) {
                return $order;
            }
        }
        return $order + 1;
    }
}
