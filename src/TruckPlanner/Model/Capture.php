<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * The location vectors of a point and the host term (02_MODEL.md 4.4, 4.5).
 *
 * Share is an origin-based gravity model with an outside option: for people at a source point the truck
 * wins f(d) * V / (A0 + f(d) * V + rivals of that point).
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Capture
{
    /**
     * The items in ascending byte-wise order of one string field (1.5). Items with the same value keep the
     * order they came in.
     *
     * @param array<int|string, array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function orderBy(array $items, string $field): array
    {
        $values = [];
        foreach ($items as $k => $item) {
            $values[$k] = (string) $item[$field];
        }
        asort($values, SORT_STRING);
        $out = [];
        foreach ($values as $k => $unused) {
            $out[] = $items[$k];
        }
        return $out;
    }

    /**
     * The pull of food outlets on people standing at (lat, lng), per competition regime.
     *
     * @param array<string, mixed> $A
     * @param array<int|string, array<string, mixed>> $outlets
     * @return array{day: float, eve: float}
     */
    public static function rivalsAtOrigin(array $A, float $lat, float $lng, array $outlets): array
    {
        $day = 0.0;
        $eve = 0.0;
        if ($outlets === []) {
            return ['day' => $day, 'eve' => $eve];
        }
        $cutoff = Num::f(Seeds::read($A, 'kernel.walk_cutoff_m'));
        $decay = Num::f(Seeds::read($A, 'kernel.walk_decay_m'));
        $weights = [];
        foreach (self::orderBy($outlets, 'id') as $o) {
            $d = Geometry::haversineM($lat, $lng, Num::f($o['lat']), Num::f($o['lng']));
            $f = ($d < 0 || $d > $cutoff) ? 0.0 : exp(-$d / $decay);
            if ($f == 0.0) {
                continue;
            }
            $kind = $o['kind'];
            if (!isset($weights[$kind])) {
                $w = Seeds::read($A, 'kernel.rival_weight.' . $kind);
                $weights[$kind] = [Num::f($w['day']), Num::f($w['eve'])];
            }
            $day += $weights[$kind][0] * $f;
            $eve += $weights[$kind][1] * $f;
        }
        return ['day' => $day, 'eve' => $eve];
    }

    /**
     * What a declared host removes from the catchment so that its people are not counted twice: (1) the
     * linked place's own source point; (2) for workers and residents, up to host.size units of the same
     * segment from the nearest points. The removed people come back through the host term.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed>|null $host
     * @return array{point_ids: list<string>, segment: ?string, amount: float}
     */
    public static function hostExclusion(array $A, ?array $host): array
    {
        if ($host === null) {
            return ['point_ids' => [], 'segment' => null, 'amount' => 0.0];
        }
        $ids = ($host['point_id'] ?? null) !== null ? [$host['point_id']] : [];
        $group = self::segmentSeed($A, $host['segment'])['group'];
        if ($group === 'workers' || $group === 'residents') {
            return ['point_ids' => $ids, 'segment' => $host['segment'], 'amount' => Num::f($host['size'])];
        }
        return ['point_ids' => $ids, 'segment' => null, 'amount' => 0.0];
    }

    /**
     * For a venue host that carries no link: the nearest source point holding the host's segment within
     * host.venue_link_radius_m (nearest by whole millimetres, ties to the smaller id), or null.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $host
     * @param array<int|string, array<string, mixed>> $sources
     */
    public static function hostLinkPoint(array $A, float $lat, float $lng, array $host, array $sources): ?string
    {
        $si = Vocab::segmentIndex($host['segment']);
        $bestId = null;
        $bestKey = 0.0;
        if ($sources === []) {
            return null;
        }
        $radius = Num::f(Seeds::read($A, 'host.venue_link_radius_m'));
        foreach (self::orderBy($sources, 'id') as $c) {
            if (Num::f($c['base'][$si]) <= 0) {
                continue;
            }
            $d = Geometry::haversineM($lat, $lng, Num::f($c['lat']), Num::f($c['lng']));
            if ($d > $radius) {
                continue;
            }
            $key = floor($d * 1000.0 + 0.5);
            if ($bestId === null || $key < $bestKey) {
                $bestId = (string) $c['id'];
                $bestKey = $key;
            }
        }
        return $bestId;
    }

    /**
     * The location vectors of a truck parked at (lat, lng).
     *
     *   capture[regime][s]  units of segment-s base the truck would win per unit of presence and intent
     *   nearby[s]           distance-weighted base within walking distance
     *   within[s]           base within the cutoff, after exclusion, not distance-weighted
     *   rivals[regime]      pull of food outlets at the truck's own position
     *
     * The backend sets in_region, region_id and dataset_version on what it returns.
     *
     * @param array<string, mixed> $A
     * @param array<int|string, array<string, mixed>> $sources
     * @param array<int|string, array<string, mixed>> $outlets
     * @param array<string, mixed> $exclusion
     * @return array<string, mixed> LocationVectors
     */
    public static function captureAtPoint(
        array $A,
        float $lat,
        float $lng,
        string $visibility,
        array $sources,
        array $outlets,
        array $exclusion
    ): array {
        $V = Num::f(Seeds::read($A, 'kernel.visibility.' . $visibility));
        $A0 = Num::f(Seeds::read($A, 'kernel.outside_option_a0'));
        $cutoff = Num::f(Seeds::read($A, 'kernel.walk_cutoff_m'));
        $decay = Num::f(Seeds::read($A, 'kernel.walk_decay_m'));

        $excluded = [];
        foreach ($exclusion['point_ids'] as $id) {
            $excluded[(string) $id] = true;
        }

        $rows = [];
        foreach (self::orderBy($sources, 'id') as $c) {
            $id = (string) $c['id'];
            if (isset($excluded[$id])) {
                continue;
            }
            $d = Geometry::haversineM($lat, $lng, Num::f($c['lat']), Num::f($c['lng']));
            $f = ($d < 0 || $d > $cutoff) ? 0.0 : exp(-$d / $decay);
            if ($f == 0.0) {
                continue;
            }
            $rows[] = ['id' => $id, 'd' => $d, 'f' => $f, 'base' => Num::perSegment($c['base']), 'rivals' => $c['rivals']];
        }

        $taken = 0.0;
        if ($exclusion['segment'] !== null && Num::f($exclusion['amount']) > 0) {
            $si = Vocab::segmentIndex($exclusion['segment']);
            $remaining = Num::f($exclusion['amount']);
            $radius = Num::f(Seeds::read($A, 'host.exclusion_radius_m'));
            $near = [];
            foreach ($rows as $k => $r) {
                if ($r['d'] <= $radius) {
                    $near[] = [floor($r['d'] * 1000.0 + 0.5), $r['id'], $k];
                }
            }
            usort(                                        // nearest first, ties by id
                $near,
                static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: (strcmp($a[1], $b[1]) ?: ($a[2] <=> $b[2]))
            );
            foreach ($near as $item) {
                if ($remaining <= 0) {
                    break;
                }
                $k = $item[2];
                $have = $rows[$k]['base'][$si];
                $take = $remaining < $have ? $remaining : $have;
                $rows[$k]['base'][$si] = $have - $take;
                $remaining -= $take;
                $taken += $take;
            }
        }

        $captureDay = array_fill(0, Vocab::NSEG, 0.0);
        $captureEve = array_fill(0, Vocab::NSEG, 0.0);
        $nearby = array_fill(0, Vocab::NSEG, 0.0);
        $within = array_fill(0, Vocab::NSEG, 0.0);
        foreach ($rows as $r) {                           // still ascending id
            $f = $r['f'];
            $base = $r['base'];
            $shareDay = $f * $V / ($A0 + $f * $V + Num::f($r['rivals']['day']));
            $shareEve = $f * $V / ($A0 + $f * $V + Num::f($r['rivals']['eve']));
            for ($s = 0; $s < Vocab::NSEG; $s++) {
                $b = $base[$s];
                $captureDay[$s] += $b * $shareDay;
                $captureEve[$s] += $b * $shareEve;
                $nearby[$s] += $b * $f;
                $within[$s] += $b;
            }
        }

        return [
            'capture' => ['day' => $captureDay, 'eve' => $captureEve],
            'nearby' => $nearby,
            'within' => $within,
            'rivals' => self::rivalsAtOrigin($A, $lat, $lng, $outlets),
            'visibility' => $visibility,
            'in_region' => true,
            'region_id' => null,
            'exclusion' => $exclusion,
            'excluded_amount' => $taken,
            'points_used' => count($rows),
            'dataset_version' => null,
            'model_version' => Vocab::MODEL_VERSION,
        ];
    }

    /**
     * The host's own people the truck would win per unit of presence and intent, per regime.
     *
     * captive (v_nightlife, v_events): people inside a venue; a flat share, the truck being the only food
     * or not. open (everything else): the host's people are a source at distance zero, with the on-site
     * kitchen (if any) as an extra rival of weight K.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed>|null $host
     * @param array<string, mixed> $rivalsHere { day, eve }
     * @return array{day: float, eve: float, share: array{day: float, eve: float}, mode: ?string}
     */
    public static function hostCapture(array $A, ?array $host, string $visibility, array $rivalsHere): array
    {
        if ($host === null || Num::f($host['size']) <= 0) {
            return ['day' => 0.0, 'eve' => 0.0, 'share' => ['day' => 0.0, 'eve' => 0.0], 'mode' => null];
        }
        $size = Num::f($host['size']);
        $mode = self::segmentSeed($A, $host['segment'])['host_mode'];
        if ($mode === 'captive') {
            $sh = $host['only_food']
                ? Num::f(Seeds::read($A, 'host.captive_share'))
                : Num::f(Seeds::read($A, 'host.shared_kitchen_share'));
            $shareDay = $sh;
            $shareEve = $sh;
        } else {
            $V = Num::f(Seeds::read($A, 'kernel.visibility.' . $visibility));
            $A0 = Num::f(Seeds::read($A, 'kernel.outside_option_a0'));
            $K = $host['only_food'] ? 0.0 : Num::f(Seeds::read($A, 'host.onsite_kitchen_weight'));
            $shareDay = $V / ($A0 + $V + Num::f($rivalsHere['day']) + $K);
            $shareEve = $V / ($A0 + $V + Num::f($rivalsHere['eve']) + $K);
        }
        return [
            'day' => $size * $shareDay,
            'eve' => $size * $shareEve,
            'share' => ['day' => $shareDay, 'eve' => $shareEve],
            'mode' => $mode,
        ];
    }

    /**
     * Were these vectors built for these terms (same visibility, same host exclusion)?
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $terms
     * @param array<string, mixed> $vectors
     */
    public static function vectorsMatch(array $A, array $terms, array $vectors): bool
    {
        $e = self::hostExclusion($A, $terms['host']);
        $x = $vectors['exclusion'];
        return $vectors['visibility'] === $terms['visibility']
            && array_values($x['point_ids']) === array_values($e['point_ids'])       // the same ids in the same order
            && $x['segment'] === $e['segment']
            && Num::f($x['amount']) == $e['amount'];
    }

    /**
     * The structural record of a segment in the seed file (group, host_mode, weak): read straight from the
     * file, never through an override.
     *
     * @param array<string, mixed> $A
     * @return array<string, mixed>
     */
    public static function segmentSeed(array $A, mixed $segment): array
    {
        if (!is_string($segment) || !isset($A['seeds']['segments'][$segment])) {
            throw new \OutOfBoundsException('unknown segment');
        }
        return $A['seeds']['segments'][$segment];
    }
}
