// Raw download cache (03_DATA.md section 3): one request at a time, resume, checksums, an index of what was
// fetched. With `offline`, or without a contact address, no request is ever made and files already in the cache
// are adopted after their check.

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { buildCounties } from './counties.mjs';
import { parseLodesVersion, parseSha256Sums } from './lodes.mjs';
import { readPbfHeader } from './pbf.mjs';
import { readZipMember } from './zip.mjs';

export class DownloadError extends Error {}

export const USER_AGENT_PRODUCT = 'TruckPlanner-ETL/1.0.0';

const CONTACT_HINT = 'pass --contact=<email> or set TP_CONTACT_EMAIL';
const CONTACT_REQUIRED = `a contact address is required before any download: ${CONTACT_HINT}`;

const PL_BASE = 'https://www2.census.gov/programs-surveys/decennial/2020/data/01-Redistricting_File--PL_94-171';
const LODES_BASE = 'https://lehd.ces.census.gov/data/lodes/LODES8';
const GEOFABRIK_BASE = 'https://download.geofabrik.de/north-america/us';
const TIGERWEB_QUERY = 'https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/State_County/MapServer/1/query';

/** `User-Agent` of every request. */
export function userAgent(contact) {
  return `${USER_AGENT_PRODUCT} (contact: ${contact})`;
}

/** TIGERweb request for the region's county polygons. */
export function tigerwebUrl(countyFips) {
  const where = `GEOID IN (${countyFips.map((f) => `'${f}'`).join(',')})`;
  return `${TIGERWEB_QUERY}?where=${encodeURIComponent(where)}&outFields=GEOID,NAME,AREALAND,INTPTLAT,INTPTLON`
    + '&returnGeometry=true&outSR=4326&geometryPrecision=5&f=geojson';
}

/** YYMMDD of a Geofabrik dated file name or URL, or null. */
export function geofabrikDate(nameOrUrl) {
  const m = /-(\d{6})\.osm\.pbf$/.exec(nameOrUrl);
  return m ? m[1] : null;
}

/** `YYMMDD` to `YYYY-MM-DD` (dated Geofabrik names are 20YY). */
export function snapshotDateFromYymmdd(yymmdd) {
  return `20${yymmdd.slice(0, 2)}-${yymmdd.slice(2, 4)}-${yymmdd.slice(4, 6)}`;
}

/**
 * The census, LODES and boundary files a region needs, in fetch order. `path` is relative to the raw directory.
 * @param {object} region region document
 */
export function censusFiles(region) {
  const files = [];
  const { segment, job_type: jobType, year } = region.lodes;
  for (const state of region.states) {
    const u = state.usps;
    files.push({
      kind: 'census_pl', state: u, path: `census/pl2020/${u}2020.pl.zip`, immutable: true,
      url: `${PL_BASE}/${state.pl_dir}/${u}2020.pl.zip`, check: 'pl',
    });
  }
  for (const state of region.states) {
    const u = state.usps;
    const dir = `lodes8/${u}`;
    const sums = `${dir}/lodes_${u}.sha256sum`;
    files.push({ kind: 'lodes_version', state: u, path: `${dir}/version.txt`, url: `${LODES_BASE}/${u}/version.txt`, check: 'lodes_version' });
    files.push({ kind: 'lodes_sums', state: u, path: sums, url: `${LODES_BASE}/${u}/lodes_${u}.sha256sum`, check: 'sums', sidecar: true });
    const wac = `${u}_wac_${segment}_${jobType}_${year}.csv.gz`;
    files.push({ kind: 'lodes_wac', state: u, path: `${dir}/${wac}`, url: `${LODES_BASE}/${u}/wac/${wac}`, check: 'lodes_csv', sums });
    files.push({ kind: 'lodes_xwalk', state: u, path: `${dir}/${u}_xwalk.csv.gz`, url: `${LODES_BASE}/${u}/${u}_xwalk.csv.gz`, check: 'lodes_csv', sums });
  }
  files.push({
    kind: 'tigerweb', state: null, path: `tigerweb/counties_${region.id}.geojson`,
    url: tigerwebUrl(region.counties.map((c) => c.fips)), check: 'tigerweb',
  });
  return files;
}

