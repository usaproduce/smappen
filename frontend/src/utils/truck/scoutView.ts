// Truck Planner - Scout, suggestions, events and catering: what their screens decide without the DOM
// (docs/truck-planner/05_FRONTEND.md 4.8, rules 5 and 6 of 4.5, the best-week panel of 4.6).
//
// Pure functions, no I/O, no clock, no React. Six things live here:
//
//   1. The Scout answer as the server sends it (04_BACKEND 4.15): a list balanced by kind of place,
//      and a contact lookup whose details are passed on and stored nowhere.
//   2. The Scout page's address, its filters and its kinds.
//   3. The sentences of a Scout card. Nothing here says that a place takes trucks: these are places
//      that could be asked. The server's score ranks and is never read.
//   4. A contact lookup: what Google answered, shown for the visit and never saved.
//   5. "Save as spot".
//   6. Suggested days and weeks, and the terms of an event or a catering stop.
//
// No model maths is done here. Every estimate is the server's (Scout, suggestions) or the
// estimator's (a planned day) and reaches the screen through RangeValue.

import type { DriveLegSource, LeadSpotBody, LeadStatus, PlanBody, RegionCounty, ScoutPlace } from '../../api/truck';
import type { StopLike } from './assemble';
import { fmtClock, fmtCount, fmtDay, fmtDuration, fmtMiles, fmtMoney, fmtPercent, fmtPhone, fmtWeekday, fmtWindow } from './format';
import { countyLabel } from './hourControl';
import { SEEDS, dayOfWeek, seed } from './model';
import type {
  Assumptions,
  CateringTerms,
  Estimate,
  EventTerms,
  EventType,
  KitchenState,
  PlaceType,
  ScoutBestWindow,
  ScoutResult,
  SegmentKey,
  Suggestion,
  Visibility,
  WeekSuggestion,
} from './model';
import { SPOT_LIMITS } from './spotSummary';
import {
  ATTRIBUTION,
  HOST_SIZE_LABEL,
  LEAD_STATUS_LABELS,
  PLACE_TYPE_LABELS,
  SIZE_UNIT_PHRASE,
  numberRangeMessage,
  placeTypeLabel,
  segmentGroup,
} from './wording';

// -------------------------------------------------------------------------------------------------
// 1. The Scout answer (04_BACKEND 4.1 and 4.15, as the server sends it)
// -------------------------------------------------------------------------------------------------

/**
 * What a contact lookup left on a lead: Google's id of the place, the outcome of the search and its
 * time. It holds no contact detail: the name, address, phone and website of an answer are passed on
 * to the browser and stored nowhere.
 */
export interface LeadMatch {
  place_id: string | null;
  lookup_state: 'found' | 'not_found';
  /** When the place was searched for by name, as stored (`YYYY-MM-DD HH:MM:SS`). For display only. */
  matched_at: string;
  /** A link the server builds from the place id; null without one. */
  maps_url: string | null;
}

export interface ScoutLead {
  /** Null for a place the owner has not touched. */
  id: string | null;
  place_key: string;
  status: LeadStatus;
  notes: string | null;
  /** The spot saved from this lead, while that spot is not deleted. */
  spot_id: string | null;
  google: LeadMatch | null;
}

/** One place that could be asked. `result.position` counts inside the kind; `result.score` is never shown. */
export interface ScoutCandidate {
  /** The place type: the list is balanced by it. */
  kind: string;
  result: ScoutResult;
  place: ScoutPlace;
  lead: ScoutLead;
  maps_url: string;
  leg_sources: { out: DriveLegSource; back: DriveLegSource };
  /** Every hour of the best window fills the truck. */
  at_capacity: boolean;
  /** Orders places at capacity among themselves on the server. A ranking key, never shown. */
  demand_key: number | null;
  /** Further places of the same site that this entry stands for. */
  merged: number;
}

export interface ScoutKind {
  kind: string;
  /** Places of the kind the server looked at. */
  screened: number;
  /** Places of the kind in the answer. */
  listed: number;
  /** Further places the listed ones stand for. */
  merged: number;
}

export interface ScoutList {
  /** The best places of every kind, one kind after the other in the order of `kinds`. */
  candidates: ScoutCandidate[];
  kinds: ScoutKind[];
  /** No kind holds more places than this. */
  quota: number;
  screened: number;
  truncated: boolean;
  limit_minutes: number;
  licence_counties: string[];
  dataset_version: string | null;
  cached: boolean;
  /** The standing reminder that travels with every list. The page prints its own copy (PermissionNotice). */
  notice: string;
  attribution: string[];
}

/** Google's answer to one lookup: passed on, stored nowhere. */
export interface Contact {
  found: boolean;
  name: string | null;
  address: string | null;
  phone: string | null;
  website: string | null;
  maps_uri: string | null;
  /** UTC, `YYYY-MM-DDTHH:MM:SSZ`. Not parsed in the browser. */
  fetched_at: string;
  source: 'text_search' | 'place_details';
  saved: false;
  attribution: string;
}

export interface ContactAnswer {
  lead: ScoutLead;
  contact: Contact;
}

/** What a place without a name in the map data is called. */
export const UNNAMED_PLACE = 'Unnamed place';

type Dict = Record<string, unknown>;

function isDict(x: unknown): x is Dict {
  return typeof x === 'object' && x !== null && !Array.isArray(x);
}

function textOrNull(x: unknown): string | null {
  return typeof x === 'string' && x !== '' ? x : null;
}

function countOf(x: unknown): number {
  return typeof x === 'number' && x === x && x > 0 ? Math.floor(x) : 0;
}

/** The `google` block of a lead as the server sends it now; anything else (an older shape) is no match. */
export function readLeadMatch(raw: unknown): LeadMatch | null {
  if (!isDict(raw)) return null;
  const state = raw.lookup_state;
  if (state !== 'found' && state !== 'not_found') return null;
  return {
    place_id: textOrNull(raw.place_id),
    lookup_state: state,
    matched_at: typeof raw.matched_at === 'string' ? raw.matched_at : '',
    maps_url: textOrNull(raw.maps_url),
  };
}

/** A lead as the screens read it. */
export function readLead(raw: unknown, placeKey: string): ScoutLead {
  const lead = isDict(raw) ? raw : {};
  const status = lead.status;
  return {
    id: textOrNull(lead.id),
    place_key: typeof lead.place_key === 'string' ? lead.place_key : placeKey,
    status: isLeadStatus(status) ? status : 'new',
    notes: textOrNull(lead.notes),
    spot_id: textOrNull(lead.spot_id),
    google: readLeadMatch(lead.google),
  };
}

/**
 * The answer of route 38 as the screens read it. The fields that describe the list (`kind`, `kinds`,
 * `quota`, `at_capacity`, `merged`) get a value when an answer leaves them out, so a list from an
 * older server still shows: its kinds are then read off the candidates.
 */
