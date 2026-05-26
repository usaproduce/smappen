<?php
namespace App\Services;

/**
 * OSMAdapter — OpenStreetMap Overpass-API client for the Carafe long
 * tail. Carafe Vendor Network Spec v3 §2 + §9 step 10.
 *
 * Why OSM:
 *   - Free + unmetered (rate-limited, but no per-call billing)
 *   - Particularly dense for non-chain produce houses, ethnic
 *     wholesalers, farmer's markets, butcher shops — exactly the
 *     long-tail Google Places misses
 *   - Stable Overpass-API endpoint, well-documented Overpass-QL
 *
 * What it does:
 *   - Build a bbox query in Overpass-QL for one or more vendor types
 *   - POST to the Overpass endpoint (configurable via OSM_OVERPASS_URL)
 *   - Parse the JSON response into AdapterPlace-shaped arrays
 *     compatible with VendorImportPipeline (and ultimately
 *     VendorUpsertService::upsertVendorFromPlace)
 *
 * Mapping from spec §2 vendor types → OSM tags:
 *   broadline           → shop=wholesale; landuse=industrial+name~"distribution"
 *   cash_carry          → shop=wholesale + name brand regexes
 *   produce             → shop=greengrocer; amenity=marketplace
 *   meat                → shop=butcher
 *   seafood             → shop=seafood
 *   dairy_bakery_bev    → shop=bakery,dairy,beverages
 *   specialty_ethnic    → shop=convenience+ethnic; shop=asian etc
 *   local_grocery       → shop=supermarket,convenience,grocery
 *   smallwares_equip    → shop=hardware (weak — Phase 2 in spec §2)
 *
 * Public API:
 *   discover(array $vendorTypes, array $bbox): array of AdapterPlace
 *   buildOverpassQl(array $vendorTypes, array $bbox): string  — pure
 *   parseOverpassJson(string $body): array of AdapterPlace          — pure
 *
 * AdapterPlace shape (compatible with the Places shape that
 * VendorUpsertService::upsertVendorFromPlace expects, plus extras):
 *   [
 *     'id'                 => 'node/12345' (osm prefix),
 *     'displayName'        => ['text' => 'Acme Wholesale'],
 *     'location'           => ['latitude' => 38.9, 'longitude' => -77.0],
 *     'formattedAddress'   => '...' (if available from addr:* tags),
 *     'primaryType'        => 'wholesaler' (best-effort guess),
 *     'types'              => ['shop=wholesale', ...],
 *     'osm_id'             => 'node/12345',
 *     'osm_tags'           => raw tag map,
 *     'source'             => 'osm',
 *   ]
 */
class OSMAdapter
{
    /** Default Overpass-API endpoint. Override via OSM_OVERPASS_URL. */
    public const DEFAULT_ENDPOINT = 'https://overpass-api.de/api/interpreter';

    public const DEFAULT_TIMEOUT_SEC = 60;

    /**
     * Spec §2 type → OSM tag predicates. Each predicate is a key/value
     * pair the Overpass-QL builder turns into a clause for node, way,
     * AND relation queries (so we catch all three geometry types).
     */
    public const TAG_MAP = [
        'broadline' => [
            ['shop' => 'wholesale'],
        ],
        'cash_carry' => [
            ['shop' => 'wholesale'],
            // Many cash-and-carry chains aren't tagged shop=wholesale
            // in OSM; they show up as shop=supermarket with the brand
            // in the name. Caller post-filters by name in dedupe.
            ['shop' => 'supermarket'],
        ],
        'produce' => [
            ['shop'    => 'greengrocer'],
            ['amenity' => 'marketplace'],
            ['shop'    => 'farm'],
        ],
        'meat' => [
            ['shop' => 'butcher'],
        ],
        'seafood' => [
            ['shop' => 'seafood'],
        ],
        'dairy_bakery_bev' => [
            ['shop' => 'bakery'],
            ['shop' => 'dairy'],
            ['shop' => 'beverages'],
        ],
        'specialty_ethnic' => [
            // OSM "shop=convenience" is too broad; the diacritics matter
            // here so we rely on the name in classify rather than try to
            // overfit a tag.
            ['shop' => 'asian'],
            ['shop' => 'ethnic'],
        ],
        'local_grocery' => [
            ['shop' => 'supermarket'],
            ['shop' => 'convenience'],
            ['shop' => 'grocery'],
        ],
        'smallwares_equip' => [
            // Weak fit — spec §2 says smallwares is Phase 2.
            ['shop' => 'kitchen'],
        ],
    ];

    private string $endpoint;
    private int $timeout;

    public function __construct(?string $endpoint = null, int $timeoutSec = self::DEFAULT_TIMEOUT_SEC)
    {
        $this->endpoint = $endpoint ?? (string) ($_ENV['OSM_OVERPASS_URL'] ?? getenv('OSM_OVERPASS_URL') ?: self::DEFAULT_ENDPOINT);
        $this->timeout  = $timeoutSec;
    }

    /**
     * Discover OSM vendors in a bbox matching the requested vendor types.
     *
     * @param string[] $vendorTypes Spec §2 type keys (filtered against TAG_MAP).
     * @param array{0:float,1:float,2:float,3:float} $bbox [latMin, lngMin, latMax, lngMax]
     * @return array<int, array> AdapterPlace dicts
     */
    public function discover(array $vendorTypes, array $bbox): array
    {
        if (count($bbox) !== 4) {
            throw new \InvalidArgumentException('bbox must be [latMin, lngMin, latMax, lngMax]');
        }
        $ql = self::buildOverpassQl($vendorTypes, $bbox);
        $body = $this->postOverpass($ql);
        return self::parseOverpassJson($body);
    }

