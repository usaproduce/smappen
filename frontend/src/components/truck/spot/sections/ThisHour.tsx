import type { WindowResult } from '../../../../utils/truck/model';
import { fmtAbout, fmtHowLong } from '../../../../utils/truck/format';
import { RangeValue } from '../../ui';

export interface ThisHourProps {
  /** The hour of the week the figures are for (the settled hour of the hour bar). */
  how: number;
  /** The one-hour window at that hour, from `useSpotEstimate`. */
  result: WindowResult;
  /** People of all segments present within walking distance at this hour, nearer people counted more. */
  people: number;
  /** The host's own people present at this hour, when the spot has a host; they are not part of `people`. */
  hostPeople: number | null;
  /** The numbers belong to the last matching estimate: new ones are on their way. */
  dim: boolean;
  onWhy: () => void;
}

/**
 * "This hour" of the spot card (docs/truck-planner/05_FRONTEND.md 4.3, section A): expected orders
 * in the hour the hour bar shows, as a range with its label, and how many people are around. The
 * count of people is not an estimate of sales and carries no range; it is rounded to say so. It is
 * the distance-weighted count the "People nearby" layer of the map colours by, and the line says so:
 * the first step of "Why this number" counts everybody within the walking cutoff and is larger. A
 * host's own people are counted apart from the people around it, so the line names them apart.
 */
export default function ThisHour({ how, result, people, hostPeople, dim, onWhy }: ThisHourProps) {
  return (
    <div className="space-y-2">
      <RangeValue
        estimate={result.orders}
        unit="orders"
        size="lg"
        label={fmtHowLong(how)}
        note={result.capped_hours > 0 ? 'Limited by how fast the truck can serve.' : undefined}
        dim={dim}
        onWhy={onWhy}
      />
      <p className={'text-sm font-semibold' + (dim ? ' tp-dim' : '')} style={{ color: 'var(--body)' }}>
        About {fmtAbout(people)} people within walking distance at this hour.
        {people >= 1 ? ' Nearer people count more.' : ''}
        {hostPeople !== null ? ' The host has about ' + fmtAbout(hostPeople) + ' of its own there.' : ''}
      </p>
    </div>
  );
}
