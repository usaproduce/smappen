/**
 * Heatmap palette catalog. Each palette has a name, an ordered list of colors,
 * and optionally explicit stop positions (0..1) — when omitted, stops are
 * spaced uniformly.
 *
 * The same anchor positions drive both:
 *   - the CSS `linear-gradient` for the legend bar
 *   - the polygon fill color (interpolated RGB at value→fraction)
 * so the two always agree visually.
 */

export interface Palette {
  id: string;
  name: string;
  description: string;
  stops: string[];
  /** Optional per-stop positions (must match `stops.length`). Defaults to even spacing. */
  positions?: number[];
}

/** Smappen-style compression: cool 18%, middle 62%, hot 20%. */
const SMAPPEN_POSITIONS = [0.00, 0.06, 0.12, 0.18, 0.30, 0.48, 0.62, 0.78, 0.90, 1.00];

export const PALETTES: Palette[] = [
  {
    id: 'smappen-pastel',
    name: 'Smappen Pastel',
    description: 'Soft lavender → coral → pink (default, matches reference)',
    stops: ['#A78BFA', '#60A5FA', '#38BDF8', '#22D3EE', '#34D399', '#A3E635', '#FDE047', '#FB923C', '#F87171', '#F472B6'],
    positions: SMAPPEN_POSITIONS,
  },
  {
    id: 'vivid-rainbow',
    name: 'Vivid Rainbow',
    description: 'Saturated violet → blue → green → yellow → red → pink',
    stops: ['#5E18B7', '#2563EB', '#0EA5E9', '#06B6D4', '#10B981', '#84CC16', '#FACC15', '#FB923C', '#EF4444', '#EC4899'],
    positions: SMAPPEN_POSITIONS,
  },
  {
    id: 'viridis',
    name: 'Viridis',
    description: 'Perceptually uniform purple → teal → green → yellow',
    stops: ['#440154', '#482475', '#414487', '#355f8d', '#2a788e', '#21918c', '#22a884', '#44bf70', '#7ad151', '#fde725'],
  },
  {
    id: 'plasma',
    name: 'Plasma',
    description: 'Deep purple → magenta → orange → yellow',
    stops: ['#0d0887', '#46039f', '#7201a8', '#9c179e', '#bd3786', '#d8576b', '#ed7953', '#fb9f3a', '#fdc926', '#f0f921'],
  },
  {
    id: 'magma',
    name: 'Magma',
    description: 'Black → purple → red → orange → cream',
    stops: ['#000004', '#180f3d', '#440f76', '#721f81', '#9e2f7f', '#cd4071', '#f1605d', '#fd9668', '#feca8d', '#fcfdbf'],
  },
  {
    id: 'inferno',
    name: 'Inferno',
    description: 'Black → magenta → red → orange → bright yellow',
    stops: ['#000004', '#1b0c41', '#4a0c6b', '#781c6d', '#a52c60', '#cf4446', '#ed6925', '#fb9b06', '#f7d13d', '#fcffa4'],
  },
  {
    id: 'turbo',
    name: 'Turbo',
    description: 'Google’s improved rainbow — navy → cyan → green → yellow → red',
    stops: ['#30123b', '#4145ab', '#4675ed', '#39a2fc', '#1bcfd4', '#24eca6', '#a8fb20', '#f1ca3a', '#fb8022', '#7a0403'],
  },
  {
    id: 'heat',
    name: 'Heat',
    description: 'Classic thermal — black → red → orange → yellow → white',
    stops: ['#0a0000', '#350000', '#5e0000', '#870000', '#b00000', '#d80000', '#f86600', '#f8c200', '#fcfd00', '#ffffff'],
  },
  {
    id: 'cool-warm',
    name: 'Cool-Warm',
    description: 'Diverging blue → neutral → red (good for income / change metrics)',
    stops: ['#2c4eb5', '#4a6fde', '#6f9dff', '#a0c4f2', '#d4e1ed', '#ead6cc', '#ed9e8a', '#d96a5a', '#b22e3f', '#800026'],
  },
  {
    id: 'sunset',
    name: 'Sunset',
    description: 'Soft sunset — deep purple → coral → peach → gold',
    stops: ['#3D0066', '#7A29A8', '#C140C6', '#E4567B', '#F08055', '#F8A546', '#F8C75A', '#FCE38A', '#FFF6B2', '#FFFADC'],
  },
  {
    id: 'mono-purple',
    name: 'Mono Purple',
    description: 'Single-hue lightness ramp — light lavender → deep violet',
    stops: ['#F5F3FF', '#EDE9FE', '#DDD6FE', '#C4B5FD', '#A78BFA', '#8B5CF6', '#7C3AED', '#6D28D9', '#5B21B6', '#4C1D95'],
  },
];

export const DEFAULT_PALETTE_ID = 'smappen-pastel';

/**
 * Accent ramp shared between the map's heatmap and the rest of the UI's
 * semantic accent tokens (KPI cards, chart series, leaderboards, goal
 * trends). Anchored on Viridis: purple → teal → green → yellow. Purple
 * anchor harmonizes with `--brand: #7848BB` so the accent system reads as
 * an extension of the brand, not a rebrand.
 *
 * Swap this constant to re-theme the whole semantic-accent system in one
 * place (the heatmap stays on whichever ramp the user picked).
 */
export const ACCENT_RAMP_ID = 'viridis';
export const ACCENT_RAMP: Palette = paletteFromCatalog(ACCENT_RAMP_ID);

function paletteFromCatalog(id: string): Palette {
  return PALETTES.find((p) => p.id === id) ?? PALETTES[0];
}

export function paletteById(id: string): Palette {
  return paletteFromCatalog(id);
}

/** CSS linear-gradient string for a palette. */
export function gradientCss(p: Palette): string {
  const positions = p.positions ?? p.stops.map((_, i) => i / (p.stops.length - 1));
  return (
    'linear-gradient(to right, ' +
    p.stops.map((c, i) => `${c} ${(positions[i] * 100).toFixed(1)}%`).join(', ') +
    ')'
  );
}
