import { useEffect, useId, useRef, useState, type CSSProperties } from 'react';
import { Crosshair, Home, SlidersHorizontal } from 'lucide-react';
import { Toggle } from '../ui';

export interface MapToolsProps {
  /** `card`: a floating card with everything in view. `menu`: one "Map options" button that opens the same card. */
  variant: 'card' | 'menu';
  showSpots: boolean;
  onShowSpots: (on: boolean) => void;
  /** False while there are no Scout results to show: the toggle is then left out. */
  scoutAvailable: boolean;
  showScout: boolean;
  onShowScout: (on: boolean) => void;
  /** Opens the spot card for the centre of the map: the way in for keyboard and screen-reader use. */
  onEstimateCentre: () => void;
  onGoToBase: () => void;
}

/** Width of the tools card in px: the long button label takes two lines in it. */
const CARD_WIDTH = 236;

/** The two actions read as rows of a list: left-aligned, and free to take a second line. */
const ACTION_STYLE: CSSProperties = { justifyContent: 'flex-start', textAlign: 'left', lineHeight: 1.25, padding: '6px 12px' };

/**
 * The tools of the map (docs/truck-planner/05_FRONTEND.md 4.2): which pins show, an estimate at the
 * centre of the map for those who cannot click it, and the way back to the base.
 */
export default function MapTools(props: MapToolsProps) {
  const { variant, showSpots, onShowSpots, scoutAvailable, showScout, onShowScout, onEstimateCentre, onGoToBase } = props;
  const ids = useId();
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const menu = variant === 'menu';

  // The menu closes on Escape and on a press anywhere else.
  useEffect(() => {
    if (!menu || !open) return undefined;
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return;
      e.stopPropagation();
      setOpen(false);
      root.current?.querySelector<HTMLButtonElement>('button[aria-haspopup]')?.focus();
    };
    const onPointer = (e: PointerEvent) => {
      if (root.current !== null && e.target instanceof Node && root.current.contains(e.target)) return;
      setOpen(false);
    };
    document.addEventListener('keydown', onKey, true);
    document.addEventListener('pointerdown', onPointer);
    return () => {
      document.removeEventListener('keydown', onKey, true);
      document.removeEventListener('pointerdown', onPointer);
    };
  }, [menu, open]);

  const body = (
    <div className="space-y-2">
      <Toggle id={ids + 'spots'} label="Saved spots" checked={showSpots} onChange={onShowSpots} />
      {scoutAvailable ? <Toggle id={ids + 'scout'} label="Scout results" checked={showScout} onChange={onShowScout} /> : null}
      <button
        type="button"
        className="btn btn-secondary min-h-11 md:min-h-9 w-full text-sm"
        style={ACTION_STYLE}
        onClick={() => {
          setOpen(false);
          onEstimateCentre();
        }}
      >
        <Crosshair size={15} aria-hidden="true" className="flex-none" /> Estimate at the centre of the map
      </button>
      <button
        type="button"
        className="btn btn-secondary min-h-11 md:min-h-9 w-full text-sm"
        style={ACTION_STYLE}
        onClick={() => {
          setOpen(false);
          onGoToBase();
        }}
      >
        <Home size={15} aria-hidden="true" className="flex-none" /> Go to base
      </button>
    </div>
  );

  if (!menu) {
    return (
      <section
        aria-label="Map tools"
        className="bg-white rounded-xl border shadow-float p-3"
        style={{ borderColor: 'var(--line-soft)', width: CARD_WIDTH }}
      >
        {body}
      </section>
    );
  }

  return (
    <div ref={root} className="relative flex flex-col items-end">
      <button
        type="button"
        aria-label="Map options"
        aria-haspopup="true"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        className="bg-white rounded-xl border shadow-float inline-flex h-11 w-11 items-center justify-center"
        style={{ borderColor: 'var(--line-soft)', color: 'var(--ink)' }}
      >
        <SlidersHorizontal size={18} aria-hidden="true" />
      </button>
      {open ? (
        <section
          aria-label="Map options"
          className="bg-white rounded-xl border shadow-float absolute right-0 top-full z-10 mt-2 p-3"
          style={{ borderColor: 'var(--line-soft)', width: CARD_WIDTH }}
        >
          {body}
        </section>
      ) : null}
    </div>
  );
}
