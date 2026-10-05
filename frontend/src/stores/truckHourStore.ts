import { create } from 'zustand';
import { HOURS_PER_WEEK, dayOfWeek, modFloor } from '../utils/truck/model';

/**
 * The hour the Truck Planner map shows (docs/truck-planner/05_FRONTEND.md 2.4). Not persisted.
 *
 * `how` is the hour of the week: `dow * 24 + hour`, `dow` 0 = Monday, so 0 is Monday 12 AM and 167
 * is Sunday 11 PM. It changes many times a second while the week plays, so:
 *
 * 1. The map layer subscribes imperatively and no React render happens between a tick and the pixels:
 *      useTruckHourStore.subscribe((s, prev) => {
 *        if (s.how !== prev.how || s.date !== prev.date) layer.setHour(s.how, s.date);
 *      });
 * 2. A React component that selects `how` must be a leaf that only prints or moves something small
 *    (the hour label, the week-strip cursor). Anything that computes reads `useSettledHow(150)`.
 * 3. `how` is never copied into truckUiStore, into the URL per tick, or into the component that
 *    renders the Google map.
 */

/** Thursday 12 PM: where the hour sits until the map page sets it from the URL, the last used hour or the clock. */
const INITIAL_HOW = 84;

export interface TruckHourState {
  /** Hour of week, an integer 0..167. */
  how: number;
  /** The week is playing. */
  playing: boolean;
  /** Null = typical week. A date = the map uses that date's holiday pattern. */
  date: string | null;
  /** Wraps modulo 168. Does nothing, and notifies nobody, when the hour is unchanged. */
  setHow(how: number): void;
  /** Moves by a number of hours, wrapping from Sunday 11 PM to Monday 12 AM and back. */
  step(delta: number): void;
  setPlaying(on: boolean): void;
  /** Also moves `how` to that date's day of the week, keeping the hour of day. */
  setDate(date: string | null): void;
}

function wrapHow(how: number): number {
  return modFloor(Math.floor(how), HOURS_PER_WEEK);
}

export const useTruckHourStore = create<TruckHourState>((set, get) => ({
  how: INITIAL_HOW,
  playing: false,
  date: null,
  setHow: (how) => {
    if (!Number.isFinite(how)) return;
    const next = wrapHow(how);
    if (next !== get().how) set({ how: next });
  },
  step: (delta) => get().setHow(get().how + delta),
  setPlaying: (on) => {
    if (on !== get().playing) set({ playing: on });
  },
  setDate: (date) => {
    const state = get();
    if (date === null) {
      if (state.date !== null) set({ date: null });
      return;
    }
    const how = dayOfWeek(date) * 24 + modFloor(state.how, 24);
    if (date !== state.date || how !== state.how) set({ date, how });
  },
}));

/** Back to the initial state. Used when the owner deletes all truck data. */
export function resetTruckHourStore(): void {
  useTruckHourStore.setState({ how: INITIAL_HOW, playing: false, date: null });
}