/** The dated Geofabrik extract of one state and its checksum sidecar. */
export function geofabrikFiles(state, yymmdd) {
  const name = `${state.geofabrik}-${yymmdd}.osm.pbf`;
  return [
    { kind: 'osm_md5', state: state.usps, path: `geofabrik/${name}.md5`, url: `${GEOFABRIK_BASE}/${name}.md5`, check: 'md5', sidecar: true, immutable: true },
    {
      kind: 'osm_pbf', state: state.usps, path: `geofabrik/${name}`, immutable: true, check: 'pbf', md5: `geofabrik/${name}.md5`,
      url: `${GEOFABRIK_BASE}/${state.geofabrik}-latest.osm.pbf`, datedUrl: `${GEOFABRIK_BASE}/${name}`,
    },
  ];
}

/** The useful part of a failed request's error (`fetch` wraps the network error in `cause`). */
function reasonOf(err) {
  const cause = err && err.cause;
  const parts = [];
  if (cause && cause.message) parts.push(String(cause.message));
  if (cause && cause.code && !parts.join(' ').includes(String(cause.code))) parts.push(String(cause.code));
  if (parts.length === 0) parts.push(String((err && err.message) || err));
  return parts.join(', ');
}

function hashFile(filePath, algorithm) {
  const hash = crypto.createHash(algorithm);
  const fd = fs.openSync(filePath, 'r');
  try {
    const buf = Buffer.alloc(1 << 22);
    for (;;) {
      const n = fs.readSync(fd, buf, 0, buf.length, null);
      if (n === 0) break;
      hash.update(n === buf.length ? buf : buf.subarray(0, n));
    }
  } finally {
    fs.closeSync(fd);
  }
  return hash.digest('hex');
}

/**
 * The raw cache: resolves, downloads, verifies and indexes raw files.
 */
export class RawCache {
  /**
   * @param {object} options
   * @param {string} options.dir raw directory
   * @param {boolean} [options.offline] never make a request
   * @param {string|null} [options.contact] contact address for the User-Agent. Without one no request is made either
   * @param {typeof fetch} [options.fetchImpl]
   * @param {() => string} [options.now] ISO 8601 clock for `fetched_at` (index.json only)
   * @param {(message: string) => void} [options.log]
   */
  constructor({ dir, offline = false, contact = null, fetchImpl = globalThis.fetch, now = () => new Date().toISOString(), log = () => {} }) {
    this.dir = dir;
    this.offline = offline;
    this.contact = contact;
    /** No request may be made: `--offline`, or no contact address to send with one (section 3, rule 1). */
    this.noRequests = offline || !contact;
    this.fetchImpl = fetchImpl;
    this.now = now;
    this.log = log;
    this.indexPath = path.join(dir, 'index.json');
    /** @type {Map<string, object>} */
    this.entries = new Map();
    /** files adopted or found without a checksum sidecar (gate G1, warn) */
    this.missingSidecars = [];
    /** every file verified by this run, by cache path */
    this.verified = new Map();
    this.requests = 0;
    if (fs.existsSync(this.indexPath)) {
      let list;
      try {
        list = JSON.parse(fs.readFileSync(this.indexPath, 'utf8'));
      } catch (err) {
        throw new DownloadError(`raw cache: ${this.indexPath} is not valid JSON: ${err.message}`);
      }
      if (!Array.isArray(list)) throw new DownloadError(`raw cache: ${this.indexPath} must hold a list of entries`);
      for (const entry of list) this.entries.set(entry.path, entry);
    }
  }

  abs(relPath) {
    return path.join(this.dir, ...relPath.split('/'));
  }

  saveIndex() {
    fs.mkdirSync(this.dir, { recursive: true });
    const list = [...this.entries.values()].sort((a, b) => (a.path < b.path ? -1 : a.path > b.path ? 1 : 0));
    const tmp = `${this.indexPath}.tmp`;
    fs.writeFileSync(tmp, `${JSON.stringify(list, null, 2)}\n`);
    fs.renameSync(tmp, this.indexPath);
  }

