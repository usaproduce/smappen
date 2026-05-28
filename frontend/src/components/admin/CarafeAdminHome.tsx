import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, MapPin, ListChecks, ShieldCheck, AlertTriangle, Activity } from 'lucide-react';
import { carafeApi, type CronWorker } from '../../api/carafe';
import { useAuthStore } from '../../stores/authStore';

/**
 * Carafe admin landing page. Spec v3 §8.
 *
 * Three blocks:
 *   - Grant banner (storage permission scope + reference)
 *   - Queue counts (dedupe + classify) — click-through to /review
 *   - Recent campaigns + "New campaign" CTA
 */
export default function CarafeAdminHome() {
  const user = useAuthStore((s) => s.user);

  const { data: queue } = useQuery({
    queryKey: ['carafe', 'review-queue', 'counts'],
    queryFn: async () => (await carafeApi.reviewQueue(undefined, 1, 0)).counts,
    refetchInterval: 30_000,
  });

  const { data: campaigns } = useQuery({
    queryKey: ['carafe', 'campaigns', 'recent'],
    queryFn: () => carafeApi.listCampaigns(10, 0),
  });

  const { data: cron } = useQuery({
    queryKey: ['carafe', 'cron-health'],
    queryFn: () => carafeApi.cronHealth(),
    refetchInterval: 60_000,
  });
  const staleWorkers = (cron?.workers ?? []).filter((w) => w.status === 'red');

  return (
    <div>
      <div className="flex items-end justify-between mb-6">
        <div>
          <h1 className="text-2xl font-extrabold text-slate-900">Vendor Network Admin</h1>
          <p className="text-slate-600 mt-1 text-sm">
            Signed in as <span className="font-semibold">{user?.email}</span> ·
            role <span className="font-semibold">{user?.role}</span>
          </p>
        </div>
        <Link to="/admin/carafe/campaigns/new" className="btn btn-primary h-10 px-4 text-sm">
          <MapPin size={14} /> New campaign
        </Link>
      </div>

      {staleWorkers.length > 0 && <StaleWorkerBanner workers={staleWorkers} />}

      <GrantBanner />

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <QueueTile
          label="Dedupe review"
          count={queue?.dedupe ?? 0}
          to="/admin/carafe/review?kind=dedupe"
          color="amber"
        />
        <QueueTile
          label="Classify review"
          count={queue?.classify ?? 0}
          to="/admin/carafe/review?kind=classify"
          color="violet"
        />
        <QueueTile
          label="Total pending"
          count={queue?.total ?? 0}
          to="/admin/carafe/review"
          color="slate"
        />
      </div>

      <section className="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div className="flex items-center justify-between px-5 py-3 border-b border-slate-200">
          <h2 className="font-bold text-slate-900">Recent campaigns</h2>
          <Link to="/admin/carafe/campaigns" className="text-xs font-semibold text-slate-500 hover:text-slate-900 flex items-center gap-1">
            All campaigns <ArrowRight size={12} />
          </Link>
        </div>
        {campaigns && campaigns.campaigns.length > 0 ? (
          <ul className="divide-y divide-slate-100">
            {campaigns.campaigns.slice(0, 6).map((c) => (
              <li key={c.id}>
                <Link to={`/admin/carafe/campaigns/${c.id}`} className="flex items-center justify-between px-5 py-3 hover:bg-slate-50">
                  <div>
                    <div className="font-semibold text-slate-900">{c.name}</div>
                    <div className="text-xs text-slate-500">
                      {c.density_profile} · {c.enrich_policy} · {c.tile_count} tiles
                    </div>
                  </div>
                  <div className="text-right">
                    <StatusBadge status={c.status} />
                    <div className="text-xs text-slate-500 mt-1">${(+c.spent_usd).toFixed(2)} spent</div>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <div className="p-8 text-center text-slate-500 text-sm">
            No campaigns yet.&nbsp;
            <Link to="/admin/carafe/campaigns/new" className="font-semibold text-violet-600 hover:underline">Create one →</Link>
          </div>
        )}
      </section>

      <div className="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        <PipelineCard workers={cron?.workers ?? []} />
        <SafetyCard />
      </div>
    </div>
  );
}

function StaleWorkerBanner({ workers }: { workers: CronWorker[] }) {
  return (
    <div className="bg-red-50 border border-red-300 rounded-lg p-4 mb-6 flex items-start gap-3">
      <AlertTriangle size={18} className="text-red-600 mt-0.5 flex-shrink-0" />
      <div className="flex-1">
        <div className="font-bold text-red-900 text-sm">
          {workers.length === 1 ? '1 worker is stale' : `${workers.length} workers are stale`}
        </div>
        <p className="text-xs text-red-800 mt-1">
          The following Carafe worker(s) haven't sent a heartbeat in more than 2× their expected cadence.
          Check cron with <code className="px-1 py-0.5 bg-red-100 rounded">crontab -l</code> on the droplet and tail the matching log under
          <code className="px-1 py-0.5 bg-red-100 rounded ml-1">storage/logs/cron/</code>.
        </p>
        <ul className="mt-2 space-y-0.5 text-xs text-red-900 font-mono">
          {workers.map((w) => (
            <li key={w.name}>
              <span className="font-bold">{w.name}</span>{' '}
              — {w.last_beat_at ? `last beat ${formatAge(w.last_beat_age_seconds)} ago` : 'never beat'}
              {' '}(expected every {formatCadence(w.cadence_seconds)})
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

function formatAge(seconds: number | null): string {
  if (seconds === null) return '?';
  if (seconds < 60)    return `${seconds}s`;
  if (seconds < 3600)  return `${Math.round(seconds / 60)}m`;
  if (seconds < 86400) return `${Math.round(seconds / 3600)}h`;
  return `${Math.round(seconds / 86400)}d`;
}
function formatCadence(seconds: number): string {
  if (seconds < 3600)  return `${Math.round(seconds / 60)}m`;
  if (seconds < 86400) return `${Math.round(seconds / 3600)}h`;
  return `${Math.round(seconds / 86400)}d`;
}

function QueueTile({ label, count, to, color }: { label: string; count: number; to: string; color: 'amber' | 'violet' | 'slate' }) {
  const colorBg = color === 'amber' ? 'bg-amber-50 border-amber-200' :
                  color === 'violet' ? 'bg-violet-50 border-violet-200' :
                  'bg-slate-50 border-slate-200';
  const colorText = color === 'amber' ? 'text-amber-700' :
                    color === 'violet' ? 'text-violet-700' : 'text-slate-700';
  return (
    <Link to={to} className={`block border rounded-lg p-4 hover:shadow ${colorBg}`}>
      <div className="flex items-center gap-2">
        <ListChecks size={16} className={colorText} />
        <span className={`text-sm font-semibold ${colorText}`}>{label}</span>
      </div>
      <div className="text-3xl font-extrabold text-slate-900 mt-2">{count}</div>
    </Link>
  );
}

function GrantBanner() {
  // The grant config is server-side PHP, not exposed via API yet — show
  // the operator that the storage permission gate is live, but don't
  // pretend to know the exact values. (A later iteration can wire
  // GET /api/admin/google-grant if the field-level scope needs surfacing.)
  return (
    <div className="bg-emerald-50 border border-emerald-200 rounded-lg p-4 mb-6 flex items-start gap-3">
      <ShieldCheck size={18} className="text-emerald-600 mt-0.5 flex-shrink-0" />
      <div className="flex-1">
        <div className="font-bold text-emerald-900 text-sm">Google Places storage grant active</div>
        <p className="text-xs text-emerald-800 mt-1">
          The Vendor Network operates under a written Google exception permitting full Places
          content storage. See <code className="px-1 py-0.5 bg-emerald-100 rounded">config/google_places_grant.php</code> for
          the grant reference, scope, and expiry. If revoked, the system falls back to Place-ID-only mode with no code change.
        </p>
      </div>
    </div>
  );
}

function PipelineCard({ workers }: { workers: CronWorker[] }) {
  const byName = Object.fromEntries(workers.map((w) => [w.name, w]));
  const order: { key: string; label: string }[] = [
    { key: 'seed-tile-worker',   label: 'seed-tile-worker — sweep queued tiles' },
    { key: 'seed-dedupe',        label: 'seed-dedupe — block + Jaro-Winkler + cluster' },
    { key: 'seed-classify',      label: 'seed-classify — type/category cascade' },
    { key: 'seed-coverage',      label: 'seed-coverage — ORS isochrone + radius' },
    { key: 'seed-resweep',       label: 'seed-resweep — stuck-tile + nightly delta' },
    { key: 'seed-enrich',        label: 'seed-enrich — nightly + tier refresh' },
    { key: 'measure-roi',        label: 'measure-roi — nightly ledger' },
    { key: 'send-weekly-digest', label: 'send-weekly-digest — Mon 13:00 UTC' },
  ];
  return (
    <div className="bg-white border border-slate-200 rounded-lg p-4">
      <h3 className="font-bold text-slate-900 text-sm mb-3 flex items-center gap-2">
        <Activity size={14} className="text-emerald-600" />
        Worker pipeline
      </h3>
      <ul className="text-xs space-y-1.5">
        {order.map(({ key, label }) => {
          const w = byName[key];
          return (
            <li key={key} className="flex items-center justify-between gap-2">
              <span className="text-slate-700 truncate"><code>{label}</code></span>
              <StatusDot worker={w} />
            </li>
          );
        })}
      </ul>
    </div>
  );
}

function StatusDot({ worker }: { worker: CronWorker | undefined }) {
  if (!worker) {
    return <span className="text-[10px] text-slate-400 font-semibold">no data</span>;
  }
  const dot =
    worker.status === 'green'  ? 'bg-emerald-500' :
    worker.status === 'yellow' ? 'bg-amber-400'   :
                                 'bg-red-500';
  const age = worker.last_beat_at ? formatAge(worker.last_beat_age_seconds) : 'never';
  return (
    <span className="flex items-center gap-1.5 flex-shrink-0">
      <span className={`inline-block w-2 h-2 rounded-full ${dot}`} />
      <span className="text-[11px] text-slate-600 tabular-nums">{age}</span>
    </span>
  );
}

function SafetyCard() {
  return (
    <div className="bg-white border border-slate-200 rounded-lg p-4">
      <h3 className="font-bold text-slate-900 text-sm mb-3 flex items-center gap-2">
        <AlertTriangle size={14} className="text-amber-500" />
        Safety guardrails
      </h3>
      <ul className="text-xs text-slate-600 space-y-1.5 list-disc pl-5">
        <li>Every campaign requires an estimate before running</li>
        <li>Budget cap halts BEFORE the next call — never overruns</li>
        <li>All Places calls write to <code>api_cost_events</code></li>
        <li>Cache check + DB lock coalesces duplicate enriches</li>
        <li>All writes are idempotent upserts on natural ids</li>
      </ul>
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
