import { useEffect, useRef, useState } from 'react';
import { RotateCcw } from 'lucide-react';
import { fmtClockShort, fmtHourTick, fmtPlain, fmtWeekday } from '../../../utils/truck/format';
import { curveView } from '../../../utils/truck/profileForm';
import { FIELD } from '../../../utils/truck/wording';
import { HourBars, NumberField } from '../ui';

type Cells = (number | null)[];

/**
 * The fields are labelled as the chart axis is ("12a", "Mon"), not in capitals. The global `.label`
 * rule is unlayered, so a plain utility would lose to it: the trailing `!` makes this one important.
 */
const HOUR_LABEL = '[&_.label]:normal-case!';

/**
 * A list of numbers edited cell by cell. The cells live here while one of them is empty: the
 * override itself is always a complete list, so it follows only once every cell holds a number.
 * A value handed in from outside (a reset, a discard, a save) replaces the cells.
 */
function useCells(value: readonly number[], onChange: (next: number[]) => void): { cells: Cells; setCell: (index: number, v: number | null) => void } {
  const [cells, setCells] = useState<Cells>(() => value.slice());
  const sent = useRef<readonly number[]>(value);
  useEffect(() => {
    // Our own change coming back is not news: only a different list replaces what is being typed.
    const same = sent.current.length === value.length && sent.current.every((x, i) => x === value[i]);
    if (!same) {
      sent.current = value;
      setCells(value.slice());
    }
  }, [value]);

  const setCell = (index: number, v: number | null) => {
    const next = cells.map((cell, i) => (i === index ? v : cell));
    setCells(next);
    if (next.every((cell): cell is number => cell !== null)) {
      sent.current = next;
      onChange(next);
    }
  };
  return { cells, setCell };
}

export interface CurveEditorProps {
  /** Prefix of the 24 field ids. */
  id: string;
  /** What the curve is, for the labels a screen reader hears: "Office workers: people present by hour, weekday". */
  label: string;
  /** The 24 values in use, by local clock hour: fractions of 1. */
  value: readonly number[];
  onChange: (next: number[]) => void;
  /** The bounds of one value, as fractions: the seed's inherited `min` and `max`. */
  min: number | null;
  max: number | null;
  /** True when the owner's values are in use: "Reset curve" then takes them away. */
  overridden: boolean;
  onReset: () => void;
  /** The problem of the curve as a whole, from the check of the merged draft. */
  error?: string;
}

/**
 * One 24-hour curve of a segment (docs/truck-planner/05_FRONTEND.md 4.9): 24 percent fields in a
 * 6 by 4 grid labelled 12a to 11p, a bar preview and "Reset curve". The fields hold fractions and
 * show them times 100. A value outside the seed's bounds stays in its field with the range message.
 */
export default function CurveEditor({ id, label, value, onChange, min, max, overridden, onReset, error }: CurveEditorProps) {
  const { cells, setCell } = useCells(value, onChange);
  const view = curveView(cells.map((cell) => (cell === null ? 0 : cell)));
  const summary =
    view.peakPercent > 0
      ? 'Highest at ' + fmtClockShort(view.peakHour * 60) + ': ' + fmtPlain(view.peakPercent, 2) + '%.'
      : 'Zero in every hour.';

  return (
    <div className="space-y-3">
      <div>
        <p className="mb-1 text-xs font-semibold" style={{ color: 'var(--body)' }}>
          {summary}
        </p>
        <HourBars values={view.percents} yMax={view.yMax} height={96} ariaSummary={label + '. ' + summary} />
      </div>
      <div className="grid grid-cols-3 gap-x-2 gap-y-2 sm:grid-cols-4 md:grid-cols-6" role="group" aria-label={label}>
        {cells.map((cell, hour) => (
          <NumberField
            key={hour}
            id={id + '-' + String(hour)}
            label={fmtHourTick(hour)}
            className={HOUR_LABEL}
            format="percent"
            value={cell}
            onCommit={(v) => setCell(hour, v)}
            min={min === null ? undefined : min}
            max={max === null ? undefined : max}
            step={0.001}
            required
            error={cell === null ? FIELD.required : undefined}
          />
        ))}
      </div>
      {error !== undefined && error !== '' ? (
        <p role="alert" className="text-xs font-semibold" style={{ color: 'var(--money-negative)' }}>
          {error}
        </p>
      ) : null}
      {overridden ? (
        <button
          type="button"
          className="inline-flex min-h-[44px] md:min-h-0 items-center gap-1 text-[13px] font-bold underline underline-offset-2"
          style={{ color: 'var(--ink)' }}
          onClick={onReset}
        >
          <RotateCcw size={13} aria-hidden /> Reset curve
        </button>
      ) : null}
    </div>
  );
}

export interface WeekdayEditorProps {
  id: string;
  label: string;
  /** Five multipliers, Monday to Friday. */
  value: readonly number[];
  onChange: (next: number[]) => void;
  min: number | null;
  max: number | null;
  error?: string;
}

/** The Monday-to-Friday factors of a segment: five number fields "Mon" to "Fri". */
export function WeekdayEditor({ id, label, value, onChange, min, max, error }: WeekdayEditorProps) {
  const { cells, setCell } = useCells(value, onChange);
  return (
    <div>
      <div className="grid grid-cols-3 gap-2 sm:grid-cols-5" role="group" aria-label={label}>
        {cells.map((cell, dow) => (
          <NumberField
            key={dow}
            id={id + '-' + String(dow)}
            label={fmtWeekday(dow, 'short')}
            className={HOUR_LABEL}
            value={cell}
            onCommit={(v) => setCell(dow, v)}
            min={min === null ? undefined : min}
            max={max === null ? undefined : max}
            step={0.01}
            required
            error={cell === null ? FIELD.required : undefined}
          />
        ))}
      </div>
      {error !== undefined && error !== '' ? (
        <p role="alert" className="mt-1 text-xs font-semibold" style={{ color: 'var(--money-negative)' }}>
          {error}
        </p>
      ) : null}
    </div>
  );
}
