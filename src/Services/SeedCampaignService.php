<?php
namespace App\Services;

use App\Core\Database;

/**
 * SeedCampaignService — lifecycle ops for seed_campaigns. Spec v3 §4 + §7.
 *
 * Owns:
 *   - create   draft → estimating (with persisted estimator output)
 *   - run      estimating → running (materializes tiles, dispatches workers)
 *   - pause    running → paused (with reason)
 *   - resume   paused → running
 *   - cancel   any → cancelled
 *   - summary / index
 *
 * Tile materialization (the meat of `run`) splits the campaign's bbox
 * into a grid sized by density_profile, inserts one `seed_tiles` row
 * per cell. Subsequent workers pull from `seed_tiles` directly via
 * FOR UPDATE SKIP LOCKED (§12.4 concurrency primitive).
 *
 * Status transitions are validated — admins can't skip stages, and a
 * draft can't go straight to running without an estimate.
 */
class SeedCampaignService
{
    /** Tile edge length in km per density profile — must mirror SeedEstimatorService. */
    public const TILE_SIZE_KM = [
        'rural'    => 12.0,
        'suburban' => 6.0,
        'dense'    => 2.5,
        'mixed'    => 6.0,
    ];

    private Database $db;
    private SeedEstimatorService $estimator;

    public function __construct(?Database $db = null, ?SeedEstimatorService $estimator = null)
    {
        $this->db        = $db        ?? Database::getInstance();
        $this->estimator = $estimator ?? new SeedEstimatorService();
    }

