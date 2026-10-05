// Truck Planner - every fixed string of the product (docs/truck-planner/05_FRONTEND.md section 6).
//
// Tone: plain, direct, second person, sentence case, numbers first. No exclamation marks and no hype:
// every figure is ordinary arithmetic on public data and the owner's settings. The app never says or
// implies that a spot may be used; the standing notice below is the only place that speaks about it.
// wording.test.ts holds every string of this file against the banned patterns of 6.9.

import { SEEDS } from './model';
import type {
  Confidence,
  Daypart,
  DayType,
  EventType,
  FuelPriceSource,
  PlaceType,
  RivalKind,
  SeedTag,
  SegmentGroup,
  SegmentKey,
  TimelineEventKind,
  TreatAs,
  Visibility,
  WarningCode,
  WarningLevel,
} from './model';
import { fmtClockShort, fmtDay, fmtPlain } from './format';

// -------------------------------------------------------------------------------------------------
// 6.2 Confidence labels (fixed)
// -------------------------------------------------------------------------------------------------

export const CONFIDENCE_TEXT: Readonly<Record<Confidence, { label: string; sentence: string }>> = {
  very_rough: { label: 'Very rough', sentence: 'A guess from generic assumptions. Treat it as a ranking only.' },
  rough: { label: 'Rough', sentence: 'Not yet checked against your own sales.' },
  fair: { label: 'Fair', sentence: 'Adjusted with your logged services.' },
  good: { label: 'Good', sentence: 'Backed by your results at this spot.' },
  fixed: { label: 'Fixed', sentence: 'Set by your terms, not estimated.' },
};

export function confidenceLabel(c: Confidence): string {
  return CONFIDENCE_TEXT[c].label;
}

export function confidenceSentence(c: Confidence): string {
  return CONFIDENCE_TEXT[c].sentence;
}

function lowerFirst(text: string): string {
  if (text.length === 0) return text;
  const code = text.charCodeAt(0);
  return code >= 65 && code <= 90 ? String.fromCharCode(code + 32) + text.slice(1) : text;
}

/** Label and sentence as one spoken line: "Rough: not yet checked against your own sales." */
export function confidenceSpoken(c: Confidence): string {
  return CONFIDENCE_TEXT[c].label + ': ' + lowerFirst(CONFIDENCE_TEXT[c].sentence);
}

/** An uppercase caption such as "TAKE-HOME" in sentence case for a spoken label: "Take-home". */
export function captionSpoken(caption: string): string {
  let out = '';
  for (let i = 0; i < caption.length; i++) {
    const code = caption.charCodeAt(i);
    out += i > 0 && code >= 65 && code <= 90 ? String.fromCharCode(code + 32) : caption[i];
  }
  return out;
}

// -------------------------------------------------------------------------------------------------
// 6.3 The standing notice and the standing lines
// -------------------------------------------------------------------------------------------------

export const STANDING = {
  /** PermissionNotice "line": spot card, Spots, spot detail, compare, planner, day sheet, calendar file. */
  noticeLine: 'Permission to trade here and local rules are yours to check.',
  /** PermissionNotice "block": Scout, spot detail. */
  noticeBlock:
    'Truck Planner estimates demand. It does not know who owns this land or what the local rules say. Permission to trade here and local rules are yours to check.',
  /** Under every breakdown. */
  underBreakdown: 'Estimates rank places and times. Before you log services they are poor at predicting dollars.',
  deleteSpot: 'It leaves your list. Days already planned there and its logged services are kept.',
  deleteEverything:
    'This deletes your truck, spots, plans, logged services, drive-time corrections and Scout notes. It cannot be undone. Your smappen account stays.',
  /** The phrase to type before everything is deleted. */
  deletePhrase: 'delete my truck data',
} as const;

export interface VintagesLike {
  census_reference_date: string;
  lodes_year: number;
  osm_snapshot_date: string;
}

/** The three parts of the data-vintages line, so the jobs and places parts can carry their links. */
export function vintagesParts(v: VintagesLike): { residents: string; jobs: string; placesLead: string; placesLinked: string; placesTail: string } {
  return {
    residents: 'Residents: April 2020.',
    jobs: 'Jobs: ' + String(v.lodes_year) + '.',
    placesLead: 'Places: ',
    placesLinked: 'OpenStreetMap',
    placesTail: ', ' + fmtDay(v.osm_snapshot_date, 'long') + '.',
  };
}

