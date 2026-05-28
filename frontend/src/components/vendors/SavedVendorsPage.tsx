import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Heart, Star, ShieldCheck, MapPin, ShoppingBag } from 'lucide-react';
import AppNav from '../layout/AppNav';
import { savedVendorsApi, type SavedVendor } from '../../api/vendorMap';
import { SkeletonList } from '../carafe';

/**
 * The operator's shortlist — vendors they've followed on the map.
 * Click-through goes to /app/vendors/map and opens the side panel
 * (handled by the map page via query string).
 */
export default function SavedVendorsPage() {
  const [items, setItems] = useState<SavedVendor[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const v = await savedVendorsApi.list();
        if (!cancelled) setItems(v);
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  async function unsave(id: string) {
    try {
      await savedVendorsApi.unsave(id);
      setItems((cur) => cur.filter((x) => x.vendor_id !== id));
    } catch (e: any) { toast.error(e?.response?.data?.error ?? 'Failed'); }
  }

  return (
    <div className="min-h-screen bg-slate-50">
      <AppNav />
      <main className="max-w-5xl mx-auto px-6 py-8">
        <div className="flex items-end justify-between mb-6">
          <div>
            <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: '#1A1A2E' }}>
              <Heart size={22} style={{ color: '#7848BB' }} /> Saved vendors
            </h1>
            <p className="text-slate-600 text-sm mt-1">Your shortlist. Pinned for quick access — request a quote or leave a review from the map.</p>
          </div>
          <Link to="/app/vendors/map" className="btn h-9 px-3 text-sm">Open vendor map →</Link>
        </div>

        {loading ? (
          <div aria-busy="true" aria-live="polite">
            <SkeletonList rows={4} rowHeight={88} />
          </div>
        ) : items.length === 0 ? (
          <SavedVendorsEmpty />
        ) : (
          <ul className="grid md:grid-cols-2 gap-3">
            {items.map((v, i) => (
              <li
                key={v.vendor_id}
                className="stagger-in bg-white border border-slate-200 rounded-xl p-3 flex items-start gap-3"
                style={{ ['--stagger-i' as any]: i }}
              >
                <span
                  className="inline-flex items-center justify-center w-10 h-10 rounded-lg font-bold text-white flex-shrink-0"
                  style={{ background: v.is_affiliated ? '#7848BB' : '#1A1A2E' }}
                >
                  {v.name.charAt(0).toUpperCase()}
                </span>
                <div className="flex-1 min-w-0">
                  <div className="font-bold text-sm flex items-center gap-1.5" style={{ color: '#1A1A2E' }}>
                    {v.name}
                    {!!v.is_affiliated && <ShieldCheck size={12} className="text-violet-700" />}
                  </div>
                  <div className="text-xs text-slate-500">
                    {v.type ?? v.primary_category}
                    {v.aggregate_rating !== null && (
                      <> · <Star size={10} className="inline text-amber-500" /> {v.aggregate_rating.toFixed(1)} ({v.rating_count})</>
                    )}
                  </div>
                  {v.note && <div className="text-[11px] text-slate-600 mt-1 italic">{v.note}</div>}
                </div>
                <button onClick={() => unsave(v.vendor_id)} className="p-1 text-slate-400 hover:text-rose-700 hover:bg-rose-50 rounded" title="Remove">
                  <Heart size={14} className="fill-current" />
                </button>
              </li>
            ))}
          </ul>
        )}
      </main>
    </div>
  );
}

function SavedVendorsEmpty() {
  const VALUE = [
    { Icon: MapPin,       title: 'Map view',     desc: 'Filter by category, drive-time radius, and what they actually supply.' },
    { Icon: ShoppingBag,  title: 'Shortlist',    desc: 'Save vendors you trust so they sit one click away every time you reorder.' },
    { Icon: ShieldCheck,  title: 'Affiliated',   desc: 'Carafe-verified vendors get a badge — same data, easier negotiation.' },
  ];
  return (
    <section
      className="rounded-xl border-2 border-dashed p-6 sm:p-10 flex flex-col items-center gap-5 text-center"
      style={{ background: 'white', borderColor: 'var(--brand-light)' }}
    >
      <span
        aria-hidden
        className="inline-flex items-center justify-center w-14 h-14 rounded-2xl"
        style={{ background: 'var(--brand-light)', color: 'var(--brand)' }}
      >
        <Heart size={26} strokeWidth={2.2} />
      </span>
      <div className="space-y-1.5 max-w-md">
        <h2 className="font-extrabold text-lg" style={{ color: 'var(--ink)' }}>
          Build your vendor shortlist
        </h2>
        <p className="text-sm" style={{ color: 'var(--body)' }}>
          Saving a vendor here means you've vetted them — pricing, reliability, delivery window.
          Carafe keeps the shortlist out of the noisy map view so you can move fast on reorders.
        </p>
      </div>
      <ul className="grid grid-cols-1 sm:grid-cols-3 gap-3 w-full max-w-2xl">
        {VALUE.map(({ Icon, title, desc }) => (
          <li
            key={title}
            className="rounded-lg border p-3 text-left flex flex-col gap-1.5"
            style={{ background: 'var(--bg-panel)', borderColor: 'var(--line-soft)' }}
          >
            <Icon size={16} style={{ color: 'var(--brand)' }} />
            <div className="font-bold text-sm" style={{ color: 'var(--ink)' }}>{title}</div>
            <div className="text-[11px]" style={{ color: 'var(--slate)' }}>{desc}</div>
          </li>
        ))}
      </ul>
      <Link
        to="/app/vendors/map"
        className="inline-flex items-center gap-1.5 px-4 min-h-[44px] rounded-lg font-bold text-sm text-white"
        style={{ background: 'var(--brand)' }}
      >
        Browse vendors →
      </Link>
    </section>
  );
}
