import { Link } from 'react-router-dom';
import type { FuelInfo } from '../../../api/truck';
import { FUEL_TEXT, fuelLine } from '../../../utils/truck/nextAction';
import { StatList, StatRow } from '../ui';

export interface FuelLineProps {
  /** The price the estimates are computed with, and where it comes from. */
  fuel: FuelInfo;
}

/**
 * "Fuel" (docs/truck-planner/05_FRONTEND.md 4.1): the price per gallon behind every fuel cost on
 * the page, with its source in words. A weekly average says which week; a default that is no
 * current figure says so. The owner's own price is one link away.
 */
export default function FuelLine({ fuel }: FuelLineProps) {
  const line = fuelLine(fuel);
  return (
    <section aria-labelledby="tp-today-fuel" className="bg-white rounded-xl border p-4 sm:p-5" style={{ borderColor: 'var(--line-soft)' }}>
      <div className="flex items-center justify-between gap-3">
        <h2 id="tp-today-fuel" className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
          {FUEL_TEXT.title}
        </h2>
        <Link
          to="/truck/settings/truck"
          className="-my-2 inline-flex min-h-[44px] md:min-h-0 items-center text-sm font-bold underline underline-offset-2"
          style={{ color: 'var(--ink)' }}
        >
          {FUEL_TEXT.change}
          <span className="sr-only"> the fuel price</span>
        </Link>
      </div>
      <StatList>
        <StatRow label={line.label} value={line.value} />
      </StatList>
      {/* The source under the row, at the card's full width: beside the price it would squeeze the label onto two lines. */}
      <p className="text-xs font-semibold" style={{ color: 'var(--body)' }} data-tp-fuel-source={fuel.source}>
        {line.sub}
      </p>
    </section>
  );
}
