import { useEffect, useId, useRef, useState } from 'react';
import { ShieldCheck, Info } from 'lucide-react';

/**
 * Honest affiliated-supplier disclosure.
 *
 * USA Produce is the Carafe rollout partner — vendors flagged
 * `is_affiliated` in the marketplace get a tasteful brand-violet badge
 * so the operator can see the relationship at a glance, with a tooltip
 * explaining exactly what it means. The disclosure copy is intentionally
 * boring: "We have a business relationship with this vendor. They do not
 * pay for ranking placement." That's the legal-and-trust gate the spec
 * calls for in §1.4.
 *
 * Variants:
 *   - `pill` (default): a chip with icon + "Affiliated" label + i-info,
 *     for cards / list rows / panel headers.
 *   - `icon`: just the ShieldCheck with a tooltip-on-hover/focus, for
 *     dense rows where the label is space-prohibitive.
 *
 * The tooltip is keyboard-accessible (Enter/Space toggles), screen-reader
 * accessible (aria-describedby pointing at the tooltip text), and closes
 * on outside click or Escape.
 */

const DISCLOSURE_COPY = (
  <>
    <strong>Affiliated supplier.</strong>{' '}
    Carafe has a business relationship with this vendor through USA Produce.
    They do <strong>not</strong> pay for ranking placement, and reviews come
    from operators only — affiliation never changes a vendor's order in the
    "who serves me?" results.
  </>
);

export default function AffiliatedBadge({
  variant = 'pill',
  className = '',
}: {
  variant?: 'pill' | 'icon';
  className?: string;
}) {
  const tooltipId = useId();
  const [open, setOpen] = useState(false);
  const wrapperRef = useRef<HTMLSpanElement>(null);

  useEffect(() => {
    if (!open) return;
    function onClick(e: MouseEvent) {
      if (!wrapperRef.current?.contains(e.target as Node)) setOpen(false);
    }
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') setOpen(false);
    }
    document.addEventListener('mousedown', onClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const handleKey = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      setOpen((v) => !v);
    }
  };

  return (
    <span ref={wrapperRef} className={'relative inline-flex ' + className}>
      {variant === 'pill' ? (
        <button
          type="button"
          aria-describedby={tooltipId}
          aria-expanded={open}
          aria-label="Affiliated supplier — what does this mean?"
          onClick={() => setOpen((v) => !v)}
          onMouseEnter={() => setOpen(true)}
          onMouseLeave={() => setOpen(false)}
          onFocus={() => setOpen(true)}
          onBlur={() => setOpen(false)}
          onKeyDown={handleKey}
          className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
          style={{
            background: 'var(--brand-light)',
            color: 'var(--brand)',
            whiteSpace: 'nowrap',
          }}
        >
          <ShieldCheck size={11} strokeWidth={2.5} aria-hidden />
          Affiliated
          <Info size={9} aria-hidden style={{ opacity: 0.7 }} />
        </button>
      ) : (
        <button
          type="button"
          aria-label="Affiliated supplier — what does this mean?"
          aria-describedby={tooltipId}
          aria-expanded={open}
          onClick={() => setOpen((v) => !v)}
          onMouseEnter={() => setOpen(true)}
          onMouseLeave={() => setOpen(false)}
          onFocus={() => setOpen(true)}
          onBlur={() => setOpen(false)}
          onKeyDown={handleKey}
          className="inline-flex items-center justify-center w-5 h-5 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
          style={{ color: 'var(--brand)' }}
        >
          <ShieldCheck size={14} strokeWidth={2.5} />
        </button>
      )}

      {open && (
        <span
          id={tooltipId}
          role="tooltip"
          className="absolute z-50 top-full mt-2 left-1/2 -translate-x-1/2 text-xs leading-snug rounded-lg p-3 shadow-float"
          style={{
            background: 'white',
            border: '1px solid var(--line-soft)',
            color: 'var(--body)',
            width: 280,
            maxWidth: 'calc(100vw - 24px)',
          }}
        >
          {DISCLOSURE_COPY}
        </span>
      )}
    </span>
  );
}
