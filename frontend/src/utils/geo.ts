import type { GeoJSONPolygon } from '../types';

export function polygonCentroid(geom: GeoJSONPolygon): { lat: number; lng: number } {
  const ring = geom.coordinates[0];
  let sumLat = 0, sumLng = 0;
  for (const [lng, lat] of ring) { sumLat += lat; sumLng += lng; }
  const n = ring.length;
  return { lat: sumLat / n, lng: sumLng / n };
}

export function polygonBounds(geom: GeoJSONPolygon) {
  let minLat = Infinity, minLng = Infinity, maxLat = -Infinity, maxLng = -Infinity;
  for (const [lng, lat] of geom.coordinates[0]) {
    if (lat < minLat) minLat = lat;
    if (lat > maxLat) maxLat = lat;
    if (lng < minLng) minLng = lng;
    if (lng > maxLng) maxLng = lng;
  }
  return { minLat, minLng, maxLat, maxLng };
}

export function geoJsonToGooglePath(geom: GeoJSONPolygon): google.maps.LatLngLiteral[] {
  return geom.coordinates[0].map(([lng, lat]) => ({ lat, lng }));
}

export function googlePolygonToGeoJson(polygon: google.maps.Polygon): GeoJSONPolygon {
  const path = polygon.getPath();
  const ring: number[][] = [];
  path.forEach((p) => ring.push([p.lng(), p.lat()]));
  if (ring.length > 0) ring.push(ring[0]);
  return { type: 'Polygon', coordinates: [ring] };
}
