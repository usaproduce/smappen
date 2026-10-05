import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import type { MapLayer } from '../../../utils/truck/model';
import { HINT_ACTION, hintLine, hintPlace, type Box } from '../../../utils/truck/hourControl';
import type { MapHit } from '../map/types';

export interface HoverHintProps {
  layer: MapLayer;
  /** False while the hint has nothing true to say (pick mode: a click picks, it does not estimate). */
  enabled: boolean;
  /** The box of the map on screen: the hint stays inside it. Null falls back to the window. */
  bounds: () => Box | null;
}

export interface HoverHintHandle {
  /** The hovered cell and where the pointer is, or null when the pointer left the map. */
  show(hit: MapHit | null): void;
}

const FINE_POINTER = '(hover: hover) and (pointer: fine)';

/** True on a device with a mouse or a trackpad; a finger has no hover. */
function useFinePointer(): boolean {
  const [fine, setFine] = useState(() => window.matchMedia(FINE_POINTER).matches);
  useEffect(() => {
    const query = window.matchMedia(FINE_POINTER);
    const update = () => setFine(query.matches);
    update();
    query.addEventListener('change', update);
    return () => query.removeEventListener('change', update);
  }, []);
  return fine;
}

/**
 * The hint that follows the pointer over the map (docs/truck-planner/05_FRONTEND.md 4.2), on fine
 * pointers only. Its first line is the legend band of the hovered cell and never a single figure:
 * a figure would come from the quantised map data and would have no range and no confidence label.
 * The estimate is one click away, which is what the second line says.
 *
 * It is moved imperatively through its handle: a pointer move never causes a React render.
 */
const HoverHint = forwardRef<HoverHintHandle, HoverHintProps>(function HoverHint({ layer, enabled, bounds }, ref) {
  const fine = useFinePointer();
  const box = useRef<HTMLDivElement>(null);
  const band = useRef<HTMLSpanElement>(null);
  const hit = useRef<MapHit | null>(null);
  const size = useRef<{ text: string; width: number; height: number } | null>(null);
  const current = useRef({ layer, enabled, bounds });
  current.current = { layer, enabled, bounds };

  const paint = () => {
    const el = box.current;
    if (el === null) return;
    const at = hit.current;
    if (at === null || !current.current.enabled) {
      el.style.display = 'none';
      return;
    }
    const text = hintLine(current.current.layer, at.byte);
    el.style.display = 'block';
    if (band.current !== null && (size.current === null || size.current.text !== text)) {
      band.current.textContent = text;
      size.current = { text, width: el.offsetWidth, height: el.offsetHeight };
    }
    const measured = size.current;
    const place = hintPlace(
      at.clientX,
      at.clientY,
      measured === null ? 0 : measured.width,
      measured === null ? 0 : measured.height,
      current.current.bounds() ?? { left: 0, top: 0, right: window.innerWidth, bottom: window.innerHeight },
    );
    el.style.transform = 'translate(' + String(place.x) + 'px, ' + String(place.y) + 'px)';
  };

  useImperativeHandle(
    ref,
    () => ({
      show: (next) => {
        hit.current = next;
        paint();
      },
    }),
    [],
  );

  // Another layer, or pick mode switching on or off, changes what a resting pointer reads.
  useEffect(paint);

  if (!fine) return null;
  return createPortal(
    <div
      ref={box}
      aria-hidden="true"
      className="tp-hint bg-white rounded-lg border shadow-float px-2.5 py-1.5"
      style={{ display: 'none', borderColor: 'var(--line-soft)' }}
    >
      <span ref={band} className="block font-extrabold tabular-nums whitespace-nowrap" />
      <span className="block whitespace-nowrap" style={{ color: 'var(--body)' }}>
        {HINT_ACTION}
      </span>
    </div>,
    document.body,
  );
});

export default HoverHint;
