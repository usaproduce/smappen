<?php
namespace App\Services;

use App\Core\Database;

/**
 * VendorClassifierService — assigns vendor_type from Google's
 * primaryType + secondary types + name. Carafe Vendor Network Spec v3
 * §4.3: "Classify vendor_type from Google primaryType + types + name
 * keywords; low confidence → review queue."
 *
 * The classifier is intentionally a layered rule cascade rather than a
 * model:
 *
 *   1. Brand-name hit (e.g. "Sysco", "Restaurant Depot") — highest
 *      confidence. Brand → type is unambiguous.
 *   2. Strong primaryType match (e.g. 'produce_market' → produce) —
 *      high confidence. Google's own classification is rarely wrong
 *      when it's specific.
 *   3. Generic primaryType + name keyword (e.g. 'wholesaler' + "meat"
 *      → meat) — medium confidence.
 *   4. Generic primaryType only ('wholesaler' alone) — low confidence,
 *      defaults to broadline, flagged for review.
 *
 * Anything < CONFIDENCE_REVIEW_THRESHOLD ends up in the review queue
 * (vendors.classification_needs_review = 1). The operator can override
 * via ReviewQueueController.
 *
 * Output:
 *   ['type' => 'produce', 'category' => 'produce', 'confidence' => 95,
 *    'signals' => ['brand:sysco', 'primary_type:wholesaler']]
 *
 * Why we don't try to be smarter: a perfect classifier needs labeled
 * training data we don't have. The cascade is deterministic, auditable
 * (signals list explains every decision), and the review queue catches
 * the residue we don't trust.
 */
class VendorClassifierService
{
    public const CONFIDENCE_REVIEW_THRESHOLD = 60;

    /**
     * Strong primary-type → vendor_type. Google's own classification
     * when specific is almost always right.
     */
    private const TYPE_DIRECT_MAP = [
        'produce_market'      => 'produce',
        'butcher_shop'        => 'meat',
        'meat_market'         => 'meat',
        'seafood_market'      => 'seafood',
        'fish_market'         => 'seafood',
        'asian_grocery_store' => 'specialty_ethnic',
        'warehouse_store'     => 'cash_carry',
        'wholesale_market'    => 'broadline',
        'supermarket'         => 'local_grocery',
        'grocery_store'       => 'local_grocery',
    ];

    /**
     * Generic primary-type values — alone they tell us nothing; we
     * combine with name keywords to refine. These ARE treated as
     * positive evidence (a wholesaler IS more likely a broadline-ish
     * vendor than a random google_place), but not enough alone.
     */
    private const GENERIC_TYPES = [
        'wholesaler', 'food_store', 'market', 'store', 'establishment',
    ];

    /**
     * Brand-name → vendor_type. Keys are lowercase substrings of the
     * normalized name; matching is case-insensitive.
     */
    private const BRAND_MAP = [
        // Broadline national distributors
        'sysco'             => 'broadline',
        'us foods'          => 'broadline',
        'usfoods'           => 'broadline',
        'pfg'               => 'broadline',
        'performance food'  => 'broadline',
        'gordon food'       => 'broadline',
        'reinhart'          => 'broadline',
        // Cash & carry
        'restaurant depot'  => 'cash_carry',
        'costco business'   => 'cash_carry',
        "chef's warehouse"  => 'cash_carry',
        'chefs warehouse'   => 'cash_carry',
        'jetro'             => 'cash_carry',
    ];

    /**
     * Name keyword → vendor_type when combined with a generic
     * primaryType. Multiple matches don't compound; whichever fires
     * first wins (cascade is in declaration order).
     */
    private const NAME_KEYWORD_MAP = [
        'produce'          => 'produce',
        'seafood'          => 'seafood',
        'fish'             => 'seafood',
        'meat'             => 'meat',
        'butcher'          => 'meat',
        'poultry'          => 'meat',
        'dairy'            => 'dairy_bakery_bev',
        'bakery'           => 'dairy_bakery_bev',
        'beverage'         => 'dairy_bakery_bev',
        'asian'            => 'specialty_ethnic',
        'latino'           => 'specialty_ethnic',
        'mexican'          => 'specialty_ethnic',
        'mediterranean'    => 'specialty_ethnic',
        'kosher'           => 'specialty_ethnic',
        'halal'            => 'specialty_ethnic',
        'restaurant supply'=> 'smallwares_equip',
        'smallwares'       => 'smallwares_equip',
    ];

