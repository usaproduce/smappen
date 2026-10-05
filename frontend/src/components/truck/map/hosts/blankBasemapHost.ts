// Truck Planner map engine - the blank host (docs/truck-planner/05_FRONTEND.md 5.6).
//
// The same layer over a neutral grid, for when Google is unavailable (the script did not load, or
// Google refused the key), and for tests: it needs no network. It keeps its own camera (the centre
// in world coordinates and a fractional zoom from 8 to 19) and implements the gestures a map needs:
// drag to pan, wheel and two-finger pinch to zoom about the pointer, arrow keys and plus and minus.
// It emits the same `move`, `click` and `leave` events and the same `Viewport` as the Google host.
//
// Structure inside the root element, bottom to top: the grid (a 2D canvas), the hexagon canvas, the
// pin layer.

import {
  blankViewport,
  cameraAt,
  cameraLatLng,
  clampDpr,
  gridLines,
  panByPixels,
  projectLatLng,
  unprojectPoint,
  zoomAbout,
} from '../../../../utils/truck/map/viewport';
import type { BlankCamera } from '../../../../utils/truck/map/viewport';
import { worldX, worldY } from '../../../../utils/truck/map/mercator';
import type { MapCamera, MapHost, MapPointerEvent, Viewport } from '../types';

type PointerKind = 'move' | 'click' | 'leave';

/** A pointer that comes up within this many pixels of where it went down is a click. */
const CLICK_SLOP_PX = 4;
/** Pixels an arrow key moves the map. */
const KEY_PAN_PX = 80;
/** Zoom levels per pixel of wheel movement: a notch of an ordinary wheel is about half a level. */
const WHEEL_ZOOM_PER_PX = 0.005;
/** The same for the pinch gesture of a trackpad, which arrives as a wheel with the control key. */
const PINCH_WHEEL_ZOOM_PER_PX = 0.02;

interface Point {
  x: number;
  y: number;
}

interface Drag {
  id: number;
  startX: number;
  startY: number;
  lastX: number;
  lastY: number;
  moved: boolean;
}

