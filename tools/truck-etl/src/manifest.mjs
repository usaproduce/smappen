// dataset_version and manifest.json (03_DATA.md section 8 and 8.5). No wall-clock value is written.

import { PIPELINE_VERSION, PLACE_TYPES, WORKER_SEGMENTS } from './vocabulary.mjs';
import { sha256Hex } from './writers.mjs';

/** Attribution strings 1, 2, 3, 4, 5 and 8 of 03_DATA.md section 14. Placeholders in braces are left as written. */
export const ATTRIBUTION = Object.freeze({
  osm: '© OpenStreetMap contributors',
  osm_long: 'Place data © OpenStreetMap contributors, available under the Open Database License (ODbL).',
  residents: 'Residents: U.S. Census Bureau, 2020 Census Redistricting Data (Public Law 94-171). Counts as of April 1, 2020, not adjusted for growth.',
  jobs: 'Jobs: U.S. Census Bureau, LEHD Origin-Destination Employment Statistics (LODES), version 8.4, 2023, all jobs. Job counts are jobs of record with statistical noise added by the Census Bureau, not people present. {blocks_adjusted} payroll-address blocks holding {jobs_spread} jobs were spread over their county (corrections {corrections_version}); construction jobs count at {cns04_weight_percent} %.',
  places: 'Places: OpenStreetMap snapshot of {osm_snapshot_date} (Geofabrik extracts). © OpenStreetMap contributors, ODbL 1.0. The places table is a database derived from OpenStreetMap and is available under the ODbL on request: {contact}.',
  boundaries: 'County boundaries: U.S. Census Bureau, TIGERweb.',
});

/**
 * dataset_version = <region>-<YYYYMMDD of the OSM snapshot>-<h8>, h8 = first 8 hex characters of the SHA-256 of
 * six LF-terminated lines: the SHA-256 of points.tsv, places.ndjson, cells.tsv and job_review.csv, the model
 * version, and the seeds revision in decimal.
 * @param {string} regionId
 * @param {string} snapshotDate YYYY-MM-DD
 * @param {{points: string, places: string, cells: string, review: string}} hashes
 * @param {string} modelVersion
 * @param {number} seedsRevision
 */
export function datasetVersion(regionId, snapshotDate, hashes, modelVersion, seedsRevision) {
  const lines = [hashes.points, hashes.places, hashes.cells, hashes.review, modelVersion, String(seedsRevision)];
  const h8 = sha256Hex(lines.map((line) => `${line}\n`).join('')).slice(0, 8);
  const version = `${regionId}-${snapshotDate.replace(/-/g, '')}-${h8}`;
  if (version.length > 48 || !/^[a-z0-9-]+$/.test(version)) throw new Error(`manifest: dataset_version ${version} is not valid`);
  return version;
}

/** The `parameters` object: every seed value the pipeline used, plus h3_res and the job_review thresholds. */
export function manifestParameters(seeds, region) {
  const segmentCns = {};
  for (const segment of WORKER_SEGMENTS) segmentCns[segment] = seeds.segmentCns[segment].slice();
  const placeTypes = {};
  for (const type of PLACE_TYPES) {
    const row = seeds.placeTypes[type];
    placeTypes[type] = {
      visitor_segment: row.visitor_segment,
      default_size: row.default_size,
      rival_kind: row.rival_kind,
      host_fit: row.host_fit,
      kitchen_default: row.kitchen_default,
    };
  }
  return {
    walk_decay_m: seeds.walkDecayM,
    walk_cutoff_m: seeds.walkCutoffM,
    earth_radius_m: seeds.earthRadiusM,
    cns04_weight: seeds.cns04Weight,
    cell_min_nearby: seeds.cellMinNearby,
    cell_min_venue: seeds.cellMinVenue,
    segment_cns: segmentCns,
    place_types: placeTypes,
    h3_res: region.h3_res,
    job_review: {
      total_min: region.job_review.total_min,
      single_sector_min: region.job_review.single_sector_min,
      single_sector_share: region.job_review.single_sector_share,
    },
  };
}

/**
 * Assembles the manifest with its keys in file order.
 * @returns {object}
 */
export function buildManifest({
  datasetVersion: version, region, bounds, inputs, parameters, sources, vintages, counts, totals, gates, outputs,
}) {
  const attribution = { ...ATTRIBUTION };
  attribution.places = attribution.places.replace('{osm_snapshot_date}', vintages.osm_snapshot_date);
  return {
    schema: 1,
    dataset_version: version,
    region_id: region.id,
    pipeline_version: PIPELINE_VERSION,
    model_version: inputs.model_version,
    region,
    bounds,
    inputs: {
      region_file_sha256: inputs.region_file_sha256,
      seeds_revision: inputs.seeds_revision,
      seeds_sha256: inputs.seeds_sha256,
      corrections_version: inputs.corrections_version,
      corrections_sha256: inputs.corrections_sha256,
      places_source: inputs.places_source,
    },
    parameters,
    sources,
    vintages,
    counts,
    totals,
    gates,
    outputs,
    attribution,
  };
}

/** manifest.json text: two-space indent, LF, final newline. */
export function renderManifest(manifest) {
  return `${JSON.stringify(manifest, null, 2)}\n`;
}