    /** vendor_type → spec §2 default category (when no other signal). */
    private const TYPE_TO_CATEGORY = [
        'broadline'         => 'broadline',
        'cash_carry'        => 'broadline',
        'produce'           => 'produce',
        'meat'              => 'protein',
        'seafood'           => 'seafood',
        'dairy_bakery_bev'  => 'specialty',
        'specialty_ethnic'  => 'specialty',
        'local_grocery'     => 'specialty',
        'smallwares_equip'  => 'specialty',
    ];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Classify a single vendor candidate. Pure-ish — depends only on
     * input + static maps.
     *
     * @return array{type:string,category:string,confidence:int,signals:string[],needs_review:bool}
     */
    public static function classify(?string $primaryType, array $types, string $name): array
    {
        $primaryType = strtolower((string) $primaryType);
        $typesLower  = array_map('strtolower', $types);
        $nameLower   = strtolower(trim($name));
        $signals     = [];

        $type       = null;
        $confidence = 0;

        // 1. Brand-name hit — highest confidence (95).
        foreach (self::BRAND_MAP as $needle => $vt) {
            if ($needle !== '' && str_contains($nameLower, $needle)) {
                $type       = $vt;
                $confidence = 95;
                $signals[]  = "brand:$needle";
                break;
            }
        }

        // 2. Strong primaryType match — high confidence (85).
        if ($type === null && isset(self::TYPE_DIRECT_MAP[$primaryType])) {
            $type       = self::TYPE_DIRECT_MAP[$primaryType];
            $confidence = 85;
            $signals[]  = "primary_type:$primaryType";
        }

        // 2b. Strong match found in secondary types list.
        if ($type === null) {
            foreach ($typesLower as $t) {
                if (isset(self::TYPE_DIRECT_MAP[$t])) {
                    $type       = self::TYPE_DIRECT_MAP[$t];
                    $confidence = 75;            // slightly weaker — not Google's PRIMARY pick
                    $signals[]  = "secondary_type:$t";
                    break;
                }
            }
        }

        // 3. Generic primaryType + name keyword combo — medium (70).
        if ($type === null) {
            $isGenericType = in_array($primaryType, self::GENERIC_TYPES, true)
                          || (bool) array_intersect($typesLower, self::GENERIC_TYPES);
            if ($isGenericType) {
                foreach (self::NAME_KEYWORD_MAP as $kw => $vt) {
                    if (str_contains($nameLower, $kw)) {
                        $type       = $vt;
                        $confidence = 70;
                        $signals[]  = "generic_type+keyword:$primaryType+$kw";
                        break;
                    }
                }
            }
        }

        // 3b. Name keyword alone (no helpful type) — low-medium (50).
        if ($type === null) {
            foreach (self::NAME_KEYWORD_MAP as $kw => $vt) {
                if (str_contains($nameLower, $kw)) {
                    $type       = $vt;
                    $confidence = 50;
                    $signals[]  = "name_keyword:$kw";
                    break;
                }
            }
        }

        // 4. Fall-through: generic type only → low confidence default.
        if ($type === null) {
            if (in_array($primaryType, self::GENERIC_TYPES, true) || (bool) array_intersect($typesLower, self::GENERIC_TYPES)) {
                $type       = 'broadline';
                $confidence = 30;
                $signals[]  = "default_from_generic:$primaryType";
            } else {
                $type       = 'broadline';
                $confidence = 15;
                $signals[]  = 'default_unknown';
            }
        }

        return [
            'type'         => $type,
            'category'     => self::TYPE_TO_CATEGORY[$type] ?? 'specialty',
            'confidence'   => $confidence,
            'signals'      => $signals,
            'needs_review' => $confidence < self::CONFIDENCE_REVIEW_THRESHOLD,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Persistence — apply classification to a vendor row
    // ─────────────────────────────────────────────────────────────────

    /**
     * Classify a single vendor by id, looking up Google signals from
     * vendor_google_details if present, else from the bare vendor
     * row. Writes the result + (re)sets needs_review.
     *
     * Returns the classification result.
     */
    public function classifyVendor(string $vendorId): array
    {
        $row = $this->db->fetch(
            "SELECT v.id, v.name,
                    gd.primary_type      AS gd_primary_type,
                    gd.types_json        AS gd_types_json
             FROM vendors v
             LEFT JOIN vendor_google_details gd ON gd.vendor_id = v.id
             WHERE v.id = ?
             LIMIT 1",
            [$vendorId]
        );
        if (!$row) {
            throw new \RuntimeException("vendor not found: $vendorId");
        }
        $types  = $row['gd_types_json'] ? (json_decode($row['gd_types_json'], true) ?: []) : [];
        $result = self::classify($row['gd_primary_type'] ?? null, $types, (string) $row['name']);

        $this->db->query(
            "UPDATE vendors
             SET type                        = ?,
                 primary_category            = ?,
                 classification_confidence   = ?,
                 classification_signals_json = ?,
                 classification_needs_review = ?,
                 classified_at               = NOW(),
                 updated_at                  = NOW()
             WHERE id = ?",
            [
                $result['type'],
                $result['category'],
                $result['confidence'],
                json_encode($result['signals']),
                $result['needs_review'] ? 1 : 0,
                $vendorId,
            ]
        );
        return $result;
    }

    /**
     * Classify every vendor that doesn't yet have a classification (or
     * whose underlying signals have changed). Returns counts. Idempotent.
     */
    public function classifyPending(int $batchSize = 5000): array
    {
        $ids = $this->db->fetchAll(
            "SELECT id FROM vendors
             WHERE classified_at IS NULL
               AND merged_into IS NULL
             ORDER BY created_at ASC
             LIMIT ?",
            [$batchSize]
        );
        $counts = ['total' => count($ids), 'auto' => 0, 'review' => 0];
        foreach ($ids as $r) {
            try {
                $c = $this->classifyVendor($r['id']);
                $counts[$c['needs_review'] ? 'review' : 'auto']++;
            } catch (\Throwable $e) {
                error_log('[classify] vendor ' . $r['id'] . ': ' . $e->getMessage());
            }
        }
        return $counts;
    }
}
