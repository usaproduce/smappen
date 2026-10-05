import { createContext, useContext, useId, useMemo, useRef, useState, type ReactNode } from 'react';
import { ChevronDown, ChevronRight, RotateCcw, TriangleAlert, UserRound } from 'lucide-react';
import { apiErrorDetails } from '../../../api/truck';
import type { OverrideMap, SeedOverrideValue } from '../../../utils/truck/model';
import { fmtClockShort, fmtCount, fmtPlain } from '../../../utils/truck/format';
import {
  assumptionGroups,
  curveView,
  dayTypeLabel,
  draftValue,
  fixedSeedGroups,
  isOverridden,
  overrideChanges,
  overrideErrors,
  rangeText,
  resetDraftValue,
  serverOverrideErrors,
  setDraftValue,
  type AssumptionRow,
  type SegmentRows,
  type WeatherTableRows,
} from '../../../utils/truck/profileForm';
import { DAY_TYPE_LABELS, PLACEHOLDER_SEED, WEATHER_SETTING_LABELS, WHY } from '../../../utils/truck/wording';
import { useResetOverrides, useSaveOverrides, useTruck } from '../data';
import { Field, Modal, NumberField, SeedTag } from '../ui';
import CurveEditor, { WeekdayEditor } from './CurveEditor';

export interface AssumptionsTabProps {
  /** The owner's overrides as they are being edited: seed path to value. */
  draft: OverrideMap;
  onChange: (next: OverrideMap) => void;
  /** Goes up with every discard: the fields start afresh, the open groups stay open. */
  version: number;
  /** The draft was saved: the page follows the saved overrides from here on. */
  onSaved: () => void;
  /** Throw the draft away: the page follows the saved overrides again. */
  onDiscard: () => void;
}

const CARD = 'bg-white rounded-xl border';

/**
 * A field keeps what was typed into it, a refused value included. After a discard every field is
 * keyed anew by this number, so it shows the saved value again.
 */
const FieldVersion = createContext(0);

/** Sets one path of the draft; a null value takes the override away. */
type Put = (path: string, value: SeedOverrideValue | null) => void;
/** The same for several paths in one step. */
type Apply = (entries: readonly (readonly [string, SeedOverrideValue | null])[]) => void;

function domId(prefix: string, path: string): string {
  return prefix + path.split('.').join('-');
}

function capitalised(text: string): string {
  return text === '' ? text : text.charAt(0).toUpperCase() + text.slice(1);
}

/** A seed value as the page prints it next to "Starting value". */
function shownValue(row: AssumptionRow, value: unknown): string {
  if (typeof value === 'number') return row.editor === 'percent' ? fmtPlain(value * 100, 4) + '%' : fmtPlain(value, 4);
  if (typeof value === 'string') return dayTypeLabel(value);
  return '';
}

/**
 * "Assumptions" (docs/truck-planner/05_FRONTEND.md 4.9): the model's starting assumptions the
 * owner may change for their truck. The page edits a draft; "Save changes" sends route 5 with only
 * the changed paths (a reset row sends null for its path). The bounds are the seed file's, the
 * merged draft is checked by the model's own validate_overrides before it travels, and nothing is
 * clamped. The seeds nobody can change are listed read-only at the end.
 */
