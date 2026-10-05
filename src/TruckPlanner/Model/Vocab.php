<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * The fixed vocabulary and the constants of the model, in index order (02_MODEL.md 1.1, 1.2).
 *
 * The lists also live in the seed file under `vocabulary` and the numbers under `constants`; the seed-sync
 * test asserts that the two agree. The numbers are written as literals on purpose: a runtime constant of
 * the language (the built-in pi, for instance) is never used in the model.
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Vocab
{
    public const MODEL_VERSION = 'tps-0.1.0';

    public const NSEG = 16;

    /** @var list<string> */
    public const SEGMENTS = [
        'res', 'w_office', 'w_health', 'w_edu', 'w_retail', 'w_industrial', 'w_hospitality', 'w_public',
        'v_nightlife', 'v_shopping', 'v_leisure', 'v_campus', 'v_hospital', 'v_transit', 'v_events', 'v_lodging',
    ];

    /** @var array<string, int> */
    public const SEGMENT_INDEX = [
        'res' => 0, 'w_office' => 1, 'w_health' => 2, 'w_edu' => 3, 'w_retail' => 4, 'w_industrial' => 5,
        'w_hospitality' => 6, 'w_public' => 7, 'v_nightlife' => 8, 'v_shopping' => 9, 'v_leisure' => 10,
        'v_campus' => 11, 'v_hospital' => 12, 'v_transit' => 13, 'v_events' => 14, 'v_lodging' => 15,
    ];

    /** @var list<string> */
    public const REGIMES = ['day', 'eve'];

    /** @var list<string> */
    public const RIVAL_KINDS = ['quick', 'full', 'cafe', 'bar', 'convenience'];

    /** @var list<string> */
    public const DAY_TYPES = ['weekday', 'saturday', 'sunday'];

    /** @var list<string> dow 0 = Monday */
    public const DOW_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @var array<string, int> */
    public const DOW_INDEX = ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6];

    /** @var list<string> */
    public const DAYPARTS = ['breakfast', 'lunch', 'dinner', 'late'];

    /** @var list<string> */
    public const VISIBILITY_LEVELS = ['hidden', 'normal', 'prominent'];

    /** @var list<string> weakest first */
    public const CONFIDENCE_LABELS = ['very_rough', 'rough', 'fair', 'good', 'fixed'];

    /** @var array<string, int> */
    public const CONFIDENCE_INDEX = ['very_rough' => 0, 'rough' => 1, 'fair' => 2, 'good' => 3, 'fixed' => 4];

    public const EARTH_RADIUS_M = 6371008.8;
    public const PI = 3.141592653589793;
    public const LN2 = 0.6931471805599453;
    public const Z80 = 1.2815515655446004;
    public const METERS_PER_MILE = 1609.344;
    public const ROUND_HALF = 0.500000001;
    public const QKEY_SCALE = 1000000.0;

    /** Index 0..15 of a segment key. An unknown key is a defect of the caller, not a model error. */
    public static function segmentIndex(string $segment): int
    {
        if (!isset(self::SEGMENT_INDEX[$segment])) {
            throw new \OutOfBoundsException('unknown segment: ' . $segment);
        }
        return self::SEGMENT_INDEX[$segment];
    }
}
