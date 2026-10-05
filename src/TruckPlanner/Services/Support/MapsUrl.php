<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

use App\TruckPlanner\Model\Estimator;

/**
 * Free Google Maps links ("Open in Google Maps"). These are plain URLs for the owner to follow: no API is
 * called and no key is used. Coordinates are printed with exactly six decimals.
 */
final class MapsUrl
{
    private const SEARCH = 'https://www.google.com/maps/search/?api=1';
    private const DIRECTIONS = 'https://www.google.com/maps/dir/?api=1';

    /** A pin at a point: ...&query=<lat>%2C<lng> */
    public static function point(float $lat, float $lng): string
    {
        return self::SEARCH . '&query=' . self::pair($lat, $lng);
    }

    /** A place Google knows by id: ...&query=<name>&query_place_id=<id> */
    public static function place(string $name, string $googlePlaceId): string
    {
        return self::SEARCH . '&query=' . rawurlencode($name) . '&query_place_id=' . rawurlencode($googlePlaceId);
    }

    /**
     * The route of a day: `$points` is the base, the stops in visiting order, then the base again, each
     * {lat, lng}. Origin and destination are both given, so the link never asks for the device's position.
     * The stops travel as waypoints. A plan without stops has no route link: the caller answers null then.
     *
     * @param list<array{lat: float|int, lng: float|int}> $points
     */
    public static function route(array $points): string
    {
        $count = count($points);
        if ($count < 2) {
            throw new \LogicException('MapsUrl::route needs the base, the stops and the base again');
        }
        $first = $points[0];
        $last = $points[$count - 1];
        $url = self::DIRECTIONS
            . '&origin=' . self::pair((float) $first['lat'], (float) $first['lng'])
            . '&destination=' . self::pair((float) $last['lat'], (float) $last['lng']);
        $stops = [];
        for ($i = 1; $i < $count - 1; $i++) {
            $stops[] = self::pair((float) $points[$i]['lat'], (float) $points[$i]['lng']);
        }
        if ($stops !== []) {
            $url .= '&waypoints=' . implode('%7C', $stops);
        }
        return $url . '&travelmode=driving';
    }

    /** "<lat>%2C<lng>" with six decimals each. */
    private static function pair(float $lat, float $lng): string
    {
        return self::six($lat) . '%2C' . self::six($lng);
    }

    private static function six(float $x): string
    {
        // %F never uses the locale's decimal sign. The value is already rounded, so no second rounding happens.
        return sprintf('%.6F', Estimator::roundHalfAway($x, 6));
    }
}
