<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\MarketData\VendorReviewRepository;
use App\PrivateData\PosIntegrationRepository;

/**
 * Verification + aggregation for vendor reviews.
 *
 * Verification ladder (parent spec §5.1):
 *   restaurant_exists  — org has at least one non-archived restaurant (Phase 2b default)
 *   pos_connected      — org has an active POS integration (stronger; flagged in UI)
 *   manual_review      — operator-side moderation tag (future)
 *
 * NOTE: this service reads from the `restaurants` table (which is NOT a
 * private-reservoir table per the data-wall rule — restaurants are the
 * entity, not transactional data). It must NEVER read pos_sales /
 * menu_items / plate_costs / recommendations / goals — those are the
 * wall's private side. The verification step uses `pos_integrations` as
 * a STRENGTH SIGNAL only (was the org technically able to plug in a POS?);
 * it never reads what came out of it. The data-wall test explicitly
 * forbids this service from touching pos_sales / menu / etc.
 */
class VendorReviewService
{
    public function __construct(
        private VendorReviewRepository $reviews,
        private ?PosIntegrationRepository $posIntegrations = null,
    ) {
        $this->posIntegrations = $this->posIntegrations ?? new PosIntegrationRepository();
    }

    /**
     * Submit (or update) a review. Verifies the org owns the restaurant
     * being credited; returns the upserted review id.
     *
     * Throws \RuntimeException with a user-facing message on rejection.
     */
    public function submit(string $vendorId, string $organizationId, string $userId, array $data): array
    {
        // Validate score bounds.
        $overall = (int) ($data['overall'] ?? 0);
        if ($overall < 1 || $overall > 5) {
            throw new \RuntimeException('overall must be 1..5');
        }
        foreach (['score_price','score_reliability','score_quality','score_accuracy','score_service'] as $k) {
            if (isset($data[$k]) && $data[$k] !== null && $data[$k] !== '') {
                $v = (int) $data[$k];
                if ($v < 1 || $v > 5) throw new \RuntimeException("$k must be 1..5");
            }
        }

        // Verification gate.
        $restaurantId = isset($data['restaurant_id']) ? (string) $data['restaurant_id'] : null;
        [$verif, $owned] = $this->verifyOrg($organizationId, $restaurantId);
        if (!$owned) {
            throw new \RuntimeException('Add a restaurant to your org before reviewing — Carafe reviews are operator-only.');
        }

        $id = $this->reviews->upsert($vendorId, $organizationId, $userId, $restaurantId, $data, $verif);

        // Recompute the vendor's aggregate score and persist on the vendors row.
        $this->refreshAggregate($vendorId);

        return [
            'review_id'             => $id,
            'verification_strength' => $verif,
        ];
    }

    public function refreshAggregate(string $vendorId): void
    {
        $agg = $this->reviews->aggregateForVendor($vendorId);
        Database::getInstance()->query(
            'UPDATE vendors SET aggregate_rating = ?, rating_count = ? WHERE id = ?',
            [$agg['overall'], $agg['count'], $vendorId]
        );
    }

    /** Returns [verification_strength, owned_bool]. */
    private function verifyOrg(string $organizationId, ?string $restaurantId): array
    {
        // The org must own at least one non-archived restaurant.
        $rstCount = Database::getInstance()->fetch(
            'SELECT COUNT(*) AS n FROM restaurants WHERE organization_id = ? AND archived_at IS NULL',
            [$organizationId]
        );
        $owned = (int) ($rstCount['n'] ?? 0) > 0;
        if (!$owned) return ['restaurant_exists', false];

        // If a specific restaurant was named, verify it belongs to the org.
        if ($restaurantId !== null) {
            $r = Database::getInstance()->fetch(
                'SELECT 1 AS one FROM restaurants WHERE id = ? AND organization_id = ? AND archived_at IS NULL LIMIT 1',
                [$restaurantId, $organizationId]
            );
            if (!$r) return ['restaurant_exists', false];
        }

        // Stronger signal: has the org ever connected any POS? We route
        // through the PrivateData repo which returns a bare boolean, so
        // this service never touches token data or transactional rows.
        $stronger = $this->posIntegrations->organizationHasAnyConnection($organizationId);
        return [$stronger ? 'pos_connected' : 'restaurant_exists', true];
    }
}
