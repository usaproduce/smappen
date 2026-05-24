import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { competitorApi, type CompetitorMonitor } from '../../api/advanced';
import { Empty, Field } from './shared';

export default function CompetitorsTab({ projectId }: { projectId: string }) {
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
