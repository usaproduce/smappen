// VT11 — expanded color palette: 24 named, brand-aligned colors arranged
// on a 4×6 grid in the color picker. The original 10-color palette wasn't
// enough to differentiate territories on dense urban maps.
export interface NamedColor { hex: string; name: string }
export const AREA_PALETTE_NAMED: NamedColor[] = [
  // Row 1 — warm
  { hex: '#E53935', name: 'Crimson'   },
  { hex: '#F57C00', name: 'Tangerine' },
  { hex: '#FFB300', name: 'Amber'     },
  { hex: '#FBC02D', name: 'Goldenrod' },
  // Row 2 — green
  { hex: '#7CB342', name: 'Lime'      },
  { hex: '#43A047', name: 'Forest'    },
  { hex: '#00897B', name: 'Teal'      },
  { hex: '#00ACC1', name: 'Lagoon'    },
  // Row 3 — blue
  { hex: '#1E88E5', name: 'Sky'       },
  { hex: '#1565C0', name: 'Cobalt'    },
  { hex: '#3949AB', name: 'Indigo'    },
  { hex: '#5E35B1', name: 'Violet'    },
  // Row 4 — purple/pink
  { hex: '#7848BB', name: 'Brand'     },
  { hex: '#8E24AA', name: 'Plum'      },
  { hex: '#D81B60', name: 'Magenta'   },
  { hex: '#EC407A', name: 'Coral'     },
  // Row 5 — earth + accents
  { hex: '#6D4C41', name: 'Espresso'  },
  { hex: '#8D6E63', name: 'Mocha'     },
  { hex: '#BF360C', name: 'Rust'      },
  { hex: '#FF7043', name: 'Sunset'    },
  // Row 6 — neutrals
  { hex: '#546E7A', name: 'Slate'     },
  { hex: '#37474F', name: 'Graphite'  },
  { hex: '#90A4AE', name: 'Pewter'    },
  { hex: '#26C6DA', name: 'Aqua'      },
];

// Legacy flat-array export kept for backwards compatibility with
// any module that still imports AREA_PALETTE.
export const AREA_PALETTE = AREA_PALETTE_NAMED.map((c) => c.hex);

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
