import { createContext, useContext, useLayoutEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import type { MapPinKind, MapPinProps } from './types';

/**
 * One pin as the map keeps it: the anchor element and the place it stands on. The map moves the
 * anchor on every viewport change by setting its `transform`, with no React render.
 */
export interface PinEntry {
  el: HTMLElement;
  lat: number;
  lng: number;
}

/** What `TruckMap` gives its pins: where to render, and how to be kept in place. */
export interface PinRegistry {
  /** The host's pin layer: pins are portalled into it, so the same pins work on both base maps. */
  layer: HTMLElement;
  /** Start keeping an anchor in place. Returns the function that stops it. */
  add(entry: PinEntry): () => void;
  /** Place one anchor now: after its coordinates changed. */
  place(entry: PinEntry): void;
}

export const MapPinContext = createContext<PinRegistry | null>(null);

/** Stacking order, lowest first (5.7). The hover outline sits under all of them. */
const Z_INDEX: Record<MapPinKind, number> = { scout: 1, spot: 2, selected: 3, base: 4 };

/**
 * A pin on the map (docs/truck-planner/05_FRONTEND.md 5.7). Its children, a real button, are rendered
 * through a portal into the pin layer of whichever base map is showing. The anchor is a zero-size
 * element on the coordinate: the pin is laid out from that point and moves itself to where it belongs
 * (`translate(-50%, -100%)` for a teardrop, `translate(-50%, -50%)` for a dot).
 *
 * Outside a `TruckMap` it renders nothing.
 */
export default function MapPin({ lat, lng, kind = 'spot', children }: MapPinProps) {
  const registry = useContext(MapPinContext);
  const anchor = useRef<HTMLDivElement | null>(null);
  const entry = useRef<PinEntry | null>(null);

  useLayoutEffect(() => {
    if (registry === null || anchor.current === null) return undefined;
    const mine: PinEntry = { el: anchor.current, lat, lng };
    entry.current = mine;
    const remove = registry.add(mine);
    return () => {
      entry.current = null;
      remove();
    };
    // The coordinates are followed by the effect below: this one runs once per registry.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [registry]);

  useLayoutEffect(() => {
    const mine = entry.current;
    if (registry === null || mine === null) return;
    if (mine.lat === lat && mine.lng === lng) return;
    mine.lat = lat;
    mine.lng = lng;
    registry.place(mine);
  }, [registry, lat, lng]);

  if (registry === null) return null;
  return createPortal(
    <div
      ref={anchor}
      className="tp-pin"
      data-tp-pin={kind}
      // Hidden until the map has placed it, so a pin never flashes in the corner.
      style={{ zIndex: Z_INDEX[kind], width: 0, height: 0, visibility: 'hidden' }}
    >
      {children}
    </div>,
    registry.layer,
  );
}
