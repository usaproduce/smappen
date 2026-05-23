import { useEffect, useMemo, useState } from 'react';
import toast from 'react-hot-toast';
import { X, Sparkles, Layers, Users, Compass, Target, Tag, MessageCircle, History, Bell, MapPinned } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import {
  cannibalizationApi, trafficApi, territoryApi, mclpApi, segmentationApi,
  collabApi, competitorApi, fieldNoteApi,
  type CannibalizationResponse, type SegmentBreakdownRow,
  type CompetitorMonitor, type Comment, type Version, type ChangeRow,
} from '../../api/advanced';

type TabKey =
  | 'territories' | 'cannibalization' | 'traffic' | 'optimize'
  | 'segments' | 'comments' | 'versions' | 'competitors' | 'field';

const TABS: { key: TabKey; label: string; icon: any }[] = [
  { key: 'territories',     label: 'Territories',     icon: Layers },
  { key: 'cannibalization', label: 'Cannibalize',     icon: Users },
  { key: 'traffic',         label: 'Traffic',         icon: Compass },
  { key: 'optimize',        label: 'Optimize',        icon: Target },
  { key: 'segments',        label: 'Segments',        icon: Tag },
  { key: 'comments',        label: 'Comments',        icon: MessageCircle },
  { key: 'versions',        label: 'Versions',        icon: History },
  { key: 'competitors',     label: 'Competitors',     icon: Bell },
  { key: 'field',           label: 'Field notes',     icon: MapPinned },
];

interface Props {
  onClose: () => void;
}

export default function AdvancedPanel({ onClose }: Props) {
  const [tab, setTab] = useState<TabKey>('territories');
  const { currentProject } = useProjectStore();
  if (!currentProject) {
    return (
      <Drawer onClose={onClose} title="Advanced">
        <Empty msg="Open a project to use advanced features." />
      </Drawer>
    );
  }
  return (
    <Drawer onClose={onClose} title="Advanced">
      <nav className="px-2 pt-2 flex flex-wrap gap-1 border-b border-slate-200 pb-2 sticky top-0 bg-white z-10">
        {TABS.map((t) => {
          const Icon = t.icon;
          return (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={`text-[11px] font-semibold px-2.5 py-1.5 rounded-md inline-flex items-center gap-1.5 transition
                ${tab === t.key ? 'bg-violet-100 text-violet-800' : 'text-slate-600 hover:bg-slate-50'}`}
            >
              <Icon size={13} /> {t.label}
            </button>
          );
        })}
      </nav>
      <div className="px-3 py-3 overflow-y-auto" style={{ maxHeight: 'calc(100vh - 160px)' }}>
        {tab === 'territories'     && <TerritoriesTab projectId={currentProject.id} />}
        {tab === 'cannibalization' && <CannibalizeTab projectId={currentProject.id} />}
        {tab === 'traffic'         && <TrafficTab />}
        {tab === 'optimize'        && <OptimizeTab projectId={currentProject.id} />}
        {tab === 'segments'        && <SegmentsTab projectId={currentProject.id} />}
        {tab === 'comments'        && <CommentsTab projectId={currentProject.id} />}
        {tab === 'versions'        && <VersionsTab projectId={currentProject.id} />}
        {tab === 'competitors'     && <CompetitorsTab projectId={currentProject.id} />}
        {tab === 'field'           && <FieldTab projectId={currentProject.id} />}
      </div>
    </Drawer>
  );
}

// ── Shell ─────────────────────────────────────────────────────────────────
function Drawer({ children, onClose, title }: { children: React.ReactNode; onClose: () => void; title: string }) {
  return (
    <aside className="absolute top-4 right-[68px] w-[400px] max-h-[calc(100%-2rem)] bg-white rounded-xl shadow-float border border-slate-200 z-30 flex flex-col">
      <header className="flex items-center justify-between px-3 py-2.5 border-b border-slate-200">
        <div className="flex items-center gap-2 font-bold text-sm" style={{ color: '#1A1A2E' }}>
          <Sparkles size={15} style={{ color: '#7848BB' }} /> {title}
        </div>
        <button onClick={onClose} className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-slate-50">
          <X size={14} />
        </button>
      </header>
      {children}
    </aside>
  );
}
function Empty({ msg }: { msg: string }) {
  return <div className="p-6 text-sm text-slate-500 text-center">{msg}</div>;
}
function Spinner() {
  return <span className="inline-block w-3.5 h-3.5 border-2 border-slate-300 border-t-violet-600 rounded-full animate-spin" />;
}