export default function AssumptionsTab({ draft, onChange, version, onSaved, onDiscard }: AssumptionsTabProps) {
  const ids = useId();
  const { A } = useTruck();
  const saved = A.overrides;
  const groups = useMemo(() => assumptionGroups(), []);
  const save = useSaveOverrides();
  const reset = useResetOverrides();
  const [problem, setProblem] = useState<string | null>(null);
  const [fromServer, setFromServer] = useState<Record<string, string>>({});
  const [confirming, setConfirming] = useState(false);
  const box = useRef<HTMLDivElement>(null);

  const changes = useMemo(() => overrideChanges(saved, draft), [saved, draft]);
  const dirty = Object.keys(changes).length > 0;
  const errors = useMemo(() => ({ ...fromServer, ...overrideErrors(draft) }), [draft, fromServer]);
  const busy = save.isPending || reset.isPending;

  // What "Reset all" takes away: every override, saved or still in the draft.
  const changedCount = useMemo(() => {
    const paths = new Set<string>([...Object.keys(saved), ...Object.keys(draft)]);
    return paths.size;
  }, [saved, draft]);

  /** Applies one or more changes to the draft in one step: a null value takes the override away. */
  const apply: Apply = (entries) => {
    let next = draft;
    for (const [path, value] of entries) next = value === null ? resetDraftValue(next, path) : setDraftValue(next, path, value);
    onChange(next);
    setProblem(null);
    if (Object.keys(fromServer).length > 0) setFromServer({});
  };
  const put: Put = (path, value) => apply([[path, value]]);

  const submit = () => {
    if (busy) return;
    const refused = box.current === null ? null : box.current.querySelector<HTMLElement>('[aria-invalid="true"]');
    if (Object.keys(overrideErrors(draft)).length > 0 || refused !== null) {
      setProblem('Check the marked values first. Nothing was saved.');
      if (refused !== null) refused.focus();
      return;
    }
    setProblem(null);
    save
      .mutateAsync(changes)
      .then(() => onSaved())
      .catch((e: unknown) => {
        // A 422 names the paths the server refused: the same sentences as the page's own check.
        const refusedPaths = serverOverrideErrors(apiErrorDetails(e));
        if (Object.keys(refusedPaths).length > 0) {
          setFromServer(refusedPaths);
          setProblem('Check the marked values first. Nothing was saved.');
        }
      });
  };

  const resetAll = () => {
    reset
      .mutateAsync(undefined)
      .then(() => {
        setConfirming(false);
        onDiscard();
      })
      .catch(() => {
        // The hook has shown the server's sentence.
      });
  };

  const changedIn = (paths: readonly string[]) => paths.filter((path) => isOverridden(draft, path)).length;
  const scalar = (row: AssumptionRow) => <ScalarRow key={row.path} id={domId(ids, row.path)} row={row} draft={draft} put={put} error={errors[row.path]} />;

  return (
    <FieldVersion.Provider value={version}>
      <div ref={box} className="space-y-4">
        <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
          <p className="max-w-3xl text-sm font-semibold" style={{ color: 'var(--body)' }}>
            These are the model's starting assumptions. None is measured from food truck sales. Change one only if you know better for your truck. Your
            logged services correct the totals either way.
          </p>
          {changedCount > 0 ? (
            <button
              type="button"
              className="inline-flex min-h-[44px] md:min-h-0 items-center gap-1 text-[13px] font-bold underline underline-offset-2"
              style={{ color: 'var(--ink)' }}
              onClick={() => setConfirming(true)}
            >
              <RotateCcw size={13} aria-hidden /> Reset all assumptions
            </button>
          ) : null}
        </div>

        <Accordion title="Hosts" changed={changedIn(groups.hosts.map((r) => r.path))} defaultOpen>
          <div className="tp-stat-list">{groups.hosts.map(scalar)}</div>
        </Accordion>

        <Accordion
          title="Weather"
          changed={changedIn([...groups.weather.map((r) => r.path), ...groups.weatherTables.flatMap((t) => t.rows.flatMap((r) => [r.open.path, r.captive.path]))])}
        >
          <div className="tp-stat-list">{groups.weather.map(scalar)}</div>
          {groups.weatherTables.map((table) => (
            <WeatherTable key={table.id} idPrefix={ids} table={table} draft={draft} put={put} apply={apply} errors={errors} />
          ))}
        </Accordion>

        <Accordion title="Events" changed={changedIn(groups.events.map((r) => r.path))}>
          <div className="tp-stat-list">{groups.events.map(scalar)}</div>
        </Accordion>

        <Accordion
          title="People by hour"
          changed={changedIn(groups.people.flatMap((s) => [...s.curves.map((c) => c.row.path), s.weekdays.path, ...s.holidays.map((h) => h.row.path)]))}
        >
          <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
            For each kind of person: how many are there in each hour, and what share of them buys a meal in that hour. Sixteen groups, each with a
            weekday, a Saturday and a Sunday.
          </p>
          <div className="mt-3 space-y-2">
            {groups.people.map((segment) => (
              <SegmentGroup key={segment.segment} idPrefix={ids} segment={segment} draft={draft} put={put} errors={errors} />
            ))}
          </div>
        </Accordion>

        <FixedSection />

        {dirty ? (
          <div style={{ position: 'sticky', bottom: 12, zIndex: 15 }}>
            <div
              role="region"
              aria-label="Unsaved changes"
              className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border bg-white px-4 py-3 shadow-float"
              style={{ borderColor: 'var(--line)' }}
            >
              {problem !== null ? (
                <p role="alert" className="mr-auto flex items-start gap-1.5 text-sm font-bold" style={{ color: 'var(--money-negative)' }}>
                  <TriangleAlert size={15} aria-hidden className="mt-0.5 flex-none" />
                  <span>{problem}</span>
                </p>
              ) : (
                <p className="mr-auto text-sm font-bold" style={{ color: 'var(--ink)' }}>
                  Unsaved changes
                </p>
              )}
              <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={onDiscard} disabled={busy}>
                Discard
              </button>
              <button type="button" className="btn btn-primary h-11 md:h-9 px-4 text-sm" onClick={submit} disabled={busy}>
                {save.isPending ? 'Saving...' : 'Save changes'}
              </button>
            </div>
          </div>
        ) : null}

        <Modal
          open={confirming}
          onClose={() => setConfirming(false)}
          title={'Reset all ' + fmtCount(changedCount) + ' changed assumptions?'}
          size="sm"
          footer={
            <>
              <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={() => setConfirming(false)} disabled={reset.isPending}>
                Keep them
              </button>
              <button type="button" className="btn btn-danger h-11 md:h-9 px-3 text-sm" onClick={resetAll} disabled={reset.isPending}>
                {reset.isPending ? 'Resetting...' : 'Reset all'}
              </button>
            </>
          }
        >
          <p>Every assumption goes back to its starting value. Your truck settings and your logged services are kept.</p>
        </Modal>
      </div>
    </FieldVersion.Provider>
  );
}

