import { useMemo } from 'react';
import { useTruckHourStore } from '../../../stores/truckHourStore';
import { stripHeights } from '../../../utils/truck/hourControl';

export interface HourStripProps {
  /** 24 mean colour bytes of the cells in view, one per hour of the selected day; null before the first answer. */
  bytes: Uint8Array | null;
  /** Height of the strip in px. */
  height?: number;
}

/** A bar is this wide, in px. */
const BAR = 3;
/** Half the width of a slider thumb: the first and the last bar sit over the thumb's end positions. */
const THUMB_HALF = 8;

/**
 * The 24 thin bars over the hour slider (docs/truck-planner/05_FRONTEND.md 4.2): how the colours in
 * view rise and fall over the selected day. Relative heights only: the tallest hour fills the
 * strip, and no number is shown, because the bytes come from the quantised map data. Decoration
 * for the slider, so it is hidden from assistive technology; the bar of the hour on screen is ink.
 */
export default function HourStrip({ bytes, height = 18 }: HourStripProps) {
  const hour = useTruckHourStore((s) => s.how % 24);
  const heights = useMemo(() => stripHeights(bytes), [bytes]);
  return (
    <div
      aria-hidden="true"
      className="flex items-end justify-between"
      style={{ height, paddingLeft: THUMB_HALF - BAR / 2, paddingRight: THUMB_HALF - BAR / 2 }}
    >
      {heights.map((share, h) => (
        <span
          key={h}
          style={{
            width: BAR,
            height: Math.max(2, Math.floor(share * height)),
            borderRadius: 1,
            background: h === hour ? 'var(--ink)' : 'var(--slate)',
            opacity: h === hour ? 1 : 0.6,
          }}
        />
      ))}
    </div>
  );
}
