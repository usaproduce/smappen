#!/usr/bin/env node
// Operator helper: puts raw files that were downloaded by other means into the raw cache layout of
// docs/truck-planner/03_DATA.md section 3, so that `build-region.mjs --offline` can adopt them.
//
//   node tools/truck-etl/bin/adopt-raw.mjs --region=dc --from=<dir with the files> --raw-dir=<cache dir>
//        [--counties=<TIGERweb GeoJSON file>]
//
// Files are found by name anywhere under --from and hard-linked into place (copied when linking is not
// possible). Nothing is downloaded and no existing cache file is replaced. A Geofabrik extract named
// <state>-latest.osm.pbf gets its dated name from the replication timestamp in its header.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from '../src/args.mjs';
import { censusFiles, geofabrikFiles } from '../src/download.mjs';
import { readPbfHeader } from '../src/pbf.mjs';
import { loadRegion } from '../src/region.mjs';

const PACKAGE_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const CACHE_DIRS = new Set(['census', 'lodes8', 'geofabrik', 'tigerweb', 'overpass']);

function die(message) {
  process.stderr.write(`${message}\n`);
  process.exit(1);
}

/** Every file under `dir` by base name, skipping the cache layout itself when --from is the cache directory. */
function indexFiles(dir, skipCacheDirs) {
  const byName = new Map();
  const walk = (current, top) => {
    for (const entry of fs.readdirSync(current, { withFileTypes: true }).sort((a, b) => (a.name < b.name ? -1 : 1))) {
      const full = path.join(current, entry.name);
      if (entry.isDirectory()) {
        if (top && skipCacheDirs && CACHE_DIRS.has(entry.name)) continue;
        walk(full, false);
      } else if (entry.isFile()) {
        const list = byName.get(entry.name);
        if (list) list.push(full); else byName.set(entry.name, [full]);
      }
    }
  };
  walk(dir, true);
  return byName;
}

function yymmdd(epochSeconds) {
  const d = new Date(epochSeconds * 1000);
  const two = (n) => String(n).padStart(2, '0');
  return two(d.getUTCFullYear() % 100) + two(d.getUTCMonth() + 1) + two(d.getUTCDate());
}

function place(source, target, report) {
  if (fs.existsSync(target)) { report.push(`exists   ${target}`); return; }
  fs.mkdirSync(path.dirname(target), { recursive: true });
  try {
    fs.linkSync(source, target);
    report.push(`linked   ${target}  <-  ${source}`);
  } catch {
    fs.copyFileSync(source, target);
    report.push(`copied   ${target}  <-  ${source}`);
  }
}

function main() {
  let args;
  try {
    args = parseArgs(process.argv.slice(2), { values: ['region', 'region-file', 'from', 'raw-dir', 'counties'], flags: ['help'] });
  } catch (err) {
    die(err.message);
  }
  if (args.help || (!args.region && !args['region-file']) || !args['raw-dir']) {
    die('Usage: node tools/truck-etl/bin/adopt-raw.mjs --region=<id> --raw-dir=<cache dir> [--from=<dir>] [--counties=<geojson>]');
  }
  const regionFile = args['region-file'] ? path.resolve(args['region-file']) : path.join(PACKAGE_DIR, 'regions', `${args.region}.json`);
  const region = loadRegion(regionFile).doc;
  const rawDir = path.resolve(args['raw-dir']);
  const fromDir = path.resolve(args.from || rawDir);
  const found = indexFiles(fromDir, fromDir === rawDir);
  const report = [];
  const missing = [];

  const one = (names) => {
    for (const name of names) {
      const list = found.get(name);
      if (!list) continue;
      if (list.length > 1) die(`${name} exists more than once under ${fromDir}:\n  ${list.join('\n  ')}`);
      return list[0];
    }
    return null;
  };

  for (const spec of censusFiles(region)) {
    const target = path.join(rawDir, ...spec.path.split('/'));
    if (spec.kind === 'tigerweb') {
      if (args.counties) {
        const counties = path.resolve(args.counties);
        if (!fs.existsSync(counties)) die(`--counties: ${counties} does not exist`);
        place(counties, target, report);
      } else if (!fs.existsSync(target)) missing.push(spec.path);
      continue;
    }
    let source;
    if (spec.kind === 'lodes_version') {
      // Every state has a `version.txt`: it is told apart by its name (`va_version.txt`) or by its folder (`va/version.txt`).
      source = one([`${spec.state}_version.txt`]);
      if (source === null) {
        const inFolder = (found.get('version.txt') || []).filter((p) => path.basename(path.dirname(p)).toLowerCase() === spec.state);
        if (inFolder.length > 1) die(`${spec.state}/version.txt exists more than once under ${fromDir}:\n  ${inFolder.join('\n  ')}`);
        source = inFolder.length === 1 ? inFolder[0] : null;
      }
    } else {
      source = one([path.posix.basename(spec.path)]);
    }
    if (source) place(source, target, report);
    else if (!fs.existsSync(target) && !spec.sidecar) missing.push(spec.path);
  }

  for (const state of region.states) {
    const latest = one([`${state.geofabrik}-latest.osm.pbf`]);
    let date = null;
    let source = latest;
    if (latest) {
      const stamp = readPbfHeader(latest).replicationTimestamp;
      if (stamp === null) die(`${latest} has no replication timestamp; rename it to ${state.geofabrik}-YYMMDD.osm.pbf by hand`);
      date = yymmdd(stamp);
    } else {
      // No `-latest` file: take the newest dated extract of the state.
      for (const name of found.keys()) {
        const m = new RegExp(`^${state.geofabrik}-(\\d{6})\\.osm\\.pbf$`).exec(name);
        if (m && (date === null || m[1] > date)) date = m[1];
      }
      if (date !== null) source = one([`${state.geofabrik}-${date}.osm.pbf`]);
    }
    if (!source) {
      const dir = path.join(rawDir, 'geofabrik');
      const have = fs.existsSync(dir) && fs.readdirSync(dir).some((n) => new RegExp(`^${state.geofabrik}-\\d{6}\\.osm\\.pbf$`).test(n));
      if (!have) missing.push(`geofabrik/${state.geofabrik}-YYMMDD.osm.pbf`);
      continue;
    }
    for (const spec of geofabrikFiles(state, date)) {
      const target = path.join(rawDir, ...spec.path.split('/'));
      if (spec.kind === 'osm_pbf') { place(source, target, report); continue; }
      const md5 = one([`${path.basename(source)}.md5`, path.posix.basename(spec.path)]);
      if (md5) place(md5, target, report);
    }
  }

  if (report.length > 0) process.stdout.write(`${report.join('\n')}\n`);
  if (missing.length > 0) die(`Not found under ${fromDir}:\n  ${missing.join('\n  ')}`);
}

try {
  main();
} catch (err) {
  die(err.message);
}
