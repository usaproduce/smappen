<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * The weather multiplier of one forecast hour (02_MODEL.md 4.6).
 *
 * The class of precipitation says how bad it is if it does precipitate; the forecast probability says how
 * likely that is. Bands are step functions.
 *
 * The function of the document is multiplier(). It is made of two steps so that an hour is classified once
 * and both settings read their multipliers from that: classify() (bands, class, probability: the same for
 * "open" and "captive") and detail() (the multipliers of one setting and their product).
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Weather
{
    /**
     * weather_multiplier(A, fc, setting): multiplier on walk-up demand for one forecast hour. setting is
     * "open" (people outdoors or walking over) or "captive" (people already inside a venue).
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed>|null $fc HourForecast
     * @return array<string, mixed> WeatherDetail
     */
    public static function multiplier(array $A, ?array $fc, string $setting): array
    {
        return self::detail($A, self::classify($A, $fc), $setting);
    }

    /**
     * What a forecast hour is, whatever the setting: its temperature band, its wind band, its precipitation
     * class and the probability applied to it. Null when there is no usable forecast (no record, or a
     * record whose four fields are all null).
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed>|null $fc HourForecast
     * @return array{temp_band: ?string, wind_band: ?string, precip_class: string, precip_p: float}|null
     */
    public static function classify(array $A, ?array $fc): ?array
    {
        $tempF = $fc['temp_f'] ?? null;
        $precipProb = $fc['precip_prob'] ?? null;
        $shortForecast = $fc['short_forecast'] ?? null;
        $windMph = $fc['wind_mph'] ?? null;
        if ($fc === null || ($tempF === null && $precipProb === null && $shortForecast === null && $windMph === null)) {
            return null;
        }
        $tempBand = $tempF !== null ? self::band($A, 'temperature_bands', 'upper_f', Num::f($tempF)) : null;
        $windBand = $windMph !== null ? self::band($A, 'wind_bands', 'upper_mph', Num::f($windMph)) : null;
        $cls = 'dry';
        if ($shortForecast !== null) {
            $text = self::asciiLower($shortForecast);
            foreach (Seeds::read($A, 'weather.precip_classes.order') as $classId) {     // listed order, first hit wins
                $hit = false;
                foreach (Seeds::read($A, 'weather.precip_classes.rows.' . $classId . '.match') as $needle) {
                    if (str_contains($text, $needle)) {
                        $hit = true;
                    }
                }
                if ($hit) {
                    $cls = $classId;
                    break;
                }
            }
        }
        if ($cls === 'dry') {
            $p = 0.0;
        } elseif ($precipProb === null) {
            $p = Num::f(Seeds::read($A, 'weather.pop_when_missing'));
        } else {
            $p = Num::clamp(Num::f($precipProb) / 100.0, 0.0, 1.0);
        }
        return ['temp_band' => $tempBand, 'wind_band' => $windBand, 'precip_class' => $cls, 'precip_p' => $p];
    }

    /**
     * The WeatherDetail of a classified hour for one setting.
     *
     * @param array<string, mixed> $A
     * @param array{temp_band: ?string, wind_band: ?string, precip_class: string, precip_p: float}|null $classified
     * @return array<string, mixed> WeatherDetail
     */
    public static function detail(array $A, ?array $classified, string $setting): array
    {
        if ($classified === null) {
            return [
                'multiplier' => 1.0, 'missing' => true, 'temp' => 1.0, 'precip' => 1.0, 'wind' => 1.0,
                'temp_band' => null, 'precip_class' => null, 'precip_p' => null, 'wind_band' => null,
            ];
        }
        $tempBand = $classified['temp_band'];
        $windBand = $classified['wind_band'];
        $cls = $classified['precip_class'];
        $p = $classified['precip_p'];
        $temp = $tempBand !== null
            ? Num::f(Seeds::read($A, 'weather.temperature_bands.rows.' . $tempBand . '.' . $setting))
            : 1.0;
        $wind = $windBand !== null
            ? Num::f(Seeds::read($A, 'weather.wind_bands.rows.' . $windBand . '.' . $setting))
            : 1.0;
        $m = Num::f(Seeds::read($A, 'weather.precip_classes.rows.' . $cls . '.' . $setting));
        $precip = 1.0 - $p * (1.0 - $m);
        $floor = Num::f(Seeds::read($A, 'weather.floor'));
        $product = $temp * $precip * $wind;
        $multiplier = $product > $floor ? $product : $floor;
        return [
            'multiplier' => $multiplier, 'missing' => false, 'temp' => $temp, 'precip' => $precip, 'wind' => $wind,
            'temp_band' => $tempBand, 'precip_class' => $cls, 'precip_p' => $p, 'wind_band' => $windBand,
        ];
    }

    /** Only A-Z become a-z; every other byte is unchanged (no locale, no Unicode case mapping). */
    public static function asciiLower(string $text): string
    {
        return strtr($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /**
     * Step-function lookup: the id of the first band whose upper bound is absent or above x. (Null cannot
     * happen with the seed file: the last band has no bound.)
     *
     * @param array<string, mixed> $A
     */
    private static function band(array $A, string $table, string $key, float $x): ?string
    {
        foreach (Seeds::read($A, 'weather.' . $table . '.order') as $bandId) {
            $upper = Seeds::read($A, 'weather.' . $table . '.rows.' . $bandId . '.' . $key);
            if ($upper === null || $x < $upper) {
                return $bandId;
            }
        }
        return null;
    }
}
