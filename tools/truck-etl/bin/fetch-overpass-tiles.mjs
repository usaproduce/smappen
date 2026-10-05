#!/usr/bin/env node
// Developer tool: saves the places of a region's fetch box as Overpass JSON tiles, the alternative input of
// docs/truck-planner/03_DATA.md section 2.3 (--places-source=overpass-tiles). Never part of a production
// refresh: the public Overpass server allows one-off use only. One request at a time.
//
//   node tools/truck-etl/bin/fetch-overpass-tiles.mjs --region=dc --contact=<email> [--raw-dir=<dir>] [--out=<dir>]

import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from '../src/args.mjs';
import { userAgent } from '../src/download.mjs';
import { fetchOverpassTiles, tileGrid } from '../src/overpass.mjs';
import { loadRegion } from '../src/region.mjs';

const PACKAGE_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const REPO_DIR = path.resolve(PACKAGE_DIR, '..', '..');
const TILE_DEGREES = 0.45;

function die(message) {
  process.stderr.write(`${message}\n`);
  process.exit(1);
}

async function main() {
  let args;
  try {
    args = parseArgs(process.argv.slice(2), { values: ['region', 'region-file', 'contact', 'raw-dir', 'out'], flags: ['help'] });
  } catch (err) {
    die(err.message);
  }
  const contact = args.contact || process.env.TP_CONTACT_EMAIL;
  if (args.help || (!args.region && !args['region-file'])) {
    die('Usage: node tools/truck-etl/bin/fetch-overpass-tiles.mjs --region=<id> --contact=<email> [--raw-dir=<dir>] [--out=<dir>]');
  }
  if (!contact) die('a contact address is required: pass --contact=<email> or set TP_CONTACT_EMAIL');
  const regionFile = args['region-file'] ? path.resolve(args['region-file']) : path.join(PACKAGE_DIR, 'regions', `${args.region}.json`);
  const region = loadRegion(regionFile).doc;
  const box = region.fetch_box;
  const rows = Math.max(1, Math.ceil((box.north - box.south) / TILE_DEGREES));
  const cols = Math.max(1, Math.ceil((box.east - box.west) / TILE_DEGREES));
  const today = new Date().toISOString().slice(0, 10).replace(/-/g, '');
  const rawDir = path.resolve(args['raw-dir'] || process.env.TP_RAW_DIR || path.join(REPO_DIR, 'storage', 'truck', 'raw'));
  const outDir = args.out ? path.resolve(args.out) : path.join(rawDir, 'overpass', region.id, today);

  const result = await fetchOverpassTiles({
    tiles: tileGrid(box, rows, cols),
    outDir,
    userAgent: userAgent(contact),
    log: (message) => process.stderr.write(`${message}\n`),
  });
  process.stdout.write(`${result.written.length} tiles written to ${outDir} (${result.requests} requests, ${result.waits} waits, ${result.splits} splits)\n`
    + `Build with: --places-source=overpass-tiles --tiles-dir=${outDir}\n`);
}

main().catch((err) => die(err.stack || String(err)));
