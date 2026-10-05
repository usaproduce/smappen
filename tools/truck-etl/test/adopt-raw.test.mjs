// The operator helper that puts files fetched by other means into the raw cache layout (03_DATA.md section 3).

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { after, test } from 'node:test';
import { RawCache, ensureRegionFiles } from '../src/download.mjs';
import { loadRegion } from '../src/region.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PACKAGE = path.resolve(HERE, '..');
const MINI = path.join(HERE, 'fixtures', 'mini');
const REGION_ARG = `--region-file=${path.join(MINI, 'mini.json')}`;
const tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'truck-etl-adopt-'));
after(() => fs.rmSync(tmpRoot, { recursive: true, force: true }));

const adopt = (...args) => spawnSync(process.execPath, [path.join(PACKAGE, 'bin', 'adopt-raw.mjs'), ...args], { encoding: 'utf8' });
const lines = (text) => text.split('\n').filter((l) => l !== '');

const CACHE_PATHS = [
  'census/pl2020/va2020.pl.zip',
  'geofabrik/virginia-261003.osm.pbf',
  'geofabrik/virginia-261003.osm.pbf.md5',
  'lodes8/va/lodes_va.sha256sum',
  'lodes8/va/va_wac_S000_JT00_2023.csv.gz',
  'lodes8/va/va_xwalk.csv.gz',
  'lodes8/va/version.txt',
  'tigerweb/counties_mini.geojson',
];

/** The fixture's raw files as someone has them after fetching them by hand: other folders, `-latest` names. */
function handFetched(dir) {
  const put = (from, to) => {
    fs.mkdirSync(path.dirname(path.join(dir, to)), { recursive: true });
    fs.copyFileSync(path.join(MINI, 'raw', from), path.join(dir, to));
  };
  put('census/pl2020/va2020.pl.zip', 'pl/va2020.pl.zip');
  put('lodes8/va/va_wac_S000_JT00_2023.csv.gz', 'lodes/va_wac_S000_JT00_2023.csv.gz');
  put('lodes8/va/va_xwalk.csv.gz', 'lodes/va_xwalk.csv.gz');
  put('lodes8/va/version.txt', 'lodes/va_version.txt');
  put('lodes8/va/lodes_va.sha256sum', 'lodes/lodes_va.sha256sum');
  put('geofabrik/virginia-261003.osm.pbf', 'osm/virginia-latest.osm.pbf');
  put('geofabrik/virginia-261003.osm.pbf.md5', 'osm/virginia-latest.osm.pbf.md5');
  put('tigerweb/counties_mini.geojson', 'boundaries/tigerweb_counties.geojson');
  return `--counties=${path.join(dir, 'boundaries', 'tigerweb_counties.geojson')}`;
}

function listFiles(dir) {
  const out = [];
  const walk = (current, rel) => {
    for (const entry of fs.readdirSync(current, { withFileTypes: true })) {
      if (entry.isDirectory()) walk(path.join(current, entry.name), `${rel}${entry.name}/`);
      else out.push(rel + entry.name);
    }
  };
  walk(dir, '');
  return out.sort();
}

test('adopt-raw: files fetched by hand get the cache layout, and an offline build accepts them', async () => {
  const from = path.join(tmpRoot, 'a', 'downloads');
  const raw = path.join(tmpRoot, 'a', 'raw');
  const countiesArg = handFetched(from);
  const before = listFiles(from);
  const r = adopt(REGION_ARG, `--from=${from}`, `--raw-dir=${raw}`, countiesArg);
  assert.equal(r.status, 0, r.stderr);
  assert.equal(r.stderr, '');

  // the layout of section 3; the extract has its dated name from the replication timestamp in its header
  assert.deepEqual(listFiles(raw), CACHE_PATHS);
  for (const rel of CACHE_PATHS) assert.deepEqual(fs.readFileSync(path.join(raw, rel)), fs.readFileSync(path.join(MINI, 'raw', rel)), rel);
  assert.equal(lines(r.stdout).length, 8);
  for (const line of lines(r.stdout)) assert.match(line, /^(linked|copied) {3}\S.* {2}<- {2}\S/);
  assert.deepEqual(listFiles(from), before); // nothing moved out of --from

  // the offline build adopts every file, with its checksum list
  const region = loadRegion(path.join(MINI, 'mini.json')).doc;
  const cache = new RawCache({ dir: raw, offline: true });
  const files = await ensureRegionFiles(cache, region, { placesSource: 'geofabrik', osmDate: null });
  assert.equal(files.osmDate, '261003');
  assert.deepEqual(cache.missingSidecars, []);
  assert.equal(cache.requests, 0);

  // a second call replaces nothing
  const again = adopt(REGION_ARG, `--from=${from}`, `--raw-dir=${raw}`, countiesArg);
  assert.equal(again.status, 0, again.stderr);
  assert.equal(lines(again.stdout).length, 8);
  for (const line of lines(again.stdout)) assert.match(line, /^exists {3}\S/);
});

