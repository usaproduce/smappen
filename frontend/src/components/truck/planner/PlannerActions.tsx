import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Printer, Trash2, TriangleAlert } from 'lucide-react';
import { fmtDay } from '../../../utils/truck/format';
import type { MapsRoute } from '../../../utils/truck/links';
import type { DayResult } from '../../../utils/truck/model';
import type { CalendarStop } from '../../../utils/truck/planDraft';
import { OPEN_IN_MAPS } from '../../../utils/truck/wording';
import CalendarButton from '../sheet/CalendarButton';
import { Modal, OpenInMaps } from '../ui';

/** "Save day" as every place that shows the button needs it. */
export interface SaveControl {
  /** There is something to save and nothing holds it back. */
  enabled: boolean;
  saving: boolean;
  /** Why the day cannot be saved as it stands: "Fix the times first." */
  hint: string | null;
  onSave: () => void;
}

/** The label of the save button. */
export function saveLabel(save: SaveControl): string {
  return save.saving ? 'Saving...' : 'Save day';
}

/**
 * "Save day", the one primary action of the planner, from 768 px (a phone has it in its bottom
 * bar). It is disabled when nothing changed and while something holds the save back; the hint says
 * what.
 */
export function SaveDayRow({ save }: { save: SaveControl }) {
  return (
    <div className="bg-white rounded-xl border p-3" style={{ borderColor: 'var(--line-soft)' }}>
      {save.hint !== null ? (
        <p className="mb-2 flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
          <TriangleAlert size={14} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--fresh-aging)' }} />
          <span>{save.hint}</span>
        </p>
      ) : null}
      <button type="button" className="btn btn-primary h-11 md:h-10 w-full px-4 text-sm" disabled={!save.enabled} onClick={save.onSave} data-tp-save="">
        {saveLabel(save)}
      </button>
    </div>
  );
}

export interface PlannerActionsProps {
  date: string;
  /** The draft differs from the saved day: the day sheet and the calendar file ask first. */
  changed: boolean;
  /** The date has a saved day. */
  saved: boolean;
  /** The draft holds something that "Clear day" would remove. */
  hasContent: boolean;
  /** Saves the day; resolves true when it was saved. */
  save: () => Promise<boolean>;
  /** Why the day cannot be saved as it stands, or null. */
  saveHint: string | null;
  saving: boolean;
  /** The route link of the saved day (`plan.maps_route_url`) while the draft is unchanged. */
  routeHref: string | null;
  /** Else the route of the draft: base, the stops in order, base. Null without a stop that has a place. */
  route: MapsRoute | null;
  /** What the calendar file is made from. */
  calendar: { result: DayResult | null; stops: CalendarStop[] };
  /** Clears the day (the owner has confirmed). */
  onClear: () => void;
  clearing: boolean;
}

/**
 * What can be done with the day besides saving it (docs/truck-planner/05_FRONTEND.md 4.5): the day
 * sheet, the calendar file, the route in Google Maps, and clearing the day. The day sheet and the
 * calendar file are made from the saved day, so with unsaved changes they ask first.
 */