// -------------------------------------------------------------------------------------------------
// Pieces
// -------------------------------------------------------------------------------------------------

function Accordion({ title, changed, defaultOpen = false, children }: { title: string; changed: number; defaultOpen?: boolean; children: ReactNode }) {
  const [open, setOpen] = useState(defaultOpen);
  const id = useId();
  return (
    <section className={CARD} style={{ borderColor: 'var(--line-soft)' }}>
      <h2>
        <button
          type="button"
          className="flex min-h-[52px] w-full items-center gap-2 rounded-xl px-4 sm:px-5 text-left"
          aria-expanded={open}
          aria-controls={id}
          onClick={() => setOpen(!open)}
        >
          {open ? <ChevronDown size={18} aria-hidden style={{ color: 'var(--body)' }} /> : <ChevronRight size={18} aria-hidden style={{ color: 'var(--body)' }} />}
          <span className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
            {title}
          </span>
          {changed > 0 ? <YourValueChip text={fmtCount(changed) + ' changed'} /> : null}
        </button>
      </h2>
      {open ? (
        <div id={id} className="border-t px-4 sm:px-5 pb-4 pt-3" style={{ borderColor: 'var(--line-soft)' }}>
          {children}
        </div>
      ) : null}
    </section>
  );
}

function YourValueChip({ text = WHY.yourValue }: { text?: string }) {
  return (
    <span className="tp-chip tp-chip-sm">
      <UserRound size={11} strokeWidth={2.75} aria-hidden />
      {text}
    </span>
  );
}

function ResetButton({ label = 'Reset', onClick }: { label?: string; onClick: () => void }) {
  return (
    <button
      type="button"
      className="inline-flex min-h-[44px] md:min-h-0 items-center gap-1 text-[13px] font-bold underline underline-offset-2"
      style={{ color: 'var(--ink)' }}
      onClick={onClick}
    >
      <RotateCcw size={13} aria-hidden /> {label}
    </button>
  );
}

