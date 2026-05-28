<?php
/**
 * USDA AMS MyMarketNews — terminal-market wholesale report mapping.
 *
 * Each entry is one MARS API report slug. Slugs are stable per report
 * series but the report content rotates daily. Look up active slugs at:
 *   https://mymarketnews.ams.usda.gov/mymarketnews-api/reports
 *
 * Fields:
 *   slug       — MARS API slug (numeric string in URL: /reports/{slug})
 *   region     — Carafe region key written into cogs_benchmark.region
 *   label      — human label for logs / freshness footer ("USDA AMS Boston")
 *   ingredients — map of (commodity, variety) keyword to ingredient_key.
 *                 Match is case-insensitive substring on both fields. The
 *                 *first* matching entry wins, so list the most specific
 *                 (e.g. tomato_roma) ahead of the generic (tomato).
 *                 Empty variety = any variety for that commodity.
 *
 * The unit AMS reports prices in is the report's "package" field. We
 * normalize it via UsdaAmsAdapter::normalizePackage(). Anything we can't
 * confidently normalize is skipped + logged — better no row than a wrong
 * row, since plate-cost math compounds.
 */
return [
    // Northeast terminal markets
    [
        'slug'   => '1830',
        'region' => 'US-NE',
        'label'  => 'USDA AMS Boston Terminal',
        'ingredients' => [
            ['commodity' => 'TOMATOES', 'variety' => 'ROMA',     'key' => 'tomato_roma'],
            ['commodity' => 'TOMATOES', 'variety' => 'CHERRY',   'key' => 'tomato_cherry'],
            ['commodity' => 'ONIONS DRY', 'variety' => 'YELLOW', 'key' => 'onion_yellow'],
            ['commodity' => 'GARLIC',   'variety' => '',         'key' => 'garlic_fresh'],
            ['commodity' => 'HERBS',    'variety' => 'BASIL',    'key' => 'basil_fresh'],
            ['commodity' => 'HERBS',    'variety' => 'PARSLEY',  'key' => 'parsley_fresh'],
            ['commodity' => 'LEMONS',   'variety' => '',         'key' => 'lemon'],
            ['commodity' => 'LIMES',    'variety' => '',         'key' => 'lime'],
            ['commodity' => 'AVOCADOS', 'variety' => '',         'key' => 'avocado'],
            ['commodity' => 'LETTUCE',  'variety' => 'ROMAINE',  'key' => 'lettuce_romaine'],
            ['commodity' => 'GREENS',   'variety' => 'SPINACH',  'key' => 'spinach_baby'],
            ['commodity' => 'MUSHROOMS','variety' => 'BUTTON',   'key' => 'mushroom_button'],
            ['commodity' => 'POTATOES', 'variety' => 'RUSSET',   'key' => 'potato_russet'],
            ['commodity' => 'CARROTS',  'variety' => '',         'key' => 'carrot'],
            ['commodity' => 'CELERY',   'variety' => '',         'key' => 'celery'],
            ['commodity' => 'CUCUMBERS','variety' => '',         'key' => 'cucumber'],
            ['commodity' => 'PEPPERS',  'variety' => 'BELL',     'key' => 'pepper_bell'],
            ['commodity' => 'BROCCOLI', 'variety' => '',         'key' => 'broccoli'],
            ['commodity' => 'CAULIFLOWER','variety' => '',       'key' => 'cauliflower'],
            ['commodity' => 'STRAWBERRIES','variety' => '',      'key' => 'strawberry'],
            ['commodity' => 'BLUEBERRIES','variety' => '',       'key' => 'blueberry'],
        ],
    ],
    [
        'slug'   => '1831',
        'region' => 'US-NE',
        'label'  => 'USDA AMS New York Terminal',
        'ingredients' => '__share_with__:1830',
    ],
    [
        'slug'   => '1832',
        'region' => 'US-NE',
        'label'  => 'USDA AMS Philadelphia Terminal',
        'ingredients' => '__share_with__:1830',
    ],

    // Mid-Atlantic (Baltimore terminal)
    [
        'slug'   => '1834',
        'region' => 'US-MID-ATLANTIC',
        'label'  => 'USDA AMS Baltimore Terminal',
        'ingredients' => '__share_with__:1830',
    ],

    // Midwest
    [
        'slug'   => '1833',
        'region' => 'US-MW',
        'label'  => 'USDA AMS Chicago Terminal',
        'ingredients' => '__share_with__:1830',
    ],
    [
        'slug'   => '1837',
        'region' => 'US-MW',
        'label'  => 'USDA AMS St. Louis Terminal',
        'ingredients' => '__share_with__:1830',
    ],

    // Southeast
    [
        'slug'   => '1828',
        'region' => 'US-SE',
        'label'  => 'USDA AMS Atlanta Terminal',
        'ingredients' => '__share_with__:1830',
    ],
    [
        'slug'   => '1835',
        'region' => 'US-SE',
        'label'  => 'USDA AMS Miami Terminal',
        'ingredients' => '__share_with__:1830',
    ],

    // South
    [
        'slug'   => '1836',
        'region' => 'US-S',
        'label'  => 'USDA AMS Dallas Terminal',
        'ingredients' => '__share_with__:1830',
    ],

    // West
    [
        'slug'   => '1839',
        'region' => 'US-W',
        'label'  => 'USDA AMS Los Angeles Terminal',
        'ingredients' => '__share_with__:1830',
    ],
    [
        'slug'   => '1840',
        'region' => 'US-W',
        'label'  => 'USDA AMS San Francisco Terminal',
        'ingredients' => '__share_with__:1830',
    ],
    [
        'slug'   => '1841',
        'region' => 'US-W',
        'label'  => 'USDA AMS Seattle Terminal',
        'ingredients' => '__share_with__:1830',
    ],
];
