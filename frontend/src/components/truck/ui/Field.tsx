import type { ReactNode } from 'react';
import { TriangleAlert } from 'lucide-react';

/** What a control inside a Field has to carry so its label, help and error are announced with it. */
export interface FieldControlProps {
  id: string;
  'aria-describedby': string | undefined;
  'aria-invalid': true | undefined;
  'aria-required': true | undefined;
}

export interface FieldProps {
  /** The id of the control; the label points at it. */
  id: string;
  label: string;
  help?: string;
  error?: string | null;
  required?: boolean;
  /** The unit in words ("dollars", "minutes"): real text inside the label, for screen readers. */
  unitWords?: string;
  className?: string;
  /**
   * The control. Given as a function it receives the id and the aria attributes to spread on the
   * input, select or textarea; given as a node, the caller sets them itself with fieldControlProps.
   */
  children: ReactNode | ((control: FieldControlProps) => ReactNode);
}

/** The ids and aria attributes a control of a field needs. */
export function fieldControlProps(id: string, help?: string, error?: string | null, required?: boolean): FieldControlProps {
  const hasError = error !== undefined && error !== null && error !== '';
  const describedBy = [help !== undefined && help !== '' ? id + '-help' : '', hasError ? id + '-error' : ''].filter((x) => x !== '').join(' ');
  return {
    id,
    'aria-describedby': describedBy === '' ? undefined : describedBy,
    'aria-invalid': hasError ? true : undefined,
    'aria-required': required === true ? true : undefined,
  };
}

/**
 * Label, help and error around a control (docs/truck-planner/05_FRONTEND.md 3.8): used by every
 * control that is not one of the kit's own fields (selects, text inputs, textareas), and by those
 * fields themselves. Help sits in body colour; an error pairs its sentence with an icon, so it
 * never rests on colour.
 */
export default function Field({ id, label, help, error, required, unitWords, className, children }: FieldProps) {
  const control = fieldControlProps(id, help, error, required);
  const hasError = control['aria-invalid'] === true;
  return (
    <div className={className}>
      <label htmlFor={id} className="label">
        {label}
        {unitWords !== undefined && unitWords !== '' ? <span className="sr-only"> ({unitWords})</span> : null}
        {required === true ? <span className="sr-only"> (required)</span> : null}
      </label>
      {typeof children === 'function' ? children(control) : children}
      {help !== undefined && help !== '' ? (
        <p id={id + '-help'} className="mt-1 text-xs font-semibold" style={{ color: 'var(--body)' }}>
          {help}
        </p>
      ) : null}
      {hasError ? (
        <p id={id + '-error'} aria-live="polite" className="mt-1 flex items-start gap-1 text-xs font-semibold" style={{ color: 'var(--money-negative)' }}>
          <TriangleAlert size={13} aria-hidden className="mt-px flex-none" />
          <span>{error}</span>
        </p>
      ) : null}
    </div>
  );
}