/** "Residents: April 2020. Jobs: 2023. Places: OpenStreetMap, Sat, Oct 3, 2026." */
export function vintagesLine(v: VintagesLike): string {
  const p = vintagesParts(v);
  return p.residents + ' ' + p.jobs + ' ' + p.placesLead + p.placesLinked + p.placesTail;
}

// -------------------------------------------------------------------------------------------------
// 6.4 Attribution strings (ids are those of 03_DATA section 14; D exists only in the browser)
// -------------------------------------------------------------------------------------------------

export const OSM_COPYRIGHT_URL = 'https://www.openstreetmap.org/copyright';
export const LEHD_URL = 'https://lehd.ces.census.gov/data/';

export const ATTRIBUTION = {
  /** 1. Wherever OpenStreetMap places are listed. */
  osm: '© OpenStreetMap contributors',
  /** 2. Where there is room for a sentence. */
  osmSentence: 'Place data © OpenStreetMap contributors, available under the Open Database License (ODbL).',
  /** 6. Weather. */
  weather: 'Forecast: National Weather Service (weather.gov).',
  /** 9. Drive times. */
  drive: 'Drive times and distances: Google Maps Platform. Kept for at most 30 days.',
  /** 10. The map legend source line; the two placeholders are filled from the region's vintages. */
  mapSource: 'People: US Census {census_year}, LEHD {lodes_year} · Venues: © OpenStreetMap contributors',
  /** 12. Next to any looked-up contact detail. */
  googleContact: 'Phone and website from Google Maps',
  /** D. The day sheet drive line. */
  sheetDrive: 'Drive times: Google Maps Platform, adjusted for the time of day.',
  sheetDriveNeutral: 'Drive times: Google Maps Platform.',
  sheetDriveStraight: 'Some drive times are straight-line estimates, not Google drive times.',
} as const;

/** String 10 in two parts, so the OpenStreetMap part can be a link. Null when a year is missing. */
export function mapSourceParts(v: VintagesLike | null | undefined): { lead: string; linked: string } | null {
  if (v === null || v === undefined) return null;
  const censusYear = v.census_reference_date.slice(0, 4);
  if (!/^\d{4}$/.test(censusYear) || !(v.lodes_year > 0)) return null;
  const full = ATTRIBUTION.mapSource.replace('{census_year}', censusYear).replace('{lodes_year}', String(v.lodes_year));
  const at = full.length - ATTRIBUTION.osm.length;
  return { lead: full.slice(0, at), linked: full.slice(at) };
}

/** String 10 filled in: "People: US Census 2020, LEHD 2023 · Venues: © OpenStreetMap contributors". */
export function mapSourceLine(v: VintagesLike | null | undefined): string | null {
  const p = mapSourceParts(v);
  return p === null ? null : p.lead + p.linked;
}

/** Row D: which drive line the day sheet prints. */
export function sheetDriveLine(anyStraightLine: boolean, trafficNeutral: boolean): string {
  if (anyStraightLine) return ATTRIBUTION.sheetDriveStraight;
  return trafficNeutral ? ATTRIBUTION.sheetDriveNeutral : ATTRIBUTION.sheetDrive;
}

// -------------------------------------------------------------------------------------------------
// 6.5 Planner warnings. The braces are filled by utils/truck/warnings.ts.
// -------------------------------------------------------------------------------------------------

export const WARNING_TEXT: Readonly<Record<WarningCode, string>> = {
  invalid_window: '{stop}: the closing time must be after the opening time.',
  stops_overlap: '{stop} opens before the stop before it closes.',
  stop_unreachable: '{stop}: you cannot arrive and set up before it closes.',
  late_arrival: '{stop}: you would open {late_minutes} min late, at {effective_open}.',
  outside_region: '{stop} is outside the loaded counties. People around it are missing or only partly counted.',
  stale_vectors: "{stop}: this estimate is out of date. It updates when the spot's details finish saving.",
  outside_allowed_hours: '{stop} falls outside the days or hours you set for this spot.',
  fallback_drive_time: 'Some drive times are straight-line estimates, not Google drive times.',
  long_gap: '{gap} of paid waiting before {stop}.',
  long_day: 'This is a {day_length} day, prep to done.',
  fee_high: '{stop}: the fee is {fee_share} of expected sales.',
  below_break_even: '{stop} is expected to lose money once its added costs are counted.',
  event_thin_crowd: '{stop}: a thin crowd for the number of food vendors.',
  weak_day_loss: '{stop} loses money on a weak day.',
  capacity_bound: '{stop}: demand is above what the truck can serve for part of the time.',
  early_start: 'Prep starts at {start_prep}.',
  ends_after_midnight: 'The day ends after midnight, at {done}.',
  no_forecast: 'No forecast for some of these hours, so no weather adjustment there.',
  holiday: '{holiday} is a federal holiday. Patterns follow the holiday settings.',
  weak_seed: '{stop}: most of this estimate rests on hospital, campus or transit figures, the weakest in the model.',
  default_host_size:
    '{stop}: the host size is a typical figure for this kind of place. Enter the real size to tighten the range.',
};

