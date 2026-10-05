import { Navigate, useLocation } from 'react-router-dom';
import { useNow } from '../data';

/**
 * `/truck/plan` -> `/truck/plan/<today>`: the planner is addressed by date, and "today" is the
 * civil date in the truck's time zone, never the device's. Query parameters travel along, so
 * `/truck/plan?add=<spotId>` still adds that spot to today.
 */
export default function PlanIndexRedirect() {
  const { date } = useNow();
  const { search } = useLocation();
  return <Navigate to={'/truck/plan/' + date + search} replace />;
}
