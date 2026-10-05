// Truck Planner - the single import point for model code (docs/truck-planner/05_FRONTEND.md 2.5).
//
// The estimator port in ./estimator/ is the TypeScript runtime of the model of 02_MODEL.md. Truck
// code never imports from that directory directly (the one exception is `import type` of the data
// shapes in api/truck.ts): screens, hooks, stores and helpers import functions and types from here.
// If the port names an export differently, this is the only file that changes.
//
// What comes through: every catalogue function in camelCase (dayContext, windowOrders, dayPlan, ...),
// the map fast path (mapWeightRows, scoreByte, the bulk scorers, MAP_DOMAIN), every data shape of
// 02_MODEL section 3 as a type, and SEEDS, MODEL_VERSION and SEEDS_REVISION under those names.
//
// Model maths is never re-implemented outside the port (rule R7).

export * from './estimator/index';

/**
 * Marks the lazy Truck Planner chunk. `TruckGate` renders it as the `data-tp-chunk` attribute, so a
 * build cannot tree-shake it; `frontend/scripts/check-truck-chunks.mjs` then asserts that the string
 * is in `TruckPages-*.js` and in no other script, which proves model code stayed out of the main bundle.
 */
export const TP_CHUNK_SENTINEL = 'tp-chunk-sentinel';
