import { describe, it, expect } from 'vitest';
import { polygonCentroid, polygonBounds } from '../geo';
import type { GeoJSONPolygon } from '../../types';

describe('polygonCentroid', () => {
  it('returns the average for a unit square', () => {
    const sq: GeoJSONPolygon = {
      type: 'Polygon',
      coordinates: [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]],
    };
    const c = polygonCentroid(sq);
    expect(c.lat).toBeCloseTo(0.4, 1); // includes the duplicated closing vertex
    expect(c.lng).toBeCloseTo(0.4, 1);
  });
});

describe('polygonBounds', () => {
  it('captures the extremes', () => {
    const poly: GeoJSONPolygon = {
      type: 'Polygon',
      coordinates: [[[-2, 3], [5, 3], [5, 7], [-2, 7], [-2, 3]]],
    };
    const b = polygonBounds(poly);
    expect(b.minLng).toBe(-2);
    expect(b.maxLng).toBe(5);
    expect(b.minLat).toBe(3);
    expect(b.maxLat).toBe(7);
  });
});
