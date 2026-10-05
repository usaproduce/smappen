import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import type { LucideIcon } from 'lucide-react';
import { escapeMark } from './overlay';

export interface HintChipProps {
  icon: LucideIcon;
  label: string;
  /** The sentence the hint opens. */
  sentence: string;
  /** Label and sentence as one spoken line. */
  spoken: string;
  size?: 'sm' | 'md';
  /** Hover, focus or tap opens a 240 px popover with the sentence. */
  hint?: boolean;
  /** Share of the glyph's box that is drawn, from the left (1 = all of it): the empty rest is cropped. */
  glyphShare?: number;
}

interface Placement {
  left: number;
  top: number;
  zIndex: number;
}

const POPOVER_WIDTH = 240;
const GAP = 6;
const EDGE = 8;

/**
 * A neutral chip: a glyph and a word on the panel background. It is the body of ConfidenceChip and
 * SeedTag. Neutral on purpose: the freshness and money colours mean something else, and the glyph
 * carries the difference between labels, never the colour.
 */
export default function HintChip({ icon: Icon, label, sentence, spoken, size = 'md', hint = false, glyphShare = 1 }: HintChipProps) {
  const id = useId();
  const button = useRef<HTMLButtonElement>(null);
  const [hovered, setHovered] = useState(false);
  const [focused, setFocused] = useState(false);
  const [pinned, setPinned] = useState(false);
  const [place, setPlace] = useState<Placement | null>(null);
  const open = hint && (hovered || focused || pinned);

  // The popover is portalled to <body> and placed from the chip's box: an ancestor that scrolls or
  // carries a transform (a table wrapper, the page fade) cannot clip or shift it. It follows the
  // chip when the page scrolls (focus by keyboard scrolls the chip into view first).
  useLayoutEffect(() => {
    if (!open) {
      setPlace(null);
      return undefined;
    }
    const update = () => {
      const el = button.current;
      if (el === null) return;
      const box = el.getBoundingClientRect();
      const width = Math.min(POPOVER_WIDTH, window.innerWidth - 2 * EDGE);
      let left = box.left;
      if (left + width > window.innerWidth - EDGE) left = window.innerWidth - EDGE - width;
      if (left < EDGE) left = EDGE;
      const below = box.bottom + GAP;
      // near the bottom of the window it opens above the chip instead (about four lines of text)
      const top = below + 96 > window.innerHeight && box.top > 120 ? box.top - GAP - 96 : below;
      // above a modal or a sheet when the chip sits in one, else on the popover level of the page
      const inLayer = el.closest('[data-tp-layer]') !== null;
      setPlace({ left, top, zIndex: inLayer ? 60 : 40 });
    };
    update();
    window.addEventListener('scroll', update, true);
    window.addEventListener('resize', update);
    return () => {
      window.removeEventListener('scroll', update, true);
      window.removeEventListener('resize', update);
    };
  }, [open]);

  useEffect(() => {
    if (!open) return undefined;
    const close = () => {
      setHovered(false);
      setFocused(false);
      setPinned(false);
    };
    // Inside a modal or a sheet the chip is marked while its hint is open (overlay.ts), so this
    // Escape closes the hint and leaves the layer for the next one.
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') close();
    };
    const onPointer = (e: PointerEvent) => {
      if (button.current !== null && e.target instanceof Node && button.current.contains(e.target)) return;
      close();
    };
    document.addEventListener('keydown', onKey);
    document.addEventListener('pointerdown', onPointer);
    return () => {
      document.removeEventListener('keydown', onKey);
      document.removeEventListener('pointerdown', onPointer);
    };
  }, [open]);

  const className = 'tp-chip' + (size === 'sm' ? ' tp-chip-sm' : '');
  const glyphSize = size === 'sm' ? 12 : 13;
  const glyph = (
    <span aria-hidden className="inline-flex flex-none overflow-hidden" style={{ width: glyphSize * glyphShare }}>
      <Icon size={glyphSize} strokeWidth={2.75} className="flex-none" />
    </span>
  );

  if (!hint) {
    return (
      <span role="status" aria-label={spoken} className={className}>
        {glyph}
        {label}
      </span>
    );
  }

  return (
    <span role="status" aria-label={spoken} className="inline-flex">
      <button
        ref={button}
        type="button"
        className={className}
        {...escapeMark(open)}
        aria-expanded={open}
        aria-describedby={open ? id : undefined}
        onPointerEnter={(e) => {
          if (e.pointerType === 'mouse') setHovered(true);
        }}
        onPointerLeave={() => setHovered(false)}
        onFocus={() => setFocused(true)}
        onBlur={() => {
          setFocused(false);
          setPinned(false);
        }}
        onClick={(e) => {
          // a chip inside a clickable row or card must not trigger it
          e.stopPropagation();
          if (pinned) {
            setPinned(false);
            setFocused(false);
          } else {
            setPinned(true);
          }
        }}
      >
        {glyph}
        {label}
      </button>
      {open && place !== null
        ? createPortal(
            <span id={id} role="tooltip" className="tp-popover bg-white shadow-float" style={{ left: place.left, top: place.top, zIndex: place.zIndex }}>
              {sentence}
            </span>,
            document.body,
          )
        : null}
    </span>
  );
}
