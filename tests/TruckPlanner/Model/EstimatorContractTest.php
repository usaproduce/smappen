<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Model;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\ModelError;
use App\TruckPlanner\Model\Seeds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the PHP port promises beyond the golden cases: how it fails, what Seeds returns, and that nothing
 * non-finite and no silently defaulted number leaves it.
 */
final class EstimatorContractTest extends TestCase
{
    // --- errors -----------------------------------------------------------------------------------------

    public function testModelErrorIsAnInvalidArgumentExceptionWhoseMessageIsTheCode(): void
    {
        try {
            Estimator::parseDate('2026-02-30');
            self::fail('expected invalid_date');
        } catch (\InvalidArgumentException $error) {
            self::assertInstanceOf(ModelError::class, $error);
            self::assertSame('invalid_date', $error->getMessage());
            self::assertSame('invalid_date', $error->errorCode());
        }
    }

    public function testTheThreeErrorsOfTheDocument(): void
    {
        $A = Seeds::assumptions([], self::regionDc());
        $ctx = Estimator::dayContext($A, '2026-10-09', null, null, 4.195, 'seed');

        self::assertSame('invalid_date', self::codeOf(static fn () => Estimator::addDays('2200-01-01', 1)));
        self::assertSame('invalid_date', self::codeOf(static fn () => Estimator::calibrate($A, [], 'yesterday')));
        self::assertSame('invalid_window', self::codeOf(
            static fn () => Estimator::windowOrders($A, self::profile(), self::terms(), self::zeroVectors(), null, $ctx, null, 840, 660)
        ));
        self::assertSame('missing_context', self::codeOf(
            static fn () => Estimator::windowOrders($A, self::profile(), self::terms(), self::zeroVectors(), null, $ctx, null, 1380, 1500)
        ));
    }

    /** @return iterable<string, array{0: \Closure}> */
    public static function nonFiniteCalls(): iterable
    {
        $A = Seeds::defaults();
        $evidence = Estimator::evidenceFrom(null, null);

        yield 'a mean of INF' => [static fn () => Estimator::interval($A, INF, $evidence)];
        yield 'a mean of NAN' => [static fn () => Estimator::interval($A, NAN, $evidence)];
        yield 'NAN coordinates' => [static fn () => Estimator::haversineM(NAN, 0.0, 1.0, 1.0)];
        yield 'INF into the rounding helper' => [static fn () => Estimator::roundHalfAway(INF, 2)];
        yield 'a ranking key beyond the integers' => [static fn () => Estimator::qkey(1.0e300)];
        yield 'a ranking key of NAN' => [static fn () => Estimator::qkey(NAN)];
        yield 'an overflowing sum' => [static fn () => Estimator::estSum([
            ['value' => 1.0e308, 'low' => 0.0, 'high' => 1.0e308, 'confidence' => 'rough'],
            ['value' => 1.0e308, 'low' => 0.0, 'high' => 1.0e308, 'confidence' => 'rough'],
        ])];
        yield 'an infinite fixed amount' => [static fn () => Estimator::estFixed(-INF)];
        yield 'a host of infinite size' => [static fn () => Estimator::hostCapture(
            $A,
            ['segment' => 'v_nightlife', 'size' => INF, 'size_source' => 'owner', 'only_food' => true, 'point_id' => null, 'place_type' => null],
            'normal',
            ['day' => 0.0, 'eve' => 0.0]
        )];
        yield 'non-finite orders' => [static fn () => Estimator::stopMoneyAt(self::profile(), self::terms(), NAN)];
        yield 'a non-finite leg' => [static fn () => Estimator::legMinutes(
            Seeds::assumptions([], self::regionDc()),
            self::profile(),
            ['source' => 'google', 'distance_m' => 1000.0, 'duration_s' => INF, 'override_minutes' => null, 'toll' => 0.0],
            Estimator::typicalContext($A, 3),
            600
        )];
    }

    /** Neither INF nor NAN leaves a function: the port raises `non_finite` instead. */
    #[DataProvider('nonFiniteCalls')]
    public function testNonFiniteResultsAreRefused(\Closure $call): void
    {
        try {
            $call();
        } catch (ModelError $error) {
            self::assertSame(ModelError::NON_FINITE, $error->errorCode());
            return;
        }
        self::fail('the call returned instead of raising non_finite');
    }