  /** Runs the check of a file. A failure is a failure of gate G1 and stops the build. */
  verify(spec, filePath) {
    try {
      return this.check(spec, filePath);
    } catch (err) {
      err.gate = 'G1';
      throw err;
    }
  }

  /** The per-kind check of section 3 on a complete local file. Returns facts read from the file. */
  check(spec, filePath) {
    const size = fs.statSync(filePath).size;
    if (size === 0) throw new DownloadError(`raw cache: ${spec.path} is empty`);
    switch (spec.check) {
      case 'pl': {
        readZipMember(fs.readFileSync(filePath), `${spec.state}geo2020.pl`);
        return {};
      }
      case 'lodes_version': {
        return parseLodesVersion(fs.readFileSync(filePath, 'utf8'));
      }
      case 'sums': {
        if (parseSha256Sums(fs.readFileSync(filePath, 'utf8')).size === 0) throw new DownloadError(`raw cache: ${spec.path} holds no checksum line`);
        return {};
      }
      case 'lodes_csv': {
        let csv;
        try {
          csv = zlib.gunzipSync(fs.readFileSync(filePath));
        } catch (err) {
          throw new DownloadError(`raw cache: ${spec.path} does not open as gzip: ${err.message}`);
        }
        const sumsPath = this.abs(spec.sums);
        if (!fs.existsSync(sumsPath)) {
          this.missingSidecars.push(spec.path);
          return {};
        }
        const name = path.posix.basename(spec.path).replace(/\.gz$/, '');
        const expected = parseSha256Sums(fs.readFileSync(sumsPath, 'utf8')).get(name);
        if (!expected) throw new DownloadError(`raw cache: ${spec.sums} has no checksum for ${name}`);
        const actual = crypto.createHash('sha256').update(csv).digest('hex');
        if (actual !== expected) throw new DownloadError(`raw cache: ${spec.path} fails its SHA-256 check (uncompressed ${actual}, expected ${expected})`);
        return {};
      }
      case 'tigerweb': {
        let doc;
        try {
          doc = JSON.parse(fs.readFileSync(filePath, 'utf8'));
        } catch (err) {
          throw new DownloadError(`raw cache: ${spec.path} is not valid JSON: ${err.message}`);
        }
        buildCounties(doc, spec.countyFips);
        return {};
      }
      case 'md5': {
        if (!/^[0-9a-f]{32}\s/i.test(fs.readFileSync(filePath, 'utf8'))) throw new DownloadError(`raw cache: ${spec.path} holds no MD5 line`);
        return {};
      }
      case 'pbf': {
        const header = readPbfHeader(filePath);
        const md5Path = this.abs(spec.md5);
        if (!fs.existsSync(md5Path)) {
          this.missingSidecars.push(spec.path);
          return { header };
        }
        const expected = fs.readFileSync(md5Path, 'utf8').trim().split(/\s+/)[0].toLowerCase();
        const actual = hashFile(filePath, 'md5');
        if (actual !== expected) throw new DownloadError(`raw cache: ${spec.path} fails its MD5 check (${actual}, expected ${expected})`);
        return { header };
      }
      default:
        throw new DownloadError(`raw cache: unknown check ${spec.check}`);
    }
  }

