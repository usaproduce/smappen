<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Fallback\StraightLineLegs;
use PHPUnit\Framework\TestCase;

final class StraightLineLegsTest extends TestCase
{
    private const BASE = ['id' => 'base', 'lat' => 39.003, 'lng' => -77.405];
    private const SPOT = ['id' => 's1', 'lat' => 38.96, 'lng' => -77.36];

    private const DRIVE_LEG_KEYS = [
        'from_id', 'to_id', 'source', 'fetched_on', 'age_days', 'distance_m', 'duration_s', 'toll_state',
        'google_toll', 'toll_source', 'override', 'fallback_reason', 'leg_input',
    ];

    public function testEveryLegIsALabelledStraightLine(): void
    {
        $legs = (new StraightLineLegs())->legs('org-1', [], [self::BASE, self::SPOT], [['base', 's1'], ['s1', 'base']]);
        self::assertCount(2, $legs);

        $out = $legs[0];
        self::assertSame(self::DRIVE_LEG_KEYS, array_keys($out));
        self::assertSame('base', $out['from_id']);
        self::assertSame('s1', $out['to_id']);
        self::assertSame('straight_line', $out['source']);
        self::assertSame('no_key', $out['fallback_reason']);
        self::assertNull($out['fetched_on']);
        self::assertNull($out['age_days']);
        self::assertSame('not_asked', $out['toll_state']);
        self::assertNull($out['google_toll']);
        self::assertSame('none', $out['toll_source']);
        self::assertNull($out['override']);
        // the documented example of 04_BACKEND.md 4.10: 8,012.82 m and 526.315 s
        self::assertEqualsWithDelta(8012.82, $out['distance_m'], 0.005);
        self::assertEqualsWithDelta(526.315, $out['duration_s'], 0.0005);

        self::assertSame('s1', $legs[1]['from_id']);
        self::assertSame('base', $legs[1]['to_id']);
        self::assertEqualsWithDelta($out['distance_m'], $legs[1]['distance_m'], 1.0e-6);
    }

    public function testTheLegInputIsTheModelsFallbackLegOnTheExactPoints(): void
    {
        $leg = (new StraightLineLegs())->legs('org-1', [], [self::BASE, self::SPOT], [['base', 's1']])[0];
        $expected = Estimator::fallbackLeg(Seeds::defaults(), 39.003, -77.405, 38.96, -77.36);
        self::assertSame($expected, $leg['leg_input']);
        self::assertSame('fallback', $leg['leg_input']['source']);
        self::assertNull($leg['leg_input']['override_minutes']);
        self::assertSame(0.0, $leg['leg_input']['toll']);
        self::assertSame($expected['distance_m'], $leg['distance_m']);
        self::assertSame($expected['duration_s'], $leg['duration_s']);
    }

    public function testLegsComeBackInPairOrderAndPairsMayRepeat(): void
    {
        $points = [self::BASE, self::SPOT, ['id' => 's2', 'lat' => 39.01, 'lng' => -77.41]];
        $pairs = [['s2', 'base'], ['base', 's1'], ['s1', 's2'], ['base', 's1']];
        $legs = (new StraightLineLegs())->legs('org-1', [], $points, $pairs);
        self::assertSame(
            [['s2', 'base'], ['base', 's1'], ['s1', 's2'], ['base', 's1']],
            array_map(static fn (array $leg): array => [$leg['from_id'], $leg['to_id']], $legs)
        );
        self::assertSame($legs[1], $legs[3]);
    }

    public function testTwoPointsWithTheSameLegKeyAreTheSamePlace(): void
    {
        // 38.960004 and 38.96 round to the same four decimals
        $points = [self::SPOT, ['id' => 'event', 'lat' => 38.960004, 'lng' => -77.359996]];
        $leg = (new StraightLineLegs())->legs('org-1', [], $points, [['s1', 'event']])[0];
        self::assertSame(self::DRIVE_LEG_KEYS, array_keys($leg));
        self::assertSame('same_point', $leg['source']);
        self::assertNull($leg['fallback_reason']);
        self::assertSame(0.0, $leg['distance_m']);
        self::assertSame(0.0, $leg['duration_s']);
        // handed to the model as a routed leg of zero length, so no fallback warning is raised for it
        self::assertSame(
            ['source' => 'google', 'distance_m' => 0.0, 'duration_s' => 0.0, 'override_minutes' => null, 'toll' => 0.0],
            $leg['leg_input']
        );
    }

    public function testNoPairsNoLegs(): void
    {
        self::assertSame([], (new StraightLineLegs())->legs('org-1', [], [self::BASE], []));
    }

    public function testAPairThatNamesAnUnknownPointIsADefect(): void
    {
        $this->expectException(\LogicException::class);
        (new StraightLineLegs())->legs('org-1', [], [self::BASE], [['base', 'ghost']]);
    }

    public function testStatusAndMovePoint(): void
    {
        $legs = new StraightLineLegs();
        self::assertSame(['state' => 'no_key'], $legs->status());
        $legs->movePoint('org-1', [], ['lat' => 38.96, 'lng' => -77.36], ['lat' => 38.9605, 'lng' => -77.36]);
        $this->addToAssertionCount(1);
    }

    public function testTheBuildersAreSharedWithTheRoutingService(): void
    {
        $leg = StraightLineLegs::straightLine('a', 'b', self::BASE, self::SPOT, 'quota');
        self::assertSame('quota', $leg['fallback_reason']);
        self::assertSame('straight_line', $leg['source']);
        self::assertSame(self::DRIVE_LEG_KEYS, array_keys(StraightLineLegs::samePoint('a', 'b')));
    }

    public function testTheModelAcceptsTheLegInputs(): void
    {
        $A = Seeds::defaults();
        $legs = (new StraightLineLegs())->legs('org-1', [], [self::BASE, self::SPOT], [['base', 's1']]);
        $ctx = Estimator::typicalContext($A, 3);
        $profile = ['truck_time_factor' => 1.1];
        $minutes = Estimator::legMinutes($A, $profile, $legs[0]['leg_input'], $ctx, 600);
        self::assertSame('fallback', $minutes['source']);
        self::assertGreaterThan(0, $minutes['minutes']);
    }
}
