// Truck Planner - the pure logic of the map page and of its spot card
// (docs/truck-planner/05_FRONTEND.md 1.2, 4.2, 4.3 and 8.2).
//
// What is held here: the playback accumulator, the keys of the hour bar, the URL parameters of the
// map page and their round trip, the layouts by width, the hover hint (a band, never a figure), the
// status sentences, and how the spot card arranges results of the model. The two anchors of
// 02_MODEL 8.3 are fed in from the golden file and must print exactly what the specification states.

import { describe, expect, it } from 'vitest';
import { assume, goldenCase, repoText, type GoldenCase } from './_kitFixtures';
import { fmtEstimate } from '../format';
import {
  CARD_WIDTH,
  DEFAULT_ZOOM,
  HINT_ACTION,
  HINT_LEADS,
  LAYER_LABELS,
  LEGEND_CAPTIONS,
  LEGEND_TITLES,
  MAX_STEPS_PER_FRAME,
  PLAY_SPEEDS,
  POINT_TERMS,
  POINT_ZOOM,
  advancePlayback,
  cardSide,
  countyLabel,
  driveSummary,
  floatLayout,
  hintLine,
  hintPlace,
  hourBarMode,
  hourKeyAction,
  howAtHour,
  howOnDay,
  initialCamera,
  legendStartsOpen,
  mapRegionLabel,
  markerShare,
  orderLeaves,
  placePoint,
  pointParam,
  readMapParams,
  statusLines,
  stepHow,
  stripHeights,
  whoIsHere,
  windowSlot,
  writeMapParams,
  type KeyFocus,
} from '../hourControl';
import {
  MAP_LAYERS,
  bestWindows,
  dayPlan,
  hourlyOrders,
  stopMoney,
  typicalContext,
  weekStrip,
  windowOrders,
} from '../model';
import type { Assumptions, HourResult, LocationVectors, SpotTerms, TruckProfile } from '../model';
import { BAND_LABELS, minByte } from '../palette';
import { typicalWithFuel } from '../assemble';
import { confidenceLabel } from '../wording';

// -------------------------------------------------------------------------------------------------
// Fixtures: the two anchors of 02_MODEL 8.3, exactly as the golden file carries them
// -------------------------------------------------------------------------------------------------

interface Anchor {
  A: Assumptions;
  profile: TruckProfile;
  terms: SpotTerms;
  vectors: LocationVectors;
}

/** The golden case an anchor of 02_MODEL 8.3 points at: the `anchors` array of the golden file names it. */
const ANCHORS = (JSON.parse(repoText('tests/fixtures/truck-planner/golden_cases.json')) as { anchors: { id: string; case: string }[] }).anchors;

function anchorCase(id: string): GoldenCase {
  const found = ANCHORS.find((a) => a.id === id);
  if (found === undefined) throw new Error('no anchor ' + id);
  return goldenCase(found.case);
}

function anchor(id: string): Anchor {
  const c = anchorCase(id);
  return { A: assume(c.args.A), profile: c.args.profile, terms: c.args.terms, vectors: c.args.vectors };
}

const office = anchor('A1'); // the office park, visibility normal, no host
const taproom = anchor('A2'); // zero vectors, a taproom host of 120 as the only food

/** The best windows of the typical week with their orders, as the card's "Best windows" lists them. */
function bestRows(a: Anchor, hours: number) {
  const week = weekStrip(a.A, a.profile, a.terms, a.vectors, null);
  return bestWindows(week, hours, 3, true).map((best) => {
    const slot = windowSlot(best);
    const result = windowOrders(
      a.A,
      a.profile,
      a.terms,
      a.vectors,
      null,
      typicalContext(a.A, slot.dow),
      typicalContext(a.A, (slot.dow + 1) % 7),
      slot.open,
      slot.close,
    );
    return { slot, text: fmtEstimate(result.orders, 'orders'), label: confidenceLabel(result.orders.confidence), result };
  });
}

function hourOf(a: Anchor, dow: number, hour: number): HourResult {
  return hourlyOrders(a.A, a.profile, a.terms, a.vectors, null, typicalContext(a.A, dow), hour);
}

// -------------------------------------------------------------------------------------------------
// Playing the week
// -------------------------------------------------------------------------------------------------

