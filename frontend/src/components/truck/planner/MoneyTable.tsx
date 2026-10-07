import type { DayTotals } from '../../../utils/truck/model';
import { fmtCount, fmtMoney } from '../../../utils/truck/format';
import { MONEY_LINE_LABELS } from '../../../utils/truck/wording';

export interface MoneyTableProps {
  totals: DayTotals;
  /** Tips are counted in the truck's profile: the row shows only then. */
  tips: boolean;
  dim: boolean;
}

type Line = 'sales' | 'food_cost' | 'packaging' | 'card_fees' | 'spot_fees' | 'tips';
const LINES: Line[] = ['sales', 'food_cost', 'packaging', 'card_fees', 'spot_fees'];

const HEAD = 'px-2 py-1.5 text-right text-[11px] font-bold uppercase tracking-wider';
const CELL = 'px-2 py-1.5 text-right font-bold tabular-nums';

/**
 * The day's sales lines on an expected, a weak and a strong day
 * (docs/truck-planner/05_FRONTEND.md 4.5). The three columns are the value, the low and the high
 * of the estimates the summary card prints above with their label: this table is the one place of
 * the planner where the parts of an estimate stand alone.
 */
export default function MoneyTable({ totals, tips, dim }: MoneyTableProps) {
  const lines: Line[] = tips ? [...LINES, 'tips'] : LINES;
  const rows = [
    {
      key: 'orders',
      label: MONEY_LINE_LABELS.orders,
      expected: fmtCount(totals.orders.value), // tp-allow-bare: expected column of the day's table
      weak: fmtCount(totals.orders.low), // tp-allow-bare: weak-day column of the day's table
      strong: fmtCount(totals.orders.high), // tp-allow-bare: strong-day column of the day's table
    },
    ...lines.map((line) => ({
      key: line as string,
      label: MONEY_LINE_LABELS[line],
      expected: fmtMoney(totals[line].value), // tp-allow-bare: expected column of the day's table
      weak: fmtMoney(totals[line].low), // tp-allow-bare: weak-day column of the day's table
      strong: fmtMoney(totals[line].high), // tp-allow-bare: strong-day column of the day's table
    })),
  ];

  return (
    <div className={'tp-scroll-x' + (dim ? ' tp-dim' : '')}>
      <table className="w-full border-collapse text-sm">
        <caption className="sr-only">The day on an expected, a weak and a strong day</caption>
        <thead>
          <tr style={{ background: 'var(--bg-panel)' }}>
            <th scope="col" className="rounded-l-md px-2 py-1.5 text-left" style={{ color: 'var(--slate)' }}>
              <span className="sr-only">Line</span>
            </th>
            <th scope="col" className={HEAD} style={{ color: 'var(--slate)' }}>
              Expected
            </th>
            <th scope="col" className={HEAD} style={{ color: 'var(--slate)' }}>
              Weak day
            </th>
            <th scope="col" className={HEAD + ' rounded-r-md'} style={{ color: 'var(--slate)' }}>
              Strong day
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.key} className="border-t" style={{ borderColor: 'var(--line-soft)' }}>
              <th scope="row" className="px-2 py-1.5 text-left font-semibold" style={{ color: 'var(--ink)' }}>
                {row.label}
              </th>
              <td className={CELL} style={{ color: 'var(--ink)' }}>
                {row.expected}
              </td>
              <td className={CELL} style={{ color: 'var(--ink)' }}>
                {row.weak}
              </td>
              <td className={CELL} style={{ color: 'var(--ink)' }}>
                {row.strong}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
