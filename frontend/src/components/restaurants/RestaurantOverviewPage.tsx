import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import {
  Sparkles, ChevronRight, Check, X, MapPin, Loader2, RefreshCw,
  Plug, Mail, Info,
} from 'lucide-react';
import {
  overviewApi, recommendationsApi,
  type OverviewPayload, type OverviewTopMove, type OverviewGoals,
} from '../../api/restaurants';
import RestaurantWorkspaceLayout from './RestaurantWorkspaceLayout';
import AnimatedNumber from '../common/AnimatedNumber';
import { studyTradeAreaForRestaurant } from '../../utils/studyTradeArea';

/**
 * War-room dashboard — spec §1.6 / §9, audit item 7.
 *
 * One screen the operator opens every morning during pre-service. Every
 * tile is a dollar number, a freshness chip, and (where possible) a one-
 * tap action — no chart-heavy filler. Hierarchy from top:
 *
 *   1. ROI hero: animated "Carafe found you $X this month" + 6-month
 *      sparkline so the trend is one glance away.
 *   2. Today's service: covers, $/cover, food-cost % to date, color-
 *      coded against the operator's goal (or industry defaults).
 *   3. Digest callout (only for 48h after the Monday send): "this is
 *      what we sent you" with a one-click jump back to those recs.
 *   4. Top Move: single highest-dollar suggested rec with Accept /
 *      Dismiss / Why this. Advances locally on decision — no refetch.
 *   5. POS connection state + Study trade area as secondary actions.
 *
 * Lighthouse target: desktop ≥ 90. Single GET /overview round-trip,
 * skeleton on load, sparkline rendered as inline SVG (no chart lib).
 */

