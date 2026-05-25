<?php
declare(strict_types=1);

namespace App\Services;

use App\PrivateData\MenuItemRepository;
use App\PrivateData\PlateCostRepository;
use App\PrivateData\PlansSandboxRepository;
use App\PrivateData\PosSalesRepository;

/**
 * Planning sandbox — model a menu change or new-location scenario before
 * committing. Phase 1 ships the math; richer site-side composition (map
 * stack reuse) lands in a follow-up.
 *
 *   - kind=menu_change   → payload.changes = [{menu_item_id, new_price_cents}, ...]
 *                          projected: per-item current_margin vs proposed_margin,
 *                          monthly delta using PMIX volume.
 *   - kind=new_location  → payload.candidate_lat/lng/region; projected.estimated_first_year
 *                          left as a stub for now (needs the map/demographics stack).
 */
class PlanningService
{
    public function __construct(
        private MenuItemRepository $items,
        private PlateCostRepository $plateCosts,
        private PosSalesRepository $sales,
        private PlansSandboxRepository $sandbox,
    ) {}

    public function compute(string $sandboxId, string $organizationId): ?array
    {
        $row = $this->sandbox->findById($sandboxId, $organizationId);
        if (!$row) return null;
        $payload = is_string($row['payload']) ? json_decode($row['payload'], true) : ($row['payload'] ?? []);
        $kind = (string) $row['kind'];

        $projected = match ($kind) {
            'menu_change'  => $this->computeMenuChange($payload, $organizationId, $row['restaurant_id'] ?? null),
            'new_location' => $this->computeNewLocation($payload),
            default        => ['note' => 'unknown kind'],
        };
        $this->sandbox->setProjected($sandboxId, $projected);
        return $projected;
    }

    private function computeMenuChange(array $payload, string $organizationId, ?string $restaurantId): array
    {
        if (!$restaurantId) return ['note' => 'menu_change requires restaurant_id'];

        $volume = $this->sales->monthlyVolumeByItem($restaurantId);
        $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : [];
        $perItem = [];
        $totalDelta = 0;

        foreach ($changes as $ch) {
            $itemId = (string) ($ch['menu_item_id'] ?? '');
            $newPrice = (int) ($ch['new_price_cents'] ?? 0);
            if ($itemId === '' || $newPrice <= 0) continue;
            $item = $this->items->findById($itemId, $organizationId);
            if (!$item) continue;
            $pc = $this->plateCosts->findByMenuItem($itemId);
            if (!$pc) continue;
            $cost = (int) $pc['true_cost_cents'];
            $cur  = (int) $item['price_cents'];
            $curMargin = $cur - $cost;
            $newMargin = $newPrice - $cost;
            $vol = $volume[$itemId] ?? 30; // fallback to 1/day estimate
            $delta = ($newMargin - $curMargin) * $vol;
            $totalDelta += $delta;
            $perItem[] = [
                'menu_item_id'           => $itemId,
                'name'                   => $item['name'],
                'current_price_cents'    => $cur,
                'new_price_cents'        => $newPrice,
                'current_margin_cents'   => $curMargin,
                'new_margin_cents'       => $newMargin,
                'est_monthly_volume'     => $vol,
                'monthly_delta_cents'    => $delta,
            ];
        }

        return [
            'kind'                  => 'menu_change',
            'per_item'              => $perItem,
            'total_monthly_delta_cents' => $totalDelta,
            'note'                  => 'Volume estimates use trailing 90-day PMIX where available.',
        ];
    }

    private function computeNewLocation(array $payload): array
    {
        // Phase 1 stub. Real composition lands when the planning page
        // wires up the map/area/demographics/isochrone stack (spec §5.9).
        $lat = $payload['candidate_lat'] ?? null;
        $lng = $payload['candidate_lng'] ?? null;
        return [
            'kind'   => 'new_location',
            'candidate' => ['lat' => $lat, 'lng' => $lng, 'region' => $payload['region'] ?? null],
            'projected_first_year_revenue_cents' => null,
            'note'   => 'Phase 1: scenario saved. Full new-location projection wires up to the map stack in a follow-up.',
        ];
    }
}
