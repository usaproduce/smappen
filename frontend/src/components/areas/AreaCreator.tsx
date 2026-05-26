import { useEffect, useMemo, useRef, useState } from 'react';
import {
  X, MapPin, Users, Clock, Circle, PenSquare, FileText,
  Search, Folder as FolderIcon, ChevronDown, Sparkles, Loader2, Pencil,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { isochroneApi } from '../../api/isochrone';
import { areasApi } from '../../api/areas';
import { geocodingApi } from '../../api/geocoding';
import { reachApi } from '../../api/reach';
import { useProjectStore } from '../../stores/projectStore';
import { useMapStore } from '../../stores/mapStore';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';
import { AREA_PALETTE_NAMED, contrastInk } from '../../utils/colors';
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

  async function calculate() {
    setPending(null);
    const c = await resolveCenter();
    if (!c) { toast.error('Enter an address or pick a point first'); return; }
    setCalculating(true);
    try {
      if (mode === 'travel') {
        const r = await isochroneApi.calculate({ lat: c.lat, lng: c.lng, time_minutes: time, travel_mode: travelMode });
        setPending({ type: 'isochrone', geojson: r.geojson, area_sq_km: r.area_sq_km });
        fitBoundsToArea(r.geojson);
        toast.success(`~${r.area_sq_km.toFixed(1)} km² ready — Save to keep`);
      } else if (mode === 'reach') {
        const r = await reachApi.calculate(c.lat, c.lng, targetPop);
        setPending({
          type: 'radius', geojson: r.geometry,
          area_sq_km: r.area_sq_km, radius_km: r.radius_km, radius_mi: r.radius_mi,
          center: r.center,
        });
        fitBoundsToArea(r.geometry);
        toast.success(`${r.radius_km} km · ${formatNumber(r.population)} people — Save to keep`);
      } else if (mode === 'radius') {
        const km = units === 'mi' ? radiusKm / 0.6213712 : radiusKm;
        const r = clientCircle(c.lat, c.lng, km);
        setPending({ type: 'radius', geojson: r.geometry, area_sq_km: r.area_sq_km, radius_km: r.radius_km, radius_mi: r.radius_mi });
        fitBoundsToArea(r.geometry);
      }
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Calculation failed');
    } finally { setCalculating(false); }
  }

  async function save() {
    // Edit path — PATCH the existing area. Meta (color/opacity/name/notes/
    // folder) always goes; geometry + travel fields only when the user
    // actually recomputed (pending set).
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
        if (pending && lat != null && lng != null) {
          patch.geometry = pending.geojson;
          patch.center_lat = lat;
          patch.center_lng = lng;
          patch.center_address = address || null;
          if (pending.type === 'isochrone') {
            patch.travel_mode = travelMode;
            patch.travel_time_minutes = time;
            patch.travel_distance_km = null;
          } else {
            patch.travel_mode = null;
            patch.travel_time_minutes = null;
            patch.travel_distance_km = pending.radius_km ?? null;
          }
        }
        const a = await areasApi.update(editing.id, patch);
        // Re-apply geometry locally so the map updates without a refetch —
        // server returns the updated row but the store wants geometry
        // alongside it, and `a` may not include it depending on the endpoint.
        updateArea({ ...editing, ...a, geometry: pending?.geojson ?? editing.geometry } as Area);
        pushRecentColor(color);
        if (pending) fitBoundsToArea(pending.geojson);
        toast.success('Area updated');
        onClose();
      } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Update failed');
      } finally { setSaving(false); }
      return;
    }

    // Create path — original behavior.
    if (!pending || !currentProject || lat == null || lng == null) return;
    setSaving(true);
    try {
      const a = await areasApi.create(currentProject.id, {
        name: name || 'Untitled area',
        area_type: pending.type === 'isochrone' ? 'isochrone' : 'radius',
        center_lat: lat, center_lng: lng, center_address: address,
        travel_mode: pending.type === 'isochrone' ? travelMode : null,
        travel_time_minutes: pending.type === 'isochrone' ? time : null,
        travel_distance_km: pending.type === 'radius' ? pending.radius_km : null,
        fill_color: color, stroke_color: color, fill_opacity: opacity,
        geometry: pending.geojson,
        folder_id: folderId,
        notes: notes || null,
      } as any);
      addArea({ ...a, geometry: pending.geojson } as Area);
      pushRecentColor(color);
      toast.success('Area saved');
      onClose();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Save failed');
    } finally { setSaving(false); }
  }

  return (
    <aside
      className="absolute top-4 max-h-[calc(100%-2rem)] w-[380px] bg-white rounded-xl shadow-float border border-slate-200 flex flex-col overflow-hidden z-20 panel-slide-in-l"
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

      <header
        className="px-4 py-3 flex items-center justify-between shrink-0"
        style={{ background: 'linear-gradient(135deg, #F57C00 0%, #E53935 50%, #7848BB 100%)' }}
      >
        <div className="flex items-center gap-2 text-white font-extrabold text-[15px]">
          {editing ? <Pencil size={15} /> : <Sparkles size={15} />}
          {editing ? 'Edit area' : 'Create area'}
        </div>
        <button onClick={onClose} className="text-white/85 hover:text-white"><X size={15} /></button>
      </header>

      <div className="overflow-y-auto flex-1 p-3 space-y-3">
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

        {/* Address */}
        <div>
          <label className="label">Starting point</label>
          <div className="flex gap-1.5">
            <div className="relative flex-1">
              <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
              <input
                ref={addressRef}
                className="input pl-8 h-9 text-sm"
                placeholder="Address, intersection, or city"
                value={address}
                onChange={(e) => { setAddress(e.target.value); setLat(null); setLng(null); setPending(null); }}
              />
            </div>
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
                <span className="text-violet-700 font-extrabold text-base tabular-nums">{time} min</span>
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
                <span className="text-violet-700 font-extrabold text-base tabular-nums">{radiusKm} {units}</span>
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

        {/* Color palette */}
        <div>
          <label className="label">Color</label>
          {recentColors.length > 0 && (
            <div className="mb-1.5">
              <div className="text-[9px] uppercase font-bold tracking-wider text-slate-400 mb-1">Recent</div>
              <div className="flex gap-1.5 flex-wrap">
                {recentColors.map((c) => (
                  <button
                    key={c}
                    type="button"
                    className={`w-5 h-5 rounded-full transition-transform border border-black/10 ${
                      color === c ? 'ring-2 ring-offset-1 ring-slate-700 scale-110' : 'hover:scale-110'
                    }`}
                    style={{ background: c }}
                    onClick={() => setColor(c)}
                  />
                ))}
              </div>
            </div>
          )}
          <div className="grid grid-cols-6 gap-1">
            {AREA_PALETTE_NAMED.map((c) => {
              const Active = color === c.hex;
              return (
                <button
                  key={c.hex}
                  type="button"
                  title={c.name}
                  className={`relative aspect-square rounded-md transition-transform border border-black/10 ${
                    Active ? 'ring-2 ring-offset-1 ring-slate-700 scale-105' : 'hover:scale-105'
                  }`}
                  style={{ background: c.hex }}
                  onClick={() => setColor(c.hex)}
                >
                  {Active && (
                    <span
                      className="absolute inset-0 flex items-center justify-center text-[9px] font-extrabold"
                      style={{ color: contrastInk(c.hex) }}
                    >✓</span>
                  )}
                </button>
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

      {/* Action bar — Calculate / Save / Cancel (creator) or
                       Recalculate / Update (editor). */}
      <div className="px-3 py-2.5 border-t border-slate-100 flex gap-2 shrink-0">
        {editing ? (
          <>
            {dirtyGeo && !pending && (
              <button
                className="btn btn-secondary h-9 px-3"
                onClick={calculate}
                disabled={calculating || (!address && (lat == null || lng == null))}
                title="Recalculate the polygon for the new drive time / radius"
              >
                {calculating ? <><Loader2 size={13} className="animate-spin" /> Recalc…</> : 'Recalculate'}
              </button>
            )}
            {pending && (
              <button className="btn btn-secondary h-9 px-3" onClick={() => setPending(null)} title="Discard the new shape and keep the saved one">
                Reset shape
              </button>
            )}
            <button
              className="btn btn-primary flex-1 justify-center h-9"
              disabled={saving || (dirtyGeo && !pending)}
              onClick={save}
              title={dirtyGeo && !pending ? 'Click Recalculate first to update the polygon' : undefined}
            >
              {saving ? <><Loader2 size={13} className="animate-spin" /> Saving…</> : 'Update area'}
            </button>
          </>
        ) : !pending ? (
          <button
            className="btn btn-primary flex-1 justify-center h-9"
            onClick={calculate}
            disabled={calculating || (!address && (lat == null || lng == null))}
          >
            {calculating ? <><Loader2 size={13} className="animate-spin" /> Calculating…</> : 'Calculate'}
          </button>
        ) : (
          <>
            <button className="btn btn-secondary h-9 px-3" onClick={() => setPending(null)}>Recalc</button>
            <button className="btn btn-primary flex-1 justify-center h-9" disabled={saving} onClick={save}>
              {saving ? <><Loader2 size={13} className="animate-spin" /> Saving…</> : 'Save area'}
            </button>
          </>
        )}
      </div>
    </aside>
  );
}

function flattenFolders(folders: any[], depth = 0): { id: string; name: string; indent: string }[] {
  const out: any[] = [];
  for (const f of folders ?? []) {
    out.push({ id: f.id, name: f.name, indent: '— '.repeat(depth) });
    if (f.children?.length) out.push(...flattenFolders(f.children, depth + 1));
  }
  return out;
}
