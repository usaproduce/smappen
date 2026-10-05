import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { after, before, test } from 'node:test';
import zlib from 'node:zlib';
import {
  DownloadError, RawCache, censusFiles, ensureRegionFiles, geofabrikDate, geofabrikFiles, manifestSources, snapshotDateFromYymmdd,
  tigerwebUrl, userAgent,
} from '../src/download.mjs';
import { loadRegion } from '../src/region.mjs';
import { writePbf } from '../test-support/pbf-writer.mjs';
import { writeZip } from '../test-support/zip-writer.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const MINI = path.join(HERE, 'fixtures', 'mini');
const tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'truck-etl-dl-'));
after(() => fs.rmSync(tmpRoot, { recursive: true, force: true }));
let dirCounter = 0;
const newDir = () => { const d = path.join(tmpRoot, `c${dirCounter++}`); fs.mkdirSync(d); return d; };
const sha = (buf) => crypto.createHash('sha256').update(buf).digest('hex');

// ---- a local HTTP server standing in for the download hosts ----
const csv = Buffer.from(`w_geocode,C000\n${'510594525011000,39466\n'.repeat(400)}`);
const csvGz = zlib.gzipSync(csv);
const served = new Map(); // path -> {body, etag?, lastModified?, ranges?, status?, location?, cutAfter?, cutWhen?}
const requests = []; // {method, url, headers}
let server;
let base;

