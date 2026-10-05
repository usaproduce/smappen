// Truck Planner map engine - viewport maths (docs/truck-planner/05_FRONTEND.md 5.1, 5.6 and 5.7).
//
// A `Viewport` says where the mesh sits on the canvas: the canvas size, the scale (CSS pixels per
// world unit of the 256-unit Mercator world) and the canvas position of the mesh origin. Both map
// hosts produce one on every camera change and the renderers draw from nothing else, which is why
// the same layer runs on the Google map and on the blank base.
//
// Also here: the camera of the blank base (it has no Google map to ask) and its pin projection.
//
// Pure: runs in Node and in the browser.

import { WORLD_SIZE, latOf, lngOf, worldX, worldY } from './mercator';

export interface Viewport {
  /** CSS px. */
  width: number;
  height: number;
  /** min(devicePixelRatio, 2). */
  dpr: number;
  /** CSS px per world unit (256-unit world). */
  scale: number;
  /** Canvas position, CSS px, of the mesh origin. */
  originX: number;
  originY: number;
}

/** Zoom range of the map: the Google map is given the same limits. */
export const MIN_ZOOM = 8;
export const MAX_ZOOM = 19;

/** Under this zoom the layer is not drawn at all: cells are far below a pixel. */
export const FADE_FROM_ZOOM = 9.0;
/** From this zoom the layer is drawn at full strength. */
export const FADE_TO_ZOOM = 10.0;
/** Under this zoom the layer reports `zoomed-out`. */
export const ZOOMED_OUT_BELOW = 9.5;

/** The device pixel ratio the renderers work at: at most 2. */
export const MAX_DPR = 2;

/** The zoom level of a scale: 2^zoom CSS pixels per world unit. */
export function zoomOf(scale: number): number {
  return Math.log2(scale);
}

/** CSS pixels per world unit at a zoom level. */
export function scaleOf(zoom: number): number {
  return Math.pow(2, zoom);
}

/** Strength of the layer by zoom: 0 below zoom 9, rising linearly to 1 at zoom 10. */
export function alphaForScale(scale: number): number {
  const t = (zoomOf(scale) - FADE_FROM_ZOOM) / (FADE_TO_ZOOM - FADE_FROM_ZOOM);
  if (!(t > 0)) return 0;
  return t > 1 ? 1 : t;
}

/** True under zoom 9.5, where the layer says "Zoom in to see the colours." */
export function isZoomedOut(scale: number): boolean {
  return zoomOf(scale) < ZOOMED_OUT_BELOW - 1e-9;
}

/** `min(devicePixelRatio, 2)`, and 1 when the browser gives nothing usable. */
export function clampDpr(devicePixelRatio: number | undefined): number {
  if (devicePixelRatio === undefined || !(devicePixelRatio > 0)) return 1;
  return devicePixelRatio > MAX_DPR ? MAX_DPR : devicePixelRatio;
}

/** A rectangle in the mesh's coordinates: world units relative to the mesh origin. */
export interface WorldRect {
  minX: number;
  minY: number;
  maxX: number;
  maxY: number;
}

/** The part of the world a viewport shows, in the mesh's coordinates. */
export function worldRect(vp: Viewport): WorldRect {
  return {
    minX: (0 - vp.originX) / vp.scale,
    minY: (0 - vp.originY) / vp.scale,
    maxX: (vp.width - vp.originX) / vp.scale,
    maxY: (vp.height - vp.originY) / vp.scale,
  };
}

/**
 * Cells whose bounding box touches a rectangle. Returns how many; with `out` (length >= n) their
 * indices are written to its start, in mesh order.
 */
export function cullCells(
  centers: Float32Array,
  halfSizes: Float32Array,
  n: number,
  rect: WorldRect,
  out: Uint32Array | null,
): number {
  let count = 0;
  const { minX, minY, maxX, maxY } = rect;
  for (let c = 0; c < n; c++) {
    const x = centers[2 * c];
    const y = centers[2 * c + 1];
    const hw = halfSizes[2 * c];
    const hh = halfSizes[2 * c + 1];
    if (x + hw < minX || x - hw > maxX || y + hh < minY || y - hh > maxY) continue;
    if (out !== null) out[count] = c;
    count++;
  }
  return count;
}

/**
 * The two uniforms of the vertex shader, as [scaleX, scaleY, offsetX, offsetY]: clip position =
 * mesh position * scale + offset. Canvas y grows downwards, clip y upwards.
 */
export function clipTransform(vp: Viewport, out: Float32Array): Float32Array {
  out[0] = (2 * vp.scale) / vp.width;
  out[1] = (-2 * vp.scale) / vp.height;
  out[2] = (2 * vp.originX) / vp.width - 1;
  out[3] = 1 - (2 * vp.originY) / vp.height;
  return out;
}

// -------------------------------------------------------------------------------------------------
// The camera of the blank base
// -------------------------------------------------------------------------------------------------