    /** A missing or null number is a TypeError, never a silent zero. */
    public function testANumberThatIsMissingIsNotTreatedAsZero(): void
    {
        $A = Seeds::assumptions([], self::regionDc());
        $withoutFuel = Estimator::dayContext($A, '2026-10-08', null, null, null, null);
        $plan = ['date' => '2026-10-08', 'stops' => [self::stop('a', 660, 840)]];
        $legs = ['base>a' => self::leg(11, 4.85), 'a>base' => self::leg(11, 4.85)];

        $this->expectException(\TypeError::class);
        Estimator::dayPlan($A, self::profile(), $plan, $withoutFuel, null, $legs, null);
    }

    public function testANullProfileNumberIsATypeError(): void
    {
        $profile = self::profile();
        $profile['avg_ticket'] = null;

        $this->expectException(\TypeError::class);
        Estimator::stopMoneyAt($profile, self::terms(), 10.0);
    }

    /** @return iterable<string, array{0: \Closure}> */
    public static function malformedCalls(): iterable
    {
        $A = Seeds::assumptions([], self::regionDc());
        $ctx = Estimator::typicalContext($A, 3);
        $holed = self::zeroVectors();
        $holed['capture']['day'][5] = null;
        $short = self::zeroVectors();
        $short['nearby'] = array_fill(0, 15, 0.0);
        $text = self::zeroVectors();
        $text['within'][0] = '12.5';

        yield 'a null in a capture vector' => [static fn () => Estimator::hourlyOrders($A, self::profile(), self::terms(), $holed, null, $ctx, 12)];
        yield 'a vector of fifteen numbers' => [static fn () => Estimator::hourlyOrders($A, self::profile(), self::terms(), $short, null, $ctx, 12)];
        yield 'a numeric string in a vector' => [static fn () => Estimator::weekStrip($A, self::profile(), self::terms(), $text, null)];
        yield 'a null in a source base' => [static fn () => Estimator::captureAtPoint(
            $A, 38.96, -77.36, 'normal',
            [['id' => 'b1', 'lat' => 38.96, 'lng' => -77.36, 'base' => array_fill(0, 15, 1.0) + [15 => null], 'rivals' => ['day' => 0.0, 'eve' => 0.0]]],
            [], ['point_ids' => [], 'segment' => null, 'amount' => 0.0]
        )];
        yield 'a float where a minute is required' => [static fn () => Estimator::windowOrders($A, self::profile(), self::terms(), self::zeroVectors(), null, $ctx, null, ...[660.0, 840.0])];
        yield 'a numeric string for a leg duration' => [static fn () => Estimator::legMinutes(
            $A, self::profile(), ['source' => 'google', 'distance_m' => 1000.0, 'duration_s' => '600', 'override_minutes' => null, 'toll' => 0.0], $ctx, 600
        )];
        yield 'a missing fee' => [static fn () => Estimator::unitMargins(self::profile(), ['spot_id' => null, 'visibility' => 'normal', 'host' => null])];
    }

    /** A malformed argument is a TypeError, whatever the field: nothing is silently read as zero. */
    #[DataProvider('malformedCalls')]
    public function testMalformedArgumentsAreTypeErrors(\Closure $call): void
    {
        set_error_handler(static fn (): bool => true);     // a missing key also raises a warning first: not the point here
        try {
            $call();
        } catch (\TypeError $error) {
            self::assertNotSame('', $error->getMessage());
            return;
        } finally {
            restore_error_handler();
        }
        self::fail('the call returned instead of raising a TypeError');
    }

    /**
     * 02_MODEL.md section 7, "A missing number, a zero divisor": the port stops where the reference stops.
     * These are programming errors, not model errors.
     */
    public function testAZeroDivisorStopsTheCall(): void
    {
        $A = Seeds::assumptions([], self::regionDc());
        $noMileage = self::profile();
        $noMileage['mpg'] = 0.0;
        $timeline = Estimator::buildTimeline($A, self::profile(), Estimator::typicalContext($A, 3), [self::stop('a', 660, 840)], [
            'base>a' => self::leg(11, 4.85), 'a>base' => self::leg(11, 4.85),
        ]);
        $calls = [
            'day_costs with mpg 0' => static fn () => Estimator::dayCosts($noMileage, $timeline, 4.195),
            'day_costs of an empty day with mpg 0' => static fn () => Estimator::dayCosts($noMileage, Estimator::buildTimeline($A, $noMileage, Estimator::typicalContext($A, 3), [], []), 4.195),
            'score_byte with hi 0' => static fn () => Estimator::scoreByte(1.0, 0.0),
        ];
        foreach ($calls as $label => $call) {
            try {
                $call();
                self::fail($label . ' returned');
            } catch (\DivisionByZeroError $error) {
                self::assertSame('Division by zero', $error->getMessage(), $label);
            }
        }
    }