export function readScoutList(raw: unknown): ScoutList {
  const data = isDict(raw) ? raw : {};
  const candidates: ScoutCandidate[] = [];
  const rows = Array.isArray(data.candidates) ? data.candidates : [];
  for (const row of rows) {
    if (!isDict(row) || !isDict(row.result) || !isDict(row.place)) continue;
    const record = row.place as unknown as ScoutPlace;
    const result = row.result as unknown as ScoutResult;
    if (typeof record.place_key !== 'string' || typeof record.place_type !== 'string') continue;
    // A place the map data has no name for still gets a line of its own.
    const named = typeof record.name === 'string' && record.name.trim() !== '';
    const place: ScoutPlace = named ? record : { ...record, name: UNNAMED_PLACE };
    const legs = isDict(row.leg_sources) ? row.leg_sources : {};
    candidates.push({
      kind: typeof row.kind === 'string' && row.kind !== '' ? row.kind : place.place_type,
      result,
      place,
      lead: readLead(row.lead, place.place_key),
      maps_url: typeof row.maps_url === 'string' ? row.maps_url : '',
      leg_sources: { out: legs.out as DriveLegSource, back: legs.back as DriveLegSource },
      at_capacity: row.at_capacity === true,
      demand_key: typeof row.demand_key === 'number' ? row.demand_key : null,
      merged: countOf(row.merged),
    });
  }

  let kinds: ScoutKind[] = [];
  if (Array.isArray(data.kinds)) {
    for (const row of data.kinds) {
      if (!isDict(row) || typeof row.kind !== 'string' || row.kind === '') continue;
      kinds.push({ kind: row.kind, screened: countOf(row.screened), listed: countOf(row.listed), merged: countOf(row.merged) });
    }
  } else {
    // An answer without the kinds: one row per kind, in the order the candidates come in.
    const seen = new Map<string, ScoutKind>();
    for (const candidate of candidates) {
      let row = seen.get(candidate.kind);
      if (row === undefined) {
        row = { kind: candidate.kind, screened: 0, listed: 0, merged: 0 };
        seen.set(candidate.kind, row);
      }
      row.listed += 1;
      row.merged += candidate.merged;
    }
    kinds = Array.from(seen.values());
  }

  let quota = countOf(data.quota);
  if (quota === 0) {
    for (const row of kinds) if (row.listed > quota) quota = row.listed;
  }

  const strings = (x: unknown): string[] => (Array.isArray(x) ? x.filter((item): item is string => typeof item === 'string') : []);
  return {
    candidates,
    kinds,
    quota,
    screened: countOf(data.screened),
    truncated: data.truncated === true,
    limit_minutes: countOf(data.limit_minutes),
    licence_counties: strings(data.licence_counties),
    dataset_version: textOrNull(data.dataset_version),
    cached: data.cached === true,
    notice: typeof data.notice === 'string' ? data.notice : '',
    attribution: strings(data.attribution),
  };
}

/** The answer of route 40. Null when it carries no contact (an answer this build cannot read). */
export function readContactAnswer(raw: unknown, placeKey: string): ContactAnswer | null {
  if (!isDict(raw) || !isDict(raw.contact)) return null;
  const c = raw.contact;
  return {
    lead: readLead(raw.lead, placeKey),
    contact: {
      found: c.found === true,
      name: textOrNull(c.name),
      address: textOrNull(c.address),
      phone: textOrNull(c.phone),
      website: textOrNull(c.website),
      maps_uri: textOrNull(c.maps_uri),
      fetched_at: typeof c.fetched_at === 'string' ? c.fetched_at : '',
      source: c.source === 'place_details' ? 'place_details' : 'text_search',
      saved: false,
      attribution: typeof c.attribution === 'string' && c.attribution !== '' ? c.attribution : ATTRIBUTION.googleContact,
    },
  };
}

// -------------------------------------------------------------------------------------------------
// 2. The page: its address, its filters, its kinds
// -------------------------------------------------------------------------------------------------

/** The six lead statuses, in the order of the status list. */
export const LEAD_STATUSES: readonly LeadStatus[] = ['new', 'shortlisted', 'contacted', 'booked', 'declined', 'hidden'];

/** The statuses the list leaves out at first. */
export const DEFAULT_HIDE: readonly LeadStatus[] = ['declined', 'hidden'];

export function isLeadStatus(x: unknown): x is LeadStatus {
  return typeof x === 'string' && (LEAD_STATUSES as readonly string[]).indexOf(x) >= 0;
}

export function leadStatusLabel(status: LeadStatus): string {
  return LEAD_STATUS_LABELS[status];
}

/** What the address of the Scout page holds (1.2). */
export interface ScoutFilterState {
  /** Lead statuses the server leaves out before it ranks, in the order of the status list. */
  hide: LeadStatus[];
  /** The kind of place being looked at; null shows every kind side by side. */
  kind: string | null;
  /** County codes (FIPS) to keep; empty keeps every county. */
  counties: string[];
  kitchen: 'any' | 'no';
  contact: 'any' | 'has';
}

function inStatusOrder(statuses: readonly LeadStatus[]): LeadStatus[] {
  return LEAD_STATUSES.filter((status) => statuses.indexOf(status) >= 0);
}

