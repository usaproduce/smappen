import { Navigate, useLocation } from 'react-router-dom';
import { mondayOf } from '../../../utils/truck/time';
import { useNow } from '../data';

/**
 * `/truck/week` -> `/truck/week/<Monday of this week>`: weeks start on Monday, and "this week" is
 * the week of today's civil date in the truck's time zone.
 */
export default function WeekIndexRedirect() {
  const { date } = useNow();
  const { search } = useLocation();
  return <Navigate to={'/truck/week/' + mondayOf(date) + search} replace />;
}
