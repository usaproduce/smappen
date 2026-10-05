import { Navigate, useParams } from 'react-router-dom';
import { Route } from 'lucide-react';
import { parseDate } from '../../../utils/truck/model';
import { useNow } from '../data';
import DaySheet from '../sheet/DaySheet';

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

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.10 and 9.2). The day sheet package replaces the body:
 * a toolbar that does not print ("Back to the planner", "Print", the calendar file, the route link)
 * around the sheet of the date's saved plan.
 *
 * What stays: the date in the URL is checked before anything else (anything that is not a date goes
 * to today's planner).
 */
export default function DaySheetPage() {
  const { date } = useParams();
  const today = useNow().date;
  if (!isDate(date)) return <Navigate to={'/truck/plan/' + today} replace />;

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
        <Route size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Day sheet
      </h1>
      <DaySheet date={date} />
    </div>
  );
}
