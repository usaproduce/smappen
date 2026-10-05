import { useMemo, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { modFloor } from '../../../utils/truck/model';
import { fmtHourTick, fmtHow, fmtWeekday } from '../../../utils/truck/format';
import { colorOfByte } from '../../../utils/truck/palette';
import { stripByte, stripKeyTarget, stripRuns } from './kit';
import { useThemeName } from './useThemeName';

export interface WeekStripProps {
  /** One value per hour of the week, 168 of them; index 0 is Monday 12 AM. */
  values: number[];
  /** The value that takes the darkest colour. Callers pass the seed map.opportunity_hi, so a colour means the same number here and on the map. */
  yMax: number;
  /** Best windows: outlined and numbered in the order given. `label` says the window in words. */
  windows?: { how: number; hours: number; label: string }[];
  cursorHow?: number;
  onPickHow?: (how: number) => void;
  /** Drops the hour ticks. */
  compact?: boolean;
  /** The point of the chart in one sentence; the screen prints the same sentence above it. */
  ariaSummary: string;
}

const LABEL_W = 30;
const PITCH = 13;
const CELL = 12;
const W = LABEL_W + 24 * PITCH;

/**
 * A week at a glance: seven rows (Monday to Sunday) by 24 hours (docs/truck-planner/05_FRONTEND.md
 * 3.10). A cell takes the opportunity colour of the map at its value; a cell under the map's floor
 * stays on the panel colour. With onPickHow the cells are a grid with one tab stop: the arrows
 * move, Enter picks.
 */
export default function WeekStrip({ values, yMax, windows, cursorHow, onPickHow, compact = false, ariaSummary }: WeekStripProps) {
  const theme = useThemeName();
  const [focusHow, setFocusHow] = useState<number | null>(null);
  const top = compact ? 2 : 15;
  const H = top + 7 * PITCH + 1;
  const interactive = onPickHow !== undefined;
  const cursor = cursorHow === undefined ? null : modFloor(cursorHow, 168);
  const tabHow = focusHow !== null ? focusHow : cursor !== null ? cursor : 0;

  const colors = useMemo(() => {
    const out: string[] = [];
    for (let how = 0; how < 168; how++) {
      const v = values[how];
      const color = colorOfByte('opportunity', theme, stripByte(typeof v === 'number' ? v : 0, yMax));
      out.push(color === null ? 'var(--bg-panel)' : color);
    }
    return out;
  }, [values, yMax, theme]);

  const xOf = (hour: number) => LABEL_W + hour * PITCH;
  const yOf = (dow: number) => top + dow * PITCH;

  const onKeyDown = (e: KeyboardEvent<SVGRectElement>, how: number) => {
    if (onPickHow === undefined) return;
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      onPickHow(how);
      return;
    }
    const to = stripKeyTarget(e.key, how);
    if (to === null) return;
    e.preventDefault();
    setFocusHow(to);
    const next = e.currentTarget.ownerSVGElement?.querySelector<SVGRectElement>('[data-how="' + String(to) + '"]');
    if (next) next.focus();
  };

  const rows = [0, 1, 2, 3, 4, 5, 6];
  const hours = Array.from({ length: 24 }, (_, h) => h);

  return (
    <div className="overflow-x-auto scroll-x">
      <svg
        viewBox={'0 0 ' + String(W) + ' ' + String(H)}
        width="100%"
        role={interactive ? 'grid' : 'img'}
        aria-label={ariaSummary}
        style={{ display: 'block', minWidth: compact ? 280 : 300, maxWidth: compact ? 342 : 470 }}
      >
        {compact
          ? null
          : [0, 6, 12, 18].map((h) => (
              <text key={h} aria-hidden x={xOf(h)} y={10.5} fontSize="11" fontWeight={700} fill="var(--slate)">
                {fmtHourTick(h)}
              </text>
            ))}

        {rows.map((dow) => (
          <g key={dow} role={interactive ? 'row' : undefined}>
            <text aria-hidden x={LABEL_W - 5} y={yOf(dow) + CELL - 2.5} textAnchor="end" fontSize="11" fontWeight={700} fill="var(--slate)">
              {fmtWeekday(dow, 'short')}
            </text>
            {hours.map((hour) => {
              const how = dow * 24 + hour;
              if (!interactive) {
                return (
                  <rect key={hour} x={xOf(hour)} y={yOf(dow)} width={CELL} height={CELL} rx={2} fill={colors[how]}>
                    <title>{fmtHow(how)}</title>
                  </rect>
                );
              }
              return (
                <rect
                  key={hour}
                  data-how={how}
                  role="gridcell"
                  tabIndex={how === tabHow ? 0 : -1}
                  aria-label={fmtHow(how)}
                  aria-selected={cursor === how}
                  className="tp-svg-focus"
                  x={xOf(hour)}
                  y={yOf(dow)}
                  width={CELL}
                  height={CELL}
                  rx={2}
                  fill={colors[how]}
                  style={{ cursor: 'pointer' }}
                  onClick={() => onPickHow(how)}
                  onFocus={() => setFocusHow(how)}
                  onKeyDown={(e) => onKeyDown(e, how)}
                >
                  <title>{fmtHow(how)}</title>
                </rect>
              );
            })}
          </g>
        ))}

        {/* best windows: a 2 px ink outline around each run of hours, and the rank */}
        {(windows === undefined ? [] : windows).map((w, index) =>
          stripRuns(w.how, w.hours).map((run) => (
            <g key={String(index) + '-' + String(run.dow) + '-' + String(run.hour)} style={{ pointerEvents: 'none' }}>
              <rect
                x={xOf(run.hour) - 1}
                y={yOf(run.dow) - 1}
                width={run.hours * PITCH - (PITCH - CELL) + 2}
                height={CELL + 2}
                rx={3}
                fill="none"
                stroke="var(--ink)"
                strokeWidth={2}
              >
                <title>{w.label}</title>
              </rect>
              {run.first ? (
                <g aria-hidden>
                  <rect x={xOf(run.hour) - 2} y={yOf(run.dow) - 2} width={9} height={9} rx={2} fill="var(--ink)" />
                  <text x={xOf(run.hour) + 2.5} y={yOf(run.dow) + 5} textAnchor="middle" fontSize="8" fontWeight={800} fill="var(--bg)">
                    {String(index + 1)}
                  </text>
                </g>
              ) : null}
            </g>
          )),
        )}

        {/* the hour the rest of the page is on: an ink ring with a light one inside, readable on every colour */}
        {cursor !== null ? (
          <g aria-hidden style={{ pointerEvents: 'none' }}>
            <rect x={xOf(cursor % 24) - 1.5} y={yOf((cursor - (cursor % 24)) / 24) - 1.5} width={CELL + 3} height={CELL + 3} rx={3} fill="none" stroke="var(--ink)" strokeWidth={2} />
            <rect x={xOf(cursor % 24) + 0.25} y={yOf((cursor - (cursor % 24)) / 24) + 0.25} width={CELL - 0.5} height={CELL - 0.5} rx={2} fill="none" stroke="var(--bg)" strokeWidth={1.5} />
          </g>
        ) : null}
      </svg>
    </div>
  );
}
