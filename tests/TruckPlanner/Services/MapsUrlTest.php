<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Services\Support\MapsUrl;
use PHPUnit\Framework\TestCase;

/**
 * The three link shapes of 04_BACKEND.md 2.3.
 */
final class MapsUrlTest extends TestCase
{
    public function testPoint(): void
    {
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=38.960000%2C-77.360000',
            MapsUrl::point(38.96, -77.36)
        );
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=39.010000%2C-77.410000',
            MapsUrl::point(39.01, -77.41)
        );
    }

    public function testCoordinatesHaveExactlySixDecimals(): void
    {
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=38.945512%2C-77.451672',
            MapsUrl::point(38.9455121, -77.4516722)
        );
        // half away from zero at the seventh decimal, never negative zero, never an exponent
        self::assertStringEndsWith('query=0.000001%2C-0.000001', MapsUrl::point(0.0000005, -0.0000005));
        self::assertStringEndsWith('query=0.000000%2C0.000000', MapsUrl::point(-0.0000001, 0.0000001));
        self::assertStringEndsWith('query=-90.000000%2C180.000000', MapsUrl::point(-90, 180));
    }

    public function testPlace(): void
    {
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=Example%20Brewing&query_place_id=ChIJN1t_tDeuEmsRUsoyG83frY4',
            MapsUrl::place('Example Brewing', 'ChIJN1t_tDeuEmsRUsoyG83frY4')
        );
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=Caf%C3%A9%20%26%20Bar%20%231%3F&query_place_id=ChIJ-abc_123',
            MapsUrl::place('Café & Bar #1?', 'ChIJ-abc_123')
        );
    }

    public function testRouteStartsAndEndsAtTheBaseWithTheStopsAsWaypoints(): void
    {
        $base = ['lat' => 39.003, 'lng' => -77.405];
        $url = MapsUrl::route([$base, ['lat' => 38.96, 'lng' => -77.36], ['lat' => 39.01, 'lng' => -77.41], $base]);
        self::assertSame(
            'https://www.google.com/maps/dir/?api=1&origin=39.003000%2C-77.405000&destination=39.003000%2C-77.405000'
            . '&waypoints=38.960000%2C-77.360000%7C39.010000%2C-77.410000&travelmode=driving',
            $url
        );
    }

    public function testRouteWithOneStop(): void
    {
        $base = ['lat' => 39.003, 'lng' => -77.405];
        self::assertSame(
            'https://www.google.com/maps/dir/?api=1&origin=39.003000%2C-77.405000&destination=39.003000%2C-77.405000'
            . '&waypoints=38.960000%2C-77.360000&travelmode=driving',
            MapsUrl::route([$base, ['lat' => 38.96, 'lng' => -77.36], $base])
        );
    }

    public function testRouteAlwaysNamesItsOriginSoNoDevicePositionIsAsked(): void
    {
        $base = ['lat' => 39.003, 'lng' => -77.405];
        $url = MapsUrl::route([$base, $base]);
        self::assertSame(
            'https://www.google.com/maps/dir/?api=1&origin=39.003000%2C-77.405000&destination=39.003000%2C-77.405000&travelmode=driving',
            $url
        );
        self::assertStringNotContainsString('waypoints', $url);
    }

    public function testRouteNeedsABase(): void
    {
        $this->expectException(\LogicException::class);
        MapsUrl::route([['lat' => 39.003, 'lng' => -77.405]]);
    }

    public function testLinksAreKeylessGoogleMapsUrls(): void
    {
        foreach ([MapsUrl::point(1.0, 2.0), MapsUrl::place('x', 'y'), MapsUrl::route([['lat' => 1, 'lng' => 2], ['lat' => 1, 'lng' => 2]])] as $url) {
            self::assertStringStartsWith('https://www.google.com/maps/', $url);
            self::assertStringNotContainsString('key=', $url);
            self::assertSame($url, filter_var($url, FILTER_VALIDATE_URL));
        }
    }
}
