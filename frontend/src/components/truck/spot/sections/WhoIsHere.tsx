import { useState } from 'react';
import type { SegmentGroup } from '../../../../utils/truck/model';
import { fmtAbout, fmtPercent } from '../../../../utils/truck/format';
import type { WhoIsHere as WhoIsHereView } from '../../../../utils/truck/hourControl';
import { WHY } from '../../../../utils/truck/wording';

export interface WhoIsHereProps {
  /** The hour's people and orders by segment, arranged by `whoIsHere`. */
  who: WhoIsHereView;
  /** The host's own name, when the spot has one on file; else the segment label is used. */
  hostName: string | null;
  dim: boolean;
}

/** Identity colours: they say which group a bar is, never whether it is good or bad (7.1). */
const GROUP_COLOR: Record<SegmentGroup, string> = {
  residents: 'var(--tp-group-res)',
  workers: 'var(--tp-group-work)',
  visitors: 'var(--tp-group-visit)',
};

const GROUP_WORD: Record<SegmentGroup, string> = {
  residents: 'Residents',
  workers: 'Workers',
  visitors: 'Visitors and hosts',
};

const GROUPS: SegmentGroup[] = ['residents', 'workers', 'visitors'];

function Row({ label, people, share, group }: { label: string; people: number; share: number | null; group: SegmentGroup }) {
  return (
    <li className="py-1.5">
      <div className="flex flex-wrap items-baseline justify-between gap-x-3">
        <span className="min-w-0 text-sm font-bold" style={{ color: 'var(--ink)' }}>
          {label}
        </span>
        <span className="text-[13px] font-semibold tabular-nums" style={{ color: 'var(--body)' }}>
          about {fmtAbout(people)} people
        </span>
      </div>
      {share !== null ? (
        <div className="mt-1 flex items-center gap-2">
          <span aria-hidden="true" className="block h-2 min-w-0 flex-1 overflow-hidden rounded-sm" style={{ background: 'var(--bg-panel)' }}>
            <span className="block h-full rounded-sm" style={{ width: fmtPercent(share > 1 ? 1 : share, 1), background: GROUP_COLOR[group] }} />
          </span>
          <span className="flex-none text-xs font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
            {fmtPercent(share)} <span className="font-semibold" style={{ color: 'var(--body)' }}>of this hour's orders</span>
          </span>
        </div>
      ) : null}
    </li>
  );
}

/**
 * "Who is here" of the spot card (docs/truck-planner/05_FRONTEND.md 4.3, section D): for the hour
 * on screen, the host's own people first, then the groups of people within walking distance that
 * would supply at least 1 % of the hour's orders, largest first. Counts of people carry no range
 * and are rounded; the shares are of expected orders, not a second estimate. The counts are the
 * distance-weighted ones of the map's "People nearby" layer, which the footnote says: the first
 * step of "Why this number" counts everybody within the walking cutoff and shows larger numbers.
 */
export default function WhoIsHere({ who, hostName, dim }: WhoIsHereProps) {
  const [all, setAll] = useState(false);
  const rows = all ? who.all : who.rows;
  const more = who.all.length > who.rows.length;

  return (
    <div className={'space-y-2' + (dim ? ' tp-dim' : '')}>
      {who.nobody ? (
        <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
          {WHY.nobodyNearby}
        </p>
      ) : null}
      {!who.hasOrders ? (
        <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
          No orders expected at this hour.
        </p>
      ) : null}

      {who.host !== null || rows.length > 0 ? (
        <ul className="tp-stat-list">
          {who.host !== null ? (
            <Row
              label={'Host: ' + (hostName !== null && hostName !== '' ? hostName : who.host.label)}
              people={who.host.people}
              share={who.host.share}
              group="visitors"
            />
          ) : null}
          {rows.map((row) => (
            <Row key={row.segment} label={row.label} people={row.people} share={row.share} group={row.group} />
          ))}
        </ul>
      ) : null}

      {more ? (
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

      {who.hasOrders && (who.host !== null || rows.length > 0) ? (
        <ul aria-label="Chart legend" className="flex flex-wrap gap-x-3 gap-y-1">
          {GROUPS.map((group) => (
            <li key={group} className="inline-flex items-center gap-1.5 text-[11px] font-bold" style={{ color: 'var(--slate)' }}>
              <span aria-hidden="true" className="block h-2 w-3 rounded-sm" style={{ background: GROUP_COLOR[group] }} />
              {GROUP_WORD[group]}
            </li>
          ))}
        </ul>
      ) : null}

      <p className="text-xs font-medium leading-snug" style={{ color: 'var(--body)' }}>
        {!who.nobody && rows.length > 0 ? 'Nearer people count more. ' : ''}
        Workers are counted from jobs at nearby addresses and the share usually on site at this hour.
      </p>
    </div>
  );
}
