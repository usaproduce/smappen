export const AREA_PALETTE = [
  '#6B4EFF', '#22c55e', '#f59e0b', '#ef4444', '#06b6d4',
  '#ec4899', '#8b5cf6', '#10b981', '#f97316', '#0ea5e9',
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
