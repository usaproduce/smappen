import { useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import Field from './Field';
import { commitNumber, numberFieldText, stepNumber, unitWords } from './kit';
import type { NumberFieldRules } from './kit';
import { escapeMark } from './overlay';

export interface NumberFieldProps {
  id: string;
  label: string;
  value: number | null;
  onCommit: (v: number | null) => void;
  min?: number;
  max?: number;
  step?: number;
  /** Decimals shown after a commit. */
  decimals?: number;
  /** percent: the value is a fraction, the field shows 30 for 0.30. */
  format?: 'number' | 'percent';
  /** Inner adornments: "$", "mi", "orders an hour", "min", "%". */
  prefix?: string;
  suffix?: string;
  help?: string;
  error?: string;
  required?: boolean;
  disabled?: boolean;
  integer?: boolean;
  /** Shown while the field is empty, for example "Truck default: 30". */
  placeholder?: string;
  className?: string;
}

/**
 * A number the owner types (docs/truck-planner/05_FRONTEND.md 3.5). A text input, not
 * type="number": no wheel changes and no locale parsing. It keeps a draft while focused and commits
 * on blur or Enter by the rules of parseNumber. Empty commits null. A value outside the range does
 * not commit and shows the range: values are never clamped. Arrow Up and Down move by one step;
 * Escape restores the last committed value, and only the Escape after that closes a modal or sheet
 * around the field.
 */
export default function NumberField(props: NumberFieldProps) {
  const { id, label, value, onCommit, min, max, step, decimals, format = 'number', prefix, suffix, help, error, required, disabled, integer, placeholder, className } = props;
  const rules: NumberFieldRules = { min, max, step, decimals, format, integer, required };
  const input = useRef<HTMLInputElement>(null);
  const [draft, setDraft] = useState<string | null>(null);
  const [ownError, setOwnError] = useState<string | null>(null);
  // The text the field showed when it took focus: leaving it untouched never commits, so a value
  // that is shown rounded is not written back rounded.
  const textAtFocus = useRef<string | null>(null);

  const adornment = format === 'percent' && suffix === undefined ? '%' : suffix;
  const text = draft !== null ? draft : numberFieldText(value, rules);
  const shownError = error !== undefined && error !== '' ? error : ownError;
  // An edit that is not committed yet, or a refusal still on show: Escape belongs to the field first.
  const pending = (draft !== null && draft !== textAtFocus.current) || ownError !== null;

  const commit = () => {
    if (draft === null) return;
    const result = commitNumber(draft, rules);
    if (!result.ok) {
      setOwnError(result.error); // the text stays, so the owner sees what was refused
      return;
    }
    const touched = draft !== textAtFocus.current;
    setOwnError(result.error);
    setDraft(null);
    // The text taken at focus is no longer what the field shows. Kept, it would make a later edit
    // that types it again (20, Enter, then back to 15) look untouched and be dropped.
    textAtFocus.current = null;
    if (touched && !Object.is(result.value, value)) onCommit(result.value);
  };

  const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      commit();
    } else if (e.key === 'Escape') {
      if (pending) e.stopPropagation(); // a layer around the field stays open: see overlay.ts
      setDraft(null);
      setOwnError(null);
    } else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
      e.preventDefault();
      const typed = draft === null ? null : commitNumber(draft, { format, integer });
      const from = typed !== null && typed.ok ? typed.value : value;
      const next = stepNumber(from, e.key === 'ArrowUp' ? 1 : -1, rules);
      if (next === null) return;
      setOwnError(null);
      textAtFocus.current = numberFieldText(next, rules);
      setDraft(textAtFocus.current);
      if (!Object.is(next, value)) onCommit(next);
    }
  };

  return (
    <Field id={id} label={label} help={help} error={shownError} required={required} unitWords={unitWords(prefix, adornment)} className={className}>
      {(control) => (
        <div className="relative">
          {prefix !== undefined && prefix !== '' ? (
            <span aria-hidden className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm font-semibold" style={{ color: 'var(--body)' }}>
              {prefix}
            </span>
          ) : null}
          <input
            {...control}
            {...escapeMark(pending)}
            ref={input}
            type="text"
            inputMode={integer === true ? 'numeric' : 'decimal'}
            autoComplete="off"
            spellCheck={false}
            disabled={disabled}
            placeholder={placeholder}
            value={text}
            onFocus={() => {
              if (draft === null) {
                textAtFocus.current = numberFieldText(value, rules);
                setDraft(textAtFocus.current);
              }
              // Select the figure, so typing replaces it: a frame later, because a click places its
              // caret after the focus event. Only while the field still has focus: select() on a
              // field that lost it would pull focus back. And only while its text is still the one
              // it had: a key pressed within that frame would otherwise be selected and typed over.
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
            className={'input h-11 md:h-9 text-sm tabular-nums text-right font-semibold' + (shownError !== null && shownError !== '' ? ' tp-invalid' : '')}
            style={{
              paddingLeft: prefix !== undefined && prefix !== '' ? 'calc(' + String(prefix.length) + 'ch + 20px)' : undefined,
              paddingRight: adornment !== undefined && adornment !== '' ? 'calc(' + String(adornment.length) + 'ch + 22px)' : undefined,
            }}
          />
          {adornment !== undefined && adornment !== '' ? (
            <span aria-hidden className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm font-semibold" style={{ color: 'var(--body)' }}>
              {adornment}
            </span>
          ) : null}
        </div>
      )}
    </Field>
  );
}
