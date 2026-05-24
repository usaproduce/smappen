import { Polygon, OverlayView } from '@react-google-maps/api';
import { useEffect, useState } from 'react';
import { Car, Bike, Footprints, MapPin, Users, DollarSign, Home, Maximize2, TrendingUp, TrendingDown } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';
import { geoJsonToGooglePaths, polygonBounds, polygonCentroid } from '../../utils/geo';
import type { Area } from '../../types';

// US national medians (ACS 2023 5-year). Used for inline trend arrows in the
// hover card — "is this area above or below average?"
const US_MEDIAN_INCOME = 74755;
const US_PEOPLE_PER_KM2 = 36;

const MODE_ICON: Record<string, any> = {
  'driving-car': Car,
  'cycling-regular': Bike,
  'foot-walking': Footprints,
};

const MODE_BG: Record<string, string> = {
  'driving-car': '#3b82f6',
  'cycling-regular': '#10b981',
  'foot-walking': '#f59e0b',
};

export default function AreaPolygon({ area, heatmapOn = false }: { area: Area; heatmapOn?: boolean }) {
  const { selectedAreaId, selectArea, hoveredAreaId, mapInstance } = useMapStore();
  const { areas } = useProjectStore();
  const showLabels = useUiPrefsStore((s) => s.showPolygonLabels);
  // #18 — track the current zoom so the label-visibility gate re-evaluates
  // when the user zooms in/out. We track once per map idle (not per render)
  // to keep this cheap.
  const [zoom, setZoom] = useState<number>(() => mapInstance?.getZoom() ?? 10);
  useEffect(() => {
    if (!mapInstance) return;
    const listener = mapInstance.addListener('idle', () => setZoom(mapInstance.getZoom() ?? 10));
    setZoom(mapInstance.getZoom() ?? 10);
    return () => google.maps.event.removeListener(listener);
  }, [mapInstance]);
  // VT21 — list-row hover bumps the matching polygon's stroke + fill so the
  // user can trace each row visually to its shape on the map.
  const isRowHovered = hoveredAreaId === area.id;
  const isSelected = selectedAreaId === area.id;
  const [hover, setHover] = useState(false);
  // Tweak #15 — soft pulse on the selected polygon's stroke weight + glow,
  // expressed as a 0..1 "phase" we slide through a sine wave so the effect
  // is smooth on a 16ms timer. Cleaned up on unselect/unmount so the timer
  // doesn't fire on every polygon constantly.
  const [pulse, setPulse] = useState(0);
  useEffect(() => {
    if (!isSelected) { setPulse(0); return; }
    let raf = 0;
    let t0 = performance.now();
    // Slower, calmer pulse — was 1.2s per cycle which read as "blinking";
    // 3s is gentle breathing. Also reduced amplitude implicitly via the
    // calling code's `pulse * 2` modifier on stroke / `pulse * 0.1` on fill.
    const tick = (t: number) => {
      const phase = ((t - t0) / 3000) % 1;
      setPulse(0.5 - 0.5 * Math.cos(phase * Math.PI * 2));
      raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [isSelected]);

  if (!area.geometry) return null;
  // After migration 011 territory geometries can be MultiPolygon (multiple
  // disjoint pieces — common when k-means clusters source tracts that aren't
  // spatially contiguous). Render one <Polygon> per piece.
  const paths = geoJsonToGooglePaths(area.geometry as any);
  if (paths.length === 0) return null;

  const fillColor = area.fill_color || '#7848BB';
  const strokeColor = heatmapOn ? '#FFFFFF' : (area.stroke_color || fillColor);
  // Selection pulse: stroke wobbles between 3 and 5px, fill opacity drifts.
  const baseStrokeWeight = heatmapOn ? 3 : (area.stroke_weight ?? 2);
  const strokeWeight = isSelected
    ? baseStrokeWeight + 2 + pulse * 2
    : isRowHovered
      ? baseStrokeWeight + 2
      : baseStrokeWeight;

  // VT7 — when demographics_cache is populated, scale fill opacity by
  // population density so dense urban tracts visually pop and sparse
  // rural ones recede. Caps at ±15% from the explicit fill_opacity so
  // user-chosen styling still leads.
  let densityBoost = 0;
  const dc: any = (area as any).demographics_cache ?? {};
  const densPerKm2 = dc.population?.density_per_sq_km ?? null;
  if (typeof densPerKm2 === 'number' && densPerKm2 > 0) {
    // Clamp via log scale: 10 → 0, 100 → 0.5, 1000 → 0.9, 10000+ → 1.
    densityBoost = Math.min(1, Math.max(0, (Math.log10(densPerKm2) - 1) / 3));
  }

  // Heatmap mode used to force fillOpacity=0 for ALL polygons so they didn't
  // obscure the choropleth. The result was that selecting an area while the
  // heatmap is on left the user with no visual indication of WHICH area is
  // selected on the map — only the right panel knew. Keep the selected area
  // faintly visible (cuts out the heatmap inside its boundary too), and the
  // hovered area visible, but let all other polygons fade out as before.
  const fillOpacity = heatmapOn
    ? (isSelected ? 0.35 : isRowHovered || hover ? 0.18 : 0)
    : isSelected
      ? Math.min(0.55, (area.fill_opacity ?? 0.2) + 0.15 + pulse * 0.1)
      : Math.min(0.55, Math.max(0.05, (area.fill_opacity ?? 0.2) - 0.075 + densityBoost * 0.15));

  const centroid = polygonCentroid(area.geometry as any);
  const ModeIcon = MODE_ICON[area.travel_mode ?? ''] ?? MapPin;
  const badgeBg = MODE_BG[area.travel_mode ?? ''] ?? '#7848BB';

  // Tweak #18 — when 3+ areas overlap at this centroid (loose check: another
  // area's bbox contains our centroid), surface a small "n overlap" pill
  // anchored at the centroid so the user can see hot zones at a glance.
  const overlapCount = (() => {
    if (!area.geometry) return 0;
    let n = 1;
    for (const other of areas) {
      if (other.id === area.id || !other.geometry) continue;
      const b = polygonBounds(other.geometry as any);
      if (Number.isFinite(b.minLat) && centroid.lng >= b.minLng && centroid.lng <= b.maxLng && centroid.lat >= b.minLat && centroid.lat <= b.maxLat) {
        n++;
      }
    }
    return n;
  })();

  return (
    <>
      {paths.map((path, i) => (
        <Polygon
          key={i}
          path={path}
          options={{
            fillColor,
            fillOpacity,
            strokeColor,
            strokeWeight,
            strokeOpacity: 1,
            clickable: true,
            zIndex: isSelected ? 5 : 1,
          }}
          onClick={() => selectArea(area.id)}
          onMouseOver={() => setHover(true)}
          onMouseOut={() => setHover(false)}
        />
      ))}

      {/* Travel-mode centroid badge — removed per user feedback. The little
          "30 min" chip overlaid in the middle of every isochrone was visual
          noise; the area is already labeled in the right panel and the
          colored center pin (AreaCenterPins) covers identification. */}

      {/* VT5 — toggleable polygon name label at centroid. #18 — gated by
          projected polygon size so labels don't pile up at low zoom. The
          gate is approximate (polygon span in lat° × zoom factor) but cheap
          and resolves the worst overlap cases. The selected polygon always
          gets its label (user explicitly asked for it). */}
      {showLabels && !hover && (isSelected || self_labelFits(area, zoom)) && (
        <OverlayView position={centroid} mapPaneName={OverlayView.OVERLAY_LAYER}>
          <div
            className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-white/95 shadow-sm border border-slate-200 pointer-events-none whitespace-nowrap"
            style={{
              color: '#1A1A2E',
              transform: 'translate(-50%, calc(-50% + ' + (area.travel_mode ? '20px' : '0') + '))',
              maxWidth: 160,
              overflow: 'hidden',
              textOverflow: 'ellipsis',
            }}
          >
            {area.name}
          </div>
        </OverlayView>
      )}

      {/* Tweak #18 — overlap pill. Only shows when 3+ areas overlap here,
          so it doesn't clutter quiet maps. */}
      {overlapCount >= 3 && !hover && (
        <OverlayView position={centroid} mapPaneName={OverlayView.OVERLAY_LAYER}>
          <div
            className="text-[10px] font-bold text-white rounded-full px-1.5 py-0.5 shadow"
            style={{ background: '#ef4444', transform: 'translate(-50%, calc(-50% + 16px))' }}
            title={`${overlapCount} areas overlap here`}
          >
            ×{overlapCount}
          </div>
        </OverlayView>
      )}

      {/* Tweak #16 — custom hover card via OverlayView. Ditches Google's
          InfoWindow chrome (white background, tail, X button) for a tight
          dark pill with stat tiles. */}
      {hover && (
        <OverlayView position={centroid} mapPaneName={OverlayView.FLOAT_PANE}>
          <HoverCard area={area} fillColor={fillColor} />
        </OverlayView>
      )}
    </>
  );
}

function formatCompact(n: number): string {
  if (Math.abs(n) >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
  if (Math.abs(n) >= 1_000)     return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'K';
  return Math.round(n).toLocaleString();
}

/**
 * #18 — label-visibility heuristic. Approximates a polygon's pixel-extent
 * at the current zoom (Google's 256 px per 360° at zoom 0 doubling per
 * level). Only polygons larger than ~60×30 px get their label. The selected
 * polygon always shows its label; the gate just kills the long-tail of
 * tiny polygons at low zoom that produce the worst label pile-ups.
 */
function self_labelFits(area: any, zoom: number): boolean {
  const g = area?.geometry;
  if (!g?.coordinates) return false;
  const ring = g.type === 'Polygon' ? g.coordinates[0] : g.coordinates?.[0]?.[0];
  if (!ring || ring.length < 3) return false;
  let minLat = Infinity, minLng = Infinity, maxLat = -Infinity, maxLng = -Infinity;
  for (const [lng, lat] of ring) {
    if (lat < minLat) minLat = lat; if (lat > maxLat) maxLat = lat;
    if (lng < minLng) minLng = lng; if (lng > maxLng) maxLng = lng;
  }
  const ppd = (256 * Math.pow(2, zoom)) / 360;
  const wPx = (maxLng - minLng) * ppd * Math.cos(((minLat + maxLat) / 2) * Math.PI / 180);
  const hPx = (maxLat - minLat) * ppd;
  return wPx > 60 && hPx > 30;
}

function HoverCard({ area, fillColor }: { area: any; fillColor: string }) {
  const dc: any = area.demographics_cache ?? {};
  const pop: number | null =
    typeof dc.population?.total === 'number' ? dc.population.total
    : typeof dc.population === 'number' ? dc.population
    : null;
  const income: number | null = dc.income?.median_household_income ?? dc.income?.median_household ?? dc.median_household_income ?? null;
  const households: number | null = dc.housing?.total_units ?? dc.housing_units_total ?? null;

  // Estimate area in km² (same logic the right-panel uses).
  const sqKm: number | null = (() => {
    if (!area.geometry) return null;
    try {
      const b = polygonBounds(area.geometry);
      if (!Number.isFinite(b.minLat)) return null;
      if (area.area_type === 'radius' && area.travel_distance_km) {
        return Math.PI * area.travel_distance_km * area.travel_distance_km;
      }
      const w = haversineKm(b.minLat, b.minLng, b.minLat, b.maxLng);
      const h = haversineKm(b.minLat, b.minLng, b.maxLat, b.minLng);
      return w * h * 0.7;
    } catch { return null; }
  })();

  const density = pop != null && sqKm != null && sqKm > 0 ? pop / sqKm : null;

  const ModeIcon = area.travel_mode === 'driving-car' ? Car
    : area.travel_mode === 'cycling-regular' ? Bike
    : area.travel_mode === 'foot-walking' ? Footprints
    : MapPin;

  // Travel-mode label for the type chip — kept terse, just "60 min car".
  const modeLabel = area.travel_mode === 'driving-car' ? 'car'
    : area.travel_mode === 'cycling-regular' ? 'bike'
    : area.travel_mode === 'foot-walking' ? 'walk'
    : area.area_type;

  return (
    // OverlayView positions the top-left of this div at the centroid. Translate
    // up + center horizontally so the card "tail" sits over the centroid pin
    // with the body floating above.
    <div
      className="pointer-events-none"
      style={{ transform: 'translate(-50%, calc(-100% - 18px))' }}
    >
      <div
        className="relative rounded-xl shadow-2xl border border-white/10 overflow-hidden"
        style={{
          background: 'linear-gradient(180deg, #1A1A2E 0%, #2D2D44 100%)',
          color: '#fff',
          minWidth: 260,
          maxWidth: 320,
          animation: 'hoverCardIn 0.16s cubic-bezier(0.16, 1, 0.3, 1) both',
        }}
      >
        {/* Color accent stripe — full width thin band at the top, picks
            up the area's brand color so the card visually "belongs" to it. */}
        <div style={{ height: 3, background: fillColor }} />

        <div className="px-3 pt-2 pb-2.5">
          {/* Title row */}
          <div className="flex items-center gap-2 min-w-0">
            <span
              className="shrink-0 w-6 h-6 rounded-full flex items-center justify-center"
              style={{ background: fillColor }}
            >
              <ModeIcon size={12} />
            </span>
            <div className="min-w-0 flex-1">
              <div className="text-[13px] font-extrabold leading-tight truncate">{area.name}</div>
              <div className="text-[10px] uppercase tracking-wider font-bold text-white/55 mt-0.5">
                {area.travel_time_minutes ? `${area.travel_time_minutes} min · ${modeLabel}` : modeLabel}
              </div>
            </div>
          </div>

          {/* 4 stat tiles in a 2×2 (or single row if 3 fit). Always show
              People + Area; income + households only if known. */}
          <div className="mt-2.5 grid grid-cols-2 gap-1.5">
            {pop != null && (
              <StatCell icon={Users} label="People" value={formatCompact(pop)} trend={density != null ? compareTrend(density, US_PEOPLE_PER_KM2) : null} />
            )}
            {income != null && (
              <StatCell icon={DollarSign} label="Med inc" value={'$' + formatCompact(Math.round(income))} trend={compareTrend(income, US_MEDIAN_INCOME)} />
            )}
            {households != null && (
              <StatCell icon={Home} label="HH" value={formatCompact(households)} />
            )}
            {sqKm != null && (
              <StatCell icon={Maximize2} label="Area" value={formatCompact(sqKm) + ' km²'} />
            )}
          </div>

          {/* Footer hint when no demographics yet. */}
          {pop == null && income == null && households == null && (
            <div className="mt-2 text-[10px] text-white/55 italic">
              Click to load demographics for this area.
            </div>
          )}
        </div>

        {/* Tail: rotated square so the card "points" at the centroid pin. */}
        <div
          className="absolute left-1/2 -translate-x-1/2"
          style={{
            bottom: -5,
            width: 10,
            height: 10,
            background: '#2D2D44',
            transform: 'translate(-50%, 0) rotate(45deg)',
            borderRight: '1px solid rgba(255,255,255,0.08)',
            borderBottom: '1px solid rgba(255,255,255,0.08)',
          }}
        />
      </div>
      <style>{`@keyframes hoverCardIn {
        from { opacity: 0; transform: translate(-50%, calc(-100% - 12px)) scale(0.95); }
        to   { opacity: 1; transform: translate(-50%, calc(-100% - 18px)) scale(1); }
      }`}</style>
    </div>
  );
}

function StatCell({ icon: Icon, label, value, trend }: { icon: any; label: string; value: string; trend?: 'up' | 'down' | null }) {
  return (
    <div className="bg-white/[0.06] rounded-md px-2 py-1.5 border border-white/[0.04]">
      <div className="flex items-center gap-1 text-[9px] uppercase tracking-wider font-bold text-white/55">
        <Icon size={9} />
        <span>{label}</span>
        {trend === 'up' && <TrendingUp size={10} className="text-emerald-400 ml-auto" />}
        {trend === 'down' && <TrendingDown size={10} className="text-rose-400 ml-auto" />}
      </div>
      <div className="text-[13px] font-extrabold tabular-nums mt-0.5">{value}</div>
    </div>
  );
}

function compareTrend(actual: number, baseline: number): 'up' | 'down' | null {
  // Quiet trend — only flag deltas larger than ±10% so noise doesn't
  // make every area look "above average".
  const d = (actual - baseline) / baseline;
  if (d > 0.10) return 'up';
  if (d < -0.10) return 'down';
  return null;
}

function haversineKm(la1: number, lo1: number, la2: number, lo2: number) {
  const R = 6371;
  const dLat = ((la2 - la1) * Math.PI) / 180;
  const dLon = ((lo2 - lo1) * Math.PI) / 180;
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((la1 * Math.PI) / 180) * Math.cos((la2 * Math.PI) / 180) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(a));
}

