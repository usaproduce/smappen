import { TriangleAlert } from 'lucide-react';
import type { DayResult, SpotTerms, StopMoney } from '../../../../utils/truck/model';
import { fmtCeil, fmtDuration, fmtMiles, fmtMoney, fmtMoneyCents } from '../../../../utils/truck/format';
import { orderLeaves, type DriveSummary } from '../../../../utils/truck/hourControl';
import { MONEY_LINE_LABELS } from '../../../../utils/truck/wording';
import { RangeValue, SkeletonRows, StatList, StatRow } from '../../ui';

export interface MoneySectionProps {
  /** The window the figures are for, in words: the table's caption. */
  windowText: string;
  /** The money lines of the selected window (the model's `stopMoney` on the window's orders). */
  money: StopMoney;
  /** The terms the estimate was computed with: they decide which unit margin applies. */
  terms: SpotTerms;
  /** Tips are counted in the truck's profile. */
  tips: boolean;
  /** The whole day when this is the only stop; null while the drive legs of a saved spot are on their way. */
  day: DayResult | null;
  /** The drive between the base and the stop, read from that day. */
  drive: DriveSummary | null;
  dim: boolean;
  /** Opens "Why this number" for the one-stop day. */
  onWhyDay: () => void;
}

type Line = 'sales' | 'food_cost' | 'packaging' | 'card_fees' | 'spot_fee' | 'tips';
const LINES: Line[] = ['sales', 'food_cost', 'packaging', 'card_fees', 'spot_fee'];

function DriveValue({ drive }: { drive: DriveSummary }) {
  const same = drive.outMinutes === drive.backMinutes;
  return (
    <>
      {same
        ? fmtDuration(drive.outMinutes) + ' each way, ' + fmtMiles(drive.miles)
        : fmtDuration(drive.outMinutes) + ' there, ' + fmtDuration(drive.backMinutes) + ' back, ' + fmtMiles(drive.miles)}
    </>
  );
}

function DriveSource({ drive }: { drive: DriveSummary }) {
  return (
    <span className="inline-flex flex-wrap items-center justify-end gap-x-1">
      {drive.straight ? <TriangleAlert size={12} aria-hidden="true" className="flex-none" style={{ color: 'var(--fresh-aging)' }} /> : null}
      <span>{drive.label}</span>
      {drive.reason !== null ? <span className="basis-full">{drive.reason}</span> : null}
    </span>
  );
}

/**
 * "Money" of the spot card (docs/truck-planner/05_FRONTEND.md 4.3, section F), for the selected
 * window: the money lines on an expected, a weak and a strong day, what is left after food and
 * fees, and what a day with this one stop would clear once prep, the drive, wages and fuel are
 * counted.
 *
 * The three columns are the value, the low and the high of the same estimates the two `RangeValue`s
 * below print with their label; they are the one place where parts of an estimate stand alone.
 */
export default function MoneySection({ windowText, money, terms, tips, day, drive, dim, onWhyDay }: MoneySectionProps) {
  const lines: Line[] = tips ? [...LINES, 'tips'] : LINES;
  const rows = lines.map((line) => ({
    line,
    expected: fmtMoney(money[line].value), // tp-allow-bare: expected column of the money table
    weak: fmtMoney(money[line].low), // tp-allow-bare: weak-day column of the money table
    strong: fmtMoney(money[line].high), // tp-allow-bare: strong-day column of the money table
  }));
  const stop = day !== null && day.stops.length > 0 ? day.stops[0] : null;
  const breakEven = stop === null ? null : stop.adds.break_even_orders;
  const breakEvenText = breakEven === null ? null : fmtCeil(breakEven);

  return (
    <div className="space-y-3">
      <div className={'tp-scroll-x' + (dim ? ' tp-dim' : '')}>
        <table className="w-full border-collapse text-sm">
          <caption className="sr-only">Money for {windowText}: expected, weak day and strong day</caption>
          <thead>
            <tr style={{ background: 'var(--bg-panel)' }}>
              <th scope="col" className="rounded-l-md px-2 py-1.5 text-left text-[11px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
                <span className="sr-only">Line</span>
              </th>
              {['Expected', 'Weak day', 'Strong day'].map((head, i) => (
                <th
                  key={head}
                  scope="col"
                  className={'px-2 py-1.5 text-right text-[11px] font-bold uppercase tracking-wider' + (i === 2 ? ' rounded-r-md' : '')}
                  style={{ color: 'var(--slate)' }}
                >
                  {head}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.line} className="border-t" style={{ borderColor: 'var(--line-soft)' }}>
                <th scope="row" className="px-2 py-1.5 text-left font-semibold" style={{ color: 'var(--ink)' }}>
                  {MONEY_LINE_LABELS[row.line]}
                </th>
                <td className="px-2 py-1.5 text-right font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                  {row.expected}
                </td>
                <td className="px-2 py-1.5 text-right font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                  {row.weak}
                </td>
                <td className="px-2 py-1.5 text-right font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                  {row.strong}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <RangeValue estimate={money.contribution} unit="money" size="md" label="LEFT AFTER FOOD AND FEES" dim={dim} />

      <div className={dim ? 'tp-dim' : undefined}>
        <StatList>
          <StatRow label="Each order leaves" value={fmtMoneyCents(orderLeaves(money, terms))} />
        </StatList>
      </div>

      <div className="border-t pt-3" style={{ borderColor: 'var(--line-soft)' }}>
        {day === null || stop === null ? (
          <div aria-busy="true">
            <SkeletonRows rows={3} rowHeight={32} />
          </div>
        ) : (
          <div className="space-y-2">
            <RangeValue
              estimate={day.totals.take_home}
              unit="money"
              size="md"
              label="TAKE-HOME FOR A ONE-STOP DAY"
              note="Includes prep, the drive from your base, wages and fuel."
              dim={dim}
              onWhy={onWhyDay}
            />
            <div className={dim ? 'tp-dim' : undefined}>
              <StatList>
                {breakEvenText !== null ? (
                  <StatRow label="Break-even" value={breakEvenText + (breakEvenText === '1' ? ' order' : ' orders')} />
                ) : (
                  <StatRow label="Break-even" value="None" sub="This spot cannot break even at your ticket and costs." negative />
                )}
                {drive !== null ? (
                  <StatRow label="Drive from base" value={<DriveValue drive={drive} />} sub={<DriveSource drive={drive} />} />
                ) : null}
              </StatList>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
