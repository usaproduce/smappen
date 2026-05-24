<?php
namespace App\Tests\Services;

use App\Services\AnalogService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-math tests for AnalogService. We don't touch the database here —
 * we test the cosine + null handling + weight behavior directly via the
 * public `cosineSimilarity` static.
 */
class AnalogServiceTest extends TestCase
{
    private array $ones;

    protected function setUp(): void
    {
        $this->ones = array_fill(0, 18, 1.0);
    }

    public function testIdenticalVectorsScoreOne(): void
    {
        $vec = array_fill(0, 18, 0.5);
        $sim = AnalogService::cosineSimilarity($vec, $vec, $this->ones);
        $this->assertEqualsWithDelta(1.0, $sim, 1e-9);
    }

    public function testOrthogonalVectorsScoreZero(): void
    {
        $a = array_fill(0, 18, 0.0);
        $b = array_fill(0, 18, 0.0);
        $a[0] = 1.0;
        $b[1] = 1.0;
        $sim = AnalogService::cosineSimilarity($a, $b, $this->ones);
        $this->assertEqualsWithDelta(0.0, $sim, 1e-9);
    }

    public function testNullDimensionsAreSkipped(): void
    {
        // Two vectors that are identical on the non-null indices. Score
        // should still come out as 1.0 since the null pair drops out.
        $a = [1.0, null, 0.5, 0.25];
        $b = [1.0, 0.9,  0.5, 0.25];
        $w = [1.0, 1.0,  1.0, 1.0];
        $sim = AnalogService::cosineSimilarity($a, $b, $w);
        $this->assertEqualsWithDelta(1.0, $sim, 1e-9);
    }

    public function testWeightedSimilarityFavorsHigherWeightedDim(): void
    {
        // Vector A perfectly matches B on dim 0 and differs on dim 1. Heavy
        // weight on dim 0 should pull the score above light-weight version.
        $a = [1.0, 0.0];
        $b = [1.0, 0.5];

        $heavyDim0 = AnalogService::cosineSimilarity($a, $b, [10.0, 1.0]);
        $heavyDim1 = AnalogService::cosineSimilarity($a, $b, [1.0,  10.0]);

        // The heavy-dim-0 case downweights the disagreement on dim 1, so
        // the resulting similarity should be strictly higher.
        $this->assertGreaterThan($heavyDim1, $heavyDim0);
    }

    public function testZeroMagnitudeReturnsZeroNotNaN(): void
    {
        $zeros = array_fill(0, 18, 0.0);
        $vec   = array_fill(0, 18, 1.0);
        $sim = AnalogService::cosineSimilarity($zeros, $vec, $this->ones);
        $this->assertSame(0.0, $sim);
        $this->assertFalse(is_nan($sim));
    }

    public function testAllNullVectorReturnsZero(): void
    {
        $a = array_fill(0, 18, null);
        $b = array_fill(0, 18, 0.5);
        $sim = AnalogService::cosineSimilarity($a, $b, $this->ones);
        $this->assertSame(0.0, $sim);
    }

    public function testDefaultWeightsAreEighteen(): void
    {
        $this->assertCount(18, AnalogService::DEFAULT_WEIGHTS);
        foreach (AnalogService::DEFAULT_WEIGHTS as $w) {
            $this->assertIsFloat((float)$w);
            $this->assertGreaterThan(0, $w);
        }
    }

    public function testCosineIsCommutative(): void
    {
        $a = [0.2, 0.4, 0.6, null, 0.8];
        $b = [0.3, 0.5, 0.7, 0.1,  0.9];
        $w = [1.0, 0.5, 0.8, 1.0,  1.2];
        $ab = AnalogService::cosineSimilarity($a, $b, $w);
        $ba = AnalogService::cosineSimilarity($b, $a, $w);
        $this->assertEqualsWithDelta($ab, $ba, 1e-12);
    }

    public function testCosineIsBoundedZeroOneForNonNegativeInputs(): void
    {
        // Random non-negative vectors → score should always fall in [0,1].
        srand(42);
        for ($i = 0; $i < 20; $i++) {
            $a = array_map(fn() => mt_rand() / mt_getrandmax(), array_fill(0, 18, 0));
            $b = array_map(fn() => mt_rand() / mt_getrandmax(), array_fill(0, 18, 0));
            $sim = AnalogService::cosineSimilarity($a, $b, $this->ones);
            $this->assertGreaterThanOrEqual(0.0, $sim);
            $this->assertLessThanOrEqual(1.0 + 1e-12, $sim);
        }
    }
}
