#!/usr/bin/env node
// Rebuilds tests/fixtures/truck-planner/region-mini/build with the geodata pipeline itself.
//
//     cd tools/truck-etl && npm ci && cd ../..
//     node tests/fixtures/truck-planner/region-mini/make-fixture.mjs
//
// The mini region is Falls Church city, Virginia: the pipeline's own committed test input
// (tools/truck-etl/test/fixtures/mini: 164 real census blocks, their jobs, the county polygon and the real
// OpenStreetMap elements of the area), built offline with the repository seed file. The result is a valid
// build with real H3 ids: about 360 source points, 420 places and 200 map cells.
//
// Nothing is downloaded. The pipeline writes an index into its raw directory, so it works on a temporary
// copy of the input. Run this again, and commit the five files, whenever the pipeline's rules or a
// build-scope seed change: the loader refuses a build whose recorded seed values are not the model's.

import { spawnSync } from 'node:child_process';
import { copyFileSync, cpSync, mkdirSync, mkdtempSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const FILES = ['manifest.json', 'points.tsv', 'places.ndjson', 'cells.tsv', 'job_review.csv'];

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..', '..', '..');
const etl = join(root, 'tools', 'truck-etl');
const input = join(etl, 'test', 'fixtures', 'mini');
const work = mkdtempSync(join(tmpdir(), 'tp-region-mini-'));

let status = 1;
try {
  cpSync(join(input, 'raw'), join(work, 'raw'), { recursive: true });
  const run = spawnSync(
    process.execPath,
    [
      join(etl, 'bin', 'build-region.mjs'),
      '--offline',
      `--region-file=${join(input, 'mini.json')}`,
      `--corrections=${join(input, 'mini.jobs.json')}`,
      `--raw-dir=${join(work, 'raw')}`,
      `--out=${join(work, 'out')}`,
    ],
    { stdio: ['ignore', 'ignore', 'inherit'] },
  );
  if (run.status !== 0) {
    throw new Error(`the pipeline ended with exit code ${run.status}: run it by hand to see its gate table`);
  }
  const versions = readdirSync(join(work, 'out')).filter((name) => !name.startsWith('_'));
  if (versions.length !== 1) {
    throw new Error(`expected one dataset version in the output, found ${versions.length}`);
  }
  const target = join(here, 'build');
  mkdirSync(target, { recursive: true });
  for (const file of FILES) {
    copyFileSync(join(work, 'out', versions[0], file), join(target, file));
  }
  console.log(`region-mini/build is now ${versions[0]}`);
  status = 0;
} catch (error) {
  console.error(`make-fixture: ${error.message}`);
} finally {
  rmSync(work, { recursive: true, force: true });
}
process.exit(status);
