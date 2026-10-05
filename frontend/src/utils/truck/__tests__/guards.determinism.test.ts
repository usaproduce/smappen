// Source guard: same inputs, same output (docs/truck-planner/05_FRONTEND.md rule R8 and 8.3).
//
// No randomness anywhere. No clock, no locale and no time zone outside utils/truck/clock.ts, the one
// file that reads the clock and applies the truck's time zone. No runtime rounding: every rounding
// goes through roundHalfAway from the model (the map engine may use Math.round for pixel snapping
// and performance.now for its measurements). No intervals: playback and clocks use animation
// frames and aligned timeouts.

import { describe, expect, it } from 'vitest';
import { report, scan, truckFiles } from './_closure';

const CLOCK = 'utils/truck/clock.ts';
const MAP_DIRS = ['components/truck/map/', 'utils/truck/map/'];

const EVERYWHERE = [
  { what: 'Math.random', pattern: /\bMath\s*\.\s*random\b/ },
  { what: 'localeCompare', pattern: /\blocaleCompare\b/ },
  { what: 'setInterval(', pattern: /\bsetInterval\s*\(/ },
];

const OUTSIDE_CLOCK = [
  { what: 'new Date', pattern: /\bnew\s+Date\b/ },
  { what: 'Date.now / Date.parse / Date.UTC', pattern: /\bDate\.(now|parse|UTC)\b/ },
  { what: 'Date()', pattern: /\bDate\(\)/ },
  { what: 'Intl.', pattern: /\bIntl\./ },
  { what: 'crypto.', pattern: /\bcrypto\./ },
  { what: 'toISOString', pattern: /toISOString/ },
  { what: 'toLocale...', pattern: /toLocale/ },
];

const OUTSIDE_MAP = [
  { what: 'performance.now', pattern: /\bperformance\.now\b/ },
  { what: 'performance.timeOrigin', pattern: /\bperformance\.timeOrigin\b/ },
  { what: '.toFixed(', pattern: /\.toFixed\(/ },
  { what: 'Math.round(', pattern: /\bMath\.round\(/ },
];

describe('guard: determinism', () => {
  const scripts = truckFiles().filter((file) => /\.tsx?$/.test(file));

  it('has files to check', () => {
    expect(scripts.length).toBeGreaterThan(20);
    expect(scripts).toContain(CLOCK);
    expect(scripts).toContain('utils/truck/estimator/index.ts');
  });

  it('has no randomness, no locale comparison and no interval', () => {
    const hits = scan(scripts, EVERYWHERE);
    expect(hits, '\n' + report(hits) + '\n').toEqual([]);
  });

  it('reads the clock, the locale and the time zone only in utils/truck/clock.ts', () => {
    const hits = scan(scripts.filter((file) => file !== CLOCK), OUTSIDE_CLOCK);
    expect(hits, '\n' + report(hits) + '\n').toEqual([]);
  });

  it('rounds and measures time only inside the map engine', () => {
    const hits = scan(scripts.filter((file) => !MAP_DIRS.some((dir) => file.startsWith(dir))), OUTSIDE_MAP);
    expect(hits, '\n' + report(hits) + '\n').toEqual([]);
  });

  it('the clock file is the place that does read the clock', () => {
    expect(scan([CLOCK], OUTSIDE_CLOCK).length).toBeGreaterThan(0);
    expect(scan([CLOCK], EVERYWHERE)).toEqual([]);
  });

  it('would catch one (the guard itself works)', () => {
    const lines = [
      'const id = Math.random();',
      'const now = new Date();',
      'const t = Date.now();',
      'const s = new Intl.NumberFormat("en-US");',
      'x.toISOString()',
      'x.toLocaleString()',
      'x.toFixed(2)',
      'Math.round(x)',
      'a.localeCompare(b)',
      'setInterval(tick, 1000)',
      'performance.now()',
    ];
    const all = [...EVERYWHERE, ...OUTSIDE_CLOCK, ...OUTSIDE_MAP];
    for (const line of lines) {
      expect(all.some(({ pattern }) => pattern.test(line)), line).toBe(true);
    }
    // Allowed everywhere.
    for (const line of ['performance.mark("tp:tick")', 'performance.measure("tp:tick")', 'Math.floor(x)', 'window.setTimeout(f, 10)', 'roundHalfAway(x, 2)', 'addDays(date, 1)', 'validate_date']) {
      expect(all.some(({ pattern }) => pattern.test(line)), line).toBe(false);
    }
  });
});