export default function RestaurantOverviewPage() {
  const { id } = useParams<{ id: string }>();
  const restaurantId = id ?? '';
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState<OverviewPayload | null>(null);
  const [studying, setStudying] = useState(false);
  const [tick, setTick] = useState(0);

  // Local Top Move queue — populated from overview.top_move + next_moves
  // so Accept/Dismiss can advance the card without a refetch (acceptance
  // criterion: "a different rec without a full page reload").
  const [queue, setQueue] = useState<OverviewTopMove[]>([]);
  const [whyOpen, setWhyOpen] = useState(false);
  const [deciding, setDeciding] = useState(false);

  useEffect(() => {
    if (!restaurantId) return;
    let cancelled = false;
    (async () => {
      try {
        const payload = await overviewApi.get(restaurantId);
        if (cancelled) return;
        setData(payload);
        setQueue([payload.top_move, ...payload.next_moves].filter(Boolean) as OverviewTopMove[]);
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed to load overview');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [restaurantId]);

  // Re-render every 60s so the "12 min ago" chips stay accurate without
  // a network round-trip.
  useEffect(() => {
    const t = window.setInterval(() => setTick((n) => n + 1), 60_000);
    return () => window.clearInterval(t);
  }, []);

  const current: OverviewTopMove | null = queue[0] ?? null;

  async function decideAndAdvance(action: 'accept' | 'dismiss') {
    if (!current || deciding) return;
    const decided = current;
    setDeciding(true);
    setWhyOpen(false);
    // Optimistic advance — pop the current card immediately.
    setQueue((q) => q.slice(1));
    try {
      if (action === 'accept') {
        await recommendationsApi.accept(decided.id);
        toast.success('Accepted — measuring impact');
      } else {
        await recommendationsApi.dismiss(decided.id);
      }
      setData((d) => (d ? { ...d, open_recs_count: Math.max(0, d.open_recs_count - 1) } : d));
    } catch (e: any) {
      // Roll back: put the card back at the front.
      setQueue((q) => [decided, ...q]);
      toast.error(e?.response?.data?.error ?? 'Could not save decision');
    } finally {
      setDeciding(false);
    }
  }

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

  if (loading || !data) {
    return (
      <RestaurantWorkspaceLayout>
        <div className="space-y-3" aria-busy="true">
          <div className="skeleton h-28" />
          <div className="skeleton h-24" />
          <div className="skeleton h-44" />
          <div className="skeleton h-20" />
        </div>
      </RestaurantWorkspaceLayout>
    );
  }

  const digestRecent = !!data.digest;
  const showDigestCallout = digestRecent;
  const digestHighlights = new Set(data.digest?.rec_ids ?? []);
  const isFromDigest = current && digestHighlights.has(current.id);

  return (
    <RestaurantWorkspaceLayout>
      {/* data-tick keeps the 60s re-render hook alive so FreshnessChip
          can recompute "12m ago" without a network call. */}
      <div className="space-y-5" data-tick={tick}>
        {/* ───────────────── ROI hero ───────────────── */}
        <RoiHero
          foundCents={data.roi.found_cents}
          measuredCents={data.roi.measured_cents}
          pendingCents={data.roi.pending_cents}
          acceptedCount={data.roi.accepted_count}
          lastUpdatedAt={data.roi.last_updated_at}
          trend={data.roi_trend.map((p) => p.found_cents)}
        />

        {/* ───────────────── Today's service ───────────────── */}
        <TodayServiceTile data={data} />

        {/* ───────────────── Digest callout ───────────────── */}
        {showDigestCallout && data.digest && (
          <section
            className="border rounded-xl p-3.5 flex items-start gap-3"
            style={{ background: 'linear-gradient(135deg,#faf5ff 0%,#fff 100%)', borderColor: '#ddd6fe' }}
          >
            <span className="inline-flex items-center justify-center w-9 h-9 rounded-lg flex-shrink-0" style={{ background: '#ede9fe', color: '#7848BB' }}>
              <Mail size={16} />
            </span>
            <div className="flex-1 min-w-0">
              <div className="text-[10px] font-bold uppercase tracking-wider" style={{ color: '#7848BB' }}>
                From your Monday digest
              </div>
              <div className="font-extrabold text-sm mt-0.5" style={{ color: '#1A1A2E' }}>
                {data.digest.rec_count} move{data.digest.rec_count === 1 ? '' : 's'} we sent you — {formatUsd(data.digest.total_cents)}/mo on the table
              </div>
              <div className="text-[11px] text-slate-500 mt-0.5">
                Sent {relativeTime(data.digest.sent_at)} · we've highlighted them below
              </div>
            </div>
            <Link
              to={`/app/restaurants/${restaurantId}/menu?source=digest`}
              className="text-xs font-semibold text-violet-700 hover:underline whitespace-nowrap pt-1"
            >
              See all →
            </Link>
          </section>
        )}

        {/* ───────────────── Top Move ───────────────── */}
        <section>
          <div className="flex items-baseline justify-between mb-2">
            <h2 className="font-extrabold text-base flex items-center gap-2" style={{ color: '#1A1A2E' }}>
              <Sparkles size={16} style={{ color: '#7848BB' }} />
              Top move
              {isFromDigest && (
                <span className="text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded" style={{ background: '#ede9fe', color: '#7848BB' }}>
                  From digest
                </span>
              )}
            </h2>
            {data.open_recs_count > 1 && (
              <Link to={`/app/restaurants/${restaurantId}/menu`} className="text-xs font-semibold text-violet-700 hover:underline">
                See all {data.open_recs_count} →
              </Link>
            )}
          </div>
          {!current ? (
            <div className="bg-white border border-slate-200 rounded-xl p-6 text-center text-sm text-slate-500">
              {data.open_recs_count === 0
                ? 'No moves on the table right now — margins look healthy.'
                : 'You’re caught up. Next batch lands Monday morning.'}
            </div>
          ) : (
            <TopMoveCard
              rec={current}
              whyOpen={whyOpen}
              setWhyOpen={setWhyOpen}
              onAccept={() => decideAndAdvance('accept')}
              onDismiss={() => decideAndAdvance('dismiss')}
              deciding={deciding}
            />
          )}
        </section>

        {/* ───────────────── POS connection state ───────────────── */}
        <PosCard data={data} restaurantId={restaurantId} />

        {/* ───────────────── Secondary: Study trade area ───────────────── */}
        <section>
          <button
            type="button"
            onClick={studyArea}
            disabled={studying}
            className="w-full text-left flex items-center justify-between bg-white border border-slate-200 rounded-xl p-3 hover:border-violet-300 hover:shadow-sm transition disabled:opacity-60 disabled:cursor-wait"
          >
            <div className="flex items-center gap-3">
              <span className="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-50" style={{ color: '#64748b' }}>
                <MapPin size={14} />
              </span>
              <div>
                <div className="font-semibold text-sm" style={{ color: '#334155' }}>Study trade area</div>
                <div className="text-[11px] text-slate-500">15-min drive · demographics, foot traffic, competitors</div>
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

/* ──────────────────────────────────────────────────────────────────────
 * ROI hero — animated headline + inline SVG sparkline.
 *
 * Inline SVG rather than ECharts because (a) Lighthouse target ≥ 90 and
 * the chart lib is ~600KB gzipped, (b) the sparkline has no axes / no
 * legend / no tooltips — pixel-perfect inline path beats a config blob.
 * ────────────────────────────────────────────────────────────────────── */
function RoiHero({
  foundCents, measuredCents, pendingCents, acceptedCount, lastUpdatedAt, trend,
}: {
  foundCents: number; measuredCents: number; pendingCents: number;
  acceptedCount: number; lastUpdatedAt: string | null; trend: number[];
}) {
  const hasMoney = foundCents > 0;
  return (
    <section
      className="border rounded-xl p-5 flex items-stretch gap-5"
      style={{
        background: hasMoney
          ? 'linear-gradient(135deg,#ecfdf5 0%,#fff 60%,#f5f3ff 100%)'
          : '#f8fafc',
        borderColor: hasMoney ? '#a7f3d0' : '#e2e8f0',
      }}
    >
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2">
          <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: hasMoney ? '#047857' : '#64748b' }}>
            This month
          </span>
          {lastUpdatedAt && <FreshnessChip iso={lastUpdatedAt} label="last move" />}
        </div>
        <div className="mt-1 flex items-baseline gap-1.5" style={{ color: '#1A1A2E' }}>
          <span className="text-3xl font-extrabold tabular-nums">
            Carafe found you <AnimatedNumber value={Math.round(foundCents / 100)} format={(n) => '$' + Math.round(n).toLocaleString()} />
          </span>
        </div>
        <div className="text-xs text-slate-600 mt-1.5 leading-snug">
          {hasMoney ? (
            <>
              {formatUsd(measuredCents)} already measured against your sales · {formatUsd(pendingCents)} pending from{' '}
              {acceptedCount} accepted move{acceptedCount === 1 ? '' : 's'}
            </>
          ) : (
            <>Once you accept your first move, we measure the dollar impact against your sales here.</>
          )}
        </div>
      </div>
      <div className="hidden sm:flex flex-col items-end justify-between min-w-[140px]">
        <div className="text-[9px] font-bold uppercase tracking-wider text-slate-500">Last 6 months</div>
        <Sparkline values={trend} height={42} width={140} />
      </div>
    </section>
  );
}

function Sparkline({ values, width, height }: { values: number[]; width: number; height: number }) {
  if (!values || values.length === 0) {
    return <div style={{ width, height }} className="text-[10px] text-slate-400 flex items-end">no history yet</div>;
  }
  const padY = 4;
  const max = Math.max(1, ...values);
  const stepX = values.length > 1 ? width / (values.length - 1) : width;
  const points = values.map((v, i) => {
    const x = i * stepX;
    const y = padY + (height - padY * 2) * (1 - v / max);
    return [x, y] as const;
  });
  const path = points.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ');
  const area = `${path} L ${(values.length - 1) * stepX} ${height} L 0 ${height} Z`;
  const last = points[points.length - 1];
  return (
    <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`} aria-hidden="true">
      <path d={area} fill="rgba(120,72,187,0.12)" />
      <path d={path} fill="none" stroke="#7848BB" strokeWidth={1.75} strokeLinejoin="round" strokeLinecap="round" />
      {last && <circle cx={last[0]} cy={last[1]} r={2.75} fill="#7848BB" />}
    </svg>
  );
}

/* ──────────────────────────────────────────────────────────────────────
 * Today's service tile — color-coded against operator goals.
 * ────────────────────────────────────────────────────────────────────── */
function TodayServiceTile({ data }: { data: OverviewPayload }) {
  const t = data.today_service;
  return (
    <section className="bg-white border border-slate-200 rounded-xl p-4">
      <div className="flex items-baseline justify-between">
        <h2 className="font-extrabold text-sm" style={{ color: '#1A1A2E' }}>Today's service</h2>
        {t && t.last_sale_at && <FreshnessChip iso={t.last_sale_at} label="last sale" />}
        {(!t || !t.last_sale_at) && data.pos.connected && (
          <FreshnessChip iso={data.pos.last_synced_at} label="POS synced" mutedIfNull />
        )}
      </div>
      {t && t.note ? (
        <div className="mt-2 text-xs text-slate-500">{t.note}</div>
      ) : null}
      <div className="grid grid-cols-3 gap-3 mt-3">
        <NumberCell
          label="Covers"
          value={t ? t.covers.toLocaleString() : '—'}
          tone="neutral"
        />
        <NumberCell
          label="$ / cover"
          value={t?.revenue_per_cover_cents != null ? formatUsd(t.revenue_per_cover_cents) : '—'}
          tone="neutral"
        />
        <NumberCell
          label="Food cost"
          value={t?.food_cost_pct != null ? formatPct(t.food_cost_pct) : '—'}
          tone={foodCostTone(t?.food_cost_pct ?? null, data.goals)}
          hint={data.goals.food_cost_pct_target != null
            ? `goal ${formatPct(data.goals.food_cost_pct_target)}`
            : 'no goal set'}
        />
      </div>
    </section>
  );
}

function foodCostTone(pct: number | null, goals: OverviewGoals): 'good' | 'warn' | 'bad' | 'neutral' {
  if (pct == null) return 'neutral';
  if (pct <= goals.food_cost_pct_good) return 'good';
  if (pct <= goals.food_cost_pct_warn) return 'warn';
  return 'bad';
}

function NumberCell({ label, value, hint, tone }: {
  label: string; value: string; hint?: string; tone: 'good' | 'warn' | 'bad' | 'neutral';
}) {
  const color = { good: '#047857', warn: '#b45309', bad: '#b91c1c', neutral: '#1A1A2E' }[tone];
  return (
    <div className="bg-slate-50 rounded-lg p-2.5">
      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">{label}</div>
      <div className="text-xl font-extrabold tabular-nums mt-0.5" style={{ color }}>{value}</div>
      {hint && <div className="text-[10px] font-semibold mt-0.5" style={{ color: '#475569' }}>{hint}</div>}
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────────────
 * Top Move card — single highest-dollar rec, big controls.
 * ────────────────────────────────────────────────────────────────────── */
function TopMoveCard({ rec, whyOpen, setWhyOpen, onAccept, onDismiss, deciding }: {
  rec: OverviewTopMove;
  whyOpen: boolean;
  setWhyOpen: (b: boolean) => void;
  onAccept: () => void;
  onDismiss: () => void;
  deciding: boolean;
}) {
  return (
    <article className="bg-white border-2 rounded-xl p-4" style={{ borderColor: '#ddd6fe' }}>
      <div className="flex items-start gap-4">
        <div className="flex-1 min-w-0">
          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">{rec.kind.replace('_', ' ')}</div>
          <div className="text-3xl font-extrabold tabular-nums leading-tight mt-1" style={{ color: '#1A1A2E' }}>
            {formatUsd(rec.dollar_estimate_cents)}<span className="text-base font-bold text-slate-500">/mo</span>
          </div>
          {rec.menu_item_name && (
            <div className="font-semibold text-sm mt-1.5" style={{ color: '#334155' }}>{rec.menu_item_name}</div>
          )}
          {rec.narrative && (
            <p className="text-sm mt-1.5 leading-snug" style={{ color: '#475569' }}>{rec.narrative}</p>
          )}
        </div>
        <FreshnessChip iso={rec.created_at} label="found" />
      </div>

      <div className="flex items-center gap-2 mt-3.5">
        <button
          type="button"
          onClick={onAccept}
          disabled={deciding}
          className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 h-10 rounded-lg text-sm font-bold text-white disabled:opacity-60 disabled:cursor-wait"
          style={{ background: '#059669' }}
        >
          <Check size={16} /> Accept
        </button>
        <button
          type="button"
          onClick={onDismiss}
          disabled={deciding}
          className="inline-flex items-center justify-center gap-1.5 px-3 h-10 rounded-lg text-sm font-semibold border border-slate-200 hover:bg-slate-50 disabled:opacity-60 disabled:cursor-wait"
          style={{ color: '#475569' }}
        >
          <X size={14} /> Dismiss
        </button>
        <button
          type="button"
          onClick={() => setWhyOpen(!whyOpen)}
          aria-expanded={whyOpen}
          className="inline-flex items-center justify-center gap-1.5 px-3 h-10 rounded-lg text-sm font-semibold border border-slate-200 hover:bg-slate-50"
          style={{ color: '#475569' }}
        >
          <Info size={14} /> Why this?
        </button>
      </div>

      {whyOpen && (
        <div className="mt-3 bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs leading-relaxed" style={{ color: '#334155' }}>
          <WhyExplanation rec={rec} />
        </div>
      )}
    </article>
  );
}

function WhyExplanation({ rec }: { rec: OverviewTopMove }) {
  const payload = rec.payload ?? {};
  const priceDelta = num(payload['price_delta_cents']);
  const baselinePrice = num(payload['baseline_price_cents']) ?? rec.menu_item_price_cents;
  const newPrice = num(payload['new_price_cents']) ?? (baselinePrice != null && priceDelta != null ? baselinePrice + priceDelta : null);
  const monthlyQty = num(payload['est_monthly_qty']);
  const plateCost = num(payload['plate_cost_cents']) ?? rec.plate_cost_cents;
  return (
    <>
      <div className="font-bold text-[10px] uppercase tracking-wider text-slate-500 mb-1.5">How we got to {formatUsd(rec.dollar_estimate_cents)}/mo</div>
      <ul className="space-y-0.5">
        {baselinePrice != null && (
          <li>Current price: <strong>{formatUsd(baselinePrice)}</strong></li>
        )}
        {newPrice != null && (
          <li>Suggested price: <strong>{formatUsd(newPrice)}</strong>{priceDelta != null && <> ({priceDelta >= 0 ? '+' : ''}{formatUsd(priceDelta)})</>}</li>
        )}
        {plateCost != null && (
          <li>Plate cost: <strong>{formatUsd(plateCost)}</strong></li>
        )}
        {monthlyQty != null && (
          <li>Recent sales pace: <strong>{monthlyQty.toLocaleString()}/mo</strong> (from your POS)</li>
        )}
        {priceDelta != null && monthlyQty != null && (
          <li className="pt-1 mt-1 border-t border-slate-200" style={{ color: '#1A1A2E' }}>
            {formatUsd(priceDelta)} × {monthlyQty}/mo = <strong>{formatUsd(rec.dollar_estimate_cents)}/mo</strong>
          </li>
        )}
      </ul>
      <div className="mt-2 text-[11px] text-slate-500">
        We don't claim measured impact until we've watched 14 days of sales after you accept.
      </div>
    </>
  );
}

function num(v: any): number | null {
  if (v === null || v === undefined) return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
}

/* ──────────────────────────────────────────────────────────────────────
 * POS connection card — actionable when disconnected, freshness chip
 * + last-sale chip when connected.
 * ────────────────────────────────────────────────────────────────────── */
function PosCard({ data, restaurantId }: { data: OverviewPayload; restaurantId: string }) {
  const { pos, usda_prices } = data;
  return (
    <section className="bg-white border border-slate-200 rounded-xl p-4">
      <div className="flex items-start gap-3">
        <span className="inline-flex items-center justify-center w-10 h-10 rounded-lg flex-shrink-0"
              style={{ background: pos.connected ? '#ecfdf5' : '#fef3c7', color: pos.connected ? '#047857' : '#b45309' }}>
          <Plug size={18} />
        </span>
        <div className="flex-1 min-w-0">
          <div className="flex items-baseline gap-2 flex-wrap">
            <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>
              {pos.connected ? `Square connected` : 'Connect Square to find moves'}
            </div>
            {pos.connected && pos.last_synced_at && (
              <FreshnessChip iso={pos.last_synced_at} label="POS synced" />
            )}
            {pos.connected && pos.last_sale_at && (
              <FreshnessChip iso={pos.last_sale_at} label="last sale" />
            )}
          </div>
          <div className="text-xs text-slate-500 mt-1">
            {pos.connected
              ? <>We pull every line item — that's the basis for the dollar moves below.</>
              : <>Without a POS feed we can't see what's selling. Connect once, then forget about it.</>}
            {usda_prices && (
              <> · <span className="whitespace-nowrap">USDA prices as of {formatDateShort(usda_prices.as_of)}</span></>
            )}
          </div>
        </div>
        {!pos.connected && (
          <Link
            to={`/app/restaurants/${restaurantId}/menu`}
            className="inline-flex items-center justify-center gap-1 px-3 h-9 rounded-lg text-xs font-bold text-white"
            style={{ background: '#7848BB' }}
          >
            Connect <ChevronRight size={14} />
          </Link>
        )}
      </div>
    </section>
  );
}

/* ──────────────────────────────────────────────────────────────────────
 * Freshness chip — "12m ago", "14h ago", "Mar 4". Hover surfaces ISO.
 * The acceptance criterion is strict: every visible number on this
 * page must carry a last_updated_at. The chip is the visible token.
 * ────────────────────────────────────────────────────────────────────── */
function FreshnessChip({ iso, label, mutedIfNull }: { iso: string | null; label: string; mutedIfNull?: boolean }) {
  if (!iso) {
    if (mutedIfNull) {
      return (
        <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded"
              style={{ background: '#f1f5f9', color: '#64748b' }}>
          <RefreshCw size={9} /> {label}: never
        </span>
      );
    }
    return null;
  }
  const rel = relativeTime(iso);
  const stale = isStale(iso);
  return (
    <span
      title={`${label}: ${iso}`}
      className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded whitespace-nowrap"
      style={{
        background: stale ? '#fef3c7' : '#f1f5f9',
        color: stale ? '#92400e' : '#475569',
      }}
    >
      <RefreshCw size={9} /> {label} {rel}
    </span>
  );
}

function relativeTime(iso: string): string {
  const ts = Date.parse(iso.replace(' ', 'T'));
  if (!Number.isFinite(ts)) return 'unknown';
  const diff = Math.max(0, Date.now() - ts);
  const m = Math.floor(diff / 60_000);
  if (m < 1)   return 'just now';
  if (m < 60)  return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24)  return `${h}h ago`;
  const d = Math.floor(h / 24);
  if (d < 14)  return `${d}d ago`;
  return formatDateShort(iso);
}

function isStale(iso: string): boolean {
  const ts = Date.parse(iso.replace(' ', 'T'));
  if (!Number.isFinite(ts)) return false;
  return Date.now() - ts > 24 * 60 * 60 * 1000;
}

function formatDateShort(iso: string): string {
  const ts = Date.parse(iso.replace(' ', 'T'));
  if (!Number.isFinite(ts)) return iso;
  return new Date(ts).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function formatUsd(cents: number | null | undefined): string {
  if (cents === null || cents === undefined) return '—';
  return '$' + Math.round(cents / 100).toLocaleString();
}

function formatPct(p: number): string {
  return (p * 100).toFixed(1) + '%';
}
