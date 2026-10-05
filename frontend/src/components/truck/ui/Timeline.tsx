import { useId } from 'react';
import type { Timeline as TimelineData } from '../../../utils/truck/model';
import { fmtClock, fmtClockShort, fmtDuration } from '../../../utils/truck/format';
import { timelineMarks, timelineRows, timelineSegmentLabel, timelineSegments, timelineSummary } from '../../../utils/truck/timelineView';
import type { TimelineSegment } from '../../../utils/truck/timelineView';
import { CHART, TIMELINE_LEGEND, TIMELINE_SEGMENT_LABELS, stopFallbackName } from '../../../utils/truck/wording';
import { TIMELINE_BAR_WIDTH, timelineBarX, timelineLabelKeep } from './kit';

export interface TimelineProps {
  timeline: TimelineData;
  /** The name of stop i of the plan. */
  stopNames: string[];
  /** bar: one horizontal bar in proportion. list: one row per event. auto: the bar from 1024 px, the list below. */
  orientation?: 'auto' | 'list' | 'bar';
}

const W = TIMELINE_BAR_WIDTH;
const H = 78;
const BAR_Y = 22;
const BAR_H = 26;

// Identity colours of truck.css: they say which thing a stretch is, never whether it is good or bad.
const FILL: Record<TimelineSegment['kind'], string> = {
  prep: 'var(--tp-tl-prep)',
  closeout: 'var(--tp-tl-prep)',
  drive: 'var(--tp-tl-drive)',
  setup: 'var(--tp-tl-setup)',
  teardown: 'var(--tp-tl-setup)',
  service: 'var(--tp-tl-service)',
  wait: 'var(--tp-tl-wait)',
};

function nameOf(stopNames: readonly string[], index: number | null): string {
  if (index === null) return '';
  const name = stopNames[index];
  return typeof name === 'string' && name !== '' ? name : stopFallbackName(index);
}

/** Text cut to the width it has, at about 5.9 units a character of 10-unit bold text. */
function fit(text: string, width: number): string {
  const max = Math.floor(width / 5.9);
  if (text.length <= max) return text;
  if (max < 4) return '';
  return text.slice(0, max - 1) + '…';
}

function Bar({ timeline, stopNames }: { timeline: TimelineData; stopNames: string[] }) {
  // useId gives ":r1:"; a url(#...) reference is safer without the colons
  const hatch = 'tp-hatch-' + useId().split(':').join('');
  const segments = timelineSegments(timeline);
  const start = timeline.start_prep === null ? 0 : timeline.start_prep;
  const end = timeline.done === null ? start + 1 : timeline.done;
  const xOf = timelineBarX(start, end);
  const marks = timelineMarks(timeline);
  // opening and closing times are placed first; the start and end of the day take what room is left
  const keep = timelineLabelKeep(marks, xOf);
  const kinds = { prep: false, drive: false, setup: false, service: false, waitPaid: false, waitUnpaid: false };
  for (const s of segments) {
    if (s.kind === 'prep' || s.kind === 'closeout') kinds.prep = true;
    else if (s.kind === 'drive') kinds.drive = true;
    else if (s.kind === 'setup' || s.kind === 'teardown') kinds.setup = true;
    else if (s.kind === 'service') kinds.service = true;
    else if (s.unpaid) kinds.waitUnpaid = true;
    else kinds.waitPaid = true;
  }
  const swatch = (fill: string, hatched: boolean) => (
    <svg width="14" height="10" viewBox="0 0 14 10" aria-hidden className="flex-none">
      <rect x={0.5} y={0.5} width={13} height={9} rx={2} fill={fill} stroke="var(--line)" strokeWidth={hatched ? 1 : 0} />
      {hatched ? <path d="M-2 10 L6 0 M3 10 L11 0 M8 10 L16 0" stroke="var(--slate)" strokeWidth={1.5} /> : null}
    </svg>
  );

  return (
    <div>
      <svg viewBox={'0 0 ' + String(W) + ' ' + String(H)} width="100%" role="img" aria-label={timelineSummary(timeline)} style={{ display: 'block', minWidth: 650 }}>
        <defs>
          <pattern id={hatch} patternUnits="userSpaceOnUse" width={7} height={7} patternTransform="rotate(40)">
            <rect width={7} height={7} fill="var(--tp-tl-wait)" />
            <line x1={0} y1={0} x2={0} y2={7} stroke="var(--slate)" strokeWidth={2.5} />
          </pattern>
        </defs>
        {segments.map((s) => {
          const x = xOf(s.from);
          const width = xOf(s.to) - x;
          const label = s.kind === 'service' ? fit(nameOf(stopNames, s.stopIndex), width) : s.kind === 'wait' ? fit(timelineSegmentLabel(s), width) : '';
          return (
            <g key={s.kind + String(s.from)}>
              <rect
                x={x + 0.5}
                y={BAR_Y}
                width={width > 1 ? width - 1 : width}
                height={BAR_H}
                rx={3}
                fill={s.kind === 'wait' ? 'url(#' + hatch + ')' : FILL[s.kind]}
                stroke={s.kind === 'wait' ? 'var(--line)' : 'none'}
                strokeWidth={1}
              >
                <title>
                  {(s.stopIndex !== null && s.kind !== 'drive' ? nameOf(stopNames, s.stopIndex) + ': ' : '') +
                    timelineSegmentLabel(s) +
                    ', ' +
                    fmtClock(s.from) +
                    ' to ' +
                    fmtClock(s.to) +
                    ' (' +
                    fmtDuration(s.to - s.from) +
                    ')'}
                </title>
              </rect>
              {label !== '' ? (
                <text aria-hidden x={x + width / 2} y={BAR_Y - 6} textAnchor="middle" fontSize="10" fontWeight={700} fill="var(--ink)">
                  {label}
                </text>
              ) : null}
            </g>
          );
        })}
        {marks.map((m, i) =>
          keep[i] ? (
            <g key={m.minute} aria-hidden>
              <line x1={xOf(m.minute)} x2={xOf(m.minute)} y1={BAR_Y + BAR_H} y2={BAR_Y + BAR_H + 5} stroke="var(--line)" strokeWidth={1} />
              <text
                x={xOf(m.minute)}
                y={BAR_Y + BAR_H + 17}
                textAnchor={i === 0 ? 'start' : i === marks.length - 1 ? 'end' : 'middle'}
                fontSize="10"
                fontWeight={700}
                fill="var(--slate)"
              >
                {fmtClockShort(m.minute)}
              </text>
            </g>
          ) : null,
        )}
      </svg>
      <ul aria-label={CHART.legend} className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold" style={{ color: 'var(--body)' }}>
        {kinds.prep ? <li className="inline-flex items-center gap-1.5">{swatch('var(--tp-tl-prep)', false)}{TIMELINE_LEGEND.prep}</li> : null}
        {kinds.drive ? <li className="inline-flex items-center gap-1.5">{swatch('var(--tp-tl-drive)', false)}{TIMELINE_LEGEND.drive}</li> : null}
        {kinds.setup ? <li className="inline-flex items-center gap-1.5">{swatch('var(--tp-tl-setup)', false)}{TIMELINE_LEGEND.setup}</li> : null}
        {kinds.service ? <li className="inline-flex items-center gap-1.5">{swatch('var(--tp-tl-service)', false)}{TIMELINE_LEGEND.service}</li> : null}
        {kinds.waitPaid ? <li className="inline-flex items-center gap-1.5">{swatch('var(--tp-tl-wait)', true)}{TIMELINE_SEGMENT_LABELS.wait}</li> : null}
        {kinds.waitUnpaid ? <li className="inline-flex items-center gap-1.5">{swatch('var(--tp-tl-wait)', true)}{TIMELINE_SEGMENT_LABELS.wait_unpaid}</li> : null}
      </ul>
    </div>
  );
}

