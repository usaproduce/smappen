<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * Presence and intent curves by clock hour (02_MODEL.md 4.2).
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Curves
{
    /**
     * presence[s][h] and intent[s][h] for the 24 clock hours of one context. The Monday-Friday factor
     * multiplies presence only. The result depends on the seeds, the overrides and two context arrays and on
     * nothing else, so within one call it is computed once per such combination.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $ctx
     * @return array{presence: list<list<float>>, intent: list<list<float>>}
     */
    public static function hourWeights(array $A, array $ctx, ?Memo $memo = null): array
    {
        $key = null;
        if ($memo !== null) {
            $key = implode(',', $ctx['day_type']) . '|' . pack('e*', ...array_values($ctx['dow_factor']));
            if (isset($memo->weights[$key])) {
                return $memo->weights[$key];
            }
        }
        $presence = [];
        $intent = [];
        for ($s = 0; $s < Vocab::NSEG; $s++) {
            $name = Vocab::SEGMENTS[$s];
            $dayType = $ctx['day_type'][$s];
            $p = Seeds::read($A, 'segments.' . $name . '.presence.' . $dayType);
            $q = Seeds::read($A, 'segments.' . $name . '.intent.' . $dayType);
            $factor = Num::f($ctx['dow_factor'][$s]);
            $presenceRow = [];
            $intentRow = [];
            for ($h = 0; $h < 24; $h++) {
                $presenceRow[] = Num::f($p[$h]) * $factor;        // an overridden curve is checked like any input
                $intentRow[] = Num::f($q[$h]);
            }
            $presence[] = $presenceRow;
            $intent[] = $intentRow;
        }
        $w = ['presence' => $presence, 'intent' => $intent];
        if ($memo !== null) {
            $memo->weights[$key] = $w;
        }
        return $w;
    }

    /**
     * presence[s] and intent[s] of one clock hour: one column of hourWeights. Without a memo only that
     * column is computed, by the same single multiplication per segment.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $ctx
     * @return array{0: list<float>, 1: list<float>} [presence x16, intent x16]
     */
    public static function hourColumn(array $A, array $ctx, int $hour, ?Memo $memo = null): array
    {
        $presence = [];
        $intent = [];
        if ($memo !== null) {
            $w = self::hourWeights($A, $ctx, $memo);
            for ($s = 0; $s < Vocab::NSEG; $s++) {
                $presence[] = $w['presence'][$s][$hour];
                $intent[] = $w['intent'][$s][$hour];
            }
            return [$presence, $intent];
        }
        for ($s = 0; $s < Vocab::NSEG; $s++) {
            $name = Vocab::SEGMENTS[$s];
            $dayType = $ctx['day_type'][$s];
            $presence[] = Num::f(Seeds::read($A, 'segments.' . $name . '.presence.' . $dayType)[$hour]) * Num::f($ctx['dow_factor'][$s]);
            $intent[] = Num::f(Seeds::read($A, 'segments.' . $name . '.intent.' . $dayType)[$hour]);
        }
        return [$presence, $intent];
    }

    /**
     * A typical week: presence[s][how] and intent[s][how] for how = dow * 24 + hour.
     *
     * @param array<string, mixed> $A
     * @return array{presence: list<list<float>>, intent: list<list<float>>}
     */
    public static function expandCurves(array $A, ?Memo $memo = null): array
    {
        $memo ??= new Memo();
        $presence = array_fill(0, Vocab::NSEG, array_fill(0, 168, 0.0));
        $intent = array_fill(0, Vocab::NSEG, array_fill(0, 168, 0.0));
        for ($dow = 0; $dow < 7; $dow++) {
            $w = self::hourWeights($A, Contexts::typicalContext($A, $dow), $memo);
            for ($s = 0; $s < Vocab::NSEG; $s++) {
                for ($h = 0; $h < 24; $h++) {
                    $presence[$s][$dow * 24 + $h] = $w['presence'][$s][$h];
                    $intent[$s][$dow * 24 + $h] = $w['intent'][$s][$h];
                }
            }
        }
        return ['presence' => $presence, 'intent' => $intent];
    }
}
