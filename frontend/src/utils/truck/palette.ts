// Truck Planner - the colour scale of the map layers and the week strip
// (docs/truck-planner/05_FRONTEND.md 5.5).
//
// One scale for the whole week. The domain is fixed and comes from the model:
// byte = scoreByte(x, hi) = floor(255 * sqrt(clamp(x / hi, 0, 1)) + 0.5), with hi from the seeds
// map.opportunity_hi, map.people_hi and map.competition_hi. It is the same for every hour, region and
// truck, so a colour always means the same number. Nothing is rescaled per viewport or per hour.
//
// The ramps below are data. Together with literal colours handed to a canvas they are the only hex
// values in Truck Planner code; every other colour is a CSS variable.

import { MAP_DOMAIN, scoreByte } from './model';
import type { MapLayer } from './model';
import { fmtCount } from './format';

export type PaletteTheme = 'light' | 'dark';

/**
 * Stops from low to high on the light theme. Each ramp gets darker as the value rises (strictly
 * falling relative luminance), which keeps it readable for colour-blind viewers and on the light
 * grey base map; one hue family per layer makes the active layer recognisable at a glance.
 */
export const RAMP_STOPS: Readonly<Record<MapLayer, readonly string[]>> = {
  // Viridis, light end low
  opportunity: ['#fde725', '#7ad151', '#44bf70', '#22a884', '#21918c', '#2a788e', '#355f8d', '#414487', '#482475', '#440154'],
  people: ['#d0e1f2', '#b0d2e8', '#89bedc', '#60a7d2', '#3e8ec4', '#2172b6', '#0a549e', '#08306b'],
  competition: ['#fee6ce', '#fdd0a2', '#fdae6b', '#fd8d3c', '#f16913', '#d94801', '#a63603', '#7f2704'],
};

/** Values marked on the legend bar; the last one is the top of the scale. */
export const LEGEND_TICKS: Readonly<Record<MapLayer, readonly number[]>> = {
  opportunity: [1, 5, 15, 30, 45],
  people: [100, 1000, 5000, 10000, 20000],
  competition: [1, 5, 25, 50, 100],
};

/** The bands the hover hint reads out, lowest first. A band starts at its lower edge. */
export const BAND_LABELS: Readonly<Record<MapLayer, readonly string[]>> = {
  opportunity: ['under 1', '1 to 5', '5 to 15', '15 to 30', '30 or more'],
  people: ['under 100', '100 to 1,000', '1,000 to 5,000', '5,000 to 10,000', '10,000 or more'],
  competition: ['under 1', '1 to 5', '5 to 25', '25 to 50', '50 or more'],
};

/** Lower edges of the second and later bands. */
const BAND_EDGES: Readonly<Record<MapLayer, readonly number[]>> = {
  opportunity: [1, 5, 15, 30],
  people: [100, 1000, 5000, 10000],
  competition: [1, 5, 25, 50],
};

/** Below this value a cell stays uncoloured. */
export const FLOORS: Readonly<Record<MapLayer, number>> = {
  opportunity: 0.1,
  people: 50,
  competition: 0.25,
};

/** The floor as the legend prints it, after "No colour: under". */
export const FLOOR_TEXT: Readonly<Record<MapLayer, string>> = {
  opportunity: '0.1 orders an hour',
  people: '50 people',
  competition: '0.25',
};

/** The layer is drawn at this opacity so roads and labels of the base map stay readable under it. */
export const LAYER_OPACITY = 0.68;

/** Rects of the legend ramp: flat swatches sampled from the table, never a CSS gradient. */
export const LEGEND_SWATCHES = 32;

/** The value that maps to byte 255 on a layer. */
export function layerHi(layer: MapLayer): number {
  return MAP_DOMAIN[layer];
}

/** The stops as drawn: low to high on the light base, reversed on the dark base so high values are light. */
export function rampStops(layer: MapLayer, theme: PaletteTheme): string[] {
  const stops = RAMP_STOPS[layer].slice();
  if (theme === 'dark') stops.reverse();
  return stops;
}

function channel(hex: string, at: number): number {
  return parseInt(hex.slice(at, at + 2), 16);
}

const lutMemo: Record<string, Uint8Array> = {};

/**
 * 256 RGBA entries (1,024 bytes), straight alpha. Entry 0 is transparent; entries 1 to 255 run from
 * the first stop to the last, interpolated linearly in sRGB. The returned table is shared: read it,
 * never write to it.
 */
