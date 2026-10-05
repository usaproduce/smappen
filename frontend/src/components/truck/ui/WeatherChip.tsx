import { CloudRain, Sun, Thermometer, Wind } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { HourForecast } from '../../../utils/truck/model';
import type { ForecastInfo } from '../../../api/truck';
import { WEATHER } from '../../../utils/truck/wording';
import { useTruck } from '../data/TruckContext';
import { weatherSummary, weatherTitle } from './kit';
import type { WeatherSummary } from './kit';

export interface WeatherChipProps {
  /** The 24 forecast hours of one date (DayContext.forecast), or null when there is none. */
  forecast: (HourForecast | null)[] | null;
  /** The stretch of the day the chip is about: from this clock hour up to, not including, toHour. */
  fromHour: number;
  toHour: number;
  /** State and age of the forecast (the day-context answer's `forecast`): a stale one says so in the title. */
  info?: Pick<ForecastInfo, 'state' | 'generated_local'> | null;
}

const ICONS: Record<WeatherSummary['icon'], LucideIcon> = {
  sun: Sun,
  rain: CloudRain,
  wind: Wind,
  thermometer: Thermometer,
};

/**
 * The weather of a stretch of a day in one chip (docs/truck-planner/05_FRONTEND.md 3.13): the
 * temperature range, then the worst precipitation class as the model classifies it, with the
 * highest chance the forecast gives for it. The forecast is the one for the area around the truck's
 * base, which the title says.
 */
export default function WeatherChip({ forecast, fromHour, toHour, info }: WeatherChipProps) {
  const { A } = useTruck();
  const summary = weatherSummary(A, forecast, fromHour, toHour);
  if (!summary.usable) {
    return (
      <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-0.5">
        <span className="tp-chip">{WEATHER.none}</span>
        <span className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
          {WEATHER.noneHelp}
        </span>
      </span>
    );
  }
  const stale = info !== null && info !== undefined && info.state === 'stale' && info.generated_local !== null ? { minute: info.generated_local.minute } : null;
  const Icon = ICONS[summary.icon];
  return (
    <span className="tp-chip" title={weatherTitle(summary, stale)}>
      <Icon size={12} strokeWidth={2.6} aria-hidden />
      {summary.text}
    </span>
  );
}
