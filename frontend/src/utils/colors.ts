// Smappen area-marker palette.
export const AREA_PALETTE = [
  '#E53935', // red
  '#00897B', // teal/green
  '#7848BB', // purple
  '#F57C00', // orange
  '#1565C0', // blue
  '#D81B60', // pink
  '#00ACC1', // cyan
  '#7CB342', // lime
  '#FFB300', // amber
  '#3949AB', // indigo
];

export function pickColor(index: number): string {
  return AREA_PALETTE[index % AREA_PALETTE.length];
}

export function contrastInk(hex: string): string {
  const c = hex.replace('#', '');
  const r = parseInt(c.substring(0, 2), 16);
  const g = parseInt(c.substring(2, 4), 16);
  const b = parseInt(c.substring(4, 6), 16);
  const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return lum > 0.6 ? '#111827' : '#ffffff';
}
