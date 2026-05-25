<?php
declare(strict_types=1);

namespace App\PrivateData;

use App\Core\Database;

class PosSalesRepository
{
    /** Upsert one line; dedupe on (restaurant_id, pos_provider, pos_line_uid). */
    public function upsertLine(string $organizationId, string $restaurantId, string $provider, array $line): void
    {
        $existing = Database::getInstance()->fetch(
            'SELECT id FROM pos_sales WHERE restaurant_id = ? AND pos_provider = ? AND pos_line_uid = ?',
            [$restaurantId, $provider, $line['pos_line_uid']]
        );
        if ($existing) {
            // Sales records are append-only — re-runs that hit the same line are no-ops.
            return;
        }
        Database::getInstance()->query(
            'INSERT INTO pos_sales
                (id, organization_id, restaurant_id, menu_item_id, pos_provider, pos_order_id, pos_line_uid,
                 qty, gross_cents, net_cents, sold_at, daypart_label, raw_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                Database::uuid(), $organizationId, $restaurantId, $line['menu_item_id'] ?? null,
                $provider, $line['pos_order_id'], $line['pos_line_uid'],
                (int) ($line['qty'] ?? 1),
                (int) ($line['gross_cents'] ?? 0),
                isset($line['net_cents']) ? (int) $line['net_cents'] : null,
                $line['sold_at'],
                $line['daypart_label'] ?? self::dayPart($line['sold_at']),
                isset($line['raw']) ? json_encode($line['raw']) : null,
            ]
        );
    }

    /** Most recent sale timestamp for incremental pulls. */
    public function maxSoldAt(string $restaurantId, string $provider): ?string
    {
        $row = Database::getInstance()->fetch(
            'SELECT MAX(sold_at) AS last_sold FROM pos_sales WHERE restaurant_id = ? AND pos_provider = ?',
            [$restaurantId, $provider]
        );
        return $row['last_sold'] ?? null;
    }

    /**
     * Average monthly qty per menu_item over the last 90 days.
     * Returns: [menu_item_id => est_monthly_qty]
     */
    public function monthlyVolumeByItem(string $restaurantId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT menu_item_id, SUM(qty) AS total_qty, MIN(sold_at) AS first_sold
               FROM pos_sales
              WHERE restaurant_id = ?
                AND sold_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                AND menu_item_id IS NOT NULL
              GROUP BY menu_item_id',
            [$restaurantId]
        );
        $out = [];
        foreach ($rows as $r) {
            $days = max(1, (int) ((time() - strtotime((string) $r['first_sold'])) / 86400));
            $days = min($days, 90);
            $out[(string) $r['menu_item_id']] = (int) round(((int) $r['total_qty'] / $days) * 30);
        }
        return $out;
    }

    private static function dayPart(string $soldAtIso): string
    {
        $h = (int) date('G', strtotime($soldAtIso));
        if ($h < 11)  return 'breakfast';
        if ($h < 16)  return 'lunch';
        if ($h < 22)  return 'dinner';
        return 'late';
    }
}
