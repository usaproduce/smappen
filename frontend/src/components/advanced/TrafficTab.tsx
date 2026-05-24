import { useState } from 'react';
import toast from 'react-hot-toast';
import { Compass } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { trafficApi } from '../../api/advanced';
import { Spinner, Field } from './shared';

export default function TrafficTab() {
  const { mapInstance } = useMapStore();
  const [time, setTime] = useState(15);
  const [day, setDay] = useState<'monday' | 'friday' | 'saturday' | 'sunday'>('monday');
  const [hour, setHour] = useState(8);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<any>(null);

  async function run() {
    if (!mapInstance) { toast.error('Map not ready yet'); return; }
    const c = mapInstance.getCenter();
    if (!c) { toast.error('Pan the map to set an origin first'); return; }
    setBusy(true);
    try {
      const r = await trafficApi.single({
        lat: c.lat(), lng: c.lng(),
        time_minutes: time,
        day_of_week: day, hour_24: hour,
      });
      setResult(r);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-600">How far you can drive at a specific time of day. Uses the map center as origin.</p>
      <Field label="Drive time (min)">
        <input type="number" min={1} max={60} value={time}
          onChange={(e) => setTime(Math.max(0, parseInt(e.target.value, 10) || 0))} className="input h-9 text-sm" />
      </Field>
      <Field label="Day">
        <select className="input h-9 text-sm" value={day} onChange={(e) => setDay(e.target.value as any)}>
          <option value="monday">Mon (rush)</option>
          <option value="friday">Fri (heavy PM)</option>
          <option value="saturday">Sat (midday)</option>
          <option value="sunday">Sun (light)</option>
        </select>
      </Field>
      <Field label="Hour">
        <select className="input h-9 text-sm" value={hour} onChange={(e) => setHour(parseInt(e.target.value))}>
          {[6, 7, 8, 9, 12, 14, 17, 18, 20, 22].map((h) => (
            <option key={h} value={h}>{h.toString().padStart(2, '0')}:00</option>
          ))}
        </select>
      </Field>
      <button className="btn btn-primary w-full h-10" onClick={run} disabled={busy}>
        {busy ? <Spinner /> : <Compass size={14} />} {busy ? 'Computing…' : 'Compute traffic isochrone'}
      </button>
      {result && (
        <div className="text-xs bg-slate-50 rounded p-2 space-y-0.5">
          <div className="font-semibold" style={{ color: '#1A1A2E' }}>{result.traffic.label}</div>
          <div>Multiplier: {result.traffic.multiplier.toFixed(2)}x</div>
          <div>Equivalent free-flow: {result.traffic.adjusted_free_flow_minutes} min</div>
          <div>Area: {Math.round(result.area_sq_km).toLocaleString()} km²</div>
        </div>
      )}
    </div>
  );
}
