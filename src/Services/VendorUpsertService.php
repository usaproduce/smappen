<?php
namespace App\Services;

use App\Core\Database;

/**
 * VendorUpsertService — idempotent writes for the seeding pipeline.
 * Carafe Vendor Network Spec v3 §12.6: "All writes are idempotent
 * upserts keyed on a natural id."
 *
 * Why this exists: the pipeline runs the same stage many times — tile
 * worker retries, nightly delta sweeps, on-demand enrich. Without
 * idempotent writes every retry risks duplicate vendor rows, duplicate
 * locations, double-counted detail refreshes. With INSERT ... ON
 * DUPLICATE KEY UPDATE keyed on `google_place_id` (and `photo_name` /
 * the natural review key), re-running any stage is free + safe + does
 * not corrupt downstream counts.
 *
 * Three public ops, each transactional:
 *
 *   - upsertVendorFromPlace($placeId, $sweepData)
 *       Sweep result → (vendor, vendor_location). Looks up an existing
 *       vendor_location.google_place_id; reuses its vendor_id if found.
 *       Otherwise creates a fresh vendor + location pair.
 *
 *   - upsertGoogleDetails($placeId, $payload, $tier)
 *       Enrich result → vendor_google_details. Single-row UPSERT on
 *       google_place_id; refreshes the right tier_fetched_at per
 *       §12.1.
 *
 *   - upsertReviewsAndPhotos($placeId, $vendorId, $reviews, $photos)
 *       Child-table upserts keyed on their respective natural ids.
 *
 * Storage gate: callers should check PlacesClient::isStorageAllowed()
 * before invoking the *Details* path. This service trusts the caller —
 * it's a low-level write layer.
 */
