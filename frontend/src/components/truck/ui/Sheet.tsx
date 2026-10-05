import { useId, useLayoutEffect, useRef, useState } from 'react';
import type { CSSProperties, ReactNode, RefObject } from 'react';
import { createPortal } from 'react-dom';
import { ChevronDown, ChevronUp, X } from 'lucide-react';
import { FIELD } from '../../../utils/truck/wording';
import { subNavBottom, useOverlayFocus, useScrollLock } from './overlay';

export interface SheetProps {
  open: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
  footer?: ReactNode;
  /** right: a side panel, full screen under 768 px. bottom: rests at 45 % of the height, expands to 92 %. */
  side?: 'right' | 'bottom';
  /** Width of the right panel in px; default 420. */
  width?: 420 | 560;
  /** false (the spot card on the map): no scrim, no focus trap, the rest of the page stays usable. */
  modal?: boolean;
}

/**
 * A panel that slides in from the right or rests at the bottom (docs/truck-planner/05_FRONTEND.md
 * 3.7). It renders through a portal into <body>, like Modal. The bottom sheet has no drag gesture:
 * a labelled button expands and collapses it.
 */
export default function Sheet({ open, onClose, title, children, footer, side = 'right', width = 420, modal = true }: SheetProps) {
  const titleId = useId();
  const panel = useRef<HTMLElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const pressedOnScrim = useRef(false);
  const [expanded, setExpanded] = useState(false);
  const [top, setTop] = useState(98);
  useScrollLock(open && modal);
  useOverlayFocus(open, panel, heading, {
    trap: modal,
    initial: modal ? 'heading' : 'none',
    onEscape: onClose,
    escapeInsideOnly: !modal,
  });

  // The right panel sits under the sub-navigation: measure it, so the panel follows the real bar.
  useLayoutEffect(() => {
    if (!open || side !== 'right') return undefined;
    const measure = () => {
      const bottom = subNavBottom();
      if (bottom > 0) setTop(bottom);
    };
    measure();
    window.addEventListener('resize', measure);
    return () => window.removeEventListener('resize', measure);
  }, [open, side]);

  if (!open) return null;

  const style = { '--tp-sheet-top': String(top) + 'px', '--tp-sheet-width': String(width) + 'px' } as CSSProperties;

  const body = (
    <>
      <header className="flex flex-none items-center justify-between gap-2 border-b pl-4 pr-1.5 py-1.5" style={{ borderColor: 'var(--line-soft)' }}>
        <h2 id={titleId} ref={heading} tabIndex={-1} className="min-w-0 truncate text-base font-extrabold focus:outline-none" style={{ color: 'var(--ink)' }}>
          {title}
        </h2>
        <span className="flex flex-none items-center">
          {side === 'bottom' ? (
            <button
              type="button"
              onClick={() => setExpanded((v) => !v)}
              aria-expanded={expanded}
              className="inline-flex h-11 items-center gap-1 rounded-lg px-2.5 text-xs font-bold"
              style={{ color: 'var(--body)' }}
            >
              {expanded ? <ChevronDown size={16} aria-hidden /> : <ChevronUp size={16} aria-hidden />}
              {expanded ? FIELD.showLess : FIELD.showMore}
            </button>
          ) : null}
          <button type="button" onClick={onClose} aria-label={FIELD.close} className="inline-flex h-11 w-11 items-center justify-center rounded-lg" style={{ color: 'var(--body)' }}>
            <X size={18} aria-hidden />
          </button>
        </span>
      </header>
      <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4 text-sm font-medium" style={{ color: 'var(--body)' }}>
        {children}
      </div>
      {footer !== undefined && footer !== null ? (
        <footer className="flex flex-none flex-wrap items-center justify-end gap-2 border-t px-4 py-3" style={{ borderColor: 'var(--line-soft)' }}>
          {footer}
        </footer>
      ) : null}
    </>
  );

  const panelClass =
    (side === 'right' ? 'tp-sheet-right panel-slide-right' : 'tp-sheet-bottom panel-slide-up') + ' bg-white shadow-float focus:outline-none';

  if (!modal) {
    return createPortal(
      <div className="tp-layer" style={style}>
        <aside ref={panel} aria-label={title} tabIndex={-1} data-tp-layer="sheet" data-expanded={expanded ? 'true' : 'false'} className={panelClass}>
          {body}
        </aside>
      </div>,
      document.body,
    );
  }

  return createPortal(
    <div
      className="tp-scrim"
      style={style}
      onPointerDown={(e) => {
        pressedOnScrim.current = e.target === e.currentTarget;
      }}
      onClick={(e) => {
        if (pressedOnScrim.current && e.target === e.currentTarget) onClose();
        pressedOnScrim.current = false;
      }}
    >
      <div
        ref={panel as RefObject<HTMLDivElement>}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        data-tp-layer="sheet"
        data-expanded={expanded ? 'true' : 'false'}
        className={panelClass}
      >
        {body}
      </div>
    </div>,
    document.body,
  );
}
