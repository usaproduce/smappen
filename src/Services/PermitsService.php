<?php
declare(strict_types=1);

namespace App\Services;

/**
 * #4 — Building-permits + housing-starts overlay (v1 skeleton).
 *
 * County-level building-department data is fragmented across thousands of
 * jurisdictions. Three paths to wire up:
 *
 *   • HUD State of the Cities Data Systems (SOCDS) — annual, free, broad
 *     coverage but lagged ~18 months.
 *   • Cherre / RealtyMole / ATTOM — commercial APIs, real-time, $$$.
 *   • Direct municipal scrapes — labor-intensive; one-offs per metro.
 *
 * v1 ships the table + service shell. Drivers slot in like the
 * foot-traffic service.
 */
class PermitsService
{
    public static function provider(): ?string
    {
        $p = Config::get('PERMITS_PROVIDER');
        return $p ?: null;
    }

    /**
     * Returns permits issued inside an area's polygon over the trailing
     * 12 months, grouped by month + permit type. null if not configured.
     */
    public function permitsForArea(string $areaId): ?array
    {
        if (!self::provider()) return null;
        return [
            'months'       => [],
            'total_permits'=> 0,
            'by_type'      => [],
            'provider'     => self::provider(),
            'note'         => 'Driver not yet implemented',
        ];
    }
}
