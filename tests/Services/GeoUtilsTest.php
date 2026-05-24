<?php
namespace App\Tests\Services;

use App\Services\GeoUtils;
use PHPUnit\Framework\TestCase;

class GeoUtilsTest extends TestCase
{
    public function testWktRoundTripPolygon(): void
    {
        $poly = ['type' => 'Polygon', 'coordinates' => [[
            [-77.1, 38.9], [-77.0, 38.9], [-77.0, 39.0], [-77.1, 39.0], [-77.1, 38.9],
        ]]];
        $wkt = GeoUtils::geoJsonToWkt($poly);
        $this->assertStringStartsWith('POLYGON(', $wkt);
        $this->assertStringContainsString('-77.1000000 38.9000000', $wkt);
    }

    public function testPointInPolygonBasic(): void
    {
        $square = ['type' => 'Polygon', 'coordinates' => [[
            [0, 0], [10, 0], [10, 10], [0, 10], [0, 0],
        ]]];
        $this->assertTrue(GeoUtils::pointInPolygon(5, 5, $square));
        $this->assertFalse(GeoUtils::pointInPolygon(11, 11, $square));
        $this->assertFalse(GeoUtils::pointInPolygon(-1, 5, $square));
    }

    public function testCirclePolygonShape(): void
    {
        $circle = GeoUtils::generateCirclePolygon(38.9, -77.0, 5); // 5km
        $this->assertEquals('Polygon', $circle['type']);
        // 64 segments + closing point = 65
        $this->assertCount(65, $circle['coordinates'][0]);
    }

    public function testCalculateAreaRoughlyAccurate(): void
    {
        // 5km radius circle ≈ π·25 = ~78.5 km².
        $circle = GeoUtils::generateCirclePolygon(38.9, -77.0, 5);
        $area = GeoUtils::calculateArea($circle);
        $this->assertGreaterThan(70, $area);
        $this->assertLessThan(85, $area);
    }

    public function testSwapGeoJsonCoordsInverts(): void
    {
        $swapped = GeoUtils::swapGeoJsonCoords([38.9, -77.0]);
        $this->assertSame([-77.0, 38.9], $swapped);
    }

    public function testBoundingBox(): void
    {
        $poly = ['type' => 'Polygon', 'coordinates' => [[
            [-1, 2], [3, 2], [3, 5], [-1, 5], [-1, 2],
        ]]];
        $bbox = GeoUtils::getBoundingBox($poly);
        $this->assertSame([-1.0, 2.0, 3.0, 5.0], $bbox);
    }
}
