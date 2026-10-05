// Truck Planner estimator - calibration and accuracy (02_MODEL 4.13).

import { LN2, clamp, cmpStr, max2, putOwn } from './core';
import { dayNumber } from './dates';
import { seed } from './seeds';
import type {
  AccuracyBlock,
  AccuracyReport,
  Assumptions,
  CalibrationSpot,
  CalibrationState,
  ServiceLogEntry,
  SpotAccuracyBlock,
} from './types';

interface CalibrationRow {
  spot_id: string;
  sold_out: boolean;
  w: number;
  Lt: number;
  Ls: number;
  in_truck: boolean;
  used: boolean;
}

// Services in (date ascending, service_id ascending) order. The input is not modified.
function byDateAndId(services: readonly ServiceLogEntry[]): ServiceLogEntry[] {
  return services.slice().sort((a, b) => {
    const c = cmpStr(a.date, b.date);
    return c !== 0 ? c : cmpStr(a.service_id, b.service_id);
  });
}

// The distinct values of a list, ascending.
function sortedDistinct(ids: readonly string[]): string[] {
  const sorted = ids.slice().sort(cmpStr);
  const out: string[] = [];
  for (let k = 0; k < sorted.length; k++) {
    if (k === 0 || sorted[k] !== sorted[k - 1]) out.push(sorted[k]);
  }
  return out;
}

/**
 * What the owner's logged services say as of a date: one factor for the truck, one per spot.
 *
 * Shrinkage on log ratios of actual to predicted orders, weighted by recency. A sold-out service is a
 * lower bound on demand: it is used only if it says more than the other logs say about its own spot.
 * Events and catering never enter. Ordinary statistics; nothing here is learned by a model.
 */
export function calibrate(A: Assumptions, services: readonly ServiceLogEntry[], asOf: string): CalibrationState {
  const kTruck = seed<number>(A, 'calibration.k_truck');
  const kSpot = seed<number>(A, 'calibration.k_spot');
  const halfLife = seed<number>(A, 'calibration.half_life_days');
  const lnRatio = Math.log(seed<number>(A, 'calibration.ratio_clamp'));
  const lnSpotRatio = Math.log(seed<number>(A, 'calibration.spot_ratio_clamp'));
  const minPredicted = seed<number>(A, 'calibration.min_predicted');
  const minActual = seed<number>(A, 'calibration.min_actual');
  const asOfDay = dayNumber(asOf);

  const rows: CalibrationRow[] = [];
  const sorted = byDateAndId(services);
  for (let k = 0; k < sorted.length; k++) {
    const sv = sorted[k];
    if (sv.kind !== 'spot' || sv.spot_id == null || !(sv.predicted_raw > 0)) continue;
    const age = asOfDay - dayNumber(sv.date);
    if (age < 0) continue;
    const w = Math.exp((-LN2 * age) / halfLife);
    const R = Math.log(max2(sv.actual, minActual) / max2(sv.predicted_raw, minActual));
    rows.push({
      spot_id: sv.spot_id,
      sold_out: Boolean(sv.sold_out),
      w,
      Lt: clamp(R, -lnRatio, lnRatio), // what the service tells the truck factor
      Ls: clamp(R, -lnSpotRatio, lnSpotRatio), // what it tells its own spot
      in_truck: sv.predicted_raw >= minPredicted,
      used: false,
    });
  }
  const allIds: string[] = [];
  for (let k = 0; k < rows.length; k++) allIds.push(rows[k].spot_id);
  const spotIds = sortedDistinct(allIds);

  // Pass A: services that were not sold out.
  let num = 0.0;
  let den = 0.0;
  for (let k = 0; k < rows.length; k++) {
    const r = rows[k];
    if (r.in_truck && !r.sold_out) {
      num += r.w * r.Lt;
      den += r.w;
    }
  }
  const mA = num / (kTruck + den);
  const sA: Record<string, number> = {};
  for (let j = 0; j < spotIds.length; j++) {
    num = 0.0;
    den = 0.0;
    for (let k = 0; k < rows.length; k++) {
      const r = rows[k];
      if (r.spot_id === spotIds[j] && !r.sold_out) {
        num += r.w * (r.Ls - mA);
        den += r.w;
      }
    }
    putOwn(sA, spotIds[j], num / (kSpot + den));
  }
  for (let k = 0; k < rows.length; k++) {
    const r = rows[k];
    r.used = !r.sold_out || r.Ls > mA + sA[r.spot_id];
  }

  // Truck factor, from used rows with in_truck.
  num = 0.0;
  let truckWeight = 0.0;
  let truckN = 0;
  for (let k = 0; k < rows.length; k++) {
    const r = rows[k];
    if (r.used && r.in_truck) {
      num += r.w * r.Lt;
      truckWeight += r.w;
      truckN += 1;
    }
  }
  const truckLogFactor = num / (kTruck + truckWeight);

  // Spot factors, from every used row of the spot.
  const spots: Record<string, CalibrationSpot> = {};
  for (let j = 0; j < spotIds.length; j++) {
    num = 0.0;
    let weight = 0.0;
    let count = 0;
    for (let k = 0; k < rows.length; k++) {
      const r = rows[k];
      if (r.used && r.spot_id === spotIds[j]) {
        num += r.w * (r.Ls - truckLogFactor);
        weight += r.w;
        count += 1;
      }
    }
    if (count === 0) continue;
    const logFactor = num / (kSpot + weight);
    putOwn(spots, spotIds[j], { factor: Math.exp(logFactor), log_factor: logFactor, n: count, weight });
  }

  // Residual spread, from used rows with in_truck that were not sold out.
  num = 0.0;
  let residWeight = 0.0;
  let residN = 0;
  for (let k = 0; k < rows.length; k++) {
    const r = rows[k];
    if (r.used && r.in_truck && !r.sold_out) {
      const resid = r.Ls - truckLogFactor - spots[r.spot_id].log_factor;
      num += r.w * resid * resid;
      residWeight += r.w;
      residN += 1;
    }
  }
  const residSd = residN >= seed<number>(A, 'calibration.min_resid_n') ? Math.sqrt(num / residWeight) : null;

  // The mean of log ratios estimates a geometric mean; the correction turns the truck factor into a mean.
  const biasLog = residSd !== null ? (0.5 * residSd * residSd * truckWeight) / (kTruck + truckWeight) : 0.0;
  const truckFactor = Math.exp(truckLogFactor + biasLog);
  return {
    model_version: A.model_version,
    seeds_revision: A.seeds_revision,
    as_of: asOf,
    truck_factor: truckFactor,
    truck_log_factor: truckLogFactor,
    bias_log: biasLog,
    truck_n: truckN,
    truck_weight: truckWeight,
    spots,
    resid_sd: residSd,
    resid_n: residN,
    resid_weight: residWeight,
  };
}

