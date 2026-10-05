<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;

/**
 * Builds `A`, the Assumptions record every model function takes (02_MODEL.md section 3), for a truck:
 * the seed file, the owner's overrides and the region block.
 *
 * This is the only place that builds `A` for a truck. Code that reads only build- or fixed-scope seeds
 * (capture, the region loader, fallback legs) uses Seeds::defaults() instead.
 */
class AssumptionsFactory
{
    private const DEFAULT_MATRIX = 'us_mean';

    /**
     * @param array<string, mixed>|null $truck the truck value of TruckBaseController::truck(): its
     *                                         `overrides` and `overrides_seeds_rev` are read. Null: no truck
     * @param array<string, mixed>|null $regionRow the truck's region as RegionRepository::find() returns
     *                                             it. Null: region `none`
     * @return array<string, mixed> Assumptions
     */
    public function forTruck(?array $truck, ?array $regionRow): array
    {
        $A = Seeds::defaults();
        if ($regionRow !== null) {
            $A['region'] = $this->regionBlock($A, $regionRow);
        }
        if ($truck !== null && is_array($truck['overrides'] ?? null) && $truck['overrides'] !== []) {
            $current = (int) $A['seeds_revision'];
            $stored = isset($truck['overrides_seeds_rev']) ? (int) $truck['overrides_seeds_rev'] : $current;
            $A['overrides'] = $this->usableOverrides($A, $truck['overrides'], $stored !== $current);
        }
        return $A;
    }

    /**
     * `A` without the seed file: what the API sends (AssumptionsInfo). The browser carries the same seeds.
     *
     * @param array<string, mixed> $A
     * @return array{model_version: string, seeds_revision: int, overrides: array<string, mixed>,
     *               region: array{id: string, traffic_matrix: string, flags: array{inauguration_day: bool}}}
     */
    public function info(array $A): array
    {
        return [
            'model_version' => (string) $A['model_version'],
            'seeds_revision' => (int) $A['seeds_revision'],
            'overrides' => $A['overrides'],
            'region' => [
                'id' => (string) $A['region']['id'],
                'traffic_matrix' => (string) $A['region']['traffic_matrix'],
                'flags' => ['inauguration_day' => (bool) $A['region']['flags']['inauguration_day']],
            ],
        ];
    }

    /**
     * The region block of `A`. The traffic matrix is the one the region definition names, or `us_mean`
     * when it names none (or one the seed file does not hold). It is never derived from the region id.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $regionRow
     * @return array{id: string, traffic_matrix: string, flags: array{inauguration_day: bool}}
     */
    private function regionBlock(array $A, array $regionRow): array
    {
        $config = is_array($regionRow['config'] ?? null) ? $regionRow['config'] : [];
        $matrix = self::DEFAULT_MATRIX;
        $named = $config['traffic_matrix'] ?? null;
        if (is_string($named) && $named !== '') {
            $traffic = $A['seeds']['traffic'] ?? [];
            if (is_array($traffic) && isset($traffic[$named], $traffic[$named . '_typical'])) {
                $matrix = $named;
            }
        }
        return [
            'id' => (string) $regionRow['region_id'],
            'traffic_matrix' => $matrix,
            'flags' => ['inauguration_day' => ($config['holidays']['inauguration_day'] ?? false) === true],
        ];
    }

    /**
     * The stored overrides as the model may read them. Overrides are validated when they are saved; a map
     * saved under another seeds revision is validated again, and a path that no longer validates is left
     * out (the stored map stays as it is until the owner saves again). Numbers take the type of the seed
     * they replace: JSON text has one kind of number, and a browser writes 1.0 as 1.
     *
     * @param array<string, mixed> $A the Assumptions record without overrides
     * @param array<int|string, mixed> $stored
     * @return array<string, mixed>
     */
    private function usableOverrides(array $A, array $stored, bool $otherRevision): array
    {
        $overrides = [];
        foreach ($stored as $path => $value) {
            $overrides[(string) $path] = $value;
        }
        if ($otherRevision) {
            $dropped = 0;
            foreach (Estimator::validateOverrides($A['seeds'], $overrides) as $problem) {
                unset($overrides[$problem['path']]);
                $dropped++;
            }
            if ($dropped > 0) {
                error_log('[tp] ' . $dropped . ' stored overrides do not fit seeds revision ' . (int) $A['seeds_revision'] . ' and were left out');
            }
        }
        foreach ($overrides as $path => $value) {
            try {
                $seed = Estimator::seed($A, $path);
            } catch (\OutOfBoundsException | \TypeError $e) {
                continue;                     // not a seed of this revision: the model never reads it
            }
            $overrides[$path] = self::typedLike($value, $seed);
        }
        return $overrides;
    }

    private static function typedLike(mixed $value, mixed $seed): mixed
    {
        if (is_float($seed) && is_int($value)) {
            return (float) $value;
        }
        if (is_array($seed) && is_array($value)) {
            foreach ($value as $i => $item) {
                if (array_key_exists($i, $seed)) {
                    $value[$i] = self::typedLike($item, $seed[$i]);
                }
            }
        }
        return $value;
    }
}
