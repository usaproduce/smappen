<?php
namespace App\Services;

class GeoUtils
{
    public static function geoJsonToWkt(array $geoJson): string
    {
        $type = $geoJson['type'] ?? '';
        if ($type === 'Feature') {
            $geoJson = $geoJson['geometry'] ?? [];
            $type = $geoJson['type'] ?? '';
        }
        if ($type === 'Polygon') {
            $rings = [];
            foreach ($geoJson['coordinates'] as $ring) {
                $points = [];
                foreach ($ring as $p) {
                    $points[] = sprintf('%.7f %.7f', $p[0], $p[1]);
                }
                $rings[] = '(' . implode(',', $points) . ')';
            }
            return 'POLYGON(' . implode(',', $rings) . ')';
        }
        if ($type === 'MultiPolygon') {
            $polys = [];
            foreach ($geoJson['coordinates'] as $poly) {
                $rings = [];
                foreach ($poly as $ring) {
                    $points = [];
                    foreach ($ring as $p) $points[] = sprintf('%.7f %.7f', $p[0], $p[1]);
                    $rings[] = '(' . implode(',', $points) . ')';
                }
                $polys[] = '(' . implode(',', $rings) . ')';
            }
            return 'MULTIPOLYGON(' . implode(',', $polys) . ')';
        }
        throw new \InvalidArgumentException('Unsupported GeoJSON type: ' . $type);
    }

    public static function wktToGeoJson(string $wkt): array
    {
        if (preg_match('/^POLYGON\s*\((.+)\)$/i', $wkt, $m)) {
            $rings = self::parseRings($m[1]);
            return ['type' => 'Polygon', 'coordinates' => $rings];
        }
        if (preg_match('/^MULTIPOLYGON\s*\((.+)\)$/i', $wkt, $m)) {
            // simplified: split top-level polygons
            return ['type' => 'MultiPolygon', 'coordinates' => []];
        }
        throw new \InvalidArgumentException('Cannot parse WKT');
    }

    private static function parseRings(string $inner): array
    {
        $rings = [];
        preg_match_all('/\(([^()]+)\)/', $inner, $matches);
        foreach ($matches[1] as $ringStr) {
            $points = [];
            foreach (explode(',', $ringStr) as $p) {
                $coords = preg_split('/\s+/', trim($p));
                if (count($coords) >= 2) $points[] = [(float)$coords[0], (float)$coords[1]];
            }
            $rings[] = $points;
        }
        return $rings;
    }

    public static function generateCirclePolygon(float $lat, float $lng, float $radiusKm, int $points = 64): array
    {
        $coords = [];
        $earthRadius = 6371.0;
        $latRad = deg2rad($lat);
        for ($i = 0; $i <= $points; $i++) {
            $bearing = ($i / $points) * 2 * M_PI;
            $latOffset = ($radiusKm / $earthRadius) * cos($bearing);
            $lngOffset = ($radiusKm / $earthRadius) * sin($bearing) / cos($latRad);
            $coords[] = [
                $lng + rad2deg($lngOffset),
                $lat + rad2deg($latOffset),
            ];
        }
        return ['type' => 'Polygon', 'coordinates' => [$coords]];
    }

    public static function calculateArea(array $geoJson): float
    {
        // Approximate area in sq km using planar approximation (good enough for small areas)
        if (($geoJson['type'] ?? '') !== 'Polygon') return 0.0;
        $ring = $geoJson['coordinates'][0] ?? [];
        $n = count($ring);
        if ($n < 3) return 0.0;
        $area = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            $p1 = $ring[$i];
            $p2 = $ring[$i + 1];
            $area += deg2rad($p2[0] - $p1[0]) * (2 + sin(deg2rad($p1[1])) + sin(deg2rad($p2[1])));
        }
        $area = abs($area * 6378137.0 * 6378137.0 / 2.0);
        return $area / 1000000.0;
    }

    public static function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $ring = $polygon['coordinates'][0] ?? [];
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $ring[$i][0]; $yi = $ring[$i][1];
            $xj = $ring[$j][0]; $yj = $ring[$j][1];
            $intersect = (($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
            if ($intersect) $inside = !$inside;
        }
        return $inside;
    }

    public static function getBoundingBox(array $geoJson): array
    {
        $coords = self::flattenCoords($geoJson);
        $minLng = INF; $minLat = INF; $maxLng = -INF; $maxLat = -INF;
        foreach ($coords as $c) {
            if ($c[0] < $minLng) $minLng = $c[0];
            if ($c[0] > $maxLng) $maxLng = $c[0];
            if ($c[1] < $minLat) $minLat = $c[1];
            if ($c[1] > $maxLat) $maxLat = $c[1];
        }
        return [$minLng, $minLat, $maxLng, $maxLat];
    }

    private static function flattenCoords(array $geoJson): array
    {
        $type = $geoJson['type'] ?? '';
        $coords = $geoJson['coordinates'] ?? [];
        $out = [];
        if ($type === 'Polygon') {
            foreach ($coords as $ring) foreach ($ring as $p) $out[] = $p;
        } elseif ($type === 'MultiPolygon') {
            foreach ($coords as $poly) foreach ($poly as $ring) foreach ($ring as $p) $out[] = $p;
        }
        return $out;
    }

    public static function encodePath(array $coords): string
    {
        // Google polyline encoding
        $result = '';
        $prevLat = 0; $prevLng = 0;
        foreach ($coords as $point) {
            $lat = (int) round($point[1] * 1e5);
            $lng = (int) round($point[0] * 1e5);
            $dLat = $lat - $prevLat;
            $dLng = $lng - $prevLng;
            $result .= self::encodeSigned($dLat) . self::encodeSigned($dLng);
            $prevLat = $lat;
            $prevLng = $lng;
        }
        return $result;
    }

    private static function encodeSigned(int $value): string
    {
        $value = $value < 0 ? ~($value << 1) : ($value << 1);
        $result = '';
        while ($value >= 0x20) {
            $result .= chr((0x20 | ($value & 0x1f)) + 63);
            $value >>= 5;
        }
        $result .= chr($value + 63);
        return $result;
    }
}
