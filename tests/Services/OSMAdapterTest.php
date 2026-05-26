<?php
namespace App\Tests\Services;

use App\Services\OSMAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for OSMAdapter. HTTP round-trip is covered by the
 * discover() integration path; here we lock the QL builder + JSON
 * parser + tag-extraction helpers.
 *
 * Carafe Vendor Network Spec v3 §2 + §9 step 10.
 */
class OSMAdapterTest extends TestCase
{
    // ─── buildOverpassQl ─────────────────────────────────────────────

    public function testQlEmitsAllThreeGeometryTypes(): void
    {
        // Spec §12.2 dedupe drives the value of catching every OSM
        // geometry: a single physical wholesaler can be tagged as a
        // node OR a way OR a relation across editors. Query all three.
        $ql = OSMAdapter::buildOverpassQl(['produce'], [38.8, -77.1, 38.9, -77.0]);
        $this->assertStringContainsString('node["shop"="greengrocer"]', $ql);
        $this->assertStringContainsString('way["shop"="greengrocer"]',  $ql);
        $this->assertStringContainsString('relation["shop"="greengrocer"]', $ql);
    }

    public function testQlIncludesBboxClause(): void
    {
        $ql = OSMAdapter::buildOverpassQl(['meat'], [38.8, -77.1, 38.9, -77.0]);
        // Format: (latMin,lngMin,latMax,lngMax) with 6 decimal precision
        $this->assertStringContainsString('(38.800000,-77.100000,38.900000,-77.000000)', $ql);
    }

    public function testQlAppendsOutCenterTags(): void
    {
        // `out center` ensures ways/relations report their centroid;
        // without it we can't extract lat/lng for the non-node geoms.
        $ql = OSMAdapter::buildOverpassQl(['meat'], [38.8, -77.1, 38.9, -77.0]);
        $this->assertStringContainsString('out center tags;', $ql);
    }

    public function testQlMergesPredicatesForMultipleTypes(): void
    {
        // produce maps to greengrocer + marketplace + farm. Meat maps
        // to butcher. The QL must include all four predicates.
        $ql = OSMAdapter::buildOverpassQl(['produce', 'meat'], [38.8, -77.1, 38.9, -77.0]);
        $this->assertStringContainsString('"shop"="greengrocer"', $ql);
        $this->assertStringContainsString('"amenity"="marketplace"', $ql);
        $this->assertStringContainsString('"shop"="farm"', $ql);
        $this->assertStringContainsString('"shop"="butcher"', $ql);
    }

    public function testQlEmptyTypesDoesNotCrash(): void
    {
        // Defensive: an empty type list should produce a (valid)
        // zero-result query rather than a syntax error from Overpass.
        $ql = OSMAdapter::buildOverpassQl([], [38.8, -77.1, 38.9, -77.0]);
        $this->assertStringStartsWith('[out:json]', $ql);
        // Has a stub clause that yields no results.
        $this->assertStringContainsString('impossible_token_3a91', $ql);
    }

    // ─── parseOverpassJson ───────────────────────────────────────────

    public function testParseExtractsNodeWithTags(): void
    {
        $json = json_encode([
            'elements' => [
                [
                    'type' => 'node', 'id' => 12345,
                    'lat'  => 38.901, 'lon' => -77.265,
                    'tags' => [
                        'name'    => 'Acme Wholesale',
                        'shop'    => 'wholesale',
                        'phone'   => '703-555-1212',
                        'website' => 'https://acme.example',
                        'addr:housenumber' => '100',
                        'addr:street'      => 'Main St',
                        'addr:city'        => 'Vienna',
                        'addr:state'       => 'VA',
                        'addr:postcode'    => '22180',
                    ],
                ],
            ],
        ]);
        $places = OSMAdapter::parseOverpassJson($json);
        $this->assertCount(1, $places);
        $p = $places[0];
        $this->assertSame('node/12345',     $p['id']);
        $this->assertSame('Acme Wholesale', $p['displayName']['text']);
        $this->assertSame(38.901,           $p['location']['latitude']);
        $this->assertSame(-77.265,          $p['location']['longitude']);
        $this->assertSame('100 Main St, Vienna, VA 22180', $p['formattedAddress']);
        $this->assertSame('wholesale',      $p['primaryType']);
        $this->assertSame('703-555-1212',   $p['phone']);
        $this->assertSame('osm',            $p['source']);
        $this->assertSame('node/12345',     $p['osm_id']);
    }

