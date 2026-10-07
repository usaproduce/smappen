import { useEffect } from 'react';
import { Link, Navigate, useParams } from 'react-router-dom';
import { ArrowLeft, Info, Printer } from 'lucide-react';
import { addDays, parseDate } from '../../../utils/truck/model';
import { OPEN_IN_MAPS } from '../../../utils/truck/wording';
import { useNow } from '../data';
import CalendarButton from '../sheet/CalendarButton';
import DaySheet, { useSavedDay } from '../sheet/DaySheet';
import { OpenInMaps } from '../ui';

/** True for a civil date the model accepts: `YYYY-MM-DD`, 1970-01-01 to 2199-12-31. */
function isDate(text: string | undefined): text is string {
  if (text === undefined) return false;
  try {
    parseDate(text);
    return true;
  } catch {
    return false;
  }
}

/** The paper the sheet is laid out for. A class cannot scope a page rule, so the page brings its own. */
const PAGE_RULE = '@page { size: letter portrait; margin: 12mm; }';

/**
 * While the day sheet is on the page, printing means printing the sheet: `<html>` carries the class
 * that every rule of print.css is prefixed with, and the page rule sits in a `<style id="tp-page">`.
 * Both go when the page is left, so printing any other page is untouched.
 */
function usePrintLayout(): void {
  useEffect(() => {
    const root = document.documentElement;
    root.classList.add('tp-printing');
    const style = document.createElement('style');
    style.id = 'tp-page';
    style.textContent = PAGE_RULE;
    document.head.appendChild(style);
    return () => {
      root.classList.remove('tp-printing');
      style.remove();
    };
  }, []);
}

/**
 * The day sheet (docs/truck-planner/05_FRONTEND.md 4.10): a toolbar that does not print ("Back to
 * the planner", "Print", the calendar file, the route in Google Maps) around the sheet of the
 * date's saved plan.
 *
 * The date in the URL is checked before anything else: anything that is not a date goes to today's
 * planner, in the truck's time zone. So does the last date of the model's calendar, as in the
 * planner: the sheet evaluates the day the same way, with the context of the day after it.
 */
export default function DaySheetPage() {
  const { date } = useParams();
  const today = useNow().date;
  if (!isDate(date) || !isDate(addDays(date, 1))) return <Navigate to={'/truck/plan/' + today} replace />;
  // Keyed by the date: another day is another sheet.
  return <SheetPage key={date} date={date} />;
}

function SheetPage({ date }: { date: string }) {
  usePrintLayout();
  const day = useSavedDay(date);
  const { plan, result } = day;
  const hasSheet = plan !== null && plan.stops.length > 0;
  // Printing waits until the page shows the sheet, not its loading state.
  const settled = day.evaluation.status === 'ready' || day.evaluation.status === 'unavailable';

  return (
    <div className="tp-sheet-page mx-auto max-w-3xl space-y-4">
      <div className="tp-no-print flex flex-wrap items-center gap-2">
        <Link
          to={'/truck/plan/' + date}
          className="mr-auto inline-flex min-h-[44px] md:min-h-[36px] items-center gap-1.5 text-sm font-bold underline underline-offset-2"
          style={{ color: 'var(--ink)' }}
        >
          <ArrowLeft size={14} aria-hidden="true" className="flex-none" />
          Back to the planner
        </Link>
        {hasSheet ? (
          <>
            <button
              type="button"
              className="btn btn-primary h-11 md:h-9 px-4 text-sm disabled:cursor-not-allowed"
              disabled={!settled}
              onClick={() => window.print()}
            >
              <Printer size={14} aria-hidden="true" /> Print
            </button>
            <CalendarButton date={date} result={result} stops={day.calendar} disabled={result === null} />
            {plan.maps_route_url !== null ? <OpenInMaps href={plan.maps_route_url} label={OPEN_IN_MAPS.route} variant="button" /> : null}
          </>
        ) : null}
      </div>

      {day.unsaved ? (
        <div
          role="status"
          className="tp-no-print flex items-start gap-2 rounded-xl border px-3 py-2.5 text-sm font-semibold"
          style={{ background: 'var(--bg-panel)', borderColor: 'var(--line-soft)', color: 'var(--ink)' }}
        >
          <Info size={15} aria-hidden="true" className="mt-0.5 flex-none" style={{ color: 'var(--slate)' }} />
          <span>This sheet shows the saved day. You have unsaved changes.</span>
        </div>
      ) : null}

      <DaySheet date={date} />
    </div>
  );
}
