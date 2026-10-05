import { TriangleAlert } from 'lucide-react';
import { QUERY_ERROR_RETRY } from '../../../utils/truck/wording';

export interface QueryErrorProps {
  /** One sentence: what could not be loaded. */
  message: string;
  /** Adds the "Try again" button. */
  onRetry?: () => void;
}

/**
 * A request that failed, in place of the content it would have filled
 * (docs/truck-planner/05_FRONTEND.md 3.11, 2.6). One failed request never blanks a page: each block
 * shows its own.
 */
export default function QueryError({ message, onRetry }: QueryErrorProps) {
  return (
    <section role="alert" className="rounded-xl border bg-white p-4 sm:p-5" style={{ borderColor: 'var(--line-soft)' }}>
      <p className="flex items-start gap-2 text-sm font-bold" style={{ color: 'var(--ink)' }}>
        <TriangleAlert size={16} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--money-negative)' }} />
        <span>{message}</span>
      </p>
      {onRetry !== undefined ? (
        <button type="button" className="btn btn-secondary mt-3 h-11 md:h-9 px-3 text-sm" onClick={onRetry}>
          {QUERY_ERROR_RETRY}
        </button>
      ) : null}
    </section>
  );
}
