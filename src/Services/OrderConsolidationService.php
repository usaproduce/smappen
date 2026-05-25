<?php
declare(strict_types=1);

namespace App\Services;

use App\MarketData\VendorRepository;
use App\SharedRef\CogsBenchmarkRepository;

/**
 * Order consolidation — spec §6.4.
 *
 * Take a basket spanning multiple categories. For each category, pull the
 * top-ranked vendor (objective ranking, same as VendorComparisonService).
 * Show two scenarios:
 *
 *   - Status quo  : one vendor per category, N min-order thresholds to clear
 *   - Consolidated: one broadline vendor that covers all categories, one
 *                   min-order threshold
 *
 * Surfaces:
 *   - vendors_count_diff (e.g., 4 vendors → 1)
 *   - cost diff (zero in Phase 2 since basket pricing is the same floor
 *     across vendors — see VendorComparisonService comment)
 *   - min_order_total_diff (real number: Σ min orders vs single min order)
 *   - admin time saved (rough estimate: 20 min/vendor/week × 4 weeks)
 *
 * Once per-vendor pricing data is hooked in, the cost diff becomes real
 * and this becomes the killer surface that drives consolidation onto
 * USA Produce (when it wins).
 */
class OrderConsolidationService
{
    private const ADMIN_MIN_PER_VENDOR_PER_WEEK = 20;
    private const WEEKS_PER_MONTH = 4;

    public function __construct(
        private VendorRepository $vendors,
        private CogsBenchmarkRepository $benchmark,
    ) {}

    /**
     * @param array $basket [['ingredient_key'=>..., 'qty'=>..., 'unit'=>...,
     *                        'category'=>'produce'|'protein'|'dairy'|...], ...]
     */
    public function compare(array $basket, ?string $region = null): array
    {
        // Bucket basket by category.
        $byCategory = [];
        foreach ($basket as $b) {
            $cat = (string) ($b['category'] ?? '');
            if ($cat === '') continue;
            $byCategory[$cat][] = $b;
        }

        // Status quo: pick top vendor per category.
        $statusQuoVendors = [];
        $statusQuoMinOrderTotal = 0;
        foreach ($byCategory as $cat => $items) {
            $cands = $this->vendors->candidatesForCategory($cat, $region);
            if (!$cands) continue;
            // Pick top — affiliated tiebreaker handled inside the repo
            // ORDER BY; we just take [0].
            $top = $cands[0];
            $listings = $this->vendors->listingsFor((string) $top['id']);
            $minOrder = null;
            foreach ($listings as $l) {
                if ($l['category'] === $cat && isset($l['min_order_cents'])) {
                    $minOrder = (int) $l['min_order_cents'];
                    break;
                }
            }
            $statusQuoMinOrderTotal += (int) ($minOrder ?? 0);
            $statusQuoVendors[] = [
                'category'        => $cat,
                'vendor_id'       => $top['id'],
                'vendor_name'     => $top['name'],
                'is_affiliated'   => (int) $top['is_affiliated'] === 1,
                'min_order_cents' => $minOrder,
                'item_count'      => count($items),
            ];
        }

        // Consolidated: find broadline vendors that cover ALL categories.
        $allCats = array_keys($byCategory);
        $consolidatedCandidates = [];
        if ($allCats) {
            $broadliners = $this->vendors->candidatesForCategory('broadline', $region);
            foreach ($broadliners as $v) {
                $listings = $this->vendors->listingsFor((string) $v['id']);
                $covered = [];
                $minOrder = null;
                foreach ($listings as $l) {
                    if (in_array($l['category'], $allCats, true)) {
                        $covered[$l['category']] = true;
                        if ($l['category'] === 'broadline' && isset($l['min_order_cents'])) {
                            $minOrder = (int) $l['min_order_cents'];
                        }
                    }
                }
                if (count($covered) === count($allCats)) {
                    $consolidatedCandidates[] = [
                        'vendor_id'       => $v['id'],
                        'vendor_name'     => $v['name'],
                        'is_affiliated'   => (int) $v['is_affiliated'] === 1,
                        'min_order_cents' => $minOrder,
                    ];
                }
            }
        }

        $consolidatedMinOrder = !empty($consolidatedCandidates) ? (int) ($consolidatedCandidates[0]['min_order_cents'] ?? 0) : null;
        $vendorsCount = count($statusQuoVendors);
        $adminMinSavedPerMonth = max(0, $vendorsCount - 1) * self::ADMIN_MIN_PER_VENDOR_PER_WEEK * self::WEEKS_PER_MONTH;

        return [
            'status_quo' => [
                'vendors'              => $statusQuoVendors,
                'vendor_count'         => $vendorsCount,
                'min_order_total_cents'=> $statusQuoMinOrderTotal,
            ],
            'consolidated' => [
                'candidates'           => $consolidatedCandidates,
                'vendor_count'         => empty($consolidatedCandidates) ? null : 1,
                'min_order_cents'      => $consolidatedMinOrder,
            ],
            'savings' => [
                'min_order_total_diff_cents' => $consolidatedMinOrder !== null
                    ? $statusQuoMinOrderTotal - $consolidatedMinOrder
                    : null,
                'admin_minutes_saved_per_month' => empty($consolidatedCandidates) ? 0 : $adminMinSavedPerMonth,
                'admin_savings_estimate_cents'  => empty($consolidatedCandidates)
                    ? 0
                    : (int) round(($adminMinSavedPerMonth / 60) * 2500), // ~$25/hr operator time
            ],
            'note' => 'Per-vendor pricing not yet ingested. Cost diff lands when that pipe opens; admin/min-order savings are real today.',
        ];
    }
}
