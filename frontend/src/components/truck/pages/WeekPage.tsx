import { Navigate, useParams } from 'react-router-dom';
import { CalendarRange } from 'lucide-react';
import { dayOfWeek, parseDate } from '../../../utils/truck/model';
import { fmtDay } from '../../../utils/truck/format';
import { mondayOf } from '../../../utils/truck/time';

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
 * STUB (docs/truck-planner/05_FRONTEND.md 4.6 and 9.2). The Week, dates and Today package replaces
 * the body: the seven days of the week, what is planned on each and the week's total.
 *
 * What stays: the week in the URL is checked before anything else. Weeks start on Monday: another
 * day of the week goes to the Monday of its week, and anything that is not a date goes to this week.
 */
export default function WeekPage() {
  const { weekStart } = useParams();
  if (!isDate(weekStart)) return <Navigate to="/truck/week" replace />;
  if (dayOfWeek(weekStart) !== 0) return <Navigate to={'/truck/week/' + mondayOf(weekStart)} replace />;

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
        <CalendarRange size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Week of {fmtDay(weekStart, 'short')}
      </h1>
      <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
        Not built yet.
      </p>
    </div>
  );
}
