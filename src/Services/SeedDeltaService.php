<?php
namespace App\Services;

use App\Core\Database;

/**
 * SeedDeltaService — delta-only seeding mechanics. Carafe Vendor
 * Network Spec v3 §12.3 + §9 step 8.
 *
 * The whole point: re-sweeps shouldn't reprocess unchanged geography.
 * The tile fingerprint (seed_tiles.result_id_hash) tells us, after a
 * fresh sweep of an already-swept tile, whether its place-id set
 * actually changed. TileSweepWorker uses that hash to skip the
 * downstream upsert path when nothing changed (Phase 8 refactor).
 *
 * This service runs the *scheduling* side:
 *
 *   scheduleResweepForCampaign($campaignId, $maxAgeDays)
 *       Flip eligible 'done' tiles back to 'queued' so the existing
 *       TileSweepWorker picks them up. Keeps the prior result_id_hash
 *       on the row — the worker compares the post-sweep hash against
 *       it to decide whether to skip upserts.
 *
 *   recoverStuckTiles($maxRunningSeconds)
 *       Janitor: tiles stuck in 'running' (a worker crashed mid-tile)
 *       get released back to 'queued'. Idempotent — safe to call from
 *       a cron every minute.
 *
 *   deltaSummary($campaignId)
 *       Counts: total / changed / unchanged / never-swept tiles. Used
 *       by the admin dashboard to show "your re-sweep would only need
 *       to process N changed tiles" before the operator confirms.
 *
 * Resumability is already handled by the existing FOR UPDATE SKIP
 * LOCKED claim + the 'queued'/'running'/'done' state machine.
 * recoverStuckTiles is the only piece the existing worker is missing
 * to be fully crash-safe.
 */
class SeedDeltaService
{
    /** Default re-sweep eligibility: tiles done > 30 days ago. */
    public const DEFAULT_RESWEEP_AGE_DAYS = 30;

    /** Default stuck-tile timeout: any 'running' tile older than 30 min is presumed dead. */
    public const DEFAULT_STUCK_TILE_SECONDS = 30 * 60;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Flip 'done' tiles older than $maxAgeDays back to 'queued' so they
     * get re-swept. Returns the number of tiles re-queued.
     *
     * IMPORTANT: keeps result_id_hash on the row so the worker can
     * compare post-sweep — that's the whole §12.3 mechanism.
     */
    public function scheduleResweepForCampaign(string $campaignId, int $maxAgeDays = self::DEFAULT_RESWEEP_AGE_DAYS): int
    {
        $stmt = $this->db->query(
            "UPDATE seed_tiles
             SET status     = 'queued',
                 started_at = NULL,
                 finished_at= NULL
             WHERE campaign_id = ?
               AND status     = 'done'
               AND finished_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$campaignId, $maxAgeDays]
        );
        $rowCount = (int) $stmt->rowCount();
        if ($rowCount > 0) {
            // Roll the campaign back to running so the worker picks up
            // the newly-queued tiles. If a campaign was 'done', it
            // becomes 'running' again.
            $this->db->query(
                "UPDATE seed_campaigns
                 SET status = 'running',
                     finished_at = NULL,
                     tiles_done_count = GREATEST(0, tiles_done_count - ?),
                     updated_at = NOW()
                 WHERE id = ?
                   AND status IN ('done','paused','approved')",
                [$rowCount, $campaignId]
            );
        }
        return $rowCount;
    }

    /**
     * Recover tiles that have been 'running' too long — almost always a
     * crashed worker. Returns count released. Safe to call from any cron;
     * the WHERE filter only matches genuinely stale rows.
     */
    public function recoverStuckTiles(int $maxRunningSeconds = self::DEFAULT_STUCK_TILE_SECONDS): int
    {
        $stmt = $this->db->query(
            "UPDATE seed_tiles
             SET status     = 'queued',
                 started_at = NULL,
                 attempt_count = LEAST(255, attempt_count + 1)
             WHERE status = 'running'
               AND started_at IS NOT NULL
               AND started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$maxRunningSeconds]
        );
        return (int) $stmt->rowCount();
    }

    /**
     * Diagnostic summary of how a campaign would respond to a re-sweep
     * right now. Doesn't mutate state — pre-flight only.
     *
     * @return array{
     *     campaign_id: string,
     *     total_tiles: int,
     *     never_swept: int,
     *     done_tiles:  int,
     *     done_within: int,
     *     resweep_eligible: int,
     *     stuck_running:   int,
     * }
     */
    public function deltaSummary(string $campaignId, int $maxAgeDays = self::DEFAULT_RESWEEP_AGE_DAYS, int $stuckSeconds = self::DEFAULT_STUCK_TILE_SECONDS): array
    {
        $row = $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'done') AS done,
                SUM(status = 'queued' OR (status = 'done' AND result_id_hash IS NULL)) AS never_swept,
                SUM(status = 'done' AND finished_at < DATE_SUB(NOW(), INTERVAL ? DAY)) AS eligible,
                SUM(status = 'done' AND finished_at >= DATE_SUB(NOW(), INTERVAL ? DAY)) AS done_within,
                SUM(status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)) AS stuck
             FROM seed_tiles
             WHERE campaign_id = ?",
            [$maxAgeDays, $maxAgeDays, $stuckSeconds, $campaignId]
        ) ?: [];
        return [
            'campaign_id'      => $campaignId,
            'total_tiles'      => (int) ($row['total']        ?? 0),
            'never_swept'      => (int) ($row['never_swept']  ?? 0),
            'done_tiles'       => (int) ($row['done']         ?? 0),
            'done_within'      => (int) ($row['done_within']  ?? 0),
            'resweep_eligible' => (int) ($row['eligible']     ?? 0),
            'stuck_running'    => (int) ($row['stuck']        ?? 0),
        ];
    }

    /**
     * Bulk diagnostic across every running/done campaign — useful for a
     * nightly cron that wants to know "anything to re-sweep tonight?"
     * before deciding whether to fire workers.
     *
     * @return array<string, array>  keyed by campaign_id
     */
    public function deltaSummaryAll(int $maxAgeDays = self::DEFAULT_RESWEEP_AGE_DAYS): array
    {
        $ids = $this->db->fetchAll(
            "SELECT id FROM seed_campaigns
             WHERE status IN ('running','done','paused')
             ORDER BY created_at DESC
             LIMIT 200"
        );
        $out = [];
        foreach ($ids as $r) {
            $out[$r['id']] = $this->deltaSummary($r['id'], $maxAgeDays);
        }
        return $out;
    }
}
