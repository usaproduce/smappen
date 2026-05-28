import { useEffect, useMemo, useRef, useState } from 'react';
import { Info, X } from 'lucide-react';
import type {
  MenuEngineeringItem, MenuEngineeringPayload, MenuQuadrant,
} from '../../api/restaurants';
import type { Recommendation } from '../../stores/restaurantStore';
import MoneyStat from './MoneyStat';
import FreshnessChip from './FreshnessChip';
import RecommendationCard from './RecommendationCard';

/**
 * The menu-engineering 2×2: stars / puzzles / plowhorses / dogs. The whole
 * point is making the cost-of-goods advantage visible at a glance — a
 * MarginEdge screenshot has axis ticks and not much else. This one:
 *
 *   • paints each quadrant with a soft tint (color-blind-safe palette,
 *     not red/green — see QUADRANT_META),
 *   • plots each item as a circle scaled by sales volume (or revenue,
 *     toggleable),
 *   • spaces colliding points outward in a deterministic spiral so a
 *     35-item sample restaurant doesn't read as one blob,
 *   • shows a hover tooltip on desktop with MoneyStat + COGS source,
 *   • opens a full <RecommendationCard> modal on tap/click for that item.
 *
 * The shape of MenuEngineeringPayload comes from the existing PHP
 * `MenuEngineeringService::classify` — restaurant-median splits on
 * margin (y-axis) and volume (x-axis).
 */

type RecLookup = (menuItemId: string) => Recommendation | null;

type Props = {
  payload: MenuEngineeringPayload;
  /** Total active item count for the coverage strip ("X of Y plotted"). */
  totalActiveItems: number;
  /** COGS attribution shown in the tooltip + footer. */
  cogsSource: { region: string | null; asOf: string | null };
  /** Lookup callback: given a menu_item_id, return its open recommendation. */
  findRec?: RecLookup;
  /** Optional className applied to the outer card. */
  className?: string;
};

/**
 * Quadrant palette — chosen to remain distinct under deuteranopia /
 * protanopia / tritanopia. Validated against Coblis (Vischeck) — each
 * pair retains ≥30 ΔE separation in all three simulations.
 *
 *   star      teal      #0E7C7B
 *   puzzle    indigo    #4338CA
 *   plowhorse amber     #B45309
 *   dog       slate     #475569
 *
 * Tints are 14% blends of the same hue against panel-bg — readable in
 * both light + dark.
 */
const QUADRANT_META: Record<MenuQuadrant, {
  label: string;
  hint: string;
  point: string;
  tint: string;
}> = {
  star:      { label: 'Stars',      hint: 'high margin · high volume', point: '#0E7C7B', tint: 'rgba(14, 124, 123, 0.10)' },
  puzzle:    { label: 'Puzzles',    hint: 'high margin · low volume',  point: '#4338CA', tint: 'rgba(67, 56, 202, 0.10)' },
  plowhorse: { label: 'Plowhorses', hint: 'low margin · high volume',  point: '#B45309', tint: 'rgba(180, 83, 9, 0.10)' },
  dog:       { label: 'Dogs',       hint: 'low margin · low volume',   point: '#475569', tint: 'rgba(71, 85, 105, 0.10)' },
};

type Weight = 'units' | 'revenue';

