import type { ReactNode } from 'react';
import { CircleCheck, CircleDashed, CircleDot, FilePen, Info, Route, TriangleAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { DayContext, TreatAs } from '../../../utils/truck/model';
import { SAVE_STATE_TEXT, TREAT_AS_OPTIONS, treatAsOf, type SaveState } from '../../../utils/truck/planDraft';
import { STRIPS } from '../../../utils/truck/wording';
import type { ForecastState } from '../data';
import { DateStepper, Field, HolidayChip, WeatherChip } from '../ui';

export interface PlannerHeaderProps {
  date: string;
  onDate: (date: string) => void;
  /** The context of the date as the browser built it ("Treat this day as" applied); null while it loads. */
  context: DayContext | null;
  /** State and age of the forecast behind the weather chip. */
  forecast: ForecastState;
  /** The clock hours the weather chip is about. */
  fromHour: number;
  toHour: number;
  treatAs: TreatAs | null;
  onTreatAs: (treatAs: TreatAs | null) => void;
  saveState: SaveState;
  /** The date lies before today (in the truck's time zone). */
  past: boolean;
  /** Google drive times are not available, or a drive of the day is a straight line. */
  driveStrip: boolean;
  /** The forecast could not be loaded: estimates carry no weather adjustment. */
  forecastFailed: boolean;
  onRetryForecast: () => void;
}

const SAVE_ICON: Record<Exclude<SaveState, 'none'>, LucideIcon> = {
  new: CircleDashed,
  unsaved: CircleDot,
  saved: CircleCheck,
  draft: FilePen,
};

/** The last date a day can be planned on: the model needs the context of the day after it. */
const LAST_DATE = '2199-12-30';

/** A strip under the header. `caution`: something behind the numbers is missing. `note`: a plain fact. */
function Strip({ tone, children }: { tone: 'caution' | 'note'; children: ReactNode }) {
  const Icon = tone === 'caution' ? TriangleAlert : Info;
  return (
    <div
      role="status"
      className="flex items-start gap-2 rounded-xl border px-3 py-2.5 text-sm font-semibold"
      style={{
        background: tone === 'caution' ? 'var(--fresh-aging-bg)' : 'var(--bg-panel)',
        borderColor: 'var(--line-soft)',
        color: 'var(--ink)',
      }}
    >
      <Icon size={15} aria-hidden className="mt-0.5 flex-none" style={{ color: tone === 'caution' ? 'var(--fresh-aging)' : 'var(--slate)' }} />
      <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1">{children}</div>
    </div>
  );
}

/**
 * The head of the planner (docs/truck-planner/05_FRONTEND.md 4.5): the date, what kind of day the
 * model takes it for, the weather over the stops, "Treat this day as", and whether the day is
 * saved. Under it, the strips that say when something behind the numbers is missing.
 */
export default function PlannerHeader(props: PlannerHeaderProps) {
  const { date, onDate, context, forecast, fromHour, toHour, treatAs, onTreatAs, saveState, past, driveStrip, forecastFailed, onRetryForecast } = props;
  const SaveIcon = saveState === 'none' ? null : SAVE_ICON[saveState];
  return (
    <header className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
        <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
          <Route size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Planner
        </h1>
        {saveState !== 'none' && SaveIcon !== null ? (
          <p role="status" className="inline-flex items-center gap-1.5 text-sm font-bold" style={{ color: 'var(--ink)' }} data-tp-save-state={saveState}>
            <SaveIcon size={15} aria-hidden className="flex-none" style={{ color: 'var(--body)' }} />
            {SAVE_STATE_TEXT[saveState]}
          </p>
        ) : null}
      </div>

      <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div className="min-w-0 space-y-2">
          {/* On a 375 px screen the stepper is two pixels wider than the page column: it borrows them
              from the gutter instead of dropping its last button to a second line. */}
          <div className="-mr-2 sm:mr-0">
            <DateStepper date={date} onChange={onDate} max={LAST_DATE} />
          </div>
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
            {context !== null ? <HolidayChip context={context} /> : null}
            {context !== null ? <WeatherChip forecast={context.forecast} fromHour={fromHour} toHour={toHour} info={forecast} /> : null}
          </div>
        </div>
        <Field
          id="tp-plan-treat-as"
          label="Treat this day as"
          help="For school breaks, local holidays and the days around Thanksgiving."
          className="w-full sm:w-72"
        >
          {(control) => (
            <select
              {...control}
              className="select h-11 md:h-9 text-sm font-semibold"
              style={{ paddingTop: 0, paddingBottom: 0 }}
              value={treatAs === null ? '' : treatAs}
              onChange={(e) => onTreatAs(treatAsOf(e.target.value))}
            >
              {TREAT_AS_OPTIONS.map((option) => (
                <option key={option.label} value={option.value === null ? '' : option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          )}
        </Field>
      </div>

      {past ? (
        <Strip tone="note">
          <span>This day is in the past.</span>
        </Strip>
      ) : null}
      {driveStrip ? (
        <Strip tone="caution">
          <span>{STRIPS.driveTimesUnavailable}</span>
        </Strip>
      ) : null}
      {forecastFailed ? (
        <Strip tone="caution">
          <span>{STRIPS.forecastFailed}</span>
          <button type="button" className="btn btn-secondary min-h-[44px] md:min-h-[32px] px-3 py-0 text-sm" onClick={onRetryForecast}>
            {STRIPS.tryAgain}
          </button>
        </Strip>
      ) : null}
    </header>
  );
}
