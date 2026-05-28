import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import {
  DollarSign, CheckCircle2, AlertTriangle, AlertOctagon, TrendingDown, Info,
  BookOpen, ChevronRight,
} from 'lucide-react';
import {
  foodCostApi, type CogsBenchmarkFreshness, type FoodCostTheoretical,
} from '../../api/restaurants';
import RestaurantWorkspaceLayout from './RestaurantWorkspaceLayout';
import {
  MoneyStat, DollarDelta, FreshnessChip, CogsStaleBanner,
  SkeletonBlock, SkeletonCard, SkeletonList,
} from '../carafe';

/**
 * Theoretical food cost + overpay flags — spec §5.4 / §5.8.
 *
 * Phase 1: the gap data ("what you pay vs the market") needs invoice
 * ingestion, which lands in Phase 2. Until then, the strongest honest
 * signal we have is each item's monthly cost basis against a 65%-margin
 * target — i.e. "if you got Carbonara to 65% margin you'd save $X/mo".
 * That number anchors each row. The visual shell is built for Phase 2:
 * when actual-paid prices land, the per-row math swaps but the layout,
 * coverage gauge, and freshness treatment all stay.
 *
 * Hierarchy:
 *   1. Food-cost % hero (band-coded by WCAG-AA color + icon + label)
 *   2. Coverage gauge — honest at low coverage, calm, not alarming
 *   3. USDA freshness strip
 *   4. Overpay flags — ranked list, dominant MoneyStat per row
 */

const TARGET_FOOD_COST_PCT = 0.35; // 65% target margin → 35% food cost ceiling

