<?php
/**
 * Google Places (New) SKU price book — Carafe Vendor Network Spec v3 §1, §5.1.
 *
 * Editable per-SKU rate map for the seeding pipeline. Distinct from
 * App\Services\GooglePricing::COSTS, which is a flat per-call estimate
 * used by the live UI cost toasts; that one undercounts past the free
 * tier and ignores Places (New) SKU stacking. This file models:
 *
 *   - tiered pricing (0-100K, 100K-500K, 500K+ monthly volume)
 *   - the SKU stack on Place Details (Pro + Contact + Atmosphere)
 *   - which fields in a Places field-mask trigger each SKU
 *
 * Rates accessed May 2026. Update when Google's pricing page changes;
 * `field_triggers` is the auditable map for the Field Mask -> SKU set
 * derivation in PlacesClient.
 *
 * Returned as a plain array so it's diffable and grep-able; PlacesClient
 * reads it through `require` and never mutates it.
 */

return [
    /*
     * Tier breakpoints for monthly billable volume per SKU family.
     * Rate selection: walk the tiers in order; the first one whose
     * `up_to_units` is >= the running monthly counter wins.
     */
    'tiers' => [
        'search' => [
            // Place Search (Nearby + Text) Pro
            ['up_to_units' => 100_000, 'rate_per_1k_usd' => 32.00],
            ['up_to_units' => 500_000, 'rate_per_1k_usd' => 25.60],
            ['up_to_units' => null,    'rate_per_1k_usd' => 19.20],
        ],
        'details' => [
            // Place Details Pro (base SKU)
            ['up_to_units' => 100_000, 'rate_per_1k_usd' => 17.00],
            ['up_to_units' => 500_000, 'rate_per_1k_usd' => 13.60],
            ['up_to_units' => null,    'rate_per_1k_usd' => 10.20],
        ],
    ],

    /*
     * Add-on SKUs stacked onto Place Details when contact / atmosphere
     * fields are requested. Flat-rate per Google's pricing page; no
     * tiering bands published.
     */
    'addons' => [
        'place_details_contact'    => 3.00, // +$3 / 1k
        'place_details_atmosphere' => 5.00, // +$5 / 1k
        'place_photo'              => 7.00, // $7 / 1k
    ],

    /*
     * Monthly free-tier credits per SKU family. Estimator subtracts
     * `free_tier_remaining` from projected calls before pricing.
     * PlacesClient surfaces the running burn-down to the run dashboard.
     */
    'free_tier_monthly' => [
        'places_nearby_pro'        => 5_000,
        'places_text_pro'          => 5_000,
        'place_details_pro'        => 5_000,
        'place_details_contact'    => 1_000,
        'place_details_atmosphere' => 1_000,
        'place_photo'              => 1_000,
    ],

    /*
     * Which Place Details field-mask tokens trigger which billable SKUs.
     *
     * The base 'place_details_pro' SKU is always billed when calling
     * /v1/places/{id} regardless of mask. The add-on SKUs only bill when
     * the mask contains at least one trigger token from the corresponding
     * list. These lists drive PlacesClient::skusForDetailsMask().
     */
    'field_triggers' => [
        'place_details_contact' => [
            'nationalPhoneNumber',
            'internationalPhoneNumber',
            'websiteUri',
        ],
        'place_details_atmosphere' => [
            'rating',
            'userRatingCount',
            'priceLevel',
            'priceRange',
            'regularOpeningHours',
            'currentOpeningHours',
            'secondaryOpeningHours',
            'reviews',
            'editorialSummary',
        ],
    ],

    /*
     * Canonical field-mask presets per pass / tier (§1 sweep+enrich,
     * §12.1 three-tier refresh). PlacesClient::maskFor($pass) returns
     * the comma-joined string for the X-Goog-FieldMask header.
     *
     * sweep   — cheap discovery, identity only, single SKU (Search Pro)
     * enrich_full   — first-time full detail pull; Pro + Contact + Atmosphere
     * tier_cold     — identity & location refresh; Pro only
     * tier_warm     — contact + hours refresh; Pro + Contact
     * tier_hot      — rating/status refresh; Pro + Atmosphere
     */
    'masks' => [
        'sweep' => [
            'places.id',
            'places.displayName',
            'places.location',
            'places.primaryType',
            'places.formattedAddress',
            'places.types',
        ],
        'sweep_text' => [
            'places.id',
            'places.displayName',
            'places.location',
            'places.primaryType',
            'places.formattedAddress',
            'places.types',
            'nextPageToken',
        ],
        // Place Details masks are unprefixed (not 'places.id' but 'id') because
        // /v1/places/{id} returns a single Place object, not a list.
        'enrich_full' => [
            'id', 'displayName', 'formattedAddress', 'addressComponents',
            'shortFormattedAddress', 'postalAddress', 'plusCode', 'viewport',
            'location', 'googleMapsUri', 'utcOffsetMinutes',
            'primaryType', 'primaryTypeDisplayName', 'types', 'businessStatus',
            'nationalPhoneNumber', 'internationalPhoneNumber', 'websiteUri',
            'rating', 'userRatingCount', 'priceLevel', 'priceRange',
            'regularOpeningHours', 'currentOpeningHours', 'secondaryOpeningHours',
            'reviews', 'photos', 'editorialSummary',
            'delivery', 'takeout', 'curbsidePickup', 'dineIn',
            'paymentOptions', 'parkingOptions', 'accessibilityOptions',
        ],
        'tier_cold' => [
            'id', 'displayName', 'location', 'formattedAddress', 'addressComponents',
            'primaryType', 'types', 'plusCode', 'viewport',
        ],
        // tier_warm follows spec §12.1 literally (hours + phone + website +
        // attributes on a 90-day TTL). Note this stacks Pro + Contact +
        // Atmosphere SKUs at refresh time because hours fields bill
        // Atmosphere. If the cost becomes an issue, the optimization is
        // to split warm into warm_contact (phone/website only — Pro+Contact)
        // and warm_hours (hours/attributes — Pro+Atmosphere) with the
        // same 90-day TTL on each, refreshed independently.
        'tier_warm' => [
            'id', 'nationalPhoneNumber', 'internationalPhoneNumber', 'websiteUri',
            'regularOpeningHours', 'currentOpeningHours', 'secondaryOpeningHours',
            'utcOffsetMinutes', 'paymentOptions', 'parkingOptions',
            'accessibilityOptions', 'delivery', 'takeout', 'curbsidePickup', 'dineIn',
        ],
        'tier_hot' => [
            'id', 'rating', 'userRatingCount', 'businessStatus', 'priceLevel', 'priceRange',
        ],
    ],
];