export function buildLut(layer: MapLayer, theme: PaletteTheme): Uint8Array {
  const key = layer + '|' + theme;
  const known = lutMemo[key];
  if (known !== undefined) return known;
  const stops = rampStops(layer, theme);
  const last = stops.length - 1;
  const lut = new Uint8Array(1024);
  for (let i = 1; i < 256; i++) {
    const p = ((i - 1) / 254) * last;
    let k = Math.floor(p);
    if (k >= last) k = last - 1;
    const f = p - k;
    for (let c = 0; c < 3; c++) {
      const a = channel(stops[k], 1 + 2 * c);
      const b = channel(stops[k + 1], 1 + 2 * c);
      lut[4 * i + c] = Math.floor(a + (b - a) * f + 0.5);
    }
    lut[4 * i + 3] = 255;
  }
  lutMemo[key] = lut;
  return lut;
}

const cssMemo: Record<string, string[]> = {};

/** The same table as 256 CSS colours; entry 0 is "transparent". */
export function lutColors(layer: MapLayer, theme: PaletteTheme): string[] {
  const key = layer + '|' + theme;
  const known = cssMemo[key];
  if (known !== undefined) return known;
  const lut = buildLut(layer, theme);
  const out: string[] = ['transparent'];
  for (let i = 1; i < 256; i++) {
    out.push('rgb(' + String(lut[4 * i]) + ', ' + String(lut[4 * i + 1]) + ', ' + String(lut[4 * i + 2]) + ')');
  }
  cssMemo[key] = out;
  return out;
}

/** The smallest byte that is drawn: scoreByte(floor, hi), which is 12, 13 and 13. */
export function minByte(layer: MapLayer): number {
  return scoreByte(FLOORS[layer], MAP_DOMAIN[layer]);
}

/** Where a value sits along the legend bar, 0..1: sqrt(v / hi). */
export function tickPosition(layer: MapLayer, value: number): number {
  let t = value / MAP_DOMAIN[layer];
  if (t < 0) t = 0;
  if (t > 1) t = 1;
  return Math.sqrt(t);
}

/** The byte of a value on a layer. */
export function tickByte(layer: MapLayer, value: number): number {
  return scoreByte(value, MAP_DOMAIN[layer]);
}

export interface LegendTick {
  value: number;
  label: string;
  /** 0..1 along the bar. */
  position: number;
  byte: number;
}

/** The ticks of a layer's legend with their labels, positions and bytes. */
export function legendTicks(layer: MapLayer): LegendTick[] {
  const out: LegendTick[] = [];
  const ticks = LEGEND_TICKS[layer];
  for (let i = 0; i < ticks.length; i++) {
    out.push({ value: ticks[i], label: fmtCount(ticks[i]), position: tickPosition(layer, ticks[i]), byte: tickByte(layer, ticks[i]) });
  }
  return out;
}

/** The 32 flat colours of the legend ramp: swatch i takes table entry 8 * i + 4. */
export function legendSwatches(layer: MapLayer, theme: PaletteTheme): string[] {
  const colors = lutColors(layer, theme);
  const out: string[] = [];
  for (let i = 0; i < LEGEND_SWATCHES; i++) out.push(colors[8 * i + 4]);
  return out;
}

/** Which band a byte falls in, 0 = lowest. An uncoloured cell (byte 0) reads the lowest band. */
export function bandIndex(layer: MapLayer, byte: number): number {
  const edges = BAND_EDGES[layer];
  let k = 0;
  for (let i = 0; i < edges.length; i++) {
    if (byte >= scoreByte(edges[i], MAP_DOMAIN[layer])) k = i + 1;
  }
  return k;
}

/** The band of a byte in words: "5 to 15". */
export function bandLabel(layer: MapLayer, byte: number): string {
  return BAND_LABELS[layer][bandIndex(layer, byte)];
}

/**
 * The colour of a byte, or null when the cell stays uncoloured (under the layer's floor). The week
 * strip uses the opportunity table through this, so a colour means the same number there and on the map.
 */
export function colorOfByte(layer: MapLayer, theme: PaletteTheme, byte: number): string | null {
  if (!(byte >= minByte(layer))) return null;
  return lutColors(layer, theme)[byte > 255 ? 255 : byte];
}
