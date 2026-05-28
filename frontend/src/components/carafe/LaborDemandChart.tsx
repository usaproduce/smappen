import { useMemo, useState } from 'react';
import { AlertTriangle, AlertOctagon } from 'lucide-react';
import type { LaborAnalysis, LaborAnalysisHour } from '../../api/restaurants';

/**
 * Labor vs demand by hour-of-day.
 *
 * Visual: a 24-hour horizontal chart with the revenue (or covers) curve
 * drawn as a soft area in --brand, and the labor-cost curve overlaid as
 * a line. Hours flagged in `understaffed` / `overstaffed` get a tinted
 * background band and a dollar annotation above the chart.
 *
 * Aggregation: every entry in `hours` is bucketed by hour-of-day (0..23)
 * across the analysis window. Over-/under-staffed flags come pre-rolled
 * from the backend per (date, hour) — we group those by hour-of-day to
 * total their dollar context.
 *
 * Mobile graceful degradation: under 480px viewport the SVG keeps its
 * aspect ratio and the chart stays scrollable horizontally so the
 * 24-hour x-axis isn't crushed. A stacked summary card lives above the
 * chart so the operator can read the highest-dollar windows without
 * even looking at the SVG.
 */

type Metric = 'revenue' | 'covers';

type Bucket = {
  hour: number;
  revenue_cents: number;
  covers: number;
  labor_cost_cents: number;
  /** Dollar waste / upside vs target labor ratio for this hour (sum across days). */
  overWasteCents: number;
  underUpsideCents: number;
  /** True if any day in the window has this hour flagged. */
  isOver: boolean;
  isUnder: boolean;
};

/** Target labor as a fraction of revenue — used to compute the "waste" annotation. */
const TARGET_LABOR_PCT = 0.30;

export default function LaborDemandChart({
  analysis,
  className,
}: { analysis: LaborAnalysis; className?: string }) {
  const [metric, setMetric] = useState<Metric>('revenue');

  const buckets = useMemo(() => aggregate(analysis), [analysis]);
  const totalOver = buckets.reduce((a, b) => a + b.overWasteCents, 0);
  const totalUnder = buckets.reduce((a, b) => a + b.underUpsideCents, 0);

  return (
    <section
      className={'rounded-xl border p-3 sm:p-5 flex flex-col gap-4 ' + (className ?? '')}
      style={{ background: 'white', borderColor: 'var(--line-soft)' }}
    >
      <Header
        metric={metric}
        onChangeMetric={setMetric}
        totalOver={totalOver}
        totalUnder={totalUnder}
      />

      <DegradedSummary buckets={buckets} />

      <div className="overflow-x-auto -mx-3 sm:mx-0 scroll-x">
        <div className="px-3 sm:px-0" style={{ minWidth: 540 }}>
          <Chart buckets={buckets} metric={metric} />
        </div>
      </div>

      <Legend />
    </section>
  );
}

/* ── Aggregation ────────────────────────────────────────────────────── */
function aggregate(a: LaborAnalysis): Bucket[] {
  const idx: Record<number, Bucket> = {};
  for (let h = 0; h < 24; h++) {
    idx[h] = {
      hour: h,
      revenue_cents: 0,
      covers: 0,
      labor_cost_cents: 0,
      overWasteCents: 0,
      underUpsideCents: 0,
      isOver: false,
      isUnder: false,
    };
  }
  for (const h of a.hours) {
    const b = idx[h.hour];
    if (!b) continue;
    b.revenue_cents += h.revenue_cents;
    b.covers += h.covers;
    b.labor_cost_cents += h.labor_cost_cents;
  }
  // Flag rows: each entry is one (date, hour). Bucket by hour-of-day and
  // sum the dollar context.
  for (const row of a.overstaffed) {
    const b = idx[row.hour];
    if (!b) continue;
    b.isOver = true;
    b.overWasteCents += overstaffWaste(row);
  }
  for (const row of a.understaffed) {
    const b = idx[row.hour];
    if (!b) continue;
    b.isUnder = true;
    b.underUpsideCents += understaffUpside(row, a.median_rpc);
  }
  return Object.values(idx).sort((x, y) => x.hour - y.hour);
}

/** Overstaff waste = labor cost minus the labor cost a 30% target ratio
 *  would have implied at that hour's actual revenue. Floored at 0. */
function overstaffWaste(row: LaborAnalysisHour): number {
  const target = Math.round(row.revenue_cents * TARGET_LABOR_PCT);
  return Math.max(0, row.labor_cost_cents - target);
}

