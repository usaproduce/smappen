import { useEffect, useMemo, useState } from 'react';
import { GoogleMap, Marker, Polygon } from '@react-google-maps/api';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';
import AreaPolygon from './AreaPolygon';
import AreaCenterPins from './AreaCenterPins';
import POIMarkers from './POIMarkers';
import ImportedMarkers from './ImportedMarkers';
import CustomLayerMarkers from './CustomLayerMarkers';
import DrawingTools from './DrawingTools';
import ChoroplethLayer from './ChoroplethLayer';
import HeatmapPanel from './HeatmapPanel';
import PresenceCursors from './PresenceCursors';
import { SMAPPEN_MAP_STYLE_DARK, MAP_STYLE_PRESETS } from '../../utils/mapStyle';
import { smoothFlyTo } from '../../utils/mapAnim';
import { usageApi } from '../../api/usage';
import type { HeatmapResponse } from '../../api/heatmap';

// Color ramp for analog match similarity: high-match = teal, lower = coral.
function analogColor(sim: number): string {
  if (sim >= 0.9)  return '#1D9E75';
  if (sim >= 0.75) return '#378ADD';
  if (sim >= 0.6)  return '#EF9F27';
  return '#D85A30';
}

export default function MapCanvas() {
  const {
    center, zoom, setMapInstance, drawingType, placePinFor,
    setPendingIsochrone, mapInstance, showHeatmap, heatmapMetric,
    timeMachine, analogResults, hiddenAreaIds,
  } = useMapStore();
  const { areas: allAreas, importedPoints } = useProjectStore();
  // Filter out user-hidden areas before rendering anything. The list panel
  // still shows them (dimmed) so the user can toggle them back; the map
  // canvas just respects the visibility flag.
  const areas = allAreas.filter((a) => !hiddenAreaIds.has(a.id));
  const [heatmapMeta, setHeatmapMeta] = useState<HeatmapResponse['meta'] | null>(null);
  const mapStylePref = useUiPrefsStore((s) => s.mapStyle);
  const setMapStylePref = useUiPrefsStore((s) => s.setMapStyle);
  // Dark mode awareness — render a dark Google Maps style when the user
  // has the app in dark mode. Re-evaluates whenever <html data-theme> flips.
  const [dark, setDark] = useState(() => typeof document !== 'undefined' && document.documentElement.getAttribute('data-theme') === 'dark');
  useEffect(() => {
    const obs = new MutationObserver(() => {
      setDark(document.documentElement.getAttribute('data-theme') === 'dark');
    });
    obs.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    return () => obs.disconnect();
  }, []);
  // Dark mode always wins so the map shell matches the app chrome — except
  // for satellite, which only makes sense in its native imagery colors.
  const preset = MAP_STYLE_PRESETS.find((p) => p.id === mapStylePref) ?? MAP_STYLE_PRESETS[0];
  const isSat = preset.id === 'satellite';
  const activeStyle = (!isSat && dark) ? SMAPPEN_MAP_STYLE_DARK : (preset.styles ?? null);
  const activeMapTypeId = preset.mapTypeId;

  // Materialize the time-machine polygon path from the geometry attached to
  // the store. Memoized so we don't rebuild Polygon paths on every render —
  // the play loop fires up to 4× per second.
  const tmPath = useMemo(() => {
    const ring = timeMachine?.geometry?.coordinates?.[0];
    if (!Array.isArray(ring)) return null;
    const out: google.maps.LatLngLiteral[] = [];
    for (const pt of ring) {
      if (Array.isArray(pt) && pt.length >= 2 && Number.isFinite(pt[0]) && Number.isFinite(pt[1])) {
        out.push({ lat: pt[1], lng: pt[0] });
      }
    }
    return out.length >= 3 ? out : null;
  }, [timeMachine?.geometry]);

  useEffect(() => {
    if (!mapInstance) return;
    const listener = mapInstance.addListener('click', (e: google.maps.MapMouseEvent) => {
      if (placePinFor === 'isochrone' && e.latLng) {
        setPendingIsochrone({ lat: e.latLng.lat(), lng: e.latLng.lng() });
      }
    });
    return () => google.maps.event.removeListener(listener);
  }, [mapInstance, placePinFor, setPendingIsochrone]);

  // Log one Maps JS session per browser session. Map loads are the biggest
  // line item on a typical Smappen bill ($7/1000); without this the cost
  // widget wildly under-counts and operators have no idea how much they're
  // really spending.
  useEffect(() => {
    if (!mapInstance) return;
    if (sessionStorage.getItem('smappen_map_load_logged') === '1') return;
    sessionStorage.setItem('smappen_map_load_logged', '1');
    usageApi.logMapLoad();
  }, [mapInstance]);

  return (
    <div className="absolute inset-0">
      <GoogleMap
        mapContainerStyle={{ width: '100%', height: '100%' }}
        center={center}
        zoom={zoom}
        onLoad={setMapInstance}
        onUnmount={() => setMapInstance(null)}
        options={{
          mapTypeControl: false,
          streetViewControl: false,
          fullscreenControl: false,
          zoomControl: false,
          styles: activeStyle ?? undefined,
          mapTypeId: activeMapTypeId,
          draggableCursor: drawingType === 'pin' ? 'crosshair' : undefined,
        }}
      >
        {showHeatmap && <ChoroplethLayer metric={heatmapMetric} onMetaChange={setHeatmapMeta} />}
        {areas.map((a) => a.geometry ? <AreaPolygon key={a.id} area={a} heatmapOn={showHeatmap} /> : null)}
        <AreaCenterPins areas={areas} />
        <ImportedMarkers points={importedPoints} />
        <CustomLayerMarkers />
        <POIMarkers />
        <DrawingTools />
        <PresenceCursors />
        {tmPath && timeMachine && (
          <Polygon
            paths={tmPath}
            options={{
              fillColor: timeMachine.color,
              fillOpacity: 0.28,
              strokeColor: timeMachine.color,
              strokeWeight: 3,
              clickable: false,
              zIndex: 999,
            }}
          />
        )}

        {/* Analog Finder candidate pins. Numbered, colored by similarity.
            Clicking a pin pans + zooms to the candidate so the user can see
            the area in detail — replaces the old onClick that called
            selectArea(null) and closed the right panel for no reason. */}
        {analogResults?.map((c, i) => (
          <Marker
            key={c.geoid}
            position={{ lat: c.lat, lng: c.lng }}
            label={{ text: String(i + 1), color: '#fff', fontSize: '11px', fontWeight: '700' }}
            icon={{
              path: google.maps.SymbolPath.CIRCLE,
              scale: 14,
              fillColor: analogColor(c.similarity),
              fillOpacity: 0.95,
              strokeColor: '#fff',
              strokeWeight: 2,
            }}
            title={`${Math.round(c.similarity * 100)}% match · Tract ${c.name || c.geoid}`}
            onClick={() => {
              // VT4 — smooth combined pan + zoom rather than two snap-calls.
              smoothFlyTo(mapInstance, { lat: c.lat, lng: c.lng, zoom: Math.max(mapInstance?.getZoom() ?? 10, 13) });
            }}
            zIndex={500}
          />
        ))}
      </GoogleMap>
      {showHeatmap && <HeatmapPanel meta={heatmapMeta} />}

      {/* Heatmap-loading toast in the top-center of the map. The
          HeatmapPanel's header spinner is good but easy to miss when the
          panel is collapsed; this is a louder, glanceable hint that the
          map is fetching new polygons during pan/zoom. */}
      <HeatmapLoadingToast />

      {/* Map style preset picker. Persists to uiPrefsStore. Adding a new
          preset to MAP_STYLE_PRESETS auto-extends this control. */}
      <div className="absolute bottom-4 left-4 bg-white rounded-lg shadow-float border border-slate-200 flex items-center p-0.5 text-[11px] font-semibold z-10">
        {MAP_STYLE_PRESETS.map((p) => (
          <button
            key={p.id}
            onClick={() => setMapStylePref(p.id)}
            className={`px-2.5 py-1 rounded-md transition-colors ${
              mapStylePref === p.id ? 'bg-violet-100 text-violet-700' : 'text-slate-500 hover:text-slate-700'
            }`}
            title={p.description}
          >
            {p.label}
          </button>
        ))}
      </div>

      {/* VT1 — color legend for analog markers. Shown only while results exist
          so it doesn't clutter the map at rest. */}
      {analogResults && analogResults.length > 0 && (
        <div className="absolute bottom-4 left-32 bg-white rounded-lg shadow-float border border-slate-200 px-2 py-1.5 text-[10px] font-semibold flex items-center gap-2 z-10">
          <span className="uppercase tracking-wider text-slate-400">Match</span>
          {[
            { c: '#1D9E75', label: '90+%' },
            { c: '#378ADD', label: '75–89%' },
            { c: '#EF9F27', label: '60–74%' },
            { c: '#D85A30', label: '<60%' },
          ].map((s) => (
            <span key={s.label} className="flex items-center gap-1">
              <span className="inline-block w-2.5 h-2.5 rounded-full" style={{ background: s.c }} />
              <span className="text-slate-600">{s.label}</span>
            </span>
          ))}
        </div>
      )}

      {/* VT24 — time-of-day tint. Subtle warm overlay during day, cool/dim
          after sunset (using the user's locale via Date.getHours). Skipped
          for the Clean map style (people who want minimal chrome should get
          unfiltered colors). */}
      {mapStylePref === 'detailed' && <TimeOfDayTint />}

      {/* VT3 (mini-overview navigator) — REMOVED per user feedback. The
          tiny US silhouette + viewport rect in the bottom-right was more
          distracting than useful. Keep the function below in case we
          want a togglable variant later. */}
    </div>
  );
}

