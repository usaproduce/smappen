<?php
declare(strict_types=1);

namespace App\Services;

use App\MarketData\VendorRepository;
use App\SharedRef\CogsBenchmarkRepository;

/**
 * Honest vendor comparison — spec §1.3, §6.2.
 *
 * Rules (these are LOAD-BEARING — the whole product breaks if these slip):
 *   1. Ranking is computed objectively. It is NEVER reordered for money.
 *   2. Promotion (vendor_promotions, Phase 3) is layered SEPARATELY, never
 *      mixed into the ranking math.
 *   3. USA Produce wins where it genuinely wins on price/coverage. When it
 *      appears, the row carries the affiliation disclosure label (the UI
 *      reads vendors.is_affiliated → spec §1.4).
 *
 * Phase 2 ranking scoring (no pricing data — see §13 Q3):
 *   - Coverage match (does the vendor list this category? +does it serve
 *     the region?): primary score.
 *   - Affiliation: NOT a ranking factor. is_affiliated only sets the
 *     disclosure label on the result row.
 *   - Claim status: minor preference for `claimed` (correctness signal).
 *
 * Once `cogs_benchmark` pricing is hooked into vendor offers (Phase 2.5,
 * needs §13 Q3 sign-off on which sources are public-safe), price-vs-
 * benchmark becomes the dominant score and the function below picks it up
 * automatically — the rest of the ranking shape stays the same.
 */
class VendorComparisonService
{
    public function __construct(
        private VendorRepository $vendors,
        private CogsBenchmarkRepository $benchmark,
    ) {}

    /**
     * Compare suppliers for a single category in a region.
     * Returns an objective ranking with explanations + the disclosure flag.
     *
     * @param array $basket Optional: [['ingredient_key'=>..., 'qty'=>..., 'unit'=>...], ...].
     *                      When the basket is provided, the response includes a per-vendor
     *                      cost line using the cogs_benchmark price floor for each
     *                      ingredient (since we don't have vendor-specific pricing yet).
     *                      The basket cost is THE SAME for every vendor in Phase 2 —
     *                      it's a price floor, not a per-vendor quote. Once vendor-side
     *                      pricing lands, this becomes a real differentiator.
     */
    public function compare(string $category, ?string $region = null, array $basket = []): array
    {
        $candidates = $this->vendors->candidatesForCategory($category, $region);

        // Coverage signals per candidate.
        $ranked = [];
        foreach ($candidates as $v) {
            $listings = $this->vendors->listingsFor((string) $v['id']);
            $coversCategory = false;
            $coversRegion   = false;
            $minOrderCents  = null;
            foreach ($listings as $l) {
                if ($l['category'] === $category) {
                    $coversCategory = true;
                    if ($region === null || $l['region'] === null || $l['region'] === $region) {
                        $coversRegion = true;
                    }
                    if (isset($l['min_order_cents']) && $l['min_order_cents'] !== null) {
                        $minOrderCents = (int) $l['min_order_cents'];
                    }
                }
            }
            // Coverage score: 0..10. Region match is the strongest signal.
            $score = 0;
            if ($coversCategory) $score += 5;
            if ($coversRegion)   $score += 4;
            if ($v['claim_status'] === 'claimed') $score += 1;

            $ranked[] = [
                'vendor_id'        => $v['id'],
                'vendor_name'      => $v['name'],
                'is_affiliated'    => (int) $v['is_affiliated'] === 1,
                'disclosure'       => (int) $v['is_affiliated'] === 1
                    ? 'USA Produce is an affiliated supplier.'
                    : null,
                'claim_status'     => $v['claim_status'],
                'covers_category'  => $coversCategory,
                'covers_region'    => $coversRegion,
                'min_order_cents'  => $minOrderCents,
                'score'            => $score,
            ];
        }

        usort($ranked, function ($a, $b) {
            // Higher score first. Tiebreak alphabetically — alphabetical
            // is the most defensible tiebreak: it can't be gamed and
            // doesn't subtly favor affiliated rows.
            if ($a['score'] !== $b['score']) return $b['score'] - $a['score'];
            return strcmp($a['vendor_name'], $b['vendor_name']);
        });

        // Optional basket pricing — same number for every vendor in Phase 2
        // (price floor from cogs_benchmark, not a per-vendor offer). Set on
        // the response, NOT on each ranked row, to make the equivalence
        // obvious to anyone reading the JSON.
        $basketCost = null;
        if (!empty($basket)) {
            $basketCost = $this->priceBasket($basket, $region);
        }

        return [
            'category'      => $category,
            'region'        => $region,
            'ranked'        => $ranked,
            'basket_cost'   => $basketCost,
            'methodology'   => [
                'algorithm'  => 'objective coverage + region match; affiliation is a label, not a ranking factor',
                'no_pricing' => $basketCost === null,
                'note'       => 'Per-vendor pricing not yet ingested. Basket cost (when present) is the cogs_benchmark price floor — the same for every row.',
            ],
        ];
    }

    /**
     * Sum the basket against cogs_benchmark. Used by the comparison view
     * AND by the order-consolidation view (Chunk 17).
     */
    public function priceBasket(array $basket, ?string $region = null): array
    {
        $totalCents = 0;
        $lines = [];
        $missing = [];
        foreach ($basket as $b) {
            $key = (string) ($b['ingredient_key'] ?? '');
            $qty = (float) ($b['qty'] ?? 0);
            if ($key === '' || $qty <= 0) continue;
            $row = $this->benchmark->lookup($key, $region);
            if (!$row) {
                $missing[] = $key;
                continue;
            }
            $line = (int) round((float) $row['market_price_cents'] * $qty);
            $totalCents += $line;
            $lines[] = [
                'ingredient_key' => $key,
                'qty'            => $qty,
                'unit'           => $row['unit'],
                'unit_cents'     => (int) $row['market_price_cents'],
                'line_cents'     => $line,
                'source'         => $row['source'],
            ];
        }
        return [
            'total_cents' => $totalCents,
            'lines'       => $lines,
            'missing'     => $missing,
        ];
    }
}
