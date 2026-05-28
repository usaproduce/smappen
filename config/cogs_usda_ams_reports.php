<?php
/**
 * USDA AMS MyMarketNews — terminal-market wholesale F&V report mapping.
 *
 * Real slug IDs verified against the MARS API report catalog at
 *   GET https://marsapi.ams.usda.gov/services/v1.2/reports
 * on 2026-05-27. Each terminal city splits across three reports:
 *   FV010 = Fruit, FV020 = Vegetables, FV030 = Onions & Potatoes.
 *
 * Discontinued markets (Dallas, San Francisco, Saint Louis) are omitted.
 * The MARS API responds with section data only when the URL includes
 * "/Report Details" (handled by the adapter).
 *
 * Fields:
 *   slug       — MARS API slug
 *   region     — Carafe region key written to cogs_benchmark.region
 *   label      — used in source_ref + freshness footer
 *   commodities — list of (commodity_substring, variety_substring,
 *                 ingredient_key) tuples. Match is case-insensitive
 *                 substring on `commodity` AND `variety` fields
 *                 returned by AMS (which look like "Tomatoes, Roma" /
 *                 "ROMA"). First match wins, so list the most specific
 *                 ahead of the generic. Empty variety = any variety.
 *
 * NOTE: prices are reported per *package* (carton, bushel, etc.). The
 * adapter normalizes via:
 *   • explicit "N lb cartons" / "N kg cartons"
 *   • sub-pack arithmetic "cartons 12 3-lb bags"
 *   • per-commodity bushel weights (the BUSHEL_WEIGHTS table in
 *     UsdaAmsAdapter::bushelLbsFor)
 *   • everything else is skipped with a notes_json entry
 */

$produce_vegetables = [
    ['TOMATOES',    'ROMA',         'tomato_roma'],
    ['TOMATOES',    'CHERRY',       'tomato_cherry'],
    ['TOMATOES',    '',             'tomato_roma'],          // generic last
    ['LETTUCE',     'ROMAINE',      'lettuce_romaine'],
    ['LETTUCE',     'ICEBERG',      'lettuce_romaine'],      // close enough as proxy
    ['CUCUMBERS',   '',             'cucumber'],
    ['PEPPERS, BELL', '',           'pepper_bell'],
    ['BROCCOLI',    '',             'broccoli'],
    ['CAULIFLOWER', '',             'cauliflower'],
    ['CARROTS',     '',             'carrot'],
    ['CELERY',      '',             'celery'],
    ['GREENS',      'SPINACH',      'spinach_baby'],
    ['SPINACH',     '',             'spinach_baby'],
    ['MUSHROOMS',   'WHITE BUTTON', 'mushroom_button'],
    ['MUSHROOMS',   'BUTTON',       'mushroom_button'],
    ['MUSHROOMS',   '',             'mushroom_button'],
    ['GARLIC',      '',             'garlic_fresh'],
    ['HERBS',       'BASIL',        'basil_fresh'],
    ['HERBS',       'PARSLEY',      'parsley_fresh'],
];
$produce_fruit = [
    ['LIMES',       '',             'lime'],
    ['LEMONS',      '',             'lemon'],
    ['AVOCADOS',    'HASS',         'avocado'],
    ['AVOCADOS',    '',             'avocado'],
    ['STRAWBERRIES','',             'strawberry'],
    ['BLUEBERRIES', '',             'blueberry'],
];
$produce_onions = [
    ['ONIONS',      'YELLOW',       'onion_yellow'],
    ['ONIONS DRY',  'YELLOW',       'onion_yellow'],
    ['POTATOES',    'RUSSET',       'potato_russet'],
    ['POTATOES, RUSSET', '',        'potato_russet'],
];

$cities = [
    // slug_fruit, slug_veg, slug_onions, region, city_label
    [2285, 2286, 2287, 'US-NE',           'Boston'],
    [2314, 2315, 2316, 'US-NE',           'New York'],
    [2318, 2319, 2320, 'US-NE',           'Philadelphia'],
    [2281, 2282, 2283, 'US-MID-ATLANTIC', 'Baltimore'],
    [2290, 2291, 2292, 'US-MW',           'Chicago'],
    [2302, 2303, 2304, 'US-MW',           'Detroit'],
    [2277, 2278, 2279, 'US-SE',           'Atlanta'],
    [2310, 2311, 2312, 'US-SE',           'Miami'],
    [2294, 2295, 2296, 'US-SE',           'Columbia'],
    [2306, 2307, 2308, 'US-W',            'Los Angeles'],
];

$out = [];
foreach ($cities as [$slugFruit, $slugVeg, $slugOnion, $region, $city]) {
    $out[] = ['slug' => (string) $slugFruit, 'region' => $region,
              'label' => "USDA AMS $city Terminal — Fruit",
              'commodities' => $produce_fruit];
    $out[] = ['slug' => (string) $slugVeg,   'region' => $region,
              'label' => "USDA AMS $city Terminal — Vegetables",
              'commodities' => $produce_vegetables];
    $out[] = ['slug' => (string) $slugOnion, 'region' => $region,
              'label' => "USDA AMS $city Terminal — Onions & Potatoes",
              'commodities' => $produce_onions];
}
return $out;
