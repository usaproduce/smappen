// Viewport maths and the camera of the blank base (docs/truck-planner/05_FRONTEND.md 5.1, 5.6, 5.7, 8.2).

import { cellToBoundary, latLngToCell } from 'h3-js';
import { describe, expect, it } from 'vitest';
import { latOf, lngOf, worldX, worldY } from '../map/mercator';
import { buildMesh } from '../map/mesh';
import {
  MAX_ZOOM,
  MIN_ZOOM,
  alphaForScale,
  blankViewport,
  cameraAt,
  cameraLatLng,
  clampCamera,
  clampDpr,
  clipTransform,
  cullCells,
  gridLines,
  isZoomedOut,
  panByPixels,
  projectLatLng,
  scaleOf,
  unprojectPoint,
  worldRect,
  zoomAbout,
  zoomOf,
} from '../map/viewport';
import { DC_CENTER, diskIds } from './_packFixture';

const DC_BOUNDS = { lat_min: 38.00484, lng_min: -78.34937, lat_max: 39.72058, lng_max: -76.66133 };

describe('zoom and scale', () => {
  it('zoomOf(scale) = log2(scale)', () => {
    expect(zoomOf(1)).toBe(0);
    expect(zoomOf(4096)).toBe(12);
    expect(zoomOf(2097152 / 256)).toBe(13); // the world is 2,097,152 px wide at zoom 13
    expect(scaleOf(12)).toBe(4096);
    for (const z of [8, 9.5, 11.25, 13, 19]) expect(zoomOf(scaleOf(z))).toBeCloseTo(z, 12);
  });

  it('fades the layer in between zoom 9 and zoom 10', () => {
    expect(alphaForScale(scaleOf(8))).toBe(0);
    expect(alphaForScale(scaleOf(8.9))).toBe(0);
    expect(alphaForScale(scaleOf(9))).toBe(0);
    expect(alphaForScale(scaleOf(9.5))).toBeCloseTo(0.5, 9);
    expect(alphaForScale(scaleOf(9.25))).toBeCloseTo(0.25, 9);
    expect(alphaForScale(scaleOf(10))).toBe(1);
    expect(alphaForScale(scaleOf(14))).toBe(1);
    expect(alphaForScale(Number.NaN)).toBe(0);
    expect(alphaForScale(0)).toBe(0);
  });

  it('reports zoomed out under zoom 9.5', () => {
    expect(isZoomedOut(scaleOf(8))).toBe(true);
    expect(isZoomedOut(scaleOf(9))).toBe(true);
    expect(isZoomedOut(scaleOf(9.49))).toBe(true);
    expect(isZoomedOut(scaleOf(9.5))).toBe(false);
    expect(isZoomedOut(scaleOf(10))).toBe(false);
    expect(isZoomedOut(scaleOf(16))).toBe(false);
  });

  it('caps the device pixel ratio at 2', () => {
    expect(clampDpr(1)).toBe(1);
    expect(clampDpr(1.5)).toBe(1.5);
    expect(clampDpr(2)).toBe(2);
    expect(clampDpr(3)).toBe(2);
    expect(clampDpr(undefined)).toBe(1);
    expect(clampDpr(0)).toBe(1);
  });
});

