// Smappen-style choropleth gradient: cool → hot.
export const HEATMAP_STOPS = [
  '#4A148C', '#283593', '#1565C0', '#00838F',
  '#2E7D32', '#558B2F', '#9E9D24', '#F9A825',
  '#EF6C00', '#D32F2F',
];

export const HEATMAP_GRADIENT_CSS =
  'linear-gradient(to right, ' + HEATMAP_STOPS.join(', ') + ')';

export function colorForValue(value: number | null, min: number, max: number): string {
  if (value === null || max === min) return '#cccccc';
  const t = Math.max(0, Math.min(1, (value - min) / (max - min)));
  const idx = Math.min(HEATMAP_STOPS.length - 1, Math.floor(t * HEATMAP_STOPS.length));
  return HEATMAP_STOPS[idx];
}