describe('playback', () => {
  it('2.5 times the step time advances two hours and carries the rest', () => {
    for (const { ms } of PLAY_SPEEDS) {
      expect(advancePlayback(0, 2.5 * ms, ms, true)).toEqual({ steps: 2, carryMs: 0.5 * ms });
    }
    // The carried half step completes with the next half.
    expect(advancePlayback(300, 300, 600, true)).toEqual({ steps: 1, carryMs: 0 });
  });

  it('adds frames up: sixty frames of 10 ms at 600 ms an hour is exactly one hour', () => {
    let carry = 0;
    let steps = 0;
    for (let i = 0; i < 60; i++) {
      const tick = advancePlayback(carry, 10, 600, true);
      carry = tick.carryMs;
      steps += tick.steps;
    }
    expect(steps).toBe(1);
    expect(carry).toBe(0);
  });

  it('does not advance while paused, and carries nothing into the next play', () => {
    expect(advancePlayback(0, 5000, 600, false)).toEqual({ steps: 0, carryMs: 0 });
    expect(advancePlayback(550, 100, 600, false)).toEqual({ steps: 0, carryMs: 0 });
  });

  it('never moves backwards and never bursts after a long gap', () => {
    expect(advancePlayback(0, -50, 600, true)).toEqual({ steps: 0, carryMs: 0 });
    expect(advancePlayback(0, Number.NaN, 600, true)).toEqual({ steps: 0, carryMs: 0 });
    expect(advancePlayback(0, Infinity, 600, true)).toEqual({ steps: 0, carryMs: 0 });
    expect(advancePlayback(0, 60000, 300, true)).toEqual({ steps: MAX_STEPS_PER_FRAME, carryMs: 0 });
    expect(advancePlayback(0, 100, 0, true)).toEqual({ steps: 0, carryMs: 0 });
  });

  it('wraps from Sunday 11 PM to Monday 12 AM, and back', () => {
    expect(stepHow(167, 1)).toBe(0);
    expect(stepHow(0, -1)).toBe(167);
    expect(stepHow(166, 2 + 168)).toBe(0);
    expect(stepHow(84, 24)).toBe(108);
    expect(stepHow(150, 24)).toBe(6); // Sunday 6 AM, one day on, is Monday 6 AM
    expect(stepHow(6, -24)).toBe(150);
  });

  it('moves the day and the hour separately', () => {
    expect(howOnDay(84, 5)).toBe(5 * 24 + 12);
    expect(howOnDay(167, 0)).toBe(23);
    expect(howAtHour(84, 0)).toBe(72);
    expect(howAtHour(84, 23)).toBe(95);
    for (let how = 0; how < 168; how++) {
      expect(howOnDay(how, (how - (how % 24)) / 24)).toBe(how);
      expect(howAtHour(how, how % 24)).toBe(how);
    }
  });

  it('offers the three speeds of the specification', () => {
    expect(PLAY_SPEEDS).toEqual([
      { ms: 1200, label: 'Slow' },
      { ms: 600, label: 'Normal' },
      { ms: 300, label: 'Fast' },
    ]);
  });
});

// -------------------------------------------------------------------------------------------------
// Keys
// -------------------------------------------------------------------------------------------------

