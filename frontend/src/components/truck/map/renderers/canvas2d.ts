// Truck Planner map engine - the 2D-canvas renderer (docs/truck-planner/05_FRONTEND.md 5.7).
//
// The fallback behind the same interface as the WebGL2 renderer, for browsers without WebGL2 and
// after a context that does not come back. It draws only the cells in view, at 1x backing
// resolution, and chooses by their number:
//
//   up to 4,000     one fill() per cell, its true outline, in its table colour
//   up to 15,000    the bounding box of each cell with fillRect
//   more            nothing: the layer says "Zoom in to see the colours."
//
// Many hexagons are never merged into one path: that measured slower. Cells are painted opaque and
// the canvas element carries the layer's opacity, so the overlapping boxes of the middle tier are not
// blended twice. A hexagon is drawn three quarters of a pixel larger than it is, so the soft edges of
// two neighbours leave no light seam between them.

import type { HexMesh } from '../../../../utils/truck/map/mesh';
import { cullCells, worldRect } from '../../../../utils/truck/map/viewport';
import type { Renderer, Viewport } from '../types';

/** Up to this many cells in view each one is drawn as its hexagon. */
export const CANVAS2D_HEXAGON_LIMIT = 4000;
/** Up to this many cells in view each one is drawn as its bounding box; above it nothing is drawn. */
export const CANVAS2D_CELL_LIMIT = 15000;

export interface Canvas2dRenderer extends Renderer {
  readonly kind: 'canvas2d';
  /** Cells in view at the last render. */
  readonly cellsInView: number;
  /** True when the last render drew nothing because more than 15,000 cells were in view. */
  readonly overLimit: boolean;
}

/** A 2D renderer on a canvas, or null when the canvas gives no 2D context (it is bound to another kind). */
export function createCanvas2dRenderer(canvas: HTMLCanvasElement): Canvas2dRenderer | null {
  let ctx: CanvasRenderingContext2D | null = null;
  try {
    ctx = canvas.getContext('2d', { willReadFrequently: true });
  } catch {
    ctx = null;
  }
  if (ctx === null) return null;
  const g = ctx;

  let mesh: HexMesh | null = null;
  let values = new Uint8Array(0);
  let visible = new Uint32Array(0);
  // 256 CSS colours; entry 0 is never drawn.
  const colors: string[] = new Array<string>(256).fill('transparent');
  let opacity = 1;
  let cellsInView = 0;
  let overLimit = false;
  let disposed = false;

  return {
    kind: 'canvas2d',

    get cellsInView(): number {
      return cellsInView;
    },

    get overLimit(): boolean {
      return overLimit;
    },

    setMesh(next: HexMesh): void {
      mesh = next;
      values = new Uint8Array(next.n);
      visible = new Uint32Array(next.n);
    },

    setLut(lut: Uint8Array): void {
      for (let i = 1; i < 256; i++) {
        colors[i] = 'rgb(' + lut[4 * i] + ',' + lut[4 * i + 1] + ',' + lut[4 * i + 2] + ')';
      }
    },

    setValues(next: Uint8Array): void {
      if (next.length < values.length) return;
      values.set(next.length === values.length ? next : next.subarray(0, values.length));
    },

    setOpacity(alpha: number): void {
      if (alpha === opacity) return;
      opacity = alpha;
      canvas.style.opacity = String(alpha);
    },

    resize(width: number, height: number, _dpr: number): void {
      const w = Math.max(1, Math.round(width));
      const h = Math.max(1, Math.round(height));
      if (canvas.width !== w) canvas.width = w;
      if (canvas.height !== h) canvas.height = h;
      canvas.style.width = width + 'px';
      canvas.style.height = height + 'px';
    },

    render(vp: Viewport): void {
      if (disposed) return;
      g.setTransform(1, 0, 0, 1, 0, 0);
      g.clearRect(0, 0, canvas.width, canvas.height);
      cellsInView = 0;
      overLimit = false;
      if (mesh === null || mesh.n === 0 || !(vp.scale > 0)) return;

      const count = cullCells(mesh.centers, mesh.halfSizes, mesh.n, worldRect(vp), visible);
      cellsInView = count;
      if (count > CANVAS2D_CELL_LIMIT) {
        overLimit = true;
        return;
      }
      if (!(opacity > 0)) return;

      const scale = vp.scale;
      const ox = vp.originX;
      const oy = vp.originY;
      let last = 0;

      if (count > CANVAS2D_HEXAGON_LIMIT) {
        const centers = mesh.centers;
        const halfSizes = mesh.halfSizes;
        for (let i = 0; i < count; i++) {
          const c = visible[i];
          const v = values[c];
          if (v === 0) continue;
          if (v !== last) {
            g.fillStyle = colors[v];
            last = v;
          }
          // Whole pixels, so the boxes meet without soft edges.
          const x0 = Math.round(ox + (centers[2 * c] - halfSizes[2 * c]) * scale);
          const y0 = Math.round(oy + (centers[2 * c + 1] - halfSizes[2 * c + 1]) * scale);
          const x1 = Math.round(ox + (centers[2 * c] + halfSizes[2 * c]) * scale);
          const y1 = Math.round(oy + (centers[2 * c + 1] + halfSizes[2 * c + 1]) * scale);
          g.fillRect(x0, y0, x1 - x0 > 0 ? x1 - x0 : 1, y1 - y0 > 0 ? y1 - y0 : 1);
        }
        return;
      }

      const positions = mesh.positions;
      const centers = mesh.centers;
      const halfSizes = mesh.halfSizes;
      for (let i = 0; i < count; i++) {
        const c = visible[i];
        const v = values[c];
        if (v === 0) continue;
        if (v !== last) {
          g.fillStyle = colors[v];
          last = v;
        }
        // Each outline is drawn about three quarters of a pixel larger, from its own centre: the soft
        // edges of two neighbours would otherwise leave a light seam between them.
        const half = halfSizes[2 * c] * scale;
        const k = scale * (1 + 0.75 / (half > 1 ? half : 1));
        const cx = centers[2 * c];
        const cy = centers[2 * c + 1];
        const bx = ox + cx * scale;
        const by = oy + cy * scale;
        const p = c * 12;
        g.beginPath();
        g.moveTo(bx + (positions[p] - cx) * k, by + (positions[p + 1] - cy) * k);
        g.lineTo(bx + (positions[p + 2] - cx) * k, by + (positions[p + 3] - cy) * k);
        g.lineTo(bx + (positions[p + 4] - cx) * k, by + (positions[p + 5] - cy) * k);
        g.lineTo(bx + (positions[p + 6] - cx) * k, by + (positions[p + 7] - cy) * k);
        g.lineTo(bx + (positions[p + 8] - cx) * k, by + (positions[p + 9] - cy) * k);
        g.lineTo(bx + (positions[p + 10] - cx) * k, by + (positions[p + 11] - cy) * k);
        g.closePath();
        g.fill();
      }
    },

    dispose(): void {
      if (disposed) return;
      disposed = true;
      mesh = null;
      values = new Uint8Array(0);
      visible = new Uint32Array(0);
      // Shrinking the canvas frees its backing store.
      canvas.width = 1;
      canvas.height = 1;
    },
  };
}