describe('the world rectangle in view', () => {
  it('is the canvas corners in mesh coordinates', () => {
    const vp = { width: 1600, height: 900, dpr: 1, scale: 4096, originX: 800, originY: 450 };
    const rect = worldRect(vp);
    expect(rect.minX).toBe(-800 / 4096);
    expect(rect.maxX).toBe(800 / 4096);
    expect(rect.minY).toBe(-450 / 4096);
    expect(rect.maxY).toBe(450 / 4096);
    // The origin off to the left and above: everything in view is right of and below it.
    const off = worldRect({ ...vp, originX: -100, originY: -50 });
    expect(off.minX).toBe(100 / 4096);
    expect(off.minY).toBe(50 / 4096);
    expect(off.maxX).toBe(1700 / 4096);
  });

  it('culls cells by their bounding boxes', async () => {
    const ids = diskIds(1000);
    const mesh = await buildMesh(ids, (id) => cellToBoundary(id), DC_BOUNDS);
    const everything = { minX: -10, minY: -10, maxX: 10, maxY: 10 };
    const out = new Uint32Array(mesh.n);
    expect(cullCells(mesh.centers, mesh.halfSizes, mesh.n, everything, out)).toBe(1000);
    expect(Array.from(out.subarray(0, 5))).toEqual([0, 1, 2, 3, 4]);
    expect(cullCells(mesh.centers, mesh.halfSizes, mesh.n, { minX: 5, minY: 5, maxX: 6, maxY: 6 }, null)).toBe(0);

    // A 600 x 400 px view at zoom 14 over the middle of the disk.
    const camera = cameraAt(DC_CENTER.lat, DC_CENTER.lng, 14);
    const vp = blankViewport(camera, 600, 400, 1, mesh.originX, mesh.originY);
    const rect = worldRect(vp);
    const count = cullCells(mesh.centers, mesh.halfSizes, mesh.n, rect, out);
    expect(count).toBeGreaterThan(50);
    expect(count).toBeLessThan(400);
    const seen = new Set(Array.from(out.subarray(0, count)));
    for (let c = 0; c < mesh.n; c++) {
      const x = mesh.centers[2 * c];
      const y = mesh.centers[2 * c + 1];
      const hw = mesh.halfSizes[2 * c];
      const hh = mesh.halfSizes[2 * c + 1];
      const touches = x + hw >= rect.minX && x - hw <= rect.maxX && y + hh >= rect.minY && y - hh <= rect.maxY;
      expect(seen.has(c)).toBe(touches);
    }
    // The cell under the middle of the view is one of them.
    const middle = ids.indexOf(latLngToCell(DC_CENTER.lat, DC_CENTER.lng, 9));
    expect(seen.has(middle)).toBe(true);
    // Counting without a buffer gives the same number.
    expect(cullCells(mesh.centers, mesh.halfSizes, mesh.n, rect, null)).toBe(count);
  });

  it('turns a viewport into the two uniforms of the vertex shader', () => {
    const vp = { width: 1000, height: 500, dpr: 2, scale: 8192, originX: 250, originY: 400 };
    const t = clipTransform(vp, new Float32Array(4));
    // The mesh origin lands on its canvas position: x = 250 of 1000 is clip -0.5, y = 400 of 500 is clip -0.6.
    expect(t[2]).toBeCloseTo(-0.5, 6);
    expect(t[3]).toBeCloseTo(-0.6, 6);
    // A point one hundredth of a world unit east and south of the origin: 81.92 px right and down.
    const x = 0.01 * t[0] + t[2];
    const y = 0.01 * t[1] + t[3];
    expect(((x + 1) / 2) * 1000).toBeCloseTo(250 + 81.92, 2);
    expect(((1 - y) / 2) * 500).toBeCloseTo(400 + 81.92, 2);
  });
});

