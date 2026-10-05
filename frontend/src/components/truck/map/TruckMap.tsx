import { forwardRef, useCallback, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react';
import { GoogleMap } from '@react-google-maps/api';
import { Minus, Plus, TriangleAlert } from 'lucide-react';
import ErrorBoundary from '../../ErrorBoundary';
import { usageApi } from '../../../api/usage';
import { SMAPPEN_MAP_STYLE_DARK, SMAPPEN_MAP_STYLE_MONO } from '../../../utils/mapStyle';
import { useTruckHourStore } from '../../../stores/truckHourStore';
import { useTruckUiStore } from '../../../stores/truckUiStore';
import { MAX_ZOOM, MIN_ZOOM } from '../../../utils/truck/map/viewport';
import { useTruckMapsLoader } from '../data/useMapsLoader';
import { useThemeName } from '../ui/useThemeName';
import { cellOutline, createHexLayer, prepareMesh } from './HexLayer';
import type { HexLayerEngine } from './HexLayer';
import { MapPinContext } from './MapPin';
import type { PinEntry, PinRegistry } from './MapPin';
import PerfHud from './PerfHud';
import { createBlankBasemapHost } from './hosts/blankBasemapHost';
import { createGoogleOverlayHost } from './hosts/googleOverlayHost';
import type { MapCamera, MapHit, MapHost, MapPointerEvent, TruckMapHandle, TruckMapProps } from './types';

/**
 * The React surface of the map engine (docs/truck-planner/05_FRONTEND.md 5.1 and 5.6).
 *
 * It loads Google through the one shared loader, picks the host (the Google map, or the blank base
 * when Google is unavailable or `?tp_basemap=blank` asks for it), mounts the hexagon layer on it and
 * subscribes the layer to the hour store itself, so a tick of the hour control reaches the pixels
 * without a React render. Hover, click and the camera at rest are forwarded to the page; pins are the
 * page's `MapPin` children, portalled into the host's pin layer.
 *
 * The component fills its positioned parent.
 */

const TEXT = {
  loading: 'Loading map...',
  googleFailed: 'The Google map could not load, so the background map is hidden. Estimates and saved spots still work.',
  zoomIn: 'Zoom in',
  zoomOut: 'Zoom out',
  blankLabel: 'Background grid. Arrow keys move it, plus and minus zoom.',
} as const;

const CONTAINER_STYLE = { width: '100%', height: '100%' } as const;
const SVG_NS = 'http://www.w3.org/2000/svg';
/** The camera is "at rest" when the viewport has not changed for this long. */
const CAMERA_REST_MS = 250;
/** How long after the Google map mounts its container is checked for Google's error element. */
const AUTH_CHECK_MS = 1500;
/** For this long after a development-mode map mounts, its "Do you own this website?" dialog is looked for. */
const DEV_DIALOG_MS = 15000;

function urlSwitch(name: string, value: string): boolean {
  try {
    return new URLSearchParams(window.location.search).get(name) === value;
  } catch {
    return false;
  }
}

function usableCamera(camera: MapCamera, fallback: { lat: number; lng: number } | null): MapCamera {
  const lat = Number.isFinite(camera.lat) && Math.abs(camera.lat) <= 85 ? camera.lat : fallback !== null ? fallback.lat : 38.9072;
  const lng = Number.isFinite(camera.lng) && Math.abs(camera.lng) <= 180 ? camera.lng : fallback !== null ? fallback.lng : -77.0369;
  let zoom = Number.isFinite(camera.zoom) ? camera.zoom : 12;
  if (zoom < MIN_ZOOM) zoom = MIN_ZOOM;
  else if (zoom > MAX_ZOOM) zoom = MAX_ZOOM;
  return { lat, lng, zoom };
}

/** The element pins are portalled into: a point, so only the pins themselves take pointer events. */
function makePinLayer(): HTMLDivElement {
  const el = document.createElement('div');
  el.setAttribute('data-tp-pins', '');
  el.style.cssText = 'position:absolute;left:0;top:0;width:0;height:0;pointer-events:none;';
  return el;
}

/** The outline of the hovered hexagon: one SVG polygon in the pin layer, under the pins. */
function makeOutline(): { svg: SVGSVGElement; polygon: SVGPolygonElement } {
  const svg = document.createElementNS(SVG_NS, 'svg');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('data-tp-outline', '');
  svg.style.cssText = 'position:absolute;left:0;top:0;width:1px;height:1px;overflow:visible;pointer-events:none;z-index:0;display:none;';
  const polygon = document.createElementNS(SVG_NS, 'polygon');
  polygon.setAttribute('fill', 'none');
  polygon.setAttribute('stroke-width', '2');
  polygon.setAttribute('stroke-linejoin', 'round');
  polygon.style.stroke = 'var(--ink)';
  svg.appendChild(polygon);
  return { svg, polygon };
}

type PlacedPin = PinEntry & { px?: number; py?: number; shown?: boolean };

const TruckMapInner = forwardRef<TruckMapHandle, TruckMapProps>(function TruckMapInner(props, ref) {
  const { region, initialCamera, layer, pack, packState, inputs, forceBlank, cursor, onHover, onClick, onCamera, onStatus, children } = props;
  const hostNotice = props.hostNotice !== false;

  const { isLoaded, loadError } = useTruckMapsLoader();
  const authFailed = useTruckUiStore((s) => s.mapsAuthFailed);
  const theme = useThemeName();

  // The two test and measurement switches of the URL, read once per mount.
  const [blankSwitch] = useState(() => urlSwitch('tp_basemap', 'blank'));
  const [perfOn] = useState(() => urlSwitch('tp_perf', '1'));
  const [mountedAt] = useState(() => performance.now());

  const googleFailed = Boolean(loadError) || authFailed;
  const mode: 'google' | 'blank' | 'loading' = forceBlank || blankSwitch || googleFailed ? 'blank' : isLoaded ? 'google' : 'loading';

  // Where the map starts. `center` of <GoogleMap> must be a stable reference or every render recentres.
  const [startCamera] = useState(() => usableCamera(initialCamera, region !== null ? region.center : null));
  const [startCenter] = useState(() => ({ lat: startCamera.lat, lng: startCamera.lng }));
  /** The last camera known: what a host starts from when it takes over. */
  const cameraRef = useRef<MapCamera>(startCamera);

  const [map, setMap] = useState<google.maps.Map | null>(null);
  const [blankRoot, setBlankRoot] = useState<HTMLDivElement | null>(null);
  const [pinLayer] = useState(makePinLayer);

  const hostRef = useRef<MapHost | null>(null);
  const layerRef = useRef<HexLayerEngine | null>(null);
  const pins = useRef(new Set<PlacedPin>());
  const refreshHoverRef = useRef<(() => void) | null>(null);

  // The latest props, for the imperative code below: it must never be rebuilt because a callback changed.
  const callbacks = useRef({ onHover, onClick, onCamera, onStatus });
  callbacks.current = { onHover, onClick, onCamera, onStatus };
  const live = useRef({ region, layer, pack, packState, inputs, theme });
  live.current = { region, layer, pack, packState, inputs, theme };

  const placePin = useCallback((entry: PlacedPin) => {
    const host = hostRef.current;
    let at: { x: number; y: number } | null = null;
    if (host !== null) {
      try {
        at = host.project(entry.lat, entry.lng);
      } catch {
        at = null;
      }
    }
    const el = entry.el;
    if (at === null || !Number.isFinite(at.x) || !Number.isFinite(at.y)) {
      if (entry.shown !== false) {
        el.style.visibility = 'hidden';
        entry.shown = false;
      }
      return;
    }
    // Whole pixels: a pin on half a pixel is drawn soft.
    const x = Math.round(at.x);
    const y = Math.round(at.y);
    if (entry.px !== x || entry.py !== y) {
      el.style.transform = 'translate(' + x + 'px,' + y + 'px)';
      entry.px = x;
      entry.py = y;
    }
    if (entry.shown !== true) {
      el.style.visibility = 'visible';
      entry.shown = true;
    }
  }, []);

  const registry = useMemo<PinRegistry>(
    () => ({
      layer: pinLayer,
      add(entry: PinEntry) {
        const placed: PlacedPin = entry;
        pins.current.add(placed);
        placePin(placed);
        return () => {
          pins.current.delete(placed);
        };
      },
      place(entry: PinEntry) {
        placePin(entry);
      },
    }),
    [pinLayer, placePin],
  );

  // ---- host and layer ----------------------------------------------------------------------------

  useEffect(() => {
    const target = mode === 'google' ? map : mode === 'blank' ? blankRoot : null;
    if (target === null) return undefined;

    let host: MapHost;
    try {
      host =
        mode === 'google'
          ? createGoogleOverlayHost(target as google.maps.Map, cameraRef.current)
          : createBlankBasemapHost(target as HTMLDivElement, cameraRef.current);
    } catch (e) {
      console.warn('[truck-map]', e);
      return undefined;
    }
    hostRef.current = host;

    const outline = makeOutline();
    pinLayer.appendChild(outline.svg);
    let hovered: { index: number; points: number[][] } | null = null;
    let lastPointer: MapPointerEvent | null = null;
    let lastByte = -1;
    let pendingMove: MapPointerEvent | null = null;
    let hoverFrame = 0;
    let restTimer = 0;

    const tellHover = (hit: MapHit | null): void => {
      try {
        callbacks.current.onHover?.(hit);
      } catch (e) {
        console.warn('[truck-map]', e);
      }
    };

    const placeOutline = (): void => {
      if (hovered === null) return;
      let points = '';
      for (let i = 0; i < hovered.points.length; i++) {
        const at = host.project(hovered.points[i][0], hovered.points[i][1]);
        if (at === null) {
          outline.svg.style.display = 'none';
          return;
        }
        points += (i === 0 ? '' : ' ') + at.x.toFixed(1) + ',' + at.y.toFixed(1);
      }
      if (points === '') {
        outline.svg.style.display = 'none';
        return;
      }
      outline.polygon.setAttribute('points', points);
      outline.svg.style.display = 'block';
    };

    const engine = createHexLayer({
      host,
      pinLayer,
      mountedAt,
      onViewport: () => {
        pins.current.forEach(placePin);
        placeOutline();
        window.clearTimeout(restTimer);
        restTimer = window.setTimeout(() => {
          try {
            const camera = host.getCamera();
            cameraRef.current = camera;
            callbacks.current.onCamera?.(camera);
          } catch (e) {
            console.warn('[truck-map]', e);
          }
        }, CAMERA_REST_MS);
      },
    });
    layerRef.current = engine;
    engine.setPerf(perfOn);

    const now = live.current;
    engine.setRegion(now.region);
    engine.setInputs(now.inputs);
    engine.setLayer(now.layer);
    engine.setTheme(now.theme);
    const hour = useTruckHourStore.getState();
    engine.setHour(hour.how, hour.date);
    engine.setPack(now.pack, now.packState);

    // A camera asked for before this host existed (flyTo while Google was loading).
    if (mode === 'google') {
      const wanted = cameraRef.current;
      if (wanted.lat !== startCamera.lat || wanted.lng !== startCamera.lng || wanted.zoom !== startCamera.zoom) {
        try {
          host.setCamera(wanted);
        } catch (e) {
          console.warn('[truck-map]', e);
        }
      }
    }

    const offStatus = engine.onStatus((status) => callbacks.current.onStatus?.(status));

    const clearHover = (): void => {
      pendingMove = null;
      if (hovered === null && lastPointer === null) return;
      hovered = null;
      lastPointer = null;
      lastByte = -1;
      outline.svg.style.display = 'none';
      tellHover(null);
    };

    // One pick per animation frame, however fast the pointer moves.
    const flushHover = (): void => {
      hoverFrame = 0;
      const e = pendingMove;
      pendingMove = null;
      if (e === null) return;
      const hit = engine.cellAt(e.lat, e.lng);
      if (hit === null) {
        clearHover();
        return;
      }
      if (hovered === null || hovered.index !== hit.index) {
        hovered = { index: hit.index, points: cellOutline(hit.id) ?? [] };
        placeOutline();
      }
      lastPointer = e;
      lastByte = hit.byte;
      tellHover({ id: hit.id, byte: hit.byte, clientX: e.clientX, clientY: e.clientY });
    };

    // The hour or the layer changed under a pointer that is not moving: its cell has another byte now.
    const refreshHover = (): void => {
      if (hovered === null || lastPointer === null) return;
      const hit = engine.cellAt(lastPointer.lat, lastPointer.lng);
      if (hit === null || hit.byte === lastByte) return;
      lastByte = hit.byte;
      tellHover({ id: hit.id, byte: hit.byte, clientX: lastPointer.clientX, clientY: lastPointer.clientY });
    };
    refreshHoverRef.current = refreshHover;

    // Rule 1 of the hour store: an imperative subscription, no React render between a tick and the pixels.
    const offHour = useTruckHourStore.subscribe((state, previous) => {
      if (state.how !== previous.how || state.date !== previous.date) {
        engine.setHour(state.how, state.date);
        refreshHover();
      }
    });

    const offMove = host.on('move', (e) => {
      pendingMove = e;
      if (hoverFrame === 0) hoverFrame = requestAnimationFrame(flushHover);
    });
    const offLeave = host.on('leave', clearHover);
    const offClick = host.on('click', (e) => {
      try {
        callbacks.current.onClick?.({ lat: e.lat, lng: e.lng });
      } catch (err) {
        console.warn('[truck-map]', err);
      }
    });
    // The pointer went onto a pin: the hexagon under it is no longer what is pointed at.
    pinLayer.addEventListener('pointerover', clearHover);

    return () => {
      pinLayer.removeEventListener('pointerover', clearHover);
      offMove();
      offLeave();
      offClick();
      offHour();
      offStatus();
      if (hoverFrame !== 0) cancelAnimationFrame(hoverFrame);
      window.clearTimeout(restTimer);
      try {
        cameraRef.current = usableCamera(host.getCamera(), cameraRef.current);
      } catch {
        // the base map is already gone: the last camera at rest stands
      }
      if (hovered !== null || lastPointer !== null) tellHover(null);
      refreshHoverRef.current = null;
      engine.destroy();
      outline.svg.remove();
      hostRef.current = null;
      layerRef.current = null;
    };
  }, [mode, map, blankRoot, pinLayer, placePin, mountedAt, perfOn, startCamera]);

  // ---- props that change while the layer lives ----------------------------------------------------

  useEffect(() => {
    // The mesh needs the pack and nothing else: its build starts as soon as the pack is decoded, also
    // while the Google map is still loading, and the layer finds it done or under way.
    if (pack !== null && packState === 'ready') prepareMesh(pack, region !== null ? region.dataset_version : null);
    const engine = layerRef.current;
    if (engine === null) return;
    engine.setRegion(region);
    engine.setPack(pack, packState);
  }, [region, pack, packState]);

  useEffect(() => {
    layerRef.current?.setInputs(inputs);
    refreshHoverRef.current?.();
  }, [inputs]);

  useEffect(() => {
    layerRef.current?.setLayer(layer);
    refreshHoverRef.current?.();
  }, [layer]);

  useEffect(() => {
    layerRef.current?.setTheme(theme);
  }, [theme]);

  // ---- the handle ---------------------------------------------------------------------------------

  useImperativeHandle(
    ref,
    () => ({
      flyTo(camera) {
        if (!Number.isFinite(camera.lat) || !Number.isFinite(camera.lng)) return;
        const zoom = camera.zoom !== undefined && Number.isFinite(camera.zoom) ? camera.zoom : cameraRef.current.zoom;
        cameraRef.current = usableCamera({ lat: camera.lat, lng: camera.lng, zoom }, cameraRef.current);
        try {
          hostRef.current?.setCamera(cameraRef.current);
        } catch (e) {
          console.warn('[truck-map]', e);
        }
      },
      getCenter() {
        try {
          const camera = hostRef.current !== null ? hostRef.current.getCamera() : cameraRef.current;
          if (Number.isFinite(camera.lat) && Number.isFinite(camera.lng)) return { lat: camera.lat, lng: camera.lng };
        } catch {
          // fall through to the last camera at rest
        }
        return { lat: cameraRef.current.lat, lng: cameraRef.current.lng };
      },
      hourStrip(dow, done) {
        const engine = layerRef.current;
        if (engine !== null) engine.hourStrip(dow, done);
        else window.setTimeout(() => done(new Uint8Array(24)), 0);
      },
    }),
    [],
  );

  // ---- the Google map ------------------------------------------------------------------------------

  const options = useMemo<google.maps.MapOptions>(
    () => ({
      styles: theme === 'dark' ? SMAPPEN_MAP_STYLE_DARK : SMAPPEN_MAP_STYLE_MONO,
      mapTypeControl: false,
      streetViewControl: false,
      fullscreenControl: false,
      clickableIcons: false,
      // A double click fires `click` first: with clicks carrying meaning it must not also zoom.
      disableDoubleClickZoom: true,
      gestureHandling: 'greedy',
      minZoom: MIN_ZOOM,
      maxZoom: MAX_ZOOM,
      draggableCursor: cursor === 'crosshair' ? 'crosshair' : null,
    }),
    [theme, cursor],
  );

  const mapTimers = useRef<{ auth: number; dialog: number; observer: MutationObserver | null }>({ auth: 0, dialog: 0, observer: null });

  const stopMapWatch = useCallback(() => {
    const t = mapTimers.current;
    window.clearTimeout(t.auth);
    window.clearTimeout(t.dialog);
    if (t.observer !== null) t.observer.disconnect();
    mapTimers.current = { auth: 0, dialog: 0, observer: null };
  }, []);

  const handleLoad = useCallback(
    (loaded: google.maps.Map) => {
      setMap(loaded);
      // Each mount constructs a google.maps.Map, which Google bills as a map load.
      void usageApi.logMapLoad();
      stopMapWatch();
      const div = loaded.getDiv();

      // Second line of defence behind gm_authFailure: with a refused key Google replaces the map by
      // an error element and stops calling our overlay.
      mapTimers.current.auth = window.setTimeout(() => {
        if (div.querySelector('.gm-err-container') !== null) useTruckUiStore.getState().patch({ mapsAuthFailed: true });
      }, AUTH_CHECK_MS);

      // With no key at all Google runs in development mode behind a dialog. Development builds click it away, once.
      const isDev = Boolean((import.meta as unknown as { env?: { DEV?: boolean } }).env?.DEV);
      if (isDev && typeof MutationObserver !== 'undefined') {
        const dismiss = (): boolean => {
          const button = div.querySelector<HTMLElement>('.dismissButton');
          if (button === null) return false;
          button.click();
          return true;
        };
        if (!dismiss()) {
          const observer = new MutationObserver(() => {
            if (dismiss()) stopMapWatchKeepingAuth();
          });
          observer.observe(div, { childList: true, subtree: true });
          mapTimers.current.observer = observer;
          mapTimers.current.dialog = window.setTimeout(stopMapWatchKeepingAuth, DEV_DIALOG_MS);
        }
      }

      function stopMapWatchKeepingAuth(): void {
        const t = mapTimers.current;
        window.clearTimeout(t.dialog);
        if (t.observer !== null) t.observer.disconnect();
        t.dialog = 0;
        t.observer = null;
      }
    },
    [stopMapWatch],
  );

  const handleUnmount = useCallback(() => {
    stopMapWatch();
    setMap(null);
  }, [stopMapWatch]);

  useEffect(() => stopMapWatch, [stopMapWatch]);

  // ---- the blank base's own controls ---------------------------------------------------------------

  const zoomBy = useCallback((direction: 1 | -1) => {
    const host = hostRef.current;
    if (host === null) return;
    try {
      const camera = host.getCamera();
      const zoom = direction > 0 ? Math.floor(camera.zoom + 1e-9) + 1 : Math.ceil(camera.zoom - 1e-9) - 1;
      host.setCamera({ lat: camera.lat, lng: camera.lng, zoom });
    } catch (e) {
      console.warn('[truck-map]', e);
    }
  }, []);

  return (
    <div className="absolute inset-0 overflow-hidden" data-tp-map="" data-tp-basemap={mode} style={{ background: 'var(--bg-panel)' }}>
      {mode === 'loading' && (
        <div className="absolute inset-0 grid place-items-center text-sm font-semibold" style={{ color: 'var(--body)' }}>
          {TEXT.loading}
        </div>
      )}

      {mode === 'google' && (
        <GoogleMap
          mapContainerStyle={CONTAINER_STYLE}
          center={startCenter}
          zoom={startCamera.zoom}
          options={options}
          onLoad={handleLoad}
          onUnmount={handleUnmount}
        />
      )}

      {mode === 'blank' && (
        <>
          <div
            ref={setBlankRoot}
            className="absolute inset-0 overflow-hidden"
            tabIndex={0}
            role="application"
            aria-label={TEXT.blankLabel}
            data-tp-blank=""
            style={{ cursor: cursor === 'crosshair' ? 'crosshair' : 'grab', touchAction: 'none' }}
          />
          <div className="absolute left-3 bottom-3 z-10 flex flex-col gap-1.5">
            <button
              type="button"
              className="btn btn-secondary shadow-float h-11 w-11 md:h-9 md:w-9"
              style={{ padding: 0, color: 'var(--ink)' }}
              aria-label={TEXT.zoomIn}
              title={TEXT.zoomIn}
              onClick={() => zoomBy(1)}
            >
              <Plus size={16} aria-hidden="true" />
            </button>
            <button
              type="button"
              className="btn btn-secondary shadow-float h-11 w-11 md:h-9 md:w-9"
              style={{ padding: 0, color: 'var(--ink)' }}
              aria-label={TEXT.zoomOut}
              title={TEXT.zoomOut}
              onClick={() => zoomBy(-1)}
            >
              <Minus size={16} aria-hidden="true" />
            </button>
          </div>
          {googleFailed && hostNotice && (
            <div
              role="status"
              data-tp-host-notice=""
              className="absolute bottom-3 z-10 bg-white rounded-xl border shadow-float px-3 py-2 flex items-start gap-2 text-[13px] font-semibold leading-snug"
              style={{ left: 68, maxWidth: 'min(380px, calc(100% - 80px))', borderColor: 'var(--line-soft)', color: 'var(--ink)' }}
            >
              <TriangleAlert size={16} className="shrink-0 mt-0.5" style={{ color: 'var(--fresh-aging)' }} aria-hidden="true" />
              <span>{TEXT.googleFailed}</span>
            </div>
          )}
        </>
      )}

      {perfOn && <PerfHud getStats={() => (layerRef.current !== null ? layerRef.current.stats() : null)} />}

      <MapPinContext.Provider value={registry}>{children}</MapPinContext.Provider>
    </div>
  );
});

const TruckMap = forwardRef<TruckMapHandle, TruckMapProps>(function TruckMap(props, ref) {
  // The last resort: the layer never throws, but if anything on the map does, the page around it stays up.
  return (
    <ErrorBoundary scope="Map" inline>
      <TruckMapInner {...props} ref={ref} />
    </ErrorBoundary>
  );
});

export default TruckMap;
