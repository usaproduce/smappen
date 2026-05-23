import { useEffect, useMemo, useRef, useState } from 'react';
import { X, MapPin, Users, Clock } from 'lucide-react';
import toast from 'react-hot-toast';
import { isochroneApi } from '../../api/isochrone';
import { areasApi } from '../../api/areas';
import { geocodingApi } from '../../api/geocoding';
import { reachApi, type DemoPreview } from '../../api/reach';
import { useProjectStore } from '../../stores/projectStore';
import { useMapStore } from '../../stores/mapStore';
import { AREA_PALETTE } from '../../utils/colors';
import { formatNumber, formatCurrency } from '../../utils/format';

type Mode = 'travel' | 'reach';

const TRAVEL_MODES = [
  { value: 'driving-car', label: 'Car' },
  { value: 'cycling-regular', label: 'Bike' },
  { value: 'foot-walking', label: 'Walk' },
] as const;
const TIME_PRESETS = [5, 10, 15, 20, 30, 45, 60];
const POP_PRESETS = [5000, 10000, 25000, 50000, 100000, 250000];

export default function AreaCreator({ onClose }: { onClose: () => void }) {
  const { currentProject, addArea } = useProjectStore();
  const { fitBoundsToArea, startDrawing, placePinFor, pendingIsochrone, setPendingIsochrone } = useMapStore();

  const [mode, setMode] = useState<Mode>('travel');
  const [address, setAddress] = useState('');
  const [lat, setLat] = useState<number | null>(null);
  const [lng, setLng] = useState<number | null>(null);

  const [travelMode, setTravelMode] = useState<'driving-car' | 'cycling-regular' | 'foot-walking'>('driving-car');
  const [time, setTime] = useState(15);
  const [targetPop, setTargetPop] = useState(25000);

  const [name, setName] = useState('');
  const [color, setColor] = useState(AREA_PALETTE[0]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [preview, setPreview] = useState<any | null>(null);
  const [demo, setDemo] = useState<DemoPreview | null>(null);
  const addressRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!addressRef.current || typeof google === 'undefined' || !google.maps?.places) return;
    const ac = new google.maps.places.Autocomplete(addressRef.current, { fields: ['geometry', 'formatted_address'] });
    const listener = ac.addListener('place_changed', () => {
      const place = ac.getPlace();
      if (!place.geometry?.location) return;
      setLat(place.geometry.location.lat());
      setLng(place.geometry.location.lng());
      setAddress(place.formatted_address ?? '');
    });
    return () => {
      google.maps.event.removeListener(listener);
      document.querySelectorAll('.pac-container').forEach((el) => el.remove());
    };
  }, []);

  useEffect(() => {
    if (pendingIsochrone && placePinFor === 'isochrone' && pendingIsochrone.lat) {
      setLat(pendingIsochrone.lat);
      setLng(pendingIsochrone.lng);
      setAddress(`${pendingIsochrone.lat.toFixed(4)}, ${pendingIsochrone.lng.toFixed(4)}`);
      setPendingIsochrone(null);
      startDrawing(null);
    }
  }, [pendingIsochrone]);

  const defaultName = useMemo(() => {
    const head = address ? address.split(',')[0] : '';
    if (mode === 'travel') {
      const tm = TRAVEL_MODES.find((m) => m.value === travelMode)?.label ?? '';
      return head ? `${head} – ${time} min ${tm}` : '';
    }
    return head ? `${head} – ${formatNumber(targetPop)} people` : `${formatNumber(targetPop)} people`;
  }, [address, mode, travelMode, time, targetPop]);
  useEffect(() => { setName(defaultName); }, [defaultName]);

  async function resolveCenter() {
    let useLat = lat, useLng = lng;
    if ((!useLat || !useLng) && address) {
      try {
        const g = await geocodingApi.geocode(address);
        useLat = g.lat; useLng = g.lng;
        setLat(useLat); setLng(useLng);
      } catch {
        toast.error('Geocoding failed');
        return null;
      }
    }
    if (!useLat || !useLng) { toast.error('Address or pin required'); return null; }
    return { lat: useLat, lng: useLng };
  }

  async function loadDemoPreview(geometry: any) {
    try { setDemo(await reachApi.previewGeometry(geometry)); }
    catch { setDemo(null); }
  }

  async function calculate() {
    setPreview(null); setDemo(null);
    const c = await resolveCenter(); if (!c) return;
    setLoading(true);

    if (mode === 'travel') {
      const toastId = toast.loading(time > 30 ? `Calculating ${time}-min isochrone (may take 10-15s)…` : `Calculating ${time}-min isochrone…`);
      try {
        const r = await isochroneApi.calculate({ lat: c.lat, lng: c.lng, time_minutes: time, travel_mode: travelMode });
        setPreview({ ...r, type: 'isochrone' });
        fitBoundsToArea(r.geojson);
        toast.success(`~${r.area_sq_km.toFixed(1)} sq km`, { id: toastId });
        loadDemoPreview(r.geojson);
      } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Isochrone failed', { id: toastId });
      } finally { setLoading(false); }
    } else {
      const toastId = toast.loading(`Finding smallest area for ${formatNumber(targetPop)} people…`);
      try {
        const r = await reachApi.calculate(c.lat, c.lng, targetPop);
        setPreview({
          type: 'radius', geojson: r.geometry,
          area_sq_km: r.area_sq_km, radius_km: r.radius_km, radius_mi: r.radius_mi,
          center: r.center, population: r.population,
        });
        fitBoundsToArea(r.geometry);
        toast.success(`${r.radius_km} km · ${formatNumber(r.population)} people`, { id: toastId });
        loadDemoPreview(r.geometry);
      } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Reach calculation failed', { id: toastId });
      } finally { setLoading(false); }
    }
  }

  async function save() {
    if (!preview || !currentProject || lat == null || lng == null) return;
    setSaving(true);
    try {
      const a = await areasApi.create(currentProject.id, {
        name: name || 'Untitled area',
        area_type: preview.type === 'radius' ? 'radius' : 'isochrone',
        center_lat: lat, center_lng: lng, center_address: address,
        travel_mode: preview.type === 'radius' ? null : travelMode,
        travel_time_minutes: preview.type === 'radius' ? null : time,
        travel_distance_km: preview.type === 'radius' ? preview.radius_km : null,
        fill_color: color, stroke_color: color,
        geometry: preview.geojson,
      } as any);
      addArea({ ...a, geometry: preview.geojson } as any);
      toast.success('Area saved');
      onClose();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Save failed');
    } finally { setSaving(false); }
  }

  return (
    <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 p-4" onClick={onClose}>
      <div className="bg-white rounded-xl shadow-xl max-w-md w-full max-h-[90vh] flex flex-col" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between p-4 border-b border-slate-100">
          <h2 className="font-bold text-base" style={{ color: '#1A1A2E' }}>Create area</h2>
          <button className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-slate-50" onClick={onClose}><X size={16} /></button>
        </div>

        <div className="p-4 space-y-3 overflow-y-auto">
          <div className="flex bg-slate-100 rounded-lg p-1">
            <button type="button"
              className={`flex-1 py-1.5 text-xs font-semibold rounded-md inline-flex items-center justify-center gap-1.5 transition-colors ${mode === 'travel' ? 'bg-white shadow-sm text-violet-700' : 'text-slate-600 hover:text-slate-800'}`}
              onClick={() => { setMode('travel'); setPreview(null); setDemo(null); }}>
              <Clock size={13} /> Travel time
            </button>
            <button type="button"
              className={`flex-1 py-1.5 text-xs font-semibold rounded-md inline-flex items-center justify-center gap-1.5 transition-colors ${mode === 'reach' ? 'bg-white shadow-sm text-violet-700' : 'text-slate-600 hover:text-slate-800'}`}
              onClick={() => { setMode('reach'); setPreview(null); setDemo(null); }}>
              <Users size={13} /> Reach population
            </button>
          </div>

          <div>
            <label className="label">Starting address</label>
            <div className="flex gap-2">
              <input ref={addressRef} className="input-address flex-1" placeholder="Starting address (e.g. London)"
                value={address} onChange={(e) => setAddress(e.target.value)} />
              <button type="button" className="btn btn-secondary px-3" title="Pick on map"
                onClick={() => { startDrawing('pin', 'isochrone'); toast('Click on the map to set center'); }}>
                <MapPin size={14} />
              </button>
            </div>
          </div>

          {mode === 'travel' && (
            <>
              <div>
                <label className="label">Travel mode</label>
                <div className="flex gap-2">
                  {TRAVEL_MODES.map((m) => (
                    <button key={m.value} type="button"
                      className={`btn ${travelMode === m.value ? 'btn-primary' : 'btn-secondary'} flex-1 justify-center text-xs`}
                      onClick={() => setTravelMode(m.value)}>{m.label}</button>
                  ))}
                </div>
              </div>
              <div>
                <label className="label">Time: {time} min</label>
                <input type="range" min={1} max={120} value={time} onChange={(e) => setTime(+e.target.value)} className="w-full accent-violet-600" />
                <div className="flex gap-1 mt-1 flex-wrap">
                  {TIME_PRESETS.map((p) => (
                    <button key={p} type="button"
                      className={`text-xs px-2 py-0.5 rounded ${time === p ? 'bg-violet-100 text-violet-700 font-semibold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
                      onClick={() => setTime(p)}>{p}m</button>
                  ))}
                </div>
              </div>
            </>
          )}

          {mode === 'reach' && (
            <div>
              <label className="label">Target population: {formatNumber(targetPop)}</label>
              <input type="range" min={500} max={500000} step={500} value={targetPop}
                onChange={(e) => setTargetPop(+e.target.value)} className="w-full accent-violet-600" />
              <div className="flex gap-1 mt-1 flex-wrap">
                {POP_PRESETS.map((p) => (
                  <button key={p} type="button"
                    className={`text-xs px-2 py-0.5 rounded ${targetPop === p ? 'bg-violet-100 text-violet-700 font-semibold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
                    onClick={() => setTargetPop(p)}>{p >= 1000 ? `${p / 1000}K` : p}</button>
                ))}
              </div>
              <div className="text-[11px] text-slate-500 mt-1.5">
                Smappen will find the smallest circle that contains at least this many people, using Census population by tract.
              </div>
            </div>
          )}

          {preview && (
            <div className="rounded-lg border border-slate-200 p-3 space-y-1.5 bg-slate-50">
              <div className="text-[10px] uppercase font-bold tracking-wider text-slate-500">Preview</div>
              <div className="flex justify-between text-sm">
                <span className="text-slate-600">Area</span>
                <span className="font-semibold">{preview.area_sq_km.toFixed(1)} km² · {(preview.area_sq_km * 0.386).toFixed(1)} mi²</span>
              </div>
              {preview.type === 'radius' && (
                <div className="flex justify-between text-sm">
                  <span className="text-slate-600">Radius</span>
                  <span className="font-semibold">{preview.radius_km} km · {preview.radius_mi} mi</span>
                </div>
              )}
              {demo && (
                <>
                  <div className="flex justify-between text-sm">
                    <span className="text-slate-600">Population (est.)</span>
                    <span className="font-semibold">{formatNumber(demo.population)}</span>
                  </div>
                  {demo.median_household_income !== null && (
                    <div className="flex justify-between text-sm">
                      <span className="text-slate-600">Median income</span>
                      <span className="font-semibold">{formatCurrency(demo.median_household_income)}</span>
                    </div>
                  )}
                  <div className="flex justify-between text-[11px] text-slate-500">
                    <span>Density</span>
                    <span>{formatNumber(demo.density_per_sq_km)} /km²</span>
                  </div>
                </>
              )}
              {!demo && <div className="text-[11px] text-slate-400 italic">Loading demographics…</div>}
            </div>
          )}

          <div>
            <label className="label">Name</label>
            <input className="input" value={name} onChange={(e) => setName(e.target.value)} />
          </div>

          <div>
            <label className="label">Color</label>
            <div className="flex flex-wrap gap-2">
              {AREA_PALETTE.map((c) => (
                <button key={c} type="button"
                  className={`w-6 h-6 rounded-full transition-transform ${color === c ? 'ring-2 ring-offset-1 ring-slate-700 scale-110' : 'hover:scale-110'}`}
                  style={{ background: c }} onClick={() => setColor(c)} />
              ))}
            </div>
          </div>
        </div>

        <div className="p-4 border-t border-slate-100 flex gap-2 justify-end">
          {!preview ? (
            <button className="btn btn-primary" disabled={loading || (!address && (lat == null || lng == null))} onClick={calculate}>
              {loading ? 'Calculating…' : 'Calculate'}
            </button>
          ) : (
            <>
              <button className="btn btn-secondary" onClick={() => { setPreview(null); setDemo(null); }}>Recalc</button>
              <button className="btn btn-primary" disabled={saving} onClick={save}>
                {saving ? 'Saving…' : 'Save area'}
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
