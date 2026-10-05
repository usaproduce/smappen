// Truck Planner estimator (model tps-0.1.0) - the TypeScript port of
// docs/truck-planner/reference/truck_planner_reference.py, as explained in docs/truck-planner/02_MODEL.md.
//
// Every function of the model catalogue is exported here in camelCase. All of it is pure: no clock, no
// random numbers, no locale, no time zone, no I/O, and no import from outside this folder, so it runs
// unchanged in Node, in the browser and in a Web Worker. The same golden cases
// (tests/fixtures/truck-planner/golden_cases.json) hold this port, the PHP port and the reference
// together; GOLDEN_DISPATCH is the by-name entry point their runner uses.

export type * from './types';

// 1. Conventions
export {
  CONFIDENCE_LABELS,
  DAYPARTS,
  DAY_TYPES,
  DOW_KEYS,
  EARTH_RADIUS_M,
  LN2,
  METERS_PER_MILE,
  MODEL_ERRORS,
  MODEL_VERSION,
  ModelError,
  NSEG,
  OVERRIDE_ERRORS,
  PI,
  PLACE_TYPES,
  QKEY_SCALE,
  REGIMES,
  RIVAL_KINDS,
  ROUND_HALF,
  SEGMENTS,
  VISIBILITY_LEVELS,
  WARNING_CODES,
  Z80,
  clamp,
  floorDiv,
  modFloor,
  qkey,
  roundHalfAway,
  segmentIndex,
} from './core';

// 2. Seeds
export { REGION_NONE, SEEDS, SEEDS_REVISION, makeAssumptions, seed, validateOverrides } from './seeds';

// 3. Helpers on Estimate
export { estFixed, estLevels, estSum, weakest } from './estimate';

// 4.1 Dates
export {
  addDays,
  civilFromDays,
  dateOfDay,
  dayContext,
  dayNumber,
  dayOfWeek,
  daysFromCivil,
  federalHolidays,
  formatDate,
  holidayOn,
  lastWeekday,
  makeContext,
  nthWeekday,
  parseDate,
  typicalContext,
} from './dates';

// 4.2 Curves
export { expandCurves, hourWeights } from './curves';

// 4.3 Geometry
export { haversineM, walkWeight } from './geometry';

// 4.4 Capture, 4.5 Host term
export { captureAtPoint, hostCapture, hostExclusion, hostLinkPoint, rivalsAtOrigin } from './capture';

// 4.6 Weather
export { asciiLower, weatherMultiplier } from './weather';

// 4.7 Demand and orders
export {
  bestWindows,
  calibrationFactor,
  clockHours,
  hourlyOrders,
  vectorsMatch,
  weekStrip,
  windowOrders,
} from './demand';

// 4.8 Ranges and confidence
export { evidenceFrom, interval, intervalCapped } from './ranges';

// 4.9 Money
export { MONEY_LINES, breakEvenOrders, dayCosts, stopMoney, stopMoneyAt, unitMargins } from './money';

// 4.10 Driving
export { fallbackLeg, legMinutes, trafficFactor } from './driving';

// 4.11 Timeline
export { buildTimeline, emptyTimeline, requiredLegKeys } from './timeline';

// 4.12 Day plan
export { dayPlan, evaluate } from './plan';

// 4.13 Calibration and accuracy
export { accuracyReport, calibrate } from './calibration';

// 4.14 Events and catering
export { cateringMoney, eventOrders } from './events';

// 4.15 Suggestions
export { suggestDay, suggestWeek } from './suggest';

// 4.16 Scouting
export { scoutEstimate, scoutRank, stripFromRows } from './scout';

// 4.17 Map fast path: the definition, and the bulk scorer the map uses
export { cellScores, dayWeightRows, mapWeightRows, scoreByte } from './mapRows';
export {
  COL_CAPTURE_DAY,
  COL_CAPTURE_EVE,
  COL_NEARBY,
  COL_RIVALS_DAY,
  COL_RIVALS_EVE,
  FEATURES_PER_CELL,
  FEATURE_COLUMNS,
  HOURS_PER_WEEK,
  MAP_DOMAIN,
  MAP_LAYERS,
  precomputeMapWeights,
  scoreCells,
  scoreCellsWithRows,
  scoreLayer,
  scoresToBytes,
} from './fastPath';
export type { MapWeightTable } from './fastPath';

// 8.1 Golden cases: dispatch by canonical name
export { GOLDEN_DISPATCH } from './dispatch';
