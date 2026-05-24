<?php
declare(strict_types=1);

namespace App\Services;

/**
 * #3 — Foot-traffic layer (v1 skeleton).
 *
 * Pulls visit counts per area from a third-party movement provider
 * (SafeGraph / Placer.ai / Foursquare). Switched on by setting
 * `FOOT_TRAFFIC_PROVIDER` + `FOOT_TRAFFIC_API_KEY` in .env. When unset
 * (the default), the service short-circuits and returns null — the UI
 * shows a "data not configured" empty state instead of an error.
 *
 * Wire-up plan:
 *   1. Implement provider drivers (SafeGraphDriver, PlacerDriver,
 *      FoursquareDriver) — each one a thin HTTP wrapper.
 *   2. Cache results in cache table keyed on area_id + month.
 *   3. Add /api/areas/{id}/foot-traffic endpoint that calls into here.
 */
class FootTrafficService
{
    public static function provider(): ?string
    {
        $p = Config::get('FOOT_TRAFFIC_PROVIDER');
        return $p ?: null;
    }

    /**
     * Returns a 12-month visit-count series for an area, or null if no
     * provider is configured. Real implementation will dispatch to a
     * provider-specific driver.
     */
    public function visitsForArea(string $areaId): ?array
    {
        if (!self::provider()) return null;
        // TODO — wire the actual driver based on provider key.
        // For now, return an empty stub so the UI knows the provider IS
        // configured but no data has loaded yet (vs the "not configured"
        // null case above).
        return [
            'months' => [],
            'total_visits' => 0,
            'provider' => self::provider(),
            'note' => 'Driver not yet implemented — provider configured but no data fetched',
        ];
    }
}
