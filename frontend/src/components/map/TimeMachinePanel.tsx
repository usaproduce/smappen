import { useEffect, useMemo, useRef, useState } from 'react';
import { Play, Pause, X, Clock, TrendingDown } from 'lucide-react';
import toast from 'react-hot-toast';
import { useMapStore } from '../../stores/mapStore';
import { api } from '../../api/client';

type Day = 'sunday' | 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday';

interface HourFrame {
  hour: number;
  label: string;
  multiplier: number;
  adjusted_minutes: number;
  requested_minutes: number;
  area_sq_km: number | null;
  geometry: { type: 'Polygon'; coordinates: number[][][] } | null;
  bbox: [number, number, number, number] | null;
  error: string | null;
}

interface DayResponse {
  origin: { lat: number; lng: number };
  day_of_week: Day;
  requested_minutes: number;
  unique_ors_calls: number;
  hours: HourFrame[];
  summary: {
    best_hour_area_sq_km: number | null;
    worst_hour_area_sq_km: number | null;
    shrink_pct_at_peak: number | null;
  };
}

interface Props {
  /** Origin point — comes from the selected area's center. */
  lat: number;
  lng: number;
  /** Default drive-time minutes (e.g. 15). */
  defaultMinutes?: number;
  /** Color used for the animated polygon. */
  color?: string;
  onClose: () => void;
}

const PALETTE = [
  // Index 0 = midnight → 6am (overnight, light traffic, big area)
  '#1e3a8a', '#1e3a8a', '#1d4ed8', '#1d4ed8', '#2563eb', '#3b82f6',
  // 6am → 11am (morning rush)
  '#06b6d4', '#0ea5e9', '#7c3aed', '#9333ea', '#ec4899', '#f59e0b',
  // noon → 5pm (midday + PM build)
  '#10b981', '#22c55e', '#84cc16', '#eab308', '#f97316', '#dc2626',
  // 6pm → 11pm (evening wind-down)
  '#ef4444', '#a855f7', '#8b5cf6', '#7848BB', '#6366f1', '#1e40af',
];

