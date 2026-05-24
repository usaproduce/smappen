import { useMemo, useState } from 'react';
import toast from 'react-hot-toast';
import { Search, MapPin, Sparkles } from 'lucide-react';
import { useProjectStore } from '../../stores/projectStore';
import { useMapStore } from '../../stores/mapStore';
import { analogsApi, type AnalogCandidate, type AnalogResponse, type RadarData } from '../../api/analogs';
import { Spinner, Field, Empty } from './shared';

/**
 * Analog Finder tab — "find me places that look like my best store."
 *
 * Source area is the currently-selected area on the map. User picks a max
 * result count + optional search radius, hits Find, and gets a ranked list
 * of census tracts with similarity scores and a radar comparison.
 *
 * Tract pins are also pushed into mapStore.analogResults so MapCanvas can
 * render numbered overlay markers — see MapCanvas.tsx.
 */
export default function AnalogTab({ projectId: _projectId }: { projectId: string }) {
  void _projectId;
  const { areas } = useProjectStore();
  const { selectedAreaId, setCenter, setZoom, setAnalogResults, clearAnalogResults, mapInstance } = useMapStore();

  const [loading, setLoading] = useState(false);
  const [results, setResults] = useState<AnalogResponse | null>(null);
  const [maxResults, setMaxResults] = useState(25);
  // Default to 200km — keeps first-time scans under ~5s on the 84K-tract
  // national dataset. "Entire US" is still an option but the user has to
  // opt into it knowing it'll take longer.
  const [radiusKm, setRadiusKm] = useState<number | null>(200);
  const [expandedId, setExpandedId] = useState<string | null>(null);

  // Source area MUST have demographics to fingerprint — radius/manual areas
  // with no demographics_cache and no isochrone data will get rejected by
  // the backend. We pre-filter here to give a clearer hint.
  const eligibleAreas = useMemo(() => {
    return areas.filter((a) => {
      const dc: any = (a as any).demographics_cache;
      if (!dc) return false;
      const parsed = typeof dc === 'string' ? safeParse(dc) : dc;
      const total = parsed?.population?.total ?? parsed?.total_population ?? 0;
      return total > 0;
    });
  }, [areas]);

  const sourceArea = eligibleAreas.find((a) => a.id === selectedAreaId);

  async function run() {
    if (!sourceArea) {
      toast.error('Select an area with demographics first');
      return;
    }
    setLoading(true);
    try {
      const data = await analogsApi.find(sourceArea.id, {
        max_results: maxResults,
        search_radius_km: radiusKm,
      });
      setResults(data);
      setAnalogResults(data.results, sourceArea.id);
      // Fit the map to the analog results bbox so the user sees the spread.
      // Guard against the source area being deleted / map unmounted mid-fetch.
      if (data.results.length > 0 && mapInstance && sourceArea.center_lat != null && sourceArea.center_lng != null) {
        try {
          const bounds = new google.maps.LatLngBounds();
          bounds.extend({ lat: sourceArea.center_lat, lng: sourceArea.center_lng });
          for (const r of data.results) bounds.extend({ lat: r.lat, lng: r.lng });
          mapInstance.fitBounds(bounds, 60);
        } catch { /* map gone — ignore */ }
      }
      toast.success(`${data.results.length} matches found across ${data.total_candidates.toLocaleString()} tracts`);
    } catch (e: any) {
      const err = e?.response?.data?.error ?? 'Analog search failed';
      toast.error(err);
    } finally {
      setLoading(false);
    }
  }

  function flyTo(c: AnalogCandidate) {
    setCenter({ lat: c.lat, lng: c.lng });
    setZoom(13);
    setExpandedId(c.geoid);
  }

  function clearResults() {
    setResults(null);
    setExpandedId(null);
    clearAnalogResults();
  }

  function similarityColor(sim: number) {
    if (sim >= 0.9) return '#1D9E75';
    if (sim >= 0.75) return '#378ADD';
    if (sim >= 0.6) return '#EF9F27';
    return '#D85A30';
  }

  return (
    <div className="space-y-3">
      <div>
        <div className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-violet-700">
          <Sparkles size={11} /> Analog finder
        </div>
        <p className="text-xs text-slate-600 mt-1 leading-snug">
          Pick your best location, and Smappen finds every census tract across the country
          with a matching demographic and competitive profile.
        </p>
      </div>

      {/* Source pill */}
      <div className="bg-violet-50 border border-violet-200 rounded-lg p-2.5">
        <div className="text-[10px] uppercase tracking-wider font-bold text-violet-700 mb-0.5">Source area</div>
        {sourceArea ? (
          <div className="flex items-center gap-2 min-w-0">
            <span className="inline-block w-3 h-3 rounded-full shrink-0 border border-black/10"
                  style={{ background: sourceArea.fill_color || '#7848BB' }} />
            <div className="min-w-0 flex-1">
              <div className="text-sm font-semibold truncate" style={{ color: '#1A1A2E' }}>{sourceArea.name}</div>
              <div className="text-[11px] text-slate-500 truncate">
                {((sourceArea as any).demographics_cache && extractPop(sourceArea)) || '—'}
                {sourceArea.travel_time_minutes ? ` · ${sourceArea.travel_time_minutes} min` : ''}
              </div>
            </div>
          </div>
        ) : (
          <div className="text-xs text-slate-500 italic">
            {eligibleAreas.length === 0
              ? 'No areas have demographics yet. Open the Demographics tab on an area first.'
              : 'Click an area on the map (one with demographics loaded) to use it as the source.'}
          </div>
        )}
      </div>

      {/* Controls */}
      <div className="grid grid-cols-2 gap-2">
        <Field label="Matches">
          <select
            className="input h-8 text-xs"
            value={maxResults}
            onChange={(e) => setMaxResults(parseInt(e.target.value, 10))}
          >
            <option value={10}>10</option>
            <option value={25}>25</option>
            <option value={50}>50</option>
          </select>
        </Field>
        <Field label="Search radius">
          <select
            className="input h-8 text-xs"
            value={radiusKm ?? 'all'}
            onChange={(e) => setRadiusKm(e.target.value === 'all' ? null : parseInt(e.target.value, 10))}
            title="Smaller radius = faster results"
          >
            <option value={50}>50 km</option>
            <option value={100}>100 km</option>
            <option value={200}>200 km</option>
            <option value={500}>500 km</option>
            <option value={1000}>1000 km</option>
            <option value="all">Entire US (slow)</option>
          </select>
        </Field>
      </div>

      {/* VT19 — sticky so it stays in view when results scroll past 1 screen. */}
      <div className="sticky top-0 z-10 -mx-3 px-3 py-2 bg-white">
        <button
          className="btn btn-primary w-full justify-center"
          disabled={!sourceArea || loading}
          onClick={run}
        >
          {loading ? <><Spinner /> Searching tracts…</> : <><Search size={14} /> Find analogs</>}
        </button>
      </div>

      {/* Results */}
      {results && (
        <div className="space-y-2 mt-2">
          <div className="flex items-center justify-between text-[11px] text-slate-500 font-medium">
            <span>
              <b className="text-slate-700">{results.results.length}</b> matches
              <span className="text-slate-400"> · {results.total_candidates.toLocaleString()} scanned</span>
            </span>
            <button onClick={clearResults} className="text-violet-700 hover:underline text-[11px] font-semibold">Clear</button>
          </div>

          {/* Legend */}
          <div className="flex gap-3 text-[10px] text-slate-500 font-semibold">
            <span className="flex items-center gap-1">
              <span className="w-2.5 h-2.5 rounded-sm" style={{ background: '#7848BB', opacity: 0.4, border: '1px solid #7848BB' }} />
              Source
            </span>
            <span className="flex items-center gap-1">
              <span className="w-2.5 h-2.5 rounded-sm" style={{ background: '#1D9E75', opacity: 0.4, border: '1px solid #1D9E75' }} />
              Candidate
            </span>
          </div>

          {results.results.length === 0 && (
            <Empty msg="No analogs above the 50% similarity floor. Try widening the radius or pick a different source area." />
          )}

          {results.results.map((c, idx) => {
            const open = expandedId === c.geoid;
            return (
              <div
                key={c.geoid}
                className="border rounded-lg overflow-hidden border-slate-200 bg-white card-expand"
                style={{ ['--stagger-i' as any]: idx }}
              >
                <button
                  onClick={() => setExpandedId(open ? null : c.geoid)}
                  className="w-full flex items-center gap-2 px-2.5 py-2 text-left hover:bg-slate-50"
                >
                  <span
                    className="w-6 h-6 rounded-full text-[11px] font-bold flex items-center justify-center shrink-0"
                    style={{ background: similarityColor(c.similarity), color: '#fff' }}
                  >
                    {idx + 1}
                  </span>
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-semibold truncate" style={{ color: '#1A1A2E' }}>
                      Tract {c.name || c.geoid}
                    </div>
                    <div className="text-[11px] text-slate-500 truncate">
                      {c.demographics.dominant_segment ? c.demographics.dominant_segment.replace(/-/g, ' ') : 'unsegmented'}
                      {' · '}{(c.demographics.population ?? 0).toLocaleString()} pop
                      {c.demographics.median_income != null && ` · $${(c.demographics.median_income / 1000).toFixed(0)}k inc`}
                    </div>
                  </div>
                  <span
                    className="text-sm font-extrabold tabular-nums shrink-0"
                    style={{ color: similarityColor(c.similarity) }}
                  >
                    {Math.round(c.similarity * 100)}%
                  </span>
                </button>

                {open && (
                  <div className="px-3 pb-3 border-t border-slate-100 pt-3 space-y-3">
                    <div className="flex justify-center">
                      <RadarChart axes={c.radar.axes} source={results.source_vector.candidate} candidate={c.radar.candidate} />
                    </div>
                    <div className="grid grid-cols-2 gap-2 text-xs">
                      <Stat label="Density"      value={c.demographics.density_per_sqkm != null ? `${Math.round(c.demographics.density_per_sqkm).toLocaleString()}/km²` : '—'} />
                      <Stat label="Med. income"  value={c.demographics.median_income != null ? `$${c.demographics.median_income.toLocaleString()}` : '—'} />
                      <Stat label="Home value"   value={c.demographics.median_home_value ? `$${(c.demographics.median_home_value / 1000).toFixed(0)}k` : '—'} />
                      <Stat label="State"        value={`${c.state_fips}-${c.county_fips}`} />
                    </div>
                    <div className="flex gap-2">
                      <button
                        onClick={() => flyTo(c)}
                        className="btn btn-secondary flex-1 h-8 text-xs justify-center"
                      >
                        <MapPin size={12} /> Fly to
                      </button>
                      <button
                        onClick={() => {
                          setCenter({ lat: c.lat, lng: c.lng });
                          setZoom(13);
                          toast.success('Centered. Use the toolbar to draw an isochrone here.', { icon: '📍' });
                        }}
                        className="btn btn-primary flex-1 h-8 text-xs justify-center"
                      >
                        Create area here
                      </button>
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="bg-slate-50 rounded-md px-2 py-1.5 border border-slate-100">
      <div className="text-[9px] uppercase tracking-wider font-bold text-slate-400">{label}</div>
      <div className="text-[12px] font-bold mt-0.5" style={{ color: '#1A1A2E' }}>{value}</div>
    </div>
  );
}

/**
 * SVG radar chart. Source polygon is violet, candidate is teal.
 * Six axes correspond to the buildRadarData() collapse on the backend.
 */
function RadarChart({ axes, source, candidate }: RadarData) {
  const size = 220;
  const cx = size / 2;
  const cy = size / 2;
  const r = 70;
  const n = axes.length;

  const point = (i: number, val: number) => {
    const angle = (Math.PI * 2 * i) / n - Math.PI / 2;
    return { x: cx + r * val * Math.cos(angle), y: cy + r * val * Math.sin(angle) };
  };
  const ringPts = (val: number) =>
    Array.from({ length: n }, (_, i) => {
      const p = point(i, val);
      return `${p.x.toFixed(2)},${p.y.toFixed(2)}`;
    }).join(' ');
  const valuePts = (vals: (number | null)[]) =>
    vals.map((v, i) => {
      const p = point(i, v ?? 0);
      return `${p.x.toFixed(2)},${p.y.toFixed(2)}`;
    }).join(' ');

  return (
    <svg viewBox={`0 0 ${size} ${size}`} width={size} height={size}>
      {/* Grid rings */}
      {[0.25, 0.5, 0.75, 1.0].map((v) => (
        <polygon key={v} points={ringPts(v)} fill="none" stroke="#E8E8EE" strokeWidth={0.6} />
      ))}
      {/* Axis spokes */}
      {axes.map((_, i) => {
        const p = point(i, 1);
        return <line key={i} x1={cx} y1={cy} x2={p.x} y2={p.y} stroke="#E8E8EE" strokeWidth={0.6} />;
      })}
      {/* Source polygon (violet) */}
      <polygon points={valuePts(source)} fill="#7848BB" fillOpacity={0.18} stroke="#7848BB" strokeWidth={1.4} />
      {/* Candidate polygon (teal) */}
      <polygon points={valuePts(candidate)} fill="#1D9E75" fillOpacity={0.22} stroke="#1D9E75" strokeWidth={1.4} />
      {/* Axis labels */}
      {axes.map((label, i) => {
        const p = point(i, 1.32);
        return (
          <text
            key={i}
            x={p.x}
            y={p.y}
            textAnchor="middle"
            dominantBaseline="middle"
            fontSize={9}
            fontWeight={600}
            fill="#6B6B7B"
          >
            {label}
          </text>
        );
      })}
    </svg>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────

function safeParse(s: string): any {
  try { return JSON.parse(s); } catch { return null; }
}

function extractPop(area: any): string {
  const dc = area.demographics_cache;
  const parsed = typeof dc === 'string' ? safeParse(dc) : dc;
  const total = parsed?.population?.total ?? parsed?.total_population;
  return typeof total === 'number' ? `${total.toLocaleString()} people` : '';
}
