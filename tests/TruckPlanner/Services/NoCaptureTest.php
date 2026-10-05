<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Fallback\NoCapture;
use PHPUnit\Framework\TestCase;

final class NoCaptureTest extends TestCase
{
    private const LOCATED_NOWHERE = ['in_region' => false, 'region_id' => null, 'county_fips' => null, 'state' => null];

    public function testEveryPointIsOutsideEveryRegion(): void
    {
        $capture = new NoCapture();
        self::assertSame(self::LOCATED_NOWHERE, $capture->locate('dc', 38.96, -77.36));
        self::assertSame(self::LOCATED_NOWHERE, $capture->locate('none', 0.0, 0.0, 'dc-20261003-3fa9c2d1'));
    }

    public function testZeroVectorsForEachRequestedVisibility(): void
    {
        $answer = (new NoCapture())->capture('dc', 38.96, -77.36, ['hidden', 'normal', 'prominent'], null);

        self::assertSame(['located', 'vectors', 'outlets', 'outlets_total', 'hosts_nearby'], array_keys($answer));
        self::assertSame(self::LOCATED_NOWHERE, $answer['located']);
        self::assertSame([], $answer['outlets']);
        self::assertSame(0, $answer['outlets_total']);
        self::assertSame([], $answer['hosts_nearby']);
        self::assertSame(['hidden', 'normal', 'prominent'], array_keys($answer['vectors']));

        $zeros = array_fill(0, 16, 0.0);
        foreach ($answer['vectors'] as $visibility => $vectors) {
            self::assertSame($visibility, $vectors['visibility']);
            self::assertSame($zeros, $vectors['capture']['day']);
            self::assertSame($zeros, $vectors['capture']['eve']);
            self::assertSame($zeros, $vectors['nearby']);
            self::assertSame(['day' => 0.0, 'eve' => 0.0], $vectors['rivals']);
            self::assertFalse($vectors['in_region']);
            self::assertNull($vectors['region_id']);
            self::assertNull($vectors['dataset_version']);
            self::assertSame(0, $vectors['points_used']);
            self::assertSame(0.0, $vectors['excluded_amount']);
            self::assertSame('tps-0.1.0', $vectors['model_version']);
        }
    }

    public function testTheVectorsAreTheModelsOwnAnswerForAnEmptyNeighbourhood(): void
    {
        $A = Seeds::defaults();
        $expected = Estimator::captureAtPoint($A, 38.96, -77.36, 'normal', [], [], Estimator::hostExclusion($A, null));
        $expected['in_region'] = false;
        $answer = (new NoCapture())->capture('none', 38.96, -77.36, ['normal'], null);
        self::assertSame($expected, $answer['vectors']['normal']);
        // The model accepts them as vectors of a spot without a host: they evaluate to no orders, not to an error.
        $terms = ['spot_id' => null, 'visibility' => 'normal', 'host' => null, 'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null];
        self::assertTrue(Estimator::vectorsMatch($A, $terms, $answer['vectors']['normal']));
    }

    public function testAHostKeepsItsExclusionOnTheVectors(): void
    {
        $host = ['segment' => 'w_office', 'size' => 800.0, 'size_source' => 'owner', 'only_food' => false, 'point_id' => null, 'place_type' => null];
        $answer = (new NoCapture())->capture('dc', 38.96, -77.36, ['normal'], $host);
        self::assertSame(
            ['point_ids' => [], 'segment' => 'w_office', 'amount' => 800.0],
            $answer['vectors']['normal']['exclusion']
        );
        $venue = ['segment' => 'v_nightlife', 'size' => 120.0, 'size_source' => 'owner', 'only_food' => true, 'point_id' => 'pw264230766', 'place_type' => 'taproom'];
        $answer = (new NoCapture())->capture('dc', 39.01, -77.41, ['prominent'], $venue);
        self::assertSame(
            ['point_ids' => ['pw264230766'], 'segment' => null, 'amount' => 0.0],
            $answer['vectors']['prominent']['exclusion']
        );
    }

    public function testTheReadersOfTheHostLinkRuleFindNothing(): void
    {
        $capture = new NoCapture();
        self::assertSame([], $capture->sources('dc', 38.96, -77.36));
        self::assertNull($capture->place('dc', 'w264230766'));
        self::assertSame([], $capture->hostsNear('dc', 38.96, -77.36, 100.0));
    }
}
