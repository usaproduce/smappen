<?php
namespace App\Services;

/**
 * Approximate per-call cost (USD) for Google Maps Platform APIs.
 *
 * Numbers come from the public pricing pages as of 2025 — they undercount
 * if you exceed the free tier and overcount once volume discounts kick in,
 * but they're the right order of magnitude for surfacing "this click costs
 * ~3¢" to operators making decisions.
 *
 * Treat these as a budget signal, not an invoice. The real number is on
 * the Google Cloud billing console.
 */
class GooglePricing
{
    public const COSTS = [
        // Geocoding API
        'geocode'           => 0.005,   // $5 / 1000
        'reverse_geocode'   => 0.005,
        // 'geocode_batch' is a rate-limit pool, NOT a Google API. The real
        // per-address geocode billing is logged under 'geocode' (count=N)
        // from the batch controller. Keeping this at 0 here prevents the
        // rate-limit row from double-counting the batch cost.
        'geocode_batch'     => 0,

        // Places API (new) — Essentials SKU
        'places_nearby'     => 0.032,   // $32 / 1000
        'places_search'     => 0.032,
        // 'places' is the rate-limit pool name; 'places_nearby' / 'places_text'
        // are the cost-bearing api_names attached to controller responses.
        // Keeping 'places' at 0 stops the middleware row from double-charging.
        'places'            => 0,
        'places_text'       => 0.032,
        'place_details'     => 0.020,   // basic fields
        'places_autocomplete' => 0.00283, // $2.83 / 1000 — per session token

        // Maps JS / Static / Routes
        'static_map'        => 0.002,   // $2 / 1000
        'dynamic_maps_load' => 0.007,   // $7 / 1000 — counted per page load
        'routes'            => 0.005,   // $5 / 1000 (Routes Essentials)
        'directions'        => 0.005,
        'distance_matrix'   => 0.005,
        'street_view'       => 0.007,
    ];

    public static function costFor(string $apiName, int $count = 1): float
    {
        $unit = self::COSTS[$apiName] ?? 0.0;
        return round($unit * $count, 6);
    }

    public static function format(float $cost): string
    {
        if ($cost <= 0) return '$0.00';
        if ($cost < 0.01) return '<$0.01';
        return '$' . number_format($cost, 2);
    }
}
