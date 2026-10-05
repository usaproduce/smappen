// Truck Planner UI kit - what Modal and Sheet share: the scroll lock of the page, the focus trap,
// where focus goes on open and where it returns on close (docs/truck-planner/05_FRONTEND.md 3.7).

import { useEffect, useRef } from 'react';
import type { RefObject } from 'react';

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

const FIELDS = 'input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled])';

/**
 * The attribute a control carries while it has a use of its own for Escape (a field restoring its
 * value, a hint closing). A layer leaves that Escape to the control and closes on the next one.
 */
export const ESCAPE_MARK = 'data-tp-escape';

/** Spread on a control: the mark while `pending`, nothing otherwise. */
export function escapeMark(pending: boolean): { 'data-tp-escape'?: '' } {
  return pending ? { 'data-tp-escape': '' } : {};
}

/** The elements of a panel that Tab can reach, in document order. */
export function focusableIn(root: HTMLElement): HTMLElement[] {
  const out: HTMLElement[] = [];
  const found = root.querySelectorAll<HTMLElement>(FOCUSABLE);
  for (let i = 0; i < found.length; i++) {
    const el = found[i];
    if (el.getAttribute('aria-hidden') === 'true') continue;
    if (el.getClientRects().length === 0) continue; // not rendered
    out.push(el);
  }
  return out;
}

let locks = 0;
let overflowBefore = '';

/** While at least one modal layer is open the page behind it does not scroll. */
export function useScrollLock(active: boolean): void {
  useEffect(() => {
    if (!active) return undefined;
    if (locks === 0) {
      overflowBefore = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
    }
    locks += 1;
    return () => {
      locks -= 1;
      if (locks === 0) document.body.style.overflow = overflowBefore;
    };
  }, [active]);
}

// Open layers, oldest first. Only the newest answers Escape and traps Tab, so a confirmation opened
// from a sheet closes on its own and leaves the sheet where it was.
const layers: object[] = [];

export interface OverlayFocusOptions {
  /** Keep Tab inside the panel. */
  trap: boolean;
  /**
   * Where focus lands on open: the first field when there is one, else the heading. "none" leaves
   * focus where it is (a sheet that is not modal: the rest of the page stays in use).
   */
  initial: 'field' | 'heading' | 'none';
  /** Escape closes; null leaves the key alone. */
  onEscape: (() => void) | null;
  /** Escape closes only while focus is inside the panel (a sheet that is not modal). */
  escapeInsideOnly?: boolean;
}

/**
 * Focus management of a layer: on open, remember what had focus and move focus into the panel; on
 * close, give it back to the opener.
 */
export function useOverlayFocus(
  open: boolean,
  panel: RefObject<HTMLElement>,
  heading: RefObject<HTMLElement>,
  options: OverlayFocusOptions,
): void {
  const opts = useRef(options);
  opts.current = options;

  useEffect(() => {
    if (!open) return undefined;
    const token = {};
    layers.push(token);
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const root = panel.current;
    if (root !== null && opts.current.initial !== 'none') {
      const field = opts.current.initial === 'field' ? root.querySelector<HTMLElement>(FIELDS) : null;
      const target = field !== null ? field : heading.current !== null ? heading.current : root;
      target.focus({ preventScroll: true });
    }

    const onKey = (e: KeyboardEvent) => {
      const current = opts.current;
      const here = panel.current;
      if (here === null || layers[layers.length - 1] !== token) return;
      if (e.key === 'Escape' && current.onEscape !== null) {
        if (current.escapeInsideOnly === true && !here.contains(document.activeElement)) return;
        // Escape goes to the innermost thing that can use it: a field holding an edit it has not
        // committed, an open hint. Such a control marks itself and takes the key; the next Escape
        // closes the layer.
        if (e.target instanceof Element && here.contains(e.target) && e.target.closest('[' + ESCAPE_MARK + ']') !== null) return;
        e.stopPropagation();
        current.onEscape();
        return;
      }
      if (e.key !== 'Tab' || !current.trap) return;
      const items = focusableIn(here);
      if (items.length === 0) {
        e.preventDefault();
        here.focus({ preventScroll: true });
        return;
      }
      const first = items[0];
      const last = items[items.length - 1];
      const active = document.activeElement;
      if (!here.contains(active)) {
        e.preventDefault();
        first.focus();
      } else if (e.shiftKey && (active === first || active === here || active === heading.current)) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && active === last) {
        e.preventDefault();
        first.focus();
      }
    };
    document.addEventListener('keydown', onKey, true);
    return () => {
      document.removeEventListener('keydown', onKey, true);
      const at = layers.indexOf(token);
      if (at >= 0) layers.splice(at, 1);
      // Give focus back to the opener, unless the owner has already moved on to something else.
      const here = panel.current;
      const inside = here !== null && here.contains(document.activeElement);
      const lost = document.activeElement === null || document.activeElement === document.body;
      if (opener !== null && document.contains(opener) && (inside || lost)) opener.focus({ preventScroll: true });
    };
  }, [open, panel, heading]);
}

/** The bottom edge of the Truck Planner sub-navigation, so a side sheet can sit right under it. */
export function subNavBottom(): number {
  const nav = document.querySelector('nav[aria-label="Truck Planner sections"]');
  if (nav === null) return 0;
  const bottom = nav.getBoundingClientRect().bottom;
  return bottom > 0 ? Math.floor(bottom) : 0;
}