    /**
     * Create a draft campaign + run the estimator. Returns the row.
     *
     * @param array $body {
     *     name: string required,
     *     region_geojson: array required (GeoJSON Feature or Geometry),
     *     bbox: [latMin,lngMin,latMax,lngMax] required,
     *     vendor_types: array required,
     *     enrich_policy: 'all'|'priority_types'|'on_demand' optional,
     *     density_profile: 'rural'|'suburban'|'dense'|'mixed' optional,
     *     source_mix: array optional,
     *     budget_cap_usd: float optional (NULL = no cap),
     * }
     */
    public function create(array $body, ?string $createdBy = null): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name required');
        }
        $bbox = $body['bbox'] ?? null;
        if (!is_array($bbox) || count($bbox) !== 4) {
            throw new \InvalidArgumentException('bbox must be [lat_min,lng_min,lat_max,lng_max]');
        }
        [$latMin, $lngMin, $latMax, $lngMax] = array_map('floatval', $bbox);
        if ($latMax <= $latMin || $lngMax <= $lngMin) {
            throw new \InvalidArgumentException('bbox must have lat_max > lat_min and lng_max > lng_min');
        }

        $vendorTypes = (array) ($body['vendor_types'] ?? []);
        if (empty($vendorTypes)) {
            throw new \InvalidArgumentException('vendor_types required');
        }

        $policy   = $body['enrich_policy']   ?? 'priority_types';
        $density  = $body['density_profile'] ?? 'mixed';
        $regionGj = $body['region_geojson']  ?? ['type' => 'bbox', 'coordinates' => [$latMin, $lngMin, $latMax, $lngMax]];
        $srcMix   = $body['source_mix']      ?? null;
        $cap      = isset($body['budget_cap_usd']) ? (float) $body['budget_cap_usd'] : null;

        $estimate = $this->estimator->estimate(
            [
                'bbox'            => [$latMin, $lngMin, $latMax, $lngMax],
                'vendor_types'    => $vendorTypes,
                'enrich_policy'   => $policy,
                'density_profile' => $density,
            ],
            self::currentMonthlyVolume($this->db)
        );

        $id = Database::uuid();
        $this->db->query(
            "INSERT INTO seed_campaigns
              (id, name, region_geojson,
               bbox_lat_min, bbox_lng_min, bbox_lat_max, bbox_lng_max,
               vendor_types_json, enrich_policy, density_profile, source_mix_json,
               budget_cap_usd, status,
               estimate_low_usd, estimate_expected_usd, estimate_high_usd,
               estimate_skus_json, estimate_meta_json, estimated_at,
               created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'estimating',
                     ?, ?, ?, ?, ?, NOW(), ?, NOW(), NOW())",
            [
                $id, $name, json_encode($regionGj),
                $latMin, $lngMin, $latMax, $lngMax,
                json_encode(array_values($vendorTypes)), $policy, $density,
                $srcMix ? json_encode($srcMix) : null,
                $cap,
                $estimate['total']['low'],
                $estimate['total']['expected'],
                $estimate['total']['high'],
                json_encode([
                    'sweep_skus'  => $estimate['sweep']['sku_breakdown_expected'],
                    'enrich_skus' => $estimate['enrich']['sku_breakdown_expected'],
                ]),
                json_encode($estimate['meta']),
                $createdBy,
            ]
        );

        return $this->findById($id) ?? throw new \RuntimeException('failed to load created campaign');
    }

    /**
     * Approve + materialize + transition to running.
     * Spec §10 g2: "No campaign without an approved estimate + budget cap."
     */
    public function run(string $campaignId): array
    {
        $c = $this->findById($campaignId);
        if (!$c) {
            throw new \RuntimeException("campaign not found: $campaignId");
        }
        if (!in_array($c['status'], ['draft', 'estimating', 'approved', 'paused'], true)) {
            throw new \DomainException("cannot run from status '{$c['status']}'");
        }
        if ($c['estimate_expected_usd'] === null) {
            throw new \DomainException('cannot run a campaign without an estimate');
        }

        $tileCount = $this->materializeTiles($c);

        $this->db->query(
            "UPDATE seed_campaigns
             SET status = 'running',
                 tile_count = ?,
                 approved_at = COALESCE(approved_at, NOW()),
                 started_at  = COALESCE(started_at,  NOW()),
                 updated_at = NOW()
             WHERE id = ?",
            [$tileCount, $campaignId]
        );
        return $this->findById($campaignId);
    }

    public function pause(string $campaignId, string $reason = ''): void
    {
        $c = $this->findById($campaignId);
        if (!$c) throw new \RuntimeException("campaign not found: $campaignId");
        if ($c['status'] !== 'running') {
            throw new \DomainException("cannot pause from status '{$c['status']}'");
        }
        $this->db->query(
            "UPDATE seed_campaigns SET status='paused', pause_reason=?, updated_at=NOW() WHERE id=?",
            [$reason ?: null, $campaignId]
        );
    }

    public function resume(string $campaignId): void
    {
        $c = $this->findById($campaignId);
        if (!$c) throw new \RuntimeException("campaign not found: $campaignId");
        if ($c['status'] !== 'paused') {
            throw new \DomainException("cannot resume from status '{$c['status']}'");
        }
        $this->db->query(
            "UPDATE seed_campaigns SET status='running', pause_reason=NULL, updated_at=NOW() WHERE id=?",
            [$campaignId]
        );
    }

    public function cancel(string $campaignId): void
    {
        $this->db->query(
            "UPDATE seed_campaigns SET status='cancelled', updated_at=NOW(), finished_at=NOW() WHERE id=?",
            [$campaignId]
        );
        // Skip remaining tiles so workers stop picking them.
        $this->db->query(
            "UPDATE seed_tiles SET status='skipped', finished_at=NOW()
             WHERE campaign_id=? AND status IN ('queued','running')",
            [$campaignId]
        );
    }

    public function findById(string $campaignId): ?array
    {
        return $this->db->fetch('SELECT * FROM seed_campaigns WHERE id=?', [$campaignId]);
    }

    public function summary(string $campaignId): ?array
    {
        $c = $this->findById($campaignId);
        if (!$c) return null;
        // Tile + vendor live counts come from joins so the values shown in
        // the dashboard reflect actual state, not cached counters that may
        // have drifted if a worker crashed mid-tile.
        $tileStats = $this->db->fetch(
            "SELECT
              COUNT(*) AS total,
              SUM(status='done')    AS done,
              SUM(status='failed')  AS failed,
              SUM(status='running') AS running,
              SUM(status='queued')  AS queued
             FROM seed_tiles WHERE campaign_id=?",
            [$campaignId]
        ) ?: [];
        $c['tile_stats'] = array_map('intval', $tileStats);
        return $c;
    }

    /** @return array<array<string,mixed>> */
    public function index(int $limit = 50, int $offset = 0): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, name, status, density_profile, enrich_policy,
                    estimate_expected_usd, budget_cap_usd, spent_usd,
                    tile_count, tiles_done_count, vendor_count,
                    created_at, updated_at, started_at, finished_at
             FROM seed_campaigns
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────
    // Tile materialization
    // ─────────────────────────────────────────────────────────────────

    /** Generate seed_tiles rows for a campaign. Idempotent — skipped if already materialized. */
    public function materializeTiles(array $campaign): int
    {
        // Already materialized?
        $existing = (int) ($this->db->fetch(
            'SELECT COUNT(*) AS n FROM seed_tiles WHERE campaign_id=?',
            [$campaign['id']]
        )['n'] ?? 0);
        if ($existing > 0) return $existing;

        $tiles = self::computeTileGrid(
            (float) $campaign['bbox_lat_min'],
            (float) $campaign['bbox_lng_min'],
            (float) $campaign['bbox_lat_max'],
            (float) $campaign['bbox_lng_max'],
            $campaign['density_profile']
        );

        if (empty($tiles)) return 0;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->pdo()->prepare(
                "INSERT INTO seed_tiles
                   (id, campaign_id, lat_min, lng_min, lat_max, lng_max, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'queued', NOW())"
            );
            foreach ($tiles as $t) {
                $stmt->execute([
                    Database::uuid(), $campaign['id'],
                    $t[0], $t[1], $t[2], $t[3],
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        return count($tiles);
    }

    /**
     * Pure tile-grid generator. Returns an array of [latMin,lngMin,latMax,lngMax].
     * Spec §4.1 — grid stays inside the bbox; cell size derived from density.
     */
    public static function computeTileGrid(float $latMin, float $lngMin, float $latMax, float $lngMax, string $density): array
    {
        $tileKm = self::TILE_SIZE_KM[$density] ?? self::TILE_SIZE_KM['mixed'];

        $latMid    = ($latMin + $latMax) / 2.0;
        $kmPerLat  = 111.32;
        $kmPerLng  = 111.32 * max(0.000001, cos(deg2rad($latMid)));
        $stepLat   = $tileKm / $kmPerLat;
        $stepLng   = $tileKm / $kmPerLng;

        $latCells  = max(1, (int) ceil(($latMax - $latMin) / $stepLat));
        $lngCells  = max(1, (int) ceil(($lngMax - $lngMin) / $stepLng));

        $tiles = [];
        for ($i = 0; $i < $latCells; $i++) {
            $lo = $latMin + $i * $stepLat;
            $hi = min($latMax, $lo + $stepLat);
            if ($hi <= $lo) continue;
            for ($j = 0; $j < $lngCells; $j++) {
                $wlo = $lngMin + $j * $stepLng;
                $whi = min($lngMax, $wlo + $stepLng);
                if ($whi <= $wlo) continue;
                $tiles[] = [$lo, $wlo, $hi, $whi];
            }
        }
        return $tiles;
    }

    /**
     * Worker uses this to insert four child tiles when a parent tile
     * saturated (full 60-result page hit). Returns the new tile ids.
     * Spec §4.1 auto-subdivision.
     */
    public function subdivideTile(string $tileId): array
    {
        $t = $this->db->fetch('SELECT * FROM seed_tiles WHERE id=?', [$tileId]);
        if (!$t) return [];
        $latMid = ((float) $t['lat_min'] + (float) $t['lat_max']) / 2.0;
        $lngMid = ((float) $t['lng_min'] + (float) $t['lng_max']) / 2.0;
        $children = [
            [(float) $t['lat_min'], (float) $t['lng_min'], $latMid, $lngMid],
            [(float) $t['lat_min'], $lngMid,                $latMid, (float) $t['lng_max']],
            [$latMid,               (float) $t['lng_min'], (float) $t['lat_max'], $lngMid],
            [$latMid,               $lngMid,                (float) $t['lat_max'], (float) $t['lng_max']],
        ];
        $ids = [];
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO seed_tiles
               (id, campaign_id, lat_min, lng_min, lat_max, lng_max, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'queued', NOW())"
        );
        foreach ($children as $c) {
            $cid = Database::uuid();
            $stmt->execute([$cid, $t['campaign_id'], $c[0], $c[1], $c[2], $c[3]]);
            $ids[] = $cid;
        }
        return $ids;
    }

    // ─────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────

    public static function currentMonthlyVolume(Database $db): array
    {
        try {
            $row = $db->fetch(
                "SELECT
                   COALESCE(SUM(CASE WHEN sku IN ('places_nearby_pro','places_text_pro') THEN billable_units ELSE 0 END), 0) AS search_units,
                   COALESCE(SUM(CASE WHEN sku IN ('place_details_pro','place_details_contact','place_details_atmosphere') THEN billable_units ELSE 0 END), 0) AS details_units
                 FROM api_cost_events
                 WHERE called_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
            );
            return [
                'search'  => (int) ($row['search_units']  ?? 0),
                'details' => (int) ($row['details_units'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['search' => 0, 'details' => 0];
        }
    }
}