/** The holiday warning on a day that is a holiday only because the owner said so. */
export const WARNING_HOLIDAY_BY_CHOICE = 'This day is treated as a holiday. Patterns follow the holiday settings.';

/** A code this build does not know: a new model warning never crashes the page. */
export const WARNING_UNKNOWN = 'Check this stop.';

/** The visible prefix of each level; the icon and its colour are the list's. */
export const WARNING_PREFIX: Readonly<Record<WarningLevel, string>> = {
  error: 'Problem:',
  warn: 'Check:',
  info: 'Note:',
};

/** A stop the list has no name for. */
export function stopFallbackName(index: number): string {
  return 'Stop ' + String(index + 1);
}

// -------------------------------------------------------------------------------------------------
// 6.6 Seed tags
// -------------------------------------------------------------------------------------------------

export const SEED_TAG_TEXT: Readonly<Record<SeedTag, { chip: string; hint: string }>> = {
  measured: { chip: 'Measured', hint: 'Published by a source we opened.' },
  derived: { chip: 'Derived', hint: 'Worked out from measured figures.' },
  assumed: { chip: 'Assumed', hint: 'Our judgement. Not measured.' },
  tuned: { chip: 'Placeholder', hint: 'Placeholder until you log services.' },
};

/** How a seed tagged "tuned" reads in a list of assumptions. */
export const PLACEHOLDER_SEED = 'Placeholder until you log services';

// -------------------------------------------------------------------------------------------------
// 6.7 Drive-time source labels
// -------------------------------------------------------------------------------------------------

export const DRIVE_SOURCE = {
  override: 'Your time',
  google: 'Google drive time',
  samePlace: 'Same place',
  straightLine: 'Straight-line estimate',
} as const;

/** The server's truthful label of a leg (DriveLeg.source of 04_BACKEND 4.1). */
export type DriveLegSourceKey = 'google_routes' | 'google_distance_matrix' | 'straight_line' | 'same_point';

/** Why Google did not supply a leg (DriveLeg.fallback_reason). */
export type FallbackReasonKey =
  | 'no_key'
  | 'refused'
  | 'quota'
  | 'budget'
  | 'rate'
  | 'timeout'
  | 'upstream'
  | 'route_not_found'
  | 'cache_only';

export interface DriveLabelInput {
  /** Leg.source of the model: google, fallback or override. */
  legSource: 'google' | 'fallback' | 'override';
  /** DriveLeg.source of the server; null when there is no leg at all. */
  driveSource: DriveLegSourceKey | null;
  /** Leg.depart_minute. */
  departMinute: number | null;
  /** trafficIsNeutral(A): the wording then drops the time-of-day adjustment. */
  trafficNeutral: boolean;
}

/** The label of one leg. A straight line is "Straight-line estimate"; its reason is driveFallbackReason. */
export function driveSourceLabel(input: DriveLabelInput): string {
  if (input.legSource === 'override') return DRIVE_SOURCE.override;
  if (input.driveSource === 'same_point') return DRIVE_SOURCE.samePlace;
  if (input.legSource === 'fallback' || input.driveSource === null || input.driveSource === 'straight_line') {
    return DRIVE_SOURCE.straightLine;
  }
  if (input.trafficNeutral || input.departMinute === null) return DRIVE_SOURCE.google;
  return DRIVE_SOURCE.google + ', adjusted for ' + fmtClockShort(input.departMinute) + ' traffic';
}

/** The sentence after "Straight-line estimate", or null when there is nothing to add. */
export function driveFallbackReason(reason: FallbackReasonKey | null | undefined): string | null {
  switch (reason) {
    case 'no_key':
    case 'refused':
      return 'Google drive times are not switched on for this server.';
    case 'quota':
    case 'budget':
    case 'rate':
      return 'The Google drive-time allowance is used up for now.';
    case 'timeout':
    case 'upstream':
      return 'Google did not answer in time.';
    case 'route_not_found':
      return 'Google found no route.';
    default:
      return null;
  }
}

/** The page strips of 2.6. */
export const STRIPS = {
  forecastFailed: 'Could not load the forecast. Showing no weather adjustment.',
  driveTimesUnavailable: 'Google drive times are unavailable. Drive times are straight-line estimates.',
  tryAgain: 'Try again',
} as const;

