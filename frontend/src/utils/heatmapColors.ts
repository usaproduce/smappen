// Smappen-style choropleth gradient.
// 10 anchor colors with EXPLICIT stop positions — Smappen compresses the cool
// and hot extremes and gives the green→yellow→orange middle most of the visual
// real estate. We mirror that exactly so the bar and polygon coloring agree.
// Pastel / soft palette matching Smappen — Tailwind 300-400 saturation range
// (lighter, more friendly than the deep 500-700 we had before).
export const HEATMAP_STOPS = [
  '#A78BFA', // violet-400 (soft lavender)
  '#60A5FA', // blue-400  (soft blue)
  '#38BDF8', // sky-400
  '#22D3EE', // cyan-400
  '#34D399', // emerald-400 (mint)
  '#A3E635', // lime-400
  '#FDE047', // yellow-300 (soft sunny)
  '#FB923C', // orange-400 (peach)
  '#F87171', // red-400 (coral)
  '#F472B6', // pink-400 (soft pink)
];

/**
 * Positions of each anchor color on the [0,1] gradient.
 * Cool (purple → cyan): first 18% only.
 * Middle (green → orange): 18% → 80% (most of the bar).
 * Hot (red → pink): last 20%.
 */
export const HEATMAP_STOP_POSITIONS = [
  0.00,  // violet
  0.06,  // blue
  0.12,  // sky
  0.18,  // cyan
  0.30,  // emerald (big jump — green starts here)
  0.48,  // lime
  0.62,  // yellow
  0.78,  // orange
  0.90,  // red
  1.00,  // pink
];

export const HEATMAP_GRADIENT_CSS =
  'linear-gradient(to right, ' +
  HEATMAP_STOPS
    .map((c, i) => `${c} ${(HEATMAP_STOP_POSITIONS[i] * 100).toFixed(1)}%`)
    .join(', ') +
  ')';

function hexToRgb(hex: string): [number, number, number] {
  const m = hex.replace('#', '');
  return [
    parseInt(m.slice(0, 2), 16),
    parseInt(m.slice(2, 4), 16),
    parseInt(m.slice(4, 6), 16),
  ];
}
function lerp(a: number, b: number, t: number) { return a + (b - a) * t; }

/** RGB-space color at t∈[0,1] using HEATMAP_STOP_POSITIONS as anchor positions. */
function interpolate(t: number): string {
  t = Math.max(0, Math.min(1, t));
  // Find which segment t falls into based on stop positions.
  const positions = HEATMAP_STOP_POSITIONS;
  for (let i = 0; i < positions.length - 1; i++) {
    if (t <= positions[i + 1]) {
      const segLo = positions[i];
      const segHi = positions[i + 1];
      const segT = segHi === segLo ? 0 : (t - segLo) / (segHi - segLo);
      const [r1, g1, b1] = hexToRgb(HEATMAP_STOPS[i]);
      const [r2, g2, b2] = hexToRgb(HEATMAP_STOPS[i + 1]);
      const r = Math.round(lerp(r1, r2, segT));
      const g = Math.round(lerp(g1, g2, segT));
      const b = Math.round(lerp(b1, b2, segT));
      return `rgb(${r},${g},${b})`;
    }
  }
  return HEATMAP_STOPS[HEATMAP_STOPS.length - 1];
}

/** Continuous color for a value (uses quantile breaks when provided). */
export function colorForValue(
  value: number | null | undefined,
  min: number,
  max: number,
  breaks?: number[]
): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '#cccccc';
  return interpolate(valueToFraction(value, min, max, breaks));
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