  /**
   * Makes one raw file available and verified, and returns its index entry.
   * @param {object} spec file description from censusFiles or geofabrikFiles
   * @param {{optional?: boolean}} [options] an optional file (a checksum sidecar) may be missing when no request is made
   * @returns {Promise<object|null>} the index entry, or null for a missing optional file
   */
  async ensure(spec, { optional = false } = {}) {
    const filePath = this.abs(spec.path);
    const exists = fs.existsSync(filePath);
    let entry = this.entries.get(spec.path);

    if (exists && entry) {
      const bytes = fs.statSync(filePath).size;
      const sha256 = hashFile(filePath, 'sha256');
      if (bytes !== entry.bytes || sha256 !== entry.sha256) {
        throw new DownloadError(`raw cache: ${spec.path} no longer matches index.json (size or SHA-256 changed). `
          + 'Remove the file to fetch it again, or remove its index entry to adopt it with --offline.');
      }
      if (!this.noRequests && !spec.immutable) {
        // Mutable upstream file: revalidate with a conditional request.
        const fresh = await this.download(spec, entry);
        if (fresh) {
          this.verified.set(spec.path, { spec, entry: fresh.entry, facts: fresh.facts });
          return fresh.entry;
        }
      }
      const facts = this.verify(spec, filePath);
      this.verified.set(spec.path, { spec, entry, facts });
      return entry;
    }

    if (exists && !entry) {
      if (!this.noRequests) {
        this.log(`raw cache: ${spec.path} has no index entry; fetching it again`);
        const fetched = await this.download(spec, null);
        this.verified.set(spec.path, { spec, entry: fetched.entry, facts: fetched.facts });
        return fetched.entry;
      }
      // Adoption: the file was placed in the cache by hand.
      const facts = this.verify(spec, filePath);
      entry = {
        path: spec.path, url: spec.url, final_url: null, http_status: null,
        bytes: fs.statSync(filePath).size, sha256: hashFile(filePath, 'sha256'),
        last_modified: null, etag: null, fetched_at: null,
      };
      this.entries.set(spec.path, entry);
      this.saveIndex();
      this.log(`raw cache: adopted ${spec.path}`);
      this.verified.set(spec.path, { spec, entry, facts });
      return entry;
    }

    if (this.noRequests) {
      if (optional) return null;
      const source = `(source: ${spec.datedUrl || spec.url})`;
      throw new DownloadError(this.offline
        ? `raw cache: ${spec.path} is missing and --offline forbids downloads ${source}`
        : `raw cache: ${spec.path} is missing and cannot be downloaded without a contact address: ${CONTACT_HINT} ${source}`);
    }
    const fetched = await this.download(spec, null);
    this.verified.set(spec.path, { spec, entry: fetched.entry, facts: fetched.facts });
    return fetched.entry;
  }

  /** One request. A network failure becomes a DownloadError that names the address. */
  async request(url, init) {
    this.requests++;
    try {
      return await this.fetchImpl(url, init);
    } catch (err) {
      throw new DownloadError(`download: ${url.split('?')[0]} could not be requested (${reasonOf(err)})`);
    }
  }

  /** Removes a partial download and its index entry, so that the next run starts the file again. */
  discardPart(spec) {
    fs.rmSync(`${this.abs(spec.path)}.part`, { force: true });
    if (this.entries.delete(`${spec.path}.part`)) this.saveIndex();
  }

