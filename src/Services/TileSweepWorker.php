<?php
namespace App\Services;

use App\Core\Database;

/**
 * TileSweepWorker — single-tile sweep processor. Spec v3 §4.1, §4.2,
 * §9 step 4, §10 guardrails 5/8/9/10.
 *
 * One `runOne()` call:
 *   1. Atomically claims one queued tile via FOR UPDATE SKIP LOCKED
 *      (so N parallel workers never grab the same tile).
 *   2. Loads the parent campaign + its vendor-type query plan.
 *   3. For each vendor type × Places query: acquires a Search-bucket
 *      token, calls PlacesClient.searchNearby/searchText (the choke
 *      point that writes api_cost_events + enforces budget cap).
 *   4. For each result, calls VendorUpsertService.upsertVendorFromPlace
 *      (idempotent, spec §12.6).
 *   5. Computes result_id_hash (the spec §12.3 fingerprint) and
 *      writes back to seed_tiles.
 *   6. Updates the campaign counters (spent_usd, tiles_done_count,
 *      vendor_count).
 *
 * On BudgetCapExceededException: pauses the campaign, marks the tile
 * back to 'queued', logs the halt, returns.
 *
 * Auto-subdivide: if a sweep returns the saturation marker (full page
 * via nextPageToken on text search, or 20 results on nearby), schedule
 * four child tiles per spec §4.1. The current tile is still considered
 * "done" with whatever it returned.
 *
 * Designed to be invoked in a loop from scripts/seed-tile-worker.php.
 */
class TileSweepWorker
{
    /** A "saturated" Places searchNearby returns exactly maxResultCount. */
    private const SATURATION_THRESHOLD = 20;
    /** Don't subdivide below this edge length — diminishing returns + cost spiral. */
    private const MIN_SUBDIVIDE_KM = 1.0;

    private Database $db;
    private PlacesClient $places;
    private VendorUpsertService $upserts;
    private SeedCampaignService $campaigns;
    private PlacesRateLimiter $rateLimiter;

    public function __construct(
        ?Database $db = null,
        ?PlacesClient $places = null,
        ?VendorUpsertService $upserts = null,
        ?SeedCampaignService $campaigns = null,
        ?PlacesRateLimiter $rateLimiter = null
    ) {
        $this->db          = $db          ?? Database::getInstance();
        $this->places      = $places      ?? new PlacesClient();
        $this->upserts     = $upserts     ?? new VendorUpsertService();
        $this->campaigns   = $campaigns   ?? new SeedCampaignService();
        $this->rateLimiter = $rateLimiter ?? new PlacesRateLimiter();
    }

