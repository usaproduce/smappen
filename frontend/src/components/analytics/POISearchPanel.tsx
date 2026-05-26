import { useState } from 'react';
import toast from 'react-hot-toast';
import {
  Building, Search as SearchIcon, Utensils, Coffee, ShoppingBag, Pill,
  Dumbbell, GraduationCap, Stethoscope, Landmark, Fuel, Sparkles,
} from 'lucide-react';
import { placesApi, type PlacesBenchmark } from '../../api/places';
import { BarChart3, Loader2 } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import EmptyState from '../common/EmptyState';
import type { Area, Place } from '../../types';

// Tweak #13 — replace the plain category <select> with a horizontal chip
// row of recognizable icons. Tapping a chip both selects and runs search.
const CATEGORIES: { key: string; label: string; icon: any }[] = [
  { key: '',             label: 'Any',         icon: Sparkles },
  { key: 'restaurant',   label: 'Food',        icon: Utensils },
  { key: 'cafe',         label: 'Cafes',       icon: Coffee },
  { key: 'store',        label: 'Retail',      icon: ShoppingBag },
  { key: 'pharmacy',     label: 'Pharmacy',    icon: Pill },
  { key: 'gym',          label: 'Gyms',        icon: Dumbbell },
  { key: 'school',       label: 'Schools',     icon: GraduationCap },
  { key: 'hospital',     label: 'Health',      icon: Stethoscope },
  { key: 'bank',         label: 'Banks',       icon: Landmark },
  { key: 'gas_station',  label: 'Gas',         icon: Fuel },
];

type DensityLabel = 'Sparse' | 'Moderate' | 'Dense' | 'Very dense';
interface SearchMeta {
  count: number;
  area_sq_km?: number | null;
  area_population?: number | null;
  density_per_sq_km?: number | null;
  density_per_1k_people?: number | null;
  density_label?: DensityLabel | null;
}

// Frontend cache: when the user switches tabs (Demographics → People →
// Businesses) or reloads the page, the panel remounts and local state
// resets. Stash the last search + benchmark per area in localStorage so
// they don't have to re-run the query just because they navigated away.
function storageKey(areaId: string) { return `poi_search:${areaId}`; }
interface PoiSnapshot {
  type: string;
  keyword: string;
  results: Place[];
  meta: SearchMeta | null;
  benchmark: PlacesBenchmark | null;
}
function readSnapshot(areaId: string): PoiSnapshot | null {
  try { const s = localStorage.getItem(storageKey(areaId)); return s ? JSON.parse(s) : null; }
  catch { return null; }
}
function writeSnapshot(areaId: string, snap: Partial<PoiSnapshot>) {
  try {
    const prev = readSnapshot(areaId) ?? { type: '', keyword: '', results: [], meta: null, benchmark: null };
    localStorage.setItem(storageKey(areaId), JSON.stringify({ ...prev, ...snap }));
  } catch { /* quota / SecurityError — non-fatal */ }
}

