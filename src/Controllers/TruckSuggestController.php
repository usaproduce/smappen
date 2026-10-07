<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\TruckPlanner\Services\SuggestionService;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * Truck Planner: suggested days and weeks (4.12).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   POST /api/truck/suggest/day  ->  day
 *   POST /api/truck/suggest/week  ->  week
 *
 * Both rank plans made of the owner's own saved spots by expected take-home, for a date or for a week.
 * An answer is a list of suggestions, never a booking, and every figure in it is a range with its
 * confidence label. Without a saved spot the answer is an empty list (a week of seven days off).
 */
class TruckSuggestController extends TruckBaseController
{
    /** The maps of the two answers: an empty one is sent as {} (04_BACKEND.md section 6, rule 6). */
    private const DAY_MAPS = ['suggestions.*.result.warnings.*.data'];
    private const WEEK_MAPS = ['week.visits', 'week.days.*.suggestion.result.warnings.*.data'];

    private ?SuggestionService $service;

    public function __construct(?SuggestionService $service = null)
    {
        $this->service = $service;
    }

    public function day(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $body = $this->body($request);
            self::allowTime();
            $this->ok($this->suggestions()->day($orgId, $truck, $this->assumptions($truck), $body), self::DAY_MAPS);
        });
    }

    public function week(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = (array) $this->truck($request);
            $body = $this->body($request);
            self::allowTime();
            $this->ok($this->suggestions()->week($orgId, $truck, $this->assumptions($truck), $body), self::WEEK_MAPS);
        });
    }

    private function suggestions(): SuggestionService
    {
        return $this->service ??= new SuggestionService();
    }

    /** A week over many spots is an exhaustive search: it may take longer than an ordinary request. */
    private static function allowTime(): void
    {
        @set_time_limit((int) TpConfig::get('requests.long_request_seconds'));
    }
}
