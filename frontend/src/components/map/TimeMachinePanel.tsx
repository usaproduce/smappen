import { useEffect, useMemo, useRef, useState } from 'react';
import { Play, Pause, X, Clock, TrendingDown, ChevronDown, ChevronUp, Download } from 'lucide-react';
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

// 24-color palette anchoring the polygon + heatstrip-bar color to a vibe per
// hour. Overnight blues → cool morning → warm midday → evening purples.
const PALETTE = [
  '#1e3a8a', '#1e3a8a', '#1d4ed8', '#1d4ed8', '#2563eb', '#3b82f6',
  '#06b6d4', '#0ea5e9', '#7c3aed', '#9333ea', '#ec4899', '#f59e0b',
  '#10b981', '#22c55e', '#84cc16', '#eab308', '#f97316', '#dc2626',
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
  const [speed, setSpeed] = useState(600);
  const [collapsed, setCollapsed] = useState(false);
  const playRef = useRef<number | null>(null);

  // Initial load on first mount.
  useEffect(() => { load(); /* eslint-disable-next-line */ }, []);

  // Push the current hour's polygon into mapStore for MapCanvas to render.
  useEffect(() => {
    if (!data) return;
    const frame = data.hours[hour];
    if (!frame || !frame.geometry) { setTimeMachine(null); return; }
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
    playRef.current = window.setInterval(() => setHour((h) => (h + 1) % 24), speed);
    return () => { if (playRef.current) window.clearInterval(playRef.current); };
  }, [playing, speed, data]);

  // Keyboard controls when the strip is open. Space toggles play/pause,
  // arrows scrub hours, brackets change speed. Disabled when focus is in
  // an input so day/duration dropdowns aren't hijacked.
  useEffect(() => {
    if (!data) return;
    const SPEEDS = [1200, 600, 300, 150];
    function onKey(e: KeyboardEvent) {
      const t = document.activeElement as HTMLElement | null;
      const isTyping = t?.tagName === 'INPUT' || t?.tagName === 'TEXTAREA' || t?.tagName === 'SELECT' || t?.isContentEditable;
      if (isTyping) return;
      if (e.key === ' ') { e.preventDefault(); setPlaying((p) => !p); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); setPlaying(false); setHour((h) => (h + 23) % 24); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); setPlaying(false); setHour((h) => (h + 1) % 24); }
      else if (e.key === '[') { e.preventDefault(); setSpeed((s) => SPEEDS[Math.min(SPEEDS.length - 1, SPEEDS.indexOf(s) + 1)] ?? s); }
      else if (e.key === ']') { e.preventDefault(); setSpeed((s) => SPEEDS[Math.max(0, SPEEDS.indexOf(s) - 1)] ?? s); }
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [data]);

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

  const current = data?.hours[hour];
  const best = data?.summary.best_hour_area_sq_km ?? null;
  const worst = data?.summary.worst_hour_area_sq_km ?? null;
  const shrink = data?.summary.shrink_pct_at_peak ?? null;

  // Heatstrip — heights relative to the day's best (largest) reach.
  const relAreas = useMemo(() => {
    if (!data || !best) return [];
    return data.hours.map((h) => h.area_sq_km && best ? Math.max(0.08, h.area_sq_km / best) : 0.08);
  }, [data, best]);

  // Dock as a slim media-player strip along the bottom of the map. Left/right
  // gutters clear the floating side panels; right-20 keeps it off the right
  // toolbar. The heatstrip IS the scrubber (each bar is clickable) so we don't
  // also need a separate <input type=range> wasting vertical space.
  return (
    <aside
      className="absolute bottom-4 left-4 right-20 bg-white rounded-xl shadow-xl border border-slate-200 z-30 overflow-hidden"
      style={{ maxWidth: 'calc(100vw - 100px)' }}
    >
      {/* ── Row 1: identity + controls (always visible) ──────────────────── */}
      <div className="flex items-center gap-2 px-3 py-2 border-b border-slate-200 bg-slate-50">
        <Clock size={14} style={{ color: '#7848BB' }} />
        <span className="font-bold text-[13px]" style={{ color: '#1A1A2E' }}>Daypart</span>
        <span className="text-[9px] uppercase font-bold tracking-wider text-violet-700 bg-violet-100 px-1.5 py-0.5 rounded-full">
          24-hour reach
        </span>

        <div className="h-4 w-px bg-slate-200 mx-1" />

        <select
          className="input h-7 text-xs w-[110px]"
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
          className="input h-7 text-xs w-[70px]"
          value={minutes}
          onChange={(e) => setMinutes(parseInt(e.target.value, 10))}
        >
          {[5, 10, 15, 20, 30, 45, 60].map((m) => <option key={m} value={m}>{m} min</option>)}
        </select>
        <button
          className="btn btn-primary h-7 text-xs px-2.5"
          disabled={loading}
          onClick={load}
        >
          {loading ? 'Loading…' : 'Run'}
        </button>
        <span className="text-[10px] text-slate-400 ml-1">
          {data ? `${data.unique_ors_calls} unique routes (rest cached)` : ''}
        </span>

        <div className="flex-1" />

        {data && (
          <button
            onClick={() => exportCsv(data, lat, lng)}
            className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-white"
            title="Download 24-hour data as CSV"
          >
            <Download size={13} />
          </button>
        )}
        <button
          onClick={() => setCollapsed((c) => !c)}
          className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-white"
          title={collapsed ? 'Expand' : 'Collapse'}
        >
          {collapsed ? <ChevronUp size={13} /> : <ChevronDown size={13} />}
        </button>
        <button onClick={onClose} className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-white" title="Close">
          <X size={13} />
        </button>
      </div>

      {/* ── Row 2: play + readout + speed (hidden when collapsed) ────────── */}
      {data && !collapsed && (
        <div className="flex items-center gap-3 px-3 py-2 border-b border-slate-100 bg-white">
          <button
            onClick={() => setPlaying((p) => !p)}
            className="w-9 h-9 rounded-full bg-violet-600 hover:bg-violet-700 text-white flex items-center justify-center shadow-sm transition shrink-0"
            title={playing ? 'Pause' : 'Play'}
          >
            {playing ? <Pause size={16} /> : <Play size={16} className="ml-0.5" />}
          </button>

          {/* Hour + stats — single line, baseline aligned so the big time
              number reads cleanly with the smaller stats trailing. */}
          <div className="flex items-baseline gap-2 min-w-0">
            <span className="font-extrabold text-[22px] tabular-nums leading-none" style={{ color: '#1A1A2E' }}>
              {current?.label ?? '—'}
            </span>
            <span className="text-[11px] text-slate-500 truncate">
              {current ? (
                <>
                  <b className="text-slate-700 tabular-nums">{Math.round(current.area_sq_km ?? 0).toLocaleString()} km²</b>
                  {' · '}<span className="tabular-nums">{(current.multiplier ?? 1).toFixed(2)}× traffic</span>
                </>
              ) : '—'}
            </span>
          </div>

          <div className="flex-1" />

          {/* Summary chip — moved up onto the player row so it doesn't
              compete with the heatstrip for vertical space. */}
          {shrink !== null && (
            <span className="hidden md:inline-flex items-center gap-1 px-2 py-1 rounded-full bg-rose-50 text-rose-700 text-[11px] font-semibold whitespace-nowrap">
              <TrendingDown size={11} />
              {shrink}% peak shrink
              <span className="text-rose-700/60 font-normal ml-1">
                · best <b>{Math.round(best ?? 0).toLocaleString()}</b>
                {' · worst '}<b>{Math.round(worst ?? 0).toLocaleString()} km²</b>
              </span>
            </span>
          )}

          <select
            value={speed}
            onChange={(e) => setSpeed(parseInt(e.target.value, 10))}
            className="input h-7 text-[11px] w-[64px] shrink-0"
            title="Playback speed"
          >
            <option value={1200}>0.5×</option>
            <option value={600}>1×</option>
            <option value={300}>2×</option>
            <option value={150}>4×</option>
          </select>
        </div>
      )}

      {/* ── Row 3: heatstrip (the scrubber). Stretched full-width since the
            strip itself is now docked to the bottom of the map. ─────────── */}
      {data && (
        <div className="px-3 pt-2 pb-2">
          <div className="flex items-end gap-1 h-9">
            {relAreas.map((rel, i) => (
              <button
                key={i}
                onClick={() => { setPlaying(false); setHour(i); }}
                title={`${data.hours[i].label} · ${Math.round(data.hours[i].area_sq_km ?? 0).toLocaleString()} km²`}
                className="flex-1 transition-all rounded-sm hover:opacity-100 cursor-pointer"
                style={{
                  height: `${Math.round(rel * 100)}%`,
                  background: PALETTE[i],
                  opacity: i === hour ? 1 : 0.45,
                  outline: i === hour ? '2px solid #1A1A2E' : 'none',
                  outlineOffset: i === hour ? '1px' : '0',
                }}
              />
            ))}
          </div>
          <div className="flex justify-between text-[9px] text-slate-400 mt-1 px-0.5 tabular-nums">
            <span>00:00</span>
            <span>06:00</span>
            <span>12:00</span>
            <span>18:00</span>
            <span>23:00</span>
          </div>
        </div>
      )}
    </aside>
  );
}

/**
 * Export the loaded 24-hour reach table as CSV. Format matches what a
 * spreadsheet user would expect — one row per hour with all the numbers
 * needed to recompute the visualization offline.
 */
function exportCsv(data: DayResponse, lat: number, lng: number) {
  const rows = [
    ['day', 'hour', 'label', 'requested_minutes', 'adjusted_minutes', 'traffic_multiplier', 'area_sq_km'],
    ...data.hours.map((h) => [
      data.day_of_week,
      String(h.hour),
      h.label,
      String(h.requested_minutes),
      String(h.adjusted_minutes),
      h.multiplier.toFixed(3),
      h.area_sq_km != null ? h.area_sq_km.toFixed(2) : '',
    ]),
  ];
  const csv = rows.map((r) => r.map(csvCell).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `daypart-${data.day_of_week}-${data.requested_minutes}min-${lat.toFixed(4)}-${lng.toFixed(4)}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
function csvCell(s: string): string {
  // Wrap any value containing comma/quote/newline; double up internal quotes.
  return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}