/** The seed's source note behind a "Source" disclosure. */
function SourceNote({ source }: { source: string }) {
  const [open, setOpen] = useState(false);
  const id = useId();
  if (source === '') return null;
  return (
    <>
      <button
        type="button"
        className="inline-flex min-h-[44px] md:min-h-0 items-center gap-0.5 text-[13px] font-bold underline underline-offset-2"
        style={{ color: 'var(--body)' }}
        aria-expanded={open}
        aria-controls={id}
        onClick={() => setOpen(!open)}
      >
        {open ? <ChevronDown size={13} aria-hidden /> : <ChevronRight size={13} aria-hidden />}
        Source
      </button>
      {open ? (
        <span id={id} className="basis-full text-xs font-semibold" style={{ color: 'var(--body)' }}>
          {source}
        </span>
      ) : null}
    </>
  );
}

/** Tag, "Your value", "Source" and "Reset" of one seed, on one wrapping line. */
function RowMeta({
  row,
  overridden,
  onReset,
  resetLabel,
  showReset = true,
  children,
}: {
  row: AssumptionRow;
  overridden: boolean;
  onReset: () => void;
  resetLabel?: string;
  /** False where another "Reset" for the same seed is on screen (an open curve has its own). */
  showReset?: boolean;
  /** One more control on the same line (the "Edit curve" button of a curve). */
  children?: ReactNode;
}) {
  return (
    <div className="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1">
      {row.meta.tag !== null ? <SeedTag tag={row.meta.tag} size="sm" /> : null}
      {overridden ? <YourValueChip /> : null}
      {children}
      {overridden && showReset ? <ResetButton label={resetLabel} onClick={onReset} /> : null}
      <SourceNote source={row.meta.source} />
    </div>
  );
}

function ScalarRow({
  id,
  row,
  draft,
  put,
  error,
}: {
  id: string;
  row: AssumptionRow;
  draft: OverrideMap;
  put: Put;
  error?: string;
}) {
  const version = useContext(FieldVersion);
  const overridden = isOverridden(draft, row.path);
  const value = draftValue(draft, row.path);
  const range = rangeText(row);
  return (
    <div className="flex flex-wrap items-start gap-x-4 gap-y-2 py-3">
      <div className="min-w-0 flex-1" style={{ flexBasis: 260 }}>
        <p className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
          {row.label}
        </p>
        <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
          {capitalised(row.meta.unit)}
          {range !== '' ? '. ' + range : ''}
          {overridden ? '. ' + WHY.startingValue + ': ' + shownValue(row, row.meta.value) : ''}
          {row.meta.tag === 'tuned' ? '. ' + PLACEHOLDER_SEED : ''}.
        </p>
        <RowMeta row={row} overridden={overridden} onReset={() => put(row.path, null)} />
      </div>
      <NumberField
        key={version}
        id={id}
        label={row.label}
        className="w-[150px] flex-none [&_.label]:sr-only"
        format={row.editor === 'percent' ? 'percent' : 'number'}
        value={typeof value === 'number' ? value : null}
        // An emptied field goes back to the starting value: that is what "no value of your own" means.
        onCommit={(next) => put(row.path, next)}
        min={row.meta.min === null ? undefined : row.meta.min}
        max={row.meta.max === null ? undefined : row.meta.max}
        step={row.editor === 'percent' ? undefined : 0.1}
        error={error}
      />
    </div>
  );
}

