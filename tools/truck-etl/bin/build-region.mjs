#!/usr/bin/env node
// Builds the geodata files of one region (docs/truck-planner/03_DATA.md section 8).
//
//   node tools/truck-etl/bin/build-region.mjs --region=dc --contact=<email>
//
// Exit 0: every gate passed (warnings allowed). Exit 2: a gate failed. Exit 1: usage or I/O error.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from '../src/args.mjs';
import { formatGates } from '../src/gates.mjs';
import { buildRegion } from '../src/pipeline.mjs';

const PACKAGE_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const REPO_DIR = path.resolve(PACKAGE_DIR, '..', '..');

const USAGE = `Usage: node tools/truck-etl/bin/build-region.mjs --region=<id> [options]

  --region=<id>              region to build; reads regions/<id>.json of this package
  --region-file=<path>       region definition file elsewhere (the id comes from the file)
  --contact=<email>          contact address sent in the User-Agent of every download
                             (or environment variable TP_CONTACT_EMAIL). Without one nothing is
                             requested: the build uses the raw cache as with --offline
  --raw-dir=<dir>            raw download cache (default: TP_RAW_DIR, else storage/truck/raw)
  --out-dir=<dir>            parent of all region builds (default storage/truck/build);
                             output goes to <out-dir>/<region>/<dataset_version>/
  --out=<dir>                the region's build directory itself; output goes to <out>/<dataset_version>/
  --seeds=<path>             seed file (default docs/truck-planner/reference/tp_seeds.json)
  --corrections=<path>       job corrections file (default corrections/<id>.jobs.json of this package)
  --places-source=<source>   geofabrik (default) or overpass-tiles (development only)
  --tiles-dir=<dir>          directory of t*.json.gz Overpass tiles, with --places-source=overpass-tiles
  --osm-date=<YYMMDD>        use the Geofabrik extracts of this date, which must be in the raw cache
  --offline                  never download; every file must already be in the raw cache
  --help                     this text

Options may be written --name=value or --name value. Paths are relative to the current directory;
the defaults are relative to the repository root.`;

const BUILT_IN_ERRORS = ['TypeError', 'RangeError', 'ReferenceError', 'SyntaxError', 'EvalError', 'URIError', 'AggregateError'];

function die(message, code = 1) {
  process.stderr.write(`${message}\n`);
  process.exit(code);
}

async function main() {
  let args;
  try {
    args = parseArgs(process.argv.slice(2), {
      values: ['region', 'region-file', 'contact', 'raw-dir', 'out-dir', 'out', 'seeds', 'corrections', 'places-source', 'tiles-dir', 'osm-date'],
      flags: ['offline', 'help'],
    });
  } catch (err) {
    die(`${err.message}\n\n${USAGE}`);
  }
  if (args.help) {
    process.stdout.write(`${USAGE}\n`);
    return;
  }
  if (!args.region && !args['region-file']) die(`--region=<id> or --region-file=<path> is required\n\n${USAGE}`);
  if (args.out && args['out-dir']) die('give --out or --out-dir, not both');

  let regionFile;
  if (args['region-file']) {
    regionFile = path.resolve(args['region-file']);
  } else {
    if (!/^[a-z0-9]{1,24}$/.test(args.region)) die('--region must be a region id such as dc');
    regionFile = path.join(PACKAGE_DIR, 'regions', `${args.region}.json`);
  }
  if (!fs.existsSync(regionFile)) die(`region file not found: ${regionFile}`);
  let regionId;
  try {
    regionId = JSON.parse(fs.readFileSync(regionFile, 'utf8')).id;
  } catch (err) {
    die(`region file ${regionFile} is not valid JSON: ${err.message}`);
  }
  if (args.region && args['region-file'] && args.region !== regionId) die(`--region=${args.region} does not match the id of ${regionFile} (${regionId})`);

  const rawDir = path.resolve(args['raw-dir'] || process.env.TP_RAW_DIR || path.join(REPO_DIR, 'storage', 'truck', 'raw'));
  const outDir = args.out
    ? path.resolve(args.out)
    : path.join(path.resolve(args['out-dir'] || path.join(REPO_DIR, 'storage', 'truck', 'build')), String(regionId));
  const seedsFile = path.resolve(args.seeds || path.join(REPO_DIR, 'docs', 'truck-planner', 'reference', 'tp_seeds.json'));
  const correctionsFile = path.resolve(args.corrections || path.join(PACKAGE_DIR, 'corrections', `${regionId}.jobs.json`));
  const contact = args.contact || process.env.TP_CONTACT_EMAIL || null;

  const started = process.hrtime.bigint();
  const elapsed = () => (Number(process.hrtime.bigint() - started) / 1e9).toFixed(1).padStart(6);
  const log = (message) => process.stderr.write(`[${elapsed()} s] ${message}\n`);

  let result;
  try {
    result = await buildRegion({
      regionFile,
      rawDir,
      outDir,
      seedsFile,
      correctionsFile,
      placesSource: args['places-source'] || 'geofabrik',
      tilesDir: args['tiles-dir'] ? path.resolve(args['tiles-dir']) : null,
      osmDate: args['osm-date'] || null,
      offline: Boolean(args.offline),
      contact,
      log,
    });
  } catch (err) {
    if (err && err.gate) die(`gate ${err.gate} failed: ${err.message}`, 2);
    // The pipeline's own error classes carry a complete message; anything else is a defect and shows its stack.
    const own = err instanceof Error && err.constructor !== Error && !BUILT_IN_ERRORS.includes(err.constructor.name);
    die(own ? err.message : (err && err.stack) || String(err));
  }

  const m = result.manifest;
  const out = [];
  out.push(formatGates(result.gates));
  out.push('');
  out.push(`dataset_version  ${result.datasetVersion}`);
  out.push(`residents        ${m.totals.residents}`);
  out.push(`jobs (raw)       ${m.totals.jobs}`);
  out.push(`jobs spread      ${m.totals.jobs_spread}   discarded ${m.totals.jobs_discarded}   blocks adjusted ${m.totals.blocks_adjusted}`);
  out.push(`points           ${m.counts.points.total} (blocks ${m.counts.points.block}, places ${m.counts.points.place}, halo ${m.counts.points.halo_block + m.counts.points.halo_place})`);
  out.push(`places           ${m.counts.places.total} (in region ${m.counts.places.in_region}, rivals ${m.counts.rivals.total}, hosts ${m.counts.hosts.total})`);
  out.push(`cells            ${m.counts.cells.kept} kept of ${m.counts.cells.candidates} candidates`);
  for (const o of m.outputs) out.push(`  ${o.file.padEnd(16)} ${String(o.rows).padStart(8)} rows ${String(o.bytes).padStart(11)} bytes  sha256 ${o.sha256}`);
  const mode = args.offline ? '--offline' : (contact ? 'online' : 'no contact address, nothing requested');
  out.push(`raw cache        ${rawDir} (${mode}; ${result.stats.requests} requests)`);
  out.push(`output           ${result.outputDir}`);
  const failed = result.gates.filter((g) => !g.pass && g.level === 'fail').length;
  const warned = result.gates.filter((g) => !g.pass && g.level === 'warn').length;
  out.push(`gates            ${result.gates.length - failed - warned} passed, ${warned} warnings, ${failed} failed`);
  out.push(`wall clock       ${elapsed().trim()} s   peak memory ${(process.resourceUsage().maxRSS / 1024).toFixed(0)} MiB`);
  process.stdout.write(`${out.join('\n')}\n`);
  if (!result.ok) process.exit(2);
}

main();