function byText(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

function isHostKind(type: string): boolean {
  return Object.prototype.hasOwnProperty.call(PLACE_TYPE_LABELS, type);
}

/**
 * Read the page's parameters. A value that is not valid counts as absent. `hide` absent is the
 * default (Declined and Hidden); `hide=` is the empty list, which hides nothing.
 */
export function readScoutParams(params: { get(name: string): string | null }): ScoutFilterState {
  let hide: LeadStatus[] = DEFAULT_HIDE.slice();
  const hideText = params.get('hide');
  if (hideText !== null) {
    const items = hideText.split(',').map((item) => item.trim());
    const named = items.filter((item) => item !== '');
    const valid = named.filter(isLeadStatus);
    // A list that names nothing we know is no list at all; an empty one is the owner's choice.
    if (named.length === 0 || valid.length > 0) hide = inStatusOrder(valid);
  }

  const typeText = params.get('type');
  const kind = typeText !== null && isHostKind(typeText) ? typeText : null;

  const counties: string[] = [];
  const countyText = params.get('county');
  if (countyText !== null) {
    for (const item of countyText.split(',')) {
      const fips = item.trim();
      if (/^[0-9]{5}$/.test(fips) && counties.indexOf(fips) < 0) counties.push(fips);
    }
    counties.sort(byText);
  }

  return {
    hide,
    kind,
    counties,
    kitchen: params.get('kitchen') === 'no' ? 'no' : 'any',
    contact: params.get('contact') === 'has' ? 'has' : 'any',
  };
}

function sameStatuses(a: readonly LeadStatus[], b: readonly LeadStatus[]): boolean {
  return inStatusOrder(a).join(',') === inStatusOrder(b).join(',');
}

/** The page's parameters in their one spelling. Parameters the page does not own are kept. */
export function writeScoutParams(current: URLSearchParams, state: ScoutFilterState): URLSearchParams {
  const next = new URLSearchParams(current);
  for (const key of ['hide', 'type', 'county', 'kitchen', 'contact']) next.delete(key);
  if (!sameStatuses(state.hide, DEFAULT_HIDE)) next.set('hide', inStatusOrder(state.hide).join(','));
  if (state.kind !== null) next.set('type', state.kind);
  if (state.counties.length > 0) next.set('county', state.counties.slice().sort(byText).join(','));
  if (state.kitchen === 'no') next.set('kitchen', 'no');
  if (state.contact === 'has') next.set('contact', 'has');
  return next;
}

/** The part of a query key for a list of hidden statuses: the same choice in any order is one entry. */
export function hideKey(hide: readonly LeadStatus[]): string {
  return hide.slice().sort(byText).join(',');
}

/** Tick or untick one status of the "Status" list: a ticked status is shown, an unticked one is hidden. */
export function toggleStatus(state: ScoutFilterState, status: LeadStatus, shown: boolean): ScoutFilterState {
  const hide = state.hide.filter((other) => other !== status);
  if (!shown) hide.push(status);
  return { ...state, hide: inStatusOrder(hide) };
}

/** Tick or untick one county. */
export function toggleCounty(state: ScoutFilterState, fips: string, on: boolean): ScoutFilterState {
  const counties = state.counties.filter((other) => other !== fips);
  if (on) counties.push(fips);
  counties.sort(byText);
  return { ...state, counties };
}

/** Every filter back to where it starts; the kind being looked at stays. */
export function clearedFilters(state: ScoutFilterState): ScoutFilterState {
  return { hide: DEFAULT_HIDE.slice(), kind: state.kind, counties: [], kitchen: 'any', contact: 'any' };
}

/** How many filters are set: the "{n}" of the button "Filters ({n})". The kind has its own control. */
export function activeFilterCount(state: ScoutFilterState): number {
  let n = 0;
  if (!sameStatuses(state.hide, DEFAULT_HIDE)) n += 1;
  if (state.counties.length > 0) n += 1;
  if (state.kitchen === 'no') n += 1;
  if (state.contact === 'has') n += 1;
  return n;
}

/** True when the place has a phone or a website on record (OpenStreetMap). */
export function hasRecordedContact(place: Pick<ScoutPlace, 'phone' | 'website'>): boolean {
  return textOrNull(place.phone) !== null || textOrNull(place.website) !== null;
}

/**
 * True when a candidate passes the filters that are applied in the browser. A lead whose status the
 * owner just moved into a hidden one leaves the list at once; the server leaves it out of the next
 * answer, where another place takes its room.
 */
export function matchesFilters(candidate: ScoutCandidate, state: ScoutFilterState, withKind = true): boolean {
  if (state.hide.indexOf(candidate.lead.status) >= 0) return false;
  if (withKind && state.kind !== null && candidate.kind !== state.kind) return false;
  if (state.counties.length > 0 && state.counties.indexOf(candidate.place.county_fips) < 0) return false;
  // The kitchen as the estimate counts it: known, or assumed for the kind of place.
  if (state.kitchen === 'no' && candidate.result.kitchen !== 'no') return false;
  if (state.contact === 'has' && !hasRecordedContact(candidate.place)) return false;
  return true;
}

export type HostFitWord = 'often' | 'sometimes' | 'rarely';

/** How commonly a kind of place hosts trucks, as a word: from 0.7 often, from 0.4 sometimes, below rarely. */
export function hostFitWord(hostFit: number): HostFitWord {
  if (hostFit >= 0.7) return 'often';
  if (hostFit >= 0.4) return 'sometimes';
  return 'rarely';
}

/** A rule of thumb about a kind of place. It says nothing about any one place. */
export function hostFitSentence(hostFit: number): string {
  return 'Our rule of thumb: this kind of place ' + hostFitWord(hostFit) + ' hosts trucks.';
}

/** The same rule of thumb under the name of a kind: "Rule of thumb: often hosts trucks." */
export function hostFitShort(hostFit: number): string {
  return 'Rule of thumb: ' + hostFitWord(hostFit) + ' hosts trucks.';
}

/** The model's weight of a place type, read from the seed copy; null for a type this build does not know. */
function seedHostFit(kind: string): number | null {
  if (!Object.prototype.hasOwnProperty.call(SEEDS.place_types.rows, kind)) return null;
  return SEEDS.place_types.rows[kind as PlaceType].host_fit;
}

/** One kind of place with the candidates of it that pass the filters. */
export interface KindGroup {
  kind: string;
  /** "Brewery or taproom". */
  label: string;
  /** Null for a kind this build has no rule of thumb for. */
  hostFit: number | null;
  screened: number;
  /** Places of the kind in the answer, before the filters of the browser. */
  listed: number;
  merged: number;
  /** In the server's order (`result.position` inside the kind). */
  candidates: ScoutCandidate[];
}

/**
 * The kinds of a list in the server's order, each with its candidates after the filters. The kind
 * the owner is looking at does not narrow this: the groups are what the kind switch is built from.
 */
export function kindGroups(list: ScoutList, state: ScoutFilterState): KindGroup[] {
  const groups: KindGroup[] = [];
  const byKind = new Map<string, KindGroup>();
  const add = (kind: string, row: ScoutKind | null): KindGroup => {
    const group: KindGroup = {
      kind,
      label: placeTypeLabel(kind),
      hostFit: null,
      screened: row === null ? 0 : row.screened,
      listed: row === null ? 0 : row.listed,
      merged: row === null ? 0 : row.merged,
      candidates: [],
    };
    groups.push(group);
    byKind.set(kind, group);
    return group;
  };
  for (const row of list.kinds) {
    if (!byKind.has(row.kind)) add(row.kind, row);
  }
  for (const candidate of list.candidates) {
    const group = byKind.get(candidate.kind) ?? add(candidate.kind, null);
    // The weight the server ranked with; the seed copy stands in for a kind without a listed place.
    if (group.hostFit === null && typeof candidate.result.host_fit === 'number') group.hostFit = candidate.result.host_fit;
    if (matchesFilters(candidate, state, false)) group.candidates.push(candidate);
  }
  for (const group of groups) {
    if (group.hostFit === null) group.hostFit = seedHostFit(group.kind);
  }
  return groups;
}

/** A county that has places in the list. */
export interface CountyOption {
  fips: string;
  /** "Fairfax County, VA"; the code itself for a county the region does not list. */
  label: string;
  /** Places of the list in this county. */
  count: number;
}

/** The counties present in a list, by name. */
export function countyOptions(list: ScoutList, counties: readonly RegionCounty[] | null | undefined): CountyOption[] {
  const counts = new Map<string, number>();
  for (const candidate of list.candidates) {
    const fips = candidate.place.county_fips;
    if (typeof fips !== 'string' || fips === '') continue;
    counts.set(fips, (counts.get(fips) ?? 0) + 1);
  }
  const out: CountyOption[] = [];
  counts.forEach((count, fips) => out.push({ fips, label: countyLabel(fips, counties) ?? fips, count }));
  out.sort((a, b) => byText(a.label, b.label) || byText(a.fips, b.fips));
  return out;
}

function plural(n: number, one: string, many: string): string {
  return fmtCount(n) + ' ' + (n === 1 ? one : many);
}

export const SCOUT = {
  title: 'Scout',
  refresh: 'Refresh',
  refreshing: 'Refreshing...',
  askLine: 'These are places you could ask. Truck Planner does not know whether any of them takes trucks.',
  truncated: 'Only the nearest places were looked at.',
  noCounties: 'No counties chosen, so every county within reach is listed. Choose the counties you hold a licence for in Settings.',
  noCountiesLink: 'Open Settings',
  kindLabel: 'Kind of place',
  allKinds: 'All kinds',
  filters: 'Filters',
  clearFilters: 'Clear filters',
  statusLabel: 'Status',
  statusHelp: 'An unticked status leaves the list and other places take its room. Untick New to see only the places you have marked.',
  countyLabel: 'County',
  kitchenLabel: 'Kitchen',
  kitchenAny: 'Any',
  kitchenNo: 'No kitchen of its own, known or assumed',
  contactLabel: 'Contact',
  contactAny: 'Any',
  contactHas: 'Has a phone or website on record',
  showMore: 'Show more of this kind',
  showingMore: 'Loading more of this kind...',
  moreFailed: 'Could not load more of this kind.',
  loadFailed: 'Could not load Scout.',
  noRegionTitle: 'No data for your area yet',
  noRegionBody: 'Scout needs a loaded region around your base.',
  noneTitle: 'No places match',
  noneBody: 'Widen the longest drive in Settings, or show more statuses.',
  noneFiltered: 'No places match these filters.',
  noneOfKind: 'No places of this kind match these filters.',
  capacityNote: 'Limited by how fast the truck can serve.',
  savedLine: 'Saved as a spot. Open it for the estimate that uses your size and your logged results.',
  openSpot: 'Open spot',
  showOnMap: 'Show on map',
  saveAsSpot: 'Save as spot',
  noContact: 'No phone or website on record.',
  osmTag: 'OpenStreetMap',
  whyWorking: 'Working out the breakdown...',
  whyFailed: 'Could not load the figures behind this number.',
  notesLabel: 'Notes',
  notesPlaceholder: 'Who you spoke to, what they said, when to call back',
  notesSaving: 'Saving...',
  notesSaved: 'Saved',
  notesFailed: 'The note was not saved.',
  footerGoogle: 'A lookup on Google is shown for this visit only. It is never saved.',
  footerSizes:
    'Sizes are typical figures for the kind of place, so these ranges are wide. Save a place as a spot and enter its real size to tighten them.',
} as const;

/** The server's sentence when the truck's region has no data (409). */
export const SCOUT_NEEDS_REGION = 'Scouting needs a loaded region';

/** The page's first sentence. */
export function scoutIntro(limitMinutes: number): string {
  return (
    'Places within ' +
    plural(limitMinutes, 'minute', 'minutes') +
    ' of your base that could host a truck. Each kind of place is ranked on its own, by what its best three hours of a typical week might be worth after the drive.'
  );
}

/** "144 places". */
export function placesLabel(count: number): string {
  return plural(count, 'place', 'places');
}

/** "144 places shown, 9,807 looked at". */
export function countLine(shown: number, screened: number): string {
  return placesLabel(shown) + ' shown, ' + fmtCount(screened) + ' looked at';
}

/** Under the name of a kind: "8 listed, 123 looked at." and what the listed places stand for. */
export function kindCountLine(group: Pick<KindGroup, 'listed' | 'screened' | 'merged'>, shown: number): string {
  let text = fmtCount(shown) + (shown === group.listed ? '' : ' of ' + fmtCount(group.listed)) + ' listed';
  if (group.screened > 0) text += ', ' + fmtCount(group.screened) + ' looked at';
  text += '.';
  if (group.merged > 0) {
    text += ' ' + plural(group.merged, 'more place is', 'more places are') + ' mapped at the same sites and not listed separately.';
  }
  return text;
}

/** "See all 8". */
export function seeAllLabel(count: number): string {
  return 'See all ' + fmtCount(count);
}

/** The footer line about contact details on record. */
export function contactShareLine(list: ScoutList): string {
  let withContact = 0;
  for (const candidate of list.candidates) if (hasRecordedContact(candidate.place)) withContact += 1;
  return (
    'Phone numbers and websites come from OpenStreetMap unless they are marked Google. ' +
    fmtCount(withContact) +
    ' of the ' +
    plural(list.candidates.length, 'place', 'places') +
    ' listed have either.'
  );
}

// -------------------------------------------------------------------------------------------------
// 3. A card
// -------------------------------------------------------------------------------------------------

/** "Best window in a typical week: Tuesday 11 AM to 2 PM", or that the week has none. */
export function bestWindowSentence(best: ScoutBestWindow | null): string {
  if (best === null) return 'No hour of the week reaches one order.';
  return 'Best window in a typical week: ' + fmtWeekday(best.dow, 'long') + ' ' + fmtWindow(best.open_minute, best.close_minute);
}

/** The best window as the title of its breakdown: "Open Road Distilling Co., Tuesday 11 AM to 2 PM in a typical week". */
export function whyTitle(name: string, best: ScoutBestWindow): string {
  return name + ', ' + fmtWeekday(best.dow, 'long') + ' ' + fmtWindow(best.open_minute, best.close_minute) + ' in a typical week';
}

/** True when either drive of the round trip is a straight line, not a Google drive time. */
export function straightLineTrip(legs: { out: DriveLegSource; back: DriveLegSource }): boolean {
  return legs.out === 'straight_line' || legs.back === 'straight_line';
}

/**
 * "Drive: 27 min round trip, 10.3 mi, about $23." The cost is the crew's wages for the drive, the
 * fuel and the tolls. Null for a place without a window: the model times no trip for it.
 */
export function driveSentence(candidate: Pick<ScoutCandidate, 'result' | 'leg_sources'>): string | null {
  const trip = candidate.result.round_trip;
  if (candidate.result.best_window === null) return null;
  return (
    'Drive: ' +
    fmtDuration(trip.minutes) +
    ' round trip, ' +
    fmtMiles(trip.miles) +
    ', about ' +
    fmtMoney(trip.cost) +
    (straightLineTrip(candidate.leg_sources) ? ' (straight-line estimate)' : '') +
    '.'
  );
}

/** "27 min round trip": the drive in a row of the overview. Null for a place without a window. */
export function tripShort(candidate: Pick<ScoutCandidate, 'result'>): string | null {
  if (candidate.result.best_window === null) return null;
  return fmtDuration(candidate.result.round_trip.minutes) + ' round trip';
}

/**
 * Whether the place sells its own food. `placeKitchen` is what the place's record says; when that
 * is unknown the estimate assumed `resolved`, the usual case for the kind of place.
 */
export function kitchenSentence(placeKitchen: KitchenState | null | undefined, resolved: 'yes' | 'no'): string {
  if (placeKitchen === 'no') return 'No kitchen of its own.';
  if (placeKitchen === 'yes') return 'Has its own kitchen.';
  return resolved === 'yes'
    ? 'Kitchen unknown. Assumed to have its own, as most places of this kind do.'
    : 'Kitchen unknown. Assumed to have none, as most places of this kind do.';
}

/**
 * The three cases of a host size: `typical` the estimate used the typical size of the kind of place;
 * `needed` the kind has a host but no typical size (an office park, an apartment community), so the
 * place was ranked on the people nearby and a saved spot needs the real size; `none` the kind has no
 * host of its own in the model (a farmers market).
 */
export type SizeCase = 'typical' | 'needed' | 'none';

export function sizeCase(result: Pick<ScoutResult, 'host_size' | 'host_segment'>): SizeCase {
  if (result.host_segment === null) return 'none';
  return result.host_size > 0 ? 'typical' : 'needed';
}

/** The unit of a host size by the group of its segment: "people in its busiest hour". */
export function sizeUnitPhrase(segment: SegmentKey): string {
  return SIZE_UNIT_PHRASE[segmentGroup(segment)];
}

/** The size line of a card. */
export function sizeSentence(result: Pick<ScoutResult, 'host_size' | 'host_segment'>): string {
  if (sizeCase(result) === 'typical' && result.host_segment !== null) {
    return 'Size assumed: ' + fmtCount(result.host_size) + ' ' + sizeUnitPhrase(result.host_segment) + ', a typical figure for this kind of place.';
  }
  return 'No typical size for this kind of place. Ranked on the people nearby.';
}

/** "This entry stands for 4 places mapped at the same site." Null for a place that stands for itself. */
export function mergedSentence(merged: number): string | null {
  if (!(merged > 0)) return null;
  return 'This entry stands for ' + fmtCount(merged + 1) + ' places mapped at the same site.';
}

/** "Reston · Fairfax County, VA": the town when the record has one, then the county. */
export function placeWhere(place: Pick<ScoutPlace, 'city' | 'county_fips'>, counties: readonly RegionCounty[] | null | undefined): string {
  const parts: string[] = [];
  const city = textOrNull(place.city);
  if (city !== null) parts.push(city);
  const county = countyLabel(place.county_fips, counties);
  if (county !== null) parts.push(county);
  return parts.join(' · ');
}

/** "1871 Fountain Drive, Reston, VA 20190", from the place's own record. Null without a street line. */
export function placeAddress(place: Pick<ScoutPlace, 'addr_line' | 'city' | 'state_code' | 'postcode'>): string | null {
  const street = textOrNull(place.addr_line);
  if (street === null) return null;
  const parts = [street];
  const city = textOrNull(place.city);
  if (city !== null) parts.push(city);
  const tail = [textOrNull(place.state_code), textOrNull(place.postcode)].filter((x): x is string => x !== null).join(' ');
  if (tail !== '') parts.push(tail);
  return parts.join(', ');
}

/** "Hours on record: May-Nov We 08:00-12:00", as the map data writes them. Null without any. */
export function recordedHours(place: Pick<ScoutPlace, 'opening_hours_raw'>): string | null {
  const raw = textOrNull(place.opening_hours_raw);
  if (raw === null || raw.trim() === '') return null;
  return 'Hours on record: ' + raw.trim();
}

/** What a card shows, decided by whether the place is already a saved spot. */
export interface CardView {
  /** The place is a saved spot: its card links to the spot instead of estimating it again. */
  saved: boolean;
  /** The two estimates of the best window. */
  showEstimates: boolean;
  /** The size line. */
  showSize: boolean;
  /** "Why this number": needs a best window, and is the spot's own once the place is saved. */
  canWhy: boolean;
  /** "Save as spot". */
  canSave: boolean;
}

export function cardView(candidate: Pick<ScoutCandidate, 'lead' | 'result'>): CardView {
  const saved = candidate.lead.spot_id !== null;
  return {
    saved,
    showEstimates: !saved,
    showSize: !saved,
    canWhy: !saved && candidate.result.best_window !== null,
    canSave: !saved,
  };
}

/** A phone number or a website as a link. `href` is null when the text cannot be made into one. */
export interface ContactLink {
  href: string | null;
  text: string;
}

function digitsOf(text: string): string {
  let out = '';
  for (let i = 0; i < text.length; i++) {
    const c = text.charCodeAt(i);
    if (c >= 48 && c <= 57) out += text[i];
  }
  return out;
}

/** A phone number as a `tel:` link: "+17034812070" reads "(703) 481-2070". */
export function phoneLink(phone: string | null | undefined): ContactLink | null {
  const text = textOrNull(phone === undefined ? null : phone);
  if (text === null) return null;
  const trimmed = text.trim();
  if (trimmed === '') return null;
  const digits = digitsOf(trimmed);
  let dial: string | null = null;
  if (trimmed[0] === '+' && digits.length >= 8 && digits.length <= 15) dial = '+' + digits;
  else if (digits.length === 10) dial = '+1' + digits;
  else if (digits.length === 11 && digits[0] === '1') dial = '+' + digits;
  return { href: dial === null ? null : 'tel:' + dial, text: dial === null ? trimmed : fmtPhone(dial) };
}

const WEB_SCHEME = /^https?:\/\//i;
const BARE_HOST = /^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:[/?#]\S*)?$/i;

/**
 * A website as a link that shows its host name. Only a web address becomes a link: anything else
 * in the record is shown as text. The host always comes from the place's own record, never from here.
 */
export function websiteLink(website: string | null | undefined): ContactLink | null {
  const text = textOrNull(website === undefined ? null : website);
  if (text === null) return null;
  const trimmed = text.trim();
  if (trimmed === '') return null;
  if (/\s/.test(trimmed) || trimmed.length > 2048) return { href: null, text: trimmed };
  let href: string | null = null;
  let rest = trimmed;
  const scheme = WEB_SCHEME.exec(trimmed);
  if (scheme !== null) {
    href = trimmed;
    rest = trimmed.slice(scheme[0].length);
  } else if (BARE_HOST.test(trimmed)) {
    href = 'https:' + '//' + trimmed;
  } else {
    return { href: null, text: trimmed };
  }
  let end = rest.length;
  for (const mark of ['/', '?', '#']) {
    const at = rest.indexOf(mark);
    if (at >= 0 && at < end) end = at;
  }
  let host = rest.slice(0, end);
  if (host.slice(0, 4).toLowerCase() === 'www.') host = host.slice(4);
  return { href, text: host === '' ? trimmed : host };
}

/** What the place's own record says about how to reach it (OpenStreetMap). */
export function recordedContact(place: Pick<ScoutPlace, 'phone' | 'website'>): { phone: ContactLink | null; website: ContactLink | null } {
  return { phone: phoneLink(place.phone), website: websiteLink(place.website) };
}

/** The name a dot of the map shows: "Open Road Distilling Co. · Brewery or taproom". */
export function dotLabel(candidate: Pick<ScoutCandidate, 'kind' | 'place'>): string {
  return candidate.place.name + ' · ' + placeTypeLabel(candidate.kind);
}

/** What a dot of the map is called: "Brewery or taproom 1: Open Road Distilling Co. Open the estimate at this point." */
export function dotAriaLabel(candidate: Pick<ScoutCandidate, 'kind' | 'place' | 'result'>): string {
  return placeTypeLabel(candidate.kind) + ' ' + fmtCount(candidate.result.position) + ': ' + candidate.place.name + '. Open the estimate at this point.';
}

/** Every place of several answers once, by its key: the dots of the map. */
export function uniquePlaces(lists: readonly ScoutList[]): ScoutCandidate[] {
  const seen = new Set<string>();
  const out: ScoutCandidate[] = [];
  for (const list of lists) {
    for (const candidate of list.candidates) {
      const key = candidate.place.place_key;
      if (seen.has(key)) continue;
      seen.add(key);
      out.push(candidate);
    }
  }
  return out;
}

/** "{name} left the list: Hidden." */
export function leftListMessage(name: string, status: LeadStatus): string {
  return name + ' left the list: ' + leadStatusLabel(status) + '.';
}

// -------------------------------------------------------------------------------------------------
// 4. A contact lookup
// -------------------------------------------------------------------------------------------------

export const LOOKUP = {
  button: 'Look up phone and website',
  again: 'Look up again',
  busy: 'Asking Google...',
  hint: 'Asks Google about this place now. The answer is shown here for this visit and is not saved.',
  caption: ATTRIBUTION.googleContact,
  notSaved: 'Not saved. Copy what you need into the notes.',
  noDetails: 'Google has no phone or website for this place.',
  notFound: 'Google found no confident match for this place.',
  searchAgain: 'Not the right place? Search again',
  failed: 'The lookup did not work. Try again later.',
  mapsStillWorks: 'The link to Google Maps still works without it.',
  openOnGoogle: 'Open this match in Google Maps',
} as const;

/** The moment a lookup was answered, in the truck's time zone. */
export interface LookupMoment {
  date: string;
  minute: number;
}

/** Google's answer as the page keeps it for the visit: in memory only. */
export interface SessionContact {
  contact: Contact;
  at: LookupMoment;
}

/** "Looked up today at 2:14 PM." */
export function lookedUpText(at: LookupMoment, today: string): string {
  return 'Looked up ' + (at.date === today ? 'today' : fmtDay(at.date, 'short')) + ' at ' + fmtClock(at.minute) + '.';
}

/** "Google matched: Example Brewing Co, 1 Example Rd, Sterling, VA 20166, USA" */
export function matchedText(contact: Pick<Contact, 'name' | 'address'>): string {
  const parts = [contact.name, contact.address].filter((x): x is string => x !== null && x !== '');
  return parts.length === 0 ? 'Google matched a place without a name or an address.' : 'Google matched: ' + parts.join(', ');
}

/** What a lookup shows. */
export type LookupView =
  | {
      kind: 'found';
      /** "Google matched: ..." so the owner can see it is the right place. */
      matched: string;
      phone: ContactLink | null;
      website: ContactLink | null;
      /** Google's own link to the place. */
      mapsHref: string | null;
      when: string;
    }
  | { kind: 'not_found'; when: string };

export function lookupView(session: SessionContact, today: string): LookupView {
  const when = lookedUpText(session.at, today);
  const contact = session.contact;
  if (!contact.found) return { kind: 'not_found', when };
  const maps = websiteLink(contact.maps_uri);
  return {
    kind: 'found',
    matched: matchedText(contact),
    phone: phoneLink(contact.phone),
    website: websiteLink(contact.website),
    mapsHref: maps === null ? null : maps.href,
    when,
  };
}

/** A sentence with its full stop: server sentences come without one. */
export function asSentence(text: string): string {
  const trimmed = text.trim();
  if (trimmed === '') return '';
  const last = trimmed[trimmed.length - 1];
  return last === '.' || last === '!' || last === '?' ? trimmed : trimmed + '.';
}

/**
 * What the card says when a lookup failed. `sentence` is what the server said, already passed
 * through the client's own replacements; null when there was no answer at all. A server that has
 * no lookup to offer (503) says so in its own words, and the card adds what still works.
 */
export function lookupFailure(status: number | null, sentence: string | null): string {
  if (sentence === null || sentence.trim() === '') return LOOKUP.failed;
  const said = asSentence(sentence);
  return status === 503 ? said + ' ' + LOOKUP.mapsStillWorks : said;
}

/** The day part of a stored date and time, when it reads as one. */
function storedDay(stamp: string): string | null {
  const day = stamp.slice(0, 10);
  return /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(day) ? day : null;
}

/**
 * What an earlier lookup left on the lead, for a card that holds no answer of this visit: only that
 * Google matched the place, or did not, and when. The details themselves were never kept.
 */
export function priorMatchSentence(match: LeadMatch | null): string | null {
  if (match === null) return null;
  const day = storedDay(match.matched_at);
  const on = day === null ? '' : ' on ' + fmtDay(day, 'medium');
  return match.lookup_state === 'found'
    ? 'Google matched this place' + on + '. Its phone and website were not kept: look it up again to see them.'
    : 'Google found no confident match' + on + '.';
}

// -------------------------------------------------------------------------------------------------
// 5. "Save as spot"
// -------------------------------------------------------------------------------------------------

/** What the dialog edits. */
export interface SaveLeadDraft {
  name: string;
  /** The host size the owner typed; null while the field is empty. */
  size: number | null;
  onlyFood: boolean;
  visibility: Visibility;
}

/** Which fields the dialog shows for a place, by the three size cases. */
export interface SaveLeadFields {
  /** `optional`: a typical size stands in; `required`: the kind has none; `none`: saved without a host. */
  size: 'optional' | 'required' | 'none';
  /** The label of the size field, by the group of the host's segment. */
  sizeLabel: string | null;
  /** The typical size, shown while the field is empty. */
  typical: number | null;
  /** The switch "Your truck is the only food here". */
  onlyFood: boolean;
  /** "Saved without a host. You can describe one on the spot page." */
  note: string | null;
}

export const SAVE_LEAD = {
  title: 'Save as a spot',
  nameLabel: 'Name',
  onlyFoodLabel: 'Your truck is the only food here',
  onlyFoodHelp: 'Turn this off if the host sells its own food.',
  visibilityLabel: 'How easy is the truck to see?',
  noHost: 'Saved without a host. You can describe one on the spot page.',
  save: 'Save spot',
  saving: 'Saving...',
  cancel: 'Cancel',
  check: 'Check the marked fields first. Nothing was saved.',
  nameRequired: 'Give the spot a name.',
  sizeRequired: 'Enter the size: this kind of place has no typical figure.',
} as const;

export function saveLeadFields(result: Pick<ScoutResult, 'host_size' | 'host_segment'>): SaveLeadFields {
  const which = sizeCase(result);
  if (which === 'none' || result.host_segment === null) {
    return { size: 'none', sizeLabel: null, typical: null, onlyFood: false, note: SAVE_LEAD.noHost };
  }
  return {
    size: which === 'typical' ? 'optional' : 'required',
    sizeLabel: HOST_SIZE_LABEL[segmentGroup(result.host_segment)],
    typical: which === 'typical' ? result.host_size : null,
    onlyFood: true,
    note: null,
  };
}

/** "Typical for this kind of place: 40. Enter the real figure if you know it." */
export function typicalSizeHelp(typical: number): string {
  return 'Typical for this kind of place: ' + fmtCount(typical) + '. Enter the real figure if you know it.';
}

/** What the dialog starts from: the place's name, no size of the owner's yet, the kitchen as the estimate counted it. */
export function saveLeadDraft(candidate: Pick<ScoutCandidate, 'place' | 'result'>): SaveLeadDraft {
  return {
    name: candidate.place.name.slice(0, SPOT_LIMITS.nameMax),
    size: null,
    onlyFood: candidate.result.kitchen === 'no',
    visibility: 'normal',
  };
}

export interface SaveLeadErrors {
  name?: string;
  size?: string;
}

/** What holds the save back. Nothing is clamped: a value outside its range stays in its field. */
export function saveLeadErrors(draft: SaveLeadDraft, fields: SaveLeadFields): SaveLeadErrors {
  const errors: SaveLeadErrors = {};
  const name = draft.name.trim();
  if (name === '') errors.name = SAVE_LEAD.nameRequired;
  else if (name.length > SPOT_LIMITS.nameMax) errors.name = 'Use at most ' + fmtCount(SPOT_LIMITS.nameMax) + ' characters.';
  if (fields.size !== 'none') {
    if (draft.size === null) {
      if (fields.size === 'required') errors.size = SAVE_LEAD.sizeRequired;
    } else if (!(draft.size >= SPOT_LIMITS.sizeMin && draft.size <= SPOT_LIMITS.sizeMax)) {
      errors.size = numberRangeMessage(SPOT_LIMITS.sizeMin, SPOT_LIMITS.sizeMax);
    }
  }
  return errors;
}

/**
 * The body of route 41. The size travels only when the owner typed one (the server then counts it
 * as the owner's; without it the typical size stands in), and neither the size nor the only-food
 * switch travels for a kind of place that has no host. No field of a lookup is ever part of it.
 */
export function leadSpotBody(draft: SaveLeadDraft, fields: SaveLeadFields): LeadSpotBody {
  const body: LeadSpotBody = { name: draft.name.trim(), visibility: draft.visibility };
  if (fields.size !== 'none' && draft.size !== null) body.host_size = draft.size;
  if (fields.onlyFood) body.only_food = draft.onlyFood;
  return body;
}

// -------------------------------------------------------------------------------------------------
// 6a. Suggested days and weeks (rule 6 of 4.5, the best-week panel of 4.6)
// -------------------------------------------------------------------------------------------------

export const SUGGEST = {
  open: 'Suggest a day',
  openText: 'Up to three days built from your saved spots, your costs and the forecast for this date.',
  title: 'Suggest a day',
  caption: "Worked out from your saved spots, costs and this date's forecast. A suggestion, not a booking.",
  use: 'Use this plan',
  takeHome: 'TAKE-HOME',
  orders: 'ORDERS',
  fallback: 'Some drive times behind these suggestions are straight-line estimates.',
  failed: 'Could not work out suggestions.',
  noSpotsTitle: 'Save a spot first',
  noSpotsBody: 'Suggestions are built from your saved spots.',
  noSpotsAction: 'Open the map',
  noneTitle: 'Nothing to suggest for this date',
  noneBody: 'None of your saved spots has a window worth a stop on this date.',
  whyWorking: 'Working out the breakdown...',
  whyFailed: 'The breakdown of this day could not be worked out.',
  deletedSpot: 'A spot that is no longer in your list',
} as const;

/** "Suggestion 1". */
export function suggestionTitle(position: number): string {
  return 'Suggestion ' + fmtCount(position);
}

/** "11 h 34 min, prep to done". */
export function dayLengthLine(dayMinutes: number): string {
  return fmtDuration(dayMinutes) + ', prep to done';
}

/** One stop of a suggested day as it reads: the spot's name and its window. */
export interface SuggestedStopLine {
  spotId: string;
  name: string;
  /** "11 AM to 2 PM". */
  window: string;
}

type SpotNames = ReadonlyMap<string, { name: string }>;

export function suggestionStops(suggestion: Pick<Suggestion, 'stops'>, spotsById: SpotNames): SuggestedStopLine[] {
  return suggestion.stops.map((stop) => {
    const spot = spotsById.get(stop.spot_id);
    return {
      spotId: stop.spot_id,
      name: spot === undefined || spot.name.trim() === '' ? SUGGEST.deletedSpot : spot.name,
      window: fmtWindow(stop.open_minute, stop.close_minute),
    };
  });
}

/**
 * The stops of a suggestion as the browser's own evaluation takes them, for "Why this number".
 * Each stop is called by its spot, as the model calls it (02_MODEL 4.15): a spot is in a
 * suggested day at most once.
 */
export function suggestionAsStops(suggestion: Pick<Suggestion, 'stops'>): StopLike[] {
  return suggestion.stops.map((stop) => ({
    id: stop.spot_id,
    kind: 'spot' as const,
    spot_id: stop.spot_id,
    point: null,
    open_minute: stop.open_minute,
    close_minute: stop.close_minute,
    gap_before_unpaid: false,
    setup_minutes: null,
    teardown_minutes: null,
    fee_flat: 0,
    fee_pct: 0,
    fee_min: 0,
    event: null,
    catering: null,
  }));
}

export const BEST_WEEK = {
  title: 'Best week',
  text: 'A week from your saved spots: where to go each day, and which days to take off.',
  ask: 'Suggest a week',
  again: 'Work it out again',
  asking: 'Working out a week...',
  total: 'SUGGESTED WEEK',
  totalNote: "Each day's low and high are added up, so the week's range is wide on purpose.",
  dayOff: 'Day off',
  planned: 'Already planned. Your plan stays as it is.',
  plannedOff: 'A day off in the suggestion. Your plan for this day stays as it is.',
  past: 'In the past. No draft is added.',
  use: 'Use for the empty days',
  nothingToAdd: 'Every suggested day is already planned or in the past.',
  fallback: 'Some drive times behind this week are straight-line estimates.',
  failed: 'Could not work out a week.',
  noneTitle: 'Nothing to suggest for this week',
  noneBody: 'None of your saved spots has a day worth going out for in this week.',
  noSpots: 'Suggestions are built from your saved spots. Save a spot first.',
  confirmBody: 'Days you have planned stay as they are. A draft becomes a planned day when you open it in the planner and save it.',
  confirm: 'Add drafts',
  cancel: 'Cancel',
  adding: 'Adding drafts...',
  exists: 'already has a plan. It was left as it is.',
} as const;

/** "At most 5 days out and 2 visits to a spot. Days off are part of the suggestion." */
export function bestWeekCaption(maxDays: number, maxVisits: number): string {
  return (
    'At most ' + plural(maxDays, 'day', 'days') + ' out and ' + plural(maxVisits, 'visit', 'visits') + ' to a spot. Days off are part of the suggestion.'
  );
}

/** The two limits of a suggested week, as the model reads them when the request names none. */
export function weekLimits(A: Assumptions): { maxDays: number; maxVisits: number } {
  return {
    maxDays: seed<number>(A, 'suggest.max_days_per_week'),
    maxVisits: seed<number>(A, 'suggest.max_visits_per_spot_per_week'),
  };
}

/**
 * What a day of a suggested week is: `suggested` a draft can be added for it; `off` the suggestion
 * rests that day; `planned` the date has a plan, which is never replaced; `past` the date is over.
 */
export type WeekDayState = 'suggested' | 'off' | 'planned' | 'past';

export interface WeekDayRow {
  date: string;
  /** "Mon Oct 5". */
  label: string;
  state: WeekDayState;
  /** The suggested stops of the day; empty when the suggestion rests that day. */
  stops: SuggestedStopLine[];
  /** The suggested day's take-home; null when the suggestion rests that day. */
  takeHome: Estimate | null;
  /** What the row says about itself: "Day off", that the day is planned, or that it is over. */
  note: string | null;
}

/** "Mon Oct 5": a date of the week as the week page names it. */
export function weekdayDate(date: string): string {
  return fmtWeekday(dayOfWeek(date), 'short') + ' ' + fmtDay(date, 'short');
}

function weekDayState(date: string, hasSuggestion: boolean, planned: readonly string[], today: string): WeekDayState {
  if (planned.indexOf(date) >= 0) return 'planned';
  if (date < today) return 'past';
  return hasSuggestion ? 'suggested' : 'off';
}

/** The seven days of a suggested week as the panel lists them. */
export function weekDayRows(
  week: Pick<WeekSuggestion, 'days'>,
  plannedDates: readonly string[],
  today: string,
  spotsById: SpotNames,
): WeekDayRow[] {
  return week.days.map((day) => {
    const suggestion = day.suggestion;
    const state = weekDayState(day.date, suggestion !== null, plannedDates, today);
    let note: string | null = null;
    if (state === 'planned') note = suggestion === null ? BEST_WEEK.plannedOff : BEST_WEEK.planned;
    else if (suggestion === null) note = BEST_WEEK.dayOff;
    else if (state === 'past') note = BEST_WEEK.past;
    return {
      date: day.date,
      label: weekdayDate(day.date),
      state,
      stops: suggestion === null ? [] : suggestionStops(suggestion, spotsById),
      takeHome: suggestion === null ? null : suggestion.take_home,
      note,
    };
  });
}

/**
 * The plans "Use for the empty days" creates: one draft for each date that has a suggestion, has no
 * plan and is not over. A planned day is never part of it, so a suggestion never replaces a plan.
 * Every body goes to route 23 (create), never to an update.
 */
export function weekDraftBodies(week: Pick<WeekSuggestion, 'days'>, plannedDates: readonly string[], today: string): PlanBody[] {
  const bodies: PlanBody[] = [];
  for (const day of week.days) {
    const suggestion = day.suggestion;
    if (suggestion === null || suggestion.stops.length === 0) continue;
    if (weekDayState(day.date, true, plannedDates, today) !== 'suggested') continue;
    bodies.push({
      date: day.date,
      status: 'draft',
      stops: suggestion.stops.map((stop) => ({
        kind: 'spot' as const,
        spot_id: stop.spot_id,
        open_minute: stop.open_minute,
        close_minute: stop.close_minute,
      })),
    });
  }
  return bodies;
}

/** "Add 3 draft days?" */
export function draftsQuestion(count: number): string {
  return 'Add ' + plural(count, 'draft day', 'draft days') + '?';
}

/** "Drafts are added for Tue Oct 6, Wed Oct 7 and Fri Oct 9." */
export function draftsDatesLine(dates: readonly string[]): string {
  const names = dates.map(weekdayDate);
  return (names.length === 1 ? 'A draft is added for ' : 'Drafts are added for ') + joinWords(names) + '.';
}

/** "3 drafts added." */
export function draftsAddedLine(count: number): string {
  return plural(count, 'draft', 'drafts') + ' added.';
}

/** "Wed Oct 7 already has a plan. It was left as it is." */
export function draftExistsLine(date: string): string {
  return weekdayDate(date) + ' ' + BEST_WEEK.exists;
}

/** "Could not add Wed Oct 7: {the server's sentence}" */
export function draftFailedLine(date: string, sentence: string | null): string {
  return 'Could not add ' + weekdayDate(date) + (sentence === null || sentence.trim() === '' ? '.' : ': ' + asSentence(sentence));
}

/** The server's sentence when the date already has a plan (409). */
export const PLAN_EXISTS_ERROR = 'A plan already exists for this date';

function joinWords(words: readonly string[]): string {
  if (words.length <= 1) return words.join('');
  return words.slice(0, words.length - 1).join(', ') + ' and ' + words[words.length - 1];
}

// -------------------------------------------------------------------------------------------------
// 6b. The terms of an event or a catering stop (rule 5 of 4.5)
// -------------------------------------------------------------------------------------------------

/** What the server accepts for an event or a catering stop (04_BACKEND 4.11). Limits of the API, not seeds. */
export const STOP_TERM_LIMITS = {
  attendanceMin: 1,
  attendanceMax: 2000000,
  vendorsMin: 1,
  vendorsMax: 500,
  feeMin: 0,
  feeMax: 100000,
  feePctMin: 0,
  feePctMax: 1,
  headcountMin: 1,
  headcountMax: 100000,
  priceMin: 0,
  priceMax: 1000,
  guaranteeMin: 0,
  guaranteeMax: 1000000,
  foodCostMin: 0,
  foodCostMax: 1000000,
  addressMax: 255,
} as const;

export const EVENT_TYPES: readonly EventType[] = ['general', 'food_focused', 'evening_show', 'incidental'];

export const STOP_TERMS = {
  placeLabel: 'Address or place',
  placeSearch: 'Search an address',
  placeChange: 'Change',
  placeKeep: 'Keep this place',
  coordsToggle: 'Enter coordinates instead',
  coordsLabel: 'Latitude, longitude',
  coordsPlaceholder: '38.9696, -77.3861',
  coordsError: 'Enter latitude and longitude, like 38.9696, -77.3861.',
  coordsUse: 'Use these coordinates',
  addressUnavailable: 'Address search is unavailable. Enter coordinates instead.',
  attendance: 'Expected attendance during your stop',
  attendanceHelp: "The organiser's figure for the hours you are there, not for the whole event.",
  vendors: 'Food vendors, counting you',
  eventType: 'Kind of event',
  feeFlat: 'Flat fee',
  feePct: 'Share of sales',
  feeMin: 'Minimum fee',
  feeHelp: 'You pay the flat fee plus the share of sales, or the minimum if that is more.',
  typicalFee: 'Typical terms, change to yours',
  eventNote: 'An event is estimated from its attendance, so its figures always read "Very rough".',
  headcount: 'Headcount',
  pricePerHead: 'Price per head',
  guarantee: 'Guaranteed minimum',
  priceHelp: 'Enter a price per head, a guaranteed minimum, or both. You are paid whichever comes to more.',
  priceMissing: 'Enter a price per head or a guaranteed minimum.',
  foodCost: 'Food cost for this job (optional)',
  cateringNote: 'A catering job is contracted, so its figures read "Fixed" and carry no range.',
} as const;

/** "Left empty: 30% of the price, your usual food cost." */
export function foodCostHelp(foodCostPct: number): string {
  return 'Left empty: ' + fmtPercent(foodCostPct) + ' of the price, your usual food cost.';
}

/** The three figures of an event while the owner types them; a number is null while its field is empty. */
export interface EventDraft {
  attendance: number | null;
  vendors: number | null;
  eventType: EventType;
}

export function eventDraftOf(event: EventTerms | null): EventDraft {
  if (event === null) return { attendance: null, vendors: null, eventType: 'general' };
  return { attendance: event.attendance, vendors: event.vendors, eventType: event.event_type };
}

/** The event terms of a draft, or null while the attendance or the number of vendors is missing. */
export function eventTermsOf(draft: EventDraft): EventTerms | null {
  if (draft.attendance === null || draft.vendors === null) return null;
  if (!(draft.attendance >= STOP_TERM_LIMITS.attendanceMin && draft.attendance <= STOP_TERM_LIMITS.attendanceMax)) return null;
  if (!(draft.vendors >= STOP_TERM_LIMITS.vendorsMin && draft.vendors <= STOP_TERM_LIMITS.vendorsMax)) return null;
  if (Math.floor(draft.vendors) !== draft.vendors) return null;
  return { attendance: draft.attendance, vendors: draft.vendors, event_type: draft.eventType };
}

export function sameEventTerms(a: EventTerms | null, b: EventTerms | null): boolean {
  if (a === null || b === null) return a === b;
  return a.attendance === b.attendance && a.vendors === b.vendors && a.event_type === b.event_type;
}

/** The four figures of a catering job while the owner types them. */
export interface CateringDraft {
  headcount: number | null;
  pricePerHead: number | null;
  guarantee: number | null;
  foodCost: number | null;
}

export function cateringDraftOf(terms: CateringTerms | null): CateringDraft {
  if (terms === null) return { headcount: null, pricePerHead: null, guarantee: null, foodCost: null };
  return { headcount: terms.headcount, pricePerHead: terms.price_per_head, guarantee: terms.guarantee, foodCost: terms.food_cost };
}

/**
 * The catering terms of a draft, or null while the headcount is missing or neither a price per head
 * nor a guaranteed minimum is given: the server requires one of the two, and a job without either
 * would read as a contract worth nothing.
 */
export function cateringTermsOf(draft: CateringDraft): CateringTerms | null {
  if (draft.headcount === null) return null;
  if (!(draft.headcount >= STOP_TERM_LIMITS.headcountMin && draft.headcount <= STOP_TERM_LIMITS.headcountMax)) return null;
  if (draft.pricePerHead === null && draft.guarantee === null) return null;
  return { headcount: draft.headcount, price_per_head: draft.pricePerHead, guarantee: draft.guarantee, food_cost: draft.foodCost };
}

export function sameCateringTerms(a: CateringTerms | null, b: CateringTerms | null): boolean {
  if (a === null || b === null) return a === b;
  return a.headcount === b.headcount && a.price_per_head === b.price_per_head && a.guarantee === b.guarantee && a.food_cost === b.food_cost;
}

/** What an event stop still lacks before its figures can be worked out, in the order of the form. */
export function eventMissing(stop: { point: unknown }, draft: EventDraft): string[] {
  const missing: string[] = [];
  if (stop.point === null || stop.point === undefined) missing.push('the place');
  if (draft.attendance === null) missing.push('the attendance');
  if (draft.vendors === null) missing.push('the number of food vendors');
  return missing;
}

/** What a catering stop still lacks, in the order of the form. */
export function cateringMissing(stop: { point: unknown }, draft: CateringDraft): string[] {
  const missing: string[] = [];
  if (stop.point === null || stop.point === undefined) missing.push('the place');
  if (draft.headcount === null) missing.push('the headcount');
  if (draft.pricePerHead === null && draft.guarantee === null) missing.push('a price per head or a guaranteed minimum');
  return missing;
}

/** "Still needed: the place, the attendance and the number of food vendors." Null when nothing is. */
export function missingSentence(parts: readonly string[]): string | null {
  if (parts.length === 0) return null;
  return 'Still needed: ' + joinWords(parts) + '.';
}

/** True while an event's fee is still the typical one the stop started with (the seeds' prefill). */
export function isTypicalEventFee(A: Assumptions, stop: { fee_flat: number; fee_pct: number; fee_min: number }): boolean {
  return (
    stop.fee_flat === 0 &&
    stop.fee_pct === seed<number>(A, 'events.suggested_fee_pct') &&
    stop.fee_min === seed<number>(A, 'events.suggested_fee_min')
  );
}