before(async () => {
  server = http.createServer((req, res) => {
    requests.push({ method: req.method, url: req.url, headers: req.headers });
    const entry = served.get(req.url);
    if (!entry) { res.writeHead(404); res.end('not found'); return; }
    if (entry.status) { res.writeHead(entry.status, entry.location ? { Location: entry.location } : {}); res.end(); return; }
    const headers = { 'Content-Type': 'application/octet-stream' };
    if (entry.etag) headers.ETag = entry.etag;
    if (entry.lastModified) headers['Last-Modified'] = entry.lastModified;
    if (entry.ranges) headers['Accept-Ranges'] = 'bytes';
    if (entry.etag && req.headers['if-none-match'] === entry.etag) { res.writeHead(304, headers); res.end(); return; }
    if (!entry.etag && entry.lastModified && req.headers['if-modified-since'] === entry.lastModified) { res.writeHead(304, headers); res.end(); return; }
    const range = /^bytes=(\d+)-$/.exec(req.headers.range || '');
    if (range && entry.ranges && (!req.headers['if-range'] || req.headers['if-range'] === entry.etag)) {
      const from = Number(range[1]);
      if (from >= entry.body.length) {
        res.writeHead(416, { ...headers, 'Content-Range': `bytes */${entry.body.length}` });
        res.end();
        return;
      }
      const part = entry.body.subarray(from);
      res.writeHead(206, { ...headers, 'Content-Length': part.length, 'Content-Range': `bytes ${from}-${entry.body.length - 1}/${entry.body.length}` });
      res.end(part);
      return;
    }
    res.writeHead(200, { ...headers, 'Content-Length': entry.body.length });
    if (entry.cutAfter) {
      // The connection breaks in mid-file: the first bytes are sent, and the socket is destroyed once the
      // client has stored them (cutWhen), so the test does not depend on timing.
      res.write(entry.body.subarray(0, entry.cutAfter));
      const timer = setInterval(() => {
        if (entry.cutWhen()) { clearInterval(timer); res.destroy(); }
      }, 5);
      return;
    }
    res.end(entry.body);
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  base = `http://127.0.0.1:${server.address().port}`;
});
after(() => new Promise((resolve) => server.close(resolve)));

const clock = () => '2026-10-05T12:00:00.000Z';
const cacheFor = (dir, options = {}) => new RawCache({ dir, contact: 'ops@example.test', now: clock, ...options });
const sumsSpec = () => ({ kind: 'lodes_sums', state: 'va', path: 'lodes8/va/lodes_va.sha256sum', url: `${base}/va/lodes_va.sha256sum`, check: 'sums', sidecar: true });
const csvSpec = () => ({ kind: 'lodes_wac', state: 'va', path: 'lodes8/va/va_wac.csv.gz', url: `${base}/va/va_wac.csv.gz`, check: 'lodes_csv', sums: 'lodes8/va/lodes_va.sha256sum' });

test('names, URLs and the User-Agent', () => {
  assert.equal(userAgent('ops@example.test'), 'TruckPlanner-ETL/1.0.0 (contact: ops@example.test)');
  assert.equal(geofabrikDate('https://download.geofabrik.de/north-america/us/district-of-columbia-261003.osm.pbf'), '261003');
  assert.equal(geofabrikDate('virginia-latest.osm.pbf'), null);
  assert.equal(snapshotDateFromYymmdd('261003'), '2026-10-03');
  const url = tigerwebUrl(['11001', '54037']);
  assert.ok(url.startsWith('https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/State_County/MapServer/1/query?where='));
  assert.ok(url.includes(encodeURIComponent("GEOID IN ('11001','54037')")));
  assert.ok(url.endsWith('&outFields=GEOID,NAME,AREALAND,INTPTLAT,INTPTLON&returnGeometry=true&outSR=4326&geometryPrecision=5&f=geojson'));
});

test('the files of the dc region: cache paths and source URLs', () => {
  const dc = loadRegion(path.join(HERE, '..', 'regions', 'dc.json')).doc;
  const files = censusFiles(dc);
  const byPath = Object.fromEntries(files.map((f) => [f.path, f]));
  assert.equal(files.length, 4 + 4 * 4 + 1);
  assert.equal(byPath['census/pl2020/wv2020.pl.zip'].url,
    'https://www2.census.gov/programs-surveys/decennial/2020/data/01-Redistricting_File--PL_94-171/West_Virginia/wv2020.pl.zip');
  assert.equal(byPath['census/pl2020/wv2020.pl.zip'].immutable, true);
  assert.equal(byPath['lodes8/md/md_wac_S000_JT00_2023.csv.gz'].url, 'https://lehd.ces.census.gov/data/lodes/LODES8/md/wac/md_wac_S000_JT00_2023.csv.gz');
  assert.equal(byPath['lodes8/md/md_xwalk.csv.gz'].url, 'https://lehd.ces.census.gov/data/lodes/LODES8/md/md_xwalk.csv.gz');
  assert.equal(byPath['lodes8/dc/version.txt'].url, 'https://lehd.ces.census.gov/data/lodes/LODES8/dc/version.txt');
  assert.equal(byPath['lodes8/dc/lodes_dc.sha256sum'].url, 'https://lehd.ces.census.gov/data/lodes/LODES8/dc/lodes_dc.sha256sum');
  assert.equal(byPath['lodes8/dc/lodes_dc.sha256sum'].sidecar, true);
  assert.ok(byPath['tigerweb/counties_dc.geojson'].url.includes('tigerweb.geo.census.gov'));
  // a checksum list comes before the files it covers
  const order = files.map((f) => f.path);
  assert.ok(order.indexOf('lodes8/va/lodes_va.sha256sum') < order.indexOf('lodes8/va/va_wac_S000_JT00_2023.csv.gz'));
  const [md5, pbf] = geofabrikFiles(dc.states[0], '261003');
  assert.equal(pbf.path, 'geofabrik/district-of-columbia-261003.osm.pbf');
  assert.equal(pbf.url, 'https://download.geofabrik.de/north-america/us/district-of-columbia-latest.osm.pbf');
  assert.equal(pbf.datedUrl, 'https://download.geofabrik.de/north-america/us/district-of-columbia-261003.osm.pbf');
  assert.equal(md5.path, 'geofabrik/district-of-columbia-261003.osm.pbf.md5');
  assert.equal(md5.url, 'https://download.geofabrik.de/north-america/us/district-of-columbia-261003.osm.pbf.md5');
});

test('download: a fresh file is fetched, checked against its checksum list and indexed', async () => {
  const dir = newDir();
  served.set('/va/lodes_va.sha256sum', { body: Buffer.from(`${sha(csv)}  va_wac.csv\n`), lastModified: 'Wed, 03 Dec 2025 13:05:59 GMT' });
  served.set('/va/va_wac.csv.gz', { body: csvGz, etag: '"v1"', lastModified: 'Wed, 03 Dec 2025 13:05:59 GMT', ranges: true });
  requests.length = 0;
  const cache = cacheFor(dir);
  await cache.ensure(sumsSpec());
  const entry = await cache.ensure(csvSpec());
  assert.deepEqual(entry, {
    path: 'lodes8/va/va_wac.csv.gz', url: `${base}/va/va_wac.csv.gz`, final_url: `${base}/va/va_wac.csv.gz`, http_status: 200, bytes: csvGz.length,
    sha256: sha(csvGz), last_modified: 'Wed, 03 Dec 2025 13:05:59 GMT', etag: '"v1"', fetched_at: '2026-10-05T12:00:00.000Z',
  });
  assert.deepEqual(fs.readFileSync(path.join(dir, 'lodes8', 'va', 'va_wac.csv.gz')), csvGz);
  assert.equal(fs.existsSync(path.join(dir, 'lodes8', 'va', 'va_wac.csv.gz.part')), false);
  const index = JSON.parse(fs.readFileSync(path.join(dir, 'index.json'), 'utf8'));
  assert.deepEqual(index.map((e) => e.path), ['lodes8/va/lodes_va.sha256sum', 'lodes8/va/va_wac.csv.gz']);
  assert.deepEqual(Object.keys(index[1]), ['path', 'url', 'final_url', 'http_status', 'bytes', 'sha256', 'last_modified', 'etag', 'fetched_at']);
  assert.equal(requests.length, 2);
  for (const r of requests) assert.equal(r.headers['user-agent'], 'TruckPlanner-ETL/1.0.0 (contact: ops@example.test)');
  assert.deepEqual(cache.missingSidecars, []);
});

test('download: a mutable file is revalidated (304 keeps it, a changed file replaces it)', async () => {
  const dir = newDir();
  served.set('/va/lodes_va.sha256sum', { body: Buffer.from(`${sha(csv)}  va_wac.csv\n`), lastModified: 'Wed, 03 Dec 2025 13:05:59 GMT' });
  served.set('/va/va_wac.csv.gz', { body: csvGz, etag: '"v1"' });
  let cache = cacheFor(dir);
  await cache.ensure(sumsSpec());
  await cache.ensure(csvSpec());

  requests.length = 0;
  cache = cacheFor(dir);
  await cache.ensure(sumsSpec());
  const kept = await cache.ensure(csvSpec());
  assert.equal(requests.length, 2);
  assert.equal(requests[0].headers['if-modified-since'], 'Wed, 03 Dec 2025 13:05:59 GMT'); // no ETag: the date is the validator
  assert.equal(requests[1].headers['if-none-match'], '"v1"');
  assert.equal(kept.etag, '"v1"');

  // upstream publishes a new file and a new checksum list
  const csv2 = Buffer.concat([csv, Buffer.from('110010001011000,71\n')]);
  const gz2 = zlib.gzipSync(csv2);
  served.set('/va/lodes_va.sha256sum', { body: Buffer.from(`${sha(csv2)}  va_wac.csv\n`), lastModified: 'Thu, 03 Dec 2026 13:00:00 GMT' });
  served.set('/va/va_wac.csv.gz', { body: gz2, etag: '"v2"' });
  cache = cacheFor(dir);
  await cache.ensure(sumsSpec());
  const fresh = await cache.ensure(csvSpec());
  assert.deepEqual([fresh.etag, fresh.bytes, fresh.sha256], ['"v2"', gz2.length, sha(gz2)]);
  assert.deepEqual(fs.readFileSync(path.join(dir, 'lodes8', 'va', 'va_wac.csv.gz')), gz2);
});

test('download: an immutable file is never requested again; --offline never requests anything', async () => {
  const dir = newDir();
  served.set('/pl.zip', { body: writeZip([{ name: 'vageo2020.pl', data: Buffer.from('x|y\n') }]), lastModified: 'Thu, 12 Aug 2021 00:00:00 GMT' });
  const spec = { kind: 'census_pl', state: 'va', path: 'census/pl2020/va2020.pl.zip', url: `${base}/pl.zip`, check: 'pl', immutable: true };
  await cacheFor(dir).ensure(spec);
  requests.length = 0;
  await cacheFor(dir).ensure(spec);
  const offline = cacheFor(dir, { offline: true, contact: null });
  await offline.ensure(spec);
  assert.equal(requests.length, 0);
  assert.equal(offline.requests, 0);
});

test('download: a file that fails its check fails gate G1, is removed and is never indexed as complete', async () => {
  const dir = newDir();
  const csvPath = path.join(dir, 'lodes8', 'va', 'va_wac.csv.gz');
  const index = () => JSON.parse(fs.readFileSync(path.join(dir, 'index.json'), 'utf8'));
  served.set('/va/lodes_va.sha256sum', { body: Buffer.from(`${'0'.repeat(64)}  va_wac.csv\n`) });
  served.set('/va/va_wac.csv.gz', { body: csvGz, etag: '"v1"', ranges: true });
  const cache = cacheFor(dir);
  await cache.ensure(sumsSpec());
  await assert.rejects(cache.ensure(csvSpec()), (err) => err instanceof DownloadError && err.gate === 'G1'
    && /fails its SHA-256 check .*the file just downloaded was removed/.test(err.message));
  assert.equal(fs.existsSync(csvPath), false);
  assert.equal(fs.existsSync(`${csvPath}.part`), false);
  assert.deepEqual(index().map((e) => e.path), ['lodes8/va/lodes_va.sha256sum']);

  // once the checksum list is right, the next run fetches the file from its start
  served.set('/va/lodes_va.sha256sum', { body: Buffer.from(`${sha(csv)}  va_wac.csv\n`) });
  requests.length = 0;
  const second = cacheFor(dir);
  await second.ensure(sumsSpec());
  const entry = await second.ensure(csvSpec());
  assert.equal(requests[1].headers.range, undefined);
  assert.deepEqual([entry.http_status, entry.sha256], [200, sha(csvGz)]);
  assert.deepEqual(fs.readFileSync(csvPath), csvGz);

  // a new upstream file that fails its check does not replace the complete file of the cache
  const gz2 = zlib.gzipSync(Buffer.concat([csv, Buffer.from('110010001011000,71\n')]));
  served.set('/va/va_wac.csv.gz', { body: gz2, etag: '"v2"', ranges: true });
  const third = cacheFor(dir);
  await third.ensure(sumsSpec());
  await assert.rejects(third.ensure(csvSpec()), (err) => err.gate === 'G1' && /fails its SHA-256 check/.test(err.message));
  assert.deepEqual(fs.readFileSync(csvPath), csvGz);
  assert.equal(fs.existsSync(`${csvPath}.part`), false);
  assert.deepEqual(index().map((e) => [e.path, e.etag]), [['lodes8/va/lodes_va.sha256sum', null], ['lodes8/va/va_wac.csv.gz', '"v1"']]);

  // a checksum list without the file is also a failure
  served.set('/va/lodes_va.sha256sum', { body: Buffer.from(`${sha(csv)}  another_file.csv\n`) });
  const dir2 = newDir();
  const cache2 = cacheFor(dir2);
  await cache2.ensure(sumsSpec());
  await assert.rejects(cache2.ensure(csvSpec()), /has no checksum for va_wac\.csv/);
});

test('download: an interrupted download resumes with Range and If-Range', async () => {
  const dir = newDir();
  // 100 kB of content that passes the "sums" check (a checksum line first)
  const filler = crypto.createHash('sha512').update('seed').digest().subarray(0, 50);
  const content = Buffer.concat([Buffer.from(`${'a'.repeat(64)}  x.csv\n`), ...Array.from({ length: 2000 }, () => filler)]);
  served.set('/blob', { body: content, etag: '"b1"', ranges: true });
  const blobSpec = { kind: 'lodes_sums', state: 'va', path: 'blob.txt', url: `${base}/blob`, check: 'sums', immutable: true };
  // The first attempt was cut after 30 kB: it left a .part file and its index entry, as download() writes them.
  const cache = cacheFor(dir);
  fs.writeFileSync(path.join(dir, 'blob.txt.part'), content.subarray(0, 30000));
  cache.entries.set('blob.txt.part', {
    path: 'blob.txt.part', url: blobSpec.url, final_url: blobSpec.url, http_status: 200, bytes: null, sha256: null, last_modified: null,
    etag: '"b1"', fetched_at: clock(), accept_ranges: true,
  });
  cache.saveIndex();
  requests.length = 0;
  const entry = await cacheFor(dir).ensure(blobSpec);
  assert.equal(requests.length, 1);
  assert.equal(requests[0].headers.range, 'bytes=30000-');
  assert.equal(requests[0].headers['if-range'], '"b1"');
  assert.equal(entry.http_status, 206);
  assert.equal(entry.bytes, content.length);
  assert.deepEqual(fs.readFileSync(path.join(dir, 'blob.txt')), content);
  assert.equal(JSON.parse(fs.readFileSync(path.join(dir, 'index.json'), 'utf8')).some((e) => e.path.endsWith('.part')), false);
});

test('download: when the upstream file changed, the partial file is discarded and the download restarts', async () => {
  const dir = newDir();
  const line = Buffer.from(`${'b'.repeat(64)}  x.csv\n`);
  const content = Buffer.concat([line, Buffer.alloc(5000, 0x41)]);
  served.set('/blob2', { body: content, etag: '"new"', ranges: true });
  const spec = { kind: 'lodes_sums', state: 'va', path: 'blob2.txt', url: `${base}/blob2`, check: 'sums', immutable: true };
  const cache = cacheFor(dir);
  fs.writeFileSync(path.join(dir, 'blob2.txt.part'), Buffer.alloc(1000, 0x5a)); // bytes of the old version
  cache.entries.set('blob2.txt.part', { path: 'blob2.txt.part', url: spec.url, etag: '"old"', last_modified: null, accept_ranges: true });
  cache.saveIndex();
  requests.length = 0;
  const entry = await cacheFor(dir).ensure(spec);
  assert.equal(requests[0].headers['if-range'], '"old"');
  assert.equal(entry.http_status, 200); // the server ignored the range because the validator no longer matches
  assert.deepEqual(fs.readFileSync(path.join(dir, 'blob2.txt')), content);
});

test('download: a connection that breaks in mid-file keeps the partial file, and the next run resumes it', async () => {
  const dir = newDir();
  const filler = crypto.createHash('sha512').update('cut').digest().subarray(0, 50);
  const content = Buffer.concat([Buffer.from(`${'c'.repeat(64)}  x.csv\n`), ...Array.from({ length: 4000 }, () => filler)]);
  const part = path.join(dir, 'cut.txt.part');
  served.set('/cut', {
    body: content, etag: '"c1"', ranges: true, cutAfter: 20000, cutWhen: () => fs.existsSync(part) && fs.statSync(part).size >= 20000,
  });
  const spec = { kind: 'lodes_sums', state: 'va', path: 'cut.txt', url: `${base}/cut`, check: 'sums', immutable: true };
  await assert.rejects(cacheFor(dir).ensure(spec), (err) => err instanceof DownloadError && err.gate === undefined
    && /\/cut was interrupted after 20000 bytes of this response \(.+\); run again to resume$/.test(err.message));
  assert.equal(fs.existsSync(path.join(dir, 'cut.txt')), false);
  assert.deepEqual(fs.readFileSync(part), content.subarray(0, 20000));
  const partEntry = JSON.parse(fs.readFileSync(path.join(dir, 'index.json'), 'utf8')).find((e) => e.path === 'cut.txt.part');
  assert.deepEqual([partEntry.etag, partEntry.accept_ranges, partEntry.bytes], ['"c1"', true, null]);

  // the next run asks for the rest only
  served.get('/cut').cutAfter = 0;
  requests.length = 0;
  const entry = await cacheFor(dir).ensure(spec);
  assert.equal(requests.length, 1);
  assert.equal(requests[0].headers.range, 'bytes=20000-');
  assert.equal(requests[0].headers['if-range'], '"c1"');
  assert.deepEqual([entry.http_status, entry.bytes, entry.sha256], [206, content.length, sha(content)]);
  assert.deepEqual(fs.readFileSync(path.join(dir, 'cut.txt')), content);
  assert.equal(fs.existsSync(part), false);
});

test('download: a partial file the server cannot continue (HTTP 416) is removed, and the next run starts again', async () => {
  const dir = newDir();
  const content = Buffer.concat([Buffer.from(`${'d'.repeat(64)}  x.csv\n`), Buffer.alloc(3000, 0x42)]);
  served.set('/whole', { body: content, etag: '"w1"', ranges: true });
  const spec = { kind: 'lodes_sums', state: 'va', path: 'whole.txt', url: `${base}/whole`, check: 'sums', immutable: true };
  // the previous run had stored every byte and was stopped before the file got its name
  const cache = cacheFor(dir);
  fs.writeFileSync(path.join(dir, 'whole.txt.part'), content);
  cache.entries.set('whole.txt.part', { path: 'whole.txt.part', url: spec.url, etag: '"w1"', last_modified: null, accept_ranges: true });
  cache.saveIndex();
  requests.length = 0;
  await assert.rejects(cacheFor(dir).ensure(spec), (err) => err instanceof DownloadError
    && err.message.includes(`refused to resume at byte ${content.length} (HTTP 416); the partial file was removed`));
  assert.equal(requests.length, 1); // no silent retry
  assert.equal(fs.existsSync(path.join(dir, 'whole.txt.part')), false);
  assert.deepEqual(JSON.parse(fs.readFileSync(path.join(dir, 'index.json'), 'utf8')), []);
  requests.length = 0;
  const entry = await cacheFor(dir).ensure(spec);
  assert.equal(requests[0].headers.range, undefined);
  assert.deepEqual([entry.http_status, entry.bytes], [200, content.length]);
  assert.deepEqual(fs.readFileSync(path.join(dir, 'whole.txt')), content);
});

test('download: any unexpected status stops the run', async () => {
  const dir = newDir();
  served.set('/missing-sidecar', { status: 500 });
  const cache = cacheFor(dir);
  await assert.rejects(cache.ensure({ kind: 'lodes_sums', state: 'va', path: 'a.txt', url: `${base}/nothing-here`, check: 'sums' }), /answered HTTP 404/);
  await assert.rejects(cache.ensure({ kind: 'lodes_sums', state: 'va', path: 'b.txt', url: `${base}/missing-sidecar`, check: 'sums' }), /answered HTTP 500/);
  served.set('/redirect', { status: 302, location: `${base}/va/va_wac.csv.gz` });
  await assert.rejects(cache.ensure({ kind: 'lodes_sums', state: 'va', path: 'c.txt', url: `${base}/redirect`, check: 'sums' }), /answered HTTP 302/);
  assert.equal(fs.existsSync(path.join(dir, 'a.txt')), false);
  assert.equal(fs.existsSync(path.join(dir, 'a.txt.part')), false);
});

test('download: a request that cannot be made is reported with the address and the reason', async () => {
  const dir = newDir();
  const failing = (error) => async () => { throw error; };
  const spec = { ...sumsSpec(), url: 'https://lehd.example.test/va/lodes_va.sha256sum?token=1' };
  const refused = Object.assign(new TypeError('fetch failed'), { cause: Object.assign(new Error('connect ECONNREFUSED 127.0.0.1:9'), { code: 'ECONNREFUSED' }) });
  await assert.rejects(cacheFor(dir, { fetchImpl: failing(refused) }).ensure(spec), (err) => err instanceof DownloadError
    && err.message === 'download: https://lehd.example.test/va/lodes_va.sha256sum could not be requested (connect ECONNREFUSED 127.0.0.1:9)');
  // several addresses failed: the cause has a code and no message
  const aggregate = Object.assign(new TypeError('fetch failed'), { cause: Object.assign(new Error(''), { code: 'ETIMEDOUT' }) });
  await assert.rejects(cacheFor(dir, { fetchImpl: failing(aggregate) }).ensure(spec), /could not be requested \(ETIMEDOUT\)$/);
  await assert.rejects(cacheFor(dir, { fetchImpl: failing(new Error('socket hang up')) }).ensure(spec), /could not be requested \(socket hang up\)$/);
  // the snapshot date request reports the same way
  const region = { id: 'xx', states: [{ usps: 'aa', geofabrik: 'alpha' }] };
  await assert.rejects(cacheFor(dir, { fetchImpl: failing(refused) }).resolveOsmDate(region, null),
    /alpha-latest\.osm\.pbf could not be requested \(connect ECONNREFUSED 127\.0\.0\.1:9\)$/);
  assert.equal(fs.existsSync(path.join(dir, 'index.json')), false);
});

test('without a contact address no request is ever made: the cache is used as with --offline, a missing file stops the run', async () => {
  const dir = newDir();
  served.set('/va/lodes_va.sha256sum', { body: Buffer.from(`${sha(csv)}  va_wac.csv\n`), lastModified: 'Wed, 03 Dec 2025 13:05:59 GMT' });
  served.set('/va/va_wac.csv.gz', { body: csvGz, etag: '"v1"' });
  requests.length = 0;
  const cache = new RawCache({ dir, contact: null });
  assert.equal(cache.noRequests, true);
  await assert.rejects(cache.ensure(csvSpec()), (err) => err instanceof DownloadError && err.gate === undefined
    && /va_wac\.csv\.gz is missing and cannot be downloaded without a contact address: pass --contact=<email> or set TP_CONTACT_EMAIL \(source: http/.test(err.message));
  assert.equal(await cache.ensure(sumsSpec(), { optional: true }), null);
  // download() itself refuses to make a request without one
  await assert.rejects(cache.download(csvSpec(), null), /a contact address is required before any download/);
  assert.equal(requests.length, 0);

  // a complete cache: the mutable file is not revalidated, and a file without an index entry is adopted
  const online = cacheFor(dir);
  await online.ensure(sumsSpec());
  const fetched = await online.ensure(csvSpec());
  fs.writeFileSync(path.join(dir, 'lodes8', 'va', 'va_wac2.csv.gz'), csvGz);
  fs.appendFileSync(path.join(dir, 'lodes8', 'va', 'lodes_va.sha256sum'), `${sha(csv)}  va_wac2.csv\n`);
  const index = JSON.parse(fs.readFileSync(path.join(dir, 'index.json'), 'utf8'));
  fs.writeFileSync(path.join(dir, 'index.json'), JSON.stringify(index.filter((e) => e.path !== 'lodes8/va/lodes_va.sha256sum')));
  requests.length = 0;
  const quiet = new RawCache({ dir, contact: '' });
  await quiet.ensure(sumsSpec(), { optional: true });
  assert.deepEqual(await quiet.ensure(csvSpec()), fetched);
  const adopted = await quiet.ensure({ ...csvSpec(), path: 'lodes8/va/va_wac2.csv.gz' });
  assert.deepEqual([adopted.final_url, adopted.fetched_at, adopted.sha256], [null, null, sha(csvGz)]);
  assert.deepEqual([requests.length, quiet.requests], [0, 0]);
  assert.deepEqual(quiet.missingSidecars, []);

  // the snapshot date comes from the cache, and the message names the way out
  const region = { id: 'xx', states: [{ usps: 'aa', geofabrik: 'alpha' }] };
  await assert.rejects(quiet.resolveOsmDate(region, null), /no dated Geofabrik extract for every state of xx .*or pass --contact=<email> or set TP_CONTACT_EMAIL to download them$/);
  await assert.rejects(new RawCache({ dir, offline: true }).resolveOsmDate(region, null), /or run without --offline to download them$/);
});

test('offline: files placed in the cache are adopted after their check; a missing file is an error', async () => {
  const dir = newDir();
  fs.mkdirSync(path.join(dir, 'lodes8', 'va'), { recursive: true });
  fs.writeFileSync(path.join(dir, 'lodes8', 'va', 'va_wac.csv.gz'), csvGz);
  requests.length = 0;
  const cache = new RawCache({ dir, offline: true });
  // the checksum list is absent: allowed offline, recorded for the G1 warning
  assert.equal(await cache.ensure(sumsSpec(), { optional: true }), null);
  const entry = await cache.ensure(csvSpec());
  assert.deepEqual(entry, {
    path: 'lodes8/va/va_wac.csv.gz', url: `${base}/va/va_wac.csv.gz`, final_url: null, http_status: null, bytes: csvGz.length, sha256: sha(csvGz),
    last_modified: null, etag: null, fetched_at: null,
  });
  assert.deepEqual(cache.missingSidecars, ['lodes8/va/va_wac.csv.gz']);
  assert.equal(requests.length, 0);
  await assert.rejects(cache.ensure({ ...csvSpec(), path: 'lodes8/va/absent.csv.gz' }), /is missing and --offline forbids downloads/);
  // with the checksum list present the adopted file is verified against it
  fs.writeFileSync(path.join(dir, 'lodes8', 'va', 'lodes_va.sha256sum'), `${'f'.repeat(64)}  va_wac2.csv\n`);
  fs.writeFileSync(path.join(dir, 'lodes8', 'va', 'va_wac2.csv.gz'), csvGz);
  await assert.rejects(new RawCache({ dir, offline: true }).ensure({ ...csvSpec(), path: 'lodes8/va/va_wac2.csv.gz' }), /fails its SHA-256 check/);
});

test('a cached file that no longer matches its index entry is refused', async () => {
  const dir = newDir();
  fs.mkdirSync(path.join(dir, 'lodes8', 'va'), { recursive: true });
  const file = path.join(dir, 'lodes8', 'va', 'va_wac.csv.gz');
  fs.writeFileSync(file, csvGz);
  await new RawCache({ dir, offline: true }).ensure(csvSpec());
  fs.writeFileSync(file, zlib.gzipSync(Buffer.from('tampered')));
  await assert.rejects(new RawCache({ dir, offline: true }).ensure(csvSpec()), /no longer matches index\.json/);
});

test('PBF extracts: MD5 sidecar, header check, adoption', async () => {
  const dir = newDir();
  fs.mkdirSync(path.join(dir, 'geofabrik'));
  const pbf = writePbf({ nodes: [{ id: 1, latE7: 1, lngE7: 2 }] }, { replicationTimestamp: 1791058850 });
  const [md5Spec, pbfSpec] = geofabrikFiles({ usps: 'va', geofabrik: 'virginia' }, '261003');
  fs.writeFileSync(path.join(dir, 'geofabrik', 'virginia-261003.osm.pbf'), pbf);
  fs.writeFileSync(path.join(dir, 'geofabrik', 'virginia-261003.osm.pbf.md5'), `${crypto.createHash('md5').update(pbf).digest('hex')}  virginia-261003.osm.pbf\n`);
  const cache = new RawCache({ dir, offline: true });
  await cache.ensure(md5Spec, { optional: true });
  await cache.ensure(pbfSpec);
  assert.equal(cache.verified.get(pbfSpec.path).facts.header.replicationTimestamp, 1791058850);
  assert.deepEqual(cache.missingSidecars, []);

  const dir2 = newDir();
  fs.mkdirSync(path.join(dir2, 'geofabrik'));
  fs.writeFileSync(path.join(dir2, 'geofabrik', 'virginia-261003.osm.pbf'), pbf);
  fs.writeFileSync(path.join(dir2, 'geofabrik', 'virginia-261003.osm.pbf.md5'), `${'0'.repeat(32)}  virginia-261003.osm.pbf\n`);
  const cache2 = new RawCache({ dir: dir2, offline: true });
  await cache2.ensure(md5Spec, { optional: true });
  await assert.rejects(cache2.ensure(pbfSpec), (err) => err.gate === 'G1' && /fails its MD5 check/.test(err.message));

  const dir3 = newDir();
  fs.mkdirSync(path.join(dir3, 'geofabrik'));
  fs.writeFileSync(path.join(dir3, 'geofabrik', 'virginia-261003.osm.pbf'), Buffer.from('definitely not a pbf file, just some text'));
  await assert.rejects(new RawCache({ dir: dir3, offline: true }).ensure(pbfSpec), (err) => err.gate === 'G1');
});

test('snapshot date: every state must redirect to the same dated extract', async () => {
  const region = { id: 'xx', states: [{ usps: 'aa', geofabrik: 'alpha' }, { usps: 'bb', geofabrik: 'beta' }] };
  const seen = [];
  const fake = (locations) => async (url, init) => {
    seen.push([url, init.method, init.redirect, init.headers['User-Agent']]);
    const name = /\/([a-z]+)-latest\.osm\.pbf$/.exec(url)[1];
    const location = locations[name];
    return new Response(null, { status: location ? 307 : 200, headers: location ? { Location: location } : {} });
  };
  const dir = newDir();
  const ok = new RawCache({ dir, contact: 'ops@example.test', fetchImpl: fake({ alpha: 'https://download.geofabrik.de/north-america/us/alpha-261003.osm.pbf', beta: 'https://download.geofabrik.de/north-america/us/beta-261003.osm.pbf' }) });
  assert.equal(await ok.resolveOsmDate(region, null), '261003');
  assert.deepEqual(seen[0], ['https://download.geofabrik.de/north-america/us/alpha-latest.osm.pbf', 'HEAD', 'manual', 'TruckPlanner-ETL/1.0.0 (contact: ops@example.test)']);
  const mixed = new RawCache({ dir, contact: 'c', fetchImpl: fake({ alpha: 'https://x/alpha-261003.osm.pbf', beta: 'https://x/beta-261004.osm.pbf' }) });
  await assert.rejects(mixed.resolveOsmDate(region, null), /different dates \(261003 and 261004\)/);
  const none = new RawCache({ dir, contact: 'c', fetchImpl: fake({ alpha: 'https://x/alpha-261003.osm.pbf' }) });
  await assert.rejects(none.resolveOsmDate(region, null), /did not redirect to a dated extract/);
  // a pinned date needs no request
  const pinned = new RawCache({ dir, contact: 'c', fetchImpl: () => { throw new Error('no request expected'); } });
  assert.equal(await pinned.resolveOsmDate(region, '260930'), '260930');
  await assert.rejects(pinned.resolveOsmDate(region, '2026-09-30'), /six digits/);
});

test('snapshot date offline: the newest date for which every state is in the cache', async () => {
  const region = { id: 'xx', states: [{ usps: 'aa', geofabrik: 'alpha' }, { usps: 'bb', geofabrik: 'beta-gamma' }] };
  const dir = newDir();
  const cache = new RawCache({ dir, offline: true });
  await assert.rejects(cache.resolveOsmDate(region, null), /no dated Geofabrik extract for every state/);
  fs.mkdirSync(path.join(dir, 'geofabrik'));
  for (const name of ['alpha-260901.osm.pbf', 'beta-gamma-260901.osm.pbf', 'alpha-261003.osm.pbf', 'beta-gamma-261003.osm.pbf', 'alpha-261004.osm.pbf', 'beta-gamma-latest.osm.pbf']) {
    fs.writeFileSync(path.join(dir, 'geofabrik', name), 'x');
  }
  assert.equal(await cache.resolveOsmDate(region, null), '261003'); // 261004 lacks the second state
});

test('the mini fixture offline: every file is adopted and verified, sources are listed in order', async () => {
  const dir = newDir();
  fs.cpSync(path.join(MINI, 'raw'), dir, { recursive: true });
  const region = loadRegion(path.join(MINI, 'mini.json')).doc;
  const cache = new RawCache({ dir, offline: true });
  const raw = await ensureRegionFiles(cache, region, { placesSource: 'geofabrik', osmDate: null });
  assert.equal(raw.osmDate, '261003');
  assert.deepEqual(raw.lodes, [{ state: 'va', format: '8.4', vintage: '20251202_1657' }]);
  assert.equal(raw.pbfHeaders.va.replicationTimestamp, 1791058850);
  assert.deepEqual(Object.keys(raw.files.pl), ['va']);
  assert.ok(fs.existsSync(raw.files.pbf.va) && fs.existsSync(raw.files.wac.va) && fs.existsSync(raw.files.xwalk.va) && fs.existsSync(raw.files.counties));
  assert.deepEqual(cache.missingSidecars, []);
  const sources = manifestSources(cache, region);
  assert.deepEqual(sources.map((s) => s.kind), ['census_pl', 'lodes_wac', 'lodes_xwalk', 'lodes_version', 'tigerweb', 'osm_pbf']);
  assert.deepEqual(Object.keys(sources[0]), ['kind', 'state', 'path', 'url', 'final_url', 'bytes', 'sha256', 'last_modified']);
  assert.equal(sources[5].path, 'geofabrik/virginia-261003.osm.pbf');
  assert.equal(sources[5].url, 'https://download.geofabrik.de/north-america/us/virginia-latest.osm.pbf');
  assert.deepEqual([sources[5].final_url, sources[5].last_modified], [null, null]);
  assert.equal(sources[4].state, null);
  // the index now holds every file, sidecars included, and a second run reuses it unchanged
  const index = fs.readFileSync(path.join(dir, 'index.json'), 'utf8');
  assert.equal(JSON.parse(index).length, 8);
  await ensureRegionFiles(new RawCache({ dir, offline: true }), region, { placesSource: 'geofabrik', osmDate: '261003' });
  assert.equal(fs.readFileSync(path.join(dir, 'index.json'), 'utf8'), index);
  // with the tile source no extract is needed
  const tiles = await ensureRegionFiles(new RawCache({ dir, offline: true }), region, { placesSource: 'overpass-tiles', osmDate: null });
  assert.deepEqual([tiles.osmDate, Object.keys(tiles.files.pbf).length], [null, 0]);
});