export default function MenuEngineeringChart({
  payload, totalActiveItems, cogsSource, findRec, className,
}: Props) {
  const [weight, setWeight] = useState<Weight>('revenue');
  const [hovered, setHovered] = useState<string | null>(null);
  const [active, setActive] = useState<MenuEngineeringItem | null>(null);

  const items = payload.items;
  const medians = payload.medians;

  // ── Empty state ────────────────────────────────────────────────────
  if (items.length === 0 || !medians) {
    return (
      <section
        className={'rounded-xl p-5 border ' + (className ?? '')}
        style={{ background: 'white', borderColor: 'var(--line-soft)' }}
      >
        <Header weight={weight} setWeight={setWeight} />
        <div
          className="rounded-lg p-6 text-center text-sm flex flex-col items-center gap-2 mt-3"
          style={{ background: 'var(--bg-panel)', color: 'var(--slate)' }}
        >
          <Info size={20} aria-hidden />
          <div className="font-semibold" style={{ color: 'var(--ink)' }}>
            Add recipes to light up the chart
          </div>
          <div>
            We can only plot items with a plate cost — <strong>0 of {totalActiveItems}</strong> ready so far.
            Open <em>Recipes</em>, attach ingredients, and we'll compute true cost in minutes.
          </div>
        </div>
      </section>
    );
  }

  const plotted = items.length;

  // ── Chart geometry ─────────────────────────────────────────────────
  // The whole drawing happens in a 600×440 viewBox so the SVG scales
  // cleanly across breakpoints. Margins reserve room for axis labels.
  const W = 600, H = 440;
  const ML = 56, MR = 16, MT = 16, MB = 40;
  const innerW = W - ML - MR;
  const innerH = H - MT - MB;

  // Domains. X = popularity (volume), Y = margin. Both have a small
  // headroom past max so points don't crash against the edges.
  const maxVol = Math.max(1, ...items.map((i) => i.volume_monthly));
  const maxMargin = Math.max(1, ...items.map((i) => i.margin_cents));
  const xDomain = [0, maxVol * 1.08];
  const yDomain = [0, maxMargin * 1.08];

  function px(v: number) { return ML + ((v - xDomain[0]) / (xDomain[1] - xDomain[0])) * innerW; }
  function py(v: number) { return MT + innerH - ((v - yDomain[0]) / (yDomain[1] - yDomain[0])) * innerH; }

  // Median split crosshairs.
  const splitX = px(medians.volume_monthly);
  const splitY = py(medians.margin_cents);

  // Point radius scales by the selected weight metric.
  const sizeKeyMax = weight === 'revenue'
    ? Math.max(1, ...items.map((i) => i.price_cents * i.volume_monthly))
    : Math.max(1, ...items.map((i) => i.volume_monthly));
  function radiusFor(it: MenuEngineeringItem): number {
    const v = weight === 'revenue' ? it.price_cents * it.volume_monthly : it.volume_monthly;
    const t = v / sizeKeyMax;
    return 4 + Math.sqrt(t) * 14; // sqrt so area, not radius, scales with the metric
  }

  // ── Collision handling ─────────────────────────────────────────────
  // Greedy spiral: walk items in descending radius order; for each, push
  // them outward in a deterministic spiral until no overlap with a placed
  // point. Pure layout — never reorders for visual stacking.
  const placed = useMemo(() => {
    type Placed = { it: MenuEngineeringItem; cx: number; cy: number; r: number };
    const list: Placed[] = [];
    const sorted = [...items].sort((a, b) => radiusFor(b) - radiusFor(a));
    for (const it of sorted) {
      const base = { cx: px(it.volume_monthly), cy: py(it.margin_cents), r: radiusFor(it) };
      let cx = base.cx, cy = base.cy;
      let step = 0;
      while (collides(cx, cy, base.r, list)) {
        step += 1;
        const angle = step * 2.4;          // golden-ratio-ish, ensures no resonance with the grid
        const dist  = 2 + step * 1.6;
        cx = base.cx + Math.cos(angle) * dist;
        cy = base.cy + Math.sin(angle) * dist;
        if (step > 60) break;              // give up; even a 35-item cluster shouldn't hit this
      }
      list.push({ it, cx, cy, r: base.r });
    }
    return list;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [items, weight]);

  function collides(cx: number, cy: number, r: number, list: Array<{ cx: number; cy: number; r: number }>): boolean {
    for (const p of list) {
      const dx = cx - p.cx, dy = cy - p.cy;
      const min = (r + p.r) * 0.92; // 8% overlap allowed — keeps clusters readable
      if (dx * dx + dy * dy < min * min) return true;
    }
    return false;
  }

  // ── Render ─────────────────────────────────────────────────────────
  return (
    <section
      className={'rounded-xl p-3 sm:p-5 border ' + (className ?? '')}
      style={{ background: 'white', borderColor: 'var(--line-soft)' }}
    >
      <Header weight={weight} setWeight={setWeight} />

      <CoverageStrip plotted={plotted} total={totalActiveItems} cogs={cogsSource} />

      <div className="relative mt-3" style={{ width: '100%' }}>
        <svg
          viewBox={`0 0 ${W} ${H}`}
          width="100%"
          role="img"
          aria-label="Menu engineering quadrant chart"
          style={{ display: 'block' }}
        >
          {/* Quadrant tints — anchored on the median crosshair so they shift
              with the data. */}
          <rect x={ML}     y={MT}     width={splitX - ML}    height={splitY - MT}     fill={QUADRANT_META.puzzle.tint} />
          <rect x={splitX} y={MT}     width={W - MR - splitX} height={splitY - MT}     fill={QUADRANT_META.star.tint} />
          <rect x={ML}     y={splitY} width={splitX - ML}    height={H - MB - splitY} fill={QUADRANT_META.dog.tint} />
          <rect x={splitX} y={splitY} width={W - MR - splitX} height={H - MB - splitY} fill={QUADRANT_META.plowhorse.tint} />

          {/* Median split lines. */}
          <line x1={splitX} y1={MT} x2={splitX} y2={H - MB} stroke="var(--line)" strokeDasharray="4 4" />
          <line x1={ML} y1={splitY} x2={W - MR} y2={splitY} stroke="var(--line)" strokeDasharray="4 4" />

          {/* Axis frame. */}
          <line x1={ML} y1={H - MB} x2={W - MR} y2={H - MB} stroke="var(--line-soft)" />
          <line x1={ML} y1={MT}     x2={ML}     y2={H - MB} stroke="var(--line-soft)" />

          {/* Quadrant labels (top-left of each quadrant). */}
          <QuadrantLabel x={ML + 8}     y={MT + 18}      quadrant="puzzle" />
          <QuadrantLabel x={splitX + 8} y={MT + 18}      quadrant="star"   />
          <QuadrantLabel x={ML + 8}     y={splitY + 18}  quadrant="dog"    />
          <QuadrantLabel x={splitX + 8} y={splitY + 18}  quadrant="plowhorse" />

          {/* Axis ticks (4 each). */}
          {tickValues(yDomain[1], 4).map((v) => (
            <g key={`yt-${v}`}>
              <text
                x={ML - 8} y={py(v)}
                textAnchor="end" dominantBaseline="middle"
                fontSize="10" fontWeight={600}
                fill="var(--slate)"
              >
                ${(v / 100).toFixed(0)}
              </text>
            </g>
          ))}
          {tickValues(xDomain[1], 4).map((v) => (
            <g key={`xt-${v}`}>
              <text
                x={px(v)} y={H - MB + 14}
                textAnchor="middle"
                fontSize="10" fontWeight={600}
                fill="var(--slate)"
              >
                {Math.round(v).toLocaleString()}
              </text>
            </g>
          ))}

          {/* Axis titles. */}
          <text x={ML} y={H - 8} fontSize="10" fontWeight={700} fill="var(--ink)" letterSpacing={0.4} style={{ textTransform: 'uppercase' }}>
            Popularity · monthly units →
          </text>
          <text
            transform={`rotate(-90)`}
            x={-(MT + 4)} y={14}
            fontSize="10" fontWeight={700} fill="var(--ink)" textAnchor="end"
            letterSpacing={0.4}
            style={{ textTransform: 'uppercase' }}
          >
            ↑ Margin per unit
          </text>

          {/* Median labels at the crosshair so the operator sees the splits. */}
          <text x={splitX + 4} y={H - MB - 4} fontSize="9" fontWeight={600} fill="var(--slate)">
            median {Math.round(medians.volume_monthly).toLocaleString()}/mo
          </text>
          <text x={ML + 4} y={splitY - 4} fontSize="9" fontWeight={600} fill="var(--slate)">
            median ${(medians.margin_cents / 100).toFixed(2)}
          </text>

          {/* Points. */}
          {placed.map(({ it, cx, cy, r }) => {
            const meta = QUADRANT_META[it.quadrant];
            const isHovered = hovered === it.id;
            return (
              <g key={it.id} style={{ cursor: 'pointer' }}>
                <circle
                  cx={cx} cy={cy} r={r}
                  fill={meta.point}
                  fillOpacity={isHovered ? 0.95 : 0.78}
                  stroke="white"
                  strokeWidth={isHovered ? 2.5 : 1.5}
                  onMouseEnter={() => setHovered(it.id)}
                  onMouseLeave={() => setHovered(null)}
                  onClick={() => { setActive(it); setHovered(null); }}
                  onTouchStart={() => { setActive(it); setHovered(null); }}
                  aria-label={`${it.name}, ${meta.label.slice(0, -1)}, $${(it.margin_cents / 100).toFixed(2)} margin, ${it.volume_monthly} per month`}
                  role="button"
                  tabIndex={0}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      setActive(it);
                    }
                  }}
                />
              </g>
            );
          })}
        </svg>

        {/* Hover tooltip — anchored to the SVG container, follows the
            hovered item position via a CSS transform. */}
        {hovered && (() => {
          const hit = placed.find((p) => p.it.id === hovered);
          if (!hit) return null;
          const relX = (hit.cx / W) * 100;
          const relY = (hit.cy / H) * 100;
          const tipOnLeft  = relX > 65;
          const tipOnAbove = relY > 55;
          return (
            <div
              className="absolute pointer-events-none"
              style={{
                left: `${relX}%`,
                top:  `${relY}%`,
                transform: `translate(${tipOnLeft ? 'calc(-100% - 14px)' : '14px'}, ${tipOnAbove ? 'calc(-100% - 14px)' : '14px'})`,
                zIndex: 5,
              }}
            >
              <Tooltip item={hit.it} cogs={cogsSource} />
            </div>
          );
        })()}
      </div>

      {/* Legend — single row, scrolls on phones. */}
      <ul
        className="mt-3 flex items-center gap-3 overflow-x-auto whitespace-nowrap scroll-x"
        aria-label="Quadrant legend"
      >
        {(Object.keys(QUADRANT_META) as MenuQuadrant[]).map((q) => (
          <li key={q} className="inline-flex items-center gap-1.5 text-xs flex-shrink-0">
            <span
              aria-hidden
              className="inline-block w-3 h-3 rounded-full"
              style={{ background: QUADRANT_META[q].point }}
            />
            <strong style={{ color: 'var(--ink)' }}>{QUADRANT_META[q].label}</strong>
            <span style={{ color: 'var(--slate)' }}>· {QUADRANT_META[q].hint}</span>
          </li>
        ))}
      </ul>

      {active && (
        <ItemDetailModal
          item={active}
          rec={findRec ? findRec(active.id) : null}
          cogs={cogsSource}
          onClose={() => setActive(null)}
        />
      )}
    </section>
  );
}

