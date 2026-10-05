// Quality gates G1 to G17 (03_DATA.md section 12). A failed `fail` gate stops the build; a failed `warn` gate is
// recorded and printed. Gate G18 (determinism) is evaluated by the test suite, the loader evaluates G19 to G22.

import { WORKER_SEGMENTS } from './vocabulary.mjs';

/** Shares that hold for every region (the region file carries the region-specific check values). */
export const NAMED_SHARE_MIN = 0.97;
export const HOURS_PARSE_SHARE_MIN = 0.93;
export const REVIEW_PENDING_SHARE_MAX = 0.5;
export const VINTAGE_TOLERANCE = 0.02;
export const CELL_TOLERANCE = 0.01;
export const CONSERVATION_TOLERANCE = 1e-9;

function within(value, expected, tolerance) {
  return Math.abs(value - expected) <= tolerance * Math.abs(expected);
}

function sameCounts(a, b) {
  const keys = Object.keys(b);
  return keys.length === Object.keys(a).length && keys.every((k) => a[k] === b[k]);
}

/**
 * Evaluates the pipeline gates.
 * @param {object} facts measured values of the build (see pipeline.mjs)
 * @param {object} region region document
 * @returns {{id: string, part: string|null, level: 'fail'|'warn', check: string, value: any, expected: any, pass: boolean}[]}
 *          `id` is the gate number of section 12 (G1 to G17, G8b); `part` names the check when a gate has several
 */
