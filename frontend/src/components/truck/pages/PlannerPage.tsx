import { Navigate, useParams } from 'react-router-dom';
import { Route } from 'lucide-react';
import { parseDate } from '../../../utils/truck/model';
import { fmtDay } from '../../../utils/truck/format';
import { useNow } from '../data';
import { PermissionNotice } from '../ui';

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
 * STUB (docs/truck-planner/05_FRONTEND.md 4.5 and 9.2). The Planner package replaces the body: the
 * day's stops with the drives between them, the timeline, and what the day clears.
 *
 * What stays: the date in the URL is checked before anything else (anything that is not a date goes
 * to today, in the truck's time zone), and the standing notice is on the page (a source guard
 * checks it).
 */
export default function PlannerPage() {
  const { date } = useParams();
  const today = useNow().date;
  if (!isDate(date)) return <Navigate to={'/truck/plan/' + today} replace />;

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
        <Route size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Planner
      </h1>
      <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
        {fmtDay(date, 'medium')}. Not built yet.
      </p>
      <PermissionNotice variant="line" />
    </div>
  );
}
