// The hexagon mesh, with real h3-js (docs/truck-planner/05_FRONTEND.md 5.3, 8.2).

import { cellToBoundary, cellToLatLng, latLngToCell } from 'h3-js';
import { describe, expect, it } from 'vitest';
import { worldX, worldY } from '../map/mercator';
import { INDICES_PER_CELL, MESH_YIELD_EVERY, POSITIONS_PER_CELL, buildMesh } from '../map/mesh';
import { diskIds } from './_packFixture';
import { spec } from './_kitFixtures';

// The box of the dc pack's cell centres (03_DATA section 11).
const DC_BOUNDS = { lat_min: 38.00484, lng_min: -78.34937, lat_max: 39.72058, lng_max: -76.66133 };
const CELL = '892aaab3043ffff';
const boundaryOf = (id: string) => cellToBoundary(id);

describe('h3-js', () => {
  it('puts the worked point in its resolution-9 cell', () => {
    expect(spec(latLngToCell(38.9696, -77.3861, 9))).toBe(CELL);
  });

  it('returns outlines as [lat, lng] pairs', () => {
    const outline = cellToBoundary(CELL);
    expect(outline.length).toBe(6);
    for (const [lat, lng] of outline) {
      expect(lat).toBeGreaterThan(38.9);
      expect(lat).toBeLessThan(39.0);
      expect(lng).toBeGreaterThan(-77.5);
      expect(lng).toBeLessThan(-77.3);
    }
  });
});

