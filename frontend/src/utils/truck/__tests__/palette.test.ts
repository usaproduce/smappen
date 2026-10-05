// The colour scale of the map layers and the week strip (docs/truck-planner/05_FRONTEND.md 5.5, 8.2).

import { describe, expect, it } from 'vitest';
import { MAP_DOMAIN, MAP_LAYERS, scoreByte } from '../model';
import type { MapLayer } from '../model';
import {
  BAND_LABELS,
  FLOORS,
  FLOOR_TEXT,
  LAYER_OPACITY,
  LEGEND_SWATCHES,
  LEGEND_TICKS,
  RAMP_STOPS,
  bandIndex,
  bandLabel,
  buildLut,
  colorOfByte,
  layerHi,
  legendSwatches,
  legendTicks,
  lutColors,
  minByte,
  rampStops,
  tickByte,
  tickPosition,
} from '../palette';
import { spec, specCount } from './_kitFixtures';

const LAYERS: MapLayer[] = ['opportunity', 'people', 'competition'];

function rgbOfHex(hex: string): [number, number, number] {
  return [parseInt(hex.slice(1, 3), 16), parseInt(hex.slice(3, 5), 16), parseInt(hex.slice(5, 7), 16)];
}

// WCAG relative luminance of an sRGB colour.
function luminance(r: number, g: number, b: number): number {
  const lin = (c: number) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

describe('ramps', () => {
  it('are the stops of the specification, low to high', () => {
    expect(spec(RAMP_STOPS.opportunity.join(' '))).toBe('#fde725 #7ad151 #44bf70 #22a884 #21918c #2a788e #355f8d #414487 #482475 #440154');
    expect(spec(RAMP_STOPS.people.join(' '))).toBe('#d0e1f2 #b0d2e8 #89bedc #60a7d2 #3e8ec4 #2172b6 #0a549e #08306b');
    expect(spec(RAMP_STOPS.competition.join(' '))).toBe('#fee6ce #fdd0a2 #fdae6b #fd8d3c #f16913 #d94801 #a63603 #7f2704');
  });

  it('get darker as the value rises: strictly falling relative luminance along each ramp', () => {
    for (const layer of LAYERS) {
      const stops = RAMP_STOPS[layer].map((hex) => luminance(...rgbOfHex(hex)));
      for (let i = 1; i < stops.length; i++) expect(stops[i]).toBeLessThan(stops[i - 1]);
      // along the whole table: strictly falling from swatch to swatch of the legend, and from entry to
      // entry never rising by more than the rounding of one channel step
      const lut = buildLut(layer, 'light');
      const at = (i: number) => luminance(lut[4 * i], lut[4 * i + 1], lut[4 * i + 2]);
      for (let i = 2; i < 256; i++) expect(at(i)).toBeLessThan(at(i - 1) + 0.004);
      for (let s = 1; s < 32; s++) expect(at(8 * s + 4)).toBeLessThan(at(8 * (s - 1) + 4));
      expect(at(255)).toBeLessThan(at(1));
    }
  });

  it('are reversed on the dark theme, so high values are light', () => {
    for (const layer of LAYERS) {
      expect(rampStops(layer, 'dark')).toEqual(RAMP_STOPS[layer].slice().reverse());
      expect(rampStops(layer, 'light')).toEqual(RAMP_STOPS[layer].slice());
      const light = buildLut(layer, 'light');
      const dark = buildLut(layer, 'dark');
      for (let i = 1; i < 256; i++) {
        const j = 256 - i;
        for (let c = 0; c < 3; c++) expect(Math.abs(dark[4 * i + c] - light[4 * j + c])).toBeLessThanOrEqual(1);
      }
      const first = luminance(dark[4], dark[5], dark[6]);
      const last = luminance(dark[4 * 255], dark[4 * 255 + 1], dark[4 * 255 + 2]);
      expect(last).toBeGreaterThan(first);
    }
  });
});

describe('buildLut', () => {
  it('has 256 RGBA entries: 1,024 bytes', () => {
    for (const layer of LAYERS) {
      for (const theme of ['light', 'dark'] as const) {
        const lut = buildLut(layer, theme);
        expect(lut).toBeInstanceOf(Uint8Array);
        expect(spec(lut.length)).toBe(1024);
      }
    }
  });

  it('keeps entry 0 transparent and every other entry opaque', () => {
    for (const layer of LAYERS) {
      const lut = buildLut(layer, 'light');
      expect(Array.from(lut.slice(0, 4))).toEqual([0, 0, 0, 0]);
      for (let i = 1; i < 256; i++) expect(lut[4 * i + 3]).toBe(255);
      expect(lutColors(layer, 'light')[0]).toBe('transparent');
    }
  });

  it('runs from the first stop at entry 1 to the last stop at entry 255, linearly in sRGB', () => {
    for (const layer of LAYERS) {
      const lut = buildLut(layer, 'light');
      const stops = RAMP_STOPS[layer];
      expect(Array.from(lut.slice(4, 7))).toEqual(rgbOfHex(stops[0]));
      expect(Array.from(lut.slice(4 * 255, 4 * 255 + 3))).toEqual(rgbOfHex(stops[stops.length - 1]));
    }
    // opportunity has ten stops: entry 1 + 254 * k / 9 sits exactly on stop k when that is whole
    const lut = buildLut('opportunity', 'light');
    expect(Array.from(lut.slice(4 * 128, 4 * 128 + 3))).toEqual([
      Math.floor((0x21 + 0x2a) / 2 + 0.5),
      Math.floor((0x91 + 0x78) / 2 + 0.5),
      Math.floor((0x8c + 0x8e) / 2 + 0.5),
    ]);
  });

  it('returns the same table for the same layer and theme', () => {
    expect(buildLut('people', 'light')).toBe(buildLut('people', 'light'));
    expect(lutColors('people', 'dark')).toBe(lutColors('people', 'dark'));
    expect(lutColors('competition', 'light')[255]).toBe('rgb(127, 39, 4)');
  });
});

describe('the fixed scale', () => {
  it('takes its top from the seeds: 45, 20,000 and 100', () => {
    expect(spec(layerHi('opportunity'))).toBe(45);
    expect(spec(layerHi('people'))).toBe(20000);
    expect(spec(layerHi('competition'))).toBe(100);
    expect(MAP_LAYERS.slice()).toEqual(LAYERS);
    for (const layer of LAYERS) expect(layerHi(layer)).toBe(MAP_DOMAIN[layer]);
  });

  it('puts a tick at sqrt(v / hi) along the bar and at byte scoreByte(v, hi)', () => {
    for (const layer of LAYERS) {
      const hi = layerHi(layer);
      for (const v of LEGEND_TICKS[layer]) {
        expect(tickPosition(layer, v)).toBe(Math.sqrt(v / hi));
        expect(tickByte(layer, v)).toBe(Math.floor(255 * Math.sqrt(v / hi) + 0.5));
        expect(tickByte(layer, v)).toBe(scoreByte(v, hi));
      }
      expect(tickPosition(layer, hi)).toBe(1);
      expect(tickPosition(layer, 0)).toBe(0);
      expect(tickPosition(layer, hi * 4)).toBe(1);
      expect(tickPosition(layer, -3)).toBe(0);
    }
    expect(tickPosition('opportunity', 5)).toBeCloseTo(1 / 3, 12);
    expect(tickPosition('competition', 25)).toBe(0.5);
  });

  it('prints the ticks of the specification', () => {
    expect(spec(legendTicks('opportunity').map((t) => t.label))).toEqual(['1', '5', '15', '30', '45']);
    expect(spec(legendTicks('people').map((t) => t.label))).toEqual(['100', '1,000', '5,000', '10,000', '20,000']);
    expect(spec(legendTicks('competition').map((t) => t.label))).toEqual(['1', '5', '25', '50', '100']);
    for (const layer of LAYERS) {
      const ticks = legendTicks(layer);
      expect(ticks[ticks.length - 1].byte).toBe(255);
      expect(ticks[ticks.length - 1].position).toBe(1);
      for (let i = 1; i < ticks.length; i++) expect(ticks[i].position).toBeGreaterThan(ticks[i - 1].position);
    }
  });

  it('leaves a cell uncoloured under the floor: minByte is 12, 13 and 13', () => {
    expect(spec(minByte('opportunity'))).toBe(12);
    expect(spec(minByte('people'))).toBe(13);
    expect(spec(minByte('competition'))).toBe(13);
    for (const layer of LAYERS) expect(minByte(layer)).toBe(scoreByte(FLOORS[layer], layerHi(layer)));
    expect(spec(FLOOR_TEXT.opportunity)).toBe('0.1 orders an hour');
    expect(spec(FLOOR_TEXT.people)).toBe('50 people');
    expect(spec(FLOOR_TEXT.competition)).toBe('0.25');
    expect(spec(LAYER_OPACITY)).toBe(0.68);
  });

  it('gives the colour of a byte, or nothing under the floor', () => {
    for (const layer of LAYERS) {
      const floor = minByte(layer);
      expect(colorOfByte(layer, 'light', 0)).toBeNull();
      expect(colorOfByte(layer, 'light', floor - 1)).toBeNull();
      expect(colorOfByte(layer, 'light', floor)).toBe(lutColors(layer, 'light')[floor]);
      expect(colorOfByte(layer, 'light', 255)).toBe(lutColors(layer, 'light')[255]);
      expect(colorOfByte(layer, 'dark', 200)).toBe(lutColors(layer, 'dark')[200]);
    }
  });
});

describe('bands of the hover hint', () => {
  it('are the bands of the specification', () => {
    expect(spec(BAND_LABELS.opportunity.slice())).toEqual(['under 1', '1 to 5', '5 to 15', '15 to 30', '30 or more']);
    expect(spec(BAND_LABELS.people.slice())).toEqual(['under 100', '100 to 1,000', '1,000 to 5,000', '5,000 to 10,000', '10,000 or more']);
    expect(spec(BAND_LABELS.competition.slice())).toEqual(['under 1', '1 to 5', '5 to 25', '25 to 50', '50 or more']);
  });

  it('looks up the band at every tick byte: a band starts at its lower edge', () => {
    for (const layer of LAYERS) {
      const ticks = LEGEND_TICKS[layer];
      const labels = BAND_LABELS[layer];
      for (let i = 0; i < ticks.length; i++) {
        const byte = tickByte(layer, ticks[i]);
        const expected = Math.min(i + 1, labels.length - 1); // the top tick is the end of the last band
        expect(bandIndex(layer, byte)).toBe(expected);
        expect(bandLabel(layer, byte)).toBe(labels[expected]);
        if (i < ticks.length - 1) expect(bandIndex(layer, byte - 1)).toBe(i);
      }
      // an uncoloured cell reads the lowest band
      expect(bandLabel(layer, 0)).toBe(labels[0]);
      expect(bandLabel(layer, minByte(layer) - 1)).toBe(labels[0]);
      expect(bandLabel(layer, 255)).toBe(labels[labels.length - 1]);
    }
    expect(bandLabel('opportunity', scoreByte(12, 45))).toBe('5 to 15');
    expect(bandLabel('people', scoreByte(7500, 20000))).toBe('5,000 to 10,000');
    expect(bandLabel('competition', scoreByte(0.5, 100))).toBe('under 1');
  });
});

describe('legend ramp', () => {
  it('is 32 flat swatches: swatch i takes table entry 8 * i + 4', () => {
    expect(spec(LEGEND_SWATCHES)).toBe(32);
    for (const layer of LAYERS) {
      for (const theme of ['light', 'dark'] as const) {
        const swatches = legendSwatches(layer, theme);
        const colors = lutColors(layer, theme);
        expect(swatches.length).toBe(32);
        for (let i = 0; i < 32; i++) expect(swatches[i]).toBe(colors[8 * i + 4]);
        for (const s of swatches) expect(/^rgb\(\d+, \d+, \d+\)$/.test(s)).toBe(true);
      }
    }
  });
});

describe('worked examples', () => {
  it('this file asserts 26 values the specification prints', () => {
    expect(specCount()).toBe(26);
  });
});
