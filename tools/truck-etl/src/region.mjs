// Region definition file: loading and validation (03_DATA.md section 1).

import fs from 'node:fs';
import crypto from 'node:crypto';
import { SECTORS, WORKER_SEGMENTS } from './vocabulary.mjs';

export class RegionError extends Error {}

function fail(message) {
  throw new RegionError(`region file: ${message}`);
}

function isObject(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function needInt(v, path, { min = 0 } = {}) {
  if (!Number.isInteger(v) || v < min) fail(`${path} must be an integer >= ${min}`);
  return v;
}

function needNumber(v, path, { min = -Infinity, max = Infinity } = {}) {
  if (typeof v !== 'number' || !Number.isFinite(v) || v < min || v > max) fail(`${path} must be a number in ${min}..${max}`);
  return v;
}

function needString(v, path, pattern = null) {
  if (typeof v !== 'string' || v === '') fail(`${path} must be a non-empty string`);
  if (pattern && !pattern.test(v)) fail(`${path} = ${JSON.stringify(v)} does not match ${pattern}`);
  return v;
}

function needRange(v, path, { min = 0, max = Infinity } = {}) {
  if (!isObject(v)) fail(`${path} must be an object {min, max}`);
  needNumber(v.min, `${path}.min`, { min, max });
  needNumber(v.max, `${path}.max`, { min: v.min, max });
  return v;
}

/**
 * Validates a parsed region document and returns it unchanged (the manifest embeds it verbatim).
 * @param {object} doc
 */
export function validateRegion(doc) {
  if (!isObject(doc)) fail('must be a JSON object');
  if (doc.schema !== 1) fail('schema must be 1');
  needString(doc.id, 'id', /^[a-z0-9]{1,24}$/);
  needString(doc.name, 'name');
  needString(doc.timezone, 'timezone');
  if (doc.h3_res !== 9) fail('h3_res must be 9 (the cell grid of 03_DATA.md section 0)');
  if (doc.membership !== 'geoid_prefix') fail('membership must be "geoid_prefix"');
  if (doc.block_point !== 'census_intpt') fail('block_point must be "census_intpt"');
  if ('traffic_matrix' in doc) needString(doc.traffic_matrix, 'traffic_matrix', /^[a-z0-9_]+$/);

  if (!isObject(doc.map_center)) fail('map_center must be an object');
  needNumber(doc.map_center.lat, 'map_center.lat', { min: -90, max: 90 });
  needNumber(doc.map_center.lng, 'map_center.lng', { min: -180, max: 180 });

  const box = doc.fetch_box;
  if (!isObject(box)) fail('fetch_box must be an object');
  for (const k of ['south', 'west', 'north', 'east']) needNumber(box[k], `fetch_box.${k}`, { min: -180, max: 180 });
  if (!(box.south < box.north) || !(box.west < box.east)) fail('fetch_box must have south < north and west < east');

  if (!Array.isArray(doc.states) || doc.states.length === 0) fail('states must be a non-empty list');
  const stateFips = new Set();
  doc.states.forEach((s, i) => {
    if (!isObject(s)) fail(`states[${i}] must be an object`);
    needString(s.usps, `states[${i}].usps`, /^[a-z]{2}$/);
    needString(s.fips, `states[${i}].fips`, /^[0-9]{2}$/);
    needString(s.pl_dir, `states[${i}].pl_dir`, /^[A-Za-z_]+$/);
    needString(s.geofabrik, `states[${i}].geofabrik`, /^[a-z-]+$/);
    if (stateFips.has(s.fips)) fail(`states[${i}].fips ${s.fips} is listed twice`);
    stateFips.add(s.fips);
  });

  if (!isObject(doc.census) || doc.census.product !== 'PL 94-171' || doc.census.year !== 2020) {
    fail('census must be {"product": "PL 94-171", "year": 2020, ...}');
  }
  needString(doc.census.reference_date, 'census.reference_date', /^\d{4}-\d{2}-\d{2}$/);

  const lodes = doc.lodes;
  if (!isObject(lodes)) fail('lodes must be an object');
  if (lodes.release !== 'LODES8') fail('lodes.release must be "LODES8"');
  needString(lodes.format, 'lodes.format', /^\d+\.\d+$/);
  needInt(lodes.year, 'lodes.year', { min: 2002 });
  needString(lodes.segment, 'lodes.segment', /^S[A-Z0-9]{3}$/);
  needString(lodes.job_type, 'lodes.job_type', /^JT0[0-5]$/);
  needString(lodes.vintage, 'lodes.vintage');

  const review = doc.job_review;
  if (!isObject(review)) fail('job_review must be an object');
  needNumber(review.total_min, 'job_review.total_min', { min: 1 });
  needNumber(review.single_sector_min, 'job_review.single_sector_min', { min: 1 });
  needNumber(review.single_sector_share, 'job_review.single_sector_share', { min: 0, max: 1 });

  if (!Array.isArray(doc.counties) || doc.counties.length === 0) fail('counties must be a non-empty list');
  const seen = new Set();
  doc.counties.forEach((c, i) => {
    if (!isObject(c)) fail(`counties[${i}] must be an object`);
    needString(c.fips, `counties[${i}].fips`, /^[0-9]{5}$/);
    if (!stateFips.has(c.fips.slice(0, 2))) fail(`counties[${i}].fips ${c.fips} belongs to no state of the region file`);
    if (seen.has(c.fips)) fail(`counties[${i}].fips ${c.fips} is listed twice`);
    seen.add(c.fips);
    needString(c.name, `counties[${i}].name`);
    for (const k of ['residents', 'housing_units', 'jobs', 'blocks']) needInt(c[k], `counties[${i}].${k}`);
  });

  const checks = doc.checks;
  if (!isObject(checks)) fail('checks must be an object');
  for (const key of ['state_residents', 'state_blocks']) {
    if (!isObject(checks[key])) fail(`checks.${key} must be an object keyed by state FIPS`);
    for (const fips of stateFips) needInt(checks[key][fips], `checks.${key}.${fips}`);
  }
  for (const key of ['residents', 'housing_units', 'jobs', 'blocks', 'blocks_with_residents', 'blocks_with_jobs',
    'blocks_with_either', 'cns04_jobs', 'res9_cells_with_residents_or_jobs', 'res9_max_residents', 'res9_max_jobs_raw']) {
    needInt(checks[key], `checks.${key}`);
  }
  needNumber(checks.res9_max_jobs_after, 'checks.res9_max_jobs_after', { min: 0 });
  if (!isObject(checks.jobs_by_segment)) fail('checks.jobs_by_segment must be an object');
  for (const segment of WORKER_SEGMENTS) needInt(checks.jobs_by_segment[segment], `checks.jobs_by_segment.${segment}`);
  if (!isObject(checks.job_review)) fail('checks.job_review must be an object');
  needInt(checks.job_review.blocks, 'checks.job_review.blocks');
  needInt(checks.job_review.jobs, 'checks.job_review.jobs');
  if (!isObject(checks.job_movement)) fail('checks.job_movement must be an object');
  needNumber(checks.job_movement.discarded_share_max, 'checks.job_movement.discarded_share_max', { min: 0, max: 1 });
  needNumber(checks.job_movement.moved_share_max, 'checks.job_movement.moved_share_max', { min: 0, max: 1 });
  needNumber(checks.halo_residents_share_max, 'checks.halo_residents_share_max', { min: 0 });
  needRange(checks.places, 'checks.places');
  needRange(checks.rivals, 'checks.rivals');
  needRange(checks.hosts_with_contact_share, 'checks.hosts_with_contact_share', { min: 0, max: 1 });
  needRange(checks.cells_kept, 'checks.cells_kept');
  if (!Array.isArray(checks.anchors)) fail('checks.anchors must be a list');
  checks.anchors.forEach((a, i) => {
    if (!isObject(a)) fail(`checks.anchors[${i}] must be an object`);
    needString(a.geoid, `checks.anchors[${i}].geoid`, /^[0-9]{15}$/);
    if (!seen.has(a.geoid.slice(0, 5))) fail(`checks.anchors[${i}].geoid ${a.geoid} is outside the region`);
    needString(a.label, `checks.anchors[${i}].label`);
    if (!SECTORS.includes(a.column)) fail(`checks.anchors[${i}].column must be one of CNS01..CNS20`);
    needNumber(a.min, `checks.anchors[${i}].min`, { min: 0 });
  });
  const probe = checks.h3_probe;
  if (!isObject(probe)) fail('checks.h3_probe must be an object');
  needNumber(probe.lat, 'checks.h3_probe.lat', { min: -90, max: 90 });
  needNumber(probe.lng, 'checks.h3_probe.lng', { min: -180, max: 180 });
  needInt(probe.res, 'checks.h3_probe.res');
  needString(probe.cell, 'checks.h3_probe.cell', /^[0-9a-f]{15}$/);
  return doc;
}

/**
 * Loads a region file.
 * @param {string} filePath
 * @returns {{doc: object, sha256: string, path: string}}
 */
export function loadRegion(filePath) {
  let bytes;
  try {
    bytes = fs.readFileSync(filePath);
  } catch (err) {
    throw new RegionError(`region file: cannot read ${filePath}: ${err.message}`);
  }
  let doc;
  try {
    doc = JSON.parse(bytes.toString('utf8'));
  } catch (err) {
    throw new RegionError(`region file: ${filePath} is not valid JSON: ${err.message}`);
  }
  validateRegion(doc);
  return { doc, sha256: crypto.createHash('sha256').update(bytes).digest('hex'), path: filePath };
}

/** Set of the region's five-digit county FIPS codes. */
export function countySet(region) {
  return new Set(region.counties.map((c) => c.fips));
}
