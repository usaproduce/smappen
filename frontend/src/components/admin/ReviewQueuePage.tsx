import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Merge, X, Clock, Check, Edit3 } from 'lucide-react';
import { carafeApi, VENDOR_TYPES, type DedupeQueueItem, type ClassifyQueueItem } from '../../api/carafe';

/**
 * /admin/carafe/review — combined queue for ambiguous dedupe matches +
 * low-confidence classifications. Spec v3 §4.3 + §8.
 *
 * Tab toggle picks one kind; query string `?kind=dedupe|classify`
 * deep-links from the home page.
 */
export default function ReviewQueuePage() {
  const [sp, setSp] = useSearchParams();
  const kind = (sp.get('kind') as 'dedupe' | 'classify' | null) ?? 'dedupe';

  return (
    <div>
      <h1 className="text-2xl font-extrabold text-slate-900 mb-1">Review queue</h1>
      <p className="text-slate-600 mb-6 text-sm">
        Ambiguous dedupe matches and low-confidence classifications surface here.
        Resolving an item removes it from the queue immediately.
      </p>

      <div className="flex gap-1 border-b border-slate-200 mb-6">
        <TabButton active={kind === 'dedupe'}   onClick={() => setSp({ kind: 'dedupe' })}>Dedupe</TabButton>
        <TabButton active={kind === 'classify'} onClick={() => setSp({ kind: 'classify' })}>Classify</TabButton>
      </div>

      {kind === 'dedupe'   ? <DedupePanel />   : null}
      {kind === 'classify' ? <ClassifyPanel /> : null}
    </div>
  );
}

// ── Dedupe panel ─────────────────────────────────────────────────────

function DedupePanel() {
  const qc = useQueryClient();
  const { data, isLoading } = useQuery({
    queryKey: ['carafe', 'review-queue', 'dedupe'],
    queryFn: () => carafeApi.reviewQueue('dedupe', 50, 0),
    refetchInterval: 20_000,
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ['carafe', 'review-queue'] });

  const merge  = useMutation({ mutationFn: (id: string) => carafeApi.dedupeMerge(id),  onSuccess: () => { toast.success('Merged'); invalidate(); }, onError: showErr });
  const reject = useMutation({ mutationFn: (id: string) => carafeApi.dedupeReject(id), onSuccess: () => { toast.success('Rejected'); invalidate(); }, onError: showErr });
  const defer  = useMutation({ mutationFn: (id: string) => carafeApi.dedupeDefer(id),  onSuccess: () => { toast.success('Deferred'); invalidate(); }, onError: showErr });

  if (isLoading) return <div className="text-slate-500 text-sm">Loading…</div>;
  const items = data?.dedupe ?? [];
  if (items.length === 0) return <Empty message="No dedupe matches awaiting review." />;

  return (
    <ul className="space-y-3">
      {items.map((it) => (
        <DedupeRow
          key={it.id}
          item={it}
          onMerge={() => merge.mutate(it.id)}
          onReject={() => reject.mutate(it.id)}
          onDefer={() => defer.mutate(it.id)}
        />
      ))}
    </ul>
  );
}

function DedupeRow({ item, onMerge, onReject, onDefer }: { item: DedupeQueueItem; onMerge: () => void; onReject: () => void; onDefer: () => void }) {
  return (
    <li className="bg-white border border-slate-200 rounded-lg p-4">
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-3 text-xs">
          <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500">Score</span>
          <span className="text-base font-extrabold text-slate-900 tabular-nums">{(+item.score).toFixed(3)}</span>
          {item.distance_m != null && (
            <span className="text-slate-500">· {Math.round(+item.distance_m)}m apart</span>
          )}
          {item.shared_name_tokens != null && (
            <span className="text-slate-500">· {item.shared_name_tokens} shared token(s)</span>
          )}
          {item.block_key_hit && (
            <span className="text-[10px] text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded">{item.block_key_hit}</span>
          )}
        </div>
        <div className="flex items-center gap-2">
          <button className="btn h-8 px-3 text-xs bg-emerald-500 text-white hover:bg-emerald-600" onClick={onMerge}>
            <Merge size={12} /> Merge
          </button>
          <button className="btn h-8 px-3 text-xs bg-slate-100 text-slate-700 hover:bg-slate-200" onClick={onReject}>
            <X size={12} /> Reject
          </button>
          <button className="btn h-8 px-3 text-xs bg-white text-slate-600 border border-slate-200 hover:bg-slate-50" onClick={onDefer}>
            <Clock size={12} /> Later
          </button>
        </div>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <SideCard label="Left"  name={item.left_name}  category={item.left_category} />
        <SideCard label="Right" name={item.right_name} category={item.right_category} />
      </div>
    </li>
  );
}

