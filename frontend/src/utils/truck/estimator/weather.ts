// Truck Planner estimator - weather (02_MODEL 4.6).

import { clamp, max2 } from './core';
import { seed } from './seeds';
import type { Assumptions, HourForecast, WeatherDetail, WeatherSetting } from './types';

/** Only A-Z become a-z; every other character is unchanged (no locale, no Unicode case mapping). */
export function asciiLower(text: string): string {
  let out = '';
  for (let i = 0; i < text.length; i++) {
    const c = text.charCodeAt(i);
    out += c >= 65 && c <= 90 ? String.fromCharCode(c + 32) : text.charAt(i);
  }
  return out;
}

// Step-function lookup: the first band whose upper bound is absent or above x.
function band(
  A: Assumptions,
  table: string,
  key: string,
  x: number,
  setting: WeatherSetting,
): { id: string | null; value: number } {
  const order = seed<string[]>(A, 'weather.' + table + '.order');
  for (let k = 0; k < order.length; k++) {
    const id = order[k];
    const upper = seed<number | null>(A, 'weather.' + table + '.rows.' + id + '.' + key);
    if (upper == null || x < upper) {
      return { id, value: seed<number>(A, 'weather.' + table + '.rows.' + id + '.' + setting) };
    }
  }
  return { id: null, value: 1.0 }; // not reached: the last band has no bound
}

/**
 * Multiplier on walk-up demand for one forecast hour. setting is "open" (people outdoors or walking
 * over) or "captive" (people already inside a venue). The class of precipitation says how bad it is if
 * it does precipitate; the forecast probability says how likely that is. A null record, or one whose
 * four fields are all null, is a missing forecast: multiplier 1.0 with missing = true.
 */
export function weatherMultiplier(A: Assumptions, fc: HourForecast | null, setting: WeatherSetting): WeatherDetail {
  if (fc == null || (fc.temp_f == null && fc.precip_prob == null && fc.short_forecast == null && fc.wind_mph == null)) {
    return {
      multiplier: 1.0,
      missing: true,
      temp: 1.0,
      precip: 1.0,
      wind: 1.0,
      temp_band: null,
      precip_class: null,
      precip_p: null,
      wind_band: null,
    };
  }
  let temp = 1.0;
  let tempBand: string | null = null;
  if (fc.temp_f != null) {
    const b = band(A, 'temperature_bands', 'upper_f', fc.temp_f, setting);
    tempBand = b.id;
    temp = b.value;
  }
  let wind = 1.0;
  let windBand: string | null = null;
  if (fc.wind_mph != null) {
    const b = band(A, 'wind_bands', 'upper_mph', fc.wind_mph, setting);
    windBand = b.id;
    wind = b.value;
  }
  let cls = 'dry';
  if (fc.short_forecast != null) {
    const text = asciiLower(fc.short_forecast);
    const order = seed<string[]>(A, 'weather.precip_classes.order'); // listed order, first hit wins
    for (let k = 0; k < order.length; k++) {
      const needles = seed<string[]>(A, 'weather.precip_classes.rows.' + order[k] + '.match');
      let hit = false;
      for (let j = 0; j < needles.length; j++) {
        if (text.indexOf(needles[j]) >= 0) hit = true;
      }
      if (hit) {
        cls = order[k];
        break;
      }
    }
  }
  const m = seed<number>(A, 'weather.precip_classes.rows.' + cls + '.' + setting);
  let p: number;
  if (cls === 'dry') {
    p = 0.0;
  } else if (fc.precip_prob == null) {
    p = seed<number>(A, 'weather.pop_when_missing');
  } else {
    p = clamp(fc.precip_prob / 100.0, 0.0, 1.0);
  }
  const precip = 1.0 - p * (1.0 - m);
  const multiplier = max2(seed<number>(A, 'weather.floor'), temp * precip * wind);
  return {
    multiplier,
    missing: false,
    temp,
    precip,
    wind,
    temp_band: tempBand,
    precip_class: cls,
    precip_p: p,
    wind_band: windBand,
  };
}
