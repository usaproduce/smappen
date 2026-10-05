// The day's timeline as segments and rows (docs/truck-planner/05_FRONTEND.md 3.10, 8.2).
//
// Every timeline here is built by the model from the inputs of golden family g17 (02_MODEL 4.11).

import { describe, expect, it } from 'vitest';
import { buildTimeline, emptyTimeline } from '../model';
import type { Timeline } from '../model';
import { timelineMarks, timelineRows, timelineSegmentLabel, timelineSegments, timelineSummary } from '../timelineView';
import type { TimelineSegment } from '../timelineView';
import { assume, goldenCase, spec, specCount } from './_kitFixtures';

function timelineOf(id: string): Timeline {
  const a = goldenCase(id).args;
  return buildTimeline(assume(a.A), a.profile, a.ctx, a.stops, a.legs);
}

const brief = (segments: TimelineSegment[]) => segments.map((s) => s.kind + ' ' + s.from + '-' + s.to);

const NAMES = ['Reston office park', 'Lost Rhino taproom'];

describe('timelineSegments', () => {
  it('gives exactly the twelve segments of the blueprint day sheet', () => {
    const segments = timelineSegments(timelineOf('g17-001'));
    expect(spec(brief(segments))).toEqual([
      'prep 574-619',
      'drive 619-630',
      'setup 630-660',
      'service 660-840',
      'teardown 840-860',
      'drive 860-870',
      'wait 870-990',
      'setup 990-1020',
      'service 1020-1200',
      'teardown 1200-1220',
      'drive 1220-1221',
      'closeout 1221-1251',
    ]);
    expect(segments.length).toBe(12);
    expect(segments.map((s) => s.stopIndex)).toEqual([null, 0, 0, 0, 0, 1, 1, 1, 1, 1, null, null]);
    expect(segments.every((s) => s.unpaid === false)).toBe(true);
  });

  it('covers the day without a hole and without an overlap', () => {
    for (const id of ['g17-001', 'g17-002', 'g17-003', 'g17-005', 'g17-007', 'g17-008', 'g17-010', 'g17-012', 'g17-013']) {
      const t = timelineOf(id);
      const segments = timelineSegments(t);
      expect(segments[0].from).toBe(t.start_prep);
      expect(segments[segments.length - 1].to).toBe(t.done);
      for (let i = 1; i < segments.length; i++) expect(segments[i].from).toBe(segments[i - 1].to);
      for (const s of segments) expect(s.to).toBeGreaterThan(s.from);
      let minutes = 0;
      for (const s of segments) minutes += s.to - s.from;
      expect(minutes).toBe(t.day_minutes);
    }
  });

  it('adds up to the totals of the model', () => {
    const t = timelineOf('g17-001');
    const sum = (kind: string) => timelineSegments(t).filter((s) => s.kind === kind).reduce((n, s) => n + s.to - s.from, 0);
    expect(spec(sum('drive'))).toBe(22);
    expect(spec(sum('service'))).toBe(360);
    expect(spec(sum('wait'))).toBe(120);
    expect(sum('drive')).toBe(t.drive_minutes);
    expect(sum('service')).toBe(t.service_minutes);
    expect(sum('setup') + sum('service') + sum('teardown')).toBe(t.generator_minutes);
  });

  it('marks an unpaid wait', () => {
    const paid = timelineSegments(timelineOf('g17-001')).filter((s) => s.kind === 'wait');
    const unpaid = timelineSegments(timelineOf('g17-005')).filter((s) => s.kind === 'wait');
    expect(paid).toEqual([{ kind: 'wait', from: 870, to: 990, stopIndex: 1, unpaid: false }]);
    expect(unpaid).toEqual([{ kind: 'wait', from: 870, to: 990, stopIndex: 1, unpaid: true }]);
    expect(timelineSegmentLabel(paid[0])).toBe('Waiting, paid');
    expect(timelineSegmentLabel(unpaid[0])).toBe('Break, unpaid');
    // the flag on the first stop means nothing: the truck leaves base just in time
    expect(timelineSegments(timelineOf('g17-006')).filter((s) => s.unpaid).length).toBe(0);
  });

  it('has no wait segment for a late arrival', () => {
    const t = timelineOf('g17-003');
    const segments = timelineSegments(t);
    expect(t.stops[1].late_minutes).toBe(15);
    expect(segments.filter((s) => s.kind === 'wait')).toEqual([]);
    expect(brief(segments).slice(5, 9)).toEqual(['drive 860-870', 'setup 870-900', 'service 900-1080', 'teardown 1080-1100']);
  });

  it('gives a stop the truck cannot reach no stretch at all', () => {
    const segments = timelineSegments(timelineOf('g17-004'));
    expect(brief(segments)).toEqual([
      'prep 574-619',
      'drive 619-630',
      'setup 630-660',
      'service 660-840',
      'teardown 840-860',
      'drive 860-870',
      'drive 870-871',
      'closeout 871-901',
    ]);
  });

  it('handles a day that starts before midnight and one that ends after it', () => {
    expect(brief(timelineSegments(timelineOf('g17-007')))[0]).toBe('prep -56--11');
    const late = timelineSegments(timelineOf('g17-013'));
    expect(late[late.length - 1]).toEqual({ kind: 'closeout', from: 1591, to: 1621, stopIndex: null, unpaid: false });
  });

  it('gives nothing for an empty timeline', () => {
    expect(timelineSegments(timelineOf('g17-009'))).toEqual([]);
    expect(timelineSegments(emptyTimeline())).toEqual([]);
    expect(timelineRows(emptyTimeline(), [])).toEqual([]);
    expect(timelineMarks(emptyTimeline())).toEqual([]);
    expect(timelineSummary(emptyTimeline())).toBe('Nothing is planned for this day.');
  });
});

