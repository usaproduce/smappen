// Development input: a saved set of Overpass JSON tiles (03_DATA.md 2.3, "Alternative input for development").
// Never used for a production refresh. The query text lives here so the fetch helper and the tests share it.

import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { hasTriggerKey } from './taxonomy.mjs';

export class OverpassError extends Error {}

/** Names of the tile files of a directory, sorted. */
export function listTiles(dir) {
  let names;
  try {
    names = fs.readdirSync(dir);
  } catch (err) {
    throw new OverpassError(`overpass: cannot read tiles directory ${dir}: ${err.message}`);
  }
  return names.filter((name) => /^t.*\.json\.gz$/.test(name)).sort().map((name) => path.join(dir, name));
}

/**
 * Reads one Overpass JSON response (already parsed).
 * @param {object} doc
 * @param {object} options
 * @param {(osmType: string, tags: object, id: number) => any} options.select as for the PBF reader
 * @param {{south: number, west: number, north: number, east: number}} [options.box]
 * @returns {{elements: object[], stats: object, timestamp: string}} the same element shape as the PBF reader
 */
export function readOverpassDocument(doc, { select, box = null }) {
  if (doc === null || typeof doc !== 'object' || !Array.isArray(doc.elements)) {
    throw new OverpassError('overpass: not an Overpass JSON response (no elements list)');
  }
  if ('remark' in doc) throw new OverpassError(`overpass: the response carries a remark: ${String(doc.remark).slice(0, 200)}`);
  const timestamp = doc.osm3s && typeof doc.osm3s.timestamp_osm_base === 'string' ? doc.osm3s.timestamp_osm_base : null;
  if (timestamp === null || Number.isNaN(Date.parse(timestamp))) {
    throw new OverpassError('overpass: the response has no osm3s.timestamp_osm_base');
  }
  const stats = { nodes: 0, ways: 0, relations: 0, triggered: 0, noGeometry: 0, outsideBox: 0 };
  const elements = [];
  for (const e of doc.elements) {
    const osmType = e.type;
    if (osmType === 'node') stats.nodes++;
    else if (osmType === 'way') stats.ways++;
    else if (osmType === 'relation') stats.relations++;
    else continue;
    const tags = e.tags && typeof e.tags === 'object' ? e.tags : {};
    if (!hasTriggerKey(tags)) continue;
    stats.triggered++;
    const payload = select(osmType, tags, e.id);
    if (!payload) continue;

    let latE7;
    let lngE7;
    let elementBox = null;
    if (osmType === 'node') {
      if (typeof e.lat !== 'number' || typeof e.lon !== 'number') { stats.noGeometry++; continue; }
      latE7 = Math.round(e.lat * 1e7);
      lngE7 = Math.round(e.lon * 1e7);
    } else if (e.bounds && typeof e.bounds.minlat === 'number') {
      elementBox = {
        minLatE7: Math.round(e.bounds.minlat * 1e7),
        minLngE7: Math.round(e.bounds.minlon * 1e7),
        maxLatE7: Math.round(e.bounds.maxlat * 1e7),
        maxLngE7: Math.round(e.bounds.maxlon * 1e7),
      };
      latE7 = Math.floor((elementBox.minLatE7 + elementBox.maxLatE7 + 1) / 2);
      lngE7 = Math.floor((elementBox.minLngE7 + elementBox.maxLngE7 + 1) / 2);
    } else if (e.center && typeof e.center.lat === 'number' && typeof e.center.lon === 'number') {
      latE7 = Math.round(e.center.lat * 1e7);
      lngE7 = Math.round(e.center.lon * 1e7);
    } else {
      stats.noGeometry++;
      continue;
    }
    if (box) {
      const lat = latE7 / 1e7;
      const lng = lngE7 / 1e7;
      if (!(lat >= box.south && lat <= box.north && lng >= box.west && lng <= box.east)) { stats.outsideBox++; continue; }
    }
    elements.push({ osmType, id: e.id, tags, payload, latE7, lngE7, box: elementBox });
  }
  return { elements, stats, timestamp };
}

