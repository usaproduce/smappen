import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Search, Building2, ShieldCheck, Sparkles } from 'lucide-react';
import { vendorsApi, type Vendor, type ComparisonResult } from '../../api/vendors';
import { useAuthStore } from '../../stores/authStore';
import { leadsApi } from '../../api/vendors';

const CATEGORIES = ['produce', 'protein', 'dairy', 'broadline', 'specialty'];

export default function VendorsPage() {
  const [vendors, setVendors] = useState<Vendor[]>([]);
  const [loading, setLoading] = useState(true);
  const [category, setCategory] = useState<string>('');
  const [region, setRegion] = useState<string>('');
  const [q, setQ] = useState<string>('');
  const [comparison, setComparison] = useState<ComparisonResult | null>(null);
  const [comparing, setComparing] = useState(false);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const v = await vendorsApi.list({
          category: category || undefined,
          region: region || undefined,
          q: q || undefined,
        });
        if (!cancelled) setVendors(v);
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed to load vendors');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [category, region, q]);

  async function runComparison() {
    if (!category) {
      toast.error('Pick a category to compare');
      return;
    }
    setComparing(true);
    try {
      const r = await vendorsApi.compare({ category, region: region || undefined });
      setComparison(r);
      // Opt-in audit trail — fire-and-forget so the signal is captured even
      // if the user never requests a quote (spec §1.5 / §7).
      vendorsApi.logComparison({
        category,
        region: region || undefined,
        vendor_ids: r.ranked.map((x) => x.vendor_id),
      }).catch(() => {});
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Comparison failed');
    } finally {
      setComparing(false);
    }
  }

  return (
    <div className="min-h-screen bg-white">
      <header className="bg-white border-b border-slate-200">
        <div className="max-w-6xl mx-auto px-6 py-3 flex items-center justify-between">
          <Link to="/dashboard" className="flex items-center gap-2 font-extrabold text-[16px]" style={{ color: '#1A1A2E' }}>
            <span
              className="inline-flex items-center justify-center w-8 h-8 rounded-lg text-white font-extrabold text-base shadow-sm"
              style={{ background: 'linear-gradient(135deg, #F57C00 0%, #E53935 50%, #7848BB 100%)' }}
            >S</span>
            smappen
          </Link>
          <nav className="flex items-center gap-4 text-sm font-semibold text-slate-700">
            <Link to="/app/restaurants" className="hover:text-violet-700">Restaurants</Link>
            <Link to="/app/vendors" className="text-violet-700">Vendors</Link>
            <Link to="/settings/profile" className="hover:text-violet-700">Settings</Link>
          </nav>
        </div>
      </header>

      <main className="max-w-6xl mx-auto px-6 py-8">
        <div className="flex items-end justify-between mb-6">
          <div>
            <h1 className="text-3xl font-extrabold flex items-center gap-2" style={{ color: '#1A1A2E' }}>
              <Building2 size={26} style={{ color: '#7848BB' }} />
              Suppliers
            </h1>
            <p className="text-slate-600 mt-1">
              Honest comparison. Affiliation disclosed. Ranking is never for sale.
            </p>
          </div>
        </div>

        {/* Filter strip */}
        <div className="bg-slate-50 rounded-xl p-3 mb-6 grid md:grid-cols-4 gap-2">
          <div className="md:col-span-2 relative">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              className="input h-10 text-sm w-full pl-9"
              placeholder="Search by name…"
              value={q}
              onChange={(e) => setQ(e.target.value)}
            />
          </div>
          <select className="input h-10 text-sm" value={category} onChange={(e) => setCategory(e.target.value)}>
            <option value="">Any category</option>
            {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
          <input
            className="input h-10 text-sm"
            placeholder="Region (e.g. US-NE)"
            value={region}
            onChange={(e) => setRegion(e.target.value)}
          />
        </div>

        <div className="flex items-center gap-2 mb-4">
          <button className="btn btn-primary h-10 px-4 text-sm" onClick={runComparison} disabled={comparing || !category}>
            <Sparkles size={14} /> Compare suppliers
          </button>
          <span className="text-xs text-slate-500">
            {category ? `Pick a category. We'll rank vendors objectively.` : 'Pick a category first.'}
          </span>
        </div>

        {comparison && (
          <ComparisonResultPanel result={comparison} onClose={() => setComparison(null)} />
        )}

        <h2 className="font-extrabold text-base mb-3 mt-8" style={{ color: '#1A1A2E' }}>Directory</h2>
        {loading ? (
          <div className="grid md:grid-cols-2 gap-3">
            <div className="skeleton h-20" /><div className="skeleton h-20" /><div className="skeleton h-20" /><div className="skeleton h-20" />
          </div>
        ) : vendors.length === 0 ? (
          <div className="bg-slate-50 rounded-xl p-10 text-center text-slate-500 text-sm">No vendors match your filters.</div>
        ) : (
          <ul className="grid md:grid-cols-2 gap-3">
            {vendors.map((v) => (
              <li key={v.id} className="bg-white border border-slate-200 rounded-xl p-3 flex items-start gap-3">
                <span
                  className="inline-flex items-center justify-center w-9 h-9 rounded-lg text-white font-bold flex-shrink-0"
                  style={{ background: v.is_affiliated ? '#7848BB' : '#1A1A2E' }}
                >
                  {v.name.charAt(0).toUpperCase()}
                </span>
                <div className="flex-1 min-w-0">
                  <div className="font-bold truncate" style={{ color: '#1A1A2E' }}>{v.name}</div>
                  <div className="text-xs text-slate-500 truncate">
                    {[v.hq_address, v.primary_category].filter(Boolean).join(' · ') || '—'}
                  </div>
                  {!!v.is_affiliated && (
                    <span className="inline-flex items-center gap-1 mt-1 text-[10px] font-bold uppercase tracking-wider text-violet-700 bg-violet-50 rounded px-1.5 py-0.5">
                      <ShieldCheck size={10} /> Affiliated supplier
                    </span>
                  )}
                  {v.claim_status === 'claimed' && (
                    <span className="ml-1 inline-block text-[10px] font-bold uppercase tracking-wider text-emerald-700 bg-emerald-50 rounded px-1.5 py-0.5">
                      Claimed
                    </span>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </main>
    </div>
  );
}

function ComparisonResultPanel({ result, onClose }: { result: ComparisonResult; onClose: () => void }) {
  const user = useAuthStore((s) => s.user) as any;
  const defaultEmail = (user?.email as string) ?? '';
  const [busyId, setBusyId] = useState<string | null>(null);

  async function requestQuote(vendorId: string) {
    const email = window.prompt('Send the quote request from which email?', defaultEmail);
    if (!email) return;
    setBusyId(vendorId);
    try {
      const r = await leadsApi.create({
        vendor_id: vendorId,
        contact_email: email,
      });
      toast.success(`Quote request sent — lead ${r.lead_id.slice(0, 8)}`);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to send quote');
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="bg-white border border-violet-200 rounded-xl p-4 mb-6">
      <div className="flex items-center justify-between mb-3">
        <div>
          <div className="text-[10px] font-bold uppercase tracking-wider text-violet-700">Comparison</div>
          <div className="text-base font-extrabold" style={{ color: '#1A1A2E' }}>{result.category}{result.region ? ` · ${result.region}` : ''}</div>
        </div>
        <button className="text-slate-400 hover:text-slate-700 text-sm" onClick={onClose}>Close</button>
      </div>

      <div className="text-[11px] text-slate-500 mb-3 italic">{result.methodology.note}</div>

      <ol className="space-y-2">
        {result.ranked.map((row, i) => (
          <li key={row.vendor_id} className="bg-slate-50 rounded-lg p-3 flex items-center gap-3">
            <span className="inline-flex items-center justify-center w-8 h-8 rounded-full bg-white border border-slate-200 font-bold text-sm" style={{ color: '#1A1A2E' }}>
              {i + 1}
            </span>
            <div className="flex-1 min-w-0">
              <div className="font-bold text-sm truncate" style={{ color: '#1A1A2E' }}>{row.vendor_name}</div>
              <div className="text-xs text-slate-500">
                {row.covers_category ? 'covers category' : 'no category match'}
                {' · '}
                {row.covers_region ? 'covers region' : 'national fallback'}
                {row.min_order_cents !== null && row.min_order_cents > 0 && ` · min order $${(row.min_order_cents / 100).toFixed(0)}`}
              </div>
              {row.disclosure && (
                <div className="mt-1 text-[10px] font-bold uppercase tracking-wider text-violet-700 bg-violet-50 inline-block rounded px-1.5 py-0.5">
                  {row.disclosure}
                </div>
              )}
            </div>
            <button
              className="btn btn-primary h-8 px-3 text-xs"
              disabled={busyId === row.vendor_id}
              onClick={() => requestQuote(row.vendor_id)}
            >
              Request quote
            </button>
          </li>
        ))}
      </ol>
    </div>
  );
}
