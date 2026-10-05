// Truck Planner map engine - a Maps key that Google refuses (docs/truck-planner/05_FRONTEND.md 5.6).
//
// With an invalid key Google replaces the map by an error panel, removes our overlay and stops
// calling it, while the loader still reports "loaded" and no load error. The only signal is the
// global `gm_authFailure`, which Google calls about a second after the map appears.
//
// TruckPages.ts imports this module for its side effect: once, when the lazy chunk loads, it installs
// that global (keeping any earlier handler) and records the refusal in `truckUiStore.mapsAuthFailed`.
// The map then switches to the blank base and the address fields fall back to coordinates.

import { useTruckUiStore } from '../../../stores/truckUiStore';

interface MapsGlobals {
  gm_authFailure?: () => void;
  __tpAuthFailureInstalled?: boolean;
}

function install(): void {
  if (typeof window === 'undefined') return;
  const w = window as unknown as MapsGlobals;
  // Once per page: the chunk may be evaluated again by a development reload.
  if (w.__tpAuthFailureInstalled === true) return;
  w.__tpAuthFailureInstalled = true;
  const earlier = w.gm_authFailure;
  w.gm_authFailure = () => {
    try {
      useTruckUiStore.getState().patch({ mapsAuthFailed: true });
    } catch {
      // the store is the only thing to tell
    }
    if (typeof earlier === 'function') {
      try {
        earlier();
      } catch {
        // an earlier handler's failure is its own
      }
    }
  };
}

install();

export {};
