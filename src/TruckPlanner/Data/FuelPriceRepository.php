<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;
use App\TruckPlanner\Services\Support\Money;

/**
 * `tp_fuel_prices`: weekly retail fuel prices of the U.S. Energy Information Administration
 * (03_DATA.md 9.1 and 13.2, 04_BACKEND.md 5.6).
 *
 * Shared reference data without an organization column: one row per area (`duoarea`, for example R1Z),
 * product (EPMR regular gasoline, EPD2D diesel) and week (`period`, the Monday the price is dated). The
 * newest row of an area and product is the current price. Prices are stored in thousandths of a dollar
 * and travel as dollars per gallon under the key `price`.
 */
class FuelPriceRepository
{
    private const SOURCE = 'eia';
    private const ROWS_PER_INSERT = 100;

    private ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * The newest stored price of an area and product, or null when there is none.
     *
     * @return array{duoarea: string, product: string, period: string, price: float, series_id: string,
     *               fetched_at: string}|null `period` is "YYYY-MM-DD", `price` dollars per gallon
     */
    public function latest(string $duoarea, string $product): ?array
    {
        $row = $this->db()->fetch(
            'SELECT duoarea, product, period, price_milli, series_id, fetched_at
               FROM tp_fuel_prices
              WHERE duoarea = ? AND product = ?
              ORDER BY period DESC
              LIMIT 1',
            [$duoarea, $product]
        );
        if ($row === null) {
            return null;
        }
        return [
            'duoarea' => (string) $row['duoarea'],
            'product' => (string) $row['product'],
            'period' => (string) $row['period'],
            'price' => Money::fromMilli((int) $row['price_milli']),
            'series_id' => (string) $row['series_id'],
            'fetched_at' => (string) $row['fetched_at'],
        ];
    }

    /** The newest week any stored price is dated ("YYYY-MM-DD"), or null while the table is empty. */
    public function newestPeriod(): ?string
    {
        $row = $this->db()->fetch('SELECT MAX(period) AS newest FROM tp_fuel_prices');
        $newest = $row['newest'] ?? null;
        return $newest === null ? null : (string) $newest;
    }

    /**
     * Stores weekly prices; a row that is there already for the area, product and week takes the new
     * price. Answers the number of rows written.
     *
     * @param list<array<string, mixed>> $rows each { duoarea, product, period: "YYYY-MM-DD",
     *                                         price: float (dollars per gallon), series_id }
     */
    public function upsertMany(array $rows): int
    {
        $byKey = [];
        foreach ($rows as $row) {
            $duoarea = (string) $row['duoarea'];
            $product = (string) $row['product'];
            $period = (string) $row['period'];
            $byKey[$duoarea . '|' . $product . '|' . $period] = [
                $duoarea,
                $product,
                $period,
                Money::toMilli((float) $row['price']),
                (string) $row['series_id'],
                self::SOURCE,
            ];
        }
        ksort($byKey, SORT_STRING);
        foreach (array_chunk(array_values($byKey), self::ROWS_PER_INSERT) as $chunk) {
            $params = [];
            foreach ($chunk as $values) {
                array_push($params, ...$values);
            }
            $this->db()->query(
                'INSERT INTO tp_fuel_prices (duoarea, product, period, price_milli, series_id, src, fetched_at)
                 VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, NOW())')) . '
                 ON DUPLICATE KEY UPDATE price_milli = VALUES(price_milli), series_id = VALUES(series_id),
                        src = VALUES(src), fetched_at = NOW()',
                $params
            );
        }
        return count($byKey);
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }
}
