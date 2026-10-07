import { useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Minus, Plus } from 'lucide-react';
import { fmtClock } from '../../../utils/truck/format';
import { FIELD, timeStepLabel } from '../../../utils/truck/wording';
import Field from './Field';
import { commitTime, stepTime } from './kit';
import type { TimeFieldRules } from './kit';
import { escapeMark } from './overlay';

export interface TimeFieldProps {
  id: string;
  label: string;
  /** Minutes from midnight of the service date, 0..2880; null while empty. */
  value: number | null;
  onCommit: (v: number | null) => void;
  min?: number;
  max?: number;
  /** The time this one comes after: a bare "2" then reads as the 2 that follows it. */
  after?: number;
  /** The time may fall on the next day (a closing time after midnight). */
  allowNextDay?: boolean;
  /** Minutes the two buttons move by; default 15. */
  step?: 5 | 15;
  help?: string;
  error?: string;
  disabled?: boolean;
  className?: string;
}

/**
 * A time of day the owner types, with a button on each side (docs/truck-planner/05_FRONTEND.md
 * 3.6). It shows its value through fmtClock and reads what is typed through parseClock: "11",
 * "2:30 pm", "930", "noon". A time outside min..max does not commit and the field says the range.
 */
export default function TimeField(props: TimeFieldProps) {
  const { id, label, value, onCommit, min, max, after, allowNextDay, step = 15, help = FIELD.timeHelp, error, disabled, className } = props;
  const rules: TimeFieldRules = { min, max, after, allowNextDay, step };
  const input = useRef<HTMLInputElement>(null);
  const [draft, setDraft] = useState<string | null>(null);
  const [ownError, setOwnError] = useState<string | null>(null);
  const textAtFocus = useRef<string | null>(null);

  const rest = value === null ? '' : fmtClock(value);
  const text = draft !== null ? draft : rest;
  const shownError = error !== undefined && error !== '' ? error : ownError;
  // An edit that is not committed yet, or a refusal still on show: Escape belongs to the field first.
  const pending = (draft !== null && draft !== textAtFocus.current) || ownError !== null;
  const earlier = stepTime(value, -1, rules);
  const later = stepTime(value, 1, rules);

  const commit = () => {
    if (draft === null) return;
    if (draft === textAtFocus.current) {
      setDraft(null); // untouched
      return;
    }
    const result = commitTime(draft, rules);
    if (!result.ok) {
      setOwnError(result.error);
      return;
    }
    setOwnError(null);
    setDraft(null);
    textAtFocus.current = null; // as in NumberField: no longer the text on show
    if (result.value !== value) onCommit(result.value);
  };

  const move = (to: number | null) => {
    if (to === null) return;
    setOwnError(null);
    setDraft(null);
    if (to !== value) onCommit(to);
  };

  const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') commit();
    else if (e.key === 'Escape') {
      if (pending) e.stopPropagation(); // a layer around the field stays open: see overlay.ts
      setDraft(null);
      setOwnError(null);
    } else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
      e.preventDefault();
      const to = e.key === 'ArrowUp' ? later : earlier;
      if (to === null) return;
      textAtFocus.current = fmtClock(to);
      setDraft(textAtFocus.current);
      setOwnError(null);
      if (to !== value) onCommit(to);
    }
  };

  const buttonClass =
    'inline-flex h-11 w-11 md:h-9 md:w-9 flex-none items-center justify-center rounded-lg border bg-white disabled:opacity-40 disabled:cursor-not-allowed';

  return (
    <Field id={id} label={label} help={help} error={shownError} className={className}>
      {(control) => (
        <div className="flex items-center gap-1">
          <button
            type="button"
            className={buttonClass}
            style={{ borderColor: 'var(--line)', color: 'var(--ink)' }}
            aria-label={timeStepLabel(step, false)}
            title={timeStepLabel(step, false)}
            disabled={disabled === true || earlier === null}
            onClick={() => move(earlier)}
          >
            <Minus size={16} aria-hidden />
          </button>
          <input
            {...control}
            {...escapeMark(pending)}
            ref={input}
            type="text"
            inputMode="text"
            autoComplete="off"
            spellCheck={false}
            disabled={disabled}
            value={text}
            onFocus={() => {
              if (draft === null) {
                textAtFocus.current = rest;
                setDraft(rest);
              }
              // as in NumberField: select a frame later, only while the field still has focus and
              // still shows the text it had
              const el = input.current;
              if (el !== null) {
                const shown = el.value;
                window.requestAnimationFrame(() => {
                  if (document.activeElement === el && el.value === shown) el.select();
                });
              }
            }}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={commit}
            onKeyDown={onKeyDown}
            className={'input h-11 md:h-9 min-w-0 flex-1 text-sm tabular-nums text-center font-semibold' + (shownError !== null && shownError !== '' ? ' tp-invalid' : '')}
          />
          <button
            type="button"
            className={buttonClass}
            style={{ borderColor: 'var(--line)', color: 'var(--ink)' }}
            aria-label={timeStepLabel(step, true)}
            title={timeStepLabel(step, true)}
            disabled={disabled === true || later === null}
            onClick={() => move(later)}
          >
            <Plus size={16} aria-hidden />
          </button>
        </div>
      )}
    </Field>
  );
}