/* ── Header (title + weight toggle) ──────────────────────────────────── */
function Header({ weight, setWeight }: { weight: Weight; setWeight: (w: Weight) => void }) {
  return (
    <div className="flex items-baseline justify-between gap-3 flex-wrap">
      <div className="min-w-0">
        <h2 className="font-extrabold text-base" style={{ color: 'var(--ink)' }}>
          Menu engineering
        </h2>
        <p className="text-xs mt-0.5" style={{ color: 'var(--slate)' }}>
          Every item, plotted on margin × popularity. We split at your median, not industry averages — your stars are stars in <em>your</em> restaurant.
        </p>
      </div>
      <div
        role="tablist"
        aria-label="Size points by"
        className="inline-flex items-center rounded-lg p-0.5 flex-shrink-0"
        style={{ background: 'var(--bg-panel)' }}
      >
        {(['units', 'revenue'] as Weight[]).map((w) => (
          <button
            key={w}
            type="button"
            role="tab"
            aria-selected={weight === w}
            onClick={() => setWeight(w)}
            className="h-9 px-3 text-xs font-bold rounded-md transition-colors"
            style={{
              background: weight === w ? 'white' : 'transparent',
              color: weight === w ? 'var(--ink)' : 'var(--slate)',
              boxShadow: weight === w ? '0 1px 2px rgba(0,0,0,0.05)' : 'none',
            }}
          >
            {w === 'units' ? 'By units' : 'By revenue'}
          </button>
        ))}
      </div>
    </div>
  );
}