export default function PlannerActions(props: PlannerActionsProps) {
  const { date, changed, saved, hasContent, save, saveHint, saving, routeHref, route, calendar, onClear, clearing } = props;
  const navigate = useNavigate();
  const [asking, setAsking] = useState<'sheet' | 'calendar' | null>(null);
  const [confirming, setConfirming] = useState(false);
  // A click on the calendar button that was held back and is owed once the day is saved.
  const [calendarOwed, setCalendarOwed] = useState(false);
  const calendarBox = useRef<HTMLSpanElement>(null);
  const letThrough = useRef(false);
  const sheet = '/truck/plan/' + date + '/sheet';

  const proceed = () => {
    const what = asking;
    void save().then((ok) => {
      if (!ok) return; // the failure has been shown; the question stays open
      setAsking(null);
      if (what === 'sheet') navigate(sheet);
      else if (what === 'calendar') setCalendarOwed(true);
    });
  };

  // The held-back click is repeated after the render that shows the saved day, so the button makes
  // its file from the saved day (new stops carry their real ids by then) and not from the draft the
  // click was held back on. A day that was edited again in the meantime is asked about again.
  useEffect(() => {
    if (!calendarOwed) return;
    setCalendarOwed(false);
    if (changed) return;
    const button = calendarBox.current === null ? null : calendarBox.current.querySelector('button');
    if (button === null) return;
    letThrough.current = true;
    button.click();
    letThrough.current = false; // a button that is switched off takes no click: nothing stays let through
  }, [calendarOwed, changed]);

  return (
    <section className="bg-white rounded-xl border p-4 sm:p-5" style={{ borderColor: 'var(--line-soft)' }} aria-label="More for this day">
      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          className="btn btn-secondary h-11 md:h-9 px-3 text-sm disabled:opacity-50 disabled:cursor-not-allowed"
          disabled={!saved && !changed}
          onClick={() => {
            if (changed) setAsking('sheet');
            else navigate(sheet);
          }}
        >
          <Printer size={14} aria-hidden /> Print day sheet
        </button>
        {/* The calendar button downloads on its own click. With unsaved changes that click is held
            back here, and repeated once the day is saved. */}
        <span
          ref={calendarBox}
          className="inline-flex"
          onClickCapture={(e) => {
            if (letThrough.current || !changed) return;
            e.preventDefault();
            e.stopPropagation();
            setAsking('calendar');
          }}
        >
          <CalendarButton date={date} result={calendar.result} stops={calendar.stops} disabled={calendar.result === null} />
        </span>
        {routeHref !== null ? (
          <OpenInMaps href={routeHref} label={OPEN_IN_MAPS.route} variant="button" />
        ) : route !== null ? (
          <OpenInMaps route={route} variant="button" />
        ) : null}
      </div>
      {saved || hasContent ? (
        <button
          type="button"
          className="mt-2 inline-flex min-h-[44px] md:min-h-[28px] items-center gap-1.5 text-sm font-bold underline underline-offset-2"
          style={{ color: 'var(--body)' }}
          onClick={() => setConfirming(true)}
        >
          <Trash2 size={14} aria-hidden /> Clear day
        </button>
      ) : null}

      <Modal
        open={asking !== null}
        onClose={() => setAsking(null)}
        title="Save the day first?"
        size="sm"
        footer={
          <>
            <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={() => setAsking(null)} disabled={saving}>
              Cancel
            </button>
            <button type="button" className="btn btn-primary h-11 md:h-9 px-3 text-sm" onClick={proceed} disabled={saving || saveHint !== null}>
              {saving ? 'Saving...' : 'Save and continue'}
            </button>
          </>
        }
      >
        <p>
          {asking === 'calendar' ? 'The calendar file is made from the saved day.' : 'The day sheet shows the saved day.'} You have
          unsaved changes.
        </p>
        {saveHint !== null ? (
          <p className="mt-2 font-bold" style={{ color: 'var(--ink)' }}>
            {saveHint}
          </p>
        ) : null}
      </Modal>

      <Modal
        open={confirming}
        onClose={() => setConfirming(false)}
        title={'Clear ' + fmtDay(date, 'medium') + '?'}
        size="sm"
        footer={
          <>
            <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={() => setConfirming(false)} disabled={clearing}>
              Keep it
            </button>
            <button
              type="button"
              className="btn btn-danger h-11 md:h-9 px-3 text-sm"
              disabled={clearing}
              onClick={() => {
                setConfirming(false);
                onClear();
              }}
            >
              <Trash2 size={15} aria-hidden /> Clear day
            </button>
          </>
        }
      >
        <p>
          {saved
            ? 'The saved day and its stops are removed. Services you logged on this day keep their numbers.'
            : 'The stops you added are removed. Nothing was saved for this day.'}
        </p>
      </Modal>
    </section>
  );
}