    public function testAnHourOutsideTheDayIsRefused(): void
    {
        $A = Seeds::defaults();
        $this->expectException(\OutOfRangeException::class);
        Estimator::hourlyOrders($A, self::profile(), self::terms(), self::zeroVectors(), null, Estimator::typicalContext($A, 0), 24);
    }

    /**
     * 02_MODEL.md 4.1: a date that is not a string at all (null, a number, a list) is `invalid_date` like any
     * other value that is not "YYYY-MM-DD", in every function that takes a date. It is not a TypeError: the
     * reference answers a model error, and callers validate dates through these very functions.
     */
    public function testADateThatIsNotAStringIsAnInvalidDate(): void
    {
        $A = Seeds::assumptions([], self::regionDc());
        $flags = ['inauguration_day' => true];
        $contexts = [];
        for ($d = 0; $d < 8; $d++) {
            $contexts[] = Estimator::dayContext($A, Estimator::addDays('2026-10-05', $d), null, null, 4.195, 'seed');
        }
        foreach ([null, 20261008, 2026.1008, true, ['2026-10-08'], ['date' => '2026-10-08']] as $notADate) {
            $label = get_debug_type($notADate);
            self::assertSame('invalid_date', self::codeOf(static fn () => Estimator::parseDate($notADate)), 'parse_date ' . $label);
            self::assertSame('invalid_date', self::codeOf(static fn () => Estimator::dayOfWeek($notADate)), 'day_of_week ' . $label);
            self::assertSame('invalid_date', self::codeOf(static fn () => Estimator::addDays($notADate, 1)), 'add_days ' . $label);
            self::assertSame('invalid_date', self::codeOf(static fn () => Estimator::holidayOn($notADate, $flags)), 'holiday_on ' . $label);
            self::assertSame('invalid_date', self::codeOf(static fn () => Estimator::dayContext($A, $notADate, null, null, null, null)), 'day_context ' . $label);
            self::assertSame('invalid_date', self::codeOf(static fn () => Estimator::calibrate($A, [], $notADate)), 'calibrate ' . $label);
            self::assertSame(
                'invalid_date',
                self::codeOf(static fn () => Estimator::suggestWeek($A, self::profile(), $notADate, $contexts, [], [], null, null)),
                'suggest_week ' . $label
            );
        }
        self::assertSame([2026, 10, 8], Estimator::parseDate('2026-10-08'));
    }

    /** A model error raised inside a day is the day's error: the plan functions hand it on unchanged. */
    public function testDayPlanHandsOnTheModelErrorOfAStop(): void
    {
        $A = Seeds::assumptions([], self::regionDc());
        $ctx = Estimator::dayContext($A, '2026-10-09', null, null, 4.195, 'seed');
        $plan = ['date' => '2026-10-09', 'stops' => [self::stop('a', 1380, 1500)]];       // 23:00 to 01:00
        $legs = ['base>a' => self::leg(11, 4.85), 'a>base' => self::leg(11, 4.85)];

        self::assertSame('missing_context', self::codeOf(static fn () => Estimator::dayPlan($A, self::profile(), $plan, $ctx, null, $legs, null)));
        self::assertSame('missing_context', self::codeOf(static fn () => Estimator::evaluate($A, self::profile(), $plan, $ctx, null, $legs, null)));
        $next = Estimator::dayContext($A, '2026-10-10', null, null, 4.195, 'seed');
        self::assertSame('2026-10-09', Estimator::dayPlan($A, self::profile(), $plan, $ctx, $next, $legs, null)['date']);
    }

