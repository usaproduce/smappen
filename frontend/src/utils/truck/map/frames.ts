// Truck Planner map engine - one byte per cell for an hour of the week
// (docs/truck-planner/05_FRONTEND.md 5.4 and 5.5, docs/truck-planner/02_MODEL.md 4.17).
//
// A frame is what the renderers draw: for a layer and an hour, the colour byte of every cell on the
// one fixed scale of the week. This file is the only one that calls the bulk scorer of the model's
// fast path. It keeps its buffers, so a tick of the hour control allocates nothing.
//
//   fill(layer, how, date, out):
//       rows   = the week's rows when date is null, else the 24 rows of that date
//       scores = the bulk scorer over the pack's features, for the active layer only
//       out[c] = scoreByte(scores[c], hi[layer]), or 0 when that byte is under the layer's floor
//
// The map never applies weather, hosts or spot factors: a date changes only the holiday pattern.
//
// Pure: runs in Node and in the browser.

import { HOURS_PER_WEEK, MAP_DOMAIN, dayContext, modFloor, precomputeMapWeights, scoreLayer, scoresToBytes } from '../model';
import type { Assumptions, CalibrationState, MapLayer, MapWeightTable, TruckProfile } from '../model';
import { minByte } from '../palette';

/** What the weight rows are built from. */
export interface FrameInputs {
  A: Assumptions;
  profile: TruckProfile;
  cal: CalibrationState | null;
}

/** The part of a pack a frame source reads. */
export interface FramePack {
  n: number;
  features: Float32Array;
}

export interface FrameSource {
  /** Number of cells: the length `fill` writes. */
  readonly n: number;
  /**
   * Take new inputs. The weight rows are rebuilt only when the assumptions, the menu fit by daypart
   * or the truck factor changed. Returns true when a frame may come out different now (those three,
   * or the truck's capacity), so the caller knows to fill again.
   */
  setInputs(inputs: FrameInputs): boolean;
  /**
   * Write the frame of a layer and hour of the week (0..167) into `out` (length >= n). With a date
   * the 24 rows of that date are used for the hour of day of `how`; with null the typical week.
   */
  fill(layer: MapLayer, how: number, date: string | null, out: Uint8Array): void;
}

interface RowKey {
  A: Assumptions;
  breakfast: number;
  lunch: number;
  dinner: number;
  late: number;
  truckFactor: number;
}

function rowKey(inputs: FrameInputs): RowKey {
  const fit = inputs.profile.daypart_fit;
  return {
    A: inputs.A,
    breakfast: fit.breakfast,
    lunch: fit.lunch,
    dinner: fit.dinner,
    late: fit.late,
    truckFactor: inputs.cal != null ? inputs.cal.truck_factor : 1.0,
  };
}

function sameRows(a: RowKey, b: RowKey): boolean {
  return (
    a.A === b.A &&
    a.breakfast === b.breakfast &&
    a.lunch === b.lunch &&
    a.dinner === b.dinner &&
    a.late === b.late &&
    a.truckFactor === b.truckFactor
  );
}

/** The smallest byte drawn on each layer: scoreByte(floor, hi), which is 12, 13 and 13. */
const MIN_BYTE: Readonly<Record<MapLayer, number>> = {
  opportunity: minByte('opportunity'),
  people: minByte('people'),
  competition: minByte('competition'),
};

/** Prepare the frames of one pack. The week's weight rows are built here and kept. */
export function createFrameSource(pack: FramePack, inputs: FrameInputs): FrameSource {
  const n = pack.n;
  const features = pack.features;
  const scores = new Float32Array(n);

  let current = inputs;
  let key = rowKey(inputs);
  let capacity = inputs.profile.capacity_orders_per_hour;
  let week: MapWeightTable = precomputeMapWeights(inputs.A, inputs.profile, inputs.cal);
  // The rows of the selected date: the week's table with that date's 24 rows in place.
  let dated: { date: string; dow: number; table: MapWeightTable } | null = null;
  // Competition is the rival pull of the hour's regime and nothing else: two frames, day and evening.
  const competition: [Uint8Array | null, Uint8Array | null] = [null, null];

  function tableOf(date: string): { dow: number; table: MapWeightTable } {
    if (dated === null || dated.date !== date) {
      // Holidays come from the date alone; forecast and fuel play no part in the map.
      const ctx = dayContext(current.A, date, null, null, null, null);
      dated = { date, dow: ctx.dow, table: precomputeMapWeights(current.A, current.profile, current.cal, ctx) };
    }
    return dated;
  }

  return {
    n,

    setInputs(next: FrameInputs): boolean {
      const nextKey = rowKey(next);
      const nextCapacity = next.profile.capacity_orders_per_hour;
      const rowsChanged = !sameRows(key, nextKey);
      const changed = rowsChanged || nextCapacity !== capacity;
      current = next;
      capacity = nextCapacity;
      if (rowsChanged) {
        key = nextKey;
        week = precomputeMapWeights(next.A, next.profile, next.cal);
        dated = null;
        // The regime of an hour is a seed too: start the two competition frames again.
        competition[0] = null;
        competition[1] = null;
      }
      return changed;
    },

    fill(layer: MapLayer, how: number, date: string | null, out: Uint8Array): void {
      if (out.length < n) throw new RangeError('frames: out is too short');
      if (!Number.isFinite(how)) throw new RangeError('frames: how must be a number');
      let h = modFloor(Math.floor(how), HOURS_PER_WEEK);
      let table = week;
      if (date !== null) {
        const d = tableOf(date);
        table = d.table;
        h = d.dow * 24 + modFloor(h, 24);
      }
      if (layer === 'competition') {
        const eve = table.eve[h] === 0 ? 0 : 1;
        let frame = competition[eve];
        if (frame === null) {
          frame = new Uint8Array(n);
          scoreLayer('competition', features, n, table, h, capacity, scores);
          scoresToBytes(scores, n, MAP_DOMAIN.competition, frame, MIN_BYTE.competition);
          competition[eve] = frame;
        }
        out.set(frame);
        return;
      }
      scoreLayer(layer, features, n, table, h, capacity, scores);
      scoresToBytes(scores, n, MAP_DOMAIN[layer], out, MIN_BYTE[layer]);
    },
  };
}

/**
 * The mean of some cells' bytes, to the nearest whole byte: one bar of the hour strip. `subset` lists
 * the cells to average (its first `count` entries); null means the first `count` cells.
 */
export function meanByte(values: Uint8Array, subset: Uint32Array | null, count: number): number {
  if (!(count > 0)) return 0;
  let sum = 0;
  if (subset === null) {
    for (let c = 0; c < count; c++) sum += values[c];
  } else {
    for (let c = 0; c < count; c++) sum += values[subset[c]];
  }
  return Math.floor(sum / count + 0.5);
}