describe('buildMesh', () => {
  it('puts the origin at the centre of the bounds', async () => {
    const mesh = await buildMesh([CELL], boundaryOf, DC_BOUNDS);
    expect(mesh.n).toBe(1);
    expect(mesh.originLat).toBe((38.00484 + 39.72058) / 2);
    expect(mesh.originLng).toBe((-78.34937 + -76.66133) / 2);
    expect(mesh.originX).toBe(worldX(mesh.originLng));
    expect(mesh.originY).toBe(worldY(mesh.originLat));
    expect(mesh.positions.length).toBe(POSITIONS_PER_CELL);
    expect(mesh.indices.length).toBe(INDICES_PER_CELL);
    expect(mesh.centers.length).toBe(2);
    expect(mesh.halfSizes.length).toBe(2);
  });

  it('gives the worked cell six distinct vertices around its centre', async () => {
    const mesh = await buildMesh([CELL], boundaryOf, DC_BOUNDS);
    const [lat, lng] = cellToLatLng(CELL);
    const cx = worldX(lng) - mesh.originX;
    const cy = worldY(lat) - mesh.originY;

    const points: [number, number][] = [];
    for (let v = 0; v < 6; v++) points.push([mesh.positions[2 * v], mesh.positions[2 * v + 1]]);
    for (let a = 0; a < 6; a++) {
      for (let b = a + 1; b < 6; b++) {
        expect(Math.hypot(points[a][0] - points[b][0], points[a][1] - points[b][1])).toBeGreaterThan(1e-5);
      }
    }
    // All six at about the same distance from the centre, and going round it one way.
    const radii = points.map(([x, y]) => Math.hypot(x - cx, y - cy));
    const mean = radii.reduce((s, r) => s + r, 0) / 6;
    for (const r of radii) expect(Math.abs(r - mean) / mean).toBeLessThan(0.1);
    let turn = 0;
    for (let v = 0; v < 6; v++) {
      const [ax, ay] = points[v];
      const [bx, by] = points[(v + 1) % 6];
      const cross = (ax - cx) * (by - cy) - (ay - cy) * (bx - cx);
      turn += Math.sign(cross);
    }
    expect(Math.abs(turn)).toBe(6);
    // A resolution-9 cell is about 0.2 km on a side: about 13 px at zoom 13 at this latitude.
    expect(mean * Math.pow(2, 13)).toBeGreaterThan(9);
    expect(mean * Math.pow(2, 13)).toBeLessThan(17);

    // The bounding box and its centre.
    const xs = points.map((p) => p[0]);
    const ys = points.map((p) => p[1]);
    // (32-bit floats: seven decimals at this size)
    expect(mesh.centers[0]).toBeCloseTo((Math.min(...xs) + Math.max(...xs)) / 2, 7);
    expect(mesh.centers[1]).toBeCloseTo((Math.min(...ys) + Math.max(...ys)) / 2, 7);
    expect(mesh.halfSizes[0]).toBeCloseTo((Math.max(...xs) - Math.min(...xs)) / 2, 7);
    expect(mesh.halfSizes[1]).toBeCloseTo((Math.max(...ys) - Math.min(...ys)) / 2, 7);
    expect(Math.hypot(mesh.centers[0] - cx, mesh.centers[1] - cy)).toBeLessThan(mean * 0.1);
  });

  it('stores positions relative to the origin, exact to under 0.02 px at zoom 19 and 0.04 px at zoom 20', async () => {
    // Cells at the corners and the centre of the region, where the offsets from the origin are largest.
    const places: [number, number][] = [
      [38.00484, -78.34937],
      [38.00484, -76.66133],
      [39.72058, -78.34937],
      [39.72058, -76.66133],
      [38.9072, -77.0369],
      [38.9696, -77.3861],
    ];
    const ids = places.map(([lat, lng]) => latLngToCell(lat, lng, 9));
    const mesh = await buildMesh(ids, boundaryOf, DC_BOUNDS);
    const zoom19 = Math.pow(2, 19);
    const zoom20 = Math.pow(2, 20);
    let worst = 0;
    let worstAbsolute = 0;
    let largest = 0;
    for (let c = 0; c < ids.length; c++) {
      const outline = cellToBoundary(ids[c]);
      for (let v = 0; v < 6; v++) {
        const ax = worldX(outline[v][1]);
        const ay = worldY(outline[v][0]);
        const x = ax - mesh.originX;
        const y = ay - mesh.originY;
        worst = Math.max(worst, Math.abs(mesh.positions[12 * c + 2 * v] - x), Math.abs(mesh.positions[12 * c + 2 * v + 1] - y));
        worstAbsolute = Math.max(worstAbsolute, Math.abs(Math.fround(ax) - ax), Math.abs(Math.fround(ay) - ay));
        largest = Math.max(largest, Math.abs(x), Math.abs(y));
      }
    }
    // Relative coordinates stay under one world unit (the dc region is 1.2 by 1.6 units across) ...
    expect(largest).toBeLessThan(1);
    expect(largest).toBeGreaterThan(0.5);
    // ... so their 32-bit rounding is at most 2^-25 units: far below a pixel at the largest zoom of the
    // map (19) and one zoom level beyond it.
    expect(worst).toBeLessThanOrEqual(Math.pow(2, -25));
    expect(worst * zoom19).toBeLessThan(0.02);
    expect(worst * zoom20).toBeLessThan(0.04);
    // Absolute world coordinates would not do: the same vertices as 32-bit floats are pixels off.
    expect(worstAbsolute * zoom20).toBeGreaterThan(1);
  });

  it('fans every cell over its own six vertices', async () => {
    const ids = diskIds(40);
    const mesh = await buildMesh(ids, boundaryOf, DC_BOUNDS);
    expect(mesh.indices).toBeInstanceOf(Uint32Array);
    expect(mesh.positions).toBeInstanceOf(Float32Array);
    for (let c = 0; c < ids.length; c++) {
      const want = [0, 1, 2, 0, 2, 3, 0, 3, 4, 0, 4, 5].map((i) => i + 6 * c);
      expect(Array.from(mesh.indices.subarray(12 * c, 12 * c + 12))).toEqual(want);
    }
    // Every cell has its own outline, vertex for vertex what h3 gives: neighbours are not copies of one shape.
    const shapes = new Set<string>();
    for (let c = 0; c < ids.length; c++) {
      const outline = cellToBoundary(ids[c]);
      expect(outline.length).toBe(6);
      for (let v = 0; v < 6; v++) {
        expect(mesh.positions[12 * c + 2 * v]).toBe(Math.fround(worldX(outline[v][1]) - mesh.originX));
        expect(mesh.positions[12 * c + 2 * v + 1]).toBe(Math.fround(worldY(outline[v][0]) - mesh.originY));
      }
      const x0 = worldX(outline[0][1]);
      const y0 = worldY(outline[0][0]);
      shapes.add(outline.map(([lat, lng]) => (worldX(lng) - x0).toExponential(9) + ':' + (worldY(lat) - y0).toExponential(9)).join(' '));
    }
    expect(shapes.size).toBe(ids.length);
  });

  it('repeats the last vertex of a five-vertex outline', async () => {
    const pentagon = [
      [38.9, -77.0],
      [38.91, -77.0],
      [38.915, -77.01],
      [38.905, -77.02],
      [38.895, -77.01],
    ];
    const mesh = await buildMesh(['pentagon'], () => pentagon, DC_BOUNDS);
    for (let v = 0; v < 5; v++) {
      expect(mesh.positions[2 * v]).toBe(Math.fround(worldX(pentagon[v][1]) - mesh.originX));
      expect(mesh.positions[2 * v + 1]).toBe(Math.fround(worldY(pentagon[v][0]) - mesh.originY));
    }
    expect(mesh.positions[10]).toBe(mesh.positions[8]);
    expect(mesh.positions[11]).toBe(mesh.positions[9]);
    expect(Array.from(mesh.indices)).toEqual([0, 1, 2, 0, 2, 3, 0, 3, 4, 0, 4, 5]);
  });

  it('drops the points that lie on a side when an outline has more than six', async () => {
    const real = cellToBoundary(CELL);
    // The midpoint of the first side and a point a third along the fourth: they bend nothing.
    const mid = [(real[0][0] + real[1][0]) / 2, (real[0][1] + real[1][1]) / 2];
    const third = [real[3][0] + (real[4][0] - real[3][0]) / 3, real[3][1] + (real[4][1] - real[3][1]) / 3];
    const eight = [real[0], mid, real[1], real[2], real[3], third, real[4], real[5]];
    const want = await buildMesh([CELL], boundaryOf, DC_BOUNDS);
    const got = await buildMesh([CELL], () => eight, DC_BOUNDS);
    expect(Array.from(got.positions)).toEqual(Array.from(want.positions));
  });

  it('draws nothing for an outline without points', async () => {
    const mesh = await buildMesh(['empty'], () => [], DC_BOUNDS);
    expect(Array.from(mesh.positions)).toEqual(new Array(12).fill(0));
    expect(Array.from(mesh.halfSizes)).toEqual([0, 0]);
  });

  it('asks its caller every 1,024 cells whether to hand control back', async () => {
    expect(MESH_YIELD_EVERY).toBe(1024);
    const square = [
      [38.9, -77.0],
      [38.9, -77.01],
      [38.91, -77.01],
      [38.91, -77.0],
      [38.905, -76.995],
      [38.9, -76.999],
    ];
    const ids = new Array<string>(20000).fill('x');
    const at: number[] = [];
    let built = 0;
    const mesh = await buildMesh(
      ids,
      () => {
        built++;
        return square;
      },
      DC_BOUNDS,
      async () => {
        at.push(built);
        await Promise.resolve();
      },
    );
    expect(mesh.n).toBe(20000);
    expect(at.length).toBe(19);
    expect(at.slice(0, 3)).toEqual([1024, 2048, 3072]);
    expect(at[18]).toBe(19456);
    // Without the callback the build still completes.
    expect((await buildMesh(ids, () => square, DC_BOUNDS)).n).toBe(20000);
    // A callback that returns nothing lets the build go straight on: the same mesh either way.
    let asked = 0;
    const straight = await buildMesh(ids.slice(0, 5000), () => square, DC_BOUNDS, () => {
      asked++;
    });
    expect(asked).toBe(4);
    expect(Array.from(straight.positions.subarray(0, 12))).toEqual(Array.from(mesh.positions.subarray(0, 12)));
  });

  it('builds an empty mesh', async () => {
    const mesh = await buildMesh([], boundaryOf, DC_BOUNDS);
    expect(mesh.n).toBe(0);
    expect(mesh.positions.length).toBe(0);
    expect(mesh.indices.length).toBe(0);
  });
});