export default function POISearchPanel({ area }: { area: Area }) {
  // Initial-render hydrate from localStorage so the user lands on their
  // last search, not a blank input. useState lazy initializer keeps this
  // cheap (runs once per mount per area, never on re-render).
  const initial = () => readSnapshot(area.id);
  const [type, setType] = useState(() => initial()?.type ?? '');
  const [keyword, setKeyword] = useState(() => initial()?.keyword ?? '');
  const [loading, setLoading] = useState(false);
  const [results, setResults] = useState<Place[]>(() => initial()?.results ?? []);
  const [meta, setMeta] = useState<SearchMeta | null>(() => initial()?.meta ?? null);
  const [benchmark, setBenchmark] = useState<PlacesBenchmark | null>(() => initial()?.benchmark ?? null);
  const [benchmarking, setBenchmarking] = useState(false);
  const { setPoiResults } = useMapStore();

  // Re-push restored markers to the map when remounting with cached results
  // — otherwise the list shows pins but the map shows none.
  useEffect(() => {
    if (results.length > 0) setPoiResults(results);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function search() {
    if (!area.center_lat || !area.center_lng) return toast.error('Area has no center');
    setLoading(true);
    try {
      // No `radius_meters` here — the backend derives one from the area's
      // bounding box, so a 200 km² isochrone gets an 8 km search circle
      // instead of the old hardcoded 5 km that missed everything on the rim.
      const r = await placesApi.nearby({
        lat: area.center_lat, lng: area.center_lng,
        type: type || undefined, keyword: keyword || undefined,
        area_id: area.id,
      });
      setResults(r.places);
      const nextMeta: SearchMeta = {
        count: r.count,
        area_sq_km: r.area_sq_km,
        area_population: r.area_population,
        density_per_sq_km: r.density_per_sq_km,
        density_per_1k_people: r.density_per_1k_people,
        density_label: r.density_label,
      };
      setMeta(nextMeta);
      // A new search invalidates any prior benchmark (different params).
      setBenchmark(null);
      setPoiResults(r.places);
      // Persist so a tab-switch or reload restores results without re-billing.
      writeSnapshot(area.id, { type, keyword, results: r.places, meta: nextMeta, benchmark: null });
      toast.success(`${r.count} results`);
    } catch (e: any) {
      const err = e?.response?.data?.error ?? 'Search failed';
      const enableUrl = e?.response?.data?.details?.enable_url;
      if (enableUrl) {
        // Toast with an embedded clickable Enable link, so the user doesn't
        // have to copy the URL out of the message into a new tab.
        toast.error(
          (t) => (
            <div className="text-sm leading-snug">
              <div className="font-semibold mb-1">{err}</div>
              <a
                href={enableUrl}
                target="_blank"
                rel="noreferrer"
                className="text-violet-700 underline font-semibold text-xs"
                onClick={() => toast.dismiss(t.id)}
              >
                Open Google Cloud Console →
              </a>
            </div>
          ) as any,
          { duration: 10000 }
        );
      } else {
        toast.error(err);
      }
    } finally { setLoading(false); }
  }

  async function runBenchmark() {
    if (!results.length) return;
    setBenchmarking(true);
    try {
      const b = await placesApi.benchmark({
        area_id: area.id,
        user_count: results.length,
        type: type || undefined,
        keyword: keyword || undefined,
      });
      setBenchmark(b);
      writeSnapshot(area.id, { benchmark: b });
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Benchmark failed');
    } finally { setBenchmarking(false); }
  }

  return (
    <div className="p-4 space-y-3">
      <div className="space-y-2">
        {/* Horizontal scrollable chip strip. Each chip combines an icon
            recognizable at a glance with a 1-word label. Tapping toggles
            selection without immediately firing the API — user can tweak
            keyword first, then Search. */}
        <div className="flex gap-1.5 overflow-x-auto -mx-1 px-1 pb-1 scroll-x">
          {CATEGORIES.map((c) => {
            const Active = type === c.key;
            const Icon = c.icon;
            return (
              <button
                key={c.key || 'any'}
                onClick={() => setType(c.key)}
                className={`flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold whitespace-nowrap shrink-0 transition-colors ${
                  Active
                    ? 'bg-violet-100 text-violet-700 ring-1 ring-violet-300'
                    : 'bg-slate-50 text-slate-600 hover:bg-slate-100'
                }`}
              >
                <Icon size={12} />
                {c.label}
              </button>
            );
          })}
        </div>
        <div className="relative">
          <SearchIcon size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
          <input className="input pl-8" placeholder="Keyword (optional)" value={keyword} onChange={(e) => setKeyword(e.target.value)} />
        </div>
        <button className="btn btn-primary w-full justify-center" disabled={loading} onClick={search}>
          {loading ? 'Searching…' : 'Search'}
        </button>
      </div>
      {loading && (
        // Skeleton cards while the Places API request is in flight — better
        // than a single spinner because users see the result shape forming.
        <div className="space-y-2">
          <div className="skeleton h-3 w-20" />
          <div className="skeleton h-16" />
          <div className="skeleton h-16" />
          <div className="skeleton h-16" />
        </div>
      )}
      {!loading && results.length === 0 && (
        <EmptyState
          icon={<Building size={28} />}
          title="No businesses yet"
          subtitle="Pick a category or enter a keyword, then Search to find businesses inside this area."
          compact
        />
      )}
      {!loading && results.length > 0 && (
        <>
          <ConcentrationHeader count={results.length} meta={meta} />
          {/* Benchmark CTA — sits between the header and the list so the
              comparison is offered before the operator dives into individual
              POIs. Once run, the BenchmarkPanel replaces the button. */}
          {!benchmark && !benchmarking && (
            <button
              type="button"
              onClick={runBenchmark}
              className="w-full mt-1 mb-2 flex items-center justify-center gap-1.5 text-xs font-bold text-violet-700 bg-violet-50 hover:bg-violet-100 border border-violet-200 rounded-md px-3 py-2 transition"
            >
              <BarChart3 size={13} /> Compare to 10 similar US areas
            </button>
          )}
          {benchmarking && (
            <div className="w-full mt-1 mb-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-slate-600 bg-slate-50 border border-slate-200 rounded-md px-3 py-2">
              <Loader2 size={13} className="animate-spin" /> Running the same search on 10 reference US areas…
            </div>
          )}
          {benchmark && <BenchmarkPanel data={benchmark} onClose={() => { setBenchmark(null); writeSnapshot(area.id, { benchmark: null }); }} />}
          <div className="space-y-2">
            {results.map((p) => (
              <div key={p.id} className="card">
                <div className="font-semibold text-sm">{p.displayName?.text}</div>
                <div className="text-xs text-slate-500">{p.formattedAddress}</div>
                {p.rating && <div className="text-xs">⭐ {p.rating} ({p.userRatingCount})</div>}
                {p.nationalPhoneNumber && <a href={`tel:${p.nationalPhoneNumber}`} className="text-xs text-violet-700">{p.nationalPhoneNumber}</a>}
                {p.websiteUri && <a href={p.websiteUri} target="_blank" rel="noreferrer" className="text-xs text-violet-700 block">Website ↗</a>}
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  );
}

/**
 * Concentration summary at the top of the results list. Backend computes
 * density per km² + per 1k people; the badge gives a one-glance label
 * (Sparse → Very dense) so the operator doesn't have to reason about raw
 * numbers like "1.4 cafes per km²". The per-capita ratio shows up next
 * to it when the area has census coverage.
 */
/**
 * Renders the user's area against the 10 reference cities as a sorted bar
 * row: each reference + the user (highlighted violet) ordered by count.
 * The horizontal position of the user bar IS the percentile, no extra
 * computation needed.
 */
function BenchmarkPanel({ data, onClose }: { data: PlacesBenchmark; onClose: () => void }) {
  // Merge user + references into one sorted list, tag the user row.
  const rows = [
    ...data.references.map((r) => ({ name: r.name, count: r.count, isUser: false })),
    { name: data.user_area.name + ' (you)', count: data.user_area.count, isUser: true },
  ].sort((a, b) => a.count - b.count);
  const maxCount = Math.max(1, ...rows.map((r) => r.count));
  const s = data.summary;
  const pct = s.user_percentile;

  const pctBadge = (() => {
    if (pct === null) return { text: '—', bg: 'bg-slate-100 text-slate-700' };
    if (pct >= 90) return { text: `${pct}th pct · top decile`,  bg: 'bg-emerald-50 text-emerald-800' };
    if (pct >= 70) return { text: `${pct}th pct · above avg`,   bg: 'bg-violet-50 text-violet-800' };
    if (pct >= 40) return { text: `${pct}th pct · about avg`,   bg: 'bg-sky-50 text-sky-800' };
    if (pct >= 20) return { text: `${pct}th pct · below avg`,   bg: 'bg-amber-50 text-amber-800' };
    return { text: `${pct}th pct · bottom decile`, bg: 'bg-rose-50 text-rose-800' };
  })();

  return (
    <div className="my-2 bg-white border border-violet-200 rounded-lg p-3 space-y-2">
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-1.5 text-[11px] font-extrabold uppercase tracking-wider text-violet-700">
          <BarChart3 size={12} /> Vs comparable US areas
        </div>
        <button onClick={onClose} className="text-[10px] text-slate-400 hover:text-slate-700 font-semibold">Close</button>
      </div>

      <div className="flex items-center gap-2 flex-wrap">
        <span className={`text-[11px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full ${pctBadge.bg}`}>
          {pctBadge.text}
        </span>
        <span className="text-[11px] text-slate-700 font-semibold">
          vs <span className="text-slate-900">{data.user_area.tier_label}</span>
        </span>
      </div>

      <div className="text-[13px] text-slate-800 leading-snug font-medium">{s.insight}</div>

      <div className="grid grid-cols-3 gap-2 text-center pt-1">
        <Stat label="Yours" value={data.user_area.count} highlight />
        <Stat label="Median (10)" value={s.median ?? '—'} />
        <Stat label={`Range`} value={s.min !== null && s.max !== null ? `${s.min}–${s.max}` : '—'} />
      </div>

      {/* Sorted bar list — user row gets the violet highlight + bold label
          so the reader can find themselves immediately. */}
      <div className="space-y-0.5 pt-1">
        {rows.map((r, i) => {
          const w = Math.round((r.count / maxCount) * 100);
          return (
            <div key={`${r.name}-${i}`} className="flex items-center gap-2 text-[11px] tabular-nums">
              <span className={`flex-1 min-w-0 truncate ${r.isUser ? 'font-extrabold text-violet-800' : 'font-semibold text-slate-700'}`}>
                {r.name}
              </span>
              <div className="w-28 h-2 bg-slate-100 rounded-full overflow-hidden">
                <div
                  className={`h-full ${r.isUser ? 'bg-violet-600' : 'bg-slate-400'}`}
                  style={{ width: `${Math.max(2, w)}%` }}
                />
              </div>
              <span className={`w-7 text-right ${r.isUser ? 'font-extrabold text-violet-800' : 'font-semibold text-slate-700'}`}>
                {r.count}
              </span>
            </div>
          );
        })}
      </div>

      <div className="text-[10px] text-slate-500 font-medium pt-1 border-t border-slate-100">
        Same search run on equal-sized {Math.round(data.reference_radius_meters / 100) / 10} km-radius circles around 10 reference US metros.
      </div>
    </div>
  );
}

function Stat({ label, value, highlight }: { label: string; value: number | string; highlight?: boolean }) {
  return (
    <div className={`px-2 py-1.5 rounded-md border ${highlight ? 'border-violet-300 bg-violet-50' : 'border-slate-200 bg-slate-50'}`}>
      <div className={`text-[9px] font-extrabold uppercase tracking-wider ${highlight ? 'text-violet-700' : 'text-slate-600'}`}>{label}</div>
      <div className={`text-base font-extrabold tabular-nums leading-none mt-0.5 ${highlight ? 'text-violet-800' : 'text-slate-800'}`}>{value}</div>
    </div>
  );
}

function ConcentrationHeader({ count, meta }: { count: number; meta: SearchMeta | null }) {
  const labelColor: Record<DensityLabel, string> = {
    'Sparse':     'bg-slate-100 text-slate-700',
    'Moderate':   'bg-sky-50 text-sky-800',
    'Dense':      'bg-violet-50 text-violet-800',
    'Very dense': 'bg-emerald-50 text-emerald-800',
  };
  return (
    <div className="border-b border-slate-100 pb-2 mb-1">
      <div className="flex items-baseline gap-2">
        <span className="text-2xl font-extrabold tabular-nums" style={{ color: '#1A1A2E' }}>{count}</span>
        <span className="text-xs font-bold uppercase tracking-wider text-slate-700">found</span>
        {meta?.density_label && (
          <span className={`ml-auto text-[11px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full ${labelColor[meta.density_label]}`}>
            {meta.density_label}
          </span>
        )}
      </div>
      {meta && (meta.density_per_sq_km !== null || meta.density_per_1k_people !== null) && (
        <div className="text-[11px] text-slate-700 font-semibold mt-1 flex flex-wrap gap-x-3 gap-y-0.5 tabular-nums">
          {meta.density_per_sq_km !== null && meta.density_per_sq_km !== undefined && (
            <span>
              {meta.density_per_sq_km.toLocaleString(undefined, { maximumFractionDigits: 2 })}
              <span className="text-slate-500 font-medium"> per km²</span>
            </span>
          )}
          {meta.density_per_1k_people !== null && meta.density_per_1k_people !== undefined && (
            <span>
              {meta.density_per_1k_people.toLocaleString(undefined, { maximumFractionDigits: 2 })}
              <span className="text-slate-500 font-medium"> per 1K people</span>
            </span>
          )}
          {meta.area_sq_km !== null && meta.area_sq_km !== undefined && (
            <span className="text-slate-500 font-medium">
              {meta.area_sq_km.toLocaleString(undefined, { maximumFractionDigits: 1 })} km² area
            </span>
          )}
        </div>
      )}
    </div>
  );
}