export function evaluateGates(facts, region) {
  const checks = region.checks;
  const gates = [];
  const add = (label, level, check, value, expected, pass) => {
    const dot = label.indexOf('.');
    gates.push({
      id: dot < 0 ? label : label.slice(0, dot), part: dot < 0 ? null : label.slice(dot + 1), level, check, value, expected, pass: Boolean(pass),
    });
  };

  // Checks tied to the LODES vintage: exact (fail) on the recorded vintage, within 2 % (warn) on another one.
  const onVintage = facts.lodes.vintages.every((v) => v === region.lodes.vintage);
  const jobsGate = (id, check, value, expected) => {
    if (onVintage) add(id, 'fail', check, value, expected, value === expected);
    else add(id, 'warn', `${check} (new LODES vintage: within 2 %)`, value, expected, within(value, expected, VINTAGE_TOLERANCE));
  };

  // G1 downloads
  add('G1.files', 'fail', 'raw files complete and verified', facts.raw.verified, facts.raw.required, facts.raw.verified === facts.raw.required);
  add('G1.sidecars', 'warn', 'files without a checksum sidecar', facts.raw.missingSidecars.length, 0, facts.raw.missingSidecars.length === 0);

  // G2 headers
  add('G2', 'fail', 'PL geo rows without 97 fields (WAC and crosswalk headers are checked while reading)', facts.pl.badRows, 0, facts.pl.badRows === 0);

  // G3 LODES release
  add('G3.format', 'fail', 'LODES release format of every state', facts.lodes.formats, region.lodes.format,
    facts.lodes.formats.every((f) => f === region.lodes.format));
  add('G3.vintage', 'warn', 'LODES data vintage of every state', facts.lodes.vintages, region.lodes.vintage, onVintage);

  // G4 state residents
  add('G4', 'fail', 'residents per state, exactly', facts.stateResidents, checks.state_residents,
    sameCounts(facts.stateResidents, checks.state_residents));

  // G5 block sets
  add('G5.wac_in_pl', 'fail', 'WAC blocks missing from the PL block set', facts.wacBlocksNotInPl, 0, facts.wacBlocksNotInPl === 0);
  add('G5.state_blocks', 'fail', 'blocks per state', facts.stateBlocks, checks.state_blocks, sameCounts(facts.stateBlocks, checks.state_blocks));
  const allBlocks = Object.values(checks.state_blocks).reduce((a, b) => a + b, 0);
  const x = facts.xwalk;
  add('G5.xwalk', 'fail', 'crosswalk block set equals the PL block set',
    { rows: x.rows, not_in_pl: x.notInPl, duplicates: x.duplicates, missing: x.missing },
    { rows: allBlocks, not_in_pl: 0, duplicates: 0, missing: 0 },
    x.rows === allBlocks && x.notInPl === 0 && x.duplicates === 0 && x.missing === 0);

  // G6 sector sums
  add('G6', 'fail', 'WAC rows whose sectors do not add up to C000', facts.wacSumMismatches, 0, facts.wacSumMismatches === 0);

  // G7 region totals
  const r = facts.region;
  add('G7.residents', 'fail', 'region residents', r.residents, checks.residents, r.residents === checks.residents);
  add('G7.housing_units', 'fail', 'region housing units', r.housingUnits, checks.housing_units, r.housingUnits === checks.housing_units);
  add('G7.blocks', 'fail', 'region blocks', r.blocks, checks.blocks, r.blocks === checks.blocks);
  add('G7.blocks_with_residents', 'fail', 'region blocks with residents', r.blocksWithResidents, checks.blocks_with_residents,
    r.blocksWithResidents === checks.blocks_with_residents);
  const byFips = new Map(r.counties.map((c) => [c.fips, c]));
  let countyDiffs = 0;
  let countyJobDiffs = 0;
  let countyJobsOutside = 0;
  for (const c of region.counties) {
    const m = byFips.get(c.fips);
    if (!m || m.residents !== c.residents || m.housing_units !== c.housing_units || m.blocks !== c.blocks) countyDiffs++;
    if (!m || m.jobs !== c.jobs) countyJobDiffs++;
    if (!m || !within(m.jobs, c.jobs, VINTAGE_TOLERANCE)) countyJobsOutside++;
  }
  add('G7.counties', 'fail', 'county rows whose residents, housing units or blocks differ from the region file', countyDiffs, 0, countyDiffs === 0);
  if (onVintage) add('G7.county_jobs', 'fail', 'county rows whose raw jobs differ from the region file', countyJobDiffs, 0, countyJobDiffs === 0);
  else add('G7.county_jobs', 'warn', 'county rows whose raw jobs are more than 2 % from the region file (new LODES vintage)', countyJobsOutside, 0, countyJobsOutside === 0);
  jobsGate('G7.jobs', 'region raw jobs', r.jobs, checks.jobs);
  for (const segment of WORKER_SEGMENTS) {
    jobsGate(`G7.jobs.${segment}`, `raw jobs of ${segment} (CNS04 at weight 1, before corrections)`, r.jobsBySegmentRaw[segment], checks.jobs_by_segment[segment]);
  }
  jobsGate('G7.cns04_jobs', 'raw construction jobs (CNS04)', r.cns04Jobs, checks.cns04_jobs);
  jobsGate('G7.blocks_with_jobs', 'region blocks with jobs', r.blocksWithJobs, checks.blocks_with_jobs);
  jobsGate('G7.blocks_with_either', 'region blocks with residents or jobs', r.blocksWithEither, checks.blocks_with_either);

  // G8 conservation
  const c = facts.conservation;
  add('G8.segments', 'fail', 'largest relative error of a worker segment: block rows against raw total less discarded',
    c.maxSegmentError, `<= ${CONSERVATION_TOLERANCE}`, c.maxSegmentError <= CONSERVATION_TOLERANCE);
  add('G8.residents', 'fail', 'residents in region block rows', c.residentsInRows, checks.residents, c.residentsInRows === checks.residents);
  const flow = facts.placesFlow;
  add('G8.places', 'fail', 'places read = places kept + dropped + merged', { read: flow.read, kept: flow.kept, dropped_and_merged: flow.dropped },
    'read = kept + dropped_and_merged', flow.read === flow.kept + flow.dropped);

  // G8b job movement
  const j = facts.jobs;
  const discardedShare = r.jobs > 0 ? (j.jobsDiscarded + j.jobsSpreadLost) / r.jobs : 0;
  const movedShare = r.jobs > 0 ? j.jobsSpread / r.jobs : 0;
  add('G8b.discarded', 'fail', 'share of raw region jobs discarded (cap, drop, spread without receivers)', discardedShare,
    `<= ${checks.job_movement.discarded_share_max}`, discardedShare <= checks.job_movement.discarded_share_max);
  add('G8b.moved', 'fail', 'share of raw region jobs moved by spread and auto_spread', movedShare,
    `<= ${checks.job_movement.moved_share_max}`, movedShare <= checks.job_movement.moved_share_max);
  add('G8b.review_sums', 'fail', 'job_review.csv sums equal the manifest totals and every adjusted block has a row',
    { jobs_spread: j.reviewSpread, jobs_discarded: j.reviewDiscarded, blocks_without_row: j.adjustedWithoutRow },
    { jobs_spread: j.jobsSpread, jobs_discarded: j.jobsDiscarded, blocks_without_row: 0 },
    within(j.reviewSpread, j.jobsSpread, CONSERVATION_TOLERANCE) && within(j.reviewDiscarded, j.jobsDiscarded, CONSERVATION_TOLERANCE)
      && j.adjustedWithoutRow === 0);

  // G9 geometry
  const g = facts.geometry;
  add('G9.fetch_box', 'fail', 'points and places outside the fetch box', g.outsideBox, 0, g.outsideBox === 0);
  add('G9.county', 'fail', 'in-region places outside every county polygon', g.placesNotInCounty, 0, g.placesNotInCounty === 0);
  add('G9.cells', 'fail', 'cells that are not valid resolution-9 cells', g.invalidCells, 0, g.invalidCells === 0);
  add('G9.h3_probe', 'fail', 'H3 probe cell', g.probeCell, checks.h3_probe.cell, g.probeCell === checks.h3_probe.cell);

  // G10 occupied cells, G11 largest cells
  const o = facts.occupancy;
  add('G10', 'warn', 'resolution-9 cells holding a region block with raw residents or jobs (within 1 %)', o.occupiedCells,
    checks.res9_cells_with_residents_or_jobs, within(o.occupiedCells, checks.res9_cells_with_residents_or_jobs, CELL_TOLERANCE));
  add('G11.residents', 'warn', 'largest resolution-9 cell, residents (within 1 %)', o.maxResidents.value, checks.res9_max_residents,
    within(o.maxResidents.value, checks.res9_max_residents, CELL_TOLERANCE));
  add('G11.jobs_raw', 'warn', 'largest resolution-9 cell, raw jobs', o.maxJobsRaw.value, checks.res9_max_jobs_raw,
    o.maxJobsRaw.value === checks.res9_max_jobs_raw);
  add('G11.caps', 'fail', 'treated blocks that keep more than their cap in the affected sectors', facts.capsExceeded, 0, facts.capsExceeded === 0);
  add('G11.jobs_after', 'warn', `largest resolution-9 cell, jobs after corrections (cell ${o.maxJobsAfter.h3})`, o.maxJobsAfter.value,
    `<= ${checks.res9_max_jobs_after}`, o.maxJobsAfter.value <= checks.res9_max_jobs_after);

  // G12 places
  const p = facts.places;
  const inRange = (v, range) => v >= range.min && v <= range.max;
  const rangeText = (range) => `${range.min} to ${range.max}`;
  add('G12.places', 'warn', 'region places', p.region, rangeText(checks.places), inRange(p.region, checks.places));
  add('G12.rivals', 'warn', 'region rivals', p.rivals, rangeText(checks.rivals), inRange(p.rivals, checks.rivals));
  const namedShare = p.region > 0 ? p.named / p.region : 0;
  add('G12.named', 'warn', 'share of region places with a name', namedShare, `>= ${NAMED_SHARE_MIN}`, namedShare >= NAMED_SHARE_MIN);
  const contactShare = p.hosts > 0 ? p.hostsWithContact / p.hosts : 0;
  add('G12.host_contact', 'warn', 'share of possible hosts with phone or website', contactShare, rangeText(checks.hosts_with_contact_share),
    inRange(contactShare, checks.hosts_with_contact_share));
  const hoursShare = p.hoursRaw > 0 ? p.hoursParsed / p.hoursRaw : 1;
  add('G12.hours', 'warn', 'share of raw opening_hours values that parse', hoursShare, `>= ${HOURS_PARSE_SHARE_MIN}`, hoursShare >= HOURS_PARSE_SHARE_MIN);
  add('G12.coordinates', 'fail', 'places without coordinates', p.noCoordinates, 0, p.noCoordinates === 0);
  const tolerated = facts.placesSource === 'overpass-tiles' ? ['transit_station'] : [];
  const missing = p.missingTypes.filter((t) => !tolerated.includes(t));
  add('G12.types', 'warn', 'place types of the vocabulary absent from the region', p.missingTypes, tolerated, missing.length === 0);

  // G13 anchors
  add('G13', 'fail', 'anchor blocks keep their jobs after corrections',
    facts.anchors.map((a) => ({ geoid: a.geoid, column: a.column, jobs: a.jobs })),
    checks.anchors.map((a) => ({ geoid: a.geoid, column: a.column, min: a.min })),
    facts.anchors.length === checks.anchors.length && facts.anchors.every((a, i) => a.jobs !== null && a.jobs >= checks.anchors[i].min));

  // G14 state coverage
  add('G14', 'fail', 'every state contributes its counties: residents and raw jobs per state',
    facts.states.map((s) => ({ fips: s.fips, residents: s.residents, jobs: s.jobs })),
    facts.states.map((s) => ({ fips: s.fips, residents: s.expectedResidents, jobs: s.expectedJobs })),
    facts.states.every((s) => s.blocks > 0 && s.residents === s.expectedResidents && (!onVintage || s.jobs === s.expectedJobs)));

  // G15 job review
  const v = facts.review;
  add('G15.flagged', 'warn', 'flagged blocks and their jobs', { blocks: v.flaggedBlocks, jobs: v.flaggedJobs },
    { blocks: checks.job_review.blocks, jobs: checks.job_review.jobs },
    v.flaggedBlocks === checks.job_review.blocks && v.flaggedJobs === checks.job_review.jobs);
  add('G15.entries', 'warn', 'stale or orphan corrections entries', { stale: v.stale, orphan: v.orphan }, { stale: 0, orphan: 0 },
    v.stale === 0 && v.orphan === 0);
  const autoShare = v.flaggedJobs > 0 ? v.autoJobs / v.flaggedJobs : 0;
  add('G15.review_pending', 'warn', 'share of flagged jobs under automatic treatment (review pending above one half)', autoShare,
    `<= ${REVIEW_PENDING_SHARE_MAX}`, autoShare <= REVIEW_PENDING_SHARE_MAX);
  add('G15.unconfirmed', 'warn', 'job corrections that await the owner\'s confirmation', v.unconfirmed, 0, v.unconfirmed === 0);

  // G16 halo
  const haloShare = r.residents > 0 ? facts.halo.residents / r.residents : 0;
  add('G16.share', 'fail', 'halo residents as a share of region residents', haloShare, `<= ${checks.halo_residents_share_max}`,
    haloShare <= checks.halo_residents_share_max);
  add('G16.totals', 'fail', 'halo rows counted in a total or flagged in_region', facts.halo.rowsInTotals, 0, facts.halo.rowsInTotals === 0);

  // G17 cells
  add('G17.ring5', 'fail', 'candidate cells accepted in ring 5 of a source point', facts.cells.ring5Accepted, 0, facts.cells.ring5Accepted === 0);
  add('G17.kept', 'warn', 'kept cells', facts.cells.kept, rangeText(checks.cells_kept), inRange(facts.cells.kept, checks.cells_kept));

  return gates;
}

/** `G15.unconfirmed`, or `G4` for a gate with a single check. */
export function gateLabel(gate) {
  return gate.part === null ? gate.id : `${gate.id}.${gate.part}`;
}

/** True when every `fail` gate passed. */
export function gatesPass(gates) {
  return gates.every((gate) => gate.level !== 'fail' || gate.pass);
}

function brief(value) {
  const text = typeof value === 'string' ? value : JSON.stringify(value);
  return text.length > 96 ? `${text.slice(0, 93)}...` : text;
}

/** Gate table for the console. */
export function formatGates(gates) {
  const lines = [];
  for (const gate of gates) {
    const status = gate.pass ? 'ok  ' : gate.level === 'fail' ? 'FAIL' : 'WARN';
    lines.push(`${status} ${gateLabel(gate).padEnd(24)} ${gate.check}`);
    if (!gate.pass) lines.push(`       value ${brief(gate.value)}   expected ${brief(gate.expected)}`);
  }
  return lines.join('\n');
}
