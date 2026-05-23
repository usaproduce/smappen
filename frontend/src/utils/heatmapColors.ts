// Smappen-style choropleth gradient: cool → hot.
// 10 anchor colors interpolated continuously between deciles.
export const HEATMAP_STOPS = [
  '#4A148C', '#283593', '#1565C0', '#00838F',
  '#2E7D32', '#558B2F', '#9E9D24', '#F9A825',
  '#EF6C00', '#D32F2F',
];

export const HEATMAP_GRADIENT_CSS =
  'linear-gradient(to right, ' + HEATMAP_STOPS.join(', ') + ')';

function hexToRgb(hex: string): [number, number, number] {
  const m = hex.replace('#', '');
  return [
    parseInt(m.slice(0, 2), 16),
    parseInt(m.slice(2, 4), 16),
    parseInt(m.slice(4, 6), 16),
  ];
}
function lerp(a: number, b: number, t: number) { return a + (b - a) * t; }

function interpolate(t: number): string {
  t = Math.max(0, Math.min(1, t));
  const n = HEATMAP_STOPS.length - 1; // 9 segments
  const idx = Math.min(n - 1, Math.floor(t * n));
  const segT = t * n - idx;
  const [r1, g1, b1] = hexToRgb(HEATMAP_STOPS[idx]);
  const [r2, g2, b2] = hexToRgb(HEATMAP_STOPS[idx + 1]);
  const r = Math.round(lerp(r1, r2, segT));
  const g = Math.round(lerp(g1, g2, segT));
  const b = Math.round(lerp(b1, b2, segT));
  return `rgb(${r},${g},${b})`;
}

/**
 * Continuous color for a value, interpolating between anchor stops.
 * When `breaks` (quantile decile cuts) are provided, each decile occupies
 * 1/N of the gradient range — so a single outlier can't pin everything else
 * to one color but values within deciles still get smooth shading.
 */
export function colorForValue(
  value: number | null | undefined,
  min: number,
  max: number,
  breaks?: number[]
): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '#cccccc';
  return interpolate(valueToFraction(value, min, max, breaks));
}

/** Returns t∈[0,1] — the legend-bar position for a given value. */
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