  /**
   * One GET with resume and conditional headers. The body goes to `<name>.part`; the file gets its name and its
   * index entry only after its check has passed, so the cache never holds a file that failed it.
   * @param {object} spec
   * @param {object|null} current the existing complete entry, when revalidating
   * @returns {Promise<{entry: object, facts: object}|null>} null on 304 (the existing file is current)
   */
  async download(spec, current) {
    if (!this.contact) {
      throw new DownloadError(CONTACT_REQUIRED);
    }
    const url = spec.datedUrl || spec.url;
    const shown = url.split('?')[0];
    const filePath = this.abs(spec.path);
    const partPath = `${filePath}.part`;
    const partKey = `${spec.path}.part`;
    fs.mkdirSync(path.dirname(filePath), { recursive: true });

    const headers = { 'User-Agent': userAgent(this.contact), 'Accept-Encoding': 'identity' };
    let resumeFrom = 0;
    const partEntry = this.entries.get(partKey);
    if (fs.existsSync(partPath) && partEntry && partEntry.accept_ranges && (partEntry.etag || partEntry.last_modified)) {
      resumeFrom = fs.statSync(partPath).size;
      if (resumeFrom > 0) {
        headers.Range = `bytes=${resumeFrom}-`;
        headers['If-Range'] = partEntry.etag || partEntry.last_modified;
      }
    } else if (current) {
      if (current.etag) headers['If-None-Match'] = current.etag;
      else if (current.last_modified) headers['If-Modified-Since'] = current.last_modified;
    }

    this.log(`GET ${shown}${resumeFrom > 0 ? ` (resume at ${resumeFrom})` : ''}`);
    const res = await this.request(url, { headers, redirect: 'manual' });
    if (res.status === 304 && current && resumeFrom === 0) {
      if (res.body) await res.body.cancel();
      return null;
    }
    if (res.status === 416 && resumeFrom > 0) {
      // The partial file is not a prefix of what the server holds now (or it is already the whole file).
      if (res.body) await res.body.cancel();
      this.discardPart(spec);
      throw new DownloadError(`download: ${shown} refused to resume at byte ${resumeFrom} (HTTP 416); `
        + 'the partial file was removed, run again to fetch the file from its start');
    }
    if (res.status !== 200 && !(res.status === 206 && resumeFrom > 0)) {
      if (res.body) await res.body.cancel();
      throw new DownloadError(`download: ${shown} answered HTTP ${res.status}`);
    }
    const append = res.status === 206 && resumeFrom > 0;
    const etag = res.headers.get('etag');
    const lastModified = res.headers.get('last-modified');
    const acceptRanges = (res.headers.get('accept-ranges') || '').toLowerCase() === 'bytes';
    const contentLength = res.headers.get('content-length');
    this.entries.set(partKey, {
      path: partKey, url: spec.url, final_url: url, http_status: res.status, bytes: null, sha256: null,
      last_modified: lastModified, etag, fetched_at: this.now(), accept_ranges: acceptRanges,
    });
    this.saveIndex();

    const fd = fs.openSync(partPath, append ? 'a' : 'w');
    let received = 0;
    try {
      if (res.body) {
        const reader = res.body.getReader();
        for (;;) {
          let chunk;
          try {
            chunk = await reader.read();
          } catch (err) {
            throw new DownloadError(`download: ${shown} was interrupted after ${received} bytes of this response (${reasonOf(err)}); `
              + 'run again to resume');
          }
          if (chunk.done) break;
          fs.writeSync(fd, chunk.value);
          received += chunk.value.length;
        }
      }
    } finally {
      fs.closeSync(fd);
    }
    if (contentLength !== null && !res.headers.get('content-encoding') && Number(contentLength) !== received) {
      throw new DownloadError(`download: ${shown} ended after ${received} of ${contentLength} bytes; run again to resume`);
    }

    // The check of section 3 runs before the file gets its name. A file that fails it is removed, and an older
    // complete file of the same name stays as it was.
    let facts;
    try {
      facts = this.check(spec, partPath);
    } catch (err) {
      this.discardPart(spec);
      err.gate = 'G1';
      err.message = `${err.message} (the file just downloaded was removed; run again to fetch it afresh)`;
      throw err;
    }
    const entry = {
      path: spec.path, url: spec.url, final_url: url, http_status: res.status,
      bytes: fs.statSync(partPath).size, sha256: hashFile(partPath, 'sha256'),
      last_modified: lastModified, etag, fetched_at: this.now(),
    };
    fs.renameSync(partPath, filePath);
    this.entries.delete(partKey);
    this.entries.set(spec.path, entry);
    this.saveIndex();
    return { entry, facts };
  }

  /**
   * Resolves the snapshot date of the region's Geofabrik extracts.
   * Online: every `-latest` URL must redirect (307) to the same dated name. When no request is made: the newest
   * date for which every state's extract is in the cache. A pinned date is taken as given.
   * @param {object} region
   * @param {string|null} pinned `--osm-date=YYMMDD`
   * @returns {Promise<string>} YYMMDD
   */
  async resolveOsmDate(region, pinned) {
    if (pinned !== null) {
      if (!/^\d{6}$/.test(pinned)) throw new DownloadError('--osm-date must be six digits, YYMMDD');
      return pinned;
    }
    if (this.noRequests) {
      // The newest date for which every state's extract is in the cache.
      const dir = this.abs('geofabrik');
      const names = fs.existsSync(dir) ? fs.readdirSync(dir) : [];
      const dates = new Set();
      for (const name of names) {
        const d = geofabrikDate(name);
        if (d) dates.add(d);
      }
      const complete = [...dates].filter((d) => region.states.every((s) => names.includes(`${s.geofabrik}-${d}.osm.pbf`))).sort();
      if (complete.length === 0) {
        throw new DownloadError(`raw cache: no dated Geofabrik extract for every state of ${region.id} under ${dir}; `
          + `place them there as <state>-YYMMDD.osm.pbf, or ${this.offline ? 'run without --offline' : CONTACT_HINT} to download them`);
      }
      return complete[complete.length - 1];
    }
    let date = null;
    for (const state of region.states) {
      const url = `${GEOFABRIK_BASE}/${state.geofabrik}-latest.osm.pbf`;
      this.log(`HEAD ${url}`);
      const res = await this.request(url, { method: 'HEAD', headers: { 'User-Agent': userAgent(this.contact) }, redirect: 'manual' });
      if (res.body) await res.body.cancel();
      const location = res.headers.get('location');
      const d = res.status === 307 && location ? geofabrikDate(location) : null;
      if (d === null) throw new DownloadError(`download: ${url} did not redirect to a dated extract (HTTP ${res.status})`);
      if (date !== null && d !== date) {
        throw new DownloadError(`download: Geofabrik extracts resolve to different dates (${date} and ${d}); run again later or pin one with --osm-date`);
      }
      date = d;
    }
    return date;
  }
}