/** VT24 — subtle full-screen tint that follows the user's local hour. */
function TimeOfDayTint() {
  const h = new Date().getHours();
  // Day (8-18): warm peach overlay at very low opacity.
  // Evening (19-21): amber.
  // Night (22-6): cool dark blue.
  // Morning (7): clean.
  let bg: string | null = null;
  if (h >= 19 && h <= 21) bg = 'rgba(255, 154, 60, 0.06)';
  else if (h >= 22 || h <= 5) bg = 'rgba(20, 30, 60, 0.10)';
  if (!bg) return null;
  return (
    <div
      className="absolute inset-0 pointer-events-none z-[5] mix-blend-multiply"
      style={{ background: bg, transition: 'background 1s' }}
      aria-hidden="true"
    />
  );
}

/** VT3 — tiny ~120×64 inset map. Pure SVG, no extra API calls. */
function MiniOverview({ mapInstance }: { mapInstance: google.maps.Map | null }) {
  const [box, setBox] = useState<{ x: number; y: number; w: number; h: number } | null>(null);
  useEffect(() => {
    if (!mapInstance) return;
    // Project the current viewport bounds onto a flat lat/lng grid (US-only).
    // We anchor the mini-map's SVG to roughly conterminous-US bounds:
    //   lat 24..50, lng -125..-66
    const usMinLat = 24, usMaxLat = 50, usMinLng = -125, usMaxLng = -66;
    const W = 120, H = 64;
    function update() {
      const b = mapInstance!.getBounds();
      if (!b) return;
      const ne = b.getNorthEast(); const sw = b.getSouthWest();
      // Clip to US bounds so a worldwide zoom-out doesn't draw a giant rect.
      const nLat = Math.max(usMinLat, Math.min(usMaxLat, ne.lat()));
      const sLat = Math.max(usMinLat, Math.min(usMaxLat, sw.lat()));
      const eLng = Math.max(usMinLng, Math.min(usMaxLng, ne.lng()));
      const wLng = Math.max(usMinLng, Math.min(usMaxLng, sw.lng()));
      const xL = ((wLng - usMinLng) / (usMaxLng - usMinLng)) * W;
      const xR = ((eLng - usMinLng) / (usMaxLng - usMinLng)) * W;
      // SVG y axis flipped — top is high lat.
      const yT = H - ((nLat - usMinLat) / (usMaxLat - usMinLat)) * H;
      const yB = H - ((sLat - usMinLat) / (usMaxLat - usMinLat)) * H;
      setBox({ x: Math.min(xL, xR), y: Math.min(yT, yB), w: Math.abs(xR - xL), h: Math.abs(yB - yT) });
    }
    update();
    const listener = mapInstance.addListener('idle', update);
    return () => google.maps.event.removeListener(listener);
  }, [mapInstance]);
  return (
    <div
      className="absolute bottom-4 right-20 bg-white/95 backdrop-blur rounded-lg shadow-float border border-slate-200 p-1.5 z-10 pointer-events-none"
      aria-hidden="true"
    >
      <svg viewBox="0 0 120 64" width="120" height="64">
        {/* Stylized US silhouette — boxy approximation that reads at 120px. */}
        <path
          d="M5,18 L18,12 L42,10 L70,8 L98,12 L115,18 L115,40 L110,52 L92,56 L74,54 L56,58 L34,56 L14,50 L5,38 Z"
          fill="#EDE5F7"
          stroke="#C8B5E0"
          strokeWidth="0.8"
        />
        {box && (
          <rect
            x={box.x}
            y={box.y}
            width={Math.max(2, box.w)}
            height={Math.max(2, box.h)}
            fill="rgba(229, 57, 53, 0.18)"
            stroke="#E53935"
            strokeWidth="1.2"
          />
        )}
      </svg>
    </div>
  );
}

function HeatmapLoadingToast() {
  const { showHeatmap, heatmapLoading } = useMapStore();
  if (!showHeatmap || !heatmapLoading) return null;
  return (
    <div className="absolute top-4 left-1/2 -translate-x-1/2 z-30 bg-white rounded-full shadow-float border border-slate-200 px-3 py-1.5 text-xs font-semibold flex items-center gap-2 pointer-events-none">
      <span className="spinner" style={{ width: 12, height: 12, borderWidth: 1.5 }} />
      <span style={{ color: '#1A1A2E' }}>Loading heatmap…</span>
    </div>
  );
}
