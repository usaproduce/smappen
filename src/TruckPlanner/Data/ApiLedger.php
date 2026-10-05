<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;
use App\TruckPlanner\Services\Support\Redactor;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * One row in `api_cost_events` per upstream HTTP call (04_BACKEND.md 5.3, "Ledger").
 *
 * The table is the house ledger of metered calls and has no tenant column. Truck Planner rows are
 * recognised by their SKU, which starts with `tp_`. `error_message` holds a transport or upstream code
 * only ("http_403", "PERMISSION_DENIED", "timeout"): never a URL, never an upstream body.
 *
 * record() never throws: a ledger that cannot be written must not fail the request that made the call.
 */
class ApiLedger
{
    private ?Database $db;
    /** One log line per process, however many calls fail. */
    private static bool $failureLogged = false;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * @param string $sku a key of `unit_cost_usd` in config/truck_planner.php
     * @param int $units billable units of this call: matrix elements on a successful answer, 1 for a
     *                   single lookup, 0 when the call failed
     * @param int|null $httpStatus null when no HTTP answer arrived
     * @param string|null $errorCode a short code, never free text from upstream
     * @param string|null $fieldMask the X-Goog-FieldMask of the call (comma-separated), when it had one
     */
    public function record(
        string $sku,
        int $units,
        ?int $httpStatus,
        int $latencyMs,
        ?string $errorCode,
        ?string $fieldMask = null
    ): void {
        try {
            $unitCost = $this->unitCost($sku);
            $units = max(0, $units);
            $this->db()->query(
                'INSERT INTO api_cost_events
                    (id, sku, billable_units, unit_cost_usd, total_cost_usd, field_mask_hash,
                     http_status, latency_ms, error_message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Database::uuid(),
                    $sku,
                    $units,
                    Sql::f($unitCost),
                    Sql::f($units * $unitCost),
                    self::maskHash($fieldMask),
                    $httpStatus,
                    max(0, $latencyMs),
                    $errorCode === null ? null : Redactor::text($errorCode),
                ]
            );
        } catch (\Throwable $e) {
            if (!self::$failureLogged) {
                self::$failureLogged = true;
                error_log('[tp] ledger insert failed');
            }
        }
    }

    /**
     * Billable units recorded since the start of the database server's day for the given SKUs: what the
     * global daily budget is checked against. When the ledger cannot be read the answer is the largest
     * integer, so the caller sees a spent budget and asks Google for nothing.
     *
     * @param list<string> $skus
     */
    public function unitsToday(array $skus): int
    {
        if ($skus === []) {
            return 0;
        }
        try {
            $row = $this->db()->fetch(
                'SELECT COALESCE(SUM(billable_units), 0) AS units
                   FROM api_cost_events
                  WHERE sku IN (' . Sql::marks(count($skus)) . ')
                    AND called_at >= CURDATE()',
                array_values($skus)
            );
            return (int) ($row['units'] ?? 0);
        } catch (\Throwable $e) {
            if (!self::$failureLogged) {
                self::$failureLogged = true;
                error_log('[tp] ledger read failed');
            }
            return PHP_INT_MAX;
        }
    }

    /** First 16 hexadecimal characters of the SHA-256 of the sorted mask tokens, null without a mask. */
    public static function maskHash(?string $fieldMask): ?string
    {
        if ($fieldMask === null) {
            return null;
        }
        $tokens = array_values(array_filter(array_map('trim', explode(',', $fieldMask)), static fn (string $t): bool => $t !== ''));
        if ($tokens === []) {
            return null;
        }
        sort($tokens, SORT_STRING);
        return substr(hash('sha256', implode(',', $tokens)), 0, 16);
    }

    private function unitCost(string $sku): float
    {
        $costs = TpConfig::get('unit_cost_usd');
        return is_array($costs) && isset($costs[$sku]) ? (float) $costs[$sku] : 0.0;
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }
}