    /**
     * Process exactly one queued tile. Returns a result summary, or
     * null if the queue was empty / no eligible tile was found.
     */
    public function runOne(): ?array
    {
        $tile = $this->claimNextTile();
        if (!$tile) return null;

        $campaign = $this->campaigns->findById($tile['campaign_id']);
        if (!$campaign) {
            $this->markTile($tile['id'], 'failed', ['error_message' => 'campaign not found']);
            return ['tile_id' => $tile['id'], 'status' => 'failed', 'reason' => 'campaign not found'];
        }
        if ($campaign['status'] !== 'running') {
            // Owner paused/cancelled mid-flight — release back to queued so
            // we don't burn time on a tile whose campaign isn't active.
            $this->markTile($tile['id'], 'queued', []);
            return ['tile_id' => $tile['id'], 'status' => 'skipped', 'reason' => "campaign status={$campaign['status']}"];
        }

        $budgetCap = $campaign['budget_cap_usd'] !== null ? (float) $campaign['budget_cap_usd'] : null;
        $this->places->setCampaignContext($campaign['id'], $tile['id'], $budgetCap);

        $vendorTypes = json_decode($campaign['vendor_types_json'] ?? '[]', true) ?: [];
        $typeMap     = require dirname(__DIR__, 2) . '/config/carafe_vendor_types.php';

        // Collect raw place objects first (no upserts yet). The downstream
        // upsert loop is conditional on the §12.3 hash check below — if
        // this re-sweep produced the same place-id set as the previous
        // sweep of this tile, skip the writes entirely.
        $collected      = [];     // place_id => place object
        $callsMade      = 0;
        $costTotal      = 0.0;
        $saturatedAny   = false;
        $errorMessage   = null;
        $budgetHalted   = false;

        $callFailures = [];
        try {
            foreach ($vendorTypes as $vt) {
                $cfg = $typeMap[$vt] ?? null;
                if (!$cfg) continue;

                foreach (($cfg['places_types'] ?? []) as $placesType) {
                    $this->rateLimiter->acquire(PlacesRateLimiter::BUCKET_SEARCH);
                    try {
                        [$results, $cost, $saturated] = $this->searchNearbyTile($tile, $placesType);
                        $callsMade++;
                        $costTotal += $cost;
                        if ($saturated) $saturatedAny = true;
                        foreach ($results as $place) {
                            $pid = $place['id'] ?? null;
                            if ($pid && !isset($collected[$pid])) $collected[$pid] = $place;
                        }
                    } catch (BudgetCapExceededException $e) {
                        throw $e; // budget halts the whole tile, don't swallow
                    } catch (\Throwable $e) {
                        // Per-call failure (bad type, transient Places 5xx, etc.) —
                        // log + continue. Killing the whole tile on one bad type
                        // would be a regression for vendor_types with mixed valid
                        // and invalid Places types.
                        $callFailures[] = "nearby[$placesType]: " . $e->getMessage();
                        error_log("[seed-tile-worker] {$tile['id']} nearby[$placesType]: " . $e->getMessage());
                    }
                }

                foreach (($cfg['text_queries'] ?? []) as $query) {
                    $this->rateLimiter->acquire(PlacesRateLimiter::BUCKET_SEARCH);
                    try {
                        [$results, $cost, $saturated] = $this->searchTextTile($tile, $query);
                        $callsMade++;
                        $costTotal += $cost;
                        if ($saturated) $saturatedAny = true;
                        foreach ($results as $place) {
                            $pid = $place['id'] ?? null;
                            if ($pid && !isset($collected[$pid])) $collected[$pid] = $place;
                        }
                    } catch (BudgetCapExceededException $e) {
                        throw $e;
                    } catch (\Throwable $e) {
                        $callFailures[] = "text[$query]: " . $e->getMessage();
                        error_log("[seed-tile-worker] {$tile['id']} text[$query]: " . $e->getMessage());
                    }
                }
            }
        } catch (BudgetCapExceededException $e) {
            $budgetHalted = true;
            $errorMessage = 'budget cap halted: ' . $e->getMessage();
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
        } finally {
            $this->places->clearCampaignContext();
        }
        // If every single call failed (e.g. API key is bad), surface that
        // so the tile goes to 'failed' instead of silently 'done with 0
        // results' — looks-like-it-worked-but-didn't is worse than failed.
        if ($errorMessage === null && !$budgetHalted && $callsMade === 0 && !empty($callFailures)) {
            $errorMessage = 'all calls failed: ' . implode(' | ', array_slice($callFailures, 0, 3));
        }

        $placeIds = array_fill_keys(array_keys($collected), true);
        $hash     = self::resultIdHash($placeIds);

        if ($budgetHalted) {
            // Roll the tile back to queued so when the budget is raised
            // (or new month rolls over) the worker can finish it.
            $this->markTile($tile['id'], 'queued', [
                'calls_made'   => $callsMade,
                'results_count'=> 0,
                'cost_usd'     => $costTotal,
                'error_message'=> $errorMessage,
            ]);
            try {
                $this->campaigns->pause($campaign['id'], 'budget_cap_reached');
            } catch (\Throwable $_) { /* status drift — ignore */ }
            $this->bumpCampaignCounters($campaign['id'], $costTotal, 0, 0);
            return ['tile_id' => $tile['id'], 'status' => 'budget_halt', 'cost_usd' => $costTotal, 'campaign_paused' => true];
        }

        if ($errorMessage !== null) {
            $this->markTile($tile['id'], 'failed', [
                'calls_made'   => $callsMade,
                'results_count'=> 0,
                'cost_usd'     => $costTotal,
                'error_message'=> substr($errorMessage, 0, 255),
            ]);
            $this->bumpCampaignCounters($campaign['id'], $costTotal, 0, 0);
            return ['tile_id' => $tile['id'], 'status' => 'failed', 'error' => $errorMessage, 'cost_usd' => $costTotal];
        }

        // §12.3 fingerprint check — skip upserts on unchanged re-sweep.
        // Only counts as "unchanged" if there was a prior hash to compare
        // against (i.e., this isn't the initial sweep of this tile).
        $priorHash = $tile['result_id_hash'] ?? null;
        $unchanged = $priorHash !== null && $priorHash === $hash;

        $createdCount = 0;
        if (!$unchanged) {
            foreach ($collected as $pid => $place) {
                try {
                    $r = $this->upserts->upsertVendorFromPlace($pid, $place);
                    if (!empty($r['created'])) $createdCount++;
                } catch (\Throwable $e) {
                    error_log('[seed-tile-worker] upsert failed for place ' . $pid . ': ' . $e->getMessage());
                }
            }
        }

        $this->markTile($tile['id'], 'done', [
            'result_id_hash' => $hash,
            'calls_made'     => $callsMade,
            'results_count'  => $createdCount,
            'cost_usd'       => $costTotal,
        ]);
        $this->bumpCampaignCounters($campaign['id'], $costTotal, 1, $createdCount);

        // Auto-subdivide on saturation (spec §4.1) — only if the tile is
        // bigger than the MIN_SUBDIVIDE threshold; otherwise we'd cost-
        // spiral on a metro core that's genuinely dense.
        $subdivided = [];
        if ($saturatedAny && self::tileEdgeKm($tile) >= self::MIN_SUBDIVIDE_KM) {
            $subdivided = $this->campaigns->subdivideTile($tile['id']);
        }

        return [
            'tile_id'        => $tile['id'],
            'status'         => 'done',
            'calls_made'     => $callsMade,
            'results_count'  => $createdCount,
            'cost_usd'       => round($costTotal, 4),
            'unchanged'      => $unchanged,
            'subdivided'     => $subdivided,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers — internal
    // ─────────────────────────────────────────────────────────────────

    /**
     * Stable hash of the place-id set returned by all sweep calls on
     * this tile. Sort before hashing so call ordering doesn't change
     * the fingerprint. Spec §12.3.
     */
    public static function resultIdHash(array $placeIds): string
    {
        $ids = array_keys($placeIds);
        sort($ids);
        return hash('sha256', implode("\n", $ids));
    }

    public static function tileEdgeKm(array $tile): float
    {
        $latMid = ((float) $tile['lat_min'] + (float) $tile['lat_max']) / 2.0;
        $hKm    = ((float) $tile['lat_max'] - (float) $tile['lat_min']) * 111.32;
        $wKm    = ((float) $tile['lng_max'] - (float) $tile['lng_min']) * 111.32 * max(0.000001, cos(deg2rad($latMid)));
        return min($hKm, $wKm);
    }

    private function claimNextTile(): ?array
    {
        $tile = null;
        try {
            $this->db->beginTransaction();
            $row = $this->db->fetch(
                "SELECT t.* FROM seed_tiles t
                 JOIN seed_campaigns c ON c.id = t.campaign_id
                 WHERE t.status = 'queued'
                   AND c.status = 'running'
                 ORDER BY t.created_at ASC
                 LIMIT 1 FOR UPDATE SKIP LOCKED"
            );
            if ($row) {
                $this->db->query(
                    "UPDATE seed_tiles
                     SET status='running', started_at=NOW(),
                         attempt_count = attempt_count + 1
                     WHERE id=?",
                    [$row['id']]
                );
                $tile = $row;
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            try { $this->db->rollback(); } catch (\Throwable $_) {}
            error_log('[seed-tile-worker] claim failed: ' . $e->getMessage());
            return null;
        }
        return $tile;
    }

    private function markTile(string $tileId, string $status, array $cols): void
    {
        $set    = ['status' => $status];
        $params = [];
        $sql    = 'UPDATE seed_tiles SET status = ?';
        $params[] = $status;
        foreach (['result_id_hash','calls_made','results_count','cost_usd','error_message'] as $c) {
            if (array_key_exists($c, $cols)) {
                $sql .= ", `$c` = ?";
                $params[] = $cols[$c];
            }
        }
        if ($status === 'done' || $status === 'failed') {
            $sql .= ', finished_at = NOW()';
        }
        if ($status === 'queued') {
            // Releasing back to queue — clear started_at so re-claim is clean.
            $sql .= ', started_at = NULL';
        }
        $sql .= ' WHERE id = ?';
        $params[] = $tileId;
        $this->db->query($sql, $params);
    }

    private function bumpCampaignCounters(string $campaignId, float $costDelta, int $tilesDoneDelta, int $vendorDelta): void
    {
        // Two statements rather than one because referencing a column in
        // a CASE WHEN expression that's *also* on the SET-clause LHS is
        // ambiguous across MySQL versions on whether it sees the pre- or
        // post-update value. Splitting eliminates that ambiguity.
        $this->db->query(
            "UPDATE seed_campaigns
             SET spent_usd        = spent_usd + ?,
                 tiles_done_count = tiles_done_count + ?,
                 vendor_count     = vendor_count + ?,
                 updated_at       = NOW()
             WHERE id = ?",
            [$costDelta, $tilesDoneDelta, $vendorDelta, $campaignId]
        );
        // If this tick completed the last tile, transition the campaign.
        // The WHERE clause makes this a no-op when there's still work.
        $this->db->query(
            "UPDATE seed_campaigns
             SET status = 'done', finished_at = NOW(), updated_at = NOW()
             WHERE id = ?
               AND status = 'running'
               AND tile_count > 0
               AND tiles_done_count >= tile_count",
            [$campaignId]
        );
    }

    /** @return array{0:array,1:float,2:bool} [results, cost_usd, saturated] */
    private function searchNearbyTile(array $tile, string $placesType): array
    {
        // searchNearby wants a circle. Inscribe the tile in a circle:
        // center = centroid, radius = half-diagonal in meters.
        $latMid = ((float) $tile['lat_min'] + (float) $tile['lat_max']) / 2.0;
        $lngMid = ((float) $tile['lng_min'] + (float) $tile['lng_max']) / 2.0;
        $hKm    = ((float) $tile['lat_max'] - (float) $tile['lat_min']) * 111.32 / 2.0;
        $wKm    = ((float) $tile['lng_max'] - (float) $tile['lng_min']) * 111.32 * max(0.000001, cos(deg2rad($latMid))) / 2.0;
        $radiusM = (int) max(50, round(sqrt($hKm * $hKm + $wKm * $wKm) * 1000));

        $costBefore = $this->places->projectCost([PlacesClient::ENDPOINT_NEARBY]);
        $resp = $this->places->searchNearby([
            'locationRestriction' => [
                'circle' => [
                    'center' => ['latitude' => $latMid, 'longitude' => $lngMid],
                    'radius' => $radiusM,
                ],
            ],
            'includedTypes'  => [$placesType],
            'maxResultCount' => 20,
        ]);
        $places    = $resp['places'] ?? [];
        $saturated = count($places) >= self::SATURATION_THRESHOLD;
        return [$places, $costBefore, $saturated];
    }

    /** @return array{0:array,1:float,2:bool} [results, cost_usd, saturated] */
    private function searchTextTile(array $tile, string $query): array
    {
        $low  = ['latitude' => (float) $tile['lat_min'], 'longitude' => (float) $tile['lng_min']];
        $high = ['latitude' => (float) $tile['lat_max'], 'longitude' => (float) $tile['lng_max']];

        $costBefore = $this->places->projectCost([PlacesClient::ENDPOINT_TEXT]);
        $resp = $this->places->searchText([
            'textQuery' => $query,
            'locationRestriction' => [
                'rectangle' => ['low' => $low, 'high' => $high],
            ],
            'pageSize' => 20,
        ]);
        $places    = $resp['places'] ?? [];
        $saturated = !empty($resp['nextPageToken']);
        return [$places, $costBefore, $saturated];
    }

}
