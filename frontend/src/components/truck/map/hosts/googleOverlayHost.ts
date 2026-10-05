// Truck Planner map engine - the Google host (docs/truck-planner/05_FRONTEND.md 5.6).
//
// Our canvas sits inside a plain google.maps.OverlayView on the existing raster map: in `mapPane`,
// so Google's own shapes and its attribution stay above it. The pin layer sits in
// `overlayMouseTarget`. On every `draw()` the canvas is moved back over the map container (the panes
// are translated while the map is dragged) and the viewport is read from the overlay's projection.
//
// Measured on Maps 3.66 (raster): `draw()` runs on every frame of a drag, a wheel zoom, `panBy` and
// `fitBounds`, and the projection reports the animated scale, while `map.getZoom()` jumps to the
// target at once. Because the per-frame `draw()` is observed rather than documented, the host also
// redraws on `bounds_changed`, `zoom_changed`, `idle` and when the container changes size.
//
// Pointer events come from the map, never from the canvas (it takes none).

import { clampDpr } from '../../../../utils/truck/map/viewport';
import type { MapCamera, MapHost, MapPointerEvent, Viewport } from '../types';

type PointerKind = 'move' | 'click' | 'leave';

/** Google fires the map's click within a millisecond of the DOM click: a click this soon after one on a pin is that click. */
const PIN_CLICK_GUARD_MS = 80;
/** A pointer that comes up further than this from where it went down has dragged. */
const CLICK_SLOP_PX = 4;

/**
 * A host on a Google map that is already constructed. `startCamera` answers `getCamera` until the
 * map can. The overlay class is made here, after the API has loaded: `class extends
 * google.maps.OverlayView` cannot be evaluated when this module loads.
 */
