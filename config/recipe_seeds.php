<?php
declare(strict_types=1);

/**
 * Seed dictionary of common restaurant recipes used by RecipeSeedMatcher
 * to give operators a starting draft when they click "suggest" on a menu
 * item. The operator confirms or edits — they never face a blank page.
 *
 * Design notes:
 *  - `key`    is matched against the normalized menu item name (lowercased,
 *             alphanumeric only). Multiple aliases per recipe widen the net.
 *  - `category` is the rough cuisine/category bucket — used as a weak tiebreak.
 *  - `ingredients[].ingredient_key` should match keys in `cogs_benchmark`
 *             whenever possible so plate cost computes immediately. Keys
 *             not yet in the benchmark (e.g. burger_bun) still save fine,
 *             they just won't contribute to plate cost until benchmarked.
 *  - `qty`/`unit` are realistic per-plate portions, not whole-recipe yields.
 *
 * Keep this list curated and small — this is a starter kit, not a cookbook.
 * Add a recipe when an operator asks for one that obviously belongs.
 */
return [
    [
        'key' => 'cheeseburger',
        'aliases' => ['burger', 'hamburger', 'classicburger', 'beefburger', 'americanburger'],
        'category' => 'burgers',
        'ingredients' => [
            ['ingredient_key' => 'ground_beef_80_20', 'qty' => 6,    'unit' => 'oz'],
            ['ingredient_key' => 'burger_bun',         'qty' => 1,    'unit' => 'each'],
            ['ingredient_key' => 'cheddar_sliced',     'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'lettuce_romaine',    'qty' => 0.5,  'unit' => 'oz'],
            ['ingredient_key' => 'tomato_roma',        'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'onion_yellow',       'qty' => 0.5,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'baconcheeseburger',
        'aliases' => ['baconburger'],
        'category' => 'burgers',
        'ingredients' => [
            ['ingredient_key' => 'ground_beef_80_20', 'qty' => 6,    'unit' => 'oz'],
            ['ingredient_key' => 'burger_bun',         'qty' => 1,    'unit' => 'each'],
            ['ingredient_key' => 'cheddar_sliced',     'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'bacon_thick',        'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'lettuce_romaine',    'qty' => 0.5,  'unit' => 'oz'],
            ['ingredient_key' => 'tomato_roma',        'qty' => 1,    'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'spaghetticarbonara',
        'aliases' => ['carbonara', 'paspaghetticarbonara'],
        'category' => 'pasta',
        'ingredients' => [
            ['ingredient_key' => 'pasta_spaghetti', 'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'bacon_thick',      'qty' => 2,    'unit' => 'oz'],
            ['ingredient_key' => 'egg_large',        'qty' => 2,    'unit' => 'each'],
            ['ingredient_key' => 'parmesan_grated',  'qty' => 1,    'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'spaghettibolognese',
        'aliases' => ['bolognese', 'spagbol'],
        'category' => 'pasta',
        'ingredients' => [
            ['ingredient_key' => 'pasta_spaghetti',   'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'ground_beef_80_20', 'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'tomato_roma',        'qty' => 3,    'unit' => 'oz'],
            ['ingredient_key' => 'onion_yellow',       'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'garlic_fresh',       'qty' => 0.2,  'unit' => 'oz'],
            ['ingredient_key' => 'parmesan_grated',    'qty' => 0.5,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'fettuccinealfredo',
        'aliases' => ['alfredo'],
        'category' => 'pasta',
        'ingredients' => [
            ['ingredient_key' => 'pasta_fettuccine',  'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'butter_unsalted',   'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'cream_heavy',       'qty' => 3,    'unit' => 'oz'],
            ['ingredient_key' => 'parmesan_grated',   'qty' => 1.5,  'unit' => 'oz'],
            ['ingredient_key' => 'garlic_fresh',      'qty' => 0.1,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'chickenparmesan',
        'aliases' => ['chickenparm', 'chickenparma'],
        'category' => 'pasta',
        'ingredients' => [
            ['ingredient_key' => 'chicken_breast',   'qty' => 6,    'unit' => 'oz'],
            ['ingredient_key' => 'flour_ap',          'qty' => 0.5,  'unit' => 'oz'],
            ['ingredient_key' => 'egg_large',         'qty' => 1,    'unit' => 'each'],
            ['ingredient_key' => 'breadcrumbs',       'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'tomato_roma',       'qty' => 3,    'unit' => 'oz'],
            ['ingredient_key' => 'mozzarella_fresh',  'qty' => 1.5,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'caesarsalad',
        'aliases' => ['caesar', 'classiccaesar'],
        'category' => 'salads',
        'ingredients' => [
            ['ingredient_key' => 'lettuce_romaine',  'qty' => 5,    'unit' => 'oz'],
            ['ingredient_key' => 'parmesan_grated',  'qty' => 0.5,  'unit' => 'oz'],
            ['ingredient_key' => 'croutons',         'qty' => 0.75, 'unit' => 'oz'],
            ['ingredient_key' => 'caesar_dressing',  'qty' => 1.5,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'chickencaesarsalad',
        'aliases' => ['chickencaesar', 'caesarchicken'],
        'category' => 'salads',
        'ingredients' => [
            ['ingredient_key' => 'chicken_breast',   'qty' => 5,    'unit' => 'oz'],
            ['ingredient_key' => 'lettuce_romaine',  'qty' => 5,    'unit' => 'oz'],
            ['ingredient_key' => 'parmesan_grated',  'qty' => 0.5,  'unit' => 'oz'],
            ['ingredient_key' => 'croutons',         'qty' => 0.75, 'unit' => 'oz'],
            ['ingredient_key' => 'caesar_dressing',  'qty' => 1.5,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'gardensalad',
        'aliases' => ['housesalad', 'mixedgreens', 'sidesalad'],
        'category' => 'salads',
        'ingredients' => [
            ['ingredient_key' => 'lettuce_romaine', 'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'tomato_cherry',   'qty' => 1.5,  'unit' => 'oz'],
            ['ingredient_key' => 'cucumber',         'qty' => 1.5,  'unit' => 'oz'],
            ['ingredient_key' => 'onion_red',        'qty' => 0.5,  'unit' => 'oz'],
            ['ingredient_key' => 'olive_oil_xv',     'qty' => 0.5,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'capresesalad',
        'aliases' => ['caprese'],
        'category' => 'salads',
        'ingredients' => [
            ['ingredient_key' => 'tomato_roma',      'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'mozzarella_fresh', 'qty' => 3,    'unit' => 'oz'],
            ['ingredient_key' => 'basil_fresh',      'qty' => 0.15, 'unit' => 'oz'],
            ['ingredient_key' => 'olive_oil_xv',     'qty' => 0.5,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'cobbsalad',
        'aliases' => ['cobb'],
        'category' => 'salads',
        'ingredients' => [
            ['ingredient_key' => 'lettuce_romaine',  'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'chicken_breast',   'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'bacon_thick',      'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'avocado',          'qty' => 2,    'unit' => 'oz'],
            ['ingredient_key' => 'egg_large',        'qty' => 1,    'unit' => 'each'],
            ['ingredient_key' => 'bleu_cheese',      'qty' => 1,    'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'margheritapizza',
        'aliases' => ['margherita'],
        'category' => 'pizza',
        'ingredients' => [
            ['ingredient_key' => 'pizza_dough',      'qty' => 8,    'unit' => 'oz'],
            ['ingredient_key' => 'tomato_roma',      'qty' => 3,    'unit' => 'oz'],
            ['ingredient_key' => 'mozzarella_fresh', 'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'basil_fresh',      'qty' => 0.1,  'unit' => 'oz'],
            ['ingredient_key' => 'olive_oil_xv',     'qty' => 0.25, 'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'pepperonipizza',
        'aliases' => ['pepperoni'],
        'category' => 'pizza',
        'ingredients' => [
            ['ingredient_key' => 'pizza_dough',      'qty' => 8,    'unit' => 'oz'],
            ['ingredient_key' => 'tomato_roma',      'qty' => 3,    'unit' => 'oz'],
            ['ingredient_key' => 'mozzarella_fresh', 'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'pepperoni',        'qty' => 2,    'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'grilledchicken',
        'aliases' => ['grilledchickenbreast', 'chickenplate'],
        'category' => 'mains',
        'ingredients' => [
            ['ingredient_key' => 'chicken_breast', 'qty' => 8,    'unit' => 'oz'],
            ['ingredient_key' => 'olive_oil_xv',   'qty' => 0.5,  'unit' => 'oz'],
            ['ingredient_key' => 'salt_kosher',    'qty' => 0.1,  'unit' => 'oz'],
            ['ingredient_key' => 'pepper_black',   'qty' => 0.05, 'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'salmonfillet',
        'aliases' => ['grilledsalmon', 'pansearedsalmon', 'salmon'],
        'category' => 'mains',
        'ingredients' => [
            ['ingredient_key' => 'salmon_fillet',  'qty' => 7,    'unit' => 'oz'],
            ['ingredient_key' => 'olive_oil_xv',   'qty' => 0.5,  'unit' => 'oz'],
            ['ingredient_key' => 'lemon',          'qty' => 0.25, 'unit' => 'each'],
            ['ingredient_key' => 'salt_kosher',    'qty' => 0.1,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'shrimpscampi',
        'aliases' => ['scampi'],
        'category' => 'mains',
        'ingredients' => [
            ['ingredient_key' => 'shrimp_16_20',    'qty' => 6,    'unit' => 'oz'],
            ['ingredient_key' => 'butter_unsalted', 'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'garlic_fresh',    'qty' => 0.2,  'unit' => 'oz'],
            ['ingredient_key' => 'lemon',           'qty' => 0.25, 'unit' => 'each'],
            ['ingredient_key' => 'parsley_fresh',   'qty' => 0.1,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'ribeyesteak',
        'aliases' => ['ribeye', 'steakribeye'],
        'category' => 'mains',
        'ingredients' => [
            ['ingredient_key' => 'ribeye_steak',    'qty' => 12,   'unit' => 'oz'],
            ['ingredient_key' => 'butter_unsalted', 'qty' => 0.5,  'unit' => 'oz'],
            ['ingredient_key' => 'salt_kosher',    'qty' => 0.15, 'unit' => 'oz'],
            ['ingredient_key' => 'pepper_black',   'qty' => 0.05, 'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'frenchfries',
        'aliases' => ['fries', 'sidefries'],
        'category' => 'sides',
        'ingredients' => [
            ['ingredient_key' => 'potato_russet', 'qty' => 6,    'unit' => 'oz'],
            ['ingredient_key' => 'oil_fryer',     'qty' => 0.5,  'unit' => 'oz'],
            ['ingredient_key' => 'salt_kosher',   'qty' => 0.1,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'mashedpotatoes',
        'aliases' => ['mashpotato', 'mashedpotato'],
        'category' => 'sides',
        'ingredients' => [
            ['ingredient_key' => 'potato_russet',   'qty' => 6,    'unit' => 'oz'],
            ['ingredient_key' => 'butter_unsalted', 'qty' => 0.75, 'unit' => 'oz'],
            ['ingredient_key' => 'milk_whole',      'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'salt_kosher',     'qty' => 0.1,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'tacosalbeefcorn',
        'aliases' => ['tacos', 'beeftacos', 'tacobeef'],
        'category' => 'mexican',
        'ingredients' => [
            ['ingredient_key' => 'ground_beef_80_20', 'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'tortilla_corn',     'qty' => 3,    'unit' => 'each'],
            ['ingredient_key' => 'cheddar_sliced',    'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'lettuce_romaine',   'qty' => 0.75, 'unit' => 'oz'],
            ['ingredient_key' => 'tomato_roma',       'qty' => 1,    'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'chickenquesadilla',
        'aliases' => ['quesadilla'],
        'category' => 'mexican',
        'ingredients' => [
            ['ingredient_key' => 'tortilla_flour',  'qty' => 2,    'unit' => 'each'],
            ['ingredient_key' => 'chicken_breast',  'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'cheddar_sliced',  'qty' => 2,    'unit' => 'oz'],
            ['ingredient_key' => 'onion_yellow',    'qty' => 0.5,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'guacamole',
        'aliases' => ['guac'],
        'category' => 'mexican',
        'ingredients' => [
            ['ingredient_key' => 'avocado',      'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'lime',         'qty' => 0.25, 'unit' => 'each'],
            ['ingredient_key' => 'onion_red',    'qty' => 0.25, 'unit' => 'oz'],
            ['ingredient_key' => 'salt_kosher',  'qty' => 0.05, 'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'fishtacos',
        'aliases' => ['bajafishtaco', 'fishtaco'],
        'category' => 'mexican',
        'ingredients' => [
            ['ingredient_key' => 'white_fish',    'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'tortilla_corn', 'qty' => 2,    'unit' => 'each'],
            ['ingredient_key' => 'cabbage',       'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'lime',          'qty' => 0.25, 'unit' => 'each'],
        ],
    ],
    [
        'key' => 'beefburrito',
        'aliases' => ['burrito'],
        'category' => 'mexican',
        'ingredients' => [
            ['ingredient_key' => 'tortilla_flour',     'qty' => 1,    'unit' => 'each'],
            ['ingredient_key' => 'ground_beef_80_20',  'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'rice_jasmine',       'qty' => 2,    'unit' => 'oz'],
            ['ingredient_key' => 'beans_black',         'qty' => 2,    'unit' => 'oz'],
            ['ingredient_key' => 'cheddar_sliced',     'qty' => 1,    'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'macandcheese',
        'aliases' => ['macaroniandcheese', 'macncheese'],
        'category' => 'comfort',
        'ingredients' => [
            ['ingredient_key' => 'pasta_macaroni',  'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'cheddar_sliced',   'qty' => 2,    'unit' => 'oz'],
            ['ingredient_key' => 'butter_unsalted',  'qty' => 0.5,  'unit' => 'oz'],
            ['ingredient_key' => 'milk_whole',       'qty' => 2,    'unit' => 'oz'],
            ['ingredient_key' => 'flour_ap',         'qty' => 0.25, 'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'grilledcheese',
        'aliases' => ['grilledcheesesandwich'],
        'category' => 'sandwiches',
        'ingredients' => [
            ['ingredient_key' => 'bread_sourdough',  'qty' => 2,    'unit' => 'each'],
            ['ingredient_key' => 'cheddar_sliced',    'qty' => 2,    'unit' => 'oz'],
            ['ingredient_key' => 'butter_unsalted',  'qty' => 0.5,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'blt',
        'aliases' => ['bltsandwich', 'baconlettucetomato'],
        'category' => 'sandwiches',
        'ingredients' => [
            ['ingredient_key' => 'bacon_thick',     'qty' => 2,    'unit' => 'oz'],
            ['ingredient_key' => 'lettuce_romaine', 'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'tomato_roma',     'qty' => 1.5,  'unit' => 'oz'],
            ['ingredient_key' => 'bread_sourdough', 'qty' => 2,    'unit' => 'each'],
            ['ingredient_key' => 'mayonnaise',      'qty' => 0.25, 'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'clubsandwich',
        'aliases' => ['club'],
        'category' => 'sandwiches',
        'ingredients' => [
            ['ingredient_key' => 'bread_sourdough',  'qty' => 3,    'unit' => 'each'],
            ['ingredient_key' => 'chicken_breast',   'qty' => 4,    'unit' => 'oz'],
            ['ingredient_key' => 'bacon_thick',      'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'lettuce_romaine',  'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'tomato_roma',      'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'mayonnaise',       'qty' => 0.25, 'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'chickenwings',
        'aliases' => ['wings', 'buffalowings'],
        'category' => 'appetizers',
        'ingredients' => [
            ['ingredient_key' => 'chicken_wings',  'qty' => 8,    'unit' => 'oz'],
            ['ingredient_key' => 'oil_fryer',      'qty' => 0.5,  'unit' => 'oz'],
            ['ingredient_key' => 'hot_sauce',      'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'butter_unsalted','qty' => 0.5,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'chickentenders',
        'aliases' => ['chickenstrips', 'tenders'],
        'category' => 'appetizers',
        'ingredients' => [
            ['ingredient_key' => 'chicken_breast', 'qty' => 6,    'unit' => 'oz'],
            ['ingredient_key' => 'flour_ap',       'qty' => 0.75, 'unit' => 'oz'],
            ['ingredient_key' => 'egg_large',      'qty' => 1,    'unit' => 'each'],
            ['ingredient_key' => 'breadcrumbs',    'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'oil_fryer',      'qty' => 0.5,  'unit' => 'oz'],
        ],
    ],
    [
        'key' => 'mushroomrisotto',
        'aliases' => ['risotto', 'mushroomrisottto'],
        'category' => 'pasta',
        'ingredients' => [
            ['ingredient_key' => 'rice_arborio',    'qty' => 3.5,  'unit' => 'oz'],
            ['ingredient_key' => 'mushroom_button', 'qty' => 3,    'unit' => 'oz'],
            ['ingredient_key' => 'butter_unsalted', 'qty' => 0.75, 'unit' => 'oz'],
            ['ingredient_key' => 'parmesan_grated', 'qty' => 1,    'unit' => 'oz'],
            ['ingredient_key' => 'onion_yellow',    'qty' => 0.5,  'unit' => 'oz'],
        ],
    ],
];