    /**
     * A seed path walks objects only. PHP would find index 3 in an array of 24 numbers under the key "3";
     * the reference and the TypeScript port stop there, and so does this port.
     */
    public function testASeedPathDoesNotWalkIntoAnArray(): void
    {
        $A = Seeds::defaults();
        self::assertCount(24, Estimator::seed($A, 'segments.w_office.presence.weekday'));
        foreach (['segments.w_office.presence.weekday.3', 'holidays.rules.0', 'holidays.rules.0.id', 'vocabulary.segments.0', 'traffic.dc.0', 'host.captive_share.value.x', ''] as $path) {
            try {
                Estimator::seed($A, $path);
                self::fail('seed("' . $path . '") returned a value');
            } catch (\OutOfBoundsException $error) {
                self::assertStringContainsString('unknown seed path', $error->getMessage());
            }
        }
        // An override under exactly such a path is still an override: seed() does not judge paths.
        self::assertSame(0.2, Estimator::seed(Seeds::assumptions(['segments.w_office.presence.weekday.3' => 0.2]), 'segments.w_office.presence.weekday.3'));
    }

    /**
     * An overridden curve is checked like any other input: a null or a numeric string among its 24 numbers
     * is a TypeError, never a silent zero (overrides are validated when saved; this is the last line).
     */
    public function testAnOverriddenCurveIsNotReadLeniently(): void
    {
        $nulls = array_fill(0, 24, 0.5);
        $nulls[12] = null;
        $strings = array_fill(0, 24, '0.5');
        foreach (['presence', 'intent'] as $curve) {
            foreach ([$nulls, $strings] as $values) {
                $A = Seeds::assumptions(['segments.w_office.' . $curve . '.weekday' => $values], self::regionDc());
                $ctx = Estimator::typicalContext($A, 3);
                $calls = [
                    static fn () => Estimator::hourWeights($A, $ctx),
                    static fn () => Estimator::hourlyOrders($A, self::profile(), self::terms(), self::zeroVectors(), null, $ctx, 12),
                    static fn () => Estimator::expandCurves($A),
                    static fn () => Estimator::mapWeightRows($A, self::profile(), null),
                ];
                foreach ($calls as $call) {
                    try {
                        $call();
                        self::fail('a malformed ' . $curve . ' curve was read');
                    } catch (\TypeError $error) {
                        self::assertStringContainsString('must be of type float', $error->getMessage());
                    }
                }
            }
        }
        // Whole numbers are numbers: a curve written 0 and 1 is read as 0.0 and 1.0.
        $A = Seeds::assumptions(['segments.w_office.presence.weekday' => array_fill(0, 24, 1), 'segments.w_office.intent.weekday' => array_fill(0, 24, 0)], self::regionDc());
        $w = Estimator::hourWeights($A, Estimator::typicalContext($A, 3));
        self::assertSame(1.08, $w['presence'][1][12]);
        self::assertSame(0.0, $w['intent'][1][12]);
    }

    /**
     * 02_MODEL.md section 7: scout_estimate refuses a fuel price that is not a number at once, whether or not
     * the place ends up with a drive to cost (a place type that does not host, a place with no window).
     */
    public function testScoutingRefusesAFuelPriceThatIsNotANumber(): void
    {
        $A = Seeds::assumptions([], self::regionDc());
        $place = [
            'place_id' => 'w1', 'place_type' => 'taproom', 'point' => ['lat' => 39.0035, 'lng' => -77.4035], 'point_id' => 'pw1',
            'size_default' => 40.0, 'kitchen' => null, 'vectors' => self::zeroVectors(),
        ];
        $places = ['a host with a window' => $place, 'no window' => ['size_default' => 0.0] + $place, 'not a host' => ['place_type' => 'restaurant'] + $place];
        foreach ($places as $label => $candidate) {
            foreach ([null, true, '4.195'] as $price) {
                try {
                    Estimator::dispatch('scout_estimate', ['A' => $A, 'profile' => self::profile(), 'place' => $candidate, 'legs' => [], 'cal' => null, 'fuel_price_per_gal' => $price]);
                    self::fail($label . ': a fuel price of ' . get_debug_type($price) . ' was accepted');
                } catch (\TypeError $error) {
                    self::assertStringContainsString('fuelPricePerGal', $error->getMessage(), $label);
                }
            }
        }
        self::assertNull(Estimator::scoutEstimate($A, self::profile(), $places['not a host'], [], null, 4.195));
        self::assertNull(Estimator::scoutEstimate($A, self::profile(), $places['no window'], [], null, 4.195)['best_window']);
        self::assertNotNull(Estimator::scoutEstimate($A, self::profile(), $places['a host with a window'], [], null, 4)['best_window']);
    }

    // --- dispatch ---------------------------------------------------------------------------------------

