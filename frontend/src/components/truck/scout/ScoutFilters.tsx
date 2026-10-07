import type { ReactNode } from 'react';
import { fmtCount } from '../../../utils/truck/format';
import {
  LEAD_STATUSES,
  SCOUT,
  activeFilterCount,
  clearedFilters,
  leadStatusLabel,
  toggleCounty,
  toggleStatus,
  type CountyOption,
  type KindGroup,
  type ScoutFilterState,
} from '../../../utils/truck/scoutView';

export interface ScoutFiltersProps {
  /** Makes the ids of the controls unique: the filters are on the page twice (the rail and the sheet). */
  idPrefix: string;
  state: ScoutFilterState;
  onChange: (next: ScoutFilterState) => void;
  /** The counties that have places in the list. */
  counties: readonly CountyOption[];
}

const OPTION = 'flex min-h-[44px] md:min-h-[30px] cursor-pointer items-center gap-2 text-sm font-semibold';

function Group({ legend, children }: { legend: string; children: ReactNode }) {
  return (
    <fieldset>
      <legend className="label">{legend}</legend>
      {children}
    </fieldset>
  );
}

/**
 * The filters of the Scout page (docs/truck-planner/05_FRONTEND.md 4.8). "Status" is the one the
 * server applies: an unticked status leaves the list before it is ranked, so other places take its
 * room. County, kitchen and contact narrow the list that came back.
 *
 * There is no filter, and no field, for whether a place takes trucks: the list does not know.
 */
export default function ScoutFilters({ idPrefix, state, onChange, counties }: ScoutFiltersProps) {
  const active = activeFilterCount(state);
  return (
    <div className="space-y-4">
      <Group legend={SCOUT.statusLabel}>
        <div>
          {LEAD_STATUSES.map((status) => {
            const id = idPrefix + 'status-' + status;
            const shown = state.hide.indexOf(status) < 0;
            return (
              <label key={status} htmlFor={id} className={OPTION} style={{ color: 'var(--ink)' }}>
                <input
                  id={id}
                  type="checkbox"
                  className="h-4 w-4 flex-none"
                  style={{ accentColor: 'var(--brand)' }}
                  checked={shown}
                  onChange={(e) => onChange(toggleStatus(state, status, e.target.checked))}
                />
                {leadStatusLabel(status)}
              </label>
            );
          })}
        </div>
        <p className="mt-1 text-xs font-semibold" style={{ color: 'var(--body)' }}>
          {SCOUT.statusHelp}
        </p>
      </Group>

      {counties.length > 1 ? (
        <Group legend={SCOUT.countyLabel}>
          {counties.map((county) => {
            const id = idPrefix + 'county-' + county.fips;
            return (
              <label key={county.fips} htmlFor={id} className={OPTION} style={{ color: 'var(--ink)' }}>
                <input
                  id={id}
                  type="checkbox"
                  className="h-4 w-4 flex-none"
                  style={{ accentColor: 'var(--brand)' }}
                  checked={state.counties.indexOf(county.fips) >= 0}
                  onChange={(e) => onChange(toggleCounty(state, county.fips, e.target.checked))}
                />
                <span className="min-w-0 flex-1">{county.label}</span>
                <span className="flex-none text-xs font-bold tabular-nums" style={{ color: 'var(--body)' }}>
                  {fmtCount(county.count)}
                </span>
              </label>
            );
          })}
        </Group>
      ) : null}

      <Group legend={SCOUT.kitchenLabel}>
        {(['any', 'no'] as const).map((value) => {
          const id = idPrefix + 'kitchen-' + value;
          return (
            <label key={value} htmlFor={id} className={OPTION} style={{ color: 'var(--ink)' }}>
              <input
                id={id}
                type="radio"
                name={idPrefix + 'kitchen'}
                className="h-4 w-4 flex-none"
                style={{ accentColor: 'var(--brand)' }}
                checked={state.kitchen === value}
                onChange={() => onChange({ ...state, kitchen: value })}
              />
              {value === 'any' ? SCOUT.kitchenAny : SCOUT.kitchenNo}
            </label>
          );
        })}
      </Group>

      <Group legend={SCOUT.contactLabel}>
        {(['any', 'has'] as const).map((value) => {
          const id = idPrefix + 'contact-' + value;
          return (
            <label key={value} htmlFor={id} className={OPTION} style={{ color: 'var(--ink)' }}>
              <input
                id={id}
                type="radio"
                name={idPrefix + 'contact'}
                className="h-4 w-4 flex-none"
                style={{ accentColor: 'var(--brand)' }}
                checked={state.contact === value}
                onChange={() => onChange({ ...state, contact: value })}
              />
              {value === 'any' ? SCOUT.contactAny : SCOUT.contactHas}
            </label>
          );
        })}
      </Group>

      {active > 0 ? (
        <button
          type="button"
          className="inline-flex min-h-[44px] md:min-h-[28px] items-center text-[13px] font-bold underline underline-offset-2"
          style={{ color: 'var(--ink)' }}
          onClick={() => onChange(clearedFilters(state))}
        >
          {SCOUT.clearFilters}
        </button>
      ) : null}
    </div>
  );
}

