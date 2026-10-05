<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Fallback;

use App\TruckPlanner\Data\LegKey;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\LegProvider;

/**
 * The leg provider while the routing service is not installed. Every leg is the model's straight-line
 * estimate, labelled as such with the reason `no_key`: nothing is asked of Google, nothing is cached and
 * there are no owner corrections to attach.
 *
 * The two builders are public so that the routing service makes the same two kinds of leg the same way.
 */
final class StraightLineLegs implements LegProvider
{
    public const REASON = 'no_key';

    public function legs(string $orgId, array $truck, array $points, array $pairs, array $options = []): array
    {
        $byId = [];
        foreach ($points as $point) {
            $byId[(string) $point['id']] = $point;
        }
        $legs = [];
        foreach ($pairs as $pair) {
            $fromId = (string) $pair[0];
            $toId = (string) $pair[1];
            if (!isset($byId[$fromId], $byId[$toId])) {
                throw new \LogicException('a leg names a point that was not given');
            }
            $from = $byId[$fromId];
            $to = $byId[$toId];
            $sameKey = LegKey::of((float) $from['lat'], (float) $from['lng'])
                === LegKey::of((float) $to['lat'], (float) $to['lng']);
            $legs[] = $sameKey
                ? self::samePoint($fromId, $toId)
                : self::straightLine($fromId, $toId, $from, $to, self::REASON);
        }
        return $legs;
    }

    public function status(): array
    {
        return ['state' => self::REASON];
    }

    public function movePoint(string $orgId, array $truck, array $oldPoint, array $newPoint): void
    {
        // No corrections exist without the routing service.
    }

    /**
     * A labelled straight-line leg between two exact (unrounded) points: the model's fallback_leg.
     *
     * @param array<string, mixed> $from {lat, lng}
     * @param array<string, mixed> $to {lat, lng}
     * @param string $reason a `fallback_reason` of DriveLeg
     * @return array<string, mixed> DriveLeg
     */
    public static function straightLine(string $fromId, string $toId, array $from, array $to, string $reason): array
    {
        $input = Estimator::fallbackLeg(
            Seeds::defaults(),
            (float) $from['lat'],
            (float) $from['lng'],
            (float) $to['lat'],
            (float) $to['lng']
        );
        return [
            'from_id' => $fromId,
            'to_id' => $toId,
            'source' => 'straight_line',
            'fetched_on' => null,
            'age_days' => null,
            'distance_m' => (float) $input['distance_m'],
            'duration_s' => (float) $input['duration_s'],
            'toll_state' => 'not_asked',
            'google_toll' => null,
            'toll_source' => 'none',
            'override' => null,
            'fallback_reason' => $reason,
            'leg_input' => $input,
        ];
    }

    /**
     * The leg between two points that share a leg key: no distance, no time. It is handed to the model as
     * a routed leg of zero length, so that it raises no fallback warning.
     *
     * @return array<string, mixed> DriveLeg
     */
    public static function samePoint(string $fromId, string $toId): array
    {
        return [
            'from_id' => $fromId,
            'to_id' => $toId,
            'source' => 'same_point',
            'fetched_on' => null,
            'age_days' => null,
            'distance_m' => 0.0,
            'duration_s' => 0.0,
            'toll_state' => 'not_asked',
            'google_toll' => null,
            'toll_source' => 'none',
            'override' => null,
            'fallback_reason' => null,
            'leg_input' => [
                'source' => 'google',
                'distance_m' => 0.0,
                'duration_s' => 0.0,
                'override_minutes' => null,
                'toll' => 0.0,
            ],
        ];
    }
}
