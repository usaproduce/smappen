// Source guard: honest numbers (docs/truck-planner/05_FRONTEND.md rule R4 and 8.3).
//
// Every estimate is rendered by RangeValue from an Estimate: the value, its range and its confidence
// label together. This test fails the build when a component formats one part of an estimate on its
// own, which is how a bare number ends up on screen: a call to fmtMoney, fmtMoneyCents, fmtCount,
// fmtCount1 or fmtPerHour whose first argument ends in .value, .low or .high.
//
// The few places where that is right (the three-column money table "Expected / Weak day / Strong
// day", chart ticks) say so on the line of the call:
//
//     fmtMoney(line.low)   // tp-allow-bare: weak-day column of the money table
//
// Scope: components/truck/, except ui/RangeValue.tsx, which is the one component that may.

import { describe, expect, it } from 'vitest';
import { code, lineOf, read, report, truckFiles, type Hit } from './_closure';

const FORMATTERS = ['fmtMoney', 'fmtMoneyCents', 'fmtCount', 'fmtCount1', 'fmtPerHour'];
const CALL = new RegExp('\\b(' + FORMATTERS.join('|') + ')\\s*\\(', 'g');
const ALLOW = /\/\/\s*tp-allow-bare:\s*\S/;
const EXEMPT = 'components/truck/ui/RangeValue.tsx';

/** The text of the first argument of a call whose opening parenthesis is at `open`. */
function firstArgument(text: string, open: number): string {
  let depth = 0;
  for (let i = open + 1; i < text.length; i++) {
    const c = text[i];
    if (c === '(' || c === '[' || c === '{') depth++;
    else if (c === ')' || c === ']' || c === '}') {
      if (depth === 0) return text.slice(open + 1, i);
      depth--;
    } else if (c === ',' && depth === 0) {
      return text.slice(open + 1, i);
    }
  }
  return text.slice(open + 1);
}

/** Calls in one source text that format a part of an estimate, with their 1-based line. */
function bareEstimateCalls(source: string, stripped: string): { line: number; call: string }[] {
  const rawLines = source.split('\n');
  const out: { line: number; call: string }[] = [];
  for (const m of stripped.matchAll(CALL)) {
    const open = (m.index ?? 0) + m[0].length - 1;
    const argument = firstArgument(stripped, open).trim();
    if (!/(\?\.|\.)\s*(value|low|high)\s*!?$/.test(argument)) continue;
    const line = lineOf(stripped, m.index ?? 0);
    if (ALLOW.test(rawLines[line - 1] ?? '')) continue;
    out.push({ line, call: m[1] + '(' + argument.replace(/\s+/g, ' ') + ')' });
  }
  return out;
}

describe('guard: honest numbers', () => {
  const files = truckFiles().filter(
    (file) => file.startsWith('components/truck/') && /\.tsx?$/.test(file) && file !== EXEMPT,
  );

  it('has components to check', () => {
    expect(files.length).toBeGreaterThan(10);
  });

  it('no component formats a part of an estimate on its own', () => {
    const hits: Hit[] = [];
    for (const file of files) {
      for (const found of bareEstimateCalls(read(file), code(file))) {
        hits.push({ file, line: found.line, what: 'bare estimate', text: found.call });
      }
    }
    expect(
      hits,
      '\n' + report(hits) + '\nRender the estimate with RangeValue, or mark the line: // tp-allow-bare: <reason>\n',
    ).toEqual([]);
  });

  it('would catch one (the guard itself works)', () => {
    const find = (source: string) => bareEstimateCalls(source, source).map((f) => f.call);
    expect(find('const a = fmtMoney(result.totals.take_home.value);')).toEqual(['fmtMoney(result.totals.take_home.value)']);
    expect(find('fmtCount(stop.orders.low)')).toEqual(['fmtCount(stop.orders.low)']);
    expect(find('fmtCount1( w?.orders?.high )')).toEqual(['fmtCount1(w?.orders?.high)']);
    expect(find('fmtPerHour(day.totals.take_home_per_hour.value)')).toHaveLength(1);
    expect(find('fmtMoneyCents(\n  lines[i]\n    .value,\n)')).toHaveLength(1);
    expect(find('fmtMoney(money(x).value)')).toHaveLength(1);
    // A reason on the line of the call lets it through; an empty marker does not.
    expect(find('fmtMoney(line.low) // tp-allow-bare: weak-day column of the money table')).toEqual([]);
    expect(find('fmtMoney(line.low) // tp-allow-bare:')).toHaveLength(1);
    // Plain numbers are not estimates.
    expect(find('fmtMoney(fuel.price_per_gal)')).toEqual([]);
    expect(find('fmtMoneyCents(leg.toll)')).toEqual([]);
    expect(find('fmtCount(counts.spots)')).toEqual([]);
    expect(find('fmtMoney(valueOf(x), e.value)')).toEqual([]);
    // Other formatters are not in scope.
    expect(find('fmtEstimate(e, "orders"); fmtRange(e, "money"); fmtDuration(leg.value)')).toEqual([]);
  });
});