function SideCard({ label, name, category }: { label: string; name: string; category: string | null }) {
  return (
    <div className="bg-slate-50 border border-slate-200 rounded p-3">
      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">{label}</div>
      <div className="font-semibold text-slate-900 mt-1">{name}</div>
      <div className="text-xs text-slate-500 mt-0.5">{category ?? 'uncategorized'}</div>
    </div>
  );
}

// ── Classify panel ───────────────────────────────────────────────────

function ClassifyPanel() {
  const qc = useQueryClient();
  const { data, isLoading } = useQuery({
    queryKey: ['carafe', 'review-queue', 'classify'],
    queryFn: () => carafeApi.reviewQueue('classify', 50, 0),
    refetchInterval: 20_000,
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ['carafe', 'review-queue'] });

  const approve = useMutation({
    mutationFn: (id: string) => carafeApi.classifyApprove(id),
    onSuccess: () => { toast.success('Approved'); invalidate(); },
    onError: showErr,
  });
  const update = useMutation({
    mutationFn: ({ id, type }: { id: string; type: string }) => carafeApi.classifyUpdate(id, type),
    onSuccess: () => { toast.success('Updated'); invalidate(); },
    onError: showErr,
  });

  if (isLoading) return <div className="text-slate-500 text-sm">Loading…</div>;
  const items = data?.classify ?? [];
  if (items.length === 0) return <Empty message="No classifications awaiting review." />;

  return (
    <ul className="space-y-3">
      {items.map((it) => (
        <ClassifyRow
          key={it.id}
          item={it}
          onApprove={() => approve.mutate(it.id)}
          onUpdate={(type) => update.mutate({ id: it.id, type })}
        />
      ))}
    </ul>
  );
}

function ClassifyRow({ item, onApprove, onUpdate }: { item: ClassifyQueueItem; onApprove: () => void; onUpdate: (type: string) => void }) {
  const [editing, setEditing] = useState(false);
  const [pickedType, setPickedType] = useState(item.type ?? '');

  let signals: string[] = [];
  try { signals = item.classification_signals_json ? JSON.parse(item.classification_signals_json) : []; } catch {}

  return (
    <li className="bg-white border border-slate-200 rounded-lg p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="flex-1 min-w-0">
          <div className="font-semibold text-slate-900">{item.name}</div>
          <div className="text-xs text-slate-500 mt-0.5 flex items-center gap-2">
            current type: <code className="font-mono">{item.type ?? '—'}</code>
            <span>· confidence <b>{item.classification_confidence ?? '—'}</b></span>
          </div>
          {signals.length > 0 && (
            <div className="text-[11px] text-slate-500 mt-1">signals: {signals.join(', ')}</div>
          )}
        </div>
        <div className="flex items-center gap-2">
          <button className="btn h-8 px-3 text-xs bg-emerald-500 text-white hover:bg-emerald-600" onClick={onApprove}>
            <Check size={12} /> Approve
          </button>
          <button className="btn h-8 px-3 text-xs bg-slate-100 text-slate-700 hover:bg-slate-200" onClick={() => setEditing((v) => !v)}>
            <Edit3 size={12} /> Override
          </button>
        </div>
      </div>
      {editing && (
        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center gap-2">
          <select
            value={pickedType}
            onChange={(e) => setPickedType(e.target.value)}
            className="input h-9 text-sm"
          >
            <option value="">— pick a type —</option>
            {VENDOR_TYPES.map((vt) => <option key={vt.key} value={vt.key}>{vt.label}</option>)}
          </select>
          <button
            className="btn h-9 px-3 text-sm bg-violet-600 text-white hover:bg-violet-700 disabled:opacity-40"
            disabled={!pickedType}
            onClick={() => { onUpdate(pickedType); setEditing(false); }}
          >
            Save
          </button>
          <button className="btn h-9 px-3 text-sm bg-white text-slate-600 border border-slate-200" onClick={() => setEditing(false)}>
            Cancel
          </button>
        </div>
      )}
    </li>
  );
}

// ── Shared ─────────────────────────────────────────────────────────────

function TabButton({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      onClick={onClick}
      className={`px-4 py-2 text-sm font-semibold border-b-2 -mb-px ${
        active ? 'border-violet-500 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700'
      }`}
    >
      {children}
    </button>
  );
}

function Empty({ message }: { message: string }) {
  return (
    <div className="bg-white border border-slate-200 rounded-lg p-8 text-center text-slate-500 text-sm">
      {message}
    </div>
  );
}

function showErr(e: any) {
  toast.error(e?.response?.data?.error ?? 'Action failed');
}
