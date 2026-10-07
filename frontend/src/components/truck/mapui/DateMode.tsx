import { useEffect } from 'react';
import { useTruckHourStore } from '../../../stores/truckHourStore';
import { addDays } from '../../../utils/truck/model';
import { DATE_MODE_TEXT, dateForHow, dateModeLine } from '../../../utils/truck/nextAction';
import { useNow, useTruck } from '../data';
import { DateStepper, Tabs } from '../ui';

const TABS = [
  { id: 'typical', label: DATE_MODE_TEXT.typical },
  { id: 'date', label: DATE_MODE_TEXT.pick },
];

/**
 * The switch "Typical week" / "Pick a date" of the hour bar (docs/truck-planner/05_FRONTEND.md
 * 4.2), in a row of its own under the hour controls. It has no props: it reads and writes
 * `truckHourStore.date`, and the map layer listens to the store by itself.
 *
 * A picked date is one of today and the six days after it, so each day of the week is exactly one
 * date. The date and the day of the hour bar are therefore one choice: stepping the date moves the
 * day (the store does that), and when the day is moved by its buttons, the keys or playback, the
 * date follows here.
 *
 * What a date changes is its holiday pattern, and the line next to the stepper says whether it
 * does. The forecast is never part of the map.
 */
export default function DateMode() {
  const { A } = useTruck();
  const today = useNow().date;
  const date = useTruckHourStore((s) => s.date);

  // The picked date follows the day of the week the hour bar is on. It also comes back into the
  // seven days from today when the page is opened again later, or at midnight.
  useEffect(() => {
    const follow = (state: { how: number; date: string | null; setDate: (date: string | null) => void }) => {
      if (state.date === null) return;
      const wanted = dateForHow(today, state.how);
      if (wanted !== state.date) state.setDate(wanted);
    };
    follow(useTruckHourStore.getState());
    return useTruckHourStore.subscribe(follow);
  }, [today]);

  const picked = date !== null;
  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 pb-2 pt-0.5" data-tp-date-mode={picked ? 'date' : 'typical'}>
      <Tabs
        tabs={TABS}
        value={picked ? 'date' : 'typical'}
        variant="segmented"
        ariaLabel={DATE_MODE_TEXT.label}
        onChange={(id) => {
          const state = useTruckHourStore.getState();
          // The date of the day the map is on: switching the mode does not move the hour.
          state.setDate(id === 'date' ? dateForHow(today, state.how) : null);
        }}
      />
      {date !== null ? (
        <DateStepper
          date={date}
          min={today}
          max={addDays(today, 6)}
          onChange={(next) => {
            const state = useTruckHourStore.getState();
            state.setPlaying(false); // a day chosen by hand stops the week, like the day buttons do
            state.setDate(next);
          }}
        />
      ) : null}
      {date !== null ? (
        <p className="min-w-0 basis-full text-xs font-semibold xl:basis-auto" style={{ color: 'var(--body)' }} data-tp-date-line="">
          {dateModeLine(A, date)}
        </p>
      ) : null}
    </div>
  );
}
