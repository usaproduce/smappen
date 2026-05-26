import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import {
  Sparkles, ChefHat, ChevronRight, Check, X, TrendingUp, MapPin, Loader2,
} from 'lucide-react';
import {
  menuApi, recommendationsApi, roiApi, posApi,
  type RoiMonthly,
} from '../../api/restaurants';
import RestaurantWorkspaceLayout from './RestaurantWorkspaceLayout';
import type { MenuItem, Recommendation } from '../../stores/restaurantStore';
import { studyTradeAreaForRestaurant } from '../../utils/studyTradeArea';

/**
 * War-room dashboard — spec §9.
 *
 * One screen the operator can land on every morning:
 *   - "Carafe found you $X this month" tile (drives word-of-mouth + renewal)
 *   - Today's headline numbers (cover count, menu items, plate-cost coverage)
 *   - Top 3 unread recommendations with Accept/Dismiss inline (the 2-3
 *     highest-dollar moves of the week — the digest in-app)
 *   - POS connection status + quick links to the deeper tabs
 *
 * Deliberately not a chart-heavy page. Operators want dollar numbers and
 * 1-2 tap actions, not graphs.
 */

export default function RestaurantOverviewPage() {
  const { id } = useParams<{ id: string }>();
  const restaurantId = id ?? '';
  const [loading, setLoading] = useState(true);
  const [items, setItems] = useState<MenuItem[]>([]);
  const [recs, setRecs] = useState<Recommendation[]>([]);
  const [roi, setRoi] = useState<RoiMonthly | null>(null);
  const [posIntegrations, setPosIntegrations] = useState<Array<{ provider: string; last_synced_at: string | null }>>([]);
  const [studying, setStudying] = useState(false);
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
        const [i, r, m, p] = await Promise.all([
          menuApi.listItems(restaurantId),
          recommendationsApi.list(restaurantId, 'suggested'),
          roiApi.monthly(restaurantId).catch(() => null),
          posApi.listIntegrations(restaurantId).catch(() => []),
        ]);
        if (cancelled) return;
        setItems(i);
        setRecs(r);
        setRoi(m);
        setPosIntegrations(p);
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed to load overview');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [restaurantId]);

  const activeItems = items.filter((m) => m.is_active);
  const itemsWithCost = activeItems.filter((m) => m.true_cost_cents !== null);
  const coveragePct = activeItems.length === 0 ? 0 : Math.round((itemsWithCost.length / activeItems.length) * 100);
  const topRecs = recs.slice(0, 3);
  const squareConnected = posIntegrations.some((p) => p.provider === 'square');

  async function accept(rec: Recommendation) {
    setRecs((cur) => cur.filter((r) => r.id !== rec.id));
    try { await recommendationsApi.accept(rec.id); toast.success('Accepted'); }
    catch (e: any) { toast.error(e?.response?.data?.error ?? 'Failed'); }
  }
  async function dismiss(rec: Recommendation) {
    setRecs((cur) => cur.filter((r) => r.id !== rec.id));
    try { await recommendationsApi.dismiss(rec.id); }
    catch (e: any) { toast.error(e?.response?.data?.error ?? 'Failed'); }
  }

  if (loading) {
    return (
      <RestaurantWorkspaceLayout>
        <div className="space-y-3">
          <div className="skeleton h-24" />
          <div className="skeleton h-40" />
          <div className="skeleton h-32" />
        </div>
      </RestaurantWorkspaceLayout>
    );
  }

  return (
    <RestaurantWorkspaceLayout>
      <div className="space-y-6">
        {/* ROI hero */}
        {roi && roi.found_cents > 0 ? (
          <section className="bg-gradient-to-br from-emerald-50 via-white to-violet-50 border border-emerald-200 rounded-xl p-5">
            <div className="text-[10px] font-bold uppercase tracking-wider text-emerald-700">This month</div>
            <div className="text-3xl font-extrabold tabular-nums mt-1" style={{ color: '#1A1A2E' }}>
              Carafe found you {formatUsd(roi.found_cents)}
            </div>
            <div className="text-xs text-slate-600 mt-1">
              {formatUsd(roi.measured_cents)} already measured against your sales · {formatUsd(roi.pending_cents)} pending from {roi.accepted_count} accepted move{roi.accepted_count === 1 ? '' : 's'}
            </div>
          </section>
        ) : (
          <section className="bg-slate-50 rounded-xl p-5">
            <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Get started</div>
            <div className="text-lg font-extrabold mt-1" style={{ color: '#1A1A2E' }}>
              {!squareConnected ? 'Connect Square so we can pull your menu.' : 'Add a recipe — we need plate cost to find money.'}
            </div>
            <div className="mt-3">
              <Link to={`/app/restaurants/${restaurantId}/${!squareConnected ? 'menu' : 'recipes'}`} className="btn btn-primary h-9 px-3 text-sm">
                {!squareConnected ? 'Go to Menu → Connect Square' : 'Open Recipes'} <ChevronRight size={14} />
              </Link>
            </div>
          </section>
        )}

        {/* Stat tiles */}
        <section className="grid grid-cols-2 md:grid-cols-4 gap-3">
          <StatTile label="Menu items"   value={String(activeItems.length)} />
          <StatTile label="Plate cost coverage" value={`${coveragePct}%`}
                    hint={activeItems.length === 0 ? 'none yet' : `${itemsWithCost.length}/${activeItems.length}`}
                    tone={coveragePct >= 75 ? 'good' : coveragePct >= 30 ? 'warn' : 'bad'} />
          <StatTile label="Suggestions"  value={String(recs.length)}
                    tone={recs.length > 0 ? 'good' : 'neutral'} />
          <StatTile label="POS"          value={squareConnected ? 'Connected' : 'Not connected'}
                    hint={squareConnected ? 'Square' : ''}
                    tone={squareConnected ? 'good' : 'warn'} />
        </section>

        {/* Top recommendations */}
        <section>
          <h2 className="font-extrabold text-base mb-3 flex items-center gap-2" style={{ color: '#1A1A2E' }}>
            <Sparkles size={16} style={{ color: '#7848BB' }} />
            Top moves
            {recs.length > topRecs.length && (
              <Link to={`/app/restaurants/${restaurantId}/menu`} className="ml-auto text-xs font-semibold text-violet-700 hover:underline">
                View all {recs.length} →
              </Link>
            )}
          </h2>
          {topRecs.length === 0 ? (
            <div className="bg-white border border-slate-200 rounded-xl p-6 text-center text-sm text-slate-500">
              {coveragePct < 30
                ? <>Add recipes for your menu items first — plate cost is what unlocks dollar-quantified recommendations.</>
                : <>Nothing suggested right now. Margins look healthy.</>
              }
            </div>
          ) : (
            <ul className="space-y-2">
              {topRecs.map((rec) => {
                const item = items.find((m) => m.id === rec.menu_item_id) ?? null;
                return (
                  <li key={rec.id} className="bg-white border border-slate-200 rounded-xl p-3 flex items-start gap-3">
                    <span className="inline-flex items-center justify-center w-10 h-10 rounded-lg font-bold text-white flex-shrink-0" style={{ background: '#7848BB' }}>+</span>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-baseline gap-2">
                        <span className="font-extrabold text-lg tabular-nums" style={{ color: '#1A1A2E' }}>{formatUsd(rec.dollar_estimate_cents)}/mo</span>
                        <span className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{rec.kind.replace('_', ' ')}</span>
                      </div>
                      {item && <div className="text-xs text-slate-500 mt-0.5">{item.name}</div>}
                      {rec.narrative && <p className="text-sm text-slate-700 mt-1 leading-snug">{rec.narrative}</p>}
                    </div>
                    <div className="flex flex-col items-center gap-1 flex-shrink-0">
                      <button className="p-1.5 text-emerald-600 hover:bg-emerald-50 rounded" onClick={() => accept(rec)} title="Accept"><Check size={16} /></button>
                      <button className="p-1.5 text-slate-500 hover:bg-slate-50 rounded" onClick={() => dismiss(rec)} title="Dismiss"><X size={16} /></button>
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </section>

        {/* Quick links to the deeper tabs */}
        <section className="grid grid-cols-2 md:grid-cols-3 gap-3">
          <QuickLink to={`/app/restaurants/${restaurantId}/menu`}    icon={ChefHat}    title="Menu"    hint="items + recommendations" />
          <QuickLink to={`/app/restaurants/${restaurantId}/costs`}   icon={TrendingUp} title="Costs"   hint="theoretical food cost" />
          <QuickLink to={`/app/restaurants/${restaurantId}/goals`}   icon={Sparkles}   title="Goals"   hint="scorecard + trends" />
        </section>

        {/* Trade-area entry — drops the user on the map with a 15-min drive
            isochrone already built around the restaurant's pin. */}
        <section>
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
                <div className="text-xs text-slate-500">15-min drive around this restaurant — demographics, foot traffic, competitors.</div>
              </div>
            </div>
            {studying
              ? <Loader2 size={14} className="text-slate-400 animate-spin" />
              : <ChevronRight size={14} className="text-slate-400" />}
          </button>
        </section>
      </div>
    </RestaurantWorkspaceLayout>
  );
}

function StatTile({ label, value, hint, tone = 'neutral' }: { label: string; value: string; hint?: string; tone?: 'good' | 'warn' | 'bad' | 'neutral' }) {
  const color = {
    good: '#059669',
    warn: '#d97706',
    bad:  '#dc2626',
    neutral: '#1A1A2E',
  }[tone];
  return (
    <div className="bg-white border border-slate-200 rounded-xl p-3">
      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">{label}</div>
      <div className="text-xl font-extrabold tabular-nums mt-1" style={{ color }}>{value}</div>
      {hint && <div className="text-[11px] text-slate-500 mt-0.5">{hint}</div>}
    </div>
  );
}

function QuickLink({ to, icon: Icon, title, hint }: { to: string; icon: any; title: string; hint: string }) {
  return (
    <Link to={to} className="bg-white border border-slate-200 rounded-xl p-3 flex items-center gap-3 hover:border-violet-300 hover:shadow-sm transition">
      <span className="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-violet-50" style={{ color: '#7848BB' }}>
        <Icon size={18} />
      </span>
      <div className="flex-1 min-w-0">
        <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>{title}</div>
        <div className="text-xs text-slate-500 truncate">{hint}</div>
      </div>
      <ChevronRight size={14} className="text-slate-400" />
    </Link>
  );
}

function formatUsd(cents: number | null | undefined): string {
  if (cents === null || cents === undefined) return '—';
  return '$' + Math.round(cents / 100).toLocaleString();
}
