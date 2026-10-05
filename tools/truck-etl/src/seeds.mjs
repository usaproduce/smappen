// The only module that knows the JSON paths of docs/truck-planner/reference/tp_seeds.json (03_DATA.md 0.1).
// A missing or malformed value is a hard error that names the path.

import fs from 'node:fs';
import crypto from 'node:crypto';
import { PLACE_TYPES, RIVAL_KINDS, SECTORS, SEGMENTS, WORKER_SEGMENTS } from './vocabulary.mjs';

export class SeedError extends Error {}

function at(root, path) {
  let node = root;
  for (const key of path.split('.')) {
    if (node === null || typeof node !== 'object' || !(key in node)) {
      throw new SeedError(`seed file: missing value at ${path}`);
    }
    node = node[key];
  }
  return node;
}

function numberAt(root, path, { min = -Infinity, max = Infinity } = {}) {
  const v = at(root, path);
  if (typeof v !== 'number' || !Number.isFinite(v)) throw new SeedError(`seed file: ${path} must be a finite number`);
  if (v < min || v > max) throw new SeedError(`seed file: ${path} = ${v} is outside ${min}..${max}`);
  return v;
}

function stringAt(root, path) {
  const v = at(root, path);
  if (typeof v !== 'string' || v === '') throw new SeedError(`seed file: ${path} must be a non-empty string`);
  return v;
}

/**
 * Reads the seed values the pipeline uses from a parsed seed document.
 * @param {object} doc parsed tp_seeds.json
 * @param {string|null} sha256 SHA-256 of the file bytes (recorded in the manifest for information)
 */
export function seedsFromDocument(doc, sha256 = null) {
  const modelVersion = stringAt(doc, 'model_version');
  const seedsRevision = numberAt(doc, 'seeds_revision', { min: 0 });
  if (!Number.isInteger(seedsRevision)) throw new SeedError('seed file: seeds_revision must be an integer');

  const earthRadiusM = numberAt(doc, 'constants.earth_radius_m.value', { min: 1 });
  const walkDecayM = numberAt(doc, 'kernel.walk_decay_m.value', { min: 1e-9 });
  const walkCutoffM = numberAt(doc, 'kernel.walk_cutoff_m.value', { min: 1e-9 });
  const cns04Weight = numberAt(doc, 'etl.cns04_weight.value', { min: 0, max: 1 });
  const cellMinNearby = numberAt(doc, 'etl.cell_min_nearby.value', { min: 0 });
  const cellMinVenue = numberAt(doc, 'etl.cell_min_venue.value', { min: 0 });

  /** @type {Record<string, string[]>} */
  const segmentCns = {};
  const owner = new Map();
  for (const segment of WORKER_SEGMENTS) {
    const path = `segments.${segment}.lodes_cns`;
    const list = at(doc, path);
    if (!Array.isArray(list) || list.length === 0) throw new SeedError(`seed file: ${path} must be a non-empty list`);
    for (const sector of list) {
      if (!SECTORS.includes(sector)) throw new SeedError(`seed file: ${path} holds unknown sector ${JSON.stringify(sector)}`);
      if (owner.has(sector)) {
        throw new SeedError(`seed file: ${sector} appears in both segments.${owner.get(sector)}.lodes_cns and ${path}`);
      }
      owner.set(sector, segment);
    }
    segmentCns[segment] = list.slice();
  }
  for (const sector of SECTORS) {
    if (!owner.has(sector)) throw new SeedError(`seed file: ${sector} appears in no segments.<segment>.lodes_cns list`);
  }

  /** @type {Record<string, {visitor_segment: string|null, default_size: number, rival_kind: string|null, host_fit: number, kitchen_default: 'yes'|'no'}>} */
  const placeTypes = {};
  for (const type of PLACE_TYPES) {
    const base = `place_types.rows.${type}`;
    const visitorSegment = at(doc, `${base}.visitor_segment`);
    if (visitorSegment !== null && !SEGMENTS.includes(visitorSegment)) {
      throw new SeedError(`seed file: ${base}.visitor_segment is not a segment: ${JSON.stringify(visitorSegment)}`);
    }
    const rivalKind = at(doc, `${base}.rival_kind`);
    if (rivalKind !== null && !RIVAL_KINDS.includes(rivalKind)) {
      throw new SeedError(`seed file: ${base}.rival_kind is not a rival kind: ${JSON.stringify(rivalKind)}`);
    }
    const kitchenDefault = at(doc, `${base}.kitchen_default`);
    if (kitchenDefault !== 'yes' && kitchenDefault !== 'no') {
      throw new SeedError(`seed file: ${base}.kitchen_default must be "yes" or "no"`);
    }
    placeTypes[type] = {
      visitor_segment: visitorSegment,
      default_size: numberAt(doc, `${base}.default_size`, { min: 0 }),
      rival_kind: rivalKind,
      host_fit: numberAt(doc, `${base}.host_fit`, { min: 0, max: 1 }),
      kitchen_default: kitchenDefault,
    };
  }

  const traffic = doc.traffic !== null && typeof doc.traffic === 'object' ? doc.traffic : {};

  return Object.freeze({
    sha256,
    modelVersion,
    seedsRevision,
    earthRadiusM,
    walkDecayM,
    walkCutoffM,
    cns04Weight,
    cellMinNearby,
    cellMinVenue,
    segmentCns,
    placeTypes,
    /** True when both `traffic.<name>` and `traffic.<name>_typical` exist. */
    hasTrafficMatrix(name) {
      return Object.prototype.hasOwnProperty.call(traffic, name)
        && Object.prototype.hasOwnProperty.call(traffic, `${name}_typical`);
    },
  });
}

/** Loads and validates the seed file at `filePath`. */
export function loadSeeds(filePath) {
  let bytes;
  try {
    bytes = fs.readFileSync(filePath);
  } catch (err) {
    throw new SeedError(`seed file: cannot read ${filePath}: ${err.message}`);
  }
  let doc;
  try {
    doc = JSON.parse(bytes.toString('utf8'));
  } catch (err) {
    throw new SeedError(`seed file: ${filePath} is not valid JSON: ${err.message}`);
  }
  return seedsFromDocument(doc, crypto.createHash('sha256').update(bytes).digest('hex'));
}
