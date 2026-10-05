// Truck Planner map engine - one byte per cell for an hour of the week
// (docs/truck-planner/05_FRONTEND.md 5.4 and 5.5, docs/truck-planner/02_MODEL.md 4.17).
//
// A frame is what the renderers draw: for a layer and an hour, the colour byte of every cell on the
// one fixed scale of the week. This file is the only one that calls the bulk scorer of the model's
// fast path.
//
//   frame(layer, how, date):
//       rows   = the week's rows when date is null, else the 24 rows of that date
//       scores = the bulk scorer over the pack's features, for the active layer only
//       byte   = scoreByte(score, hi[layer]), or 0 when that byte is under the layer's floor
//
// Frames are kept once they have been scored: the week of one layer is 168 frames of one byte per
// cell (10 MB for the dc region). A tick of the hour control on a kept frame is a copy; the layer
// scores the hours ahead while the browser is idle (`warm`), so a week that plays, or a slider that
// is dragged, rarely waits for the scorer. Another layer, another date or other inputs start the
// kept frames again.
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
   * or the truck's capacity), so the caller knows to fill again; the kept frames are dropped then.
   */
  setInputs(inputs: FrameInputs): boolean;
  /**
   * Write the frame of a layer and hour of the week (0..167) into `out` (length >= n). With a date
   * the 24 rows of that date are used for the hour of day of `how`; with null the typical week.
   */
  fill(layer: MapLayer, how: number, date: string | null, out: Uint8Array): void;
  /** True when `fill` for this layer, hour and date is a copy of a kept frame. */
  has(layer: MapLayer, how: number, date: string | null): boolean;
  /** Score a frame and keep it, without handing it out. Returns false when it was kept already. */
  warm(layer: MapLayer, how: number, date: string | null): boolean;
  /** How many frames are kept right now. */
  kept(): number;
}

/** The kept frames of a region never take more than this, however many cells it has. */
export const FRAME_CACHE_BYTES = 16 * 1024 * 1024;

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

  // The kept frames of one layer and one date (or of the typical week), by hour of the week.
  const frames: (Uint8Array | null)[] = new Array<Uint8Array | null>(HOURS_PER_WEEK).fill(null);
  const order: number[] = [];
  const most = Math.max(24, Math.min(HOURS_PER_WEEK, n > 0 ? Math.floor(FRAME_CACHE_BYTES / n) : HOURS_PER_WEEK));
  let keptLayer: MapLayer | null = null;
  let keptDate: string | null = null;

  function drop(): void {
    for (let h = 0; h < HOURS_PER_WEEK; h++) frames[h] = null;
    order.length = 0;
  }

  function tableOf(date: string): { dow: number; table: MapWeightTable } {
    if (dated === null || dated.date !== date) {
      // Holidays come from the date alone; forecast and fuel play no part in the map.
      const ctx = dayContext(current.A, date, null, null, null, null);
      dated = { date, dow: ctx.dow, table: precomputeMapWeights(current.A, current.profile, current.cal, ctx) };
    }
    return dated;
  }

  /** Which row of which table an hour and a date mean. */
  function resolve(how: number, date: string | null): { table: MapWeightTable; h: number } {
    if (!Number.isFinite(how)) throw new RangeError('frames: how must be a number');
    const h = modFloor(Math.floor(how), HOURS_PER_WEEK);
    if (date === null) return { table: week, h };
    const d = tableOf(date);
    return { table: d.table, h: d.dow * 24 + modFloor(h, 24) };
  }

  /** The frame of a layer and hour: kept, or scored now and kept. `fresh` says which. */
  function frameOf(layer: MapLayer, how: number, date: string | null): { frame: Uint8Array; fresh: boolean } {
    const { table, h } = resolve(how, date);
    if (layer === 'competition') {
      const eve = table.eve[h] === 0 ? 0 : 1;
      let frame = competition[eve];
      const fresh = frame === null;
      if (frame === null) {
        frame = new Uint8Array(n);
        scoreLayer('competition', features, n, table, h, capacity, scores);
        scoresToBytes(scores, n, MAP_DOMAIN.competition, frame, MIN_BYTE.competition);
        competition[eve] = frame;
      }
      return { frame, fresh };
    }
    if (keptLayer !== layer || keptDate !== date) {
      drop();
      keptLayer = layer;
      keptDate = date;
    }
    let frame = frames[h];
    if (frame !== null) return { frame, fresh: false };
    if (order.length >= most) {
      // Over the limit: the frame scored longest ago makes room, and its buffer is used again.
      const oldest = order.shift() as number;
      frame = frames[oldest];
      frames[oldest] = null;
    }
    if (frame === null) frame = new Uint8Array(n);
    scoreLayer(layer, features, n, table, h, capacity, scores);
    scoresToBytes(scores, n, MAP_DOMAIN[layer], frame, MIN_BYTE[layer]);
    frames[h] = frame;
    order.push(h);
    return { frame, fresh: true };
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
      if (changed) drop();
      return changed;
    },

    fill(layer: MapLayer, how: number, date: string | null, out: Uint8Array): void {
      if (out.length < n) throw new RangeError('frames: out is too short');
      out.set(frameOf(layer, how, date).frame);
    },

    has(layer: MapLayer, how: number, date: string | null): boolean {
      const { table, h } = resolve(how, date);
      if (layer === 'competition') return competition[table.eve[h] === 0 ? 0 : 1] !== null;
      return keptLayer === layer && keptDate === date && frames[h] !== null;
    },

    warm(layer: MapLayer, how: number, date: string | null): boolean {
      return frameOf(layer, how, date).fresh;
    },

    kept(): number {
      return order.length;
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
