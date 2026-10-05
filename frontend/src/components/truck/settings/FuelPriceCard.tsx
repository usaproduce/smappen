import type { FuelInfo } from '../../../api/truck';
import { fmtFuel } from '../../../utils/truck/format';
import { FUEL_PRICE_OVERRIDE_RULE, fuelProductLabel, fuelSourceLine } from '../../../utils/truck/profileForm';
import { MoneyField, StatList, StatRow } from '../ui';

export interface FuelPriceCardProps {
  /** Prefix of the field id. */
  id: string;
  /** The price in use right now and where it comes from. */
  fuel: FuelInfo;
  /** The owner's own price in the draft; null follows the weekly average. */
  value: number | null;
  onChange: (next: number | null) => void;
  /** The draft changes something the price depends on (the fuel, or the owner's price). */
  pendingChange: boolean;
  error?: string;
}

/**
 * "Fuel price" (docs/truck-planner/05_FRONTEND.md 4.9): the price the costs are computed with and
 * its source, as on Today, and a field for the owner's own price. Left empty, the truck follows
 * the weekly average of its area.
 */
export default function FuelPriceCard({ id, fuel, value, onChange, pendingChange, error }: FuelPriceCardProps) {
  return (
    <div className="space-y-2">
      <div className="label" style={{ marginBottom: 0 }}>
        Fuel price
      </div>
      <StatList>
        <StatRow label={fuelProductLabel(fuel.product)} value={fmtFuel(fuel.price_per_gal)} sub={fuelSourceLine(fuel)} />
      </StatList>
      {pendingChange ? (
        <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
          This is the price in use now. It follows your changes once they are saved.
        </p>
      ) : null}
      <MoneyField
        id={id}
        label="Use my own price"
        className="max-w-[260px]"
        value={value}
        onCommit={onChange}
        decimals={3}
        step={0.01}
        min={FUEL_PRICE_OVERRIDE_RULE.min}
        max={FUEL_PRICE_OVERRIDE_RULE.max}
        suffix="per gal"
        help="Leave empty to follow the weekly average."
        error={error}
      />
    </div>
  );
}
