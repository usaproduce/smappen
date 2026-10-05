import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { fmtClockShort, fmtHourTick, fmtPlain } from '../../../utils/truck/format';
import { CHART } from '../../../utils/truck/wording';
import { modFloor } from '../../../utils/truck/model';
import { barShare, yTicks } from './kit';

export interface HourBarsProps {
  /** One value per clock hour, 24 of them. */
  values: number[];
  /** The top of the chart. Given by the caller (normally the truck's orders per hour), so bars compare between spots. */
  yMax: number;
  /** Draws the dashed "Truck limit" line at this value. */
  capacity?: number;
  cursorHour?: number;
  /** The selected window: its bars are at full strength, the rest at 45 %. `endHour` is not included. */
  window?: { startHour: number; endHour: number };
  onPickHour?: (h: number) => void;
  /** Height of the drawing in the units of the 336-wide view box; default 132. */
  height?: number;
  /** The point of the chart in one sentence; the screen prints the same sentence above it. */
  ariaSummary: string;
}

const W = 336;
const LEFT = 28;
const RIGHT = 6;
const TOP = 14;
const BOTTOM = 19;

/**
 * Twenty-four bars, one per hour of a day (docs/truck-planner/05_FRONTEND.md 3.10). Hand-rolled
 * SVG on CSS variables. With onPickHour the hours are a row of buttons with one tab stop: Left and
 * Right move, Enter picks.
 */
export default function HourBars(props: HourBarsProps) {
  const { values, yMax, capacity, cursorHour, window: selected, onPickHour, height = 132, ariaSummary } = props;
  const [focusHour, setFocusHour] = useState<number | null>(null);
  const H = height;
  const innerW = W - LEFT - RIGHT;
  const innerH = H - TOP - BOTTOM;
  const slot = innerW / 24;
  const barW = slot - 2;
  const baseline = TOP + innerH;
  const yOf = (v: number) => baseline - barShare(v, yMax) * innerH;
  const ticks = yTicks(yMax);
  const interactive = onPickHour !== undefined;
  const tabHour = focusHour !== null ? focusHour : cursorHour !== undefined ? modFloor(cursorHour, 24) : 0;
  const inWindow = (h: number) => selected === undefined || (h >= selected.startHour && h < selected.endHour);

  const onKeyDown = (e: KeyboardEvent<SVGRectElement>, h: number) => {
    if (onPickHour === undefined) return;
    let to: number | null = null;
    if (e.key === 'ArrowRight') to = modFloor(h + 1, 24);
    else if (e.key === 'ArrowLeft') to = modFloor(h - 1, 24);
    else if (e.key === 'Home') to = 0;
    else if (e.key === 'End') to = 23;
    else if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      onPickHour(h);
      return;
    }
    if (to === null) return;
    e.preventDefault();
    setFocusHour(to);
    const next = e.currentTarget.ownerSVGElement?.querySelector<SVGRectElement>('[data-hour="' + String(to) + '"]');
    if (next) next.focus();
  };

  return (
    <div className="overflow-x-auto scroll-x">
      <svg
        viewBox={'0 0 ' + String(W) + ' ' + String(H)}
        width="100%"
        role={interactive ? 'group' : 'img'}
        aria-label={ariaSummary}
        style={{ display: 'block', minWidth: 288, maxWidth: 440 }}
      >
        {/* gridlines and their values */}
        <line x1={LEFT} x2={W - RIGHT} y1={baseline} y2={baseline} stroke="var(--line)" strokeWidth={1} />
        {ticks.map((t) => (
          <g key={t} aria-hidden>
            <line x1={LEFT} x2={W - RIGHT} y1={yOf(t)} y2={yOf(t)} stroke="var(--line-soft)" strokeWidth={1} />
            <text x={LEFT - 4} y={yOf(t) + 3.5} textAnchor="end" fontSize="11" fontWeight={700} fill="var(--slate)">
              {fmtPlain(t, 1)}
            </text>
          </g>
        ))}

        {/* bars */}
        {values.slice(0, 24).map((v, h) => {
          const y = yOf(v);
          return (
            <rect
              key={h}
              aria-hidden
              x={LEFT + h * slot + 1}
              y={y}
              width={barW}
              height={baseline - y}
              rx={1.5}
              fill="var(--accent-brand)"
              opacity={inWindow(h) ? 1 : 0.45}
            />
          );
        })}

        {/* the truck's limit */}
        {capacity !== undefined && capacity > 0 && capacity <= yMax ? (
          <g aria-hidden>
            <line x1={LEFT} x2={W - RIGHT} y1={yOf(capacity)} y2={yOf(capacity)} stroke="var(--ink)" strokeWidth={1.25} strokeDasharray="4 3" />
            <text x={W - RIGHT} y={yOf(capacity) - 4} textAnchor="end" fontSize="11" fontWeight={700} fill="var(--ink)">
              {CHART.truckLimit}
            </text>
          </g>
        ) : null}

        {/* the hour the rest of the page is on */}
        {cursorHour !== undefined ? (
          <rect
            aria-hidden
            x={LEFT + modFloor(cursorHour, 24) * slot}
            y={TOP - 2}
            width={slot}
            height={innerH + 4}
            rx={2}
            fill="none"
            stroke="var(--ink)"
            strokeWidth={1.5}
          />
        ) : null}

        {/* hour ticks */}
        {[0, 6, 12, 18].map((h) => (
          <text key={h} aria-hidden x={LEFT + h * slot + slot / 2} y={H - 5} textAnchor="middle" fontSize="11" fontWeight={700} fill="var(--slate)">
            {fmtHourTick(h)}
          </text>
        ))}

        {/* one target per hour */}
        {interactive
          ? values.slice(0, 24).map((v, h) => (
              <rect
                key={'pick-' + String(h)}
                data-hour={h}
                role="button"
                tabIndex={h === tabHour ? 0 : -1}
                aria-label={fmtClockShort(h * 60) + ': ' + fmtPlain(v, 1)}
                aria-pressed={cursorHour !== undefined && modFloor(cursorHour, 24) === h}
                className="tp-svg-focus"
                x={LEFT + h * slot}
                y={TOP - 2}
                width={slot}
                height={innerH + 4}
                fill="transparent"
                style={{ cursor: 'pointer' }}
                onClick={() => onPickHour(h)}
                onFocus={() => setFocusHour(h)}
                onKeyDown={(e) => onKeyDown(e, h)}
              >
                <title>{fmtClockShort(h * 60) + ': ' + fmtPlain(v, 1)}</title>
              </rect>
            ))
          : null}
      </svg>
    </div>
  );
}
