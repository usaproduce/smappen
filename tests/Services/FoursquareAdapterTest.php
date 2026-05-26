<?php
namespace App\Tests\Services;

use App\Services\FoursquareAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for FoursquareAdapter — URL building, category map,
 * response parsing. HTTP + cost-ledger paths covered by integration.
 *
 * Carafe Vendor Network Spec v3 §2 + §9 step 10.
 */
class FoursquareAdapterTest extends TestCase
{
    // ─── collectCategoryIds ──────────────────────────────────────────

    public function testCategoryMapCoversAllSpecTypes(): void
    {
        // Every spec §2 vendor type must have at least one Foursquare
        // category, otherwise FoursquareAdapter::discover silently
        // returns nothing for that type.
        $vendorTypes = require dirname(__DIR__, 2) . '/config/carafe_vendor_types.php';
        foreach (array_keys($vendorTypes) as $vt) {
            $this->assertNotEmpty(
                FoursquareAdapter::collectCategoryIds([$vt]),
                "FoursquareAdapter::CATEGORY_MAP is missing vendor type '$vt'"
            );
        }
    }

    public function testCategoryIdsAreUniqueAcrossMultipleTypes(): void
    {
        // broadline + cash_carry both include 17110. Combining them
        // should not include 17110 twice in the request.
        $ids = FoursquareAdapter::collectCategoryIds(['broadline', 'cash_carry']);
        $this->assertSame(array_unique($ids), $ids);
        $this->assertContains(17110, $ids);
    }

    public function testCategoryIdsUnknownTypeIsEmpty(): void
    {
        $this->assertSame([], FoursquareAdapter::collectCategoryIds(['totally-unknown']));
        $this->assertSame([], FoursquareAdapter::collectCategoryIds([]));
    }

    // ─── buildSearchUrl ──────────────────────────────────────────────

    public function testSearchUrlIncludesBboxAsSwAndNe(): void
    {
        $url = FoursquareAdapter::buildSearchUrl([38.8, -77.1, 38.9, -77.0], [17110], 25);
        $this->assertStringContainsString('sw=38.800000%2C-77.100000', $url);
        $this->assertStringContainsString('ne=38.900000%2C-77.000000', $url);
        $this->assertStringContainsString('categories=17110', $url);
        $this->assertStringContainsString('limit=25', $url);
    }

    public function testSearchUrlClampsLimitToFifty(): void
    {
        // Foursquare's per-call cap is 50; values higher must clamp
        // rather than 400.
        $url = FoursquareAdapter::buildSearchUrl([0, 0, 1, 1], [17110], 200);
        $this->assertStringContainsString('limit=50', $url);
    }

    public function testSearchUrlClampsLimitToOneMinimum(): void
    {
        $url = FoursquareAdapter::buildSearchUrl([0, 0, 1, 1], [17110], 0);
        $this->assertStringContainsString('limit=1', $url);
    }

    public function testSearchUrlRejectsMisshapenBbox(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FoursquareAdapter::buildSearchUrl([38.8, -77.0], [17110]);
    }

    // ─── parseSearchResponse ─────────────────────────────────────────

    public function testParseValidResponse(): void
    {
        $body = json_encode([
            'results' => [
                [
                    'fsq_id'   => '4b6e7e0bf964a5202f7c2ce3',
                    'name'     => 'Joe Produce Inc',
                    'geocodes' => ['main' => ['latitude' => 40.815, 'longitude' => -73.876]],
                    'location' => ['formatted_address' => 'NYC, NY'],
                    'categories' => [
                        ['id' => 17021, 'short_name' => 'Greengrocer'],
                    ],
                ],
            ],
        ]);
        $places = FoursquareAdapter::parseSearchResponse($body);
        $this->assertCount(1, $places);
        $p = $places[0];
        $this->assertSame('foursquare/4b6e7e0bf964a5202f7c2ce3', $p['id']);
        $this->assertSame('Joe Produce Inc',                     $p['displayName']['text']);
        $this->assertSame(40.815,                                $p['location']['latitude']);
        $this->assertSame(-73.876,                               $p['location']['longitude']);
        $this->assertSame('NYC, NY',                             $p['formattedAddress']);
        $this->assertSame('Greengrocer',                         $p['primaryType']);
        $this->assertContains('foursquare=17021',                $p['types']);
        $this->assertSame('foursquare',                          $p['source']);
        $this->assertSame('4b6e7e0bf964a5202f7c2ce3',            $p['foursquare_fsq_id']);
    }

    public function testParseSkipsResultsWithMissingFields(): void
    {
        $body = json_encode([
            'results' => [
                ['fsq_id' => 'abc'], // missing name + geo
                ['fsq_id' => 'def', 'name' => 'No Geo'], // missing coords
                ['fsq_id' => 'ghi', 'name' => 'OK',
                    'geocodes' => ['main' => ['latitude' => 1, 'longitude' => 2]]],
            ],
        ]);
        $places = FoursquareAdapter::parseSearchResponse($body);
        $this->assertCount(1, $places);
        $this->assertSame('OK', $places[0]['displayName']['text']);
    }

    public function testParseEmptyOrGarbageIsEmptyArray(): void
    {
        $this->assertSame([], FoursquareAdapter::parseSearchResponse(''));
        $this->assertSame([], FoursquareAdapter::parseSearchResponse('{}'));
        $this->assertSame([], FoursquareAdapter::parseSearchResponse('{"results":[]}'));
        $this->assertSame([], FoursquareAdapter::parseSearchResponse('not json'));
    }
}
