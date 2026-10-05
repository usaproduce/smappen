// Block table: every census block of the region's states with residents, housing units, internal point and
// raw jobs by sector (03_DATA.md 4.1). Region membership is the GEOID prefix, nothing else.

import fs from 'node:fs';
import zlib from 'node:zlib';
import { readWac, scanXwalk } from './lodes.mjs';
import { scanPlGeo } from './pl.mjs';
import { readZipMember } from './zip.mjs';

export class BlocksError extends Error {}

/**
 * Reads the PL 94-171 geo headers and the WAC files of every state of the region.
 *
 * @param {object} region region document
 * @param {{pl: Record<string, string>, wac: Record<string, string>}} files absolute paths by lower-case USPS code
 * @returns {{table: object, stats: object}} `table` holds one entry per block, sorted by GEOID:
 *   geoid[], state (index into region.states), lat, lng, residents, housing, landArea, inRegion, hasWac, c000,
 *   cns (20 per block), index (Map geoid -> position)
 */
export function readBlocks(region, files) {
  const countySet = new Set(region.counties.map((c) => c.fips));
  const rows = [];
  const stats = {
    plRows: 0,
    plBadRows: 0,
    stateBlocks: {},
    stateResidents: {},
    wacRows: {},
    wacSumMismatches: 0,
    wacBlocksNotInPl: 0,
  };

  region.states.forEach((state, stateIndex) => {
    const member = `${state.usps}geo2020.pl`;
    const geo = readZipMember(fs.readFileSync(files.pl[state.usps]), member);
    let blocks = 0;
    let residents = 0;
    const scan = scanPlGeo(geo, (block) => {
      if (block.geoid.slice(0, 2) !== state.fips) {
        throw new BlocksError(`blocks: ${member} holds block ${block.geoid}, which is not in state ${state.fips}`);
      }
      block.state = stateIndex;
      rows.push(block);
      blocks++;
      residents += block.residents;
    });
    stats.plRows += scan.rows;
    stats.plBadRows += scan.badRows;
    stats.stateBlocks[state.fips] = blocks;
    stats.stateResidents[state.fips] = residents;
  });

  rows.sort((a, b) => (a.geoid < b.geoid ? -1 : a.geoid > b.geoid ? 1 : 0));
  const n = rows.length;
  const table = {
    n,
    geoid: new Array(n),
    state: new Uint8Array(n),
    lat: new Float64Array(n),
    lng: new Float64Array(n),
    residents: new Int32Array(n),
    housing: new Int32Array(n),
    landArea: new Float64Array(n),
    inRegion: new Uint8Array(n),
    hasWac: new Uint8Array(n),
    c000: new Int32Array(n),
    cns: new Int32Array(n * 20),
    index: new Map(),
  };
  for (let i = 0; i < n; i++) {
    const r = rows[i];
    if (table.index.has(r.geoid)) throw new BlocksError(`blocks: block ${r.geoid} appears twice in the PL files`);
    table.index.set(r.geoid, i);
    table.geoid[i] = r.geoid;
    table.state[i] = r.state;
    table.lat[i] = r.lat;
    table.lng[i] = r.lng;
    table.residents[i] = r.residents;
    table.housing[i] = r.housingUnits;
    table.landArea[i] = r.landAreaM2;
    table.inRegion[i] = countySet.has(r.geoid.slice(0, 5)) ? 1 : 0;
  }

  for (const state of region.states) {
    const wac = readWac(zlib.gunzipSync(fs.readFileSync(files.wac[state.usps])));
    stats.wacRows[state.usps] = wac.rows;
    stats.wacSumMismatches += wac.sumMismatches;
    for (let r = 0; r < wac.rows; r++) {
      const i = table.index.get(wac.geoids[r]);
      if (i === undefined) { stats.wacBlocksNotInPl++; continue; }
      if (table.hasWac[i]) throw new BlocksError(`blocks: block ${wac.geoids[r]} has two WAC rows`);
      table.hasWac[i] = 1;
      table.c000[i] = wac.c000[r];
      for (let s = 0; s < 20; s++) table.cns[i * 20 + s] = wac.cns[r * 20 + s];
    }
  }
  return { table, stats };
}

/**
 * Reads the crosswalks: checks that their block set equals the PL block set and returns the labels of the
 * wanted blocks (review list only).
 *
 * @param {object} region
 * @param {{xwalk: Record<string, string>}} files
 * @param {object} table block table from readBlocks
 * @param {Set<string>} wanted GEOIDs whose labels are needed
 * @returns {{labels: Map<string, {county: string, place: string, military: string}>, rows: number,
 *            notInPl: number, duplicates: number, missing: number}}
 */
export function readXwalkLabels(region, files, table, wanted) {
  const labels = new Map();
  const seen = new Uint8Array(table.n);
  let rows = 0;
  let notInPl = 0;
  let duplicates = 0;
  let matched = 0;
  for (const state of region.states) {
    const result = scanXwalk(zlib.gunzipSync(fs.readFileSync(files.xwalk[state.usps])), (geoid, county, place, military) => {
      const i = table.index.get(geoid);
      if (i === undefined) { notInPl++; return; }
      if (seen[i]) { duplicates++; return; }
      seen[i] = 1;
      matched++;
      if (wanted.has(geoid)) labels.set(geoid, { county, place, military });
    });
    rows += result.rows;
  }
  return { labels, rows, notInPl, duplicates, missing: table.n - matched };
}