/** Reads one `t*.json.gz` tile file. */
export function readOverpassTile(filePath, options) {
  let doc;
  try {
    doc = JSON.parse(zlib.gunzipSync(fs.readFileSync(filePath)).toString('utf8'));
  } catch (err) {
    throw new OverpassError(`overpass: ${filePath} is not gzipped JSON: ${err.message}`);
  }
  try {
    return readOverpassDocument(doc, options);
  } catch (err) {
    if (err instanceof OverpassError) throw new OverpassError(`${err.message} (${path.basename(filePath)})`);
    throw err;
  }
}

/**
 * Overpass QL for one tile: every tag the taxonomy of section 5.1 can match.
 * @param {{south: number, west: number, north: number, east: number}} box
 */
export function overpassQuery(box) {
  return [
    `[out:json][timeout:60][bbox:${box.south},${box.west},${box.north},${box.east}];`,
    '(',
    '  nwr["amenity"~"^(restaurant|fast_food|food_court|cafe|ice_cream|bar|pub|biergarten|hospital|university|college|bus_station|events_venue|conference_centre|exhibition_centre|theatre|cinema|arts_centre|marketplace)$"];',
    '  nwr["craft"~"^(brewery|winery|distillery|cidery)$"];',
    '  nwr["microbrewery"="yes"];',
    '  nwr["shop"~"^(deli|bakery|pastry|coffee|convenience|supermarket|mall|department_store|wholesale|doityourself|furniture|garden_centre|car)$"];',
    '  nwr["healthcare"="hospital"];',
    '  nwr["railway"="station"];',
    '  nwr["public_transport"="station"];',
    '  nwr["leisure"~"^(stadium|fitness_centre|sports_centre|sports_hall|ice_rink|park|water_park)$"]["name"];',
    '  nwr["tourism"~"^(hotel|museum|attraction|theme_park|zoo)$"]["name"];',
    '  nwr["landuse"~"^(retail|industrial|commercial)$"]["name"];',
    '  nwr["building"~"^(apartments|industrial|warehouse|office)$"]["name"];',
    '  nwr["residential"~"^(apartments|condominium)$"]["name"];',
    '  nwr["industrial"]["name"];',
    '  nwr["man_made"="works"]["name"];',
    '  way["office"]["name"];',
    '  relation["office"]["name"];',
    ');',
    'out tags bb qt;',
    '',
  ].join('\n');
}

// ---- fetching a tile set (developer helper, never part of a build) --------------------------------------

export const OVERPASS_ENDPOINT = 'https://overpass-api.de/api/interpreter';
/** Shortest wait after HTTP 429 or 504, in seconds (public Overpass policy). */
export const OVERPASS_BACKOFF_S = 30;

/**
 * Cuts a box into rows x cols tiles named t<row><col>, row 0 in the south, column 0 in the west.
 * @returns {{name: string, box: {south: number, west: number, north: number, east: number}}[]}
 */
export function tileGrid(box, rows, cols) {
  const tiles = [];
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      tiles.push({
        name: `t${r}${c}`,
        box: {
          south: r === 0 ? box.south : box.south + ((box.north - box.south) * r) / rows,
          north: r === rows - 1 ? box.north : box.south + ((box.north - box.south) * (r + 1)) / rows,
          west: c === 0 ? box.west : box.west + ((box.east - box.west) * c) / cols,
          east: c === cols - 1 ? box.east : box.west + ((box.east - box.west) * (c + 1)) / cols,
        },
      });
    }
  }
  return tiles;
}