    public function testDispatchByCanonicalNameWithNamedArguments(): void
    {
        self::assertSame(2.68, Estimator::dispatch('round_half_away', ['decimals' => 2, 'x' => 2.675]));
        self::assertSame(25776000, Estimator::dispatch('qkey', ['x' => 25.776]));
        self::assertSame('2027-01-02', Estimator::dispatch('add_days', ['n' => 3, 'date' => '2026-12-30']));
        self::assertSame(
            1.08,
            Estimator::dispatch('day_context', [
                'A' => ['overrides' => [], 'region' => self::regionDc()],
                'date' => '2026-10-08', 'treat_as' => null, 'forecast' => null,
                'fuel_price_per_gal' => null, 'fuel_price_source' => null,
            ])['dow_factor'][1]
        );
        // A complete Assumptions record is passed through as it is.
        self::assertSame(0.6, Estimator::dispatch('seed', ['A' => Seeds::assumptions(['host.captive_share' => 0.6]), 'path' => 'host.captive_share']));
        self::assertSame([], Estimator::dispatch('validate_overrides', ['overrides' => ['host.captive_share' => 0.6]]));
    }

    public function testDispatchRefusesAnUnknownFunctionAndAnUnknownArgument(): void
    {
        try {
            Estimator::dispatch('dispatch', []);
            self::fail('expected a refusal');
        } catch (\BadFunctionCallException $error) {
            self::assertStringContainsString('dispatch', $error->getMessage());
        }
        $this->expectException(\Error::class);
        Estimator::dispatch('qkey', ['x' => 1.0, 'y' => 2.0]);
    }

