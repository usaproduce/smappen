import { TriangleAlert } from 'lucide-react';
import { fmtClock, fmtDay } from '../../../utils/truck/format';
import type { HourForecast } from '../../../utils/truck/model';
import { WEATHER_TEXT } from '../../../utils/truck/nextAction';
import type { ForecastState } from '../data';
import { SkeletonRows, WeatherChip } from '../ui';

/** One stop of today's plan as the weather card shows it. */
export interface WeatherStop {
  key: string;
  name: string;
  /** "11 AM to 2 PM". */
  window: string;
  /** The clock hours of the stop, for its chip. */
  fromHour: number;
  toHour: number;
  /** The stop opens after midnight: its hours are those of the next civil date. */
  nextDay: boolean;
  /** What the forecast does to this stop's orders, as a sentence; null while the day is not worked out. */
  effect: string | null;
}

export interface WeatherAtStopsProps {
  /** Today's civil date in the truck's time zone. */
  today: string;
  /** One row per stop of today's plan, in the order of the day; empty with nothing planned. */
  stops: readonly WeatherStop[];
  /** The 24 forecast hours of today (`DayContext.forecast`); undefined while the day's context loads. */
  forecast: (HourForecast | null)[] | null | undefined;
  /** The same for tomorrow, for a stop that opens after midnight. */
  forecastNext: (HourForecast | null)[] | null;
  /** State and age of the forecast. */
  info: ForecastState;
  /** The hours of the one chip shown when nothing is planned. */
  fromHour: number;
  toHour: number;
}

/**
 * "Weather" (docs/truck-planner/05_FRONTEND.md 4.1): the forecast over each stop's own hours and,
 * under it, what that forecast does to the stop's expected orders in the model's own terms. The
 * forecast is the one for the area around the truck's base (the server fetches no other), and the
 * card says so; a forecast that may be out of date says that too, in words and not only in a title.
 */
export default function WeatherAtStops({ today, stops, forecast, forecastNext, info, fromHour, toHour }: WeatherAtStopsProps) {
  const made = info.state === 'stale' ? info.generated_local : null;
  return (
    <section aria-labelledby="tp-today-weather" className="bg-white rounded-xl border p-4 sm:p-5" style={{ borderColor: 'var(--line-soft)' }}>
      <h2 id="tp-today-weather" className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
        {WEATHER_TEXT.title}
      </h2>

      {forecast === undefined ? (
        <div className="mt-3" aria-busy="true">
          <SkeletonRows rows={stops.length > 1 ? 2 : 1} rowHeight={40} />
        </div>
      ) : stops.length === 0 ? (
        <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1.5">
          <span className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
            {WEATHER_TEXT.noStops}
          </span>
          <WeatherChip forecast={forecast} fromHour={fromHour} toHour={toHour} info={info} />
        </div>
      ) : (
        <ul className="tp-stat-list mt-1">
          {stops.map((stop) => (
            <li key={stop.key} className="py-2.5">
              <p className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
                <span className="break-words">{stop.name}</span>
                {' · '}
                <span className="whitespace-nowrap tabular-nums">{stop.window}</span>
              </p>
              <div className="mt-1">
                <WeatherChip forecast={stop.nextDay ? forecastNext : forecast} fromHour={stop.fromHour} toHour={stop.toHour} info={info} />
              </div>
              {stop.effect !== null ? (
                <p className="mt-1 text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
                  {stop.effect}
                </p>
              ) : null}
            </li>
          ))}
        </ul>
      )}

      {made !== null ? (
        <p className="mt-2 flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
          <TriangleAlert size={14} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--fresh-aging)' }} />
          <span>
            This forecast is from {fmtClock(made.minute)}
            {made.date !== today ? ' on ' + fmtDay(made.date, 'medium') : ''} and may be out of date.
          </span>
        </p>
      ) : null}
      <p className="mt-2 text-xs font-semibold" style={{ color: 'var(--body)' }}>
        {WEATHER_TEXT.source}
      </p>
    </section>
  );
}