test('adopt-raw: what cannot be found is listed and the exit code is 1; a checksum list is optional', () => {
  const from = path.join(tmpRoot, 'b', 'downloads');
  const raw = path.join(tmpRoot, 'b', 'raw');
  handFetched(from);
  fs.rmSync(path.join(from, 'lodes', 'va_xwalk.csv.gz'));
  fs.rmSync(path.join(from, 'lodes', 'lodes_va.sha256sum'));
  fs.rmSync(path.join(from, 'osm', 'virginia-latest.osm.pbf'));
  const r = adopt(REGION_ARG, `--from=${from}`, `--raw-dir=${raw}`); // and no --counties
  assert.equal(r.status, 1);
  const err = lines(r.stderr);
  assert.match(err[0], /^Not found under .*downloads:$/);
  assert.deepEqual(err.slice(1).map((l) => l.trim()), ['lodes8/va/va_xwalk.csv.gz', 'tigerweb/counties_mini.geojson', 'geofabrik/virginia-YYMMDD.osm.pbf']);
  // what was found is in place all the same
  assert.deepEqual(listFiles(raw), ['census/pl2020/va2020.pl.zip', 'lodes8/va/va_wac_S000_JT00_2023.csv.gz', 'lodes8/va/version.txt']);
});

test('adopt-raw: a dated extract keeps its date and the newest one is taken; version.txt is found by its folder', () => {
  const from = path.join(tmpRoot, 'c', 'downloads');
  const raw = path.join(tmpRoot, 'c', 'raw');
  const countiesArg = handFetched(from);
  fs.renameSync(path.join(from, 'osm', 'virginia-latest.osm.pbf'), path.join(from, 'osm', 'virginia-260930.osm.pbf'));
  fs.renameSync(path.join(from, 'osm', 'virginia-latest.osm.pbf.md5'), path.join(from, 'osm', 'virginia-260930.osm.pbf.md5'));
  fs.writeFileSync(path.join(from, 'osm', 'virginia-260815.osm.pbf'), 'an older extract');
  // the LODES folders as the server has them: one version.txt per state folder
  fs.mkdirSync(path.join(from, 'lodes', 'va'));
  fs.renameSync(path.join(from, 'lodes', 'va_version.txt'), path.join(from, 'lodes', 'va', 'version.txt'));
  fs.mkdirSync(path.join(from, 'lodes', 'md'));
  fs.writeFileSync(path.join(from, 'lodes', 'md', 'version.txt'), 'the version file of another state');
  const r = adopt(REGION_ARG, `--from=${from}`, `--raw-dir=${raw}`, countiesArg);
  assert.equal(r.status, 0, r.stderr);
  assert.deepEqual(listFiles(raw).filter((p) => p.startsWith('geofabrik/')), ['geofabrik/virginia-260930.osm.pbf', 'geofabrik/virginia-260930.osm.pbf.md5']);
  assert.deepEqual(fs.readFileSync(path.join(raw, 'geofabrik', 'virginia-260930.osm.pbf')), fs.readFileSync(path.join(MINI, 'raw', 'geofabrik', 'virginia-261003.osm.pbf')));
  assert.deepEqual(fs.readFileSync(path.join(raw, 'lodes8', 'va', 'version.txt')), fs.readFileSync(path.join(MINI, 'raw', 'lodes8', 'va', 'version.txt')));
});

test('adopt-raw: a name found twice, a missing county file and wrong usage stop with a message', () => {
  const from = path.join(tmpRoot, 'd', 'downloads');
  const countiesArg = handFetched(from);
  fs.mkdirSync(path.join(from, 'copy'));
  fs.copyFileSync(path.join(from, 'pl', 'va2020.pl.zip'), path.join(from, 'copy', 'va2020.pl.zip'));
  const twice = adopt(REGION_ARG, `--from=${from}`, `--raw-dir=${path.join(tmpRoot, 'd', 'raw')}`, countiesArg);
  assert.equal(twice.status, 1);
  assert.match(twice.stderr, /^va2020\.pl\.zip exists more than once under /);
  assert.equal(lines(twice.stderr).length, 3);

  const noCounties = adopt(REGION_ARG, `--from=${path.join(from, 'lodes')}`, `--raw-dir=${path.join(tmpRoot, 'd', 'raw2')}`, `--counties=${path.join(from, 'absent.geojson')}`);
  assert.equal(noCounties.status, 1);
  assert.match(noCounties.stderr, /^--counties: .*absent\.geojson does not exist\n$/);

  const usage = adopt('--region=dc');
  assert.equal(usage.status, 1);
  assert.match(usage.stderr, /^Usage: node tools\/truck-etl\/bin\/adopt-raw\.mjs /);
  const noRegion = adopt(`--region-file=${path.join(from, 'absent.json')}`, `--raw-dir=${path.join(tmpRoot, 'd', 'raw3')}`);
  assert.equal(noRegion.status, 1);
  assert.equal(lines(noRegion.stderr).length, 1); // a message, not a stack trace
});