// -------------------------------------------------------------------------------------------------
// 6.8 Place type and segment labels
// -------------------------------------------------------------------------------------------------

export const PLACE_TYPE_LABELS: Readonly<Record<PlaceType, string>> = {
  taproom: 'Brewery or taproom',
  bar: 'Bar or pub',
  restaurant: 'Restaurant',
  fast_food: 'Fast food',
  cafe: 'Cafe',
  convenience: 'Convenience or grocery store',
  gym: 'Gym or sports centre',
  park: 'Park',
  shopping_centre: 'Shopping centre',
  big_box: 'Big-box store',
  campus: 'College campus',
  hospital: 'Hospital',
  transit_station: 'Transit station',
  events_venue: 'Events venue',
  stadium: 'Stadium',
  hotel: 'Hotel',
  attraction: 'Museum or attraction',
  farmers_market: 'Farmers market',
  office_park: 'Office park',
  apartment_community: 'Apartment community',
  industrial_site: 'Industrial site',
  car_dealership: 'Car dealership',
};

/** The label of a place type; an unknown type (a later data build) reads "Place". */
export function placeTypeLabel(type: string | null | undefined): string {
  if (type !== null && type !== undefined && Object.prototype.hasOwnProperty.call(PLACE_TYPE_LABELS, type)) {
    return PLACE_TYPE_LABELS[type as PlaceType];
  }
  return 'Place';
}

/** Segment labels come from the seed file (segments.<s>.label). */
export function segmentLabel(segment: SegmentKey): string {
  return SEEDS.segments[segment].label;
}

export function segmentGroup(segment: SegmentKey): SegmentGroup {
  return SEEDS.segments[segment].group;
}

/** After a host size, by the group of its segment: "120 people in its busiest hour". */
export const SIZE_UNIT_PHRASE: Readonly<Record<SegmentGroup, string>> = {
  visitors: 'people in its busiest hour',
  workers: 'people working there',
  residents: 'people living there',
};

/** The label of the host size field, by the group of the host's segment. */
export const HOST_SIZE_LABEL: Readonly<Record<SegmentGroup, string>> = {
  visitors: 'People there in its busiest hour',
  workers: 'People who work there',
  residents: 'People who live there',
};

/** What one unit of a segment's base is, for the breakdown. */
export const GROUP_BASE_PHRASE: Readonly<Record<SegmentGroup, string>> = {
  visitors: 'at the busiest hour',
  workers: 'jobs nearby',
  residents: 'residents nearby',
};

/** How presence reads for a group, for the breakdown. */
export const GROUP_PRESENT_PHRASE: Readonly<Record<SegmentGroup, string>> = {
  visitors: 'there at this hour',
  workers: 'on site at this hour',
  residents: 'at home at this hour',
};

export const RIVAL_KIND_LABELS: Readonly<Record<RivalKind, string>> = {
  quick: 'Quick service',
  full: 'Sit-down restaurant',
  cafe: 'Cafe or bakery',
  bar: 'Bar',
  convenience: 'Convenience or grocery',
};

export const VISIBILITY_TEXT: Readonly<Record<Visibility, { label: string; help: string }>> = {
  hidden: { label: 'Hidden', help: 'Tucked away from where people walk.' },
  normal: { label: 'Normal', help: '' },
  prominent: { label: 'Prominent', help: 'On the main path, signposted or announced by the host.' },
};

export const EVENT_TYPE_LABELS: Readonly<Record<EventType, string>> = {
  general: 'General (fair, market, sports)',
  food_focused: 'Food is the draw',
  evening_show: 'Evening show',
  incidental: 'Food is incidental',
};

export const DAYPART_LABELS: Readonly<Record<Daypart, string>> = {
  breakfast: 'Breakfast (5 to 11 AM)',
  lunch: 'Lunch (11 AM to 4 PM)',
  dinner: 'Dinner (4 to 10 PM)',
  late: 'Late (10 PM to 5 AM)',
};

/** The same parts of the day inside a sentence: "100% for lunch". */
export const DAYPART_WORDS: Readonly<Record<Daypart, string>> = {
  breakfast: 'breakfast',
  lunch: 'lunch',
  dinner: 'dinner',
  late: 'late hours',
};

export const DAY_TYPE_LABELS: Readonly<Record<DayType, string>> = {
  weekday: 'Weekday',
  saturday: 'Saturday',
  sunday: 'Sunday',
};

