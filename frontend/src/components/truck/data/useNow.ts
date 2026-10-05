import { useMemo, useSyncExternalStore } from 'react';
import { dayOfWeek, floorDiv, modFloor } from '../../../utils/truck/model';
import { nowEpochMs, regionNow } from '../../../utils/truck/clock';
import { useTruck } from './TruckContext';
import { useBootstrap, type BootstrapData } from './useBootstrap';

/** "Now" in the truck's time zone. */
export interface TruckNow {
  /** Today's civil date, `YYYY-MM-DD`. */
  date: string;
  /** Minutes from local midnight, 0..1439. */
  minute: number;
  /** Day of week, 0 = Monday. */
  dow: number;
  /** Hour of week, `dow * 24 + hour`. */
  how: number;
}

const MS_PER_MINUTE = 60000;

// One ticker for every `useNow` on the page. It fires on the minute (an aligned timeout, never an
// interval) and again when the tab becomes visible, because browsers hold back timers of hidden tabs.
const listeners = new Set<() => void>();
let timer: number | undefined;

function notify(): void {
  listeners.forEach((listener) => listener());
}

function arm(): void {
  const wait = MS_PER_MINUTE - modFloor(nowEpochMs(), MS_PER_MINUTE) + 25;
  timer = window.setTimeout(() => {
    timer = undefined;
    notify();
    if (listeners.size > 0) arm();
  }, wait);
}

function onVisibility(): void {
  if (document.visibilityState === 'visible') notify();
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  if (listeners.size === 1) {
    arm();
    document.addEventListener('visibilitychange', onVisibility);
  }
  return () => {
    listeners.delete(listener);
    if (listeners.size === 0) {
      if (timer !== undefined) window.clearTimeout(timer);
      timer = undefined;
      document.removeEventListener('visibilitychange', onVisibility);
    }
  };
}

/** The device's epoch in whole minutes: stable within a minute, so it is a valid store snapshot. */
function epochMinute(): number {
  return floorDiv(nowEpochMs(), MS_PER_MINUTE);
}

function skewOf(data: BootstrapData): number {
  return data.clock_skew_minutes;
}

/**
 * Today and now in the truck's time zone; re-renders on the minute (docs/truck-planner/05_FRONTEND.md 2.7).
 *
 * The device's time zone is never used, and its clock is corrected by the skew measured against the
 * server when the bootstrap answer arrived: a device with a wrong clock or a wrong zone still shows
 * the server's day.
 */
export function useNow(): TruckNow {
  const { timezone } = useTruck();
  const skew = useBootstrap(skewOf).data ?? 0;
  const stamp = useSyncExternalStore(subscribe, epochMinute);
  return useMemo(() => {
    const reading = regionNow(timezone, (stamp + skew) * MS_PER_MINUTE);
    const dow = dayOfWeek(reading.date);
    return { date: reading.date, minute: reading.minute, dow, how: dow * 24 + floorDiv(reading.minute, 60) };
  }, [timezone, skew, stamp]);
}
