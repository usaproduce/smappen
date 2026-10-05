import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import {
  CorrectionsError, TREATMENT_NAMES, applyCorrections, automaticCap, capHaloBlock, loadCorrections, reviewRule, validateCorrections,
} from '../src/corrections.mjs';
import { loadRegion } from '../src/region.mjs';
import { SECTORS } from '../src/vocabulary.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const T = { total_min: 5000, single_sector_min: 2000, single_sector_share: 0.9 };

function close(actual, expected, tolerance = 1e-9) {
  assert.ok(Math.abs(actual - expected) <= tolerance * Math.max(1, Math.abs(expected)), `${actual} != ${expected}`);
}

/** Builds applyCorrections input from rows of [geoid, residents, {CNSxx: jobs} | null]. */
function county(rows, entries = {}) {
  const sorted = rows.slice().sort((a, b) => (a[0] < b[0] ? -1 : 1));
  const n = sorted.length;
  const input = {
    geoids: sorted.map((r) => r[0]),
    residents: Int32Array.from(sorted.map((r) => r[1])),
    c000: new Int32Array(n),
    raw: new Int32Array(n * 20),
    hasWac: new Uint8Array(n),
    entries: validateCorrections({ schema: 1, region: 'syn', version: '1', lodes: {}, entries }, 'syn'),
    thresholds: T,
  };
  sorted.forEach((r, i) => {
    if (!r[2]) return;
    input.hasWac[i] = 1;
    for (const [sector, jobs] of Object.entries(r[2])) {
      input.raw[i * 20 + SECTORS.indexOf(sector)] = jobs;
      input.c000[i] += jobs;
    }
  });
  return input;
}

const entry = (action, extra = {}) => ({ action, c000_at_review: 0, reviewed: '2026-10-05', reason: 'test', ...extra });
const total = (result, i) => { let s = 0; for (let k = 0; k < 20; k++) s += result.corrected[i * 20 + k]; return s; };

test('review rule A, B and both', () => {
  assert.equal(reviewRule(4999, 100, T), '');
  assert.equal(reviewRule(5000, 100, T), 'A');
  assert.equal(reviewRule(2000, 1800, T), 'B'); // exactly 90 %
  assert.equal(reviewRule(2000, 1799, T), '');
  assert.equal(reviewRule(1999, 1999, T), '');
  assert.equal(reviewRule(39466, 38385, T), 'AB');
  assert.equal(reviewRule(0, 0, T), '');
  assert.equal(automaticCap('A', T), 5000);
  assert.equal(automaticCap('B', T), 2000);
  assert.equal(automaticCap('AB', T), 2000);
});

test('a synthetic county: keep, spread with a cap, automatic spread, receivers by residents', () => {
  // County 99001: one school payroll block, one kept hospital, one unreviewed single-sector block, three ordinary blocks.
  const input = county([
    ['990010000000001', 100, { CNS15: 9000, CNS12: 1000 }], // entry: spread, cap 500
    ['990010000000002', 0, { CNS16: 6000 }], // entry: keep
    ['990010000000003', 50, { CNS20: 3000 }], // no entry: rule B, automatic cap 2000
    ['990010000000004', 300, { CNS07: 40 }],
    ['990010000000005', 700, null],
    ['990010000000006', 0, { CNS07: 10 }], // no residents: not a receiver
    ['990020000000001', 500, { CNS07: 5 }], // another county: receives nothing
  ], {
    990010000000001: entry('spread', { cap: 500, c000_at_review: 10000 }),
    990010000000002: entry('keep', { c000_at_review: 6000, confirmed: false }),
  });
  const r = applyCorrections(input);
  assert.deepEqual(r.rule, ['AB', 'AB', 'B', '', '', '', '']);
  assert.deepEqual([...r.treatment].map((t) => TREATMENT_NAMES[t]), ['spread', 'keep', 'auto_spread', '', '', '', '']);
  assert.deepEqual([...r.reduced], [1, 0, 1, 0, 0, 0, 0]);

  // block 1: f = 500 / 10000; block 3: f = 2000 / 3000
  close(r.corrected[0 * 20 + 14], 450);
  close(r.corrected[0 * 20 + 11], 50);
  close(total(r, 0), 500);
  close(total(r, 2), 2000);
  close(r.jobsSpread[0], 9500);
  close(r.jobsSpread[2], 1000);
  assert.equal(total(r, 1), 6000); // kept as reported: it has no residents, so it receives nothing either

  // receivers: blocks 4 (300 residents) and 5 (700); the kept hospital has none, block 6 has none
  close(r.corrected[3 * 20 + 14], 9000 * 0.95 * 0.3); // education from block 1
  close(r.corrected[3 * 20 + 11], 1000 * 0.95 * 0.3);
  close(r.corrected[3 * 20 + 19], 1000 * 0.3); // public administration from block 3
  close(total(r, 3), 40 + 10500 * 0.3);
  close(total(r, 4), 10500 * 0.7);
  assert.equal(total(r, 5), 10);
  assert.equal(total(r, 6), 5);

  // conservation and totals
  let sum = 0;
  for (let i = 0; i < 7; i++) sum += total(r, i);
  close(sum, 10000 + 6000 + 3000 + 40 + 10 + 5);
  close(r.totals.jobsSpread, 10500);
  assert.equal(r.totals.jobsDiscarded, 0);
  assert.equal(r.totals.jobsSpreadLost, 0);
  assert.equal(r.totals.blocksAdjusted, 2);
  assert.deepEqual([r.totals.flaggedBlocks, r.totals.flaggedJobs, r.totals.autoBlocks, r.totals.autoJobs], [3, 19000, 1, 3000]);
  assert.equal(r.totals.unconfirmedEntries, 1);
  assert.deepEqual(r.entryState.slice(0, 3), ['ok', 'ok', '']);
  assert.deepEqual([r.cap[0], r.cap[2], Number.isNaN(r.cap[1]), Number.isNaN(r.cap[3])], [500, 2000, true, true]);
});