describe('timelineRows', () => {
  it('labels the events of the blueprint day; "Start setting up" only where there is a wait', () => {
    const rows = timelineRows(timelineOf('g17-001'), NAMES);
    expect(spec(rows.map((r) => r.minute))).toEqual([574, 619, 630, 660, 840, 860, 870, 990, 1020, 1200, 1220, 1221, 1251]);
    expect(rows.map((r) => r.label)).toEqual([
      'Start prep',
      'Leave base',
      'Arrive at Reston office park',
      'Open',
      'Close',
      'Leave Reston office park',
      'Arrive at Lost Rhino taproom',
      'Start setting up',
      'Open',
      'Close',
      'Leave Lost Rhino taproom',
      'Back at base',
      'Done',
    ]);
  });

  it('without the setup row it is the sheet of the blueprint', () => {
    const minutes = timelineRows(timelineOf('g17-001'), NAMES)
      .filter((r) => r.kind !== 'setup_start')
      .map((r) => r.minute);
    expect(spec(minutes)).toEqual([574, 619, 630, 660, 840, 860, 870, 1020, 1200, 1220, 1221, 1251]);
  });

  it('names a stop that has no name', () => {
    const rows = timelineRows(timelineOf('g17-002'), []);
    expect(rows.map((r) => r.label)).toEqual(['Start prep', 'Leave base', 'Arrive at Stop 1', 'Open', 'Close', 'Leave Stop 1', 'Back at base', 'Done']);
  });

  it('opens a late stop at the time the truck can really open', () => {
    const rows = timelineRows(timelineOf('g17-003'), NAMES);
    const open = rows.filter((r) => r.kind === 'open' && r.stopIndex === 1)[0];
    expect(open.minute).toBe(900);
    expect(rows.some((r) => r.kind === 'setup_start')).toBe(false);
  });
});

describe('timelineMarks and timelineSummary', () => {
  it('marks the start, each opening and closing, and the end', () => {
    expect(timelineMarks(timelineOf('g17-001'))).toEqual([
      { minute: 574, kind: 'day' },
      { minute: 660, kind: 'stop' },
      { minute: 840, kind: 'stop' },
      { minute: 1020, kind: 'stop' },
      { minute: 1200, kind: 'stop' },
      { minute: 1251, kind: 'day' },
    ]);
    expect(timelineMarks(timelineOf('g17-002')).map((m) => m.minute)).toEqual([574, 660, 840, 901]);
    // a truck that needs no prep, setup or close-out: the day starts and ends on the drive
    expect(timelineMarks(timelineOf('g17-014'))).toEqual([
      { minute: 649, kind: 'day' },
      { minute: 660, kind: 'stop' },
      { minute: 840, kind: 'stop' },
      { minute: 851, kind: 'day' },
    ]);
  });

  it('says the day in one sentence', () => {
    expect(timelineSummary(timelineOf('g17-001'))).toBe('The day runs from 9:34 AM to 8:51 PM: 11 h 17 min with 2 stops and 22 min of driving.');
    expect(timelineSummary(timelineOf('g17-002'))).toBe('The day runs from 9:34 AM to 3:01 PM: 5 h 27 min with 1 stop and 22 min of driving.');
  });
});

describe('worked examples', () => {
  it('this file asserts 6 values the specification prints', () => {
    expect(specCount()).toBe(6);
  });
});