    /**
     * Pure — build the Overpass-QL string for the requested vendor
     * types + bbox. Emits node + way + relation queries for each tag
     * predicate so we catch every OSM geometry type with a single
     * round-trip.
     */
    public static function buildOverpassQl(array $vendorTypes, array $bbox): string
    {
        [$latMin, $lngMin, $latMax, $lngMax] = array_map('floatval', $bbox);
        $bboxClause = sprintf('(%.6f,%.6f,%.6f,%.6f)', $latMin, $lngMin, $latMax, $lngMax);

        $clauses = [];
        foreach ($vendorTypes as $vt) {
            foreach (self::TAG_MAP[$vt] ?? [] as $tags) {
                $tagFilter = '';
                foreach ($tags as $k => $v) {
                    $tagFilter .= sprintf('["%s"="%s"]', self::escapeQl($k), self::escapeQl($v));
                }
                foreach (['node', 'way', 'relation'] as $geom) {
                    $clauses[] = "  $geom$tagFilter$bboxClause;";
                }
            }
        }
        if (empty($clauses)) {
            // Empty selector still produces a valid (zero-result) query.
            $clauses[] = "  node[\"highway\"=\"impossible_token_3a91\"]$bboxClause;";
        }

        return "[out:json][timeout:60];\n"
             . "(\n"
             . implode("\n", array_unique($clauses)) . "\n"
             . ");\n"
             . "out center tags;\n";
    }

    /**
     * Pure — parse an Overpass-JSON response into AdapterPlace shape.
     *
     * Skips elements without geometry. For ways/relations, uses the
     * Overpass `center` block (we requested `out center tags`).
     */
    public static function parseOverpassJson(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['elements'])) return [];
        $out = [];
        foreach ($data['elements'] as $el) {
            $type = $el['type'] ?? null;
            $id   = $el['id']   ?? null;
            if (!$type || !$id) continue;

            // Coordinates: nodes have lat/lon at top level; ways /
            // relations have the centroid in 'center'.
            $lat = $el['lat'] ?? ($el['center']['lat'] ?? null);
            $lon = $el['lon'] ?? ($el['center']['lon'] ?? null);
            if ($lat === null || $lon === null) continue;

            $tags = $el['tags'] ?? [];
            $name = $tags['name'] ?? null;
            if (!$name) continue; // un-named OSM rows are noise for our use case

            $out[] = [
                'id'               => "$type/$id",
                'displayName'      => ['text' => (string) $name],
                'location'         => ['latitude' => (float) $lat, 'longitude' => (float) $lon],
                'formattedAddress' => self::addressFromTags($tags),
                'primaryType'      => self::primaryTypeFromTags($tags),
                'types'            => self::typesFromTags($tags),
                'phone'            => $tags['phone']
                                      ?? ($tags['contact:phone'] ?? null),
                'website'          => $tags['website']
                                      ?? ($tags['contact:website'] ?? null),
                'osm_id'           => "$type/$id",
                'osm_tags'         => $tags,
                'source'           => 'osm',
            ];
        }
        return $out;
    }

    /**
     * Pure — assemble a formatted address from OSM addr:* tags. Returns
     * null when no addr fields are present. Format mirrors the Google
     * Places `formattedAddress` for downstream compatibility.
     */
    public static function addressFromTags(array $tags): ?string
    {
        $parts = [];
        if (!empty($tags['addr:housenumber']) && !empty($tags['addr:street'])) {
            $parts[] = trim($tags['addr:housenumber'] . ' ' . $tags['addr:street']);
        } elseif (!empty($tags['addr:street'])) {
            $parts[] = $tags['addr:street'];
        }
        if (!empty($tags['addr:city']))     $parts[] = $tags['addr:city'];
        $stateZip = trim(
            ($tags['addr:state']    ?? '') . ' ' .
            ($tags['addr:postcode'] ?? '')
        );
        if ($stateZip !== '')              $parts[] = $stateZip;
        if (!empty($tags['addr:country'])) $parts[] = $tags['addr:country'];
        return empty($parts) ? null : implode(', ', $parts);
    }

    /** Best-effort: pick a representative tag value as the "primary type". */
    public static function primaryTypeFromTags(array $tags): ?string
    {
        foreach (['shop', 'amenity', 'industrial', 'craft'] as $k) {
            if (!empty($tags[$k])) return (string) $tags[$k];
        }
        return null;
    }

    /** All tag values as a flat list (similar to Google's `types[]`). */
    public static function typesFromTags(array $tags): array
    {
        $out = [];
        foreach (['shop', 'amenity', 'industrial', 'craft', 'office'] as $k) {
            if (!empty($tags[$k])) $out[] = $k . '=' . $tags[$k];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────
    // HTTP — protected so a test subclass can stub.
    // ─────────────────────────────────────────────────────────────────

    protected function postOverpass(string $ql): string
    {
        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            // The endpoint reads the query from the POST body as
            // `data=<URL-encoded-QL>` — application/x-www-form-urlencoded.
            CURLOPT_POSTFIELDS     => http_build_query(['data' => $ql]),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Overpass HTTP failed: ' . $err);
        }
        // 429 = "too many requests". 504 = "server gave up". The
        // Overpass servers are free + crowded — caller backs off.
        if ($code >= 400) {
            throw new \RuntimeException("Overpass HTTP $code");
        }
        return (string) $body;
    }

    /** Conservative QL string escape — only key/value scope, not full QL. */
    private static function escapeQl(string $v): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
    }
}
