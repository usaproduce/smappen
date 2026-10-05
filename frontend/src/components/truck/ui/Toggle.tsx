import { Check } from 'lucide-react';

export interface ToggleProps {
  id: string;
  label: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
  help?: string;
  disabled?: boolean;
}

/**
 * A switch (docs/truck-planner/05_FRONTEND.md 3.8): role="switch", a 44 x 24 px track, on is the
 * brand colour. The label is clickable. The knob moves and carries a tick when on, so the state
 * does not rest on colour.
 */
export default function Toggle({ id, label, checked, onChange, help, disabled = false }: ToggleProps) {
  const helpId = help !== undefined && help !== '' ? id + '-help' : undefined;
  return (
    <div className="flex items-start gap-3">
      <button
        id={id}
        type="button"
        role="switch"
        aria-checked={checked}
        aria-describedby={helpId}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        className="inline-flex min-h-[44px] md:min-h-[24px] flex-none items-center rounded-full disabled:opacity-50 disabled:cursor-not-allowed"
      >
        <span className="tp-switch" data-on={checked ? 'true' : 'false'}>
          <span className="tp-switch-knob bg-white">{checked ? <Check size={12} strokeWidth={3} aria-hidden /> : null}</span>
        </span>
      </button>
      <div className="min-w-0 pt-[11px] md:pt-px">
        <label htmlFor={id} className="block cursor-pointer text-sm font-semibold leading-snug" style={{ color: 'var(--ink)' }}>
          {label}
        </label>
        {helpId !== undefined ? (
          <p id={helpId} className="mt-0.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
            {help}
          </p>
        ) : null}
      </div>
    </div>
  );
}