describe('hour keys', () => {
  const page: KeyFocus = { onPage: true, inHourBar: false, tag: 'body', inputType: '', editable: false };
  const slider: KeyFocus = { onPage: false, inHourBar: true, tag: 'input', inputType: 'range', editable: false };

  it('Space plays or pauses, the arrows move an hour or a day, Home is Now', () => {
    expect(hourKeyAction(' ', page, false)).toEqual({ kind: 'toggle-play' });
    expect(hourKeyAction('ArrowLeft', page, false)).toEqual({ kind: 'step', delta: -1 });
    expect(hourKeyAction('ArrowRight', page, false)).toEqual({ kind: 'step', delta: 1 });
    expect(hourKeyAction('ArrowUp', page, false)).toEqual({ kind: 'step', delta: -24 });
    expect(hourKeyAction('ArrowDown', page, false)).toEqual({ kind: 'step', delta: 24 });
    expect(hourKeyAction('Home', page, false)).toEqual({ kind: 'now' });
    expect(hourKeyAction('Enter', page, false)).toBeNull();
    expect(hourKeyAction('a', page, false)).toBeNull();
  });

  it('works inside the hour bar, on the slider too', () => {
    expect(hourKeyAction('ArrowRight', slider, false)).toEqual({ kind: 'step', delta: 1 });
    expect(hourKeyAction('ArrowDown', slider, false)).toEqual({ kind: 'step', delta: 24 });
    expect(hourKeyAction(' ', slider, false)).toEqual({ kind: 'toggle-play' });
  });

  it('never fires while typing, with a modifier, or with focus elsewhere (the map pans with its own arrows)', () => {
    const inMap: KeyFocus = { onPage: false, inHourBar: false, tag: 'div', inputType: '', editable: false };
    expect(hourKeyAction('ArrowLeft', inMap, false)).toBeNull();
    expect(hourKeyAction(' ', inMap, false)).toBeNull();
    for (const typing of [
      { ...slider, tag: 'select' },
      { ...slider, tag: 'textarea' },
      { ...slider, tag: 'input', inputType: 'text' },
      { ...slider, tag: 'input', inputType: 'date' },
      { ...slider, tag: 'div', editable: true },
    ]) {
      for (const key of [' ', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home']) {
        expect(hourKeyAction(key, typing, false), key + ' on ' + typing.tag).toBeNull();
      }
    }
    expect(hourKeyAction('ArrowLeft', page, true)).toBeNull();
    expect(hourKeyAction(' ', page, true)).toBeNull();
  });

  it('leaves Space to a focused button or link, which it activates', () => {
    for (const tag of ['button', 'a', 'summary']) {
      const focus: KeyFocus = { onPage: false, inHourBar: true, tag, inputType: '', editable: false };
      expect(hourKeyAction(' ', focus, false)).toBeNull();
      expect(hourKeyAction('ArrowRight', focus, false)).toEqual({ kind: 'step', delta: 1 });
    }
  });
});

// -------------------------------------------------------------------------------------------------
// The URL
// -------------------------------------------------------------------------------------------------

describe('URL parameters of the map page', () => {
  it('reads every parameter of 1.2', () => {
    const p = readMapParams('?lat=38.9696&lng=-77.3861&z=13.5&how=84&layer=people&pt=38.96,-77.36&scout=1&tp_basemap=blank');
    expect(p).toEqual({
      lat: 38.9696,
      lng: -77.3861,
      z: 13.5,
      how: 84,
      layer: 'people',
      pt: { lat: 38.96, lng: -77.36 },
      spot: null,
      pick: null,
      returnTo: null,
      scout: true,
      blank: true,
    });
    expect(readMapParams('pt=38.960000%2C-77.360000').pt).toEqual({ lat: 38.96, lng: -77.36 });
    expect(readMapParams('?spot=b2f0a1c4-0000-4000-8000-000000000001').spot).toBe('b2f0a1c4-0000-4000-8000-000000000001');
    expect(readMapParams('?pick=base&return=/truck/settings/truck')).toMatchObject({ pick: 'base', returnTo: '/truck/settings/truck' });
    expect(readMapParams('?pick=spot').pick).toBe('spot');
  });

  it('gives null for what is absent, so the defaults of 1.2 apply', () => {
    expect(readMapParams('')).toEqual({
      lat: null,
      lng: null,
      z: null,
      how: null,
      layer: null,
      pt: null,
      spot: null,
      pick: null,
      returnTo: null,
      scout: false,
      blank: false,
    });
  });

  it('treats a bad value as absent and never throws', () => {
    expect(readMapParams('?lat=91&lng=-77')).toMatchObject({ lat: null, lng: null });
    expect(readMapParams('?lat=38.9')).toMatchObject({ lat: null, lng: null }); // half a centre is no centre
    expect(readMapParams('?lat=38.9&lng=-181')).toMatchObject({ lat: null, lng: null });
    expect(readMapParams('?lat=1e1&lng=2')).toMatchObject({ lat: null, lng: null });
    expect(readMapParams('?z=7.9').z).toBeNull();
    expect(readMapParams('?z=19.1').z).toBeNull();
    expect(readMapParams('?z=8').z).toBe(8);
    expect(readMapParams('?z=19').z).toBe(19);
    expect(readMapParams('?how=168').how).toBeNull();
    expect(readMapParams('?how=-1').how).toBeNull();
    expect(readMapParams('?how=12.5').how).toBeNull();
    expect(readMapParams('?how=167').how).toBe(167);
    expect(readMapParams('?how=0').how).toBe(0);
    expect(readMapParams('?layer=heat').layer).toBeNull();
    expect(readMapParams('?pt=38.96').pt).toBeNull();
    expect(readMapParams('?pt=abc,def').pt).toBeNull();
    expect(readMapParams('?pt=38.96,-77.36,5').pt).toBeNull();
    expect(readMapParams('?pt=95,-77').pt).toBeNull();
    expect(readMapParams('?spot=has%20space').spot).toBeNull();
    expect(readMapParams('?pick=truck').pick).toBeNull();
    expect(readMapParams('?scout=yes').scout).toBe(false);
    expect(readMapParams('?%E0%A4%A').lat).toBeNull();
  });

  it('takes a return path only when it stays under /truck', () => {
    const back = (value: string) => readMapParams('?pick=base&return=' + encodeURIComponent(value)).returnTo;
    expect(back('/truck')).toBe('/truck');
    expect(back('/truck/spots?new=1')).toBe('/truck/spots?new=1');
    expect(back('/truck/settings/truck')).toBe('/truck/settings/truck');
    expect(back('/trucks')).toBeNull();
    expect(back('/dashboard')).toBeNull();
    expect(back('//evil.example/truck')).toBeNull();
    expect(back('https://evil.example/truck')).toBeNull();
    expect(back('/truck/../dashboard')).toBeNull();
    expect(back('/truck//evil.example')).toBeNull();
    expect(back('/truck\\evil')).toBeNull();
  });

  it('a saved spot wins over a point when a link names both', () => {
    const p = readMapParams('?pt=38.96,-77.36&spot=abc');
    expect(p.spot).toBe('abc');
    expect(p.pt).toBeNull();
  });

  it('writes the centre with six decimals, the zoom with one, and a point with a plain comma', () => {
    expect(writeMapParams('', { camera: { lat: 38.9696, lng: -77.3861, zoom: 12 } })).toBe('?lat=38.969600&lng=-77.386100&z=12.0');
    expect(writeMapParams('', { camera: { lat: 38.96961234567, lng: -77.38609999999, zoom: 13.449 } })).toBe(
      '?lat=38.969612&lng=-77.386100&z=13.4',
    );
    expect(writeMapParams('', { pt: { lat: 38.96, lng: -77.36 } })).toBe('?pt=38.960000,-77.360000');
    expect(pointParam({ lat: -0.0000001, lng: 0 })).toBe('0.000000,0.000000');
    expect(writeMapParams('', { how: 84, layer: 'competition' })).toBe('?how=84&layer=competition');
    expect(writeMapParams('', { camera: { lat: 1, lng: 2, zoom: 30 } })).toBe('?lat=1.000000&lng=2.000000&z=19.0');
  });

  it('hands a place on with the six decimals the URL carries', () => {
    expect(placePoint({ lat: 38.89977740297995, lng: -77.25800150839586 })).toEqual({ lat: 38.899777, lng: -77.258002 });
    // The centre of the map comes back from the projection with noise in its last digits.
    expect(placePoint({ lat: 38.92999999999999, lng: -77.30000000000001 })).toEqual({ lat: 38.93, lng: -77.3 });
    expect(placePoint({ lat: 39.0105, lng: -77.4291 })).toEqual({ lat: 39.0105, lng: -77.4291 });
    const tiny = placePoint({ lat: -0.0000001, lng: 0.0000004 });
    expect(Object.is(tiny.lat, 0)).toBe(true);
    expect(tiny.lng).toBe(0);
    // What is handed on is exactly what the URL reads back.
    for (const p of [{ lat: 38.89977740297995, lng: -77.25800150839586 }, { lat: -33.8688197, lng: 151.2092955 }, { lat: 89.9999996, lng: -179.9999996 }]) {
      const placed = placePoint(p);
      expect(readMapParams('?pt=' + pointParam(p)).pt).toEqual(placed);
      expect(pointParam(placed)).toBe(pointParam(p));
      expect(placePoint(placed)).toEqual(placed);
    }
  });

  it('keeps what a write does not name, drops what it sets to null, and leaves the keys of others alone', () => {
    const start = '?lat=38.969600&lng=-77.386100&z=12.0&how=84&layer=people&pt=38.960000,-77.360000&tp_perf=1&tp_basemap=blank';
    expect(writeMapParams(start, {})).toBe(start);
    expect(writeMapParams(start, { how: 85 })).toBe(start.replace('how=84', 'how=85'));
    expect(writeMapParams(start, { pt: null })).toBe(start.replace('&pt=38.960000,-77.360000', ''));
    expect(writeMapParams(start, { spot: 'abc', pt: null })).toBe(start.replace('pt=38.960000,-77.360000', 'spot=abc'));
    expect(writeMapParams(start, { camera: null, how: null, layer: null, pt: null })).toBe('?tp_perf=1&tp_basemap=blank');
    expect(writeMapParams('?tp_perf=1', {})).toBe('?tp_perf=1');
    expect(writeMapParams('', {})).toBe('');
    expect(writeMapParams('?how=84', { how: null })).toBe('');
  });

  it('writes pick mode with its way back, and drops the way back with it', () => {
    const picking = writeMapParams('', { pick: 'base', returnTo: '/truck/settings/truck' });
    expect(picking).toBe('?pick=base&return=/truck/settings/truck');
    expect(readMapParams(picking)).toMatchObject({ pick: 'base', returnTo: '/truck/settings/truck' });
    expect(writeMapParams(picking, { pick: null, returnTo: null })).toBe('');
    expect(writeMapParams(picking, { pick: null })).toBe('');
    // A way back with a query of its own survives the trip: its ?, & and = are escaped on the way out.
    const withQuery = writeMapParams('', { pick: 'spot', returnTo: '/truck/spots?new=1&q=a%20b' });
    expect(withQuery).toBe('?pick=spot&return=/truck/spots%3Fnew%3D1%26q%3Da%2520b');
    expect(readMapParams(withQuery).returnTo).toBe('/truck/spots?new=1&q=a%20b');
    expect(readMapParams('?pick=spot&return=' + encodeURIComponent('/truck/spots?q=a b')).returnTo).toBeNull();
    expect(writeMapParams('', { scout: true })).toBe('?scout=1');
    expect(writeMapParams('?scout=1', { scout: false })).toBe('');
  });

  it('round-trips: what is written is read back, and writing it again changes nothing', () => {
    const cameras = [
      { lat: 38.9696, lng: -77.3861, zoom: 12 },
      { lat: -33.868819, lng: 151.209296, zoom: 8 },
      { lat: 0, lng: 0, zoom: 19 },
      { lat: 89.999999, lng: -179.999999, zoom: 14.3 },
    ];
    for (const camera of cameras) {
      for (const layer of MAP_LAYERS) {
        for (const how of [0, 1, 23, 24, 84, 167]) {
          const url = writeMapParams('?tp_perf=1', { camera, how, layer, pt: { lat: camera.lat / 2, lng: camera.lng / 2 } });
          const read = readMapParams(url);
          expect(read.lat).toBeCloseTo(camera.lat, 6);
          expect(read.lng).toBeCloseTo(camera.lng, 6);
          expect(read.z).toBeCloseTo(camera.zoom, 1);
          expect(read.how).toBe(how);
          expect(read.layer).toBe(layer);
          expect(read.pt).not.toBeNull();
          expect(read.pt!.lat).toBeCloseTo(camera.lat / 2, 6);
          expect(read.pt!.lng).toBeCloseTo(camera.lng / 2, 6);
          expect(writeMapParams(url, {})).toBe(url);
          expect(writeMapParams(url, { camera: { lat: read.lat!, lng: read.lng!, zoom: read.z! }, how: read.how, layer: read.layer })).toBe(url);
        }
      }
    }
    const spotUrl = writeMapParams('', { spot: 'b2f0a1c4-0000-4000-8000-000000000001', pt: null });
    expect(readMapParams(spotUrl).spot).toBe('b2f0a1c4-0000-4000-8000-000000000001');
    expect(writeMapParams(spotUrl, {})).toBe(spotUrl);
  });

  it('starts the map where 1.2 says: the link, else the place it names, else the last visit, else the base, else the region', () => {
    const none = readMapParams('');
    const base = { lat: 39.003, lng: -77.405 };
    const centre = { lat: 38.9072, lng: -77.0369 };
    const persisted = { lat: 38.8, lng: -77.1, zoom: 14.5 };
    expect(initialCamera(readMapParams('?lat=38.5&lng=-77.5&z=10'), persisted, base, centre)).toEqual({ lat: 38.5, lng: -77.5, zoom: 10 });
    expect(initialCamera(readMapParams('?lat=38.5&lng=-77.5'), persisted, base, centre)).toEqual({ lat: 38.5, lng: -77.5, zoom: 14.5 });
    expect(initialCamera(readMapParams('?lat=38.5&lng=-77.5'), null, base, centre)).toEqual({ lat: 38.5, lng: -77.5, zoom: DEFAULT_ZOOM });
    expect(initialCamera(none, persisted, base, centre)).toEqual(persisted);
    expect(initialCamera(none, null, base, centre)).toEqual({ ...base, zoom: 12 });
    expect(initialCamera(none, null, { lat: Number.NaN, lng: 0 }, centre)).toEqual({ ...centre, zoom: 12 });
    expect(initialCamera(readMapParams('?z=15'), null, base, null)).toEqual({ ...base, zoom: 15 });
    // A link that names a point or a saved spot without a camera opens on that place.
    expect(initialCamera(readMapParams('?pt=38.96,-77.36'), persisted, base, centre)).toEqual({ lat: 38.96, lng: -77.36, zoom: 14.5 });
    expect(initialCamera(readMapParams('?pt=38.96,-77.36'), null, base, centre)).toEqual({ lat: 38.96, lng: -77.36, zoom: POINT_ZOOM });
    expect(initialCamera(readMapParams('?spot=abc'), persisted, base, centre, { lat: 38.7, lng: -77.2 })).toEqual({ lat: 38.7, lng: -77.2, zoom: 14.5 });
    expect(initialCamera(readMapParams('?spot=abc'), persisted, base, centre, null)).toEqual(persisted);
    // A stored zoom outside the map's range is brought back into it.
    expect(initialCamera(none, { lat: 38.8, lng: -77.1, zoom: 3 }, base, centre).zoom).toBe(8);
  });
});

// -------------------------------------------------------------------------------------------------
// Layout
// -------------------------------------------------------------------------------------------------

describe('layout by width', () => {
  it('puts the spot card at the right on a desktop and a tablet held sideways, else at the bottom', () => {
    expect(cardSide(1440, 900)).toBe('right');
    expect(cardSide(1024, 768)).toBe('right');
    expect(cardSide(1024, 1366)).toBe('right');
    expect(cardSide(820, 1180)).toBe('bottom');
    expect(cardSide(1180, 820)).toBe('right');
    expect(cardSide(768, 1024)).toBe('bottom');
    expect(cardSide(375, 812)).toBe('bottom');
    expect(cardSide(812, 375)).toBe('right');
    expect(cardSide(667, 375)).toBe('bottom');
    expect(CARD_WIDTH).toBe(420);
  });

  it('opens the legend on a desktop and starts it as a button below', () => {
    expect(legendStartsOpen(1440)).toBe(true);
    expect(legendStartsOpen(1024)).toBe(true);
    expect(legendStartsOpen(1023)).toBe(false);
    expect(legendStartsOpen(375)).toBe(false);
  });

  it('arranges the floating cards by the room the map has, with the card open or not', () => {
    expect(floatLayout(1440)).toBe('wide');
    expect(floatLayout(1440 - CARD_WIDTH)).toBe('wide');
    expect(floatLayout(1024)).toBe('wide');
    expect(floatLayout(1024 - CARD_WIDTH)).toBe('tight');
    expect(floatLayout(820)).toBe('medium');
    expect(floatLayout(768)).toBe('medium');
    expect(floatLayout(699)).toBe('tight');
    expect(floatLayout(560)).toBe('tight');
    expect(floatLayout(559)).toBe('narrow');
    expect(floatLayout(375)).toBe('narrow');
    expect(floatLayout(360)).toBe('narrow');
  });

  it('lays the hour bar out by its own width: day buttons, a day select, then two rows', () => {
    expect(hourBarMode(1440)).toBe('wide');
    expect(hourBarMode(1440 - CARD_WIDTH)).toBe('wide');
    expect(hourBarMode(1024)).toBe('wide');
    expect(hourBarMode(820)).toBe('compact');
    expect(hourBarMode(768)).toBe('compact');
    expect(hourBarMode(1024 - CARD_WIDTH)).toBe('stacked');
    expect(hourBarMode(375)).toBe('stacked');
  });
});

// -------------------------------------------------------------------------------------------------
// Hover hint, legend, status
// -------------------------------------------------------------------------------------------------

describe('hover hint and legend', () => {
  it('reads a legend band for every byte of every layer, never a single figure', () => {
    for (const layer of MAP_LAYERS) {
      for (let byte = 0; byte <= 255; byte++) {
        const line = hintLine(layer, byte);
        const lead = HINT_LEADS[layer] + ': ';
        expect(line.startsWith(lead)).toBe(true);
        const band = line.slice(lead.length);
        expect(BAND_LABELS[layer]).toContain(band);
        // A band is "under N", "N to M" or "N or more": a bare number would be a figure.
        expect(band).toMatch(/^(under [\d,]+|[\d,]+ to [\d,]+|[\d,]+ or more)$/);
      }
    }
  });

  it('reads the lowest band for a cell without colour', () => {
    expect(hintLine('opportunity', 0)).toBe('Orders an hour: under 1');
    expect(hintLine('people', 0)).toBe('People nearby: under 100');
    expect(hintLine('competition', 0)).toBe('Competition: under 1');
    expect(hintLine('opportunity', 255)).toBe('Orders an hour: 30 or more');
    expect(hintLine('people', 255)).toBe('People nearby: 10,000 or more');
    expect(hintLine('competition', 255)).toBe('Competition: 50 or more');
    expect(HINT_ACTION).toBe('Click for an estimate at this point');
  });

  it('places the hint beside the pointer and keeps it inside the map', () => {
    const map = { left: 0, top: 98, right: 1440, bottom: 824 };
    expect(hintPlace(100, 200, 200, 40, map)).toEqual({ x: 114, y: 214 });
    // near the right edge it flips to the left of the pointer, near the bottom above it
    expect(hintPlace(1400, 200, 200, 40, map)).toEqual({ x: 1186, y: 214 });
    expect(hintPlace(100, 800, 200, 40, map)).toEqual({ x: 114, y: 746 });
    expect(hintPlace(1400.6, 800.4, 200, 40, map)).toEqual({ x: 1186, y: 746 });
    // with the spot card open the map ends at the card: the hint never lies over it
    const beside = { left: 0, top: 98, right: 1020, bottom: 824 };
    const flipped = hintPlace(893, 621, 202, 46, beside);
    expect(flipped).toEqual({ x: 677, y: 635 });
    expect(flipped.x + 202).toBeLessThanOrEqual(beside.right);
    // never off the left or the top of the box
    const tight = hintPlace(5, 100, 400, 300, { left: 0, top: 98, right: 320, bottom: 338 });
    expect(tight.x).toBeGreaterThanOrEqual(8);
    expect(tight.y).toBeGreaterThanOrEqual(106);
  });

  it('puts the marker where the byte sits on the ramp, and nowhere for a cell without colour', () => {
    expect(markerShare(null)).toBeNull();
    expect(markerShare(undefined)).toBeNull();
    expect(markerShare(0)).toBeNull();
    expect(markerShare(255)).toBe(1);
    expect(markerShare(300)).toBe(1);
    expect(markerShare(51)).toBeCloseTo(0.2, 12);
    for (const layer of MAP_LAYERS) expect(markerShare(minByte(layer))).toBeGreaterThan(0);
  });

  it('has the titles, labels and captions of 4.2', () => {
    expect(LAYER_LABELS).toEqual({ opportunity: 'Opportunity', people: 'People nearby', competition: 'Competition' });
    expect(LEGEND_TITLES).toEqual({ opportunity: 'Expected orders per hour', people: 'People nearby', competition: 'Food competition' });
    expect(LEGEND_CAPTIONS.opportunity).toBe(
      'Your truck parked at each hexagon in a typical week. No host, no weather. A rough guide for ranking places. Click a point for an estimate with its range. Colours near hospitals, campuses and stations rest on the weakest figures.',
    );
    expect(LEGEND_CAPTIONS.people).toBe('People present within walking distance. Nearer people count more.');
    expect(LEGEND_CAPTIONS.competition).toBe('Pull of food outlets around each hexagon. 1 equals one quick-service outlet at the same spot.');
    expect(mapRegionLabel('opportunity')).toBe('Map of expected orders per hour. Use Spots and Scout for the same information as lists.');
    expect(mapRegionLabel('people')).toBe('Map of people nearby. Use Spots and Scout for the same information as lists.');
    expect(mapRegionLabel('competition')).toBe('Map of food competition. Use Spots and Scout for the same information as lists.');
  });

  it('scales the hour strip to its tallest hour and shows nothing for nothing', () => {
    const bytes = new Uint8Array(24);
    expect(stripHeights(null)).toEqual(new Array(24).fill(0));
    expect(stripHeights(bytes)).toEqual(new Array(24).fill(0));
    bytes[12] = 200;
    bytes[13] = 100;
    bytes[18] = 50;
    const heights = stripHeights(bytes);
    expect(heights).toHaveLength(24);
    expect(heights[12]).toBe(1);
    expect(heights[13]).toBe(0.5);
    expect(heights[18]).toBe(0.25);
    expect(heights[0]).toBe(0);
    expect(stripHeights(new Uint8Array(5))).toHaveLength(24);
  });
});

describe('status chip', () => {
  const ask = (status: Parameters<typeof statusLines>[0]['status'], more: Partial<Parameters<typeof statusLines>[0]> = {}) =>
    statusLines({ status, rebuilding: false, gzBytes: 3413224, googleFailed: false, ...more }).map((line) => line.text);

  it('says nothing while the colours draw', () => {
    expect(ask('ready')).toEqual([]);
    expect(ask('ready-2d')).toEqual([]);
  });

  it('has the sentence of 4.2 for every other state', () => {
    expect(ask('loading')).toEqual(['Loading map data (3.4 MB, first time only)...']);
    expect(ask('building')).toEqual(['Loading map data (3.4 MB, first time only)...']);
    expect(ask('loading', { gzBytes: null })).toEqual(['Loading map data...']);
    expect(ask('zoomed-out')).toEqual(['Zoom in to see the colours.']);
    expect(ask('failed')).toEqual(['Map colours are unavailable right now. You can still click the map for an estimate.']);
    expect(ask('version-mismatch')).toEqual(['The map data is from a different version. Reload the page.']);
    expect(ask('no-region')).toEqual(['No map data for this area yet.']);
    expect(ask('no-region', { rebuilding: true })).toEqual([
      'Map data is being rebuilt after an update. New estimates are unavailable until it finishes.',
    ]);
  });

  it('adds the Google sentence when the base map could not load', () => {
    expect(ask('ready', { googleFailed: true })).toEqual([
      'The Google map could not load, so the background map is hidden. Estimates and saved spots still work.',
    ]);
    expect(ask('zoomed-out', { googleFailed: true })).toHaveLength(2);
  });

  it('pairs every sentence with an icon kind', () => {
    for (const status of ['no-region', 'loading', 'building', 'zoomed-out', 'failed', 'version-mismatch'] as const) {
      const lines = statusLines({ status, rebuilding: false, gzBytes: 1, googleFailed: true });
      for (const line of lines) expect(['loading', 'zoom', 'warning', 'info']).toContain(line.kind);
    }
  });
});

// -------------------------------------------------------------------------------------------------
// The spot card
// -------------------------------------------------------------------------------------------------

describe('spot card: the anchors of 02_MODEL 8.3', () => {
  it('a clicked point has the terms of a truck at normal visibility with no host and no fee', () => {
    expect(POINT_TERMS).toEqual(office.terms);
  });

  it('A1, the office park: Thursday 11 AM to 2 PM reads 60 orders (33 to 93), Rough', () => {
    const rows = bestRows(office, 3);
    // 02_MODEL 4.7: Tuesday, Wednesday, Thursday at 11:00, in that order.
    expect(rows.map((r) => [r.slot.dow, r.slot.open, r.slot.close])).toEqual([
      [1, 660, 840],
      [2, 660, 840],
      [3, 660, 840],
    ]);
    expect(rows.map((r) => r.slot.how)).toEqual([35, 59, 83]);
    expect(rows[2].text).toBe('60 orders (33 to 93)');
    expect(rows[2].label).toBe('Rough');
    expect(rows[2].result.orders.value).toBeCloseTo(60.4938, 4);
    expect(rows[2].result.orders.low).toBeCloseTo(33.01, 2);
    expect(rows[2].result.orders.high).toBeCloseTo(93.19, 2);
    // The typical Thursday gives what the golden case gives for the dated Thursday without a forecast.
    const golden = anchorCase('A1').expected.orders;
    expect(rows[2].result.orders).toEqual(golden);
    expect(rows[0].text).toBe('67 orders (37 to 98)');
    expect(rows[0].label).toBe('Rough');
  });

  it('A2, the taproom: Thursday 5 PM to 8 PM reads 39 orders (21 to 62), Rough, and Friday is higher', () => {
    const rows = bestRows(taproom, 3);
    // 02_MODEL 4.7: Saturday, Friday, Thursday at 17:00.
    expect(rows.map((r) => r.slot.how)).toEqual([137, 113, 89]);
    expect(rows[2].slot).toMatchObject({ dow: 3, open: 1020, close: 1200 });
    expect(rows[2].text).toBe('39 orders (21 to 62)');
    expect(rows[2].label).toBe('Rough');
    expect(rows[2].result.orders).toEqual(anchorCase('A2').expected.orders);
    expect(rows[1].text).toBe('59 orders (32 to 92)');
    expect(rows[1].result.orders.value).toBeGreaterThan(rows[2].result.orders.value);
  });

  it('reads a window of the week strip as a day and two clock times, past midnight too', () => {
    expect(windowSlot({ start: 83, length: 3, total: 1 })).toEqual({ dow: 3, open: 660, close: 840, how: 83, hours: 3 });
    expect(windowSlot({ start: 118, length: 4, total: 1 })).toEqual({ dow: 4, open: 1320, close: 1560, how: 118, hours: 4 });
    expect(windowSlot({ start: 167, length: 2, total: 1 })).toEqual({ dow: 6, open: 1380, close: 1500, how: 167, hours: 2 });
  });
});

describe('spot card: who is here', () => {
  it('the office park at Thursday noon: office workers, about 440 people, all of the orders', () => {
    const who = whoIsHere(hourOf(office, 3, 12));
    expect(who.hasOrders).toBe(true);
    expect(who.nobody).toBe(false);
    expect(who.host).toBeNull();
    expect(who.people).toBeCloseTo(437.11, 2); // 02_MODEL 4.7: nearby_present
    expect(who.rows).toHaveLength(1);
    expect(who.rows[0]).toMatchObject({ segment: 'w_office', group: 'workers' });
    expect(who.rows[0].share).toBeCloseTo(1, 12);
    expect(who.rows[0].label).not.toBe('');
  });

  it('the taproom at Thursday 6 PM: the host first, about 74 people, and nobody else nearby', () => {
    const who = whoIsHere(hourOf(taproom, 3, 18));
    expect(who.hasOrders).toBe(true);
    expect(who.host).not.toBeNull();
    expect(who.host!.segment).toBe('v_nightlife');
    expect(who.host!.people).toBeCloseTo(74.4, 6); // 120 x 0.62
    expect(who.host!.share).toBeCloseTo(1, 12);
    expect(who.rows).toEqual([]);
    expect(who.all).toEqual([]);
    expect(who.nobody).toBe(true);
  });

  it('with no orders at the hour, lists who is around by people present', () => {
    const night = whoIsHere(hourOf(office, 3, 3));
    expect(night.hasOrders).toBe(false);
    for (const row of night.rows) expect(row.share).toBeNull();
    for (let i = 1; i < night.rows.length; i++) expect(night.rows[i - 1].people).toBeGreaterThanOrEqual(night.rows[i].people);
    expect(night.rows.length).toBeLessThanOrEqual(6);
  });

  it('keeps segments with at least 1 % of the orders, largest first, at most six', () => {
    const hour = hourOf(office, 3, 12);
    // Sixteen segments with orders 16, 15, ..., 1 (total 136): the two smallest fall under 1 %.
    const segments = hour.segments.map((s, i) => ({ ...s, nearby_present: 10 + i, orders: i === 15 ? 1 : i === 14 ? 1.2 : 16 - i }));
    const total = segments.reduce((sum, s) => sum + s.orders, 0);
    const who = whoIsHere({ ...hour, segments, orders: total, host: null });
    expect(who.rows).toHaveLength(6);
    expect(who.rows.map((r) => r.orders)).toEqual([16, 15, 14, 13, 12, 11]);
    expect(who.all).toHaveLength(16);
    expect(who.all.filter((r) => r.share !== null && r.share >= 0.01)).toHaveLength(14);
    // Fewer rows on request, and ties keep the model's segment order.
    expect(whoIsHere({ ...hour, segments, orders: total, host: null }, 3).rows).toHaveLength(3);
    const tied = hour.segments.map((s) => ({ ...s, nearby_present: 5, orders: 2 }));
    expect(whoIsHere({ ...hour, segments: tied, orders: 32, host: null }).rows.map((r) => r.segment)).toEqual(
      hour.segments.slice(0, 6).map((s) => s.segment),
    );
  });
});

describe('spot card: money and the drive', () => {
  const week = weekStrip(office.A, office.profile, office.terms, office.vectors, null);
  const slot = windowSlot(bestWindows(week, 3, 3, true)[2]);
  const thursday = windowOrders(
    office.A,
    office.profile,
    office.terms,
    office.vectors,
    null,
    typicalContext(office.A, 3),
    typicalContext(office.A, 4),
    slot.open,
    slot.close,
  );

  it('each order leaves $9.54 with no fee, and the margin after the share once the percentage is what is paid', () => {
    const money = stopMoney(office.profile, office.terms, thursday.orders);
    expect(orderLeaves(money, office.terms)).toBeCloseTo(9.541, 9); // 02_MODEL 4.9
    const fee: SpotTerms = { ...office.terms, fee_pct: 0.1, fee_min: 75 };
    const withFee = stopMoney(office.profile, fee, thursday.orders);
    // 60 orders x $15 x 10 % is $90.74, above the $75 minimum: the percentage is what is paid.
    expect(orderLeaves(withFee, fee)).toBeCloseTo(8.041, 9);
    const high: SpotTerms = { ...office.terms, fee_pct: 0.1, fee_min: 500 };
    expect(orderLeaves(stopMoney(office.profile, high, thursday.orders), high)).toBeCloseTo(9.541, 9);
  });

  it('a clicked point drives on a straight-line estimate, and says so', () => {
    const fuel = { price_per_gal: 4.195, source: 'eia' as const, area: 'R1Z', product: 'EPMR' as const, period: '2026-09-28' };
    const day = dayPlan(
      office.A,
      office.profile,
      {
        date: '2026-10-08',
        stops: [
          {
            id: 'point',
            kind: 'spot',
            spot_id: null,
            point: { lat: 38.96, lng: -77.36 },
            open_minute: slot.open,
            close_minute: slot.close,
            gap_before_unpaid: false,
            setup_minutes: null,
            teardown_minutes: null,
            terms: office.terms,
            vectors: office.vectors,
            event: null,
            catering: null,
          },
        ],
      },
      typicalWithFuel(office.A, 3, fuel),
      typicalWithFuel(office.A, 4, fuel),
      {},
      null,
    );
    const drive = driveSummary(day.timeline.legs, [], false);
    expect(drive).not.toBeNull();
    expect(drive!.straight).toBe(true);
    expect(drive!.label).toBe('Straight-line estimate');
    expect(drive!.reason).toBeNull();
    expect(drive!.outMinutes).toBeGreaterThan(0);
    expect(drive!.backMinutes).toBeGreaterThan(0);
    expect(drive!.miles).toBeGreaterThan(0);
    expect(day.stops[0].adds.break_even_orders).not.toBeNull();
    // The same legs with what the server sent for them: a Google leg, and a refused one.
    const google = day.timeline.legs.map((leg) => ({ ...leg, source: 'google' as const }));
    const sent = [
      { from_id: 'base', to_id: 'point', source: 'google_routes' as const, fallback_reason: null },
      { from_id: 'point', to_id: 'base', source: 'google_routes' as const, fallback_reason: null },
    ];
    expect(driveSummary(google, sent, true)!.label).toBe('Google drive time');
    expect(driveSummary(google, sent, false)!.label).toMatch(/^Google drive time, adjusted for .+ traffic$/);
    expect(driveSummary(google, sent, false)!.straight).toBe(false);
    const refused = [{ from_id: 'base', to_id: 'point', source: 'straight_line' as const, fallback_reason: 'no_key' as const }];
    expect(driveSummary(day.timeline.legs, refused, false)!.reason).toBe('Google drive times are not switched on for this server.');
    const mine = day.timeline.legs.map((leg) => ({ ...leg, source: 'override' as const }));
    expect(driveSummary(mine, sent, false)!.label).toBe('Your time');
    expect(driveSummary([], [], false)).toBeNull();
  });

  it('names the county of a point from the list of the region', () => {
    const counties = [
      { fips: '51059', name: 'Fairfax County', state: 'VA' },
      { fips: '11001', name: 'District of Columbia', state: 'DC' },
    ];
    expect(countyLabel('51059', counties)).toBe('Fairfax County, VA');
    expect(countyLabel('99999', counties)).toBeNull();
    expect(countyLabel(null, counties)).toBeNull();
    expect(countyLabel('51059', null)).toBeNull();
  });
});
