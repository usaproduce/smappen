import { useId } from 'react';
import type { RegionCounty } from '../../../api/truck';

export interface CountyChecklistProps {
  /** The counties of the truck's region. */
  counties: readonly RegionCounty[];
  /** County codes (FIPS) the owner ticked. */
  value: readonly string[];
  onChange: (next: string[]) => void;
  error?: string;
}

function byText(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

/**
 * "Counties you hold a licence for" (docs/truck-planner/05_FRONTEND.md 4.9): tick boxes for the
 * counties of the region, grouped by state, with one button per state that ticks them all. Scout
 * only lists places in the ticked counties. The app records what the owner ticks and nothing more:
 * where a licence applies is the owner's to know.
 */
export default function CountyChecklist({ counties, value, onChange, error }: CountyChecklistProps) {
  const ids = useId();
  const states: string[] = [];
  for (const county of counties) if (!states.includes(county.state)) states.push(county.state);
  states.sort(byText);

  const toggle = (fips: string, on: boolean) => {
    onChange(on ? [...value.filter((other) => other !== fips), fips] : value.filter((other) => other !== fips));
  };

  if (counties.length === 0) {
    return (
      <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
        There is no county list for this area, so Scout looks at every place within reach.
      </p>
    );
  }

  return (
    <div className="space-y-3">
      {states.map((state) => {
        const inState = counties.filter((county) => county.state === state).sort((a, b) => byText(a.name, b.name));
        const codes = inState.map((county) => county.fips);
        const all = codes.every((fips) => value.includes(fips));
        return (
          <fieldset key={state}>
            <div className="flex flex-wrap items-center justify-between gap-x-3">
              <legend className="label" style={{ marginBottom: 0 }}>
                {state}
              </legend>
              <button
                type="button"
                className="inline-flex min-h-[44px] md:min-h-0 items-center text-[13px] font-bold underline underline-offset-2"
                style={{ color: 'var(--ink)' }}
                onClick={() => onChange(all ? value.filter((fips) => !codes.includes(fips)) : [...value.filter((fips) => !codes.includes(fips)), ...codes])}
              >
                {all ? 'Untick all in ' + state : 'Select all in ' + state}
              </button>
            </div>
            <div className="mt-1 grid gap-x-3 gap-y-0.5 sm:grid-cols-2 xl:grid-cols-3">
              {inState.map((county) => {
                const on = value.includes(county.fips);
                return (
                  <label key={county.fips} htmlFor={ids + county.fips} className="flex min-h-[44px] md:min-h-[30px] cursor-pointer items-center gap-2 text-sm font-semibold" style={{ color: 'var(--ink)' }}>
                    <input
                      id={ids + county.fips}
                      type="checkbox"
                      className="h-4 w-4 flex-none"
                      style={{ accentColor: 'var(--brand)' }}
                      checked={on}
                      onChange={(e) => toggle(county.fips, e.target.checked)}
                    />
                    {county.name}
                  </label>
                );
              })}
            </div>
          </fieldset>
        );
      })}
      {error !== undefined && error !== '' ? (
        <p role="alert" className="text-xs font-semibold" style={{ color: 'var(--money-negative)' }}>
          {error}
        </p>
      ) : null}
    </div>
  );
}
