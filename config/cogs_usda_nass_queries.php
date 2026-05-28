<?php
/**
 * USDA NASS Quick Stats — commodity price queries for protein + dairy.
 *
 * Quick Stats: https://quickstats.nass.usda.gov/api
 * Each entry is one query. NASS prices are reported in commodity units
 * (CWT for cattle/hogs/milk, LB for broilers, DOZ for eggs). We convert
 * to a retail-comparable lb/each/cup price via $convert below.
 *
 * The result is a *commodity-level proxy* for the retail wholesale price
 * an operator pays. It's nowhere near as precise as a USDA AMS terminal
 * price for produce, but it's the best free public number for protein
 * and dairy, and provenance is recorded so anyone querying the price can
 * see "this came from NASS national CATTLE/PRICE RECEIVED".
 *
 * NASS query reference:
 *   commodity_desc       — e.g. CATTLE, HOGS, BROILERS, EGGS, MILK
 *   statisticcat_desc    — PRICE RECEIVED is the producer-side mid-channel proxy
 *   unit_desc            — '$ / CWT', '$ / LB', '$ / DOZ', etc.
 *   agg_level_desc       — NATIONAL | STATE
 *   year__GE             — earliest year
 *   reference_period_desc — MARKETING YEAR | YEAR | quarterly buckets
 *
 * 'markup' is the wholesale-to-restaurant multiplier (rough). Producer
 * price × markup ≈ the wholesale cost an operator sees. Default 1.0 if
 * omitted. Document the assumption per-row so it's tunable later.
 */
return [
    [
        'ingredient_key' => 'ground_beef_80_20',
        'commodity_desc' => 'CATTLE',
        'class_desc'     => 'STEERS',          // optional refinement
        'unit_desc'      => '$ / CWT',
        'wholesale_unit' => 'lb',
        'cwt_to_unit'    => 0.01,              // $/CWT × 0.01 = $/lb
        'markup'         => 2.2,               // live-weight to ground-beef trim markup
        'source_ref'     => 'NASS CATTLE / PRICE RECEIVED / STEERS / national',
    ],
    [
        'ingredient_key' => 'bacon_thick',
        'commodity_desc' => 'HOGS',
        'unit_desc'      => '$ / CWT',
        'wholesale_unit' => 'lb',
        'cwt_to_unit'    => 0.01,
        'markup'         => 3.4,               // hog live-weight to belly/bacon trim
        'source_ref'     => 'NASS HOGS / PRICE RECEIVED / national',
    ],
    [
        'ingredient_key' => 'chicken_breast',
        'commodity_desc' => 'BROILERS',
        'unit_desc'      => '$ / LB',
        'wholesale_unit' => 'lb',
        'cwt_to_unit'    => 1.0,
        'markup'         => 3.0,               // whole-bird to boneless skinless breast premium
        'source_ref'     => 'NASS BROILERS / PRICE RECEIVED / national',
    ],
    [
        'ingredient_key' => 'egg_large',
        'commodity_desc' => 'EGGS',
        'class_desc'     => 'TABLE',
        'unit_desc'      => '$ / DOZEN',
        'wholesale_unit' => 'each',
        'cwt_to_unit'    => 1.0 / 12.0,        // $/doz → $/each
        'markup'         => 1.1,               // farmgate to wholesale
        'source_ref'     => 'NASS EGGS / PRICE RECEIVED / TABLE / national',
    ],
    [
        'ingredient_key' => 'milk_whole',
        'commodity_desc' => 'MILK',
        'unit_desc'      => '$ / CWT',
        'wholesale_unit' => 'cup',
        // CWT (100 lb) ≈ 11.63 gallons ≈ 186 cups. $/CWT × (1/186) = $/cup.
        'cwt_to_unit'    => 1.0 / 186.0,
        'markup'         => 1.4,               // farm to bottled wholesale
        'source_ref'     => 'NASS MILK / PRICE RECEIVED / national',
    ],
    [
        'ingredient_key' => 'butter_unsalted',
        'commodity_desc' => 'BUTTER',
        'unit_desc'      => '$ / LB',
        'wholesale_unit' => 'lb',
        'cwt_to_unit'    => 1.0,
        'markup'         => 1.0,               // already at wholesale
        'source_ref'     => 'NASS BUTTER / PRICE RECEIVED / national',
    ],
    [
        'ingredient_key' => 'cream_heavy',
        'commodity_desc' => 'MILK',
        'unit_desc'      => '$ / CWT',
        'wholesale_unit' => 'cup',
        'cwt_to_unit'    => 1.0 / 186.0,
        'markup'         => 4.0,               // milk to heavy-cream multiplier
        'source_ref'     => 'NASS MILK / PRICE RECEIVED / national (cream proxy)',
    ],
];
