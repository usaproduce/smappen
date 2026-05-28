import { AlertTriangle, Info } from 'lucide-react';

/**
 * Graceful degradation when the COGS feed is past its freshness window.
 * Spec §1.6 + the audit's DataFreshnessFooter fallback — we never show
 * money math derived from stale prices silently. The banner sits *above*
 * the affected surface (plate-cost table, overpay list, food-cost hero)
 * so the operator sees the caveat before the number, not after.
 *
 * Severity is data-driven:
 *   • `aging`  — newer than `staleAfterDays` but older than `freshUntilDays`
 *                Neutral amber. "Last refresh was N days ago."
 *   • `stale`  — older than `staleAfterDays`. Stronger amber border + the
 *                "prices may be out of date" treatment from the spec.
 *   • `missing` — no `as_of` at all. Slate-tinted "stub prices only" note.
 *
 * Stays compact (one line on phones, two on desktop) so it doesn't shove
 * the actual data below the fold.
 */

type Props = {
  /** ISO of the latest USDA/CogsBenchmark batch. Null → `missing` severity. */
  asOf?: string | null;
  /** Region label ("Mid-Atlantic", "national") for clarity in the copy. */
  region?: string | null;
  /** Inside this window, the banner doesn't render at all. Default 7 days. */
  freshUntilDays?: number;
  /** Past this window, severity escalates to `stale`. Default 30 days. */
  staleAfterDays?: number;
  /** Optional retry action — surfaced as an inline link when present. */
  onRetry?: () => void;
  className?: string;
};

export default function CogsStaleBanner({
  asOf,
  region,
  freshUntilDays = 7,
  staleAfterDays = 30,
  onRetry,
  className,
}: Props) {
  const severity = pickSeverity(asOf, freshUntilDays, staleAfterDays);
  if (!severity) return null; // fresh — render nothing, no noise

  const palette = severity === 'stale'
    ? { bg: 'var(--money-negative-bg)', fg: 'var(--money-negative)', border: 'var(--money-negative)', Icon: AlertTriangle }
    : severity === 'aging'
      ? { bg: 'var(--fresh-aging-bg)', fg: '#92670E', border: '#92670E', Icon: AlertTriangle }
      : { bg: 'var(--bg-panel)', fg: 'var(--slate)', border: 'var(--line-soft)', Icon: Info };

  const Icon = palette.Icon;
  const regionLabel = region ?? 'national';
  const ageStr = asOf ? formatAge(asOf) : null;
  const headline = severity === 'stale'
    ? 'COGS prices may be out of date'
    : severity === 'aging'
      ? 'COGS prices aging'
      : 'Stub COGS prices only';
  const body = severity === 'missing'
    ? `USDA + GreenDock ingest hasn't run for ${regionLabel}. Margins below use placeholder prices and will jump once real data lands.`
    : severity === 'stale'
      ? `Last USDA refresh for ${regionLabel} was ${ageStr}. Treat margin math below as directional until the next ingest.`
      : `USDA refresh for ${regionLabel} was ${ageStr}. Still usable, but watch for movement on commodity items.`;

  return (
    <div
      role="status"
      aria-live="polite"
      className={'rounded-lg border px-3 py-2 flex items-start gap-2.5 ' + (className ?? '')}
      style={{ background: palette.bg, borderColor: palette.border, borderWidth: severity === 'stale' ? 2 : 1 }}
    >
      <Icon
        size={16}
        strokeWidth={2.4}
        className="flex-shrink-0 mt-0.5"
        style={{ color: palette.fg }}
        aria-hidden
      />
      <div className="min-w-0 flex-1">
        <div className="flex items-baseline gap-2 flex-wrap">
          <span
            className="text-[11px] font-bold uppercase tracking-wider"
            style={{ color: palette.fg }}
          >
            {headline}
          </span>
          {asOf && (
            <span className="text-[11px]" style={{ color: 'var(--slate)' }}>
              · as of {formatAbsolute(asOf)}
            </span>
          )}
        </div>
        <div className="text-xs mt-0.5" style={{ color: 'var(--body)' }}>
          {body}
        </div>
      </div>
      {onRetry && (
        <button
          type="button"
          onClick={onRetry}
          className="text-xs font-bold underline flex-shrink-0 mt-0.5"
          style={{ color: palette.fg }}
        >
          Retry ingest
        </button>
      )}
    </div>
  );
}

type Severity = 'aging' | 'stale' | 'missing' | null;

function pickSeverity(asOf: string | null | undefined, freshDays: number, staleDays: number): Severity {
  if (!asOf) return 'missing';
  const ts = Date.parse(String(asOf).replace(' ', 'T'));
  if (!Number.isFinite(ts)) return 'missing';
  const ageDays = (Date.now() - ts) / (24 * 60 * 60_000);
  if (ageDays <= freshDays) return null;
  if (ageDays <= staleDays) return 'aging';
  return 'stale';
}

function formatAge(iso: string): string {
  const ts = Date.parse(iso.replace(' ', 'T'));
  if (!Number.isFinite(ts)) return iso;
  const days = Math.round((Date.now() - ts) / (24 * 60 * 60_000));
  if (days < 1) return 'today';
  if (days === 1) return 'yesterday';
  if (days < 30) return `${days} days ago`;
  const months = Math.round(days / 30);
  return months === 1 ? 'a month ago' : `${months} months ago`;
}

function formatAbsolute(iso: string): string {
  const ts = Date.parse(iso.replace(' ', 'T'));
  if (!Number.isFinite(ts)) return iso;
  return new Date(ts).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}