    public function testCatalogueListsEveryPublicFunction(): void
    {
        $functions = Estimator::functions();
        self::assertCount(64, $functions);
        self::assertSame($functions, array_values(array_unique($functions)));
        foreach ($functions as $function) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $function);
            $method = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $function))));
            self::assertTrue(method_exists(Estimator::class, $method), $method);
            self::assertTrue((new \ReflectionMethod(Estimator::class, $method))->isStatic(), $method);
        }
        $public = [];
        foreach ((new \ReflectionClass(Estimator::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertTrue($method->isStatic(), $method->getName() . ' must be static');
            $public[] = $method->getName();
        }
        self::assertCount(66, $public, 'the 64 functions of the document, dispatch() and functions()');
        self::assertTrue((new \ReflectionClass(Estimator::class))->isFinal());
    }

    // --- seeds ------------------------------------------------------------------------------------------

    public function testWithOverridesValidatesAndApplies(): void
    {
        $A = Seeds::withOverrides(['host.captive_share' => 0.6, 'weather.floor' => 0]);

        self::assertSame(['host.captive_share' => 0.6, 'weather.floor' => 0], $A['overrides']);
        self::assertSame('none', $A['region']['id']);
        self::assertSame(0.6, Estimator::seed($A, 'host.captive_share'));
        self::assertSame(0.75, Estimator::seed(Seeds::defaults(), 'host.captive_share'));
        self::assertSame('dc', Seeds::withOverrides([], self::regionDc())['region']['id']);

        $host = ['segment' => 'v_nightlife', 'size' => 120.0, 'size_source' => 'owner', 'only_food' => true, 'point_id' => null, 'place_type' => null];
        self::assertSame(72.0, Estimator::hostCapture($A, $host, 'normal', ['day' => 0.0, 'eve' => 0.0])['eve']);
        self::assertSame(90.0, Estimator::hostCapture(Seeds::defaults(), $host, 'normal', ['day' => 0.0, 'eve' => 0.0])['eve']);
    }

    public function testWithOverridesRefusesWhatValidationRefuses(): void
    {
        $overrides = ['kernel.outside_option_a0' => 2.0, 'host.captive_share' => 1.5, 'weather.floor' => 0.2];
        try {
            Seeds::withOverrides($overrides);
            self::fail('expected invalid_overrides');
        } catch (ModelError $error) {
            self::assertSame(ModelError::INVALID_OVERRIDES, $error->errorCode());
            self::assertSame(
                [
                    ['path' => 'host.captive_share', 'error' => 'out_of_bounds'],
                    ['path' => 'kernel.outside_option_a0', 'error' => 'not_overridable'],
                ],
                $error->details()
            );
            self::assertSame($error->details(), Estimator::validateOverrides(Seeds::data(), $overrides));
        }
        // The unvalidated builder takes the map as it is: for overrides already validated when they were saved.
        self::assertSame($overrides, Seeds::assumptions($overrides)['overrides']);
    }

    public function testAnUnknownSeedPathIsADefectNotAZero(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        Estimator::seed(Seeds::defaults(), 'kernel.no_such_seed');
    }

    // --- the calendar ------------------------------------------------------------------------------------

    /** Every civil day of 1970-01-01 .. 2199-12-31 against PHP's own calendar (an independent one). */
    public function testCalendarAgainstAnIndependentOneForEveryValidDay(): void
    {
        $last = Estimator::daysFromCivil(2199, 12, 31);
        self::assertSame(84005, $last);
        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $wrong = [];
            for ($z = 0; $z <= $last && count($wrong) < 5; $z++) {
                [$y, $m, $d] = Estimator::civilFromDays($z);
                $text = Estimator::formatDate($y, $m, $d);
                $independent = gmdate('Y-m-d', $z * 86400);
                $weekday = ((int) gmdate('N', $z * 86400)) - 1;        // ISO: Monday = 1
                if ($text !== $independent || Estimator::daysFromCivil($y, $m, $d) !== $z || Estimator::dayOfWeek($text) !== $weekday) {
                    $wrong[] = $z . ': ' . $text . ' vs ' . $independent;
                }
            }
            self::assertSame([], $wrong);
        } finally {
            date_default_timezone_set($previous);
        }
        self::assertSame([1969, 12, 31], Estimator::civilFromDays(-1));
        self::assertSame('2200-01-01', Estimator::formatDate(...Estimator::civilFromDays($last + 1)));
    }

    // --- fixtures ---------------------------------------------------------------------------------------

    private static function codeOf(\Closure $call): string
    {
        try {
            $call();
        } catch (ModelError $error) {
            return $error->errorCode();
        }
        return 'no error';
    }

    /** @return array<string, mixed> */
    private static function regionDc(): array
    {
        return ['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => true]];
    }

    /** @return array<string, mixed> the default truck of the seed file */
    private static function profile(): array
    {
        $profile = [
            'name' => 'Test truck', 'region_id' => 'dc',
            'base' => ['lat' => 39.003, 'lng' => -77.405, 'address' => 'Sterling, VA'],
            'fuel_price_override' => null, 'licence_counties' => [],
        ];
        foreach (Seeds::data()['profile_defaults'] as $field => $entry) {
            $profile[$field] = $field === 'daypart_fit'
                ? ['breakfast' => $entry['breakfast'], 'lunch' => $entry['lunch'], 'dinner' => $entry['dinner'], 'late' => $entry['late']]
                : $entry['value'];
        }
        return $profile;
    }

    /** @return array<string, mixed> */
    private static function terms(): array
    {
        return ['spot_id' => null, 'visibility' => 'normal', 'host' => null, 'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null];
    }

    /** @return array<string, mixed> */
    private static function zeroVectors(): array
    {
        $zeros = array_fill(0, 16, 0.0);
        return [
            'capture' => ['day' => $zeros, 'eve' => $zeros], 'nearby' => $zeros, 'within' => $zeros,
            'rivals' => ['day' => 0.0, 'eve' => 0.0], 'visibility' => 'normal', 'in_region' => true, 'region_id' => null,
            'exclusion' => ['point_ids' => [], 'segment' => null, 'amount' => 0.0], 'excluded_amount' => 0.0,
            'points_used' => 0, 'dataset_version' => null, 'model_version' => 'tps-0.1.0',
        ];
    }

    /** @return array<string, mixed> */
    private static function stop(string $id, int $open, int $close): array
    {
        return [
            'id' => $id, 'kind' => 'spot', 'spot_id' => null, 'point' => ['lat' => 38.96, 'lng' => -77.36],
            'open_minute' => $open, 'close_minute' => $close, 'gap_before_unpaid' => false,
            'setup_minutes' => null, 'teardown_minutes' => null,
            'terms' => self::terms(), 'vectors' => self::zeroVectors(), 'event' => null, 'catering' => null,
        ];
    }

    /** @return array<string, mixed> a leg whose minutes the owner has set */
    private static function leg(int $minutes, float $miles): array
    {
        return ['source' => 'google', 'distance_m' => $miles * 1609.344, 'duration_s' => 0.0, 'override_minutes' => $minutes, 'toll' => 0.0];
    }
}