/** Where the blank base looks: the world coordinates of the centre of its view and a fractional zoom. */
export interface BlankCamera {
  x: number;
  y: number;
  zoom: number;
}

/** The map stops near 85 degrees north and south, like every Web Mercator map. */
const MAX_LAT = 85.05112878;
const MIN_WORLD_Y = worldY(MAX_LAT);
const MAX_WORLD_Y = worldY(-MAX_LAT);

/** A camera inside the zoom range and inside the world. Anything that is not a number becomes the middle. */
export function clampCamera(camera: BlankCamera): BlankCamera {
  let { x, y, zoom } = camera;
  if (!Number.isFinite(zoom)) zoom = MIN_ZOOM;
  if (zoom < MIN_ZOOM) zoom = MIN_ZOOM;
  else if (zoom > MAX_ZOOM) zoom = MAX_ZOOM;
  if (!Number.isFinite(x)) x = WORLD_SIZE / 2;
  if (x < 0) x = 0;
  else if (x > WORLD_SIZE) x = WORLD_SIZE;
  if (!Number.isFinite(y)) y = WORLD_SIZE / 2;
  if (y < MIN_WORLD_Y) y = MIN_WORLD_Y;
  else if (y > MAX_WORLD_Y) y = MAX_WORLD_Y;
  return { x, y, zoom };
}

export function cameraAt(lat: number, lng: number, zoom: number): BlankCamera {
  return clampCamera({ x: worldX(lng), y: worldY(lat), zoom });
}

export function cameraLatLng(camera: BlankCamera): { lat: number; lng: number } {
  return { lat: latOf(camera.y), lng: lngOf(camera.x) };
}

/** The viewport of a blank-base camera for a mesh whose origin is at (originWorldX, originWorldY). */
export function blankViewport(
  camera: BlankCamera,
  width: number,
  height: number,
  dpr: number,
  originWorldX: number,
  originWorldY: number,
): Viewport {
  const scale = scaleOf(camera.zoom);
  return {
    width,
    height,
    dpr,
    scale,
    originX: width / 2 + (originWorldX - camera.x) * scale,
    originY: height / 2 + (originWorldY - camera.y) * scale,
  };
}

/** Where a place is on the blank base, in CSS pixels from the top left of the view: the pin projection. */
export function projectLatLng(
  camera: BlankCamera,
  width: number,
  height: number,
  lat: number,
  lng: number,
): { x: number; y: number } {
  const scale = scaleOf(camera.zoom);
  return {
    x: width / 2 + (worldX(lng) - camera.x) * scale,
    y: height / 2 + (worldY(lat) - camera.y) * scale,
  };
}

/** The place under a pixel of the blank base: the inverse of projectLatLng. */
export function unprojectPoint(
  camera: BlankCamera,
  width: number,
  height: number,
  px: number,
  py: number,
): { lat: number; lng: number } {
  const scale = scaleOf(camera.zoom);
  return {
    lat: latOf(camera.y + (py - height / 2) / scale),
    lng: lngOf(camera.x + (px - width / 2) / scale),
  };
}

/** The camera after a drag: the view moves with the pointer, so the centre moves the other way. */
export function panByPixels(camera: BlankCamera, dx: number, dy: number): BlankCamera {
  const scale = scaleOf(camera.zoom);
  return clampCamera({ x: camera.x - dx / scale, y: camera.y - dy / scale, zoom: camera.zoom });
}

/** The camera at another zoom with the place under a pixel staying under that pixel. */
export function zoomAbout(
  camera: BlankCamera,
  width: number,
  height: number,
  px: number,
  py: number,
  zoom: number,
): BlankCamera {
  let z = zoom;
  if (!Number.isFinite(z)) z = camera.zoom;
  if (z < MIN_ZOOM) z = MIN_ZOOM;
  else if (z > MAX_ZOOM) z = MAX_ZOOM;
  const before = scaleOf(camera.zoom);
  const after = scaleOf(z);
  const ax = camera.x + (px - width / 2) / before;
  const ay = camera.y + (py - height / 2) / before;
  return clampCamera({ x: ax - (px - width / 2) / after, y: ay - (py - height / 2) / after, zoom: z });
}

/**
 * The lines of the blank base's grid along one axis: 64 px apart at whole zoom levels (a quarter of
 * a 256 px map tile), anchored to the world so the grid moves and grows with the camera. Returns the
 * first line's position and the spacing, both in CSS pixels.
 */
export function gridLines(cameraCoord: number, zoom: number, size: number): { first: number; step: number } {
  const whole = Math.floor(zoom);
  const step = 64 * Math.pow(2, zoom - whole);
  // World units between lines at the whole zoom level under this one.
  const worldStep = 64 / Math.pow(2, whole);
  const scale = scaleOf(zoom);
  const leftWorld = cameraCoord - size / 2 / scale;
  const k = Math.ceil(leftWorld / worldStep);
  return { first: (k * worldStep - leftWorld) * scale, step };
}
