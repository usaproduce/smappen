<?php
declare(strict_types=1);

namespace App\MarketData;

use App\Core\Database;

/**
 * Vendor reviews — the trust layer. One per (org, vendor), editable by
 * that org only. Vendors can't edit/remove/reorder. Spam defense lives
 * in VendorReviewService (verification check), not here.
 *
 * Wall rules: MarketData namespace.
 */
class VendorReviewRepository
{
    /** Upsert by (org, vendor) — operators editing a prior review = same row. */
    public function upsert(string $vendorId, string $organizationId, string $userId, ?string $restaurantId, array $data, string $verificationStrength): string
    {
        $existing = Database::getInstance()->fetch(
            'SELECT id FROM vendor_reviews WHERE organization_id = ? AND vendor_id = ?',
            [$organizationId, $vendorId]
        );
        if ($existing) {
            Database::getInstance()->query(
                'UPDATE vendor_reviews
                    SET overall = ?, score_price = ?, score_reliability = ?, score_quality = ?,
                        score_accuracy = ?, score_service = ?, body = ?, photo_url = ?,
                        categories_bought = ?, volume_band = ?, delivery_or_pickup = ?,
                        verification_strength = ?, restaurant_id = ?, updated_at = NOW()
                  WHERE id = ?',
                self::params($data, $verificationStrength, $restaurantId, $existing['id'])
            );
            return (string) $existing['id'];
        }
        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO vendor_reviews
                (id, vendor_id, organization_id, reviewer_user_id, restaurant_id, overall,
                 score_price, score_reliability, score_quality, score_accuracy, score_service,
                 body, photo_url, categories_bought, volume_band, delivery_or_pickup,
                 verification_strength, is_hidden, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())',
            [
                $id, $vendorId, $organizationId, $userId, $restaurantId,
                (int) $data['overall'],
                self::optInt($data, 'score_price'),
                self::optInt($data, 'score_reliability'),
                self::optInt($data, 'score_quality'),
                self::optInt($data, 'score_accuracy'),
                self::optInt($data, 'score_service'),
                $data['body'] ?? null,
                $data['photo_url'] ?? null,
                isset($data['categories_bought']) ? json_encode($data['categories_bought']) : null,
                $data['volume_band'] ?? null,
                $data['delivery_or_pickup'] ?? null,
                $verificationStrength,
            ]
        );
        return $id;
    }

    private static function params(array $data, string $verif, ?string $restaurantId, string $id): array
    {
        return [
            (int) $data['overall'],
            self::optInt($data, 'score_price'),
            self::optInt($data, 'score_reliability'),
            self::optInt($data, 'score_quality'),
            self::optInt($data, 'score_accuracy'),
            self::optInt($data, 'score_service'),
            $data['body'] ?? null,
            $data['photo_url'] ?? null,
            isset($data['categories_bought']) ? json_encode($data['categories_bought']) : null,
            $data['volume_band'] ?? null,
            $data['delivery_or_pickup'] ?? null,
            $verif,
            $restaurantId,
            $id,
        ];
    }

    private static function optInt(array $d, string $k): ?int
    {
        return isset($d[$k]) && $d[$k] !== null && $d[$k] !== '' ? (int) $d[$k] : null;
    }

    public function findById(string $id): ?array
    {
        return Database::getInstance()->fetch('SELECT * FROM vendor_reviews WHERE id = ?', [$id]);
    }

    public function findByOrgVendor(string $organizationId, string $vendorId): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT * FROM vendor_reviews WHERE organization_id = ? AND vendor_id = ?',
            [$organizationId, $vendorId]
        );
    }

    public function listForVendor(string $vendorId, int $limit = 50): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT id, organization_id, overall, score_price, score_reliability, score_quality,
                    score_accuracy, score_service, body, photo_url, volume_band, delivery_or_pickup,
                    verification_strength, created_at, updated_at
               FROM vendor_reviews
              WHERE vendor_id = ? AND is_hidden = 0
              ORDER BY created_at DESC LIMIT ?',
            [$vendorId, $limit]
        );
    }

    public function aggregateForVendor(string $vendorId): array
    {
        $row = Database::getInstance()->fetch(
            'SELECT
                COUNT(*)               AS n,
                AVG(overall)           AS avg_overall,
                AVG(score_price)       AS avg_price,
                AVG(score_reliability) AS avg_reliability,
                AVG(score_quality)     AS avg_quality,
                AVG(score_accuracy)    AS avg_accuracy,
                AVG(score_service)     AS avg_service
               FROM vendor_reviews
              WHERE vendor_id = ? AND is_hidden = 0',
            [$vendorId]
        );
        return [
            'count'          => (int) ($row['n'] ?? 0),
            'overall'        => $row['avg_overall']     === null ? null : round((float) $row['avg_overall'], 2),
            'price'          => $row['avg_price']       === null ? null : round((float) $row['avg_price'], 2),
            'reliability'    => $row['avg_reliability'] === null ? null : round((float) $row['avg_reliability'], 2),
            'quality'        => $row['avg_quality']     === null ? null : round((float) $row['avg_quality'], 2),
            'accuracy'       => $row['avg_accuracy']    === null ? null : round((float) $row['avg_accuracy'], 2),
            'service'        => $row['avg_service']     === null ? null : round((float) $row['avg_service'], 2),
        ];
    }
}