/** Understaff upside = covers × (median rpc − this-hour rpc). Floored at 0. */
function understaffUpside(row: LaborAnalysisHour, medianRpc: number): number {
  if (medianRpc <= 0 || row.covers <= 0) return 0;
  const gap = medianRpc - row.revenue_per_cover;
  return Math.max(0, Math.round(gap * row.covers));
}

/* ── Header ─────────────────────────────────────────────────────────── */
function Header({
  metric, onChangeMetric, totalOver, totalUnder,
}: {
  metric: Metric;
  onChangeMetric: (m: Metric) => void;
  totalOver: number;
  totalUnder: number;
}) {
  return (
    <div className="flex items-baseline justify-between gap-3 flex-wrap">
      <div className="min-w-0">
        <h2 className="font-extrabold text-base" style={{ color: 'var(--ink)' }}>
          Labor vs demand by hour
        </h2>
        <p className="text-xs mt-0.5 max-w-md" style={{ color: 'var(--slate)' }}>
          Demand area + staffing line, hour-of-day across the period. Shaded bands flag windows that ran over- or under-staffed against the {Math.round(TARGET_LABOR_PCT * 100)}% labor target.
        </p>
      </div>
      <div className="flex items-center gap-2 flex-wrap">
        {(totalOver > 0 || totalUnder > 0) && (
          <div className="flex items-center gap-2 text-[11px] font-semibold">
            {totalOver > 0 && (
              <span
                className="inline-flex items-center gap-1 px-2 py-1 rounded-full"
                style={{ background: 'var(--money-negative-bg)', color: 'var(--money-negative)' }}
              >
                <AlertOctagon size={12} aria-hidden />
                {formatUsd(totalOver)} over
              </span>
            )}
            {totalUnder > 0 && (
              <span
                className="inline-flex items-center gap-1 px-2 py-1 rounded-full"
                style={{ background: 'var(--fresh-aging-bg)', color: '#92670E' }}
              >
                <AlertTriangle size={12} aria-hidden />
                {formatUsd(totalUnder)} addressable
              </span>
            )}
          </div>
        )}
        <div
          role="tablist"
          aria-label="Demand metric"
          className="inline-flex items-center rounded-lg p-0.5 flex-shrink-0"
          style={{ background: 'var(--bg-panel)' }}
        >
          {(['revenue', 'covers'] as Metric[]).map((m) => (
            <button
              key={m}
              type="button"
              role="tab"
              aria-selected={metric === m}
              onClick={() => onChangeMetric(m)}
              className="h-9 px-3 text-xs font-bold rounded-md transition-colors"
              style={{
                background: metric === m ? 'white' : 'transparent',
                color: metric === m ? 'var(--ink)' : 'var(--slate)',
                boxShadow: metric === m ? '0 1px 2px rgba(0,0,0,0.05)' : 'none',
              }}
            >
              {m === 'revenue' ? 'Revenue' : 'Covers'}
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}

/* ── Degraded summary — readable on phones without the SVG ───────────── */
function DegradedSummary({ buckets }: { buckets: Bucket[] }) {
  const topOver = [...buckets]
    .filter((b) => b.overWasteCents > 0)
    .sort((a, b) => b.overWasteCents - a.overWasteCents)
    .slice(0, 2);
  const topUnder = [...buckets]
    .filter((b) => b.underUpsideCents > 0)
    .sort((a, b) => b.underUpsideCents - a.underUpsideCents)
    .slice(0, 2);

  if (topOver.length === 0 && topUnder.length === 0) {
    return (
      <div
        className="rounded-lg px-3 py-2 text-xs flex items-center gap-2"
        style={{ background: 'var(--money-positive-bg)', color: 'var(--money-positive)' }}
      >
        Every hour with sales kept labor inside the target ratio.
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
      {topOver.map((b) => (
        <SummaryRow
          key={'o-' + b.hour}
          tint="negative"
          title={`Overstaffed at ${formatHour(b.hour)}`}
          dollar={`${formatUsd(b.overWasteCents)} over`}
          detail={`${formatUsd(b.labor_cost_cents)} labor on ${formatUsd(b.revenue_cents)} revenue`}
        />
      ))}
      {topUnder.map((b) => (
        <SummaryRow
          key={'u-' + b.hour}
          tint="warn"
          title={`Understaffed at ${formatHour(b.hour)}`}
          dollar={`${formatUsd(b.underUpsideCents)} addressable`}
          detail={`${b.covers.toLocaleString()} covers seen — pace fell below median`}
        />
      ))}
    </div>
  );
}

function SummaryRow({ tint, title, dollar, detail }: {
  tint: 'negative' | 'warn';
  title: string;
  dollar: string;
  detail: string;
}) {
  const bg = tint === 'negative' ? 'var(--money-negative-bg)' : 'var(--fresh-aging-bg)';
  const fg = tint === 'negative' ? 'var(--money-negative)'    : '#92670E';
  return (
    <div className="rounded-lg p-2.5" style={{ background: bg, color: fg }}>
      <div className="text-[10px] font-bold uppercase tracking-wider opacity-80">{title}</div>
      <div className="font-extrabold text-sm tabular-nums">{dollar}</div>
      <div className="text-[11px] opacity-80 mt-0.5">{detail}</div>
    </div>
  );
}

/* ── The SVG chart ──────────────────────────────────────────────────── */
function Chart({ buckets, metric }: { buckets: Bucket[]; metric: Metric }) {
  const W = 720, H = 260;
  const ML = 48, MR = 16, MT = 28, MB = 36;
  const innerW = W - ML - MR;
  const innerH = H - MT - MB;
  const stepX = innerW / 24;

  const demand = metric === 'revenue'
    ? buckets.map((b) => b.revenue_cents / 100)
    : buckets.map((b) => b.covers);
  const labor  = buckets.map((b) => b.labor_cost_cents / 100);

  const demandMax = Math.max(1, ...demand);
  const laborMax  = Math.max(1, ...labor);

  function dx(i: number) { return ML + (i + 0.5) * stepX; }
  function dy(v: number, max: number) { return MT + innerH - (v / max) * innerH; }

  const demandPath = demand
    .map((v, i) => `${i === 0 ? 'M' : 'L'}${dx(i).toFixed(1)} ${dy(v, demandMax).toFixed(1)}`)
    .join(' ');
  const demandArea = `${demandPath} L ${dx(23).toFixed(1)} ${MT + innerH} L ${dx(0).toFixed(1)} ${MT + innerH} Z`;

  const laborPath = labor
    .map((v, i) => `${i === 0 ? 'M' : 'L'}${dx(i).toFixed(1)} ${dy(v, laborMax).toFixed(1)}`)
    .join(' ');

  // Find annotation slots so labels don't overlap. We rank by dollar value
  // and place annotations only for the top-N over/under bands.
  const overAnnos = buckets.filter((b) => b.overWasteCents > 0)
    .sort((a, b) => b.overWasteCents - a.overWasteCents).slice(0, 4);
  const underAnnos = buckets.filter((b) => b.underUpsideCents > 0)
    .sort((a, b) => b.underUpsideCents - a.underUpsideCents).slice(0, 3);

  return (
    <svg
      viewBox={`0 0 ${W} ${H}`}
      width="100%"
      role="img"
      aria-label="Labor vs demand by hour of day"
      style={{ display: 'block' }}
    >
      {/* Hour bands — over/under tints */}
      {buckets.map((b) => {
        if (!b.isOver && !b.isUnder) return null;
        const x = ML + b.hour * stepX;
        const fill = b.isOver ? 'var(--money-negative-bg)' : 'var(--fresh-aging-bg)';
        return (
          <rect
            key={'band-' + b.hour}
            x={x} y={MT}
            width={stepX} height={innerH}
            fill={fill}
            opacity={0.7}
          />
        );
      })}

      {/* Demand area + stroke */}
      <path d={demandArea} fill="var(--brand)" opacity={0.12} />
      <path d={demandPath} fill="none" stroke="var(--brand)" strokeWidth={2.25} strokeLinejoin="round" strokeLinecap="round" />

      {/* Labor line — darker stroke for legibility against the demand fill */}
      <path d={laborPath} fill="none" stroke="var(--ink)" strokeWidth={1.6} strokeLinejoin="round" strokeLinecap="round" strokeDasharray="0" />

      {/* Hour ticks (every 4 hours) */}
      {[0, 4, 8, 12, 16, 20].map((h) => (
        <text
          key={'xt-' + h}
          x={dx(h)} y={H - MB + 14}
          fontSize="10" fontWeight={600} fill="var(--slate)"
          textAnchor="middle"
        >
          {formatHourShort(h)}
        </text>
      ))}

      {/* Demand axis ticks (4) */}
      {[0.25, 0.5, 0.75, 1].map((t) => {
        const v = demandMax * t;
        return (
          <g key={'yt-' + t}>
            <line
              x1={ML} x2={W - MR}
              y1={dy(v, demandMax)} y2={dy(v, demandMax)}
              stroke="var(--line-soft)" strokeWidth={1}
            />
            <text
              x={ML - 6} y={dy(v, demandMax)}
              fontSize="9" fontWeight={600} fill="var(--slate)"
              textAnchor="end" dominantBaseline="middle"
            >
              {metric === 'revenue' ? `$${Math.round(v).toLocaleString()}` : Math.round(v).toLocaleString()}
            </text>
          </g>
        );
      })}

      {/* Axis frame */}
      <line x1={ML} y1={MT} x2={ML} y2={H - MB} stroke="var(--line-soft)" />
      <line x1={ML} y1={H - MB} x2={W - MR} y2={H - MB} stroke="var(--line-soft)" />

      {/* Y-axis labels */}
      <text x={ML} y={MT - 10} fontSize="9" fontWeight={700} fill="var(--slate)" style={{ textTransform: 'uppercase', letterSpacing: 0.5 }}>
        Demand · {metric === 'revenue' ? 'revenue' : 'covers'}
      </text>
      <text x={W - MR} y={MT - 10} fontSize="9" fontWeight={700} fill="var(--ink)" textAnchor="end" style={{ textTransform: 'uppercase', letterSpacing: 0.5 }}>
        Labor cost · line
      </text>

      {/* Annotations — over-staff labels above the band */}
      {overAnnos.map((b) => (
        <g key={'ao-' + b.hour}>
          <line
            x1={dx(b.hour)} x2={dx(b.hour)}
            y1={MT} y2={MT + 6}
            stroke="var(--money-negative)" strokeWidth={1.5}
          />
          <foreignObject x={dx(b.hour) - 40} y={MT - 22} width={80} height={20}>
            <div
              style={{
                fontSize: 9,
                fontWeight: 700,
                color: 'var(--money-negative)',
                background: 'var(--money-negative-bg)',
                border: '1px solid var(--money-negative)',
                borderRadius: 4,
                padding: '1px 4px',
                textAlign: 'center',
                whiteSpace: 'nowrap',
              }}
            >
              −{formatUsd(b.overWasteCents)} {formatHourShort(b.hour)}
            </div>
          </foreignObject>
        </g>
      ))}
      {/* Under-staff labels just below the demand peak — these are upside,
          not waste, so they sit lower in the chart and read positive. */}
      {underAnnos.map((b) => {
        const peakY = dy(metric === 'revenue' ? b.revenue_cents / 100 : b.covers, demandMax);
        return (
          <g key={'au-' + b.hour}>
            <line
              x1={dx(b.hour)} x2={dx(b.hour)}
              y1={peakY - 4} y2={peakY - 14}
              stroke="#92670E" strokeWidth={1.5}
            />
            <foreignObject x={dx(b.hour) - 44} y={peakY - 34} width={88} height={20}>
              <div
                style={{
                  fontSize: 9,
                  fontWeight: 700,
                  color: '#92670E',
                  background: 'var(--fresh-aging-bg)',
                  border: '1px solid #92670E',
                  borderRadius: 4,
                  padding: '1px 4px',
                  textAlign: 'center',
                  whiteSpace: 'nowrap',
                }}
              >
                +{formatUsd(b.underUpsideCents)} {formatHourShort(b.hour)}
              </div>
            </foreignObject>
          </g>
        );
      })}
    </svg>
  );
}

/* ── Legend ─────────────────────────────────────────────────────────── */
function Legend() {
  return (
    <ul
      className="flex items-center gap-3 flex-wrap text-[11px] font-semibold"
      aria-label="Chart legend"
    >
      <li className="inline-flex items-center gap-1.5">
        <span aria-hidden className="inline-block w-3 h-3 rounded-sm" style={{ background: 'var(--brand)', opacity: 0.35 }} />
        <span style={{ color: 'var(--ink)' }}>Demand</span>
      </li>
      <li className="inline-flex items-center gap-1.5">
        <span aria-hidden className="inline-block w-4 h-0.5" style={{ background: 'var(--ink)' }} />
        <span style={{ color: 'var(--ink)' }}>Labor cost</span>
      </li>
      <li className="inline-flex items-center gap-1.5">
        <span aria-hidden className="inline-block w-3 h-3 rounded-sm" style={{ background: 'var(--money-negative-bg)' }} />
        <span style={{ color: 'var(--ink)' }}>Overstaffed</span>
      </li>
      <li className="inline-flex items-center gap-1.5">
        <span aria-hidden className="inline-block w-3 h-3 rounded-sm" style={{ background: 'var(--fresh-aging-bg)' }} />
        <span style={{ color: 'var(--ink)' }}>Understaffed</span>
      </li>
    </ul>
  );
}

/* ── helpers ────────────────────────────────────────────────────────── */
function formatUsd(cents: number): string {
  return '$' + Math.round(cents / 100).toLocaleString();
}
function formatHour(h: number): string {
  if (h === 0) return '12 AM';
  if (h === 12) return 'noon';
  return h < 12 ? `${h} AM` : `${h - 12} PM`;
}
function formatHourShort(h: number): string {
  if (h === 0) return '12a';
  if (h === 12) return '12p';
  return h < 12 ? `${h}a` : `${h - 12}p`;
}
