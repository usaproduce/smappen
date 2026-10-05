import { useEffect, useState } from 'react';
import { useTruckHourStore, type TruckHourState } from '../../../stores/truckHourStore';

/**
 * The hour of the week for anything that computes (docs/truck-planner/05_FRONTEND.md 2.4 rule 2).
 *
 * While the week plays the hour changes several times a second; a component that recomputes an
 * estimate on each tick would fall behind. This hook returns `how` once it has been unchanged for
 * `delayMs`, or at once when playback is off. Leaves that only print or move something small (the
 * hour label, the week-strip cursor) select `how` from the store directly instead.
 */
export function useSettledHow(delayMs = 150): number {
  const [settled, setSettled] = useState(() => useTruckHourStore.getState().how);

  useEffect(() => {
    let timer: number | undefined;

    const follow = (state: TruckHourState) => {
      if (timer !== undefined) {
        window.clearTimeout(timer);
        timer = undefined;
      }
      if (!state.playing) {
        setSettled(state.how);
        return;
      }
      timer = window.setTimeout(() => {
        timer = undefined;
        setSettled(useTruckHourStore.getState().how);
      }, delayMs);
    };

    follow(useTruckHourStore.getState());
    const unsubscribe = useTruckHourStore.subscribe((state, previous) => {
      if (state.how !== previous.how || state.playing !== previous.playing) follow(state);
    });
    return () => {
      unsubscribe();
      if (timer !== undefined) window.clearTimeout(timer);
    };
  }, [delayMs]);

  return settled;
}