describe('the camera of the blank base', () => {
  const W = 1024;
  const H = 768;

  it('projects its own centre to the middle of the view', () => {
    const camera = cameraAt(DC_CENTER.lat, DC_CENTER.lng, 12);
    const p = projectLatLng(camera, W, H, DC_CENTER.lat, DC_CENTER.lng);
    expect(p.x).toBeCloseTo(W / 2, 9);
    expect(p.y).toBeCloseTo(H / 2, 9);
    const back = cameraLatLng(camera);
    expect(back.lat).toBeCloseTo(DC_CENTER.lat, 9);
    expect(back.lng).toBeCloseTo(DC_CENTER.lng, 9);
  });

  it('projects a pin by the scale of the zoom: east is right, north is up', () => {
    const camera = cameraAt(38.9, -77.0, 13);
    const east = projectLatLng(camera, W, H, 38.9, -76.99);
    const north = projectLatLng(camera, W, H, 38.91, -77.0);
    expect(east.y).toBeCloseTo(H / 2, 9);
    expect(east.x - W / 2).toBeCloseTo((worldX(-76.99) - worldX(-77.0)) * 8192, 9);
    expect(east.x).toBeGreaterThan(W / 2);
    expect(north.x).toBeCloseTo(W / 2, 9);
    expect(north.y).toBeLessThan(H / 2);
    expect(H / 2 - north.y).toBeCloseTo((worldY(38.9) - worldY(38.91)) * 8192, 9);
    // One zoom level in doubles every offset from the centre.
    const closer = projectLatLng({ ...camera, zoom: 14 }, W, H, 38.9, -76.99);
    expect(closer.x - W / 2).toBeCloseTo(2 * (east.x - W / 2), 9);
  });

  it('agrees with the viewport it hands to the renderers', async () => {
    const id = latLngToCell(38.9696, -77.3861, 9);
    const mesh = await buildMesh([id], (cell) => cellToBoundary(cell), DC_BOUNDS);
    const camera = cameraAt(38.97, -77.38, 15.5);
    const vp = blankViewport(camera, W, H, 2, mesh.originX, mesh.originY);
    expect(vp.width).toBe(W);
    expect(vp.height).toBe(H);
    expect(vp.dpr).toBe(2);
    expect(vp.scale).toBeCloseTo(scaleOf(15.5), 9);
    // The mesh origin through the pin projection is the viewport's origin.
    const o = projectLatLng(camera, W, H, mesh.originLat, mesh.originLng);
    expect(vp.originX).toBeCloseTo(o.x, 6);
    expect(vp.originY).toBeCloseTo(o.y, 6);
    // A vertex drawn from the mesh lands where a pin at the same place is put: under a hundredth of a pixel apart.
    const outline = cellToBoundary(id);
    for (let v = 0; v < 6; v++) {
      const drawnX = vp.originX + mesh.positions[2 * v] * vp.scale;
      const drawnY = vp.originY + mesh.positions[2 * v + 1] * vp.scale;
      const pin = projectLatLng(camera, W, H, outline[v][0], outline[v][1]);
      expect(Math.abs(drawnX - pin.x)).toBeLessThan(0.01);
      expect(Math.abs(drawnY - pin.y)).toBeLessThan(0.01);
    }
  });

  it('unprojects what it projects', () => {
    const camera = cameraAt(38.95, -77.2, 11.3);
    for (const [px, py] of [[0, 0], [W, H], [W / 2, H / 2], [137, 601]]) {
      const place = unprojectPoint(camera, W, H, px, py);
      const p = projectLatLng(camera, W, H, place.lat, place.lng);
      expect(p.x).toBeCloseTo(px, 6);
      expect(p.y).toBeCloseTo(py, 6);
    }
    const centre = unprojectPoint(camera, W, H, W / 2, H / 2);
    expect(centre.lat).toBeCloseTo(38.95, 9);
    expect(centre.lng).toBeCloseTo(-77.2, 9);
  });

  it('pans with the pointer', () => {
    const camera = cameraAt(38.9, -77.0, 12);
    const moved = panByPixels(camera, 100, -40); // content moves 100 px right and 40 px up
    const p = projectLatLng(moved, W, H, 38.9, -77.0);
    expect(p.x).toBeCloseTo(W / 2 + 100, 9);
    expect(p.y).toBeCloseTo(H / 2 - 40, 9);
    expect(moved.zoom).toBe(12);
  });

  it('zooms about the pointer: the place under it stays under it', () => {
    const camera = cameraAt(38.9, -77.0, 12);
    const px = 200;
    const py = 650;
    const under = unprojectPoint(camera, W, H, px, py);
    for (const zoom of [12.4, 13, 15, 10.5]) {
      const next = zoomAbout(camera, W, H, px, py, zoom);
      expect(next.zoom).toBe(zoom);
      const p = projectLatLng(next, W, H, under.lat, under.lng);
      expect(p.x).toBeCloseTo(px, 6);
      expect(p.y).toBeCloseTo(py, 6);
    }
    // About the middle of the view the centre does not move.
    const centred = zoomAbout(camera, W, H, W / 2, H / 2, 16);
    expect(centred.x).toBeCloseTo(camera.x, 12);
    expect(centred.y).toBeCloseTo(camera.y, 12);
  });

  it('keeps the zoom between 8 and 19 and the centre inside the world', () => {
    expect(MIN_ZOOM).toBe(8);
    expect(MAX_ZOOM).toBe(19);
    const camera = cameraAt(38.9, -77.0, 12);
    expect(zoomAbout(camera, W, H, 10, 10, 3).zoom).toBe(8);
    expect(zoomAbout(camera, W, H, 10, 10, 25).zoom).toBe(19);
    expect(zoomAbout(camera, W, H, 10, 10, Number.NaN).zoom).toBe(12);
    expect(cameraAt(38.9, -77.0, 30).zoom).toBe(19);
    expect(cameraAt(38.9, -77.0, -1).zoom).toBe(8);
    const far = clampCamera({ x: 9999, y: -9999, zoom: Number.NaN });
    expect(far.x).toBe(256);
    expect(far.zoom).toBe(8);
    expect(latOf(far.y)).toBeCloseTo(85.05112878, 6);
    const nowhere = clampCamera({ x: Number.NaN, y: Number.NaN, zoom: 12 });
    expect(lngOf(nowhere.x)).toBe(0);
    expect(latOf(nowhere.y)).toBe(0);
    // Panning far north stops at the edge of the world.
    const north = panByPixels(cameraAt(84, 0, 8), 0, 1e7);
    expect(latOf(north.y)).toBeCloseTo(85.05112878, 6);
  });

  it('lays grid lines 64 px apart at whole zoom levels, fixed to the world', () => {
    const camera = cameraAt(38.9, -77.0, 12);
    const g = gridLines(camera.x, 12, W);
    expect(g.step).toBe(64);
    expect(g.first).toBeGreaterThanOrEqual(0);
    expect(g.first).toBeLessThan(64);
    // A line is a fixed place: after a 30 px drag every line has moved 30 px.
    const dragged = panByPixels(camera, 30, 0);
    const h = gridLines(dragged.x, 12, W);
    const offBy = (a: number, step: number) => {
      const r = ((a % step) + step) % step;
      return Math.min(r, step - r);
    };
    expect(offBy(h.first - g.first - 30, 64)).toBeLessThan(1e-6);
    // Between whole levels the same lines spread out, up to 128 px, then every second gap is split again.
    expect(gridLines(camera.x, 12.5, W).step).toBeCloseTo(64 * Math.SQRT2, 9);
    expect(gridLines(camera.x, 13, W).step).toBe(64);
    // A line stays on its place while zooming about it.
    const lineWorld = camera.x + (g.first - W / 2) / scaleOf(12);
    const zoomed = gridLines(camera.x, 12.5, W);
    const linePx = W / 2 + (lineWorld - camera.x) * scaleOf(12.5);
    expect(offBy(linePx - zoomed.first, zoomed.step)).toBeLessThan(1e-6);
  });
});
