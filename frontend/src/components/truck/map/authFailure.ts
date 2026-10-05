// STUB (docs/truck-planner/05_FRONTEND.md 5.6 and 9.2). The map engine package replaces the body.
//
// TruckPages.ts imports this module for its side effect: once, when the lazy chunk loads, it
// installs `window.gm_authFailure` (keeping any earlier handler) and sets
// `truckUiStore.mapsAuthFailed` when Google refuses the Maps key, so the map can switch to the blank
// base and the address fields can fall back to coordinates.
//
// Until then it does nothing.

export {};
