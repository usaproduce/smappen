import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import {
  Sparkles, ChevronRight, MapPin, Loader2, Mail, TrendingUp, FileText,
} from 'lucide-react';
import { api } from '../../api/client';
import {
  overviewApi,
  type OverviewPayload, type OverviewTopMove, type OverviewGoals,
} from '../../api/restaurants';
import type { Recommendation } from '../../stores/restaurantStore';
import RestaurantWorkspaceLayout from './RestaurantWorkspaceLayout';
import {
  MoneyStat, DollarDelta, FreshnessChip, RecommendationCard,
  SyncStatus, CogsStaleBanner,
} from '../carafe';
import { studyTradeAreaForRestaurant } from '../../utils/studyTradeArea';

/**
 * War-room — mobile-first one-handed pre-service screen.
 *
 * Hierarchy from top:
 *   1. ROI hero — MoneyStat with the 6-month sparkline below.
 *   2. Top Move — unified <RecommendationCard density="hero"> with big
 *      Accept/Dismiss/Why-this. Optimistic + undoable via useRecommendationAction.
 *   3. Today's service — three tiles tinted against operator goals.
 *   4. Digest callout — 48h after the Monday send.
 *   5. POS state — actionable when disconnected.
 *   6. Study trade area — secondary footer link.
 *
 * Above the fold at 390×844: tiles 1 + 2 fit before any scroll.
 * Desktop reflows 1+2 into a 5/7 column row; remainder stacked below.
 */

export default function RestaurantOverviewPage() {
  const { id } = useParams<{ id: string }>();
  const restaurantId = id ?? '';
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState<OverviewPayload | null>(null);
  const [studying, setStudying] = useState(false);
  const [, setTick] = useState(0);

  const [queue, setQueue] = useState<OverviewTopMove[]>([]);

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

  // Re-render once a minute so freshness chips roll without a network hit.
  useEffect(() => {
    const t = window.setInterval(() => setTick((n) => n + 1), 60_000);
    return () => window.clearInterval(t);
  }, []);

  const current: OverviewTopMove | null = queue[0] ?? null;

  function advanceQueue() {
    setQueue((q) => q.slice(1));
    setData((d) => (d ? { ...d, open_recs_count: Math.max(0, d.open_recs_count - 1) } : d));
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
          <div className="skeleton h-32" />
          <div className="skeleton h-44" />
          <div className="skeleton h-28" />
        </div>
      </RestaurantWorkspaceLayout>
    );
  }

  const showDigestCallout = !!data.digest;
  const digestHighlights = new Set(data.digest?.rec_ids ?? []);
  const isFromDigest = !!current && digestHighlights.has(current.id);

  return (
    <RestaurantWorkspaceLayout>
      <div className="space-y-4 sm:space-y-5">
        {/* ── Above-the-fold pair (ROI + Top Move) ──────────────────── */}
        <div className="grid grid-cols-1 md:grid-cols-12 gap-4 sm:gap-5">
          <section className="md:col-span-5">
            <RoiHero data={data} restaurantId={restaurantId} />
          </section>
          <section className="md:col-span-7">
            <TopMoveSection
              current={current}
              openRecsCount={data.open_recs_count}
              restaurantId={restaurantId}
              isFromDigest={isFromDigest}
              onAfterDecide={advanceQueue}
            />
          </section>
        </div>

        <TodayServiceTile data={data} />

        {showDigestCallout && data.digest && (
          <DigestCallout digest={data.digest} restaurantId={restaurantId} />
        )}

        <PosCard data={data} restaurantId={restaurantId} />

        <section>
          <button
            type="button"
            onClick={studyArea}
            disabled={studying}
            className="w-full min-h-[44px] text-left flex items-center justify-between gap-3 bg-white border border-slate-200 rounded-xl px-3 py-2.5 hover:border-violet-300 hover:shadow-sm transition disabled:opacity-60 disabled:cursor-wait"
          >
            <div className="flex items-center gap-3 min-w-0">
              <span
                className="inline-flex items-center justify-center w-10 h-10 rounded-lg flex-shrink-0"
                style={{ background: 'var(--bg-panel)', color: 'var(--slate)' }}
              >
                <MapPin size={16} />
              </span>
              <div className="min-w-0">
                <div className="font-semibold text-sm truncate" style={{ color: 'var(--ink)' }}>
                  Study trade area
                </div>
                <div className="text-[11px] truncate" style={{ color: 'var(--slate)' }}>
                  15-min drive · demographics, competitors, projected covers &amp; margin
                </div>
              </div>
            </div>
            {studying
              ? <Loader2 size={16} className="animate-spin flex-shrink-0" style={{ color: 'var(--slate)' }} />
              : <ChevronRight size={16} className="flex-shrink-0" style={{ color: 'var(--slate)' }} />}
          </button>
        </section>
      </div>
    </RestaurantWorkspaceLayout>
  );
}

