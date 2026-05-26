import { useEffect, useMemo, useRef, useState } from 'react';
import {
  X, MapPin, Users, Clock, Circle, PenSquare, FileText,
  Folder as FolderIcon, ChevronDown, Sparkles, Loader2, Pencil,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { isochroneApi } from '../../api/isochrone';
import { areasApi } from '../../api/areas';
import { geocodingApi } from '../../api/geocoding';
import { reachApi } from '../../api/reach';
import { useProjectStore } from '../../stores/projectStore';
import { useMapStore } from '../../stores/mapStore';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';
import { AREA_PALETTE_NAMED } from '../../utils/colors';
import { formatNumber } from '../../utils/format';
import type { Area } from '../../types';

function deriveInitial(area?: Area) {
  if (!area) return null;
  const mode: Mode =
    area.area_type === 'radius' ? 'radius'
    : (area.area_type === 'isochrone' || area.area_type === 'isodistance') ? 'travel'
    : 'travel'; // 'manual' (drawn) — meta-only edit; mode picker hidden anyway
  return {
    mode,
    address:    area.center_address ?? '',
    lat:        (area.center_lat ?? null) as number | null,
    lng:        (area.center_lng ?? null) as number | null,
    travelMode: (area.travel_mode as any) ?? 'driving-car',
    time:       area.travel_time_minutes ?? 15,
    radiusKm:   area.travel_distance_km ?? 5,
    name:       area.name,
    color:      area.fill_color ?? AREA_PALETTE_NAMED[12].hex,
    opacity:    typeof area.fill_opacity === 'number' ? area.fill_opacity : 0.3,
    folderId:   (area.folder_id ?? null) as string | null,
    notes:      area.notes ?? '',
  };
}

/**
 * Sidebar-expanding area creator (replaces the full-screen modal).
 *
 * Behavior change vs the previous modal:
 *   • Slides out as a SECOND PANEL to the right of the left sidebar instead
 *     of stealing the whole screen. The map stays fully visible underneath
 *     so the user can pan/zoom while picking a center, and watch the saved
 *     area appear at its final position when they hit Save.
 *   • Live preview removed entirely — was distracting + wasted Isochrone
 *     API budget on every keystroke. The user picks settings then hits
 *     "Calculate" once when they're ready. The calculated area lands on
 *     the map immediately so the preview IS the map.
 *   • Slide-in animation: 320ms cubic-bezier from left=-100% → left=0.
 */

type Mode = 'travel' | 'reach' | 'radius' | 'draw';

const MODES: { key: Mode; label: string; sub: string; icon: any }[] = [
  { key: 'travel', label: 'Travel time',      sub: 'Drive/walk/bike isochrone',    icon: Clock     },
  { key: 'reach',  label: 'Reach population', sub: 'Smallest circle for N people', icon: Users     },
  { key: 'radius', label: 'Pure radius',      sub: 'Fixed-distance circle',        icon: Circle    },
  { key: 'draw',   label: 'Draw on map',      sub: 'Freehand polygon',             icon: PenSquare },
];

const TRAVEL_MODES = [
  { value: 'driving-car',     label: 'Car',  icon: '🚗' },
  { value: 'cycling-regular', label: 'Bike', icon: '🚴' },
  { value: 'foot-walking',    label: 'Walk', icon: '🚶' },
] as const;
// 60 is the ceiling — ORS (our routing provider) rejects range > 3600s
// (60 minutes) with HTTP 400 "range out of range". The slider + preset
// chips top out at 60 too; users who need longer reach get a clear "use
// Radius instead" error from the backend now (IsochroneController), but
// we never set them up to fail in the first place.
const TIME_PRESETS = [5, 10, 15, 20, 30, 45, 60];
const POP_PRESETS = [5000, 10000, 25000, 50000, 100000, 250000, 500000];
const RADIUS_KM_PRESETS = [1, 2, 5, 10, 25, 50, 100];

interface Props {
  onClose: () => void;
  /** When set, the panel acts as an editor for this area instead of a
   *  creator: fields prefill from the area, mode picker is hidden (the
   *  shape type is locked), Save → PUT /api/areas/:id with the updated
   *  geometry (if recomputed) plus all meta (color, opacity, name…). */
  editing?: Area;
}

export default function AreaCreator({ onClose, editing }: Props) {
  const { currentProject, addArea, updateArea, folders } = useProjectStore() as any;
  const { startDrawing, placePinFor, pendingIsochrone, setPendingIsochrone, fitBoundsToArea } = useMapStore();
  const { recentColors, pushRecentColor } = useUiPrefsStore();

  // Initial values are derived once from the area being edited so we can
  // diff against them later to know whether the geometry needs a recalc
  // before Save. Plain object captured by ref so it survives re-renders
  // without re-deriving (and never changes mid-edit, even if the props
  // briefly do).
  const initialRef = useRef(deriveInitial(editing));
  const initial = initialRef.current;

  const [mode, setMode] = useState<Mode>(initial?.mode ?? 'travel');
  const [address, setAddress] = useState(initial?.address ?? '');
  const [lat, setLat] = useState<number | null>(initial?.lat ?? null);
  const [lng, setLng] = useState<number | null>(initial?.lng ?? null);

  const [travelMode, setTravelMode] = useState<'driving-car' | 'cycling-regular' | 'foot-walking'>(initial?.travelMode ?? 'driving-car');
  const [time, setTime] = useState(initial?.time ?? 15);
  const [targetPop, setTargetPop] = useState(25000);
  const [radiusKm, setRadiusKm] = useState(initial?.radiusKm ?? 5);
  const [units, setUnits] = useState<'km' | 'mi'>('km');

  const [name, setName] = useState(initial?.name ?? '');
  const [color, setColor] = useState(initial?.color ?? AREA_PALETTE_NAMED[12].hex);
  const [opacity, setOpacity] = useState(initial?.opacity ?? 0.3);
  const [folderId, setFolderId] = useState<string | null>(initial?.folderId ?? null);
  const [notes, setNotes] = useState(initial?.notes ?? '');

  const [calculating, setCalculating] = useState(false);
  const [saving, setSaving] = useState(false);
  // Pending geometry from a Calculate click — held in memory until Save
  // (or discarded on cancel / mode change). Live preview removed.
  const [pending, setPending] = useState<any | null>(null);
  const addressRef = useRef<HTMLInputElement>(null);

  // In edit mode: which geometry-inputs has the user touched? If anything
  // dirty AND no `pending`, Save is blocked until Recalculate runs so we
  // don't persist a 30-min label on a 15-min polygon.
  const dirtyGeo = useMemo(() => {
    if (!editing || !initial) return false;
    if (mode !== initial.mode) return true;
    const moved = lat !== initial.lat || lng !== initial.lng;
    if (mode === 'travel')  return moved || travelMode !== initial.travelMode || time !== initial.time;
    if (mode === 'radius')  return moved || radiusKm !== initial.radiusKm;
    return false;
  }, [editing, initial, mode, travelMode, time, radiusKm, lat, lng]);

  // Google Places autocomplete.
  useEffect(() => {
    if (!addressRef.current || typeof google === 'undefined' || !google.maps?.places) return;
    const ac = new google.maps.places.Autocomplete(addressRef.current, { fields: ['geometry', 'formatted_address'] });
    const listener = ac.addListener('place_changed', () => {
      const place = ac.getPlace();
      if (!place.geometry?.location) return;
      setLat(place.geometry.location.lat());
      setLng(place.geometry.location.lng());
      setAddress(place.formatted_address ?? '');
      setPending(null);
    });
    return () => {
      google.maps.event.removeListener(listener);
      document.querySelectorAll('.pac-container').forEach((el) => el.remove());
    };
  }, []);

  // Map-pin pick.
  useEffect(() => {
    if (pendingIsochrone && placePinFor === 'isochrone' && pendingIsochrone.lat) {
      setLat(pendingIsochrone.lat);
      setLng(pendingIsochrone.lng);
      setAddress(`${pendingIsochrone.lat.toFixed(4)}, ${pendingIsochrone.lng.toFixed(4)}`);
      setPendingIsochrone(null);
      startDrawing(null);
      setPending(null);
    }
  }, [pendingIsochrone]);

  // Auto-generated name.
  const defaultName = useMemo(() => {
    const head = address ? address.split(',')[0] : '';
    if (mode === 'travel') {
      const tm = TRAVEL_MODES.find((m) => m.value === travelMode)?.label ?? '';
      return head ? `${head} – ${time} min ${tm}` : '';
    }
    if (mode === 'reach')  return head ? `${head} – ${formatNumber(targetPop)} people` : `${formatNumber(targetPop)} people`;
    if (mode === 'radius') return head ? `${head} – ${radiusKm} ${units} radius` : `${radiusKm} ${units} radius`;
    return head ? `${head} – drawn` : 'Drawn area';
  }, [address, mode, travelMode, time, targetPop, radiusKm, units]);
  useEffect(() => {
    // In edit mode, keep the area's original name unless the user manually
    // edited it — otherwise changing the time slider would silently rewrite
    // "Acme HQ – 15 min" to "Acme HQ – 20 min Car" before they hit Save.
    if (editing) return;
    setName(defaultName);
  }, [defaultName, editing]);

  // Draw mode closes the panel and arms the drawing tool — but only when
  // the user actively picks it. In edit mode, the panel can OPEN with a
  // 'manual' (drawn) area and we don't want to immediately re-arm the
  // drawing tool. So gate on the prior mode actually being non-draw.
  const prevMode = useRef(mode);
  useEffect(() => {
    if (mode === 'draw' && prevMode.current !== 'draw' && !editing) {
      startDrawing('polygon');
      toast('Draw your polygon on the map — double-click to finish', { icon: '✏️' });
      onClose();
    }
    prevMode.current = mode;
  }, [mode, editing]);

  async function resolveCenter(): Promise<{ lat: number; lng: number } | null> {
    let useLat = lat, useLng = lng;
    if ((!useLat || !useLng) && address) {
      try {
        const g = await geocodingApi.geocode(address);
        useLat = g.lat; useLng = g.lng;
        setLat(useLat); setLng(useLng);
      } catch { return null; }
    }
    if (!useLat || !useLng) return null;
    return { lat: useLat, lng: useLng };
  }

  function clientCircle(centerLat: number, centerLng: number, kmRadius: number) {
    const points = 64;
    const earth = 6371;
    const latRad = (centerLat * Math.PI) / 180;
    const ring: [number, number][] = [];
    for (let i = 0; i <= points; i++) {
      const bearing = (i / points) * 2 * Math.PI;
      const latOff = (kmRadius / earth) * Math.cos(bearing);
      const lngOff = (kmRadius / earth) * Math.sin(bearing) / Math.cos(latRad);
      ring.push([centerLng + (lngOff * 180) / Math.PI, centerLat + (latOff * 180) / Math.PI]);
    }
    return {
      geometry: { type: 'Polygon', coordinates: [ring] } as any,
      area_sq_km: Math.PI * kmRadius * kmRadius,
      radius_km: kmRadius,
      radius_mi: +(kmRadius * 0.6213712).toFixed(2),
      center: { lat: centerLat, lng: centerLng },
      type: 'radius' as const,
    };
  }

  /** Builds the geometry for the current mode/inputs. Returns the
   *  pending-shape object on success (also stashed in `pending` state so
   *  the map preview renders), or null on failure / no center. Called
   *  inside save() — no separate Calculate button anymore. */
  async function calculate(): Promise<any | null> {
    setPending(null);
    const c = await resolveCenter();
    if (!c) { toast.error('Enter an address or pick a point first'); return null; }
    setCalculating(true);
    let result: any | null = null;
    try {
      if (mode === 'travel') {
        const r = await isochroneApi.calculate({ lat: c.lat, lng: c.lng, time_minutes: time, travel_mode: travelMode });
        result = { type: 'isochrone', geojson: r.geojson, area_sq_km: r.area_sq_km };
        setPending(result);
        fitBoundsToArea(r.geojson);
      } else if (mode === 'reach') {
        const r = await reachApi.calculate(c.lat, c.lng, targetPop);
        result = {
          type: 'radius', geojson: r.geometry,
          area_sq_km: r.area_sq_km, radius_km: r.radius_km, radius_mi: r.radius_mi,
          center: r.center,
        };
        setPending(result);
        fitBoundsToArea(r.geometry);
      } else if (mode === 'radius') {
        const km = units === 'mi' ? radiusKm / 0.6213712 : radiusKm;
        const r = clientCircle(c.lat, c.lng, km);
        result = { type: 'radius', geojson: r.geometry, area_sq_km: r.area_sq_km, radius_km: r.radius_km, radius_mi: r.radius_mi };
        setPending(result);
        fitBoundsToArea(r.geometry);
      }
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Calculation failed');
      return null;
    } finally { setCalculating(false); }
    return result;
  }

  async function save() {
    // One-button flow: if we don't already have geometry (create) or the
    // user changed something that affects shape (edit + dirtyGeo), compute
    // it inline before persisting. Saves the operator the explicit
    // "Calculate, then Save" two-step.
    let geom = pending;
    if (!geom && (!editing || dirtyGeo)) {
      geom = await calculate();
      if (!geom) return; // toast already shown by calculate()
    }

    // Edit path — PATCH the existing area. Meta (color/opacity/name/notes/
    // folder) always goes; geometry + travel fields only when we re-ran
    // calculate above (i.e. dirtyGeo).
    if (editing) {
      setSaving(true);
      try {
        const patch: any = {
          name: name.trim() || editing.name,
          fill_color: color,
          stroke_color: color,
          fill_opacity: opacity,
          notes: notes.trim() || null,
          folder_id: folderId,
        };
        if (geom && lat != null && lng != null) {
          patch.geometry = geom.geojson;
          patch.center_lat = lat;
          patch.center_lng = lng;
          patch.center_address = address || null;
          if (geom.type === 'isochrone') {
            patch.travel_mode = travelMode;
            patch.travel_time_minutes = time;
            patch.travel_distance_km = null;
          } else {
            patch.travel_mode = null;
            patch.travel_time_minutes = null;
            patch.travel_distance_km = geom.radius_km ?? null;
          }
        }
        const a = await areasApi.update(editing.id, patch);
        // Re-apply geometry locally so the map updates without a refetch.
        updateArea({ ...editing, ...a, geometry: geom?.geojson ?? editing.geometry } as Area);
        pushRecentColor(color);
        if (geom) fitBoundsToArea(geom.geojson);
        toast.success('Area updated');
        onClose();
      } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Update failed');
      } finally { setSaving(false); }
      return;
    }

    // Create path.
    if (!geom || !currentProject || lat == null || lng == null) return;
    setSaving(true);
    try {
      const a = await areasApi.create(currentProject.id, {
        name: name || 'Untitled area',
        area_type: geom.type === 'isochrone' ? 'isochrone' : 'radius',
        center_lat: lat, center_lng: lng, center_address: address,
        travel_mode: geom.type === 'isochrone' ? travelMode : null,
        travel_time_minutes: geom.type === 'isochrone' ? time : null,
        travel_distance_km: geom.type === 'radius' ? geom.radius_km : null,
        fill_color: color, stroke_color: color, fill_opacity: opacity,
        geometry: geom.geojson,
        folder_id: folderId,
        notes: notes || null,
      } as any);
      addArea({ ...a, geometry: geom.geojson } as Area);
      pushRecentColor(color);
      toast.success('Area saved');
      onClose();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Save failed');
    } finally { setSaving(false); }
  }

  // Close on Escape + click-outside. Drawing mode (pin pick, polygon draw)
  // is exempt from outside-close — otherwise the very next click on the
  // map (to drop the pin) would dismiss the panel before the drawing tool
  // received the click. Toasts and react-portal popovers are also exempt
  // so quick UI feedback doesn't kill the panel.
  const panelRef = useRef<HTMLElement>(null);
  useEffect(() => {
    function onKey(e: KeyboardEvent) { if (e.key === 'Escape') onClose(); }
    function onDown(e: MouseEvent) {
      if (useMapStore.getState().drawingType) return;
      const target = e.target as Node | null;
      if (!target) return;
      if (panelRef.current?.contains(target)) return;
      // Tray/popovers Google Places injects (pac-container) and react-hot-
      // toast notifications live outside the panel but should NOT count as
      // outside clicks.
      const el = target as HTMLElement;
      if (el.closest && (el.closest('.pac-container') || el.closest('[data-react-hot-toast]'))) return;
      onClose();
    }
    window.addEventListener('keydown', onKey);
    document.addEventListener('mousedown', onDown);
    return () => {
      window.removeEventListener('keydown', onKey);
      document.removeEventListener('mousedown', onDown);
    };
  }, [onClose]);

  return (
    <aside
      ref={panelRef}
      // top-10 (40px) is the real fix here — at top-6 the white panel
      // header was butted up against the white AppNav with only the navbar
      // shadow between them, which read as "the card is cut off". 4rem of
      // bottom margin so the action bar never hugs the viewport edge either.
      className="absolute top-10 max-h-[calc(100%-4rem)] w-[380px] bg-white rounded-xl shadow-float border border-slate-200 flex flex-col overflow-hidden z-20 panel-slide-in-l"
      style={{ left: 'calc(360px + 1rem + 8px)' }}
      onClick={(e) => e.stopPropagation()}
    >
      {/* Slide-in keyframe defined inline so we don't need a global edit. */}
      <style>{`
        @keyframes panelSlideInL {
          from { opacity: 0; transform: translateX(-24px) scale(0.99); }
          to   { opacity: 1; transform: translateX(0)     scale(1); }
        }
        .panel-slide-in-l { animation: panelSlideInL 320ms cubic-bezier(0.16, 1, 0.3, 1); }
      `}</style>

      {/* 2px violet accent strip at the very top so the panel never visually
          fuses with the white AppNav above it. */}
      <div className="h-[3px] shrink-0" style={{ background: '#7848BB' }} aria-hidden="true" />
      <header className="px-4 py-3.5 flex items-center justify-between shrink-0 border-b border-slate-100 bg-white">
        <div className="flex items-center gap-2 font-extrabold text-base" style={{ color: '#1A1A2E' }}>
          {editing ? <Pencil size={16} style={{ color: '#7848BB' }} /> : <Sparkles size={16} style={{ color: '#7848BB' }} />}
          {editing ? 'Edit area' : 'Create area'}
        </div>
        <button
          onClick={onClose}
          className="-mr-1 p-1.5 rounded-md text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition-colors"
          title="Close (Esc)"
        >
          <X size={18} />
        </button>
      </header>

      <div className="overflow-y-auto flex-1 px-3 py-2.5 space-y-2">
        {/* Mode picker — hidden in edit mode. Converting an isochrone into a
            radius (or vice versa) would orphan the original geometry; the
            cleaner path is delete + recreate. */}
        {!editing && (
        <div className="grid grid-cols-2 gap-1.5">
          {MODES.map((m) => {
            const Active = mode === m.key;
            const Icon = m.icon;
            return (
              <button
                key={m.key}
                type="button"
                onClick={() => { setMode(m.key); setPending(null); }}
                className={`text-left px-2.5 py-1.5 rounded-lg border transition-all ${
                  Active ? 'border-violet-500 bg-violet-50 ring-2 ring-violet-200'
                         : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'
                }`}
              >
                <div className="flex items-center gap-1.5">
                  <Icon size={13} className={Active ? 'text-violet-700' : 'text-slate-500'} />
                  <span className={`text-[12px] font-bold ${Active ? 'text-violet-800' : 'text-slate-800'}`}>{m.label}</span>
                </div>
                <div className="text-[10px] text-slate-500 mt-0.5 leading-snug">{m.sub}</div>
              </button>
            );
          })}
        </div>
        )}

        {/* Address — no leading icon so the placeholder + autocomplete text
            sit flush left. The magnifier was visually crowding the input. */}
        <div>
          <label className="label">Starting point</label>
          <div className="flex gap-1.5">
            <input
              ref={addressRef}
              className="input flex-1 h-9 text-sm px-3"
              placeholder="Address, intersection, or city"
              value={address}
              onChange={(e) => { setAddress(e.target.value); setLat(null); setLng(null); setPending(null); }}
            />
            <button
              type="button"
              className="btn btn-secondary px-2.5 h-9"
              title="Pick on map"
              onClick={() => { startDrawing('pin', 'isochrone'); toast('Click on the map to set center'); }}
            >
              <MapPin size={13} />
            </button>
          </div>
        </div>

        {/* Per-mode controls */}
        {mode === 'travel' && (
          <>
            <div>
              <label className="label">Travel mode</label>
              <div className="grid grid-cols-3 gap-1.5">
                {TRAVEL_MODES.map((m) => {
                  const Active = travelMode === m.value;
                  return (
                    <button
                      key={m.value}
                      type="button"
                      onClick={() => { setTravelMode(m.value); setPending(null); }}
                      className={`px-2 py-1.5 rounded-lg border text-sm font-semibold flex items-center justify-center gap-1 transition ${
                        Active ? 'border-violet-500 bg-violet-50 text-violet-800' : 'border-slate-200 hover:bg-slate-50'
                      }`}
                    >
                      <span>{m.icon}</span> {m.label}
                    </button>
                  );
                })}
              </div>
            </div>
            <div>
              <label className="label flex items-center justify-between">
                <span>Travel time</span>
                <span className="flex items-baseline gap-1">
                  <input
                    type="number" min={1} max={60} step={1}
                    value={time}
                    onChange={(e) => {
                      const n = parseInt(e.target.value, 10);
                      if (Number.isNaN(n)) return;
                      setTime(Math.max(1, Math.min(60, n)));
                      setPending(null);
                    }}
                    className="w-12 text-right text-violet-700 font-extrabold text-base tabular-nums bg-white border border-slate-200 rounded px-1 py-0.5 focus:outline-none focus:ring-1 focus:ring-violet-400"
                  />
                  <span className="text-violet-700 font-bold text-[11px]">min</span>
                </span>
              </label>
              <input type="range" min={1} max={60} value={time} onChange={(e) => { setTime(+e.target.value); setPending(null); }} className="w-full accent-violet-600" />
              <div className="flex gap-1 mt-1 flex-wrap">
                {TIME_PRESETS.map((p) => (
                  <button
                    key={p}
                    type="button"
                    className={`text-[11px] px-2 py-0.5 rounded-full font-semibold transition ${
                      time === p ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                    }`}
                    onClick={() => { setTime(p); setPending(null); }}
                  >{p}m</button>
                ))}
              </div>
            </div>
          </>
        )}

        {mode === 'reach' && (
          <div>
            <label className="label flex items-center justify-between">
              <span>Target population</span>
              <span className="text-violet-700 font-extrabold text-base tabular-nums">{formatNumber(targetPop)}</span>
            </label>
            <input
              type="range" min={500} max={1_000_000} step={500}
              value={targetPop} onChange={(e) => { setTargetPop(+e.target.value); setPending(null); }}
              className="w-full accent-violet-600"
            />
            <div className="flex gap-1 mt-1 flex-wrap">
              {POP_PRESETS.map((p) => (
                <button
                  key={p}
                  type="button"
                  className={`text-[11px] px-2 py-0.5 rounded-full font-semibold transition ${
                    targetPop === p ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                  onClick={() => { setTargetPop(p); setPending(null); }}
                >{p >= 1000 ? `${p / 1000}K` : p}</button>
              ))}
            </div>
          </div>
        )}

        {mode === 'radius' && (
          <div>
            <label className="label flex items-center justify-between">
              <span>Radius</span>
              <span className="flex items-center gap-2">
                <input
                  type="number" min={0.1} step={0.1}
                  value={radiusKm}
                  onChange={(e) => {
                    const n = parseFloat(e.target.value);
                    if (Number.isNaN(n) || n <= 0) return;
                    setRadiusKm(n);
                    setPending(null);
                  }}
                  className="w-16 text-right text-violet-700 font-extrabold text-base tabular-nums bg-white border border-slate-200 rounded px-1 py-0.5 focus:outline-none focus:ring-1 focus:ring-violet-400"
                />
                <div className="bg-slate-100 rounded p-0.5 flex text-[10px] font-bold">
                  {(['km', 'mi'] as const).map((u) => (
                    <button
                      key={u}
                      type="button"
                      onClick={() => { setUnits(u); setPending(null); }}
                      className={`px-2 py-0.5 rounded ${units === u ? 'bg-white text-violet-700 shadow-sm' : 'text-slate-500'}`}
                    >{u}</button>
                  ))}
                </div>
              </span>
            </label>
            <input
              type="range" min={0.5} max={units === 'mi' ? 100 : 200} step={0.5}
              value={radiusKm} onChange={(e) => { setRadiusKm(+e.target.value); setPending(null); }}
              className="w-full accent-violet-600"
            />
            <div className="flex gap-1 mt-1 flex-wrap">
              {RADIUS_KM_PRESETS.map((p) => (
                <button
                  key={p}
                  type="button"
                  className={`text-[11px] px-2 py-0.5 rounded-full font-semibold transition ${
                    radiusKm === p ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                  onClick={() => { setRadiusKm(p); setPending(null); }}
                >{p}{units}</button>
              ))}
            </div>
          </div>
        )}

        {/* Color — compact 12-col strip (2 rows for the 24-color brand
            palette + any recent picks the user hasn't seen in it yet).
            The separate Recent block was the single biggest source of
            vertical weight in the old layout. */}
        <div>
          <label className="label flex items-center justify-between">
            <span>Color</span>
            <span
              className="inline-block w-4 h-4 rounded-full border border-black/10"
              style={{ background: color }}
            />
          </label>
          <div className="grid grid-cols-12 gap-1">
            {dedupeColors([...recentColors, ...AREA_PALETTE_NAMED.map((c) => c.hex)]).map((hex) => {
              const Active = color.toLowerCase() === hex.toLowerCase();
              const name = AREA_PALETTE_NAMED.find((c) => c.hex.toLowerCase() === hex.toLowerCase())?.name ?? hex;
              return (
                <button
                  key={hex}
                  type="button"
                  title={name}
                  className={`h-5 rounded border border-black/10 transition-transform ${
                    Active ? 'ring-2 ring-slate-700 ring-offset-1' : 'hover:scale-110'
                  }`}
                  style={{ background: hex }}
                  onClick={() => setColor(hex)}
                />
              );
            })}
          </div>
        </div>

        {/* Opacity — easy to overlook in the create flow but the most-asked
            edit on saved areas (operator wants the underlying basemap to
            show through more or less). */}
        <div>
          <label className="label flex items-center justify-between">
            <span>Fill opacity</span>
            <span className="text-violet-700 font-extrabold text-xs tabular-nums">{Math.round(opacity * 100)}%</span>
          </label>
          <input
            type="range" min={0.05} max={1} step={0.05}
            value={opacity} onChange={(e) => setOpacity(+e.target.value)}
            className="w-full accent-violet-600"
          />
        </div>

        {/* Folder + notes */}
        {folders && folders.length > 0 && (
          <div>
            <label className="label flex items-center gap-1"><FolderIcon size={10} /> Folder</label>
            <div className="relative">
              <select
                className="input h-9 pr-8 text-sm appearance-none"
                value={folderId ?? ''}
                onChange={(e) => setFolderId(e.target.value || null)}
              >
                <option value="">— No folder —</option>
                {flattenFolders(folders).map((f: any) => (
                  <option key={f.id} value={f.id}>{f.indent}{f.name}</option>
                ))}
              </select>
              <ChevronDown size={11} className="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
            </div>
          </div>
        )}
        <div>
          <label className="label flex items-center gap-1"><FileText size={10} /> Notes (optional)</label>
          <textarea
            className="textarea text-sm"
            rows={2}
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="Hypothesis, stakeholder, follow-up…"
          />
        </div>
        <div>
          <label className="label">Name</label>
          <input className="input h-9 text-sm" value={name} onChange={(e) => setName(e.target.value)} />
        </div>
      </div>

      {/* Single-button action bar. Save auto-calculates the polygon (or
          recalculates it if the user changed time/mode/radius) before
          persisting — no separate Calculate step. */}
      <div className="px-3 py-2 border-t border-slate-100 shrink-0">
        <button
          className="btn btn-primary w-full justify-center h-9"
          onClick={save}
          disabled={saving || calculating || (!address && (lat == null || lng == null))}
        >
          {calculating ? <><Loader2 size={13} className="animate-spin" /> Calculating…</>
            : saving   ? <><Loader2 size={13} className="animate-spin" /> Saving…</>
            : editing  ? 'Update area' : 'Save area'}
        </button>
      </div>
    </aside>
  );
}

function dedupeColors(hexes: string[]): string[] {
  const seen = new Set<string>();
  const out: string[] = [];
  for (const h of hexes) {
    const k = h.toLowerCase();
    if (seen.has(k)) continue;
    seen.add(k);
    out.push(h);
  }
  return out;
}

function flattenFolders(folders: any[], depth = 0): { id: string; name: string; indent: string }[] {
  const out: any[] = [];
  for (const f of folders ?? []) {
    out.push({ id: f.id, name: f.name, indent: '— '.repeat(depth) });
    if (f.children?.length) out.push(...flattenFolders(f.children, depth + 1));
  }
  return out;
}
