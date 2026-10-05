// Stage order of a region build (03_DATA.md section 8):
// download, blocks, jobs and corrections, places, source points and halo, cells, gates, write.

import fs from 'node:fs';
import path from 'node:path';
import { isValidCell, getResolution, latLngToCell } from 'h3-js';
import { readBlocks, readXwalkLabels } from './blocks.mjs';
import { enumerateCandidates, occupancy, pruneCandidates } from './cells.mjs';
import { TREATMENT_KEEP, TREATMENT_NONE, applyCorrections, loadCorrections } from './corrections.mjs';
import { buildCounties, countyOf } from './counties.mjs';
import { RawCache, ensureRegionFiles, manifestSources, snapshotDateFromYymmdd } from './download.mjs';
import { evaluateGates, gatesPass } from './gates.mjs';
import { insideBox } from './geo.mjs';
import { compileSegmentGrouping } from './lodes.mjs';
import { buildManifest, datasetVersion, manifestParameters, renderManifest } from './manifest.mjs';
import { listTiles, readOverpassTile } from './overpass.mjs';
import { readPbf } from './pbf.mjs';
import { DROP_COUNTERS, collectPlaces } from './places.mjs';
import { buildSourcePoints } from './points.mjs';
import { loadRegion } from './region.mjs';
import { buildReviewRows } from './review.mjs';
import { loadSeeds } from './seeds.mjs';
import { TRIGGER_KEYS } from './taxonomy.mjs';
import { GEOM_KINDS, PLACE_TYPES, RIVAL_KINDS, SECTORS, SEGMENTS, WORKER_SEGMENTS, CNS04_INDEX } from './vocabulary.mjs';
import { describeOutput, renderCells, renderPlaces, renderPoints, renderReview, sha256Hex } from './writers.mjs';

export class BuildError extends Error {}

