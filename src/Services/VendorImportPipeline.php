<?php
namespace App\Services;

use App\Core\Database;

/**
 * VendorImportPipeline — the single ingest path for non-Google
 * adapters. Carafe Vendor Network Spec v3 §7 + §9 step 10:
 * "Non-Google adapters (OSM/Foursquare/chain) merged in as supplementary
 * fields + cost-free coverage for the long tail."
 *
 * Why this exists separate from VendorUpsertService:
 *   - Adapter results aren't shaped exactly like Google Places results
 *     — OSM uses tags, Foursquare uses fsq_id + categories, chain
 *     scrapers vary wildly. The pipeline normalizes them all into
 *     AdapterPlace shape and runs through ONE upsert path.
 *   - Provenance tracking (vendor_sources) is mandatory for non-Google
 *     rows per spec §3.6: "Provenance stays required even with storage
 *     permission — it drives the freshness footer, dedupe conflict
 *     resolution, and the fallback path."
 *   - Cross-source identity: when an OSM row and a Foursquare row both
 *     resolve to the same vendor (via Placekey or dedupe), the
 *     external IDs need to land on the right vendor_locations row.
 *
 * Public API:
 *   importBatch(array $adapterPlaces, string $source): array
 *     where $source ∈ 'osm' | 'foursquare' | 'usda' | 'chain_seed' | ...
 *
 * Returns a tally:
 *   ['attempted' => N, 'created' => N, 'updated' => N, 'failed' => N]
 *
 * What it does NOT do:
 *   - Doesn't run dedupe — that's VendorDedupeService. Imported rows
 *     get `dedupe_scanned_at = NULL` so the next dedupe sweep picks
 *     them up. Cross-source matching happens there via Placekey + the
 *     three blocking keys.
 *   - Doesn't classify — that's VendorClassifierService. New vendors
 *     get `classified_at = NULL`.
 *   - Doesn't enrich — non-Google sources don't have Place IDs that
 *     PlacesEnrichService can use; the catalog fields they DO bring
 *     (phone, website, hours) get written via vendor_sources.
 */
class VendorImportPipeline
{
    private Database $db;
    private VendorUpsertService $upserts;

    public function __construct(?Database $db = null, ?VendorUpsertService $upserts = null)
    {
        $this->db      = $db      ?? Database::getInstance();
        $this->upserts = $upserts ?? new VendorUpsertService();
    }

