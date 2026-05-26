<?php
namespace App\Services;

use App\Core\Database;

/**
 * PlacesEnrichService — orchestrates the full Place Details pull per a
 * campaign's enrich_policy. Carafe Vendor Network Spec v3 §4.4 + §9
 * step 7.
 *
 * Single point that wires together every Phase 1–6 piece:
 *
 *   PlacesClient            — actual /v1/places/{id} call + cost ledger
 *   VendorCacheService      — three-tier freshness check + GET_LOCK
 *                             request coalescing (§12.1)
 *   VendorUpsertService     — idempotent vendor_google_details +
 *                             vendor_google_reviews + vendor_google_photos
 *                             writes (§12.6)
 *   SeedCampaignService     — pause-on-budget-halt
 *
 * Three operating modes:
 *
 *   enrichVendor($vendorId, $tier)
 *       Single-vendor enrich. Used by the admin "view vendor" path
 *       (on_demand policy), the campaign batch loop, and the nightly
 *       tier refresh. Coalesced — N callers on the same place_id
 *       within the TTL fire ONE Places call.
 *
 *   enrichCampaign($campaignId, $batchSize)
 *       Apply the campaign's enrich_policy across its in-bbox vendors:
 *         - all              → every vendor (highest cost)
 *         - priority_types   → ['broadline','cash_carry','produce','seafood']
 *         - on_demand        → no-op (lazy)
 *       Stops + pauses the campaign on BudgetCapExceededException.
 *
 *   refreshStaleTier($tier, $batchSize)
 *       Nightly worker — pull only the records whose `{tier}_fetched_at`
 *       is past TTL, using a tier-specific narrow field mask. Cuts re-
 *       enrich volume ~80% vs. whole-record refresh (§12.1).
 *
 * Storage gate: every public method short-circuits when
 * places_storage_allowed=false. The system is still usable in id-only
 * fallback (sweep continues), enrich just becomes a no-op.
 */
class PlacesEnrichService
{
    /** Vendor types that the `priority_types` policy enriches at seed time. Mirrors config/carafe_vendor_types.php. */
    public const PRIORITY_TYPES = ['broadline', 'cash_carry', 'produce', 'seafood'];

    private Database $db;
    private PlacesClient $places;
    private VendorCacheService $cache;
    private VendorUpsertService $upserts;
    private SeedCampaignService $campaigns;

    public function __construct(
        ?Database            $db        = null,
        ?PlacesClient        $places    = null,
        ?VendorCacheService  $cache     = null,
        ?VendorUpsertService $upserts   = null,
        ?SeedCampaignService $campaigns = null
    ) {
        $this->db        = $db        ?? Database::getInstance();
        $this->places    = $places    ?? new PlacesClient();
        $this->cache     = $cache     ?? new VendorCacheService();
        $this->upserts   = $upserts   ?? new VendorUpsertService();
        $this->campaigns = $campaigns ?? new SeedCampaignService();
    }

