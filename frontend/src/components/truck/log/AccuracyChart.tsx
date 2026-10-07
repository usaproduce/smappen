import { useLayoutEffect, useMemo, useRef } from 'react';
import type { ServiceLogEntry } from '../../../utils/truck/model';
import {
  ACCURACY_CHART,
  ACCURACY_TEXT,
  CHART_LEGEND,
  chartEntries,
  chartLayout,
  chartSummary,
} from '../../../utils/truck/accuracyView';
import { fmtCount } from '../../../utils/truck/format';
import { CHART } from '../../../utils/truck/wording';

export interface AccuracyChartProps {
  /** Every logged service that carries an estimate (the entries of the accuracy report). */
  entries: readonly ServiceLogEntry[];
  /** Names of the spots by id, for the words a pointer shows on a mark. */
  names: Readonly<Record<string, string>>;
}

const C = ACCURACY_CHART;

/** A length of the view box as a share of its width or height, for the axis laid over the drawing. */
function share(units: number, of: number): string {
  return String((units / of) * 100) + '%';
}

/**
 * "Estimates against actuals" (docs/truck-planner/05_FRONTEND.md 4.7 and 7.3): the last thirty
 * services, oldest at the left. Each column is the estimate that was kept with the service (a bar
 * from its low to its high, a tick at the estimate) and what happened (a dot at the orders
 * served). A sold-out service is a hollow ring: its count is a minimum, so the real figure may lie
 * anywhere above it.
 *
 * Hand-rolled SVG on CSS variables. The sentence above the chart says its point in words and is
 * also what a screen reader hears for it. On a phone the drawing is wider than its card and
 * scrolls sideways: it then starts at the newest service, and the orders axis stays at the left
 * edge while the columns move under it.
 */
export default function AccuracyChart({ entries, names }: AccuracyChartProps) {
  const charted = useMemo(() => chartEntries(entries), [entries]);
  const layout = useMemo(() => chartLayout(charted, names), [charted, names]);
  const summary = chartSummary(charted, entries.length);
  const anySoldOut = layout.marks.some((mark) => mark.soldOut);
  const scroller = useRef<HTMLDivElement>(null);
  const plot = useRef<HTMLDivElement>(null);

  // Where the drawing does not fit, the newest service is the one in view.
  const lastX = layout.marks.length === 0 ? null : layout.marks[layout.marks.length - 1].x;
  useLayoutEffect(() => {
    const box = scroller.current;
    const drawing = plot.current;
    if (box === null || drawing === null || lastX === null) return;
    const right = ((lastX + C.tick) / C.width) * drawing.clientWidth;
    box.scrollLeft = right > box.clientWidth ? right - box.clientWidth : 0;
  }, [lastX]);

  // The values of the orders axis: zero and the four gridlines.
  const axis = [{ orders: 0, y: layout.baseline }, ...layout.gridlines];

  return (
    <section className="min-w-0 rounded-xl border bg-white p-4 sm:p-5" style={{ borderColor: 'var(--line-soft)' }}>
      <h3 className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
        {ACCURACY_TEXT.chartTitle}
      </h3>
      <p className="mt-1 text-sm font-semibold" style={{ color: 'var(--body)' }}>
        {summary}
      </p>
      <div ref={scroller} className="tp-scroll-x mt-3">
        <div ref={plot} className="@container relative" style={{ minWidth: 590, maxWidth: 860 }}>
          <svg viewBox={'0 0 ' + String(C.width) + ' ' + String(C.height)} width="100%" role="img" aria-label={summary} style={{ display: 'block' }}>
            {/* gridlines */}
            <line x1={C.left} x2={C.width - C.right} y1={layout.baseline} y2={layout.baseline} stroke="var(--line)" strokeWidth={1} />
            {layout.gridlines.map((grid) => (
              <line key={grid.orders} aria-hidden x1={C.left} x2={C.width - C.right} y1={grid.y} y2={grid.y} stroke="var(--line-soft)" strokeWidth={1} />
            ))}

            {/* one column per service */}
            {layout.marks.map((mark) => (
              <g key={mark.id}>
                <title>{mark.title}</title>
                <rect x={mark.x - C.bar / 2} y={mark.yHigh} width={C.bar} height={Math.max(mark.yLow - mark.yHigh, 1.5)} rx={2} fill="var(--line)" />
                <line
                  x1={mark.x - C.tick / 2}
                  x2={mark.x + C.tick / 2}
                  y1={mark.yEstimate}
                  y2={mark.yEstimate}
                  stroke="var(--ink)"
                  strokeWidth={2.5}
                  strokeLinecap="round"
                />
                {mark.soldOut ? (
                  <circle cx={mark.x} cy={mark.yActual} r={C.dot} fill="none" stroke="var(--accent-brand)" strokeWidth={2.25} />
                ) : (
                  <circle cx={mark.x} cy={mark.yActual} r={C.dot} fill="var(--accent-brand)" />
                )}
              </g>
            ))}

            {/* date ticks */}
            {layout.dates.map((date) => (
              <text key={String(date.x)} aria-hidden x={date.x} y={C.height - 9} textAnchor="middle" fontSize={C.text} fontWeight={700} fill="var(--slate)">
                {date.text}
              </text>
            ))}
          </svg>

          {/*
            The orders axis, laid over the drawing in the drawing's own measure (a length is a share
            of the box, the text a share of its width). It sticks to the left edge of the box that
            scrolls, on the card's surface, so the columns pass under it and keep their scale.
          */}
          <div aria-hidden className="pointer-events-none absolute inset-0">
            <div
              className="bg-white sticky left-0 font-bold tabular-nums"
              style={{
                width: share(C.left - 3, C.width),
                // down to just under the zero line: the dates below it scroll with the columns
                height: share(layout.baseline + C.text, C.height),
                color: 'var(--slate)',
                fontSize: String((C.text / C.width) * 100) + 'cqw',
                lineHeight: 1,
              }}
            >
              <span className="absolute left-0 top-0 whitespace-nowrap">Orders</span>
              {axis.map((tick) => (
                <span key={tick.orders} className="absolute right-[10%]" style={{ top: share(tick.y, layout.baseline + C.text), transform: 'translateY(-50%)' }}>
                  {fmtCount(tick.orders)}
                </span>
              ))}
            </div>
          </div>
        </div>
      </div>
      <ul aria-label={CHART.legend} className="mt-2 flex flex-wrap gap-x-4 gap-y-1.5 text-xs font-bold" style={{ color: 'var(--body)' }}>
        <li className="inline-flex items-center gap-1.5">
          <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden>
            <rect x="4" y="1" width="6" height="12" rx="2" fill="var(--line)" />
          </svg>
          {CHART_LEGEND.range}
        </li>
        <li className="inline-flex items-center gap-1.5">
          <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden>
            <line x1="1.5" x2="12.5" y1="7" y2="7" stroke="var(--ink)" strokeWidth="2.5" strokeLinecap="round" />
          </svg>
          {CHART_LEGEND.estimate}
        </li>
        <li className="inline-flex items-center gap-1.5">
          <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden>
            <circle cx="7" cy="7" r="4.5" fill="var(--accent-brand)" />
          </svg>
          {CHART_LEGEND.actual}
        </li>
        {anySoldOut ? (
          <li className="inline-flex items-center gap-1.5">
            <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden>
              <circle cx="7" cy="7" r="4" fill="none" stroke="var(--accent-brand)" strokeWidth="2.25" />
            </svg>
            {CHART_LEGEND.soldOut}
          </li>
        ) : null}
      </ul>
    </section>
  );
}