function List({ timeline, stopNames }: { timeline: TimelineData; stopNames: string[] }) {
  const rows = timelineRows(timeline, stopNames);
  const segments = timelineSegments(timeline);
  // what starts at an event: the drive after leaving, the wait after arriving
  const after = (kind: string, minute: number, stopIndex: number | null): TimelineSegment | undefined => {
    if (kind === 'leave_base' || kind === 'leave') return segments.find((s) => s.kind === 'drive' && s.from === minute);
    if (kind === 'arrive') return segments.find((s) => s.kind === 'wait' && s.stopIndex === stopIndex);
    return undefined;
  };
  return (
    <ol className="text-sm">
      {rows.map((row, i) => {
        const next = after(row.kind, row.minute, row.stopIndex);
        return (
          <li key={row.kind + String(i)} className={'flex gap-3 py-1.5' + (i > 0 ? ' border-t' : '')} style={{ borderColor: 'var(--line-soft)' }}>
            <span className="w-[5.25rem] flex-none whitespace-nowrap text-right tabular-nums font-bold" style={{ color: 'var(--ink)' }}>
              {fmtClock(row.minute)}
            </span>
            <span className="min-w-0">
              <span className="font-semibold" style={{ color: 'var(--ink)' }}>
                {row.label}
              </span>
              {next !== undefined ? (
                <span className="block text-xs font-semibold" style={{ color: 'var(--body)' }}>
                  {timelineSegmentLabel(next)}, {fmtDuration(next.to - next.from)}
                </span>
              ) : null}
            </span>
          </li>
        );
      })}
    </ol>
  );
}

/**
 * The day, start to finish (docs/truck-planner/05_FRONTEND.md 3.10), from the model's own events.
 * As a bar the stretches are in proportion with the clock at the stop boundaries; as a list there
 * is one row per event. A wait is hatched and says in words whether it is paid, so the difference
 * never rests on colour.
 */
export default function Timeline({ timeline, stopNames, orientation = 'auto' }: TimelineProps) {
  const summary = timelineSummary(timeline);
  if (timeline.events.length === 0) {
    return (
      <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
        {summary}
      </p>
    );
  }
  return (
    <div>
      <p className="mb-3 text-sm font-semibold" style={{ color: 'var(--body)' }}>
        {summary}
      </p>
      {orientation !== 'list' ? (
        <div className={(orientation === 'auto' ? 'hidden lg:block ' : '') + 'tp-scroll-x'}>
          <Bar timeline={timeline} stopNames={stopNames} />
        </div>
      ) : null}
      {orientation !== 'bar' ? (
        <div className={orientation === 'auto' ? 'lg:hidden' : ''}>
          <List timeline={timeline} stopNames={stopNames} />
        </div>
      ) : null}
    </div>
  );
}
