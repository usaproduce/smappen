// Truck Planner estimator - the port is pure and self-contained (docs/truck-planner/02_MODEL.md 1.5, 7).
//
// The estimator folder must run unchanged in Node, in the browser and in a Web Worker, and the same
// inputs must give the same output on every machine. This test reads its source files and fails on an
// import that leaves the folder, on any clock, random source, locale, environment or DOM access, and
// on the arithmetic shortcuts section 7 of the model document rules out.

import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import * as estimator from '../estimator/index';
import * as fastPath from '../estimator/fastPath';

const DIR = fileURLToPath(new URL('../estimator/', import.meta.url));
const FILES = readdirSync(DIR)
  .filter((name) => name.endsWith('.ts'))
  .sort();

// Source text without its comments and, unless keepStrings is set, with the contents of string
// literals blanked out, so that prose and seed text cannot trip (or hide from) the checks below. Line
// structure is kept.
function codeOnly(source: string, keepStrings = false): string {
  let out = '';
  let i = 0;
  const n = source.length;
  while (i < n) {
    const ch = source[i];
    const next = i + 1 < n ? source[i + 1] : '';
    if (ch === '/' && next === '/') {
      while (i < n && source[i] !== '\n') i += 1;
    } else if (ch === '/' && next === '*') {
      i += 2;
      while (i < n && !(source[i] === '*' && source[i + 1] === '/')) {
        if (source[i] === '\n') out += '\n';
        i += 1;
      }
      i += 2;
    } else if (ch === "'" || ch === '"' || ch === '`') {
      out += ch;
      i += 1;
      while (i < n && source[i] !== ch) {
        if (source[i] === '\\') {
          if (keepStrings) out += source[i];
          i += 1;
        }
        if (i < n && (keepStrings || source[i] === '\n')) out += source[i];
        i += 1;
      }
      out += ch;
      i += 1;
    } else {
      out += ch;
      i += 1;
    }
  }
  return out;
}

