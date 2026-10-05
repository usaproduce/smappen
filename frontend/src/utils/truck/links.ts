// Truck Planner - free Google Maps links (docs/truck-planner/05_FRONTEND.md 3.14).
//
// Plain URLs, no API call. The server supplies Spot.maps_url, Plan.maps_route_url and
// ScoutCandidate.maps_url; these builders give the same shapes for things the server has not seen
// (a clicked point, an unsaved day, one leg). Coordinates carry exactly six decimals through
// roundHalfAway, as the server writes them (04_BACKEND 2.3).

import { fmtFixed } from './format';

export interface MapsPoint {
  lat: number;
  lng: number;
}

export interface MapsRoute {
  origin: MapsPoint;
  destination: MapsPoint;
  waypoints?: readonly MapsPoint[];
}

/** "<lat>%2C<lng>" with six decimals each. */
function pair(p: MapsPoint): string {
  return fmtFixed(p.lat, 6) + '%2C' + fmtFixed(p.lng, 6);
}

/** A pin at one point. */
export function mapsSearchUrl(point: MapsPoint): string {
  return 'https://www.google.com/maps/search/?api=1&query=' + pair(point);
}

/**
 * Driving directions. The origin is always written, so the link never asks Google for the position
 * of the device; `waypoints` is left out when there are none.
 */
export function mapsDirUrl(route: MapsRoute): string {
  let url = 'https://www.google.com/maps/dir/?api=1&origin=' + pair(route.origin) + '&destination=' + pair(route.destination);
  const stops = route.waypoints === undefined ? [] : route.waypoints;
  if (stops.length > 0) {
    let joined = '';
    for (let i = 0; i < stops.length; i++) joined += (i > 0 ? '%7C' : '') + pair(stops[i]);
    url += '&waypoints=' + joined;
  }
  return url + '&travelmode=driving';
}

/** A day's route: base, the stops in order, base. */
export function dayRoute(base: MapsPoint, stops: readonly MapsPoint[]): MapsRoute {
  return { origin: base, destination: base, waypoints: stops };
}
