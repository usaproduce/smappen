<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Upstream;

use App\Core\Config;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Http\OutboundHttp;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * Weekly retail fuel prices of the U.S. Energy Information Administration, API version 2
 * (03_DATA.md 13.2, 04_BACKEND.md 5.6).
 *
 *     GET https://api.eia.gov/v2/petroleum/pri/gnd/data/?api_key=<EIA_API_KEY>&frequency=weekly
 *         &data[0]=value&facets[duoarea][0]=R1Y&...&facets[product][0]=EPMR&facets[product][1]=EPD2D
 *         &start=<YYYY-MM-DD>&sort[0][column]=period&sort[0][direction]=desc&offset=0&length=100
 *
 * The service wants its key in the address of the request (it is not read from a header). So the address
 * is never logged, stored or returned: the log and the ledger hold a status word only. Without a key
 * nothing is requested.
 *
 * weekly() never raises:
 *
 *     { ok: bool, error: string?, rows: [ { duoarea, product, period: "YYYY-MM-DD",
 *                                           price: float (dollars per gallon), series_id } ] }
 *
 * The service sends every number as text; a row whose `value` is not a number, or that names an area or
 * product that was not asked for, is left out.
 */
class FuelClient
{
    public const URL = 'https://api.eia.gov/v2/petroleum/pri/gnd/data/';
    public const NO_KEY = 'no_key';

    private const KEY_NAME = 'EIA_API_KEY';
    private const MAX_PRICE = 100.0;

    private OutboundHttp $http;
    private ApiLedger $ledger;

    public function __construct(?OutboundHttp $http = null, ?ApiLedger $ledger = null)
    {
        $this->http = $http ?? new OutboundHttp();
        $this->ledger = $ledger ?? new ApiLedger();
    }

    /** Is EIA_API_KEY set on this server? */
    public function hasKey(): bool
    {
        return (string) Config::get(self::KEY_NAME, '') !== '';
    }

    /**
     * @param list<string> $areas the areas wanted (`duoarea` codes such as R1Y, R1Z, NUS)
     * @param string $start the first week wanted, "YYYY-MM-DD"
     * @return array{ok: bool, error: ?string, rows: list<array<string, mixed>>}
     */
    public function weekly(array $areas, string $start): array
    {
        $key = (string) Config::get(self::KEY_NAME, '');
        if ($key === '') {
            return ['ok' => false, 'error' => self::NO_KEY, 'rows' => []];
        }
        $areas = array_values(array_unique(array_map('strval', $areas)));
        if ($areas === []) {
            return ['ok' => true, 'error' => null, 'rows' => []];
        }
        /** @var array<string, string> $byFuel */
        $byFuel = TpConfig::get('fuel.products');
        $products = array_values($byFuel);

        [$status, $body, $latencyMs, $transport] = $this->http->request(
            'GET',
            self::URL . '?' . self::query($key, $areas, $products, $start),
            ['Accept: application/json'],
            null,
            (int) TpConfig::get('fuel.connect_timeout_s'),
            (int) TpConfig::get('fuel.timeout_s')
        );

        $sku = (string) TpConfig::get('fuel.sku');
        $decoded = $body === '' ? null : json_decode($body, true);
        $data = is_array($decoded) && is_array($decoded['response'] ?? null) ? ($decoded['response']['data'] ?? null) : null;
        if ($transport !== null || !is_array($data)) {
            $code = self::upstreamCode($decoded) ?? $transport ?? 'bad_body';
            $this->ledger->record($sku, 0, $status === 0 ? null : $status, $latencyMs, $code);
            error_log('[tp] eia ' . $code);
            return ['ok' => false, 'error' => $code, 'rows' => []];
        }
        $this->ledger->record($sku, 1, $status, $latencyMs, null);
        return ['ok' => true, 'error' => null, 'rows' => self::rows($data, $areas, $products)];
    }

    /**
     * The query string of the request, as http_build_query writes it.
     *
     * @param list<string> $areas
     * @param list<string> $products
     */
    public static function query(string $key, array $areas, array $products, string $start): string
    {
        return http_build_query([
            'api_key' => $key,
            'frequency' => 'weekly',
            'data' => ['value'],
            'facets' => ['duoarea' => array_values($areas), 'product' => array_values($products)],
            'start' => $start,
            'sort' => [['column' => 'period', 'direction' => 'desc']],
            'offset' => 0,
            'length' => (int) TpConfig::get('fuel.page_length'),
        ]);
    }

    /**
     * @param array<int|string, mixed> $data `response.data` of the answer
     * @param list<string> $areas
     * @param list<string> $products
     * @return list<array<string, mixed>>
     */
    public static function rows(array $data, array $areas, array $products): array
    {
        $out = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $area = $item['duoarea'] ?? null;
            $product = $item['product'] ?? null;
            $period = $item['period'] ?? null;
            $value = $item['value'] ?? null;
            if (!in_array($area, $areas, true) || !in_array($product, $products, true) || !self::isDate($period)) {
                continue;
            }
            if (!(is_string($value) || is_int($value) || is_float($value)) || !is_numeric($value)) {
                continue;
            }
            $price = (float) $value;
            if (!is_finite($price) || $price <= 0.0 || $price > self::MAX_PRICE) {
                continue;
            }
            $series = $item['series'] ?? null;
            $out[] = [
                'duoarea' => $area,
                'product' => $product,
                'period' => $period,
                'price' => $price,
                'series_id' => is_string($series) && preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $series) === 1 ? $series : '',
            ];
        }
        return $out;
    }

    private static function isDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        try {
            Estimator::parseDate($value);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /** The service's own error word (API_KEY_INVALID, OVER_RATE_LIMIT ...), when the body carries one. */
    private static function upstreamCode(mixed $decoded): ?string
    {
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        $code = is_array($error) ? ($error['code'] ?? null) : null;
        return is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{2,39}$/', $code) === 1 ? $code : null;
    }
}