/**
 * Makes every raw file of a region available.
 * @param {RawCache} cache
 * @param {object} region region document
 * @param {{placesSource: 'geofabrik'|'overpass-tiles', osmDate: string|null}} options
 * @returns {Promise<{files: object, osmDate: string|null, lodes: object[], pbfHeaders: object}>}
 */
export async function ensureRegionFiles(cache, region, { placesSource, osmDate }) {
  const files = { pl: {}, wac: {}, xwalk: {}, version: {}, counties: null, pbf: {} };
  const lodes = [];
  const countyFips = region.counties.map((c) => c.fips);
  for (const spec of censusFiles(region)) {
    if (spec.check === 'tigerweb') spec.countyFips = countyFips;
    const entry = await cache.ensure(spec, { optional: spec.sidecar === true });
    if (entry === null) continue;
    const abs = cache.abs(spec.path);
    if (spec.kind === 'census_pl') files.pl[spec.state] = abs;
    else if (spec.kind === 'lodes_wac') files.wac[spec.state] = abs;
    else if (spec.kind === 'lodes_xwalk') files.xwalk[spec.state] = abs;
    else if (spec.kind === 'tigerweb') files.counties = abs;
    else if (spec.kind === 'lodes_version') {
      files.version[spec.state] = abs;
      lodes.push({ state: spec.state, ...cache.verified.get(spec.path).facts });
    }
  }
  const pbfHeaders = {};
  let resolvedDate = null;
  if (placesSource === 'geofabrik') {
    resolvedDate = await cache.resolveOsmDate(region, osmDate);
    for (const state of region.states) {
      for (const spec of geofabrikFiles(state, resolvedDate)) {
        const entry = await cache.ensure(spec, { optional: spec.sidecar === true });
        if (entry === null || spec.kind !== 'osm_pbf') continue;
        files.pbf[state.usps] = cache.abs(spec.path);
        pbfHeaders[state.usps] = cache.verified.get(spec.path).facts.header;
      }
    }
  }
  return { files, osmDate: resolvedDate, lodes, pbfHeaders };
}

/** Manifest `sources[]` kinds, in the order the manifest lists them. */
const SOURCE_KINDS = ['census_pl', 'lodes_wac', 'lodes_xwalk', 'lodes_version', 'tigerweb', 'osm_pbf'];

/** Manifest `sources[]` rows for the verified raw files of this run (checksum sidecars are not listed). */
export function manifestSources(cache, region) {
  const order = new Map(region.states.map((s, i) => [s.usps, i]));
  const rows = [];
  for (const [relPath, { spec, entry }] of cache.verified) {
    if (!SOURCE_KINDS.includes(spec.kind)) continue;
    rows.push({
      kind: spec.kind, state: spec.state, path: relPath, url: entry.url, final_url: entry.final_url,
      bytes: entry.bytes, sha256: entry.sha256, last_modified: entry.last_modified,
    });
  }
  rows.sort((a, b) => (SOURCE_KINDS.indexOf(a.kind) - SOURCE_KINDS.indexOf(b.kind))
    || ((a.state === null ? -1 : order.get(a.state)) - (b.state === null ? -1 : order.get(b.state))));
  return rows;
}