export interface KindSwitchProps {
  /** The kinds of the list in the server's order, each with the places that pass the filters. */
  groups: readonly KindGroup[];
  /** The kind being looked at; null is every kind side by side. */
  kind: string | null;
  onKind: (kind: string | null) => void;
}

function total(groups: readonly KindGroup[]): number {
  let n = 0;
  for (const group of groups) n += group.candidates.length;
  return n;
}

/**
 * The kinds of place as a list, for the rail of a wide screen: one press switches from every kind
 * side by side to one kind, or from one kind to another.
 */
export function KindNav({ groups, kind, onKind }: KindSwitchProps) {
  const row = (key: string, label: string, count: number, selected: boolean, value: string | null) => (
    <li key={key}>
      <button
        type="button"
        aria-current={selected ? 'true' : undefined}
        onClick={() => onKind(value)}
        className="flex h-9 w-full items-center justify-between gap-2 rounded-lg px-2.5 text-left text-[13px] font-bold"
        style={selected ? { background: 'var(--nav-active-bg)', color: 'var(--nav-active-fg)' } : { color: 'var(--ink)' }}
      >
        <span className="min-w-0 truncate">{label}</span>
        <span className="flex-none text-xs tabular-nums" style={{ color: selected ? 'var(--nav-active-fg)' : 'var(--body)' }}>
          {fmtCount(count)}
        </span>
      </button>
    </li>
  );
  return (
    <nav aria-label={SCOUT.kindLabel}>
      <h2 className="label px-2.5">{SCOUT.kindLabel}</h2>
      <ul className="space-y-0.5">
        {row('all', SCOUT.allKinds, total(groups), kind === null, null)}
        {groups.map((group) => row(group.kind, group.label, group.candidates.length, kind === group.kind, group.kind))}
      </ul>
    </nav>
  );
}

/** The same switch as one select, for a narrow screen. */
export function KindSelect({ groups, kind, onKind, id }: KindSwitchProps & { id: string }) {
  return (
    <div className="min-w-0 flex-1">
      <label htmlFor={id} className="label">
        {SCOUT.kindLabel}
      </label>
      <select
        id={id}
        className="select h-11 text-sm font-bold"
        style={{ paddingTop: 0, paddingBottom: 0 }}
        value={kind === null ? '' : kind}
        onChange={(e) => onKind(e.target.value === '' ? null : e.target.value)}
      >
        <option value="">
          {SCOUT.allKinds} ({fmtCount(total(groups))})
        </option>
        {groups.map((group) => (
          <option key={group.kind} value={group.kind}>
            {group.label} ({fmtCount(group.candidates.length)})
          </option>
        ))}
      </select>
    </div>
  );
}