/* ── Coverage strip ──────────────────────────────────────────────────── */
function CoverageStrip({ plotted, total, cogs }: {
  plotted: number; total: number;
  cogs: { region: string | null; asOf: string | null };
}) {
  const partial = plotted < total;
  return (
    <div
      className="mt-3 flex items-center justify-between gap-3 flex-wrap rounded-lg px-3 py-2 text-xs"
      style={{ background: partial ? 'var(--fresh-aging-bg)' : 'var(--money-positive-bg)' }}
    >
      <div style={{ color: 'var(--ink)' }}>
        <strong>{plotted}</strong> of <strong>{total}</strong> items plotted
        {partial && (
          <> · <span style={{ color: 'var(--slate)' }}>add recipes to plot the rest</span></>
        )}
      </div>
      <CogsCite cogs={cogs} />
    </div>
  );
}

function CogsCite({ cogs }: { cogs: { region: string | null; asOf: string | null } }) {
  if (!cogs.asOf) return null;
  const niceRegion = cogs.region ? cogs.region : 'national';
  return (
    <FreshnessChip
      state={isStale(cogs.asOf) ? 'aging' : 'fresh'}
      text={`COGS · USDA ${niceRegion}, ${formatAsOf(cogs.asOf)}`}
      size="xs"
    />
  );
}

