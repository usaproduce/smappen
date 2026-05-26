<?php
namespace App\Tests\Services;

use App\Services\VendorDedupeService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-helper tests for VendorDedupeService. The DB-touching paths
 * (assignBlockKeys, dedupeNewLocations, applyPendingAutoMerges,
 * mergeCluster) need an integration harness — we cover the pure
 * algorithm pieces that drive every decision:
 *
 *   - block key derivation (zip5, state, soundex, prefix3, geohash6)
 *   - jaro-winkler edge cases
 *   - haversineMeters round trips
 *   - score() decision bands + the auto-merge override
 *   - clusters() union-find correctness on chains and stars
 *
 * Carafe Vendor Network Spec v3 §12.2 + §4.3.
 */
class VendorDedupeServiceTest extends TestCase
{
    // ─── Block-key derivation ────────────────────────────────────────

    public function testBlockKeysFromTypicalUsAddress(): void
    {
        $keys = VendorDedupeService::blockKeysFor(
            'Restaurant Depot',
            '100 Main St, Vienna, VA 22180, USA',
            38.901, -77.265
        );
        $this->assertSame('22180', $keys['zip5']);
        $this->assertSame('VA',    $keys['state_code']);
        $this->assertSame(4,       strlen($keys['name_soundex']));
        $this->assertSame('res',   $keys['name_prefix3']);
        $this->assertSame(6,       strlen($keys['geohash6']));
    }

    public function testBlockKeysFromMissingAddressStillProducesGeohashAndName(): void
    {
        $keys = VendorDedupeService::blockKeysFor('Awesome Wholesale', null, 0.0, 0.0);
        $this->assertNull($keys['zip5']);
        $this->assertNull($keys['state_code']);
        $this->assertNotEmpty($keys['name_soundex']);
        $this->assertNotEmpty($keys['name_prefix3']);
        $this->assertSame(6, strlen($keys['geohash6']));
    }

    public function testBlockKeysPlacekeyPassthrough(): void
    {
        $keys = VendorDedupeService::blockKeysFor('Foo', null, 0, 0, '226@5vg-7j6-mwk');
        $this->assertSame('226@5vg-7j6-mwk', $keys['placekey']);
    }

    public function testBlockKeysIgnoreStopwordsForPrefix(): void
    {
        // "The Wholesale Co" → tokens drop 'the','wholesale','co' → ''
        // → prefix3 falls back to the normalized name prefix.
        $keys = VendorDedupeService::blockKeysFor('The Wholesale Co', null, 0, 0);
        // Whatever it picks must be deterministic + 3 chars.
        $this->assertNotNull($keys['name_prefix3']);
        $this->assertSame(3, strlen($keys['name_prefix3']));
    }

    public function testZip5HandlesPlus4(): void
    {
        $this->assertSame('22180', VendorDedupeService::zip5From('100 Main St, Vienna, VA 22180-1234, USA'));
        $this->assertSame('22180', VendorDedupeService::zip5From('Vienna, VA 22180'));
        $this->assertNull(VendorDedupeService::zip5From(null));
        $this->assertNull(VendorDedupeService::zip5From('no zip here'));
    }

    public function testStateFromHandlesUsAddress(): void
    {
        $this->assertSame('VA', VendorDedupeService::stateFrom('100 Main St, Vienna, VA 22180, USA'));
        $this->assertNull(VendorDedupeService::stateFrom('100 Main St'));
    }

    // ─── Name tokenization ───────────────────────────────────────────

    public function testNameTokensDropStopwords(): void
    {
        $tokens = VendorDedupeService::nameTokens('The Wholesale Co.');
        $this->assertEmpty($tokens); // every term is in STOP_TOKENS
    }

    public function testNameTokensKeepDistinctiveWords(): void
    {
        $tokens = VendorDedupeService::nameTokens("Joe's Produce Wholesale, Inc.");
        $this->assertContains('joes', $tokens);
        // Stop tokens are filtered.
        $this->assertNotContains('produce', $tokens);
        $this->assertNotContains('wholesale', $tokens);
        $this->assertNotContains('inc', $tokens);
    }

    // ─── Jaro-Winkler ────────────────────────────────────────────────

    public function testJaroWinklerExactMatch(): void
    {
        $this->assertSame(1.0, VendorDedupeService::jaroWinkler('abc', 'abc'));
    }

