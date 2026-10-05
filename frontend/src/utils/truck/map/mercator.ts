// Truck Planner map engine - Web Mercator in the 256-unit world Google Maps uses
// (docs/truck-planner/05_FRONTEND.md 5.1).
//
// A point of the world is (x, y) with both in 0..256: x grows eastwards from the antimeridian, y grows
// southwards from the top of the map. At zoom z one world unit is 2^z CSS pixels. The hexagon mesh,
// the blank base map and the pin projection of the blank base all work in these units, so they agree
// with the projection of the Google map to the last digit a double can hold.
//
// Pure: runs in Node and in the browser.

/** Width and height of the world in world units. */
export const WORLD_SIZE = 256;

/** The sine of the latitude is kept inside this, as Google does: the map ends near 89.19 degrees. */
const MAX_SIN = 0.9999;

/** World x of a longitude: 0 at -180, 256 at 180. */
export function worldX(lng: number): number {
  return (WORLD_SIZE * (lng + 180)) / 360;
}

/** World y of a latitude: 128 at the equator, smaller to the north. */
export function worldY(lat: number): number {
  let s = Math.sin((lat * Math.PI) / 180);
  if (s < -MAX_SIN) s = -MAX_SIN;
  else if (s > MAX_SIN) s = MAX_SIN;
  return WORLD_SIZE * (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI));
}

/** The longitude of a world x: the inverse of worldX. */
export function lngOf(x: number): number {
  return (x / WORLD_SIZE) * 360 - 180;
}

/** The latitude of a world y: the inverse of worldY. */
export function latOf(y: number): number {
  // worldY is 256 * (0.5 - atanh(sin(lat)) / (2 * PI)), so sin(lat) = tanh(2 * PI * (0.5 - y / 256)).
  return (Math.asin(Math.tanh(2 * Math.PI * (0.5 - y / WORLD_SIZE))) * 180) / Math.PI;
}
