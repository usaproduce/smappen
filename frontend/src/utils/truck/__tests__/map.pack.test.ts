// The cell pack decoder (docs/truck-planner/03_DATA.md section 11, docs/truck-planner/05_FRONTEND.md 5.2, 8.2).
//
// The packs here are written by _packFixture.ts, which mirrors the format the loader writes.

import { describe, expect, it } from 'vitest';
import { FEATURE_COLUMNS, MODEL_VERSION } from '../model';
import { PACK_MAGIC, PackError, decodePack, packDataOffset } from '../map/pack';
import type { PackErrorCode } from '../map/pack';
import { PACK_K, diskIds, encodePack, quantBound, quantise, syntheticValues } from './_packFixture';

const N = 300;
const IDS = diskIds(N);
const VALUES = syntheticValues(N, 77);

function codeOf(run: () => unknown): PackErrorCode | 'no error' | 'other error' {
  try {
    run();
  } catch (e) {
    return e instanceof PackError ? e.code : 'other error';
  }
  return 'no error';
}

describe('decodePack', () => {
  const fixture = encodePack({ ids: IDS, values: VALUES });
  const pack = decodePack(fixture.buffer);

  it('reads the prefix, the header and the two sections', () => {
    expect(new DataView(fixture.buffer).getUint32(0, false)).toBe(PACK_MAGIC);
    expect(pack.n).toBe(N);
    expect(pack.k).toBe(50);
    expect(pack.header.cell_count).toBe(N);
    expect(pack.header.model_version).toBe(MODEL_VERSION);
    expect(pack.header.dataset_version).toBe('dc-20261003-3fa9c2d1');
    expect(pack.header.h3_res).toBe(9);
    expect(pack.header.columns).toEqual([...FEATURE_COLUMNS]);
    expect(pack.features).toBeInstanceOf(Float32Array);
    expect(pack.features.length).toBe(N * 50);
    expect(fixture.buffer.byteLength).toBe(fixture.dataOffset + 8 * N + 100 * N);
    expect(packDataOffset(fixture.headerBytes)).toBe(fixture.dataOffset);
    expect(fixture.dataOffset % 8).toBe(0);
  });

  it('gives back the same ids, as 15-character strings in file order', () => {
    expect(pack.ids).toEqual(IDS);
    for (const id of pack.ids) expect(id).toMatch(/^[0-9a-f]{15}$/);
    expect([...pack.ids].sort()).toEqual(pack.ids);
  });

  it('keeps an id whose low word starts with a zero', () => {
    // The id is hi.toString(16) + lo.toString(16) padded to 8: without the padding these would lose a digit.
    const ids = diskIds(3000).filter((id) => id[7] === '0');
    expect(ids.length).toBeGreaterThan(10);
    const some = decodePack(encodePack({ ids, values: syntheticValues(ids.length, 5) }).buffer);
    expect(some.ids).toEqual(ids);
    // The worked cell of the mesh test.
    const one = decodePack(encodePack({ ids: ['892aaab3043ffff'], values: syntheticValues(1, 5) }).buffer);
    expect(one.ids).toEqual(['892aaab3043ffff']);
  });

  it('decodes every value within the quantisation bound, row-major', () => {
    let worst = 0;
    let nonZero = 0;
    for (let i = 0; i < N; i++) {
      for (let j = 0; j < PACK_K; j++) {
        const v = VALUES[i * PACK_K + j];
        const got = pack.features[i * PACK_K + j];
        const bound = quantBound(v, fixture.scale[j]);
        // The decoder stores 32-bit floats: allow their rounding on top of the bound of the format.
        const slack = bound + Math.abs(v) * 1e-6;
        expect(Math.abs(got - v)).toBeLessThanOrEqual(slack);
        if (bound > 0) worst = Math.max(worst, Math.abs(got - v) / bound);
        if (v !== 0) nonZero++;
      }
    }
    expect(nonZero).toBeGreaterThan(N * 20);
    expect(worst).toBeGreaterThan(0.05); // the codes really are 16 bits wide
  });

  it('decodes zero exactly, and the largest value of a column to its scale', () => {
    let zeros = 0;
    for (let i = 0; i < N * PACK_K; i++) {
      if (VALUES[i] === 0) {
        expect(pack.features[i]).toBe(0);
        zeros++;
      }
    }
    expect(zeros).toBeGreaterThan(N * 5);
    for (let j = 0; j < PACK_K; j++) {
      let max = 0;
      for (let i = 0; i < N; i++) max = Math.max(max, pack.features[i * PACK_K + j]);
      expect(Math.abs(max - fixture.scale[j])).toBeLessThanOrEqual(fixture.scale[j] * 1e-6);
    }
  });

  it('value = scale * (code / 65535)^2', () => {
    const scale = fixture.scale[3];
    const v = VALUES[7 * PACK_K + 3];
    const code = quantise(v, scale);
    expect(pack.features[7 * PACK_K + 3]).toBe(Math.fround((scale / (65535 * 65535)) * code * code));
  });

  it('decodes an empty column (scale 0) to zeros', () => {
    const values = syntheticValues(4, 9);
    for (let i = 0; i < 4; i++) values[i * PACK_K + 11] = 0;
    const f = encodePack({ ids: diskIds(4), values });
    expect(f.scale[11]).toBe(0);
    const p = decodePack(f.buffer);
    for (let i = 0; i < 4; i++) expect(p.features[i * PACK_K + 11]).toBe(0);
  });

  it('decodes a pack with no cells', () => {
    const empty = decodePack(encodePack({ ids: [], values: [] }).buffer);
    expect(empty.n).toBe(0);
    expect(empty.ids).toEqual([]);
    expect(empty.features.length).toBe(0);
  });

  it('handles a header that needs no padding and one that needs seven bytes', () => {
    // P = (8 - (12 + H) mod 8) mod 8: none when H mod 8 is 4, seven when H mod 8 is 5.
    const none = encodePack({ ids: IDS, values: VALUES, headerBytesMod8: 4 });
    expect(none.padding).toBe(0);
    expect(none.dataOffset).toBe(12 + none.headerBytes);
    expect(decodePack(none.buffer).ids).toEqual(IDS);

    const seven = encodePack({ ids: IDS, values: VALUES, headerBytesMod8: 5 });
    expect(seven.padding).toBe(7);
    expect(seven.dataOffset).toBe(12 + seven.headerBytes + 7);
    const p = decodePack(seven.buffer);
    expect(p.ids).toEqual(IDS);
    expect(Array.from(p.features)).toEqual(Array.from(pack.features));

    for (let mod = 0; mod < 8; mod++) {
      const f = encodePack({ ids: IDS.slice(0, 3), values: syntheticValues(3, 1), headerBytesMod8: mod });
      expect(f.padding).toBe((8 - ((12 + f.headerBytes) % 8)) % 8);
      expect(decodePack(f.buffer).n).toBe(3);
    }
  });
});

