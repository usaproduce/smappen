import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Heart, Star, ShieldCheck } from 'lucide-react';
import AppNav from '../layout/AppNav';
import { savedVendorsApi, type SavedVendor } from '../../api/vendorMap';

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
          <div className="grid md:grid-cols-2 gap-3">
            <div className="skeleton h-24" /><div className="skeleton h-24" />
          </div>
        ) : items.length === 0 ? (
          <div className="bg-white border border-slate-200 rounded-xl p-10 text-center">
            <Heart size={28} className="mx-auto text-slate-300 mb-2" />
            <div className="font-semibold text-slate-700">No saved vendors yet</div>
            <div className="text-sm text-slate-500 mt-1">Open the vendor map, drop a pin, and save the ones you want close at hand.</div>
            <Link to="/app/vendors/map" className="btn btn-primary mt-4 h-9 px-3 text-sm inline-flex">Browse vendors →</Link>
          </div>
        ) : (
          <ul className="grid md:grid-cols-2 gap-3">
            {items.map((v) => (
              <li key={v.vendor_id} className="bg-white border border-slate-200 rounded-xl p-3 flex items-start gap-3">
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
