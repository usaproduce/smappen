import { useMemo } from 'react';
import type { CalibrationState } from '../../../utils/truck/model';
import { ACCURACY_TEXT, spotFactorLines, truckFactorSentence } from '../../../utils/truck/accuracyView';
import { StatList, StatRow } from '../ui';

export interface FactorsCardProps {
  /** The calibration of the truck: what the logged services say today. */
  cal: CalibrationState;
  /** Names of the spots by id. */
  names: Readonly<Record<string, string>>;
}

/**
 * "Your results in the model" (docs/truck-planner/05_FRONTEND.md 4.7): the factor the logged
 * services have given the truck, and the factor of each spot that has one of its own. Every
 * estimate of the app is multiplied by these, which is how the owner's results outrank the model.
 * The factors are the server's calibration, shown as it is.
 */
export default function FactorsCard({ cal, names }: FactorsCardProps) {
  const lines = useMemo(() => spotFactorLines(cal, names), [cal, names]);
  return (
    <section className="min-w-0 rounded-xl border bg-white p-4 sm:p-5" style={{ borderColor: 'var(--line-soft)' }}>
      <h3 className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
        {ACCURACY_TEXT.factorsTitle}
      </h3>
      <p className="mt-1.5 text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
        {truckFactorSentence(cal)}
      </p>
      <p className="mt-0.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
        {ACCURACY_TEXT.factorHelp}
      </p>
      {lines.length === 0 ? (
        <p className="mt-3 text-sm font-semibold" style={{ color: 'var(--body)' }}>
          {ACCURACY_TEXT.noSpotFactors}
        </p>
      ) : (
        <StatList className="mt-2">
          {lines.map((line) => (
            <StatRow key={line.spotId} label={line.name} value={line.factor} sub={<span className="whitespace-nowrap">{line.from}</span>} />
          ))}
        </StatList>
      )}
    </section>
  );
}
