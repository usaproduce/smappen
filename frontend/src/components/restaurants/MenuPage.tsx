import { useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import {
  ArrowLeft, RefreshCw, Sparkles, Check, X, ExternalLink, AlertCircle, MapPin,
} from 'lucide-react';
import {
  restaurantsApi, posApi, menuApi, recommendationsApi, roiApi, type RoiMonthly,
} from '../../api/restaurants';
import { useRestaurantStore, type MenuItem, type Recommendation } from '../../stores/restaurantStore';

/**
 * Carafe menu workspace — the entire Phase 1 vertical slice lives here.
 *
 * Top: restaurant header + POS connection state + sync button.
 * Middle: menu table (name, price, plate cost, margin).
 * Bottom: dollar-quantified recommendations the operator can accept or
 *         dismiss. Acceptance is what eventually drives the ROI ledger
 *         (Chunk 3).
 */
export default function MenuPage() {
  const { id } = useParams<{ id: string }>();
  const restaurantId = id ?? '';
  const currentRestaurant = useRestaurantStore((s) => s.currentRestaurant);
  const setCurrentRestaurant = useRestaurantStore((s) => s.setCurrentRestaurant);
  const menuItems = useRestaurantStore((s) => s.menuItems);
  const setMenuItems = useRestaurantStore((s) => s.setMenuItems);
  const recommendations = useRestaurantStore((s) => s.recommendations);
  const setRecommendations = useRestaurantStore((s) => s.setRecommendations);
  const updateRecStatus = useRestaurantStore((s) => s.updateRecommendationStatus);

  const [loading, setLoading] = useState(true);
  const [posIntegrations, setPosIntegrations] = useState<Array<{ provider: string; last_synced_at: string | null }>>([]);
  const [syncing, setSyncing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [roi, setRoi] = useState<RoiMonthly | null>(null);

  useEffect(() => {
    if (!restaurantId) return;
    let cancelled = false;
    (async () => {
      try {
        const [r, items, recs, pos, roiMonthly] = await Promise.all([
          restaurantsApi.show(restaurantId),
          menuApi.listItems(restaurantId),
          recommendationsApi.list(restaurantId, 'suggested'),
          posApi.listIntegrations(restaurantId).catch(() => []),
          roiApi.monthly(restaurantId).catch(() => null),
        ]);
        if (cancelled) return;
        setCurrentRestaurant(r);
        setMenuItems(items);
        setRecommendations(recs);
        setPosIntegrations(pos);
        setRoi(roiMonthly);
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed to load menu');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [restaurantId, setCurrentRestaurant, setMenuItems, setRecommendations]);

  const squareConnected = useMemo(() => posIntegrations.some((p) => p.provider === 'square'), [posIntegrations]);

  async function connectSquare() {
    setBusy(true);
    try {
      const { auth_url } = await posApi.connect(restaurantId, 'square');
      window.location.href = auth_url;
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to start Square OAuth');
    } finally {
      setBusy(false);
    }
  }

  async function syncNow() {
    setSyncing(true);
    try {
      await posApi.sync(restaurantId, 'square');
      toast.success('Sync queued — refresh in a moment to see pulled items.');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Sync failed');
    } finally {
      setSyncing(false);
    }
  }

  async function refreshAll() {
    setBusy(true);
    try {
      await menuApi.recomputePlateCosts(restaurantId);
      await recommendationsApi.runForRestaurant(restaurantId);
      const [items, recs] = await Promise.all([
        menuApi.listItems(restaurantId),
        recommendationsApi.list(restaurantId, 'suggested'),
      ]);
      setMenuItems(items);
      setRecommendations(recs);
      toast.success('Recomputed plate costs + recommendations');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Refresh failed');
    } finally {
      setBusy(false);
    }
  }

  async function acceptRec(rec: Recommendation) {
    updateRecStatus(rec.id, 'accepted');
    try {
      await recommendationsApi.accept(rec.id);
      // Optionally apply the suggested price right away — for the slice we
      // just record acceptance; Chunk 3 will push the price back to POS.
      toast.success('Recommendation accepted');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to accept');
    }
  }

  async function dismissRec(rec: Recommendation) {
    updateRecStatus(rec.id, 'dismissed');
    try {
      await recommendationsApi.dismiss(rec.id);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to dismiss');
    }
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-white">
        <div className="max-w-6xl mx-auto px-6 py-6 space-y-3">
          <div className="skeleton h-8 w-64" />
          <div className="skeleton h-32 w-full" />
          <div className="skeleton h-64 w-full" />
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-white">
      <header className="bg-white border-b border-slate-200">
        <div className="max-w-6xl mx-auto px-6 py-3 flex items-center justify-between">
          <Link to="/app/restaurants" className="text-sm font-semibold text-slate-700 hover:text-violet-700 flex items-center gap-1">
            <ArrowLeft size={14} /> Restaurants
          </Link>
          <div className="text-sm font-bold" style={{ color: '#1A1A2E' }}>
            {currentRestaurant?.name ?? '—'}
          </div>
        </div>
      </header>

      <main className="max-w-6xl mx-auto px-6 py-6">
        {/* ROI strip — only when there's something to show */}
        {roi && roi.found_cents > 0 && (
          <section className="bg-gradient-to-r from-emerald-50 to-white border border-emerald-200 rounded-xl p-4 mb-6 flex items-center justify-between">
            <div>
              <div className="text-[10px] font-bold uppercase tracking-wider text-emerald-700">This month</div>
              <div className="text-2xl font-extrabold tabular-nums" style={{ color: '#1A1A2E' }}>
                Carafe found you {formatUsd(roi.found_cents)}
              </div>
              <div className="text-xs text-slate-500 mt-0.5">
                {formatUsd(roi.measured_cents)} measured · {formatUsd(roi.pending_cents)} estimated from {roi.accepted_count} accepted move{roi.accepted_count === 1 ? '' : 's'}
              </div>
            </div>
          </section>
        )}

        {/* POS strip */}
        <section className="bg-slate-50 rounded-xl p-4 mb-6 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <span
              className="inline-flex items-center justify-center w-10 h-10 rounded-lg font-bold text-white"
              style={{ background: squareConnected ? '#22c55e' : '#94a3b8' }}
            >
              SQ
            </span>
            <div>
              <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>
                Square {squareConnected ? '— connected' : '— not connected'}
              </div>
              <div className="text-xs text-slate-500">
                {squareConnected
                  ? `Last sync: ${posIntegrations.find((p) => p.provider === 'square')?.last_synced_at ?? 'never'}`
                  : 'Connect Square to auto-pull your menu items.'}
              </div>
            </div>
          </div>
          <div className="flex items-center gap-2">
            {!squareConnected && (
              <button className="btn btn-primary h-9 px-3 text-sm" onClick={connectSquare} disabled={busy}>
                Connect Square <ExternalLink size={12} />
              </button>
            )}
            {squareConnected && (
              <button className="btn h-9 px-3 text-sm" onClick={syncNow} disabled={syncing}>
                <RefreshCw size={12} className={syncing ? 'animate-spin' : ''} /> Sync now
              </button>
            )}
            <button className="btn h-9 px-3 text-sm" onClick={refreshAll} disabled={busy}>
              <Sparkles size={12} /> Recompute
            </button>
          </div>
        </section>

        {/* Recommendations strip — only when there's something to act on */}
        {recommendations.length > 0 && (
          <section className="mb-6">
            <h2 className="font-extrabold text-base mb-3 flex items-center gap-2" style={{ color: '#1A1A2E' }}>
              <Sparkles size={16} style={{ color: '#7848BB' }} />
              Money you're leaving on the table
            </h2>
            <ul className="space-y-2">
              {recommendations.map((rec) => (
                <RecommendationCard
                  key={rec.id}
                  rec={rec}
                  item={menuItems.find((m) => m.id === rec.menu_item_id) ?? null}
                  onAccept={() => acceptRec(rec)}
                  onDismiss={() => dismissRec(rec)}
                />
              ))}
            </ul>
          </section>
        )}

        {/* Market intel entry point — repurposes the existing /app map stack */}
        <section className="mb-6">
          <Link to="/app" className="flex items-center justify-between bg-white border border-slate-200 rounded-xl p-4 hover:border-violet-300 hover:shadow-sm transition">
            <div className="flex items-center gap-3">
              <span className="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-violet-50" style={{ color: '#7848BB' }}>
                <MapPin size={18} />
              </span>
              <div>
                <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>Study your trade area</div>
                <div className="text-xs text-slate-500">Who lives in your delivery radius, how busy this block is, where competitors are.</div>
              </div>
            </div>
            <ExternalLink size={14} className="text-slate-400" />
          </Link>
        </section>

        {/* Menu items */}
        <section>
          <h2 className="font-extrabold text-base mb-3" style={{ color: '#1A1A2E' }}>Menu</h2>
          {menuItems.length === 0 ? (
            <EmptyMenuState squareConnected={squareConnected} onConnectSquare={connectSquare} />
          ) : (
            <MenuTable items={menuItems} />
          )}
        </section>
      </main>
    </div>
  );
}

function MenuTable({ items }: { items: MenuItem[] }) {
  return (
    <div className="overflow-x-auto bg-white border border-slate-200 rounded-xl">
      <table className="w-full text-sm">
        <thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-600">
          <tr>
            <th className="text-left px-3 py-2">Item</th>
            <th className="text-left px-3 py-2">Category</th>
            <th className="text-right px-3 py-2">Price</th>
            <th className="text-right px-3 py-2">Plate cost</th>
            <th className="text-right px-3 py-2">Margin</th>
            <th className="text-right px-3 py-2">Margin %</th>
          </tr>
        </thead>
        <tbody>
          {items.map((it) => (
            <tr key={it.id} className="border-t border-slate-100">
              <td className="px-3 py-2 font-semibold" style={{ color: '#1A1A2E' }}>{it.name}</td>
              <td className="px-3 py-2 text-slate-500">{it.category ?? '—'}</td>
              <td className="px-3 py-2 text-right font-semibold tabular-nums" style={{ color: '#1A1A2E' }}>
                {formatUsd(it.price_cents)}
              </td>
              <td className="px-3 py-2 text-right tabular-nums">
                {it.true_cost_cents !== null ? formatUsd(it.true_cost_cents) : <NoCostHint coverage={it.coverage_pct} />}
              </td>
              <td className="px-3 py-2 text-right font-semibold tabular-nums" style={{ color: it.margin_pct !== null && it.margin_pct < 0.6 ? '#dc2626' : '#1A1A2E' }}>
                {it.margin_cents !== null ? formatUsd(it.margin_cents) : '—'}
              </td>
              <td className="px-3 py-2 text-right tabular-nums">
                {it.margin_pct !== null ? `${Math.round(it.margin_pct * 100)}%` : '—'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function NoCostHint({ coverage }: { coverage: number | null }) {
  if (coverage === null) {
    return <span className="text-slate-400 text-xs">add recipe</span>;
  }
  return <span className="text-amber-600 text-xs">{coverage}% covered</span>;
}

function RecommendationCard({
  rec,
  item,
  onAccept,
  onDismiss,
}: {
  rec: Recommendation;
  item: MenuItem | null;
  onAccept: () => void;
  onDismiss: () => void;
}) {
  const usd = formatUsd(rec.dollar_estimate_cents) + '/mo';
  return (
    <li className="bg-white border border-slate-200 rounded-xl p-3 flex items-start gap-3">
      <span className="inline-flex items-center justify-center w-10 h-10 rounded-lg font-bold text-white flex-shrink-0" style={{ background: '#7848BB' }}>
        +
      </span>
      <div className="flex-1 min-w-0">
        <div className="flex items-baseline gap-3">
          <span className="font-extrabold text-lg tabular-nums" style={{ color: '#1A1A2E' }}>{usd}</span>
          <span className="text-xs font-semibold uppercase tracking-wider text-slate-500">{rec.kind.replace('_', ' ')}</span>
        </div>
        {item && <div className="text-xs text-slate-500 mt-0.5">{item.name}</div>}
        {rec.narrative && <p className="text-sm text-slate-700 mt-1.5 leading-snug">{rec.narrative}</p>}
      </div>
      <div className="flex items-center gap-1 flex-shrink-0">
        <button className="p-1.5 text-emerald-600 hover:bg-emerald-50 rounded" onClick={onAccept} title="Accept">
          <Check size={16} />
        </button>
        <button className="p-1.5 text-slate-500 hover:bg-slate-50 rounded" onClick={onDismiss} title="Dismiss">
          <X size={16} />
        </button>
      </div>
    </li>
  );
}

function EmptyMenuState({ squareConnected, onConnectSquare }: { squareConnected: boolean; onConnectSquare: () => void }) {
  return (
    <div className="bg-slate-50 rounded-xl p-10 text-center">
      <AlertCircle size={28} className="mx-auto text-slate-400 mb-2" />
      <div className="font-semibold text-slate-700">No menu items yet</div>
      <div className="text-sm text-slate-500 mt-1">
        {squareConnected
          ? "Click 'Sync now' to pull your menu from Square."
          : "Connect Square to auto-import your menu, or use the API to add items manually."}
      </div>
      {!squareConnected && (
        <button className="btn btn-primary mt-3 h-9 px-3 text-sm" onClick={onConnectSquare}>
          Connect Square
        </button>
      )}
    </div>
  );
}

function formatUsd(cents: number | null | undefined): string {
  if (cents === null || cents === undefined) return '—';
  const v = cents / 100;
  return '$' + v.toFixed(2);
}
