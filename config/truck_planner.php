<?php
declare(strict_types=1);

/**
 * Truck Planner settings (docs/truck-planner/04_BACKEND.md 7.1).
 *
 * One array, read with `require` by the classes that need it. No secrets live here: keys are read from the
 * environment by the client classes (GOOGLE_API_KEY, EIA_API_KEY, TP_CONTACT_EMAIL, MAIL_FROM, APP_ENV).
 *
 * The budgets, rate numbers and the per-call cap are placeholders to tune with real use. They limit what
 * this server asks Google for. They are not a cost bound for the key: that bound is the quota set in the
 * Google Cloud console.
 */
return [
    // Sent to the browser by the bootstrap endpoint (4.3). Exactly these seven keys.
    'limits' => [
        'max_spots' => 500,
        'max_stops_per_plan' => 8,
        'max_points_per_drive_request' => 60,
        'max_pairs_per_drive_request' => 650,
        'max_body_bytes' => 262144,
        'day_context_max_days' => 14,
        'max_suggest_spots' => 200,
    ],

    // Server-side bounds that the browser does not need (sections 4 and 5).
    'requests' => [
        'plans_range_max_days' => 92,
        'services_range_max_days' => 730,
        'services_default_days_back' => 90,
        'plans_default_days_back' => 7,
        'plans_default_days_ahead' => 21,
        'day_context_default_days' => 7,
        'max_override_entries' => 200,
        'max_licence_counties' => 60,
        'refresh_stale_spots' => 50,
        'refresh_script_batch' => 200,
        'simulate_max_outlets' => 60,
        'simulate_max_hosts_nearby' => 10,
        'hosts_nearby_radius_m' => 250.0,
        'host_relink_radius_m' => 100.0,
        'spot_move_keeps_corrections_m' => 250.0,
        'export_page_rows' => 200,
        'long_request_seconds' => 60,
    ],

    // Google Routes API and the leg cache (5.3).
    'routing' => [
        'connect_timeout_s' => 3,
        'timeout_s' => 8,
        'call_budget_s' => 12,
        'leg_ttl_days' => 30,
        'max_elements_per_call' => 650,
        'org_elements_per_day' => 3000,
        'global_elements_per_day' => 20000,
        'org_counter_ttl_s' => 172800,
        'bucket' => 'tp_routes_elements',
        'bucket_wait_s' => 2,
        'refusal_ttl_s' => 3600,
        'backoff_quota_s' => 120,
        'backoff_upstream_s' => 30,
        'grid_fill_ratio' => 0.6,
        'routes_chunk_side' => 25,
        'routes_chunk_elements' => 625,
        'legacy_chunk_side' => 10,
        'cache_pairs_per_statement' => 200,
        'purge_rows_per_call' => 500,
        'skus' => [
            'plain' => 'tp_routes_matrix',
            'modifiers' => 'tp_routes_matrix_pro',
            'tolls' => 'tp_routes_matrix_ent',
            'legacy' => 'tp_distance_matrix',
        ],
        'attribution' => 'Drive times and distances: Google Maps Platform. Kept for at most 30 days.',
    ],

    // Google Places contact lookup, on demand (5.4).
    'places' => [
        'contact_ttl_days' => 30,
        'force_min_age_hours' => 24,
        'bias_radius_m' => 500.0,
        'connect_timeout_s' => 3,
        'timeout_s' => 6,
        'bucket' => 'tp_places_lookup',
        'bucket_wait_s' => 2,
        'refusal_ttl_s' => 3600,
        'backoff_s' => 60,
        'sku' => 'tp_places_text',
    ],

    // api.weather.gov hourly forecast (5.5).
    'weather' => [
        'connect_timeout_s' => 3,
        'timeout_s' => 6,
        'retry_wait_s' => 1,
        'point_ttl_s' => 1209600,
        'no_coverage_ttl_s' => 86400,
        'hourly_ttl_s' => 604800,
        'fresh_min_s' => 600,
        'fresh_default_s' => 3600,
        'stale_max_s' => 21600,
        'sku_points' => 'tp_nws_points',
        'sku_hourly' => 'tp_nws_hourly',
        'source' => 'National Weather Service (weather.gov)',
    ],

    // EIA weekly retail fuel price (5.6).
    'fuel' => [
        'connect_timeout_s' => 3,
        'timeout_s' => 5,
        'attempt_ttl_s' => 21600,
        'lookback_days' => 28,
        'page_length' => 100,
        'release_zone' => 'America/New_York',
        'release_dow' => 1,
        'release_minute' => 600,
        'national_area' => 'NUS',
        'products' => ['gasoline' => 'EPMR', 'diesel' => 'EPD2D'],
        'sku' => 'tp_eia_weekly',
    ],

    // Region data (5.1, 5.2).
    'regions' => [
        'usable_verdict_ttl_s' => 86400,
        'locate_radius_m' => 2400.0,
        'default_timezone' => 'America/New_York',
    ],

    // Plan snapshots (5.8).
    'plans' => [
        'snapshot_ttl_days' => 30,
    ],

    // Scouting (5.9).
    'scout' => [
        'shortlist_extra' => 10,
        'max_screen' => 15000,
        'cache_ttl_s' => 86400,
        'page_rows' => 2000,
        'reach_slack' => 1.25,
        'display_keys_per_query' => 100,
    ],

    // Suggestions (5.10).
    'suggest' => [
        'cache_ttl_s' => 600,
    ],

    // api_cost_events.unit_cost_usd per SKU (5.3). Matrix SKUs are per element, the others per call.
    'unit_cost_usd' => [
        'tp_routes_matrix' => 0.005,
        'tp_routes_matrix_pro' => 0.010,
        'tp_routes_matrix_ent' => 0.015,
        'tp_distance_matrix' => 0.005,
        'tp_places_text' => 0.035,
        'tp_nws_points' => 0.0,
        'tp_nws_hourly' => 0.0,
        'tp_eia_weekly' => 0.0,
    ],
];
