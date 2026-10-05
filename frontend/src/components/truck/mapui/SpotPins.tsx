import type { Spot } from '../../../api/truck';
import MapPin from '../map/MapPin';

export interface SpotPinsProps {
  /** The saved spots to show (not archived). */
  spots: readonly Spot[];
  /** The spot whose card is open. */
  selectedId: string | null;
  /** True from zoom 13: every pin shows its name. Below, a name shows on hover or focus. */
  showNames: boolean;
  onOpen: (spot: Spot) => void;
}

/** A pin's own offset inside the positioned wrapper of `MapPin`: its tip, or its centre, sits on the point. */
const TIP_ON_POINT = { transform: 'translate(-50%, -100%)' } as const;
const CENTRE_ON_POINT = { transform: 'translate(-50%, -50%)' } as const;

/**
 * The saved spots on the map (docs/truck-planner/05_FRONTEND.md 4.2): a 28 px teardrop in the brand
 * colour with a light dot, each a real button named after its spot. The button is 44 px so a finger
 * can hit it. A click on a pin opens that spot's card and never reaches the map under it.
 */
export default function SpotPins({ spots, selectedId, showNames, onOpen }: SpotPinsProps) {
  return (
    <>
      {spots.map((spot) => {
        const selected = spot.id === selectedId;
        return (
          <MapPin key={spot.id} lat={spot.point.lat} lng={spot.point.lng} kind="spot">
            <button
              type="button"
              aria-label={'Open spot ' + spot.name}
              aria-pressed={selected}
              onClick={(e) => {
                e.stopPropagation();
                onOpen(spot);
              }}
              className="group absolute flex h-11 w-11 items-end justify-center"
              style={TIP_ON_POINT}
            >
              <svg width="22" height="28" viewBox="0 0 22 28" aria-hidden="true" style={{ overflow: 'visible' }}>
                <path
                  d="M11 27 C11 27 1 16.4 1 10.6 A10 10 0 1 1 21 10.6 C21 16.4 11 27 11 27 Z"
                  fill="var(--brand)"
                  stroke={selected ? 'var(--ink)' : 'var(--bg)'}
                  strokeWidth={selected ? 2.5 : 1.5}
                />
                <circle cx="11" cy="10.6" r="3.6" fill="var(--bg)" />
              </svg>
              <span
                className={
                  'bg-white pointer-events-none absolute left-1/2 top-full mt-0.5 max-w-[180px] -translate-x-1/2 truncate rounded-full border px-2 py-0.5 text-[11px] font-bold shadow-float ' +
                  (showNames || selected ? 'block' : 'hidden group-hover:block group-focus-visible:block')
                }
                style={{ borderColor: 'var(--line-soft)', color: 'var(--ink)' }}
              >
                {spot.name}
              </span>
            </button>
          </MapPin>
        );
      })}
    </>
  );
}

export interface SelectedPointPinProps {
  lat: number;
  lng: number;
  /** A click on the ring closes the card. */
  onClose: () => void;
}

/**
 * The point whose card is open: a 20 px light ring with an ink border, centred on the exact
 * coordinates that were clicked (the hexagon is only a highlight). A click on it closes the card.
 */
export function SelectedPointPin({ lat, lng, onClose }: SelectedPointPinProps) {
  return (
    <MapPin lat={lat} lng={lng} kind="selected">
      <button
        type="button"
        aria-label="Close the estimate at this point"
        onClick={(e) => {
          e.stopPropagation();
          onClose();
        }}
        className="absolute flex h-11 w-11 items-center justify-center"
        style={CENTRE_ON_POINT}
      >
        <span
          aria-hidden="true"
          className="block h-5 w-5 rounded-full"
          style={{ border: '2px solid var(--ink)', boxShadow: 'inset 0 0 0 3px var(--bg), 0 0 0 1px var(--bg)' }}
        />
      </button>
    </MapPin>
  );
}
