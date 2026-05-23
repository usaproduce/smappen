// Smappen-style choropleth gradient: cool → hot. 10 stops to match decile breaks.
export const HEATMAP_STOPS = [
  '#4A148C', '#283593', '#1565C0', '#00838F',
  '#2E7D32', '#558B2F', '#9E9D24', '#F9A825',
  '#EF6C00', '#D32F2F',
];

export const HEATMAP_GRADIENT_CSS =
  'linear-gradient(to right, ' + HEATMAP_STOPS.join(', ') + ')';

/**
 * Color picker that uses quantile breaks when provided.
 * `breaks` is an array of length 9 (the 10/20/.../90 percentile values)
 * dividing the data into 10 equal-count buckets — fixes the "all purple"
 * rendering when a few outliers blow out the linear scale.
 * Falls back to linear scaling when breaks aren't provided.
 */
export function colorForValue(
  value: number | null | undefined,
  min: number,
  max: number,
  breaks?: number[]
): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '#cccccc';

  if (breaks && breaks.length > 0) {
    let bucket = 0;
    for (const b of breaks) {
      if (value <= b) break;
      bucket++;
    }
    return HEATMAP_STOPS[Math.min(HEATMAP_STOPS.length - 1, bucket)];
  }

  if (max === min) return HEATMAP_STOPS[0];
  const t = Math.max(0, Math.min(1, (value - min) / (max - min)));
  const idx = Math.min(HEATMAP_STOPS.length - 1, Math.floor(t * HEATMAP_STOPS.length));
  return HEATMAP_STOPS[idx];
}
