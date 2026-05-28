import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import {
  Sparkles, ExternalLink, AlertCircle, AlertTriangle, MapPin, Loader2,
} from 'lucide-react';
import {
  posApi, menuApi, recommendationsApi, roiApi, overviewApi, engineeringApi,
  type RoiMonthly, type MenuEngineeringPayload,
} from '../../api/restaurants';
import { useRestaurantStore, type MenuItem } from '../../stores/restaurantStore';
import RestaurantWorkspaceLayout from './RestaurantWorkspaceLayout';
import { studyTradeAreaForRestaurant } from '../../utils/studyTradeArea';
import {
  RecommendationCard, MenuEngineeringChart, SyncStatus, CogsStaleBanner,
  SkeletonBlock, SkeletonCard, SkeletonChart, SkeletonRecCard, SkeletonTable,
} from '../carafe';

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
  const menuItems = useRestaurantStore((s) => s.menuItems);
  const setMenuItems = useRestaurantStore((s) => s.setMenuItems);
  const recommendations = useRestaurantStore((s) => s.recommendations);
  const setRecommendations = useRestaurantStore((s) => s.setRecommendations);

  const [loading, setLoading] = useState(true);
  const [posIntegrations, setPosIntegrations] = useState<Array<{ provider: string; last_synced_at: string | null }>>([]);
  const [syncing, setSyncing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [roi, setRoi] = useState<RoiMonthly | null>(null);
  const [studying, setStudying] = useState(false);
  const [engineering, setEngineering] = useState<MenuEngineeringPayload | null>(null);
  const [cogsAsOf, setCogsAsOf] = useState<string | null>(null);
  const currentRestaurant = useRestaurantStore((s) => s.currentRestaurant);
  const navigate = useNavigate();

  async function studyArea() {
    if (studying) return;
    setStudying(true);
    try {
      const ok = await studyTradeAreaForRestaurant(restaurantId);
      if (ok) navigate('/app');
    } finally {
      setStudying(false);
    }
  }

  useEffect(() => {
    if (!restaurantId) return;
    let cancelled = false;
    (async () => {
      try {
        const [items, recs, pos, roiMonthly, classify, overview] = await Promise.all([
          menuApi.listItems(restaurantId),
          recommendationsApi.list(restaurantId, 'suggested'),
          posApi.listIntegrations(restaurantId).catch(() => []),
          roiApi.monthly(restaurantId).catch(() => null),
          engineeringApi.classify(restaurantId).catch(() => null),
          // overview gives us USDA as_of for the COGS attribution chip
          overviewApi.get(restaurantId).catch(() => null),
        ]);
        if (cancelled) return;
        setMenuItems(items);
        setRecommendations(recs);
        setPosIntegrations(pos);
        setRoi(roiMonthly);
        setEngineering(classify);
        setCogsAsOf(overview?.usda_prices?.as_of ?? null);
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed to load menu');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [restaurantId, setMenuItems, setRecommendations]);

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
      const [items, recs, classify] = await Promise.all([
        menuApi.listItems(restaurantId),
        recommendationsApi.list(restaurantId, 'suggested'),
        engineeringApi.classify(restaurantId).catch(() => null),
      ]);
      setMenuItems(items);
      setRecommendations(recs);
      setEngineering(classify);
      toast.success('Recomputed plate costs + recommendations');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Refresh failed');
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <RestaurantWorkspaceLayout>
        <div className="space-y-6" aria-busy="true" aria-live="polite">
          {/* POS connection strip */}
          <SkeletonCard minH={72} />
          {/* Menu-engineering chart */}
          <SkeletonChart height={300} />
          {/* Recommendation card */}
          <div className="space-y-2">
            <SkeletonBlock className="h-4 w-56" />
            <SkeletonRecCard />
            <SkeletonRecCard />
          </div>
          {/* Menu items table */}
          <SkeletonTable rows={6} />
        </div>
      </RestaurantWorkspaceLayout>
    );
  }

  return (
    <RestaurantWorkspaceLayout>
      <div className="space-y-6">
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

        {/* POS sync status — drives every state of the Square integration
            (not connected / connecting / syncing / synced / stale / error). */}
        <section className="mb-6 flex items-stretch gap-2 flex-wrap">
          <div className="flex-1 min-w-[260px]">
            <SyncStatus
              provider="Square"
              lastSyncedAt={posIntegrations.find((p) => p.provider === 'square')?.last_synced_at ?? null}
              isSyncing={syncing}
              isConnecting={busy && !squareConnected}
              onPrimary={squareConnected ? syncNow : connectSquare}
            />
          </div>
          <button
            type="button"
            className="inline-flex items-center justify-center gap-1.5 px-3 min-h-[44px] rounded-lg text-xs font-bold flex-shrink-0"
            style={{ background: 'white', border: '1px solid var(--line)', color: 'var(--ink)' }}
            onClick={refreshAll}
            disabled={busy}
          >
            <Sparkles size={14} /> Recompute
          </button>
        </section>

        {/* Recommendations strip — only when there's something to act on */}
        {/* COGS freshness banner — graceful degradation when USDA prices
            are aging/stale. Sits ABOVE the chart so the operator reads the
            caveat first, not after they've trusted the math. */}
        <CogsStaleBanner
          asOf={cogsAsOf}
          region={currentRestaurant?.region ?? null}
          className="mb-3"
        />

        {/* Menu-engineering 2×2 — the screenshot moment.
            Always renders when we have a classify payload; if zero items
            qualify (no recipes yet) the chart shows its own coverage
            empty state instead of being hidden. */}
        {engineering && (
          <section className="mb-6">
            <MenuEngineeringChart
              payload={engineering}
              totalActiveItems={menuItems.filter((m) => m.is_active === 1).length || engineering.items.length}
              cogsSource={{
                region: currentRestaurant?.region ?? null,
                asOf: cogsAsOf,
              }}
              findRec={(itemId) =>
                recommendations.find((r) => r.menu_item_id === itemId && r.status === 'suggested') ?? null
              }
            />
          </section>
        )}

        {recommendations.length > 0 && (
          <section className="mb-6">
            <h2 className="font-extrabold text-base mb-3 flex items-center gap-2" style={{ color: '#1A1A2E' }}>
              <Sparkles size={16} style={{ color: '#7848BB' }} />
              Money you're leaving on the table
            </h2>
            <ul className="space-y-2">
              {recommendations
                .filter((rec) => rec.status === 'suggested')
                .map((rec, i) => {
                  const item = menuItems.find((m) => m.id === rec.menu_item_id) ?? null;
                  return (
                    <li
                      key={rec.id}
                      className="stagger-in"
                      style={{ ['--stagger-i' as any]: i }}
                    >
                      <RecommendationCard
                        rec={rec}
                        itemName={item?.name ?? null}
                        fallbackPrice={item?.price_cents ?? null}
                        fallbackPlateCost={item?.true_cost_cents ?? null}
                        density="comfortable"
                      />
                    </li>
                  );
                })}
            </ul>
          </section>
        )}

        {/* Market intel entry point — repurposes the existing /app map stack.
            Pre-builds a 15-min drive isochrone on the restaurant's pin so the
            user lands on a useful map, not an empty one. */}
        <section className="mb-6">
          <button
            type="button"
            onClick={studyArea}
            disabled={studying}
            className="w-full text-left flex items-center justify-between bg-white border border-slate-200 rounded-xl p-4 hover:border-violet-300 hover:shadow-sm transition disabled:opacity-60 disabled:cursor-wait"
          >
            <div className="flex items-center gap-3">
              <span className="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-violet-50" style={{ color: '#7848BB' }}>
                <MapPin size={18} />
              </span>
              <div>
                <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>Study your trade area</div>
                <div className="text-xs text-slate-500">Who lives in your delivery radius, how busy this block is, where competitors are.</div>
              </div>
            </div>
            {studying
              ? <Loader2 size={14} className="text-slate-400 animate-spin" />
              : <ExternalLink size={14} className="text-slate-400" />}
          </button>
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
      </div>
    </RestaurantWorkspaceLayout>
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
              <td
                className="px-3 py-2 text-right font-semibold tabular-nums"
                style={{ color: it.margin_pct !== null && it.margin_pct < 0.6 ? 'var(--money-negative)' : 'var(--ink)' }}
              >
                {it.margin_cents !== null ? formatUsd(it.margin_cents) : '—'}
              </td>
              <td className="px-3 py-2 text-right tabular-nums">
                {it.margin_pct === null ? '—' : <MarginPctCell pct={it.margin_pct} />}
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

/* Margin % cell — pairs color with an explicit icon + verbal "low" tag
 * for items below the 60% target margin, so the warning isn't carried by
 * color alone. */
function MarginPctCell({ pct }: { pct: number }) {
  const low = pct < 0.6;
  const text = `${Math.round(pct * 100)}%`;
  if (!low) return <span style={{ color: 'var(--ink)' }}>{text}</span>;
  return (
    <span
      className="inline-flex items-center justify-end gap-1 font-semibold"
      style={{ color: 'var(--money-negative)' }}
      aria-label={`${text} — below 60% margin target`}
    >
      <AlertTriangle size={11} strokeWidth={2.5} aria-hidden />
      <span>{text}</span>
      <span
        className="text-[9px] font-bold uppercase tracking-wider ml-0.5 px-1 rounded"
        style={{ background: 'var(--money-negative-bg)' }}
      >
        Low
      </span>
    </span>
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
