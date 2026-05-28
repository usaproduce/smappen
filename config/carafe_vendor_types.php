<?php
/**
 * Carafe vendor types → Google Places query map. Spec v3 §2.
 *
 * The seeding pipeline reads this to know HOW to discover each vendor
 * type from Places — some have `includedTypes` we can pass to
 * searchNearby (clean, single SKU per call), others only surface
 * through `searchText` keyword queries (also single Search SKU, but
 * locationBias rectangle).
 *
 * Each entry:
 *   - `places_types`     — list passed to searchNearby `includedTypes`.
 *                          One sweep call per type per tile.
 *   - `text_queries`     — list of search-text queries to run with
 *                          `locationBias.rectangle` = tile bbox. One
 *                          sweep call per query per tile.
 *   - `category`         — the multi-category fallback used by
 *                          VendorUpsertService when Places returns a
 *                          weak primaryType. Spec §2.
 *   - `priority_enrich`  — true means this type is part of the
 *                          `priority_types` enrich policy (§4.4).
 *                          False means it sweeps but doesn't auto-enrich.
 *
 * Adding a new vendor type: append a key here, then add it to the
 * vendor_types ENUM in migration 026 (or another idempotent ALTER).
 */

return [

    'broadline' => [
        // STRICT B2B only — `food_store` removed because it pulled in
        // 7-Eleven, Starbucks-adjacent retail. `wholesaler` is the only
        // Places (New) type that's reliably B2B.
        // Brand-name text queries (Sysco, US Foods, PFG, Gordon Food
        // Service, Reinhart) pruned — they're caught at classify time
        // via VendorClassifierService::BRAND_MAP, and their places come
        // back through the `wholesaler` includedTypes call anyway.
        'places_types'    => ['wholesaler'],
        'text_queries'    => ['foodservice distributor', 'food service distributor'],
        'category'        => 'broadline',
        'priority_enrich' => true,
    ],

    'cash_carry' => [
        // `warehouse_store` catches Restaurant Depot + Costco Business —
        // legitimate B2B targets. But it ALSO catches Sam's Club / BJ's,
        // which are consumer-wholesale. Filter is at insert (see
        // VendorUpsertService::isLikelyJunk). Brand-name text queries
        // (Restaurant Depot, Chef's Warehouse, Jetro) pruned — all three
        // come back through the warehouse_store / wholesaler includedTypes
        // calls, and BRAND_MAP catches them at classify time.
        'places_types'    => ['warehouse_store', 'wholesaler'],
        'text_queries'    => ['cash and carry foodservice'],
        'category'        => 'broadline',
        'priority_enrich' => true,
    ],

    'produce' => [
        // `farm` removed — catches U-pick / agritourism retail.
        // The text queries pull in the actual produce wholesalers.
        // 'produce wholesalers' dropped — near-duplicate of 'produce wholesale'.
        'places_types'    => ['wholesaler'],
        'text_queries'    => ['produce wholesale', 'produce distributor', 'terminal market'],
        'category'        => 'produce',
        'priority_enrich' => true,
    ],

    'meat' => [
        // `butcher_shop` kept — many catch real B2B (e.g. Capitol Hill
        // Poultry). Retail butchers filtered at insert by name pattern.
        'places_types'    => ['wholesaler', 'butcher_shop'],
        'text_queries'    => ['meat wholesale', 'meat purveyor', 'meat distributor', 'poultry wholesale'],
        'category'        => 'protein',
        'priority_enrich' => true,
    ],

    'seafood' => [
        'places_types'    => ['wholesaler'],
        'text_queries'    => ['seafood wholesale', 'seafood distributor', 'seafood purveyor'],
        'category'        => 'seafood',
        'priority_enrich' => true,
    ],

    'dairy_bakery_bev' => [
        'places_types'    => ['wholesaler'],
        'text_queries'    => ['dairy distributor', 'bakery distributor', 'beverage distributor', 'wholesale bakery'],
        'category'        => 'specialty',
        'priority_enrich' => false,
    ],

    'specialty_ethnic' => [
        // `asian_grocery_store` removed — overwhelmingly retail. The
        // import-specific text queries catch the actual B2B importers.
        'places_types'    => ['wholesaler'],
        'text_queries'    => ['Asian food importer', 'Latino food importer', 'specialty food importer', 'ethnic food wholesale'],
        'category'        => 'specialty',
        'priority_enrich' => false,
    ],

    'local_grocery' => [
        // CONSUMER RETAIL — disabled by default. Spec §2 listed this
        // because some restaurants source from neighborhood grocers,
        // but in practice Places returns Safeway / 7-Eleven / Whole
        // Foods which is pure pollution. Keep the entry so the type
        // is still selectable, but with NO Places types or queries
        // until we have a tighter pattern.
        'places_types'    => [],
        'text_queries'    => [],
        'category'        => 'specialty',
        'priority_enrich' => false,
    ],

    'smallwares_equip' => [
        // Phase 2 of the build — see spec §2. Keep the entry so the type
        // exists in the enum, but with no sweep work attached yet.
        'places_types'    => [],
        'text_queries'    => [],
        'category'        => 'specialty',
        'priority_enrich' => false,
    ],
];
