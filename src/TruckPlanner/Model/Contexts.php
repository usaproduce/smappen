<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * The context of one civil date: day types and Monday-Friday factors per segment, the holiday, the traffic
 * row, and the forecast and fuel price handed through unchanged (02_MODEL.md 4.1).
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Contexts
{
    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed>|null $hol
     * @param array<int, mixed>|null $forecast
     * @return array<string, mixed> DayContext
     */
    public static function makeContext(
        array $A,
        ?string $date,
        int $dow,
        int $effDow,
        ?string $cls,
        ?array $hol,
        ?string $treatAs,
        ?array $forecast,
        ?float $fuelPricePerGal,
        ?string $fuelPriceSource,
        bool $typical
    ): array {
        $baseType = $effDow <= 4 ? 'weekday' : ($effDow === 5 ? 'saturday' : 'sunday');
        $dayType = [];
        $dowFactor = [];
        for ($s = 0; $s < Vocab::NSEG; $s++) {
            $name = Vocab::SEGMENTS[$s];
            $t = $baseType;
            if ($cls !== null) {
                $t = Seeds::read($A, 'segments.' . $name . '.holiday_day_type.' . $cls);
                if ($t === 'weekday') {
                    $t = $baseType;
                }
            }
            $dayType[] = $t;
            $dowFactor[] = $t === 'weekday'
                ? Num::f(Seeds::read($A, 'segments.' . $name . '.dow_factor')[$effDow])
                : 1.0;
        }
        return [
            'date' => $date,
            'typical' => $typical,
            'dow' => $dow,
            'eff_dow' => $effDow,
            'holiday' => $hol,                            // kept even when treat_as suppresses its effect
            'holiday_class' => $cls,
            'treat_as' => $treatAs,
            'day_type' => $dayType,
            'dow_factor' => $dowFactor,
            'traffic_dow' => $cls === 'major' ? 6 : $effDow,
            'forecast' => $forecast,
            'fuel_price_per_gal' => $fuelPricePerGal,
            'fuel_price_source' => $fuelPriceSource,
        ];
    }

    /**
     * @param array<string, mixed> $A
     * @param array<int, mixed>|null $forecast
     * @return array<string, mixed> DayContext
     */
    public static function dayContext(
        array $A,
        mixed $date,
        ?string $treatAs,
        ?array $forecast,
        ?float $fuelPricePerGal,
        ?string $fuelPriceSource
    ): array {
        $dow = Dates::dayOfWeek($date);
        $hol = Dates::holidayOn($date, $A['region']['flags']);
        $effDow = $dow;
        $cls = $hol !== null ? $hol['class'] : null;
        if ($treatAs !== null && isset(Vocab::DOW_INDEX[$treatAs])) {
            $effDow = Vocab::DOW_INDEX[$treatAs];         // behave like that day of the week
            $cls = null;
        } elseif ($treatAs === 'holiday') {
            $cls = 'major';
        } elseif ($treatAs === 'normal') {
            $cls = null;
        }
        return self::makeContext($A, $date, $dow, $effDow, $cls, $hol, $treatAs, $forecast, $fuelPricePerGal, $fuelPriceSource, false);
    }

    /**
     * A typical week: no date, no holiday, no weather, no fuel price.
     *
     * @param array<string, mixed> $A
     * @return array<string, mixed> DayContext
     */
    public static function typicalContext(array $A, int $dow): array
    {
        return self::makeContext($A, null, $dow, $dow, null, null, null, null, null, null, true);
    }
}