describe('PackError', () => {
  const good = encodePack({ ids: IDS, values: VALUES });

  it('is an Error with a code', () => {
    const e = new PackError('bad_magic', 'x');
    expect(e).toBeInstanceOf(Error);
    expect(e.name).toBe('PackError');
    expect(e.code).toBe('bad_magic');
  });

  it('bad_magic: not a pack at all', () => {
    expect(codeOf(() => decodePack(encodePack({ ids: IDS, values: VALUES, magic: 'TPCQ' }).buffer))).toBe('bad_magic');
    expect(codeOf(() => decodePack(new ArrayBuffer(0)))).toBe('bad_magic');
    expect(codeOf(() => decodePack(new ArrayBuffer(3)))).toBe('bad_magic');
    // The JSON envelope of an error answer, read as bytes.
    const json = new TextEncoder().encode('{"success":false,"error":"Not found"}');
    expect(codeOf(() => decodePack(json.buffer.slice(json.byteOffset, json.byteOffset + json.byteLength)))).toBe('bad_magic');
    // Something that is not a buffer.
    expect(codeOf(() => decodePack(undefined as unknown as ArrayBuffer))).toBe('bad_magic');
    expect(codeOf(() => decodePack('TPCP' as unknown as ArrayBuffer))).toBe('bad_magic');
  });

  it('bad_version: a pack of another format version', () => {
    expect(codeOf(() => decodePack(encodePack({ ids: IDS, values: VALUES, formatVersion: 2 }).buffer))).toBe('bad_version');
    expect(codeOf(() => decodePack(good.buffer.slice(0, 5)))).toBe('bad_version');
  });

  it('bad_header: the header cannot be read or lacks what the map needs', () => {
    // Cut inside the header.
    expect(codeOf(() => decodePack(good.buffer.slice(0, 10)))).toBe('bad_header');
    expect(codeOf(() => decodePack(good.buffer.slice(0, 12 + 40)))).toBe('bad_header');
    // A header length that points past the end of the file.
    const long = good.buffer.slice(0);
    new DataView(long).setUint32(8, 0x7fffffff, true);
    expect(codeOf(() => decodePack(long))).toBe('bad_header');
    // Bytes that are not JSON.
    const broken = good.buffer.slice(0);
    new Uint8Array(broken)[12] = 0x5b; // "[" where "{" was
    expect(codeOf(() => decodePack(broken))).toBe('bad_header');
    // Bytes that are not UTF-8.
    const notText = good.buffer.slice(0);
    new Uint8Array(notText)[20] = 0xff;
    expect(codeOf(() => decodePack(notText))).toBe('bad_header');
    // Missing or unusable fields.
    for (const header of [
      { cell_count: 'many' },
      { cell_count: -1 },
      { cell_count: 2.5 },
      { columns: 'c_day_res' },
      { scale: [1, 2, 3] },
      { scale: FEATURE_COLUMNS.map(() => -1) },
      { h3_res: 16 },
      { bounds: null },
      { bounds: { lat_min: 38, lng_min: -78, lat_max: 'x', lng_max: -76 } },
      { model_version: 7 },
      { dataset_version: null },
    ]) {
      expect(codeOf(() => decodePack(encodePack({ ids: IDS, values: VALUES, header }).buffer)), JSON.stringify(header)).toBe('bad_header');
    }
    // A header that is JSON but not an object.
    const array = new TextEncoder().encode('[1,2,3]     ');
    const buf = new ArrayBuffer(12 + array.length);
    new Uint8Array(buf).set([0x54, 0x50, 0x43, 0x50, 1, 0, 0, 0]);
    new DataView(buf).setUint32(8, array.length, true);
    new Uint8Array(buf).set(array, 12);
    expect(codeOf(() => decodePack(buf))).toBe('bad_header');
  });

  it('bad_length: the file is not as long as its header says', () => {
    expect(codeOf(() => decodePack(good.buffer.slice(0, good.buffer.byteLength - 1)))).toBe('bad_length');
    expect(codeOf(() => decodePack(good.buffer.slice(0, good.dataOffset + 8 * N)))).toBe('bad_length');
    const longer = new Uint8Array(good.buffer.byteLength + 2);
    longer.set(new Uint8Array(good.buffer));
    expect(codeOf(() => decodePack(longer.buffer))).toBe('bad_length');
    // A cell count that does not match the sections (nothing is allocated for it).
    expect(codeOf(() => decodePack(encodePack({ ids: IDS, values: VALUES, header: { cell_count: N + 1 } }).buffer))).toBe('bad_length');
    expect(codeOf(() => decodePack(encodePack({ ids: IDS, values: VALUES, header: { cell_count: 4000000000 } }).buffer))).toBe('bad_length');
  });

  it('bad_columns: not the 50 columns of the fast path, in its order', () => {
    const twenty = FEATURE_COLUMNS.slice(0, 20);
    const few = encodePack({ ids: IDS.slice(0, 5), values: new Float64Array(5 * 20).fill(1), columns: twenty });
    expect(codeOf(() => decodePack(few.buffer))).toBe('bad_columns');

    const swapped = [...FEATURE_COLUMNS];
    swapped[48] = 'r_eve';
    swapped[49] = 'r_day';
    expect(codeOf(() => decodePack(encodePack({ ids: IDS, values: VALUES, columns: swapped }).buffer))).toBe('bad_columns');

    const renamed = [...FEATURE_COLUMNS];
    renamed[0] = 'c_day_residents';
    expect(codeOf(() => decodePack(encodePack({ ids: IDS, values: VALUES, columns: renamed }).buffer))).toBe('bad_columns');
  });

  it('a good pack throws nothing', () => {
    expect(codeOf(() => decodePack(good.buffer))).toBe('no error');
  });
});
