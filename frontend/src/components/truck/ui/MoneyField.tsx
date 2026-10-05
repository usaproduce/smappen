import NumberField from './NumberField';
import type { NumberFieldProps } from './NumberField';

export type MoneyFieldProps = Omit<NumberFieldProps, 'format' | 'prefix' | 'integer'>;

/**
 * A dollar amount (docs/truck-planner/05_FRONTEND.md 3.5): a NumberField with the prefix "$", two
 * decimals and a minimum of 0 unless one is given. Money is dollars everywhere in the app.
 */
export default function MoneyField(props: MoneyFieldProps) {
  return <NumberField {...props} prefix="$" decimals={props.decimals === undefined ? 2 : props.decimals} min={props.min === undefined ? 0 : props.min} />;
}
