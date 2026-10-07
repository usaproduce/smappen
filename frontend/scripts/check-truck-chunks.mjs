#!/usr/bin/env node
// Truck Planner - build check for the lazy chunk (docs/truck-planner/05_FRONTEND.md 1.7, 5.9, 8.4).
//
//   npx vite build --outDir "$TMP/tp-build" --emptyOutDir     (never into public/app during a check)
//   node scripts/check-truck-chunks.mjs "$TMP/tp-build"
//
// /truck is the app's first lazy route. This script looks at a finished build and fails (exit 1)
// unless Truck Planner really is a chunk of its own, with the map engine in a second one:
//
//   1. exactly one assets/TruckPages-*.js;
//   2. the sentinel string (exported by utils/truck/model.ts and rendered by TruckGate, so it cannot
//      be tree-shaken) is in that file and in no other .js file;
//   3. no assets/index-*.js contains `cellToBoundary` (h3-js stayed out of the main bundle), and
//      neither does TruckPages: exactly one assets/MapPage-*.js holds it (the map engine is fetched
//      when the map is opened, not with the other truck pages);
//   4. index.html does not mention TruckPages- or MapPage- (the chunks are not preloaded);
//   5. the gzip sizes meet the bundle budget: TruckPages at most 260 kB, and the main chunk at most
//      8 kB above its size before Truck Planner.
//
// It prints every size it measured (kB = 1,000 bytes, gzip level 9). Exit 2 means the script was
// called wrongly.
//
// Options:
//   --main-baseline=<bytes>   gzip size of index-*.js before Truck Planner. Default 245406: the
//                             build of commit f3c49fc (906,686 bytes raw). When code outside Truck
//                             Planner has changed the main chunk since, build the commit before
//                             Truck Planner the same way and pass its figure. Also read from the
//                             environment variable TP_MAIN_BASELINE_GZIP.
//   --main-growth=<bytes>     allowed growth of the main chunk (default 8000)
//   --chunk-budget=<bytes>    allowed gzip size of TruckPages (default 260000)

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { gzipSync } from 'node:zlib';

const SENTINEL = 'tp-chunk-sentinel';
const H3_MARKER = 'cellToBoundary';
const KB = 1000;

const args = process.argv.slice(2);
const dirArg = args.find((a) => !a.startsWith('--'));

function option(name, envName, fallback) {
  const flag = args.find((a) => a.startsWith('--' + name + '='));
  const raw = flag ? flag.slice(name.length + 3) : envName ? process.env[envName] : undefined;
  if (raw === undefined || raw === '') return fallback;
  const value = Number(raw);
  if (!Number.isFinite(value) || value < 0) {
    console.error('check-truck-chunks: --' + name + ' must be a number of bytes, got ' + JSON.stringify(raw));
    process.exit(2);
  }
  return value;
}

if (!dirArg) {
  console.error('usage: node scripts/check-truck-chunks.mjs <build dir> [--main-baseline=<bytes>] [--main-growth=<bytes>] [--chunk-budget=<bytes>]');
  process.exit(2);
}

const buildDir = resolve(dirArg);
const assetsDir = join(buildDir, 'assets');
const indexHtml = join(buildDir, 'index.html');
if (!existsSync(assetsDir) || !existsSync(indexHtml)) {
  console.error('check-truck-chunks: ' + buildDir + ' is not a build (no index.html or no assets/)');
  process.exit(2);
}

const MAIN_BASELINE = option('main-baseline', 'TP_MAIN_BASELINE_GZIP', 245406);
const MAIN_GROWTH = option('main-growth', null, 8 * KB);
const CHUNK_BUDGET = option('chunk-budget', null, 260 * KB);

const kb = (bytes) => (bytes / KB).toFixed(1) + ' kB';
const gzipSize = (buffer) => gzipSync(buffer, { level: 9 }).length;

const scripts = readdirSync(assetsDir)
  .filter((name) => name.endsWith('.js'))
  .sort()
  .map((name) => {
    const buffer = readFileSync(join(assetsDir, name));
    return { name, raw: statSync(join(assetsDir, name)).size, gzip: gzipSize(buffer), text: buffer.toString('utf8') };
  });