test('a kept block with residents receives spread jobs, a treated block never does', () => {
  const input = county([
    ['990010000000001', 0, { CNS15: 6000 }], // automatic spread, cap 2000
    ['990010000000002', 100, { CNS16: 7000 }], // kept, has residents: a receiver
    ['990010000000003', 100, { CNS20: 2000 }], // rule B exactly at its cap: nothing removed, still not a receiver
    ['990010000000004', 200, null],
  ], { 990010000000002: entry('keep', { c000_at_review: 7000 }) });
  const r = applyCorrections(input);
  close(r.jobsSpread[0], 4000);
  close(total(r, 1), 7000 + 4000 * (100 / 300));
  assert.equal(total(r, 2), 2000); // at its cap and untouched
  assert.equal(r.reduced[2], 0);
  close(total(r, 3), 4000 * (200 / 300));
  assert.equal(r.totals.blocksAdjusted, 1);
});

test('cap and drop discard, sectors limit the action, a spread without receivers is lost', () => {
  const input = county([
    ['990010000000001', 10, { CNS15: 800, CNS07: 200 }], // cap 100 on CNS15 only
    ['990010000000002', 10, { CNS20: 400, CNS18: 100 }], // drop
    ['990020000000001', 0, { CNS14: 900 }], // spread in a county without residents: lost
    ['990010000000003', 30, { CNS07: 1 }],
  ], {
    990010000000001: entry('cap', { cap: 100, sectors: ['CNS15'], c000_at_review: 1000 }),
    990010000000002: entry('drop', { c000_at_review: 500 }),
    990020000000001: entry('spread', { c000_at_review: 900 }),
  });
  const r = applyCorrections(input);
  // geoids sorted: ...0001 (i 0), ...0002 (1), ...0003 (2), 99002...0001 (3)
  assert.equal(r.corrected[0 * 20 + 14], 100);
  assert.equal(r.corrected[0 * 20 + 6], 200);
  close(r.jobsDiscarded[0], 700);
  assert.equal(total(r, 1), 0);
  close(r.jobsDiscarded[1], 500);
  assert.equal(total(r, 3), 0);
  close(r.jobsLost[3], 900);
  assert.equal(r.jobsSpread[3], 0);
  assert.equal(total(r, 2), 1); // cap and drop spread nothing
  close(r.totals.jobsDiscarded, 1200);
  close(r.totals.jobsSpreadLost, 900);
  assert.equal(r.totals.jobsSpread, 0);
  assert.equal(r.totals.blocksAdjusted, 0);
  // discarded per sector covers cap, drop and the lost spread
  close(r.discardedBySector[14], 700);
  close(r.discardedBySector[19], 400);
  close(r.discardedBySector[17], 100);
  close(r.discardedBySector[13], 900);
  assert.deepEqual([...r.reduced], [1, 1, 0, 1]);
});

test('a cap above the current count removes nothing', () => {
  const input = county([
    ['990010000000001', 10, { CNS15: 300 }],
    ['990010000000002', 10, null],
  ], { 990010000000001: entry('spread', { cap: 500, c000_at_review: 300 }) });
  const r = applyCorrections(input);
  assert.equal(total(r, 0), 300);
  assert.equal(r.reduced[0], 0);
  assert.equal(r.jobsSpread[0], 0);
  assert.equal(total(r, 1), 0);
});

test('stale and orphan entries', () => {
  const input = county([
    ['990010000000001', 10, { CNS15: 6000 }],
    ['990010000000002', 10, { CNS15: 6000 }],
    ['990010000000003', 10, null], // an entry on a block without a WAC row is an orphan
  ], {
    990010000000001: entry('keep', { c000_at_review: 4799 }), // |6000 - 4799| / 4799 = 0.2503 > 0.25
    990010000000002: entry('keep', { c000_at_review: 4800 }), // exactly 0.25: not stale
    990010000000003: entry('spread', { c000_at_review: 100 }),
    990990000000009: entry('drop', { c000_at_review: 5 }), // outside the region
  });
  const r = applyCorrections(input);
  assert.deepEqual(r.entryState, ['stale', 'ok', '']);
  assert.deepEqual(r.orphans, ['990010000000003', '990990000000009']);
  assert.deepEqual([r.totals.staleEntries, r.totals.orphanEntries], [1, 2]);
  assert.equal(total(r, 0), 6000); // a stale entry is still applied as written
  assert.equal(r.treatment[2], 0); // an orphan entry is ignored
});