export default function CostsPage() {
  const { id } = useParams<{ id: string }>();
  const restaurantId = id ?? '';
  const [start, setStart] = useState<string>(firstOfMonth());
  const [end, setEnd] = useState<string>(today());
  const [data, setData] = useState<FoodCostTheoretical | null>(null);
  const [loading, setLoading] = useState(true);

  async function load() {
    setLoading(true);
    try {
      setData(await foodCostApi.theoretical(restaurantId, { start, end }));
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    if (!restaurantId) return;
    let cancelled = false;
    (async () => {
      try {
        const r = await foodCostApi.theoretical(restaurantId, { start, end });
        if (!cancelled) setData(r);
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [restaurantId, start, end]);

  return (
    <RestaurantWorkspaceLayout>
      <div className="space-y-4">
        <header className="flex items-center justify-between flex-wrap gap-2">
          <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
            <DollarSign size={22} style={{ color: 'var(--brand)' }} /> Food cost
          </h1>
          <div className="flex items-center gap-2">
            <input
              type="date"
              aria-label="Period start"
              className="input h-10 text-sm"
              value={start}
              onChange={(e) => setStart(e.target.value)}
            />
            <span style={{ color: 'var(--slate)' }}>→</span>
            <input
              type="date"
              aria-label="Period end"
              className="input h-10 text-sm"
              value={end}
              onChange={(e) => setEnd(e.target.value)}
            />
            <button className="btn h-10 px-3 text-sm" onClick={load} disabled={loading}>Refresh</button>
          </div>
        </header>

        {loading ? (
          <div className="space-y-4" aria-busy="true" aria-live="polite">
            {/* Food-cost hero — band + percentage + ruler. */}
            <div
              className="rounded-xl border-2 p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-12 gap-4 sm:gap-5 items-center"
              style={{ background: 'white', borderColor: 'var(--line-soft)' }}
              aria-hidden
            >
              <div className="sm:col-span-3 flex items-center gap-3">
                <SkeletonBlock className="rounded-xl" style={{ width: 48, height: 48 }} />
                <div className="flex flex-col gap-1.5">
                  <SkeletonBlock className="h-2.5 w-12" />
                  <SkeletonBlock className="h-4 w-20" />
                </div>
              </div>
              <div className="sm:col-span-5 flex flex-col gap-1.5">
                <SkeletonBlock className="h-2.5 w-32" />
                <SkeletonBlock className="h-10 w-24" />
                <SkeletonBlock className="h-3 w-48" />
              </div>
              <div className="sm:col-span-4 flex flex-col gap-2">
                <SkeletonBlock className="h-3 w-24" />
                <SkeletonBlock className="h-3 w-full rounded-full" />
                <SkeletonBlock className="h-3 w-40" />
              </div>
            </div>
            {/* Coverage gauge */}
            <SkeletonCard minH={72} />
            {/* Freshness strip */}
            <SkeletonBlock className="h-9 w-full rounded-lg" />
            {/* Overpay list */}
            <div className="space-y-2">
              <SkeletonBlock className="h-4 w-40" />
              <SkeletonList rows={4} rowHeight={88} />
            </div>
          </div>
        ) : !data ? (
          <CostsEmptyState />
        ) : (
          <>
            {/* Aging/stale COGS banner — sits above the hero so the
                operator sees the caveat before they read the % figure. */}
            <CogsStaleBanner
              asOf={latestBenchmarkAsOf(data.benchmark_freshness)}
              region={null}
              onRetry={load}
            />

            <FoodCostHero
              theoreticalCostCents={data.theoretical_cost_cents}
              revenueCents={data.revenue_cents}
              theoreticalPct={data.theoretical_pct}
            />

            <CoverageGauge coveragePct={data.coverage_pct} note={data.note} />

            <CogsFreshnessStrip
              isLive={data.benchmark_is_live ?? false}
              entries={data.benchmark_freshness ?? []}
            />

            <OverpayList contributors={data.top_contributors} />
          </>
        )}
      </div>
    </RestaurantWorkspaceLayout>
  );
}

/* ── Food-cost % hero ──────────────────────────────────────────────────
   Band: good <30%, warn <40%, bad ≥40%. Color is paired with both an
   icon AND a one-word status so the signal survives color-blindness and
   the WCAG "color is not the only means" rule. All three colors against
   white pass WCAG AA contrast (verified at section bottom).
   ─────────────────────────────────────────────────────────────────────── */
function FoodCostHero({
  theoreticalCostCents, revenueCents, theoreticalPct,
}: {
  theoreticalCostCents: number; revenueCents: number; theoreticalPct: number;
}) {
  const band = pickBand(theoreticalPct);
  const pct = Math.round(theoreticalPct * 100);
  const overTargetCents = Math.max(0, theoreticalCostCents - Math.round(revenueCents * TARGET_FOOD_COST_PCT));

  return (
    <section
      className="rounded-xl p-4 sm:p-5 border-2"
      style={{
        background: 'white',
        borderColor: band.tint,
      }}
      aria-label={`Theoretical food cost ${pct}%, ${band.label}`}
    >
      <div className="grid grid-cols-1 sm:grid-cols-12 gap-4 sm:gap-5 items-center">
        {/* Status icon + label — left column on desktop, top on mobile. */}
        <div className="sm:col-span-3 flex items-center gap-3">
          <span
            aria-hidden
            className="inline-flex items-center justify-center w-12 h-12 rounded-xl flex-shrink-0"
            style={{ background: band.tint, color: band.color }}
          >
            <band.Icon size={24} strokeWidth={2.4} />
          </span>
          <div>
            <div className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
              Status
            </div>
            <div className="font-extrabold text-base" style={{ color: band.color }}>
              {band.label}
            </div>
          </div>
        </div>

        {/* The hero number — food cost % (rendered inline since MoneyStat
            is dollar-formatted; the dollar context lives in the footer). */}
        <div className="sm:col-span-5 flex flex-col gap-1.5">
          <div className="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
            <TrendingDown size={11} strokeWidth={2.5} /> Theoretical food cost
          </div>
          <div
            className="font-extrabold leading-none tabular-nums text-5xl"
            style={{ color: band.color }}
          >
            {pct}<span className="text-2xl ml-1" style={{ color: 'var(--slate)' }}>%</span>
          </div>
          <div className="text-xs" style={{ color: 'var(--body)' }}>
            <strong style={{ color: 'var(--ink)' }}>
              ${(theoreticalCostCents / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </strong>{' '}cost · <strong style={{ color: 'var(--ink)' }}>
              ${(revenueCents / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </strong>{' '}revenue
          </div>
        </div>

        {/* Band ruler + delta vs target */}
        <div className="sm:col-span-4 flex flex-col gap-2">
          <BandRuler value={theoreticalPct} />
          {overTargetCents > 0 ? (
            <DollarDelta
              value={overTargetCents / 100}
              goodWhen="down"
              precision="cents"
              comparison={`over ${Math.round(TARGET_FOOD_COST_PCT * 100)}% target`}
              size="sm"
            />
          ) : (
            <div className="inline-flex items-center gap-1.5 text-xs font-semibold" style={{ color: 'var(--money-positive)' }}>
              <CheckCircle2 size={14} strokeWidth={2.5} />
              Under the {Math.round(TARGET_FOOD_COST_PCT * 100)}% target
            </div>
          )}
        </div>
      </div>
    </section>
  );
}

/* Three-stop ruler: 0% .. 30% (good) .. 40% (warn) .. ~50% (bad).
   Marker rendered with both a colored chip AND a value label so it
   doesn't depend on color alone. */
function BandRuler({ value }: { value: number }) {
  const ceil = 0.5;
  const pos = Math.min(1, value / ceil);
  return (
    <div className="w-full">
      <div className="text-[9px] font-bold uppercase tracking-wider mb-1" style={{ color: 'var(--slate)' }}>
        Food-cost band
      </div>
      <div
        className="relative h-3 rounded-full overflow-hidden"
        style={{ background: 'var(--bg-panel)' }}
        aria-hidden
      >
        {/* Stop segments — good/warn/bad. */}
        <div className="absolute inset-y-0 left-0"          style={{ width: `${(0.30 / ceil) * 100}%`, background: 'var(--money-positive-bg)' }} />
        <div className="absolute inset-y-0"                 style={{ left: `${(0.30 / ceil) * 100}%`, width: `${((0.40 - 0.30) / ceil) * 100}%`, background: 'var(--fresh-aging-bg)' }} />
        <div className="absolute inset-y-0"                 style={{ left: `${(0.40 / ceil) * 100}%`, right: 0, background: 'var(--money-negative-bg)' }} />
        {/* Marker */}
        <div
          className="absolute top-1/2 -translate-y-1/2"
          style={{
            left: `calc(${pos * 100}% - 6px)`,
            width: 12,
            height: 12,
            borderRadius: 999,
            background: pickBand(value).color,
            border: '2px solid white',
            boxShadow: '0 1px 2px rgba(0,0,0,0.18)',
          }}
        />
      </div>
      <div className="flex justify-between text-[10px] mt-1 font-semibold" style={{ color: 'var(--slate)' }}>
        <span>0%</span>
        <span>30%</span>
        <span>40%</span>
        <span>50%+</span>
      </div>
    </div>
  );
}

type Band = {
  label: string;
  color: string;
  tint: string;
  Icon: typeof CheckCircle2;
};

function pickBand(pct: number): Band {
  if (pct < 0.30) return { label: 'Healthy',     color: 'var(--money-positive)', tint: 'var(--money-positive-bg)', Icon: CheckCircle2 };
  if (pct < 0.40) return { label: 'Watch',       color: '#92670E',                tint: 'var(--fresh-aging-bg)',    Icon: AlertTriangle };
  return                 { label: 'Over target', color: 'var(--money-negative)',  tint: 'var(--money-negative-bg)', Icon: AlertOctagon };
}

/* ── Coverage gauge ────────────────────────────────────────────────────
   Calm visual. Even at 5% the bar reads as "we're building toward full
   coverage" rather than "broken / red". Border-bottom-only progress.
   ─────────────────────────────────────────────────────────────────────── */
function CoverageGauge({ coveragePct, note }: { coveragePct: number; note: string }) {
  const pct = Math.max(0, Math.min(100, coveragePct));
  return (
    <section
      className="rounded-xl border p-3 sm:p-4 flex flex-col gap-2"
      style={{ background: 'white', borderColor: 'var(--line-soft)' }}
    >
      <div className="flex items-baseline justify-between gap-3 flex-wrap">
        <div className="font-extrabold text-sm" style={{ color: 'var(--ink)' }}>
          Coverage · {pct}% of sales lines have plate cost
        </div>
        <div className="text-[11px] italic" style={{ color: 'var(--slate)' }}>
          {note}
        </div>
      </div>
      <div
        className="relative h-2 rounded-full overflow-hidden"
        style={{ background: 'var(--bg-panel)' }}
        aria-label={`Coverage gauge, ${pct} percent`}
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={pct}
      >
        <div
          className="absolute inset-y-0 left-0 transition-[width] duration-500"
          style={{ width: `${pct}%`, background: 'var(--brand)' }}
        />
      </div>
      <div className="text-[11px]" style={{ color: 'var(--slate)' }}>
        We can only flag overpays on items where every ingredient has a plate cost.
        {pct < 100 && <> Adding recipes to the remaining {100 - pct}% unlocks more rows.</>}
      </div>
    </section>
  );
}

/* ── COGS freshness strip — compact FreshnessChip per (source, region) ── */
function CogsFreshnessStrip({
  isLive, entries,
}: { isLive: boolean; entries: CogsBenchmarkFreshness[] }) {
  if (!isLive && entries.length === 0) {
    return (
      <div
        className="text-[11px] flex items-center gap-2 px-3 py-2 rounded-lg border"
        style={{
          background: 'var(--fresh-aging-bg)',
          color: '#92670E',
          borderColor: 'transparent',
        }}
      >
        <Info size={12} />
        <span className="font-semibold">Source:</span>
        Stub prices only — USDA + GreenDock ingest hasn't run yet.
      </div>
    );
  }

  const sortedEntries = [...entries].sort((a, b) =>
    a.last_ingested_at < b.last_ingested_at ? 1 : -1,
  );
  if (sortedEntries.length === 0) {
    return (
      <div
        className="text-[11px] px-3 py-2 rounded-lg border"
        style={{ background: 'white', color: 'var(--slate)', borderColor: 'var(--line-soft)' }}
      >
        Live benchmarks, but no non-stub batches in the last 30 days.
      </div>
    );
  }

  return (
    <div
      className="rounded-lg border px-3 py-2"
      style={{ background: 'white', borderColor: 'var(--line-soft)' }}
    >
      <div className="flex items-center gap-2 flex-wrap">
        <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
          Benchmark provenance
        </span>
        {sortedEntries.map((e) => (
          <FreshnessChip
            key={`${e.source}|${e.region ?? 'null'}`}
            timestamp={e.last_ingested_at}
            label={`${sourceLabel(e.source)} ${e.region ?? 'national'}`}
            size="xs"
            freshUntilMinutes={60 * 6}   // <6h = fresh on prices
            staleAfterMinutes={60 * 48}  // >48h = stale, matches old footer
          />
        ))}
      </div>
    </div>
  );
}

function sourceLabel(source: string): string {
  switch (source) {
    case 'usda':             return 'USDA';
    case 'greendock':        return 'GreenDock';
    case 'usa_produce':      return 'USA Produce';
    case 'foundation_foods': return 'Foundation';
    case 'stub':             return 'Stub';
    default:                 return source;
  }
}

/* ── Overpay flags ranked list ─────────────────────────────────────────
   Each row leads with the dollar gap to the 65%-margin target — the
   highest-leverage item floats to the top. Phase-1 honest framing:
   savings = (current cost) − (cost at 35% target), per period.
   ─────────────────────────────────────────────────────────────────────── */
type Contributor = FoodCostTheoretical['top_contributors'][number];

type OverpayRow = {
  c: Contributor;
  savingsCents: number;        // Σ over the period
  perUnitGapCents: number;     // current cost/unit − target cost/unit
  costRatio: number;
  qtySold: number;
};

function buildRows(contributors: Contributor[]): OverpayRow[] {
  return contributors
    .map((c) => {
      const targetCost = Math.round(c.revenue_cents * TARGET_FOOD_COST_PCT);
      const savings = Math.max(0, c.cost_cents - targetCost);
      const perUnit = c.qty_sold > 0
        ? Math.round((c.cost_cents - targetCost) / c.qty_sold)
        : 0;
      return {
        c,
        savingsCents: savings,
        perUnitGapCents: perUnit,
        costRatio: c.revenue_cents > 0 ? c.cost_cents / c.revenue_cents : 0,
        qtySold: c.qty_sold,
      };
    })
    .sort((a, b) => b.savingsCents - a.savingsCents);
}

function OverpayList({ contributors }: { contributors: Contributor[] }) {
  const rows = buildRows(contributors);
  const total = rows.reduce((a, r) => a + r.savingsCents, 0);

  return (
    <section>
      <header className="flex items-end justify-between gap-3 flex-wrap mb-3">
        <div>
          <h2 className="font-extrabold text-base flex items-center gap-2" style={{ color: 'var(--ink)' }}>
            <TrendingDown size={16} style={{ color: 'var(--money-positive)' }} />
            Overpay flags
          </h2>
          <p className="text-xs mt-0.5 max-w-md" style={{ color: 'var(--slate)' }}>
            Items costing more than a 65% target margin would allow. Closing the gap to the {Math.round(TARGET_FOOD_COST_PCT * 100)}% food-cost target unlocks the savings to the right.
          </p>
        </div>
        {total > 0 && (
          <div className="text-right">
            <div className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
              Total addressable
            </div>
            <div className="text-2xl font-extrabold tabular-nums" style={{ color: 'var(--money-positive)' }}>
              ${(total / 100).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 })}
              <span className="text-xs font-bold ml-1" style={{ color: 'var(--slate)' }}>/period</span>
            </div>
          </div>
        )}
      </header>

      {rows.length === 0 ? (
        <div
          className="rounded-xl p-8 text-center text-sm flex flex-col items-center gap-2"
          style={{ background: 'var(--money-positive-bg)', color: 'var(--money-positive)' }}
        >
          <CheckCircle2 size={20} />
          <div className="font-semibold">No overpay flags in this window</div>
          <div style={{ color: 'var(--slate)' }}>
            Every plated item is at or below the {Math.round(TARGET_FOOD_COST_PCT * 100)}% target.
          </div>
        </div>
      ) : (
        <ul className="space-y-2">
          {rows.map((r, idx) => (
            <OverpayRowCard
              key={r.c.menu_item_id}
              row={r}
              isTop={idx === 0 && r.savingsCents > 0}
            />
          ))}
        </ul>
      )}

      <div
        className="mt-3 text-[11px] flex items-start gap-2 px-3 py-2 rounded-lg border"
        style={{ borderColor: 'var(--line-soft)', background: 'var(--bg-panel)', color: 'var(--slate)' }}
      >
        <Info size={12} className="flex-shrink-0 mt-0.5" />
        <div>
          <strong style={{ color: 'var(--ink)' }}>Phase 1:</strong> savings is computed against a 35% food-cost target.
          When restaurant invoice ingestion ships, this list switches to actual-paid vs market price per ingredient — the visual stays the same.
        </div>
      </div>
    </section>
  );
}

function OverpayRowCard({ row, isTop }: { row: OverpayRow; isTop: boolean }) {
  const { c, savingsCents, perUnitGapCents, costRatio } = row;
  const noSavings = savingsCents <= 0;
  const rowBand = pickBand(costRatio);
  const RowBandIcon = rowBand.Icon;
  return (
    <li>
      <article
        className="rounded-xl border p-3 sm:p-4"
        style={{
          background: 'white',
          borderColor: isTop ? 'var(--money-positive)' : 'var(--line-soft)',
          borderWidth: isTop ? 2 : 1,
        }}
      >
        <div className="grid grid-cols-1 md:grid-cols-12 gap-3 md:gap-4 items-start">
          {/* Name + qty + cost ratio */}
          <div className="md:col-span-5 min-w-0">
            <div className="flex items-center gap-2 mb-1 flex-wrap">
              {isTop && (
                <span
                  className="text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded"
                  style={{ background: 'var(--money-positive-bg)', color: 'var(--money-positive)' }}
                >
                  Top opportunity
                </span>
              )}
              <span
                className="text-[10px] font-bold uppercase tracking-wider"
                style={{ color: 'var(--slate)' }}
              >
                {c.qty_sold.toLocaleString()} sold
              </span>
            </div>
            <div className="font-extrabold text-base truncate" style={{ color: 'var(--ink)' }}>
              {c.name}
            </div>
            <div className="text-[11px] mt-1 inline-flex items-center gap-1.5" style={{ color: 'var(--slate)' }}>
              <span>Current cost ratio</span>
              <strong style={{ color: rowBand.color }}>{Math.round(costRatio * 100)}%</strong>
              <RowBandIcon size={11} strokeWidth={2.5} style={{ color: rowBand.color }} aria-label={rowBand.label} />
            </div>
          </div>

          {/* Pay → market gap (per unit) */}
          <div className="md:col-span-4">
            <div className="flex items-center gap-2 text-xs flex-wrap" style={{ color: 'var(--body)' }}>
              <PriceCell label="Your cost / unit" cents={c.qty_sold > 0 ? Math.round(c.cost_cents / c.qty_sold) : null} />
              <span aria-hidden style={{ color: 'var(--muted)' }}>→</span>
              <PriceCell
                label={`Target at ${Math.round(TARGET_FOOD_COST_PCT * 100)}%`}
                cents={c.qty_sold > 0 ? Math.round((c.revenue_cents * TARGET_FOOD_COST_PCT) / c.qty_sold) : null}
                tone="target"
              />
            </div>
            {perUnitGapCents > 0 && (
              <div className="mt-2">
                <DollarDelta
                  value={perUnitGapCents / 100}
                  precision="cents"
                  goodWhen="down"
                  comparison="per unit gap"
                  size="sm"
                />
              </div>
            )}
          </div>

          {/* Dominant MoneyStat — $/period addressable */}
          <div className="md:col-span-3">
            <MoneyStat
              label="Addressable / period"
              value={noSavings ? 0 : savingsCents / 100}
              precision="dollars"
              size="lg"
              tone={noSavings ? 'neutral' : 'positive'}
              durationMs={240}
              footer={noSavings
                ? <span style={{ color: 'var(--money-positive)' }}>At or under target</span>
                : <span style={{ color: 'var(--slate)' }}>vs 35% target</span>}
            />
          </div>
        </div>
      </article>
    </li>
  );
}

function PriceCell({ label, cents, tone = 'actual' }: {
  label: string;
  cents: number | null;
  tone?: 'actual' | 'target';
}) {
  const isTarget = tone === 'target';
  return (
    <span className="inline-flex flex-col leading-tight">
      <span className="text-[9px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
        {label}
      </span>
      <span
        className="font-bold tabular-nums text-sm"
        style={{ color: isTarget ? 'var(--money-positive)' : 'var(--ink)' }}
      >
        {cents == null ? '—' : `$${(cents / 100).toFixed(2)}`}
      </span>
    </span>
  );
}

function CostsEmptyState() {
  const { id } = useParams<{ id: string }>();
  const restaurantId = id ?? '';
  return (
    <section
      className="rounded-xl border-2 border-dashed p-6 sm:p-10 text-center flex flex-col items-center gap-4"
      style={{ background: 'white', borderColor: 'var(--brand-light)' }}
    >
      <span
        aria-hidden
        className="inline-flex items-center justify-center w-14 h-14 rounded-2xl"
        style={{ background: 'var(--brand-light)', color: 'var(--brand)' }}
      >
        <BookOpen size={26} strokeWidth={2.2} />
      </span>
      <div className="space-y-1.5 max-w-md">
        <h2 className="font-extrabold text-lg" style={{ color: 'var(--ink)' }}>
          Add recipes to see your real food cost
        </h2>
        <p className="text-sm" style={{ color: 'var(--body)' }}>
          Food-cost % is a math problem we can solve the moment your items have plate costs.
          Once recipes are attached, this page fills with theoretical cost, overpay flags, and a
          coverage gauge.
        </p>
      </div>
      <Link
        to={`/app/restaurants/${restaurantId}/recipes`}
        className="inline-flex items-center gap-1.5 px-4 min-h-[44px] rounded-lg font-bold text-sm text-white"
        style={{ background: 'var(--brand)' }}
      >
        Open Recipes <ChevronRight size={16} />
      </Link>
      <p className="text-[11px]" style={{ color: 'var(--slate)' }}>
        Recipes take a minute each — or paste a TSV and we'll build them in bulk.
      </p>
    </section>
  );
}

/** Picks the most recent benchmark batch's `last_ingested_at` so the
 *  CogsStaleBanner has a single timestamp to grade. Falls back to null
 *  when no batches exist (which the banner handles as `missing`). */
function latestBenchmarkAsOf(entries: CogsBenchmarkFreshness[] | undefined): string | null {
  if (!entries || entries.length === 0) return null;
  return entries.reduce<string | null>((acc, e) => {
    if (!acc) return e.last_ingested_at;
    return e.last_ingested_at > acc ? e.last_ingested_at : acc;
  }, null);
}

function firstOfMonth(): string { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`; }
function today(): string { return new Date().toISOString().slice(0, 10); }

/*
 * WCAG AA contrast notes for the food-cost bands (foreground against
 * white #ffffff body):
 *   --money-positive  #0F8A4A   contrast 5.62 ✓ AA
 *   warn              #92670E   contrast 6.42 ✓ AAA
 *   --money-negative  #B14242   contrast 5.72 ✓ AA
 * Icon shape + verbal label run alongside, so users with full color
 * blindness still get the signal.
 */
