<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Fallback;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Contracts\DayContextProvider;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * The day-context provider while the day-context service is not installed: holidays and the fuel price,
 * no forecast. Every context has `forecast: null` and the forecast state is `unavailable`.
 */
final class PlainDayContexts implements DayContextProvider
{
    public function contexts(array $truck, array $A, string $from, int $days, array $treatAs = []): array
    {
        $fuel = Registry::fuel()->resolve($truck);
        $list = [];
        for ($i = 0; $i < $days; $i++) {
            $date = Estimator::addDays($from, $i);
            $context = Estimator::dayContext(
                $A,
                $date,
                $treatAs[$date] ?? null,
                null,
                (float) $fuel['price_per_gal'],
                (string) $fuel['source']
            );
            $list[] = ['date' => $date, 'holiday' => $context['holiday'], 'context' => $context];
        }
        $base = $truck['profile']['base'] ?? [];
        return [
            'days' => $list,
            'forecast' => [
                'state' => 'unavailable',
                'generated_at' => null,
                'point' => ['lat' => (float) ($base['lat'] ?? 0.0), 'lng' => (float) ($base['lng'] ?? 0.0)],
                'source' => (string) TpConfig::get('weather.source'),
            ],
            'fuel' => $fuel,
        ];
    }
}
