// Which cell is under a point, with real h3-js (docs/truck-planner/05_FRONTEND.md 5.7, 8.2).

import { cellToBoundary, cellToLatLng, gridDisk, latLngToCell } from 'h3-js';
import { describe, expect, it } from 'vitest';
import { buildCellIndex, cellAt } from '../map/pick';
import { DC_CENTER, diskIds } from './_packFixture';

const IDS = diskIds(400);
const INDEX = buildCellIndex(IDS);

describe('buildCellIndex', () => {
  it('maps every id to its position in the pack', () => {
    expect(INDEX.size).toBe(400);
    for (let i = 0; i < IDS.length; i += 37) expect(INDEX.get(IDS[i])).toBe(i);
    expect(buildCellIndex([]).size).toBe(0);
  });
});

describe('cellAt', () => {
  it('finds the cell of a point inside the pack', () => {
    for (let i = 0; i < IDS.length; i += 23) {
      const [lat, lng] = cellToLatLng(IDS[i]);
      expect(cellAt(INDEX, lat, lng, latLngToCell, 9)).toBe(i);
    }
    const centre = latLngToCell(DC_CENTER.lat, DC_CENTER.lng, 9);
    expect(IDS[cellAt(INDEX, DC_CENTER.lat, DC_CENTER.lng, latLngToCell, 9)]).toBe(centre);
  });

  it('gives -1 for a point outside the pack', () => {
    expect(cellAt(INDEX, 38.9696, -77.3861, latLngToCell, 9)).toBe(-1); // Reston: 30 km from the disk
    expect(cellAt(INDEX, 0, 0, latLngToCell, 9)).toBe(-1);
    expect(cellAt(buildCellIndex([]), DC_CENTER.lat, DC_CENTER.lng, latLngToCell, 9)).toBe(-1);
  });

  it('gives -1 for something that is not a place', () => {
    expect(cellAt(INDEX, Number.NaN, -77, latLngToCell, 9)).toBe(-1);
    expect(cellAt(INDEX, 38.9, Number.POSITIVE_INFINITY, latLngToCell, 9)).toBe(-1);
    expect(cellAt(INDEX, 91, -77, latLngToCell, 9)).toBe(-1);
    const throws = () => {
      throw new Error('no cell');
    };
    expect(cellAt(INDEX, 38.9, -77, throws, 9)).toBe(-1);
  });

  it('is exact near cell edges: a point just inside an edge belongs to that cell, just outside to its neighbour', () => {
    const id = latLngToCell(DC_CENTER.lat, DC_CENTER.lng, 9);
    const at = INDEX.get(id) as number;
    const [clat, clng] = cellToLatLng(id);
    const outline = cellToBoundary(id);
    const neighbours = new Set(gridDisk(id, 1));
    let crossed = 0;
    for (let v = 0; v < outline.length; v++) {
      const a = outline[v];
      const b = outline[(v + 1) % outline.length];
      // The middle of this edge, then 2 % of the way towards the centre and 2 % beyond the edge.
      const mlat = (a[0] + b[0]) / 2;
      const mlng = (a[1] + b[1]) / 2;
      const inside = [mlat + (clat - mlat) * 0.02, mlng + (clng - mlng) * 0.02];
      const outside = [mlat - (clat - mlat) * 0.02, mlng - (clng - mlng) * 0.02];
      expect(cellAt(INDEX, inside[0], inside[1], latLngToCell, 9)).toBe(at);
      const other = cellAt(INDEX, outside[0], outside[1], latLngToCell, 9);
      expect(other).not.toBe(at);
      expect(other).toBeGreaterThanOrEqual(0);
      expect(neighbours.has(IDS[other])).toBe(true);
      crossed++;
    }
    expect(crossed).toBe(6);
  });

  it('agrees with h3 membership where the nearest centre would not', () => {
    // Points on a fine grid over a few cells: the picked cell is always the one h3 names.
    const base = cellToLatLng(latLngToCell(DC_CENTER.lat, DC_CENTER.lng, 9));
    let checked = 0;
    for (let i = -20; i <= 20; i++) {
      for (let j = -20; j <= 20; j++) {
        const lat = base[0] + i * 0.0002;
        const lng = base[1] + j * 0.0002;
        const picked = cellAt(INDEX, lat, lng, latLngToCell, 9);
        expect(picked).toBeGreaterThanOrEqual(0);
        expect(IDS[picked]).toBe(latLngToCell(lat, lng, 9));
        checked++;
      }
    }
    expect(checked).toBe(1681);
  });
});