    public function testParseExtractsWayWithCenter(): void
    {
        // Ways don't have lat/lon directly — the `out center` directive
        // adds a `center` block. We must read from there.
        $json = json_encode([
            'elements' => [
                [
                    'type' => 'way', 'id' => 999,
                    'center' => ['lat' => 40.7, 'lon' => -74.0],
                    'tags'   => ['name' => 'Big Coop', 'shop' => 'farm'],
                ],
            ],
        ]);
        $places = OSMAdapter::parseOverpassJson($json);
        $this->assertCount(1, $places);
        $this->assertSame('way/999', $places[0]['id']);
        $this->assertSame(40.7,      $places[0]['location']['latitude']);
    }

    public function testParseSkipsElementsMissingCoords(): void
    {
        $json = json_encode([
            'elements' => [
                ['type' => 'node', 'id' => 1, 'tags' => ['name' => 'X']],
                ['type' => 'node', 'id' => 2, 'lat' => 0, 'lon' => 0, 'tags' => ['name' => 'Y']],
            ],
        ]);
        $places = OSMAdapter::parseOverpassJson($json);
        $this->assertCount(1, $places);
        $this->assertSame('node/2', $places[0]['id']);
    }

    public function testParseSkipsUnnamedElements(): void
    {
        // OSM has millions of un-named nodes; they're noise for the
        // vendor directory. Skip them at parse time.
        $json = json_encode([
            'elements' => [
                ['type' => 'node', 'id' => 1, 'lat' => 0, 'lon' => 0, 'tags' => ['shop' => 'wholesale']],
            ],
        ]);
        $this->assertSame([], OSMAdapter::parseOverpassJson($json));
    }

    public function testParseGarbageBodyIsEmptyArray(): void
    {
        $this->assertSame([], OSMAdapter::parseOverpassJson(''));
        $this->assertSame([], OSMAdapter::parseOverpassJson('{}'));
        $this->assertSame([], OSMAdapter::parseOverpassJson('not json'));
    }

    // ─── addressFromTags / primaryTypeFromTags / typesFromTags ───────

    public function testAddressFromMinimalTags(): void
    {
        $this->assertNull(OSMAdapter::addressFromTags([]));
        $this->assertSame('Main St', OSMAdapter::addressFromTags(['addr:street' => 'Main St']));
    }

    public function testAddressJoinsStateAndZipInSameToken(): void
    {
        // Convention: state + zip together (e.g. "VA 22180") so it
        // round-trips with VendorDedupeService::stateFrom + zip5From.
        $addr = OSMAdapter::addressFromTags([
            'addr:street'      => 'Main St',
            'addr:city'        => 'Vienna',
            'addr:state'       => 'VA',
            'addr:postcode'    => '22180',
        ]);
        $this->assertSame('Main St, Vienna, VA 22180', $addr);
    }

    public function testPrimaryTypeFromTagsPrefersShop(): void
    {
        $this->assertSame('wholesale', OSMAdapter::primaryTypeFromTags([
            'shop'    => 'wholesale',
            'amenity' => 'marketplace',
        ]));
        $this->assertSame('marketplace', OSMAdapter::primaryTypeFromTags([
            'amenity' => 'marketplace',
        ]));
        $this->assertNull(OSMAdapter::primaryTypeFromTags([]));
    }

    public function testTypesFromTagsAreFlat(): void
    {
        $t = OSMAdapter::typesFromTags(['shop' => 'wholesale', 'amenity' => 'marketplace']);
        $this->assertContains('shop=wholesale', $t);
        $this->assertContains('amenity=marketplace', $t);
    }
}
