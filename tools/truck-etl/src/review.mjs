// The job review list, job_review.csv (03_DATA.md 8.4): every flagged block and every block with an entry.

import { TREATMENT_NAMES, TREATMENT_NONE } from './corrections.mjs';
import { BucketGrid } from './geo.mjs';
import { SECTORS } from './vocabulary.mjs';

export const REVIEW_COLUMNS = Object.freeze([
  'geoid', 'county_fips', 'county_name', 'place_name', 'military_name', 'lat', 'lng', 'map_url', 'residents',
  'land_area_m2', 'c000', 'top1_sector', 'top1_jobs', 'top1_share', 'top2_sector', 'top2_jobs', 'top3_sector',
  'top3_jobs', 'rule', 'treatment', 'manual', 'confirmed', 'cap', 'jobs_after', 'jobs_spread', 'jobs_discarded',
  'entry_state', 'hint_place', 'reason',
]);

export const HINT_RADIUS_M = 800;
const HINT_TYPES = new Set(['hospital', 'campus']);

/** Free Google Maps link for a coordinate (a URL, not an API call). */
export function mapUrl(lat, lng) {
  return `https://www.google.com/maps/search/?api=1&query=${lat}%2C${lng}`;
}

/** The three largest sectors of a block: by count descending, then sector number ascending. */
export function topSectors(raw, offset) {
  const order = [];
  for (let s = 0; s < 20; s++) order.push(s);
  order.sort((a, b) => (raw[offset + b] - raw[offset + a]) || (a - b));
  return order.slice(0, 3).map((s) => ({ sector: SECTORS[s], jobs: raw[offset + s] }));
}

/**
 * Builds the rows of job_review.csv.
 * @param {object} args
 * @param {object} args.table block table
 * @param {Int32Array} args.regionBlocks table positions of the region's blocks, ascending GEOID
 * @param {object} args.result result of applyCorrections
 * @param {Map<string, object>} args.entries corrections entries
 * @param {Map<string, {county: string, place: string, military: string}>} args.labels crosswalk labels
 * @param {object[]} args.places kept places (for the hospital or campus hint)
 * @param {number} args.earthRadiusM
 * @returns {object[]} rows keyed by column name, in file order
 */
export function buildReviewRows({ table, regionBlocks, result, entries, labels, places, earthRadiusM }) {
  const hints = places.filter((p) => HINT_TYPES.has(p.place_type) && p.name !== null);
  let maxAbsLat = 0;
  for (const p of hints) if (Math.abs(p.lat) > maxAbsLat) maxAbsLat = Math.abs(p.lat);
  for (let k = 0; k < regionBlocks.length; k++) {
    const lat = Math.abs(table.lat[regionBlocks[k]]);
    if (lat > maxAbsLat) maxAbsLat = lat;
  }
  const grid = new BucketGrid(HINT_RADIUS_M, maxAbsLat, earthRadiusM);
  for (const p of hints) grid.add(p.lat, p.lng);

  const hintFor = (lat, lng) => {
    let best = -1;
    let bestD = Infinity;
    grid.forEachWithin(lat, lng, HINT_RADIUS_M, (index, d) => {
      if (d < bestD || (d === bestD && hints[index].place_key < hints[best].place_key)) { best = index; bestD = d; }
    });
    return best < 0 ? '' : `${hints[best].place_type}: ${hints[best].name} (${Math.round(bestD)} m)`;
  };

  const rows = [];
  for (let k = 0; k < regionBlocks.length; k++) {
    const i = regionBlocks[k];
    const geoid = table.geoid[i];
    const entry = table.hasWac[i] ? entries.get(geoid) : undefined;
    if (result.rule[k] === '' && !entry) continue;
    const top = topSectors(table.cns, i * 20);
    const label = labels.get(geoid) || { county: '', place: '', military: '' };
    const c000 = table.c000[i];
    let after = 0;
    for (let s = 0; s < 20; s++) after += result.corrected[k * 20 + s];
    const treatment = result.treatment[k];
    rows.push({
      geoid,
      county_fips: geoid.slice(0, 5),
      county_name: label.county,
      place_name: label.place,
      military_name: label.military,
      lat: table.lat[i],
      lng: table.lng[i],
      map_url: mapUrl(table.lat[i], table.lng[i]),
      residents: table.residents[i],
      land_area_m2: table.landArea[i],
      c000,
      top1_sector: top[0].sector,
      top1_jobs: top[0].jobs,
      top1_share: c000 > 0 ? Number((top[0].jobs / c000).toFixed(4)) : 0,
      top2_sector: top[1].sector,
      top2_jobs: top[1].jobs,
      top3_sector: top[2].sector,
      top3_jobs: top[2].jobs,
      rule: result.rule[k],
      treatment: treatment === TREATMENT_NONE ? '' : TREATMENT_NAMES[treatment],
      manual: entry ? 1 : 0,
      confirmed: entry ? (entry.confirmed ? 1 : 0) : '',
      cap: Number.isNaN(result.cap[k]) ? '' : result.cap[k],
      jobs_after: after,
      jobs_spread: result.jobsSpread[k],
      jobs_discarded: result.jobsDiscarded[k],
      entry_state: result.entryState[k],
      hint_place: hintFor(table.lat[i], table.lng[i]),
      reason: entry ? entry.reason : '',
    });
  }
  rows.sort((a, b) => (b.c000 - a.c000) || (a.geoid < b.geoid ? -1 : a.geoid > b.geoid ? 1 : 0));

  // Orphan entries: no WAC row or outside the region. They sort after every other row, by GEOID.
  for (const geoid of result.orphans) {
    const entry = entries.get(geoid);
    const row = {};
    for (const column of REVIEW_COLUMNS) row[column] = '';
    row.geoid = geoid;
    row.treatment = entry.action;
    row.manual = 1;
    row.confirmed = entry.confirmed ? 1 : 0;
    row.cap = entry.cap === null ? '' : entry.cap;
    row.entry_state = 'orphan';
    row.reason = entry.reason;
    rows.push(row);
  }
  return rows;
}
