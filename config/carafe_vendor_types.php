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
        'places_types'    => ['wholesaler', 'food_store'],
        'text_queries'    => ['food distributor', 'foodservice distributor', 'Sysco', 'US Foods', 'PFG'],
        'category'        => 'broadline',
        'priority_enrich' => true,
    ],

    'cash_carry' => [
        'places_types'    => ['warehouse_store', 'wholesaler'],
        'text_queries'    => ['restaurant depot', 'cash and carry', "Chef's Warehouse"],
        'category'        => 'broadline',
        'priority_enrich' => true,
    ],

    'produce' => [
        'places_types'    => ['produce_market', 'wholesaler'],
        'text_queries'    => ['produce wholesale', 'produce distributor'],
        'category'        => 'produce',
        'priority_enrich' => true,
    ],

    'meat' => [
        'places_types'    => ['wholesaler', 'butcher_shop'],
        'text_queries'    => ['meat wholesale', 'meat purveyor'],
        'category'        => 'protein',
        'priority_enrich' => true,
    ],

    'seafood' => [
        'places_types'    => ['wholesaler', 'seafood_market'],
        'text_queries'    => ['seafood wholesale', 'seafood distributor'],
        'category'        => 'seafood',
        'priority_enrich' => true,
    ],

    'dairy_bakery_bev' => [
        'places_types'    => ['wholesaler'],
        'text_queries'    => ['dairy distributor', 'bakery distributor', 'beverage distributor'],
        'category'        => 'specialty',
        'priority_enrich' => false,
    ],

    'specialty_ethnic' => [
        'places_types'    => ['wholesaler', 'asian_grocery_store', 'market'],
        'text_queries'    => ['Asian wholesale', 'Latino wholesale', 'specialty importer'],
        'category'        => 'specialty',
        'priority_enrich' => false,
    ],

    'local_grocery' => [
        'places_types'    => ['supermarket', 'grocery_store', 'asian_grocery_store'],
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