class VendorUpsertService
{
    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->pdo();
    }

    /**
     * Upsert from a sweep-pass Places result (cheap mask only:
     * id / displayName / location / formattedAddress / primaryType / types).
     *
     * Returns ['vendor_id' => ..., 'location_id' => ..., 'created' => bool]
     * where `created` is true if a NEW vendor row was minted on this call.
     *
     * @param array $place Places (New) result shape — `id`, `displayName.text`,
     *                     `location.latitude`, `location.longitude`,
     *                     `formattedAddress`, `primaryType`, `types`.
     */
    public function upsertVendorFromPlace(string $placeId, array $place): array
    {
        if ($placeId === '') {
            throw new \InvalidArgumentException('placeId required');
        }
        $name    = $place['displayName']['text'] ?? ($place['displayName'] ?? null);
        $lat     = $place['location']['latitude']  ?? null;
        $lng     = $place['location']['longitude'] ?? null;
        $address = $place['formattedAddress']      ?? null;
        $type    = $place['primaryType']           ?? null;

        if ($name === null || $lat === null || $lng === null) {
            throw new \InvalidArgumentException('place must include displayName + location.latitude + location.longitude');
        }

        $this->pdo->beginTransaction();
        try {
            // Existing vendor for this place_id?
            $stmt = $this->pdo->prepare(
                'SELECT id, vendor_id FROM vendor_locations WHERE google_place_id = ? LIMIT 1'
            );
            $stmt->execute([$placeId]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

            $created = false;
            if ($existing) {
                $vendorId   = $existing['vendor_id'];
                $locationId = $existing['id'];
                // Idempotent location refresh — keeps coordinates / address /
                // phone in sync if Google has updated them since the last sweep.
                $upd = $this->pdo->prepare(
                    "UPDATE vendor_locations
                     SET address = ?, lat = ?, lng = ?,
                         pt = ST_GeomFromText(?, 4326),
                         updated_at = NOW()
                     WHERE id = ?"
                );
                $upd->execute([
                    $address, $lat, $lng,
                    "POINT($lat $lng)",
                    $locationId,
                ]);
            } else {
                $vendorId   = $this->uuid();
                $locationId = $this->uuid();

                $vIns = $this->pdo->prepare(
                    "INSERT INTO vendors (id, name, primary_category, source, is_affiliated, claim_status, completeness_score, created_at, updated_at)
                     VALUES (?, ?, ?, 'public_directory', 0, 'unclaimed', 0, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE updated_at = NOW()"
                );
                // vendors.name has a UNIQUE — if a name collision happens, the
                // ON DUPLICATE branch keeps the existing row. The follow-up
                // SELECT below grabs whichever id won.
                $vIns->execute([$vendorId, $name, self::categoryFromType($type)]);

                $found = $this->pdo->prepare('SELECT id FROM vendors WHERE name = ? LIMIT 1');
                $found->execute([$name]);
                $vendorId = $found->fetchColumn() ?: $vendorId;

                $lIns = $this->pdo->prepare(
                    "INSERT INTO vendor_locations
                       (id, vendor_id, label, address, google_place_id, lat, lng, pt, is_primary, source, created_at, updated_at)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ST_GeomFromText(?, 4326), 1, 'places', NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                       address = VALUES(address),
                       lat     = VALUES(lat),
                       lng     = VALUES(lng),
                       pt      = VALUES(pt),
                       updated_at = NOW()"
                );
                $lIns->execute([
                    $locationId, $vendorId, $address, $placeId, $lat, $lng,
                    "POINT($lat $lng)",
                ]);
                $created = true;
            }

            $this->pdo->commit();
            return [
                'vendor_id'   => $vendorId,
                'location_id' => $locationId,
                'created'     => $created,
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Upsert the full Place Details payload for an existing vendor.
     * $tier is which volatility tier this fetch covered — drives which
     * `{tier}_fetched_at` gets bumped. Valid: cold | warm | hot | full.
     * 'full' bumps all three.
     */
    public function upsertGoogleDetails(string $placeId, string $vendorId, array $payload, string $tier = 'full'): void
    {
        if (!in_array($tier, ['cold', 'warm', 'hot', 'full'], true)) {
            throw new \InvalidArgumentException("unknown tier: $tier");
        }

        $row = [
            'id'                          => $this->uuid(),
            'vendor_id'                   => $vendorId,
            'google_place_id'             => $placeId,

            'national_phone'              => $payload['nationalPhoneNumber']      ?? null,
            'international_phone'         => $payload['internationalPhoneNumber'] ?? null,
            'website_uri'                 => $payload['websiteUri']               ?? null,
            'google_maps_uri'             => $payload['googleMapsUri']            ?? null,

            'rating'                      => isset($payload['rating']) ? round((float) $payload['rating'], 2) : null,
            'user_rating_count'           => $payload['userRatingCount'] ?? null,
            'price_level'                 => $payload['priceLevel']      ?? null,
            'price_range_json'            => self::jsonOrNull($payload['priceRange'] ?? null),

            'business_status'             => $payload['businessStatus']        ?? null,
            'primary_type'                => $payload['primaryType']           ?? null,
            'primary_type_display'        => $payload['primaryTypeDisplayName']['text'] ?? null,
            'types_json'                  => self::jsonOrNull($payload['types'] ?? null),

            'regular_hours_json'          => self::jsonOrNull($payload['regularOpeningHours']    ?? null),
            'current_opening_hours_json'  => self::jsonOrNull($payload['currentOpeningHours']    ?? null),
            'secondary_hours_json'        => self::jsonOrNull($payload['secondaryOpeningHours']  ?? null),
            'utc_offset_minutes'          => $payload['utcOffsetMinutes'] ?? null,

            'short_formatted_address'     => $payload['shortFormattedAddress'] ?? null,
            'address_components_json'     => self::jsonOrNull($payload['addressComponents'] ?? null),
            'postal_address_json'         => self::jsonOrNull($payload['postalAddress']     ?? null),

            'delivery'                    => self::boolOrNull($payload['delivery']       ?? null),
            'takeout'                     => self::boolOrNull($payload['takeout']        ?? null),
            'curbside_pickup'             => self::boolOrNull($payload['curbsidePickup'] ?? null),
            'dine_in'                     => self::boolOrNull($payload['dineIn']         ?? null),
            'payment_options_json'        => self::jsonOrNull($payload['paymentOptions']        ?? null),
            'parking_options_json'        => self::jsonOrNull($payload['parkingOptions']        ?? null),
            'accessibility_json'          => self::jsonOrNull($payload['accessibilityOptions']  ?? null),

            'raw_payload_json'            => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        // Tier columns are appended to the INSERT but managed in the
        // UPDATE clause separately — re-bumping `hot_fetched_at` on a hot
        // refresh must not clobber an already-fresh `warm_fetched_at`.
        $cols      = array_keys($row);
        $updateSet = [];
        foreach ($cols as $c) {
            if ($c === 'id') continue; // never overwrite the surrogate id on UPDATE
            $updateSet[] = "`$c` = VALUES(`$c`)";
        }
        // Tier timestamps.
        $now = date('Y-m-d H:i:s');
        $tierUpdates = [];
        $tierInserts = ['cold_fetched_at' => null, 'warm_fetched_at' => null, 'hot_fetched_at' => null];
        $tiersToBump = $tier === 'full' ? ['cold', 'warm', 'hot'] : [$tier];
        foreach ($tiersToBump as $t) {
            $tierInserts["{$t}_fetched_at"] = $now;
            $tierUpdates[] = "`{$t}_fetched_at` = ?";
        }

        $allCols  = array_merge($cols, array_keys($tierInserts));
        $allVals  = array_merge(array_values($row), array_values($tierInserts));
        $allColList = '`' . implode('`, `', $allCols) . '`';
        $allValList = implode(', ', array_fill(0, count($allCols), '?'));

        $sql = "INSERT INTO vendor_google_details ($allColList) VALUES ($allValList)
                ON DUPLICATE KEY UPDATE " . implode(', ', $updateSet);
        if (!empty($tierUpdates)) {
            $sql .= ', ' . implode(', ', $tierUpdates);
        }

        $bindParams = $allVals;
        foreach ($tiersToBump as $_) {
            $bindParams[] = $now;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindParams);
    }

    /**
     * Upsert child-table review rows. Idempotent via the UNIQUE
     * (google_place_id, author_uri, publish_time) — the same review
     * pulled twice never doubles.
     */
    public function upsertReviews(string $placeId, string $vendorId, array $reviews): int
    {
        if (empty($reviews)) return 0;
        $stmt = $this->pdo->prepare(
            'INSERT INTO vendor_google_reviews
               (id, vendor_id, google_place_id, author_name, author_uri, author_photo_uri,
                rating, text_body, language_code, relative_time_desc, publish_time, fetched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               rating = VALUES(rating),
               text_body = VALUES(text_body),
               language_code = VALUES(language_code),
               relative_time_desc = VALUES(relative_time_desc),
               author_photo_uri = VALUES(author_photo_uri),
               fetched_at = NOW()'
        );
        $n = 0;
        foreach ($reviews as $r) {
            $publishTime = isset($r['publishTime']) ? date('Y-m-d H:i:s', strtotime($r['publishTime'])) : null;
            $stmt->execute([
                $this->uuid(),
                $vendorId,
                $placeId,
                $r['authorAttribution']['displayName'] ?? null,
                $r['authorAttribution']['uri']         ?? null,
                $r['authorAttribution']['photoUri']    ?? null,
                $r['rating']            ?? null,
                $r['text']['text']      ?? null,
                $r['text']['languageCode'] ?? ($r['originalText']['languageCode'] ?? null),
                $r['relativePublishTimeDescription'] ?? null,
                $publishTime,
            ]);
            $n++;
        }
        return $n;
    }

    /** Idempotent via UNIQUE(photo_name). */
    public function upsertPhotos(string $placeId, string $vendorId, array $photos): int
    {
        if (empty($photos)) return 0;
        $stmt = $this->pdo->prepare(
            'INSERT INTO vendor_google_photos
               (id, vendor_id, google_place_id, photo_name, width_px, height_px, author_attributions_json, fetched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               width_px  = VALUES(width_px),
               height_px = VALUES(height_px),
               author_attributions_json = VALUES(author_attributions_json),
               fetched_at = NOW()'
        );
        $n = 0;
        foreach ($photos as $p) {
            if (empty($p['name'])) continue;
            $stmt->execute([
                $this->uuid(),
                $vendorId,
                $placeId,
                $p['name'],
                $p['widthPx']  ?? null,
                $p['heightPx'] ?? null,
                isset($p['authorAttributions']) ? json_encode($p['authorAttributions']) : null,
            ]);
            $n++;
        }
        return $n;
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Map a Google primaryType to a coarse Carafe category. Best-effort
     * heuristic — the dedupe-and-classify stage in later phases refines
     * this. Returns 'specialty' as a safe fallback.
     */
    public static function categoryFromType(?string $primaryType): string
    {
        if (!$primaryType) return 'specialty';
        $t = strtolower($primaryType);
        return match (true) {
            str_contains($t, 'wholesale'),
            str_contains($t, 'food_distributor'),
            str_contains($t, 'warehouse'),
            str_contains($t, 'depot')                  => 'broadline',
            str_contains($t, 'produce')                => 'produce',
            str_contains($t, 'butcher'), str_contains($t, 'meat') => 'protein',
            str_contains($t, 'seafood'), str_contains($t, 'fish') => 'seafood',
            str_contains($t, 'bakery'), str_contains($t, 'dairy') => 'specialty',
            str_contains($t, 'grocery'), str_contains($t, 'supermarket'), str_contains($t, 'market') => 'specialty',
            default                                                 => 'specialty',
        };
    }

    private static function jsonOrNull($v): ?string
    {
        if ($v === null) return null;
        if (is_string($v)) return $v;
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function boolOrNull($v): ?int
    {
        if ($v === null) return null;
        return $v ? 1 : 0;
    }

    private function uuid(): string
    {
        return Database::uuid();
    }
}
