import { useMemo } from 'react';
import { startingValues } from '../../../utils/truck/profileForm';
import { Modal, SeedTag } from '../ui';

export interface StartingValuesModalProps {
  open: boolean;
  onClose: () => void;
}

/**
 * "See the starting values and where they come from" (docs/truck-planner/05_FRONTEND.md 4.9): every
 * profile default of the seed file with its value, its tag and its source note. These are where a
 * new truck starts; after that the profile holds the truth.
 */
export default function StartingValuesModal({ open, onClose }: StartingValuesModalProps) {
  const rows = useMemo(() => startingValues(), []);
  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Starting values"
      size="lg"
      footer={
        <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={onClose}>
          Close
        </button>
      }
    >
      <p className="font-semibold" style={{ color: 'var(--body)' }}>
        A new truck starts with these figures. None is measured from your truck: change each one in Settings to match yours.
      </p>
      <ul className="tp-stat-list mt-3">
        {rows.map((row) => (
          <li key={row.key} className="py-2.5">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <span className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
                {row.label}
              </span>
              <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
                <span className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                  {row.value}
                </span>
                <SeedTag tag={row.tag} size="sm" />
              </span>
            </div>
            <p className="mt-0.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
              {row.source}
            </p>
          </li>
        ))}
      </ul>
    </Modal>
  );
}
