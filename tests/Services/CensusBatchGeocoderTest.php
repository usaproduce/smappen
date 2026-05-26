<?php
namespace App\Tests\Services;

use App\Services\CensusBatchGeocoder;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for CensusBatchGeocoder. The HTTP round-trip is covered by
 * the geocode() / geocodeBatch() integration paths; here we lock down
 * the two pure pieces:
 *
 *   - buildCsv: produces the row format Census expects, with RFC 4180
 *     escaping for fields containing commas / quotes
 *   - parseResponse: maps the response shape back to id-keyed lat/lng
 *     including the lng,lat coord-order quirk, and handles No_Match
 *     + missing-id cases without dropping the caller's ids
 *
 * Carafe Vendor Network Spec v3 §12.4.
 */
class CensusBatchGeocoderTest extends TestCase
{
    // ─── buildCsv ────────────────────────────────────────────────────

    public function testBuildCsvProducesExpectedRowFormat(): void
    {
        $csv = CensusBatchGeocoder::buildCsv([
            'loc-1' => ['street' => '100 Main St', 'city' => 'Vienna', 'state' => 'VA', 'zip' => '22180'],
        ]);
        $this->assertSame('loc-1,100 Main St,Vienna,VA,22180', $csv);
    }

    public function testBuildCsvMultipleRowsAreNewlineSeparated(): void
    {
        $csv = CensusBatchGeocoder::buildCsv([
            'a' => ['street' => '1 First',  'city' => 'A', 'state' => 'NY', 'zip' => '10001'],
            'b' => ['street' => '2 Second', 'city' => 'B', 'state' => 'NY', 'zip' => '10002'],
        ]);
        $this->assertSame(
            "a,1 First,A,NY,10001\nb,2 Second,B,NY,10002",
            $csv
        );
    }

    public function testBuildCsvHandlesMissingFields(): void
    {
        // Caller may not have city/state/zip — Census tolerates the
        // empty columns. Make sure we emit them as empty, not "null".
        $csv = CensusBatchGeocoder::buildCsv(['x' => ['street' => '500 Broadway']]);
        $this->assertSame('x,500 Broadway,,,', $csv);
    }

    public function testBuildCsvQuotesFieldsContainingCommas(): void
    {
        // RFC 4180 — a comma inside a field requires quoting; an internal
        // quote requires doubling.
        $csv = CensusBatchGeocoder::buildCsv([
            'id1' => ['street' => 'Smith, John & Co', 'city' => 'NYC', 'state' => 'NY', 'zip' => '10001'],
        ]);
        $this->assertStringContainsString('"Smith, John & Co"', $csv);
    }

    public function testBuildCsvDoublesQuotesInsideFields(): void
    {
        $csv = CensusBatchGeocoder::buildCsv([
            'id1' => ['street' => 'The "Big" Bldg', 'city' => 'NYC', 'state' => 'NY', 'zip' => '10001'],
        ]);
        $this->assertStringContainsString('"The ""Big"" Bldg"', $csv);
    }

    // ─── parseResponse ───────────────────────────────────────────────

    public function testParseResponseMatchedRow(): void
    {
        // Real-shape Census response. Note the coord ordering: "lng,lat".
        $body = '"loc-1","100 Main St, Vienna, VA, 22180","Match","Exact","100 MAIN ST, VIENNA, VA, 22180","-77.265,38.901","12345","L"';
        $out = CensusBatchGeocoder::parseResponse($body, ['loc-1']);
        $this->assertArrayHasKey('loc-1', $out);
        $this->assertNotNull($out['loc-1']);
        $this->assertEqualsWithDelta(38.901,  $out['loc-1']['lat'], 1e-6);
        $this->assertEqualsWithDelta(-77.265, $out['loc-1']['lng'], 1e-6);
        $this->assertSame('Exact', $out['loc-1']['match_type']);
        $this->assertStringContainsString('VIENNA', $out['loc-1']['matched_address']);
    }

    public function testParseResponseNoMatchYieldsNull(): void
    {
        $body = '"loc-2","garble","No_Match","","",""';
        $out  = CensusBatchGeocoder::parseResponse($body, ['loc-2']);
        $this->assertNull($out['loc-2']);
    }

    public function testParseResponsePreservesExpectedIdsWhenMissingFromBody(): void
    {
        // If Census drops a row (rare), our caller still needs to see
        // null for that id so it knows to retry — never a missing key.
        $body = '"loc-1","x","Match","Exact","X","-77.0,38.9","",""';
        $out  = CensusBatchGeocoder::parseResponse($body, ['loc-1', 'loc-2']);
        $this->assertArrayHasKey('loc-1', $out);
        $this->assertArrayHasKey('loc-2', $out);
        $this->assertNotNull($out['loc-1']);
        $this->assertNull($out['loc-2']);
    }

    public function testParseResponseTreatsTieAsMatch(): void
    {
        // Tie = address resolves to multiple records; we accept the first.
        $body = '"loc-1","x","Tie","Exact","X","-77.0,38.9","",""';
        $out  = CensusBatchGeocoder::parseResponse($body, ['loc-1']);
        $this->assertNotNull($out['loc-1']);
        $this->assertSame(38.9,  $out['loc-1']['lat']);
        $this->assertSame(-77.0, $out['loc-1']['lng']);
    }

    public function testParseResponseHandlesBlankLinesAndMixedNewlines(): void
    {
        $body  = "\r\n";
        $body .= '"a","x","Match","Exact","X","-77,38","",""' . "\n";
        $body .= "\n";
        $body .= '"b","y","No_Match","","","","",""' . "\r\n";
        $out = CensusBatchGeocoder::parseResponse($body, ['a', 'b']);
        $this->assertNotNull($out['a']);
        $this->assertNull($out['b']);
    }

    public function testParseResponseGarbageCoordsTreatedAsNoMatch(): void
    {
        $body = '"loc-1","x","Match","Exact","X","not-coords","",""';
        $out  = CensusBatchGeocoder::parseResponse($body, ['loc-1']);
        $this->assertNull($out['loc-1']);
    }

    // ─── Constants sanity ────────────────────────────────────────────

    public function testMaxBatchMatchesSpec(): void
    {
        // Spec §12.4 explicitly: 10,000 per batch. If Census raises this
        // we want to bump deliberately — locking it down prevents drift.
        $this->assertSame(10000, CensusBatchGeocoder::MAX_BATCH);
    }
}
