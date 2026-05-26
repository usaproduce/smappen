import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Pause, Play, Square, Sparkles, RefreshCw, AlertTriangle } from 'lucide-react';
import { carafeApi } from '../../api/carafe';
import AnimatedNumber from '../common/AnimatedNumber';

/**
 * /admin/carafe/campaigns/:id — live run dashboard.
 * Spec v3 §5.3 + §8.
 *
 * Lists actual progress against the original estimate, plus the action
 * buttons for run / pause / resume / cancel / enrich / re-sweep. Polls
 * every 5s while the campaign is running.
 */
export default function SeedCampaignDetailPage() {
  const { id = '' } = useParams();
  const qc = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['carafe', 'campaign', id],
    queryFn: () => carafeApi.getCampaign(id),
    refetchInterval: (q) => (q.state.data?.campaign.status === 'running' ? 5_000 : 20_000),
  });

  const { data: delta } = useQuery({
    queryKey: ['carafe', 'campaign', id, 'delta'],
    queryFn: () => carafeApi.deltaSummary(id),
    enabled: !!data,
    staleTime: 60_000,
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ['carafe', 'campaign', id] });

  const run = useMutation({
    mutationFn: () => carafeApi.runCampaign(id),
    onSuccess: () => { toast.success('Running — tiles enqueued'); invalidate(); },
    onError:   (e: any) => toast.error(e?.response?.data?.error ?? 'Run failed'),
  });
  const pause = useMutation({
    mutationFn: () => carafeApi.pauseCampaign(id, 'manual'),
    onSuccess: () => { toast.success('Paused'); invalidate(); },
    onError:   (e: any) => toast.error(e?.response?.data?.error ?? 'Pause failed'),
  });
  const resume = useMutation({
    mutationFn: () => carafeApi.resumeCampaign(id),
    onSuccess: () => { toast.success('Resumed'); invalidate(); },
    onError:   (e: any) => toast.error(e?.response?.data?.error ?? 'Resume failed'),
  });
  const cancel = useMutation({
    mutationFn: () => carafeApi.cancelCampaign(id),
    onSuccess: () => { toast.success('Cancelled'); invalidate(); },
    onError:   (e: any) => toast.error(e?.response?.data?.error ?? 'Cancel failed'),
  });
  const enrich = useMutation({
    mutationFn: () => carafeApi.enrichCampaign(id, 100),
    onSuccess: (r) => { toast.success('Enriched: ' + JSON.stringify(r.result)); invalidate(); },
    onError:   (e: any) => toast.error(e?.response?.data?.error ?? 'Enrich failed'),
  });
  const resweep = useMutation({
    mutationFn: () => carafeApi.resweepCampaign(id),
    onSuccess: (r) => { toast.success(`Re-queued ${r.requeued} tile(s)`); invalidate(); },
    onError:   (e: any) => toast.error(e?.response?.data?.error ?? 'Re-sweep failed'),
  });

  if (isLoading || !data) {
    return <div className="text-slate-500 text-sm">Loading…</div>;
  }
  const c = data.campaign;
  let types: string[] = [];
  try { types = JSON.parse(c.vendor_types_json ?? '[]') || []; } catch { /* ignore */ }
  const overCap = c.budget_cap_usd != null && +c.spent_usd > +c.budget_cap_usd;
  const tileProgressPct = c.tile_count > 0 ? Math.round((100 * c.tiles_done_count) / c.tile_count) : 0;

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <div>
          <div className="text-xs text-slate-500">
            <Link to="/admin/carafe/campaigns" className="hover:text-slate-900">Campaigns</Link>
            <span className="mx-1">/</span>
            <code className="text-[11px]">{c.id.slice(0, 8)}</code>
          </div>
          <h1 className="text-2xl font-extrabold text-slate-900">{c.name}</h1>
          <div className="text-xs text-slate-500 mt-1">
            <StatusBadge status={c.status} /> · {c.density_profile} · {c.enrich_policy} · {types.length} types
          </div>
        </div>
        <div className="flex items-center gap-2">
          {(c.status === 'draft' || c.status === 'estimating' || c.status === 'approved') && (
            <button className="btn btn-primary h-9 px-3 text-sm" disabled={run.isPending} onClick={() => run.mutate()}>
              <Play size={14} /> Run
            </button>
          )}
          {c.status === 'running' && (
            <button className="btn h-9 px-3 text-sm bg-amber-500 text-white hover:bg-amber-600" onClick={() => pause.mutate()}>
              <Pause size={14} /> Pause
            </button>
          )}
          {c.status === 'paused' && (
            <button className="btn h-9 px-3 text-sm bg-emerald-500 text-white hover:bg-emerald-600" onClick={() => resume.mutate()}>
              <Play size={14} /> Resume
            </button>
          )}
          {(c.status === 'running' || c.status === 'paused' || c.status === 'approved') && (
            <button className="btn h-9 px-3 text-sm bg-slate-100 text-slate-700 hover:bg-slate-200" onClick={() => {
              if (confirm('Cancel campaign? Queued tiles will be skipped.')) cancel.mutate();
            }}>
              <Square size={14} /> Cancel
            </button>
          )}
        </div>
      </div>

      {/* Pause banner */}
      {c.status === 'paused' && c.pause_reason && (
        <div className="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg flex items-center gap-2 text-sm">
          <AlertTriangle size={14} className="text-amber-600" />
          <span className="font-semibold text-amber-900">Paused:</span>
          <span className="text-amber-800">{c.pause_reason}</span>
        </div>
      )}

      {/* Spend + budget cap */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <Tile label="Spent" value={
          <span className={overCap ? 'text-red-600' : 'text-slate-900'}>
            $<AnimatedNumber value={+c.spent_usd} format={(n) => n.toFixed(2)} />
          </span>
        } sub={c.budget_cap_usd != null ? `cap $${(+c.budget_cap_usd).toFixed(2)}` : 'no cap'} />
        <Tile
          label="Tiles done"
          value={<><AnimatedNumber value={c.tiles_done_count} /> / {c.tile_count}</>}
          sub={`${tileProgressPct}% complete`}
        />
        <Tile label="Vendors" value={<AnimatedNumber value={c.vendor_count} />} sub="new + existing rows touched" />
        <Tile label="Estimate" value={c.estimate_expected_usd != null ? `$${(+c.estimate_expected_usd).toFixed(2)}` : '—'} sub={
          c.estimate_low_usd != null && c.estimate_high_usd != null
            ? `range $${(+c.estimate_low_usd).toFixed(2)} – $${(+c.estimate_high_usd).toFixed(2)}`
            : ''
        } />
      </div>

      {/* Progress bar */}
      {c.tile_count > 0 && (
        <div className="mb-6">
          <div className="flex justify-between text-xs text-slate-600 mb-1">
            <span>Tile progress</span>
            <span className="tabular-nums">{c.tiles_done_count}/{c.tile_count}</span>
          </div>
          <div className="h-2 bg-slate-100 rounded overflow-hidden">
            <div
              className="h-full bg-emerald-500 transition-all"
              style={{ width: `${tileProgressPct}%` }}
            />
          </div>
          {c.tile_stats && (
            <div className="flex gap-4 mt-2 text-xs text-slate-500">
              <span><b className="text-slate-700">{c.tile_stats.queued}</b> queued</span>
              <span><b className="text-slate-700">{c.tile_stats.running}</b> running</span>
              <span><b className="text-slate-700">{c.tile_stats.done}</b> done</span>
              <span><b className="text-red-700">{c.tile_stats.failed}</b> failed</span>
            </div>
          )}
        </div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <ActionCard
          title="Enrich"
          description="Pull Place Details per the campaign's policy. Coalesced — already-enriched vendors skip the call."
          actionLabel={enrich.isPending ? 'Enriching…' : 'Enrich now'}
          icon={<Sparkles size={14} />}
          disabled={c.status !== 'running' || enrich.isPending}
          onClick={() => enrich.mutate()}
        />
        <ActionCard
          title="Re-sweep"
          description={
            delta
              ? `${delta.delta.resweep_eligible} of ${delta.delta.total_tiles} tile(s) older than 30 days are eligible for re-sweep.${delta.delta.stuck_running > 0 ? ` ${delta.delta.stuck_running} stuck running.` : ''}`
              : 'Re-queue tiles done > 30 days ago. Unchanged tiles skip downstream upserts.'
          }
          actionLabel={resweep.isPending ? 'Scheduling…' : 'Schedule re-sweep'}
          icon={<RefreshCw size={14} />}
          disabled={resweep.isPending || (delta && delta.delta.resweep_eligible === 0)}
          onClick={() => resweep.mutate()}
        />
      </div>

      <div className="mt-6 text-xs text-slate-500">
        Created {new Date(c.created_at).toLocaleString()}
        {c.started_at && ` · Started ${new Date(c.started_at).toLocaleString()}`}
        {c.finished_at && ` · Finished ${new Date(c.finished_at).toLocaleString()}`}
      </div>
    </div>
  );
}

// ─── Sub-components ────────────────────────────────────────────────────

function Tile({ label, value, sub }: { label: string; value: React.ReactNode; sub?: string }) {
  return (
    <div className="bg-white border border-slate-200 rounded-lg p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500">{label}</div>
      <div className="text-2xl font-extrabold mt-1 tabular-nums">{value}</div>
      {sub && <div className="text-[11px] text-slate-500 mt-1">{sub}</div>}
    </div>
  );
}

function ActionCard({ title, description, actionLabel, icon, disabled, onClick }: { title: string; description: string; actionLabel: string; icon: React.ReactNode; disabled?: boolean; onClick: () => void }) {
  return (
    <div className="bg-white border border-slate-200 rounded-lg p-4">
      <h3 className="font-bold text-slate-900 text-sm">{title}</h3>
      <p className="text-xs text-slate-600 mt-1 mb-3">{description}</p>
      <button
        className="btn h-9 px-3 text-sm bg-slate-900 text-white hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed"
        disabled={disabled}
        onClick={onClick}
      >
        {icon} {actionLabel}
      </button>
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  const palette: Record<string, string> = {
    draft:      'bg-slate-100 text-slate-700',
    estimating: 'bg-blue-50 text-blue-700',
    approved:   'bg-blue-50 text-blue-700',
    running:    'bg-emerald-50 text-emerald-700',
    paused:     'bg-amber-50 text-amber-700',
    done:       'bg-emerald-100 text-emerald-800',
    failed:     'bg-red-50 text-red-700',
    cancelled:  'bg-slate-100 text-slate-500',
  };
  return (
    <span className={`inline-block text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded ${palette[status] ?? palette.draft}`}>
      {status}
    </span>
  );
}