// ── 1. Territories ────────────────────────────────────────────────────────
function TerritoriesTab({ projectId }: { projectId: string }) {
  const { mapInstance } = useMapStore();
  const [target, setTarget] = useState(8);
  const [metric, setMetric] = useState<'population' | 'income_weighted_pop' | 'housing_units'>('population');
  const [busy, setBusy] = useState(false);
  const [last, setLast] = useState<any>(null);

  async function run() {
    if (!mapInstance) return;
    const b = mapInstance.getBounds();
    if (!b) return;
    const ne = b.getNorthEast(); const sw = b.getSouthWest();
    setBusy(true);
    try {
      const res = await territoryApi.generate(projectId, {
        target_count: target, balance_metric: metric,
        bbox: [sw.lng(), sw.lat(), ne.lng(), ne.lat()],
        name: 'Territory',
        constraints: { max_imbalance_pct: 12 },
      });
      setLast(res);
      toast.success(`Created ${res.territory_count} territories`);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Generation failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-600">Auto-generate balanced territories over the current map view. Each territory becomes an area in this project.</p>
      <Field label="Number of territories">
        <input type="number" min={2} max={30} value={target}
          onChange={(e) => setTarget(parseInt(e.target.value || '0'))}
          className="input h-9 text-sm" />
      </Field>
      <Field label="Balance by">
        <select className="input h-9 text-sm" value={metric} onChange={(e) => setMetric(e.target.value as any)}>
          <option value="population">Population</option>
          <option value="income_weighted_pop">Income-weighted population</option>
          <option value="housing_units">Housing units</option>
        </select>
      </Field>
      <button className="btn btn-primary w-full h-10" onClick={run} disabled={busy}>
        {busy ? <Spinner /> : <Sparkles size={14} />} {busy ? 'Generating…' : 'Generate territories'}
      </button>
      {last && (
        <div className="mt-3 border border-slate-200 rounded-lg p-3 space-y-1.5 text-xs">
          <div className="font-semibold" style={{ color: '#1A1A2E' }}>Result</div>
          <div className="text-slate-600">{last.territory_count} territories · {last.tract_count} tracts</div>
          <ul className="grid grid-cols-2 gap-1 mt-1">
            {last.territories?.map((t: any) => (
              <li key={t.index} className="bg-slate-50 rounded p-2">
                <div className="font-semibold">#{t.index + 1}</div>
                <div>{t.population.toLocaleString()} ppl</div>
                <div className="text-slate-500">{t.pop_share_pct}% share</div>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

// ── 2. Cannibalization ────────────────────────────────────────────────────
function CannibalizeTab({ projectId }: { projectId: string }) {
  const [data, setData] = useState<CannibalizationResponse | null>(null);
  const [busy, setBusy] = useState(false);

  async function run() {
    setBusy(true);
    try {
      setData(await cannibalizationApi.forProject(projectId));
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Analysis failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-600">How much population each pair of your areas shares.</p>
      <button className="btn btn-primary w-full h-10" onClick={run} disabled={busy}>
        {busy ? <Spinner /> : null} {busy ? 'Analyzing…' : 'Analyze overlaps'}
      </button>
      {data?.note && <div className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1.5">{data.note}</div>}
      {data && data.areas.length > 0 && (
        <>
          <div className="text-[10px] font-bold uppercase tracking-wide text-slate-500 mt-3">Per area</div>
          <ul className="text-xs space-y-1">
            {data.areas.map((a) => (
              <li key={a.id} className="flex items-center justify-between bg-slate-50 rounded px-2 py-1.5">
                <span className="flex items-center gap-1.5 truncate">
                  <span className="w-2.5 h-2.5 rounded-sm" style={{ background: a.color }} />
                  <span className="font-semibold truncate" style={{ color: '#1A1A2E' }}>{a.name}</span>
                </span>
                <span className="text-slate-600 shrink-0 ml-2">
                  {a.population.toLocaleString()} · {a.unique_pct}% unique
                </span>
              </li>
            ))}
          </ul>
          {data.overlaps.length > 0 && (
            <>
              <div className="text-[10px] font-bold uppercase tracking-wide text-slate-500 mt-3">Top overlaps</div>
              <ul className="text-xs space-y-1">
                {data.overlaps.slice(0, 10).map((o, i) => (
                  <li key={i} className="bg-rose-50 border border-rose-100 rounded px-2 py-1.5">
                    <div className="font-semibold truncate" style={{ color: '#1A1A2E' }}>
                      {o.area_a_name} ↔ {o.area_b_name}
                    </div>
                    <div className="text-slate-600">
                      {o.shared_population.toLocaleString()} shared ·
                      {' '}{o.pct_of_a}% of A · {o.pct_of_b}% of B
                    </div>
                  </li>
                ))}
              </ul>
            </>
          )}
        </>
      )}
    </div>
  );
}

// ── 3. Traffic ────────────────────────────────────────────────────────────
function TrafficTab() {
  const { mapInstance } = useMapStore();
  const [time, setTime] = useState(15);
  const [day, setDay] = useState<'monday' | 'friday' | 'saturday' | 'sunday'>('monday');
  const [hour, setHour] = useState(8);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<any>(null);

  async function run() {
    if (!mapInstance) return;
    const c = mapInstance.getCenter();
    if (!c) return;
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
          onChange={(e) => setTime(parseInt(e.target.value || '0'))}
          className="input h-9 text-sm" />
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

// ── 4. Optimize ───────────────────────────────────────────────────────────
function OptimizeTab({ projectId }: { projectId: string }) {
  const { mapInstance } = useMapStore();
  const [pickCount, setPickCount] = useState(5);
  const [radiusKm, setRadiusKm] = useState(8);
  const [gridStep, setGridStep] = useState(5);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<any>(null);

  async function run() {
    if (!mapInstance) return;
    const b = mapInstance.getBounds();
    if (!b) return;
    const ne = b.getNorthEast(); const sw = b.getSouthWest();
    setBusy(true);
    try {
      const r = await mclpApi.optimize(projectId, {
        bbox: [sw.lng(), sw.lat(), ne.lng(), ne.lat()],
        grid_step_km: gridStep,
        pick_count: pickCount,
        radius_km: radiusKm,
        demand_metric: 'population',
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
      <p className="text-xs text-slate-600">Find the best K locations within the map view to maximize population covered.</p>
      <Field label="Picks">
        <input type="number" min={1} max={20} value={pickCount}
          onChange={(e) => setPickCount(parseInt(e.target.value || '0'))} className="input h-9 text-sm" />
      </Field>
      <Field label="Reach radius (km)">
        <input type="number" min={1} max={80} value={radiusKm}
          onChange={(e) => setRadiusKm(parseFloat(e.target.value || '0'))} className="input h-9 text-sm" />
      </Field>
      <Field label="Grid step (km)">
        <input type="number" min={1} max={50} value={gridStep}
          onChange={(e) => setGridStep(parseFloat(e.target.value || '0'))} className="input h-9 text-sm" />
      </Field>
      <button className="btn btn-primary w-full h-10" onClick={run} disabled={busy}>
        {busy ? <Spinner /> : <Target size={14} />} {busy ? 'Optimizing…' : 'Find best locations'}
      </button>
      {result && (
        <div className="text-xs space-y-2">
          <div className="bg-emerald-50 border border-emerald-200 rounded p-2">
            <div className="font-bold text-emerald-700">{result.total_covered.toLocaleString()} people covered</div>
            <div className="text-emerald-700/80">{result.coverage_pct ?? '—'}% of universe · {result.candidate_count} candidates</div>
          </div>
          <ol className="space-y-1">
            {result.picks.map((p: any) => (
              <li key={p.rank} className="flex items-center justify-between bg-slate-50 rounded px-2 py-1.5">
                <span className="font-semibold">#{p.rank} ({p.lat.toFixed(3)}, {p.lng.toFixed(3)})</span>
                <span className="text-slate-600">+{p.unique_demand.toLocaleString()}</span>
              </li>
            ))}
          </ol>
        </div>
      )}
    </div>
  );
}

// ── 5. Segments ───────────────────────────────────────────────────────────
function SegmentsTab({ projectId }: { projectId: string }) {
  const [totals, setTotals] = useState<SegmentBreakdownRow[]>([]);
  const [busy, setBusy] = useState(false);

  async function run() {
    setBusy(true);
    try {
      const r = await segmentationApi.forProject(projectId);
      setTotals(r.totals);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed (run /api/segmentation/recompute first?)');
    } finally {
      setBusy(false);
    }
  }

  const total = totals.reduce((s, x) => s + x.population, 0) || 1;
  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-600">Mix of customer segments across this project's areas.</p>
      <button className="btn btn-primary w-full h-10" onClick={run} disabled={busy}>
        {busy ? <Spinner /> : null} {busy ? 'Computing…' : 'Compute segment mix'}
      </button>
      {totals.length > 0 && (
        <ul className="text-xs space-y-1">
          {totals.map((s) => {
            const pct = (100 * s.population) / total;
            return (
              <li key={s.segment_id} className="bg-white border border-slate-200 rounded p-2">
                <div className="flex items-center justify-between mb-1">
                  <span className="font-semibold" style={{ color: '#1A1A2E' }}>{s.segment_name}</span>
                  <span className="text-slate-600">{s.population.toLocaleString()} · {pct.toFixed(1)}%</span>
                </div>
                <div className="h-1.5 rounded bg-slate-100 overflow-hidden">
                  <div className="h-full" style={{ width: `${pct}%`, background: s.color }} />
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

// ── 6. Comments ───────────────────────────────────────────────────────────
function CommentsTab({ projectId }: { projectId: string }) {
  const [comments, setComments] = useState<Comment[]>([]);
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const { selectedAreaId } = useMapStore();

  async function load() {
    try {
      const r = await collabApi.listComments(projectId);
      setComments(r.comments);
    } catch {}
  }
  useEffect(() => { load(); }, [projectId]);

  async function post() {
    if (!body.trim()) return;
    setBusy(true);
    try {
      await collabApi.createComment(projectId, {
        body: body.trim(),
        area_id: selectedAreaId || undefined,
      });
      setBody('');
      await load();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setBusy(false);
    }
  }
  async function resolve(id: string) {
    try { await collabApi.resolveComment(id); await load(); } catch {}
  }

  return (
    <div className="space-y-3">
      <textarea className="input text-sm" placeholder="Leave a comment…" value={body}
        onChange={(e) => setBody(e.target.value)} rows={2} />
      <button className="btn btn-primary w-full h-9" onClick={post} disabled={busy || !body.trim()}>
        {busy ? <Spinner /> : null} Post comment
      </button>
      <ul className="space-y-2">
        {comments.map((c) => (
          <li key={c.id} className={`text-xs border rounded p-2 ${c.resolved_at ? 'bg-slate-50 border-slate-200 opacity-70' : 'bg-white border-slate-200'}`}>
            <div className="flex items-center justify-between mb-0.5">
              <span className="font-semibold" style={{ color: '#1A1A2E' }}>{c.author_name ?? 'Someone'}</span>
              <span className="text-slate-400">{new Date(c.created_at).toLocaleString()}</span>
            </div>
            <div className="text-slate-700 whitespace-pre-wrap">{c.body}</div>
            {!c.resolved_at && (
              <button className="mt-1 text-violet-700 font-semibold hover:underline" onClick={() => resolve(c.id)}>Mark resolved</button>
            )}
          </li>
        ))}
        {comments.length === 0 && <Empty msg="No comments yet." />}
      </ul>
    </div>
  );
}

// ── 7. Versions / History ─────────────────────────────────────────────────
function VersionsTab({ projectId }: { projectId: string }) {
  const [versions, setVersions] = useState<Version[]>([]);
  const [changes, setChanges] = useState<ChangeRow[]>([]);
  const [busy, setBusy] = useState(false);

  async function load() {
    try {
      const [v, c] = await Promise.all([
        collabApi.listVersions(projectId),
        collabApi.listChanges(projectId),
      ]);
      setVersions(v.versions);
      setChanges(c.changes);
    } catch {}
  }
  useEffect(() => { load(); }, [projectId]);

  async function snapshot() {
    setBusy(true);
    try {
      const note = prompt('Snapshot note (optional):') ?? '';
      await collabApi.snapshot(projectId, note);
      toast.success('Snapshot saved');
      await load();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-3">
      <button className="btn btn-primary w-full h-9" onClick={snapshot} disabled={busy}>
        {busy ? <Spinner /> : <History size={14} />} Save snapshot
      </button>
      <div className="text-[10px] font-bold uppercase tracking-wide text-slate-500">Snapshots</div>
      <ul className="space-y-1 text-xs">
        {versions.map((v) => (
          <li key={v.id} className="bg-white border border-slate-200 rounded p-2">
            <div className="flex items-center justify-between">
              <span className="font-semibold" style={{ color: '#1A1A2E' }}>v{v.version_number}</span>
              <span className="text-slate-400">{new Date(v.created_at).toLocaleString()}</span>
            </div>
            {v.note && <div className="text-slate-700 mt-0.5">{v.note}</div>}
            <div className="text-slate-500 mt-0.5">{v.created_by_name ?? 'system'}</div>
          </li>
        ))}
        {versions.length === 0 && <Empty msg="No snapshots yet." />}
      </ul>
      <div className="text-[10px] font-bold uppercase tracking-wide text-slate-500">Recent activity</div>
      <ul className="space-y-1 text-xs">
        {changes.slice(0, 30).map((c) => (
          <li key={c.id} className="text-slate-700">
            <span className="font-semibold">{c.user_name ?? 'someone'}</span>{' '}
            <span className="text-slate-600">{c.action} {c.entity_type}</span>{' '}
            <span className="text-slate-400">· {new Date(c.created_at).toLocaleString()}</span>
          </li>
        ))}
        {changes.length === 0 && <Empty msg="No activity yet." />}
      </ul>
    </div>
  );
}

// ── 8. Competitors ────────────────────────────────────────────────────────
function CompetitorsTab({ projectId }: { projectId: string }) {
  const [monitors, setMonitors] = useState<CompetitorMonitor[]>([]);
  const [name, setName] = useState('');
  const [types, setTypes] = useState('restaurant');
  const [busy, setBusy] = useState(false);

  async function load() {
    try { setMonitors((await competitorApi.list(projectId)).monitors); } catch {}
  }
  useEffect(() => { load(); }, [projectId]);

  async function create() {
    if (!name.trim() || !types.trim()) return;
    setBusy(true);
    try {
      await competitorApi.create(projectId, {
        name: name.trim(),
        place_types: types.split(',').map((s) => s.trim()).filter(Boolean),
        frequency: 'weekly',
      });
      setName('');
      await load();
      toast.success('Monitor created');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setBusy(false);
    }
  }

  async function scanNow(id: string) {
    setBusy(true);
    try {
      const r = await competitorApi.scanNow(id);
      toast.success(`Scan done: ${r.new_count} new, ${r.gone_count} gone, ${r.moved_count} moved`);
      await load();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Scan failed');
    } finally {
      setBusy(false);
    }
  }
  async function remove(id: string) {
    if (!confirm('Remove this monitor?')) return;
    try { await competitorApi.remove(id); await load(); } catch {}
  }

  return (
    <div className="space-y-3">
      <div className="bg-slate-50 rounded p-2 space-y-1.5">
        <Field label="Monitor name">
          <input className="input h-9 text-sm" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Italian restaurants near HQ" />
        </Field>
        <Field label="Place types (comma-separated)">
          <input className="input h-9 text-sm" value={types} onChange={(e) => setTypes(e.target.value)} />
        </Field>
        <button className="btn btn-primary w-full h-9" onClick={create} disabled={busy || !name.trim()}>
          Add monitor
        </button>
      </div>
      <ul className="space-y-2">
        {monitors.map((m) => (
          <li key={m.id} className="bg-white border border-slate-200 rounded p-2 text-xs">
            <div className="flex items-center justify-between mb-1">
              <span className="font-semibold" style={{ color: '#1A1A2E' }}>{m.name}</span>
              <span className="text-slate-500">{m.frequency}</span>
            </div>
            <div className="text-slate-600">{(m.place_types || []).join(', ')}</div>
            <div className="flex items-center gap-2 mt-1.5">
              <span className="text-slate-500">{m.active_places ?? 0} active</span>
              {(m.unread_alerts ?? 0) > 0 && <span className="bg-rose-100 text-rose-700 px-1.5 py-0.5 rounded font-semibold">{m.unread_alerts} alerts</span>}
              <div className="flex-1" />
              <button className="text-violet-700 font-semibold hover:underline" onClick={() => scanNow(m.id)} disabled={busy}>Scan now</button>
              <button className="text-rose-600 font-semibold hover:underline" onClick={() => remove(m.id)}>Remove</button>
            </div>
          </li>
        ))}
        {monitors.length === 0 && <Empty msg="No monitors yet." />}
      </ul>
    </div>
  );
}

// ── 9. Field notes ────────────────────────────────────────────────────────
function FieldTab({ projectId }: { projectId: string }) {
  const [notes, setNotes] = useState<any[]>([]);
  const [body, setBody] = useState('');
  const [pos, setPos] = useState<{ lat: number; lng: number; accuracy?: number } | null>(null);
  const [busy, setBusy] = useState(false);
  const { mapInstance } = useMapStore();

  async function load() {
    try { setNotes((await fieldNoteApi.list(projectId)).field_notes); } catch {}
  }
  useEffect(() => { load(); }, [projectId]);

  function locate() {
    if (!navigator.geolocation) {
      toast.error('Geolocation not available');
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (p) => setPos({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }),
      () => toast.error('Could not get location'),
      { enableHighAccuracy: true, timeout: 10_000 }
    );
  }

  function useMapCenter() {
    const c = mapInstance?.getCenter();
    if (!c) return;
    setPos({ lat: c.lat(), lng: c.lng() });
  }

  async function save() {
    if (!body.trim() || !pos) return;
    setBusy(true);
    try {
      await fieldNoteApi.create(projectId, {
        body: body.trim(), lat: pos.lat, lng: pos.lng,
        accuracy_m: pos.accuracy,
      });
      setBody('');
      await load();
      toast.success('Note saved');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-600">Capture geo-stamped notes from the field. Works offline (PWA-installed).</p>
      <textarea className="input text-sm" placeholder="What did you see?" value={body}
        onChange={(e) => setBody(e.target.value)} rows={2} />
      <div className="flex gap-2">
        <button className="btn btn-secondary h-9 flex-1 text-xs" onClick={locate}>Use my location</button>
        <button className="btn btn-secondary h-9 flex-1 text-xs" onClick={useMapCenter}>Use map center</button>
      </div>
      {pos && (
        <div className="text-[11px] text-slate-600 bg-slate-50 rounded px-2 py-1">
          {pos.lat.toFixed(5)}, {pos.lng.toFixed(5)}
          {pos.accuracy ? ` · ±${Math.round(pos.accuracy)}m` : ''}
        </div>
      )}
      <button className="btn btn-primary w-full h-9" onClick={save} disabled={busy || !pos || !body.trim()}>
        Save note
      </button>
      <ul className="space-y-2 mt-2">
        {notes.map((n) => (
          <li key={n.id} className="bg-white border border-slate-200 rounded p-2 text-xs">
            <div className="flex items-center justify-between mb-0.5">
              <span className="font-semibold" style={{ color: '#1A1A2E' }}>{n.author_name ?? 'You'}</span>
              <span className="text-slate-400">{new Date(n.captured_at).toLocaleString()}</span>
            </div>
            <div className="text-slate-700">{n.body}</div>
            <div className="text-slate-500 mt-0.5">{n.lat.toFixed(5)}, {n.lng.toFixed(5)}</div>
          </li>
        ))}
        {notes.length === 0 && <Empty msg="No notes yet." />}
      </ul>
    </div>
  );
}

// ── small UI helpers ──────────────────────────────────────────────────────
function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1 block">{label}</span>
      {children}
    </label>
  );
}
