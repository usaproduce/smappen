import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Play, Pause, X, Clock, TrendingDown, ChevronDown, ChevronUp, Move } from 'lucide-react';
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
  lat: number;
  lng: number;
  defaultMinutes?: number;
  color?: string;
  onClose: () => void;
}

// 24-color palette — overnight blues → morning cool → midday warm → evening
// purples. Anchors the polygon color + the heatstrip bars to a vibe per hour.
const PALETTE = [
  '#1e3a8a', '#1e3a8a', '#1d4ed8', '#1d4ed8', '#2563eb', '#3b82f6',
  '#06b6d4', '#0ea5e9', '#7c3aed', '#9333ea', '#ec4899', '#f59e0b',
  '#10b981', '#22c55e', '#84cc16', '#eab308', '#f97316', '#dc2626',
  '#ef4444', '#a855f7', '#8b5cf6', '#7848BB', '#6366f1', '#1e40af',
];

const PANEL_W = 440;        // target width — narrow enough to leave the map readable
const STORAGE_KEY = 'sm-time-machine-pos';

export default function TimeMachinePanel({ lat, lng, defaultMinutes = 15, color = '#7848BB', onClose }: Props) {
  const { setTimeMachine, fitBoundsToArea } = useMapStore();
  const [day, setDay] = useState<Day>('monday');
  const [minutes, setMinutes] = useState(defaultMinutes);
  const [loading, setLoading] = useState(false);
  const [data, setData] = useState<DayResponse | null>(null);
  const [hour, setHour] = useState(8);
  const [playing, setPlaying] = useState(false);
  const [speed, setSpeed] = useState(600);
  const [collapsed, setCollapsed] = useState(false);
  const [pos, setPos] = useState<{ x: number; y: number } | null>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const playRef = useRef<number | null>(null);

  // Restore last position from localStorage so the user's preferred spot sticks
  // across sessions. Validates against viewport so a smaller window doesn't
  // strand the panel off-screen.
  useLayoutEffect(() => {
    try {
      const saved = localStorage.getItem(STORAGE_KEY);
      if (saved) {
        const p = JSON.parse(saved);
        if (typeof p.x === 'number' && typeof p.y === 'number') {
          const clamped = clampToViewport(p.x, p.y);
          setPos(clamped);
        }
      }
    } catch {}
  }, []);

  // Initial load when the panel first opens.
  useEffect(() => { load(); /* eslint-disable-next-line */ }, []);

  // Push the current hour's polygon into mapStore every time `hour` changes.
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

  useEffect(() => () => { setTimeMachine(null); }, [setTimeMachine]);

  // Auto-advance on play.
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
      toast.success(`24 hours loaded · ${payload.unique_ors_calls} unique routes`);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to load timeline');
    } finally {
      setLoading(false);
    }
  }

  // Drag-by-header. Stores the offset from the cursor to the panel's top-left
  // at mousedown, then writes absolute viewport coordinates on each mousemove.
  // Persists to localStorage on release so the spot sticks.
  function onDragStart(e: React.PointerEvent) {
    // Ignore drags that originate on buttons/inputs inside the header.
    if ((e.target as HTMLElement).closest('button, select, input')) return;
    e.preventDefault();
    const rect = panelRef.current?.getBoundingClientRect();
    if (!rect) return;
    const offsetX = e.clientX - rect.left;
    const offsetY = e.clientY - rect.top;
    const move = (ev: PointerEvent) => {
      setPos(clampToViewport(ev.clientX - offsetX, ev.clientY - offsetY));
    };
    const up = (ev: PointerEvent) => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
      try {
        const final = clampToViewport(ev.clientX - offsetX, ev.clientY - offsetY);
        localStorage.setItem(STORAGE_KEY, JSON.stringify(final));
      } catch {}
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  }

  const current = data?.hours[hour];
  const best = data?.summary.best_hour_area_sq_km ?? null;
  const worst = data?.summary.worst_hour_area_sq_km ?? null;
  const shrink = data?.summary.shrink_pct_at_peak ?? null;

  const relAreas = useMemo(() => {
    if (!data || !best) return [];
    return data.hours.map((h) => h.area_sq_km && best ? Math.max(0.1, h.area_sq_km / best) : 0.1);
  }, [data, best]);

  // Default position: bottom-LEFT of the viewport, clear of the left panel
  // (which is ~360px wide) and the right area panel. Picked so on first open
  // the polygon stays visible in the middle of the map.
  const style: React.CSSProperties = pos
    ? { left: pos.x, top: pos.y }
    : { left: 16, bottom: 16 };

  return (
    <aside
      ref={panelRef}
      className="absolute bg-white rounded-xl shadow-2xl border border-slate-200 z-30 overflow-hidden select-none"
      style={{ ...style, width: PANEL_W }}
    >
      {/* Header — also the drag handle */}
      <header
        onPointerDown={onDragStart}
        className="flex items-center gap-1.5 px-3 py-2 border-b border-slate-200 bg-slate-50 cursor-grab active:cursor-grabbing"
      >
        <Move size={11} className="text-slate-400 shrink-0" />
        <Clock size={13} style={{ color: '#7848BB' }} />
        <span className="font-bold text-[12px]" style={{ color: '#1A1A2E' }}>Drive-time over a full day</span>
        <span className="text-[9px] uppercase font-bold tracking-wider text-slate-400 ml-0.5">Time machine</span>
        <div className="flex-1" />
        <button
          onClick={() => setCollapsed((c) => !c)}
          className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-white"
          title={collapsed ? 'Expand' : 'Collapse'}
        >
          {collapsed ? <ChevronUp size={12} /> : <ChevronDown size={12} />}
        </button>
        <button onClick={onClose} className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-white" title="Close">
          <X size={12} />
        </button>
      </header>

      {/* Day + drive-time selectors — single row, no separate labels (the
          values are self-evident). Hidden when collapsed to save space. */}
      {!collapsed && (
        <div className="flex items-center gap-1.5 px-3 py-2 bg-white border-b border-slate-100">
          <select
            className="input h-8 text-xs flex-1 min-w-0"
            value={day}
            onChange={(e) => setDay(e.target.value as Day)}
          >
            <option value="sunday">Sunday</option>
            <option value="monday">Monday</option>
            <option value="tuesday">Tuesday</option>
            <option value="wednesday">Wednesday</option>
            <option value="thursday">Thursday</option>
            <option value="friday">Friday</option>
            <option value="saturday">Saturday</option>
          </select>
          <select
            className="input h-8 text-xs w-[78px]"
            value={minutes}
            onChange={(e) => setMinutes(parseInt(e.target.value, 10))}
          >
            {[5, 10, 15, 20, 30, 45, 60].map((m) => <option key={m} value={m}>{m} min</option>)}
          </select>
          <button
            className="btn btn-primary h-8 text-xs px-3"
            disabled={loading}
            onClick={load}
          >
            {loading ? '…' : 'Run'}
          </button>
        </div>
      )}

      {/* Player */}
      {data && (
        <div className="px-3 py-2.5">
          <div className="flex items-center gap-2">
            <button
              onClick={() => setPlaying((p) => !p)}
              className="w-9 h-9 rounded-full bg-violet-600 hover:bg-violet-700 text-white flex items-center justify-center shadow-sm transition shrink-0"
              title={playing ? 'Pause' : 'Play'}
            >
              {playing ? <Pause size={16} /> : <Play size={16} className="ml-0.5" />}
            </button>
            <div className="flex-1 min-w-0">
              <div className="flex items-baseline justify-between gap-2 leading-none">
                <span className="font-extrabold text-[18px] tabular-nums" style={{ color: '#1A1A2E' }}>
                  {current?.label ?? '—'}
                </span>
                <span className="text-[10px] text-slate-500 truncate">
                  {current ? (
                    <>
                      <b className="text-slate-700">{Math.round(current.area_sq_km ?? 0).toLocaleString()} km²</b>
                      {' · '}{(current.multiplier ?? 1).toFixed(2)}× traffic
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
                className="w-full accent-violet-600 mt-1"
              />
            </div>
            <select
              value={speed}
              onChange={(e) => setSpeed(parseInt(e.target.value, 10))}
              className="input h-7 text-[10px] w-[58px] shrink-0"
              title="Playback speed"
            >
              <option value={1200}>0.5×</option>
              <option value={600}>1×</option>
              <option value={300}>2×</option>
              <option value={150}>4×</option>
            </select>
          </div>

          {/* 24-bar strip */}
          <div className="mt-2.5 flex items-end gap-0.5 h-7">
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
          <div className="flex justify-between text-[9px] text-slate-400 mt-0.5 px-0.5">
            <span>00</span><span>06</span><span>12</span><span>18</span><span>23</span>
          </div>

          {/* Summary chip — only when not collapsed */}
          {!collapsed && shrink !== null && (
            <div className="mt-2 flex items-center gap-2 text-[11px]">
              <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-rose-50 text-rose-700 font-semibold">
                <TrendingDown size={10} />
                {shrink}% smaller at peak
              </span>
              <span className="text-slate-500 truncate">
                Best <b className="text-slate-700">{Math.round(best ?? 0).toLocaleString()}</b>
                {' '}· Worst <b className="text-slate-700">{Math.round(worst ?? 0).toLocaleString()} km²</b>
              </span>
            </div>
          )}
        </div>
      )}
    </aside>
  );
}

/** Keep the panel mostly on-screen. Allows a 60px peek off the right/bottom
 *  so the user can park it at a true corner. */
function clampToViewport(x: number, y: number) {
  const w = PANEL_W;
  const h = 220; // upper bound — actual height varies w/ collapsed state
  const maxX = window.innerWidth - 60;
  const maxY = window.innerHeight - 60;
  return {
    x: Math.max(0 - (w - 60), Math.min(maxX, x)),
    y: Math.max(0, Math.min(maxY, y)),
  };
}
