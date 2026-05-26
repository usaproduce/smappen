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

        // §2 Carafe target = B2B wholesale only. Places sweeps inevitably
        // return adjacent retail (cafés, Starbucks, Safeway, 7-Eleven) even
        // with strict includedTypes. Filter at write time so the dedupe
        // queue + downstream pipeline stays clean.
        if (self::isLikelyJunk((string) $name)) {
            return [
                'vendor_id'   => '',
                'location_id' => '',
                'created'     => false,
                'skipped'     => 'retail_or_restaurant_pattern',
            ];
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
     * Is this place name overwhelmingly likely to be retail/restaurant
     * rather than B2B wholesale? Returns true to short-circuit insert.
     *
     * Two-stage filter:
     *   1. DENY_PATTERNS — obvious retail/restaurant/cafe matches reject.
     *   2. KEEP_PATTERNS — name must contain at least one B2B marker
     *      (wholesale / distributor / depot / a food-product noun like
     *      "Poultry" or "Foods" at the end / a known brand). Otherwise
     *      reject. This is aggressive — names like "ABOVEGROUND" or
     *      "Capital Eagle Inc" get dropped because there's no signal
     *      that they're B2B food. Spec §2 target is B2B wholesale ONLY.
     *
     * If a legit B2B vendor gets rejected because its name lacks any
     * marker (rare but possible — e.g. a single-brand-name vendor),
     * add it to the brand whitelist in KEEP_PATTERNS group 3.
     */
    public static function isLikelyJunk(string $name): bool
    {
        $n = strtolower(trim($name));
        if ($n === '') return true; // unnamed = noise
        foreach (self::DENY_PATTERNS as $pat) {
            if (preg_match($pat, $n)) return true;
        }
        foreach (self::KEEP_PATTERNS as $pat) {
            if (preg_match($pat, $n)) return false; // explicit B2B signal → keep
        }
        return true; // no B2B marker found → reject
    }

    /**
     * Allow-list. ANY match → keep. Three groups:
     *   - operational B2B markers ("wholesale", "distributor", etc.)
     *   - food-product nouns at end-of-name ("Capitol Hill Poultry",
     *     "Euro Foods") — restaurants don't end this way
     *   - explicit known B2B brand prefixes
     */
    private const KEEP_PATTERNS = [
        // Operational B2B markers anywhere in the name.
        '/\b(wholesale|wholesalers?|whole\s?sales?|distributor|distributors|distribution|distributing|foodservice|food\s?service|purveyors?|importers?|importing|imports|depot|cash\s?(?:and|&)\s?carry|terminal\s?market|farmers\s?market|food\s?supply|restaurant\s?supply|smallwares)\b/iu',
        // Food-product noun at end of name, optionally followed by Inc/LLC/Corp/Co.
        // Restaurants and retail rarely end with these words (they say "Cafe", "Restaurant", "Market", etc.).
        '/\b(foods?|meats|seafoods?|produce|dairy|poultry|beverages?|bakery|fish|fishery|fisheries|provisions?|deli\s?meats|deli\s?products)\s*(?:co\.?|inc\.?|llc\.?|corp\.?|ltd\.?)?\s*$/iu',
        // Known B2B brand prefixes / contains. Add to this list when an
        // operator flags a real B2B that the other two groups missed.
        '/^(sysco|us\s?foods|pfg\b|performance\s?food|gordon\s?food|reinhart|baldor|coosemans|cuisine\s?solutions|jetro|restaurant\s?depot|chef.?s\s?warehouse|costco\s?business|a\.?\s*litteri|saval|coastal\s?sunbelt|lancaster\s?foods|pat\s?lafrieda|usa\s?produce|hunts\s?point|maine\s?avenue\s?fish|fulton\s?fish|empson|euro\s?foods|fruver)/iu',
    ];

    /**
     * Case-insensitive regex patterns. Match → reject at insert.
     *
     * Grouped by reason so the reader can see why each pattern exists.
     * Add to a group rather than appending a new line of unknown intent.
     */
    private const DENY_PATTERNS = [
        // /u flag — needed so \b works around accented chars like 'café'.
        // Restaurant / cafe / direct-to-consumer food. Plurals allowed via 's?'.
        '/(?:^|[\s\-])(cafes?|cafés?|coffee\s?shop|restaurants?|grill|diner|bistro|pizzeria|trattoria|brasserie|tavern|gastropub|brewpub|brewery|brewing\s?co|beer\s?garden|wine\s?bar|cocktail\s?bar|taproom|tea\s?house|teahouse|pastry\s?shop|ice\s?cream|gelato|smoothies?|juicery|donuts?|doughnuts?|bagel\s?shop|sandwich\s?shop|takeout|take[-\s]out|carry[-\s]?out|sushi\s?bar|hibachi|ramen|noodle\s?house|food\s?truck|food\s?court|cocktails?)\b/iu',
        '/(?:^|[\s\-])(crab|seafood)\s?(house|bar|garden|cabana|shack)\b/iu',  // "Boom Boom Crab ... Bar"
        // Major consumer-retail food chains
        '/\b(7[-\s]?eleven|safeway|aldi|wegmans|whole\s?foods|trader\s?joe|harris\s?teeter|balducci|wal[-\s]?mart|target|food\s?lion|giant\s?food|publix|kroger|albertsons|stop\s?&?\s?shop|shoprite|sam.s\s?club|bj.s\s?wholesale|dollar\s?(tree|general)|family\s?dollar|fresh\s?market|sprouts\s?farmers|h[-\s]?e[-\s]?b|meijer|piggly\s?wiggly|ingles|winn[-\s]?dixie|costco\s?wholesale|fresh\s?direct)\b/iu',
        // QSR / coffee + bakery chains
        '/\b(starbucks|dunkin|panera|chick[-\s]?fil[-\s]?a|chipotle|mcdonald|burger\s?king|wendy|taco\s?bell|subway|domino|kfc|popeyes|baskin[-\s]?robbins|jamba|tim\s?hortons|cold\s?stone|krispy\s?kreme|five\s?guys|in[-\s]?n[-\s]?out|shake\s?shack|sweetgreen|cava|pret\s?a\s?manger|le\s?pain|chopt|jersey\s?mike|jimmy\s?john|capital\s?one)\b/iu',
        // Gas + convenience + pharmacy + retail liquor
        '/\b(shell\s?(gas|station)?|exxon|mobil\s?(gas|station)?|chevron|sunoco|valero|wawa|sheetz|circle\s?k|^bp\s|^bp$|cvs|walgreens|rite\s?aid|duane\s?reade|liquor\s?store)\b/iu',
        // Non-food businesses (plurals allowed: trees, salons, etc)
        '/\b(christmas|holiday)\s?trees?\b/iu',
        '/\b(car\s?park|parking|hair\s?salons?|nail\s?salons?|gyms?|fitness|spa|spas|batter(?:y|ies)|florists?|jeweler(?:y|s)|laundry|dry\s?clean|barber|tobacco|smoke\s?shop|vape|cigars?|hardware|auto\s?parts|tire\s?shop|instruments?|nursery|nurser(?:y|ies)|copier|copy\s?center|copy\s?shop)\b/iu',
        // Personal care / beauty (catches "Bellacara", "CrystalzBeauty4YU")
        '/(?:^|\W)(beauty|cosmetics|makeup|skincare)/iu',
        // Hotels + lodging + government / military commissaries
        '/\b(hotels?|motels?|inn\b|hostel|resort|commissary|naval\s?station|air\s?force|fort\s+\w+)\b/iu',
        // Trailing "Supermarket" — almost always retail (B2B uses "Market" rarely too, but the "Super" prefix is consumer)
        '/\bsupermarket\b/iu',
        // Generic small-mart indicators (catches "DC Mini Super Market", "Discount Market", "Circle 7 Food & Grocery Market")
        '/\b(mini\s?(super\s?)?market|discount\s?market|food\s?(&|and)\s?grocery|grocery\s?market|grocery\s?shop|food\s?store)\b/iu',
        // Tobacco + crab houses + carry-outs that slipped through
        '/\b(carry\s?out|crab\s?cake|crab\s?house|tobacco\s?(&|and)\s?grocery|chicken\s?(&|and)\s?waffles|deli\b)\b/iu',
        // Catering / consulting / consultancy
        '/\b(catering|caterer|consulting|consultanc(?:y|ies)|copy)\b/iu',
        // Bottled-water / beer-wine retailers
        '/\b(beer\s?(&|and)\s?wine|wine\s?(&|and)\s?spirits|water\s?delivery)\b/iu',
    ];

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