/** The options of "Treat this day as"; the null value is "Automatic". */
export const TREAT_AS_AUTOMATIC = 'Automatic';
export const TREAT_AS_LABELS: Readonly<Record<TreatAs, string>> = {
  normal: 'A normal day (ignore the holiday)',
  holiday: 'A holiday',
  mon: 'A Monday',
  tue: 'A Tuesday',
  wed: 'A Wednesday',
  thu: 'A Thursday',
  fri: 'A Friday',
  sat: 'A Saturday',
  sun: 'A Sunday',
};

/** The six lead statuses of Scout. */
export const LEAD_STATUS_LABELS = {
  new: 'New',
  shortlisted: 'Shortlisted',
  contacted: 'Contacted',
  booked: 'Booked',
  declined: 'Declined',
  hidden: 'Hidden',
} as const;

// -------------------------------------------------------------------------------------------------
// The kit: estimates, breakdown, fields, charts, chips
// -------------------------------------------------------------------------------------------------

export const WHY = {
  heading: 'Why this number',
  button: 'Why this number',
  hourPicker: 'Hour that steps 1 to 9 describe',
  hourPickerLead: 'Steps 1 to 9 for',
  step: 'Step',
  showAllSegments: 'Show all 16',
  technicalDetail: 'Technical detail',
  yourValue: 'Your value',
  changeInSettings: 'Change in Settings',
  startingValue: 'Starting value',
  noHours: 'This window has no hours.',
  noHost: 'No host at this spot.',
  noForecast: 'No forecast for this hour: no weather adjustment.',
  typicalWeek: 'Typical week: no weather adjustment.',
  noLogs: 'No logged services yet.',
  noLogsHere: 'No services logged at this spot yet.',
  cappedRange: 'The strong-day figure is limited by how fast the truck can serve.',
  eventMenuFit: 'Menu fit is not applied to events.',
  eventNoHost: 'No host: this is an event stop.',
  eventSpotResults: 'Results at single spots are not used for events.',
  dayRange: "Each stop's weak-day and strong-day figures are added up, so the day's range is wide on purpose.",
  nobodyNearby: 'Almost nobody is within walking distance at this hour.',
  sharesNotStored: 'Shares are not shown for this point: only distance-weighted figures are stored for it.',
  noFee: 'No fee at this stop.',
  feeMinimum: 'The minimum fee applies.',
  feeFlatPlusShare: 'The flat fee plus the share of sales applies.',
  cateringFixed: 'A catering job is contracted, not estimated.',
  eventFromAttendance: 'An event is estimated from its attendance, not from the people nearby.',
} as const;

/** The thirteen steps of "Why this number", in their fixed order (02_MODEL section 5). */
export const WHY_STEP_TITLES: readonly string[] = [
  'Who is within walking distance',
  'How many of them buy a meal this hour',
  'What share the truck wins',
  'Menu fit',
  'Host',
  'Subtotal before adjustments',
  'Weather',
  'Your own results',
  'Capacity',
  'Window',
  'Range and label',
  'Money',
  'Where the assumptions come from',
];

/** One row of step 11 per part of the spread. */
export const SPREAD_PART_LABELS = {
  v_truck: 'How well the model fits your truck overall',
  v_spot: 'How this spot differs from your average',
  v_day: 'Normal day-to-day swings',
  v_weak: 'Weak figures for hospitals, campuses and stations',
  v_size: 'The host size is a typical figure, not yours',
  v_event: "Attendance is the organiser's guess",
  v_count: 'Small numbers bounce around',
} as const;

export const MONEY_LINE_LABELS = {
  orders: 'Orders',
  sales: 'Sales',
  food_cost: 'Food cost',
  packaging: 'Packaging',
  card_fees: 'Card fees',
  spot_fee: 'Spot fee',
  spot_fees: 'Spot fees',
  tips: 'Tips',
  contribution: 'Left after food and fees',
  labour: 'Labour',
  fuel: 'Fuel',
  tolls: 'Tolls',
  fixed_cost: 'Fixed cost for the day',
  take_home: 'Take-home',
  take_home_per_hour: 'Per hour of your day',
} as const;

/** How the fuel price of a day reads in a cost line. */
export const FUEL_SOURCE_PHRASE: Readonly<Record<FuelPriceSource, string>> = {
  owner: 'your price',
  eia: 'EIA weekly average',
  seed: 'default price',
};

