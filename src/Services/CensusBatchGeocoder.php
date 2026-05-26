<?php
namespace App\Services;

/**
 * CensusBatchGeocoder — batched address → (lat,lng) geocoding via the
 * U.S. Census Bureau's free Geocoder Batch API. Carafe Vendor Network
 * Spec v3 §12.4: "Census geocoder: 10,000 addresses per batch request.
 * Never geocode one-at-a-time — ~10,000× fewer geocode calls."
 *
 * Why Census instead of Google for backfill:
 *   - Free + unmetered + 10K per request
 *   - Returns coordinates + Census tract identifiers (handy for
 *     joining demographics later)
 *   - U.S.-only (which is the Carafe scope)
 *   - No API key required
 *
 * Endpoint:
 *   POST https://geocoding.geo.census.gov/geocoder/locations/addressbatch
 *   multipart/form-data fields:
 *     addressFile — CSV with header? no; rows of "id,street,city,state,zip"
 *     benchmark   — '2020' (most recent fully-released national benchmark)
 *
 * Response: text/csv with rows
 *   id,input_address,match_status,match_type,matched_address,coords,tigerline,side
 * coords is "lng,lat" (Census convention — note the order is the
 * OPPOSITE of every other API).
 *
 * Usage:
 *   $g = new CensusBatchGeocoder();
 *   $out = $g->geocode([
 *     'v1' => ['street' => '100 Main St', 'city' => 'Vienna', 'state' => 'VA', 'zip' => '22180'],
 *     ...
 *   ]);
 *   $out['v1'] === ['lat' => 38.9, 'lng' => -77.2, 'matched_address' => '...']
 *   or null on no match.
 *
 * Failure modes:
 *   - Address didn't match Census's national file → returns null
 *   - HTTP failure → throws RuntimeException; caller retries or skips
 *   - Batches > MAX_BATCH split automatically across multiple requests
 */
class CensusBatchGeocoder
{
    public const ENDPOINT     = 'https://geocoding.geo.census.gov/geocoder/locations/addressbatch';
    public const MAX_BATCH    = 10000;
    public const BENCHMARK    = 'Public_AR_Current';
    public const TIMEOUT_SEC  = 120;

    /**
     * Geocode a batch of addresses.
     *
     * @param array<string, array{street: string, city?: ?string, state?: ?string, zip?: ?string}> $addresses
     *        Keyed by caller-supplied id (e.g. vendor_locations.id) so
     *        the response map round-trips back to the right row.
     *
     * @return array<string, ?array{lat: float, lng: float, matched_address: ?string, match_type: ?string}>
     */
    public function geocode(array $addresses): array
    {
        if (empty($addresses)) return [];
        $out = [];
        foreach (array_chunk($addresses, self::MAX_BATCH, true) as $batch) {
            $out += $this->geocodeBatch($batch);
        }
        return $out;
    }

    /**
     * Single-batch round-trip. Public so callers can opt into smaller
     * batches (e.g. ~500 in a unit test).
     */
    public function geocodeBatch(array $addresses): array
    {
        $csv = self::buildCsv($addresses);
        $resp = $this->postCsv($csv);
        return self::parseResponse($resp, array_keys($addresses));
    }

    /**
     * Pure: build the CSV payload the endpoint expects. Each row:
     *   id,street,city,state,zip
     * Quoting follows RFC 4180 (double-quote any field with a comma /
     * quote / newline; escape internal quotes by doubling).
     */
    public static function buildCsv(array $addresses): string
    {
        $rows = [];
        foreach ($addresses as $id => $a) {
            $rows[] = implode(',', [
                self::csvField((string) $id),
                self::csvField((string) ($a['street'] ?? '')),
                self::csvField((string) ($a['city']   ?? '')),
                self::csvField((string) ($a['state']  ?? '')),
                self::csvField((string) ($a['zip']    ?? '')),
            ]);
        }
        return implode("\n", $rows);
    }

    /**
     * Pure: parse the Census batch response CSV. The endpoint emits one
     * row per input id; "id,input,match_status,match_type,matched_address,coords,tigerline,side".
     * Match_status is "Match" / "Tie" / "No_Match".
     *
     * Match → returns parsed lat/lng. Tie → first match wins (the API
     * doesn't disambiguate). No_Match → null.
     *
     * Ids missing from the response (e.g. truncated payload) come back as null
     * so the caller can retry them.
     *
     * @param string[] $expectedIds — preserves output order + fills nulls for missing rows
     * @return array<string, ?array{lat: float, lng: float, matched_address: ?string, match_type: ?string}>
     */
    public static function parseResponse(string $body, array $expectedIds = []): array
    {
        $out = array_fill_keys($expectedIds, null);
        $lines = preg_split("/\r\n|\r|\n/", trim($body)) ?: [];
        foreach ($lines as $line) {
            if ($line === '') continue;
            $cols = str_getcsv($line, ',', '"', '\\');
            if (count($cols) < 6) continue;
            $id            = (string) $cols[0];
            $matchStatus   = $cols[2] ?? '';
            $matchType     = $cols[3] ?? null;
            $matchedAddr   = $cols[4] ?? null;
            $coordsPair    = $cols[5] ?? '';
            if (!in_array($matchStatus, ['Match', 'Tie'], true)) {
                $out[$id] = null;
                continue;
            }
            // Census emits "lng,lat" — note the order!
            if (preg_match('/^\s*(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)\s*$/', $coordsPair, $m)) {
                $out[$id] = [
                    'lat'             => (float) $m[2],
                    'lng'             => (float) $m[1],
                    'matched_address' => $matchedAddr ?: null,
                    'match_type'      => $matchType   ?: null,
                ];
            } else {
                $out[$id] = null;
            }
        }
        return $out;
    }

    /**
     * POST the CSV to Census as multipart/form-data. Returns the raw
     * response body. Protected so a test subclass can stub HTTP.
     */
    protected function postCsv(string $csv): string
    {
        // Temporary file because cURL's CURLFile expects a real file path.
        $tmp = tempnam(sys_get_temp_dir(), 'census_geo_');
        if ($tmp === false) {
            throw new \RuntimeException('failed to create tempfile for Census geocoder');
        }
        try {
            file_put_contents($tmp, $csv);
            $post = [
                'addressFile' => new \CURLFile($tmp, 'text/csv', 'addresses.csv'),
                'benchmark'   => self::BENCHMARK,
            ];
            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $post,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                throw new \RuntimeException('Census geocoder HTTP failed: ' . $err);
            }
            if ($code >= 400) {
                throw new \RuntimeException("Census geocoder returned HTTP $code");
            }
            return (string) $body;
        } finally {
            @unlink($tmp);
        }
    }

    /** RFC 4180 CSV escaping. */
    private static function csvField(string $v): string
    {
        if ($v === '') return '';
        if (preg_match('/[",\r\n]/', $v)) {
            return '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }
}
