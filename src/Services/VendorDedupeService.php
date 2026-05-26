<?php
namespace App\Services;

use App\Core\Database;

/**
 * VendorDedupeService — block → match → cluster. Spec v3 §12.2 + §4.3.
 *
 * The dedupe pipeline takes the raw sweep output (lots of rough
 * duplicates from overlapping searches + different sources) and
 * collapses them to one canonical vendor per real-world place. Naive
 * O(n²) comparison doesn't scale past 10k vendors — the three-stage
 * block→match→cluster brings it to O(n × block_size).
 *
 *   Block — generate candidate pairs from the union of three keys:
 *     1. (zip5, first_3_normalized_name_chars)
 *     2. (state, soundex(name))
 *     3. geohash-6 cell (~1.2km — catches same-place-different-spelling)
 *   A vendor compares only against others sharing ≥1 block key.
 *
 *   Match — score each candidate pair on:
 *     - Jaro-Winkler(name)
 *     - exact zip
 *     - haversine distance bucket
 *     - normalized phone exact
 *     - shared name-token count (also used as the auto-merge override)
 *   Decision bands:
 *     score >= 0.85  → auto_merge
 *     score >= 0.60  → review queue (human)
 *     score <  0.60  → reject
 *   Override: distance ≤ 100m AND shared_tokens >= 2 → auto_merge
 *     regardless of score (spec §4.3 + §12.2).
 *
 *   Cluster — connected components via union-find. A=B + B=C means
 *     all three merge. The survivor (chosen as oldest created_at)
 *     keeps every merged row's google_place_id + provenance.
 *
 * Placekey shortcut (§12.2): if both sides have a placekey and they
 * match, the pair is auto_merge with score=1.0 and no scoring math.
 *
 * Phase 5 ships: block-key assignment + candidate enumeration + scoring
 * + persistence to vendor_dedupe_pairs + clustering on auto_merge pairs
 * + the apply-merge action. The review-queue UI is a later phase.
 */
class VendorDedupeService
{
    public const SCORE_AUTO_MERGE = 0.85;
    public const SCORE_REVIEW     = 0.60;

    /** Auto-merge override: distance ≤ 100m AND >= 2 shared name tokens. */
    public const OVERRIDE_DISTANCE_M = 100;
    public const OVERRIDE_SHARED_TOKENS = 2;

    private const STOP_TOKENS = [
        'the','a','an','of','and','&','co','company','corp','inc','llc','ltd',
        'wholesale','wholesalers','wholesaler','distributor','distributors',
        'foods','food','market','markets','produce','company',
    ];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    // ─────────────────────────────────────────────────────────────────
    // Persistence: assign block keys + run the pipeline
    // ─────────────────────────────────────────────────────────────────

    /**
     * Compute + persist block keys for one vendor_locations row.
     * Idempotent — re-running just overwrites the keys.
     *
     * Pass null for $address when it isn't known; zip5 / state_code will
     * stay null but the geohash + name-prefix + soundex still get
     * derived from the inputs available.
     */
    public function assignBlockKeys(string $locationId, string $name, ?string $address, float $lat, float $lng, ?string $placekey = null): void
    {
        $keys = self::blockKeysFor($name, $address, $lat, $lng, $placekey);
        $this->db->query(
            "UPDATE vendor_locations
             SET zip5         = ?,
                 state_code   = ?,
                 name_soundex = ?,
                 name_prefix3 = ?,
                 geohash6     = ?,
                 placekey     = COALESCE(?, placekey),
                 updated_at   = NOW()
             WHERE id = ?",
            [
                $keys['zip5'],
                $keys['state_code'],
                $keys['name_soundex'],
                $keys['name_prefix3'],
                $keys['geohash6'],
                $keys['placekey'],
                $locationId,
            ]
        );
    }