export const FIELD = {
  required: 'Required',
  number: 'Enter a number.',
  wholeNumber: 'Enter a whole number.',
  timeHelp: 'Type a time, like 11 or 2:30 pm.',
  timeUnreadable: 'That is not a time. Type one like 11 or 2:30 pm.',
  pickDate: 'Pick a date',
  previousDay: 'Previous day',
  nextDay: 'Next day',
  today: 'Today',
  close: 'Close',
  showMore: 'Show more',
  showLess: 'Show less',
} as const;

/** "Enter a number from 1 to 200." Bounds are printed plainly, grouped, without trailing zeros. */
export function numberRangeMessage(min: number | null | undefined, max: number | null | undefined, whole = false): string {
  const noun = whole ? 'a whole number' : 'a number';
  const lo = min === null || min === undefined ? null : fmtPlain(min);
  const hi = max === null || max === undefined ? null : fmtPlain(max);
  if (lo !== null && hi !== null) return 'Enter ' + noun + ' from ' + lo + ' to ' + hi + '.';
  if (lo !== null) return 'Enter ' + noun + ' of ' + lo + ' or more.';
  if (hi !== null) return 'Enter ' + noun + ' of ' + hi + ' or less.';
  return whole ? FIELD.wholeNumber : FIELD.number;
}

/** "Enter a time from 11:00 AM to 2:00 PM." The two bounds arrive already formatted. */
export function timeRangeMessage(min: string, max: string): string {
  return 'Enter a time from ' + min + ' to ' + max + '.';
}

/** "15 minutes earlier" and "15 minutes later", the two buttons of a time field. */
export function timeStepLabel(step: number, later: boolean): string {
  return String(step) + ' minutes ' + (later ? 'later' : 'earlier');
}

/** Messages for the override error codes of the model (02_MODEL 2.2), also used for a 422. */
export function overrideErrorMessage(code: string, min?: number | null, max?: number | null): string {
  if (code === 'out_of_bounds') return numberRangeMessage(min, max);
  if (code === 'wrong_shape') return 'Enter all 24 values.';
  if (code === 'not_allowed') return 'Choose one of the listed options.';
  return 'This value cannot be changed.';
}

export const OPEN_IN_MAPS = {
  point: 'Open in Google Maps',
  route: 'Open route in Google Maps',
  leg: 'Check this drive in Google Maps',
} as const;

export const QUERY_ERROR_RETRY = 'Try again';

export const TABLE = {
  empty: 'Nothing here yet.',
  open: 'Open',
} as const;

export const CHART = {
  truckLimit: 'Truck limit',
  legend: 'Chart legend',
} as const;

/** Labels of the timeline events, in the list orientation and on the day sheet. `{stop}` is the stop's name. */
export const TIMELINE_EVENT_LABELS: Readonly<Record<TimelineEventKind, string>> = {
  start_prep: 'Start prep',
  leave_base: 'Leave base',
  arrive: 'Arrive at {stop}',
  setup_start: 'Start setting up',
  open: 'Open',
  close: 'Close',
  leave: 'Leave {stop}',
  back_at_base: 'Back at base',
  done: 'Done',
};

/** What a stretch of the day is. A wait says in words whether it is paid. */
export const TIMELINE_SEGMENT_LABELS = {
  prep: 'Prep',
  drive: 'Driving',
  wait: 'Waiting, paid',
  wait_unpaid: 'Break, unpaid',
  setup: 'Setting up',
  service: 'Serving',
  teardown: 'Packing up',
  closeout: 'Close-out',
} as const;

/** The legend of the timeline bar: one entry per colour. */
export const TIMELINE_LEGEND = {
  prep: 'Prep and close-out',
  drive: 'Driving',
  setup: 'Setup and pack-up',
  service: 'Serving',
  wait: 'Waiting',
} as const;

/** Chip words of the precipitation classes of the model (weather.precip_classes). */
export const PRECIP_CLASS_WORDS: Readonly<Record<string, string>> = {
  storm: 'Storms',
  heavy_snow: 'Heavy snow',
  ice: 'Ice',
  heavy_rain: 'Heavy rain',
  snow: 'Snow',
  light_rain: 'Light rain',
  rain: 'Rain',
  dry: 'Dry',
};

/** The chip word of a precipitation class; a class this build does not know reads "Rain or snow". */
export function precipClassWord(cls: string | null | undefined): string {
  if (cls !== null && cls !== undefined && Object.prototype.hasOwnProperty.call(PRECIP_CLASS_WORDS, cls)) {
    return PRECIP_CLASS_WORDS[cls];
  }
  return 'Rain or snow';
}

export const WEATHER = {
  none: 'No forecast yet',
  noneHelp: 'Forecasts cover about six days.',
  title: 'Forecast for the area around your base',
  noChance: 'The forecast gives no chance for these hours.',
  staleTail: ', may be out of date',
} as const;