/** A host inside `root`, an element the caller has sized and made focusable. */
export function createBlankBasemapHost(root: HTMLElement, startCamera: MapCamera): MapHost {
  const listeners: Record<PointerKind, Set<(e: MapPointerEvent) => void>> = {
    move: new Set(),
    click: new Set(),
    leave: new Set(),
  };

  let camera: BlankCamera = cameraAt(startCamera.lat, startCamera.lng, startCamera.zoom);
  let originX = camera.x;
  let originY = camera.y;
  let width = 0;
  let height = 0;

  let grid: HTMLCanvasElement | null = null;
  let canvas: HTMLCanvasElement | null = null;
  let pinLayer: HTMLElement | null = null;
  let onViewport: ((vp: Viewport) => void) | null = null;
  let resizeObserver: ResizeObserver | null = null;
  let themeObserver: MutationObserver | null = null;
  let frame = 0;
  let background = '';
  let line = '';

  const pointers = new Map<number, Point>();
  let drag: Drag | null = null;
  let pinch: { dist: number; midX: number; midY: number } | null = null;
  let cursorBeforeDrag: string | null = null;

  function emit(kind: PointerKind, e: MapPointerEvent): void {
    listeners[kind].forEach((cb) => cb(e));
  }

  function readColors(): void {
    const style = getComputedStyle(root);
    background = style.getPropertyValue('--bg-panel').trim();
    line = style.getPropertyValue('--line-soft').trim();
  }

  function measure(): void {
    width = root.clientWidth;
    height = root.clientHeight;
  }

  function drawGrid(dpr: number): void {
    if (grid === null) return;
    const w = Math.max(1, Math.round(width * dpr));
    const h = Math.max(1, Math.round(height * dpr));
    if (grid.width !== w) grid.width = w;
    if (grid.height !== h) grid.height = h;
    const g = grid.getContext('2d');
    if (g === null) return;
    g.setTransform(dpr, 0, 0, dpr, 0, 0);
    if (background !== '') {
      g.fillStyle = background;
      g.fillRect(0, 0, width, height);
    } else {
      g.clearRect(0, 0, width, height);
    }
    if (line === '') return;
    g.strokeStyle = line;
    g.lineWidth = 1;
    g.beginPath();
    const gx = gridLines(camera.x, camera.zoom, width);
    for (let x = gx.first; x < width; x += gx.step) {
      const px = Math.round(x) + 0.5;
      g.moveTo(px, 0);
      g.lineTo(px, height);
    }
    const gy = gridLines(camera.y, camera.zoom, height);
    for (let y = gy.first; y < height; y += gy.step) {
      const py = Math.round(y) + 0.5;
      g.moveTo(0, py);
      g.lineTo(width, py);
    }
    g.stroke();
  }

  /** Draw the grid and report the viewport: both in the same task, so base and layer move as one. */
  function flush(): void {
    if (frame !== 0) {
      cancelAnimationFrame(frame);
      frame = 0;
    }
    if (onViewport === null || width <= 0 || height <= 0) return;
    const dpr = clampDpr(window.devicePixelRatio);
    drawGrid(dpr);
    onViewport(blankViewport(camera, width, height, dpr, originX, originY));
  }

  function schedule(): void {
    if (frame === 0 && onViewport !== null) frame = requestAnimationFrame(flush);
  }

  function local(e: { clientX: number; clientY: number }): Point {
    const r = root.getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
  }

  function pointerEvent(at: Point, e: { clientX: number; clientY: number }): MapPointerEvent {
    const place = unprojectPoint(camera, width, height, at.x, at.y);
    return { lat: place.lat, lng: place.lng, clientX: e.clientX, clientY: e.clientY };
  }

  function onPin(target: EventTarget | null): boolean {
    return pinLayer !== null && target instanceof Node && pinLayer.contains(target);
  }

  function pinchNow(): { dist: number; midX: number; midY: number } | null {
    if (pointers.size < 2) return null;
    const it = pointers.values();
    const a = it.next().value as Point;
    const b = it.next().value as Point;
    return { dist: Math.hypot(a.x - b.x, a.y - b.y), midX: (a.x + b.x) / 2, midY: (a.y + b.y) / 2 };
  }

  function endDragCursor(): void {
    if (cursorBeforeDrag !== null) {
      root.style.cursor = cursorBeforeDrag;
      cursorBeforeDrag = null;
    }
  }

  function onPointerDown(e: PointerEvent): void {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    // A press on a pin is the pin's.
    if (onPin(e.target)) return;
    const at = local(e);
    pointers.set(e.pointerId, at);
    try {
      root.setPointerCapture(e.pointerId);
    } catch {
      // a pointer that is already gone cannot be captured
    }
    if (pointers.size === 1) {
      drag = { id: e.pointerId, startX: at.x, startY: at.y, lastX: at.x, lastY: at.y, moved: false };
    } else {
      if (drag !== null) drag.moved = true;
      pinch = pinchNow();
    }
  }

  function onPointerMove(e: PointerEvent): void {
    const at = local(e);
    if (!pointers.has(e.pointerId)) {
      // Not pressed: a hover. Fingers do not hover.
      if (e.pointerType === 'touch') return;
      if (onPin(e.target)) emit('leave', pointerEvent(at, e));
      else if (width > 0 && height > 0) emit('move', pointerEvent(at, e));
      return;
    }
    pointers.set(e.pointerId, at);

    if (pointers.size >= 2) {
      const next = pinchNow();
      if (next !== null && pinch !== null) {
        // The place under the old midpoint goes to the new midpoint, then the zoom changes about it.
        camera = panByPixels(camera, next.midX - pinch.midX, next.midY - pinch.midY);
        if (next.dist > 0 && pinch.dist > 0) {
          camera = zoomAbout(camera, width, height, next.midX, next.midY, camera.zoom + Math.log2(next.dist / pinch.dist));
        }
        schedule();
      }
      pinch = next;
      return;
    }

    if (drag !== null && drag.id === e.pointerId) {
      if (!drag.moved && Math.hypot(at.x - drag.startX, at.y - drag.startY) > CLICK_SLOP_PX) {
        drag.moved = true;
        cursorBeforeDrag = root.style.cursor;
        root.style.cursor = 'grabbing';
        emit('leave', pointerEvent(at, e));
      }
      if (drag.moved) {
        camera = panByPixels(camera, at.x - drag.lastX, at.y - drag.lastY);
        drag.lastX = at.x;
        drag.lastY = at.y;
        schedule();
      }
    }
  }

  function release(e: PointerEvent, click: boolean): void {
    if (!pointers.has(e.pointerId)) return;
    const at = local(e);
    pointers.delete(e.pointerId);
    try {
      root.releasePointerCapture(e.pointerId);
    } catch {
      // already released
    }
    if (drag !== null && drag.id === e.pointerId) {
      const wasClick = click && !drag.moved && pointers.size === 0;
      drag = null;
      endDragCursor();
      if (wasClick && width > 0 && height > 0) emit('click', pointerEvent(at, e));
    }
    if (pointers.size < 2) pinch = null;
    if (pointers.size === 1 && drag === null) {
      // One finger of a pinch stays down: it goes on dragging, and its release is not a click.
      const [id, p] = pointers.entries().next().value as [number, Point];
      drag = { id, startX: p.x, startY: p.y, lastX: p.x, lastY: p.y, moved: true };
    }
  }

  function onPointerUp(e: PointerEvent): void {
    release(e, true);
  }

  function onPointerCancel(e: PointerEvent): void {
    release(e, false);
  }

  function onPointerLeave(e: PointerEvent): void {
    if (pointers.size === 0) emit('leave', { lat: 0, lng: 0, clientX: e.clientX, clientY: e.clientY });
  }

  function onWheel(e: WheelEvent): void {
    e.preventDefault();
    if (width <= 0 || height <= 0) return;
    let delta = e.deltaY;
    if (e.deltaMode === 1) delta *= 16;
    else if (e.deltaMode === 2) delta *= height;
    let dz = -delta * (e.ctrlKey ? PINCH_WHEEL_ZOOM_PER_PX : WHEEL_ZOOM_PER_PX);
    if (dz > 1) dz = 1;
    else if (dz < -1) dz = -1;
    if (dz === 0) return;
    const at = local(e);
    camera = zoomAbout(camera, width, height, at.x, at.y, camera.zoom + dz);
    schedule();
  }

  function onKeyDown(e: KeyboardEvent): void {
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    switch (e.key) {
      case 'ArrowLeft':
        camera = panByPixels(camera, KEY_PAN_PX, 0);
        break;
      case 'ArrowRight':
        camera = panByPixels(camera, -KEY_PAN_PX, 0);
        break;
      case 'ArrowUp':
        camera = panByPixels(camera, 0, KEY_PAN_PX);
        break;
      case 'ArrowDown':
        camera = panByPixels(camera, 0, -KEY_PAN_PX);
        break;
      case '+':
      case '=':
        camera = zoomAbout(camera, width, height, width / 2, height / 2, Math.floor(camera.zoom + 1e-9) + 1);
        break;
      case '-':
      case '_':
        camera = zoomAbout(camera, width, height, width / 2, height / 2, Math.ceil(camera.zoom - 1e-9) - 1);
        break;
      default:
        return;
    }
    // The map took the key: the page's own shortcuts (the hour control) must not act on it as well.
    e.preventDefault();
    e.stopPropagation();
    schedule();
  }

  function detach(): void {
    root.removeEventListener('pointerdown', onPointerDown);
    root.removeEventListener('pointermove', onPointerMove);
    root.removeEventListener('pointerup', onPointerUp);
    root.removeEventListener('pointercancel', onPointerCancel);
    root.removeEventListener('pointerleave', onPointerLeave);
    root.removeEventListener('wheel', onWheel);
    root.removeEventListener('keydown', onKeyDown);
    if (resizeObserver !== null) {
      resizeObserver.disconnect();
      resizeObserver = null;
    }
    if (themeObserver !== null) {
      themeObserver.disconnect();
      themeObserver = null;
    }
    if (frame !== 0) {
      cancelAnimationFrame(frame);
      frame = 0;
    }
    endDragCursor();
    pointers.clear();
    drag = null;
    pinch = null;
    if (grid !== null) grid.remove();
    if (canvas !== null) canvas.remove();
    if (pinLayer !== null) pinLayer.remove();
    grid = null;
    canvas = null;
    pinLayer = null;
    onViewport = null;
  }

  return {
    kind: 'blank',

    attach(layerCanvas: HTMLCanvasElement, pins: HTMLElement, viewportCallback: (vp: Viewport) => void): void {
      if (onViewport !== null) detach();
      canvas = layerCanvas;
      pinLayer = pins;
      onViewport = viewportCallback;

      grid = document.createElement('canvas');
      grid.setAttribute('aria-hidden', 'true');
      grid.setAttribute('data-tp-grid', '');
      grid.style.cssText = 'position:absolute;left:0;top:0;width:100%;height:100%;pointer-events:none;';
      root.appendChild(grid);
      layerCanvas.style.left = '0px';
      layerCanvas.style.top = '0px';
      root.appendChild(layerCanvas);
      root.appendChild(pins);

      readColors();
      measure();

      root.addEventListener('pointerdown', onPointerDown);
      root.addEventListener('pointermove', onPointerMove);
      root.addEventListener('pointerup', onPointerUp);
      root.addEventListener('pointercancel', onPointerCancel);
      root.addEventListener('pointerleave', onPointerLeave);
      root.addEventListener('wheel', onWheel, { passive: false });
      root.addEventListener('keydown', onKeyDown);

      if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => {
          measure();
          flush();
        });
        resizeObserver.observe(root);
      }
      if (typeof MutationObserver !== 'undefined') {
        // The grid is painted from CSS variables, which change with the theme.
        themeObserver = new MutationObserver(() => {
          readColors();
          schedule();
        });
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
      }
      flush();
    },

    setOrigin(lat: number, lng: number): void {
      originX = worldX(lng);
      originY = worldY(lat);
      // At once, not on the next frame: the layer draws its first frame from this viewport.
      flush();
    },

    project(lat: number, lng: number): { x: number; y: number } | null {
      if (width <= 0 || height <= 0) return null;
      return projectLatLng(camera, width, height, lat, lng);
    },

    on(event: PointerKind, cb: (e: MapPointerEvent) => void): () => void {
      listeners[event].add(cb);
      return () => {
        listeners[event].delete(cb);
      };
    },

    getCamera(): MapCamera {
      const at = cameraLatLng(camera);
      return { lat: at.lat, lng: at.lng, zoom: camera.zoom };
    },

    setCamera(c: { lat: number; lng: number; zoom?: number }): void {
      if (!Number.isFinite(c.lat) || !Number.isFinite(c.lng)) return;
      const zoom = c.zoom !== undefined && Number.isFinite(c.zoom) ? c.zoom : camera.zoom;
      camera = cameraAt(c.lat, c.lng, zoom);
      schedule();
    },

    detach,
  };
}
