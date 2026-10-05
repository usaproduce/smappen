import { useId, useRef } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { FIELD } from '../../../utils/truck/wording';
import { useOverlayFocus, useScrollLock } from './overlay';

export interface ModalProps {
  open: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
  footer?: ReactNode;
  /** Max width 400 / 520 / 720 px; default "md". */
  size?: 'sm' | 'md' | 'lg';
  /** Escape and a click on the scrim close it; default true. */
  dismissible?: boolean;
}

const WIDTH: Record<NonNullable<ModalProps['size']>, string> = {
  sm: 'sm:max-w-[400px]',
  md: 'sm:max-w-[520px]',
  lg: 'sm:max-w-[720px]',
};

/**
 * A dialog (docs/truck-planner/05_FRONTEND.md 3.7). Centred from 640 px; below that a bottom panel
 * that scrolls inside. A plain scrim, no blur. Focus is trapped, lands on the first field or the
 * heading, and returns to the opener on close.
 */
export default function Modal({ open, onClose, title, children, footer, size = 'md', dismissible = true }: ModalProps) {
  const titleId = useId();
  const panel = useRef<HTMLDivElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const pressedOnScrim = useRef(false);
  useScrollLock(open);
  useOverlayFocus(open, panel, heading, { trap: true, initial: 'field', onEscape: dismissible ? onClose : null });

  if (!open) return null;

  return createPortal(
    <div
      className="tp-scrim flex items-end sm:items-center justify-center sm:p-4"
      onPointerDown={(e) => {
        pressedOnScrim.current = e.target === e.currentTarget;
      }}
      onClick={(e) => {
        // only a press that began and ended on the scrim closes: a drag out of a field does not
        if (dismissible && pressedOnScrim.current && e.target === e.currentTarget) onClose();
        pressedOnScrim.current = false;
      }}
    >
      <div
        ref={panel}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        data-tp-layer="modal"
        className={
          'tp-modal panel-slide-up flex w-full flex-col bg-white shadow-float border focus:outline-none rounded-t-xl sm:rounded-xl ' + WIDTH[size]
        }
        style={{ borderColor: 'var(--line-soft)' }}
      >
        <header className="flex flex-none items-start justify-between gap-3 px-4 sm:px-5 pt-4 pb-3">
          <h2 id={titleId} ref={heading} tabIndex={-1} className="text-base font-extrabold leading-snug focus:outline-none" style={{ color: 'var(--ink)' }}>
            {title}
          </h2>
          {dismissible ? (
            <button
              type="button"
              onClick={onClose}
              aria-label={FIELD.close}
              className="-mr-2 -mt-2 inline-flex h-11 w-11 flex-none items-center justify-center rounded-lg"
              style={{ color: 'var(--body)' }}
            >
              <X size={18} aria-hidden />
            </button>
          ) : null}
        </header>
        <div className="min-h-0 flex-1 overflow-y-auto px-4 sm:px-5 pb-4 text-sm font-medium" style={{ color: 'var(--body)' }}>
          {children}
        </div>
        {footer !== undefined && footer !== null ? (
          <footer className="flex flex-none flex-wrap items-center justify-end gap-2 border-t px-4 sm:px-5 py-3" style={{ borderColor: 'var(--line-soft)' }}>
            {footer}
          </footer>
        ) : null}
      </div>
    </div>,
    document.body,
  );
}
