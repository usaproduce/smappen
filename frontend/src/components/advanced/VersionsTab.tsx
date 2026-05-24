import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { History } from 'lucide-react';
import { collabApi, type Version, type ChangeRow } from '../../api/advanced';
import { Spinner, Empty } from './shared';

export default function VersionsTab({ projectId }: { projectId: string }) {
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
