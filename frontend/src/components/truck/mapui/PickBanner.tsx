import { MousePointerClick } from 'lucide-react';

export interface PickBannerProps {
  /** What the next click on the map does: sets the base, or starts "Add spot". */
  mode: 'base' | 'spot';
  /** Picks the centre of the map: the way through for keyboard and screen-reader use. */
  onUseCentre: () => void;
  onCancel: () => void;
}

const SENTENCES = {
  base: 'Click the map to set your base.',
  spot: 'Click the map where the spot is.',
} as const;

/**
 * The banner of pick mode (docs/truck-planner/05_FRONTEND.md 4.2, `?pick=`): it says what the next
 * click does and offers the way out. The place always comes from a click or from the centre of the
 * map the owner moved there, never from the position of the device.
 */
export default function PickBanner({ mode, onUseCentre, onCancel }: PickBannerProps) {
  return (
    <div role="status" className="bg-white rounded-xl border shadow-float px-3 py-2" style={{ borderColor: 'var(--brand)' }}>
      <p className="flex items-center gap-2 text-sm font-bold" style={{ color: 'var(--ink)' }}>
        <MousePointerClick size={16} aria-hidden="true" className="flex-none" style={{ color: 'var(--brand)' }} />
        <span>{SENTENCES[mode]}</span>
      </p>
      <div className="mt-1 flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
        <button
          type="button"
          onClick={onUseCentre}
          className="inline-flex min-h-11 md:min-h-9 items-center rounded-lg px-1.5 text-[13px] font-bold underline underline-offset-2"
          style={{ color: 'var(--ink)' }}
        >
          Use the centre of the map
        </button>
        <button type="button" onClick={onCancel} className="btn btn-secondary h-11 md:h-9 text-sm">
          Cancel
        </button>
      </div>
    </div>
  );
}
