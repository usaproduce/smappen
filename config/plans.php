<?php
/**
 * Plan metadata + feature matrix.
 *
 * Per the durable product directive ("no restrictions on the free tier"),
 * every limit here is the unlimited sentinel (-1) or `true`. The structure
 * exists so the matrix can be flipped per-feature later WITHOUT a code
 * change anywhere else — `PlanLimits::getLimit($plan, $name)` reads from
 * this file, `<UpgradeGate>` reads the same matrix on the frontend, the
 * pricing page renders the same matrix as a comparison table.
 *
 * Bumping a single limit (say, free → max_areas=10) cascades to:
 *   • Server-side enforcement in PlanGate middleware
 *   • Frontend UpgradeGate component greys out the action + shows upsell
 *   • Pricing page picks up the new value automatically
 *   • Stripe webhook updates `organizations.plan` → next request sees new limit
 */

return [
    // Public-facing pricing metadata (rendered on /pricing).
    'plans' => [
        'free' => [
            'name' => 'Free',
            'price_monthly_usd' => 0,
            'price_annual_usd'  => 0,
            'stripe_price_id'   => null,
            'tagline'           => 'Get a feel for the platform.',
            'badge_color'       => '#94a3b8',
            'features_summary'  => ['Unlimited areas', 'Demographics for all 50 states', 'Basic export'],
        ],
        'starter' => [
            'name' => 'Starter',
            'price_monthly_usd' => 29,
            'price_annual_usd'  => 290,
            'stripe_price_id'   => 'price_starter_monthly',
            'tagline'           => 'For solo operators + small teams.',
            'badge_color'       => '#3b82f6',
            'features_summary'  => ['Everything in Free', 'POI search', 'PDF reports', 'CSV import 10K rows'],
        ],
        'pro' => [
            'name' => 'Pro',
            'price_monthly_usd' => 79,
            'price_annual_usd'  => 790,
            'stripe_price_id'   => 'price_pro_monthly',
            'tagline'           => 'For growing franchise + sales teams.',
            'badge_color'       => '#7848BB',
            'features_summary'  => ['Everything in Starter', 'Analog Finder', 'Territory generation', 'Drive-time matrix', 'AI scoring'],
        ],
        'business' => [
            'name' => 'Business',
            'price_monthly_usd' => 199,
            'price_annual_usd'  => 1990,
            'stripe_price_id'   => 'price_business_monthly',
            'tagline'           => 'For multi-location operators.',
            'badge_color'       => '#dc2626',
            'features_summary'  => ['Everything in Pro', 'Realtime collaboration', 'CRM integrations', '5 team seats'],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'price_monthly_usd' => null, // contact-us
            'price_annual_usd'  => null,
            'stripe_price_id'   => null,
            'tagline'           => 'For large orgs with custom needs.',
            'badge_color'       => '#1f2937',
            'features_summary'  => ['Everything in Business', 'SSO/SAML', 'SLA', 'Custom integrations', 'Dedicated CSM'],
        ],
    ],

    // Feature matrix — server + client read the same shape via
    // PlanLimits::getLimit($plan, $name). -1 = unlimited, true = enabled.
    // Per the directive, every plan is currently unlimited; the structure
    // is here so individual cells can be flipped later.
    'features' => [
        'free' => [
            'max_projects' => -1,
            'max_areas_per_project' => -1,
            'max_isochrones_per_day' => -1,
            'max_poi_searches_per_day' => -1,
            'max_import_rows' => -1,
            'reports' => true,
            'pdf_reports' => true,
            'export' => true,
            'team_seats' => -1,
            'api_access' => true,
            'analog_finder' => true,
            'territory_generation' => true,
            'drive_time_matrix' => true,
            'forecast' => true,
            'rebalancer' => true,
            'realtime_cursors' => true,
            'crm_integrations' => true,
            'scheduled_reports' => true,
            'custom_data_layers' => true,
            'street_view' => true,
            'ai_scoring' => true,
            'trial_days' => 0,
        ],
        'starter'    => /* same shape, all flags true / -1 */ [],
        'pro'        => [],
        'business'   => [],
        'enterprise' => [],
    ],

    // Trial config. When a user signs up, they get this many days at the
    // target_plan level. trial_ends_at on the organization row drives the
    // banner + downgrade.
    'trial' => [
        'target_plan' => 'pro',
        'duration_days' => 14,
        'email_touchpoints' => [1, 3, 7, 12, 14], // days after signup
    ],

    // Dunning state machine. On Stripe payment failure we move the org to
    // `past_due`, show a banner, and auto-downgrade after this many days.
    'dunning' => [
        'past_due_grace_days' => 7,
    ],
];