export const TEMPERATURE_BAND_LABELS: Readonly<Record<string, string>> = {
  below_20: 'Below 20°F',
  '20_31': '20 to 31°F',
  '32_39': '32 to 39°F',
  '40_49': '40 to 49°F',
  '50_59': '50 to 59°F',
  '60_79': '60 to 79°F',
  '80_89': '80 to 89°F',
  '90_94': '90 to 94°F',
  '95_up': '95°F and above',
};

export const WIND_BAND_LABELS: Readonly<Record<string, string>> = {
  calm: 'Under 20 mph',
  windy: '20 to 29 mph',
  very_windy: '30 mph and above',
};

/** The two weather settings, as the column heads of the weather tables read. */
export const WEATHER_SETTING_LABELS = {
  open: 'Open-air spots',
  captive: 'Inside a venue',
} as const;

export const HOLIDAY_CHIP = {
  ignored: 'Holiday ignored',
  byChoice: 'Treated as a holiday',
} as const;

/** "Treated as a Saturday": the chip when the owner picked a weekday for the date. */
export function treatedAsDay(weekdayLong: string): string {
  return 'Treated as a ' + weekdayLong;
}

/** The four verdicts on a logged service (4.7). */
export const VERDICT_WORDS = {
  inside: 'inside the range',
  above: 'above the range',
  below: 'below the range',
  sold_out: 'sold out, counted as a minimum',
} as const;

/** Tags a saved spot can carry in lists (rule 5 of 2.5). */
export const SPOT_STATE_TAGS = {
  stale: 'Updating',
  rebuilding: 'Out of date',
  none: 'No estimate yet',
  archived: 'Deleted',
} as const;

// -------------------------------------------------------------------------------------------------
// Plain-language labels of the seeds, keyed by seed path (Settings > Assumptions and step 13)
// -------------------------------------------------------------------------------------------------

const SEED_LABELS: Readonly<Record<string, string>> = {
  'kernel.walk_decay_m': 'How fast interest falls with walking distance',
  'kernel.walk_cutoff_m': 'Longest walk that is counted',
  'kernel.outside_option_a0': 'Pull of every other way to eat',
  'host.captive_share': "Share of a venue's meal buyers the truck wins as its only food",
  'host.shared_kitchen_share': 'Share the truck wins when the venue sells its own food',
  'host.onsite_kitchen_weight': "Pull of a host's own kitchen or cafeteria",
  'host.exclusion_radius_m': "Distance within which a host's people are taken out of the nearby count",
  'host.venue_link_radius_m': 'Distance within which a venue counts as the host',
  'weather.floor': 'Lowest the weather can take an estimate',
  'weather.pop_when_missing': 'Chance of rain or snow when the forecast gives none',
  'events.attendance_haircut': "Share of the organiser's attendance figure that is counted",
  'events.min_attendees_per_vendor': 'Fewest attendees per food vendor before the crowd reads as thin',
  'events.suggested_fee_pct': 'Typical event fee as a share of sales',
  'events.suggested_fee_min': 'Typical minimum event fee',
  'uncertainty.sd_truck': 'Spread: how well the model fits a truck overall',
  'uncertainty.sd_spot': 'Spread: how a spot differs from the truck average',
  'uncertainty.sd_day': 'Spread: normal day-to-day swings',
  'uncertainty.sd_weak': 'Spread: weak figures for hospitals, campuses and stations',
  'uncertainty.sd_default_size': 'Spread: a host size taken from a typical figure',
  'uncertainty.sd_event': "Spread: attendance as the organiser's guess",
  'uncertainty.count_dispersion': 'How much small counts bounce around',
  'uncertainty.resid_prior_weight': 'Services before your own day-to-day swings weigh as much as the starting figure',
  'uncertainty.label_good_below': 'Spread under which an estimate reads Good',
  'uncertainty.label_fair_below': 'Spread under which an estimate reads Fair',
  'uncertainty.label_rough_below': 'Spread under which an estimate reads Rough',
  'calibration.k_truck': 'Logged services it takes to weigh as much as the model, for the truck',
  'calibration.k_spot': 'Logged services it takes to weigh as much as the model, for one spot',
  'calibration.half_life_days': 'Days after which a logged service counts half',
  'calibration.ratio_clamp': 'Largest correction one service can make to the truck',
  'calibration.spot_ratio_clamp': 'Largest correction one service can make to its spot',
  'calibration.min_predicted': 'Smallest estimate that informs the truck factor',
  'calibration.min_actual': 'Smallest order count used in a ratio',
  'calibration.min_resid_n': 'Services needed before your own day-to-day swings are measured',
  'timeline.long_gap_minutes': 'A paid wait from this length is pointed out',
  'timeline.long_day_minutes': 'A day longer than this is pointed out',
  'timeline.early_start_minute': 'Prep before this time of day is pointed out',
  'money.fee_warn_share': 'A fee above this share of sales is pointed out',
  'drive_fallback.detour_factor': 'Road miles for each straight-line mile',
  'drive_fallback.local_miles': 'Miles driven at local speed',
  'drive_fallback.local_mph': 'Local speed without traffic',
  'drive_fallback.trunk_mph': 'Main-road speed without traffic',
  'traffic.dc': 'Traffic by day and hour, Washington region',
  'traffic.us_mean': 'Traffic by day and hour, national average',
  'traffic.dc_typical': 'Average traffic over all hours, Washington region',
  'traffic.us_mean_typical': 'Average traffic over all hours, national average',
  'constants.z80': 'Width of an 80% range',
  'hours.regime_of_hour': 'Which hours use day and which use evening competition',
  'hours.daypart_of_hour': 'Which hours are breakfast, lunch, dinner and late',
  'map.opportunity_hi': 'Top of the orders colour scale',
  'map.people_hi': 'Top of the people colour scale',
  'map.competition_hi': 'Top of the competition colour scale',
};

