import type { GeoJSONPolygon } from '../types';

// Loosened input type — anything that quacks like Polygon or MultiPolygon.
// Lets us run through territory geometries (now MultiPolygon-capable after
// migration 011) without thrashing the type defs everywhere.
type AnyGeom = { type: string; coordinates: any };

/** Returns all rings as a flat list of [[lng,lat],...] arrays, for either
 *  Polygon (1 outer ring) or MultiPolygon (N outer rings, one per piece). */
export function allOuterRings(geom: AnyGeom): number[][][] {
  if (!geom?.coordinates) return [];
  if (geom.type === 'Polygon') return [geom.coordinates[0]];
  if (geom.type === 'MultiPolygon') return geom.coordinates.map((poly: any) => poly[0]);
  return [];
}

export function polygonCentroid(geom: AnyGeom): { lat: number; lng: number } {
  // Average all outer-ring vertices across all pieces. Good-enough centroid
  // for InfoWindow placement; not a true area-weighted centroid.
  let sumLat = 0, sumLng = 0, n = 0;
  for (const ring of allOuterRings(geom)) {
    for (const [lng, lat] of ring) { sumLat += lat; sumLng += lng; n++; }
  }
  return n > 0 ? { lat: sumLat / n, lng: sumLng / n } : { lat: 0, lng: 0 };
}

export function polygonBounds(geom: AnyGeom) {
  let minLat = Infinity, minLng = Infinity, maxLat = -Infinity, maxLng = -Infinity;
  for (const ring of allOuterRings(geom)) {
    for (const [lng, lat] of ring) {
      if (lat < minLat) minLat = lat;
      if (lat > maxLat) maxLat = lat;
      if (lng < minLng) minLng = lng;
      if (lng > maxLng) maxLng = lng;
    }
  }
  return { minLat, minLng, maxLat, maxLng };
}

/** Convert a Polygon to a single Google path. For MultiPolygon, callers
 *  should use `geoJsonToGooglePaths` (plural) and render one Polygon per piece. */
export function geoJsonToGooglePath(geom: GeoJSONPolygon | AnyGeom): google.maps.LatLngLiteral[] {
  const ring = allOuterRings(geom)[0] ?? [];
  return ring.map(([lng, lat]) => ({ lat, lng }));
}

/** Multi-piece variant — returns one path array per outer ring. */
export function geoJsonToGooglePaths(geom: AnyGeom): google.maps.LatLngLiteral[][] {
  return allOuterRings(geom).map((ring) => ring.map(([lng, lat]) => ({ lat, lng })));
}

export function googlePolygonToGeoJson(polygon: google.maps.Polygon): GeoJSONPolygon {
  const path = polygon.getPath();
  const ring: number[][] = [];
  path.forEach((p) => ring.push([p.lng(), p.lat()]));
  if (ring.length > 0) ring.push(ring[0]);
  return { type: 'Polygon', coordinates: [ring] };
}