function isoSeconds(epochSeconds) {
  return new Date(epochSeconds * 1000).toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function zeroCounts(keys) {
  const out = {};
  for (const key of keys) out[key] = 0;
  return out;
}

/**
 * Builds one region.
 *
 * @param {object} options
 * @param {string} options.regionFile path of the region definition file
 * @param {string} options.rawDir raw download cache
 * @param {string} options.outDir the region's build directory: output goes to <outDir>/<dataset_version>/
 * @param {string} options.seedsFile path of tp_seeds.json
 * @param {string} options.correctionsFile path of the job corrections file
 * @param {'geofabrik'|'overpass-tiles'} [options.placesSource]
 * @param {string|null} [options.tilesDir] directory of t*.json.gz files (overpass-tiles)
 * @param {string|null} [options.osmDate] pinned Geofabrik date, YYMMDD
 * @param {boolean} [options.offline]
 * @param {string|null} [options.contact]
 * @param {(message: string) => void} [options.log]
 * @param {typeof fetch} [options.fetchImpl]
 * @param {boolean} [options.write] false computes everything and writes nothing (tests)
 * @returns {Promise<{ok: boolean, datasetVersion: string, outputDir: string|null, manifest: object, files: Record<string, string>}>}
 */
export async function buildRegion(options) {
  const {
    regionFile, rawDir, outDir, seedsFile, correctionsFile,
    placesSource = 'geofabrik', tilesDir = null, osmDate = null, offline = false, contact = null,
    log = () => {}, fetchImpl = globalThis.fetch, write = true,
  } = options;
  if (placesSource !== 'geofabrik' && placesSource !== 'overpass-tiles') {
    throw new BuildError(`places source must be geofabrik or overpass-tiles, not ${JSON.stringify(placesSource)}`);
  }
  if (placesSource === 'overpass-tiles' && !tilesDir) throw new BuildError('--places-source=overpass-tiles needs --tiles-dir=<dir>');

  // ---- inputs ----
  const { doc: region, sha256: regionSha } = loadRegion(regionFile);
  const seeds = loadSeeds(seedsFile);
  const trafficMatrix = region.traffic_matrix || 'us_mean';
  if (!seeds.hasTrafficMatrix(trafficMatrix)) {
    throw new BuildError(`seed file: traffic.${trafficMatrix} or traffic.${trafficMatrix}_typical is missing (region traffic_matrix)`);
  }
  const corrections = loadCorrections(correctionsFile, region.id);
  const R = seeds.earthRadiusM;
  const res = region.h3_res;

  // ---- download ----
  log('stage download');
  const cache = new RawCache({ dir: rawDir, offline, contact, fetchImpl, log });
  if (cache.noRequests && !offline) {
    log('  no contact address (--contact or TP_CONTACT_EMAIL): nothing is requested, the raw cache is used as with --offline');
  }
  const raw = await ensureRegionFiles(cache, region, { placesSource, osmDate });
  const { counties: countyList, bounds } = (() => {
    const lookup = buildCounties(JSON.parse(fs.readFileSync(raw.files.counties, 'utf8')), region.counties.map((c) => c.fips));
    return { counties: lookup, bounds: lookup.bounds };
  })();

  // ---- blocks ----
  log('stage blocks');
  const { table, stats: blockStats } = readBlocks(region, raw.files);
  const regionList = [];
  for (let i = 0; i < table.n; i++) if (table.inRegion[i]) regionList.push(i);
  const regionBlocks = Int32Array.from(regionList);
  const nRegion = regionBlocks.length;

  // ---- jobs and corrections ----
  log('stage jobs and corrections');
  const grouping = compileSegmentGrouping(seeds.segmentCns, seeds.cns04Weight);
  const rGeoids = new Array(nRegion);
  const rResidents = new Int32Array(nRegion);
  const rC000 = new Int32Array(nRegion);
  const rRaw = new Int32Array(nRegion * 20);
  const rHasWac = new Uint8Array(nRegion);
  for (let k = 0; k < nRegion; k++) {
    const i = regionBlocks[k];
    rGeoids[k] = table.geoid[i];
    rResidents[k] = table.residents[i];
    rC000[k] = table.c000[i];
    rHasWac[k] = table.hasWac[i];
    for (let s = 0; s < 20; s++) rRaw[k * 20 + s] = table.cns[i * 20 + s];
  }
  const thresholds = region.job_review;
  const result = applyCorrections({
    geoids: rGeoids, residents: rResidents, c000: rC000, raw: rRaw, hasWac: rHasWac, entries: corrections.entries, thresholds,
  });

  // region sums
  const sectorTotals = new Float64Array(20);
  const countyRows = new Map(region.counties.map((c) => [c.fips, { fips: c.fips, residents: 0, housing_units: 0, jobs: 0, blocks: 0 }]));
  const regionSums = { residents: 0, housingUnits: 0, jobs: 0, blocksWithResidents: 0, blocksWithJobs: 0, blocksWithEither: 0 };
  for (let k = 0; k < nRegion; k++) {
    const i = regionBlocks[k];
    const county = countyRows.get(rGeoids[k].slice(0, 5));
    county.residents += table.residents[i];
    county.housing_units += table.housing[i];
    county.jobs += table.c000[i];
    county.blocks++;
    regionSums.residents += table.residents[i];
    regionSums.housingUnits += table.housing[i];
    regionSums.jobs += table.c000[i];
    if (table.residents[i] > 0) regionSums.blocksWithResidents++;
    if (table.c000[i] > 0) regionSums.blocksWithJobs++;
    if (table.residents[i] > 0 || table.c000[i] > 0) regionSums.blocksWithEither++;
    for (let s = 0; s < 20; s++) sectorTotals[s] += rRaw[k * 20 + s];
  }
  const jobsBySegmentRaw = zeroCounts(WORKER_SEGMENTS);
  for (let s = 0; s < 20; s++) jobsBySegmentRaw[WORKER_SEGMENTS[grouping.segmentOfSector[s]]] += sectorTotals[s];

  // ---- places ----
  log('stage places');
  let inputs;
  let tileFiles = [];
  let newestTile = null;
  let snapshotDate;
  let replicationTimestamp = null;
  if (placesSource === 'geofabrik') {
    snapshotDate = snapshotDateFromYymmdd(raw.osmDate);
    inputs = region.states.map((state) => ({
      label: state.usps,
      read: (readerOptions) => {
        const out = readPbf(raw.files.pbf[state.usps], readerOptions);
        log(`  ${state.geofabrik}: ${out.stats.nodes} nodes, ${out.stats.ways} ways, ${out.stats.relations} relations, `
          + `${out.elements.length} elements kept`);
        return out;
      },
    }));
    for (const state of region.states) {
      const ts = raw.pbfHeaders[state.usps].replicationTimestamp;
      if (ts !== null && (replicationTimestamp === null || ts > replicationTimestamp)) replicationTimestamp = ts;
    }
  } else {
    tileFiles = listTiles(tilesDir);
    if (tileFiles.length === 0) throw new BuildError(`no t*.json.gz tile in ${tilesDir}`);
    inputs = tileFiles.map((file) => ({
      label: path.basename(file),
      read: (readerOptions) => {
        const out = readOverpassTile(file, readerOptions);
        if (newestTile === null || Date.parse(out.timestamp) > Date.parse(newestTile)) newestTile = out.timestamp;
        return out;
      },
    }));
  }
  const placeStage = collectPlaces({
    inputs, fetchBox: region.fetch_box, placeTypes: seeds.placeTypes, counties: countyList, earthRadiusM: R, triggerKeys: TRIGGER_KEYS,
  });
  if (placesSource === 'overpass-tiles') {
    // The snapshot is the UTC date of the newest tile.
    replicationTimestamp = Math.floor(Date.parse(newestTile) / 1000);
    snapshotDate = isoSeconds(replicationTimestamp).slice(0, 10);
  }

  // ---- source points and halo ----
  log('stage source points and halo');
  const pointStage = buildSourcePoints({
    table, regionBlocks, corrected: result.corrected, reduced: result.reduced, grouping, places: placeStage.places,
    thresholds, walkCutoffM: seeds.walkCutoffM, earthRadiusM: R,
  });
  const { points, places } = pointStage;
  placeStage.counters.dropped_outside_region = pointStage.stats.droppedOutsideRegion;

  // ---- cells ----
  log('stage cells');
  const candidates = enumerateCandidates(pointStage.s0, res, seeds.walkCutoffM, R);
  const pruned = pruneCandidates(candidates.cells, points, {
    walkDecayM: seeds.walkDecayM, walkCutoffM: seeds.walkCutoffM, earthRadiusM: R,
    cellMinNearby: seeds.cellMinNearby, cellMinVenue: seeds.cellMinVenue,
  });
  const occ = occupancy(table, regionBlocks, result.corrected, res);

  // ---- review list ----
  log('stage review list');
  const wanted = new Set(corrections.entries.keys());
  for (let k = 0; k < nRegion; k++) if (result.rule[k] !== '') wanted.add(rGeoids[k]);
  const xwalk = readXwalkLabels(region, raw.files, table, wanted);
  const reviewRows = buildReviewRows({
    table, regionBlocks, result, entries: corrections.entries, labels: xwalk.labels, places, earthRadiusM: R,
  });

  // ---- render ----
  log('stage write');
  const text = {
    'points.tsv': renderPoints(points),
    'places.ndjson': renderPlaces(places),
    'cells.tsv': renderCells(pruned.kept),
    'job_review.csv': renderReview(reviewRows),
  };
  const outputs = [
    describeOutput('points.tsv', text['points.tsv'], points.length),
    describeOutput('places.ndjson', text['places.ndjson'], places.length),
    describeOutput('cells.tsv', text['cells.tsv'], pruned.kept.length),
    describeOutput('job_review.csv', text['job_review.csv'], reviewRows.length),
  ];
  const version = datasetVersion(region.id, snapshotDate, {
    points: outputs[0].sha256, places: outputs[1].sha256, cells: outputs[2].sha256, review: outputs[3].sha256,
  }, seeds.modelVersion, seeds.seedsRevision);

  // ---- counts and totals (region rows only; halo figures are kept apart) ----
  const baseBySegment = zeroCounts(SEGMENTS);
  const blockRowSegments = new Float64Array(16);
  let haloRowsInTotals = 0;
  const regionCountySet = new Set(region.counties.map((c) => c.fips));
  let pointsOutsideBox = 0;
  for (const p of points) {
    if (!insideBox(p.lat, p.lng, region.fetch_box)) pointsOutsideBox++;
    if (!p.inRegion) {
      if (p.kind === 'block' && regionCountySet.has(p.ref.slice(0, 5))) haloRowsInTotals++;
      continue;
    }
    if (p.kind === 'block' && !regionCountySet.has(p.ref.slice(0, 5))) haloRowsInTotals++;
    for (let s = 0; s < 16; s++) {
      baseBySegment[SEGMENTS[s]] += p.base[s];
      if (p.kind === 'block') blockRowSegments[s] += p.base[s];
    }
  }

  const byType = zeroCounts(PLACE_TYPES);
  const byGeom = zeroCounts(GEOM_KINDS);
  const rivalsByKind = zeroCounts(RIVAL_KINDS);
  const visitorsByType = zeroCounts(PLACE_TYPES);
  const coverage = { phone_raw: 0, phone_ok: 0, website_raw: 0, website_ok: 0, hours_raw: 0, hours_parsed: 0 };
  const placeCounts = { inRegion: 0, halo: 0, named: 0, rivals: 0, visitors: 0, hosts: 0, hostsWithContact: 0, noCoordinates: 0, notInCounty: 0, outsideBox: 0 };
  for (const place of places) {
    if (!Number.isFinite(place.lat) || !Number.isFinite(place.lng)) placeCounts.noCoordinates++;
    if (!insideBox(place.lat, place.lng, region.fetch_box)) placeCounts.outsideBox++;
    if (!place.in_region) {
      placeCounts.halo++;
      if (place.county_fips !== null) haloRowsInTotals++;
      continue;
    }
    placeCounts.inRegion++;
    if (countyOf(countyList, place.lat, place.lng) !== place.county_fips || place.county_fips === null) placeCounts.notInCounty++;
    byType[place.place_type]++;
    byGeom[place.geom_kind]++;
    if (place.name !== null) placeCounts.named++;
    if (place.rival_kind !== null) { placeCounts.rivals++; rivalsByKind[place.rival_kind]++; }
    if (place.visitor_segment !== null) { placeCounts.visitors++; visitorsByType[place.place_type]++; }
    if (place.host_fit > 0) {
      placeCounts.hosts++;
      if (place.phone !== null || place.website !== null) placeCounts.hostsWithContact++;
    }
    if (place.phoneRaw) { coverage.phone_raw++; if (place.phone !== null) coverage.phone_ok++; }
    if (place.websiteRaw) { coverage.website_raw++; if (place.website !== null) coverage.website_ok++; }
    if (place.opening_hours_raw !== null) { coverage.hours_raw++; if (place.hours_mask !== null) coverage.hours_parsed++; }
  }

  const dropped = DROP_COUNTERS.reduce((sum, name) => sum + placeStage.counters[name], 0);
  const counts = {
    blocks: {
      state: table.n,
      by_state: blockStats.stateBlocks,
      region: nRegion,
      with_residents: regionSums.blocksWithResidents,
      with_jobs: regionSums.blocksWithJobs,
      with_either: regionSums.blocksWithEither,
    },
    points: {
      total: points.length,
      block: pointStage.stats.blockRows,
      place: pointStage.stats.placeRows,
      halo_block: pointStage.stats.haloBlockRows,
      halo_place: pointStage.stats.haloPlaceRows,
    },
    places: {
      total: places.length,
      in_region: placeCounts.inRegion,
      halo: placeCounts.halo,
      named: placeCounts.named,
      by_type: byType,
      by_geom_kind: byGeom,
    },
    rivals: { total: placeCounts.rivals, by_kind: rivalsByKind },
    visitor_sources: { total: placeCounts.visitors, by_type: visitorsByType },
    hosts: { total: placeCounts.hosts, with_contact: placeCounts.hostsWithContact },
    halo: {
      residents: pointStage.stats.haloResidents,
      jobs_raw: pointStage.stats.haloJobsRaw,
      blocks_capped: pointStage.stats.haloBlocksCapped,
      visitor_places: pointStage.stats.haloVisitorPlaces,
      rival_places: pointStage.stats.haloRivalPlaces,
    },
    cells: {
      candidates: candidates.cells.length,
      kept: pruned.kept.length,
      kept_by_venue_test_only: pruned.stats.keptByVenueOnly,
      point_cell_pairs: pruned.stats.pairs,
      max_points_in_range: pruned.stats.maxPointsInRange,
      occupied: occ.occupiedCells,
      max_residents: occ.maxResidents.value,
      max_jobs_raw: occ.maxJobsRaw.value,
      max_jobs_after: occ.maxJobsAfter.value,
      max_jobs_after_cell: occ.maxJobsAfter.h3,
    },
    elements_read: placeStage.read,
  };
  for (const name of DROP_COUNTERS) counts[name] = placeStage.counters[name];
  counts.coverage = coverage;

  const jobsBySector = {};
  SECTORS.forEach((name, s) => { jobsBySector[name] = sectorTotals[s]; });
  const totals = {
    residents: regionSums.residents,
    housing_units: regionSums.housingUnits,
    jobs: regionSums.jobs,
    jobs_by_sector: jobsBySector,
    base_by_segment: baseBySegment,
    jobs_spread: result.totals.jobsSpread,
    jobs_discarded: result.totals.jobsDiscarded,
    halo_jobs_capped: pointStage.stats.haloJobsCapped,
    jobs_spread_lost: result.totals.jobsSpreadLost,
    blocks_adjusted: result.totals.blocksAdjusted,
    review_blocks: result.totals.flaggedBlocks,
    review_jobs: result.totals.flaggedJobs,
    by_county: region.counties.map((c) => countyRows.get(c.fips)),
  };

  // ---- gates ----
  let maxSegmentError = 0;
  WORKER_SEGMENTS.forEach((segment, w) => {
    let expected = 0;
    for (let s = 0; s < 20; s++) {
      if (grouping.segmentOfSector[s] === w) expected += grouping.weightOfSector[s] * (sectorTotals[s] - result.discardedBySector[s]);
    }
    const actual = blockRowSegments[1 + w];
    const error = expected === 0 ? Math.abs(actual) : Math.abs(actual - expected) / Math.abs(expected);
    if (error > maxSegmentError) maxSegmentError = error;
  });

  let capsExceeded = 0;
  for (let k = 0; k < nRegion; k++) {
    const t = result.treatment[k];
    if (t === TREATMENT_NONE || t === TREATMENT_KEEP) continue;
    const entry = corrections.entries.get(rGeoids[k]);
    const sectors = entry && entry.sectors ? entry.sectors : null;
    let kept = 0;
    if (sectors) for (const s of sectors) kept += result.corrected[k * 20 + s];
    else for (let s = 0; s < 20; s++) kept += result.corrected[k * 20 + s];
    if (kept > result.cap[k] * (1 + 1e-9) + 1e-9) capsExceeded++;
  }

  const reviewGeoids = new Set(reviewRows.map((row) => row.geoid));
  let reviewSpread = 0;
  let reviewDiscarded = 0;
  for (const row of reviewRows) {
    if (typeof row.jobs_spread === 'number') reviewSpread += row.jobs_spread;
    if (typeof row.jobs_discarded === 'number') reviewDiscarded += row.jobs_discarded;
  }
  let adjustedWithoutRow = 0;
  for (let k = 0; k < nRegion; k++) {
    if ((result.jobsSpread[k] > 0 || result.jobsDiscarded[k] > 0) && !reviewGeoids.has(rGeoids[k])) adjustedWithoutRow++;
  }

  let invalidCells = 0;
  for (const cell of pruned.kept) if (!isValidCell(cell.h3) || getResolution(cell.h3) !== res) invalidCells++;
  const probe = region.checks.h3_probe;

  const anchors = region.checks.anchors.map((a) => {
    const k = rGeoids.indexOf(a.geoid);
    return { geoid: a.geoid, column: a.column, jobs: k < 0 ? null : result.corrected[k * 20 + SECTORS.indexOf(a.column)] };
  });

  const states = region.states.map((state) => {
    const out = { fips: state.fips, residents: 0, jobs: 0, blocks: 0, expectedResidents: 0, expectedJobs: 0 };
    for (const c of region.counties) {
      if (c.fips.slice(0, 2) !== state.fips) continue;
      const row = countyRows.get(c.fips);
      out.residents += row.residents;
      out.jobs += row.jobs;
      out.blocks += row.blocks;
      out.expectedResidents += c.residents;
      out.expectedJobs += c.jobs;
    }
    return out;
  });

  const requiredFiles = [...cache.verified.values()].filter((v) => !v.spec.sidecar).length;
  const facts = {
    placesSource,
    raw: { verified: requiredFiles, required: requiredFiles, missingSidecars: cache.missingSidecars.slice().sort() },
    pl: { rows: blockStats.plRows, badRows: blockStats.plBadRows },
    lodes: { formats: raw.lodes.map((l) => l.format), vintages: raw.lodes.map((l) => l.vintage) },
    stateResidents: blockStats.stateResidents,
    stateBlocks: blockStats.stateBlocks,
    wacBlocksNotInPl: blockStats.wacBlocksNotInPl,
    wacSumMismatches: blockStats.wacSumMismatches,
    xwalk: { rows: xwalk.rows, notInPl: xwalk.notInPl, duplicates: xwalk.duplicates, missing: xwalk.missing },
    region: {
      residents: regionSums.residents,
      housingUnits: regionSums.housingUnits,
      blocks: nRegion,
      blocksWithResidents: regionSums.blocksWithResidents,
      blocksWithJobs: regionSums.blocksWithJobs,
      blocksWithEither: regionSums.blocksWithEither,
      jobs: regionSums.jobs,
      jobsBySegmentRaw,
      cns04Jobs: sectorTotals[CNS04_INDEX],
      counties: totals.by_county,
    },
    conservation: { maxSegmentError, residentsInRows: blockRowSegments[0] },
    placesFlow: { read: placeStage.read, kept: places.length, dropped },
    jobs: {
      jobsSpread: result.totals.jobsSpread,
      jobsDiscarded: result.totals.jobsDiscarded,
      jobsSpreadLost: result.totals.jobsSpreadLost,
      reviewSpread,
      reviewDiscarded,
      adjustedWithoutRow,
    },
    geometry: {
      outsideBox: pointsOutsideBox + placeCounts.outsideBox,
      placesNotInCounty: placeCounts.notInCounty,
      invalidCells,
      probeCell: latLngToCell(probe.lat, probe.lng, probe.res),
    },
    occupancy: occ,
    capsExceeded,
    places: {
      region: placeCounts.inRegion,
      rivals: placeCounts.rivals,
      named: placeCounts.named,
      hosts: placeCounts.hosts,
      hostsWithContact: placeCounts.hostsWithContact,
      hoursRaw: coverage.hours_raw,
      hoursParsed: coverage.hours_parsed,
      noCoordinates: placeCounts.noCoordinates,
      missingTypes: PLACE_TYPES.filter((t) => byType[t] === 0),
    },
    anchors,
    states,
    review: {
      flaggedBlocks: result.totals.flaggedBlocks,
      flaggedJobs: result.totals.flaggedJobs,
      stale: result.totals.staleEntries,
      orphan: result.totals.orphanEntries,
      autoJobs: result.totals.autoJobs,
      unconfirmed: result.totals.unconfirmedEntries,
    },
    halo: { residents: pointStage.stats.haloResidents, rowsInTotals: haloRowsInTotals },
    cells: { ring5Accepted: candidates.ring5Accepted, kept: pruned.kept.length },
  };
  const gates = evaluateGates(facts, region);
  const ok = gatesPass(gates);

  // ---- manifest ----
  const sources = manifestSources(cache, region);
  for (const file of tileFiles) {
    const bytes = fs.readFileSync(file);
    sources.push({
      kind: 'overpass_tile', state: null, path: path.basename(file), url: null, final_url: null,
      bytes: bytes.length, sha256: sha256Hex(bytes), last_modified: null,
    });
  }
  const manifest = buildManifest({
    datasetVersion: version,
    region,
    bounds,
    inputs: {
      model_version: seeds.modelVersion,
      region_file_sha256: regionSha,
      seeds_revision: seeds.seedsRevision,
      seeds_sha256: seeds.sha256,
      corrections_version: corrections.version,
      corrections_sha256: corrections.sha256,
      places_source: placesSource,
    },
    parameters: manifestParameters(seeds, region),
    sources,
    vintages: {
      census_reference_date: region.census.reference_date,
      lodes_year: region.lodes.year,
      lodes_format: raw.lodes.length > 0 && raw.lodes.every((l) => l.format === raw.lodes[0].format) ? raw.lodes[0].format : raw.lodes.map((l) => l.format).join(','),
      lodes_vintage: raw.lodes.length > 0 && raw.lodes.every((l) => l.vintage === raw.lodes[0].vintage) ? raw.lodes[0].vintage : raw.lodes.map((l) => l.vintage).join(','),
      osm_snapshot_date: snapshotDate,
      osm_replication_timestamp: replicationTimestamp === null ? null : isoSeconds(replicationTimestamp),
    },
    counts,
    totals,
    gates,
    outputs,
  });
  text['manifest.json'] = renderManifest(manifest);

  let outputDir = null;
  if (write) {
    outputDir = path.join(outDir, ok ? version : '_failed');
    fs.rmSync(outputDir, { recursive: true, force: true });
    fs.mkdirSync(outputDir, { recursive: true });
    for (const [name, content] of Object.entries(text)) fs.writeFileSync(path.join(outputDir, name), content);
  }
  return {
    ok, datasetVersion: version, outputDir, manifest, files: text, gates,
    stats: { inputs: placeStage.inputStats, sitePartByType: placeStage.sitePartByType, cells: pruned.stats, points: pointStage.stats, requests: cache.requests },
  };
}