function importSpecifiers(source: string): string[] {
  const found: string[] = [];
  const pattern = /(?:\bfrom\s*|\bimport\s*\(\s*|\bimport\s+|\brequire\s*\(\s*)(['"])([^'"\n]+)\1/g;
  let m: RegExpExecArray | null;
  while ((m = pattern.exec(source)) !== null) found.push(m[2]);
  return found;
}

const FORBIDDEN: [RegExp, string][] = [
  [/\bDate\b/, 'the clock (Date)'],
  [/\bperformance\b/, 'the clock (performance)'],
  [/\bMath\s*\.\s*random\b/, 'a random source'],
  [/\bcrypto\b/, 'a random source (crypto)'],
  [/\bIntl\b/, 'the locale (Intl)'],
  [/\.\s*toLocale\w*\s*\(/, 'locale formatting'],
  [/\.\s*localeCompare\s*\(/, 'locale comparison (ids are compared code unit by code unit)'],
  [/\bMath\s*\.\s*round\b/, 'Math.round (only roundHalfAway rounds)'],
  [/\.\s*toFixed\s*\(/, 'toFixed (only roundHalfAway rounds)'],
  [/\.\s*toPrecision\s*\(/, 'toPrecision (only roundHalfAway rounds)'],
  [/\bMath\s*\.\s*pow\b/, 'Math.pow (write x * x)'],
  [/\*\*/, 'the power operator (write x * x)'],
  [/\bMath\s*\.\s*(min|max|trunc|hypot|fround)\b/, 'Math.min/max/trunc/hypot/fround (use min2, max2, floorDiv)'],
  [/\.\s*sort\s*\(\s*\)/, 'a sort without a comparator'],
  [/\.\s*toLowerCase\s*\(|\.\s*toUpperCase\s*\(/, 'Unicode case mapping (use asciiLower)'],
  // a field called `window` (DayStop.window) is a name of the contract, not the global: member
  // accesses and keys are skipped
  [
    /(?<![.\w$])(window|document|navigator|self|globalThis|localStorage|sessionStorage|indexedDB)\b(?!\s*[:?])/,
    'a browser global',
  ],
  [/(?<![.\w$])(process|require|module|__dirname|__filename|Buffer)\b(?!\s*[:?])/, 'a Node global'],
  [/\b(setTimeout|setInterval|requestAnimationFrame|queueMicrotask)\b/, 'a timer'],
  [/\b(fetch|XMLHttpRequest|WebSocket|EventSource|importScripts)\b/, 'network or script loading'],
  [/\bconsole\b/, 'console output'],
  [/\b(async|await|Promise)\b/, 'asynchronous code (the model is synchronous)'],
];

describe('estimator folder', () => {
  it('the scanner of this file sees what it is for', () => {
    const bad = [
      "import x from '../format';",
      "import { api } from 'axios';",
      "const y = await import('./nowhere');",
      'const t = Date.now();',
      'const r = Math.random();',
      'const s = n.toFixed(2);',
      'const q = Math.round(x);',
      'const p = x ** 2;',
      'const m = Math.max(a, b);',
      'ids.sort();',
      'const w = window.innerWidth;',
      'names.sort((a, b) => a.localeCompare(b));',
      'const lower = text.toLowerCase();',
    ].join('\n');
    expect(importSpecifiers(codeOnly(bad, true))).toEqual(['../format', 'axios', './nowhere']);
    const flagged = codeOnly(bad)
      .split('\n')
      .filter((line) => FORBIDDEN.some(([pattern]) => pattern.test(line)));
    expect(flagged.length).toBe(11); // every line after the two plain imports
    const fine = [
      '// Date, Math.random and window in a comment are prose',
      "const label = 'Date of the window'; /* Math.round */",
      'const stop = { window: served, class: holiday.class };',
      'const hours = st.window.hours.slice().sort((a, b) => a.hour - b.hour);',
    ].join('\n');
    expect(codeOnly(fine).split('\n').filter((line) => FORBIDDEN.some(([pattern]) => pattern.test(line)))).toEqual([]);
  });

  it('holds the port', () => {
    expect(FILES).toContain('index.ts');
    expect(FILES).toContain('types.ts');
    expect(FILES).toContain('fastPath.ts');
    expect(FILES).toContain('seeds.generated.ts');
    expect(FILES.length).toBeGreaterThanOrEqual(20);
  });

  it('imports only its own files', () => {
    const outside: string[] = [];
    for (const file of FILES) {
      if (file === 'seeds.generated.ts') continue; // data only: checked below
      for (const spec of importSpecifiers(codeOnly(readFileSync(DIR + file, 'utf8'), true))) {
        const ok = /^\.\/[A-Za-z0-9_.]+$/.test(spec) && FILES.includes(spec.slice(2) + '.ts');
        if (!ok) outside.push(`${file}: ${spec}`);
      }
    }
    expect(outside).toEqual([]);
  });

  it('reads no clock, random source, locale, environment or DOM, and takes no arithmetic shortcut', () => {
    const hits: string[] = [];
    for (const file of FILES) {
      const lines = codeOnly(readFileSync(DIR + file, 'utf8')).split('\n');
      lines.forEach((line, k) => {
        for (const [pattern, what] of FORBIDDEN) {
          if (pattern.test(line)) hits.push(`${file}:${k + 1}: ${what}: ${line.trim().slice(0, 100)}`);
        }
      });
    }
    expect(hits).toEqual([]);
  });

  it('types.ts has no runtime content', () => {
    const code = codeOnly(readFileSync(DIR + 'types.ts', 'utf8'));
    expect(/\bexport\s+(const|let|var|function|class|enum|default)\b/.test(code)).toBe(false);
    expect(/^\s*(const|let|var|function|class)\s+[A-Za-z_$]/m.test(code)).toBe(false); // `class:` is a field
  });

  it('the generated seed copy is data only', () => {
    const code = codeOnly(readFileSync(DIR + 'seeds.generated.ts', 'utf8'));
    expect(/\b(import|require|from)\b/.test(code)).toBe(false);
    expect(code.match(/\bexport\b/g)?.length).toBe(2); // the SEEDS constant and its type
    expect(/\bfunction\b|=>/.test(code)).toBe(false);
  });
});

describe('estimator exports', () => {
  const CATALOGUE = [
    'roundHalfAway', 'qkey', 'seed', 'validateOverrides', 'estFixed', 'estLevels', 'weakest', 'estSum',
    'daysFromCivil', 'civilFromDays', 'parseDate', 'formatDate', 'dayOfWeek', 'addDays', 'nthWeekday',
    'lastWeekday', 'federalHolidays', 'holidayOn', 'dayContext', 'typicalContext', 'makeContext', 'hourWeights',
    'expandCurves', 'haversineM', 'walkWeight', 'rivalsAtOrigin', 'hostExclusion', 'hostLinkPoint',
    'captureAtPoint', 'hostCapture', 'weatherMultiplier', 'calibrationFactor', 'hourlyOrders', 'vectorsMatch',
    'windowOrders', 'weekStrip', 'bestWindows', 'evidenceFrom', 'interval', 'intervalCapped', 'stopMoneyAt',
    'stopMoney', 'unitMargins', 'breakEvenOrders', 'dayCosts', 'fallbackLeg', 'trafficFactor', 'legMinutes',
    'requiredLegKeys', 'buildTimeline', 'evaluate', 'dayPlan', 'calibrate', 'accuracyReport', 'eventOrders',
    'cateringMoney', 'suggestDay', 'suggestWeek', 'scoutEstimate', 'stripFromRows', 'scoutRank', 'mapWeightRows',
    'cellScores', 'scoreByte',
  ];

  function snake(name: string): string {
    return name.replace(/[A-Z]/g, (ch) => '_' + ch.toLowerCase());
  }

  it('every catalogue function is exported in camelCase and dispatched under its canonical name', () => {
    const api = estimator as unknown as Record<string, unknown>;
    expect(CATALOGUE.length).toBe(64);
    for (const name of CATALOGUE) {
      expect(typeof api[name], name).toBe('function');
      expect(typeof estimator.GOLDEN_DISPATCH[snake(name)], snake(name)).toBe('function');
    }
    expect(Object.keys(estimator.GOLDEN_DISPATCH).length).toBe(CATALOGUE.length);
  });

  it('index.ts and fastPath.ts can be re-exported together without a clash', () => {
    const a = estimator as unknown as Record<string, unknown>;
    const b = fastPath as unknown as Record<string, unknown>;
    for (const name of Object.keys(b)) {
      if (name in a) expect(a[name], name).toBe(b[name]); // the same binding, not a second copy
    }
    for (const name of ['mapWeightRows', 'dayWeightRows', 'cellScores', 'scoreByte', 'precomputeMapWeights',
      'scoreCells', 'scoreLayer', 'scoreCellsWithRows', 'scoresToBytes', 'MAP_DOMAIN', 'FEATURE_COLUMNS']) {
      expect(name in b, name).toBe(true);
      expect(name in a, name).toBe(true);
    }
  });

  it('model errors carry their code', () => {
    const error = new estimator.ModelError('invalid_window');
    expect(error).toBeInstanceOf(Error);
    expect(error).toBeInstanceOf(estimator.ModelError);
    expect(error.code).toBe('invalid_window');
    expect(error.name).toBe('ModelError');
    expect(() => estimator.parseDate('2026-02-30')).toThrow(estimator.ModelError);
  });
});
