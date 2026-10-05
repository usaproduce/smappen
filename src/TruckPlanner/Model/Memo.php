<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * What one call of a model function has already computed and may reuse.
 *
 * It lives for a single call (a window, a week strip, a day plan, a suggestion run), during which the
 * Assumptions, the profile, the calibration and the contexts do not change, so a reused value is exactly
 * the value a second computation would give. Nothing is kept between calls: the model has no global state.
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Memo
{
    /** @var array<string, array{presence: list<list<float>>, intent: list<list<float>>}> hour weights by context */
    public array $weights = [];

    /** @var array<string, array<int, mixed>> orders and money of a stop by "<stop key>:<effective open>:<close>" */
    public array $stops = [];
}