/* ── Quadrant SVG label ──────────────────────────────────────────────── */
function QuadrantLabel({ x, y, quadrant }: { x: number; y: number; quadrant: MenuQuadrant }) {
  const meta = QUADRANT_META[quadrant];
  return (
    <text
      x={x} y={y}
      fontSize="11" fontWeight={800}
      fill={meta.point}
      letterSpacing={0.6}
      style={{ textTransform: 'uppercase' }}
    >
      {meta.label}
    </text>
  );
}

/* ── Hover tooltip ───────────────────────────────────────────────────── */
function Tooltip({ item, cogs }: { item: MenuEngineeringItem; cogs: { region: string | null; asOf: string | null } }) {
  const meta = QUADRANT_META[item.quadrant];
  const marginPct = item.price_cents > 0 ? item.margin_cents / item.price_cents : 0;
  return (
    <div
      className="rounded-lg shadow-float min-w-[220px] max-w-[280px]"
      style={{
        background: 'white',
        border: `1px solid ${meta.point}`,
        padding: 12,
      }}
    >
      <div className="flex items-center justify-between gap-2">
        <span
          className="text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded"
          style={{ background: meta.tint, color: meta.point }}
        >
          {meta.label.slice(0, -1)}
        </span>
        <span className="text-[10px]" style={{ color: 'var(--slate)' }}>
          {item.volume_monthly.toLocaleString()}/mo
        </span>
      </div>
      <div className="font-extrabold text-sm mt-1" style={{ color: 'var(--ink)' }}>
        {item.name}
      </div>
      <div className="mt-2">
        <MoneyStat
          label="Margin / unit"
          value={item.margin_cents / 100}
          precision="cents"
          size="md"
          tone={item.margin_cents > 0 ? 'positive' : 'negative'}
          durationMs={0}
          footer={<span style={{ color: 'var(--slate)' }}>{(marginPct * 100).toFixed(1)}% of ${(item.price_cents / 100).toFixed(2)} price</span>}
        />
      </div>
      <div
        className="mt-2 pt-2 border-t text-[10px] flex items-center justify-between gap-2"
        style={{ borderColor: 'var(--line-soft)' }}
      >
        <span style={{ color: 'var(--slate)' }}>Plate cost</span>
        <span className="font-semibold tabular-nums" style={{ color: 'var(--ink)' }}>
          ${(item.true_cost_cents / 100).toFixed(2)}
        </span>
      </div>
      {cogs.asOf && (
        <div className="mt-1 text-[10px]" style={{ color: 'var(--slate)' }}>
          Source: USDA {cogs.region ?? 'national'} · {formatAsOf(cogs.asOf)}
        </div>
      )}
    </div>
  );
}