function WeatherTable({
  idPrefix,
  table,
  draft,
  put,
  apply,
  errors,
}: {
  idPrefix: string;
  table: WeatherTableRows;
  draft: OverrideMap;
  put: Put;
  apply: Apply;
  errors: Record<string, string>;
}) {
  const first = table.rows[0];
  const version = useContext(FieldVersion);
  const columns = '@[520px]:grid-cols-[minmax(0,1fr)_150px_150px]';
  const headCell = 'text-[11px] font-bold uppercase tracking-wider';
  return (
    <div className="mt-4">
      <h3 className="text-sm font-extrabold" style={{ color: 'var(--ink)' }}>
        {table.title}
      </h3>
      <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
        Share of the usual orders that is left in this weather.
        {first !== undefined ? ' ' + rangeText(first.open) + '.' : ''}
      </p>
      {/*
        A table from 520 px of room: one row per band, a column per setting. With less room (a
        phone) each band keeps its two fields side by side under its name, and each field shows
        its own label, so nothing is scrolled sideways.
      */}
      <div className="@container mt-2 rounded-lg border" style={{ borderColor: 'var(--line-soft)' }}>
        <div aria-hidden className={'hidden gap-x-3 rounded-t-lg border-b px-3 py-2 @[520px]:grid ' + columns} style={{ background: 'var(--bg-panel)', borderColor: 'var(--line-soft)', color: 'var(--slate)' }}>
          <span className={headCell}>{table.title}</span>
          <span className={headCell}>{WEATHER_SETTING_LABELS.open}</span>
          <span className={headCell}>{WEATHER_SETTING_LABELS.captive}</span>
        </div>
        <div className="tp-stat-list">
          {table.rows.map((r) => {
            const overridden = isOverridden(draft, r.open.path) || isOverridden(draft, r.captive.path);
            const cell = (cellRow: AssumptionRow, label: string) => {
              const value = draftValue(draft, cellRow.path);
              return (
                <NumberField
                  key={cellRow.path + ':' + String(version)}
                  id={domId(idPrefix, cellRow.path)}
                  label={label}
                  className="@[520px]:[&_.label]:sr-only"
                  format="percent"
                  value={typeof value === 'number' ? value : null}
                  onCommit={(next) => put(cellRow.path, next)}
                  min={cellRow.meta.min === null ? undefined : cellRow.meta.min}
                  max={cellRow.meta.max === null ? undefined : cellRow.meta.max}
                  error={errors[cellRow.path]}
                />
              );
            };
            return (
              <div key={r.id} role="group" aria-label={r.label} className={'grid gap-x-3 gap-y-2 px-3 py-2.5 ' + columns}>
                <div className="min-w-0">
                  <p className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
                    {r.label}
                  </p>
                  <RowMeta
                    row={r.open}
                    overridden={overridden}
                    resetLabel="Reset row"
                    // both cells of the row in one change of the draft
                    onReset={() =>
                      apply([
                        [r.open.path, null],
                        [r.captive.path, null],
                      ])
                    }
                  />
                </div>
                <div className="grid grid-cols-2 gap-3 @[520px]:contents">
                  {cell(r.open, WEATHER_SETTING_LABELS.open)}
                  {cell(r.captive, WEATHER_SETTING_LABELS.captive)}
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

function SegmentGroup({
  idPrefix,
  segment,
  draft,
  put,
  errors,
}: {
  idPrefix: string;
  segment: SegmentRows;
  draft: OverrideMap;
  put: Put;
  errors: Record<string, string>;
}) {
  const [open, setOpen] = useState(false);
  const id = useId();
  const version = useContext(FieldVersion);
  const paths = [...segment.curves.map((c) => c.row.path), segment.weekdays.path, ...segment.holidays.map((h) => h.row.path)];
  const changed = paths.filter((path) => isOverridden(draft, path)).length;
  return (
    <section className="rounded-lg border" style={{ borderColor: 'var(--line-soft)' }}>
      <h3>
        <button
          type="button"
          className="flex min-h-[44px] w-full items-center gap-2 rounded-lg px-3 text-left"
          aria-expanded={open}
          aria-controls={id}
          onClick={() => setOpen(!open)}
        >
          {open ? <ChevronDown size={16} aria-hidden style={{ color: 'var(--body)' }} /> : <ChevronRight size={16} aria-hidden style={{ color: 'var(--body)' }} />}
          <span className="text-sm font-extrabold" style={{ color: 'var(--ink)' }}>
            {segment.label}
          </span>
          {changed > 0 ? <YourValueChip text={fmtCount(changed) + ' changed'} /> : null}
        </button>
      </h3>
      {open ? (
        <div id={id} className="space-y-4 border-t px-3 pb-3 pt-3" style={{ borderColor: 'var(--line-soft)' }}>
          {(['presence', 'intent'] as const).map((kind) => {
            const curves = segment.curves.filter((c) => c.kind === kind);
            const first = curves[0];
            return (
              <div key={kind}>
                <h4 className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
                  {kind === 'presence' ? 'People present, hour by hour' : 'Share buying a meal, hour by hour'}
                </h4>
                {first !== undefined ? (
                  <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
                    {capitalised(first.row.meta.unit)}. {rangeText(first.row)}.{first.row.meta.tag === 'tuned' ? ' ' + PLACEHOLDER_SEED + '.' : ''}
                  </p>
                ) : null}
                <div className="tp-stat-list mt-1">
                  {curves.map((curve) => (
                    <CurveRow
                      key={curve.row.path}
                      id={domId(idPrefix, curve.row.path)}
                      row={curve.row}
                      title={DAY_TYPE_LABELS[curve.dayType]}
                      draft={draft}
                      put={put}
                      error={errors[curve.row.path]}
                    />
                  ))}
                </div>
              </div>
            );
          })}

          <div>
            <h4 className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
              Monday to Friday adjustment
            </h4>
            <p className="mb-1.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
              {capitalised(segment.weekdays.meta.unit)}. {rangeText(segment.weekdays)}. 1 leaves the weekday curve as it is.
            </p>
            <WeekdayEditor
              key={version}
              id={domId(idPrefix, segment.weekdays.path)}
              label={segment.weekdays.label}
              value={draftValue(draft, segment.weekdays.path) as number[]}
              onChange={(next) => put(segment.weekdays.path, next)}
              min={segment.weekdays.meta.min}
              max={segment.weekdays.meta.max}
              error={errors[segment.weekdays.path]}
            />
            <RowMeta row={segment.weekdays} overridden={isOverridden(draft, segment.weekdays.path)} onReset={() => put(segment.weekdays.path, null)} />
          </div>

          <div>
            <h4 className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
              On a federal holiday
            </h4>
            <p className="mb-1.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
              Which day's pattern these people follow.
            </p>
            <div className="grid gap-3 sm:grid-cols-2">
              {segment.holidays.map((holiday) => {
                const value = draftValue(draft, holiday.row.path);
                const fieldId = domId(idPrefix, holiday.row.path);
                return (
                  <div key={holiday.cls}>
                    <Field id={fieldId} label={holiday.cls === 'major' ? 'Major holiday' : 'Minor holiday'} error={errors[holiday.row.path]}>
                      {(control) => (
                        <select
                          {...control}
                          className="select h-11 md:h-9 text-sm font-semibold"
                          style={{ paddingTop: 0, paddingBottom: 0 }}
                          value={typeof value === 'string' ? value : ''}
                          onChange={(e) => put(holiday.row.path, e.target.value)}
                        >
                          {(holiday.row.meta.allowed ?? []).map((option) => (
                            <option key={option} value={option}>
                              {dayTypeLabel(option)}
                            </option>
                          ))}
                        </select>
                      )}
                    </Field>
                    <RowMeta row={holiday.row} overridden={isOverridden(draft, holiday.row.path)} onReset={() => put(holiday.row.path, null)} />
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      ) : null}
    </section>
  );
}

/** One 24-hour curve: what it peaks at, its tag, and the editor behind "Edit curve". */
function CurveRow({
  id,
  row,
  title,
  draft,
  put,
  error,
}: {
  id: string;
  row: AssumptionRow;
  title: string;
  draft: OverrideMap;
  put: Put;
  error?: string;
}) {
  const [open, setOpen] = useState(false);
  const panel = useId();
  const version = useContext(FieldVersion);
  const overridden = isOverridden(draft, row.path);
  const value = draftValue(draft, row.path) as number[];
  const view = useMemo(() => curveView(value), [value]);
  return (
    <div className="py-2.5">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
        <span className="text-sm font-bold" style={{ color: 'var(--ink)', minWidth: 76 }}>
          {title}
        </span>
        {open ? null : (
          <span className="text-xs font-semibold tabular-nums" style={{ color: 'var(--body)' }}>
            {view.peakPercent > 0 ? 'Highest at ' + fmtClockShort(view.peakHour * 60) + ': ' + fmtPlain(view.peakPercent, 2) + '%' : 'Zero in every hour'}
          </span>
        )}
      </div>
      <RowMeta row={row} overridden={overridden} resetLabel="Reset curve" showReset={!open} onReset={() => put(row.path, null)}>
        <button
          type="button"
          className="inline-flex min-h-[44px] md:min-h-0 items-center gap-0.5 text-[13px] font-bold underline underline-offset-2"
          style={{ color: 'var(--ink)' }}
          aria-expanded={open}
          aria-controls={panel}
          onClick={() => setOpen(!open)}
        >
          {open ? <ChevronDown size={13} aria-hidden /> : <ChevronRight size={13} aria-hidden />}
          {open ? 'Close the curve' : 'Edit curve'}
        </button>
      </RowMeta>
      {error !== undefined && !open ? (
        <p role="alert" className="mt-1 text-xs font-semibold" style={{ color: 'var(--money-negative)' }}>
          {error}
        </p>
      ) : null}
      {open ? (
        <div id={panel} className="mt-2">
          <CurveEditor
            key={version}
            id={id}
            label={row.label}
            value={value}
            onChange={(next) => put(row.path, next)}
            min={row.meta.min}
            max={row.meta.max}
            overridden={overridden}
            onReset={() => put(row.path, null)}
            error={error}
          />
        </div>
      ) : null}
    </div>
  );
}

/** The closed section "Fixed in this version": the seeds nobody can change, with tag and source. */
function FixedSection() {
  const { A } = useTruck();
  const [open, setOpen] = useState(false);
  const id = useId();
  const groups = useMemo(() => (open ? fixedSeedGroups(A) : []), [open, A]);
  return (
    <section className={CARD} style={{ borderColor: 'var(--line-soft)' }}>
      <h2>
        <button
          type="button"
          className="flex min-h-[52px] w-full items-center gap-2 rounded-xl px-4 sm:px-5 text-left"
          aria-expanded={open}
          aria-controls={id}
          onClick={() => setOpen(!open)}
        >
          {open ? <ChevronDown size={18} aria-hidden style={{ color: 'var(--body)' }} /> : <ChevronRight size={18} aria-hidden style={{ color: 'var(--body)' }} />}
          <span className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
            Fixed in this version
          </span>
        </button>
      </h2>
      {open ? (
        <div id={id} className="space-y-4 border-t px-4 sm:px-5 pb-4 pt-3" style={{ borderColor: 'var(--line-soft)' }}>
          <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
            These assumptions are part of the model or of the map data and cannot be changed here. Each one says where it comes from.
          </p>
          {groups.map((group) => (
            <div key={group.id}>
              <h3 className="text-sm font-extrabold" style={{ color: 'var(--ink)' }}>
                {group.title}
              </h3>
              <ul className="tp-stat-list">
                {group.rows.map((r) => (
                  <li key={r.key} className="py-2">
                    <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                      <span className="min-w-0 text-sm font-semibold" style={{ color: 'var(--ink)' }}>
                        {r.label}
                      </span>
                      <span className="inline-flex flex-wrap items-center justify-end gap-x-2 gap-y-1">
                        <span className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                          {r.value}
                          {r.unit !== '' && r.unit.length <= 24 ? ' ' + r.unit : ''}
                        </span>
                        {r.tag !== null ? <SeedTag tag={r.tag} size="sm" /> : null}
                      </span>
                    </div>
                    <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
                      {r.unit.length > 24 ? capitalised(r.unit) + '. ' : ''}
                      {r.note !== null ? r.note + '. ' : ''}
                      {r.source}
                    </p>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      ) : null}
    </section>
  );
}
