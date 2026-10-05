// Web Mercator in the 256-unit world (docs/truck-planner/05_FRONTEND.md 5.1, 8.2).

import { describe, expect, it } from 'vitest';
import { WORLD_SIZE, latOf, lngOf, worldX, worldY } from '../map/mercator';

const DC = { lat: 38.9072, lng: -77.0369 };

describe('world coordinates', () => {
  it('x runs from 0 at the antimeridian to 256', () => {
    expect(WORLD_SIZE).toBe(256);
    expect(worldX(-180)).toBe(0);
    expect(worldX(180)).toBe(256);
    expect(worldX(0)).toBe(128);
    expect(worldX(-90)).toBe(64);
  });

  it('y is 128 at the equator and falls to the north', () => {
    expect(worldY(0)).toBe(128);
    expect(worldY(10)).toBeLessThan(128);
    expect(worldY(-10)).toBeGreaterThan(128);
    let previous = worldY(-80);
    for (let lat = -79; lat <= 80; lat++) {
      const y = worldY(lat);
      expect(y).toBeLessThan(previous);
      previous = y;
    }
  });

  it('agrees with the published world coordinate of Chicago', () => {
    // Google Maps JavaScript API, "Map and Tile Coordinates": (41.85, -87.65) is (65.67111111111113, 95.17492654697409).
    expect(Math.abs(worldX(-87.65) - 65.67111111111113)).toBeLessThan(1e-12);
    expect(Math.abs(worldY(41.85) - 95.17492654697409)).toBeLessThan(1e-12);
  });

  it('is symmetric about the equator', () => {
    for (const lat of [1, 20, 38.9072, 60, 85]) {
      expect(Math.abs(worldY(lat) + worldY(-lat) - 256)).toBeLessThan(1e-10);
    }
  });
});

describe('the inverses', () => {
  it('round trip within 1e-9 degrees at the centre of the dc region', () => {
    expect(Math.abs(latOf(worldY(DC.lat)) - DC.lat)).toBeLessThan(1e-9);
    expect(Math.abs(lngOf(worldX(DC.lng)) - DC.lng)).toBeLessThan(1e-9);
  });

  it('round trip across the region box and well beyond it', () => {
    for (let lat = -84; lat <= 84; lat += 3.7) {
      for (let lng = -179; lng <= 179; lng += 17.3) {
        expect(Math.abs(latOf(worldY(lat)) - lat)).toBeLessThan(1e-9);
        expect(Math.abs(lngOf(worldX(lng)) - lng)).toBeLessThan(1e-9);
      }
    }
    for (const lat of [38.00484, 38.5, 39.0, 39.72058]) {
      for (const lng of [-78.34937, -77.5, -76.66133]) {
        expect(Math.abs(latOf(worldY(lat)) - lat)).toBeLessThan(1e-9);
        expect(Math.abs(worldY(latOf(worldY(lat))) - worldY(lat))).toBeLessThan(1e-11);
        expect(Math.abs(worldX(lngOf(worldX(lng))) - worldX(lng))).toBeLessThan(1e-11);
      }
    }
  });

  it('give the corners of the world', () => {
    expect(lngOf(0)).toBe(-180);
    expect(lngOf(256)).toBe(180);
    expect(latOf(128)).toBe(0);
    // y = 0 is the top edge of the square world: 85.0511 degrees north.
    expect(Math.abs(latOf(0) - 85.0511287798066)).toBeLessThan(1e-9);
    expect(Math.abs(latOf(256) + 85.0511287798066)).toBeLessThan(1e-9);
  });
});

describe('the clamp near the poles', () => {
  it('keeps y finite: the sine of the latitude never passes 0.9999', () => {
    const atLimit = 256 * (0.5 - Math.log((1 + 0.9999) / (1 - 0.9999)) / (4 * Math.PI));
    expect(Number.isFinite(worldY(90))).toBe(true);
    expect(worldY(90)).toBe(atLimit);
    expect(worldY(89.9)).toBe(atLimit);
    expect(Math.abs(worldY(-90) - (256 - atLimit))).toBeLessThan(1e-10);
    expect(worldY(-89.5)).toBe(worldY(-90));
    // 89 degrees is still inside the clamp (sin = 0.99985).
    expect(worldY(89)).toBeGreaterThan(atLimit);
  });

  it('the inverse of a clamped y is the latitude of the clamp, about 89.19 degrees', () => {
    const limit = (Math.asin(0.9999) * 180) / Math.PI;
    expect(Math.abs(latOf(worldY(90)) - limit)).toBeLessThan(1e-6);
    expect(Math.abs(limit - 89.1897)).toBeLessThan(1e-3);
  });
});
