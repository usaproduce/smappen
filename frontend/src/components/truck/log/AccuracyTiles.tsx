import type { AccuracyTile } from '../../../utils/truck/accuracyView';

export interface AccuracyTilesProps {
  /** The four tiles, from `accuracyTiles`. */
  tiles: readonly AccuracyTile[];
}

/**
 * The four tiles of the accuracy tab (docs/truck-planner/05_FRONTEND.md 4.7): services logged,
 * bias, typical miss, and how many services landed inside their range. These are facts about the
 * past from the server's report, not estimates, so they carry no range of their own. A tile that
 * has nothing to show prints the em dash and says what is missing.
 */
export default function AccuracyTiles({ tiles }: AccuracyTilesProps) {
  return (
    <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      {tiles.map((tile) => (
        <div key={tile.id} className="min-w-0 rounded-xl border bg-white p-4" style={{ borderColor: 'var(--line-soft)' }}>
          <dt className="text-[11px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
            {tile.caption}
          </dt>
          <dd className="mt-1">
            <span
              className={(tile.wordy ? 'text-lg' : 'text-2xl') + ' block font-extrabold tabular-nums leading-tight'}
              style={{ color: 'var(--ink)' }}
            >
              {tile.value}
            </span>
            {tile.line !== '' ? (
              <span className="mt-1 block text-[13px] font-semibold leading-snug" style={{ color: 'var(--body)' }}>
                {tile.line}
              </span>
            ) : null}
          </dd>
        </div>
      ))}
    </dl>
  );
}