/* ── Item modal — shows the rec card if one exists, else a summary ──── */
function ItemDetailModal({ item, rec, cogs, onClose }: {
  item: MenuEngineeringItem;
  rec: Recommendation | null;
  cogs: { region: string | null; asOf: string | null };
  onClose: () => void;
}) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose();
    }
    document.addEventListener('keydown', onKey);
    ref.current?.focus();
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <div
      className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-2 sm:p-4"
      style={{ background: 'rgba(15, 23, 42, 0.45)' }}
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-label={`${item.name} details`}
    >
      <div
        ref={ref}
        tabIndex={-1}
        className="w-full max-w-md rounded-xl shadow-float focus:outline-none"
        style={{ background: 'white', border: '1px solid var(--line-soft)' }}
        onClick={(e) => e.stopPropagation()}
      >
        <div
          className="flex items-center justify-between px-4 py-3 border-b"
          style={{ borderColor: 'var(--line-soft)' }}
        >
          <div className="min-w-0">
            <div className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
              {QUADRANT_META[item.quadrant].label.slice(0, -1)}
            </div>
            <h3 className="font-extrabold text-base truncate" style={{ color: 'var(--ink)' }}>
              {item.name}
            </h3>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className="inline-flex items-center justify-center w-11 h-11 rounded-lg hover:bg-slate-50"
          >
            <X size={18} style={{ color: 'var(--slate)' }} />
          </button>
        </div>
        <div className="p-3 sm:p-4 space-y-3">
          {rec ? (
            <RecommendationCard
              rec={rec}
              itemName={null /* the modal header already shows it */}
              fallbackPrice={item.price_cents}
              fallbackPlateCost={item.true_cost_cents}
              density="comfortable"
              onAfterDecide={onClose}
            />
          ) : (
            <div
              className="rounded-lg p-4 text-sm flex flex-col gap-2"
              style={{ background: 'var(--bg-panel)', color: 'var(--body)' }}
            >
              <MoneyStat
                label="Margin / unit"
                value={item.margin_cents / 100}
                precision="cents"
                size="lg"
                tone={item.margin_cents > 0 ? 'positive' : 'negative'}
                durationMs={0}
              />
              <div className="flex items-center justify-between text-xs">
                <span style={{ color: 'var(--slate)' }}>Price</span>
                <span className="font-semibold tabular-nums" style={{ color: 'var(--ink)' }}>
                  ${(item.price_cents / 100).toFixed(2)}
                </span>
              </div>
              <div className="flex items-center justify-between text-xs">
                <span style={{ color: 'var(--slate)' }}>Plate cost</span>
                <span className="font-semibold tabular-nums" style={{ color: 'var(--ink)' }}>
                  ${(item.true_cost_cents / 100).toFixed(2)}
                </span>
              </div>
              <div className="flex items-center justify-between text-xs">
                <span style={{ color: 'var(--slate)' }}>Monthly volume</span>
                <span className="font-semibold tabular-nums" style={{ color: 'var(--ink)' }}>
                  {item.volume_monthly.toLocaleString()}
                </span>
              </div>
              {cogs.asOf && (
                <div
                  className="pt-2 mt-1 border-t text-[11px]"
                  style={{ borderColor: 'var(--line-soft)', color: 'var(--slate)' }}
                >
                  COGS source: USDA {cogs.region ?? 'national'} · {formatAsOf(cogs.asOf)}
                </div>
              )}
              <div className="text-[11px]" style={{ color: 'var(--slate)' }}>
                No open recommendation for this item right now — margins look healthy enough that we haven't flagged a move.
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

/* ── helpers ─────────────────────────────────────────────────────────── */
function tickValues(max: number, count: number): number[] {
  // "Nice" tick spacing — round to a power of 10 that yields ~count ticks.
  const raw = max / count;
  const exp = Math.floor(Math.log10(raw));
  const base = Math.pow(10, exp);
  const candidates = [1, 2, 2.5, 5, 10].map((m) => m * base);
  const step = candidates.find((c) => max / c <= count + 1) ?? candidates[candidates.length - 1];
  const out: number[] = [];
  for (let v = step; v <= max; v += step) out.push(v);
  return out;
}

function isStale(asOf: string): boolean {
  const ts = Date.parse(String(asOf).replace(' ', 'T'));
  if (!Number.isFinite(ts)) return false;
  return Date.now() - ts > 30 * 24 * 60 * 60 * 1000;
}

function formatAsOf(asOf: string): string {
  const ts = Date.parse(String(asOf).replace(' ', 'T'));
  if (!Number.isFinite(ts)) return asOf;
  return new Date(ts).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}
