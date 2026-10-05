import { Flag } from 'lucide-react';
import type { DayContext } from '../../../utils/truck/model';
import { holidayChipText } from './kit';

export interface HolidayChipProps {
  context: DayContext;
}

/**
 * What kind of day the model takes a date for (docs/truck-planner/05_FRONTEND.md 3.13): the
 * holiday's name, "Treated as a Saturday" when the owner picked a weekday for it, "Holiday ignored"
 * when the owner switched the holiday off. Nothing on an ordinary day.
 */
export default function HolidayChip({ context }: HolidayChipProps) {
  const text = holidayChipText(context);
  if (text === null) return null;
  return (
    <span className="tp-chip">
      <Flag size={12} strokeWidth={2.6} aria-hidden />
      {text}
    </span>
  );
}