/* ── ROI hero ────────────────────────────────────────────────────────── */
function RoiHero({ data, restaurantId }: { data: OverviewPayload; restaurantId: string }) {
  const foundDollars = Math.round(data.roi.found_cents / 100);
  const measuredDollars = Math.round(data.roi.measured_cents / 100);
  const pendingDollars = Math.round(data.roi.pending_cents / 100);
  const trend = data.roi_trend.map((p) => p.found_cents);
  const lastMonthDollars = useMemo(() => {
    if (trend.length < 2) return null;
    return Math.round(trend[trend.length - 2] / 100);
  }, [trend]);
  const monthOverMonth = lastMonthDollars != null ? foundDollars - lastMonthDollars : null;
  const [downloading, setDownloading] = useState(false);

  async function downloadReport() {
    if (downloading) return;
    setDownloading(true);
    const t = toast.loading('Generating report…');
    try {
      // The endpoint is auth-protected — a naive window.open() strips
      // the Authorization header. Fetch as a Blob and save via the
      // download-attribute pattern (same as components/data/ReportButton).
      const resp = await api.get(
        `/api/restaurants/${restaurantId}/reports/money-found.pdf`,
        { responseType: 'blob' },
      );
      const blob = new Blob([resp.data], { type: 'application/pdf' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `carafe-money-found-${new Date().toISOString().slice(0, 7)}.pdf`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      toast.success('Report downloaded', { id: t });
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Report failed', { id: t });
    } finally {
      setDownloading(false);
    }
  }

  return (
    <article
      className="border rounded-xl p-4 sm:p-5 h-full flex flex-col gap-3"
      style={{ background: 'white', borderColor: 'var(--line-soft)' }}
    >
      <MoneyStat
        label="Carafe found you this month"
        value={foundDollars}
        size="xl"
        tone={foundDollars > 0 ? 'positive' : 'neutral'}
        timestamp={data.roi.last_updated_at ?? undefined}
        freshnessLabel="last move"
        icon={<TrendingUp size={11} strokeWidth={2.5} />}
        footer={monthOverMonth != null && (
          <DollarDelta value={monthOverMonth} comparison="vs last month" size="sm" />
        )}
      />

      {/* Download report — surfaced only once there's something worth
          showing. The PDF uses the exact same RoiService::monthlySummary
          numbers as the figure above, so the two can't drift. */}
      {foundDollars > 0 && (
        <button
          type="button"
          onClick={downloadReport}
          disabled={downloading}
          aria-label={`Download money-found report for ${new Date().toLocaleString('default', { month: 'long' })}`}
          className="inline-flex items-center gap-1.5 self-start px-3 min-h-[36px] rounded-lg text-xs font-bold disabled:opacity-60"
          style={{
            background: 'var(--brand-light)',
            color: 'var(--brand)',
            border: '1px solid var(--brand-light)',
          }}
        >
          {downloading ? <Loader2 size={12} className="animate-spin" /> : <FileText size={12} />}
          {downloading ? 'Generating…' : 'Download report'}
        </button>
      )}

      <div className="text-xs leading-snug" style={{ color: 'var(--body)' }}>
        {foundDollars > 0 ? (
          <>
            <strong style={{ color: 'var(--ink)' }}>${measuredDollars.toLocaleString()}</strong> already measured against your sales ·{' '}
            <strong style={{ color: 'var(--ink)' }}>${pendingDollars.toLocaleString()}</strong> pending from{' '}
            {data.roi.accepted_count} accepted move{data.roi.accepted_count === 1 ? '' : 's'}
          </>
        ) : (
          <>Once you accept your first move, we measure the dollar impact against your sales here.</>
        )}
      </div>

      {trend.length > 0 && (
        <div className="flex items-end justify-between gap-3 mt-auto pt-1">
          <div className="text-[9px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
            Last 6 months
          </div>
          <Sparkline values={trend} height={36} width={120} />
        </div>
      )}
    </article>
  );
}

function Sparkline({ values, width, height }: { values: number[]; width: number; height: number }) {
  if (!values || values.length === 0) {
    return <div style={{ width, height, color: 'var(--muted)' }} className="text-[10px] flex items-end">no history yet</div>;
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
      <path d={path} fill="none" stroke="var(--brand)" strokeWidth={1.75} strokeLinejoin="round" strokeLinecap="round" />
      {last && <circle cx={last[0]} cy={last[1]} r={2.75} fill="var(--brand)" />}
    </svg>
  );
}

/* ── Top Move section — uses the unified <RecommendationCard> ────────── */
function TopMoveSection({
  current, openRecsCount, restaurantId, isFromDigest, onAfterDecide,
}: {
  current: OverviewTopMove | null;
  openRecsCount: number;
  restaurantId: string;
  isFromDigest: boolean;
  onAfterDecide: () => void;
}) {
  return (
    <div className="h-full flex flex-col gap-2">
      <div className="flex items-baseline justify-between gap-2 min-w-0">
        <h2 className="font-extrabold text-base flex items-center gap-2 min-w-0" style={{ color: 'var(--ink)' }}>
          <Sparkles size={16} style={{ color: 'var(--brand)' }} />
          <span className="truncate">Top move</span>
        </h2>
        {openRecsCount > 1 && (
          <Link
            to={`/app/restaurants/${restaurantId}/menu`}
            className="text-xs font-semibold whitespace-nowrap min-h-[44px] flex items-center"
            style={{ color: 'var(--brand)' }}
          >
            See all {openRecsCount} →
          </Link>
        )}
      </div>

      {!current ? (
        <div
          className="border rounded-xl p-6 text-center text-sm flex-1 flex items-center justify-center"
          style={{ background: 'white', borderColor: 'var(--line-soft)', color: 'var(--slate)' }}
        >
          {openRecsCount === 0
            ? 'No moves on the table right now — margins look healthy.'
            : "You're caught up. Next batch lands Monday morning."}
        </div>
      ) : (
        <RecommendationCard
          key={current.id}
          rec={topMoveToRec(current)}
          itemName={current.menu_item_name}
          fallbackPrice={current.menu_item_price_cents}
          fallbackPlateCost={current.plate_cost_cents}
          density="hero"
          badge={isFromDigest ? (
            <span
              className="text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded"
              style={{ background: 'var(--brand-light)', color: 'var(--brand)' }}
            >
              From digest
            </span>
          ) : undefined}
          onAfterDecide={onAfterDecide}
        />
      )}
    </div>
  );
}

/* OverviewTopMove → minimal Recommendation shape for the unified card.
   Fields the card needs: id, kind, payload, narrative, dollar_estimate_cents,
   created_at, status. The rest are filled with safe defaults. */
function topMoveToRec(t: OverviewTopMove): Recommendation {
  return {
    id: t.id,
    menu_item_id: t.menu_item_id,
    kind: t.kind,
    payload: t.payload,
    narrative: t.narrative,
    dollar_estimate_cents: t.dollar_estimate_cents,
    status: 'suggested',
    measured_impact_cents: null,
    created_at: t.created_at,
    decided_at: null,
    measured_at: null,
  };
}

/* ── Today's service ─────────────────────────────────────────────────── */
function TodayServiceTile({ data }: { data: OverviewPayload }) {
  const t = data.today_service;
  const tone = foodCostTone(t?.food_cost_pct ?? null, data.goals);
  const lastSaleTs = t?.last_sale_at ?? null;
  return (
    <section
      className="rounded-xl p-4 sm:p-5 border"
      style={{ background: 'white', borderColor: 'var(--line-soft)' }}
    >
      <div className="flex items-baseline justify-between gap-2 mb-3">
        <h2 className="font-extrabold text-base truncate" style={{ color: 'var(--ink)' }}>
          Today's service
        </h2>
        {lastSaleTs ? (
          <FreshnessChip timestamp={lastSaleTs} label="last sale" />
        ) : data.pos.connected && data.pos.last_synced_at ? (
          <FreshnessChip timestamp={data.pos.last_synced_at} label="POS" />
        ) : null}
      </div>
      {t?.note && (
        <div className="mb-3 text-xs" style={{ color: 'var(--slate)' }}>{t.note}</div>
      )}
      <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
        <ServiceCell label="Covers"     value={t ? t.covers.toLocaleString() : '—'} accent="var(--accent-revenue)" />
        <ServiceCell label="$ / cover"  value={t?.revenue_per_cover_cents != null ? formatUsd(t.revenue_per_cover_cents) : '—'} accent="var(--accent-margin)" />
        <ServiceCell
          label="Food cost"
          value={t?.food_cost_pct != null ? formatPct(t.food_cost_pct) : '—'}
          tone={tone}
          hint={data.goals.food_cost_pct_target != null
            ? `goal ${formatPct(data.goals.food_cost_pct_target)}`
            : 'no goal set'}
          className="col-span-2 sm:col-span-1"
        />
      </div>
    </section>
  );
}

function ServiceCell({ label, value, hint, tone = 'neutral', accent, className }: {
  label: string;
  value: string;
  hint?: string;
  tone?: 'good' | 'warn' | 'bad' | 'neutral';
  /** Optional semantic-accent stripe + label tint. Use --accent-* tokens. */
  accent?: string;
  className?: string;
}) {
  const color = {
    good: 'var(--money-positive)',
    warn: '#92670E',
    bad: 'var(--money-negative)',
    neutral: 'var(--ink)',
  }[tone];
  const bg = {
    good: 'var(--money-positive-bg)',
    warn: 'var(--fresh-aging-bg)',
    bad: 'var(--money-negative-bg)',
    neutral: 'var(--bg-panel)',
  }[tone];
  // The accent prop only applies when the cell has no status to convey —
  // tone-driven cells keep their state coloring per the "never overload
  // ramp hues with error/ok meaning" rule.
  const useAccent = tone === 'neutral' && accent;
  return (
    <div
      className={'rounded-lg p-3 relative overflow-hidden ' + (className ?? '')}
      style={{ background: bg }}
    >
      {useAccent && (
        <span
          aria-hidden="true"
          className="absolute left-0 top-0 bottom-0 w-1"
          style={{ background: accent }}
        />
      )}
      <div
        className="text-[10px] font-bold uppercase tracking-wider"
        style={{ color: useAccent ? accent : 'var(--slate)' }}
      >
        {label}
      </div>
      <div className="text-2xl font-extrabold tabular-nums mt-1 leading-none" style={{ color }}>
        {value}
      </div>
      {hint && (
        <div className="text-[11px] font-semibold mt-1.5" style={{ color: 'var(--slate)' }}>
          {hint}
        </div>
      )}
    </div>
  );
}

function foodCostTone(pct: number | null, goals: OverviewGoals): 'good' | 'warn' | 'bad' | 'neutral' {
  if (pct == null) return 'neutral';
  if (pct <= goals.food_cost_pct_good) return 'good';
  if (pct <= goals.food_cost_pct_warn) return 'warn';
  return 'bad';
}

/* ── Digest callout ──────────────────────────────────────────────────── */
function DigestCallout({ digest, restaurantId }: { digest: NonNullable<OverviewPayload['digest']>; restaurantId: string }) {
  return (
    <section
      className="border rounded-xl p-3 sm:p-3.5 flex items-start gap-3"
      style={{ background: 'var(--brand-50)', borderColor: 'var(--brand-light)' }}
    >
      <span
        className="inline-flex items-center justify-center w-10 h-10 rounded-lg flex-shrink-0"
        style={{ background: 'var(--brand-light)', color: 'var(--brand)' }}
      >
        <Mail size={16} />
      </span>
      <div className="flex-1 min-w-0">
        <div className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--brand)' }}>
          From your Monday digest
        </div>
        <div className="font-extrabold text-sm mt-0.5" style={{ color: 'var(--ink)' }}>
          {digest.rec_count} move{digest.rec_count === 1 ? '' : 's'} we sent you — {formatUsd(digest.total_cents)}/mo on the table
        </div>
        <div className="text-[11px] mt-0.5" style={{ color: 'var(--slate)' }}>
          Sent <RelTime iso={digest.sent_at} /> · we've highlighted them below
        </div>
      </div>
      <Link
        to={`/app/restaurants/${restaurantId}/menu?source=digest`}
        className="text-xs font-semibold whitespace-nowrap min-h-[44px] flex items-center px-2 -mr-2"
        style={{ color: 'var(--brand)' }}
      >
        See all →
      </Link>
    </section>
  );
}

/* ── POS card ────────────────────────────────────────────────────────── */
function PosCard({ data, restaurantId }: { data: OverviewPayload; restaurantId: string }) {
  const { pos, usda_prices } = data;
  const navigate = useNavigate();
  return (
    <div className="space-y-2">
      <SyncStatus
        provider="Square"
        lastSyncedAt={pos.connected ? pos.last_synced_at : null}
        lastSaleAt={pos.connected ? pos.last_sale_at : null}
        onPrimary={() => navigate(`/app/restaurants/${restaurantId}/menu`)}
      />
      {usda_prices && (
        <CogsStaleBanner asOf={usda_prices.as_of} region={null} />
      )}
    </div>
  );
}

function RelTime({ iso }: { iso: string }) {
  const ts = Date.parse(iso.replace(' ', 'T'));
  if (!Number.isFinite(ts)) return <>unknown</>;
  const diff = Math.max(0, Date.now() - ts);
  const m = Math.floor(diff / 60_000);
  if (m < 1) return <>just now</>;
  if (m < 60) return <>{m}m ago</>;
  const h = Math.floor(m / 60);
  if (h < 24) return <>{h}h ago</>;
  const d = Math.floor(h / 24);
  if (d < 14) return <>{d}d ago</>;
  return <>{formatDateShort(iso)}</>;
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
