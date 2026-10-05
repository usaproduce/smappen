// Google Maps links (docs/truck-planner/05_FRONTEND.md 3.14, 04_BACKEND 2.3).
//
// The browser builds the same strings as the server's MapsUrl::point and MapsUrl::route. The server
// side of that promise is restated here from 04_BACKEND 2.3 (six decimals after roundHalfAway,
// "%2C" between latitude and longitude, "%7C" between waypoints) and from the examples that document
// prints, so a drift on either side shows up as a failing character.

import { describe, expect, it } from 'vitest';
import { roundHalfAway } from '../model';
import { dayRoute, mapsDirUrl, mapsSearchUrl } from '../links';
import { spec, specCount } from './_kitFixtures';

const BASE = { lat: 39.003, lng: -77.405 };
const OFFICE = { lat: 38.96, lng: -77.36 };
const TAPROOM = { lat: 39.0031, lng: -77.4062 };

// 04_BACKEND 2.3, written out independently of links.ts: sprintf('%.6F', roundHalfAway(x, 6)).
function six(x: number): string {
  const r = roundHalfAway(x, 6);
  const units = BigInt(Math.floor(Math.abs(r) * 1000000 + 0.5));
  const whole = units / 1000000n;
  const frac = (units % 1000000n).toString().padStart(6, '0');
  return (r < 0 ? '-' : '') + whole.toString() + '.' + frac;
}
function serverPoint(lat: number, lng: number): string {
  return 'https://www.google.com/maps/search/?api=1&query=' + six(lat) + '%2C' + six(lng);
}
function serverRoute(points: { lat: number; lng: number }[]): string {
  const pair = (p: { lat: number; lng: number }) => six(p.lat) + '%2C' + six(p.lng);
  let url = 'https://www.google.com/maps/dir/?api=1&origin=' + pair(points[0]) + '&destination=' + pair(points[points.length - 1]);
  const stops = points.slice(1, points.length - 1).map(pair);
  if (stops.length > 0) url += '&waypoints=' + stops.join('%7C');
  return url + '&travelmode=driving';
}

describe('mapsSearchUrl', () => {
  it("gives the server's own examples character for character", () => {
    expect(spec(mapsSearchUrl({ lat: 38.96, lng: -77.36 }))).toBe('https://www.google.com/maps/search/?api=1&query=38.960000%2C-77.360000');
    expect(spec(mapsSearchUrl({ lat: 39.01, lng: -77.41 }))).toBe('https://www.google.com/maps/search/?api=1&query=39.010000%2C-77.410000');
  });

  it('writes exactly six decimals, rounded half away from zero', () => {
    expect(mapsSearchUrl({ lat: 38.9696437, lng: -77.3861254 })).toBe('https://www.google.com/maps/search/?api=1&query=38.969644%2C-77.386125');
    expect(mapsSearchUrl({ lat: 38.9696435, lng: -77.3861255 })).toBe('https://www.google.com/maps/search/?api=1&query=38.969644%2C-77.386126');
    expect(mapsSearchUrl({ lat: 0, lng: 0 })).toBe('https://www.google.com/maps/search/?api=1&query=0.000000%2C0.000000');
    expect(mapsSearchUrl({ lat: -0.0000004, lng: 0.0000004 })).toBe('https://www.google.com/maps/search/?api=1&query=0.000000%2C0.000000');
    expect(mapsSearchUrl({ lat: -5, lng: 120 })).toBe('https://www.google.com/maps/search/?api=1&query=-5.000000%2C120.000000');
    const query = mapsSearchUrl({ lat: 38.9, lng: -77.3 }).split('query=')[1];
    for (const part of query.split('%2C')) expect(/^-?\d+\.\d{6}$/.test(part)).toBe(true);
  });

  it('matches MapsUrl::point for the same inputs', () => {
    for (const p of [BASE, OFFICE, TAPROOM, { lat: 38.9696437, lng: -77.3861254 }, { lat: -33.8688, lng: 151.2093 }, { lat: 0.0000005, lng: -0.0000005 }]) {
      expect(mapsSearchUrl(p)).toBe(serverPoint(p.lat, p.lng));
    }
  });
});

describe('mapsDirUrl', () => {
  it('has the documented shape', () => {
    expect(spec(mapsDirUrl({ origin: BASE, destination: BASE, waypoints: [OFFICE, TAPROOM] }))).toBe(
      'https://www.google.com/maps/dir/?api=1&origin=39.003000%2C-77.405000&destination=39.003000%2C-77.405000' +
        '&waypoints=38.960000%2C-77.360000%7C39.003100%2C-77.406200&travelmode=driving',
    );
  });

  it('leaves out the waypoints parameter when there are none', () => {
    const a = mapsDirUrl({ origin: BASE, destination: OFFICE });
    const b = mapsDirUrl({ origin: BASE, destination: OFFICE, waypoints: [] });
    expect(a).toBe('https://www.google.com/maps/dir/?api=1&origin=39.003000%2C-77.405000&destination=38.960000%2C-77.360000&travelmode=driving');
    expect(b).toBe(a);
    expect(a.includes('waypoints')).toBe(false);
  });

  it('always writes the origin, so the link never asks for the position of the device', () => {
    for (const url of [mapsDirUrl({ origin: BASE, destination: OFFICE }), mapsDirUrl(dayRoute(BASE, [OFFICE]))]) {
      expect(url.includes('&origin=39.003000%2C-77.405000')).toBe(true);
      expect(url.endsWith('&travelmode=driving')).toBe(true);
    }
  });

  it('matches MapsUrl::route for a day: base, the stops in order, base', () => {
    expect(mapsDirUrl(dayRoute(BASE, [OFFICE, TAPROOM]))).toBe(serverRoute([BASE, OFFICE, TAPROOM, BASE]));
    expect(mapsDirUrl(dayRoute(BASE, [OFFICE]))).toBe(serverRoute([BASE, OFFICE, BASE]));
    expect(mapsDirUrl({ origin: OFFICE, destination: TAPROOM })).toBe(serverRoute([OFFICE, TAPROOM]));
    const eight = [1, 2, 3, 4, 5, 6, 7, 8].map((i) => ({ lat: 38.9 + i / 100, lng: -77.3 - i / 1000 }));
    expect(mapsDirUrl(dayRoute(BASE, eight))).toBe(serverRoute([BASE, ...eight, BASE]));
    expect(mapsDirUrl(dayRoute(BASE, eight)).split('%7C').length).toBe(8);
  });
});

describe('worked examples', () => {
  it('this file asserts 3 values the specification prints', () => {
    expect(specCount()).toBe(3);
  });
});