    public function testJaroWinklerEmptyStrings(): void
    {
        $this->assertSame(1.0, VendorDedupeService::jaroWinkler('', ''));
        $this->assertSame(0.0, VendorDedupeService::jaroWinkler('abc', ''));
        $this->assertSame(0.0, VendorDedupeService::jaroWinkler('', 'abc'));
    }

    public function testJaroWinklerCloseSpellings(): void
    {
        // Classic JW example: MARTHA vs MARHTA — published JW ≈ 0.961
        $score = VendorDedupeService::jaroWinkler('martha', 'marhta');
        $this->assertGreaterThan(0.90, $score);
    }

    public function testJaroWinklerVeryDifferentStrings(): void
    {
        $score = VendorDedupeService::jaroWinkler('produce wholesale', 'restaurant depot');
        $this->assertLessThan(0.6, $score);
    }

    public function testJaroWinklerIsSymmetric(): void
    {
        $ab = VendorDedupeService::jaroWinkler('chefs warehouse', 'chef warehouse');
        $ba = VendorDedupeService::jaroWinkler('chef warehouse',  'chefs warehouse');
        $this->assertEqualsWithDelta($ab, $ba, 1e-9);
    }

    // ─── Geohash ─────────────────────────────────────────────────────

    public function testGeohashLengthMatchesPrecision(): void
    {
        $this->assertSame(6, strlen(VendorDedupeService::geohash(38.9, -77.0, 6)));
        $this->assertSame(8, strlen(VendorDedupeService::geohash(38.9, -77.0, 8)));
    }

    public function testGeohashDcKnownPrefix(): void
    {
        // (38.9072, -77.0369) — Washington DC. Known geohash starts "dqcj"
        // for any precision ≥ 4. Real Smappen rows hit this regularly.
        $h = VendorDedupeService::geohash(38.9072, -77.0369, 6);
        $this->assertStringStartsWith('dqcj', $h);
    }

    public function testGeohashNearbyPointsShareCell(): void
    {
        // Two points 200m apart at DC should share the same 6-char
        // geohash cell (cell width ~1.2km at this latitude).
        $h1 = VendorDedupeService::geohash(38.9072, -77.0369, 6);
        $h2 = VendorDedupeService::geohash(38.9090, -77.0369, 6);
        $this->assertSame($h1, $h2);
    }

    public function testGeohashDistantPointsDiffer(): void
    {
        // DC vs LA — totally different cell.
        $dc = VendorDedupeService::geohash(38.9072, -77.0369, 6);
        $la = VendorDedupeService::geohash(34.0522, -118.2437, 6);
        $this->assertNotSame($dc, $la);
    }

    // ─── Haversine ───────────────────────────────────────────────────

    public function testHaversineZeroForSamePoint(): void
    {
        $this->assertEqualsWithDelta(0.0, VendorDedupeService::haversineMeters(40, -74, 40, -74), 1e-6);
    }

    public function testHaversineDcToNyc(): void
    {
        // Great-circle DC (38.9072,-77.0369) → NYC (40.7128,-74.0060) ≈ 327 km.
        $m = VendorDedupeService::haversineMeters(38.9072, -77.0369, 40.7128, -74.0060);
        $this->assertGreaterThan(325000, $m);
        $this->assertLessThan(335000,    $m);
    }

    // ─── score() — banding + override ────────────────────────────────

    public function testScoreNearIdenticalVendorsAutoMerge(): void
    {
        $a = ['name' => "Joe's Produce", 'zip5' => '22180', 'lat' => 38.901, 'lng' => -77.265, 'address' => '100 Main St, Vienna, VA 22180', 'phone' => '703-555-1212'];
        $b = ['name' => "Joe's Produce", 'zip5' => '22180', 'lat' => 38.9012, 'lng' => -77.2651, 'address' => '100 Main St, Vienna, VA 22180', 'phone' => '7035551212'];
        $r = VendorDedupeService::score($a, $b);
        $this->assertSame('auto_merge', $r['decision']);
        $this->assertGreaterThanOrEqual(0.85, $r['score']);
    }

