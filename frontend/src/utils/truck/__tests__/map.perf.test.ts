// Loose, machine-independent bounds on the map engine's three costs (docs/truck-planner/05_FRONTEND.md 5.9, 8.2).
//
// A synthetic region of the size of the dc region: 60,000 real H3 cells, 50 features per cell. The
// budgets the layer is held to in a browser are much tighter (5.9); these bounds only catch a change
// that makes a step several times slower, on any machine that runs the tests.

import { cellToBoundary } from 'h3-js';
import { describe, expect, it } from 'vitest';
import { createFrameSource } from '../map/frames';
import { buildMesh } from '../map/mesh';
import { decodePack } from '../map/pack';
import type { CellPack } from '../map/pack';
import { buildCellIndex } from '../map/pick';
import { syntheticPack } from './_packFixture';
import { assume, goldenCase } from './_kitFixtures';

/* eslint-disable @typescript-eslint/no-explicit-any */

const N = 60000;
const WEEK = goldenCase('g23-001').args as any;
const A = assume(WEEK.A);
const PROFILE = WEEK.profile;

function median(values: number[]): number {
  const sorted = [...values].sort((a, b) => a - b);
  return sorted[Math.floor(sorted.length / 2)];
}

describe('a region of 60,000 cells with 50 features per cell', () => {
  const fixture = syntheticPack(N);
  let pack: CellPack;

  it('decodes in under 500 ms', () => {
    expect(fixture.buffer.byteLength).toBe(fixture.dataOffset + 108 * N);
    const times: number[] = [];
    for (let run = 0; run < 5; run++) {
      const t0 = performance.now();
      pack = decodePack(fixture.buffer);
      times.push(performance.now() - t0);
    }
    expect(pack.n).toBe(N);
    expect(pack.k).toBe(50);
    expect(pack.features.length).toBe(N * 50);
    console.log('map.perf: decode ' + median(times).toFixed(1) + ' ms (median of 5, ' + (fixture.buffer.byteLength / 1e6).toFixed(2) + ' MB)');
    expect(median(times)).toBeLessThan(500);
  });

  it('scores one hour in under 10 ms (median of 20 runs)', () => {
    const source = createFrameSource(pack, { A, profile: PROFILE, cal: null });
    const out = new Uint8Array(N);
    for (const layer of ['opportunity', 'people', 'competition'] as const) {
      // Warm up, then time 20 hours that have not been scored yet (a kept frame would only be a copy).
      for (let how = 0; how < 8; how++) source.fill(layer, how, null, out);
      const times: number[] = [];
      for (let run = 0; run < 20; run++) {
        const how = 8 + ((run * 37) % 160);
        if (layer !== 'competition') expect(source.has(layer, how, null)).toBe(false);
        const t0 = performance.now();
        source.fill(layer, how, null, out);
        times.push(performance.now() - t0);
      }
      console.log('map.perf: ' + layer + ' frame ' + median(times).toFixed(2) + ' ms (median of 20)');
      expect(median(times)).toBeLessThan(10);
    }
    let coloured = 0;
    source.fill('opportunity', 84, null, out);
    for (let c = 0; c < N; c++) if (out[c] > 0) coloured++;
    expect(coloured).toBeGreaterThan(N / 2);
  });

  it('builds its mesh in under 1,500 ms', async () => {
    let yields = 0;
    const t0 = performance.now();
    const mesh = await buildMesh(pack.ids, (id) => cellToBoundary(id), pack.header.bounds, () => {
      yields++;
    });
    const took = performance.now() - t0;
    console.log('map.perf: mesh ' + took.toFixed(1) + ' ms, ' + yields + ' yields');
    expect(mesh.n).toBe(N);
    expect(mesh.positions.length).toBe(N * 12);
    expect(mesh.indices.length).toBe(N * 12);
    expect(yields).toBe(58);
    expect(took).toBeLessThan(1500);
  });

  it('indexes its ids in well under a second', () => {
    const t0 = performance.now();
    const index = buildCellIndex(pack.ids);
    const took = performance.now() - t0;
    expect(index.size).toBe(N);
    expect(took).toBeLessThan(1000);
  });
});