function accuracyBlock(rows: readonly ServiceLogEntry[]): AccuracyBlock {
  const sorted = byDateAndId(rows);
  const scored: ServiceLogEntry[] = [];
  for (let k = 0; k < sorted.length; k++) {
    if (!sorted[k].sold_out) scored.push(sorted[k]);
  }
  const block: AccuracyBlock = {
    n_total: rows.length,
    n_scored: scored.length,
    n_sold_out: rows.length - scored.length,
    bias: null,
    mape: null,
    coverage: null,
    raw_bias: null,
    raw_mape: null,
  };
  if (scored.length === 0) return block;
  let sumActual = 0.0;
  let sumDiff = 0.0;
  let sumApe = 0.0;
  let rawDiff = 0.0;
  let rawApe = 0.0;
  let inside = 0;
  for (let k = 0; k < scored.length; k++) {
    const e = scored[k];
    sumActual += e.actual;
    sumDiff += e.predicted - e.actual;
    sumApe += Math.abs(e.predicted - e.actual) / max2(e.actual, 1.0);
    rawDiff += e.predicted_raw - e.actual;
    rawApe += Math.abs(e.predicted_raw - e.actual) / max2(e.actual, 1.0);
    if (e.low <= e.actual && e.actual <= e.high) inside += 1;
  }
  if (sumActual !== 0) {
    block.bias = sumDiff / sumActual; // > 0: the model predicted too much
    block.raw_bias = rawDiff / sumActual;
  }
  block.mape = sumApe / scored.length;
  block.raw_mape = rawApe / scored.length;
  block.coverage = inside / scored.length;
  return block;
}

/**
 * How the estimates did against the logged services, overall and per spot (spots ascending). Sold-out
 * services are counted, not scored. Entries without a spot_id count in the overall block only.
 */
export function accuracyReport(entries: readonly ServiceLogEntry[]): AccuracyReport {
  const overall = accuracyBlock(entries);
  const ids: string[] = [];
  for (let k = 0; k < entries.length; k++) {
    const id = entries[k].spot_id;
    if (id != null) ids.push(id);
  }
  const spotIds = sortedDistinct(ids);
  const bySpot: SpotAccuracyBlock[] = [];
  for (let j = 0; j < spotIds.length; j++) {
    const own: ServiceLogEntry[] = [];
    for (let k = 0; k < entries.length; k++) {
      if (entries[k].spot_id === spotIds[j]) own.push(entries[k]);
    }
    const block = accuracyBlock(own);
    bySpot.push({
      n_total: block.n_total,
      n_scored: block.n_scored,
      n_sold_out: block.n_sold_out,
      bias: block.bias,
      mape: block.mape,
      coverage: block.coverage,
      raw_bias: block.raw_bias,
      raw_mape: block.raw_mape,
      spot_id: spotIds[j],
    });
  }
  return {
    n_total: overall.n_total,
    n_scored: overall.n_scored,
    n_sold_out: overall.n_sold_out,
    bias: overall.bias,
    mape: overall.mape,
    coverage: overall.coverage,
    raw_bias: overall.raw_bias,
    raw_mape: overall.raw_mape,
    by_spot: bySpot,
  };
}
