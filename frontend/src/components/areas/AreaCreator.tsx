import { useEffect, useRef, useState } from 'react';
import { X, MapPin } from 'lucide-react';
import toast from 'react-hot-toast';
import { isochroneApi } from '../../api/isochrone';
import { areasApi } from '../../api/areas';
import { geocodingApi } from '../../api/geocoding';
import { useProjectStore } from '../../stores/projectStore';
import { useMapStore } from '../../stores/mapStore';
import { AREA_PALETTE } from '../../utils/colors';

const MODES = [
  { value: 'driving-car', label: 'Car' },
  { value: 'cycling-regular', label: 'Bike' },
  { value: 'foot-walking', label: 'Walk' },
];
const PRESETS = [5, 10, 15, 20, 30, 45, 60];

export default function AreaCreator({ onClose }: { onClose: () => void }) {
  const { currentProject, addArea } = useProjectStore();
  const { fitBoundsToArea, startDrawing, placePinFor, pendingIsochrone, setPendingIsochrone } = useMapStore();
  const [address, setAddress] = useState('');
  const [lat, setLat] = useState<number | null>(null);
  const [lng, setLng] = useState<number | null>(null);
  const [mode, setMode] = useState<'driving-car' | 'cycling-regular' | 'foot-walking'>('driving-car');
  const [time, setTime] = useState(15);
  const [name, setName] = useState('');
  const [color, setColor] = useState(AREA_PALETTE[0]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [preview, setPreview] = useState<any | null>(null);

  const addressRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!addressRef.current || typeof google === 'undefined' || !google.maps?.places) return;
    const ac = new google.maps.places.Autocomplete(addressRef.current, {
      fields: ['geometry', 'formatted_address'],
    });
    ac.addListener('place_changed', () => {
      const place = ac.getPlace();
      if (!place.geometry?.location) return;
      setLat(place.geometry.location.lat());
      setLng(place.geometry.location.lng());
      setAddress(place.formatted_address ?? '');
    });
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

  useEffect(() => {
    setName(address ? `${address} – ${time} min ${MODES.find((m) => m.value === mode)?.label}` : '');
  }, [address, mode, time]);

  async function calculate() {
    let useLat = lat, useLng = lng;
    if ((!useLat || !useLng) && address) {
      try {
        const g = await geocodingApi.geocode(address);
        useLat = g.lat;
        useLng = g.lng;
        setLat(useLat); setLng(useLng);
      } catch (e: any) {
        return toast.error('Geocoding failed');
      }
    }
    if (!useLat || !useLng) return toast.error('Address or pin required');

    setLoading(true);
    try {
      const r = await isochroneApi.calculate({ lat: useLat, lng: useLng, time_minutes: time, travel_mode: mode });
      setPreview(r);
      fitBoundsToArea(r.geojson);
      toast.success(`~${r.area_sq_km.toFixed(1)} sq km`);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Isochrone failed');
    } finally {
      setLoading(false);
    }
  }

  async function save() {
    if (!preview || !currentProject || !lat || !lng) return;
    setSaving(true);
    try {
      const a = await areasApi.create(currentProject.id, {
        name: name || 'Untitled area',
        area_type: 'isochrone',
        center_lat: lat,
        center_lng: lng,
        center_address: address,
        travel_mode: mode,
        travel_time_minutes: time,
        fill_color: color,
        stroke_color: color,
        geometry: preview.geojson,
      } as any);
      addArea({ ...a, geometry: preview.geojson } as any);
      toast.success('Area saved');
      onClose();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Save failed');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 p-4" onClick={onClose}>
      <div className="bg-white rounded-lg shadow-xl max-w-md w-full" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between p-4 border-b border-slate-100">
          <h2 className="font-bold">Create Area</h2>
          <button className="btn btn-ghost p-1" onClick={onClose}><X size={16} /></button>
        </div>
        <div className="p-4 space-y-3">
          <div>
            <label className="label">Address</label>
            <div className="flex gap-2">
              <input ref={addressRef} className="input flex-1" placeholder="Enter address…" value={address} onChange={(e) => setAddress(e.target.value)} />
              <button type="button" className="btn btn-secondary" onClick={() => { startDrawing('pin', 'isochrone'); toast('Click on the map to set center'); }}>
                <MapPin size={14} />
              </button>
            </div>
          </div>
          <div>
            <label className="label">Travel mode</label>
            <div className="flex gap-2">
              {MODES.map((m) => (
                <button key={m.value} type="button"
                  className={`btn ${mode === m.value ? 'btn-primary' : 'btn-secondary'} flex-1 justify-center`}
                  onClick={() => setMode(m.value as any)}>{m.label}</button>
              ))}
            </div>
          </div>
          <div>
            <label className="label">Time: {time} min</label>
            <input type="range" min={1} max={120} value={time} onChange={(e) => setTime(+e.target.value)} className="w-full" />
            <div className="flex gap-1 mt-1 flex-wrap">
              {PRESETS.map((p) => (
                <button key={p} type="button" className={`text-xs px-2 py-1 rounded ${time === p ? 'bg-violet-100 text-violet-700' : 'bg-slate-100 text-slate-600'}`} onClick={() => setTime(p)}>{p}m</button>
              ))}
            </div>
          </div>
          <div>
            <label className="label">Name</label>
            <input className="input" value={name} onChange={(e) => setName(e.target.value)} />
          </div>
          <div>
            <label className="label">Color</label>
            <div className="flex flex-wrap gap-2">
              {AREA_PALETTE.map((c) => (
                <button key={c} type="button" className={`w-7 h-7 rounded-full ${color === c ? 'ring-2 ring-offset-1 ring-slate-700' : ''}`}
                  style={{ background: c }} onClick={() => setColor(c)} />
              ))}
            </div>
          </div>
        </div>
        <div className="p-4 border-t border-slate-100 flex gap-2 justify-end">
          {!preview ? (
            <button className="btn btn-primary" disabled={loading || (!address && (!lat || !lng))} onClick={calculate}>
              {loading ? 'Calculating…' : 'Calculate'}
            </button>
          ) : (
            <>
              <button className="btn btn-secondary" onClick={() => setPreview(null)}>Recalc</button>
              <button className="btn btn-primary" disabled={saving} onClick={save}>
                {saving ? 'Saving…' : 'Save Area'}
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
