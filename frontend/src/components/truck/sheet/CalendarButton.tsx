import { CalendarPlus } from 'lucide-react';
import { nowEpochMs, utcStamp } from '../../../utils/truck/clock';
import { ICS_MIME, buildDayIcs, icsFileName, type IcsStop } from '../../../utils/truck/ics';
import type { DayResult } from '../../../utils/truck/model';
import { useTruck } from '../data';

export interface CalendarButtonProps {
  date: string;
  /** The day as evaluated in the browser; null while it cannot be evaluated. */
  result: DayResult | null;
  /** The day's stops in order, with what the calendar entries need. */
  stops: { id: string; name: string; address: string; point: { lat: number; lng: number } }[];
  disabled?: boolean;
}

/**
 * Hands the browser a file to save under `filename`. The file is already in the page (made here,
 * or answered by the truck API): this sends nothing anywhere. The blob's address is given back a
 * moment later, once the download has started.
 */
export function downloadBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.rel = 'noopener';
  link.hidden = true;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 2000);
}

/**
 * Each stop with the orders the model estimated for it. A stop the result does not hold is left
 * out: an entry never carries a figure that was not estimated.
 */
function withOrders(result: DayResult, stops: CalendarButtonProps['stops']): IcsStop[] {
  const out: IcsStop[] = [];
  for (const stop of stops) {
    const evaluated = result.stops.find((s) => s.id === stop.id);
    if (evaluated !== undefined) {
      out.push({ id: stop.id, name: stop.name, address: stop.address, point: stop.point, orders: evaluated.orders });
    }
  }
  return out;
}

/**
 * "Calendar file" (docs/truck-planner/05_FRONTEND.md 4.10): the day as a calendar file, made in the
 * browser and saved as `truck-day-<date>.ics`. One entry for the whole day and one per stop, at the
 * model's own times in the truck's time zone; each stop's entry carries its estimate with its range
 * and label, and the standing notice.
 */
export default function CalendarButton({ date, result, stops, disabled }: CalendarButtonProps) {
  const { timezone, profile } = useTruck();
  const off = disabled === true || result === null || result.timeline.start_prep === null || stops.length === 0;

  const save = () => {
    if (off || result === null) return;
    const text = buildDayIcs({
      date,
      timeZone: timezone,
      timeline: result.timeline,
      stops: withOrders(result, stops),
      truckName: profile.name,
      host: window.location.host,
      dtstamp: utcStamp(nowEpochMs()),
    });
    downloadBlob(new Blob([text], { type: ICS_MIME }), icsFileName(date));
  };

  return (
    <button
      type="button"
      className="btn btn-secondary h-11 md:h-9 px-3 text-sm disabled:opacity-50 disabled:cursor-not-allowed"
      disabled={off}
      onClick={save}
    >
      <CalendarPlus size={14} aria-hidden="true" /> Calendar file
    </button>
  );
}
