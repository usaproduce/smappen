import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';
import { addDays, parseDate } from '../../../utils/truck/model';
import { fmtDay } from '../../../utils/truck/format';
import { FIELD } from '../../../utils/truck/wording';
import { useNow } from '../data/useNow';

export interface DateStepperProps {
  /** "YYYY-MM-DD". Only such strings cross the boundary of this control. */
  date: string;
  onChange: (date: string) => void;
  min?: string;
  max?: string;
}

function validDate(text: string): boolean {
  try {
    parseDate(text);
    return true;
  } catch {
    return false;
  }
}

/** The neighbouring date, or null at the end of the model's calendar or outside min..max. */
function neighbour(date: string, delta: number, min?: string, max?: string): string | null {
  let next: string;
  try {
    next = addDays(date, delta);
    parseDate(next);
  } catch {
    return null;
  }
  if (min !== undefined && next < min) return null;
  if (max !== undefined && next > max) return null;
  return next;
}

/**
 * Pick a date by stepping (docs/truck-planner/05_FRONTEND.md 3.8): previous and next day, the date
 * in words, "Today", and the browser's own date picker behind a calendar button for jumps. "Today"
 * is the civil date in the truck's time zone, never the device's.
 */
export default function DateStepper({ date, onChange, min, max }: DateStepperProps) {
  const today = useNow().date;
  const previous = neighbour(date, -1, min, max);
  const next = neighbour(date, 1, min, max);
  const todayAllowed = (min === undefined || today >= min) && (max === undefined || today <= max);

  const buttonClass =
    'inline-flex h-11 w-11 md:h-9 md:w-9 flex-none items-center justify-center rounded-lg border bg-white disabled:opacity-40 disabled:cursor-not-allowed';
  const buttonStyle = { borderColor: 'var(--line)', color: 'var(--ink)' };

  return (
    <div className="inline-flex max-w-full flex-wrap items-center gap-1.5">
      <button
        type="button"
        className={buttonClass}
        style={buttonStyle}
        aria-label={FIELD.previousDay}
        title={FIELD.previousDay}
        disabled={previous === null}
        onClick={() => {
          if (previous !== null) onChange(previous);
        }}
      >
        <ChevronLeft size={18} aria-hidden />
      </button>
      <span aria-live="polite" className="min-w-[7.5rem] px-1 text-center text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
        {fmtDay(date, 'medium')}
      </span>
      <button
        type="button"
        className={buttonClass}
        style={buttonStyle}
        aria-label={FIELD.nextDay}
        title={FIELD.nextDay}
        disabled={next === null}
        onClick={() => {
          if (next !== null) onChange(next);
        }}
      >
        <ChevronRight size={18} aria-hidden />
      </button>
      <button
        type="button"
        className="btn btn-secondary h-11 md:h-9 px-3 text-sm disabled:opacity-40 disabled:cursor-not-allowed"
        disabled={!todayAllowed || date === today}
        aria-pressed={date === today}
        onClick={() => onChange(today)}
      >
        {FIELD.today}
      </button>
      <span className={'tp-date-wrap relative ' + buttonClass} style={buttonStyle}>
        <CalendarDays size={18} aria-hidden />
        <input
          type="date"
          className="tp-date-overlay"
          aria-label={FIELD.pickDate}
          title={FIELD.pickDate}
          value={date}
          min={min}
          max={max}
          onClick={(e) => {
            // the picker opens on a click anywhere on the button, where the browser allows it
            const el = e.currentTarget as HTMLInputElement & { showPicker?: () => void };
            if (typeof el.showPicker === 'function') {
              try {
                el.showPicker();
              } catch {
                // not available here: the input still opens by itself
              }
            }
          }}
          onChange={(e) => {
            const picked = e.target.value;
            if (picked === '' || picked === date || !validDate(picked)) return;
            if ((min !== undefined && picked < min) || (max !== undefined && picked > max)) return;
            onChange(picked);
          }}
        />
      </span>
    </div>
  );
}