const styles = readdirSync(assetsDir)
  .filter((name) => name.endsWith('.css'))
  .sort()
  .map((name) => {
    const buffer = readFileSync(join(assetsDir, name));
    return { name, raw: buffer.length, gzip: gzipSize(buffer) };
  });

const truckChunks = scripts.filter((s) => /^TruckPages-.*\.js$/.test(s.name));
const mainChunks = scripts.filter((s) => /^index-.*\.js$/.test(s.name));
const mapChunks = scripts.filter((s) => /^MapPage-.*\.js$/.test(s.name));
const html = readFileSync(indexHtml, 'utf8');

const failures = [];
const check = (ok, message) => {
  console.log((ok ? '  ok    ' : '  FAIL  ') + message);
  if (!ok) failures.push(message);
};

console.log('check-truck-chunks: ' + buildDir);
console.log('');
console.log('Scripts (raw / gzip):');
for (const s of scripts) console.log('  ' + s.name.padEnd(46) + kb(s.raw).padStart(11) + kb(s.gzip).padStart(11));
console.log('Stylesheets (raw / gzip):');
for (const s of styles) console.log('  ' + s.name.padEnd(46) + kb(s.raw).padStart(11) + kb(s.gzip).padStart(11));
console.log('');

// 1. One chunk.
check(
  truckChunks.length === 1,
  'exactly one assets/TruckPages-*.js (found ' + truckChunks.length + (truckChunks.length ? ': ' + truckChunks.map((s) => s.name).join(', ') : '') + ')',
);

// 2. The sentinel is in it and nowhere else.
const withSentinel = scripts.filter((s) => s.text.includes(SENTINEL)).map((s) => s.name);
check(
  truckChunks.length === 1 && withSentinel.length === 1 && withSentinel[0] === truckChunks[0].name,
  'the sentinel "' + SENTINEL + '" is in TruckPages-*.js and in no other script (found in: ' + (withSentinel.join(', ') || 'none') + ')',
);

// 3. h3-js stayed out of the main bundle.
check(mainChunks.length >= 1, 'a main chunk assets/index-*.js exists (found ' + mainChunks.length + ')');
const mainWithH3 = mainChunks.filter((s) => s.text.includes(H3_MARKER)).map((s) => s.name);
check(mainWithH3.length === 0, 'no assets/index-*.js contains ' + H3_MARKER + (mainWithH3.length ? ' (found in: ' + mainWithH3.join(', ') + ')' : ''));

// 3b. The map engine is a chunk of its own.
const withH3 = scripts.filter((s) => s.text.includes(H3_MARKER)).map((s) => s.name);
check(
  mapChunks.length === 1 && withH3.length === 1 && withH3[0] === mapChunks[0].name,
  H3_MARKER + ' is in exactly one assets/MapPage-*.js and in no other script (found in: ' + (withH3.join(', ') || 'none') + ')',
);

// 4. The chunks are not preloaded.
check(!html.includes('TruckPages-') && !html.includes('MapPage-'), 'index.html mentions neither TruckPages- nor MapPage-');

// 5. The bundle budget.
if (truckChunks.length === 1) {
  const chunk = truckChunks[0];
  check(chunk.gzip <= CHUNK_BUDGET, 'TruckPages gzip ' + kb(chunk.gzip) + ' is within the budget of ' + kb(CHUNK_BUDGET));
  if (mapChunks.length === 1) console.log('        (with the map chunk: ' + kb(chunk.gzip + mapChunks[0].gzip) + ' gzip in all)');
}
const mainGzip = mainChunks.reduce((sum, s) => sum + s.gzip, 0);
const growth = mainGzip - MAIN_BASELINE;
check(
  growth <= MAIN_GROWTH,
  'main chunk gzip ' + kb(mainGzip) + ' grew by ' + kb(growth) + ' over the baseline of ' + kb(MAIN_BASELINE) + ' (allowed: ' + kb(MAIN_GROWTH) + ')',
);

console.log('');
if (failures.length > 0) {
  console.error('check-truck-chunks: ' + failures.length + ' check(s) failed');
  process.exit(1);
}
console.log('check-truck-chunks: all checks passed');
