<?php
namespace App\Services;

use App\Core\Config;

/**
 * FoursquareAdapter — Foursquare Places API client for non-Google
 * discovery. Carafe Vendor Network Spec v3 §2 + §9 step 10.
 *
 * Why Foursquare:
 *   - Strong specialty/ethnic category taxonomy where Google is shallow
 *   - Stable category IDs (numeric) — categorical queries return cleanly
 *   - Per-call cost is metered — every call writes api_cost_events via
 *     PlacesClient-style ledger (PlacesEnrichService budget cap doesn't
 *     apply since this isn't Google, but the same monthly volume
 *     dashboard surfaces it). Cost is added to the api_cost_events
 *     ledger with sku='foursquare_search' for reconciliation.
 *
 * What it does:
 *   - search($bbox, $categoryIds, $limit) → AdapterPlace[]
 *   - buildSearchUrl($bbox, $categories, $limit): string  — pure
 *   - parseSearchResponse(string $json): array              — pure
 *
 * Spec §2 type → Foursquare category id (from Foursquare's taxonomy
 * https://docs.foursquare.com/data-products/docs/categories):
 *   broadline, cash_carry — 17110 (Wholesale Store), 17066 (Warehouse Store)
 *   produce               — 17021 (Greengrocer), 17013 (Farmers Market)
 *   meat                  — 17002 (Butcher)
 *   seafood               — 17046 (Seafood Market)
 *   dairy_bakery_bev      — 17070 (Bakery), 17074 (Dairy Store), 17078 (Beverage)
 *   specialty_ethnic      — 17034 (Specialty Food Store)
 *   local_grocery         — 17069 (Grocery Store), 17029 (Supermarket)
 *   smallwares_equip      — 17087 (Kitchen Supply)
 *
 * API key: FOURSQUARE_API_KEY env var. Adapter throws on missing key.
 *
 * AdapterPlace shape matches the OSMAdapter output so the import
 * pipeline can treat all non-Google adapters uniformly.
 */
class FoursquareAdapter
{
    public const ENDPOINT_SEARCH = 'https://api.foursquare.com/v3/places/search';

    public const TIMEOUT_SEC = 15;

    /** Approximate per-call cost (USD). Foursquare's pricing varies — keep this aligned with the latest invoice. */
    public const PER_CALL_COST_USD = 0.0049;

    /**
     * Spec §2 vendor type → Foursquare category id list.
     * Multiple ids per type means "any of these matches."
     */
    public const CATEGORY_MAP = [
        'broadline'        => [17110, 17066],
        'cash_carry'       => [17110, 17066],
        'produce'          => [17021, 17013],
        'meat'             => [17002],
        'seafood'          => [17046],
        'dairy_bakery_bev' => [17070, 17074, 17078],
        'specialty_ethnic' => [17034],
        'local_grocery'    => [17069, 17029],
        'smallwares_equip' => [17087],
    ];

    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $key = $apiKey ?? (string) Config::get('FOURSQUARE_API_KEY', '');
        if ($key === '') {
            throw new \RuntimeException('FOURSQUARE_API_KEY not configured');
        }
        $this->apiKey = $key;
    }

    /**
     * Search Foursquare places inside a bbox for the given vendor types.
     *
     * @param string[] $vendorTypes Spec §2 type keys (filtered against CATEGORY_MAP).
     * @param array{0:float,1:float,2:float,3:float} $bbox [latMin, lngMin, latMax, lngMax]
     * @return array<int, array> AdapterPlace dicts
     */
    public function discover(array $vendorTypes, array $bbox, int $limit = 50): array
    {
        $categories = self::collectCategoryIds($vendorTypes);
        if (empty($categories)) return [];

        $url  = self::buildSearchUrl($bbox, $categories, $limit);
        $body = $this->httpGet($url);
        $this->recordCost();
        return self::parseSearchResponse($body);
    }

    /**
     * Pure — build the Foursquare /v3/places/search URL for a bbox +
     * category set + result limit. Foursquare's `ne` and `sw` params
     * are colon-separated "lat,lng" pairs.
     */
    public static function buildSearchUrl(array $bbox, array $categoryIds, int $limit = 50): string
    {
        if (count($bbox) !== 4) {
            throw new \InvalidArgumentException('bbox must be [latMin, lngMin, latMax, lngMax]');
        }
        [$latMin, $lngMin, $latMax, $lngMax] = array_map('floatval', $bbox);

        $params = [
            'sw'         => sprintf('%.6f,%.6f', $latMin, $lngMin),
            'ne'         => sprintf('%.6f,%.6f', $latMax, $lngMax),
            'categories' => implode(',', array_map('intval', $categoryIds)),
            'limit'      => max(1, min(50, $limit)),
        ];
        return self::ENDPOINT_SEARCH . '?' . http_build_query($params);
    }

    /**
     * Pure — parse the /v3/places/search response into AdapterPlace
     * shape. Foursquare's response wraps results under `results[]` with
     * fsq_id, name, location.formatted_address, geocodes.main.{latitude,longitude}.
     */
    public static function parseSearchResponse(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['results'])) return [];
        $out = [];
        foreach ($data['results'] as $r) {
            $fsq  = $r['fsq_id']  ?? null;
            $name = $r['name']    ?? null;
            $lat  = $r['geocodes']['main']['latitude']  ?? null;
            $lng  = $r['geocodes']['main']['longitude'] ?? null;
            if (!$fsq || !$name || $lat === null || $lng === null) continue;

            $primaryCat = $r['categories'][0]['short_name'] ?? null;
            $types      = [];
            foreach ($r['categories'] ?? [] as $c) {
                if (!empty($c['id'])) $types[] = 'foursquare=' . $c['id'];
            }
            $out[] = [
                'id'                 => "foursquare/$fsq",
                'displayName'        => ['text' => (string) $name],
                'location'           => ['latitude' => (float) $lat, 'longitude' => (float) $lng],
                'formattedAddress'   => $r['location']['formatted_address'] ?? null,
                'primaryType'        => $primaryCat,
                'types'              => $types,
                'foursquare_fsq_id'  => $fsq,
                'foursquare_payload' => $r,
                'source'             => 'foursquare',
            ];
        }
        return $out;
    }

    /** Flatten + dedupe category ids from a vendor-type list. */
    public static function collectCategoryIds(array $vendorTypes): array
    {
        $ids = [];
        foreach ($vendorTypes as $vt) {
            foreach (self::CATEGORY_MAP[$vt] ?? [] as $cid) {
                $ids[$cid] = true;
            }
        }
        return array_keys($ids);
    }

    // ─────────────────────────────────────────────────────────────────
    // Protected — overridable seams for testing
    // ─────────────────────────────────────────────────────────────────

    protected function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Foursquare HTTP failed: ' . $err);
        }
        if ($code >= 400) {
            throw new \RuntimeException("Foursquare HTTP $code: " . substr((string) $body, 0, 200));
        }
        return (string) $body;
    }

    /**
     * Append a row to the api_cost_events ledger so the run dashboard's
     * spend graph (§5.3) includes Foursquare alongside Google Places.
     */
    protected function recordCost(): void
    {
        try {
            \App\Core\Database::getInstance()->insert('api_cost_events', [
                'sku'             => 'foursquare_search',
                'billable_units'  => 1,
                'unit_cost_usd'   => self::PER_CALL_COST_USD,
                'total_cost_usd'  => self::PER_CALL_COST_USD,
                'called_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[foursquare] cost ledger write failed: ' . $e->getMessage());
        }
    }
}