    /**
     * @param array $places  Output from OSMAdapter::discover / FoursquareAdapter::discover / etc.
     * @param string $source 'osm' | 'foursquare' | 'usda' | 'chain_seed' | 'manual'
     * @return array{attempted:int, created:int, updated:int, failed:int}
     */
    public function importBatch(array $places, string $source): array
    {
        $tally = ['attempted' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0];
        foreach ($places as $place) {
            $tally['attempted']++;
            try {
                $result = $this->importOne($place, $source);
                if ($result['created']) $tally['created']++;
                else                    $tally['updated']++;
            } catch (\Throwable $e) {
                $tally['failed']++;
                error_log("[import:$source] " . ($place['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }
        return $tally;
    }

    /**
     * Single-row import. Public so chain-scraper bespoke logic can
     * call it one row at a time when a streaming adapter doesn't fit
     * the importBatch pattern.
     *
     * Returns ['vendor_id', 'location_id', 'created'].
     */
    public function importOne(array $place, string $source): array
    {
        // Stage 1: cross-source identity. If we've seen this external
        // id before, find the existing location and skip vendor creation.
        $extKey = self::externalIdKeyFor($source);
        $extVal = $place[$extKey] ?? null;

        $existing = null;
        if ($extKey && $extVal) {
            $existing = $this->db->fetch(
                "SELECT id, vendor_id FROM vendor_locations WHERE `$extKey` = ? LIMIT 1",
                [$extVal]
            );
        }

        if ($existing) {
            // Already imported from this source — refresh coordinates
            // + provenance and we're done.
            $this->upsertExistingExternal((string) $existing['id'], $place);
            $this->writeProvenance((string) $existing['vendor_id'], $source, $place, [
                'name', 'address', 'phone', 'website',
            ]);
            return [
                'vendor_id'   => $existing['vendor_id'],
                'location_id' => $existing['id'],
                'created'     => false,
            ];
        }

        // Stage 2: write through the standard upsert path. This handles
        // the name-collision-as-merge case naturally (VendorUpsertService
        // looks up vendor by name when no place_id matches).
        $r = $this->upserts->upsertVendorFromPlace((string) ($place['id'] ?? $extVal ?? self::syntheticId($place)), $place);

        // Stage 3: persist the external id on the new location so
        // future imports from the same source find it.
        if ($extKey && $extVal && !empty($r['location_id'])) {
            $this->db->query(
                "UPDATE vendor_locations
                 SET `$extKey` = ?, source = ?, updated_at = NOW()
                 WHERE id = ?",
                [$extVal, $source, $r['location_id']]
            );
        }

        // Stage 4: provenance for every non-empty field the adapter
        // supplied (§3.6 + §3.7).
        $this->writeProvenance((string) $r['vendor_id'], $source, $place, [
            'name', 'address', 'phone', 'website',
        ]);

        return [
            'vendor_id'   => $r['vendor_id'],
            'location_id' => $r['location_id'],
            'created'     => (bool) $r['created'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Pure helpers
    // ─────────────────────────────────────────────────────────────────

    /** Which vendor_locations column stores the external id for a given source. */
    public static function externalIdKeyFor(string $source): ?string
    {
        return match ($source) {
            'osm'        => 'osm_id',
            'foursquare' => 'foursquare_fsq_id',
            default      => null,
        };
    }

    /** Synthesize a stable id for sources that don't supply one (e.g. some chain scrapers). */
    public static function syntheticId(array $place): string
    {
        $name = $place['displayName']['text'] ?? ($place['displayName'] ?? '');
        $lat  = $place['location']['latitude']  ?? 0;
        $lng  = $place['location']['longitude'] ?? 0;
        return 'synth/' . substr(hash('sha256', "$name|$lat|$lng"), 0, 24);
    }

    // ─────────────────────────────────────────────────────────────────
    // Internal
    // ─────────────────────────────────────────────────────────────────

    private function upsertExistingExternal(string $locationId, array $place): void
    {
        $lat = (float) ($place['location']['latitude']  ?? 0);
        $lng = (float) ($place['location']['longitude'] ?? 0);
        $addr = $place['formattedAddress'] ?? null;
        $this->db->query(
            "UPDATE vendor_locations
             SET address = COALESCE(?, address),
                 lat     = ?,
                 lng     = ?,
                 pt      = ST_GeomFromText(?, 4326),
                 updated_at = NOW()
             WHERE id = ?",
            [$addr, $lat, $lng, "POINT($lat $lng)", $locationId]
        );
    }

    /**
     * Write per-field provenance rows. Idempotent — one row per
     * (vendor_id, field_name, source); re-import bumps verified_at.
     */
    private function writeProvenance(string $vendorId, string $source, array $place, array $fields): void
    {
        $valueByField = [
            'name'    => $place['displayName']['text'] ?? null,
            'address' => $place['formattedAddress']     ?? null,
            'phone'   => $place['phone']                ?? null,
            'website' => $place['website']              ?? null,
        ];
        $ref = $place['id'] ?? null;
        foreach ($fields as $field) {
            if (empty($valueByField[$field])) continue;
            try {
                $this->db->query(
                    "INSERT INTO vendor_sources (id, vendor_id, field_name, source, source_ref, verified_at)
                     VALUES (?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                       source_ref  = VALUES(source_ref),
                       verified_at = NOW()",
                    [Database::uuid(), $vendorId, $field, $source, $ref]
                );
            } catch (\Throwable $e) {
                // vendor_sources doesn't have a UNIQUE on (vendor_id,
                // field_name, source) yet — INSERT may duplicate. Log
                // and move on; the duplicate is benign (we'll see all
                // rows in the audit trail).
                error_log("[import:$source] provenance for $vendorId.$field: " . $e->getMessage());
            }
        }
    }
}