/** Splits a tile in four: <name>a (south-west), b (south-east), c (north-west), d (north-east). */
export function splitTile(tile) {
  const { south, west, north, east } = tile.box;
  const midLat = (south + north) / 2;
  const midLng = (west + east) / 2;
  return [
    { name: `${tile.name}a`, box: { south, west, north: midLat, east: midLng } },
    { name: `${tile.name}b`, box: { south, west: midLng, north: midLat, east } },
    { name: `${tile.name}c`, box: { south: midLat, west, north, east: midLng } },
    { name: `${tile.name}d`, box: { south: midLat, west: midLng, north, east } },
  ];
}

/**
 * Fetches tiles one request at a time and writes each response as <name>.json.gz.
 * HTTP 429 and 504 wait at least 30 s and retry; a response with a `remark` splits the tile in four; any
 * other status stops the run.
 *
 * @param {object} options
 * @param {{name: string, box: object}[]} options.tiles
 * @param {string} options.outDir
 * @param {string} options.userAgent
 * @param {typeof fetch} [options.fetchImpl]
 * @param {(seconds: number) => Promise<void>} [options.sleep]
 * @param {string} [options.endpoint]
 * @param {number} [options.maxAttempts] attempts per tile before giving up on 429 or 504
 * @param {number} [options.maxSplits] how many times one tile may be split
 * @param {(message: string) => void} [options.log]
 * @returns {Promise<{written: string[], requests: number, waits: number, splits: number}>}
 */
export async function fetchOverpassTiles({
  tiles, outDir, userAgent, fetchImpl = globalThis.fetch,
  sleep = (seconds) => new Promise((resolve) => { setTimeout(resolve, seconds * 1000); }),
  endpoint = OVERPASS_ENDPOINT, maxAttempts = 6, maxSplits = 3, log = () => {},
}) {
  fs.mkdirSync(outDir, { recursive: true });
  const queue = tiles.map((tile) => ({ ...tile, depth: 0 }));
  const result = { written: [], requests: 0, waits: 0, splits: 0 };
  while (queue.length > 0) {
    const tile = queue.shift();
    let done = false;
    for (let attempt = 1; attempt <= maxAttempts && !done; attempt++) {
      result.requests++;
      log(`POST ${tile.name} (attempt ${attempt})`);
      const res = await fetchImpl(endpoint, {
        method: 'POST',
        headers: { 'User-Agent': userAgent, 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        body: `data=${encodeURIComponent(overpassQuery(tile.box))}`,
      });
      if (res.status === 429 || res.status === 504) {
        if (res.body) await res.body.cancel();
        result.waits++;
        log(`HTTP ${res.status} for ${tile.name}: waiting ${OVERPASS_BACKOFF_S * attempt} s`);
        await sleep(OVERPASS_BACKOFF_S * attempt);
        continue;
      }
      if (res.status !== 200) {
        if (res.body) await res.body.cancel();
        throw new OverpassError(`overpass: HTTP ${res.status} for tile ${tile.name}`);
      }
      const text = await res.text();
      let doc;
      try {
        doc = JSON.parse(text);
      } catch {
        throw new OverpassError(`overpass: tile ${tile.name} did not answer JSON`);
      }
      if ('remark' in doc) {
        if (tile.depth >= maxSplits) throw new OverpassError(`overpass: tile ${tile.name} still carries a remark after ${maxSplits} splits: ${String(doc.remark).slice(0, 160)}`);
        result.splits++;
        log(`remark for ${tile.name}: splitting in four`);
        queue.unshift(...splitTile(tile).map((t) => ({ ...t, depth: tile.depth + 1 })));
      } else {
        const file = path.join(outDir, `${tile.name}.json.gz`);
        fs.writeFileSync(file, zlib.gzipSync(Buffer.from(text, 'utf8'), { level: 9 }));
        result.written.push(file);
      }
      done = true;
    }
    if (!done) throw new OverpassError(`overpass: tile ${tile.name} was refused ${maxAttempts} times (HTTP 429 or 504); try again later`);
  }
  return result;
}