/** A plain-language label for a seed path; a path without one is returned as it is. */
export function seedLabel(path: string): string {
  if (Object.prototype.hasOwnProperty.call(SEED_LABELS, path)) return SEED_LABELS[path];
  const k = path.split('.');
  if (k[0] === 'segments' && k.length >= 3 && Object.prototype.hasOwnProperty.call(SEEDS.segments, k[1])) {
    const who = segmentLabel(k[1] as SegmentKey);
    const dayType = k.length >= 4 && Object.prototype.hasOwnProperty.call(DAY_TYPE_LABELS, k[3]) ? DAY_TYPE_LABELS[k[3] as DayType] : '';
    if (k[2] === 'presence') return who + ': people present by hour' + (dayType === '' ? '' : ', ' + lowerFirst(dayType));
    if (k[2] === 'intent') return who + ': share buying a meal each hour' + (dayType === '' ? '' : ', ' + lowerFirst(dayType));
    if (k[2] === 'dow_factor') return who + ': Monday to Friday adjustment';
    if (k[2] === 'holiday_day_type') return who + ': pattern on a ' + (k[3] === 'major' ? 'major' : 'minor') + ' holiday';
  }
  if (k[0] === 'weather' && k[2] === 'rows' && k.length >= 4) {
    let row = k[3];
    if (k[1] === 'temperature_bands' && Object.prototype.hasOwnProperty.call(TEMPERATURE_BAND_LABELS, row)) row = TEMPERATURE_BAND_LABELS[row];
    else if (k[1] === 'wind_bands' && Object.prototype.hasOwnProperty.call(WIND_BAND_LABELS, row)) row = 'Wind ' + lowerFirst(WIND_BAND_LABELS[row]);
    else if (k[1] === 'precip_classes') row = precipClassWord(row);
    if (k[4] === 'open') return row + ': ' + lowerFirst(WEATHER_SETTING_LABELS.open);
    if (k[4] === 'captive') return row + ': ' + lowerFirst(WEATHER_SETTING_LABELS.captive);
    return row;
  }
  if (k[0] === 'events' && k[1] === 'p_buy' && k.length === 3 && Object.prototype.hasOwnProperty.call(EVENT_TYPE_LABELS, k[2])) {
    return 'Share of people who buy a meal: ' + lowerFirst(EVENT_TYPE_LABELS[k[2] as EventType]);
  }
  if (k[0] === 'kernel' && k[1] === 'visibility' && k.length === 3 && Object.prototype.hasOwnProperty.call(VISIBILITY_TEXT, k[2])) {
    return 'Pull of the truck at ' + lowerFirst(VISIBILITY_TEXT[k[2] as Visibility].label) + ' visibility';
  }
  if (k[0] === 'kernel' && k[1] === 'rival_weight' && k.length >= 3 && Object.prototype.hasOwnProperty.call(RIVAL_KIND_LABELS, k[2])) {
    return 'Pull of one outlet: ' + lowerFirst(RIVAL_KIND_LABELS[k[2] as RivalKind]) + (k[3] === 'eve' ? ', evening' : k[3] === 'day' ? ', day' : '');
  }
  return path;
}
