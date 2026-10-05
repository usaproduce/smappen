<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Contracts;

/**
 * Day contexts for a run of civil dates: holiday, hourly forecast and fuel price (04_BACKEND.md 5.5).
 *
 * Resolved with Registry::dayContexts(): the day-context service when it is installed, else the fallback,
 * which knows the holidays and the fuel price but has no forecast.
 */
interface DayContextProvider
{
    /**
     * @param array<string, mixed> $truck the truck value of TruckBaseController::truck()
     * @param array<string, mixed> $A the truck's Assumptions
     * @param string $from first civil date, "YYYY-MM-DD"
     * @param int $days number of consecutive dates, 1 or more
     * @param array<string, ?string> $treatAs date => "treat this day as" value for that date; dates that
     *                                        are absent are built with null
     * @return array{days: list<array{date: string, holiday: ?array<string, mixed>, context: array<string, mixed>}>,
     *               forecast: array{state: "fresh"|"stale"|"unavailable", generated_at: ?string,
     *                               point: array{lat: float, lng: float}, source: string},
     *               fuel: array<string, mixed>}
     *         `days` holds one DayInfo per date in order: `context` is the DayContext of 02_MODEL.md
     *         section 3 (its `forecast` is 24 entries or null) and `holiday` is `context.holiday`.
     *         `forecast` describes the one forecast behind them (for the truck's base point);
     *         `generated_at` is an ISO 8601 instant or null. `fuel` is the FuelInfo the contexts carry.
     */
    public function contexts(array $truck, array $A, string $from, int $days, array $treatAs = []): array;
}
