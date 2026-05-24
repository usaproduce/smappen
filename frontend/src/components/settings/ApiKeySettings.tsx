import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { Copy, RotateCw, Eye, EyeOff } from 'lucide-react';
import { api } from '../../api/client';

export default function ApiKeySettings() {
  const [hasKey, setHasKey] = useState(false);
  const [last4, setLast4] = useState<string | null>(null);
  const [freshKey, setFreshKey] = useState<string | null>(null);
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);

  async function load() {
    try {
      const { data } = await api.get('/api/auth/api-key');
      setHasKey(!!data.data.has_key);
      setLast4(data.data.last4 ?? null);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to load API key info');
    }
  }
  useEffect(() => { load(); }, []);

  async function regenerate() {
    if (hasKey && !confirm('Regenerate the API key? Any existing integrations using the current key will stop working.')) return;
    setBusy(true);
    try {
      const { data } = await api.post('/api/auth/api-key/regenerate');
      setFreshKey(data.data.api_key);
      setLast4(data.data.last4);
      setHasKey(true);
      setShow(true);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setBusy(false);
    }
  }

  async function copy() {
    if (!freshKey) return;
    await navigator.clipboard.writeText(freshKey);
    toast.success('Copied');
  }

  const masked = freshKey ? (show ? freshKey : freshKey.slice(0, 6) + '…' + freshKey.slice(-4)) : null;

  return (
    <div className="space-y-6 max-w-3xl">
      <section className="card space-y-3">
        <h2 className="font-bold text-base" style={{ color: '#1A1A2E' }}>API key</h2>
        <p className="text-xs text-slate-500">
          Use the API key in the <code className="bg-slate-100 px-1 rounded text-[11px]">X-Api-Key</code> header
          to call Smappen APIs from scripts and integrations. Treat it like a password.
        </p>

        {!hasKey && !freshKey && (
          <div className="text-sm text-slate-600 bg-amber-50 border border-amber-200 rounded p-3">
            You haven't generated an API key yet. Click below to issue one.
          </div>
        )}
        {hasKey && !freshKey && (
          <div className="text-sm text-slate-700 bg-slate-50 border border-slate-200 rounded p-3">
            Your current key ends in <code className="bg-white px-2 py-0.5 rounded border border-slate-200">…{last4}</code>.
            For security, the full key is shown only at creation.
          </div>
        )}
        {freshKey && (
          <div className="text-sm bg-emerald-50 border border-emerald-200 rounded p-3 space-y-2">
            <div className="font-semibold text-emerald-800">Save this key now — it won't be shown again.</div>
            <div className="flex items-center gap-2">
              <code className="flex-1 bg-white border border-emerald-200 rounded px-2 py-1.5 text-xs font-mono break-all">{masked}</code>
              <button className="btn btn-secondary h-9" onClick={() => setShow(!show)} title={show ? 'Hide' : 'Show'}>
                {show ? <EyeOff size={14} /> : <Eye size={14} />}
              </button>
              <button className="btn btn-primary h-9" onClick={copy}><Copy size={14} /> Copy</button>
            </div>
          </div>
        )}
        <button className="btn btn-primary" onClick={regenerate} disabled={busy}>
          <RotateCw size={14} /> {hasKey ? 'Regenerate API key' : 'Generate API key'}
        </button>
      </section>

      <section className="card space-y-3">
        <h2 className="font-bold text-base" style={{ color: '#1A1A2E' }}>Usage</h2>
        <p className="text-xs text-slate-500">Try the API with these snippets. Replace <code>$KEY</code> with your key.</p>
        <CodeBlock title="curl" code={`curl -H 'X-Api-Key: $KEY' \\\n  https://smappen.mygreendock.com/api/health`} />
        <CodeBlock title="JavaScript (fetch)" code={`const r = await fetch('https://smappen.mygreendock.com/api/projects', {\n  headers: { 'X-Api-Key': process.env.SMAPPEN_KEY }\n});\nconst { data } = await r.json();`} />
        <CodeBlock title="Python (requests)" code={`import requests\nr = requests.get(\n  'https://smappen.mygreendock.com/api/projects',\n  headers={'X-Api-Key': os.environ['SMAPPEN_KEY']},\n)\nprint(r.json())`} />
        <p className="text-xs">
          Full API docs: <a href="/api/docs" target="_blank" className="text-violet-700 font-semibold hover:underline">/api/docs</a>
        </p>
      </section>
    </div>
  );
}

function CodeBlock({ title, code }: { title: string; code: string }) {
  return (
    <div>
      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">{title}</div>
      <pre className="bg-slate-900 text-slate-100 rounded p-3 text-[11px] font-mono whitespace-pre overflow-x-auto">{code}</pre>
    </div>
  );
}
