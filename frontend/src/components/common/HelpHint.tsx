import { useState, useRef, useEffect } from 'react';
import { HelpCircle } from 'lucide-react';
import { createPortal } from 'react-dom';

/**
 * #25 — Inline contextual help. A small `?` button users can click for a
 * short blurb about whatever surface they're looking at. Hover-to-open with
 * a 200ms delay; clicks lock it open so the user can copy text or follow a
 * link inside.
 *
 * Usage:
 *   <HelpHint title="Analog Finder">
 *     This is what the Analog Finder does in 2-3 sentences. Optionally
 *     a <a href="https://docs.smappen.com/...">link</a> to deeper docs.
 *   </HelpHint>
 *
 * Placed inline next to panel/section headings — keeps "what is this?" out
 * of the support inbox.
 */
export default function HelpHint({
  title,
  children,
  size = 13,
}: {
  title?: string;
  children: React.ReactNode;
  size?: number;
}) {
  const [open, setOpen] = useState(false);
  const [locked, setLocked] = useState(false);
  const btnRef = useRef<HTMLButtonElement>(null);
  const [pos, setPos] = useState<{ top: number; left: number } | null>(null);

  useEffect(() => {
    if (!open || !btnRef.current) { setPos(null); return; }
    const r = btnRef.current.getBoundingClientRect();
    // Open to the right when there's room; otherwise to the left.
    const popoverW = 280;
    const left = r.right + popoverW + 16 < window.innerWidth
      ? r.right + 8
      : Math.max(8, r.left - popoverW - 8);
    setPos({ top: r.top, left });
  }, [open]);

  // Close on Esc / outside-click when locked.
  useEffect(() => {
    if (!locked) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') { setLocked(false); setOpen(false); } };
    const onClick = (e: MouseEvent) => {
      if (btnRef.current?.contains(e.target as Node)) return;
      if ((e.target as HTMLElement)?.closest?.('[data-help-popover]')) return;
      setLocked(false); setOpen(false);
    };
    window.addEventListener('keydown', onKey);
    document.addEventListener('mousedown', onClick);
    return () => { window.removeEventListener('keydown', onKey); document.removeEventListener('mousedown', onClick); };
  }, [locked]);

  return (
    <>
      <button
        ref={btnRef}
        type="button"
        className="inline-flex items-center text-slate-400 hover:text-violet-700 transition-colors"
        title="What is this?"
        onMouseEnter={() => setOpen(true)}
        onMouseLeave={() => !locked && setOpen(false)}
        onClick={(e) => { e.stopPropagation(); setLocked(!locked); setOpen(true); }}
      >
        <HelpCircle size={size} />
      </button>
      {open && pos && createPortal(
        <div
          data-help-popover
          className="fixed z-[300] w-[280px] bg-white rounded-lg shadow-2xl border border-slate-200 p-3 card-expand"
          style={{ top: pos.top, left: pos.left }}
          onMouseEnter={() => setOpen(true)}
          onMouseLeave={() => !locked && setOpen(false)}
        >
          {title && <div className="text-[10px] uppercase font-bold tracking-wider text-violet-700 mb-1">{title}</div>}
          <div className="text-xs text-slate-700 leading-relaxed">{children}</div>
        </div>,
        document.body,
      )}
    </>
  );
}