    // ─────────────────────────────────────────────────────────────────
    // Single-vendor enrich (used by on_demand, campaign loop, refresh)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Pull (and store) Place Details for one vendor. Returns the cache
     * disposition + (on fetch) the parsed payload.
     *
     * @param string  $vendorId
     * @param string  $tier         'full' | 'cold' | 'warm' | 'hot'
     * @param ?string $campaignId   Attaches the cost to this campaign +
     *                              enables the per-call budget halt.
     * @return array {
     *     status:    'enriched'|'cached'|'no_place_id'|'storage_disallowed'|'locked_out',
     *     fetched?:  bool,
     *     payload?:  array,
     *     place_id?: string,
     * }
     */
    public function enrichVendor(string $vendorId, string $tier = 'full', ?string $campaignId = null): array
    {
        if (!$this->places->isStorageAllowed()) {
            return ['status' => 'storage_disallowed'];
        }

        $row = $this->db->fetch(
            "SELECT vl.google_place_id, vl.vendor_id
             FROM vendor_locations vl
             JOIN vendors v ON v.id = vl.vendor_id
             WHERE vl.vendor_id = ?
               AND vl.google_place_id IS NOT NULL
               AND v.merged_into IS NULL
             ORDER BY vl.is_primary DESC, vl.created_at ASC
             LIMIT 1",
            [$vendorId]
        );
        if (!$row || empty($row['google_place_id'])) {
            return ['status' => 'no_place_id'];
        }
        $placeId = $row['google_place_id'];

        $cacheResult = $this->cache->withCoalescedFetch($placeId, $tier, function () use ($placeId, $vendorId, $tier, $campaignId) {
            $maskPreset = self::maskPresetForTier($tier);
            if ($campaignId !== null) {
                // Attach the cost to the campaign + opt into its budget cap.
                $cap = $this->campaignBudgetCap($campaignId);
                $this->places->setCampaignContext($campaignId, null, $cap);
            }
            try {
                $payload = $this->places->placeDetails($placeId, $maskPreset);
                $this->upserts->upsertGoogleDetails($placeId, $vendorId, $payload, $tier);
                if (!empty($payload['reviews'])) {
                    $this->upserts->upsertReviews($placeId, $vendorId, $payload['reviews']);
                }
                if (!empty($payload['photos'])) {
                    $this->upserts->upsertPhotos($placeId, $vendorId, $payload['photos']);
                }
                return $payload;
            } finally {
                if ($campaignId !== null) {
                    $this->places->clearCampaignContext();
                }
            }
        });

        if (!empty($cacheResult['fresh']) || !empty($cacheResult['coalesced'])) {
            return ['status' => 'cached', 'place_id' => $placeId];
        }
        if (!empty($cacheResult['locked_out'])) {
            return ['status' => 'locked_out', 'place_id' => $placeId];
        }
        return [
            'status'   => 'enriched',
            'fetched'  => true,
            'place_id' => $placeId,
            'payload'  => $cacheResult['result'] ?? null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Campaign batch enrich
    // ─────────────────────────────────────────────────────────────────

    /**
     * Apply the campaign's enrich_policy across its candidate vendors.
     * Returns a result tally. Stops + pauses the campaign on
     * BudgetCapExceededException (spec §10 guardrail 5).
     */
    public function enrichCampaign(string $campaignId, int $batchSize = 100): array
    {
        $campaign = $this->campaigns->findById($campaignId);
        if (!$campaign) {
            return ['status' => 'campaign_not_found'];
        }
        if (!$this->places->isStorageAllowed()) {
            return ['status' => 'storage_disallowed'];
        }

        $policy = $campaign['enrich_policy'] ?? 'priority_types';
        if ($policy === 'on_demand') {
            return ['status' => 'on_demand_skipped', 'policy' => $policy];
        }

        $candidates = self::candidatesForCampaign($this->db, $campaign, $policy, $batchSize);
        $tally = [
            'policy'      => $policy,
            'candidates'  => count($candidates),
            'enriched'    => 0,
            'cached'      => 0,
            'no_place'    => 0,
            'failed'      => 0,
            'halted_budget' => false,
        ];
        foreach ($candidates as $row) {
            try {
                $r = $this->enrichVendor($row['vendor_id'], 'full', $campaignId);
                switch ($r['status']) {
                    case 'enriched':  $tally['enriched']++; break;
                    case 'cached':    $tally['cached']++;   break;
                    case 'no_place_id': $tally['no_place']++; break;
                    case 'locked_out':
                    case 'storage_disallowed':
                        // locked_out is benign (other worker is fetching) — count as cached.
                        $tally['cached']++; break;
                }
            } catch (BudgetCapExceededException $e) {
                $tally['halted_budget'] = true;
                try {
                    $this->campaigns->pause($campaignId, 'budget_cap_reached_enrich');
                } catch (\Throwable $_) { /* status may already be paused */ }
                break;
            } catch (\Throwable $e) {
                $tally['failed']++;
                error_log('[enrich] vendor ' . $row['vendor_id'] . ': ' . $e->getMessage());
            }
        }
        return $tally;
    }

    // ─────────────────────────────────────────────────────────────────
    // Nightly tier refresh (§12.1)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Re-pull stale rows for a single tier, using only that tier's
     * narrow mask. Cheap by design — the whole point of the three-tier
     * cache is that hot fields refresh on a 30-day cycle without ever
     * touching the cold-tier fee.
     */
    public function refreshStaleTier(string $tier, int $batchSize = 200): array
    {
        if (!in_array($tier, ['cold', 'warm', 'hot'], true)) {
            throw new \InvalidArgumentException("refresh tier must be cold|warm|hot, got '$tier'");
        }
        if (!$this->places->isStorageAllowed()) {
            return ['status' => 'storage_disallowed'];
        }

        $stalePlaceIds = $this->cache->staleForRefresh($tier, $batchSize);
        $tally = [
            'tier'      => $tier,
            'candidates'=> count($stalePlaceIds),
            'refreshed' => 0,
            'cached'    => 0,
            'failed'    => 0,
        ];
        foreach ($stalePlaceIds as $pid) {
            $row = $this->db->fetch(
                'SELECT vendor_id FROM vendor_google_details WHERE google_place_id = ? LIMIT 1',
                [$pid]
            );
            if (!$row) continue;
            try {
                $r = $this->enrichVendor($row['vendor_id'], $tier, null);
                if ($r['status'] === 'enriched')  $tally['refreshed']++;
                elseif ($r['status'] === 'cached') $tally['cached']++;
            } catch (\Throwable $e) {
                $tally['failed']++;
                error_log("[refresh:$tier] vendor {$row['vendor_id']}: " . $e->getMessage());
            }
        }
        return $tally;
    }

    // ─────────────────────────────────────────────────────────────────
    // Pure helpers
    // ─────────────────────────────────────────────────────────────────

    /** Tier name → field-mask preset name in config/google_places_pricing.php. */
    public static function maskPresetForTier(string $tier): string
    {
        return match ($tier) {
            'cold' => 'tier_cold',
            'warm' => 'tier_warm',
            'hot'  => 'tier_hot',
            'full' => 'enrich_full',
            default => throw new \InvalidArgumentException("unknown tier: $tier"),
        };
    }

    /**
     * Apply the policy filter against a campaign's bbox. Public+static
     * so tests can exercise the SQL-shape logic via a stub Database.
     *
     * Candidates are vendors:
     *   - with a google_place_id on their primary location
     *   - inside the campaign's bbox
     *   - not merged
     *   - without an existing vendor_google_details row (un-enriched)
     *   - matching the policy filter:
     *       'all'             → no type filter
     *       'priority_types'  → vendors.type IN PRIORITY_TYPES
     */
    public static function candidatesForCampaign(Database $db, array $campaign, string $policy, int $limit): array
    {
        $latMin = (float) $campaign['bbox_lat_min'];
        $latMax = (float) $campaign['bbox_lat_max'];
        $lngMin = (float) $campaign['bbox_lng_min'];
        $lngMax = (float) $campaign['bbox_lng_max'];

        $typeFilter = '';
        $typeParams = [];
        if ($policy === 'priority_types') {
            $ph         = implode(',', array_fill(0, count(self::PRIORITY_TYPES), '?'));
            $typeFilter = " AND v.type IN ($ph) ";
            $typeParams = self::PRIORITY_TYPES;
        }
        $sql = "SELECT v.id AS vendor_id, vl.google_place_id
                FROM vendors v
                JOIN vendor_locations vl ON vl.vendor_id = v.id AND vl.is_primary = 1
                WHERE vl.google_place_id IS NOT NULL
                  AND v.merged_into IS NULL
                  AND vl.lat BETWEEN ? AND ?
                  AND vl.lng BETWEEN ? AND ?
                  $typeFilter
                  AND NOT EXISTS (
                    SELECT 1 FROM vendor_google_details gd
                    WHERE gd.google_place_id = vl.google_place_id
                  )
                ORDER BY v.created_at ASC
                LIMIT ?";
        $params = array_merge(
            [$latMin, $latMax, $lngMin, $lngMax],
            $typeParams,
            [$limit]
        );
        return $db->fetchAll($sql, $params);
    }

    private function campaignBudgetCap(string $campaignId): ?float
    {
        $row = $this->db->fetch('SELECT budget_cap_usd FROM seed_campaigns WHERE id = ?', [$campaignId]);
        if (!$row || $row['budget_cap_usd'] === null) return null;
        return (float) $row['budget_cap_usd'];
    }
}
