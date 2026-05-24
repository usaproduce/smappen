import { useEffect } from 'react';

/**
 * Close a dropdown when the user clicks (or taps) outside its container.
 * Pass a ref to the outermost element and a `close` callback. The hook only
 * attaches the listener while `open` is true — so menus that are mostly
 * closed don't pay the global listener cost.
 *
 * Listens on `mousedown` (not `click`) so the dropdown closes before any
 * inner button click event fires — avoids fighting with controlled state.
 */
export function useClickOutside(
  ref: React.RefObject<HTMLElement>,
  close: () => void,
  open: boolean,
) {
  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent | TouchEvent) => {
      if (!ref.current) return;
      if (e.target instanceof Node && !ref.current.contains(e.target)) {
        close();
      }
    };
    document.addEventListener('mousedown', handler);
    document.addEventListener('touchstart', handler, { passive: true });
    return () => {
      document.removeEventListener('mousedown', handler);
      document.removeEventListener('touchstart', handler as any);
    };
  }, [ref, close, open]);
}