    /**
     * Pure derivation of all block keys + placekey passthrough.
     * Returns the column->value map suitable for direct INSERT/UPDATE.
     */
    public static function blockKeysFor(string $name, ?string $address, float $lat, float $lng, ?string $placekey = null): array
    {
        $normName  = self::normalizeName($name);
        $tokens    = self::nameTokens($name);
        $firstTok  = $tokens[0] ?? $normName;
        $prefix3   = substr($firstTok, 0, 3) ?: null;
        $soundex   = $normName !== '' ? substr(soundex($normName), 0, 4) : null;
        $zip5      = self::zip5From($address);
        $state     = self::stateFrom($address);
        $geo6      = self::geohash($lat, $lng, 6);
        return [
            'zip5'         => $zip5,
            'state_code'   => $state,
            'name_soundex' => $soundex ?: null,
            'name_prefix3' => $prefix3 ?: null,
            'geohash6'     => $geo6 ?: null,
            'placekey'     => $placekey ?: null,
        ];
    }

    /**
     * Run dedupe on every vendor_locations row whose dedupe_scanned_at
     * is NULL (i.e. new since the last sweep). Returns counts of how
     * many pairs landed in each decision bucket. Idempotent on re-run.
     */
    public function dedupeNewLocations(int $batchSize = 5000): array
    {
        $rows = $this->loadUnscannedLocations($batchSize);
        if (empty($rows)) {
            return ['scanned' => 0, 'auto_merge' => 0, 'review' => 0, 'reject' => 0];
        }
        // Ensure every row has block keys — first-run rows may not yet.
        foreach ($rows as $r) {
            if (empty($r['geohash6']) && empty($r['name_soundex'])) {
                $this->assignBlockKeys(
                    $r['id'],
                    (string) ($r['name'] ?? ''),
                    $r['address'] ?? null,
                    (float) $r['lat'],
                    (float) $r['lng'],
                    $r['placekey'] ?? null
                );
            }
        }
        // Reload with block keys + the joined vendor.name we need for scoring.
        $ids  = array_column($rows, 'id');
        $rows = $this->loadLocationsForScoring($ids);

        $candidates = $this->enumerateCandidatePairs($rows);

        $counts = ['scanned' => count($rows), 'auto_merge' => 0, 'review' => 0, 'reject' => 0];
        foreach ($candidates as $pair) {
            [$a, $b, $blockKey] = $pair;
            $decision = $this->scoreAndPersist($a, $b, $blockKey);
            if ($decision !== null) $counts[$decision]++;
        }

        // Mark rows scanned so the next pass picks up only newly-arrived ones.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->query(
            "UPDATE vendor_locations SET dedupe_scanned_at = NOW() WHERE id IN ($placeholders)",
            $ids
        );
        return $counts;
    }

    /**
     * Apply every auto_merge pair that hasn't yet been applied.
     * Returns the number of mergers performed. Uses union-find over
     * the auto_merge pairs so a chain A↔B + B↔C produces a single
     * 3-vendor merger, not two pairwise merges that could choose
     * different survivors.
     */
    public function applyPendingAutoMerges(): int
    {
        $pairs = $this->db->fetchAll(
            "SELECT id, left_vendor_id, right_vendor_id
             FROM vendor_dedupe_pairs
             WHERE decision = 'auto_merge' AND applied_merge_at IS NULL"
        );
        if (empty($pairs)) return 0;

        $components = self::clusters(array_map(
            fn ($p) => [$p['left_vendor_id'], $p['right_vendor_id']],
            $pairs
        ));
        $mergedCount = 0;
        foreach ($components as $vendorIds) {
            if (count($vendorIds) < 2) continue;
            $survivor = $this->mergeCluster($vendorIds);
            $mergedCount += count($vendorIds) - 1;
            // Mark all touching pairs applied.
            $ph = implode(',', array_fill(0, count($vendorIds), '?'));
            $this->db->query(
                "UPDATE vendor_dedupe_pairs
                 SET applied_merge_at = NOW()
                 WHERE applied_merge_at IS NULL
                   AND decision = 'auto_merge'
                   AND (left_vendor_id IN ($ph) OR right_vendor_id IN ($ph))",
                array_merge($vendorIds, $vendorIds)
            );
        }
        return $mergedCount;
    }

    // ─────────────────────────────────────────────────────────────────
    // Pure helpers
    // ─────────────────────────────────────────────────────────────────

    public static function normalizeName(string $name): string
    {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? '';
        $s = preg_replace('/\s+/', ' ', $s) ?? '';
        return trim($s);
    }

    /** Tokenize a name into significant words (drops stop tokens). */
    public static function nameTokens(string $name): array
    {
        $norm   = self::normalizeName($name);
        if ($norm === '') return [];
        $parts  = explode(' ', $norm);
        $tokens = [];
        foreach ($parts as $p) {
            if ($p === '' || in_array($p, self::STOP_TOKENS, true)) continue;
            $tokens[] = $p;
        }
        return $tokens;
    }

    public static function zip5From(?string $address): ?string
    {
        if (!$address) return null;
        if (preg_match('/\b(\d{5})(?:-\d{4})?\b/', $address, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Best-effort 2-letter state code from a US-shaped formatted address. */
    public static function stateFrom(?string $address): ?string
    {
        if (!$address) return null;
        // "..., VA 22030, USA" or "..., Virginia 22030, USA"
        if (preg_match('/,\s*([A-Z]{2})\s+\d{5}/', $address, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Jaro-Winkler similarity in [0,1]. Pure. Standard prefix-bonus
     * variant with p=0.1 and l capped at 4.
     */
    public static function jaroWinkler(string $a, string $b): float
    {
        $a = (string) $a;
        $b = (string) $b;
        if ($a === '' && $b === '') return 1.0;
        if ($a === '' || $b === '')  return 0.0;
        if ($a === $b) return 1.0;

        $aLen = mb_strlen($a);
        $bLen = mb_strlen($b);
        $matchDist = max(0, intdiv(max($aLen, $bLen), 2) - 1);

        $aMatched = array_fill(0, $aLen, false);
        $bMatched = array_fill(0, $bLen, false);
        $matches  = 0;
        for ($i = 0; $i < $aLen; $i++) {
            $lo = max(0, $i - $matchDist);
            $hi = min($bLen - 1, $i + $matchDist);
            for ($j = $lo; $j <= $hi; $j++) {
                if ($bMatched[$j]) continue;
                if (mb_substr($a, $i, 1) !== mb_substr($b, $j, 1)) continue;
                $aMatched[$i] = true;
                $bMatched[$j] = true;
                $matches++;
                break;
            }
        }
        if ($matches === 0) return 0.0;

        // Count transpositions.
        $k = 0;
        $transpositions = 0;
        for ($i = 0; $i < $aLen; $i++) {
            if (!$aMatched[$i]) continue;
            while (!$bMatched[$k]) $k++;
            if (mb_substr($a, $i, 1) !== mb_substr($b, $k, 1)) $transpositions++;
            $k++;
        }
        $t = $transpositions / 2.0;
        $jaro = ($matches / $aLen + $matches / $bLen + ($matches - $t) / $matches) / 3.0;

        // Winkler prefix bonus.
        $prefix = 0;
        $lMax   = min(4, $aLen, $bLen);
        for ($i = 0; $i < $lMax; $i++) {
            if (mb_substr($a, $i, 1) === mb_substr($b, $i, 1)) $prefix++;
            else break;
        }
        return $jaro + $prefix * 0.1 * (1.0 - $jaro);
    }

    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R     = 6371000.0;
        $phi1  = deg2rad($lat1);
        $phi2  = deg2rad($lat2);
        $dPhi  = deg2rad($lat2 - $lat1);
        $dLam  = deg2rad($lng2 - $lng1);
        $a     = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLam / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Base32 geohash to $precision chars. Standard algorithm (no
     * library dependency). At precision 6, a cell is ~1.2km × 600m at
     * the equator (smaller at high latitude).
     */
    public static function geohash(float $lat, float $lng, int $precision = 6): string
    {
        static $BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';
        $latRange = [-90.0, 90.0];
        $lngRange = [-180.0, 180.0];
        $bits = 0;
        $bit  = 0;
        $even = true;
        $hash = '';
        while (strlen($hash) < $precision) {
            if ($even) {
                $mid = ($lngRange[0] + $lngRange[1]) / 2.0;
                if ($lng > $mid) { $bits = ($bits << 1) | 1; $lngRange[0] = $mid; }
                else             { $bits = $bits << 1;       $lngRange[1] = $mid; }
            } else {
                $mid = ($latRange[0] + $latRange[1]) / 2.0;
                if ($lat > $mid) { $bits = ($bits << 1) | 1; $latRange[0] = $mid; }
                else             { $bits = $bits << 1;       $latRange[1] = $mid; }
            }
            $even = !$even;
            $bit++;
            if ($bit === 5) {
                $hash .= $BASE32[$bits];
                $bits = 0;
                $bit  = 0;
            }
        }
        return $hash;
    }

    /**
     * Score a candidate pair. Returns:
     *   ['score' => float [0,1], 'distance_m' => float, 'shared_tokens' => int,
     *    'decision' => 'auto_merge'|'review'|'reject']
     *
     * Weights:
     *   - 0.40 × jaro_winkler(name)
     *   - 0.20 × exact zip (1 / 0)
     *   - 0.20 × distance bucket (≤50m=1.0, ≤500m=0.5, else 0)
     *   - 0.10 × phone exact (1 / 0)
     *   - 0.10 × normalized levenshtein(street1 vs street2)
     *
     * Override: if distance ≤ 100m AND shared_tokens ≥ 2, decision is
     * forced to auto_merge regardless of weighted score — spec §4.3.
     */
    public static function score(array $a, array $b): array
    {
        $nameA = (string) ($a['name'] ?? '');
        $nameB = (string) ($b['name'] ?? '');
        $nameSim = self::jaroWinkler(self::normalizeName($nameA), self::normalizeName($nameB));

        $zipExact = (!empty($a['zip5']) && !empty($b['zip5']) && $a['zip5'] === $b['zip5']) ? 1 : 0;

        $dist = self::haversineMeters(
            (float) $a['lat'], (float) $a['lng'],
            (float) $b['lat'], (float) $b['lng']
        );
        $distBucket = $dist <= 50 ? 1.0 : ($dist <= 500 ? 0.5 : 0.0);

        $phoneExact = (self::normalizePhone($a['phone'] ?? null)
                    === self::normalizePhone($b['phone'] ?? null)
                    && !empty($a['phone'])) ? 1 : 0;

        $streetA = self::streetOnly($a['address'] ?? '');
        $streetB = self::streetOnly($b['address'] ?? '');
        $streetSim = self::levenshteinNormalized($streetA, $streetB);

        $score = 0.40 * $nameSim
               + 0.20 * $zipExact
               + 0.20 * $distBucket
               + 0.10 * $phoneExact
               + 0.10 * $streetSim;

        // Token overlap (for the override + the persisted column).
        $tokensA = self::nameTokens($nameA);
        $tokensB = self::nameTokens($nameB);
        $shared  = count(array_intersect($tokensA, $tokensB));

        $decision = $score >= self::SCORE_AUTO_MERGE ? 'auto_merge'
                  : ($score >= self::SCORE_REVIEW   ? 'review'     : 'reject');
        if ($dist <= self::OVERRIDE_DISTANCE_M && $shared >= self::OVERRIDE_SHARED_TOKENS) {
            $decision = 'auto_merge';
        }
        return [
            'score'         => round($score, 4),
            'distance_m'    => round($dist,  2),
            'shared_tokens' => $shared,
            'decision'      => $decision,
        ];
    }

    /**
     * Connected components via union-find. Input is an array of
     * 2-element arrays [id_a, id_b]; output is an array of clusters,
     * each cluster a list of ids. Singletons not in any pair are omitted.
     */
    public static function clusters(array $pairs): array
    {
        $parent = [];
        $find = function (string $x) use (&$parent, &$find): string {
            if (!isset($parent[$x])) $parent[$x] = $x;
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $union = function (string $a, string $b) use (&$parent, $find): void {
            $ra = $find($a); $rb = $find($b);
            if ($ra !== $rb) $parent[$ra] = $rb;
        };
        foreach ($pairs as $p) {
            $union((string) $p[0], (string) $p[1]);
        }
        $groups = [];
        foreach (array_keys($parent) as $x) {
            $root = $find($x);
            $groups[$root][] = $x;
        }
        return array_values($groups);
    }

    // ─────────────────────────────────────────────────────────────────
    // Internal — DB helpers
    // ─────────────────────────────────────────────────────────────────

    private function loadUnscannedLocations(int $limit): array
    {
        return $this->db->fetchAll(
            "SELECT vl.id, vl.lat, vl.lng, vl.address, vl.placekey,
                    vl.geohash6, vl.name_soundex, vl.name_prefix3,
                    vl.zip5, vl.state_code, vl.phone,
                    v.name
             FROM vendor_locations vl
             JOIN vendors v ON v.id = vl.vendor_id
             WHERE vl.dedupe_scanned_at IS NULL
               AND v.merged_into IS NULL
             ORDER BY vl.created_at ASC
             LIMIT ?",
            [$limit]
        );
    }

    private function loadLocationsForScoring(array $ids): array
    {
        if (empty($ids)) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->fetchAll(
            "SELECT vl.id, vl.vendor_id, vl.lat, vl.lng, vl.address, vl.placekey,
                    vl.geohash6, vl.name_soundex, vl.name_prefix3,
                    vl.zip5, vl.state_code, vl.phone, v.name
             FROM vendor_locations vl
             JOIN vendors v ON v.id = vl.vendor_id
             WHERE vl.id IN ($ph)",
            $ids
        );
    }

    /**
     * For each row in $rows, look up its three block neighbors in the
     * full vendor_locations table and emit candidate pairs. Yields
     * canonicalized [a, b, block_key_label] triplets, deduped across
     * block families.
     */
    private function enumerateCandidatePairs(array $rows): \Generator
    {
        $seenPair = [];
        foreach ($rows as $r) {
            $rid = $r['id'];
            $rVid = $r['vendor_id'];

            // Placekey shortcut — zero-cost identity.
            if (!empty($r['placekey'])) {
                $neighbors = $this->db->fetchAll(
                    "SELECT vl.id, vl.vendor_id, vl.lat, vl.lng, vl.address, vl.placekey,
                            vl.zip5, vl.state_code, vl.phone, v.name
                     FROM vendor_locations vl JOIN vendors v ON v.id = vl.vendor_id
                     WHERE vl.placekey = ? AND vl.id != ? AND v.merged_into IS NULL",
                    [$r['placekey'], $rid]
                );
                foreach ($neighbors as $n) {
                    if ($n['vendor_id'] === $rVid) continue;
                    $key = self::pairKey($rVid, $n['vendor_id']);
                    if (isset($seenPair[$key])) continue;
                    $seenPair[$key] = true;
                    yield [$r, $n, 'placekey'];
                }
            }

            // Block 1: zip5 + name_prefix3
            if (!empty($r['zip5']) && !empty($r['name_prefix3'])) {
                $neighbors = $this->blockNeighbors(
                    'WHERE vl.zip5 = ? AND vl.name_prefix3 = ? AND vl.id != ?',
                    [$r['zip5'], $r['name_prefix3'], $rid]
                );
                foreach ($neighbors as $n) {
                    if ($n['vendor_id'] === $rVid) continue;
                    $key = self::pairKey($rVid, $n['vendor_id']);
                    if (isset($seenPair[$key])) continue;
                    $seenPair[$key] = true;
                    yield [$r, $n, 'zip5+prefix3'];
                }
            }

            // Block 2: state + soundex
            if (!empty($r['state_code']) && !empty($r['name_soundex'])) {
                $neighbors = $this->blockNeighbors(
                    'WHERE vl.state_code = ? AND vl.name_soundex = ? AND vl.id != ?',
                    [$r['state_code'], $r['name_soundex'], $rid]
                );
                foreach ($neighbors as $n) {
                    if ($n['vendor_id'] === $rVid) continue;
                    $key = self::pairKey($rVid, $n['vendor_id']);
                    if (isset($seenPair[$key])) continue;
                    $seenPair[$key] = true;
                    yield [$r, $n, 'state+soundex'];
                }
            }

            // Block 3: geohash6
            if (!empty($r['geohash6'])) {
                $neighbors = $this->blockNeighbors(
                    'WHERE vl.geohash6 = ? AND vl.id != ?',
                    [$r['geohash6'], $rid]
                );
                foreach ($neighbors as $n) {
                    if ($n['vendor_id'] === $rVid) continue;
                    $key = self::pairKey($rVid, $n['vendor_id']);
                    if (isset($seenPair[$key])) continue;
                    $seenPair[$key] = true;
                    yield [$r, $n, 'geohash6'];
                }
            }
        }
    }

    private function blockNeighbors(string $where, array $params): array
    {
        $sql = "SELECT vl.id, vl.vendor_id, vl.lat, vl.lng, vl.address, vl.placekey,
                       vl.zip5, vl.state_code, vl.phone, v.name
                FROM vendor_locations vl
                JOIN vendors v ON v.id = vl.vendor_id
                $where AND v.merged_into IS NULL
                LIMIT 200";
        return $this->db->fetchAll($sql, $params);
    }

    private function scoreAndPersist(array $a, array $b, string $blockKeyLabel): ?string
    {
        // Placekey shortcut — bypass scoring, force auto_merge.
        if (!empty($a['placekey']) && !empty($b['placekey']) && $a['placekey'] === $b['placekey']) {
            $decision = 'auto_merge';
            $score    = 1.0;
            $dist     = self::haversineMeters((float) $a['lat'], (float) $a['lng'], (float) $b['lat'], (float) $b['lng']);
            $shared   = count(array_intersect(self::nameTokens((string) $a['name']), self::nameTokens((string) $b['name'])));
        } else {
            $r = self::score($a, $b);
            $decision = $r['decision'];
            $score    = $r['score'];
            $dist     = $r['distance_m'];
            $shared   = $r['shared_tokens'];
        }

        if ($decision === 'reject') {
            // Don't pollute the table with the noise — only persist
            // decisions worth acting on. (If we ever want to audit
            // rejections this is the spot to flip on.)
            return $decision;
        }

        [$leftVid, $rightVid] = self::canonicalOrder($a['vendor_id'], $b['vendor_id']);
        [$leftLid, $rightLid] = $leftVid === $a['vendor_id']
            ? [$a['id'], $b['id']]
            : [$b['id'], $a['id']];

        try {
            $this->db->query(
                "INSERT INTO vendor_dedupe_pairs
                   (id, left_vendor_id, right_vendor_id, left_location_id, right_location_id,
                    score, distance_m, shared_name_tokens, decision, block_key_hit, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                   score = IF(VALUES(score) > score, VALUES(score), score),
                   distance_m = VALUES(distance_m),
                   shared_name_tokens = VALUES(shared_name_tokens),
                   decision = CASE
                     WHEN VALUES(decision) = 'auto_merge' THEN 'auto_merge'
                     WHEN decision = 'auto_merge'         THEN 'auto_merge'
                     ELSE VALUES(decision)
                   END,
                   block_key_hit = CONCAT_WS(',', block_key_hit, VALUES(block_key_hit))",
                [
                    Database::uuid(),
                    $leftVid, $rightVid, $leftLid, $rightLid,
                    $score, $dist, $shared, $decision, $blockKeyLabel,
                ]
            );
        } catch (\Throwable $e) {
            error_log('[dedupe] persist failed: ' . $e->getMessage());
        }
        return $decision;
    }

    /**
     * Merge a cluster of vendor ids into a single survivor. Survivor
     * = the oldest vendor by created_at. All locations / listings /
     * google_details from the others get re-pointed; the merged
     * vendors stay on disk with merged_into set (audit trail).
     */
    private function mergeCluster(array $vendorIds): string
    {
        $ph = implode(',', array_fill(0, count($vendorIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT id, created_at FROM vendors WHERE id IN ($ph) AND merged_into IS NULL ORDER BY created_at ASC",
            $vendorIds
        );
        if (count($rows) < 2) return $rows[0]['id'] ?? '';

        $survivor = $rows[0]['id'];
        $losers   = array_slice(array_column($rows, 'id'), 1);
        if (empty($losers)) return $survivor;

        $loserPh = implode(',', array_fill(0, count($losers), '?'));

        $this->db->beginTransaction();
        try {
            // Reassign child rows. Each table that joins by vendor_id
            // needs an UPDATE here; conditionally skip ones that don't
            // exist (e.g. the dedupe pairs table is mig 032 — older
            // installs may not have run it).
            foreach ([
                'vendor_locations',
                'vendor_categories',
                'vendor_sources',
                'vendor_listings',
                'vendor_coverage',
                'vendor_google_details',
                'vendor_google_reviews',
                'vendor_google_photos',
            ] as $tbl) {
                try {
                    $this->db->query(
                        "UPDATE $tbl SET vendor_id = ? WHERE vendor_id IN ($loserPh)",
                        array_merge([$survivor], $losers)
                    );
                } catch (\Throwable $_) {
                    // Table may not exist yet in older schemas — ignore.
                }
            }
            // Mark losers as merged.
            $this->db->query(
                "UPDATE vendors SET merged_into = ?, updated_at = NOW() WHERE id IN ($loserPh)",
                array_merge([$survivor], $losers)
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        return $survivor;
    }

    // ─────────────────────────────────────────────────────────────────
    // Small private statics
    // ─────────────────────────────────────────────────────────────────

    private static function pairKey(string $a, string $b): string
    {
        [$x, $y] = self::canonicalOrder($a, $b);
        return $x . '|' . $y;
    }

    private static function canonicalOrder(string $a, string $b): array
    {
        return strcmp($a, $b) <= 0 ? [$a, $b] : [$b, $a];
    }

    private static function normalizePhone(?string $phone): string
    {
        if (!$phone) return '';
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /**
     * Best-effort: return the leading street component of a formatted
     * address ("100 Main St" out of "100 Main St, City, ST 12345").
     */
    public static function streetOnly(string $address): string
    {
        $p = explode(',', $address, 2);
        return self::normalizeName($p[0] ?? '');
    }

    /** Normalized Levenshtein → similarity in [0,1]. */
    public static function levenshteinNormalized(string $a, string $b): float
    {
        if ($a === '' && $b === '') return 1.0;
        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen === 0) return 1.0;
        // PHP's built-in levenshtein only accepts strings ≤ 255 chars.
        $a = substr($a, 0, 250);
        $b = substr($b, 0, 250);
        $dist = levenshtein($a, $b);
        return max(0.0, 1.0 - $dist / $maxLen);
    }
}
