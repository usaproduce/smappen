// Truck Planner - a day's timeline as segments and rows (docs/truck-planner/05_FRONTEND.md 3.10).
//
// The model's timeline (02_MODEL 4.11) is a list of events in whole minutes from local midnight of the
// service date. This module turns it into the stretches between events, for the bar, and into
// labelled rows, for the list, the day sheet and the calendar file. Nothing is recomputed: the event
// minutes are the model's, which never run backwards.

import type { Timeline, TimelineEvent, TimelineEventKind } from './model';
import { fmtClock, fmtDuration } from './format';
import { TIMELINE_EVENT_LABELS, TIMELINE_SEGMENT_LABELS, stopFallbackName } from './wording';

export type TimelineSegmentKind = 'prep' | 'drive' | 'wait' | 'setup' | 'service' | 'teardown' | 'closeout';

export interface TimelineSegment {
  kind: TimelineSegmentKind;
  /** Minutes from local midnight of the service date; `to` is later than `from`. */
  from: number;
  to: number;
  /** The stop this stretch belongs to; for a drive, the stop it leads to. Null for prep, close-out and the drive home. */
  stopIndex: number | null;
  /** True only for a wait the owner marked as an unpaid break. */
  unpaid: boolean;
}

/** What the stretch between two consecutive events is; null when the pair is not a stretch of the day. */
function kindBetween(a: TimelineEventKind, b: TimelineEventKind): TimelineSegmentKind | null {
  if (a === 'start_prep' && b === 'leave_base') return 'prep';
  if (a === 'leave_base' && b === 'arrive') return 'drive';
  if (a === 'arrive' && b === 'setup_start') return 'wait';
  if (a === 'setup_start' && b === 'open') return 'setup';
  if (a === 'open' && b === 'close') return 'service';
  if (a === 'close' && b === 'leave') return 'teardown';
  if (a === 'leave' && (b === 'arrive' || b === 'back_at_base')) return 'drive';
  if (a === 'back_at_base' && b === 'done') return 'closeout';
  return null;
}

/**
 * The stretches of the day in order. A stretch of no length is left out, so a stop the truck reaches
 * on time has no wait and a stop it cannot reach before closing has no service.
 */
export function timelineSegments(timeline: Timeline): TimelineSegment[] {
  const out: TimelineSegment[] = [];
  const events = timeline.events;
  for (let i = 0; i + 1 < events.length; i++) {
    const a = events[i];
    const b = events[i + 1];
    const kind = kindBetween(a.kind, b.kind);
    if (kind === null || !(b.minute > a.minute)) continue;
    let stopIndex: number | null;
    if (kind === 'prep' || kind === 'closeout') stopIndex = null;
    else if (kind === 'drive') stopIndex = b.kind === 'arrive' ? b.stop_index : null;
    else stopIndex = a.stop_index;
    let unpaid = false;
    if (kind === 'wait' && stopIndex !== null) {
      const stop = timeline.stops[stopIndex];
      unpaid = stop !== undefined && stop.gap_unpaid === true;
    }
    out.push({ kind, from: a.minute, to: b.minute, stopIndex, unpaid });
  }
  return out;
}

/** "Waiting, paid", "Break, unpaid", "Serving": what a stretch is, in words. */
export function timelineSegmentLabel(segment: TimelineSegment): string {
  if (segment.kind === 'wait') return segment.unpaid ? TIMELINE_SEGMENT_LABELS.wait_unpaid : TIMELINE_SEGMENT_LABELS.wait;
  return TIMELINE_SEGMENT_LABELS[segment.kind];
}

export interface TimelineRow {
  kind: TimelineEventKind;
  minute: number;
  stopIndex: number | null;
  /** "Arrive at Reston Station", "Open", "Back at base". */
  label: string;
}

function stopName(stopNames: readonly string[], index: number | null): string {
  if (index === null) return '';
  const name = stopNames[index];
  return typeof name === 'string' && name !== '' ? name : stopFallbackName(index);
}

function eventLabel(e: TimelineEvent, stopNames: readonly string[]): string {
  return TIMELINE_EVENT_LABELS[e.kind].split('{stop}').join(stopName(stopNames, e.stop_index));
}

/**
 * One row per event, in order. "Start setting up" is listed only when there is a wait before it
 * (otherwise it is the moment of arrival).
 */
export function timelineRows(timeline: Timeline, stopNames: readonly string[]): TimelineRow[] {
  const out: TimelineRow[] = [];
  const events = timeline.events;
  for (let i = 0; i < events.length; i++) {
    const e = events[i];
    if (e.kind === 'setup_start') {
      const before = i > 0 ? events[i - 1] : null;
      if (before === null || !(e.minute > before.minute)) continue;
    }
    out.push({ kind: e.kind, minute: e.minute, stopIndex: e.stop_index, label: eventLabel(e, stopNames) });
  }
  return out;
}

export interface TimelineMark {
  minute: number;
  /** stop: an opening or a closing time. day: the start or the end of the day. */
  kind: 'stop' | 'day';
}

/**
 * The clock times printed under the bar, in time order: each stop's opening and closing (the stop
 * boundaries) and the start and end of the day. A minute that is both counts as a stop boundary.
 */
export function timelineMarks(timeline: Timeline): TimelineMark[] {
  const out: TimelineMark[] = [];
  const add = (minute: number | null, kind: 'stop' | 'day') => {
    if (minute === null) return;
    for (let i = 0; i < out.length; i++) {
      if (out[i].minute === minute) {
        if (kind === 'stop') out[i].kind = 'stop';
        return;
      }
    }
    out.push({ minute, kind });
  };
  add(timeline.start_prep, 'day');
  for (let i = 0; i < timeline.events.length; i++) {
    const e = timeline.events[i];
    if (e.kind === 'open' || e.kind === 'close') add(e.minute, 'stop');
  }
  add(timeline.done, 'day');
  out.sort((x, y) => x.minute - y.minute);
  return out;
}

/** The day in one sentence, printed above the timeline and read out as the label of its bar. */
export function timelineSummary(timeline: Timeline): string {
  if (timeline.start_prep === null || timeline.done === null) return 'Nothing is planned for this day.';
  const stops = timeline.stops.length;
  return (
    'The day runs from ' +
    fmtClock(timeline.start_prep) +
    ' to ' +
    fmtClock(timeline.done) +
    ': ' +
    fmtDuration(timeline.day_minutes) +
    ' with ' +
    String(stops) +
    (stops === 1 ? ' stop' : ' stops') +
    ' and ' +
    fmtDuration(timeline.drive_minutes) +
    ' of driving.'
  );
}