export default function TimeMachinePanel({ lat, lng, defaultMinutes = 15, color = '#7848BB', onClose }: Props) {
  const { setTimeMachine, fitBoundsToArea } = useMapStore();
  const [day, setDay] = useState<Day>('monday');
  const [minutes, setMinutes] = useState(defaultMinutes);
  const [loading, setLoading] = useState(false);
  const [data, setData] = useState<DayResponse | null>(null);
  const [hour, setHour] = useState(8);
  const [playing, setPlaying] = useState(false);
  const [speed, setSpeed] = useState(600); // ms per hour at playback
  const playRef = useRef<number | null>(null);

  // Initial load when the panel first opens.
  useEffect(() => { load(); /* eslint-disable-next-line */ }, []);

  // Push the current hour's polygon into the map store every time `hour` or
  // `data` changes. MapCanvas reads timeMachine and renders the overlay.
  useEffect(() => {
    if (!data) return;
    const frame = data.hours[hour];
    if (!frame || !frame.geometry) {
      setTimeMachine(null);
      return;
    }
    setTimeMachine({
      geometry: frame.geometry,
      hour: frame.hour,
      label: frame.label,
      areaSqKm: frame.area_sq_km,
      color: PALETTE[hour] ?? color,
    });
  }, [data, hour, color, setTimeMachine]);

  // Clean up the polygon when the panel unmounts.
  useEffect(() => () => { setTimeMachine(null); }, [setTimeMachine]);

  // Auto-advance the hour when "playing". Wraps to 0 at the end so the user
  // can watch a full day cycle without re-clicking.
  useEffect(() => {
    if (!playing || !data) return;
    playRef.current = window.setInterval(() => {
      setHour((h) => (h + 1) % 24);
    }, speed);
    return () => { if (playRef.current) window.clearInterval(playRef.current); };
  }, [playing, speed, data]);

  async function load() {
    setLoading(true);
    setData(null);
    try {
      const { data: res } = await api.post('/api/isochrone/traffic/day', {
        lat, lng, time_minutes: minutes, travel_mode: 'driving-car', day_of_week: day,
      });
      const payload = res.data as DayResponse;
      setData(payload);
      // Center the map on the union bbox of the hours so the whole animation
      // is visible without panning.
      if (payload.hours[0]?.bbox) {
        const all = payload.hours.filter((h) => h.bbox);
        if (all.length) {
          const minLng = Math.min(...all.map((h) => h.bbox![0]));
          const minLat = Math.min(...all.map((h) => h.bbox![1]));
          const maxLng = Math.max(...all.map((h) => h.bbox![2]));
          const maxLat = Math.max(...all.map((h) => h.bbox![3]));
          fitBoundsToArea({
            type: 'Polygon',
            coordinates: [[
              [minLng, minLat], [maxLng, minLat], [maxLng, maxLat], [minLng, maxLat], [minLng, minLat],
            ]],
          });
        }
      }
      toast.success(`24 hours loaded · ${payload.unique_ors_calls} unique routes`);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to load timeline');
    } finally {
      setLoading(false);
    }
  }

  const current = data?.hours[hour];
  const best = data?.summary.best_hour_area_sq_km ?? null;
  const worst = data?.summary.worst_hour_area_sq_km ?? null;
  const shrink = data?.summary.shrink_pct_at_peak ?? null;

  // For the timeline strip — relative shrinkage per hour, 0..1.
  const relAreas = useMemo(() => {
    if (!data || !best) return [];
    return data.hours.map((h) => h.area_sq_km && best ? Math.max(0.1, h.area_sq_km / best) : 0.1);
  }, [data, best]);

  return (
    <aside className="absolute bottom-4 left-1/2 -translate-x-1/2 w-[min(720px,calc(100%-2rem))] bg-white rounded-2xl shadow-2xl border border-slate-200 z-30 overflow-hidden">
      <header className="flex items-center justify-between px-4 py-3 border-b border-slate-200">
        <div className="flex items-center gap-2">
          <Clock size={16} style={{ color: '#7848BB' }} />
          <span className="font-bold text-sm" style={{ color: '#1A1A2E' }}>Drive-time over a full day</span>
          <span className="text-[10px] uppercase font-bold tracking-wider text-slate-400 ml-2">Time machine</span>
        </div>
        <button onClick={onClose} className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-slate-50">
          <X size={14} />
        </button>
      </header>

      {/* Controls */}
      <div className="flex flex-wrap items-end gap-2 px-4 py-3 bg-slate-50 border-b border-slate-100">
        <div className="flex flex-col">
          <label className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Day</label>
          <select className="input h-9 text-sm w-32" value={day} onChange={(e) => setDay(e.target.value as Day)}>
            <option value="sunday">Sunday</option>
            <option value="monday">Monday</option>
            <option value="tuesday">Tuesday</option>
            <option value="wednesday">Wednesday</option>
            <option value="thursday">Thursday</option>
            <option value="friday">Friday</option>
            <option value="saturday">Saturday</option>
          </select>
        </div>
        <div className="flex flex-col">
          <label className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Drive time</label>
          <select className="input h-9 text-sm w-24" value={minutes} onChange={(e) => setMinutes(parseInt(e.target.value, 10))}>
            {[5, 10, 15, 20, 30, 45, 60].map((m) => <option key={m} value={m}>{m} min</option>)}
          </select>
        </div>
        <button className="btn btn-primary h-9" disabled={loading} onClick={load}>
          {loading ? 'Loading 24h…' : 'Run timeline'}
        </button>
        <div className="ml-auto text-xs text-slate-500 leading-snug">
          {data ? `${data.unique_ors_calls} ORS calls (rest cached)` : 'Click run to fetch 24 hours.'}
        </div>
      </div>

      {/* Player */}
      {data && (
        <div className="px-4 py-3">
          <div className="flex items-center gap-3">
            <button
              onClick={() => setPlaying((p) => !p)}
              className="w-10 h-10 rounded-full bg-violet-600 hover:bg-violet-700 text-white flex items-center justify-center shadow-md transition"
              title={playing ? 'Pause' : 'Play'}
            >
              {playing ? <Pause size={18} /> : <Play size={18} className="ml-0.5" />}
            </button>
            <div className="flex-1">
              <div className="flex items-center justify-between mb-1">
                <span className="font-extrabold text-[20px] tabular-nums" style={{ color: '#1A1A2E' }}>
                  {current?.label ?? '—'}
                </span>
                <span className="text-xs text-slate-500">
                  {current ? (
                    <>
                      <b className="text-slate-700">{Math.round(current.area_sq_km ?? 0).toLocaleString()} km²</b>
                      {' · '}
                      {(current.multiplier ?? 1).toFixed(2)}× traffic
                    </>
                  ) : '—'}
                </span>
              </div>
              <input
                type="range"
                min={0}
                max={23}
                step={1}
                value={hour}
                onChange={(e) => { setPlaying(false); setHour(parseInt(e.target.value, 10)); }}
                className="w-full accent-violet-600"
              />
            </div>
            <select
              value={speed}
              onChange={(e) => setSpeed(parseInt(e.target.value, 10))}
              className="input h-8 text-xs w-20"
              title="Playback speed (ms per hour)"
            >
              <option value={1200}>0.5×</option>
              <option value={600}>1×</option>
              <option value={300}>2×</option>
              <option value={150}>4×</option>
            </select>
          </div>

          {/* 24-bar strip — height = relative reach at that hour vs the day's best */}
          <div className="mt-3 flex items-end gap-0.5 h-10">
            {relAreas.map((rel, i) => (
              <button
                key={i}
                onClick={() => { setPlaying(false); setHour(i); }}
                title={`${data.hours[i].label} · ${Math.round(data.hours[i].area_sq_km ?? 0)} km²`}
                className="flex-1 transition-all rounded-sm hover:opacity-100"
                style={{
                  height: `${Math.round(rel * 100)}%`,
                  background: PALETTE[i],
                  opacity: i === hour ? 1 : 0.5,
                  outline: i === hour ? '2px solid #1A1A2E' : 'none',
                }}
              />
            ))}
          </div>
          <div className="flex justify-between text-[10px] text-slate-400 mt-1 px-0.5">
            <span>00</span><span>06</span><span>12</span><span>18</span><span>23</span>
          </div>

          {/* Summary */}
          {shrink !== null && (
            <div className="mt-3 flex items-center gap-3 text-xs">
              <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-rose-50 text-rose-700 font-semibold">
                <TrendingDown size={11} />
                {shrink}% smaller at peak
              </span>
              <span className="text-slate-500">
                Best: <b className="text-slate-700">{Math.round(best ?? 0).toLocaleString()} km²</b>
                {' '}· Worst: <b className="text-slate-700">{Math.round(worst ?? 0).toLocaleString()} km²</b>
              </span>
            </div>
          )}
        </div>
      )}
    </aside>
  );
}
