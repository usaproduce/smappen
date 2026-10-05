import { Link } from 'react-router-dom';
import type { LucideIcon } from 'lucide-react';

export interface EmptyStateAction {
  label: string;
  onClick?: () => void;
  /** A route to go to instead of a handler. */
  to?: string;
}

export interface EmptyStateProps {
  icon: LucideIcon;
  title: string;
  body: string;
  /** The one primary action, 44 px tall. */
  action?: EmptyStateAction;
  secondary?: EmptyStateAction;
}

function Action({ action, primary }: { action: EmptyStateAction; primary: boolean }) {
  const className = 'btn ' + (primary ? 'btn-primary' : 'btn-secondary') + ' min-h-[44px] px-4 text-sm';
  if (action.to !== undefined) {
    return (
      <Link to={action.to} className={className} onClick={action.onClick}>
        {action.label}
      </Link>
    );
  }
  return (
    <button type="button" className={className} onClick={action.onClick}>
      {action.label}
    </button>
  );
}

/**
 * What a page shows when there is nothing yet (docs/truck-planner/05_FRONTEND.md 3.11): a dashed
 * card, an icon tile, one sentence that says plainly what is missing and one primary action.
 */
export default function EmptyState({ icon: Icon, title, body, action, secondary }: EmptyStateProps) {
  return (
    <section className="flex flex-col items-center gap-3 rounded-xl border-2 border-dashed bg-white p-6 sm:p-10 text-center" style={{ borderColor: 'var(--brand-light)' }}>
      <span aria-hidden className="inline-flex h-14 w-14 items-center justify-center rounded-xl" style={{ background: 'var(--brand-light)', color: 'var(--brand)' }}>
        <Icon size={26} strokeWidth={2.2} />
      </span>
      <h2 className="text-lg font-extrabold" style={{ color: 'var(--ink)' }}>
        {title}
      </h2>
      <p className="max-w-md text-sm font-medium" style={{ color: 'var(--body)' }}>
        {body}
      </p>
      {action !== undefined || secondary !== undefined ? (
        <div className="mt-1 flex flex-wrap items-center justify-center gap-2">
          {action !== undefined ? <Action action={action} primary /> : null}
          {secondary !== undefined ? <Action action={secondary} primary={false} /> : null}
        </div>
      ) : null}
    </section>
  );
}
