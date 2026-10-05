import { useState } from 'react';
import type { OutletRow } from '../../../../api/truck';
import { METERS_PER_MILE } from '../../../../utils/truck/model';
import type { Regime } from '../../../../utils/truck/model';
import { fmtCount, fmtCount1, fmtMiles } from '../../../../utils/truck/format';
import { RIVAL_KIND_LABELS } from '../../../../utils/truck/wording';
import { SourceLine, StatList, StatRow } from '../../ui';

export interface CompetitionProps {
  /** The pull of the food outlets around the point in the hour's regime (`vectors.rivals`). */
  pull: number;
  /** Which weights the hour on screen uses. */
  regime: Regime;
  /** Food outlets within walking distance, nearest first; null while the list is not loaded. */
  outlets: OutletRow[] | null;
  /** How many the server found; it sends at most 60. */
  outletsTotal: number | null;
  /** The list is on its way (a saved spot asks for it separately). */
  loading: boolean;
  dim: boolean;
}

const FIRST = 8;

/**
 * "Competition" of the spot card (docs/truck-planner/05_FRONTEND.md 4.3, section E): how hard the
 * food outlets around the point pull on the people there, and which outlets they are. The list is
 * OpenStreetMap data and carries its credit.
 */
export default function Competition({ pull, regime, outlets, outletsTotal, loading, dim }: CompetitionProps) {
  const [all, setAll] = useState(false);
  const shown = outlets === null ? [] : all ? outlets : outlets.slice(0, FIRST);
  const cut = outlets !== null && outletsTotal !== null ? outletsTotal - outlets.length : 0;

  return (
    <div className="space-y-2">
      <div className={dim ? 'tp-dim' : undefined}>
        <StatList>
          <StatRow
            label="Pull of nearby food outlets"
            value={fmtCount1(pull)}
            sub={regime === 'eve' ? 'evening weights' : 'day weights'}
          />
        </StatList>
      </div>
      <p className="text-xs font-medium leading-snug" style={{ color: 'var(--body)' }}>
        1 equals one quick-service outlet at this exact point. Higher means more of the people here buy elsewhere.
      </p>

      <h4 className="pt-1 text-[11px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
        Food outlets within walking distance
      </h4>
      {outlets === null ? (
        loading ? (
          <div aria-busy="true">
            <div aria-hidden="true" className="skeleton" style={{ height: 28, borderRadius: 8 }} />
          </div>
        ) : null
      ) : outlets.length === 0 ? (
        <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
          No food outlets within walking distance in our data.
        </p>
      ) : (
        <>
          <ul className="tp-stat-list">
            {shown.map((outlet) => (
              <li key={outlet.place_key} className="flex items-baseline justify-between gap-3 py-1.5">
                <span className="min-w-0">
                  <span className="block truncate text-sm font-bold" style={{ color: 'var(--ink)' }}>
                    {outlet.name !== null && outlet.name !== '' ? outlet.name : 'Unnamed'}
                  </span>
                  <span className="block text-xs font-semibold" style={{ color: 'var(--body)' }}>
                    {RIVAL_KIND_LABELS[outlet.rival_kind] ?? 'Food outlet'}
                  </span>
                </span>
                <span className="flex-none text-[13px] font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                  {fmtMiles(outlet.distance_m / METERS_PER_MILE)}
                </span>
              </li>
            ))}
          </ul>
          {all && cut > 0 ? (
            <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
              and {fmtCount(cut)} more
            </p>
          ) : null}
          {outlets.length > FIRST || cut > 0 ? (
            <button
              type="button"
              aria-expanded={all}
              onClick={() => setAll((v) => !v)}
              className="inline-flex min-h-11 md:min-h-7 items-center text-xs font-bold underline underline-offset-2"
              style={{ color: 'var(--ink)' }}
            >
              {all ? 'Show fewer' : 'Show all'}
            </button>
          ) : null}
        </>
      )}
      {outlets !== null && outlets.length > 0 ? <SourceLine kinds={['osm']} /> : null}
    </div>
  );
}
