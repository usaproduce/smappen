import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { X, ShieldCheck, Star, Heart, ExternalLink, Send } from 'lucide-react';
import {
  vendorMapApi, vendorReviewsApi, savedVendorsApi,
  type VendorDetail, type VendorReview, type VendorReviewAggregate,
} from '../../api/vendorMap';
import { leadsApi } from '../../api/vendors';
import { useAuthStore } from '../../stores/authStore';

/**
 * Right-side panel that opens when a vendor is selected — either by
 * clicking a pin or a "who serves me" result row. Shows:
 *
 *   - identity + affiliation disclosure
 *   - locations + categories
 *   - aggregate review score + recent reviews
 *   - actions: save/follow, request a quote, leave a review
 *
 * Coverage polygon rendering on the map itself happens in VendorMapPage —
 * this panel only triggers the selection.
 */
export default function VendorSidePanel({ vendorId, onClose }: { vendorId: string; onClose: () => void }) {
  const [detail, setDetail] = useState<VendorDetail | null>(null);
  const [agg, setAgg] = useState<VendorReviewAggregate | null>(null);
  const [reviews, setReviews] = useState<VendorReview[]>([]);
  const [myReview, setMyReview] = useState<VendorReview | null>(null);
  const [saved, setSaved] = useState(false);
  const [loading, setLoading] = useState(true);
  const [showReviewForm, setShowReviewForm] = useState(false);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    (async () => {
      try {
        const [d, a, r, savedList] = await Promise.all([
          vendorMapApi.detail(vendorId),
          vendorReviewsApi.aggregate(vendorId).catch(() => null),
          vendorReviewsApi.list(vendorId).catch(() => ({ reviews: [], my_review: null })),
          savedVendorsApi.list().catch(() => []),
        ]);
        if (cancelled) return;
        setDetail(d);
        setAgg(a);
        setReviews(r.reviews);
        setMyReview(r.my_review);
        setSaved(savedList.some((s) => s.vendor_id === vendorId));
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed to load vendor');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [vendorId]);

  async function toggleSave() {
    try {
      if (saved) { await savedVendorsApi.unsave(vendorId); setSaved(false); toast.success('Removed'); }
      else { await savedVendorsApi.save(vendorId); setSaved(true); toast.success('Saved'); }
    } catch (e: any) { toast.error(e?.response?.data?.error ?? 'Failed'); }
  }

  return (
    <aside className="absolute top-4 left-4 w-[420px] max-h-[calc(100%-2rem)] overflow-hidden bg-white border border-slate-200 rounded-xl shadow-lg flex flex-col z-30">
      <header className="flex items-start justify-between px-4 py-3 border-b border-slate-200">
        <div className="min-w-0">
          {loading ? (
            <div className="skeleton h-6 w-48" />
          ) : (
            <div className="font-extrabold text-base truncate flex items-center gap-2" style={{ color: '#1A1A2E' }}>
              {detail?.vendor.name}
              {!!detail?.vendor.is_affiliated && <ShieldCheck size={14} className="text-violet-700 flex-shrink-0" />}
            </div>
          )}
          {detail?.vendor.is_affiliated ? (
            <div className="text-[10px] font-bold uppercase tracking-wider text-violet-700">USA Produce — affiliated supplier</div>
          ) : detail?.vendor.type ? (
            <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">{detail.vendor.type.replace('_', ' ')}</div>
          ) : null}
        </div>
        <button className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-slate-50" onClick={onClose}>
          <X size={14} />
        </button>
      </header>

      <div className="overflow-y-auto px-4 py-3 space-y-4">
        {loading || !detail ? (
          <>
            <div className="skeleton h-16" />
            <div className="skeleton h-32" />
            <div className="skeleton h-20" />
          </>
        ) : (
          <>
            {/* Aggregate + actions */}
            <section className="bg-slate-50 rounded-lg p-3 space-y-2">
              {agg && agg.count > 0 ? (
                <div className="flex items-center gap-3">
                  <div>
                    <div className="text-2xl font-extrabold tabular-nums flex items-baseline gap-1" style={{ color: '#1A1A2E' }}>
                      {agg.overall?.toFixed(1) ?? '—'}
                      <Star size={16} className="text-amber-500" />
                    </div>
                    <div className="text-[10px] text-slate-500">{agg.count} review{agg.count === 1 ? '' : 's'}</div>
                  </div>
                  <div className="flex-1 text-[11px] grid grid-cols-2 gap-x-3 gap-y-0.5 text-slate-600">
                    {agg.price       !== null && <div>Price <b className="text-slate-800">{agg.price.toFixed(1)}</b></div>}
                    {agg.reliability !== null && <div>Reliability <b className="text-slate-800">{agg.reliability.toFixed(1)}</b></div>}
                    {agg.quality     !== null && <div>Quality <b className="text-slate-800">{agg.quality.toFixed(1)}</b></div>}
                    {agg.accuracy    !== null && <div>Accuracy <b className="text-slate-800">{agg.accuracy.toFixed(1)}</b></div>}
                    {agg.service     !== null && <div>Service <b className="text-slate-800">{agg.service.toFixed(1)}</b></div>}
                  </div>
                </div>
              ) : (
                <div className="text-xs text-slate-500">No reviews yet — be the first.</div>
              )}
              <div className="flex flex-wrap gap-2 pt-1">
                <button onClick={toggleSave} className={`btn h-8 px-3 text-xs ${saved ? 'btn-primary' : ''}`}>
                  <Heart size={12} className={saved ? 'fill-current' : ''} /> {saved ? 'Saved' : 'Save'}
                </button>
                <button
                  onClick={() => setShowReviewForm((v) => !v)}
                  className="btn h-8 px-3 text-xs"
                >
                  {myReview ? 'Edit your review' : 'Write a review'}
                </button>
                <RequestQuoteButton vendorId={vendorId} />
              </div>
            </section>

            {showReviewForm && (
              <ReviewForm
                vendorId={vendorId}
                existing={myReview}
                onSubmitted={async () => {
                  setShowReviewForm(false);
                  const [a, r] = await Promise.all([
                    vendorReviewsApi.aggregate(vendorId),
                    vendorReviewsApi.list(vendorId),
                  ]);
                  setAgg(a);
                  setReviews(r.reviews);
                  setMyReview(r.my_review);
                  toast.success('Review saved');
                }}
              />
            )}

            {/* Locations */}
            <section>
              <h3 className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Locations</h3>
              <ul className="space-y-1">
                {detail.locations.map((l) => (
                  <li key={l.id} className="text-xs">
                    <span className="font-semibold" style={{ color: '#1A1A2E' }}>{l.label ?? 'Branch'}</span>
                    {l.address && <span className="text-slate-500"> · {l.address}</span>}
                    {!!l.is_primary && <span className="ml-1 text-[9px] font-bold uppercase text-violet-700">primary</span>}
                  </li>
                ))}
              </ul>
            </section>

            {/* Categories */}
            {detail.categories.length > 0 && (
              <section>
                <h3 className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Categories</h3>
                <div className="flex flex-wrap gap-1">
                  {detail.categories.map((c) => (
                    <span key={c.category} className="text-[10px] font-semibold bg-slate-100 text-slate-700 rounded px-1.5 py-0.5">
                      {c.category.replace('_', ' ')}
                    </span>
                  ))}
                </div>
              </section>
            )}

            {/* Recent reviews */}
            {reviews.length > 0 && (
              <section>
                <h3 className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Recent reviews</h3>
                <ul className="space-y-2">
                  {reviews.slice(0, 5).map((r) => (
                    <li key={r.id} className="bg-slate-50 rounded p-2 text-xs">
                      <div className="flex items-center gap-2 mb-1">
                        <div className="text-amber-600 font-bold">{'★'.repeat(r.overall)}<span className="text-slate-300">{'★'.repeat(5 - r.overall)}</span></div>
                        <span className="text-[10px] text-slate-500">{new Date(r.created_at).toLocaleDateString()}</span>
                        {r.verification_strength === 'pos_connected' && (
                          <span className="text-[9px] font-bold uppercase text-emerald-700 bg-emerald-50 rounded px-1 py-0.5">POS-verified</span>
                        )}
                      </div>
                      {r.body && <div className="text-slate-700 leading-snug">{r.body}</div>}
                    </li>
                  ))}
                </ul>
              </section>
            )}

            {/* Provenance footer */}
            {detail.vendor.last_verified_at && (
              <div className="text-[10px] text-slate-400 italic">Last verified {new Date(detail.vendor.last_verified_at).toLocaleDateString()}</div>
            )}
          </>
        )}
      </div>
    </aside>
  );
}

function RequestQuoteButton({ vendorId }: { vendorId: string }) {
  const user = useAuthStore((s) => s.user) as any;
  const [busy, setBusy] = useState(false);
  async function go() {
    const email = window.prompt('Send the quote request from which email?', user?.email ?? '');
    if (!email) return;
    setBusy(true);
    try {
      const r = await leadsApi.create({ vendor_id: vendorId, contact_email: email });
      toast.success(`Quote request sent — lead ${r.lead_id.slice(0, 8)}`);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setBusy(false);
    }
  }
  return (
    <button onClick={go} disabled={busy} className="btn btn-primary h-8 px-3 text-xs">
      <Send size={12} /> Request quote
    </button>
  );
}

function ReviewForm({
  vendorId, existing, onSubmitted,
}: { vendorId: string; existing: VendorReview | null; onSubmitted: () => void }) {
  const [overall,     setOverall]     = useState(existing?.overall ?? 4);
  const [price,       setPrice]       = useState<number | ''>(existing?.score_price ?? '');
  const [reliability, setReliability] = useState<number | ''>(existing?.score_reliability ?? '');
  const [quality,     setQuality]     = useState<number | ''>(existing?.score_quality ?? '');
  const [accuracy,    setAccuracy]    = useState<number | ''>(existing?.score_accuracy ?? '');
  const [service,     setService]     = useState<number | ''>(existing?.score_service ?? '');
  const [body,        setBody]        = useState(existing?.body ?? '');
  const [volume,      setVolume]      = useState<string>(existing?.volume_band ?? '');
  const [delivery,    setDelivery]    = useState<string>(existing?.delivery_or_pickup ?? '');
  const [busy,        setBusy]        = useState(false);

  async function submit() {
    setBusy(true);
    try {
      await vendorReviewsApi.submit(vendorId, {
        overall,
        score_price:       (price       === '' ? undefined : Number(price))       as any,
        score_reliability: (reliability === '' ? undefined : Number(reliability)) as any,
        score_quality:     (quality     === '' ? undefined : Number(quality))     as any,
        score_accuracy:    (accuracy    === '' ? undefined : Number(accuracy))    as any,
        score_service:     (service     === '' ? undefined : Number(service))     as any,
        body: body || undefined,
        volume_band: (volume || undefined) as any,
        delivery_or_pickup: (delivery || undefined) as any,
      });
      onSubmitted();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to submit');
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="bg-white border border-violet-200 rounded-lg p-3 space-y-2">
      <div className="text-xs font-bold uppercase tracking-wider text-violet-700">{existing ? 'Edit your review' : 'Write a review'}</div>
      <StarPicker value={overall} onChange={setOverall} />
      <div className="grid grid-cols-2 gap-2">
        <SubScore label="Price"       value={price}       onChange={setPrice} />
        <SubScore label="Reliability" value={reliability} onChange={setReliability} />
        <SubScore label="Quality"     value={quality}     onChange={setQuality} />
        <SubScore label="Accuracy"    value={accuracy}    onChange={setAccuracy} />
        <SubScore label="Service"     value={service}     onChange={setService} />
      </div>
      <textarea
        className="input text-sm w-full p-2 h-20"
        placeholder="Tell other operators what to know (price, delivery, quality, surprises)…"
        value={body}
        onChange={(e) => setBody(e.target.value)}
      />
      <div className="grid grid-cols-2 gap-2">
        <select className="input h-9 text-sm" value={volume} onChange={(e) => setVolume(e.target.value)}>
          <option value="">Volume…</option>
          <option value="light">Light</option><option value="moderate">Moderate</option><option value="heavy">Heavy</option>
        </select>
        <select className="input h-9 text-sm" value={delivery} onChange={(e) => setDelivery(e.target.value)}>
          <option value="">Delivery or pickup…</option>
          <option value="delivery">Delivery</option><option value="pickup">Pickup</option><option value="both">Both</option>
        </select>
      </div>
      <button className="btn btn-primary h-9 px-3 text-sm w-full" onClick={submit} disabled={busy}>
        {existing ? 'Update review' : 'Submit review'}
      </button>
      <p className="text-[10px] text-slate-500 leading-snug">
        Carafe reviews are operator-only. Verification is required (your org must have a restaurant). Vendors can publicly respond — they cannot edit or remove your review.
      </p>
    </section>
  );
}

function StarPicker({ value, onChange }: { value: number; onChange: (n: number) => void }) {
  return (
    <div className="flex items-center gap-1">
      {[1, 2, 3, 4, 5].map((n) => (
        <button
          key={n}
          type="button"
          className="hover:scale-110 transition"
          onClick={() => onChange(n)}
        >
          <Star size={22} className={n <= value ? 'fill-amber-400 text-amber-400' : 'text-slate-300'} />
        </button>
      ))}
    </div>
  );
}

function SubScore({ label, value, onChange }: { label: string; value: number | ''; onChange: (v: number | '') => void }) {
  return (
    <label className="block">
      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">{label}</div>
      <select className="input h-9 text-sm w-full" value={value} onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))}>
        <option value="">—</option>
        <option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option>
      </select>
    </label>
  );
}
