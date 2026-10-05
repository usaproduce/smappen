import { useMemo } from 'react';
import { useTruckHourStore } from '../../../../stores/truckHourStore';
import { fmtCount } from '../../../../utils/truck/format';
import type { WindowSlot } from '../../../../utils/truck/hourControl';
import { WeekStrip } from '../../ui';
import { windowLabel } from './BestWindows';

export interface WeekSectionProps {
  /** Expected orders for each hour of a typical week (168 values), from `useSpotEstimate`. */
  week: number[];
  /** The best windows, outlined and numbered on the strip. */
  slots: readonly WindowSlot[];
  /** The value that takes the darkest colour: the seed `map.opportunity_hi`, as on the map. */
  yMax: number;
  /** The truck's orders per hour, which caps every hour. */
  capacity: number;
  /** A cell was picked: the hour bar moves there. */
  onPickHow: (how: number) => void;
  dim: boolean;
}

/**
 * "Week at a glance" of the spot card (docs/truck-planner/05_FRONTEND.md 4.3, section C): the 168
 * hours of a typical week in the colours of the map, the best windows outlined, and a ring on the
 * hour the hour bar shows. This component is a leaf that selects the hour for that ring only; the
 * colours are computed once per estimate.
 */
export default function WeekSection({ week, slots, yMax, capacity, onPickHow, dim }: WeekSectionProps) {
  const how = useTruckHourStore((s) => s.how);
  const windows = useMemo(
    () => slots.map((slot) => ({ how: slot.how, hours: slot.hours, label: windowLabel(slot) })),
    [slots],
  );
  const summary =
    windows.length === 0
      ? 'No hour of the week reaches one order here.'
      : 'The busiest stretch of a typical week here is ' + windows[0].label + '.';

  return (
    <div className={'space-y-2' + (dim ? ' tp-dim' : '')}>
      <p className="text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
        {summary}
      </p>
      <WeekStrip values={week} yMax={yMax} windows={windows} cursorHow={how} onPickHow={onPickHow} ariaSummary={summary} />
      <p className="text-xs font-medium leading-snug" style={{ color: 'var(--body)' }}>
        Expected orders per hour in a typical week, capped at your truck's {fmtCount(capacity)} an hour. Same colours as the map.
      </p>
    </div>
  );
}
