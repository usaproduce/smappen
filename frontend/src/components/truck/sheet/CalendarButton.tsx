import { CalendarPlus } from 'lucide-react';
import type { DayResult } from '../../../utils/truck/model';

export interface CalendarButtonProps {
  date: string;
  /** The day as evaluated in the browser; null while it cannot be evaluated. */
  result: DayResult | null;
  /** The day's stops in order, with what the calendar entries need. */
  stops: { id: string; name: string; address: string; point: { lat: number; lng: number } }[];
  disabled?: boolean;
}

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.10 and 9.2). The day sheet package replaces the body:
 * it builds the day's calendar file in the browser and downloads `truck-day-<date>.ics`.
 *
 * Until then the button is there and switched off.
 */
export default function CalendarButton(_props: CalendarButtonProps) {
  return (
    <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm opacity-60 cursor-not-allowed" disabled>
      <CalendarPlus size={14} aria-hidden="true" /> Calendar file
    </button>
  );
}