    public function testScoreDifferentVendorsReject(): void
    {
        $a = ['name' => 'Sysco Foods',     'zip5' => '22180', 'lat' => 38.90, 'lng' => -77.26, 'address' => '100 Main St',  'phone' => '7035551111'];
        $b = ['name' => 'Restaurant Depot','zip5' => '21090', 'lat' => 39.20, 'lng' => -76.65, 'address' => '500 First Ave','phone' => '4105552222'];
        $r = VendorDedupeService::score($a, $b);
        $this->assertSame('reject', $r['decision']);
    }

    public function testScoreOverrideBeatsLowScore(): void
    {
        // Same name tokens shared, very close coords, but
        // intentionally weak street/phone overlap so the
        // base score lands in the 'review' band. Override should
        // still force auto_merge.
        $a = ['name' => 'Hunts Point Produce Market',
              'zip5' => null, 'lat' => 40.815, 'lng' => -73.876,
              'address' => '', 'phone' => null];
        $b = ['name' => 'Hunts Point Produce Market Co.',
              'zip5' => null, 'lat' => 40.815, 'lng' => -73.876,
              'address' => 'totally different address line', 'phone' => null];
        $r = VendorDedupeService::score($a, $b);
        $this->assertSame('auto_merge', $r['decision']);
        $this->assertGreaterThanOrEqual(2, $r['shared_tokens']);
        $this->assertLessThanOrEqual(VendorDedupeService::OVERRIDE_DISTANCE_M, $r['distance_m']);
    }

    public function testScoreDistanceAndTokensReported(): void
    {
        $a = ['name' => 'Foo Wholesale', 'zip5' => null, 'lat' => 0.0, 'lng' => 0.0, 'address' => '', 'phone' => null];
        $b = ['name' => 'Foo Distributors', 'zip5' => null, 'lat' => 0.001, 'lng' => 0.001, 'address' => '', 'phone' => null];
        $r = VendorDedupeService::score($a, $b);
        $this->assertIsFloat($r['distance_m']);
        $this->assertIsInt($r['shared_tokens']);
        $this->assertGreaterThan(0, $r['distance_m']);
    }

    // ─── clusters() — union-find ─────────────────────────────────────

    public function testClustersChain(): void
    {
        // A-B, B-C, C-D → one cluster {A,B,C,D}
        $g = VendorDedupeService::clusters([['A', 'B'], ['B', 'C'], ['C', 'D']]);
        $this->assertCount(1, $g);
        sort($g[0]);
        $this->assertSame(['A', 'B', 'C', 'D'], $g[0]);
    }

    public function testClustersTwoSeparateComponents(): void
    {
        $g = VendorDedupeService::clusters([['A', 'B'], ['C', 'D']]);
        $this->assertCount(2, $g);
        $sizes = array_map('count', $g);
        sort($sizes);
        $this->assertSame([2, 2], $sizes);
    }

    public function testClustersStar(): void
    {
        // Central A linked to B, C, D, E → one 5-vertex cluster
        $g = VendorDedupeService::clusters([['A', 'B'], ['A', 'C'], ['A', 'D'], ['A', 'E']]);
        $this->assertCount(1, $g);
        $this->assertCount(5, $g[0]);
    }

    public function testClustersEmpty(): void
    {
        $this->assertSame([], VendorDedupeService::clusters([]));
    }

    public function testClustersIgnoresSelfLoops(): void
    {
        // A-A is a no-op pair; result is still {A}
        $g = VendorDedupeService::clusters([['A', 'A']]);
        $this->assertCount(1, $g);
        $this->assertSame(['A'], $g[0]);
    }

    // ─── streetOnly + levenshteinNormalized ──────────────────────────

    public function testStreetOnlyDropsCityState(): void
    {
        $this->assertSame('100 main st', VendorDedupeService::streetOnly('100 Main St, Vienna, VA 22180'));
        $this->assertSame('', VendorDedupeService::streetOnly(''));
    }

    public function testLevenshteinNormalizedBounds(): void
    {
        $this->assertSame(1.0, VendorDedupeService::levenshteinNormalized('abc', 'abc'));
        $this->assertGreaterThan(0.0, VendorDedupeService::levenshteinNormalized('abcd', 'abce'));
        $this->assertSame(1.0, VendorDedupeService::levenshteinNormalized('', ''));
        $this->assertSame(0.0, VendorDedupeService::levenshteinNormalized('foo', 'xyz'));
    }
}
