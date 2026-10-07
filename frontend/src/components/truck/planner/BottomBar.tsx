import type { Estimate } from '../../../utils/truck/model';
import { RangeValue } from '../ui';
import { saveLabel, type SaveControl } from './PlannerActions';

export interface BottomBarProps {
  /** The day's take-home; null while the day has none to show. */
  takeHome: Estimate | null;
  /** The drive legs are being read again: the figure may still change. */
  dim: boolean;
  /** What stands in for the figure: why the day cannot be saved, or its save state. */
  text: string | null;
  save: SaveControl;
}

/**
 * The phone planner's bar, fixed to the bottom of the screen (docs/truck-planner/05_FRONTEND.md
 * 4.5): what the day clears, as a range with its label, and "Save day" under the thumb. The page
 * leaves room for it at its end, so nothing hides behind it. From 768 px the bar is not shown.
 */
export default function BottomBar({ takeHome, dim, text, save }: BottomBarProps) {
  return (
    <div className="tp-bottom-bar tp-no-print bg-white md:hidden">
      <div className="flex items-center gap-3 px-4 py-2">
        <div className="min-w-0 flex-1">
          {takeHome !== null ? (
            <RangeValue estimate={takeHome} unit="money" layout="inline" size="sm" label="TAKE-HOME" dim={dim} />
          ) : text !== null ? (
            <p className="text-[13px] font-bold" style={{ color: 'var(--ink)' }}>
              {text}
            </p>
          ) : null}
        </div>
        <button type="button" className="btn btn-primary h-11 flex-none px-4 text-sm" disabled={!save.enabled} onClick={save.onSave} data-tp-save="">
          {saveLabel(save)}
        </button>
      </div>
    </div>
  );
}
