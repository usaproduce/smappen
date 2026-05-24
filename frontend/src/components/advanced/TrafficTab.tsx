import { useState } from 'react';
import toast from 'react-hot-toast';
import { Compass, Clock } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { trafficApi } from '../../api/advanced';
import { Spinner, Field } from './shared';

export default function TrafficTab() {
  const { mapInstance, openTimeMachine } = useMapStore();
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

  function openDayMachine() {
    if (!mapInstance) { toast.error('Map not ready yet'); return; }
    const c = mapInstance.getCenter();
    if (!c) { toast.error('Pan the map to set an origin first'); return; }
    openTimeMachine({ lat: c.lat(), lng: c.lng(), minutes: time, color: '#7848BB' });
  }

  return (
    <div className="space-y-3">
      {/* Featured: Drive-time time machine. Animates 24 hours of one origin so
          you can watch the reach polygon shrink at rush hour. */}
      <button
        onClick={openDayMachine}
        className="w-full rounded-lg p-3 border-2 border-dashed border-violet-300 hover:border-violet-500 hover:bg-violet-50 transition flex items-center gap-3 text-left group"
      >
        <div className="w-10 h-10 rounded-full bg-violet-100 group-hover:bg-violet-200 flex items-center justify-center transition shrink-0">
          <Clock size={18} style={{ color: '#7848BB' }} />
        </div>
        <div className="flex-1 min-w-0">
          <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>
            ▶ Watch drive-time over a full day
          </div>
          <div className="text-[11px] text-slate-500 leading-snug">
            Animate the reach polygon hour-by-hour from midnight to midnight.
            Uses the current map center as origin.
          </div>
        </div>
      </button>

      <div className="text-[10px] font-bold uppercase tracking-wide text-slate-400 pt-2">Or compute a single hour</div>
      <p className="text-xs text-slate-600">How far you can drive at one specific moment. Uses the map center as origin.</p>
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