export function createGoogleOverlayHost(map: google.maps.Map, startCamera: MapCamera): MapHost {
  const listeners: Record<PointerKind, Set<(e: MapPointerEvent) => void>> = {
    move: new Set(),
    click: new Set(),
    leave: new Set(),
  };

  let canvas: HTMLCanvasElement | null = null;
  let pinLayer: HTMLElement | null = null;
  let onViewport: ((vp: Viewport) => void) | null = null;
  let overlay: google.maps.OverlayView | null = null;
  let mapListeners: google.maps.MapsEventListener[] = [];
  let resizeObserver: ResizeObserver | null = null;
  let origin: google.maps.LatLng = new google.maps.LatLng(startCamera.lat, startCamera.lng);
  let width = 0;
  let height = 0;
  const containerCorner = new google.maps.Point(0, 0);

  function measure(): void {
    const div = map.getDiv();
    width = div.clientWidth;
    height = div.clientHeight;
  }

  function emit(kind: PointerKind, e: MapPointerEvent): void {
    listeners[kind].forEach((cb) => cb(e));
  }

  /** True when a DOM event of the map started on a pin: such an event is the pin's, not the map's. */
  function fromPin(domEvent: unknown): boolean {
    const target = (domEvent as { target?: unknown } | null | undefined)?.target;
    return pinLayer !== null && target instanceof Node && pinLayer.contains(target);
  }

  // The second line behind preventMapHitsFrom. The `click` Google hands to map listeners carries an
  // event without a target, so where a click started is remembered here: the last press went down on
  // a pin, or a click event has just passed through the pin layer (a pin activated from the keyboard
  // has no press at all).
  let pressOnPin = false;
  let pinClickAt = -1e9;
  let pressX = 0;
  let pressY = 0;
  let pressDragged = false;

  function onPress(e: PointerEvent): void {
    pressOnPin = pinLayer !== null && e.target instanceof Node && pinLayer.contains(e.target);
    pressX = e.clientX;
    pressY = e.clientY;
    pressDragged = false;
  }

  function onRelease(e: PointerEvent): void {
    if (Math.hypot(e.clientX - pressX, e.clientY - pressY) > CLICK_SLOP_PX) pressDragged = true;
  }

  function onPinClick(e: Event): void {
    // The map can be dragged by a pin, and the pin travels with the pointer: the release then lands on
    // the same pin and the browser calls that a click. It was a drag: the pin must not open.
    // (A click from the keyboard has detail 0 and no press: it always goes through.)
    if (pressOnPin && pressDragged && (e as MouseEvent).detail !== 0) {
      e.stopPropagation();
      e.preventDefault();
      return;
    }
    pinClickAt = performance.now();
  }

  function fromPinGesture(): boolean {
    return pressOnPin || performance.now() - pinClickAt < PIN_CLICK_GUARD_MS;
  }

  function pointerOf(e: google.maps.MapMouseEvent): MapPointerEvent | null {
    if (!e.latLng) return null;
    const dom = e.domEvent as { clientX?: unknown; clientY?: unknown; changedTouches?: ArrayLike<{ clientX: number; clientY: number }> } | undefined;
    let clientX = 0;
    let clientY = 0;
    if (dom !== undefined && typeof dom.clientX === 'number' && typeof dom.clientY === 'number') {
      clientX = dom.clientX;
      clientY = dom.clientY;
    } else if (dom !== undefined && dom.changedTouches !== undefined && dom.changedTouches.length > 0) {
      clientX = dom.changedTouches[0].clientX;
      clientY = dom.changedTouches[0].clientY;
    }
    return { lat: e.latLng.lat(), lng: e.latLng.lng(), clientX, clientY };
  }

  function drawOverlay(view: google.maps.OverlayView): void {
    if (view !== overlay || canvas === null || onViewport === null) return;
    const p = view.getProjection();
    if (!p) return;
    const nw = p.fromContainerPixelToLatLng(containerCorner);
    if (!nw) return;
    const d = p.fromLatLngToDivPixel(nw);
    const o = p.fromLatLngToDivPixel(origin);
    if (!d || !o) return;
    if (width <= 0 || height <= 0) measure();
    if (width <= 0 || height <= 0) return;
    // Whole pixels, so the browser never resamples the canvas; the remainder goes into the origin.
    const lx = Math.round(d.x);
    const ly = Math.round(d.y);
    canvas.style.left = lx + 'px';
    canvas.style.top = ly + 'px';
    // Never map.getZoom(): it jumps to the target during an animated zoom.
    const scale = p.getWorldWidth() / 256;
    onViewport({
      width,
      height,
      dpr: clampDpr(window.devicePixelRatio),
      scale,
      originX: o.x - lx,
      originY: o.y - ly,
    });
  }

  function redraw(): void {
    if (overlay !== null) drawOverlay(overlay);
  }

  function makeOverlay(layerCanvas: HTMLCanvasElement, pins: HTMLElement): google.maps.OverlayView {
    class HexOverlay extends google.maps.OverlayView {
      onAdd(): void {
        const panes = this.getPanes();
        if (!panes) return;
        panes.mapPane.appendChild(layerCanvas);
        panes.overlayMouseTarget.appendChild(pins);
        // A click or tap on a pin is the pin's: it must not also be a click on the map. Without this
        // Google also moves the keyboard focus from the clicked pin to the map (measured on 3.66).
        try {
          google.maps.OverlayView.preventMapHitsFrom(pins);
        } catch {
          // the host's own guard (fromPinGesture) still keeps pin clicks away from the page
        }
      }

      draw(): void {
        drawOverlay(this);
      }

      onRemove(): void {
        layerCanvas.remove();
        // The pin layer is handed on when the host is attached again: only the overlay that still
        // holds it may take it out.
        if (overlay === null || overlay === this) pins.remove();
      }
    }
    return new HexOverlay();
  }

  function detach(): void {
    for (const l of mapListeners) {
      try {
        l.remove();
      } catch {
        // the map may already be gone (an authentication failure tears it down)
      }
    }
    mapListeners = [];
    try {
      map.getDiv().removeEventListener('pointerdown', onPress, true);
      map.getDiv().removeEventListener('pointerup', onRelease, true);
    } catch {
      // as above
    }
    if (pinLayer !== null) pinLayer.removeEventListener('click', onPinClick, true);
    pressOnPin = false;
    if (resizeObserver !== null) {
      resizeObserver.disconnect();
      resizeObserver = null;
    }
    const old = overlay;
    overlay = null;
    if (old !== null) {
      try {
        old.setMap(null);
      } catch {
        // as above
      }
    }
    // onRemove is not called when Google has already thrown the overlay away: take the elements out here.
    if (canvas !== null) canvas.remove();
    if (pinLayer !== null) pinLayer.remove();
    canvas = null;
    pinLayer = null;
    onViewport = null;
  }

  return {
    kind: 'google',

    attach(layerCanvas: HTMLCanvasElement, pins: HTMLElement, viewportCallback: (vp: Viewport) => void): void {
      if (overlay !== null) detach();
      canvas = layerCanvas;
      pinLayer = pins;
      onViewport = viewportCallback;
      measure();

      overlay = makeOverlay(layerCanvas, pins);
      overlay.setMap(map);

      mapListeners = [
        map.addListener('mousemove', (e: google.maps.MapMouseEvent) => {
          if (fromPin(e.domEvent)) return;
          const at = pointerOf(e);
          if (at !== null) emit('move', at);
        }),
        map.addListener('click', (e: google.maps.MapMouseEvent) => {
          if (fromPin(e.domEvent) || fromPinGesture()) return;
          const at = pointerOf(e);
          if (at !== null) emit('click', at);
        }),
        map.addListener('mouseout', () => emit('leave', { lat: 0, lng: 0, clientX: 0, clientY: 0 })),
        map.addListener('bounds_changed', redraw),
        map.addListener('zoom_changed', redraw),
        map.addListener('idle', redraw),
      ];

      // Capture phase: before Google's own handlers and before anything can stop the event.
      map.getDiv().addEventListener('pointerdown', onPress, true);
      map.getDiv().addEventListener('pointerup', onRelease, true);
      pins.addEventListener('click', onPinClick, true);

      if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => {
          measure();
          redraw();
        });
        resizeObserver.observe(map.getDiv());
      }
    },

    setOrigin(lat: number, lng: number): void {
      origin = new google.maps.LatLng(lat, lng);
      redraw();
    },

    project(lat: number, lng: number): { x: number; y: number } | null {
      if (overlay === null) return null;
      const p = overlay.getProjection();
      if (!p) return null;
      const at = p.fromLatLngToDivPixel(new google.maps.LatLng(lat, lng));
      return at ? { x: at.x, y: at.y } : null;
    },

    on(event: PointerKind, cb: (e: MapPointerEvent) => void): () => void {
      listeners[event].add(cb);
      return () => {
        listeners[event].delete(cb);
      };
    },

    getCamera(): MapCamera {
      const center = map.getCenter();
      const zoom = map.getZoom();
      return {
        lat: center ? center.lat() : startCamera.lat,
        lng: center ? center.lng() : startCamera.lng,
        zoom: typeof zoom === 'number' ? zoom : startCamera.zoom,
      };
    },

    setCamera(c: { lat: number; lng: number; zoom?: number }): void {
      if (c.zoom !== undefined && Number.isFinite(c.zoom) && c.zoom !== map.getZoom()) map.setZoom(c.zoom);
      if (Number.isFinite(c.lat) && Number.isFinite(c.lng)) map.panTo({ lat: c.lat, lng: c.lng });
    },

    detach,
  };
}
