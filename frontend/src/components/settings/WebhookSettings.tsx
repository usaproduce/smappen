import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { Trash2, Send, Plus, History, ChevronDown, ChevronUp } from 'lucide-react';
import { api } from '../../api/client';

interface Webhook {
  id: string;
  target_url: string;
  events: string[];
  is_active: 0 | 1;
  last_delivery_at: string | null;
  last_status_code: number | null;
  failure_count: number;
  created_at: string;
}

export default function WebhookSettings() {
  const [hooks, setHooks] = useState<Webhook[]>([]);
  const [events, setEvents] = useState<string[]>([]);
  const [newUrl, setNewUrl] = useState('');
  const [newEvents, setNewEvents] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [freshSecret, setFreshSecret] = useState<string | null>(null);

  async function load() {
    try {
      const { data } = await api.get('/api/webhooks');
      // Backend stores `events` as JSON in MySQL — usually returned as an
      // array, but on older PHP/MySQL combos PDO can hand it back as a
      // JSON-encoded string. Normalize either shape so `.join(', ')` below
      // doesn't blow up.
      const hooks: Webhook[] = (data.data.webhooks ?? []).map((h: any) => ({
        ...h,
        events: Array.isArray(h.events)
          ? h.events
          : (typeof h.events === 'string' ? (JSON.parse(h.events || '[]') as string[]) : []),
      }));
      setHooks(hooks);
      setEvents(data.data.available_events);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    }
  }
  useEffect(() => { load(); }, []);

  async function create() {
    if (!newUrl.trim() || newEvents.length === 0) {
      toast.error('URL and at least one event required');
      return;
    }
    setBusy(true);
    try {
      const { data } = await api.post('/api/webhooks', { target_url: newUrl.trim(), events: newEvents });
      setFreshSecret(data.data.secret);
      setNewUrl(''); setNewEvents([]);
      await load();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setBusy(false);
    }
  }

  async function remove(id: string) {
    if (!confirm('Delete this webhook?')) return;
    try { await api.delete(`/api/webhooks/${id}`); await load(); } catch {}
  }
  async function test(id: string) {
    try {
      const { data } = await api.post(`/api/webhooks/${id}/test`);
      toast.success(`Sent — status ${data.data.status_code}`);
      await load();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    }
  }

  return (
    <div className="space-y-6 max-w-3xl">
      <section className="card space-y-3">
        <h2 className="font-bold text-base" style={{ color: '#1A1A2E' }}>Add a webhook</h2>
        <p className="text-xs text-slate-500">
          Smappen will POST signed JSON payloads to your URL when events fire. Verify with HMAC-SHA256
          using the secret as the key.
        </p>
        <input className="input" placeholder="https://your.app/webhooks/smappen"
          value={newUrl} onChange={(e) => setNewUrl(e.target.value)} />
        <div>
          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Events</div>
          <div className="grid grid-cols-2 gap-1">
            {events.map((ev) => (
              <label key={ev} className="text-xs flex items-center gap-1.5">
                <input type="checkbox" className="accent-violet-600"
                  checked={newEvents.includes(ev)}
                  onChange={(e) => setNewEvents(e.target.checked ? [...newEvents, ev] : newEvents.filter((x) => x !== ev))} />
                {ev}
              </label>
            ))}
          </div>
        </div>
        <button className="btn btn-primary" onClick={create} disabled={busy}>
          <Plus size={14} /> {busy ? 'Adding…' : 'Add webhook'}
        </button>
        {freshSecret && (
          <div className="mt-2 text-sm bg-emerald-50 border border-emerald-200 rounded p-3">
            <div className="font-semibold text-emerald-800 mb-1">Webhook secret (shown only once):</div>
            <code className="block bg-white border border-emerald-200 rounded px-2 py-1.5 text-xs font-mono break-all">{freshSecret}</code>
          </div>
        )}
      </section>

      <section className="card">
        <h2 className="font-bold text-base mb-3" style={{ color: '#1A1A2E' }}>Active webhooks</h2>
        {hooks.length === 0 && <div className="text-sm text-slate-500">No webhooks yet.</div>}
        <ul className="divide-y divide-slate-100">
          {hooks.map((h) => (
            <WebhookRow key={h.id} h={h} onTest={() => test(h.id)} onRemove={() => remove(h.id)} />
          ))}
        </ul>
      </section>
    </div>
  );
}

