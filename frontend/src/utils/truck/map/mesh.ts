// Truck Planner map engine - the hexagon mesh (docs/truck-planner/05_FRONTEND.md 5.3).
//
// One static mesh for a whole region: the true outline of every H3 cell, in the 256-unit Mercator
// world, stored relative to the centre of the region so that 32-bit floats stay exact enough at street
// zoom (absolute world coordinates would jitter from zoom 18). The renderers draw it and only ever
// change one byte per cell.
//
// Pure: runs in Node and in the browser. The cell outlines come from the caller (`boundaryOf`), which
// in the app is `cellToBoundary` of h3-js.

import { worldX, worldY } from './mercator';

/** One static mesh of true H3 cell outlines, in world coordinates relative to the centre of the pack's bounds. */
export interface HexMesh {
  /** Number of cells. */
  n: number;
  /** The mesh origin: the centre of the pack's bounds. */
  originLat: number;
  originLng: number;
  /** The same point in the 256-unit Mercator world. */
  originX: number;
  originY: number;
  /** 12 numbers per cell: six vertices, x then y, relative to the origin. A pentagon repeats its last vertex. */
  positions: Float32Array;
  /** 12 indices per cell: a fan over its six vertices. */
  indices: Uint32Array;
  /** 2 numbers per cell: the centre of its bounding box, relative to the origin. */
  centers: Float32Array;
  /** 2 numbers per cell: half-width and half-height of its bounding box. */
  halfSizes: Float32Array;
}

/** A box in degrees, as the pack header carries it. */
export interface MeshBounds {
  lat_min: number;
  lng_min: number;
  lat_max: number;
  lng_max: number;
}

/** Vertices kept per cell. */
export const VERTICES_PER_CELL = 6;
/** Numbers per cell in `positions`. */
export const POSITIONS_PER_CELL = 12;
/** Indices per cell: four triangles. */
export const INDICES_PER_CELL = 12;
/**
 * The build calls `onYield` after every this many cells. The caller decides there whether to hand
 * control back to the event loop (the layer does when ten milliseconds have passed), so no task
 * runs long on a slow device either.
 */
export const MESH_YIELD_EVERY = 1024;

/** The fan over six vertices: (0,1,2) (0,2,3) (0,3,4) (0,4,5). */
const FAN = [0, 1, 2, 0, 2, 3, 0, 3, 4, 0, 4, 5];

// Scratch for one cell: world coordinates of its outline, relative to the origin. An H3 outline has
// five or six corners, and up to ten points where it crosses an edge of the icosahedron.
const sx: number[] = [];
const sy: number[] = [];

/**
 * Bring the scratch outline to exactly six vertices. With more (an outline that crosses an
 * icosahedron edge carries extra points on its sides) the point that bends the outline least is
 * dropped until six remain; with fewer (a pentagon) the last vertex is repeated.
 */
function toSix(count: number): void {
  let m = count;
  while (m > VERTICES_PER_CELL) {
    let drop = 0;
    let least = Infinity;
    for (let i = 0; i < m; i++) {
      const a = (i + m - 1) % m;
      const b = (i + 1) % m;
      // Twice the area of the triangle (previous, this, next): zero for a point on a straight side.
      const bend = Math.abs((sx[i] - sx[a]) * (sy[b] - sy[a]) - (sy[i] - sy[a]) * (sx[b] - sx[a]));
      if (bend < least) {
        least = bend;
        drop = i;
      }
    }
    for (let i = drop; i < m - 1; i++) {
      sx[i] = sx[i + 1];
      sy[i] = sy[i + 1];
    }
    m--;
  }
  if (m === 0) {
    sx[0] = 0;
    sy[0] = 0;
    m = 1;
  }
  while (m < VERTICES_PER_CELL) {
    sx[m] = sx[m - 1];
    sy[m] = sy[m - 1];
    m++;
  }
}

/**
 * Build the mesh of some cells.
 *
 * `boundaryOf(id)` returns the outline of a cell as `[lat, lng]` pairs (the order h3-js uses).
 * `bounds` is the box of the cell centres from the pack header: its centre becomes the mesh origin.
 * `onYield`, when given, is awaited every 1,024 cells; it may return nothing to go straight on.
 */
export async function buildMesh(
  ids: readonly string[],
  boundaryOf: (id: string) => ArrayLike<ArrayLike<number>>,
  bounds: MeshBounds,
  onYield?: () => void | Promise<void>,
): Promise<HexMesh> {
  const n = ids.length;
  const originLat = (bounds.lat_min + bounds.lat_max) / 2;
  const originLng = (bounds.lng_min + bounds.lng_max) / 2;
  const originX = worldX(originLng);
  const originY = worldY(originLat);

  const positions = new Float32Array(n * POSITIONS_PER_CELL);
  const indices = new Uint32Array(n * INDICES_PER_CELL);
  const centers = new Float32Array(n * 2);
  const halfSizes = new Float32Array(n * 2);

  for (let c = 0; c < n; c++) {
    if (c > 0 && c % MESH_YIELD_EVERY === 0 && onYield !== undefined) await onYield();

    const outline = boundaryOf(ids[c]);
    const count = outline.length;
    for (let v = 0; v < count; v++) {
      sx[v] = worldX(outline[v][1]) - originX;
      sy[v] = worldY(outline[v][0]) - originY;
    }
    toSix(count);

    let minX = sx[0];
    let maxX = sx[0];
    let minY = sy[0];
    let maxY = sy[0];
    const p = c * POSITIONS_PER_CELL;
    for (let v = 0; v < VERTICES_PER_CELL; v++) {
      const x = sx[v];
      const y = sy[v];
      positions[p + 2 * v] = x;
      positions[p + 2 * v + 1] = y;
      if (x < minX) minX = x;
      else if (x > maxX) maxX = x;
      if (y < minY) minY = y;
      else if (y > maxY) maxY = y;
    }
    centers[2 * c] = (minX + maxX) / 2;
    centers[2 * c + 1] = (minY + maxY) / 2;
    halfSizes[2 * c] = (maxX - minX) / 2;
    halfSizes[2 * c + 1] = (maxY - minY) / 2;

    const base = c * VERTICES_PER_CELL;
    const q = c * INDICES_PER_CELL;
    for (let t = 0; t < INDICES_PER_CELL; t++) indices[q + t] = base + FAN[t];
  }

  return { n, originLat, originLng, originX, originY, positions, indices, centers, halfSizes };
}
