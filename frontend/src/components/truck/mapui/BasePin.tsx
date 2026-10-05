import { Home } from 'lucide-react';
import MapPin from '../map/MapPin';

export interface BasePinProps {
  lat: number;
  lng: number;
  /** A click on the base: the page opens the estimate at that point. */
  onOpen: () => void;
}

/**
 * The truck's base on the map (docs/truck-planner/05_FRONTEND.md 4.2): a 28 px ink square with the
 * home glyph and the label "Base". It is the place the owner typed or picked, never a position of
 * the device.
 */
export default function BasePin({ lat, lng, onOpen }: BasePinProps) {
  return (
    <MapPin lat={lat} lng={lng} kind="base">
      <button
        type="button"
        aria-label="Base"
        onClick={(e) => {
          e.stopPropagation();
          onOpen();
        }}
        className="absolute flex h-11 w-11 items-center justify-center"
        style={{ transform: 'translate(-50%, -50%)' }}
      >
        <span
          aria-hidden="true"
          className="flex h-7 w-7 items-center justify-center rounded-lg"
          style={{ background: 'var(--ink)', color: 'var(--bg)', boxShadow: '0 0 0 1.5px var(--bg)' }}
        >
          <Home size={16} strokeWidth={2.5} />
        </span>
        <span
          aria-hidden="true"
          className="bg-white pointer-events-none absolute left-1/2 top-full -mt-1.5 -translate-x-1/2 rounded-full border px-2 py-0.5 text-[11px] font-bold shadow-float"
          style={{ borderColor: 'var(--line-soft)', color: 'var(--ink)' }}
        >
          Base
        </span>
      </button>
    </MapPin>
  );
}
