<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Contracts;

/**
 * Drive legs between points (04_BACKEND.md 5.3).
 *
 * Resolved with Registry::legs(): the routing service when it is installed, else the fallback, which
 * answers every leg as a labelled straight-line estimate. A leg Google could not supply is never an
 * error: it comes back as a `straight_line` leg with its `fallback_reason`.
 *
 * `$truck` is the truck value of TruckBaseController::truck() (its routing options are in `profile`).
 *
 *     DriveLeg = { from_id, to_id,
 *                  source: "google_routes"|"google_distance_matrix"|"straight_line"|"same_point",
 *                  fetched_on: string?, age_days: int?, distance_m: float, duration_s: float,
 *                  toll_state: "not_asked"|"none"|"estimate"|"unknown", google_toll: float?,
 *                  toll_source: "owner"|"google"|"none",
 *                  override: { id, minutes: int?, toll: float?, note }?,
 *                  fallback_reason: null|"no_key"|"refused"|"quota"|"budget"|"rate"|"timeout"|"upstream"
 *                                   |"route_not_found"|"cache_only",
 *                  leg_input: LegInput }
 *     LegInput = { source: "google"|"fallback", distance_m: float, duration_s: float,
 *                  override_minutes: int?, toll: float }          (02_MODEL.md section 3)
 *
 * `leg_input.source` is "google" for the two Google sources and for `same_point`, "fallback" for
 * `straight_line`. `leg_input.toll` is the owner's toll when set, else Google's estimate, else 0.0.
 */
interface LegProvider
{
    /**
     * @param array<string, mixed> $truck
     * @param list<array{id: string, lat: float, lng: float}> $points ids are unique
     * @param list<array{0: string, 1: string}> $pairs directed [from_id, to_id]; both ids are in `$points`
     * @param array{tolls?: bool, fetch?: bool} $options `tolls` (default true) asks for toll estimates;
     *                                                   `fetch` false (default true) serves the cache only
     * @return list<array<string, mixed>> one DriveLeg per pair, in pair order
     */
    public function legs(string $orgId, array $truck, array $points, array $pairs, array $options = []): array;

    /**
     * @return array{state: "ok"|"no_key"|"refused"|"backoff"}
     */
    public function status(): array;

    /**
     * Re-keys the owner's corrections of a point that moved a short way, so they follow the pin.
     *
     * @param array<string, mixed> $truck
     * @param array{lat: float, lng: float} $oldPoint
     * @param array{lat: float, lng: float} $newPoint
     */
    public function movePoint(string $orgId, array $truck, array $oldPoint, array $newPoint): void;
}