/**
 * One row in the webhook list. Expandable to show the last 50 deliveries
 * (status code, event type, response excerpt, retry count, timestamp) —
 * essential for debugging "my receiver isn't getting events".
 */
function WebhookRow({ h, onTest, onRemove }: { h: Webhook; onTest: () => void; onRemove: () => void }) {
  const [open, setOpen] = useState(false);
  const [deliveries, setDeliveries] = useState<any[] | null>(null);
  const [loading, setLoading] = useState(false);

  async function toggle() {
    const next = !open;
    setOpen(next);
    if (next && deliveries === null) {
      setLoading(true);
      try {
        const { data } = await api.get(`/api/webhooks/${h.id}/deliveries`);
        setDeliveries(data.data.deliveries);
      } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Could not load log');
      } finally { setLoading(false); }
    }
  }

  return (
    <li className="py-2.5">
      <div className="flex items-center gap-3">
        <div className="flex-1 min-w-0">
          <div className="text-sm font-semibold truncate" style={{ color: '#1A1A2E' }}>{h.target_url}</div>
          <div className="text-xs text-slate-500">{(h.events || []).join(', ')}</div>
          <div className="text-[11px] text-slate-400">
            {h.last_delivery_at
              ? `Last delivered ${new Date(h.last_delivery_at).toLocaleString()} · status ${h.last_status_code ?? '—'}`
              : 'No deliveries yet'}
            {h.failure_count > 0 && <span className="text-rose-600 ml-2">{h.failure_count} failures</span>}
          </div>
        </div>
        <button className="text-slate-500 hover:text-violet-700 p-1" onClick={toggle} title={open ? 'Hide log' : 'Show delivery log'}>
          {open ? <ChevronUp size={14} /> : <History size={14} />}
        </button>
        <button className="text-slate-500 hover:text-violet-700 p-1" onClick={onTest} title="Send a test event"><Send size={14} /></button>
        <button className="text-slate-400 hover:text-rose-600 p-1" onClick={onRemove} title="Delete"><Trash2 size={14} /></button>
      </div>
      {open && (
        <div className="mt-2 ml-1 border-l-2 border-violet-200 pl-3">
          {loading && <div className="text-xs text-slate-400 py-2">Loading log…</div>}
          {!loading && deliveries && deliveries.length === 0 && (
            <div className="text-xs text-slate-400 italic py-2">No deliveries yet.</div>
          )}
          {!loading && deliveries && deliveries.length > 0 && (
            <table className="w-full text-[11px]">
              <thead>
                <tr className="text-left text-slate-400">
                  <th className="py-1 pr-2">When</th>
                  <th className="py-1 pr-2">Event</th>
                  <th className="py-1 pr-2">Status</th>
                  <th className="py-1">Response excerpt</th>
                </tr>
              </thead>
              <tbody>
                {deliveries.map((d) => {
                  const ok = d.status_code && d.status_code >= 200 && d.status_code < 300;
                  return (
                    <tr key={d.id} className="border-t border-slate-100">
                      <td className="py-1 pr-2 text-slate-500 whitespace-nowrap">
                        {new Date(d.created_at).toLocaleString()}
                      </td>
                      <td className="py-1 pr-2 text-slate-700 font-medium">{d.event_type}</td>
                      <td className={`py-1 pr-2 font-bold tabular-nums ${ok ? 'text-emerald-700' : 'text-rose-700'}`}>
                        {d.status_code ?? '—'}
                      </td>
                      <td className="py-1 text-slate-600 font-mono truncate max-w-[300px]" title={d.response_excerpt}>
                        {d.response_excerpt || ''}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </div>
      )}
    </li>
  );
}
