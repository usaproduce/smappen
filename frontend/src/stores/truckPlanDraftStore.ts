import { create } from 'zustand';
import type { CateringTerms, DayContext, EventTerms, StopKind } from '../utils/truck/model';
import type { Plan } from '../api/truck';

/**
 * The planner's unsaved days (docs/truck-planner/05_FRONTEND.md 2.4 and 4.5). Not persisted: a
 * draft lives as long as the tab does, so leaving the planner and coming back keeps the edits.
 *
 * One draft per date (there is one plan per date). The saved plan itself is server data and lives
 * in TanStack Query; this store only holds what the owner is editing.
 */

export interface DraftStop {
  /** A saved stop's id, or a temporary id (`n1`, `n2`, ...) for a stop the server has not seen. */
  id: string;
  kind: StopKind;
  spot_id: string | null;
  label: string;
  point: { lat: number; lng: number } | null;
  address: string;
  open_minute: number;
  close_minute: number;
  gap_before_unpaid: boolean;
  setup_minutes: number | null;
  teardown_minutes: number | null;
  fee_flat: number;
  fee_pct: number;
  fee_min: number;
  event: EventTerms | null;
  catering: CateringTerms | null;
}

export interface PlanDraft {
  /** Id of the saved plan of this date, or null before the first save. */
  planId: string | null;
  date: string;
  treat_as: DayContext['treat_as'];
  notes: string;
  stops: DraftStop[];
  dirty: boolean;
}

/** What a draft is built from: a `Plan`, or the row of a plan list read with `stops: true`. */
export type SavedPlan = Pick<Plan, 'id' | 'date' | 'treat_as' | 'notes' | 'stops'>;

export interface PlanDraftState {
  /** Key = date. */
  drafts: Record<string, PlanDraft>;
  /** Start from the saved plan (or empty). Ignored while a dirty draft for that date exists. */
  load(date: string, saved: SavedPlan | null): void;
  /** Change the draft of a date. Sets `dirty`. */
  patch(date: string, fn: (d: PlanDraft) => PlanDraft): void;
  /** The server answered a save: its plan replaces the draft, which gives new stops their real ids. */
  markSaved(date: string, saved: SavedPlan): void;
  /** Drop the draft of a date. */
  discard(date: string): void;
}

// Temporary stop ids come from a counter: never from randomness, and never the literal `base`,
// which is the id of the truck's base in drive-time requests. They are left out of the save body.
let stopCounter = 0;

/** A fresh temporary id for a stop that has not been saved: `n1`, `n2`, ... */
export function newDraftStopId(): string {
  stopCounter += 1;
  return 'n' + stopCounter;
}

/** True for an id made by `newDraftStopId` (server ids are UUIDs and never look like this). */
export function isTemporaryStopId(id: string): boolean {
  return /^n[0-9]+$/.test(id);
}

/** An empty, unsaved day. */
export function emptyPlanDraft(date: string): PlanDraft {
  return { planId: null, date, treat_as: null, notes: '', stops: [], dirty: false };
}

/** The draft that shows a saved plan unchanged. */
export function draftFromPlan(saved: SavedPlan): PlanDraft {
  return {
    planId: saved.id,
    date: saved.date,
    treat_as: saved.treat_as,
    notes: saved.notes ?? '',
    stops: saved.stops.map((s) => ({
      id: s.id,
      kind: s.kind,
      spot_id: s.spot_id,
      label: s.label,
      point: s.point === null ? null : { lat: s.point.lat, lng: s.point.lng },
      address: s.address,
      open_minute: s.open_minute,
      close_minute: s.close_minute,
      gap_before_unpaid: s.gap_before_unpaid,
      setup_minutes: s.setup_minutes,
      teardown_minutes: s.teardown_minutes,
      fee_flat: s.fee_flat,
      fee_pct: s.fee_pct,
      fee_min: s.fee_min,
      event: s.event,
      catering: s.catering,
    })),
    dirty: false,
  };
}

export const useTruckPlanDraftStore = create<PlanDraftState>((set, get) => ({
  drafts: {},
  load: (date, saved) => {
    const current = get().drafts[date];
    if (current !== undefined && current.dirty) return;
    const next = saved === null ? emptyPlanDraft(date) : { ...draftFromPlan(saved), date };
    // A refetch that changed nothing must not hand every subscriber a new object.
    if (current !== undefined && JSON.stringify(current) === JSON.stringify(next)) return;
    set((s) => ({ drafts: { ...s.drafts, [date]: next } }));
  },
  patch: (date, fn) => {
    set((s) => {
      const current = s.drafts[date] ?? emptyPlanDraft(date);
      return { drafts: { ...s.drafts, [date]: { ...fn(current), date, dirty: true } } };
    });
  },
  markSaved: (date, saved) => {
    set((s) => ({ drafts: { ...s.drafts, [date]: { ...draftFromPlan(saved), date } } }));
  },
  discard: (date) => {
    set((s) => {
      if (s.drafts[date] === undefined) return s;
      const drafts = { ...s.drafts };
      delete drafts[date];
      return { drafts };
    });
  },
}));

/** Drop every draft. Used when the owner deletes all truck data. */
export function resetTruckPlanDraftStore(): void {
  useTruckPlanDraftStore.setState({ drafts: {} });
}