test('halo blocks: a flagged block is cut to the automatic cap, the excess discarded', () => {
  const raw = new Int32Array(20);
  raw[14] = 3000; raw[6] = 100;
  const out = new Float64Array(20);
  close(capHaloBlock(raw, 0, T, out), 1100); // rule B: 3100 jobs cut to 2000
  close(out[14], 3000 * (2000 / 3100));
  close(out[6], 100 * (2000 / 3100));
  raw[14] = 300;
  assert.equal(capHaloBlock(raw, 0, T, out), 0);
  assert.deepEqual([out[14], out[6]], [300, 100]);
  raw.fill(0); raw[0] = 3000; raw[1] = 3000; // rule A only: cap 5000
  close(capHaloBlock(raw, 0, T, out), 1000);
  close(out[0] + out[1], 5000);
});

test('corrections file validation', () => {
  const good = { schema: 1, region: 'dc', version: 'v', lodes: {}, entries: {} };
  assert.equal(validateCorrections(good, 'dc').size, 0);
  const bad = (patch, pattern) => assert.throws(() => validateCorrections({ ...good, ...patch }, 'dc'), pattern);
  bad({ schema: 2 }, /schema must be 1/);
  bad({ region: 'xx' }, /region is "xx"/);
  bad({ version: '' }, /version/);
  const e = (geoid, value) => ({ entries: { [geoid]: value } });
  bad(e('123', entry('keep')), /15-digit/);
  bad(e('110010002012001', entry('move')), /action must be/);
  bad(e('110010002012001', entry('cap')), /cap is required/);
  bad(e('110010002012001', entry('keep', { cap: 5 })), /not allowed for action keep/);
  bad(e('110010002012001', entry('drop', { cap: 5 })), /not allowed for action drop/);
  bad(e('110010002012001', entry('spread', { cap: -1 })), /cap must be/);
  bad(e('110010002012001', entry('spread', { sectors: ['CNS21'] })), /CNS01..CNS20/);
  bad(e('110010002012001', entry('spread', { sectors: [] })), /non-empty list/);
  bad(e('110010002012001', entry('spread', { sectors: ['CNS15', 'CNS15'] })), /twice/);
  bad(e('110010002012001', { ...entry('keep'), reason: ' ' }), /reason is required/);
  bad(e('110010002012001', { action: 'keep', reviewed: '2026-10-05', reason: 'r' }), /c000_at_review/);
  bad(e('110010002012001', { action: 'keep', c000_at_review: 1, reason: 'r' }), /reviewed/);
  bad(e('110010002012001', entry('keep', { confirmed: 'no' })), /confirmed must be/);
  const ok = validateCorrections({ ...good, ...e('110010002012001', entry('spread', { sectors: ['CNS20', 'CNS15'], confirmed: false })) }, 'dc');
  assert.deepEqual(ok.get('110010002012001').sectors, [14, 19]);
  assert.deepEqual([ok.get('110010002012001').cap, ok.get('110010002012001').confirmed], [0, false]);
  assert.throws(() => loadCorrections(path.join(HERE, 'no-such-file.json'), 'dc'), CorrectionsError);
});

test('the committed dc corrections file: one entry per flagged block of the first-pass review', () => {
  const file = path.join(HERE, '..', 'corrections', 'dc.jobs.json');
  const c = loadCorrections(file, 'dc');
  const region = loadRegion(path.join(HERE, '..', 'regions', 'dc.json')).doc;
  assert.equal(c.entries.size, region.checks.job_review.blocks);
  assert.equal(c.entries.size, 86);
  const counties = new Set(region.counties.map((x) => x.fips));
  let keep = 0;
  let sum = 0;
  for (const [geoid, entry2] of c.entries) {
    assert.ok(counties.has(geoid.slice(0, 5)), `${geoid} is outside the region`);
    assert.equal(entry2.confirmed, false, `${geoid}: every first-pass entry awaits the owner's confirmation`);
    assert.ok(entry2.reason.length >= 20);
    assert.ok(['keep', 'spread'].includes(entry2.action));
    if (entry2.action === 'keep') keep++;
    sum += entry2.c000AtReview;
  }
  assert.equal(sum, region.checks.job_review.jobs); // 572,153 jobs under review
  assert.equal(keep, 55);
  // the anchors of the region file are kept
  for (const anchor of region.checks.anchors) assert.equal(c.entries.get(anchor.geoid).action, 'keep');
  // the file is plain JSON with the documented header
  const doc = JSON.parse(fs.readFileSync(file, 'utf8'));
  assert.deepEqual(doc.lodes, { year: 2023, job_type: 'JT00', vintage: '20251202_1657' });
  assert.match(doc.version, /^\d{4}-\d{2}-\d{2}\.\d+$/);
});
