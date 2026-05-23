import { paletteById, gradientCss, type Palette } from './palettes';

export { PALETTES, paletteById, gradientCss, DEFAULT_PALETTE_ID } from './palettes';
export type { Palette } from './palettes';

/** Legacy export — kept for any callers still importing it. Resolves to default palette stops. */
export const HEATMAP_STOPS = paletteById('smappen-pastel').stops;

/** CSS gradient for the default palette. Most callers should use gradientCss(activePalette). */
export const HEATMAP_GRADIENT_CSS = gradientCss(paletteById('smappen-pastel'));

function hexToRgb(hex: string): [number, number, number] {
  const m = hex.replace('#', '');
  return [
    parseInt(m.slice(0, 2), 16),
    parseInt(m.slice(2, 4), 16),
    parseInt(m.slice(4, 6), 16),
  ];
}
function lerp(a: number, b: number, t: number) { return a + (b - a) * t; }

/** RGB-space color at t∈[0,1] using a palette's stops + positions. */
export function interpolatePalette(palette: Palette, t: number): string {
  t = Math.max(0, Math.min(1, t));
  const stops = palette.stops;
  const positions = palette.positions ?? stops.map((_, i) => i / (stops.length - 1));
  for (let i = 0; i < positions.length - 1; i++) {
    if (t <= positions[i + 1]) {
      const segLo = positions[i];
      const segHi = positions[i + 1];
      const segT = segHi === segLo ? 0 : (t - segLo) / (segHi - segLo);
      const [r1, g1, b1] = hexToRgb(stops[i]);
      const [r2, g2, b2] = hexToRgb(stops[i + 1]);
      const r = Math.round(lerp(r1, r2, segT));
      const g = Math.round(lerp(g1, g2, segT));
      const b = Math.round(lerp(b1, b2, segT));
      return `rgb(${r},${g},${b})`;
    }
  }
  return stops[stops.length - 1];
}

/** Color for a value using a specific palette (quantile-aware). */
export function colorForValueWith(
  palette: Palette,
  value: number | null | undefined,
  min: number,
  max: number,
  breaks?: number[]
): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '#cccccc';
  return interpolatePalette(palette, valueToFraction(value, min, max, breaks));
}

/** Backward-compatible default-palette color picker. */
export function colorForValue(
  value: number | null | undefined,
  min: number,
  max: number,
  breaks?: number[]
): string {
  return colorForValueWith(paletteById('smappen-pastel'), value, min, max, breaks);
}

/**
 * Returns t∈[0,1] — the legend-bar position for a given value.
 * Uses quantile breaks (decile cuts) when provided, otherwise linear.
 */
export function valueToFraction(
  value: number | null | undefined,
  min: number,
  max: number,
  breaks?: number[]
): number {
  if (value === null || value === undefined || Number.isNaN(value)) return 0;
  if (breaks && breaks.length > 0) {
    const N = breaks.length + 1;
    if (value <= breaks[0]) {
      const segLo = min;
      const segHi = breaks[0];
      const t = segHi === segLo ? 0 : (value - segLo) / (segHi - segLo);
      return Math.max(0, Math.min(1 / N, t / N));
    }
    if (value >= breaks[breaks.length - 1]) {
      const segLo = breaks[breaks.length - 1];
      const segHi = max;
      const t = segHi === segLo ? 1 : (value - segLo) / (segHi - segLo);
      return Math.max((N - 1) / N, Math.min(1, (N - 1) / N + t / N));
    }
    for (let i = 0; i < breaks.length - 1; i++) {
      if (value >= breaks[i] && value < breaks[i + 1]) {
        const segLo = breaks[i];
        const segHi = breaks[i + 1];
        const segT = segHi === segLo ? 0 : (value - segLo) / (segHi - segLo);
        return (i + 1 + segT) / N;
      }
    }
    return 0;
  }
  if (max === min) return 0;
  return Math.max(0, Math.min(1, (value - min) / (max - min)));
}
